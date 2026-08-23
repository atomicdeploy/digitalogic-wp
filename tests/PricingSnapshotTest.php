<?php
/**
 * Immutable pricing snapshot contract tests.
 *
 * @package Digitalogic
 */

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
			'digitalogic_report_cache_generation_v1' => 'snapshot-test-generation',
		);
		$GLOBALS['digitalogic_test_option_cache']           = array();
		$GLOBALS['digitalogic_test_update_failures']        = array();
		$GLOBALS['digitalogic_test_transaction_failures']   = array();
		$GLOBALS['digitalogic_test_transients']             = array();
		$GLOBALS['digitalogic_test_transient_deletes']      = array();
		$GLOBALS['digitalogic_test_transient_set_callback'] = null;
		$GLOBALS['digitalogic_test_scheduled_events']       = array();
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

	/** Revision GET/HEAD/304 is stable, cheap, scoped, and does not create a secret. */
	public function test_revision_surface_is_cheap_conditional_and_read_only(): void {
		$options_before = $GLOBALS['digitalogic_test_options'];
		$this->assertTrue( Digitalogic_Pricing_Snapshot::instance()->authorize( $this->query_request( 'GET' ) ) );
		$response = $this->revision_response();
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( Digitalogic_Pricing_Snapshot::REVISION_SCHEMA, $data['schema'] );
		$this->assertSame( 'excel-v1', $data['projection'] );
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
		$this->assertSame( $this->source, $data['source'] );
		$this->assertSame( '"' . $data['state_revision'] . '"', $data['etag'] );
		$this->assertSame( $this->revision_response()->get_data()['state_revision'], $data['state_revision'] );
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

	/** A committed invalidation remains durable until the panel queue accepts it. */
	public function test_projection_event_outbox_retries_failed_queue_write_without_losing_or_duplicating_revision(): void {
		$snapshot = Digitalogic_Pricing_Snapshot::instance();
		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_scheduled_events'] );

		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		$snapshot->publish_scheduled_state_revision_events();
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$snapshot->run_state_revision_event_delivery();
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
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
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_pricing_state_event_outbox_v1';

		$this->assertTrue( Digitalogic_Report_Engine::instance()->invalidate_cache() );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
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
		$this->assertArrayNotHasKey( 'digitalogic_pricing_state_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
	}

	/** Store exactly one non-recurring next boundary and replace it when dates move. */
	public function test_freshness_boundary_is_one_shot_and_rescheduled_when_effective_date_changes(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_settings'] = array(
			'stale_after_hours' => 1000,
		);
		$snapshot = Digitalogic_Pricing_Snapshot::instance();

		$this->assertTrue( $snapshot->install_freshness_boundary_schedule() );
		$initial = $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary_v1' );
		$this->assertCount( 1, $initial );
		$this->assertSame( '', $initial[0]['recurrence'] );
		$this->assertCount( 2, $initial[0]['args'] );
		$this->assertGreaterThan( time(), $initial[0]['timestamp'] );

		$this->assertTrue( $snapshot->install_freshness_boundary_schedule() );
		$this->assertCount( 1, $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary_v1' ) );

		$tomorrow = gmdate( 'ymd', strtotime( '+1 day' ) );
		$this->assertTrue( update_option( 'options_update_date', $tomorrow, false ) );
		$this->assertTrue( update_option( 'update_date', $tomorrow, false ) );
		$rescheduled = $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary_v1' );
		$this->assertCount( 1, $rescheduled );
		$this->assertSame( '', $rescheduled[0]['recurrence'] );
		$this->assertLessThan( $initial[0]['timestamp'], $rescheduled[0]['timestamp'] );

		$snapshot->deactivate_freshness_boundary_schedule();
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary_v1' ) );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_freshness_boundary_schedule_v1', $GLOBALS['digitalogic_test_options'] );
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
		$GLOBALS['digitalogic_test_options']['digitalogic_pricing_freshness_boundary_schedule_v1'] = array(
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
		$this->assertArrayNotHasKey( 'digitalogic_pricing_freshness_boundary_schedule_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 0, $this->scheduled_events_for( 'digitalogic_pricing_freshness_boundary_v1' ) );

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
		$this->assertArrayHasKey( 'digitalogic_pricing_source_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
		$snapshot->publish_scheduled_state_revision_events();
		$this->assertArrayNotHasKey( 'digitalogic_panel_events', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayHasKey( 'digitalogic_pricing_source_event_outbox_v1', $GLOBALS['digitalogic_test_options'] );

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

		$this->assertTrue( delete_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION ) );
		$snapshot->publish_scheduled_state_revision_events();
		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 3, $events );
		$this->assertSame( 'pricing.source.removed', $events[2]['name'] );
		$this->assertSame( 'removed', $events[2]['data']['change'] );
		$this->assertSame( $changed_source['revision'], $events[2]['data']['previous_source_revision'] );
		$this->assertSame( $changed_source, $events[2]['data']['source'] );
		$this->assertTrue( Digitalogic_Event_Mesh::event_visible_to( $events[2], 0, '', 'patris_pricing', $changed_source ) );

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
		$this->assertCount( 46, $payload['catalog']['columns'] );
		$this->assertSame( $this->excel_v1_keys(), array_column( $payload['catalog']['columns'], 'key' ) );
		$this->assertSame( $this->excel_v1_keys(), array_keys( $payload['catalog']['rows'][0] ) );
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

		do_action( 'digitalogic_excel_pricing_apply_committed', array( 'status' => 'applied' ) );
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

	/** Return the immutable ordered excel-v1 projection contract. */
	private function excel_v1_keys(): array {
		return array(
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
		);
	}

	/** Return one revision response. */
	private function revision_response( $headers = array(), $method = 'GET' ) {
		return Digitalogic_REST_API::instance()->pricing_sync_revision( $this->query_request( $method, array(), $headers ) );
	}

	/** Start one exact revision-bound build. */
	private function start_response( $request_id, $state_revision, $max_age ) {
		$payload = array(
			'schema'                  => Digitalogic_Pricing_Snapshot::REQUEST_SCHEMA,
			'schema_version'          => 1,
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
					'schema_version'  => 1,
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

	/** Reset one singleton between tests. */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
