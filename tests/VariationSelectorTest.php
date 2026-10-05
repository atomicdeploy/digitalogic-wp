<?php
/**
 * Model selector metadata and native WooCommerce contract checks.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-variation-selector.php';

/** Verify this presentation consumes child-owned identity and price metadata. */
final class VariationSelectorTest extends TestCase {
	/** Native markup remains intact; only published, explicit children provide metadata. */
	public function test_wraps_native_select_and_exposes_child_price_without_calculating_it() {
		$GLOBALS['digitalogic_test_posts']       = array();
		$GLOBALS['digitalogic_test_wc_products'] = array();
		$GLOBALS['digitalogic_test_posts'][100]  = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'variable',
			'meta'         => array( '_thumbnail_id' => 99 ),
		);
		foreach ( array( 101, 102, 103, 104 ) as $id ) {
			$GLOBALS['digitalogic_test_posts'][ $id ]       = array(
				'post_type'   => 'product_variation',
				'post_parent' => 100,
				'post_status' => 102 === $id ? 'draft' : 'publish',
				'meta'        => array(
					'attribute_source_model'           => 103 === $id ? '' : ( 104 === $id ? 'model-unavailable' : 'model "quoted"' ),
					'attribute_pa_ram'                 => 103 === $id ? '' : ( 104 === $id ? 'model-unavailable' : 'model "quoted"' ),
					'_sku'                             => 'A-101',
					'_digitalogic_patris_product_code' => '114005004',
					'_digitalogic_persian_name'        => '<b>نام مدل</b>',
					'_stock_status'                    => 104 === $id ? 'outofstock' : 'instock',
				),
			);
			$GLOBALS['digitalogic_test_wc_products'][ $id ] = new class( $id ) extends WC_Product_Variation {
				/** Return hostile HTML as a rendering boundary fixture. */
				public function get_description() {
					return '<p>First line</p><script>alert(1)</script>';
				}
				/** Return an exact raw price for the selector contract. */
				public function get_price() {
					return '293500';
				}
				/** Return WooCommerce-owned display HTML without client calculation. */
				public function get_price_html() {
					return '<span class="price">293,500 تومان</span>';
				}
			};
		}
		$select = '<select id="source_model" name="attribute_source_model"><option value="">Choose</option></select>';
		$args   = array(
			'attribute' => 'source_model',
			'product'   => new WC_Product_Variable( 100 ),
		);
		$html   = Digitalogic_Variation_Selector::render( $select, $args );
		$this->assertStringContainsString( $select, $html );
		$this->assertSame( 1, preg_match( '/data-digitalogic-model-selector="([^"]+)"/', $html, $match ) );
		$data = json_decode( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), true );
		$this->assertCount( 2, $data['items'] );
		$items = array_column( $data['items'], null, 'value' );
		$this->assertSame( 'A-101', $items['model "quoted"']['sku'] );
		$this->assertSame( '114005004', $items['model "quoted"']['productCode'] );
		$this->assertSame( '293500', $items['model "quoted"']['priceRaw'] );
		$this->assertSame( '293,500 تومان', $items['model "quoted"']['priceText'] );
		$this->assertStringContainsString( '293,500', $items['model "quoted"']['priceHtml'] );
		$this->assertTrue( $items['model "quoted"']['available'] );
		$this->assertFalse( $items['model-unavailable']['available'] );
		$this->assertNull( $items['model-unavailable']['priceRaw'] );
		$this->assertSame( '', $items['model-unavailable']['priceHtml'] );
		$this->assertSame( '', $items['model-unavailable']['priceText'] );
		$this->assertStringNotContainsString( '<', $items['model "quoted"']['title'] );
		$this->assertStringNotContainsString( '<', $items['model "quoted"']['description'] );
		$this->assertSame( 'ناموجود', $data['unavailable'] );
		foreach ( array( 101, 103, 104 ) as $id ) {
			$GLOBALS['digitalogic_test_posts'][ $id ]['meta']['attribute_pa_model'] = $GLOBALS['digitalogic_test_posts'][ $id ]['meta']['attribute_source_model'];
		}
		$args['attribute'] = 'pa_model';
		$taxonomy_html     = Digitalogic_Variation_Selector::render( $select, $args );
		$this->assertStringContainsString( 'data-digitalogic-model-selector=', $taxonomy_html );
		$args['attribute'] = 'pa_ram';
		$ram_html          = Digitalogic_Variation_Selector::render( $select, $args );
		$this->assertSame( 1, preg_match( '/data-digitalogic-model-selector="([^"]+)"/', $ram_html, $ram_match ) );
		$ram_data = json_decode( html_entity_decode( $ram_match[1], ENT_QUOTES, 'UTF-8' ), true );
		$this->assertSame( 'حافظه رم', $ram_data['label'] );
		$this->assertSame( 'انتخاب حافظه رم', $ram_data['placeholder'] );
		$args['attribute'] = 'color';
		$this->assertSame( $select, Digitalogic_Variation_Selector::render( $select, $args ) );
	}

	/** The unenhanced native select remains a full-width, usable fallback. */
	public function test_native_select_fallback_has_responsive_width_contract() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/variation-selector.css' );
		$this->assertStringContainsString( '.digitalogic-model-selector > select', $css );
		$this->assertStringContainsString( 'width: 100% !important', $css );
		$this->assertStringContainsString( 'table.variations:has(.digitalogic-model-selector)', $css );
		$this->assertStringContainsString( 'grid-template-columns: minmax(0, 1fr) !important', $css );
		$this->assertStringContainsString( '> .single_variation_wrap', $css );
	}
}
