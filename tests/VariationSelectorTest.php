<?php
/**
 * Model selector metadata and native WooCommerce contract checks.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-variation-selector.php';

/** Verify this presentation component does not become a pricing authority. */
final class VariationSelectorTest extends TestCase {
	/** Native markup remains intact; only published, explicit children provide metadata. */
	public function test_wraps_native_select_and_escapes_child_metadata_without_pricing() {
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
					'attribute_source_model'    => 103 === $id ? '' : 'model "quoted"',
					'_sku'                      => 'A-101',
					'_digitalogic_persian_name' => '<b>نام مدل</b>',
				),
			);
			$GLOBALS['digitalogic_test_wc_products'][ $id ] = new class( $id ) extends WC_Product_Variation {
				/** Return hostile HTML as a rendering boundary fixture. */
				public function get_description() {
					return '<p>First line</p><script>alert(1)</script>';
				}
				/**
				 * Pricing must never be read or calculated by the selector.
				 *
				 * @throws RuntimeException If presentation reads a price.
				 */
				public function get_price() {
					throw new RuntimeException( 'Presentation read a price.' );
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
		$this->assertStringNotContainsString( '<', $data['items'][0]['title'] );
		$this->assertStringNotContainsString( '<', $data['items'][0]['description'] );
		$this->assertArrayNotHasKey( 'price', $data['items'][0] );
		$args['attribute'] = 'color';
		$this->assertSame( $select, Digitalogic_Variation_Selector::render( $select, $args ) );
	}
}
