<?php
/**
 * Current living Patris report tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers exact Code matching, sparse values, drift, bounds, and static input.
 */
final class CurrentPatrisReportTest extends TestCase {

	/** Reset shared WordPress state before each report assertion. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']         = array();
		$GLOBALS['digitalogic_test_option_cache']    = array();
		$GLOBALS['digitalogic_test_posts']           = array();
		$GLOBALS['digitalogic_test_wc_products']     = array();
		$GLOBALS['digitalogic_test_wc_product_query_args'] = array();
		$GLOBALS['digitalogic_test_capabilities']    = array();
		$GLOBALS['digitalogic_test_current_user_id'] = 0;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 0,
			'user_login'   => '',
			'display_name' => '',
			'roles'        => array(),
		);
		$GLOBALS['wpdb']                             = new Digitalogic_Test_WPDB();
		WP_CLI::$errors                              = array();
		WP_CLI::$logs                                = array();
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$this->reset_singleton( Digitalogic_Report_Engine::class );
		$this->reset_singleton( Digitalogic_REST_API::class );
	}

	/** Exact Code metadata is authoritative and sparse nulls remain distinct. */
	public function test_exact_code_matching_excludes_variable_parents_and_preserves_sparse_source_values(): void {
		$this->store_source(
			array(
				'CODE-A' => array(
					'product_code'      => 'CODE-A',
					'name'              => 'Source A',
					'foreign_currency'  => 'CNY',
					'foreign_price'     => null,
					'source_updated_at' => gmdate( 'c' ),
					'warnings'          => array(),
					'record_hash'       => 'sha256:source-a',
				),
				'CODE-B' => array(
					'product_code' => 'CODE-B',
					'name'         => 'Source B',
					'warnings'     => array(),
					'record_hash'  => 'sha256:source-b',
				),
				'CODE-V' => array(
					'product_code' => 'CODE-V',
					'name'         => 'Source variation',
					'warnings'     => array(),
					'record_hash'  => 'sha256:source-v',
				),
			)
		);

		$GLOBALS['digitalogic_test_posts'][101] = $this->woo_post(
			'simple',
			'Woo A',
			array(
				'_sku'                               => 'UNRELATED-SKU',
				'_digitalogic_patris_product_code'   => 'CODE-A',
				'_digitalogic_patris_foreign_price'  => '',
				'_digitalogic_patris_record_hash'    => 'sha256:source-a',
				'_digitalogic_patris_updated_at'     => gmdate( 'c' ),
				'_digitalogic_patris_null_fields'    => wp_json_encode( array( 'foreign_price' ) ),
				'_digitalogic_patris_missing_fields' => wp_json_encode( array( 'weight_grams' ) ),
			)
		);
		$GLOBALS['digitalogic_test_posts'][102] = $this->woo_post(
			'simple',
			'SKU-only B',
			array( '_sku' => 'CODE-B' )
		);
		$GLOBALS['digitalogic_test_posts'][103] = $this->woo_post(
			'variable',
			'Variable B',
			array( '_digitalogic_patris_product_code' => 'CODE-B' )
		);
		$GLOBALS['digitalogic_test_posts'][104] = $this->woo_post(
			'variation',
			'Woo variation',
			array( '_digitalogic_patris_product_code' => 'CODE-V' )
		);

		$report = Digitalogic_Report_Engine::instance()->get_report( array( 'view' => 'price_list' ) );

		$this->assertSame( 2, $report['counts']['matched_products'] );
		$this->assertSame( 1, $report['counts']['source_only_products'] );
		$this->assertSame( 1, $report['counts']['woocommerce_only_products'] );
		$this->assertSame( 1, $report['counts']['variable_parents_excluded'] );
		$this->assertNotNull( $this->find_row( $report['rows'], 'CODE-V', 'matched' ) );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_wc_product_query_args'] );
		$this->assertContains( 'variation', $GLOBALS['digitalogic_test_wc_product_query_args'][0]['type'] );

		$matched = $this->find_row( $report['rows'], 'CODE-A', 'matched' );
		$this->assertNull( $matched['source']['foreign_price'] );
		$this->assertArrayNotHasKey( 'weight_grams', $matched['source'] );
		$this->assertContains( 'null_foreign_price', $matched['issues'] );
		$this->assertContains( 'missing_weight', $matched['issues'] );

		$source_only = $this->find_row( $report['rows'], 'CODE-B', 'source_only' );
		$this->assertContains( 'missing_in_woocommerce', $source_only['issues'] );
		$this->assertArrayNotHasKey( 'woocommerce', $source_only );

		$woo_only = $this->find_row( $report['rows'], 'woo:102', 'woocommerce_only' );
		$this->assertSame( 'SKU-only B', $woo_only['woo_name'] );
		$this->assertContains( 'missing_product_code', $woo_only['issues'] );
	}

	/** Current persisted price, stock, weight, timestamp, and hash drift is visible. */
	public function test_reports_source_warnings_and_all_operational_drift_fields(): void {
		$updated_at = gmdate( 'c' );
		$this->store_source(
			array(
				'DRIFT-1' => array(
					'product_code'                   => 'DRIFT-1',
					'name'                           => 'Drift source',
					'foreign_currency'               => 'CNY',
					'foreign_price'                  => '100',
					'weight_grams'                   => '1000',
					'total_stock'                    => 10,
					'shipping_method_id'             => 'air_express',
					'shipping_price_per_kg'          => '20',
					'shipping_price_per_kg_currency' => 'CNY',
					'markup_percent'                 => '30',
					'irt_per_cny'                    => '30000',
					'final_price'                    => 4680000,
					'source_updated_at'              => $updated_at,
					'warnings'                       => array( 'review_source_row' ),
					'record_hash'                    => 'sha256:current-record',
				),
			)
		);
		$GLOBALS['digitalogic_test_posts'][201] = $this->woo_post(
			'simple',
			'Drift target',
			array(
				'_digitalogic_patris_product_code' => 'DRIFT-1',
				'_regular_price'                   => '1',
				'_price'                           => '1',
				'_stock'                           => 2,
				'_manage_stock'                    => 'no',
				'_stock_status'                    => 'outofstock',
				'_digitalogic_patris_final_price'  => '1',
				'_digitalogic_patris_total_stock'  => '2',
				'_digitalogic_patris_weight_grams' => '1',
				'_digitalogic_patris_record_hash'  => 'sha256:old-record',
				'_digitalogic_patris_updated_at'   => '2026-01-01T00:00:00Z',
			)
		);

		$report = Digitalogic_Report_Engine::instance()->get_report( array( 'view' => 'warnings' ) );
		$row    = $this->find_row( $report['rows'], 'DRIFT-1', 'matched' );

		$this->assertSame( $updated_at, $row['source_updated_at'] );
		$this->assertSame( array( 'review_source_row' ), $row['source_warnings'] );
		$this->assertContains( 'source_warning', $row['issues'] );
		$this->assertContains( 'price_drift', $row['issues'] );
		$this->assertContains( 'stock_drift', $row['issues'] );
		$this->assertContains( 'stock_management_drift', $row['issues'] );
		$this->assertContains( 'stock_status_drift', $row['issues'] );
		$this->assertContains( 'weight_drift', $row['issues'] );
		$this->assertContains( 'record_hash_drift', $row['issues'] );
		$this->assertContains( 'source_updated_at_drift', $row['issues'] );
		$this->assertSame( 1, $report['counts']['drift_products'] );
	}

	/** Active/sale prices and operational stock state cannot hide behind correct canonical values. */
	public function test_active_sale_price_and_stock_operational_state_are_checked(): void {
		$this->store_source(
			array(
				'OPS-1' => array(
					'product_code' => 'OPS-1',
					'final_price'  => 4680000,
					'total_stock'  => 10,
					'warnings'     => array(),
				),
			)
		);
		$GLOBALS['digitalogic_test_posts'][250] = $this->woo_post(
			'simple',
			'Operational drift',
			array(
				'_digitalogic_patris_product_code' => 'OPS-1',
				'_regular_price'                   => '4680000',
				'_price'                           => '1',
				'_sale_price'                      => '1',
				'_stock'                           => 10,
				'_manage_stock'                    => 'no',
				'_stock_status'                    => 'outofstock',
				'_digitalogic_patris_final_price'  => '4680000',
				'_digitalogic_patris_total_stock'  => '10',
			)
		);

		$report = Digitalogic_Report_Engine::instance()->get_report( array( 'view' => 'warnings' ) );
		$row    = $this->find_row( $report['rows'], 'OPS-1', 'matched' );

		$this->assertContains( 'price_drift', $row['issues'] );
		$this->assertSame( array( 'active_price', 'sale_price' ), $row['issue_fields']['price_drift'] );
		$this->assertContains( 'stock_management_drift', $row['issues'] );
		$this->assertContains( 'stock_status_drift', $row['issues'] );
		$this->assertNotContains( 'stock_drift', $row['issues'] );
	}

	/** Out-of-stock operational zeroing is not a false price drift. */
	public function test_expected_out_of_stock_price_zero_and_store_weight_are_current(): void {
		$updated_at = gmdate( 'c' );
		$source     = array(
			'product_code'                   => 'CURRENT-0',
			'foreign_currency'               => 'CNY',
			'foreign_price'                  => '100',
			'weight_grams'                   => '1000',
			'total_stock'                    => 0,
			'shipping_method_id'             => 'air_express',
			'shipping_price_per_kg'          => '20',
			'shipping_price_per_kg_currency' => 'CNY',
			'markup_percent'                 => '30',
			'irt_per_cny'                    => '30000',
			'final_price'                    => 4680000,
			'source_updated_at'              => $updated_at,
			'warnings'                       => array(),
			'record_hash'                    => 'sha256:current-zero',
		);
		$this->store_source( array( 'CURRENT-0' => $source ) );
		$GLOBALS['digitalogic_test_posts'][301] = $this->woo_post(
			'simple',
			'Current zero-stock product',
			array(
				'_digitalogic_patris_product_code' => 'CURRENT-0',
				'_regular_price'                   => '0',
				'_price'                           => '0',
				'_stock'                           => 0,
				'_manage_stock'                    => 'yes',
				'_stock_status'                    => 'outofstock',
				'_weight'                          => '1',
				'_digitalogic_patris_final_price'  => '4680000',
				'_digitalogic_patris_total_stock'  => '0',
				'_digitalogic_patris_weight_grams' => '1000',
				'_digitalogic_patris_record_hash'  => 'sha256:current-zero',
				'_digitalogic_patris_updated_at'   => $updated_at,
			)
		);

		$report = Digitalogic_Report_Engine::instance()->get_report( array( 'view' => 'price_list' ) );
		$row    = $this->find_row( $report['rows'], 'CURRENT-0', 'matched' );
		$this->assertContains( 'zero_stock', $row['issues'] );
		$this->assertNotContains( 'price_drift', $row['issues'] );
		$this->assertNotContains( 'stock_drift', $row['issues'] );
		$this->assertNotContains( 'stock_management_drift', $row['issues'] );
		$this->assertNotContains( 'stock_status_drift', $row['issues'] );
		$this->assertNotContains( 'weight_drift', $row['issues'] );
	}

	/** Missing receiver state withholds reconciliation instead of inventing Woo-only findings. */
	public function test_missing_source_state_withholds_reconciliation_findings(): void {
		$GLOBALS['digitalogic_test_posts'][350] = $this->woo_post(
			'simple',
			'Woo without a source snapshot',
			array( '_digitalogic_patris_product_code' => 'NO-SOURCE' )
		);

		$report = Digitalogic_Report_Engine::instance()->get_report( array( 'view' => 'warnings' ) );

		$this->assertSame( 'source_state_empty', $report['status'] );
		$this->assertSame( 1, $report['counts']['woocommerce_products'] );
		$this->assertSame( 0, $report['counts']['woocommerce_only_products'] );
		$this->assertSame( 0, $report['counts']['warning_products'] );
		$this->assertSame( 0, $report['pagination']['total'] );
		$this->assertSame( array(), $report['rows'] );
	}

	/** Positive-stock source-only products have a dedicated count and filter. */
	public function test_positive_stock_source_only_count_and_filter(): void {
		$this->store_source(
			array(
				'POSITIVE' => array(
					'product_code' => 'POSITIVE',
					'total_stock'  => '2.5',
					'warnings'     => array(),
				),
				'ZERO'     => array(
					'product_code' => 'ZERO',
					'total_stock'  => 0,
					'warnings'     => array(),
				),
			)
		);

		$report = Digitalogic_Report_Engine::instance()->get_report(
			array(
				'view'     => 'warnings',
				'category' => 'positive_stock_missing_in_woocommerce',
			)
		);

		$this->assertSame( 2, $report['counts']['source_only_products'] );
		$this->assertSame( 1, $report['counts']['positive_source_only_products'] );
		$this->assertSame( 1, $report['pagination']['total'] );
		$this->assertSame( 'POSITIVE', $report['rows'][0]['product_code'] );
		$this->assertContains( 'positive_stock_missing_in_woocommerce', $report['rows'][0]['issues'] );
	}

	/** Warning and price-list transports share deterministic bounded pagination. */
	public function test_price_list_is_paginated_and_category_filter_is_bounded(): void {
		$products = array();
		foreach ( array( 'PAGE-A', 'PAGE-B', 'PAGE-C' ) as $code ) {
			$products[ $code ] = array(
				'product_code' => $code,
				'warnings'     => array(),
				'record_hash'  => 'sha256:' . strtolower( $code ),
			);
		}
		$this->store_source( $products );

		$price_page = Digitalogic_Report_Engine::instance()->get_report(
			array(
				'view'     => 'price_list',
				'page'     => 2,
				'per_page' => 1,
			)
		);
		$this->assertSame( 3, $price_page['pagination']['total'] );
		$this->assertSame( 3, $price_page['pagination']['pages'] );
		$this->assertSame( 'PAGE-B', $price_page['rows'][0]['product_code'] );

		$warning_page = Digitalogic_Report_Engine::instance()->get_report(
			array(
				'view'     => 'warnings',
				'category' => 'missing_in_woocommerce',
				'per_page' => 5000,
			)
		);
		$this->assertSame( 100, $warning_page['pagination']['per_page'] );
		$this->assertCount( 3, $warning_page['rows'] );
		foreach ( $warning_page['rows'] as $row ) {
			$this->assertContains( 'missing_in_woocommerce', $row['issues'] );
		}

		$response = Digitalogic_REST_API::instance()->get_reports(
			new WP_REST_Request(
				array(
					'view'     => 'price_list',
					'page'     => 2,
					'per_page' => 1,
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$rest_report = $response->get_data()['data'];
		$this->assertSame( 1, $rest_report['pagination']['per_page'] );
		$this->assertSame( 2, $rest_report['pagination']['page'] );
		$this->assertCount( 1, $rest_report['rows'] );
	}

	/** Static inspection is read-only; separately named ingestion requires confirmation. */
	public function test_static_kala_inspection_is_read_only_and_ingestion_requires_yes(): void {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'digitalogic-current-report-' . bin2hex( random_bytes( 4 ) );
		mkdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated test fixture.
		$path = $directory . DIRECTORY_SEPARATOR . 'kala.json';
		copy( __DIR__ . '/fixtures/patris-product-sync-golden.json', $path );

		$validated = Digitalogic_CLI_Commands::validate_current_patris_json_path( $path );
		$this->assertSame( realpath( $path ), $validated );
		$public_path = ABSPATH . 'kala.json';
		copy( $path, $public_path );
		$public_result = Digitalogic_CLI_Commands::validate_current_patris_json_path( $public_path );
		$this->assertInstanceOf( WP_Error::class, $public_result );
		$this->assertSame( 'digitalogic_patris_inspect_webroot_forbidden', $public_result->get_error_code() );
		unlink( $public_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated test fixture cleanup.

		$command = new Digitalogic_CLI_Commands();
		$command->patris_inspect(
			array(),
			array(
				'file'   => $path,
				'format' => 'json',
			)
		);
		$this->assertNotEmpty( WP_CLI::$errors );
		$this->assertSame( array(), WP_CLI::$logs );

		WP_CLI::$errors                              = array();
		$GLOBALS['digitalogic_test_current_user_id'] = 1;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 1,
			'user_login'   => 'administrator',
			'display_name' => 'Administrator',
			'roles'        => array( 'administrator' ),
		);
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$command->patris_inspect(
			array(),
			array(
				'file'   => $path,
				'format' => 'json',
			)
		);

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 1, WP_CLI::$logs );
		$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'valid', $output['inspection']['status'] );
		$this->assertSame( 'static', $output['report']['status'] );
		$this->assertFalse( get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, false ) );

		WP_CLI::$errors = array();
		WP_CLI::$logs   = array();
		$command->patris_ingest(
			array(),
			array(
				'file'   => $path,
				'format' => 'json',
			)
		);
		$this->assertNotEmpty( WP_CLI::$errors );
		$this->assertSame( array(), WP_CLI::$logs );
		$this->assertFalse( get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, false ) );

		WP_CLI::$errors = array();
		$command->patris_ingest(
			array(),
			array(
				'file'   => $path,
				'format' => 'yaml',
				'yes'    => true,
			)
		);
		$this->assertNotEmpty( WP_CLI::$errors );
		$this->assertSame( array(), WP_CLI::$logs );
		$this->assertFalse( get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, false ) );

		WP_CLI::$errors = array();
		$command->patris_ingest(
			array(),
			array(
				'file'   => $path,
				'format' => 'json',
				'yes'    => true,
			)
		);
		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 1, WP_CLI::$logs );
		$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'accepted', $output['ingestion']['status'] );
		$this->assertSame( 'current', $output['report']['status'] );
		$this->assertIsArray( get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, false ) );

		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated test fixture cleanup.
		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated test fixture cleanup.
	}

	/**
	 * Store one deterministic living receiver source.
	 *
	 * @param array $products Sparse canonical source products.
	 */
	private function store_source( $products ): void {
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
	 * @return array
	 */
	private function woo_post( $type, $title, $meta ): array {
		return array(
			'post_type'    => 'variation' === $type ? 'product_variation' : 'product',
			'post_status'  => 'publish',
			'product_type' => $type,
			'post_title'   => $title,
			'meta'         => $meta,
		);
	}

	/**
	 * Find one report row by exact output identity.
	 *
	 * @param array  $rows Report rows.
	 * @param string $code Exact report Code.
	 * @param string $status Expected match state.
	 * @return array
	 */
	private function find_row( $rows, $code, $status ): array {
		foreach ( $rows as $row ) {
			if ( $code === $row['product_code'] && $status === $row['status'] ) {
				return $row;
			}
		}

		$this->fail( 'Expected report row was not found: ' . $code . ' / ' . $status );
	}

	/**
	 * Reset a singleton with an established private static instance property.
	 *
	 * @param string $class_name Singleton class name.
	 */
	private function reset_singleton( $class_name ): void {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
