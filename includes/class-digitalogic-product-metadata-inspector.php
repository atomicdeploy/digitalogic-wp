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
		'_virtual',
		'_downloadable',
		'_backorders',
		'_sold_individually',
		'total_sales',
		'_tax_status',
		'_tax_class',
		'_wc_rating_count',
		'_wc_average_rating',
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
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'digitalogic_product_unavailable',
				__( 'The resolved product is no longer available.', 'digitalogic' ),
				array( 'status' => 404 )
			);
		}

		$lookup = $this->read_lookup_row( $product_id );
		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$postmeta        = $this->read_postmeta( $product_id );
		$effective       = $this->read_effective_woocommerce( $product );
		$expected_lookup = $this->expected_lookup_values( $product_id, $postmeta );
		$inconsistencies = $this->compare_sources( $expected_lookup, $postmeta, $lookup );

		return array(
			'product_id'             => $product_id,
			'woocommerce_id'         => (string) $resolved['woocommerce_id'],
			'post_type'              => (string) $resolved['post_type'],
			'product_type'           => (string) $effective['type'],
			'sku'                    => (string) $resolved['sku'],
			'patris_code'            => (string) $resolved['patris_code'],
			'resolved_by'            => (string) $resolved['resolved_by'],
			'identifier'             => (string) $resolved['identifier'],
			'source_of_truth'        => 'woocommerce_crud_and_current_postmeta',
			'lookup_table_role'      => 'derived_diagnostic_only',
			'postmeta'               => $postmeta,
			'effective_woocommerce'  => $effective,
			'expected_lookup_values' => $expected_lookup,
			'lookup_table'           => $lookup,
			'is_consistent'          => empty( $inconsistencies ),
			'inconsistency_count'    => count( $inconsistencies ),
			'inconsistencies'        => $inconsistencies,
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
	 * Read effective values through WooCommerce CRUD inheritance semantics.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array
	 */
	private function read_effective_woocommerce( $product ) {
		return array(
			'type'           => (string) $product->get_type(),
			'sku'            => (string) $product->get_sku(),
			'price'          => $product->get_price(),
			'manage_stock'   => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => (string) $product->get_stock_status(),
			'tax_status'     => (string) $product->get_tax_status(),
			'tax_class'      => (string) $product->get_tax_class(),
			'total_sales'    => $product->get_total_sales(),
		);
	}

	/**
	 * Reproduce WooCommerce's stable lookup-source semantics.
	 *
	 * Effective variation getters intentionally inherit display values from the
	 * parent. The lookup table does not: WooCommerce builds it from this post's
	 * raw meta, including every `_price` row used for variable min/max values.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $postmeta Current post meta.
	 * @return array
	 */
	private function expected_lookup_values( $product_id, $postmeta ) {
		$price_rows = array_values( (array) get_post_meta( $product_id, '_price', false ) );
		$minimum    = empty( $price_rows ) ? null : reset( $price_rows );
		$maximum    = empty( $price_rows ) ? null : end( $price_rows );
		$stock      = null;

		if ( 'yes' === (string) ( $postmeta['_manage_stock'] ?? '' ) ) {
			$stock = $postmeta['_stock'] ?? '';
			if ( function_exists( 'wc_stock_amount' ) ) {
				$stock = wc_stock_amount( $stock );
			}
		}

		$total_sales = $postmeta['total_sales'] ?? 0;
		if ( '' === $total_sales || null === $total_sales ) {
			$total_sales = 0;
		}

		return array(
			'sku'            => $postmeta['_sku'] ?? '',
			'min_price'      => $minimum,
			'max_price'      => $maximum,
			'stock_quantity' => $stock,
			'stock_status'   => $postmeta['_stock_status'] ?? '',
			'total_sales'    => $total_sales,
			'tax_status'     => $postmeta['_tax_status'] ?? '',
			'tax_class'      => $postmeta['_tax_class'] ?? '',
		);
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
				average_rating, total_sales, tax_status, tax_class
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
	 * @param array $expected_lookup Expected raw lookup-source values.
	 * @param array $postmeta Current post meta.
	 * @param array $lookup Derived lookup row.
	 * @return array
	 */
	private function compare_sources( $expected_lookup, $postmeta, $lookup ) {
		if ( empty( $lookup ) ) {
			return array(
				array(
					'code'           => 'lookup_row_missing',
					'field'          => 'product_id',
					'expected_value' => null,
					'postmeta_value' => null,
					'lookup_value'   => null,
				),
			);
		}

		$checks          = array(
			array( 'sku', 'string', '_sku' ),
			array( 'min_price', 'decimal', '_price' ),
			array( 'max_price', 'decimal', '_price' ),
			array( 'stock_quantity', 'decimal', '_stock' ),
			array( 'stock_status', 'string', '_stock_status' ),
			array( 'tax_status', 'string', '_tax_status' ),
			array( 'tax_class', 'string', '_tax_class' ),
			array( 'total_sales', 'decimal', 'total_sales' ),
		);
		$inconsistencies = array();

		foreach ( $checks as $check ) {
			[$lookup_key, $kind, $meta_key] = $check;
			if ( ! array_key_exists( $lookup_key, $lookup ) ) {
				continue;
			}

			$expected_value = $expected_lookup[ $lookup_key ] ?? null;
			$postmeta_value = $postmeta[ $meta_key ] ?? null;
			$lookup_value   = $lookup[ $lookup_key ] ?? null;
			$left           = 'decimal' === $kind ? $this->canonical_decimal( $expected_value ) : $this->canonical_string( $expected_value );
			$right          = 'decimal' === $kind ? $this->canonical_decimal( $lookup_value ) : $this->canonical_string( $lookup_value );
			if ( $left !== $right ) {
				$inconsistencies[] = $this->mismatch( $lookup_key, $meta_key, $expected_value, $postmeta_value, $lookup_value );
			}
		}

		return $inconsistencies;
	}

	/**
	 * Build a machine-readable mismatch record.
	 *
	 * @param string $field Lookup field.
	 * @param string $meta_key Post meta key.
	 * @param mixed  $expected_value Expected lookup-source value.
	 * @param mixed  $postmeta_value Current post meta value.
	 * @param mixed  $lookup_value Derived lookup value.
	 * @return array
	 */
	private function mismatch( $field, $meta_key, $expected_value, $postmeta_value, $lookup_value ) {
		return array(
			'code'           => 'derived_lookup_mismatch',
			'field'          => (string) $field,
			'postmeta_key'   => (string) $meta_key,
			'expected_value' => $expected_value,
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
