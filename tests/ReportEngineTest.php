<?php
/**
 * Current-report cache, lock, and REST error tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		if ( empty( $GLOBALS['digitalogic_test_object_cache_enabled'] ) ) {
			$found = false;
			return false;
		}

		$cache_key = (string) $group . ':' . (string) $key;
		$cache     = (array) ( $GLOBALS['digitalogic_test_object_cache'] ?? array() );
		$found     = array_key_exists( $cache_key, $cache );

		return $found ? $cache[ $cache_key ] : false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		if ( empty( $GLOBALS['digitalogic_test_object_cache_enabled'] ) ) {
			return false;
		}

		if ( isset( $GLOBALS['digitalogic_test_cache_set_callback'] ) && is_callable( $GLOBALS['digitalogic_test_cache_set_callback'] ) ) {
			$callback = $GLOBALS['digitalogic_test_cache_set_callback'];
			$GLOBALS['digitalogic_test_cache_set_callback'] = null;
			$callback( $key, $data, $group, $expire );
		}

		$cache_key = (string) $group . ':' . (string) $key;
		$GLOBALS['digitalogic_test_object_cache'][ $cache_key ] = $data;
		$GLOBALS['digitalogic_test_object_cache_sets'][]        = array( $key, $group, (int) $expire );

		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		if ( empty( $GLOBALS['digitalogic_test_object_cache_enabled'] ) ) {
			return true;
		}

		if ( isset( $GLOBALS['digitalogic_test_cache_add_callback'] ) && is_callable( $GLOBALS['digitalogic_test_cache_add_callback'] ) ) {
			$GLOBALS['digitalogic_test_cache_add_callback']( $key, $data, $group, $expire );
		}

		$cache_key = (string) $group . ':' . (string) $key;
		if ( array_key_exists( $cache_key, $GLOBALS['digitalogic_test_object_cache'] ) ) {
			return false;
		}

		$GLOBALS['digitalogic_test_object_cache'][ $cache_key ] = $data;

		return true;
	}
}

final class ReportEngineTest extends TestCase {

	private Digitalogic_Report_Engine $engine;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['digitalogic_test_object_cache_enabled'] = true;
		$GLOBALS['digitalogic_test_object_cache']         = array();
		$GLOBALS['digitalogic_test_object_cache_sets']    = array();
		$GLOBALS['digitalogic_test_cache_add_callback']   = null;
		$GLOBALS['digitalogic_test_cache_set_callback']   = null;
		$GLOBALS['digitalogic_test_cache_deletes']        = array();
		$GLOBALS['digitalogic_test_options']              = array(
			'digitalogic_patris_feed_settings' => array( 'stale_after_hours' => 48 ),
		);
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_posts']                = array();
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_wc_products']          = array();
		$GLOBALS['digitalogic_test_wc_product_query_args'] = array();
		$GLOBALS['digitalogic_test_actions']              = array();
		$GLOBALS['digitalogic_test_action_callbacks']     = array();
		$GLOBALS['digitalogic_test_filters']              = array();
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();

		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$this->reset_singleton( Digitalogic_Patris_Feed::class );
		$this->reset_singleton( Digitalogic_Report_Engine::class );
		$this->reset_singleton( Digitalogic_REST_API::class );
		$this->store_source( array( 'CACHE-1' => $this->source_product( 'CACHE-1' ) ) );
		$this->engine = Digitalogic_Report_Engine::instance();
	}

	protected function tearDown(): void {
		$GLOBALS['digitalogic_test_object_cache_enabled'] = false;
		$GLOBALS['digitalogic_test_cache_add_callback']   = null;
		$GLOBALS['digitalogic_test_cache_set_callback']   = null;
		parent::tearDown();
	}

	public function test_cache_is_request_shaped_and_force_refresh_is_explicit(): void {
		$args    = array( 'view' => 'price_list', 'page' => 1, 'per_page' => 25 );
		$initial = $this->engine->get_report( $args );
		$this->assertSame( 1, $initial['counts']['patris_products'] );
		$this->assertCount( 1, $this->report_cache_writes() );

		$this->add_source_without_invalidation( 'CACHE-2' );
		$cached = $this->engine->get_report( $args + array( 'force_refresh' => 'false' ) );
		$this->assertSame( 1, $cached['counts']['patris_products'] );

		$forced = $this->engine->get_report( $args + array( 'force_refresh' => 'true' ) );
		$this->assertSame( 2, $forced['counts']['patris_products'] );
		$this->assertCount( 2, $this->report_cache_writes() );
	}

	public function test_distinct_normalized_requests_use_distinct_cache_entries(): void {
		$this->engine->get_report( array( 'view' => 'warnings', 'page' => 1, 'per_page' => 25 ) );
		$this->engine->get_report( array( 'view' => 'price_list', 'page' => 1, 'per_page' => 50 ) );

		$this->assertCount( 2, $this->report_cache_keys() );
	}

	public function test_generation_invalidation_rebuilds_an_existing_request_shape(): void {
		$args = array( 'view' => 'price_list', 'page' => 1, 'per_page' => 25 );
		$this->assertSame( 1, $this->engine->get_report( $args )['counts']['patris_products'] );

		$this->add_source_without_invalidation( 'CACHE-2' );
		$this->engine->invalidate_cache();
		$refreshed = $this->engine->get_report( $args );

		$this->assertSame( 2, $refreshed['counts']['patris_products'] );
		$this->assertArrayHasKey( 'digitalogic_reports:generation-v1', $GLOBALS['digitalogic_test_object_cache'] );
	}

	public function test_lock_loser_receives_a_retryable_error(): void {
		$args     = $this->normalized_args( array( 'view' => 'warnings' ) );
		$lock_key = $this->invoke_private( 'build_lock_key', array( $args ) );
		$GLOBALS['digitalogic_test_object_cache'][ 'digitalogic_reports:' . $lock_key ] = 'another-request';

		$report = $this->engine->get_report( array( 'view' => 'warnings' ) );

		$this->assertInstanceOf( WP_Error::class, $report );
		$this->assertSame( 'digitalogic_report_build_in_progress', $report->get_error_code() );
		$this->assertSame( 503, $report->get_error_data()['status'] );
	}

	public function test_lock_owner_never_deletes_a_replacement_lock(): void {
		$args     = $this->normalized_args( array( 'view' => 'warnings' ) );
		$acquired = $this->invoke_private( 'acquire_build_lock', array( $args ) );
		$lock_key = $this->invoke_private( 'build_lock_key', array( $args ) );
		$this->assertTrue( $acquired );

		$GLOBALS['digitalogic_test_object_cache'][ 'digitalogic_reports:' . $lock_key ] = 'replacement-owner';
		$this->invoke_private( 'release_build_lock' );

		$this->assertSame( 'replacement-owner', $GLOBALS['digitalogic_test_object_cache'][ 'digitalogic_reports:' . $lock_key ] );
	}

	public function test_cache_is_double_checked_after_lock_acquisition(): void {
		$args      = $this->normalized_args( array( 'view' => 'price_list', 'per_page' => 25 ) );
		$fresh     = $this->engine->get_report_from_validated_envelope( $this->static_envelope(), $args );
		$cache_key = 'digitalogic_reports:' . $this->invoke_private( 'cache_key', array( $args ) );
		$fresh['_cache_generation'] = 'initial';
		$GLOBALS['digitalogic_test_cache_add_callback'] = static function( $key, $data, $group ) use ( $fresh, $cache_key ) {
			if ( 'digitalogic_reports' === $group && str_starts_with( (string) $key, 'build-lock-v3-' ) ) {
				$GLOBALS['digitalogic_test_object_cache'][ $cache_key ] = $fresh;
			}
		};

		$report = $this->engine->get_report( array( 'view' => 'price_list', 'per_page' => 25 ) );

		$this->assertSame( 'static', $report['status'] );
		$this->assertCount( 0, $this->report_cache_writes() );
	}

	public function test_invalidation_during_cache_publish_rejects_stale_output(): void {
		$GLOBALS['digitalogic_test_cache_set_callback'] = function( $key, $data, $group ) {
			if ( 'digitalogic_reports' === $group && str_starts_with( (string) $key, 'current-v3-' ) ) {
				$this->engine->invalidate_cache();
			}
		};

		$report = $this->engine->get_report( array( 'view' => 'warnings' ) );

		$this->assertInstanceOf( WP_Error::class, $report );
		$this->assertSame( 'digitalogic_report_source_changed', $report->get_error_code() );
		$this->assertSame( array(), $this->report_cache_keys() );
	}

	public function test_rest_reports_propagates_the_build_lock_error(): void {
		$args     = $this->normalized_args( array( 'view' => 'warnings' ) );
		$lock_key = $this->invoke_private( 'build_lock_key', array( $args ) );
		$GLOBALS['digitalogic_test_object_cache'][ 'digitalogic_reports:' . $lock_key ] = 'another-request';

		$response = Digitalogic_REST_API::instance()->get_reports( new WP_REST_Request( array( 'view' => 'warnings' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'digitalogic_report_build_in_progress', $response->get_error_code() );
		$this->assertSame( 503, $response->get_error_data()['status'] );
	}

	public function test_unknown_category_is_rejected_instead_of_silently_widened(): void {
		$report = $this->engine->get_report( array( 'category' => 'not-a-report' ) );

		$this->assertInstanceOf( WP_Error::class, $report );
		$this->assertSame( 'digitalogic_unknown_report_category', $report->get_error_code() );
		$this->assertSame( 400, $report->get_error_data()['status'] );
	}

	private function report_cache_writes(): array {
		return array_values(
			array_filter(
				$GLOBALS['digitalogic_test_object_cache_sets'],
				static fn( $write ) => 'digitalogic_reports' === $write[1] && 300 === $write[2] && str_starts_with( (string) $write[0], 'current-v3-' )
			)
		);
	}

	private function report_cache_keys(): array {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['digitalogic_test_object_cache'] ),
				static fn( $key ) => str_starts_with( (string) $key, 'digitalogic_reports:current-v3-' )
			)
		);
	}

	private function normalized_args( $args ): array {
		return $this->invoke_private( 'normalize_args', array( $args ) );
	}

	private function invoke_private( $method, $args = array() ) {
		$reflection = new ReflectionMethod( Digitalogic_Report_Engine::class, $method );
		return $reflection->invokeArgs( $this->engine, $args );
	}

	private function add_source_without_invalidation( $code ): void {
		$option = Digitalogic_Product_Sync_Receiver::STATE_OPTION;
		$GLOBALS['digitalogic_test_options'][ $option ]['sources']['test-source']['products'][ $code ] = $this->source_product( $code );
		unset( $GLOBALS['digitalogic_test_option_cache'][ $option ] );
	}

	private function store_source( $products ): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'test-source' => array(
					'source'          => array( 'id' => 'patris-export', 'dataset' => 'ALLANBAR', 'revision' => 'sha256:test-source' ),
					'generated_at'    => gmdate( 'c' ),
					'received_at'     => current_time( 'mysql' ),
					'last_event_id'   => 'sha256:test-event',
					'last_event_type' => 'snapshot',
					'products'        => $products,
				),
			),
		);
	}

	private function source_product( $code ): array {
		return array(
			'product_code'                   => $code,
			'name'                           => 'Cache product ' . $code,
			'foreign_currency'               => 'CNY',
			'foreign_price'                  => '10',
			'weight_grams'                   => '100',
			'total_stock'                    => 5,
			'shipping_method_id'             => 'air_express',
			'shipping_price_per_kg'          => '20',
			'shipping_price_per_kg_currency' => 'CNY',
			'markup_percent'                 => '30',
			'irt_per_cny'                    => '30000',
			'final_price'                    => 468000,
			'source_updated_at'              => gmdate( 'c' ),
			'warnings'                       => array(),
			'record_hash'                    => 'sha256:' . strtolower( $code ),
		);
	}

	private function static_envelope(): array {
		return array(
			'schema'       => 'digitalogic.patris.product-sync.v1',
			'event_id'     => 'sha256:static-event',
			'event_type'   => 'snapshot',
			'generated_at' => gmdate( 'c' ),
			'source'       => array( 'id' => 'patris-static', 'dataset' => 'ALLANBAR', 'revision' => 'sha256:static' ),
			'products'     => array( $this->source_product( 'STATIC-1' ) ),
		);
	}

	private function reset_singleton( $class_name ): void {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
