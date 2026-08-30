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
			$callback                                       = $GLOBALS['digitalogic_test_cache_set_callback'];
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

		$GLOBALS['digitalogic_test_object_cache_enabled']         = true;
		$GLOBALS['digitalogic_test_object_cache']                 = array();
		$GLOBALS['digitalogic_test_object_cache_sets']            = array();
		$GLOBALS['digitalogic_test_cache_add_callback']           = null;
		$GLOBALS['digitalogic_test_cache_set_callback']           = null;
		$GLOBALS['digitalogic_test_cache_deletes']                = array();
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		$GLOBALS['digitalogic_test_object_term_cache_cleans']     = array();
		$GLOBALS['digitalogic_test_update_failures']              = array();
		$GLOBALS['digitalogic_test_options']                      = array(
			'digitalogic_patris_feed_settings'       => array( 'stale_after_hours' => 48 ),
			'digitalogic_report_cache_generation' => 'test-report-generation',
		);
		$GLOBALS['digitalogic_test_option_cache']                 = array();
		$GLOBALS['digitalogic_test_posts']                        = array();
		$GLOBALS['digitalogic_test_post_meta_cache']              = array();
		$GLOBALS['digitalogic_test_wc_products']                  = array();
		$GLOBALS['digitalogic_test_wc_product_query_args'] = array();
		$GLOBALS['digitalogic_test_actions']                      = array();
		$GLOBALS['digitalogic_test_action_callbacks']             = array();
		$GLOBALS['digitalogic_test_filters']                      = array();
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

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
		$args    = array(
			'view'     => 'price_list',
			'page'     => 1,
			'per_page' => 25,
		);
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
		$this->engine->get_report(
			array(
				'view'     => 'warnings',
				'page'     => 1,
				'per_page' => 25,
			)
		);
		$this->engine->get_report(
			array(
				'view'     => 'price_list',
				'page'     => 1,
				'per_page' => 50,
			)
		);

		$this->assertCount( 2, $this->report_cache_keys() );
	}

	public function test_generation_invalidation_rebuilds_an_existing_request_shape(): void {
		$args = array(
			'view'     => 'price_list',
			'page'     => 1,
			'per_page' => 25,
		);
		$this->assertSame( 1, $this->engine->get_report( $args )['counts']['patris_products'] );

		$this->add_source_without_invalidation( 'CACHE-2' );
		$this->engine->invalidate_cache();
		$refreshed = $this->engine->get_report( $args );

		$this->assertSame( 2, $refreshed['counts']['patris_products'] );
		$this->assertArrayHasKey( 'digitalogic_reports:generation', $GLOBALS['digitalogic_test_object_cache'] );
	}

	/** One committed effect owns one deterministic generation across every replay. */
	public function test_effect_invalidation_is_durable_and_idempotent(): void {
		$effect = 'sha256:' . str_repeat( 'a', 64 );
		$first  = $this->engine->invalidate_cache_for_effect( $effect );
		$this->assertIsArray( $first );
		$this->assertSame( 'complete', $first['status'] );
		$generation = $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation'];

		$replayed = $this->engine->invalidate_cache_for_effect( $effect );

		$this->assertSame( $first, $replayed );
		$this->assertSame( $generation, $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_effects'] );
	}

	/** A generation write failure remains pending and converges without a new target. */
	public function test_effect_invalidation_failure_cannot_report_false_success(): void {
		$effect = 'sha256:' . str_repeat( 'b', 64 );
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_report_cache_generation';

		$failed = $this->engine->invalidate_cache_for_effect( $effect );

		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertSame( 'digitalogic_report_effect_generation_store_failed', $failed->get_error_code() );
		$this->assertTrue( $failed->get_error_data()['retry_scheduled'] );
		$this->assertNotFalse(
			wp_next_scheduled( 'digitalogic_report_effect_invalidation_retry', array( $effect ) )
		);
		$pending = $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_effects'][ $effect ];
		$this->assertSame( 'pending', $pending['status'] );
		$this->assertSame( 'test-report-generation', $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation'] );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$completed = $this->engine->retry_effect_invalidation( $effect );

		$this->assertIsArray( $completed );
		$this->assertSame( 'complete', $completed['status'] );
		$this->assertSame( $pending['target_generation'], $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'] );
	}

	/** Replaying an old complete effect never replaces a newer generation. */
	public function test_completed_effect_replay_never_regresses_newer_generation(): void {
		$effect = 'sha256:' . str_repeat( 'c', 64 );
		$this->assertIsArray( $this->engine->invalidate_cache_for_effect( $effect ) );
		$this->assertTrue( $this->engine->invalidate_cache() );
		$newer = $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'];

		$replayed = $this->engine->invalidate_cache_for_effect( $effect );

		$this->assertSame( 'complete', $replayed['status'] );
		$this->assertSame( $newer, $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'] );
	}

	/** Reject a cached report when source freshness crosses its threshold. */
	public function test_freshness_transition_rejects_a_cached_report_without_generation_change(): void {
		$args    = array(
			'view'     => 'price_list',
			'page'     => 1,
			'per_page' => 25,
		);
		$initial = $this->engine->get_report( $args );
		$this->assertNotContains( 'stale_source', $initial['rows'][0]['issues'] );
		$generation = $this->invoke_private( 'cache_generation' );

		$option = Digitalogic_Product_Sync_Receiver::STATE_OPTION;
		$GLOBALS['digitalogic_test_options'][ $option ]['sources'][ $this->source_state_key() ]['products']['CACHE-1']['source_updated_at'] = gmdate( 'c', time() - 49 * HOUR_IN_SECONDS );
		unset( $GLOBALS['digitalogic_test_option_cache'][ $option ] );
		$refreshed = $this->engine->get_report( $args );

		$this->assertSame( $generation, $this->invoke_private( 'cache_generation' ) );
		$this->assertContains( 'stale_source', $refreshed['rows'][0]['issues'] );
		$this->assertCount( 2, $this->report_cache_writes() );
	}

	/** Invalidate the projection for model attribute and taxonomy mutations. */
	public function test_model_attribute_mutations_advance_projection_generation(): void {
		$GLOBALS['digitalogic_test_posts'][77] = array(
			'post_type' => 'product',
			'meta'      => array(),
		);
		$before                                = $this->invoke_private( 'cache_generation' );

		$this->engine->invalidate_cache_for_product_meta( 1, 77, '_product_attributes' );
		$after_meta = $this->invoke_private( 'cache_generation' );
		$this->assertNotSame( $before, $after_meta );

		$this->engine->invalidate_cache_for_product_terms( 77, array(), array(), 'pa_model' );
		$this->assertNotSame( $after_meta, $this->invoke_private( 'cache_generation' ) );
		$this->assertTrue( has_action( 'created_pa_model' ) );
		$this->assertTrue( has_action( 'edited_pa_model' ) );
		$this->assertTrue( has_action( 'delete_pa_model' ) );
	}

	/** Product-type taxonomy writes rotate WooCommerce's versioned type cache. */
	public function test_product_type_mutation_invalidates_exact_woocommerce_product_cache_group(): void {
		$GLOBALS['digitalogic_test_posts'][77] = array(
			'post_type' => 'product',
			'meta'      => array(),
		);

		$this->engine->invalidate_cache_for_product_terms( 77, array(), array(), 'product_type' );

		$this->assertSame( array( array( 77, 'product' ) ), $GLOBALS['digitalogic_test_object_term_cache_cleans'] );
		$this->assertSame( array( 'product_77' ), $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
	}

	/** Keep report-generation installation explicit and outside construction. */
	public function test_generation_install_is_explicit_and_constructor_is_read_only(): void {
		unset( $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation'] );
		$this->reset_singleton( Digitalogic_Report_Engine::class );
		$engine = Digitalogic_Report_Engine::instance();

		$this->assertArrayNotHasKey( 'digitalogic_report_cache_generation', $GLOBALS['digitalogic_test_options'] );
		$revision = $engine->projection_revision( 'patris-export', 'ALLANBAR' );
		$this->assertInstanceOf( WP_Error::class, $revision );
		$this->assertSame( 'digitalogic_report_generation_uninitialized', $revision->get_error_code() );

		$this->assertTrue( $engine->install_cache_generation() );
		$this->assertIsString( $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation'] );
		$this->assertNotSame( '', $GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation'] );
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
		$args                               = $this->normalized_args(
			array(
				'view'     => 'price_list',
				'per_page' => 25,
			)
		);
		$fresh                              = $this->engine->get_report_from_validated_envelope( $this->static_envelope(), $args );
		$cache_key                          = 'digitalogic_reports:' . $this->invoke_private( 'cache_key', array( $args ) );
		$fresh['_cache_generation']         = $this->invoke_private( 'cache_generation' );
		$fresh['_cache_freshness_revision'] = $this->invoke_private(
			'cache_freshness_revision',
			array( $args )
		);
		$GLOBALS['digitalogic_test_cache_add_callback'] = static function ( $key, $data, $group ) use ( $fresh, $cache_key ) {
			if ( 'digitalogic_reports' === $group && str_starts_with( (string) $key, 'build-lock-v3-' ) ) {
				$GLOBALS['digitalogic_test_object_cache'][ $cache_key ] = $fresh;
			}
		};

		$report = $this->engine->get_report(
			array(
				'view'     => 'price_list',
				'per_page' => 25,
			)
		);

		$this->assertSame( 'static', $report['status'] );
		$this->assertCount( 0, $this->report_cache_writes() );
	}

	public function test_invalidation_during_cache_publish_rejects_stale_output(): void {
		$GLOBALS['digitalogic_test_cache_set_callback'] = function ( $key, $data, $group ) {
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

	public function test_explicit_null_rounding_digits_does_not_report_the_required_absent_mode_as_missing(): void {
		$product                          = $this->source_product( 'NULL-ROUNDING' );
		$product['price_rounding_digits'] = null;
		unset( $product['price_rounding_mode'], $product['final_price'] );
		$this->store_source( array( 'NULL-ROUNDING' => $product ) );

		$report = $this->engine->get_report( array( 'view' => 'warnings' ) );

		$this->assertNotInstanceOf( WP_Error::class, $report );
		$this->assertCount( 1, $report['rows'] );
		$this->assertContains( 'null_rounding_digits', $report['rows'][0]['issues'] );
		$this->assertNotContains( 'missing_rounding_mode', $report['rows'][0]['issues'] );
	}

	public function test_partner_only_diagnostics_remain_attention_for_cny_price_source(): void {
		$product             = $this->source_product( 'CNY-WARNING' );
		$product['warnings'] = array( 'weight_missing' );
		$this->store_source( array( 'CNY-WARNING' => $product ) );

		$report = $this->engine->get_report( array( 'view' => 'price_list' ) );
		$rows   = array_values(
			array_filter(
				$report['rows'],
				static fn( $row ) => 'CNY-WARNING' === ( $row['product_code'] ?? '' )
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertContains( 'source_warning', $rows[0]['issues'] );
	}

	/** A stored zero weight remains visible but is invalid for foreign freight pricing. */
	public function test_zero_weight_is_reported_as_invalid_for_cny_price_source(): void {
		$product                 = $this->source_product( 'CNY-ZERO-WEIGHT' );
		$product['weight_grams'] = '0';
		$this->store_source( array( 'CNY-ZERO-WEIGHT' => $product ) );

		$report = $this->engine->get_report( array( 'view' => 'warnings' ) );
		$row    = $report['rows'][0];

		$this->assertSame( '0', $row['source']['weight_grams'] );
		$this->assertContains( 'invalid_source_value', $row['issues'] );
		$this->assertContains( 'weight_grams', $row['issue_fields']['invalid_source_value'] );
	}

	public function test_direct_sale_fallback_is_reported_without_unused_pricing_requirements(): void {
		$product                                   = $this->source_product( 'DIRECT-SALE' );
		$product['sale_price_source']              = '100000';
		$product['price_source_amount']            = '100000';
		$product['price_source_currency']          = 'IRR';
		$product['price_source_kind']              = 'sale_price_direct';
		$product['shipping_method_id']             = 'domestic';
		$product['shipping_price_per_kg']          = '0';
		$product['shipping_price_per_kg_currency'] = 'IRR';
		$product['final_price']                    = 10000;
		$product['warnings']                       = array(
			'sale_price_direct_fallback_used',
			'freight_not_applied_for_sale_price_direct',
			'weight_missing',
		);
		unset(
			$product['foreign_currency'],
			$product['foreign_price'],
			$product['weight_grams'],
			$product['markup_percent'],
			$product['irt_per_cny'],
			$product['price_rounding_digits'],
			$product['price_rounding_mode']
		);
		$this->store_source( array( 'DIRECT-SALE' => $product ) );

		$report = $this->engine->get_report( array( 'view' => 'price_list' ) );
		$row    = $report['rows'][0];

		$this->assertContains( 'sale_price_direct_fallback', $row['issues'] );
		$this->assertNotContains( 'missing_markup', $row['issues'] );
		$this->assertNotContains( 'missing_rounding_digits', $row['issues'] );
		$this->assertNotContains( 'missing_shipping', $row['issues'] );
		$this->assertNotContains( 'invalid_domestic_shipping', $row['issues'] );
		$this->assertNotContains( 'source_warning', $row['issues'] );
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
		$GLOBALS['digitalogic_test_options'][ $option ]['sources'][ $this->source_state_key() ]['products'][ $code ] = $this->source_product( $code );
		unset( $GLOBALS['digitalogic_test_option_cache'][ $option ] );
	}

	private function store_source( $products ): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				$this->source_state_key() => array(
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
		);
	}

	/** Return the receiver-state key for the exact test source. */
	private function source_state_key(): string {
		return hash( 'sha256', "patris-export\nALLANBAR" );
	}

	private function source_product( $code ): array {
		return array(
			'product_code'                   => $code,
			'name'                           => 'Cache product ' . $code,
			'foreign_currency'               => 'CNY',
			'foreign_price'                  => '10',
			'price_source_amount'            => '10',
			'price_source_currency'          => 'CNY',
			'price_source_kind'              => 'foreign_price',
			'weight_grams'                   => '100',
			'total_stock'                    => 5,
			'shipping_method_id'             => 'air_express',
			'shipping_price_per_kg'          => '20',
			'shipping_price_per_kg_currency' => 'CNY',
			'markup_percent'                 => '30',
			'irt_per_cny'                    => '30000',
			'price_rounding_digits'          => 0,
			'price_rounding_mode'            => 'nearest_half_up',
			'final_price'                    => 468000,
			'source_updated_at'              => gmdate( 'c' ),
			'warnings'                       => array(),
			'record_hash'                    => 'sha256:' . strtolower( $code ),
		);
	}

	private function static_envelope(): array {
		return array(
			'schema'       => 'patris.product-sync',
			'event_id'     => 'sha256:static-event',
			'event_type'   => 'snapshot',
			'generated_at' => gmdate( 'c' ),
			'source'       => array(
				'id'       => 'patris-static',
				'dataset'  => 'ALLANBAR',
				'revision' => 'sha256:static',
			),
			'products'     => array( $this->source_product( 'STATIC-1' ) ),
		);
	}

	private function reset_singleton( $class_name ): void {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
