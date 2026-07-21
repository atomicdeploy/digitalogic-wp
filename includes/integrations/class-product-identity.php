<?php
/**
 * Storefront display helpers for reviewed Persian and Patris identities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Product_Identity {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_patris_name' ), 1 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_patris_name' ), 6 );
		add_action( 'woocommerce_after_variations_form', array( $this, 'render_variation_identity_slot' ), 5 );
		add_filter( 'woocommerce_available_variation', array( $this, 'add_variation_identity_data' ), 10, 3 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'add_product_schema_identity' ), 10, 2 );
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'add_product_schema_identity' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 90 );
	}

	/**
	 * Render the second line in product loops/search results.
	 */
	public function render_loop_patris_name() {
		global $product;
		$this->render_product_patris_name( $product );
	}

	/**
	 * Render the second line directly below the single-product Persian title.
	 */
	public function render_single_patris_name() {
		global $product;
		$this->render_product_patris_name( $product );
	}

	/**
	 * Provide a stable destination for selected-variation identity JavaScript.
	 */
	public function render_variation_identity_slot() {
		echo '<div class="digitalogic-variation-identity" dir="ltr" lang="en" aria-live="polite" hidden></div>';
	}

	/**
	 * Add escaped, child-owned identities to WooCommerce variation JSON.
	 *
	 * @param array      $data Variation data.
	 * @param WC_Product $parent Variable parent.
	 * @param WC_Product $variation Variation child.
	 * @return array
	 */
	public function add_variation_identity_data( $data, $parent, $variation ) {
		if ( ! is_array( $data ) || ! $variation instanceof WC_Product ) {
			return $data;
		}
		$data['digitalogic_patris_name']  = sanitize_text_field( (string) $variation->get_meta( '_digitalogic_patris_name', true ) );
		$data['digitalogic_patris_code']  = sanitize_text_field( (string) $variation->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		$data['digitalogic_persian_name'] = sanitize_text_field( (string) $variation->get_meta( '_digitalogic_persian_name', true ) );

		return $data;
	}

	/**
	 * Add reviewed identities and remove only an impossible offer for unpriced products.
	 *
	 * @param array      $entity Existing WooCommerce or Rank Math Product entity.
	 * @param WC_Product $product Product when supplied by the integration.
	 * @return array
	 */
	public function add_product_schema_identity( $entity, $product = null ) {
		if ( ! is_array( $entity ) ) {
			return $entity;
		}
		if ( ! $product instanceof WC_Product && isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ) {
			$product = $GLOBALS['product'];
		}
		if ( ! $product instanceof WC_Product ) {
			return $entity;
		}
		$patris_name = $this->get_product_patris_name( $product );
		if ( '' !== $patris_name ) {
			$entity['alternateName'] = $patris_name;
		}
		if ( '' === trim( (string) $product->get_price() ) ) {
			unset( $entity['offers'] );
		} elseif ( isset( $entity['offers'] ) ) {
			$entity['offers'] = $this->normalize_toman_offer( $entity['offers'] );
		}

		$code = trim( (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		if ( '' === $code || (string) $product->get_sku() !== $code ) {
			return $entity;
		}
		$entity['sku'] = $code;
		$mpn           = trim( (string) $product->get_meta( '_digitalogic_part_number', true ) );
		if ( '' !== $mpn ) {
			$entity['mpn'] = $mpn;
		}

		return $entity;
	}

	/**
	 * Convert the store's Toman offer into the equivalent ISO 4217 Rial offer.
	 *
	 * @param mixed $offer Offer, price specification, or a list of offers.
	 * @param bool  $inherited_toman Whether a parent object declared IRT.
	 * @return mixed
	 */
	private function normalize_toman_offer( $offer, $inherited_toman = false ) {
		if ( ! is_array( $offer ) ) {
			return $offer;
		}
		if ( array_is_list( $offer ) ) {
			return array_map(
				function ( $item ) use ( $inherited_toman ) {
					return $this->normalize_toman_offer( $item, $inherited_toman );
				},
				$offer
			);
		}

		$declared_currency = strtoupper( trim( (string) ( $offer['priceCurrency'] ?? '' ) ) );
		$is_toman          = '' === $declared_currency ? $inherited_toman : 'IRT' === $declared_currency;
		if ( isset( $offer['priceSpecification'] ) ) {
			$offer['priceSpecification'] = $this->normalize_toman_offer( $offer['priceSpecification'], $is_toman );
		}
		if ( isset( $offer['offers'] ) ) {
			$offer['offers'] = $this->normalize_toman_offer( $offer['offers'], $is_toman );
		}
		if ( ! $is_toman ) {
			return $offer;
		}

		$has_price = false;
		foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $field ) {
			if ( array_key_exists( $field, $offer ) ) {
				$has_price       = true;
				$offer[ $field ] = $this->multiply_decimal_by_ten( $offer[ $field ] );
			}
		}
		if ( isset( $offer['priceCurrency'] ) || $has_price ) {
			$offer['priceCurrency'] = 'IRR';
		}

		return $offer;
	}

	/**
	 * Multiply one nonnegative canonical decimal by ten without float drift.
	 *
	 * @param mixed $value Decimal value.
	 * @return mixed
	 */
	private function multiply_decimal_by_ten( $value ) {
		$text = trim( (string) $value );
		if ( ! preg_match( '/^([0-9]+)(?:\.([0-9]+))?$/', $text, $matches ) ) {
			return $value;
		}

		$whole    = ltrim( $matches[1], '0' );
		$whole    = '' === $whole ? '0' : $whole;
		$fraction = $matches[2] ?? '';
		if ( '' === $fraction ) {
			return '0' === $whole ? '0' : $whole . '0';
		}

		$result_whole = ltrim( $whole . $fraction[0], '0' );
		$result_whole = '' === $result_whole ? '0' : $result_whole;
		$result_scale = rtrim( substr( $fraction, 1 ), '0' );

		return '' === $result_scale ? $result_whole : $result_whole . '.' . $result_scale;
	}

	/**
	 * Enqueue the small presentation layer only on the public storefront.
	 */
	public function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_style(
			'digitalogic-product-identity',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/product-identity.css',
			array(),
			DIGITALOGIC_VERSION
		);
		wp_enqueue_script(
			'digitalogic-product-identity',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/product-identity.js',
			array( 'jquery' ),
			DIGITALOGIC_VERSION,
			true
		);

		$this->localize_single_product_identity();
	}

	/**
	 * Provide the reviewed Patris identity to the Woodmart single-product fallback.
	 */
	private function localize_single_product_identity() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = null;
		if ( function_exists( 'get_queried_object_id' ) ) {
			$product_id = absint( get_queried_object_id() );
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
			}
		}
		if ( ! $product instanceof WC_Product && isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ) {
			$product = $GLOBALS['product'];
		}

		$patris_name = $this->get_product_patris_name( $product );
		if ( '' === $patris_name ) {
			return;
		}

		wp_localize_script(
			'digitalogic-product-identity',
			'digitalogicProductIdentity',
			array(
				'singleProductPatrisName' => $patris_name,
			)
		);
	}

	/**
	 * Render one non-duplicated English/Patris family identity.
	 *
	 * @param mixed $product Product candidate.
	 */
	private function render_product_patris_name( $product ) {
		$patris_name = $this->get_product_patris_name( $product );
		if ( '' === $patris_name ) {
			return;
		}

		echo '<div class="digitalogic-patris-name" dir="ltr" lang="en">' . esc_html( $patris_name ) . '</div>';
	}

	/**
	 * Resolve one non-duplicated English/Patris family identity.
	 *
	 * @param mixed $product Product candidate.
	 * @return string
	 */
	private function get_product_patris_name( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$patris_name = (string) $product->get_meta( '_digitalogic_patris_name', true );
		if ( '' === trim( $patris_name ) ) {
			$patris_name = (string) $product->get_meta( '_digitalogic_patris_family_name', true );
		}
		$patris_name = trim( $patris_name );
		if ( '' === $patris_name || 0 === strcasecmp( trim( (string) $product->get_name() ), $patris_name ) ) {
			return '';
		}

		return $patris_name;
	}
}
