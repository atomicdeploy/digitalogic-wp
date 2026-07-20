<?php
/**
 * Google Sheets catalog tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies the canonical Sheets projection and REST adapter.
 */
final class GoogleSheetsCatalogTest extends TestCase {

	/**
	 * Catalog under test.
	 *
	 * @var Digitalogic_Google_Sheets_Catalog
	 */
	private $catalog;

	/** Prepare isolated catalog fixtures. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_terms']                              = array();
		$GLOBALS['digitalogic_test_wc_currency']                        = 'IRT';
		$GLOBALS['digitalogic_test_options']['woocommerce_weight_unit'] = 'kg';
		$this->catalog = Digitalogic_Google_Sheets_Catalog::instance();
	}

	/** Verify canonical fields and dynamic warehouse projections. */
	public function test_product_projection_uses_code_shipping_pricing_and_dynamic_warehouse_columns() {
		$products    = array(
			array(
				'id'                      => 41,
				'parent_id'               => 0,
				'type'                    => 'simple',
				'status'                  => 'publish',
				'name'                    => 'Arduino Uno',
				'part_number'             => 'UNO-R3',
				'sku'                     => 'SKU-41',
				'patris_product_code'     => '000123',
				'categories'              => array( array( 'name' => 'Development Boards' ) ),
				'category_ids'            => array( 8 ),
				'price'                   => '2450000',
				'patris_final_price'      => '2400000',
				'patris_price_status'     => 'calculated',
				'stock_quantity'          => '7',
				'stock_status'            => 'instock',
				'patris_total_stock'      => '12',
				'patris_minimum_stock'    => '2',
				'patris_warehouse_stock'  => array(
					'تهران'    => 7,
					'Shenzhen' => 5,
				),
				'patris_weight_grams'     => '240',
				'patris_foreign_price'    => '24.5',
				'patris_foreign_currency' => 'CNY',
				'canonical_url'           => 'https://digitalogic.test/product/uno',
				'image'                   => 'https://digitalogic.test/media/41',
				'patris_updated_at'       => '2026-07-20T12:00:00Z',
			),
		);
		$integration = array(
			'currency'            => array( 'local' => 'IRT' ),
			'selected_warehouses' => array( 'تهران' ),
			'shipping_methods'    => array(
				array(
					'id'               => 'air_express',
					'name'             => 'Air/Express',
					'price_per_kg_cny' => 85,
				),
			),
		);
		$assignments = array(
			'results' => array(
				array(
					'code'       => '000123',
					'status'     => 'ok',
					'assignment' => array(
						'shipping_method_id'    => 'air_express',
						'profit_percent'        => '30',
						'profit_percent_source' => 'global_default',
					),
				),
			),
		);

		$result = $this->catalog->transform_products( $products, $integration, $assignments );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 1, $result['rows'] );
		$row = $result['rows'][0];
		$this->assertSame( '000123', $row['sync_key'] );
		$this->assertSame( '000123', $row['patris_code'] );
		$this->assertSame( 2400000, $row['effective_price'] );
		$this->assertSame( 'air_express', $row['shipping_method_id'] );
		$this->assertSame( 'Air/Express', $row['shipping_method_name_en'] );
		$this->assertSame( 'حمل هوایی (اکسپرس)', $row['shipping_method_name_fa'] );
		$this->assertSame( 85, $row['shipping_price_per_kg_cny'] );
		$this->assertSame( 30, $row['profit_percent'] );
		$this->assertSame( 7, $row[ 'warehouse_stock:' . rawurlencode( 'تهران' ) ] );
		$this->assertSame( 5, $row['warehouse_stock:Shenzhen'] );
		$this->assertSame( 'ok', $row['sync_status'] );
		$this->assertStringStartsWith( 'sha256:', $row['record_revision'] );

		$keys = array_column( $result['columns'], 'key' );
		$this->assertContains( 'warehouse_stock:' . rawurlencode( 'تهران' ), $keys );
		$this->assertContains( 'warehouse_stock:Shenzhen', $keys );
		$this->assertSame( array(), array_diff( $keys, array_keys( $row ) ) );
	}

	/** Verify safe string fallbacks and visible data-quality warnings. */
	public function test_missing_code_uses_string_sku_fallback_and_exposes_actionable_status() {
		$result = $this->catalog->transform_products(
			array(
				array(
					'id'                  => 9,
					'sku'                 => '000009',
					'name'                => 'Unmatched Product',
					'patris_product_code' => '',
				),
			),
			array( 'currency' => array( 'local' => 'IRT' ) ),
			array(
				'results' => array(
					array(
						'code'   => '000009',
						'status' => 'error',
						'error'  => array( 'code' => 'digitalogic_product_not_found' ),
					),
				),
			)
		);

		$row = $result['rows'][0];
		$this->assertSame( '000009', $row['sync_key'] );
		$this->assertSame( '', $row['patris_code'] );
		$this->assertSame( 'warning', $row['sync_status'] );
		$this->assertStringContainsString( 'missing_patris_code', $row['sync_error'] );
		$this->assertStringContainsString( 'digitalogic_product_not_found', $row['sync_error'] );
		$this->assertStringContainsString( 'missing_effective_price', $row['sync_error'] );
	}

	/** Verify category pagination, separation, and Persian headers. */
	public function test_categories_are_bounded_separate_and_localized() {
		$GLOBALS['digitalogic_test_terms'] = array(
			(object) array(
				'term_id'     => 1,
				'name'        => 'Modules',
				'slug'        => 'modules',
				'parent'      => 0,
				'count'       => 5,
				'description' => '<b>Electronic</b>',
			),
			(object) array(
				'term_id'     => 2,
				'name'        => 'Sensors',
				'slug'        => 'sensors',
				'parent'      => 1,
				'count'       => 3,
				'description' => '',
			),
			(object) array(
				'term_id'     => 3,
				'name'        => 'Power',
				'slug'        => 'power',
				'parent'      => 0,
				'count'       => 2,
				'description' => '',
			),
		);

		$result = $this->catalog->get_page(
			array(
				'dataset' => 'categories',
				'locale'  => 'fa',
				'page'    => 1,
				'limit'   => 2,
			)
		);

		$this->assertSame( 'categories', $result['dataset'] );
		$this->assertSame( 3, $result['pagination']['total'] );
		$this->assertSame( 2, $result['pagination']['pages'] );
		$this->assertTrue( $result['pagination']['has_more'] );
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( 'دسته والد', $result['columns'][5]['header'] );
		$this->assertSame( 'Modules', $result['rows'][1]['parent_name'] );
		$this->assertSame( 'Electronic', $result['rows'][0]['description'] );
		$this->assertSame( 'https://digitalogic.test/product-category/sensors', $result['rows'][1]['permalink'] );
	}

	/** Verify unsupported contracts fail closed. */
	public function test_invalid_dataset_and_locale_fail_closed() {
		$invalid_dataset = $this->catalog->get_page( array( 'dataset' => 'orders' ) );
		$invalid_locale  = $this->catalog->get_page( array( 'locale' => 'de' ) );

		$this->assertSame( 'digitalogic_sheets_dataset_invalid', $invalid_dataset->get_error_code() );
		$this->assertSame( 400, $invalid_dataset->get_error_data()['status'] );
		$this->assertSame( 'digitalogic_sheets_locale_invalid', $invalid_locale->get_error_code() );
	}

	/** Verify REST errors remain bounded and secret-free. */
	public function test_rest_route_wraps_catalog_errors_without_exposing_credentials() {
		$api      = Digitalogic_REST_API::instance();
		$response = $api->get_google_sheets_catalog( new WP_REST_Request( array( 'dataset' => 'orders' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( 'digitalogic_sheets_dataset_invalid', $response->get_data()['code'] );
		$this->assertArrayNotHasKey( 'credentials', $response->get_data() );
	}
}
