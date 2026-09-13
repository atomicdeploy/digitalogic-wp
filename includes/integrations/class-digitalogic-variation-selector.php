<?php
/**
 * Accessible storefront presentation for the existing model attribute.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep WooCommerce's select as the only variation selection authority. */
final class Digitalogic_Variation_Selector {
	/** Register presentation hooks, including dynamically rendered product forms. */
	public static function init() {
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( self::class, 'render' ), 30, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ), 100 );
	}

	/** Load small assets globally so quick views and Elementor forms can initialize. */
	public static function enqueue_assets() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'digitalogic-variation-selector', DIGITALOGIC_PLUGIN_URL . 'assets/css/variation-selector.css', array(), (string) filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/css/variation-selector.css' ) );
		wp_enqueue_script( 'digitalogic-variation-selector', DIGITALOGIC_PLUGIN_URL . 'assets/js/variation-selector.js', array( 'jquery' ), (string) filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/js/variation-selector.js' ), true );
		$price_asset = DIGITALOGIC_PLUGIN_DIR . 'assets/js/variation-price-presentation.js';
		if ( file_exists( $price_asset ) ) {
			wp_enqueue_script(
				'digitalogic-variation-price-presentation',
				DIGITALOGIC_PLUGIN_URL . 'assets/js/variation-price-presentation.js',
				array( 'jquery', 'wc-add-to-cart-variation' ),
				(string) filemtime( $price_asset ),
				true
			);
		}
	}

	/**
	 * Wrap only the model dropdown and supply child-owned display metadata.
	 *
	 * @param string $html Original WooCommerce select HTML.
	 * @param array  $args Dropdown arguments.
	 * @return string Progressive enhancement wrapper, or unchanged select.
	 */
	public static function render( $html, $args ) {
		$attribute = $args['attribute'] ?? '';
		$product   = $args['product'] ?? null;
		if ( ! in_array( $attribute, array( 'source_model', 'pa_source_model' ), true ) || ! $product instanceof WC_Product_Variable ) {
			return $html;
		}
		$items = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child instanceof WC_Product_Variation || 'publish' !== $child->get_status() ) {
				continue;
			}
			$attributes = $child->get_variation_attributes();
			$value      = $attributes[ 'attribute_' . $attribute ] ?? '';
			if ( '' === $value ) {
				continue;
			}
			$image_id = $child->get_image_id();
			if ( ! $image_id ) {
				$image_id = $product->get_image_id();
			}
			$image       = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			$title       = $child->get_meta( '_digitalogic_persian_name', true );
			$description = $child->get_description();
			if ( '' === trim( (string) $description ) ) {
				$description = $child->get_meta( '_digitalogic_patris_name', true );
			}
			$items[] = array(
				'value'       => (string) $value,
				'attributes'  => $attributes,
				'title'       => sanitize_text_field( (string) $title ),
				'description' => sanitize_textarea_field( wp_strip_all_tags( (string) $description ) ),
				'sku'         => sanitize_text_field( $child->get_sku() ),
				'image'       => $image ? esc_url_raw( $image ) : '',
			);
		}
		$data = array(
			'items'       => $items,
			'label'       => __( 'مدل', 'digitalogic' ),
			'placeholder' => __( 'انتخاب مدل', 'digitalogic' ),
			'search'      => __( 'جستجوی مدل، توضیحات یا کد کالا…', 'digitalogic' ),
			'empty'       => __( 'مدلی با این مشخصات پیدا نشد.', 'digitalogic' ),
			'skuLabel'    => __( 'کد کالا', 'digitalogic' ),
		);
		return '<div class="digitalogic-model-selector" data-digitalogic-model-selector="' . esc_attr( wp_json_encode( $data ) ) . '">' . $html . '</div>';
	}
}
