<?php
/**
 * Storefront display helpers for reviewed Persian and Patris identities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Product_Identity {

	private static $instance = null;

	/**
	 * Published child identities resolved during this request.
	 *
	 * @var array
	 */
	private $child_identity_cache = array();

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
		add_filter( 'woocommerce_get_item_data', array( $this, 'add_cart_item_patris_code' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_patris_code' ), 10, 4 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'add_product_schema_identity' ), 10, 2 );
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'add_product_schema_identity' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 90 );
	}

	/**
	 * Render the second line in product loops/search results.
	 */
	public function render_loop_patris_name() {
		global $product;
		$this->render_product_identity( $product, 'loop' );
	}

	/**
	 * Render the second line directly below the single-product Persian title.
	 */
	public function render_single_patris_name() {
		global $product;
		$this->render_product_identity( $product, 'single' );
	}

	/**
	 * Provide a stable destination for selected-variation identity JavaScript.
	 */
	public function render_variation_identity_slot() {
		echo '<div class="digitalogic-product-identity digitalogic-variation-identity" data-digitalogic-variation-identity aria-live="polite" hidden></div>';
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
	 * Keep the selected leaf identity visible in cart and checkout summaries.
	 *
	 * @param array $item_data Existing customer-facing item data.
	 * @param array $cart_item WooCommerce cart item.
	 * @return array
	 */
	public function add_cart_item_patris_code( $item_data, $cart_item ) {
		if ( ! is_array( $item_data ) || ! is_array( $cart_item ) ) {
			return $item_data;
		}

		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
			? $cart_item['data']
			: false;
		if ( ! $product ) {
			$product_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : absint( $cart_item['product_id'] ?? 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
		}

		$code = $this->get_product_patris_code( $product );
		if ( '' === $code ) {
			return $item_data;
		}

		foreach ( $item_data as $item ) {
			if ( isset( $item['key'] ) && 'کد پاتریس' === $item['key'] ) {
				return $item_data;
			}
		}

		$item_data[] = array(
			'key'     => 'کد پاتریس',
			'value'   => $code,
			'display' => '<span class="digitalogic-cart-patris-code" dir="ltr">' . esc_html( $code ) . '</span>',
		);

		return $item_data;
	}

	/**
	 * Persist the selected leaf Code for account pages, emails and order details.
	 *
	 * @param mixed  $item Order line item.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $values Cart item values.
	 * @param mixed  $order Order object.
	 */
	public function add_order_item_patris_code( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) || ! is_array( $values ) ) {
			return;
		}

		$product = isset( $values['data'] ) && $values['data'] instanceof WC_Product
			? $values['data']
			: false;
		if ( ! $product ) {
			$product_id = ! empty( $values['variation_id'] ) ? absint( $values['variation_id'] ) : absint( $values['product_id'] ?? 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
		}

		$code = $this->get_product_patris_code( $product );
		if ( '' !== $code ) {
			$item->add_meta_data( 'کد پاتریس', $code, true );
		}
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
		$effective_price = trim( (string) $product->get_price() );
		if ( '' === $effective_price || $this->is_zero_decimal( $effective_price ) ) {
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
		$valid                    = true;
		$contains_converted_price = false;
		$normalized               = $this->normalize_toman_offer_node(
			$offer,
			$inherited_toman,
			$valid,
			$contains_converted_price
		);

		return $valid ? $normalized : $offer;
	}

	/**
	 * Normalize one offer subtree while sharing an all-or-nothing validity flag.
	 *
	 * @param mixed $offer Offer node or list.
	 * @param bool  $inherited_toman Whether the parent declared IRT.
	 * @param bool  $valid Whether every inherited Toman price is canonical.
	 * @param bool  $contains_converted_price Whether this subtree converted a price.
	 * @return mixed
	 */
	private function normalize_toman_offer_node( $offer, $inherited_toman, &$valid, &$contains_converted_price ) {
		if ( ! is_array( $offer ) ) {
			return $offer;
		}
		if ( array_is_list( $offer ) ) {
			$normalized = array();
			foreach ( $offer as $item ) {
				$child_converted = false;
				$normalized[]    = $this->normalize_toman_offer_node( $item, $inherited_toman, $valid, $child_converted );
				$contains_converted_price = $contains_converted_price || $child_converted;
			}

			return $normalized;
		}

		$declared_currency = strtoupper( trim( (string) ( $offer['priceCurrency'] ?? '' ) ) );
		$is_toman          = '' === $declared_currency ? $inherited_toman : 'IRT' === $declared_currency;
		if ( isset( $offer['priceSpecification'] ) ) {
			$child_converted             = false;
			$offer['priceSpecification'] = $this->normalize_toman_offer_node( $offer['priceSpecification'], $is_toman, $valid, $child_converted );
			$contains_converted_price    = $contains_converted_price || $child_converted;
		}
		if ( isset( $offer['offers'] ) ) {
			$child_converted          = false;
			$offer['offers']          = $this->normalize_toman_offer_node( $offer['offers'], $is_toman, $valid, $child_converted );
			$contains_converted_price = $contains_converted_price || $child_converted;
		}
		if ( ! $valid || ! $is_toman ) {
			return $offer;
		}

		foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $field ) {
			if ( array_key_exists( $field, $offer ) ) {
				$converted       = false;
				$offer[ $field ] = $this->multiply_decimal_by_ten( $offer[ $field ], $converted );
				if ( ! $converted ) {
					$valid = false;
					return $offer;
				}
				$contains_converted_price = true;
			}
		}
		if ( $contains_converted_price ) {
			$offer['priceCurrency'] = 'IRR';
		}

		return $offer;
	}

	/**
	 * Multiply one nonnegative canonical decimal by ten without float drift.
	 *
	 * @param mixed $value Decimal value.
	 * @param bool  $converted Whether conversion succeeded.
	 * @return mixed
	 */
	private function multiply_decimal_by_ten( $value, &$converted ) {
		$converted = false;
		$text = trim( (string) $value );
		if ( ! preg_match( '/^([0-9]+)(?:\.([0-9]+))?$/', $text, $matches ) ) {
			return $value;
		}
		$converted = true;

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
	 * Determine whether a canonical WooCommerce decimal represents zero.
	 *
	 * @param string $value Canonical decimal text.
	 * @return bool
	 */
	private function is_zero_decimal( $value ) {
		return 1 === preg_match( '/^0+(?:\.0+)?$/', $value );
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
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/css/product-identity.css' ) ?: DIGITALOGIC_VERSION
		);
		wp_enqueue_script(
			'digitalogic-product-identity',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/product-identity.js',
			array( 'jquery' ),
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/js/product-identity.js' ) ?: DIGITALOGIC_VERSION,
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
		$patris_code = $this->get_product_patris_code( $product );
		$is_variable = $product instanceof WC_Product && $product->is_type( 'variable' );
		$child_codes = '' === $patris_code ? $this->get_product_child_identities( $product ) : array();
		if ( '' === $patris_name && '' === $patris_code && ! $is_variable && empty( $child_codes ) ) {
			return;
		}

		wp_localize_script(
			'digitalogic-product-identity',
			'digitalogicProductIdentity',
			array(
				'singleProductPatrisName'            => $patris_name,
				'singleProductPatrisCode'            => $patris_code,
				'singleProductIsVariable'            => $is_variable || ! empty( $child_codes ),
				'singleProductChildCodes'            => $child_codes,
				'singleProductLegacyChildReferences' => ! $is_variable && ! empty( $child_codes ),
				'codeLabel'                          => 'کد پاتریس',
				'selectModelLabel'                   => 'مدل رو انتخاب کن تا کد دقیقش بیاد',
				'legacyChildNote'                    => 'این کدها فعلاً مرجع مدل‌ها هستن؛ برای انتخاب کد دقیق با پشتیبانی هماهنگ کن.',
			)
		);
	}

	/**
	 * Render one non-duplicated Patris name and customer-facing Code.
	 *
	 * @param mixed  $product Product candidate.
	 * @param string $context Rendering context.
	 */
	private function render_product_identity( $product, $context ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$patris_name = $this->get_product_patris_name( $product );
		$patris_code = $this->get_product_patris_code( $product );
		$is_variable = $product->is_type( 'variable' );
		$child_codes = 'single' === $context && '' === $patris_code ? $this->get_product_child_identities( $product ) : array();
		if ( '' === $patris_name && '' === $patris_code && ! $is_variable && empty( $child_codes ) ) {
			return;
		}

		echo $this->identity_markup( $patris_name, $patris_code, $is_variable, $context, $child_codes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Build the escaped identity component used by PHP and mirrored in JS.
	 *
	 * @param string $patris_name Reviewed English/Patris name.
	 * @param string $patris_code Exact Patris Code.
	 * @param bool   $is_variable Whether a child selection supplies the exact Code.
	 * @param string $context Rendering context.
	 * @param array  $child_codes Published child Code/name pairs.
	 * @return string
	 */
	private function identity_markup( $patris_name, $patris_code, $is_variable, $context, $child_codes = array() ) {
		$context = in_array( $context, array( 'single', 'loop' ), true ) ? $context : 'loop';
		$output  = '<div class="digitalogic-product-identity" data-digitalogic-product-identity="' . esc_attr( $context ) . '">';

		if ( '' !== $patris_name ) {
			$output .= '<div class="digitalogic-patris-name" dir="ltr" lang="en">' . esc_html( $patris_name ) . '</div>';
		}

		if ( '' !== $patris_code ) {
			$output .= '<div class="digitalogic-patris-code"><span>کد پاتریس</span><code dir="ltr">' . esc_html( $patris_code ) . '</code></div>';
		} elseif ( ! empty( $child_codes ) ) {
			$output .= '<div class="digitalogic-patris-code-list"><span>کدهای ثبت‌شده برای مدل‌ها</span><div>';
			foreach ( $child_codes as $child ) {
				$output .= '<span class="digitalogic-patris-code-item"><i>' . esc_html( $child['name'] ) . '</i><code dir="ltr">' . esc_html( $child['code'] ) . '</code></span>';
			}
			$output .= '</div></div>';
			if ( ! $is_variable ) {
				$output .= '<p class="digitalogic-patris-code-note">این کدها فعلاً مرجع مدل‌ها هستن؛ برای انتخاب کد دقیق با پشتیبانی هماهنگ کن.</p>';
			}
		} elseif ( $is_variable ) {
			$output .= '<div class="digitalogic-patris-code is-placeholder"><span>کد پاتریس</span><em>مدل رو انتخاب کن</em></div>';
		}

		return $output . '</div>';
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

	/**
	 * Read published child identities even for legacy parents whose Woo type or
	 * purchasability currently prevents the variation form from rendering.
	 *
	 * @param mixed $product Parent product candidate.
	 * @return array
	 */
	private function get_product_child_identities( $product ) {
		if ( ! $product instanceof WC_Product || $product->get_id() <= 0 ) {
			return array();
		}
		$product_id = $product->get_id();
		if ( array_key_exists( $product_id, $this->child_identity_cache ) ) {
			return $this->child_identity_cache[ $product_id ];
		}

		$child_ids  = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'post_parent'    => $product_id,
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
		$identities = array();

		foreach ( $child_ids as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product || $variation->get_parent_id() !== $product_id || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$code = $this->get_product_patris_code( $variation );
			if ( '' === $code ) {
				continue;
			}
			$name = $this->get_product_patris_name( $variation );
			if ( '' === $name ) {
				$name = trim( (string) $variation->get_name() );
			}
			$identities[ $code ] = array(
				'name' => $name,
				'code' => $code,
			);
		}

		$this->child_identity_cache[ $product_id ] = array_values( $identities );

		return $this->child_identity_cache[ $product_id ];
	}

	/**
	 * Resolve the exact leaf Code without mislabeling an unrelated Woo SKU.
	 *
	 * @param mixed $product Product candidate.
	 * @return string
	 */
	private function get_product_patris_code( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		return trim( (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
	}
}
