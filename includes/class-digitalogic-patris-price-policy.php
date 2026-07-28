<?php
/**
 * Explicit Patris-to-WooCommerce storefront price policy.
 *
 * The canonical calculated selling price is written as WooCommerce regular and
 * effective price. WooCommerce's separate promotion field is always empty, so
 * it can never make the customer-visible value diverge from the selling price.
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
	public const CANONICAL_SALE = 'canonical_sale';
	public const CANONICAL_META = '_digitalogic_patris_final_price';
	public const STATUS_META    = '_digitalogic_patris_price_status';
	public const POLICY_META    = '_digitalogic_patris_sale_policy';
	public const WARNING_META   = '_digitalogic_patris_price_warning';

	/**
	 * Operator-visible warning used when weight is the missing price input.
	 */
	public const MISSING_WEIGHT_WARNING = 'وزن نامشخص؛ قیمت قبلی حفظ شد';

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
	 * Return the single managed-product sale policy.
	 *
	 * The old option and constants remain readable for compatibility with
	 * existing audit tooling, but they no longer select storefront behavior.
	 *
	 * @return string
	 */
	public function get_sale_policy() {
		return self::CANONICAL_SALE;
	}

	/**
	 * Apply the canonical calculated selling price to Woo price projections.
	 *
	 * Variable containers remain fail-closed because their variation prices
	 * require a separate reconciliation. When weight is the only unavailable
	 * price dependency, an already-valid regular/effective price pair is
	 * preserved with an explicit warning until weight is supplied. Other
	 * missing or non-positive canonical values clear the simple/variation price.
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
		$product->delete_meta_data( self::WARNING_META );

		if ( $is_variable ) {
			$status = $has_price ? 'canonical_only_variable' : 'canonical_missing_variable';
			$product->update_meta_data( self::STATUS_META, $status );

			return $this->project( $product, $canonical, $status, $policy );
		}

		if ( ! $has_price || ! is_numeric( $canonical ) ) {
			if ( $this->weight_is_missing( $data ) && $this->has_preservable_storefront_price( $product ) ) {
				$status = 'canonical_missing_preserved';
				$product->update_meta_data( self::STATUS_META, $status );
				$product->update_meta_data( self::WARNING_META, self::MISSING_WEIGHT_WARNING );

				return $this->project( $product, null, $status, $policy );
			}

			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
			$product->set_price( '' );
			$status = 'canonical_missing_unpriced';
			$product->update_meta_data( self::STATUS_META, $status );

			return $this->project( $product, null, $status, $policy );
		}

		if ( (float) $canonical <= 0 ) {
			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
			$product->set_price( '' );
			$status = 'canonical_nonpositive_unpriced';
			$product->update_meta_data( self::STATUS_META, $status );

			return $this->project( $product, $canonical, $status, $policy );
		}

		$canonical_string = $this->decimal_string( $canonical );
		$product->set_regular_price( $canonical_string );
		$product->set_sale_price( '' );
		$product->set_price( $canonical_string );
		$status = 'priced';

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
			$policy        = in_array( $stored_policy, array( self::CANONICAL_SALE ), true )
				? $stored_policy
				: $this->get_sale_policy();
		}

		$regular   = (string) $product->get_regular_price();
		$sale      = (string) $product->get_sale_price();
		$effective = (string) $product->get_price();
		$warning   = (string) $product->get_meta( self::WARNING_META, true );
		$on_sale   = method_exists( $product, 'is_on_sale' )
			? (bool) $product->is_on_sale()
			: ( '' !== $sale && $this->prices_equal( $sale, $effective ) );

		return array(
			'canonical_patris_price'     => '' === (string) $canonical ? null : (string) $canonical,
			'woo_regular_price'          => $regular,
			'woo_sale_price'             => $sale,
			'woo_effective_price'        => $effective,
			'sale_policy'                => $policy,
			'sale_active'                => $on_sale,
			'price_source'               => $product->is_type( 'variable' ) ? 'variations' : ( $on_sale ? 'sale' : 'regular' ),
			'policy_status'              => $status,
			'canonical_matches_regular'  => '' !== (string) $canonical && $this->prices_equal( $canonical, $regular ),
			'canonical_matches_visible'  => '' !== (string) $canonical && $this->prices_equal( $canonical, $effective ),
			'woo_sale_price_cleared'     => '' === trim( $sale ),
			'canonical_only_variable'    => $product->is_type( 'variable' ),
			'preserved_storefront_price' => 'canonical_missing_preserved' === $status,
			'policy_warning'             => '' === $warning ? null : $warning,
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
			if ( 'canonical_missing_preserved' === $projection['policy_status'] ) {
				$audit_status = 'canonical_missing_preserved';
			} elseif ( null === $canonical ) {
				$audit_status = 'missing_canonical';
			} elseif ( $projection['canonical_only_variable'] ) {
				$audit_status = 'canonical_only_variable';
			} elseif ( (float) $canonical <= 0 ) {
				$audit_status = 'nonpositive_canonical';
			} elseif (
				$projection['canonical_matches_regular']
				&& $projection['canonical_matches_visible']
				&& $projection['woo_sale_price_cleared']
			) {
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
				'needs_review'     => in_array( $audit_status, array( 'canonical_missing_preserved', 'missing_canonical', 'different', 'nonpositive_canonical' ), true ) ? 'yes' : 'no',
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
	 * Whether weight is unavailable for the landed-price calculation.
	 *
	 * @param array $data Normalized Patris row.
	 * @return bool
	 */
	private function weight_is_missing( $data ) {
		return ! array_key_exists( 'weight_grams', $data )
			|| null === $data['weight_grams']
			|| ! is_numeric( $data['weight_grams'] )
			|| (float) $data['weight_grams'] <= 0;
	}

	/**
	 * Whether the current storefront price already satisfies the managed rule.
	 *
	 * @param WC_Product $product WooCommerce product or variation.
	 * @return bool
	 */
	private function has_preservable_storefront_price( WC_Product $product ) {
		$regular   = trim( (string) $product->get_regular_price() );
		$effective = trim( (string) $product->get_price() );
		$sale      = trim( (string) $product->get_sale_price() );

		return '' !== $regular
			&& is_numeric( $regular )
			&& (float) $regular > 0
			&& $this->prices_equal( $regular, $effective )
			&& '' === $sale;
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
