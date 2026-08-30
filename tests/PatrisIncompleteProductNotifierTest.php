<?php
/**
 * Tests for durable incomplete-product operator alerts.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify transition, routing, durability, and failure isolation contracts. */
final class PatrisIncompleteProductNotifierTest extends TestCase {

	/**
	 * Notifier under test.
	 *
	 * @var Digitalogic_Patris_Incomplete_Product_Notifier
	 */
	private $service;

	/**
	 * Canonical events observed by the fake private adapter.
	 *
	 * @var array
	 */
	private $adapter_events = array();

	/**
	 * Optional fake adapter result.
	 *
	 * @var mixed
	 */
	private $adapter_result = null;

	/**
	 * Inject one receipt-store interruption after provider success.
	 *
	 * @var bool
	 */
	private $fail_completion_once = false;

	/** Prepare isolated stores, hooks, and one fake private Telegram adapter. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']          = array();
		$GLOBALS['digitalogic_test_option_cache']     = array();
		$GLOBALS['digitalogic_test_filters']          = array();
		$GLOBALS['digitalogic_test_actions']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['digitalogic_test_update_failures']  = array();
		$GLOBALS['digitalogic_test_cache_deletes']    = array();
		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$GLOBALS['digitalogic_test_schedule_failure'] = false;
		$GLOBALS['wpdb']                              = new Digitalogic_Test_WPDB();
		$this->adapter_events                         = array();
		$this->adapter_result                         = null;
		$this->fail_completion_once                   = false;

		$test_case = $this;
		add_filter(
			Digitalogic_Patris_Incomplete_Product_Notifier::ADAPTER_FILTER,
			static function () use ( $test_case ) {
				return static function ( array $event ) use ( $test_case ) {
					return $test_case->fake_adapter_delivery( $event );
				};
			}
		);

		$this->reset_singleton( Digitalogic_Patris_Incomplete_Product_Notifier::class );
		$this->service = Digitalogic_Patris_Incomplete_Product_Notifier::instance();
	}

	/** The concrete LM2576 fixture produces a safe Persian Telegram receipt. */
	public function test_lm2576_warning_is_durable_persian_targeted_and_secret_free(): void {
		$snapshot                   = $this->snapshot( array( 'weight', 'stock', 'markup', 'price', 'freight', 'price' ) );
		$snapshot['customer_phone'] = 'must-not-leak';
		$snapshot['secret']         = 'must-not-leak-either';

		$this->commit_snapshot( $snapshot );

		$store = $this->store();
		$this->assertCount( 1, $store['outbox'] );
		$this->assertCount( 1, $store['products'] );
		$this->assertCount( 0, $store['receipts'] );
		$entry = reset( $store['outbox'] );
		$event = $entry['event'];

		$this->assertSame( 'digitalogic.alert-event', $event['schema'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $event['event_id'] );
		$this->assertSame( $event['event_id'], $event['idempotency_key'] );
		$this->assertSame( 'catalog.product_incomplete', $event['event_type'] );
		$this->assertSame( 'catalog', $event['category'] );
		$this->assertSame( 'digitalogic-patris-materializer', $event['source'] );
		$this->assertSame( 'digitalogic-patris-materializer', $event['source_id'] );
		$this->assertSame( 'catalog.product:101001001', $event['resource'] );
		$this->assertSame( 'catalog.product_incomplete', $event['condition'] );
		$this->assertSame( 'active', $event['state'] );
		$this->assertSame( 'warning', $event['severity'] );
		$this->assertSame( $event['message'], $event['summary'] );
		$this->assertSame( $event['observed_at'], $event['created_at'] );
		$this->assertArrayNotHasKey( 'schema_version', $event );
		$this->assertSame( array( 'freight', 'markup', 'price', 'stock', 'weight' ), $event['missing_fields'] );
		$this->assertSame( array( 'telegram' ), $event['notify_channels'] );
		$this->assertArrayNotHasKey( 'channels', $event );
		$this->assertSame( array( 'shokri' ), $event['audience']['operators'] );
		$this->assertSame( 101, $event['product_id'] );
		$this->assertSame( '101001001', $event['product_code'] );
		$this->assertSame( '101001001', $event['sku'] );
		$this->assertSame( 'LM2576S-ADJ', $event['name'] );
		$this->assertSame( '101001001', $event['object']['product_code'] );
		$this->assertSame( '101001001', $event['object']['sku'] );
		$this->assertSame( 'LM2576S-ADJ', $event['object']['name'] );
		$this->assertTrue( $event['object']['visible'] );
		$this->assertFalse( $event['object']['purchasable'] );
		$this->assertSame( 'canonical_missing_unpriced', $event['object']['price_status'] );
		$this->assertStringContainsString( 'اطلاعات ناقص', $event['message'] );
		$this->assertStringContainsString( 'قیمت معتبر', $event['message'] );
		$this->assertStringContainsString( 'موجودی واقعی', $event['message'] );
		$this->assertStringContainsString( 'وزن', $event['message'] );
		$this->assertStringContainsString( 'کرایه حمل', $event['message'] );
		$this->assertStringContainsString( 'درصد سود', $event['message'] );
		$this->assertStringContainsString( 'قابل مشاهده است، اما قابل خرید نیست و قیمت ندارد', $event['message'] );
		$this->assertStringContainsString( 'Patris', $event['requested_action'] );
		$this->assertStringNotContainsString( 'قیمت: 0', $event['message'] );
		$this->assertStringNotContainsString( '0 تومان', $event['message'] );
		$this->assertStringNotContainsString( 'must-not-leak', wp_json_encode( $store ) );
		$this->assertCount( 1, $this->scheduled_alert_workers() );
		$this->assertCount( 0, $this->adapter_events );

		$this->service->run_delivery_worker();

		$store = $this->store();
		$this->assertCount( 0, $store['outbox'] );
		$this->assertCount( 1, $store['receipts'] );
		$receipt  = reset( $store['receipts'] );
		$telegram = $receipt['channel_receipts']['telegram'];
		$this->assertSame( 'delivered', $telegram['status'] );
		$this->assertSame( 'telegram', $telegram['channel'] );
		$this->assertSame( 'shokri', $telegram['audience'] );
		$this->assertSame( $event['event_id'], $telegram['event_id'] );
		$this->assertSame( $event['event_id'], $telegram['idempotency_key'] );
		$this->assertTrue( $telegram['delivery_confirmed'] );
		$this->assertSame( 'telegram', $telegram['provider_receipt']['provider'] );
		$this->assertNotSame( '', $telegram['provider_receipt']['message_id'] );
		$this->assertSame( '200758', $telegram['n8n_execution_id'] );
		$this->assertSame( 200, $telegram['n8n_status_code'] );
		$this->assertCount( 1, $this->adapter_events );
		$this->assertSame( $event, $this->adapter_events[0] );
		$this->assertStringNotContainsString( 'must-not-leak', wp_json_encode( $store ) );
		$this->assertStringNotContainsString( 'synthetic-private-chat', wp_json_encode( $store ) );
	}

	/** Only first/increased missing sets and exact recovery reach the adapter. */
	public function test_only_new_or_increased_incompleteness_and_full_recovery_notify(): void {
		$initial = $this->snapshot( array( 'price', 'stock' ) );
		$this->capture_and_deliver( $initial );
		$this->assertCount( 1, $this->adapter_events );

		// An unchanged snapshot and representation-only source revision do not spam.
		do_action( Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK, $initial );
		$next_revision              = 'sha256:' . str_repeat( 'b', 64 );
		$initial['source_revision'] = $next_revision;
		do_action( Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK, $initial );
		$this->service->flush_captured_snapshots();
		$this->service->run_delivery_worker();
		$this->assertCount( 1, $this->adapter_events );

		// Partial improvement updates state but does not emit a transition.
		$partial                   = $initial;
		$partial['missing_fields'] = array( 'price' );
		$this->commit_snapshot( $partial );
		$this->service->run_delivery_worker();
		$this->assertCount( 1, $this->adapter_events );

		// A newly missing field is an increased-incompleteness transition.
		$increased                   = $partial;
		$increased['missing_fields'] = array( 'weight', 'price' );
		$this->capture_and_deliver( $increased );
		$this->assertCount( 2, $this->adapter_events );
		$warning = $this->adapter_events[1];
		$this->assertSame( 'increased', $warning['transition'] );
		$this->assertSame( array( 'weight' ), $warning['added_missing_fields'] );

		// The empty set after incompleteness emits exactly one recovery.
		$complete                   = $increased;
		$complete['missing_fields'] = array();
		$complete['purchasable']    = true;
		$complete['price_status']   = 'canonical_priced';
		$this->capture_and_deliver( $complete );
		$this->assertCount( 3, $this->adapter_events );
		$recovery = $this->adapter_events[2];
		$this->assertSame( 'recovered', $recovery['transition'] );
		$this->assertSame( 'resolved', $recovery['status'] );
		$this->assertSame( array( 'price', 'weight' ), $recovery['resolved_fields'] );
		$this->assertStringContainsString( 'رفع نقص', $recovery['title'] );
		$this->assertStringContainsString( 'موارد برطرف‌شده', $recovery['message'] );

		$this->commit_snapshot( $complete );
		$this->service->run_delivery_worker();
		$this->assertCount( 3, $this->adapter_events );
		$this->assertCount( 3, $this->store()['receipts'] );
	}

	/** Same-revision cycles keep unique identities and causal delivery order. */
	public function test_batched_same_revision_cycles_are_unique_and_delivered_in_order(): void {
		$missing                    = $this->snapshot( array( 'price' ) );
		$complete                   = $missing;
		$complete['missing_fields'] = array();
		$complete['purchasable']    = true;
		$complete['price_status']   = 'canonical_priced';

		foreach ( array( $missing, $complete, $missing, $complete, $missing ) as $snapshot ) {
			do_action( Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK, $snapshot );
		}
		$this->assertArrayNotHasKey( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertTrue( $this->service->flush_captured_snapshots() );
		$this->assertCount( 5, $this->store()['outbox'] );

		$this->make_outbox_due();
		$this->service->run_delivery_worker();
		$this->assertSame(
			array( 'incomplete', 'recovered', 'increased', 'recovered', 'increased' ),
			array_column( $this->adapter_events, 'transition' )
		);
		$this->assertSame( array( 1, 2, 3, 4, 5 ), array_column( $this->adapter_events, 'transition_sequence' ) );
		$this->assertCount( 5, array_unique( array_column( $this->adapter_events, 'event_id' ) ) );
		$this->assertCount( 0, $this->store()['outbox'] );
		$this->assertCount( 5, $this->store()['receipts'] );
	}

	/** A 757-row materializer pass is flushed once within a bounded store size. */
	public function test_large_materializer_capture_is_batched_and_bounded(): void {
		for ( $index = 1; $index <= 757; ++$index ) {
			$snapshot                 = $this->snapshot( array( 'price', 'stock' ) );
			$snapshot['product_id']   = 1000 + $index;
			$snapshot['product_code'] = sprintf( 'B%09d', $index );
			$snapshot['name']         = 'Synthetic product ' . $index;
			do_action( Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK, $snapshot );
		}

		$this->assertArrayNotHasKey( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertTrue( $this->service->flush_captured_snapshots() );
		$store = $this->store();
		$this->assertCount( 757, $store['products'] );
		$this->assertCount( 757, $store['outbox'] );
		$this->assertLessThan( 4 * 1024 * 1024, strlen( wp_json_encode( $store ) ) );
		$this->assertCount( 1, $this->scheduled_alert_workers() );
		$this->assertCount( 0, $this->adapter_events );
	}

	/** A later bounded repair pass recovers a hook/process gap from canonical meta. */
	public function test_repair_worker_recovers_only_identity_safe_committed_product_metadata(): void {
		$canonical_meta                         = array(
			'_sku'                                   => '101001001',
			'_stock_status'                          => 'outofstock',
			'_digitalogic_patris_source_revision'    => 'sha256:' . str_repeat( 'a', 64 ),
			'_digitalogic_patris_materialization_missing_fields' => wp_json_encode( array( 'price', 'stock', 'weight' ) ),
			'_digitalogic_patris_owner_source_id'    => 'patris-export',
			'_digitalogic_patris_owner_dataset'      => 'ALLANBAR',
			'_digitalogic_patris_owner_product_code' => '101001001',
			'_digitalogic_patris_product_code'       => '101001001',
			'_digitalogic_patris_price_status'       => 'canonical_missing_unpriced',
		);
		$GLOBALS['digitalogic_test_posts'][202] = array(
			'post_type'          => 'product',
			'post_status'        => 'publish',
			'post_title'         => 'LM2576S-ADJ',
			'catalog_visibility' => 'visible',
			'meta'               => $canonical_meta,
		);
		$unsafe_meta                            = $canonical_meta;
		$unsafe_meta['_sku']                    = 'DIFFERENT-SKU';
		$GLOBALS['digitalogic_test_posts'][203] = array(
			'post_type'          => 'product',
			'post_status'        => 'publish',
			'post_title'         => 'Wrong identity',
			'catalog_visibility' => 'visible',
			'meta'               => $unsafe_meta,
		);
		unset( $GLOBALS['digitalogic_test_wc_products'][202], $GLOBALS['digitalogic_test_wc_products'][203] );
		add_filter(
			Digitalogic_Patris_Incomplete_Product_Notifier::REPAIR_IDS_FILTER,
			static function () {
				return array( 203, 202 );
			},
			10,
			3
		);

		// Simulate a completed materializer write whose request ended before its hook.
		$this->service->run_repair_worker();

		$store = $this->store();
		$this->assertCount( 1, $store['products'] );
		$this->assertCount( 1, $store['outbox'] );
		$this->assertSame( 1, $store['repair_page'] );
		$entry = reset( $store['outbox'] );
		$this->assertSame( 202, $entry['event']['product_id'] );
		$this->assertSame( '101001001', $entry['event']['product_code'] );
		$this->assertSame( array( 'price', 'stock', 'weight' ), $entry['event']['missing_fields'] );
		$this->assertSame( 'محصول قابل مشاهده است، اما قابل خرید نیست و قیمت ندارد.', $entry['event']['impact'] );
		$this->assertCount( 1, $this->scheduled_repair_workers() );
		$this->assertCount( 0, $this->adapter_events );

		$this->make_outbox_due();
		$this->service->run_delivery_worker();
		$this->assertCount( 1, $this->adapter_events );
		$this->assertCount( 1, $this->store()['receipts'] );
	}

	/** Pending and malformed route results cannot become provider receipts. */
	public function test_only_exact_successful_telegram_provider_receipt_completes_delivery(): void {
		$this->commit_snapshot( $this->snapshot( array( 'price' ) ) );
		$this->adapter_result = array( 'status' => 'pending' );
		$this->make_outbox_due();
		$this->service->run_delivery_worker();
		$entry = reset( $this->store()['outbox'] );
		$this->assertSame( 'digitalogic_patris_incomplete_alert_route_pending', $entry['last_error'] );
		$this->assertCount( 0, $this->store()['receipts'] );

		$this->adapter_result = array(
			'status'              => 'delivered',
			'channel'             => 'telegram',
			'audience'            => 'another-operator',
			'event_id'            => $entry['event']['event_id'],
			'idempotency_key'     => $entry['event']['event_id'],
			'provider_message_id' => 'synthetic-message',
			'delivered_at'        => '2026-08-30T10:00:00Z',
			'provider'            => 'telegram',
		);
		$this->make_outbox_due();
		$this->service->run_delivery_worker();
		$entry = reset( $this->store()['outbox'] );
		$this->assertSame( 'digitalogic_patris_incomplete_alert_receipt_invalid', $entry['last_error'] );
		$this->assertCount( 0, $this->store()['receipts'] );

		$this->adapter_result = null;
		$this->make_outbox_due();
		$this->service->run_delivery_worker();
		$this->assertCount( 0, $this->store()['outbox'] );
		$this->assertCount( 1, $this->store()['receipts'] );
	}

	/** A receipt-store interruption replays only the exact idempotent event. */
	public function test_receipt_store_interruption_replays_the_same_event_identity(): void {
		$this->fail_completion_once = true;
		$this->commit_snapshot( $this->snapshot( array( 'price', 'stock' ) ) );
		$this->make_outbox_due();
		$this->service->run_delivery_worker();

		$store = $this->store();
		$this->assertCount( 1, $store['outbox'] );
		$this->assertCount( 0, $store['receipts'] );
		$this->assertCount( 1, $this->adapter_events );
		$event_id = $this->adapter_events[0]['event_id'];

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->make_outbox_due();
		$this->service->run_delivery_worker();

		$this->assertCount( 2, $this->adapter_events );
		$this->assertSame( $event_id, $this->adapter_events[1]['event_id'] );
		$this->assertSame( $event_id, $this->adapter_events[1]['idempotency_key'] );
		$this->assertCount( 0, $this->store()['outbox'] );
		$this->assertCount( 1, $this->store()['receipts'] );
	}

	/** Delivery failures retry only three times and never discard the outbox. */
	public function test_delivery_failure_is_bounded_and_never_discards_the_outbox(): void {
		$this->adapter_result       = new WP_Error( 'digitalogic_private_route_failed', 'Synthetic route failure.' );
		$warning                    = $this->snapshot( array( 'price', 'stock', 'weight', 'freight', 'markup' ) );
		$complete                   = $warning;
		$complete['missing_fields'] = array();
		$complete['purchasable']    = true;
		$complete['price_status']   = 'canonical_priced';
		$this->commit_snapshot( $warning );
		$this->commit_snapshot( $complete );

		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$this->make_outbox_due();
			$this->service->run_delivery_worker();
			$outbox = $this->store()['outbox'];
			$entry  = reset( $outbox );
			$this->assertSame( $attempt, $entry['attempts'] );
		}

		$store = $this->store();
		$this->assertCount( 2, $store['outbox'] );
		$this->assertCount( 0, $store['receipts'] );
		$entries = array_values( $store['outbox'] );
		$this->assertSame( 'exhausted', $entries[0]['status'] );
		$this->assertSame( 3, $entries[0]['attempts'] );
		$this->assertSame( 'digitalogic_private_route_failed', $entries[0]['last_error'] );
		$this->assertSame( 0, $entries[0]['next_attempt_at'] );
		$this->assertSame( 0, $entries[1]['attempts'] );

		$this->service->run_delivery_worker();
		$after_outbox = $this->store()['outbox'];
		$after        = reset( $after_outbox );
		$this->assertSame( 3, $after['attempts'] );
		$this->assertCount( 3, $this->adapter_events );
	}

	/** Invalid snapshots and storage failures never escape the post-commit hook. */
	public function test_invalid_or_unpersistable_alert_never_escapes_the_commit_hook(): void {
		$invalid                   = $this->snapshot( array( 'price' ) );
		$invalid['missing_fields'] = array( 'price', 'customer_phone' );

		do_action( Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK, $invalid );
		$this->assertArrayNotHasKey( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, $GLOBALS['digitalogic_test_options'] );

		$GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION;
		do_action(
			Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK,
			$this->snapshot( array( 'price' ) )
		);
		$this->service->flush_captured_snapshots();
		$this->assertArrayNotHasKey( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_patris_incomplete_product_alert_failed'] );
	}

	/**
	 * Build the concrete regression snapshot.
	 *
	 * @param array $missing_fields Missing-field keys.
	 * @return array
	 */
	private function snapshot( array $missing_fields ): array {
		return array(
			'product_id'      => 101,
			'product_code'    => '101001001',
			'name'            => 'LM2576S-ADJ',
			'source_id'       => 'patris-export',
			'dataset'         => 'ALLANBAR',
			'source_revision' => 'sha256:' . str_repeat( 'a', 64 ),
			'missing_fields'  => $missing_fields,
			'visible'         => true,
			'purchasable'     => false,
			'price_status'    => 'canonical_missing_unpriced',
		);
	}

	/**
	 * Capture and synchronously run the fake delivery worker.
	 *
	 * @param array $snapshot Committed snapshot.
	 * @return void
	 */
	private function capture_and_deliver( array $snapshot ): void {
		$this->commit_snapshot( $snapshot );
		$this->make_outbox_due();
		$this->service->run_delivery_worker();
	}

	/**
	 * Capture one snapshot and flush the request-local batch.
	 *
	 * @param array $snapshot Committed snapshot.
	 * @return void
	 */
	private function commit_snapshot( array $snapshot ): void {
		do_action( Digitalogic_Patris_Incomplete_Product_Notifier::COMMITTED_HOOK, $snapshot );
		$this->service->flush_captured_snapshots();
	}

	/** Make every non-exhausted outbox entry due now. */
	private function make_outbox_due(): void {
		$store = $this->store();
		foreach ( $store['outbox'] as &$entry ) {
			if ( 'exhausted' !== (string) ( $entry['status'] ?? '' ) ) {
				$entry['status']          = 'pending';
				$entry['next_attempt_at'] = 0;
				$entry['lease_token']     = '';
				$entry['lease_until']     = 0;
			}
		}
		unset( $entry );
		update_option( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, $store, false );
		wp_cache_delete( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, 'options' );
	}

	/** Return the durable notifier store without an option-cache artifact. */
	private function store(): array {
		wp_cache_delete( Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION, 'options' );

		return get_option(
			Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION,
			array(
				'products' => array(),
				'outbox'   => array(),
				'receipts' => array(),
			)
		);
	}

	/** Return only scheduled alert-delivery workers. */
	private function scheduled_alert_workers(): array {
		return array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static function ( $event ) {
					return Digitalogic_Patris_Incomplete_Product_Notifier::WORKER_HOOK === $event['hook'];
				}
			)
		);
	}

	/** Return only scheduled missed-snapshot repair workers. */
	private function scheduled_repair_workers(): array {
		return array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static function ( $event ) {
					return Digitalogic_Patris_Incomplete_Product_Notifier::REPAIR_HOOK === $event['hook'];
				}
			)
		);
	}

	/**
	 * Reset one singleton between tests.
	 *
	 * @param string $class_name Singleton class name.
	 * @return void
	 */
	private function reset_singleton( string $class_name ): void {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}

	/**
	 * Simulate the approved private route without any external side effect.
	 *
	 * @param array $event Canonical AlertEvent.
	 * @return array|WP_Error
	 */
	private function fake_adapter_delivery( array $event ) {
		$this->adapter_events[] = $event;
		if ( null !== $this->adapter_result ) {
			return $this->adapter_result;
		}
		if ( $this->fail_completion_once ) {
			$this->fail_completion_once                    = false;
			$GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Patris_Incomplete_Product_Notifier::STORE_OPTION;
		}

		return array(
			'status'           => 'delivered',
			'channel'          => 'telegram',
			'audience'         => 'shokri',
			'event_id'         => $event['event_id'],
			'idempotency_key'  => $event['event_id'],
			'route'            => 'private-operator-notification',
			'relay_id'         => 'relay-synthetic-101001001',
			'n8n_execution_id' => '200758',
			'n8n_status_code'  => 200,
			'provider_receipt' => array(
				'provider'     => 'telegram',
				'message_id'   => 'synthetic-message-' . substr( $event['event_id'], -12 ),
				'delivered_at' => '2026-08-30T10:00:00Z',
			),
			'chat_id'          => 'synthetic-private-chat',
			'customer_phone'   => 'must-not-leak',
		);
	}
}
