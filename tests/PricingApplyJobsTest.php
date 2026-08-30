<?php
/**
 * Durable provider-neutral pricing-apply job tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

// phpcs:disable -- PHPUnit fixtures intentionally keep concise doubles and tests in their discovery file.
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
	public $settings_invocations       = array();
	public $settings_payloads          = array();
	public $settings_effects           = 0;
	public $batch_invocations          = array();
	public $batch_effects              = 0;
	public $publish_invocations        = array();
	public $publish_effects            = 0;
	public $published_snapshots        = array();
	public $worker_schedules           = array();
	public $watchdog_schedules         = array();
	public $unscheduled                = array();
	public $worker_schedule_ok         = true;
	public $watchdog_schedule_ok       = true;
	public $publish_failures_remaining = 0;
	public $malformed_publish_receipts = 0;
	public $lock_probe                 = false;
	public $live_reads_fail            = false;
	public $settings_ambiguous_once    = false;
	public $batch_ambiguous_once       = false;
	public $await_ack_on_completed     = false;
	public $bypass_ack_on_completed    = false;
	public $publish_result_override    = array();
	public $confirmation_stages        = 0;
	public $confirmation_override      = array();
	public $ack_during_publish_once    = false;
	public $early_ack_result;
	public $timeout_during_publish_once = false;
	public $early_timeout_result;
	public $early_timeout_lease             = '';
	public $run_worker_during_schedule_once = false;
	public $scope_reads                     = array();

	private $settings_receipts = array();
	private $batch_receipts    = array();
	private $publish_receipts  = array();
	private $job_sequence      = 0;

	protected function prepare_settings( $settings ) {
		return $settings;
	}

	protected function read_canonical_state() {
		return $this->live_reads_fail
			? new WP_Error( 'test_live_read_forbidden', 'Live canonical read was not expected.', array( 'status' => 500 ) )
			: $this->canonical;
	}

	protected function read_pricing_scope( $source ) {
		$this->scope_reads[] = $source;
		return $this->live_reads_fail
			? new WP_Error( 'test_live_read_forbidden', 'Live scope read was not expected.', array( 'status' => 500 ) )
			: $this->scope;
	}

	protected function read_ack_consumer( $source ) {
		if ( $this->live_reads_fail ) {
			return new WP_Error( 'test_live_read_forbidden', 'Live acknowledgement consumer read was not expected.', array( 'status' => 500 ) );
		}

		return array(
			'consumer_id' => 'excel-companion',
			'channel'     => 'excel',
			'capability'  => 'pricing_settings_ack',
			'source_id'   => (string) $source['id'],
			'dataset'     => (string) $source['dataset'],
		);
	}

	protected function commit_settings_phase( $settings, $source, $expected_revision, $operation_id, $request_context, $source_context = array() ) {
		$this->settings_invocations[] = $operation_id;
		$this->settings_payloads[]    = $settings;
		if ( isset( $this->settings_receipts[ $operation_id ] ) ) {
			return array_merge( $this->settings_receipts[ $operation_id ], array( 'replayed' => true ) );
		}
		if ( ! hash_equals( (string) $expected_revision, (string) $this->canonical['state_revision'] ) ) {
			return new WP_Error( 'test_settings_revision_conflict', 'Settings revision changed.', array( 'status' => 409 ) );
		}
		$previous  = $this->canonical;
		$restoring = str_starts_with( $operation_id, 'pricing-apply-restore:' );
		if (
			$restoring
			&& ! hash_equals( (string) ( $settings['shipping_catalog_revision'] ?? '' ), (string) $previous['shipping']['catalog_revision'] )
		) {
			return new WP_Error( 'digitalogic_pricing_shipping_revision_conflict', 'Shipping revision changed.', array( 'status' => 409 ) );
		}
		$next_revision   = $restoring ? $this->original_revision : $this->desired_revision;
		$next_catalog    = $restoring ? $this->original_catalog_revision : $this->desired_catalog_revision;
		$this->canonical = array(
			'state_revision' => $next_revision,
			'settings'       => $settings,
			'shipping'       => array( 'catalog_revision' => $next_catalog ),
		);
		++$this->settings_effects;
		$receipt                                  = array(
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
		if ( $this->settings_ambiguous_once ) {
			$this->settings_ambiguous_once = false;

			return new WP_Error(
				'digitalogic_excel_sync_commit_ambiguous',
				'Commit reply was lost after the receipt became durable.',
				array(
					'status'    => 500,
					'retryable' => true,
					'uncertain' => true,
				)
			);
		}

		return $receipt;
	}

	protected function reprice_batch( $settings, $codes, $previous_catalog_revision, $expected_scope_revision, $expected_code_digest, $operation_id, $source ) {
		$this->batch_invocations[] = array(
			'operation_id'              => $operation_id,
			'codes'                     => $codes,
			'settings'                  => $settings,
			'previous_catalog_revision' => $previous_catalog_revision,
			'source'                    => $source,
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
		$next_revision                         = 'sha256:' . hash( 'sha256', $operation_id );
		$this->scope['state_revision']         = $next_revision;
		$count                                 = count( $codes );
		$receipt                               = array(
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
						'source'          => array(
							'id'       => 'provider-a',
							'dataset'  => 'prices',
							'revision' => self::revision( '9' ),
						),
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
		if ( $this->batch_ambiguous_once ) {
			$this->batch_ambiguous_once = false;

			return new WP_Error(
				'digitalogic_excel_sync_commit_ambiguous',
				'Commit reply was lost after the receipt became durable.',
				array(
					'status'    => 500,
					'retryable' => true,
					'uncertain' => true,
				)
			);
		}

		return $receipt;
	}

	protected function publish_outbox( $job ) {
		$event_id                    = (string) $job['outbox']['event_id'];
		$effect_id                   = 'sha256:' . hash( 'sha256', "pricing-apply-terminal\0" . $event_id . "\0" . (string) $job['request_fingerprint'] );
		$this->publish_invocations[] = $event_id;
		$this->published_snapshots[] = $job;
		if ( isset( $this->publish_receipts[ $event_id ] ) ) {
			return array_merge( $this->publish_receipts[ $event_id ], array( 'replayed' => true ) );
		}
		if ( $this->publish_failures_remaining > 0 ) {
			--$this->publish_failures_remaining;

			return new WP_Error(
				'test_publish_busy',
				'Terminal event store is temporarily unavailable.',
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}
		$status = (string) $job['pending_terminal']['status'];
		if ( 'completed' === $status && ! $this->bypass_ack_on_completed && 'acknowledged' !== (string) ( $job['confirmation']['status'] ?? '' ) ) {
			++$this->confirmation_stages;
			$transaction_id     = 'ptx_' . substr( hash( 'sha256', $event_id ), 0, 32 );
			$committed_settings = (array) $job['settings_phase']['settings'];
			ksort( $committed_settings, SORT_STRING );
			$confirmation = array(
				'schema'                    => 'digitalogic.pricing-confirmation',
				'status'                    => 'awaiting_ack',
				'transaction_id'            => $transaction_id,
				'previous_revision'         => $job['settings_phase']['previous_state_revision'],
				'committed_revision'        => $job['settings_phase']['state_revision'],
				'current_revision'          => $job['settings_phase']['state_revision'],
				'committed_settings_digest' => 'sha256:' . hash( 'sha256', wp_json_encode( $committed_settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
				'ack_deadline'              => $this->clock + 90,
				'recovery_deadline'         => $this->clock + 390,
				'ack_path'                  => '/wp-json/digitalogic/pricing/sync/ack',
				'consumer_id'               => 'excel-companion',
				'channel'                   => 'excel',
			);
			$confirmation = array_merge( $confirmation, $this->confirmation_override );
			$apply_result = array(
				'schema'         => 'digitalogic.pricing-sync-apply',
				'status'         => 'applied',
				'event_id'       => $event_id,
				'effect_id'      => $effect_id,
				'job_id'         => $job['job_id'],
				'request_id'     => $job['request_id'],
				'source'         => $job['operation_binding']['source'],
				'state_revision' => $job['settings_phase']['state_revision'],
				'settings'       => $job['desired_settings'],
				'confirmation'   => $confirmation,
			);
			$apply_result = array_merge( $apply_result, $this->publish_result_override );
			if ( $this->ack_during_publish_once || ! $this->await_ack_on_completed ) {
				$this->ack_during_publish_once = false;
				$ack                           = array_merge( $confirmation, array( 'status' => 'acknowledged' ) );
				$this->early_ack_result        = $this->acknowledge_confirmation( $job['job_id'], $transaction_id, $ack );
			}
			if ( $this->timeout_during_publish_once ) {
				$this->timeout_during_publish_once = false;
				$this->early_timeout_result        = $this->request_confirmation_rollback( $job['job_id'], $transaction_id );
				$this->early_timeout_lease         = (string) $this->raw_job( $job['job_id'] )['lease_token'];
			}

			return array(
				'awaiting_ack' => true,
				'confirmation' => $confirmation,
				'result'       => $apply_result,
			);
		}
		++$this->publish_effects;
		$result = 'completed' === $status
			? array(
				'schema'         => 'digitalogic.pricing-sync-apply',
				'status'         => 'applied',
				'event_id'       => $event_id,
				'effect_id'      => $effect_id,
				'job_id'         => $job['job_id'],
				'request_id'     => $job['request_id'],
				'source'         => $job['operation_binding']['source'],
				'state_revision' => $job['settings_phase']['state_revision'],
				'settings'       => $job['desired_settings'],
				'confirmation'   => $job['confirmation'],
				'event_delivery' => array( 'durable' => true ),
			)
			: array(
				'schema'          => 'digitalogic.pricing-apply-terminal',
				'event_id'        => $event_id,
				'effect_id'       => $effect_id,
				'job_id'          => $job['job_id'],
				'request_id'      => $job['request_id'],
				'source'          => $job['operation_binding']['source'],
				'status'          => $status,
				'terminal_reason' => $job['pending_terminal']['reason'],
				'event_delivery'  => array( 'durable' => true ),
			);
		$result = array_merge( $result, $this->publish_result_override );
		if ( 'rolled_back' === $status && is_array( $job['confirmation'] ?? null ) ) {
			$result['confirmation']                     = $job['confirmation'];
			$result['confirmation']['status']           = 'rolled_back';
			$result['confirmation']['current_revision'] = $job['expected_state_revision'];
		}
		$this->publish_receipts[ $event_id ] = $result;
		if ( $this->malformed_publish_receipts > 0 ) {
			--$this->malformed_publish_receipts;

			return array();
		}

		return $result;
	}

	protected function probe_pricing_locks() {
		return $this->lock_probe;
	}

	protected function schedule_worker( $job_id, $delay ) {
		$this->worker_schedules[] = array( $job_id, $delay );
		if ( $this->run_worker_during_schedule_once ) {
			$this->run_worker_during_schedule_once = false;
			$this->run_job( $job_id );
		}

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

	public function claim_for_test( $job_id ) {
		$method = new ReflectionMethod( Digitalogic_Pricing_Apply_Jobs::class, 'claim_job' );

		return $method->invoke( $this, $job_id );
	}

	public function execute_for_test( $job ) {
		$method = new ReflectionMethod( Digitalogic_Pricing_Apply_Jobs::class, 'execute_phase' );

		return $method->invoke( $this, $job );
	}

	public function complete_for_test( $job_id, $lease_token, $outcome ) {
		$method = new ReflectionMethod( Digitalogic_Pricing_Apply_Jobs::class, 'complete_claim' );

		return $method->invoke( $this, $job_id, $lease_token, $outcome );
	}

	public function schedule_fence_for_test( $job_id ) {
		$method = new ReflectionMethod( Digitalogic_Pricing_Apply_Jobs::class, 'schedule_failure_fence' );

		return $method->invoke( $this, $job_id );
	}

	public function record_schedule_failure_for_test( $job_id, $fence, $kind = 'worker' ) {
		$method = new ReflectionMethod( Digitalogic_Pricing_Apply_Jobs::class, 'record_schedule_failure' );

		return $method->invoke( $this, $job_id, $kind, $fence );
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
		$job   = $this->raw_job( $job_id );
		$batch = array_slice( $job['codes'], (int) $job['forward_cursor'], 25 );

		return $this->reprice_batch(
			$job['desired_settings'],
			$batch,
			$job['settings_phase']['previous_catalog_revision'],
			$job['expected_scope_revision'],
			$job['source_pin']['code_digest'],
			'pricing-apply-forward:' . $job_id . ':' . intdiv( (int) $job['forward_cursor'], 25 ),
			$job['operation_binding']['source']
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

		$this->source   = array(
			'id'       => 'provider-a',
			'dataset'  => 'prices',
			'revision' => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '9' ),
		);
		$this->binding  = array(
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
		$this->context  = array(
			'client_id'  => 'excel-companion',
			'channel'    => 'excel',
			'request_id' => 'request-00000001',
		);
		$this->settings = array(
			'yuan_price'            => '31000',
			'profit_margin_percent' => '30',
		);

		$this->jobs                            = new Digitalogic_Pricing_Apply_Jobs_Harness( false );
		$this->jobs->original_revision         = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '1' );
		$this->jobs->desired_revision          = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '2' );
		$this->jobs->original_catalog_revision = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '3' );
		$this->jobs->desired_catalog_revision  = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '4' );
		$this->jobs->original_settings         = array(
			'yuan_price'            => '30000',
			'profit_margin_percent' => '30',
		);
		$this->jobs->canonical                 = array(
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
		$replay                      = $this->jobs->replay(
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
		$this->assertSame(
			array( 'provider-a', 'provider-a', 'provider-a' ),
			array_map( static fn( $call ) => $call['source']['id'], $this->jobs->batch_invocations )
		);
		$this->assertSame( 'pricing_settings_ack', $this->jobs->raw_job( $status['job_id'] )['ack_consumer']['capability'] );

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
			array(
				'id'       => $this->source['id'],
				'dataset'  => $this->source['dataset'],
				'revision' => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '7' ),
				'extra'    => true,
			)
		);
		$this->assertIsArray( $found );

		$hidden = $this->jobs->get(
			$status['job_id'],
			array(
				'id'      => $this->source['id'],
				'dataset' => 'another-dataset',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $hidden );
		$this->assertSame( 404, $hidden->get_error_data()['status'] );

		$cancelled = $this->jobs->cancel(
			$status['job_id'],
			array(
				'id'      => $this->source['id'],
				'dataset' => $this->source['dataset'],
			)
		);
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

	public function test_ambiguous_commit_errors_replay_exact_phase_receipts(): void {
		$this->jobs->settings_ambiguous_once = true;
		$this->jobs->batch_ambiguous_once    = true;
		$status                              = $this->admit( 'request-ambiguous-0001' );
		$job_id                              = $status['job_id'];

		$this->jobs->run_job( $job_id );
		$job = $this->jobs->raw_job( $job_id );
		$this->assertSame( 'settings', $job['phase'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( 1, $this->jobs->settings_effects );

		$this->jobs->run_job( $job_id );
		$job = $this->jobs->raw_job( $job_id );
		$this->assertSame( 'repricing', $job['phase'] );
		$this->assertFalse( $job['phase_resolution_required'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( $this->jobs->settings_invocations[0], $this->jobs->settings_invocations[1] );

		$this->jobs->run_job( $job_id );
		$job = $this->jobs->raw_job( $job_id );
		$this->assertSame( 'repricing', $job['phase'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( 1, $this->jobs->batch_effects );

		$this->jobs->run_job( $job_id );
		$job = $this->jobs->raw_job( $job_id );
		$this->assertSame( 25, $job['forward_cursor'] );
		$this->assertFalse( $job['phase_resolution_required'] );
		$this->assertSame( 1, $this->jobs->batch_effects );
		$this->assertSame( $this->jobs->batch_invocations[0]['operation_id'], $this->jobs->batch_invocations[1]['operation_id'] );
	}

	public function test_completed_apply_waits_for_exact_ack_without_worker_polling(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-awaiting-ack-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'awaiting_ack' );

		$this->assertFalse( $status['terminal'] );
		$this->assertSame( 'awaiting_ack', $status['status'] );
		$this->assertSame( 'awaiting_ack', $status['confirmation']['status'] );
		$this->assertSame( 1, $this->jobs->confirmation_stages );
		$this->assertSame( 0, $this->jobs->publish_effects );
		$schedules = count( $this->jobs->worker_schedules );
		$this->jobs->run_job( $status['job_id'] );
		$this->assertSame( $schedules, count( $this->jobs->worker_schedules ) );

		$confirmation              = $status['confirmation'];
		$confirmation['status']    = 'acknowledged';
		$mismatched                = $confirmation;
		$mismatched['consumer_id'] = 'another-consumer';
		$rejected                  = $this->jobs->acknowledge_confirmation( $status['job_id'], $confirmation['transaction_id'], $mismatched );
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'digitalogic_pricing_apply_confirmation_conflict', $rejected->get_error_code() );
		$mutated                       = $confirmation;
		$mutated['committed_revision'] = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '6' );
		$rejected                      = $this->jobs->acknowledge_confirmation( $status['job_id'], $confirmation['transaction_id'], $mutated );
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'digitalogic_pricing_apply_confirmation_conflict', $rejected->get_error_code() );
		$resumed = $this->jobs->acknowledge_confirmation(
			$status['job_id'],
			$confirmation['transaction_id'],
			$confirmation
		);
		$this->assertSame( 'publishing', $resumed['phase'] );
		$this->assertSame( $schedules + 1, count( $this->jobs->worker_schedules ) );
		$duplicate           = $confirmation;
		$duplicate['status'] = 'replayed';
		$duplicate           = $this->jobs->acknowledge_confirmation( $status['job_id'], $confirmation['transaction_id'], $duplicate );
		$this->assertTrue( $duplicate['replayed'] );
		$this->assertSame( 'publishing', $duplicate['phase'] );

		$completed = $this->finish( $status['job_id'] );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 'acknowledged', $completed['confirmation']['status'] );
		$this->assertSame( 1, $this->jobs->publish_effects );
		$replayed = $this->jobs->acknowledge_confirmation(
			$status['job_id'],
			$confirmation['transaction_id'],
			$confirmation
		);
		$this->assertTrue( $replayed['replayed'] );
		$this->assertSame( 'completed', $replayed['status'] );
	}

	public function test_delayed_schedule_failure_cannot_supersede_a_new_ack_wait(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-stale-schedule-ack-wait-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'publishing' );
		$claim                              = $this->jobs->claim_for_test( $status['job_id'] );

		$first_fence = $this->jobs->schedule_fence_for_test( $status['job_id'] );
		$this->jobs->record_schedule_failure_for_test( $status['job_id'], $first_fence );
		$second_fence = $this->jobs->schedule_fence_for_test( $status['job_id'] );
		$this->jobs->record_schedule_failure_for_test( $status['job_id'], $second_fence );
		$delayed_fence = $this->jobs->schedule_fence_for_test( $status['job_id'] );
		$outcome       = $this->jobs->execute_for_test( $claim['job'] );
		$waiting       = $this->jobs->complete_for_test( $status['job_id'], $claim['lease_token'], $outcome );
		$this->jobs->record_schedule_failure_for_test( $status['job_id'], $delayed_fence );
		$stored = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'awaiting_ack', $waiting['phase'] );
		$this->assertSame( 'awaiting_ack', $stored['phase'] );
		$this->assertSame( 'awaiting_ack', $stored['status'] );
		$this->assertSame( 'awaiting_ack', $stored['outbox']['state'] );
		$this->assertSame( 2, $stored['schedule_failures'] );
		$this->assertSame( 'awaiting_ack', $stored['confirmation']['status'] );

		$confirmation           = $waiting['confirmation'];
		$confirmation['status'] = 'acknowledged';
		$this->jobs->acknowledge_confirmation( $status['job_id'], $confirmation['transaction_id'], $confirmation );
		$completed = $this->finish( $status['job_id'] );
		$this->assertSame( 'completed', $completed['status'] );
	}

	public function test_reentrant_ack_is_reconciled_after_the_publisher_lease(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed  = true;
		$this->jobs->ack_during_publish_once = true;
		$status                              = $this->admit( 'request-early-ack-0001' );
		$completed                           = $this->finish( $status['job_id'] );

		$this->assertIsArray( $this->jobs->early_ack_result );
		$this->assertSame( 'publishing', $this->jobs->early_ack_result['phase'] );
		$this->assertSame( 'acknowledged', $this->jobs->early_ack_result['confirmation']['status'] );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 'acknowledged', $completed['confirmation']['status'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( 1, $this->jobs->batch_effects );
		$this->assertSame( 1, $this->jobs->publish_effects );
	}

	public function test_reentrant_ack_extends_recovery_for_the_final_publish(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed  = true;
		$this->jobs->ack_during_publish_once = true;
		$status                              = $this->admit( 'request-late-early-ack-0001' );
		$status                              = $this->run_until_phase( $status['job_id'], 'publishing' );
		$old_recovery                        = $this->jobs->raw_job( $status['job_id'] )['recovery_deadline_at'];
		$this->jobs->advance( $old_recovery - $this->jobs->clock - 1 );
		$this->jobs->run_job( $status['job_id'] );
		$acknowledged = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'publishing', $acknowledged['phase'] );
		$this->assertSame( 'acknowledged', $acknowledged['confirmation']['status'] );
		$this->assertGreaterThan( $old_recovery, $acknowledged['recovery_deadline_at'] );
		$this->assertSame( $acknowledged['confirmation']['recovery_deadline'], $acknowledged['recovery_deadline_at'] );

		$this->jobs->advance( 2 );
		$completed = $this->finish( $status['job_id'] );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 'acknowledged', $completed['confirmation']['status'] );
	}

	public function test_invalid_staged_confirmation_never_parks_the_job_as_awaiting_ack(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$this->jobs->confirmation_override  = array( 'ack_deadline' => 0 );
		$status                             = $this->admit( 'request-invalid-confirmation-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'publishing' );
		$this->jobs->run_job( $status['job_id'] );
		$job = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'publishing', $job['phase'] );
		$this->assertNull( $job['confirmation'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( 'digitalogic_pricing_apply_confirmation_invalid', $job['last_error']['code'] );
	}

	public function test_staged_confirmation_must_match_the_exact_settings_receipt(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$this->jobs->confirmation_override  = array( 'committed_settings_digest' => Digitalogic_Pricing_Apply_Jobs_Harness::revision( '6' ) );
		$status                             = $this->admit( 'request-wrong-confirmation-digest-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'publishing' );
		$this->jobs->run_job( $status['job_id'] );
		$job = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'publishing', $job['phase'] );
		$this->assertNull( $job['confirmation'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( 'digitalogic_pricing_apply_confirmation_conflict', $job['last_error']['code'] );
	}

	public function test_completed_receipt_cannot_bypass_the_ack_barrier(): void {
		$this->set_scope_size( 10 );
		$this->jobs->bypass_ack_on_completed = true;
		$status                              = $this->admit( 'request-bypass-ack-0001' );
		$status                              = $this->run_until_phase( $status['job_id'], 'publishing' );
		$this->jobs->run_job( $status['job_id'] );
		$job = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'publishing', $job['phase'] );
		$this->assertNull( $job['confirmation'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( 'digitalogic_pricing_apply_outbox_receipt_invalid', $job['last_error']['code'] );
	}

	public function test_completed_receipt_must_bind_the_exact_outbox_event(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed  = true;
		$this->jobs->publish_result_override = array( 'event_id' => 'pricing-apply:job_ffffffffffffffffffffffffffffffff' );
		$status                              = $this->admit( 'request-wrong-event-0001' );
		$status                              = $this->run_until_phase( $status['job_id'], 'publishing' );
		$this->jobs->run_job( $status['job_id'] );
		$job = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'publishing', $job['phase'] );
		$this->assertNull( $job['confirmation'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( 'digitalogic_pricing_apply_outbox_receipt_invalid', $job['last_error']['code'] );
	}

	public function test_completed_receipt_confirmation_must_match_the_acknowledged_identity(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-conflicting-final-confirmation-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'awaiting_ack' );
		$confirmation                      = $status['confirmation'];
		$confirmation['status']            = 'acknowledged';
		$resumed                           = $this->jobs->acknowledge_confirmation( $status['job_id'], $confirmation['transaction_id'], $confirmation );
		$conflicting                       = $confirmation;
		$conflicting['current_revision']   = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '6' );
		$this->jobs->publish_result_override = array( 'confirmation' => $conflicting );

		$this->assertSame( 'publishing', $resumed['phase'] );
		$this->jobs->run_job( $status['job_id'] );
		$job = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'publishing', $job['phase'] );
		$this->assertTrue( $job['phase_resolution_required'] );
		$this->assertSame( $confirmation['current_revision'], $job['confirmation']['current_revision'] );
		$this->assertSame( 'digitalogic_pricing_apply_outbox_receipt_invalid', $job['last_error']['code'] );

		$original_event = $job['outbox']['event_id'];
		$unknown        = $this->finish( $status['job_id'] );
		$this->assertSame( 'outcome_unknown', $unknown['status'] );
		$this->assertTrue( $unknown['terminal'] );
		$this->assertSame( 'emitted', $unknown['event_delivery']['state'] );
		$this->assertNotSame( $original_event, $unknown['event_delivery']['event_id'] );
		$this->assertSame( 'pricing-apply-outcome-unknown:' . $status['job_id'], $unknown['event_delivery']['event_id'] );
		$this->assertSame( $confirmation['current_revision'], $unknown['confirmation']['current_revision'] );
		$this->assertSame( 'outcome_unknown', end( $this->jobs->published_snapshots )['pending_terminal']['status'] );
	}

	public function test_late_confirmation_extends_recovery_for_its_valid_timeout(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-late-confirmation-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'publishing' );
		$before                             = $this->jobs->raw_job( $status['job_id'] )['recovery_deadline_at'];
		$this->jobs->advance( $before - $this->jobs->clock - 1 );
		$this->jobs->run_job( $status['job_id'] );
		$waiting = $this->jobs->get( $status['job_id'], $this->source );
		$stored  = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'awaiting_ack', $waiting['phase'] );
		$this->assertGreaterThan( $before, $stored['recovery_deadline_at'] );
		$this->assertSame( $waiting['confirmation']['recovery_deadline'], $stored['recovery_deadline_at'] );
		$this->jobs->request_confirmation_rollback( $status['job_id'], $waiting['confirmation']['transaction_id'] );
		$rolled_back = $this->finish( $status['job_id'] );
		$this->assertSame( 'rolled_back', $rolled_back['status'] );
	}

	public function test_ack_timeout_uses_the_same_bounded_compensation_job(): void {
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-await-timeout-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'awaiting_ack' );
		$transaction_id                     = $status['confirmation']['transaction_id'];
		$schedules                          = count( $this->jobs->worker_schedules );
		$cancelled                          = $this->jobs->cancel( $status['job_id'], $this->source );
		$this->assertSame( 'awaiting_ack', $cancelled['phase'] );
		$this->assertTrue( $cancelled['cancel_requested'] );
		$this->assertSame( $schedules, count( $this->jobs->worker_schedules ) );

		$resumed = $this->jobs->request_confirmation_rollback( $status['job_id'], $transaction_id );
		$this->assertSame( 'recovering', $resumed['status'] );
		$this->assertSame( 'rollback_settings', $resumed['phase'] );
		$rolled_back = $this->finish( $status['job_id'] );

		$this->assertSame( 'rolled_back', $rolled_back['status'] );
		$this->assertSame( 'rolled_back', $rolled_back['confirmation']['status'] );
		$this->assertSame( $this->jobs->original_revision, $rolled_back['confirmation']['current_revision'] );
		$this->assertSame( $this->jobs->original_revision, $this->jobs->canonical['state_revision'] );
		$this->assertSame( 2, $this->jobs->settings_effects );
		$this->assertSame( $this->jobs->desired_catalog_revision, $this->jobs->settings_payloads[1]['shipping_catalog_revision'] );
		$this->assertSame( array( 25, 25, 10, 25, 25, 10 ), array_map( static fn( $call ) => count( $call['codes'] ), $this->jobs->batch_invocations ) );
		$this->assertSame( 1, $this->jobs->publish_effects );
		$this->assertSame( 'rolled_back', end( $this->jobs->published_snapshots )['pending_terminal']['status'] );
		$timeout_replay = $this->jobs->request_confirmation_rollback( $status['job_id'], $transaction_id );
		$this->assertTrue( $timeout_replay['replayed'] );
		$reason_conflict = $this->jobs->request_confirmation_rollback( $status['job_id'], $transaction_id, 'different_reason' );
		$this->assertInstanceOf( WP_Error::class, $reason_conflict );
		$this->assertSame( 'digitalogic_pricing_apply_confirmation_closed', $reason_conflict->get_error_code() );
	}

	public function test_timeout_during_publish_waits_for_the_exact_lease_then_compensates(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed      = true;
		$this->jobs->timeout_during_publish_once = true;
		$status                                  = $this->admit( 'request-early-timeout-0001' );
		$rolled_back                             = $this->finish( $status['job_id'] );

		$this->assertIsArray( $this->jobs->early_timeout_result );
		$this->assertSame( 'publishing', $this->jobs->early_timeout_result['phase'] );
		$this->assertNotSame( '', $this->jobs->early_timeout_lease );
		$this->assertSame( 'rolled_back', $rolled_back['status'] );
		$this->assertSame( 'rolled_back', $rolled_back['confirmation']['status'] );
		$this->assertSame( 2, $this->jobs->settings_effects );
		$this->assertSame( 2, $this->jobs->batch_effects );
	}

	public function test_crashed_early_timeout_cannot_compensate_after_the_hard_recovery_bound(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed      = true;
		$this->jobs->timeout_during_publish_once = true;
		$status                                  = $this->admit( 'request-crashed-early-timeout-0001' );
		$status                                  = $this->run_until_phase( $status['job_id'], 'publishing' );
		$claim                                   = $this->jobs->claim_for_test( $status['job_id'] );
		$outcome                                 = $this->jobs->execute_for_test( $claim['job'] );
		$crashed                                 = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'rollback', $crashed['confirmation_signal']['type'] );
		$this->assertSame( $claim['lease_token'], $crashed['lease_token'] );
		$this->jobs->advance( $crashed['recovery_deadline_at'] - $this->jobs->clock + 1 );
		$this->jobs->run_job( $status['job_id'] );
		$unknown = $this->jobs->get( $status['job_id'], $this->source );
		$stored  = $this->jobs->raw_job( $status['job_id'] );

		$this->assertIsArray( $outcome );
		$this->assertSame( 'outcome_unknown', $unknown['status'] );
		$this->assertTrue( $unknown['terminal'] );
		$this->assertNull( $stored['confirmation_signal'] );
		$this->assertSame( 'pricing-apply-outcome-unknown:' . $status['job_id'], $unknown['event_delivery']['event_id'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( 1, $this->jobs->batch_effects );
	}

	public function test_cancel_after_forward_effect_compensates_only_processed_codes(): void {
		$status = $this->admit( 'request-00000005' );
		$job_id = $status['job_id'];
		$this->jobs->run_job( $job_id );
		$this->jobs->run_job( $job_id );

		$cancel = $this->jobs->cancel(
			$job_id,
			array(
				'id'      => $this->source['id'],
				'dataset' => $this->source['dataset'],
			)
		);
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

	public function test_cancel_cannot_rotate_an_expired_exact_verification_lease(): void {
		$this->set_scope_size( 10 );
		$status = $this->admit( 'request-expired-verification-cancel-0001' );
		$status = $this->run_until_phase( $status['job_id'], 'verifying' );
		$claim  = $this->jobs->claim_for_test( $status['job_id'] );
		$this->jobs->advance( 46 );
		$cancelled = $this->jobs->cancel( $status['job_id'], $this->source );

		$this->assertSame( 'verifying', $cancelled['phase'] );
		$this->assertTrue( $cancelled['cancel_requested'] );
		$this->assertSame( $claim['lease_token'], $this->jobs->raw_job( $status['job_id'] )['lease_token'] );

		$outcome    = $this->jobs->execute_for_test( $claim['job'] );
		$recovering = $this->jobs->complete_for_test( $status['job_id'], $claim['lease_token'], $outcome );
		$this->assertSame( 'recovering', $recovering['status'] );
		$this->assertSame( 'rollback_settings', $recovering['phase'] );
		$terminal = $this->finish( $status['job_id'] );

		$this->assertSame( 'cancelled', $terminal['status'] );
		$this->assertSame( $this->jobs->original_revision, $this->jobs->canonical['state_revision'] );
		$this->assertSame( 2, $this->jobs->settings_effects );
		$this->assertSame( 2, $this->jobs->batch_effects );
	}

	public function test_deadline_rollback_before_confirmation_publishes_without_a_closed_projection(): void {
		$status = $this->admit( 'request-pre-confirmation-deadline-0001' );
		$job_id = $status['job_id'];
		$this->jobs->run_job( $job_id );
		$this->jobs->run_job( $job_id );
		$forward = $this->jobs->raw_job( $job_id );

		$this->assertSame( 25, $forward['forward_cursor'] );
		$this->assertNull( $forward['confirmation'] );
		$this->jobs->advance( $forward['deadline_at'] - $this->jobs->clock );
		$rolled_back = $this->finish( $job_id );

		$this->assertSame( 'rolled_back', $rolled_back['status'] );
		$this->assertSame( 'deadline_exceeded', $rolled_back['terminal_reason'] );
		$this->assertNull( $rolled_back['confirmation'] );
		$this->assertSame( 'emitted', $rolled_back['event_delivery']['state'] );
		$this->assertSame( $this->jobs->original_revision, $this->jobs->canonical['state_revision'] );
		$this->assertSame( array( 25, 25 ), array_map( static fn( $call ) => count( $call['codes'] ), $this->jobs->batch_invocations ) );
		$this->assertSame( 'rolled_back', end( $this->jobs->published_snapshots )['pending_terminal']['status'] );
	}

	public function test_terminal_publish_retries_without_repeating_pricing_effects(): void {
		$this->set_scope_size( 10 );
		$this->jobs->publish_failures_remaining = 2;
		$status                                 = $this->admit( 'request-00000006' );
		$status                                 = $this->finish( $status['job_id'] );

		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( 1, $this->jobs->batch_effects );
		$this->assertSame( 4, count( $this->jobs->publish_invocations ) );
		$this->assertSame( 1, $this->jobs->publish_effects );
		$this->assertSame( 4, $status['event_delivery']['attempts'] );
	}

	public function test_initial_scheduler_failure_is_terminal_unaccepted_and_exactly_replayable(): void {
		$this->jobs->watchdog_schedule_ok = false;
		$this->jobs->worker_schedule_ok   = false;
		$status                           = $this->admit( 'request-00000007' );

		$this->assertSame( 'failed', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertFalse( $status['accepted'] );
		$this->assertSame( 'schedule_failed', $status['terminal_reason'] );
		$this->assertSame( 0, $this->jobs->settings_effects );

		$this->jobs->live_reads_fail = true;
		$replay                      = $this->jobs->replay( $this->settings, $this->jobs->original_revision, 'request-00000007', $this->binding, $this->context );
		$this->assertIsArray( $replay );
		$this->assertTrue( $replay['replayed'] );
		$this->assertFalse( $replay['accepted'] );
	}

	public function test_scheduler_failure_cannot_terminalize_a_concurrently_claimed_worker(): void {
		$this->jobs->watchdog_schedule_ok            = false;
		$this->jobs->worker_schedule_ok              = false;
		$this->jobs->run_worker_during_schedule_once = true;
		$status                                      = $this->admit( 'request-schedule-race-0001' );

		$this->assertTrue( $status['accepted'] );
		$this->assertFalse( $status['terminal'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
		$this->assertSame( 'repricing', $status['phase'] );
		$this->assertSame( array(), $this->jobs->unscheduled );

		$this->jobs->watchdog_schedule_ok = true;
		$this->jobs->worker_schedule_ok   = true;
		$completed                        = $this->finish( $status['job_id'] );
		$this->assertSame( 'completed', $completed['status'] );
	}

	public function test_continuation_schedule_failures_cannot_supersede_a_live_effect_lease(): void {
		$status = $this->admit( 'request-live-lease-schedule-0001' );
		$claim  = $this->jobs->claim_for_test( $status['job_id'] );
		$this->assertTrue( $claim['claimed'] );
		$this->jobs->worker_schedule_ok = false;
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$this->jobs->cancel( $status['job_id'], $this->source );
		}
		$live = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'settings', $live['phase'] );
		$this->assertSame( $claim['lease_token'], $live['lease_token'] );
		$this->assertGreaterThan( $this->jobs->clock, $live['lease_expires_at'] );
		$this->assertSame( 3, $live['schedule_failures'] );

		$outcome   = $this->jobs->execute_for_test( $claim['job'] );
		$recovered = $this->jobs->complete_for_test( $status['job_id'], $claim['lease_token'], $outcome );
		$this->assertIsArray( $recovered );
		$this->assertSame( 'recovering', $recovered['status'] );
		$this->assertSame( 'rollback_settings', $recovered['phase'] );

		$this->jobs->worker_schedule_ok = true;
		$cancelled                     = $this->finish( $status['job_id'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( $this->jobs->original_revision, $this->jobs->canonical['state_revision'] );
		$this->assertSame( 2, $this->jobs->settings_effects );
	}

	public function test_watchdog_schedule_failures_cannot_supersede_an_expired_exact_lease(): void {
		$status = $this->admit( 'request-expired-lease-watchdog-0001' );
		$claim  = $this->jobs->claim_for_test( $status['job_id'] );
		$this->jobs->advance( 46 );
		$this->jobs->watchdog_schedule_ok = false;
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$this->jobs->watchdog( $status['job_id'] );
		}
		$leased = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'settings', $leased['phase'] );
		$this->assertSame( $claim['lease_token'], $leased['lease_token'] );
		$this->assertLessThanOrEqual( $this->jobs->clock, $leased['lease_expires_at'] );
		$this->assertSame( 3, $leased['schedule_failures'] );

		$outcome   = $this->jobs->execute_for_test( $claim['job'] );
		$continued = $this->jobs->complete_for_test( $status['job_id'], $claim['lease_token'], $outcome );
		$this->assertIsArray( $continued );
		$this->assertSame( 'repricing', $continued['phase'] );

		$this->jobs->watchdog_schedule_ok = true;
		$completed                        = $this->finish( $status['job_id'] );
		$this->assertSame( 'completed', $completed['status'] );
		$this->assertSame( 1, $this->jobs->settings_effects );
	}

	public function test_watchdog_has_a_finite_unknown_outcome_when_locks_never_clear(): void {
		$status = $this->admit( 'request-00000008' );
		$job_id = $status['job_id'];
		$this->mark_lost_claim( $job_id );
		$this->jobs->lock_probe = true;

		$this->jobs->watchdog( $job_id );
		$this->jobs->watchdog( $job_id );
		$this->jobs->watchdog( $job_id );
		$status = $this->jobs->get(
			$job_id,
			array(
				'id'      => $this->source['id'],
				'dataset' => $this->source['dataset'],
			)
		);

		$this->assertSame( 'publishing', $status['status'] );
		$this->assertFalse( $status['terminal'] );
		$this->jobs->malformed_publish_receipts = 1;
		$this->jobs->run_job( $job_id );
		$status = $this->jobs->get( $job_id, $this->source );
		$this->assertSame( 'publishing', $status['status'] );
		$this->assertFalse( $status['terminal'] );
		$this->assertSame( 'digitalogic_pricing_apply_outbox_receipt_invalid', $status['error']['code'] );
		$status = $this->finish( $job_id );
		$this->assertSame( 'outcome_unknown', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertTrue( $status['readback_required'] );
		$this->assertSame( 0, $this->jobs->settings_effects );
		$this->assertSame( 1, $this->jobs->publish_effects );
		$this->assertSame( 'outcome_unknown', end( $this->jobs->published_snapshots )['pending_terminal']['status'] );
	}

	public function test_watchdog_unknown_intent_invalidates_the_superseded_publisher_lease(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-watchdog-publisher-lease-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'publishing' );
		$claim                              = $this->jobs->claim_for_test( $status['job_id'] );
		$outcome                            = $this->jobs->execute_for_test( $claim['job'] );

		$this->jobs->advance( 91 );
		$this->jobs->lock_probe = true;
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$this->jobs->watchdog( $status['job_id'] );
		}
		$unknown = $this->jobs->raw_job( $status['job_id'] );
		$this->assertSame( 'outcome_unknown', $unknown['pending_terminal']['status'] );
		$this->assertSame( '', $unknown['lease_token'] );
		$this->assertSame( 'pricing-apply-outcome-unknown:' . $status['job_id'], $unknown['outbox']['event_id'] );

		$stale = $this->jobs->complete_for_test( $status['job_id'], $claim['lease_token'], $outcome );
		$this->assertInstanceOf( WP_Error::class, $stale );
		$this->assertSame( 'digitalogic_pricing_apply_lease_lost', $stale->get_error_code() );
		$this->jobs->run_job( $status['job_id'] );
		$terminal = $this->jobs->get( $status['job_id'], $this->source );

		$this->assertSame( 'outcome_unknown', $terminal['status'] );
		$this->assertTrue( $terminal['terminal'] );
		$this->assertSame( 'emitted', $terminal['event_delivery']['state'] );
		$this->assertSame( 'pricing-apply:' . $status['job_id'], $this->jobs->publish_invocations[0] );
		$this->assertSame( 'pricing-apply-outcome-unknown:' . $status['job_id'], end( $this->jobs->publish_invocations ) );
		$this->assertSame( 1, $this->jobs->publish_effects );
	}

	public function test_delivery_exhaustion_still_persists_an_outcome_unknown_event(): void {
		$this->set_scope_size( 10 );
		$this->jobs->publish_failures_remaining = 12;
		$status                                 = $this->admit( 'request-outbox-exhaust-0001' );
		$status                                 = $this->finish( $status['job_id'] );

		$this->assertSame( 'outcome_unknown', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertSame( 'emitted', $status['event_delivery']['state'] );
		$this->assertSame( 13, count( $this->jobs->publish_invocations ) );
		$this->assertSame( 1, $this->jobs->publish_effects );
	}

	public function test_unknown_intent_is_immutable_across_a_crashed_delivery_and_deadline(): void {
		$this->set_scope_size( 10 );
		$this->jobs->await_ack_on_completed = true;
		$status                             = $this->admit( 'request-immutable-unknown-0001' );
		$status                             = $this->run_until_phase( $status['job_id'], 'awaiting_ack' );
		$confirmation                      = $status['confirmation'];
		$confirmation['status']            = 'acknowledged';
		$this->jobs->acknowledge_confirmation( $status['job_id'], $confirmation['transaction_id'], $confirmation );
		$conflicting                     = $confirmation;
		$conflicting['current_revision'] = Digitalogic_Pricing_Apply_Jobs_Harness::revision( '6' );
		$this->jobs->publish_result_override = array( 'confirmation' => $conflicting );

		for ( $attempt = 0; $attempt < 20; ++$attempt ) {
			$pending = $this->jobs->raw_job( $status['job_id'] );
			if ( 'outcome_unknown' === (string) ( $pending['pending_terminal']['status'] ?? '' ) ) {
				break;
			}
			$this->jobs->run_job( $status['job_id'] );
		}
		$pending       = $this->jobs->raw_job( $status['job_id'] );
		$unknown_event = $pending['outbox']['event_id'];
		$unknown_reason = $pending['pending_terminal']['reason'];
		$this->assertSame( 'outcome_unknown', $pending['pending_terminal']['status'] );
		$this->assertSame( 'event_delivery_readback_required', $unknown_reason );

		$claim   = $this->jobs->claim_for_test( $status['job_id'] );
		$outcome = $this->jobs->execute_for_test( $claim['job'] );
		$this->assertTrue( $outcome['ok'] );
		$this->jobs->advance( $pending['recovery_deadline_at'] - $this->jobs->clock + 1 );
		$this->jobs->run_job( $status['job_id'] );
		$terminal = $this->jobs->get( $status['job_id'], $this->source );
		$stored   = $this->jobs->raw_job( $status['job_id'] );

		$this->assertSame( 'outcome_unknown', $terminal['status'] );
		$this->assertTrue( $terminal['terminal'] );
		$this->assertSame( $unknown_event, $terminal['event_delivery']['event_id'] );
		$this->assertSame( $unknown_reason, $terminal['terminal_reason'] );
		$this->assertSame( $unknown_reason, $stored['delivery_result']['terminal_reason'] );
		$unknown_reasons = array();
		foreach ( $this->jobs->published_snapshots as $snapshot ) {
			if ( hash_equals( $unknown_event, (string) $snapshot['outbox']['event_id'] ) ) {
				$unknown_reasons[] = (string) $snapshot['pending_terminal']['reason'];
			}
		}
		$this->assertSame( array( $unknown_reason ), array_values( array_unique( $unknown_reasons ) ) );
	}

	public function test_compact_tombstone_preserves_exact_replay_after_job_capacity_pruning(): void {
		$this->set_scope_size( 1 );
		$this->jobs->await_ack_on_completed = true;
		$first_expected                     = $this->jobs->original_revision;
		$first                              = $this->admit( 'request-capacity-0001' );
		$first_waiting                      = $this->run_until_phase( $first['job_id'], 'awaiting_ack' );
		$first_confirmation                 = $first_waiting['confirmation'];
		$first_confirmation['status']       = 'acknowledged';
		$this->jobs->acknowledge_confirmation( $first['job_id'], $first_confirmation['transaction_id'], $first_confirmation );
		$this->finish( $first['job_id'] );

		$this->jobs->original_revision         = $this->jobs->canonical['state_revision'];
		$this->jobs->desired_revision          = $this->jobs->canonical['state_revision'];
		$this->jobs->original_catalog_revision = $this->jobs->canonical['shipping']['catalog_revision'];
		$this->jobs->desired_catalog_revision  = $this->jobs->canonical['shipping']['catalog_revision'];
		$this->jobs->original_settings         = $this->jobs->canonical['settings'];
		$second                                = $this->admit( 'request-capacity-0002' );
		$second_waiting                        = $this->run_until_phase( $second['job_id'], 'awaiting_ack' );
		$second_transaction                    = $second_waiting['confirmation']['transaction_id'];
		$this->jobs->request_confirmation_rollback( $second['job_id'], $second_transaction );
		$this->finish( $second['job_id'] );
		$this->jobs->await_ack_on_completed = false;

		for ( $index = 3; $index <= 66; ++$index ) {
			$this->jobs->original_revision         = $this->jobs->canonical['state_revision'];
			$this->jobs->desired_revision          = $this->jobs->canonical['state_revision'];
			$this->jobs->original_catalog_revision = $this->jobs->canonical['shipping']['catalog_revision'];
			$this->jobs->desired_catalog_revision  = $this->jobs->canonical['shipping']['catalog_revision'];
			$this->jobs->original_settings         = $this->jobs->canonical['settings'];
			$status                                = $this->admit( sprintf( 'request-capacity-%04d', $index ) );
			$this->finish( $status['job_id'] );
		}

		$registry = get_option( Digitalogic_Pricing_Apply_Jobs::REGISTRY_OPTION, array() );
		$this->assertCount( 64, $registry['jobs'] );
		$this->assertArrayHasKey( 'request-capacity-0001', $registry['tombstones'] );
		$this->assertArrayHasKey( 'request-capacity-0002', $registry['tombstones'] );
		$this->assertArrayNotHasKey( $first['job_id'], $registry['jobs'] );
		$this->assertArrayNotHasKey( $second['job_id'], $registry['jobs'] );

		$this->jobs->live_reads_fail = true;
		$replay                      = $this->jobs->replay(
			$this->settings,
			$first_expected,
			'request-capacity-0001',
			$this->binding,
			$this->context
		);
		$this->assertIsArray( $replay );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $first['job_id'], $replay['job_id'] );
		$this->assertSame( 'completed', $replay['status'] );

		$by_job = $this->jobs->get( $first['job_id'], $this->source );
		$this->assertTrue( $by_job['replayed'] );
		$this->assertSame( $first['job_id'], $by_job['job_id'] );
		$first_confirmation['status'] = 'replayed';
		$ack_replay                   = $this->jobs->acknowledge_confirmation( $first['job_id'], $first_confirmation['transaction_id'], $first_confirmation );
		$this->assertTrue( $ack_replay['replayed'] );
		$this->assertSame( 'completed', $ack_replay['status'] );
		$timeout_replay = $this->jobs->request_confirmation_rollback( $second['job_id'], $second_transaction );
		$this->assertTrue( $timeout_replay['replayed'] );
		$this->assertSame( 'rolled_back', $timeout_replay['status'] );
		$timeout_conflict = $this->jobs->request_confirmation_rollback( $second['job_id'], $second_transaction, 'different_reason' );
		$this->assertInstanceOf( WP_Error::class, $timeout_conflict );
		$this->assertSame( 'digitalogic_pricing_apply_confirmation_closed', $timeout_conflict->get_error_code() );

		$conflict = $this->jobs->replay(
			array_merge( $this->settings, array( 'yuan_price' => '32000' ) ),
			$first_expected,
			'request-capacity-0001',
			$this->binding,
			$this->context
		);
		$this->assertInstanceOf( WP_Error::class, $conflict );
		$this->assertSame( 'digitalogic_pricing_apply_request_id_conflict', $conflict->get_error_code() );
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

		$status = $this->jobs->get(
			$job_id,
			array(
				'id'      => $this->source['id'],
				'dataset' => $this->source['dataset'],
			)
		);
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
		$source = array(
			'id'      => $this->source['id'],
			'dataset' => $this->source['dataset'],
		);
		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			$status = $this->jobs->get( $job_id, $source );
			if ( ! empty( $status['terminal'] ) ) {
				return $status;
			}
			$this->jobs->run_job( $job_id );
		}

		$this->fail( 'Pricing apply job did not reach a finite terminal state.' );
	}

	private function run_until_phase( $job_id, $phase ) {
		$source = array(
			'id'      => $this->source['id'],
			'dataset' => $this->source['dataset'],
		);
		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			$status = $this->jobs->get( $job_id, $source );
			if ( (string) $phase === (string) ( $status['phase'] ?? '' ) ) {
				return $status;
			}
			$this->jobs->run_job( $job_id );
		}

		$this->fail( 'Pricing apply job did not reach the expected phase.' );
	}

	private function mark_lost_claim( $job_id ) {
		$now = $this->jobs->clock;
		$this->jobs->mutate_job(
			$job_id,
			static function ( $job ) use ( $now ) {
				$job['lease_token']      = 'lost-worker-token';
				$job['lease_expires_at'] = $now - 1;
				$job['heartbeat_at']     = $now - 100;

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
