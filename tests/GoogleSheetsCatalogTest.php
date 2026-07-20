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
		$GLOBALS['digitalogic_test_options']              = array(
			'woocommerce_weight_unit'          => 'kg',
			'options_yuan_price'               => '30000',
			'options_update_date'              => '260720',
			'digitalogic_patris_feed_settings' => array(
				'selected_warehouses' => array( 'تهران' ),
			),
		);
		$GLOBALS['digitalogic_test_terms']                = array();
		$GLOBALS['digitalogic_test_posts']                = array();
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_meta_update_failures'] = array();
		$GLOBALS['digitalogic_test_meta_delete_failures'] = array();
		$GLOBALS['digitalogic_test_transaction_failures'] = array();
		$GLOBALS['digitalogic_test_wc_currency']          = 'IRT';
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();
		$this->reset_singleton( Digitalogic_Shipping_Method_Service::class );
		$this->reset_singleton( Digitalogic_WooCommerce_Currency_Status::class );
		$this->catalog = Digitalogic_Google_Sheets_Catalog::instance();
	}

	/** Verify the real canonical presenters and dynamic warehouse projection. */
	public function test_product_projection_uses_code_shipping_pricing_and_dynamic_warehouse_columns() {
		$GLOBALS['digitalogic_test_posts'][41] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array(
				'_digitalogic_patris_product_code' => '000123',
				'_digitalogic_shipping_method_id'  => 'air_express',
			),
		);
		$service                               = Digitalogic_Shipping_Method_Service::instance();
		$this->assertFalse( is_wp_error( $service->update_default_percentage_markup( '30' ) ) );

		$products = array(
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

		$result = $this->catalog->transform_products( $products );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 1, $result['rows'] );
		$row = $result['rows'][0];
		$this->assertSame( '000123', $row['sync_key'] );
		$this->assertSame( '000123', $row['patris_code'] );
		$this->assertSame( 2400000, $row['effective_price'] );
		$this->assertSame( 'air_express', $row['shipping_method_id'] );
		$this->assertSame( 'Air (Express)', $row['shipping_method_name_en'] );
		$this->assertSame( 'حمل هوایی (اکسپرس)', $row['shipping_method_name_fa'] );
		$this->assertSame( '85', $row['shipping_price_per_kg'] );
		$this->assertSame( 'CNY', $row['shipping_price_per_kg_currency'] );
		$this->assertSame( 30, $row['profit_percent'] );
		$this->assertSame( 7, $row[ 'warehouse_stock:' . rawurlencode( 'تهران' ) ] );
		$this->assertSame( 5, $row['warehouse_stock:Shenzhen'] );
		$this->assertSame( 'ok', $row['sync_status'] );
		$this->assertStringStartsWith( 'sha256:', $row['record_revision'] );

		$keys = array_column( $result['columns'], 'key' );
		$this->assertContains( 'warehouse_stock:' . rawurlencode( 'تهران' ), $keys );
		$this->assertContains( 'warehouse_stock:Shenzhen', $keys );
		$this->assertArrayNotHasKey( 'schema', $result );
	}

	/** Preserve the canonical shipping decimal in the numeric Sheets column. */
	public function test_shipping_decimal_is_not_coerced_through_a_float() {
		$result = $this->catalog->transform_products(
			array(
				array(
					'id'                  => 42,
					'patris_product_code' => 'EXACT-42',
					'name'                => 'Exact shipping decimal',
				),
			),
			array(
				'currency' => array('local' => 'IRT'),
				'shipping_methods' => array(
					array(
						'id' => 'exact',
						'name' => 'Exact',
						'enabled' => true,
						'currency' => 'CNY',
						'price_per_kg' => '1.234567890125',
					),
				),
			),
			array(
				'results' => array(
					array(
						'code' => 'EXACT-42',
						'status' => 'ok',
						'assignment' => array(
							'shipping_method_id' => 'exact',
							'profit_percent_source' => 'unavailable',
							'pricing_warnings' => array(),
						),
					),
				),
			)
		);

		$this->assertFalse(is_wp_error($result));
		$this->assertSame('1.234567890125', $result['rows'][0]['shipping_price_per_kg']);
		$column = array_values(array_filter(
			$result['columns'],
			static fn($candidate) => 'shipping_price_per_kg' === $candidate['key']
		))[0];
		$this->assertSame('number', $column['type']);
		$this->assertStringContainsString(
			'"shipping_price_per_kg":"1.234567890125"',
			json_encode($result['rows'][0], JSON_THROW_ON_ERROR)
		);
	}

	/** Verify SKU is never used for Patris matching or as the primary sync key. */
	public function test_missing_code_uses_woo_key_and_never_matches_sku_assignment() {
		$result = $this->catalog->transform_products(
			array(
				array(
					'id'   => 9,
					'sku'  => '000009',
					'name' => 'Unmatched Product',
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
		$this->assertSame( 'woo:9', $row['sync_key'] );
		$this->assertArrayNotHasKey( 'patris_code', $row );
		$this->assertSame( '000009', $row['sku'] );
		$this->assertSame( 'warning', $row['sync_status'] );
		$this->assertStringContainsString( 'missing_patris_code', $row['sync_error'] );
		$this->assertStringNotContainsString( 'digitalogic_product_not_found', $row['sync_error'] );
		$this->assertStringContainsString( 'missing_effective_price', $row['sync_error'] );
	}

	/** Verify omission and explicit null remain distinguishable in JSON and hashes. */
	public function test_sparse_rows_preserve_missing_versus_explicit_null() {
		$base                           = array(
			'id'                  => 70,
			'patris_product_code' => 'CODE-70',
			'name'                => 'Sparse row',
		);
		$missing                        = $this->catalog->transform_products(
			array( $base ),
			array( 'currency' => array( 'local' => 'IRT' ) ),
			array( 'results' => array() )
		);
		$explicit_null                  = $base;
		$explicit_null['regular_price'] = null;
		$with_null                      = $this->catalog->transform_products(
			array( $explicit_null ),
			array( 'currency' => array( 'local' => 'IRT' ) ),
			array( 'results' => array() )
		);

		$missing_row = $missing['rows'][0];
		$null_row    = $with_null['rows'][0];
		$this->assertArrayNotHasKey( 'regular_price', $missing_row );
		$this->assertArrayNotHasKey( 'effective_price', $missing_row );
		$this->assertArrayHasKey( 'regular_price', $null_row );
		$this->assertNull( $null_row['regular_price'] );
		$this->assertArrayHasKey( 'effective_price', $null_row );
		$this->assertNull( $null_row['effective_price'] );
		$this->assertStringNotContainsString( ':null', wp_json_encode( $missing_row ) );
		$this->assertNotSame( $missing_row['record_revision'], $null_row['record_revision'] );
	}

	/** Verify a stable row identity is mandatory and never synthesized as woo:0. */
	public function test_product_without_code_or_positive_id_fails_closed() {
		$result = $this->catalog->transform_products(
			array( array( 'name' => 'No identity' ) ),
			array( 'currency' => array( 'local' => 'IRT' ) ),
			array( 'results' => array() )
		);

		$this->assertSame( 'digitalogic_sheets_sync_key_missing', $result->get_error_code() );
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
		$this->assertSame(
			array( 'dataset', 'locale', 'generated_at', 'page_revision', 'columns', 'rows', 'pagination' ),
			array_keys( $result )
		);
		$this->assertArrayNotHasKey( 'parent_name', $result['rows'][0] );
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

	/**
	 * Reset one singleton between isolated tests.
	 *
	 * @param string $class_name Singleton class name.
	 */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
