<?php
/**
 * Immutable pricing snapshot contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Test helpers use self-describing signatures.

/** Verify revision, async build, cache, integrity, cancellation, and replay. */
final class PricingSnapshotTest extends TestCase {

	/**
	 * Exact source fixture.
	 *
	 * @var array
	 */
	private $source;

	/** Prepare one current Patris source with enough rows for two fixed pages. */
	protected function setUp(): void {
		parent::setUp();

		$this->source = array(
			'id'       => 'patris-office',
			'dataset'  => 'kala.db',
			'revision' => 'sha256:' . str_repeat( 'f', 64 ),
		);
		$products     = array();
		for ( $index = 1; $index <= 251; ++$index ) {
			$code              = sprintf( 'SNAP-%04d', $index );
			$products[ $code ] = $this->source_product( $code );
		}
		$source_key = hash( 'sha256', $this->source['id'] . "\n" . $this->source['dataset'] );

		$GLOBALS['digitalogic_test_capabilities']           = array();
		$GLOBALS['digitalogic_test_filters']                = array();
		$GLOBALS['digitalogic_test_routes']                 = array();
		$GLOBALS['digitalogic_test_actions']                = array();
		$GLOBALS['digitalogic_test_action_callbacks']       = array();
		$GLOBALS['digitalogic_test_options']                = array(
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION => 'receiver-secret',
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION => array(
				array(
					'id'      => $this->source['id'],
					'dataset' => $this->source['dataset'],
				),
			),
			Digitalogic_Product_Sync_Receiver::STATE_OPTION => array(
				'sources' => array(
					$source_key => array(
						'source'            => $this->source,
						'generated_at'      => gmdate( 'c' ),
						'received_at'       => current_time( 'mysql' ),
						'last_event_id'     => 'sha256:' . str_repeat( 'e', 64 ),
						'last_event_type'   => 'snapshot',
						'products'          => $products,
						'categories'        => array(),
						'excluded_codes'    => array(),
						'quarantined_codes' => array(),
						'applied_products'  => array(),
						'pending_products'  => array(),
						'deferred_products' => array_keys( $products ),
					),
				),
			),
			'dollar_price'                           => '187891',
			'options_dollar_price'                   => '187891',
			'yuan_price'                             => '29500',
			'options_yuan_price'                     => '29500',
			'update_date'                            => gmdate( 'ymd' ),
			'options_update_date'                    => gmdate( 'ymd' ),
			Digitalogic_Shipping_Method_Service::METHODS_OPTION => array(
				'air_express' => array(
					'id'           => 'air_express',
					'name'         => 'Air (Express)',
					'enabled'      => true,
					'currency'     => 'CNY',
					'price_per_kg' => '120',
				),
			),
			Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION => $this->default_markup_state(),
			Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION => 0,
			'digitalogic_shipping_currency_migration_complete' => 'complete',
			'woocommerce_currency'                   => 'IRT',
			'woocommerce_weight_unit'                => 'kg',
			'home'                                   => 'https://digitalogic.test',
			'siteurl'                                => 'https://digitalogic.test',
			'permalink_structure'                    => '/%postname%/',
			'digitalogic_report_cache_generation'    => 'snapshot-test-generation',
		);
		$GLOBALS['digitalogic_test_option_cache']           = array();
		$GLOBALS['digitalogic_test_update_failures']        = array();
		$GLOBALS['digitalogic_test_transaction_failures']   = array();
		$GLOBALS['digitalogic_test_transients']             = array();
		$GLOBALS['digitalogic_test_transient_deletes']      = array();
		$GLOBALS['digitalogic_test_transient_set_callback'] = null;
		$GLOBALS['digitalogic_test_scheduled_events']       = array();
		$GLOBALS['digitalogic_test_action_scheduler_actions'] = array();
		$GLOBALS['digitalogic_test_action_scheduler_next_id'] = 1;
		$GLOBALS['digitalogic_test_action_scheduler_available'] = true;
		$GLOBALS['digitalogic_test_schedule_failure']       = false;
		$GLOBALS['digitalogic_test_posts']                  = array();
		$GLOBALS['digitalogic_test_post_meta_cache']        = array();
		$GLOBALS['digitalogic_test_terms']                  = array();
		$GLOBALS['digitalogic_test_term_meta']              = array();
		$GLOBALS['digitalogic_test_wc_products']            = array();
		$GLOBALS['digitalogic_test_wc_product_query_args']  = array();
		$GLOBALS['digitalogic_test_wc_currency']            = 'IRT';
		$GLOBALS['digitalogic_test_object_cache_enabled']   = true;
		$GLOBALS['digitalogic_test_object_cache']           = array();
		$GLOBALS['digitalogic_test_object_cache_sets']      = array();
		$GLOBALS['digitalogic_test_cache_deletes']          = array();
		$GLOBALS['wpdb']                                    = new Digitalogic_Test_WPDB();

		foreach (
			array(
				Digitalogic_Pricing_Snapshot::class,
				Digitalogic_Report_Engine::class,
				Digitalogic_Excel_Pricing_Sync::class,
				Digitalogic_REST_API::class,
				Digitalogic_Patris_Feed::class,
				Digitalogic_Product_Sync_Receiver::class,
				Digitalogic_Shipping_Method_Service::class,
				Digitalogic_Google_Sheets_Catalog::class,
				Digitalogic_Product_Manager::class,
			) as $class_name
		) {
			$this->reset_singleton( $class_name );
		}
	}

	/** Restore Action Scheduler availability for unrelated tests. */
	protected function tearDown(): void {
		$GLOBALS['digitalogic_test_action_scheduler_available'] = false;
		parent::tearDown();
	}

	/** Revision GET/HEAD/304 is stable, cheap, scoped, and does not create a secret. */
	public function test_revision_surface_is_cheap_conditional_and_read_only(): void {
		$options_before = $GLOBALS['digitalogic_test_options'];
		$this->assertTrue( Digitalogic_Pricing_Snapshot::instance()->authorize( $this->query_request( 'GET' ) ) );
		$response = $this->revision_response();
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( Digitalogic_Pricing_Snapshot::REVISION_SCHEMA, $data['schema'] );
		$this->assertSame( 'excel', $data['projection'] );
		$this->assertArrayNotHasKey( 'schema_version', $data );
		foreach (
			array(
				Digitalogic_Pricing_Snapshot::REVISION_SCHEMA,
				Digitalogic_Pricing_Snapshot::REQUEST_SCHEMA,
				Digitalogic_Pricing_Snapshot::BUILD_SCHEMA,
				Digitalogic_Pricing_Snapshot::SNAPSHOT_SCHEMA,
				Digitalogic_Pricing_Snapshot::PAGE_SCHEMA,
				Digitalogic_Pricing_Snapshot::STATE_EVENT_SCHEMA,
				Digitalogic_Pricing_Snapshot::SOURCE_EVENT_SCHEMA,
				Digitalogic_Pricing_Snapshot::PROJECTION,
				Digitalogic_Pricing_Snapshot::PROJECTION_SCHEMA,
				Digitalogic_Pricing_Snapshot::PRICING_POLICY_SCHEMA,
			) as $identifier
		) {
			$this->assertDoesNotMatchRegularExpression( '/(?:\/v\d+|\.v\d+|-v\d+)$/', $identifier );
		}
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $data['state_revision'] );
		$this->assertSame( $this->source, $data['source'] );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_wc_product_query_args'] );
		$this->assertSame( $options_before, $GLOBALS['digitalogic_test_options'] );
		$etag = $response->get_headers()['ETag'];

		$head = $this->revision_response( array(), 'HEAD' );
		$this->assertSame( 200, $head->get_status() );
		$this->assertNull( $head->get_data() );
		$this->assertSame( $etag, $head->get_headers()['ETag'] );

		$not_modified = $this->revision_response( array( 'If-None-Match' => $etag ) );
		$this->assertSame( 304, $not_modified->get_status() );
		$this->assertNull( $not_modified->get_data() );
		$this->assertSame( 'private, no-cache, must-revalidate', $not_modified->get_headers()['Cache-Control'] );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_wc_product_query_args'] );
		$default_projection = $this->revision_response( array(), 'GET', array( 'projection' => null ) );
		$this->assertSame( 200, $default_projection->get_status() );
		$this->assertSame( $data['state_revision'], $default_projection->get_data()['state_revision'] );
		$unknown_projection = $this->revision_response( array(), 'GET', array( 'projection' => 'unsupported' ) );
		$this->assertSame( 422, $unknown_projection->get_status() );

		unset(
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ],
			$GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ]
		);
		$denied = Digitalogic_Pricing_Snapshot::instance()->authorize( $this->query_request( 'GET' ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'digitalogic_pricing_snapshot_unauthorized', $denied->get_error_code() );
		$this->assertArrayNotHasKey( Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION, $GLOBALS['digitalogic_test_options'] );
	}

	/** Coalesce mutations into one exact-source durable state-change event. */
	public function test_projection_invalidations_publish_one_scoped_composite_revision_event(): void {
		$redis = new Digitalogic_Test_Redis_Client();
		add_filter(
			'digitalogic_panel_redis_client',
			static function () use ( $redis ) {
				return $redis;
			}
		);
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$engine   = Digitalogic_Report_Engine::instance();

		$this->assertTrue( $engine->invalidate_cache() );
		$this->assertTrue( $engine->invalidate_cache() );
		$snapshot->publish_scheduled_state_revision_events();

		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 1, $events );
		$this->assertCount( 1, $redis->published );
		$this->assertSame( 'pricing.state.changed', $events[0]['name'] );
		$data = $events[0]['data'];
		$this->assertSame( Digitalogic_Pricing_Snapshot::STATE_EVENT_SCHEMA, $data['schema'] );
		$this->assertArrayNotHasKey( 'schema_version', $data );
		$this->assertSame( $this->source, $data['source'] );
		$this->assertSame( '"' . $data['state_revision'] . '"', $data['etag'] );
		$this->assertSame( $this->revision_response()->get_data()['state_revision'], $data['state_revision'] );
		$this->assertArrayNotHasKey( 'versionless', $data );
		$this->assertSame( array( 'patris_pricing' ), $data['audience']['services'] );
		$this->assertArrayNotHasKey( 'settings', $data );
		$this->assertArrayNotHasKey( 'secret', $data );
		$this->assertTrue(
			Digitalogic_Event_Mesh::event_visible_to( $events[0], 0, '', 'patris_pricing', $this->source )
		);
		$this->assertFalse( Digitalogic_Event_Mesh::event_visible_to( $events[0], 0, '' ) );
		$this->assertFalse(
			Digitalogic_Event_Mesh::event_visible_to(
				$events[0],
				0,
				'',
				'patris_pricing',
				array(
					'id'      => $this->source['id'],
					'dataset' => 'other.db',
				)
			)
		);
		$malformed = $events[0];
		unset( $malformed['data']['etag'] );
		$this->assertFalse(
			Digitalogic_Event_Mesh::event_visible_to( $malformed, 0, '', 'patris_pricing', $this->source )
		);

		$snapshot->publish_scheduled_state_revision_events();
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
	}

	/** A pending pricing event rebases to the newest Patris revision before delivery. */
	public function test_projection_event_rebases_continuously_changing_source_revision(): void {
		$redis = new Digitalogic_Test_Redis_Client();
		add_filter(
			'digitalogic_panel_redis_client',
			static function () use ( $redis ) {
				return $redis;
			}
		);
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );

		$source_key    = hash( 'sha256', $this->source['id'] . "\n" . $this->source['dataset'] );
		$latest_source = $this->source;

		$latest_source['revision'] = 'sha256:' . str_repeat( '8', 64 );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]['sources'][ $source_key ]['source'] = $latest_source;
		unset( $GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] );

		$snapshot->run_state_revision_event_delivery();

		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( $latest_source, $events[0]['data']['source'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 1, $redis->published );
	}

	/** A committed invalidation remains durable until the panel queue accepts it. */
	public function test_projection_event_outbox_retries_failed_queue_write_without_losing_or_duplicating_revision(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_scheduled_events'] );

		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		$snapshot->publish_scheduled_state_revision_events();
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$snapshot->run_state_revision_event_delivery();
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );
		$this->assertMatchesRegularExpression(
			'/\Asha256:[a-f0-9]{64}\z/D',
			$GLOBALS['digitalogic_test_options']['digitalogic_panel_events'][0]['data']['idempotency_key']
		);
		$snapshot->run_state_revision_event_delivery();
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
	}

	/** Scheduler arguments recover an invalidation when the first outbox write fails. */
	public function test_projection_event_retry_recovers_initial_outbox_persistence_failure(): void {
		$snapshot                                      = Digitalogic_Pricing_Snapshot::instance();
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_outbox';

		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_scheduled_events'] );
		$fallback_sources = $GLOBALS['digitalogic_test_scheduled_events'][0]['args'][0];
		$this->assertSame( array( $this->source ), $fallback_sources );

		$new_source             = $this->source;
		$new_source['revision'] = 'sha256:' . str_repeat( '9', 64 );
		$source_key             = hash( 'sha256', $this->source['id'] . "\n" . $this->source['dataset'] );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]['sources'][ $source_key ]['source'] = $new_source;
		unset( $GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] );
		$GLOBALS['digitalogic_test_update_failures'] = array();
		$snapshot->run_state_revision_event_delivery( $fallback_sources );

		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertSame( $new_source, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'][0]['data']['source'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );
	}

	/** Repeated invalidations retain one durable pending wake-up. */
	public function test_state_event_wakeups_coalesce_one_hundred_invalidations(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$engine   = Digitalogic_Report_Engine::instance();

		for ( $index = 0; $index < 100; ++$index ) {
			$this->set_snapshot_property( 'state_event_retry_scheduled', false );
			$this->assertTrue( $engine->invalidate_cache() );
		}

		$this->assertCount( 1, $this->pending_action_scheduler_actions_for( 'digitalogic_pricing_state_event_delivery' ) );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_outbox'] );
		$this->assertSame( $snapshot, Digitalogic_Pricing_Snapshot::instance() );
	}

	/** A claimed worker that leaves work schedules exactly one successor. */
	public function test_claimed_state_event_worker_reschedules_one_successor_when_outbox_remains(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$claimed = $this->claim_action_scheduler_action( 'digitalogic_pricing_state_event_delivery' );

		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		$snapshot->run_state_revision_event_delivery( ...$claimed['args'] );
		for ( $index = 0; $index < 100; ++$index ) {
			$this->set_snapshot_property( 'state_event_retry_scheduled', false );
			$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry' ) );
		}

		$pending = $this->pending_action_scheduler_actions_for( 'digitalogic_pricing_state_event_delivery' );
		$this->assertCount( 1, $pending );
		$this->assertNotSame( $claimed['args'][2], $pending[0]['args'][2] );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );

		$GLOBALS['digitalogic_test_action_scheduler_actions'][ $claimed['action_id'] ]['status'] = 'complete';
		$GLOBALS['digitalogic_test_update_failures'] = array();

		$successor = $this->claim_action_scheduler_action( 'digitalogic_pricing_state_event_delivery' );
		$snapshot->run_state_revision_event_delivery( ...$successor['args'] );
		$GLOBALS['digitalogic_test_action_scheduler_actions'][ $successor['action_id'] ]['status'] = 'complete';

		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertCount( 0, $this->pending_action_scheduler_actions_for( 'digitalogic_pricing_state_event_delivery' ) );
	}

	/** Cross-request invalidations retain one exact pending fallback action. */
	public function test_state_event_retry_coalesces_same_fallback_across_requests(): void {
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_outbox_v1';

		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
			Digitalogic_Pricing_Snapshot::instance()->schedule_state_revision_event();
		}

		$scheduled = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( array( $this->source ), $scheduled[0]['args'][0] );
		$this->assertSame( array(), $scheduled[0]['args'][1] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
	}

	/** Exact coalescing retains independent fallback identities. */
	public function test_state_event_retry_coalescing_does_not_evict_distinct_fallbacks(): void {
		$other_source       = $this->source;
		$other_source['id'] = 'patris-backup';
		$first_args         = array( array( $this->source ), array() );
		$other_args         = array( array( $other_source ), array() );
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $first_args ) );
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $first_args ) );
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $other_args ) );

		$scheduled = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$this->assertCount( 2, $scheduled );
		$this->assertSame( $first_args, $scheduled[0]['args'] );
		$this->assertSame( $other_args, $scheduled[1]['args'] );
		$schedule_locks = array_values(
			array_filter(
				$GLOBALS['wpdb']->lock_names,
				static fn( $name ) => str_starts_with( $name, 'digitalogic_pricing_state_event_schedule_v1:' )
			)
		);
		$this->assertCount( 3, $schedule_locks );
		$this->assertSame( $schedule_locks[0], $schedule_locks[1] );
		$this->assertNotSame( $schedule_locks[0], $schedule_locks[2] );
	}

	/** A lock-contending request accepts the exact pending action created by its peer. */
	public function test_state_event_retry_lock_contender_does_not_insert_a_duplicate(): void {
		$args = array( array( $this->source ), array() );
		$this->assertTrue(
			wp_schedule_single_event(
				time() + 2,
				'digitalogic_pricing_state_event_delivery_v1',
				$args,
				true
			)
		);
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_outbox_v1';
		$GLOBALS['wpdb']->acquire_results              = array( 1, 0 );

		Digitalogic_Pricing_Snapshot::instance()->schedule_state_revision_event();

		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' ) );
		$this->assertContains( 'digitalogic_pricing_snapshot_admission_v1', $GLOBALS['wpdb']->lock_names );
		$this->assertNotEmpty(
			array_filter(
				$GLOBALS['wpdb']->lock_names,
				static fn( $name ) => str_starts_with( $name, 'digitalogic_pricing_state_event_schedule_v1:' )
			)
		);
		$this->assertSame( 1, $GLOBALS['wpdb']->release_count );
	}

	/** A timed-out scheduler mutex preserves an unscheduled distinct fallback. */
	public function test_state_event_retry_lock_timeout_preserves_unscheduled_fallback(): void {
		$other_source                    = $this->source;
		$other_source['id']              = 'patris-uncontended';
		$args                            = array( array( $other_source ), array() );
		$GLOBALS['wpdb']->acquire_result = 0;

		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
		$scheduled = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( $args, $scheduled[0]['args'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->release_count );
	}

	/** A filtered WP-Cron success is rejected without exact storage readback. */
	#[RunInSeparateProcess]
	public function test_state_event_retry_rejects_fake_wp_cron_success_and_uses_action_scheduler(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		add_filter(
			'pre_schedule_event',
			static function () {
				return true;
			}
		);
		$args                            = array( array( $this->source ), array() );
		$GLOBALS['wpdb']->acquire_result = 0;

		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertTrue( $GLOBALS['digitalogic_test_as_actions'][0]['unique'] );
		$this->assertSame( $args, $GLOBALS['digitalogic_test_as_actions'][0]['args'] );
	}

	/** A throwing AS insertion adapter fails over to exact WP-Cron readback. */
	#[RunInSeparateProcess]
	public function test_state_event_handoff_survives_throwing_action_scheduler_insert_and_uses_wp_cron(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args = array( array( $this->source ), array() );

		$GLOBALS['digitalogic_test_as_schedule_exceptions'] = array( 'digitalogic_pricing_state_event_delivery_v1' );

		$snapshot = Digitalogic_Pricing_Snapshot::instance();

		$this->assertTrue( $snapshot->run_state_revision_event_handoff( $args[0], $args[1] ) );
		$scheduled = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( $args, $scheduled[0]['args'] );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_as_actions'] ?? array() );
		$this->assertSame( 1, $GLOBALS['wpdb']->release_count );
	}

	/** Throwing lock/WP-Cron adapters fail over to one atomic Action Scheduler action. */
	#[RunInSeparateProcess]
	public function test_state_event_retry_survives_throwing_lock_and_wp_cron_adapters(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args = array( array( $this->source ), array() );

		$GLOBALS['wpdb']->before_get_lock = static function () {
			throw new RuntimeException( 'Injected scheduler mutex adapter failure.' );
		};
		add_filter(
			'pre_schedule_event',
			static function () {
				throw new RuntimeException( 'Injected WP-Cron adapter failure.' );
			}
		);

		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame( 'digitalogic_pricing_state_event_delivery_v1', $GLOBALS['digitalogic_test_as_actions'][0]['hook'] );
		$this->assertSame( $args, $GLOBALS['digitalogic_test_as_actions'][0]['args'] );
		$this->assertTrue( $GLOBALS['digitalogic_test_as_actions'][0]['unique'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->release_count );
	}

	/** A throwing/contended handoff mutex retains one exact verified WP-Cron successor. */
	#[RunInSeparateProcess]
	public function test_state_event_handoff_lock_failure_uses_one_verified_wp_cron_successor(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args  = array( array( $this->source ), array() );
		$group = $this->invoke_snapshot( 'state_event_action_group', array( $args ) );

		$GLOBALS['digitalogic_test_as_actions'] = array(
			array(
				'id'        => 1,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_delivery_v1',
				'args'      => $args,
				'group'     => $group,
				'status'    => 'in-progress',
				'unique'    => false,
			),
			array(
				'id'        => 2,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_handoff_v1',
				'args'      => $args,
				'group'     => $group,
				'status'    => 'in-progress',
				'unique'    => true,
			),
		);
		$GLOBALS['wpdb']->before_get_lock       = static function () {
			throw new RuntimeException( 'Injected handoff mutex adapter failure.' );
		};
		$GLOBALS['wpdb']->acquire_result        = 0;
		$snapshot                               = Digitalogic_Pricing_Snapshot::instance();

		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$this->assertTrue( $snapshot->run_state_revision_event_handoff( $args[0], $args[1] ) );
		}
		$scheduled = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$this->assertCount( 1, $scheduled );
		$this->assertSame( $args, $scheduled[0]['args'] );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->release_count );
	}

	/** With WP-Cron unavailable, one recovery relay survives handoff mutex timeout. */
	#[RunInSeparateProcess]
	public function test_state_event_handoff_lock_timeout_uses_one_unique_recovery_relay(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args  = array( array( $this->source ), array() );
		$group = $this->invoke_snapshot( 'state_event_action_group', array( $args ) );

		$GLOBALS['digitalogic_test_as_actions']       = array(
			array(
				'id'        => 1,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_delivery_v1',
				'args'      => $args,
				'group'     => $group,
				'status'    => 'in-progress',
				'unique'    => false,
			),
			array(
				'id'        => 2,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_handoff_v1',
				'args'      => $args,
				'group'     => $group,
				'status'    => 'in-progress',
				'unique'    => true,
			),
		);
		$GLOBALS['digitalogic_test_schedule_failure'] = true;
		$GLOBALS['wpdb']->acquire_result              = 0;
		$snapshot                                     = Digitalogic_Pricing_Snapshot::instance();

		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$this->assertTrue( $snapshot->run_state_revision_event_handoff( $args[0], $args[1] ) );
		}
		$pending = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_as_actions'],
				static fn( $action ) => 'pending' === (string) $action['status']
			)
		);
		$this->assertCount( 1, $pending );
		$this->assertSame( 'digitalogic_pricing_state_event_recovery_v1', $pending[0]['hook'] );
		$this->assertSame( $args, $pending[0]['args'] );
		$this->assertTrue( $pending[0]['unique'] );

		foreach ( $GLOBALS['digitalogic_test_as_actions'] as &$action ) {
			$action['status'] = 'digitalogic_pricing_state_event_recovery_v1' === $action['hook'] ? 'in-progress' : 'complete';
		}
		unset( $action );
		$GLOBALS['digitalogic_test_schedule_failure'] = false;
		$GLOBALS['wpdb']->acquire_result              = 1;
		$this->assertTrue( $snapshot->run_state_revision_event_handoff( $args[0], $args[1] ) );
		$pending = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_as_actions'],
				static fn( $action ) => 'pending' === (string) $action['status']
			)
		);
		$this->assertCount( 1, $pending );
		$this->assertSame( 'digitalogic_pricing_state_event_delivery_v1', $pending[0]['hook'] );
		$this->assertFalse( $pending[0]['unique'] );
	}

	/** Persistent AS read failure uses verified WP-Cron without non-unique insert storms. */
	#[RunInSeparateProcess]
	public function test_persistent_action_scheduler_query_failure_retains_one_wp_cron_action_per_identity(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args               = array( array( $this->source ), array() );
		$other_source       = $this->source;
		$other_source['id'] = 'patris-query-failure-peer';
		$other_args         = array( array( $other_source ), array() );

		$GLOBALS['digitalogic_test_as_query_exceptions'] = 1000;
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
			$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $other_args ) );
		}
		$scheduled = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$this->assertCount( 2, $scheduled );
		$this->assertSame( array( $args, $other_args ), array_column( $scheduled, 'args' ) );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_as_actions'] ?? array() );
	}

	/** Persistent unreadable AS plus failed WP-Cron remains bounded by unique relay hooks. */
	#[RunInSeparateProcess]
	public function test_persistent_scheduler_read_failure_bounds_atomic_relays_and_retains_diversity(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args               = array( array( $this->source ), array() );
		$other_source       = $this->source;
		$other_source['id'] = 'patris-query-failure-degraded-peer';
		$other_args         = array( array( $other_source ), array() );

		$GLOBALS['digitalogic_test_as_query_exceptions'] = 5000;
		$GLOBALS['digitalogic_test_schedule_failure']    = true;
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$this->invoke_snapshot( 'schedule_state_revision_event_retry', $args );
			$this->invoke_snapshot( 'schedule_state_revision_event_retry', $other_args );
		}
		$this->assertCount( 6, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame(
			array(
				'digitalogic_pricing_state_event_delivery_v1',
				'digitalogic_pricing_state_event_delivery_v1',
				'digitalogic_pricing_state_event_handoff_v1',
				'digitalogic_pricing_state_event_handoff_v1',
				'digitalogic_pricing_state_event_recovery_v1',
				'digitalogic_pricing_state_event_recovery_v1',
			),
			array_column( $GLOBALS['digitalogic_test_as_actions'], 'hook' )
		);
		$this->assertSame( array( true, true, true, true, true, true ), array_column( $GLOBALS['digitalogic_test_as_actions'], 'unique' ) );
		$this->assertCount( 2, array_unique( array_column( $GLOBALS['digitalogic_test_as_actions'], 'group' ) ) );
	}

	/** Atomic AS uniqueness coalesces degraded contenders without evicting another identity. */
	#[RunInSeparateProcess]
	public function test_action_scheduler_unlocked_fallback_coalesces_contention_and_retains_diversity(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$GLOBALS['digitalogic_test_schedule_failure'] = true;

		$args               = array( array( $this->source ), array() );
		$other_source       = $this->source;
		$other_source['id'] = 'patris-degraded-peer';
		$other_args         = array( array( $other_source ), array() );

		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$this->assertTrue( $this->invoke_snapshot( 'schedule_state_event_retry_without_lock', array( $args ) ) );
		}
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_event_retry_without_lock', array( $other_args ) ) );

		$this->assertCount( 2, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame( array( $args, $other_args ), array_column( $GLOBALS['digitalogic_test_as_actions'], 'args' ) );
		$this->assertCount( 2, array_unique( array_column( $GLOBALS['digitalogic_test_as_actions'], 'group' ) ) );
		$this->assertSame( array( true, true ), array_column( $GLOBALS['digitalogic_test_as_actions'], 'unique' ) );
	}

	/** A running primary gets one alternate pending handoff after hard or filtered WP-Cron failure. */
	#[RunInSeparateProcess]
	public function test_action_scheduler_unlocked_running_worker_retains_one_handoff(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args               = array( array( $this->source ), array() );
		$other_source       = $this->source;
		$other_source['id'] = 'patris-filtered-peer';
		$other_args         = array( array( $other_source ), array() );
		$group              = $this->invoke_snapshot( 'state_event_action_group', array( $args ) );
		$other_group        = $this->invoke_snapshot( 'state_event_action_group', array( $other_args ) );

		$GLOBALS['digitalogic_test_as_actions']       = array(
			array(
				'id'        => 1,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_delivery_v1',
				'args'      => $args,
				'group'     => $group,
				'status'    => 'in-progress',
				'unique'    => false,
			),
			array(
				'id'        => 2,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_delivery_v1',
				'args'      => $other_args,
				'group'     => $other_group,
				'status'    => 'in-progress',
				'unique'    => false,
			),
		);
		$GLOBALS['wpdb']->acquire_result              = 0;
		$GLOBALS['digitalogic_test_schedule_failure'] = true;
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );

		$GLOBALS['digitalogic_test_schedule_failure'] = false;
		add_filter(
			'pre_schedule_event',
			static function () {
				return true;
			}
		);
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $other_args ) );
		for ( $attempt = 0; $attempt < 25; ++$attempt ) {
			$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
			$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $other_args ) );
		}

		$pending = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_as_actions'],
				static fn( $action ) => 'pending' === (string) $action['status']
			)
		);
		$this->assertCount( 2, $pending );
		$this->assertSame(
			array( 'digitalogic_pricing_state_event_handoff_v1', 'digitalogic_pricing_state_event_handoff_v1' ),
			array_column( $pending, 'hook' )
		);
		$this->assertSame( array( $args, $other_args ), array_column( $pending, 'args' ) );
		$this->assertSame( array( true, true ), array_column( $pending, 'unique' ) );
		$this->assertCount( 4, $GLOBALS['digitalogic_test_as_actions'] );

		foreach ( $GLOBALS['digitalogic_test_as_actions'] as &$action ) {
			if ( 'digitalogic_pricing_state_event_handoff_v1' === $action['hook'] ) {
				$action['status'] = 'in-progress';
			}
		}
		unset( $action );
		$snapshot                                       = Digitalogic_Pricing_Snapshot::instance();
		$GLOBALS['wpdb']->acquire_result                = 1;
		$GLOBALS['wpdb']->acquire_results               = array( 1, 0 );
		$contending_scheduler_result                    = null;
		$GLOBALS['digitalogic_test_as_before_schedule'] = function () use ( &$contending_scheduler_result, $args ) {
			$contending_scheduler_result = $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args );
		};
		$this->assertTrue( $snapshot->run_state_revision_event_handoff( $args[0], $args[1] ) );
		$this->assertTrue( $contending_scheduler_result );
		$this->assertTrue( $snapshot->run_state_revision_event_handoff( $other_args[0], $other_args[1] ) );
		for ( $attempt = 0; $attempt < 25; ++$attempt ) {
			$this->assertTrue( $snapshot->run_state_revision_event_handoff( $args[0], $args[1] ) );
			$this->assertTrue( $snapshot->run_state_revision_event_handoff( $other_args[0], $other_args[1] ) );
		}
		$pending = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_as_actions'],
				static fn( $action ) => 'pending' === (string) $action['status']
			)
		);
		$this->assertCount( 2, $pending );
		$this->assertSame(
			array( 'digitalogic_pricing_state_event_delivery_v1', 'digitalogic_pricing_state_event_delivery_v1' ),
			array_column( $pending, 'hook' )
		);
		$this->assertSame( array( $args, $other_args ), array_column( $pending, 'args' ) );
		$this->assertSame( array( false, false ), array_column( $pending, 'unique' ) );
		$this->assertCount( 6, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame( 52, $GLOBALS['wpdb']->release_count );
	}

	/** Action Scheduler uses an exact pending-only readback under a per-identity mutex. */
	public function test_state_event_retry_action_scheduler_path_is_cross_request_safe(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source fixture for a production-path guard.
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-digitalogic-pricing-snapshot.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'STATE_EVENT_SCHEDULE_LOCK_NAME', $source );
		$this->assertStringContainsString( "function_exists( 'as_get_scheduled_actions' )", $source );
		$this->assertStringContainsString( "'status'   => 'pending'", $source );
		$this->assertStringNotContainsString( 'state_event_retry_scheduled', $source );
	}

	/** A running Action Scheduler worker creates exactly one pending replacement. */
	#[RunInSeparateProcess]
	public function test_action_scheduler_running_worker_retains_one_pending_replacement(): void {
		require_once __DIR__ . '/fixtures/action-scheduler-state-event-stubs.php';
		$args                                   = array( array( $this->source ), array() );
		$GLOBALS['digitalogic_test_as_actions'] = array(
			array(
				'id'        => 1,
				'timestamp' => time(),
				'hook'      => 'digitalogic_pricing_state_event_delivery_v1',
				'args'      => $args,
				'group'     => 'digitalogic-pricing-snapshots',
				'status'    => 'in-progress',
			),
		);

		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
		$this->assertTrue( $this->invoke_snapshot( 'schedule_state_revision_event_retry', $args ) );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame( 'in-progress', $GLOBALS['digitalogic_test_as_actions'][0]['status'] );
		$this->assertSame( 'pending', $GLOBALS['digitalogic_test_as_actions'][1]['status'] );
		$this->assertSame( $args, $GLOBALS['digitalogic_test_as_actions'][1]['args'] );
	}

	/** Stale fallback actions cannot replay one recorded state or evict diversity. */
	public function test_stale_state_event_fallback_is_fenced_by_exact_recorded_identity(): void {
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_outbox_v1';
		Digitalogic_Pricing_Snapshot::instance()->schedule_state_revision_event();
		$scheduled        = $this->scheduled_events_for( 'digitalogic_pricing_state_event_delivery_v1' );
		$fallback_sources = $scheduled[0]['args'][0];

		$diverse = array();
		for ( $index = 1; $index <= 199; ++$index ) {
			$diverse[] = array(
				'id'    => $index,
				'event' => 'diversity_probe',
				'name'  => 'diversity.probe.' . $index,
				'data'  => array( 'probe' => $index ),
				'time'  => '2026-08-24 00:00:00',
			);
		}
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events']         = $diverse;
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 199;
		$GLOBALS['digitalogic_test_update_failures']                             = array();

		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery( $fallback_sources );
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 200, $events );
		$this->assertCount( 1, array_filter( $events, static fn( $event ) => 'pricing.state.changed' === (string) ( $event['name'] ?? '' ) ) );
		$this->assertSame( 'diversity.probe.1', $events[0]['name'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_receipts_v1', $GLOBALS['digitalogic_test_options'] );

		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			delete_option( 'digitalogic_pricing_state_event_receipts_v1' );
			$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
			Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery( $fallback_sources );
			$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		}

		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 200, $events );
		$this->assertCount( 1, array_filter( $events, static fn( $event ) => 'pricing.state.changed' === (string) ( $event['name'] ?? '' ) ) );
		$this->assertSame( 'diversity.probe.1', $events[0]['name'] );
	}

	/** A durable outbox marker survives receipt failure and full panel rotation. */
	public function test_state_event_outbox_delivery_marker_fences_replay_after_panel_rotation(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$snapshot->schedule_state_revision_event();
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_receipts_v1';
		$snapshot->publish_scheduled_state_revision_events();

		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_receipts_v1', $GLOBALS['digitalogic_test_options'] );
		$event      = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'][0];
		$source_key = $this->source['id'] . "\n" . $this->source['dataset'];
		$outbox     = $GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_outbox_v1'];
		$this->assertSame( $event['data']['state_revision'], $outbox[ $source_key ]['delivered_state_revision'] );
		$this->assertSame( $event['data']['idempotency_key'], $outbox[ $source_key ]['delivered_idempotency_key'] );
		$snapshot->schedule_state_revision_event();
		$outbox = $GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_outbox_v1'];
		$this->assertSame( $event['data']['state_revision'], $outbox[ $source_key ]['delivered_state_revision'] );
		$this->assertSame( $event['data']['idempotency_key'], $outbox[ $source_key ]['delivered_idempotency_key'] );

		$diverse = array();
		for ( $index = 1; $index <= 200; ++$index ) {
			$diverse[] = array(
				'id'    => 1000 + $index,
				'event' => 'diversity_probe',
				'name'  => 'diversity.after.delivery.' . $index,
				'data'  => array( 'probe' => $index ),
				'time'  => '2026-08-24 00:00:00',
			);
		}
		$this->assertTrue( update_option( 'digitalogic_panel_events', $diverse, false ) );
		$this->assertTrue( update_option( 'digitalogic_panel_event_sequence', 1200, false ) );

		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
			Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
			$this->assertSame( $diverse, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
			$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		}

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$this->assertSame( $diverse, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_receipts_v1', $GLOBALS['digitalogic_test_options'] );
	}

	/** Expired receipts cannot suppress reintroduced state and storage stays bounded. */
	public function test_state_event_receipts_expire_and_prune_to_bounded_newest_set(): void {
		$revision        = $this->revision_response()->get_data()['state_revision'];
		$idempotency_key = $this->invoke_snapshot( 'state_event_idempotency_key', array( $this->source, $revision ) );
		$source_key      = $this->source['id'] . "\n" . $this->source['dataset'];
		$receipts        = array(
			$source_key => array(
				'state_revision'  => $revision,
				'idempotency_key' => $idempotency_key,
				'recorded_at'     => gmdate( 'c', time() - HOUR_IN_SECONDS - 1 ),
			),
		);
		for ( $index = 0; $index < 205; ++$index ) {
			$receipts[ 'retired-' . $index ] = array(
				'state_revision'  => 'sha256:' . hash( 'sha256', 'state-' . $index ),
				'idempotency_key' => 'sha256:' . hash( 'sha256', 'event-' . $index ),
				'recorded_at'     => gmdate( 'c', time() - $index ),
			);
		}
		$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1'] = $receipts;

		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$snapshot->schedule_state_revision_event();
		$snapshot->publish_scheduled_state_revision_events();

		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$stored = $GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1'];
		$this->assertCount( 200, $stored );
		$this->assertArrayHasKey( $source_key, $stored );
		$this->assertSame( $idempotency_key, $stored[ $source_key ]['idempotency_key'] );
		$this->assertArrayNotHasKey( 'retired-204', $stored );
	}

	/** Source retirement persists normalized cleanup for fresh, expired, and malformed receipts. */
	public function test_source_retirement_removes_raw_receipt_identity_after_normalization(): void {
		$source_key = $this->source['id'] . "\n" . $this->source['dataset'];
		$other_key  = 'patris-other' . "\n" . $this->source['dataset'];
		$valid      = array(
			'state_revision'  => 'sha256:' . str_repeat( '1', 64 ),
			'idempotency_key' => 'sha256:' . str_repeat( '2', 64 ),
			'recorded_at'     => gmdate( 'c' ),
		);
		$cases      = array(
			'fresh'     => $valid,
			'expired'   => array_merge( $valid, array( 'recorded_at' => gmdate( 'c', time() - HOUR_IN_SECONDS - 1 ) ) ),
			'malformed' => array_merge( $valid, array( 'state_revision' => 'not-a-revision' ) ),
		);

		foreach ( $cases as $name => $receipt ) {
			$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1'] = array(
				$source_key => $receipt,
				$other_key  => $valid,
			);
			unset( $GLOBALS['digitalogic_test_option_cache']['digitalogic_pricing_state_event_receipts_v1'] );

			$this->assertTrue( $this->invoke_snapshot( 'retire_state_event_delivery_for_source', array( $this->source ) ), $name );
			$stored = $GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1'];
			$this->assertArrayNotHasKey( $source_key, $stored, $name );
			$this->assertArrayHasKey( $other_key, $stored, $name );
		}
	}

	/** Removal retires a delivered outbox marker before publishing and cannot replay it. */
	public function test_source_removal_retires_marker_after_receipt_failure_without_retry_loop(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$snapshot->schedule_state_revision_event();
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_receipts_v1';
		$snapshot->publish_scheduled_state_revision_events();

		$source_key = $this->source['id'] . "\n" . $this->source['dataset'];
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayHasKey(
			'delivered_state_revision',
			$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_outbox_v1'][ $source_key ]
		);
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_receipts_v1', $GLOBALS['digitalogic_test_options'] );

		$this->assertTrue( delete_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION ) );
		$GLOBALS['digitalogic_test_update_failures'] = array( 'digitalogic_pricing_state_event_outbox_v1' );
		$snapshot->publish_scheduled_state_revision_events();
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_source_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey( $source_key, $GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_outbox_v1'] );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$snapshot->publish_scheduled_state_revision_events();
		$this->assertSame(
			array( 'pricing.state.changed', 'pricing.source.removed' ),
			array_column( $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'], 'name' )
		);
		$this->assertArrayNotHasKey( 'digitalogic_pricing_source_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );

		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
			Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery( array( $this->source ) );
		}
		$this->assertCount( 2, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
	}

	/** Rapid reintroduction preserves the fresh state after a normal prior receipt. */
	public function test_rapid_source_reintroduction_preserves_fresh_state_after_receipt(): void {
		$this->assert_rapid_source_reintroduction_sequence( false );
	}

	/** Rapid reintroduction clears a stale delivered marker without deleting fresh state. */
	public function test_rapid_source_reintroduction_preserves_fresh_state_after_marker_failure(): void {
		$this->assert_rapid_source_reintroduction_sequence( true );
	}

	/** Store exactly one non-recurring next boundary and replace it when dates move. */
	public function test_freshness_boundary_is_one_shot_and_rescheduled_when_effective_date_changes(): void {
		$state = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		foreach ( $state['sources'] as &$source_state ) {
			$source_state['products'] = array();
		}
		unset( $source_state );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = $state;
		$GLOBALS['digitalogic_test_options']['options_update_date']                             = '2099-01-15';
		$GLOBALS['digitalogic_test_options']['update_date']                                     = '2099-01-15';
		$initial_boundary = new DateTimeImmutable( '2099-01-15 00:00:00', wp_timezone() );
		$moved_boundary   = new DateTimeImmutable( '2099-01-16 00:00:00', wp_timezone() );
		$snapshot         = Digitalogic_Pricing_Snapshot::instance();

		$this->assertTrue( $snapshot->install_freshness_boundary_schedule() );
		$initial = $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary' );
		$this->assertCount( 1, $initial );
		$this->assertSame( '', $initial[0]['recurrence'] );
		$this->assertCount( 2, $initial[0]['args'] );
		$this->assertSame( $initial_boundary->getTimestamp(), $initial[0]['timestamp'] );

		$this->assertTrue( $snapshot->install_freshness_boundary_schedule() );
		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary' ) );

		$this->assertTrue( update_option( 'options_update_date', '2099-01-16', false ) );
		$this->assertTrue( update_option( 'update_date', '2099-01-16', false ) );
		$rescheduled = $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary' );
		$this->assertCount( 1, $rescheduled );
		$this->assertSame( '', $rescheduled[0]['recurrence'] );
		$this->assertSame( $moved_boundary->getTimestamp(), $rescheduled[0]['timestamp'] );
		$this->assertGreaterThan( $initial[0]['timestamp'], $rescheduled[0]['timestamp'] );

		$snapshot->deactivate_freshness_boundary_schedule();
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary' ) );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_freshness_boundary_schedule', $GLOBALS['digitalogic_test_options'] );
	}

	/** A due freshness transition emits once and does not create a recurring poll. */
	public function test_freshness_boundary_emits_once_at_threshold_without_recurring_poll(): void {
		$past_date = gmdate( 'ymd', strtotime( '-20 days' ) );
		$GLOBALS['digitalogic_test_options']['options_update_date']              = $past_date;
		$GLOBALS['digitalogic_test_options']['update_date']                      = $past_date;
		$GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_settings'] = array(
			'stale_after_hours' => 1,
		);
		$state = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		foreach ( $state['sources'] as &$source_state ) {
			foreach ( $source_state['products'] as &$product ) {
				$product['source_updated_at'] = gmdate( 'c', time() - ( 2 * HOUR_IN_SECONDS ) );
			}
			unset( $product );
		}
		unset( $source_state );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = $state;
		$GLOBALS['digitalogic_test_option_cache'] = array();

		$timestamp   = time() - 1;
		$fingerprint = 'sha256:' . str_repeat( '8', 64 );
		$GLOBALS['digitalogic_test_options']['digitalogic_pricing_freshness_boundary_schedule'] = array(
			'timestamp'   => $timestamp,
			'fingerprint' => $fingerprint,
			'reasons'     => array( 'source-stale' ),
		);
		$redis = new Digitalogic_Test_Redis_Client();
		add_filter(
			'digitalogic_panel_redis_client',
			static function () use ( $redis ) {
				return $redis;
			}
		);

		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$snapshot->run_freshness_boundary( $timestamp, $fingerprint );
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( 'pricing.state.changed', $events[0]['name'] );
		$this->assertSame( 'freshness-boundary', $events[0]['data']['cause'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_freshness_boundary_schedule', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary' ) );

		$snapshot->run_freshness_boundary( $timestamp, $fingerprint );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertCount( 1, $redis->published );
	}

	/** Source change/removal is exact-scoped, durable, ordered, and idempotent. */
	public function test_source_change_and_removal_events_survive_queue_failure_and_are_exactly_scoped(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		Digitalogic_Report_Engine::instance();
		$changed                                     = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$source_key                                  = hash( 'sha256', $this->source['id'] . "\n" . $this->source['dataset'] );
		$changed_source                              = $this->source;
		$changed_source['revision']                  = 'sha256:' . str_repeat( '9', 64 );
		$changed['sources'][ $source_key ]['source'] = $changed_source;

		$before = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = $changed;
		unset( $GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] );
		do_action( 'digitalogic_product_sync_state_committed', $before, $changed );
		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$this->assertArrayHasKey( 'digitalogic_pricing_source_event_outbox', $GLOBALS['digitalogic_test_options'] );
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		$snapshot->publish_scheduled_state_revision_events();
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_source_event_outbox', $GLOBALS['digitalogic_test_options'] );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$snapshot->run_state_revision_event_delivery();
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertSame( array( 'pricing.source.changed', 'pricing.state.changed' ), array_column( $events, 'name' ) );
		$this->assertSame( 'changed', $events[0]['data']['change'] );
		$this->assertSame( $this->source['revision'], $events[0]['data']['previous_source_revision'] );
		$this->assertSame( $changed_source, $events[0]['data']['source'] );
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $events[0], 0, '', 'patris_pricing', $changed_source ) );
		$this->assertFalse(
			Digitalogic_Event_Mesh::event_visible_to(
				$events[0],
				0,
				'',
				'patris_pricing',
				array(
					'id'      => $changed_source['id'],
					'dataset' => 'other.db',
				)
			)
		);
		$receipt_source_key = $changed_source['id'] . "\n" . $changed_source['dataset'];
		$this->assertArrayHasKey(
			$receipt_source_key,
			$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1']
		);

		$this->assertTrue( delete_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION ) );
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_receipts_v1';
		$snapshot->publish_scheduled_state_revision_events();
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 2, $events );
		$this->assertArrayHasKey( 'digitalogic_pricing_source_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey(
			$receipt_source_key,
			$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1']
		);

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$snapshot->publish_scheduled_state_revision_events();
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 3, $events );
		$this->assertSame( 'pricing.source.removed', $events[2]['name'] );
		$this->assertSame( 'removed', $events[2]['data']['change'] );
		$this->assertSame( $changed_source['revision'], $events[2]['data']['previous_source_revision'] );
		$this->assertSame( $changed_source, $events[2]['data']['source'] );
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $events[2], 0, '', 'patris_pricing', $changed_source ) );
		$this->assertArrayNotHasKey(
			$receipt_source_key,
			$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1']
		);

		$snapshot->publish_scheduled_state_revision_events();
		$this->assertCount( 3, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
	}

	/** One cold computation produces fixed immutable pages and replay survives drift. */
	public function test_cold_build_computes_once_pages_cheaply_and_replays_exactly(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-request-0001', $revision, 0 );
		$this->assertSame( 202, $started->get_status() );
		$build_id = $started->get_data()['build_id'];
		$pending  = $this->status_response( $build_id );
		$this->assertSame( 202, $pending->get_status() );
		$pending_conditional = $this->status_response( $build_id, array( 'If-None-Match' => $pending->get_headers()['ETag'] ) );
		$this->assertSame( 202, $pending_conditional->get_status() );
		$this->assertNotNull( $pending_conditional->get_data() );
		$this->assertArrayHasKey( 'Retry-After', $pending_conditional->get_headers() );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		$ready = $this->status_response( $build_id );
		$this->assertSame( 200, $ready->get_status() );
		$this->assertSame( 'ready', $ready->get_data()['status'] );
		$this->assertSame( 251, $ready->get_data()['row_count'] );
		$this->assertSame( 2, $ready->get_data()['page_count'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_query_args'] );

		$token    = $ready->get_data()['snapshot_token'];
		$snapshot = $this->snapshot_response( $token );
		$this->assertSame( 200, $snapshot->get_status() );
		$payload = $snapshot->get_data();
		$this->assertSame( 251, $payload['row_count'] );
		$this->assertSame( 251, $payload['distinct_sync_keys'] );
		$this->assertSame( 251, $payload['remote_total'] );
		$this->assertSame( 251, $payload['integrity']['row_count'] );
		$this->assertSame( 251, $payload['reconciliation']['counts']['source_only'] );
		$this->assertSame( 0, $payload['reconciliation']['counts']['ambiguous_codes'] );
		$this->assertCount( 26, $payload['catalog']['columns'] );
		$this->assertSame( $this->excel_keys(), array_column( $payload['catalog']['columns'], 'key' ) );
		$this->assertSame( $this->excel_keys(), array_keys( $payload['catalog']['rows'][0] ) );
		foreach ( $this->excel_non_projection_keys() as $key ) {
			$this->assertArrayNotHasKey( $key, $payload['catalog']['rows'][0] );
		}
		$this->assertCount( 251, $payload['catalog']['rows'] );

		$page_one = $this->page_response( $token, 1 );
		$page_two = $this->page_response( $token, 2 );
		$this->assertCount( 250, $page_one->get_data()['rows'] );
		$this->assertCount( 1, $page_two->get_data()['rows'] );
		$this->assertSame( $payload['digest'], $page_two->get_data()['digest'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_query_args'] );

		$page_etag = $page_one->get_headers()['ETag'];
		$page_304  = $this->page_response( $token, 1, array( 'If-None-Match' => $page_etag ) );
		$this->assertSame( 304, $page_304->get_status() );
		$this->assertArrayHasKey( 'Cache-Control', $page_304->get_headers() );

		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$after_apply = $this->revision_response()->get_data()['state_revision'];
		$this->assertNotSame( $revision, $after_apply );

		$replay = $this->start_response( 'snapshot-request-0001', $revision, 0 );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertTrue( $replay->get_data()['replayed'] );
		$this->assertSame( $build_id, $replay->get_data()['build_id'] );

		$conflict = $this->start_response( 'snapshot-request-0001', $revision, 60 );
		$this->assertSame( 409, $conflict->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_idempotency_conflict', $conflict->get_data()['code'] );

		$stale = $this->start_response( 'snapshot-request-0002', $revision, 0 );
		$this->assertSame( 412, $stale->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_state_revision_conflict', $stale->get_data()['code'] );

		$cancel_ready = $this->cancel_response( $build_id );
		$this->assertSame( 409, $cancel_ready->get_status() );
	}

	/** Admission persists independent build/watchdog paths and sibling delivery is a no-op. */
	public function test_cold_admission_uses_wp_cron_when_action_scheduler_is_unavailable_and_cleans_sibling_actions(): void {
		$GLOBALS['digitalogic_test_action_scheduler_available'] = false;
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-dual-path-0001', $revision, 0 );
		$GLOBALS['digitalogic_test_action_scheduler_available'] = true;
		$build_id = $started->get_data()['build_id'];
		$job_key  = $this->invoke_snapshot( 'job_key', array( $build_id ) );
		$job      = $GLOBALS['digitalogic_test_transients'][ $job_key ]['value'];

		$this->assertSame( 202, $started->get_status() );
		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertSame(
			array( $build_id ),
			$this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' )[0]['args']
		);
		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );
		$this->assertSame(
			array( $build_id, $job['watchdog_token'] ),
			$this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' )[0]['args']
		);

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$this->assertCount( 1, $this->terminal_events() );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$this->assertCount( 1, $this->terminal_events() );
	}

	/** Both schedulers are attempted and either durable path is sufficient. */
	public function test_dual_one_shot_scheduler_exercises_complete_durability_matrix(): void {
		$cases = array(
			array( true, true, true ),
			array( true, false, true ),
			array( false, true, true ),
			array( false, false, false ),
		);
		foreach ( $cases as $case ) {
			$attempts         = array();
			$action_scheduler = static function ( $hook, $args, $timestamp, $mode ) use ( &$attempts, $case ) {
				$attempts[] = array( 'action_scheduler', $hook, $args, $timestamp, $mode );
				return $case[0];
			};
			$wp_cron          = static function ( $hook, $args, $timestamp ) use ( &$attempts, $case ) {
				$attempts[] = array( 'wp_cron', $hook, $args, $timestamp );
				return $case[1];
			};
			$result           = $this->invoke_snapshot(
				'schedule_dual_one_shot',
				array(
					'digitalogic_pricing_snapshot_test',
					array( 'build_fixture' ),
					time() + 5,
					'async',
					$action_scheduler,
					$wp_cron,
				)
			);

			$this->assertSame( $case[2], $result );
			$this->assertCount( 2, $attempts );
			$this->assertSame( 'action_scheduler', $attempts[0][0] );
			$this->assertSame( 'wp_cron', $attempts[1][0] );
			$this->assertSame( 'async', $attempts[0][4] );
			$this->assertSame( $attempts[0][1], $attempts[1][1] );
			$this->assertSame( $attempts[0][2], $attempts[1][2] );
			$this->assertSame( $attempts[0][3], $attempts[1][3] );
		}
	}

	/** One scheduler adapter failure cannot suppress the independent path. */
	public function test_dual_one_shot_scheduler_isolates_adapter_errors(): void {
		$attempts      = array();
		$throwing_as   = static function () use ( &$attempts ) {
			$attempts[] = 'action_scheduler';
			throw new RuntimeException( 'synthetic Action Scheduler failure' );
		};
		$successful_wp = static function () use ( &$attempts ) {
			$attempts[] = 'wp_cron';
			return true;
		};
		$this->assertTrue(
			$this->invoke_snapshot(
				'schedule_dual_one_shot',
				array( 'digitalogic_pricing_snapshot_test', array( 'build_fixture' ), time() + 5, 'async', $throwing_as, $successful_wp )
			)
		);
		$this->assertSame( array( 'action_scheduler', 'wp_cron' ), $attempts );

		$attempts    = array();
		$wp_error_as = static function () use ( &$attempts ) {
			$attempts[] = 'action_scheduler';
			return new WP_Error( 'synthetic_action_scheduler_failure', 'synthetic failure' );
		};
		$throwing_wp = static function () use ( &$attempts ) {
			$attempts[] = 'wp_cron';
			throw new RuntimeException( 'synthetic WP-Cron failure' );
		};
		$this->assertFalse(
			$this->invoke_snapshot(
				'schedule_dual_one_shot',
				array( 'digitalogic_pricing_snapshot_test', array( 'build_fixture' ), time() + 5, 'single', $wp_error_as, $throwing_wp )
			)
		);
		$this->assertSame( array( 'action_scheduler', 'wp_cron' ), $attempts );
	}

	/** A reported WP-Cron write is durable only after exact readback. */
	public function test_dual_one_shot_scheduler_requires_exact_wp_cron_readback(): void {
		$scheduled = static function () {
			return true;
		};
		$unavailable_as = static function () {
			return false;
		};
		$existing  = static function () {
			return time() + 5;
		};

		foreach ( array( false, null, true, new WP_Error( 'filtered_schedule' ) ) as $invalid_readback ) {
			$invalid = static function () use ( $invalid_readback ) {
				return $invalid_readback;
			};

			$this->assertFalse(
				$this->invoke_snapshot(
					'schedule_dual_one_shot',
					array( 'digitalogic_pricing_snapshot_test', array( 'build_fixture' ), time() + 5, 'single', $unavailable_as, $scheduled, $invalid )
				)
			);
		}
		$this->assertTrue(
			$this->invoke_snapshot(
				'schedule_dual_one_shot',
				array( 'digitalogic_pricing_snapshot_test', array( 'build_fixture' ), time() + 5, 'single', $unavailable_as, $scheduled, $existing )
			)
		);
	}

	/** A contended-worker retry is dual scheduled and a late sibling is harmless. */
	public function test_retry_worker_dual_schedules_and_late_sibling_is_noop(): void {
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-retry-dual-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];

		$this->assertTrue( $this->invoke_snapshot( 'retry_worker', array( $build_id ) ) );
		$this->assertCount( 4, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$this->assertCount( 1, $this->terminal_events() );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$this->assertCount( 1, $this->terminal_events() );
	}

	/** A test/host AS-path override cannot bypass the independent WP-Cron record. */
	public function test_enqueue_override_cannot_bypass_wp_cron_build_activation(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-override-dual-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];

		$this->assertSame( 202, $started->get_status() );
		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertCount( 2, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );

		$this->cancel_response( $build_id );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );
	}

	/** Every request attached to one cold build receives one Living terminal event. */
	public function test_coalesced_requests_receive_distinct_versionless_terminal_events(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$first_id = 'sha256:' . str_repeat( '3', 64 );
		$next_id  = 'sha256:' . str_repeat( '4', 64 );
		$first    = $this->start_response( $first_id, $revision, 0 );
		$next     = $this->start_response( $next_id, $revision, 0 );

		$this->assertSame( $first->get_data()['build_id'], $next->get_data()['build_id'] );
		$this->assertTrue( $next->get_data()['replayed'] );
		Digitalogic_Pricing_Snapshot::instance()->run_build( $first->get_data()['build_id'] );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();

		$events = $this->terminal_events();
		$this->assertCount( 2, $events );
		$this->assertSame( array( $first_id, $next_id ), array_column( array_column( $events, 'data' ), 'request_id' ) );
		$this->assertSame( array( 'ready', 'ready' ), array_column( array_column( $events, 'data' ), 'status' ) );
		$this->assertCount( 2, array_unique( array_column( array_column( $events, 'data' ), 'idempotency_key' ) ) );
		foreach ( $events as $event ) {
			$this->assertSame( Digitalogic_Pricing_Snapshot::BUILD_EVENT_SCHEMA, $event['data']['schema'] );
			$this->assertArrayNotHasKey( 'schema_version', $event['data'] );
		}
	}

	/** Cross-request retries use one pending-only readback under a database mutex. */
	public function test_terminal_event_retry_is_atomic_and_pending_only(): void {
		$this->assertTrue( $this->invoke_snapshot( 'schedule_terminal_event_retry' ) );
		$this->assertTrue( $this->invoke_snapshot( 'schedule_terminal_event_retry' ) );
		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_terminal_event_delivery' ) );
		$this->assertContains( 'digitalogic_pricing_terminal_event_schedule', $GLOBALS['wpdb']->lock_names );

		$source = implode( '', iterator_to_array( new SplFileObject( dirname( __DIR__ ) . '/includes/class-digitalogic-pricing-snapshot.php' ) ) );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'TERMINAL_EVENT_SCHEDULE_LOCK_NAME', $source );
		$this->assertStringContainsString( "'status'   => 'pending'", $source );
		$this->assertStringNotContainsString( "add_action( 'shutdown', array( \$this, 'publish_scheduled_terminal_events' )", $source );
	}

	/** A conflicting accepted terminal is preserved without retrying forever. */
	public function test_terminal_event_conflict_is_removed_from_retry_outbox(): void {
		$revision   = $this->revision_response()->get_data()['state_revision'];
		$started    = $this->start_response( 'terminal-conflict-0001', $revision, 0 );
		$build_id   = $started->get_data()['build_id'];
		$request_id = 'terminal-conflict-0001';
		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		$event = $this->terminal_events()[0];
		$data  = $event['data'];
		unset( $data['snapshot_token'], $data['snapshot_revision'], $data['digest'], $data['snapshot_path'] );
		$data['status'] = 'cancelled';
		$data['code']   = 'request_cancelled';
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events'][0]['data'] = $data;

		update_option(
			'digitalogic_pricing_snapshot_terminal_event_outbox_v1',
			array(
				$event['data']['idempotency_key'] => array(
					'name'         => $event['name'],
					'data'         => $event['data'],
					'build_id'     => $build_id,
					'request_id'   => $request_id,
					'attempts'     => 0,
					'created_at'   => time(),
					'committed'    => true,
					'committed_at' => time(),
					'updated_at'   => gmdate( 'c' ),
				),
			),
			false
		);

		Digitalogic_Pricing_Snapshot::instance()->run_terminal_event_delivery();

		$this->assertCount( 1, $this->terminal_events() );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_snapshot_terminal_event_outbox', $GLOBALS['digitalogic_test_options'] );
	}

	/** The no-poll path autonomously terminalizes a missed queued worker. */
	public function test_build_watchdog_is_job_fenced_and_publishes_queue_timeout(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-watchdog-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];
		$job_key  = $this->invoke_snapshot( 'job_key', array( $build_id ) );
		$job      = $GLOBALS['digitalogic_test_transients'][ $job_key ]['value'];
		$this->assertMatchesRegularExpression( '/\A[a-f0-9]{32}\z/D', $job['watchdog_token'] );

		$GLOBALS['digitalogic_test_transients'][ $job_key ]['value']['start_deadline_at'] = gmdate( 'c', time() - 1 );
		Digitalogic_Pricing_Snapshot::instance()->run_build_watchdog( $build_id, str_repeat( '0', 32 ) );
		$this->assertSame( 'queued', $GLOBALS['digitalogic_test_transients'][ $job_key ]['value']['status'] );

		Digitalogic_Pricing_Snapshot::instance()->run_build_watchdog( $build_id, $job['watchdog_token'] );
		$status = $this->status_response( $build_id );
		$this->assertSame( 503, $status->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_scheduler_start_timeout', $status->get_data()['code'] );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$events = $this->terminal_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'failed', $events[0]['data']['status'] );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );
	}

	/** A crashed worker with an expired lease becomes a bounded retryable terminal. */
	public function test_build_watchdog_terminalizes_expired_running_worker(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-watchdog-stalled-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];
		$job_key  = $this->invoke_snapshot( 'job_key', array( $build_id ) );
		$job      = $GLOBALS['digitalogic_test_transients'][ $job_key ]['value'];
		$GLOBALS['digitalogic_test_transients'][ $job_key ]['value']['status'] = 'running';
		add_option(
			$this->invoke_snapshot( 'worker_key', array( $build_id ) ),
			array(
				'build_id'   => $build_id,
				'token'      => str_repeat( 'a', 32 ),
				'expires_at' => time() - 1,
			),
			'',
			false
		);

		Digitalogic_Pricing_Snapshot::instance()->run_build_watchdog( $build_id, $job['watchdog_token'] );
		$status = $this->status_response( $build_id );
		$this->assertSame( 503, $status->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_worker_stalled', $status->get_data()['code'] );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$this->assertSame( 'digitalogic_pricing_snapshot_worker_stalled', $this->terminal_events()[0]['data']['code'] );
	}

	/** An uncaught worker throwable is converted into a bounded secret-free terminal. */
	public function test_worker_throwable_is_caught_and_published_as_failure(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-throwable-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];
		$GLOBALS['digitalogic_test_transient_set_callback'] = static function ( $name, $value ) {
			if (
				str_starts_with( (string) $name, 'digitalogic_pricing_snapshot_job_' )
				&& is_array( $value )
				&& 'running' === (string) ( $value['status'] ?? '' )
			) {
				$GLOBALS['digitalogic_test_transient_set_callback'] = null;
				throw new RuntimeException( 'synthetic worker throwable' );
			}
			return true;
		};

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		$status = $this->status_response( $build_id );
		$this->assertSame( 503, $status->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_worker_exception', $status->get_data()['code'] );
		Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		$events = $this->terminal_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'digitalogic_pricing_snapshot_worker_exception', $events[0]['data']['code'] );
		$this->assertArrayNotHasKey( 'message', $events[0]['data'] );
	}

	/** The Living snapshot contains only the exact fields consumed by Excel. */
	public function test_living_snapshot_contract_is_exactly_26_fields_and_rejects_versioned_schema(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data();
		$started  = $this->start_response( 'snapshot-living-0001', $revision['state_revision'], 0 );
		$this->assertSame( 202, $started->get_status() );
		$this->assertSame( Digitalogic_Pricing_Snapshot::BUILD_SCHEMA, $started->get_data()['schema'] );
		$this->assertArrayNotHasKey( 'schema_version', $started->get_data() );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $started->get_data()['build_id'] );
		$ready   = $this->status_response( $started->get_data()['build_id'] );
		$payload = $this->snapshot_response( $ready->get_data()['snapshot_token'] )->get_data();
		$this->assertSame( Digitalogic_Pricing_Snapshot::SNAPSHOT_SCHEMA, $payload['schema'] );
		$this->assertArrayNotHasKey( 'schema_version', $payload );
		$this->assertSame( 'excel', $payload['projection'] );
		$this->assertSame( $this->excel_keys(), array_column( $payload['catalog']['columns'], 'key' ) );
		$this->assertSame( $this->excel_keys(), array_keys( $payload['catalog']['rows'][0] ) );
		$this->assertCount( 26, $payload['catalog']['columns'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_query_args'] );

		$versioned = $this->start_response(
			'snapshot-versioned-0001',
			$revision['state_revision'],
			0,
			array( 'schema' => 'digitalogic.unsupported-pricing-snapshot-request' )
		);
		$this->assertSame( 422, $versioned->get_status() );

		$removed_field = $this->start_response(
			'snapshot-removed-field-0001',
			$revision['state_revision'],
			0,
			array( 'schema_version' => 1 )
		);
		$this->assertSame( 400, $removed_field->get_status() );
		$this->assertSame(
			'digitalogic_pricing_snapshot_unknown_fields',
			$removed_field->get_data()['code']
		);
	}

	/** Queued cancellation is terminal, repeatable, and releases build admission. */
	public function test_queued_cancel_is_durable_and_idempotent(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-cancel-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];

		$cancelled = $this->cancel_response( $build_id );
		$this->assertSame( 200, $cancelled->get_status() );
		$this->assertSame( 'cancelled', $cancelled->get_data()['status'] );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build' ) );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_snapshot_build_watchdog' ) );
		$repeated = $this->cancel_response( $build_id );
		$this->assertSame( 200, $repeated->get_status() );
		$this->assertSame( 'cancelled', $repeated->get_data()['status'] );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		$this->assertSame( 'cancelled', $this->status_response( $build_id )->get_data()['status'] );

		$next = $this->start_response( 'snapshot-cancel-0002', $revision, 0 );
		$this->assertSame( 202, $next->get_status() );
		$this->assertNotSame( $build_id, $next->get_data()['build_id'] );
	}

	/** A queue watchdog never fails a worker that already owns a live lease. */
	public function test_queue_timeout_is_fenced_by_live_worker_lease(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-timeout-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];
		$job_key  = $this->invoke_snapshot( 'job_key', array( $build_id ) );
		$GLOBALS['digitalogic_test_transients'][ $job_key ]['value']['start_deadline_at'] = gmdate( 'c', time() - 1 );

		$worker_key = $this->invoke_snapshot( 'worker_key', array( $build_id ) );
		add_option(
			$worker_key,
			array(
				'build_id'   => $build_id,
				'token'      => str_repeat( 'a', 32 ),
				'expires_at' => time() + 60,
			),
			'',
			false
		);
		$live = $this->status_response( $build_id );
		$this->assertSame( 202, $live->get_status() );
		$this->assertSame( 'queued', $live->get_data()['status'] );

		delete_option( $worker_key );
		$expired = $this->status_response( $build_id );
		$this->assertSame( 503, $expired->get_status() );
		$this->assertSame( 'failed', $expired->get_data()['status'] );
		$this->assertSame( 'digitalogic_pricing_snapshot_scheduler_start_timeout', $expired->get_data()['code'] );
	}

	/** The worker itself enforces its advertised build deadline at checkpoints. */
	public function test_worker_deadline_fails_before_projection_or_publish(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-deadline-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];
		$job_key  = $this->invoke_snapshot( 'job_key', array( $build_id ) );
		$GLOBALS['digitalogic_test_transients'][ $job_key ]['value']['deadline_at'] = gmdate( 'c', time() - 1 );

		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );
		$status = $this->status_response( $build_id );
		$this->assertSame( 503, $status->get_status() );
		$this->assertSame( 'failed', $status->get_data()['status'] );
		$this->assertSame( 'digitalogic_pricing_snapshot_build_timeout', $status->get_data()['code'] );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_wc_product_query_args'] );
	}

	/** Failed terminal status cannot be hidden by 304 and partial publication rolls back. */
	public function test_ready_job_storage_failure_rolls_back_and_status_stays_503(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-failure-0001', $revision, 0 );
		$build_id = $started->get_data()['build_id'];

		$GLOBALS['digitalogic_test_transient_set_callback'] = static function ( $name, $value ) {
			if (
				str_starts_with( (string) $name, 'digitalogic_pricing_snapshot_job_' )
				&& is_array( $value )
				&& 'ready' === ( $value['status'] ?? '' )
			) {
				$GLOBALS['digitalogic_test_transient_set_callback'] = null;
				return false;
			}
			return true;
		};
		Digitalogic_Pricing_Snapshot::instance()->run_build( $build_id );

		$status = $this->status_response( $build_id );
		$this->assertSame( 503, $status->get_status() );
		$this->assertSame( 'failed', $status->get_data()['status'] );
		$this->assertSame( 'digitalogic_pricing_snapshot_storage_unavailable', $status->get_data()['code'] );
		$conditional = $this->status_response( $build_id, array( 'If-None-Match' => $status->get_headers()['ETag'] ) );
		$this->assertSame( 503, $conditional->get_status() );
		$this->assertNotNull( $conditional->get_data() );
		$this->assertArrayHasKey( 'Retry-After', $conditional->get_headers() );

		foreach ( array_keys( $GLOBALS['digitalogic_test_transients'] ) as $key ) {
			$this->assertStringNotContainsString( 'digitalogic_pricing_snapshot_ready_', $key );
			$this->assertStringNotContainsString( 'digitalogic_pricing_snapshot_meta_', $key );
			$this->assertStringNotContainsString( 'digitalogic_pricing_snapshot_page_', $key );
		}
	}

	/** Corrupting one immutable page is rejected by bulk and page conditionals. */
	public function test_page_digest_is_recomputed_before_conditional_response(): void {
		add_filter(
			'digitalogic_pricing_snapshot_enqueue',
			static function () {
				return true;
			}
		);
		$revision = $this->revision_response()->get_data()['state_revision'];
		$started  = $this->start_response( 'snapshot-corrupt-0001', $revision, 0 );
		Digitalogic_Pricing_Snapshot::instance()->run_build( $started->get_data()['build_id'] );
		$ready         = $this->status_response( $started->get_data()['build_id'] )->get_data();
		$page          = $this->page_response( $ready['snapshot_token'], 1 );
		$etag          = $page->get_headers()['ETag'];
		$snapshot      = $this->snapshot_response( $ready['snapshot_token'] );
		$snapshot_etag = $snapshot->get_headers()['ETag'];
		$page_key      = '';

		foreach ( $GLOBALS['digitalogic_test_transients'] as $key => $record ) {
			if ( str_starts_with( $key, 'digitalogic_pricing_snapshot_page_' ) && is_array( $record['value'] ?? null ) && 250 === count( $record['value'] ) ) {
				$page_key = $key;
				$GLOBALS['digitalogic_test_transients'][ $key ]['value'][0]['technical_name'] = 'corrupted';
				break;
			}
		}
		$this->assertNotSame( '', $page_key );

		$bulk_corrupt = $this->snapshot_response( $ready['snapshot_token'], array( 'If-None-Match' => $snapshot_etag ) );
		$this->assertSame( 503, $bulk_corrupt->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_digest_mismatch', $bulk_corrupt->get_data()['code'] );

		$corrupt = $this->page_response( $ready['snapshot_token'], 1, array( 'If-None-Match' => $etag ) );
		$this->assertSame( 503, $corrupt->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_page_digest_mismatch', $corrupt->get_data()['code'] );

		unset( $GLOBALS['digitalogic_test_transients'][ $page_key ] );
		$bulk_missing = $this->snapshot_response( $ready['snapshot_token'], array( 'If-None-Match' => $snapshot_etag ) );
		$this->assertSame( 503, $bulk_missing->get_status() );
		$this->assertSame( 'digitalogic_pricing_snapshot_cache_incomplete', $bulk_missing->get_data()['code'] );
	}

	/** Synthetic counts prove runtime algebra without freezing production state. */
	public function test_snapshot_count_algebra_is_runtime_derived(): void {
		$fixture = json_decode(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local immutable test fixture, not a URL.
			(string) file_get_contents( __DIR__ . '/fixtures/pricing-snapshot-count-algebra.json' ),
			true
		);
		$this->assertIsArray( $fixture );
		$this->assertSame( 'synthetic_count_algebra', $fixture['evidence_class'] );
		$report         = $fixture['report'];
		$product_sync   = $fixture['product_sync'];
		$expected       = $fixture['expected_snapshot'];
		$projected_rows = $report['matched'] + $report['source_only'] + $report['woo_only'];
		$this->assertGreaterThan( 0, $projected_rows );
		$this->assertSame(
			$projected_rows,
			$expected['row_count']
		);
		$this->assertSame( $expected['row_count'], $expected['distinct_sync_keys'] );
		$this->assertSame( $expected['row_count'], $expected['remote_total'] );
		$this->assertSame( $report['patris'], $report['matched'] + $report['source_only'] );
		$this->assertSame( $report['woo_usable'], $report['matched'] + $report['woo_only'] );
		$this->assertSame(
			$report['woo_raw'],
			$report['woo_usable'] + $report['variable_parents_excluded']
		);
		$this->assertSame( $report['matched'], $product_sync['applied'] );
		$this->assertSame( $report['source_only'], $product_sync['deferred'] );
		$this->assertSame( 0, $product_sync['pending'] );
		$this->assertSame( 0, $report['ambiguous'] );
		$this->assertSame( array(), $report['integrity_warnings'] );
		$this->assertArrayNotHasKey( 'captured_at_tehran', $fixture );
		$this->assertArrayNotHasKey( 'source_id', $product_sync );
		$this->assertArrayNotHasKey( 'source_dataset', $product_sync );
		$this->assertArrayNotHasKey( 'source_revision', $product_sync );
		$this->assertArrayNotHasKey( 'snapshot_revision', $report );
	}

	/** Return the immutable ordered Excel projection contract. */
	private function excel_keys(): array {
		return array(
			'sync_key',
			'reconciliation_status',
			'patris_code',
			'woocommerce_id',
			'sku',
			'weight_grams',
			'foreign_price',
			'patris_location',
			'categories',
			'foreign_currency',
			'shipping_price_per_kg',
			'shipping_price_per_kg_currency',
			'profit_margin_percent',
			'price_source_amount',
			'price_source_currency',
			'price_source_kind',
			'effective_price',
			'patris_total_stock',
			'stock_quantity',
			'name',
			'updated_at',
			'record_revision',
			'permalink',
			'patris_final_price',
			'sale_price',
			'publication_status',
		);
	}

	/** Return canonical catalog fields deliberately excluded from Excel snapshots. */
	private function excel_non_projection_keys(): array {
		return array(
			'parent_id',
			'product_type',
			'part_number',
			'category_ids',
			'currency',
			'regular_price',
			'price_status',
			'stock_status',
			'patris_minimum_stock',
			'woocommerce_weight',
			'woocommerce_weight_unit',
			'partner_price_irr',
			'price_rounding_digits',
			'price_rounding_mode',
			'shipping_method_id',
			'shipping_method_name_en',
			'shipping_method_name_fa',
			'image_url',
			'sync_status',
			'sync_error',
		);
	}

	/** Return one revision response. */
	private function revision_response( $headers = array(), $method = 'GET', $extra = array() ) {
		return Digitalogic_REST_API::instance()->pricing_sync_revision( $this->query_request( $method, $extra, $headers ) );
	}

	/** Start one exact revision-bound build. */
	private function start_response( $request_id, $state_revision, $max_age, $extra = array() ) {
		$payload = array_merge(
			array(
				'schema'                  => Digitalogic_Pricing_Snapshot::REQUEST_SCHEMA,
				'operation'               => 'snapshot',
				'client_id'               => 'patris-export',
				'channel'                 => 'excel-workbook',
				'request_id'              => $request_id,
				'idempotency_key'         => $request_id,
				'source'                  => $this->source,
				'locale'                  => 'fa',
				'page_size'               => 250,
				'max_age_seconds'         => $max_age,
				'expected_state_revision' => $state_revision,
			),
			$extra
		);
		$request = new WP_REST_Request(
			array(),
			$payload,
			array(
				'X-Patris-Product-Sync-Secret' => 'receiver-secret',
				'Idempotency-Key'              => $request_id,
				'If-Match'                     => '"' . $state_revision . '"',
			),
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
		$request->set_method( 'POST' );

		return Digitalogic_REST_API::instance()->pricing_sync_snapshot_start( $request );
	}

	/** Return one build status. */
	private function status_response( $build_id, $headers = array() ) {
		return Digitalogic_REST_API::instance()->pricing_sync_snapshot_status(
			$this->query_request( 'GET', array( 'build_id' => $build_id ), $headers )
		);
	}

	/** Cancel one build. */
	private function cancel_response( $build_id ) {
		return Digitalogic_REST_API::instance()->pricing_sync_snapshot_cancel(
			$this->query_request( 'DELETE', array( 'build_id' => $build_id ) )
		);
	}

	/** Return a bulk immutable snapshot. */
	private function snapshot_response( $token, $headers = array() ) {
		return Digitalogic_REST_API::instance()->pricing_sync_snapshot(
			$this->query_request( 'GET', array( 'snapshot_token' => $token ), $headers )
		);
	}

	/** Return one immutable page. */
	private function page_response( $token, $page, $headers = array() ) {
		return Digitalogic_REST_API::instance()->pricing_sync_snapshot_page(
			$this->query_request(
				'GET',
				array(
					'snapshot_token' => $token,
					'page'           => $page,
				),
				$headers
			)
		);
	}

	/** Build one source-bound query request. */
	private function query_request( $method, $extra = array(), $headers = array() ) {
		$request = new WP_REST_Request(
			array_merge(
				array(
					'source_id'       => $this->source['id'],
					'source_dataset'  => $this->source['dataset'],
					'source_revision' => $this->source['revision'],
					'locale'          => 'fa',
					'page_size'       => 250,
					'projection'      => 'excel',
				),
				$extra
			),
			array(),
			array_merge( array( 'X-Patris-Product-Sync-Secret' => 'receiver-secret' ), $headers )
		);
		$request->set_method( $method );

		return $request;
	}

	/** Return one exact source-only row. */
	private function source_product( $code ) {
		return array(
			'product_code'                   => $code,
			'name'                           => 'Snapshot product ' . $code,
			'foreign_currency'               => 'CNY',
			'foreign_price'                  => '10',
			'price_source_amount'            => '10',
			'price_source_currency'          => 'CNY',
			'price_source_kind'              => 'foreign_price',
			'weight_grams'                   => '100',
			'total_stock'                    => 5,
			'shipping_method_id'             => 'air_express',
			'shipping_price_per_kg'          => '120',
			'shipping_price_per_kg_currency' => 'CNY',
			'markup_percent'                 => '30',
			'irt_per_cny'                    => '29500',
			'price_rounding_digits'          => 0,
			'price_rounding_mode'            => 'nearest_half_up',
			'final_price'                    => 507400,
			'source_updated_at'              => gmdate( 'c' ),
			'warnings'                       => array(),
			'record_hash'                    => 'sha256:' . hash( 'sha256', $code ),
		);
	}

	/** Return installed default-markup state. */
	private function default_markup_state() {
		$identity = array(
			'schema'         => Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_SCHEMA,
			'configured'     => true,
			'type'           => 'percentage',
			'source'         => 'global_default',
			'profit_percent' => '30',
		);

		return array_merge(
			$identity,
			array(
				'revision'   => 'sha256:' . hash( 'sha256', wp_json_encode( $identity ) ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated_by' => 0,
			)
		);
	}

	/** Assert ordered remove/re-add delivery with or without a durable old marker. */
	private function assert_rapid_source_reintroduction_sequence( $fail_receipt ) {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$snapshot->schedule_state_revision_event();
		if ( $fail_receipt ) {
			$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_receipts_v1';
		}
		$snapshot->publish_scheduled_state_revision_events();
		$old_event = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'][0];
		$this->assertSame( 'pricing.state.changed', $old_event['name'] );

		$source_key = $this->source['id'] . "\n" . $this->source['dataset'];
		if ( $fail_receipt ) {
			$this->assertArrayHasKey(
				'delivered_state_revision',
				$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_outbox_v1'][ $source_key ]
			);
		} else {
			$this->assertArrayHasKey(
				$source_key,
				$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1']
			);
		}

		$state = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$this->assertTrue( delete_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION ) );
		$this->assertTrue( add_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, '', 'no' ) );
		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$snapshot->publish_scheduled_state_revision_events();

		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertSame(
			array( 'pricing.state.changed', 'pricing.source.removed', 'pricing.source.changed', 'pricing.state.changed' ),
			array_column( $events, 'name' )
		);
		$this->assertSame( 'removed', $events[1]['data']['change'] );
		$this->assertSame( 'added', $events[2]['data']['change'] );
		$this->assertNotSame( $events[0]['data']['idempotency_key'], $events[3]['data']['idempotency_key'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_source_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey(
			$source_key,
			$GLOBALS['digitalogic_test_options']['digitalogic_pricing_state_event_receipts_v1']
		);

		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
			Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery( array( $this->source ) );
		}
		$this->assertCount( 4, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
	}

	/** Invoke one private snapshot helper for deterministic lifecycle setup. */
	private function invoke_snapshot( $method, $args = array() ) {
		$reflection = new ReflectionMethod( Digitalogic_Pricing_Snapshot::class, $method );

		return $reflection->invokeArgs( Digitalogic_Pricing_Snapshot::instance(), $args );
	}

	/** Return scheduled events for one exact hook. */
	private function scheduled_events_for( $hook ) {
		return array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static function ( $event ) use ( $hook ) {
					return (string) $event['hook'] === (string) $hook;
				}
			)
		);
	}

	/** Return only durable terminal snapshot events. */
	private function terminal_events() {
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] ?? array();

		return array_values(
			array_filter(
				is_array( $events ) ? $events : array(),
				static function ( $event ) {
					return 'pricing.snapshot.build.terminal' === (string) ( $event['name'] ?? '' );
				}
			)
		);
	}

	/** Return pending Action Scheduler records for one exact hook. */
	private function pending_action_scheduler_actions_for( $hook ) {
		return array_values(
			array_filter(
				$GLOBALS['digitalogic_test_action_scheduler_actions'],
				static function ( $action ) use ( $hook ) {
					return (string) $action['hook'] === (string) $hook
						&& 'pending' === (string) $action['status']
						&& empty( $action['claimed'] );
				}
			)
		);
	}

	/** Mark and return the oldest pending Action Scheduler record for one hook. */
	private function claim_action_scheduler_action( $hook ) {
		foreach ( $GLOBALS['digitalogic_test_action_scheduler_actions'] as $action_id => $action ) {
			if ( (string) $action['hook'] !== (string) $hook || 'pending' !== (string) $action['status'] ) {
				continue;
			}
			$GLOBALS['digitalogic_test_action_scheduler_actions'][ $action_id ]['status']  = 'running';
			$GLOBALS['digitalogic_test_action_scheduler_actions'][ $action_id ]['claimed'] = true;

			$GLOBALS['digitalogic_test_scheduled_events'] = array_values(
				array_filter(
					$GLOBALS['digitalogic_test_scheduled_events'],
					static function ( $event ) use ( $action_id ) {
						return (int) ( $event['action_id'] ?? 0 ) !== (int) $action_id;
					}
				)
			);

			$action['status']  = 'running';
			$action['claimed'] = true;

			return $action;
		}

		$this->fail( 'No pending Action Scheduler record exists for ' . $hook );
	}

	/** Set one request-local snapshot guard for cross-request scheduling tests. */
	private function set_snapshot_property( $name, $value ) {
		$property = new ReflectionProperty( Digitalogic_Pricing_Snapshot::class, $name );
		$property->setValue( Digitalogic_Pricing_Snapshot::instance(), $value );
	}

	/** Reset one singleton between tests. */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
