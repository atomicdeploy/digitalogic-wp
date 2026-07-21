<?php
/**
 * Explicit Patris-to-WooCommerce storefront price policy.
 *
 * Canonical Patris price metadata, WooCommerce regular price, and the
 * effective storefront price are deliberately kept as separate values.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies and reports the explicit Patris storefront price policy.
 */
final class Digitalogic_Patris_Price_Policy {

	public const OPTION_NAME    = 'digitalogic_patris_sale_policy';
	public const PRESERVE_SALE  = 'preserve_sale';
	public const REPLACE_SALE   = 'replace_sale';
	public const CANONICAL_META = '_digitalogic_patris_final_price';
	public const STATUS_META    = '_digitalogic_patris_price_status';
	public const POLICY_META    = '_digitalogic_patris_sale_policy';

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared policy service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resolve the only supported active-sale policies.
	 *
	 * Unknown or missing values always fail back to promotion preservation.
	 *
	 * @return string
	 */
	public function get_sale_policy() {
		$policy = sanitize_key( (string) get_option( self::OPTION_NAME, self::PRESERVE_SALE ) );
		$policy = apply_filters( 'digitalogic_patris_sale_policy', $policy );

		return self::REPLACE_SALE === $policy ? self::REPLACE_SALE : self::PRESERVE_SALE;
	}

	/**
	 * Apply a canonical Patris price without conflating WooCommerce values.
	 *
	 * Variable containers remain canonical-only. Missing and non-positive
	 * canonical prices never erase an existing commercial price. Promotions
	 * are preserved unless an administrator has explicitly selected the
	 * replacement policy.
	 *
	 * @param WC_Product $product WooCommerce product or variation.
	 * @param array      $data    Normalized Patris row.
	 * @return array Price projection after the in-memory policy decision.
	 */
	public function apply( WC_Product $product, $data ) {
		$data        = is_array( $data ) ? $data : array();
		$policy      = $this->get_sale_policy();
		$has_price   = array_key_exists( 'final_price', $data ) && null !== $data['final_price'];
		$canonical   = $has_price ? $data['final_price'] : null;
		$is_variable = $product->is_type( 'variable' );

		$product->update_meta_data( self::POLICY_META, $policy );

		if ( $is_variable ) {
			$status = $has_price ? 'canonical_only_variable' : 'canonical_missing_variable';
			$product->update_meta_data( self::STATUS_META, $status );

			return $this->project( $product, $canonical, $status, $policy );
		}

		if ( ! $has_price || ! is_numeric( $canonical ) ) {
			$status = 'canonical_missing_preserved';
			$product->update_meta_data( self::STATUS_META, $status );

			return $this->project( $product, null, $status, $policy );
		}

		if ( (float) $canonical <= 0 ) {
			$status = 'canonical_nonpositive_preserved';
			$product->update_meta_data( self::STATUS_META, $status );

			return $this->project( $product, $canonical, $status, $policy );
		}

		$canonical_string = $this->decimal_string( $canonical );
		$had_sale         = '' !== trim( (string) $product->get_sale_price() );
		$product->set_regular_price( $canonical_string );

		if ( $had_sale && self::REPLACE_SALE === $policy ) {
			$product->set_sale_price( '' );
			$status = 'priced_sale_replaced';
		} elseif ( $had_sale ) {
			$status = 'priced_sale_preserved';
		} else {
			$status = 'priced';
		}

		$product->update_meta_data( self::STATUS_META, $status );

		return $this->project( $product, $canonical_string, $status, $policy );
	}

	/**
	 * Invalidate WooCommerce and WordPress caches after the product is saved.
	 *
	 * @param WC_Product $product Saved product.
	 * @return void
	 */
	public function invalidate( WC_Product $product ) {
		$product_id = (int) $product->get_id();
		wc_delete_product_transients( $product_id );
		clean_post_cache( $product_id );
	}

	/**
	 * Build an explicit API/admin projection for one product.
	 *
	 * @param WC_Product  $product   WooCommerce product.
	 * @param mixed|null  $canonical Optional in-memory canonical override.
	 * @param string|null $status    Optional in-memory status override.
	 * @param string|null $policy    Optional in-memory policy override.
	 * @return array
	 */
	public function project( WC_Product $product, $canonical = null, $status = null, $policy = null ) {
		if ( null === $canonical ) {
			$canonical = $product->get_meta( self::CANONICAL_META, true );
		}
		if ( null === $status ) {
			$status = (string) $product->get_meta( self::STATUS_META, true );
		}
		if ( null === $policy ) {
			$stored_policy = (string) $product->get_meta( self::POLICY_META, true );
			$policy        = in_array( $stored_policy, array( self::PRESERVE_SALE, self::REPLACE_SALE ), true )
				? $stored_policy
				: $this->get_sale_policy();
		}

		$regular   = (string) $product->get_regular_price();
		$sale      = (string) $product->get_sale_price();
		$effective = (string) $product->get_price();
		$on_sale   = method_exists( $product, 'is_on_sale' )
			? (bool) $product->is_on_sale()
			: ( '' !== $sale && $this->prices_equal( $sale, $effective ) );

		return array(
			'canonical_patris_price'    => '' === (string) $canonical ? null : (string) $canonical,
			'woo_regular_price'         => $regular,
			'woo_sale_price'            => $sale,
			'woo_effective_price'       => $effective,
			'sale_policy'               => $policy,
			'sale_active'               => $on_sale,
			'price_source'              => $product->is_type( 'variable' ) ? 'variations' : ( $on_sale ? 'sale' : 'regular' ),
			'policy_status'             => $status,
			'canonical_matches_regular' => '' !== (string) $canonical && $this->prices_equal( $canonical, $regular ),
			'canonical_only_variable'   => $product->is_type( 'variable' ),
		);
	}

	/**
	 * Return a bounded, non-mutating storefront pricing audit.
	 *
	 * @param int $limit Maximum products to inspect.
	 * @param int $page  One-based page.
	 * @return array
	 */
	public function audit( $limit = 100, $page = 1 ) {
		$limit    = max( 1, min( 500, (int) $limit ) );
		$page     = max( 1, (int) $page );
		$products = wc_get_products(
			array(
				'status'  => 'any',
				'limit'   => $limit,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);
		$rows     = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$projection = $this->project( $product );
			$canonical  = $projection['canonical_patris_price'];
			if ( null === $canonical ) {
				$audit_status = 'missing_canonical';
			} elseif ( $projection['canonical_only_variable'] ) {
				$audit_status = 'canonical_only_variable';
			} elseif ( (float) $canonical <= 0 ) {
				$audit_status = 'nonpositive_canonical';
			} elseif ( $projection['canonical_matches_regular'] ) {
				$audit_status = 'match';
			} else {
				$audit_status = 'different';
			}

			$rows[] = array(
				'product_id'       => (int) $product->get_id(),
				'product_type'     => (string) $product->get_type(),
				'canonical_patris' => $canonical,
				'woo_regular'      => $projection['woo_regular_price'],
				'woo_sale'         => $projection['woo_sale_price'],
				'woo_effective'    => $projection['woo_effective_price'],
				'sale_policy'      => $projection['sale_policy'],
				'price_source'     => $projection['price_source'],
				'audit_status'     => $audit_status,
				'needs_review'     => in_array( $audit_status, array( 'missing_canonical', 'different', 'nonpositive_canonical' ), true ) ? 'yes' : 'no',
			);
		}

		return $rows;
	}

	/**
	 * Convert a numeric value to a non-exponent decimal string.
	 *
	 * @param mixed $value Numeric value.
	 * @return string
	 */
	private function decimal_string( $value ) {
		$value = trim( (string) $value );
		if ( false === stripos( $value, 'e' ) ) {
			return $value;
		}

		return rtrim( rtrim( sprintf( '%.14F', (float) $value ), '0' ), '.' );
	}

	/**
	 * Compare ordinary decimal strings without float rounding.
	 *
	 * @param mixed $left  Left price.
	 * @param mixed $right Right price.
	 * @return bool
	 */
	private function prices_equal( $left, $right ) {
		$normalize = static function ( $value ) {
			$value = trim( (string) $value );
			if ( ! preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches ) ) {
				return null;
			}
			$integer  = ltrim( $matches[2], '0' );
			$integer  = '' === $integer ? '0' : $integer;
			$fraction = isset( $matches[3] ) ? rtrim( $matches[3], '0' ) : '';
			$sign     = '-' === $matches[1] && ( '0' !== $integer || '' !== $fraction ) ? '-' : '';

			return $sign . $integer . ( '' === $fraction ? '' : '.' . $fraction );
		};

		$left_normalized  = $normalize( $left );
		$right_normalized = $normalize( $right );

		return null !== $left_normalized && $left_normalized === $right_normalized;
	}
}
