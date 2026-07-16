<?php
/**
 * Read-only WooCommerce product metadata diagnostics.
 *
 * The normal WooCommerce CRUD object and current post meta remain the source
 * of truth. The lookup table is inspected only to detect stale derived data.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare exact current product metadata with WooCommerce's derived row.
 */
final class Digitalogic_Product_Metadata_Inspector {

	private const META_KEYS = array(
		'_sku',
		'_regular_price',
		'_sale_price',
		'_price',
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_sold_individually',
		'total_sales',
		'_tax_status',
		'_tax_class',
	);

	/**
	 * Shared singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared inspector.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Inspect one exact product or variation.
	 *
	 * @param array $identifiers Identifier object accepted by the shared resolver.
	 * @return array|WP_Error
	 */
	public function inspect( $identifiers ) {
		$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve( $identifiers );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$product_id = (int) $resolved['woocommerce_id'];
		$lookup     = $this->read_lookup_row( $product_id );
		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$postmeta        = $this->read_postmeta( $product_id );
		$inconsistencies = $this->compare_sources( $postmeta, $lookup );

		return array(
			'product_id'          => $product_id,
			'woocommerce_id'      => (string) $resolved['woocommerce_id'],
			'post_type'           => (string) $resolved['post_type'],
			'sku'                 => (string) $resolved['sku'],
			'patris_code'         => (string) $resolved['patris_code'],
			'resolved_by'         => (string) $resolved['resolved_by'],
			'identifier'          => (string) $resolved['identifier'],
			'source_of_truth'     => 'woocommerce_crud_and_current_postmeta',
			'lookup_table_role'   => 'derived_diagnostic_only',
			'postmeta'            => $postmeta,
			'lookup_table'        => $lookup,
			'is_consistent'       => empty( $inconsistencies ),
			'inconsistency_count' => count( $inconsistencies ),
			'inconsistencies'     => $inconsistencies,
		);
	}

	/**
	 * Read the whitelisted current post meta fields.
	 *
	 * @param int $product_id WooCommerce product or variation ID.
	 * @return array
	 */
	private function read_postmeta( $product_id ) {
		$postmeta = array();
		foreach ( self::META_KEYS as $meta_key ) {
			if ( ! metadata_exists( 'post', $product_id, $meta_key ) ) {
				continue;
			}

			$postmeta[ $meta_key ] = get_post_meta( $product_id, $meta_key, true );
		}

		return $postmeta;
	}

	/**
	 * Read one derived WooCommerce product lookup row.
	 *
	 * @param int $product_id WooCommerce product or variation ID.
	 * @return array|WP_Error
	 */
	private function read_lookup_row( $product_id ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $this->query_failed();
		}

		$table = $wpdb->prefix . 'wc_product_meta_lookup';
		$query = "/* digitalogic_product_metadata_lookup */
            SELECT product_id, sku, virtual, downloadable, min_price, max_price,
                onsale, stock_quantity, stock_status, rating_count,
                average_rating, total_sales, tax_status, tax_class,
                global_unique_id
            FROM {$table}
            WHERE product_id = %d
            LIMIT 1";

		try {
			$prepared = $wpdb->prepare( $query, $product_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $prepared || null === $prepared || '' === $prepared ) {
				return $this->query_failed();
			}
			$row = $wpdb->get_row( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} catch ( Throwable ) {
			return $this->query_failed();
		}

		if ( '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) || ( null !== $row && ! is_array( $row ) ) ) {
			return $this->query_failed();
		}

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Compare stable fields without treating derived lookup data as canonical.
	 *
	 * @param array $postmeta Current post meta.
	 * @param array $lookup Derived lookup row.
	 * @return array
	 */
	private function compare_sources( $postmeta, $lookup ) {
		if ( empty( $lookup ) ) {
			return array(
				array(
					'code'           => 'lookup_row_missing',
					'field'          => 'product_id',
					'postmeta_value' => null,
					'lookup_value'   => null,
				),
			);
		}

		$checks          = array(
			array( '_sku', 'sku', 'string' ),
			array( '_stock', 'stock_quantity', 'decimal' ),
			array( '_stock_status', 'stock_status', 'string' ),
			array( '_tax_status', 'tax_status', 'string' ),
			array( '_tax_class', 'tax_class', 'string' ),
			array( 'total_sales', 'total_sales', 'decimal' ),
		);
		$inconsistencies = array();

		foreach ( $checks as $check ) {
			[$meta_key, $lookup_key, $kind] = $check;
			if ( ! array_key_exists( $meta_key, $postmeta ) && ! array_key_exists( $lookup_key, $lookup ) ) {
				continue;
			}

			$postmeta_value = $postmeta[ $meta_key ] ?? null;
			$lookup_value   = $lookup[ $lookup_key ] ?? null;
			$left           = 'decimal' === $kind ? $this->canonical_decimal( $postmeta_value ) : $this->canonical_string( $postmeta_value );
			$right          = 'decimal' === $kind ? $this->canonical_decimal( $lookup_value ) : $this->canonical_string( $lookup_value );
			if ( $left !== $right ) {
				$inconsistencies[] = $this->mismatch( $lookup_key, $meta_key, $postmeta_value, $lookup_value );
			}
		}

		if (
			array_key_exists( '_price', $postmeta )
			&& array_key_exists( 'min_price', $lookup )
			&& array_key_exists( 'max_price', $lookup )
			&& $this->canonical_decimal( $lookup['min_price'] ) === $this->canonical_decimal( $lookup['max_price'] )
			&& $this->canonical_decimal( $postmeta['_price'] ) !== $this->canonical_decimal( $lookup['min_price'] )
		) {
			$inconsistencies[] = $this->mismatch( 'price', '_price', $postmeta['_price'], $lookup['min_price'] );
		}

		return $inconsistencies;
	}

	/**
	 * Build a machine-readable mismatch record.
	 *
	 * @param string $field Lookup field.
	 * @param string $meta_key Post meta key.
	 * @param mixed  $postmeta_value Current post meta value.
	 * @param mixed  $lookup_value Derived lookup value.
	 * @return array
	 */
	private function mismatch( $field, $meta_key, $postmeta_value, $lookup_value ) {
		return array(
			'code'           => 'derived_lookup_mismatch',
			'field'          => (string) $field,
			'postmeta_key'   => (string) $meta_key,
			'postmeta_value' => $postmeta_value,
			'lookup_value'   => $lookup_value,
		);
	}

	/**
	 * Canonicalize a nullable string.
	 *
	 * @param mixed $value Source value.
	 * @return string|null
	 */
	private function canonical_string( $value ) {
		return null === $value ? null : (string) $value;
	}

	/**
	 * Canonicalize decimal text without binary floating-point conversion.
	 *
	 * @param mixed $value Source value.
	 * @return string|null
	 */
	private function canonical_decimal( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$value = trim( (string) $value );
		if ( ! preg_match( '/^-?(?:0|[0-9]+)(?:\.[0-9]+)?$/', $value ) ) {
			return $value;
		}

		$negative             = str_starts_with( $value, '-' );
		$value                = ltrim( $value, '-' );
		[$integer, $fraction] = array_pad( explode( '.', $value, 2 ), 2, '' );
		$integer              = ltrim( $integer, '0' );
		$integer              = '' === $integer ? '0' : $integer;
		$fraction             = rtrim( $fraction, '0' );
		$canonical            = $integer . ( '' !== $fraction ? '.' . $fraction : '' );

		return $negative && '0' !== $canonical ? '-' . $canonical : $canonical;
	}

	/**
	 * Return a redacted retryable database failure.
	 *
	 * @return WP_Error
	 */
	private function query_failed() {
		return new WP_Error(
			'digitalogic_product_metadata_query_failed',
			__( 'Product metadata diagnostics could not read the WooCommerce lookup table.', 'digitalogic' ),
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
	}
}
