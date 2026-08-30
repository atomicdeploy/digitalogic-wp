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
		$GLOBALS['digitalogic_test_options']               = array(
			'digitalogic_report_cache_generation' => 'test-generation',
			'woocommerce_weight_unit'          => 'kg',
			'options_yuan_price'               => '30000',
			'options_update_date'              => '260720',
			'digitalogic_patris_feed_settings' => array(
				'selected_warehouses' => array( 'تهران' ),
			),
		);
		$GLOBALS['digitalogic_test_terms']                 = array();
		$GLOBALS['digitalogic_test_posts']                 = array();
		$GLOBALS['digitalogic_test_wc_products']           = array();
		$GLOBALS['digitalogic_test_option_cache']          = array();
		$GLOBALS['digitalogic_test_post_meta_cache']       = array();
		$GLOBALS['digitalogic_test_meta_update_failures']  = array();
		$GLOBALS['digitalogic_test_meta_delete_failures']  = array();
		$GLOBALS['digitalogic_test_transaction_failures']  = array();
		$GLOBALS['digitalogic_test_wp_query_results']      = array();
		$GLOBALS['digitalogic_test_wp_query_args']         = array();
		$GLOBALS['digitalogic_test_wc_product_query_args'] = array();
		$GLOBALS['digitalogic_test_wc_currency']           = 'IRT';
		$GLOBALS['wpdb']                                   = new Digitalogic_Test_WPDB();
		$this->reset_singleton( Digitalogic_Shipping_Method_Service::class );
		$this->reset_singleton( Digitalogic_WooCommerce_Currency_Status::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$this->reset_singleton( Digitalogic_Report_Engine::class );
		$this->catalog = Digitalogic_Google_Sheets_Catalog::instance();
	}

	/** Cheap revision is typed and fails closed for an incomplete exact source scope. */
	public function test_catalog_revision_is_typed_and_fail_closed() {
		$this->store_source(
			array(
				'0001' => array(
					'product_code'      => '0001',
					'name'              => 'Current product',
					'source_updated_at' => '2026-08-30T00:00:00Z',
				),
			)
		);

		$result = $this->catalog->get_revision();
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic.google-sheets-catalog-revision/v1', $result['schema'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $result['revision'] );

		$invalid = $this->catalog->get_revision( array( 'source_id' => 'source-only' ) );
		$this->assertTrue( is_wp_error( $invalid ) );
		$this->assertSame( 'digitalogic_report_projection_scope_incomplete', $invalid->get_error_code() );
	}

	/** Oversized requests are capped at 250 rows assembled through 100-row DB queries. */
	public function test_large_catalog_page_uses_bounded_internal_query_windows() {
		$this->seed_catalog_products( 1, 260 );
		$GLOBALS['digitalogic_test_wp_query_results'] = array(
			array(
				'posts'       => range( 1, 100 ),
				'found_posts' => 260,
			),
			array(
				'posts'       => range( 101, 200 ),
				'found_posts' => 260,
			),
			array(
				'posts'       => range( 201, 260 ),
				'found_posts' => 260,
			),
		);

		$result = $this->catalog->get_page(
			array(
				'dataset' => 'products',
				'locale'  => 'fa',
				'page'    => 1,
				'limit'   => 500,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 250, $result['rows'] );
		$this->assertSame( 'woo:1', $result['rows'][0]['sync_key'] );
		$this->assertSame( 'woo:250', $result['rows'][249]['sync_key'] );
		$this->assertSame( 250, count( array_unique( array_column( $result['rows'], 'sync_key' ) ) ) );
		$this->assertSame( 250, $result['pagination']['limit'] );
		$this->assertSame( 260, $result['pagination']['total'] );
		$this->assertSame( 2, $result['pagination']['pages'] );
		$this->assertTrue( $result['pagination']['has_more'] );
		$this->assertCount( 3, $GLOBALS['digitalogic_test_wp_query_args'] );
		$this->assertSame( array( 100, 100, 100 ), array_column( $GLOBALS['digitalogic_test_wp_query_args'], 'posts_per_page' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $GLOBALS['digitalogic_test_wp_query_args'], 'paged' ) );
	}

	/** A non-aligned external page preserves exact row boundaries across query windows. */
	public function test_non_aligned_catalog_page_does_not_skip_or_duplicate_products() {
		$this->seed_catalog_products( 101, 260 );
		$GLOBALS['digitalogic_test_wp_query_results'] = array(
			array(
				'posts'       => range( 101, 200 ),
				'found_posts' => 260,
			),
			array(
				'posts'       => range( 201, 260 ),
				'found_posts' => 260,
			),
		);

		$result = $this->catalog->get_page(
			array(
				'dataset' => 'products',
				'locale'  => 'fa',
				'page'    => 2,
				'limit'   => 125,
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 125, $result['rows'] );
		$this->assertSame( 'woo:126', $result['rows'][0]['sync_key'] );
		$this->assertSame( 'woo:250', $result['rows'][124]['sync_key'] );
		$this->assertSame( 125, count( array_unique( array_column( $result['rows'], 'sync_key' ) ) ) );
		$this->assertSame(
			array(
				'page'     => 2,
				'limit'    => 125,
				'total'    => 260,
				'pages'    => 3,
				'has_more' => true,
			),
			$result['pagination']
		);
		$this->assertSame( array( 2, 3 ), array_column( $GLOBALS['digitalogic_test_wp_query_args'], 'paged' ) );
		$this->assertSame( array( 100, 100 ), array_column( $GLOBALS['digitalogic_test_wp_query_args'], 'posts_per_page' ) );
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
		$this->assertSame( 'woo:41', $row['sync_key'] );
		$this->assertSame( '000123', $row['patris_code'] );
		$code_column = array_values(
			array_filter(
				$result['columns'],
				static fn( $candidate ) => 'patris_code' === $candidate['key']
			)
		)[0];
		$this->assertSame( 'Product Code', $code_column['label_en'] );
		$this->assertSame( 'کد کالا', $code_column['label_fa'] );
		$this->assertSame( 2400000, $row['effective_price'] );
		$this->assertSame( 'air_express', $row['shipping_method_id'] );
		$this->assertSame( 'Air (Express)', $row['shipping_method_name_en'] );
		$this->assertSame( 'حمل هوایی (اکسپرس)', $row['shipping_method_name_fa'] );
		$this->assertSame( '85', $row['shipping_price_per_kg'] );
		$this->assertSame( 'CNY', $row['shipping_price_per_kg_currency'] );
		$this->assertSame( 30, $row['profit_margin_percent'] );
		$this->assertArrayNotHasKey( 'profit_percent_source', $row );
		$this->assertSame( 7, $row[ 'warehouse_stock:' . rawurlencode( 'تهران' ) ] );
		$this->assertSame( 5, $row['warehouse_stock:Shenzhen'] );
		$this->assertSame( 'ok', $row['sync_status'] );
		$this->assertStringStartsWith( 'sha256:', $row['record_revision'] );

		$keys = array_column( $result['columns'], 'key' );
		$this->assertContains( 'warehouse_stock:' . rawurlencode( 'تهران' ), $keys );
		$this->assertContains( 'warehouse_stock:Shenzhen', $keys );
		$this->assertArrayNotHasKey( 'schema', $result );
	}

	/** The reconciled union is leaf-only, page-stable, and uses durable row identities. */
	public function test_reconciled_products_union_has_stable_keys_columns_and_source_woo_ownership() {
		$updated_at = '2026-07-27T08:00:00Z';
		$this->store_source(
			array(
				'000123' => array(
					'product_code'                   => '000123',
					'name'                           => 'Source matched name',
					'foreign_currency'               => 'CNY',
					'foreign_price'                  => '120',
					'partner_price_source'           => '500000',
					'price_source_amount'            => '120',
					'price_source_currency'          => 'CNY',
					'price_source_kind'              => 'foreign_price',
					'price_rounding_digits'          => 0,
					'price_rounding_mode'            => 'nearest_half_up',
					'weight_grams'                   => '350',
					'total_stock'                    => '11',
					'location'                       => 'Shenzhen',
					'warehouse_stock'                => array( 'Shenzhen' => 11 ),
					'shipping_method_id'             => 'air_express',
					'shipping_price_per_kg'          => '120',
					'shipping_price_per_kg_currency' => 'CNY',
					'markup_percent'                 => '30',
					'final_price'                    => '5100000',
					'source_updated_at'              => $updated_at,
					'warnings'                       => array(),
				),
				'ONLY-P' => array(
					'product_code' => 'ONLY-P',
					'name'         => 'Patris only',
					'location'     => 'Guangzhou',
					'warnings'     => array(),
				),
				'VAR'    => array(
					'product_code' => 'VAR',
					'name'         => 'Variation source',
					'warnings'     => array(),
				),
			)
		);
		$GLOBALS['digitalogic_test_terms']     = array(
			8 => array(
				'term_id'  => 8,
				'taxonomy' => 'product_cat',
				'name'     => 'Reviewed category',
				'slug'     => 'reviewed-category',
				'parent'   => 0,
			),
		);
		$GLOBALS['digitalogic_test_posts'][41] = $this->woo_post(
			'simple',
			'Woo matched name',
			array(
				'_digitalogic_patris_product_code' => '000123',
				'_regular_price'                   => '5000000',
				'_price'                           => '5000000',
				'_sale_price'                      => '',
				'_stock'                           => 11,
				'_stock_status'                    => 'instock',
			),
			array( 'category_ids' => array( 8 ) )
		);
		$GLOBALS['digitalogic_test_posts'][42] = $this->woo_post( 'simple', 'Woo only', array() );
		$GLOBALS['digitalogic_test_posts'][50] = $this->woo_post(
			'variable',
			'Variable parent',
			array(),
			array( 'category_ids' => array( 8 ) )
		);
		$GLOBALS['digitalogic_test_posts'][51] = $this->woo_post(
			'variation',
			'Variation leaf',
			array( '_digitalogic_patris_product_code' => 'VAR' ),
			array( 'post_parent' => 50 )
		);

		$first  = $this->catalog->get_page(
			array(
				'dataset' => 'reconciled_products',
				'locale'  => 'fa',
				'page'    => 1,
				'limit'   => 3,
			)
		);
		$second = $this->catalog->get_page(
			array(
				'dataset' => 'reconciled_products',
				'locale'  => 'fa',
				'page'    => 2,
				'limit'   => 3,
			)
		);

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertFalse( is_wp_error( $second ) );
		$this->assertSame( 4, $first['pagination']['total'] );
		$this->assertSame( 2, $first['pagination']['pages'] );
		$this->assertSame( $first['dataset_revision'], $second['dataset_revision'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $first['dataset_revision'] );
		$this->assertSame( array_column( $first['columns'], 'key' ), array_column( $second['columns'], 'key' ) );
		$this->assertSame(
			array(
				'sync_key',
				'reconciliation_status',
				'patris_code',
				'woocommerce_id',
				'parent_id',
				'product_type',
				'publication_status',
				'name',
				'part_number',
				'sku',
				'categories',
				'category_ids',
				'currency',
				'regular_price',
				'sale_price',
				'effective_price',
				'patris_final_price',
				'price_status',
				'stock_quantity',
				'stock_status',
				'patris_total_stock',
				'patris_minimum_stock',
				'patris_location',
				'weight_grams',
				'woocommerce_weight',
				'woocommerce_weight_unit',
				'foreign_price',
				'foreign_currency',
				'partner_price_irr',
				'price_source_amount',
				'price_source_currency',
				'price_source_kind',
				'price_rounding_digits',
				'price_rounding_mode',
				'shipping_method_id',
				'shipping_method_name_en',
				'shipping_method_name_fa',
				'shipping_price_per_kg',
				'shipping_price_per_kg_currency',
				'profit_margin_percent',
				'permalink',
				'image_url',
				'updated_at',
				'sync_status',
				'sync_error',
				'record_revision',
			),
			array_column( $first['columns'], 'key' )
		);
		$this->assertNotContains( Digitalogic_Product_Column_Schema::warehouse_key( 'Shenzhen' ), array_column( $first['columns'], 'key' ) );

		$rows = array_merge( $first['rows'], $second['rows'] );
		$this->assertCount( 4, $rows );
		$this->assertSame( 4, count( array_unique( array_column( $rows, 'sync_key' ) ) ) );
		$this->assertContains( 'patris:ONLY-P', array_column( $rows, 'sync_key' ) );
		$this->assertNotContains( 'woo:50', array_column( $rows, 'sync_key' ) );
		$this->assertNotContains( '000123', array_column( $rows, 'sync_key' ) );

		$this->assertContains( 'patris:000123', array_column( $rows, 'sync_key' ) );
		$matched = $this->find_catalog_row( $rows, 'patris:000123' );
		$this->assertSame( 'matched', $matched['reconciliation_status'] );
		$this->assertSame( '000123', $matched['patris_code'] );
		$this->assertSame( 41, $matched['woocommerce_id'] );
		$this->assertSame( 'Source matched name', $matched['name'] );
		$this->assertSame( 'Shenzhen', $matched['patris_location'] );
		$this->assertSame( 350, $matched['weight_grams'] );
		$this->assertSame( 120, $matched['foreign_price'] );
		$this->assertSame( 500000, $matched['partner_price_irr'] );
		$this->assertSame( 120, $matched['price_source_amount'] );
		$this->assertSame( 'CNY', $matched['price_source_currency'] );
		$this->assertSame( 'foreign_price', $matched['price_source_kind'] );
		$this->assertSame( 0, $matched['price_rounding_digits'] );
		$this->assertSame( 'nearest_half_up', $matched['price_rounding_mode'] );
		$this->assertSame( 11, $matched['patris_total_stock'] );
		$this->assertSame( 5100000, $matched['patris_final_price'] );
		$this->assertSame( 5000000, $matched['effective_price'] );
		$this->assertStringContainsString( 'price_drift', $matched['sync_error'] );
		$this->assertSame( 'publish', $matched['publication_status'] );
		$this->assertSame( 'Reviewed category', $matched['categories'] );
		$this->assertSame( '8', $matched['category_ids'] );
		$this->assertSame( 'https://digitalogic.test/product/41', $matched['permalink'] );
		$this->assertArrayNotHasKey( Digitalogic_Product_Column_Schema::warehouse_key( 'Shenzhen' ), $matched );

		$this->assertSame(
			array(
				'patris_products'             => 3,
				'woocommerce_raw'             => 4,
				'woocommerce_leaves'          => 3,
				'union_rows'                  => 4,
				'matched'                     => 2,
				'source_only'                 => 1,
				'patris_only'                 => 1,
				'woo_only'                    => 1,
				'ambiguous_codes'             => 0,
				'variable_parents_excluded'   => 1,
				'quarantined_identity_groups' => 0,
				'quarantined_source_rows'     => 0,
				'quarantined_woo_rows'        => 0,
				'one_to_one_split_candidates' => 0,
				'identity_collision_groups'   => 0,
				'source_code_collision_groups' => 0,
				'woo_code_collision_groups'    => 0,
				'woo_sku_collision_groups'     => 0,
				'unsafe_identity_groups'       => 0,
			),
			$first['reconciliation']['counts']
		);
		$this->assertSame( 'current', $first['reconciliation']['integrity_status'] );
		$this->assertSame( array(), $first['reconciliation']['warnings'] );
	}

	/** A legitimate exact Product Code beginning with woo: is never mistaken for a sentinel. */
	public function test_reconciled_product_code_may_begin_with_woo_prefix() {
		$this->store_source(
			array(
				'woo:ABC' => array(
					'product_code' => 'woo:ABC',
					'name'         => 'Prefixed exact code',
					'warnings'     => array(),
				),
			)
		);
		$GLOBALS['digitalogic_test_posts'][61] = $this->woo_post(
			'simple',
			'Prefixed exact code',
			array( '_digitalogic_patris_product_code' => 'woo:ABC' )
		);

		$result = $this->catalog->get_page(
			array(
				'dataset' => 'reconciled_products',
				'page'    => 1,
				'limit'   => 10,
			)
		);
		$row    = $this->find_catalog_row( $result['rows'], 'patris:woo:ABC' );

		$this->assertSame( 'matched', $row['reconciliation_status'] );
		$this->assertSame( 'woo:ABC', $row['patris_code'] );
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
				'currency'         => array( 'local' => 'IRT' ),
				'shipping_methods' => array(
					array(
						'id'           => 'exact',
						'name'         => 'Exact',
						'enabled'      => true,
						'currency'     => 'CNY',
						'price_per_kg' => '1.234567890125',
					),
				),
			),
			array(
				'results' => array(
					array(
						'code'       => 'EXACT-42',
						'status'     => 'ok',
						'assignment' => array(
							'shipping_method_id'    => 'exact',
							'profit_percent_source' => 'unavailable',
							'pricing_warnings'      => array(),
						),
					),
				),
			)
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( '1.234567890125', $result['rows'][0]['shipping_price_per_kg'] );
		$column = array_values(
			array_filter(
				$result['columns'],
				static fn( $candidate ) => 'shipping_price_per_kg' === $candidate['key']
			)
		)[0];
		$this->assertSame( 'number', $column['type'] );
		$this->assertStringContainsString(
			'"shipping_price_per_kg":"1.234567890125"',
			wp_json_encode( $result['rows'][0], JSON_THROW_ON_ERROR )
		);
	}

	/** Exact source decimals must never collide after numeric display coercion. */
	public function test_record_revision_preserves_exact_price_precision() {
		$base                    = array(
			'id'                  => 43,
			'patris_product_code' => 'EXACT-43',
			'name'                => 'Exact revision decimal',
			'stock_status'        => 'instock',
		);
		$first                   = $base;
		$first['regular_price']  = '999999999999998.000001';
		$first['price']          = '999999999999998.000001';
		$second                  = $base;
		$second['regular_price'] = '999999999999998.000002';
		$second['price']         = '999999999999998.000002';

		$first_row  = $this->catalog->transform_products( array( $first ) )['rows'][0];
		$second_row = $this->catalog->transform_products( array( $second ) )['rows'][0];

		$this->assertSame( $first_row['regular_price'], $second_row['regular_price'] );
		$this->assertNotSame( $first_row['record_revision'], $second_row['record_revision'] );
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
	 * Seed deterministic product fixtures for catalog pagination tests.
	 *
	 * @param int $first_id First product ID.
	 * @param int $last_id Last product ID.
	 */
	private function seed_catalog_products( $first_id, $last_id ) {
		for ( $product_id = $first_id; $product_id <= $last_id; ++$product_id ) {
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Catalog product ' . $product_id,
				'meta'        => array(),
			);
		}
	}

	/**
	 * Store one deterministic living receiver source.
	 *
	 * @param array $products Sparse canonical source products.
	 */
	private function store_source( $products ) {
		update_option(
			Digitalogic_Product_Sync_Receiver::STATE_OPTION,
			array(
				'sources' => array(
					'test-source' => array(
						'source'          => array(
							'id'       => 'patris-export',
							'dataset'  => 'ALLANBAR',
							'revision' => 'sha256:test-source',
						),
						'generated_at'    => gmdate( 'c' ),
						'received_at'     => current_time( 'mysql' ),
						'last_event_id'   => 'sha256:test-event',
						'last_event_type' => 'snapshot',
						'products'        => $products,
					),
				),
			),
			false
		);
	}

	/**
	 * Create one WooCommerce test post.
	 *
	 * @param string $type Product type.
	 * @param string $title Product title.
	 * @param array  $meta Product metadata.
	 * @param array  $extra Additional post fields.
	 * @return array
	 */
	private function woo_post( $type, $title, $meta, $extra = array() ) {
		return array_merge(
			array(
				'post_type'    => 'variation' === $type ? 'product_variation' : 'product',
				'post_status'  => 'publish',
				'product_type' => $type,
				'post_title'   => $title,
				'meta'         => $meta,
			),
			$extra
		);
	}

	/**
	 * Find one catalog row by stable sync key.
	 *
	 * @param array  $rows Catalog rows.
	 * @param string $sync_key Stable row key.
	 * @return array
	 */
	private function find_catalog_row( $rows, $sync_key ) {
		foreach ( $rows as $row ) {
			if ( ( $row['sync_key'] ?? null ) === $sync_key ) {
				return $row;
			}
		}

		$this->fail( 'Catalog row not found: ' . $sync_key );
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
