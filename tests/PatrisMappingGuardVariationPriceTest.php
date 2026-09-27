<?php
/**
 * Identity-guarded variation price contract checks.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-patris-mapping-guard.php';

/** Verify raw and HTML variation prices remain paired without leaking unsafe prices. */
final class PatrisMappingGuardVariationPriceTest extends TestCase {
	/** Build an isolated mapped variation fixture. */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['digitalogic_test_posts']       = array(
			100 => array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'product_type' => 'variable',
				'meta'         => array(),
			),
			101 => array(
				'post_type'    => 'product_variation',
				'post_status'  => 'publish',
				'product_type' => 'variation',
				'post_parent'  => 100,
				'meta'         => array(
					'_sku'                                => '114005004',
					'_price'                              => '293500',
					'_regular_price'                      => '293500',
					'_digitalogic_patris_product_code'    => '114005004',
					'_digitalogic_patris_owner_product_code' => '114005004',
					'_digitalogic_patris_owner_source_id' => 'patris-export',
					'_digitalogic_patris_owner_dataset'   => 'kala',
					'_digitalogic_patris_weight_grams'    => '3',
				),
			),
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();
		$GLOBALS['digitalogic_test_options']     = array(
			'digitalogic_product_sync_state' => array(
				'sources' => array(
					array(
						'source'           => array(
							'id'      => 'patris-export',
							'dataset' => 'kala',
						),
						'products'         => array( '114005004' => array() ),
						'applied_products' => array(
							'114005004' => array( 'woocommerce_id' => 101 ),
						),
					),
				),
			),
		);
		Digitalogic_Patris_Mapping_Guard::clear_index();
	}

	/** Woo's equal-price empty HTML is restored and paired with exact raw price. */
	public function test_priced_variation_exposes_paired_raw_and_html_contract(): void {
		$variation = new class( 101 ) extends WC_Product_Variation {
			/** Return the server-owned formatted price. */
			public function get_price_html() {
				return '<span class="woocommerce-Price-amount amount">293,500 Toman</span>';
			}
		};

		$data = Digitalogic_Patris_Mapping_Guard::variation_data(
			array(
				'price_html'     => '',
				'display_price'  => 293500,
				'is_purchasable' => true,
			),
			new WC_Product_Variable( 100 ),
			$variation
		);

		$this->assertSame( 1, $data['digitalogic_price_contract'] );
		$this->assertSame( '293500', $data['digitalogic_price_raw'] );
		$this->assertSame( 'IRT', $data['digitalogic_price_currency'] );
		$this->assertSame( 0, $data['digitalogic_price_decimals'] );
		$this->assertSame( $data['price_html'], $data['digitalogic_price_html'] );
		$this->assertStringContainsString( 'data-digitalogic-price-raw="293500"', $data['price_html'] );
		$this->assertStringContainsString( 'woocommerce-Price-amount', $data['price_html'] );
		$this->assertTrue( $data['is_purchasable'] );
	}

	/** Invalid identity remains non-purchasable and exposes no raw or HTML price. */
	public function test_unmapped_variation_exposes_explicit_unavailable_contract(): void {
		$GLOBALS['digitalogic_test_posts'][101]['meta']['_digitalogic_patris_owner_product_code'] = 'OTHER';
		$GLOBALS['digitalogic_test_wc_products'] = array();
		Digitalogic_Patris_Mapping_Guard::clear_index();
		$variation = new WC_Product_Variation( 101 );

		$data = Digitalogic_Patris_Mapping_Guard::variation_data(
			array(
				'price_html'            => '<span>must disappear</span>',
				'display_price'         => 293500,
				'display_regular_price' => 293500,
				'is_purchasable'        => true,
			),
			new WC_Product_Variable( 100 ),
			$variation
		);

		$this->assertSame( 1, $data['digitalogic_price_contract'] );
		$this->assertNull( $data['digitalogic_price_raw'] );
		$this->assertSame( '', $data['digitalogic_price_html'] );
		$this->assertSame( 0, $data['digitalogic_price_decimals'] );
		$this->assertSame( '', $data['price_html'] );
		$this->assertNull( $data['display_price'] );
		$this->assertNull( $data['display_regular_price'] );
		$this->assertFalse( $data['is_purchasable'] );
	}
}
