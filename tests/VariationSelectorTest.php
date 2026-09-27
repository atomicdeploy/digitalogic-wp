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
		foreach ( array( 101, 102, 103 ) as $id ) {
			$GLOBALS['digitalogic_test_posts'][ $id ]       = array(
				'post_type'   => 'product_variation',
				'post_parent' => 100,
				'post_status' => 102 === $id ? 'draft' : 'publish',
				'meta'        => array(
					'attribute_source_model'           => 103 === $id ? '' : 'model "quoted"',
					'_sku'                             => 'A-101',
					'_digitalogic_patris_product_code' => '114005004',
					'_digitalogic_persian_name'        => '<b>نام مدل</b>',
				),
			);
			$GLOBALS['digitalogic_test_wc_products'][ $id ] = new class( $id ) extends WC_Product_Variation {
				/** Return hostile HTML as a rendering boundary fixture. */
				public function get_description() {
					return '<p>First line</p><script>alert(1)</script>';
				}
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
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'model "quoted"', $data['items'][0]['value'] );
		$this->assertSame( 'A-101', $data['items'][0]['sku'] );
		$this->assertSame( '114005004', $data['items'][0]['productCode'] );
		$this->assertSame( '293500', $data['items'][0]['priceRaw'] );
		$this->assertSame( '293,500 تومان', $data['items'][0]['priceText'] );
		$this->assertStringContainsString( '293,500', $data['items'][0]['priceHtml'] );
		$this->assertStringNotContainsString( '<', $data['items'][0]['title'] );
		$this->assertStringNotContainsString( '<', $data['items'][0]['description'] );
		$args['attribute'] = 'color';
		$this->assertSame( $select, Digitalogic_Variation_Selector::render( $select, $args ) );
	}
}
