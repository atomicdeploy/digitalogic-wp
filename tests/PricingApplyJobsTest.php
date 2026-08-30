<?php
/**
 * Durable provider-neutral pricing-apply job tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Digitalogic_Pricing_Apply_Jobs' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-digitalogic-pricing-apply-jobs.php';
}

/** Deterministic receipt-backed service double. */
final class Digitalogic_Pricing_Apply_Jobs_Harness extends Digitalogic_Pricing_Apply_Jobs {

	public $clock = 1700000000;
	public $canonical;
	public $scope;
	public $original_settings;
	public $desired_revision;
	public $original_revision;
	public $original_catalog_revision;
	public $desired_catalog_revision;
	public $settings_invocations = array();
	public $settings_effects = 0;
	public $batch_invocations = array();
	public $batch_effects = 0;
	public $publish_invocations = array();
	public $publish_effects = 0;
	public $published_snapshots = array();
	public $worker_schedules = array();
	public $watchdog_schedules = array();
	public $unscheduled = array();
	public $worker_schedule_ok = true;
	public $watchdog_schedule_ok = true;
	public $publish_failures_remaining = 0;
	public $lock_probe = false;
	public $live_reads_fail = false;

	private $settings_receipts = array();
	private $batch_receipts = array();
	private $publish_receipts = array();
	private $job_sequence = 0;

	protected function prepare_settings( $settings ) {
		return $settings;
	}

	protected function read_canonical_state() {
		return $this->live_reads_fail
			? new WP_Error( 'test_live_read_forbidden', 'Live canonical read was not expected.', array( 'status' => 500 ) )
			: $this->canonical;
	}

	protected function read_pricing_scope() {
		return $this->live_reads_fail
			? new WP_Error( 'test_live_read_forbidden', 'Live scope read was not expected.', array( 'status' => 500 ) )
			: $this->scope;
	}

	protected function commit_settings_phase( $settings, $source, $expected_revision, $operation_id, $request_context, $source_context = array() ) {
		$this->settings_invocations[] = $operation_id;
		if ( isset( $this->settings_receipts[ $operation_id ] ) ) {
			return array_merge( $this->settings_receipts[ $operation_id ], array( 'replayed' => true ) );
		}
		if ( ! hash_equals( (string) $expected_revision, (string) $this->canonical['state_revision'] ) ) {
			return new WP_Error( 'test_settings_revision_conflict', 'Settings revision changed.', array( 'status' => 409 ) );
		}
		$previous       = $this->canonical;
		$restoring      = hash_equals( $this->json( $settings ), $this->json( $this->original_settings ) );
		$next_revision  = $restoring ? $this->original_revision : $this->desired_revision;
		$next_catalog   = $restoring ? $this->original_catalog_revision : $this->desired_catalog_revision;
		$this->canonical = array(
			'state_revision' => $next_revision,
			'settings'       => $settings,
			'shipping'       => array( 'catalog_revision' => $next_catalog ),
		);
		++$this->settings_effects;
		$receipt = array(
			'schema'                    => 'digitalogic.pricing-settings-phase',
			'status'                    => hash_equals( (string) $previous['state_revision'], $next_revision ) ? 'already_current' : 'committed',
			'operation_id'              => $operation_id,
			'previous_state_revision'   => $previous['state_revision'],
			'state_revision'            => $next_revision,
			'previous_catalog_revision' => $previous['shipping']['catalog_revision'],
			'catalog_revision'          => $next_catalog,
			'previous'                  => $previous,
			'readback'                  => $this->canonical,
			'settings'                  => $settings,
			'settings_changed'          => ! hash_equals( (string) $previous['state_revision'], $next_revision ),
			'source'                    => $source,
			'source_context'            => $source_context,
			'request_context'           => $request_context,
			'replayed'                  => false,
		);
		$this->settings_receipts[ $operation_id ] = $receipt;

		return $receipt;
	}

	protected function reprice_batch( $settings, $codes, $previous_catalog_revision, $expected_scope_revision, $expected_code_digest, $operation_id ) {
		$this->batch_invocations[] = array(
			'operation_id'              => $operation_id,
			'codes'                     => $codes,
			'settings'                  => $settings,
			'previous_catalog_revision' => $previous_catalog_revision,
		);
		if ( isset( $this->batch_receipts[ $operation_id ] ) ) {
			return array_merge( $this->batch_receipts[ $operation_id ], array( 'replayed' => true ) );
		}
		if (
			! hash_equals( (string) $expected_scope_revision, (string) $this->scope['state_revision'] )
			|| ! hash_equals( (string) $expected_code_digest, (string) $this->scope['code_digest'] )
		) {
			return new WP_Error( 'test_scope_revision_conflict', 'Pricing scope changed.', array( 'status' => 409 ) );
		}
		++$this->batch_effects;
		$next_revision                = 'sha256:' . hash( 'sha256', $operation_id );
		$this->scope['state_revision'] = $next_revision;
		$count                        = count( $codes );
		$receipt                      = array(
			'schema'            => 'digitalogic.pricing-batch-receipt',
			'operation_id'      => $operation_id,
			'receiver_revision' => $next_revision,
			'row_count'         => $count,
			'pricing_results'   => array(
				'changed_products'         => $count,
				'updated_products'         => $count,
				'already_current_products' => 0,
				'deferred_missing'         => 0,
				'deferred_ambiguous'       => 0,
				'pending_products'         => 0,
				'warning_count'            => 0,
				'warnings'                 => array(),
				'sources'                  => array(
					array(
						'source'          => array( 'id' => 'provider-a', 'dataset' => 'prices', 'revision' => self::revision( '9' ) ),
						'target_products' => $count,
						'woocommerce'     => array(
							'attempted'       => $count,
							'updated'         => $count,
							'already_applied' => 0,
							'missing'         => 0,
							'ambiguous'       => 0,
							'failed'          => 0,
							'errors'          => array(),
						),
					),
				),
			),
			'cache_plan'        => array( 'products' => $codes ),
			'replayed'          => false,
		);
		$this->batch_receipts[ $operation_id ] = $receipt;

		return $receipt;
	}

	protected function publish_outbox( $job ) {
		$event_id = (string) $job['outbox']['event_id'];
		$this->publish_invocations[] = $event_id;
		$this->published_snapshots[] = $job;
		if ( isset( $this->publish_receipts[ $event_id ] ) ) {
			return array_merge( $this->publish_receipts[ $event_id ], array( 'replayed' => true ) );
		}
		if ( $this->publish_failures_remaining > 0 ) {
			--$this->publish_failures_remaining;

			return new WP_Error( 'test_publish_busy', 'Terminal event store is temporarily unavailable.', array( 'status' => 503, 'retryable' => true ) );
		}
		++$this->publish_effects;
		$status = (string) $job['pending_terminal']['status'];
		$result = 'completed' === $status
			? array(
				'schema'         => 'digitalogic.pricing-sync-apply',
				'status'         => 'applied',
				'job_id'         => $job['job_id'],
				'state_revision' => $job['settings_phase']['state_revision'],
				'settings'       => $job['desired_settings'],
				'event_delivery' => array( 'durable' => true ),
			)
			: array(
				'schema'          => 'digitalogic.pricing-apply-terminal',
				'job_id'          => $job['job_id'],
				'status'          => $status,
				'terminal_reason' => $job['pending_terminal']['reason'],
				'event_delivery'  => array( 'durable' => true ),
			);
		$this->publish_receipts[ $event_id ] = $result;

		return $result;
	}

	protected function probe_pricing_locks() {
		return $this->lock_probe;
	}

	protected function schedule_worker( $job_id, $delay ) {
		$this->worker_schedules[] = array( $job_id, $delay );

		return $this->worker_schedule_ok;
	}

	protected function schedule_watchdog( $job_id, $delay ) {
		$this->watchdog_schedules[] = array( $job_id, $delay );

		return $this->watchdog_schedule_ok;
	}

	protected function unschedule_job( $job_id ) {
		$this->unscheduled[] = $job_id;
	}

	protected function now() {
		return $this->clock;
	}

	protected function new_job_id() {
		++$this->job_sequence;

		return 'job_' . str_pad( dechex( $this->job_sequence ), 32, '0', STR_PAD_LEFT );
	}

	public function advance( $seconds ) {
		$this->clock += (int) $seconds;
	}

	public function raw_job( $job_id ) {
		$registry = get_option( self::REGISTRY_OPTION, array() );

		return $registry['jobs'][ $job_id ];
	}

	public function mutate_job( $job_id, $callback ) {
		$registry                    = get_option( self::REGISTRY_OPTION, array() );
		$registry['jobs'][ $job_id ] = call_user_func( $callback, $registry['jobs'][ $job_id ] );
		update_option( self::REGISTRY_OPTION, $registry, false );
	}

	public function simulate_settings_effect( $job_id ) {
		$job = $this->raw_job( $job_id );

		return $this->commit_settings_phase(
			$job['desired_settings'],
			$job['operation_binding']['source'],
			$job['expected_state_revision'],
			'pricing-apply-settings:' . $job_id,
			$job['request_context'],
			$job['operation_metadata']['source_context']
		);
	}

	public function simulate_forward_batch_effect( $job_id ) {
		$job  = $this->raw_job( $job_id );
		$batch = array_slice( $job['codes'], (int) $job['forward_cursor'], 25 );

		return $this->reprice_batch(
			$job['desired_settings'],
			$batch,
			$job['settings_phase']['previous_catalog_revision'],
			$job['expected_scope_revision'],
			$job['source_pin']['code_digest'],
			'pricing-apply-forward:' . $job_id . ':' . intdiv( (int) $job['forward_cursor'], 25 )
		);
	}

	public static function revision( $character ) {
		return 'sha256:' . str_repeat( (string) $character, 64 );
	}

	private function json( $value ) {
		return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

/** Exercises admission, batching, receipts, recovery, and terminal delivery. */
final class PricingApplyJobsTest extends TestCase {

	private $jobs;
	private $source;
	private $binding;
	private $metadata;
	private $context;
	private $settings;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['digitalogic_test_options']          = array();
		$GLOBALS['digitalogic_test_option_cache']     = array();
		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$GLOBALS['digitalogic_test_actions']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['wpdb']                              = new Digitalogic_Test_WPDB();

		$this->source = array(
			'id'       => 'provider-a',
			'dataset'  => 'prices',
			'revision' => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '9' ),
		);
		$this->binding = array(
			'source'         => array_merge( $this->source, array( 'provider_hint' => 'ignored-additive-field' ) ),
			'preview_digest' => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '8' ),
			'confirmation'   => 'APPLY',
			'client_id'      => 'excel-companion',
			'channel'        => 'excel',
			'new_field'      => array( 'ignored' => true ),
		);
		$this->metadata = array(
			'source_context'     => array(
				'id'                       => $this->source['id'],
				'dataset'                  => $this->source['dataset'],
				'submitted_revision'       => $this->source['revision'],
				'current_revision'         => $this->source['revision'],
				'revision_matches_current' => true,
				'revision_capability'      => true,
				'provider_diagnostic'      => 'ignored',
			),
			'preview_expires_at' => 1700000300,
			'warnings'           => array(),
			'future_metadata'    => array( 'ignored' => true ),
		);
		$this->context = array(
			'client_id'  => 'excel-companion',
			'channel'    => 'excel',
			'request_id' => 'request-00000001',
		);
		$this->settings = array( 'yuan_price' => '31000', 'profit_margin_percent' => '30' );

		$this->jobs                            = new Digitalogic_Pricing_Apply_Jobs_Harness( false );
		$this->jobs->original_revision         = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '1' );
		$this->jobs->desired_revision          = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '2' );
		$this->jobs->original_catalog_revision = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '3' );
		$this->jobs->desired_catalog_revision  = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '4' );
		$this->jobs->original_settings          = array( 'yuan_price' => '30000', 'profit_margin_percent' => '30' );
		$this->jobs->canonical                  = array(
			'state_revision' => $this->jobs->original_revision,
			'settings'       => $this->jobs->original_settings,
			'shipping'       => array( 'catalog_revision' => $this->jobs->original_catalog_revision ),
		);
		$this->set_scope_size( 60 );
	}

	public function test_exact_replay_precedes_live_validation_and_does_not_create_aliases(): void {
		$status = $this->admit( 'request-00000001' );

		$this->assertTrue( $status['accepted'] );
		$this->assertMatchesRegularExpression( '/\Ajob_[a-f0-9]{32}\z/', $status['job_id'] );
		$this->assertSame( 'armed', $status['schedule_state'] );
		$this->assertStringContainsString( '?source_id=provider-a&source_dataset=prices', $status['status_url'] );
		$this->assertStringContainsString( '&source_revision=sha256%3A', $status['status_url'] );
		$this->assertSame( $status['status_url'], $status['cancel_url'] );
		$this->assertSame( 0, $this->jobs->settings_effects );
		$this->assertSame( 0, $this->jobs->batch_effects );

		$this->jobs->live_reads_fail = true;
		$replay = $this->jobs->replay(
			$this->settings,
			$this->jobs->original_revision,
			'request-00000001',
			array_merge( $this->binding, array( 'newer_optional_field' => 'tolerated' ) ),
			$this->context
		);
		$this->assertIsArray( $replay );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $status['job_id'], $replay['job_id'] );

		$conflict = $this->jobs->replay(
			array_merge( $this->settings, array( 'yuan_price' => '32000' ) ),
			$this->jobs->original_revision,
			'request-00000001',
			$this->binding,
			$this->context
		);
		$this->assertInstanceOf( WP_Error::class, $conflict );
		$this->assertSame( 'digitalogic_pricing_apply_request_id_conflict', $conflict->get_error_code() );

		$registry = get_option( Digitalogic_Pricing_Apply_Jobs::REGISTRY_OPTION, array() );
		$this->assertArrayHasKey( 'requests', $registry );
		$this->assertArrayNotHasKey( 'aliases', $registry );
	}

	public function test_runs_exact_twenty_five_code_batches_and_publishes_once(): void {
		$status = $this->admit( 'request-00000002' );
		$status = $this->finish( $status['job_id'] );

		$this->assertSame( 'completed', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertSame( array( 25, 25, 10 ), array_map( static fn( $call ) => count( $call['codes'] ), $this->jobs->batch_invocations ) );
		$this->assertSame(
			array(
				'pricing-apply-forward:' . $status['job_id'] . ':0',
				'pricing-apply-forward:' . $status['job_id'] . ':1',
				'pricing-apply-forward:' . $status['job_id'] . ':2',
			),
			array_column( $this->jobs->batch_invocations, 'operation_id' )
		);
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( 3, $this->jobs->batch_effects );
		$this->assertSame( 1, $this->jobs->publish_effects );
		$this->assertSame( 'applied', $status['result']['status'] );
		$this->assertSame( 'emitted', $status['event_delivery']['state'] );

		$snapshot = end( $this->jobs->published_snapshots );
		$this->assertSame( 'completed', $snapshot['pending_terminal']['status'] );
		$this->assertSame( count( $snapshot['codes'] ), $snapshot['forward_cursor'] );

		$before = array( $this->jobs->settings_effects, $this->jobs->batch_effects, $this->jobs->publish_effects );
		$this->jobs->run_job( $status['job_id'] );
		$this->assertSame( $before, array( $this->jobs->settings_effects, $this->jobs->batch_effects, $this->jobs->publish_effects ) );
	}

	public function test_source_scope_uses_only_id_and_dataset_and_pristine_cancel_is_published(): void {
		$status = $this->admit( 'request-00000003' );
		$found  = $this->jobs->get(
			$status['job_id'],
			array( 'id' => $this->source['id'], 'dataset' => $this->source['dataset'], 'revision' => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '7' ), 'extra' => true )
		);
		$this->assertIsArray( $found );

		$hidden = $this->jobs->get( $status['job_id'], array( 'id' => $this->source['id'], 'dataset' => 'another-dataset' ) );
		$this->assertInstanceOf( WP_Error::class, $hidden );
		$this->assertSame( 404, $hidden->get_error_data()['status'] );

		$cancelled = $this->jobs->cancel( $status['job_id'], array( 'id' => $this->source['id'], 'dataset' => $this->source['dataset'] ) );
		$this->assertSame( 'publishing', $cancelled['phase'] );
		$cancelled = $this->finish( $status['job_id'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 0, $this->jobs->settings_effects );
		$this->assertSame( 0, $this->jobs->batch_effects );
		$this->assertSame( 'cancelled', end( $this->jobs->published_snapshots )['pending_terminal']['status'] );
	}

	public function test_lost_ack_replays_settings_and_batch_receipts_without_reactuation(): void {
		$status = $this->admit( 'request-00000004' );
		$job_id = $status['job_id'];

		$this->assertIsArray( $this->jobs->simulate_settings_effect( $job_id ) );
		$this->mark_lost_claim( $job_id );
		$this->jobs->run_job( $job_id );
		$job = $this->jobs->raw_job( $job_id );
		$this->assertSame( 'repricing', $job['phase'] );
		$this->assertSame( 2, count( $this->jobs->settings_invocations ) );
		$this->assertSame( 1, $this->jobs->settings_effects );

		$this->assertIsArray( $this->jobs->simulate_forward_batch_effect( $job_id ) );
		$this->mark_lost_claim( $job_id );
		$this->jobs->run_job( $job_id );
		$job = $this->jobs->raw_job( $job_id );
		$this->assertSame( 25, $job['forward_cursor'] );
		$this->assertSame( 2, count( $this->jobs->batch_invocations ) );
		$this->assertSame( 1, $this->jobs->batch_effects );
		$this->assertFalse( $job['phase_resolution_required'] );
	}

	public function test_cancel_after_forward_effect_compensates_only_processed_codes(): void {
		$status = $this->admit( 'request-00000005' );
		$job_id = $status['job_id'];
		$this->jobs->run_job( $job_id );
		$this->jobs->run_job( $job_id );

		$cancel = $this->jobs->cancel( $job_id, array( 'id' => $this->source['id'], 'dataset' => $this->source['dataset'] ) );
		$this->assertSame( 'rollback_settings', $cancel['phase'] );
		$status = $this->finish( $job_id );

		$this->assertSame( 'cancelled', $status['status'] );
		$this->assertSame( $this->jobs->original_revision, $this->jobs->canonical['state_revision'] );
		$this->assertSame( 2, $this->jobs->settings_effects );
		$this->assertSame( 2, $this->jobs->batch_effects );
		$this->assertSame( array( 25, 25 ), array_map( static fn( $call ) => count( $call['codes'] ), $this->jobs->batch_invocations ) );
		$this->assertStringContainsString( 'pricing-apply-rollback:', $this->jobs->batch_invocations[1]['operation_id'] );
		$this->assertSame( 'cancelled', end( $this->jobs->published_snapshots )['pending_terminal']['status'] );
	}

	public function test_terminal_publish_retries_without_repeating_pricing_effects(): void {
		$this->set_scope_size( 10 );
		$this->jobs->publish_failures_remaining = 2;
		$status = $this->admit( 'request-00000006' );
		$status = $this->finish( $status['job_id'] );

		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( 1, $this->jobs->batch_effects );
		$this->assertSame( 3, count( $this->jobs->publish_invocations ) );
		$this->assertSame( 1, $this->jobs->publish_effects );
		$this->assertSame( 3, $status['event_delivery']['attempts'] );
	}

	public function test_initial_scheduler_failure_is_terminal_unaccepted_and_exactly_replayable(): void {
		$this->jobs->watchdog_schedule_ok = false;
		$status = $this->admit( 'request-00000007' );

		$this->assertSame( 'failed', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertFalse( $status['accepted'] );
		$this->assertSame( 'schedule_failed', $status['terminal_reason'] );
		$this->assertSame( 0, $this->jobs->settings_effects );

		$this->jobs->live_reads_fail = true;
		$replay = $this->jobs->replay( $this->settings, $this->jobs->original_revision, 'request-00000007', $this->binding, $this->context );
		$this->assertIsArray( $replay );
		$this->assertTrue( $replay['replayed'] );
		$this->assertFalse( $replay['accepted'] );
	}

	public function test_watchdog_has_a_finite_unknown_outcome_when_locks_never_clear(): void {
		$status = $this->admit( 'request-00000008' );
		$job_id = $status['job_id'];
		$this->mark_lost_claim( $job_id );
		$this->jobs->lock_probe = true;

		$this->jobs->watchdog( $job_id );
		$this->jobs->watchdog( $job_id );
		$this->jobs->watchdog( $job_id );
		$status = $this->jobs->get( $job_id, array( 'id' => $this->source['id'], 'dataset' => $this->source['dataset'] ) );

		$this->assertSame( 'outcome_unknown', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertTrue( $status['readback_required'] );
		$this->assertSame( 0, $this->jobs->settings_effects );
	}

	public function test_init_rearms_a_stored_unarmed_admission(): void {
		$status = $this->admit( 'request-00000009' );
		$job_id = $status['job_id'];
		$this->jobs->mutate_job(
			$job_id,
			static function ( $job ) {
				$job['schedule_state']            = 'unarmed';
				$job['schedule_token']            = '';
				$job['schedule_lease_expires_at'] = 0;

				return $job;
			}
		);
		$this->jobs->worker_schedules   = array();
		$this->jobs->watchdog_schedules = array();
		$this->jobs->recover_unarmed_admissions();

		$status = $this->jobs->get( $job_id, array( 'id' => $this->source['id'], 'dataset' => $this->source['dataset'] ) );
		$this->assertSame( 'armed', $status['schedule_state'] );
		$this->assertCount( 1, $this->jobs->worker_schedules );
		$this->assertCount( 1, $this->jobs->watchdog_schedules );
	}

	private function admit( $request_id ) {
		$context               = $this->context;
		$context['request_id'] = $request_id;

		return $this->jobs->admit(
			$this->settings,
			$this->jobs->original_revision,
			$request_id,
			$this->binding,
			$this->metadata,
			$context
		);
	}

	private function finish( $job_id ) {
		$source = array( 'id' => $this->source['id'], 'dataset' => $this->source['dataset'] );
		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			$status = $this->jobs->get( $job_id, $source );
			if ( ! empty( $status['terminal'] ) ) {
				return $status;
			}
			$this->jobs->run_job( $job_id );
		}

		$this->fail( 'Pricing apply job did not reach a finite terminal state.' );
	}

	private function mark_lost_claim( $job_id ) {
		$now = $this->jobs->clock;
		$this->jobs->mutate_job(
			$job_id,
			static function ( $job ) use ( $now ) {
				$job['lease_token']      = 'lost-worker-token';
				$job['lease_expires_at'] = $now - 1;
				$job['heartbeat_at']      = $now - 100;

				return $job;
			}
		);
	}

	private function set_scope_size( $size ) {
		$codes = array();
		for ( $index = 1; $index <= $size; ++$index ) {
			$codes[] = sprintf( 'CODE-%03d', $index );
		}
		$this->jobs->scope = array(
			'schema'             => 'digitalogic.pricing-scope',
			'state_revision'     => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '5' ),
			'code_digest'        => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '6' ),
			'row_count'          => count( $codes ),
			'codes'              => $codes,
			'sources'            => array( $this->source ),
			'pending_products'   => 0,
			'deferred_ambiguous' => 0,
		);
	}
}
