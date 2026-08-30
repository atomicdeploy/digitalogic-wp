<?php
/**
 * Durable, bounded pricing-apply jobs.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies one validated pricing document as a finite, receipt-backed saga.
 */
class Digitalogic_Pricing_Apply_Jobs {
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Private saga helpers operate on one documented durable record shape.

	public const REGISTRY_OPTION = 'digitalogic_pricing_apply_jobs';
	public const WORKER_HOOK     = 'digitalogic_pricing_apply_job_run';
	public const WATCHDOG_HOOK   = 'digitalogic_pricing_apply_job_watchdog';

	private const ACTION_GROUP              = 'digitalogic-pricing-apply';
	private const LOCK_NAME                 = 'digitalogic_pricing_apply_jobs';
	private const BATCH_SIZE                = 25;
	private const LEASE_SECONDS             = 45;
	private const HEARTBEAT_GRACE_SECONDS   = 90;
	private const JOB_DEADLINE_SECONDS      = 900;
	private const RECOVERY_DEADLINE_SECONDS = 300;
	private const MAX_PHASE_ATTEMPTS        = 3;
	private const MAX_OUTBOX_ATTEMPTS       = 10;
	private const MAX_WATCHDOG_FAILURES     = 3;
	private const MAX_SCHEDULE_FAILURES     = 3;
	private const ADMISSION_ARM_GRACE       = 15;
	private const TERMINAL_RETENTION        = 604800;
	private const MAX_JOBS                  = 64;

	/**
	 * Shared production instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Register worker and recovery hooks.
	 *
	 * @param bool $register_hooks Whether hooks should be registered.
	 */
	public function __construct( $register_hooks = true ) {
		if ( $register_hooks ) {
			add_action( self::WORKER_HOOK, array( $this, 'run_job' ), 10, 1 );
			add_action( self::WATCHDOG_HOOK, array( $this, 'watchdog' ), 10, 1 );
			add_action( 'init', array( $this, 'recover_unarmed_admissions' ), 20 );
		}
	}

	/** Return the shared production instance. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Remove only queued pricing-apply wakes while preserving durable state.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::WORKER_HOOK, array(), self::ACTION_GROUP );
			as_unschedule_all_actions( self::WATCHDOG_HOOK, array(), self::ACTION_GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::WORKER_HOOK );
			wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
		}
	}

	/**
	 * Resolve an exact prior admission before any live validation.
	 *
	 * @param array  $settings          Complete requested settings.
	 * @param string $expected_revision Expected canonical revision.
	 * @param string $request_id        Exact idempotency identity.
	 * @param array  $binding           Immutable preview/source binding.
	 * @param array  $request_context   Bounded caller context.
	 * @return array|WP_Error|null
	 */
	public function replay( $settings, $expected_revision, $request_id, $binding, $request_context = array() ) {
		$request = $this->normalize_request( $settings, $expected_revision, $request_id, $binding, $request_context );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$existing = $this->with_registry_lock(
			function ( $registry ) use ( $request ) {
				return $this->existing_request( $registry, $request );
			}
		);
		if ( is_wp_error( $existing ) || null === $existing ) {
			return $existing;
		}

		return $this->ensure_admission_armed( $existing );
	}

	/**
	 * Persist a new pricing apply before scheduling any worker.
	 *
	 * @param array  $settings          Complete requested settings.
	 * @param string $expected_revision Expected canonical revision.
	 * @param string $request_id        Exact idempotency identity.
	 * @param array  $binding           Immutable preview/source binding.
	 * @param array  $metadata          Validated live preview/source metadata.
	 * @param array  $request_context   Bounded caller context.
	 * @return array|WP_Error
	 */
	public function admit( $settings, $expected_revision, $request_id, $binding, $metadata, $request_context = array() ) {
		$request = $this->normalize_request( $settings, $expected_revision, $request_id, $binding, $request_context );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$prior = $this->with_registry_lock(
			function ( $registry ) use ( $request ) {
				$existing = $this->existing_request( $registry, $request );
				return null !== $existing ? $existing : $this->fresh_admission_blocker( $registry );
			}
		);
		if ( is_wp_error( $prior ) ) {
			return $prior;
		}
		if ( is_array( $prior ) ) {
			return $this->ensure_admission_armed( $prior );
		}

		$metadata = $this->normalize_metadata( $metadata );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		if ( ! $this->same_source_scope( $request['operation_binding']['source'], $metadata['source_context'] ) ) {
			return $this->error( 'digitalogic_pricing_apply_source_conflict', 'Pricing apply metadata does not match its bound source.', 400 );
		}

		$prepared = $this->prepare_apply( $request['settings'], $request['expected_state_revision'] );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$scope = $this->normalized_scope( $this->read_pricing_scope( $request['operation_binding']['source'] ) );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		if ( (int) $scope['pending_products'] > 0 || (int) $scope['deferred_ambiguous'] > 0 ) {
			return $this->error(
				'digitalogic_pricing_apply_integrity_blocked',
				'Pricing integrity must be current before an apply job can start.',
				409,
				array(
					'pending_products'   => (int) $scope['pending_products'],
					'deferred_ambiguous' => (int) $scope['deferred_ambiguous'],
				)
			);
		}
		if ( empty( $scope['codes'] ) ) {
			return $this->error( 'digitalogic_pricing_apply_scope_empty', 'No managed Product Codes are available.', 409 );
		}
		$source_matches = false;
		foreach ( $scope['sources'] as $scope_source ) {
			if ( is_array( $scope_source ) && $this->same_source_scope( $request['operation_binding']['source'], $scope_source ) ) {
				$source_matches = true;
				break;
			}
		}
		if ( ! $source_matches ) {
			return $this->error( 'digitalogic_pricing_apply_source_scope_changed', 'The validated source is not present in the managed pricing scope.', 409 );
		}
		$ack_consumer = $this->normalized_ack_consumer(
			$this->read_ack_consumer( $request['operation_binding']['source'] ),
			$request['operation_binding']['source']
		);
		if ( is_wp_error( $ack_consumer ) ) {
			return $ack_consumer;
		}

		$now    = $this->now();
		$job_id = $this->new_job_id();
		$job    = array(
			'schema'                    => 'digitalogic.pricing-apply-job-record',
			'job_id'                    => $job_id,
			'request_id'                => $request['request_id'],
			'request_fingerprint'       => $request['request_fingerprint'],
			'operation_fingerprint'     => $request['operation_fingerprint'],
			'operation_binding'         => $request['operation_binding'],
			'operation_metadata'        => $metadata,
			'request_context'           => $request['request_context'],
			'expected_state_revision'   => $request['expected_state_revision'],
			'previous_settings'         => $prepared['previous_settings'],
			'desired_settings'          => $prepared['settings'],
			'settings_changed'          => (bool) $prepared['settings_changed'],
			'previous_catalog_revision' => $prepared['previous_catalog_revision'],
			'source_pin'                => array(
				'state_revision' => $scope['state_revision'],
				'code_digest'    => $scope['code_digest'],
				'row_count'      => (int) $scope['row_count'],
				'sources'        => $scope['sources'],
			),
			'ack_consumer'              => $ack_consumer,
			'codes'                     => $scope['codes'],
			'status'                    => 'queued',
			'phase'                     => 'settings',
			'created_at'                => $now,
			'updated_at'                => $now,
			'heartbeat_at'              => $now,
			'deadline_at'               => $now + self::JOB_DEADLINE_SECONDS,
			'recovery_deadline_at'      => $now + self::JOB_DEADLINE_SECONDS + self::RECOVERY_DEADLINE_SECONDS,
			'lease_token'               => '',
			'lease_expires_at'          => 0,
			'phase_resolution_required' => false,
			'phase_attempts'            => 0,
			'watchdog_failures'         => 0,
			'schedule_failures'         => 0,
			'cancel_requested'          => false,
			'cancel_requested_at'       => 0,
			'settings_committed'        => false,
			'settings_restored'         => false,
			'settings_phase'            => null,
			'expected_scope_revision'   => $scope['state_revision'],
			'forward_cursor'            => 0,
			'rollback_cursor'           => 0,
			'aggregate'                 => $this->empty_aggregate(),
			'recovery_reason'           => '',
			'last_error'                => null,
			'pending_terminal'          => null,
			'terminal_result'           => null,
			'delivery_result'           => null,
			'confirmation'              => null,
			'confirmation_signal'       => null,
			'outbox'                    => array(
				'event_id'   => 'pricing-apply:' . $job_id,
				'state'      => 'not_ready',
				'attempts'   => 0,
				'emitted_at' => 0,
			),
			'schedule_state'            => 'unarmed',
			'schedule_token'            => '',
			'schedule_lease_expires_at' => 0,
		);

		$admitted = $this->with_registry_lock(
			function ( $registry ) use ( $request, $job ) {
				$existing = $this->existing_request( $registry, $request );
				if ( null !== $existing ) {
					return $existing;
				}
				$blocked = $this->fresh_admission_blocker( $registry );
				if ( is_wp_error( $blocked ) ) {
					return $blocked;
				}
				$registry['jobs'][ $job['job_id'] ]             = $job;
				$registry['requests'][ $request['request_id'] ] = array(
					'job_id'              => $job['job_id'],
					'request_fingerprint' => $request['request_fingerprint'],
				);
				$stored = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job, array( 'accepted' => true ) );
			}
		);
		if ( is_wp_error( $admitted ) ) {
			return $admitted;
		}
		if ( empty( $admitted['accepted'] ) ) {
			return $this->ensure_admission_armed( $admitted );
		}

		$armed = $this->arm_admission( $job_id );
		if ( is_wp_error( $armed ) ) {
			return $armed;
		}

		return array_merge( $armed, array( 'accepted' => ! empty( $armed['accepted'] ) ) );
	}

	/**
	 * Return one exact source-scoped status.
	 *
	 * @param string $identifier Job or request identity.
	 * @param array  $source     Authenticated source scope.
	 * @return array|WP_Error
	 */
	public function get( $identifier, $source ) {
		$identifier = $this->normalize_identifier( $identifier );
		if ( is_wp_error( $identifier ) ) {
			return $identifier;
		}
		$source = $this->normalize_auth_source( $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return $this->with_registry_lock(
			function ( $registry ) use ( $identifier, $source ) {
				$located = $this->locate_job( $registry, $identifier );
				if ( is_wp_error( $located ) ) {
					return $located;
				}
				if ( is_array( $located['tombstone'] ?? null ) ) {
					if ( ! $this->same_source_scope( $located['tombstone']['source'] ?? array(), $source ) ) {
						return $this->source_not_found_error();
					}

					return $this->tombstone_status( $located['tombstone'], array( 'replayed' => true ) );
				}
				if ( ! $this->same_source_scope( $located['job']['operation_binding']['source'] ?? array(), $source ) ) {
					return $this->source_not_found_error();
				}

				return $this->status_payload( $located['job'] );
			}
		);
	}

	/**
	 * Cooperatively cancel one exact source-scoped job.
	 *
	 * @param string $identifier Job or request identity.
	 * @param array  $source     Authenticated source scope.
	 * @return array|WP_Error
	 */
	public function cancel( $identifier, $source ) {
		$identifier = $this->normalize_identifier( $identifier );
		if ( is_wp_error( $identifier ) ) {
			return $identifier;
		}
		$source = $this->normalize_auth_source( $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$status = $this->with_registry_lock(
			function ( $registry ) use ( $identifier, $source ) {
				$located = $this->locate_job( $registry, $identifier );
				if ( is_wp_error( $located ) ) {
					return $located;
				}
				if ( is_array( $located['tombstone'] ?? null ) ) {
					if ( ! $this->same_source_scope( $located['tombstone']['source'] ?? array(), $source ) ) {
						return $this->source_not_found_error();
					}

					return $this->tombstone_status( $located['tombstone'], array( 'replayed' => true ) );
				}
				$job = $located['job'];
				if ( ! $this->same_source_scope( $job['operation_binding']['source'] ?? array(), $source ) ) {
					return $this->source_not_found_error();
				}
				if ( $this->is_terminal( $job ) ) {
					return $this->status_payload( $job, array( 'replayed' => true ) );
				}

				$job['cancel_requested']    = true;
				$job['cancel_requested_at'] = $this->now();
				$job['updated_at']          = $this->now();
				if (
					'publishing' !== (string) $job['phase']
					&& 'awaiting_ack' !== (string) $job['phase']
					&& '' === (string) ( $job['lease_token'] ?? '' )
					&& empty( $job['phase_resolution_required'] )
				) {
					$job = $this->move_to_recovery_or_publish( $job, 'cancelled' );
				}
				$registry['jobs'][ $job['job_id'] ] = $job;
				$stored                             = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
			}
		);
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		if ( ! empty( $status['terminal'] ) ) {
			$this->unschedule_job( (string) $status['job_id'] );
			return $status;
		}
		if ( 'awaiting_ack' === (string) ( $status['phase'] ?? '' ) ) {
			return $status;
		}
		$schedule_fence = $this->schedule_failure_fence( (string) $status['job_id'] );
		if ( ! $this->schedule_worker( (string) $status['job_id'], 1 ) ) {
			$recorded = $this->record_schedule_failure( (string) $status['job_id'], 'worker', $schedule_fence );
			return is_array( $recorded ) ? $recorded : $status;
		}

		return $status;
	}

	/**
	 * Resume a waiting job after the exact Excel confirmation is acknowledged.
	 *
	 * @param string $job_id         Durable job identity.
	 * @param string $transaction_id Exact confirmation transaction identity.
	 * @param array  $confirmation   Durable acknowledgement projection.
	 * @return array|WP_Error
	 */
	public function acknowledge_confirmation( $job_id, $transaction_id, $confirmation ) {
		$job_id         = $this->normalize_job_id( $job_id );
		$transaction_id = $this->normalize_confirmation_id( $transaction_id );
		if ( is_wp_error( $job_id ) || is_wp_error( $transaction_id ) ) {
			return is_wp_error( $job_id ) ? $job_id : $transaction_id;
		}
		$confirmation = $this->normalize_confirmation_projection( $confirmation, $transaction_id );
		if ( is_wp_error( $confirmation ) ) {
			return $confirmation;
		}
		if ( 'replayed' === (string) $confirmation['status'] ) {
			$confirmation['status'] = 'acknowledged';
		}

		$status = $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $transaction_id, $confirmation ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					$tombstone = $this->tombstone_by_job( $registry, $job_id );
					if ( ! is_array( $tombstone ) ) {
						return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
					}
					$closed = $tombstone['payload']['confirmation'] ?? null;
					if ( ! is_array( $closed ) || ! hash_equals( (string) ( $closed['transaction_id'] ?? '' ), $transaction_id ) ) {
						return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation does not match this job.', 409 );
					}

					return 'acknowledged' === (string) ( $closed['status'] ?? '' )
						&& 'acknowledged' === (string) $confirmation['status']
						&& $this->same_confirmation_identity( $closed, $confirmation )
						? $this->tombstone_status( $tombstone, array( 'replayed' => true ) )
						: $this->error( 'digitalogic_pricing_apply_confirmation_closed', 'Pricing confirmation is already closed.', 409 );
				}
				$stored_id = (string) ( $job['confirmation']['transaction_id'] ?? '' );
				if ( '' === $stored_id ) {
					$early_ack = 'publishing' === (string) $job['phase']
						&& 'completed' === (string) ( $job['pending_terminal']['status'] ?? '' )
						&& 'acknowledged' === (string) $confirmation['status']
						&& $this->confirmation_matches_consumer( $confirmation, $job['ack_consumer'] ?? array() )
						&& $this->confirmation_matches_settings_phase( $confirmation, $job );
					if ( ! $early_ack ) {
						return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation does not match this job.', 409 );
					}
					$job['confirmation']         = $confirmation;
					$job['recovery_deadline_at'] = max( (int) $job['recovery_deadline_at'], (int) $confirmation['recovery_deadline'] );
					$registry['jobs'][ $job_id ] = $job;
					$stored                      = $this->save_registry( $registry );

					return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
				}
				if ( ! hash_equals( $stored_id, $transaction_id ) ) {
					return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation does not match this job.', 409 );
				}
				if (
					'acknowledged' !== (string) $confirmation['status']
					|| ! $this->confirmation_matches_consumer( $confirmation, $job['ack_consumer'] ?? array() )
					|| ! $this->same_confirmation_identity( $job['confirmation'], $confirmation )
				) {
					return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing acknowledgement changed its staged identity.', 409 );
				}
				if ( $this->is_terminal( $job ) ) {
					if ( 'acknowledged' === (string) ( $job['confirmation']['status'] ?? '' ) ) {
						return $this->status_payload( $job, array( 'replayed' => true ) );
					}

					return $this->error( 'digitalogic_pricing_apply_confirmation_closed', 'Pricing confirmation is already closed.', 409 );
				}
				if (
					'publishing' === (string) $job['phase']
					&& 'completed' === (string) ( $job['pending_terminal']['status'] ?? '' )
					&& 'acknowledged' === (string) ( $job['confirmation']['status'] ?? '' )
				) {
					return $this->status_payload( $job, array( 'replayed' => true ) );
				}
				if ( 'awaiting_ack' !== (string) $job['phase'] ) {
					return $this->error( 'digitalogic_pricing_apply_confirmation_not_waiting', 'Pricing apply is not awaiting acknowledgement.', 409 );
				}

				$job['confirmation']['status']          = 'acknowledged';
				$job['terminal_result']                 = is_array( $job['terminal_result'] ?? null ) ? $job['terminal_result'] : array();
				$job['terminal_result']['confirmation'] = $job['confirmation'];
				$job                                    = $this->queue_terminal_publish( $job, 'completed', 'completed', null );
				$registry['jobs'][ $job_id ]            = $job;
				$stored                                 = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
			}
		);
		if ( is_wp_error( $status ) || ! is_array( $status ) || ! empty( $status['terminal'] ) ) {
			return $status;
		}
		$schedule_fence = $this->schedule_failure_fence( $job_id );
		if ( ! $this->schedule_worker( $job_id, 1 ) ) {
			$recorded = $this->record_schedule_failure( $job_id, 'worker', $schedule_fence );
			return is_array( $recorded ) ? $recorded : $status;
		}

		return $status;
	}

	/**
	 * Resume a waiting job through its existing bounded compensation phases.
	 *
	 * @param string $job_id         Durable job identity.
	 * @param string $transaction_id Exact confirmation transaction identity.
	 * @param string $reason         Stable recovery reason.
	 * @return array|WP_Error
	 */
	public function request_confirmation_rollback( $job_id, $transaction_id, $reason = 'excel_ack_timeout' ) {
		$job_id         = $this->normalize_job_id( $job_id );
		$transaction_id = $this->normalize_confirmation_id( $transaction_id );
		$reason         = sanitize_key( (string) $reason );
		if ( is_wp_error( $job_id ) || is_wp_error( $transaction_id ) ) {
			return is_wp_error( $job_id ) ? $job_id : $transaction_id;
		}
		if ( '' === $reason ) {
			$reason = 'excel_ack_timeout';
		}

		$status = $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $transaction_id, $reason ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					$tombstone = $this->tombstone_by_job( $registry, $job_id );
					if ( ! is_array( $tombstone ) ) {
						return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
					}
					$closed = $tombstone['payload']['confirmation'] ?? null;
					if ( ! is_array( $closed ) || ! hash_equals( (string) ( $closed['transaction_id'] ?? '' ), $transaction_id ) ) {
						return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation does not match this job.', 409 );
					}

					return in_array( (string) ( $tombstone['payload']['status'] ?? '' ), array( 'rolled_back', 'cancelled' ), true )
						&& hash_equals( (string) ( $tombstone['payload']['terminal_reason'] ?? '' ), $reason )
						? $this->tombstone_status( $tombstone, array( 'replayed' => true ) )
						: $this->error( 'digitalogic_pricing_apply_confirmation_closed', 'Pricing confirmation is already closed.', 409 );
				}
				$stored_id     = (string) ( $job['confirmation']['transaction_id'] ?? '' );
				$early_timeout = '' === $stored_id
					&& 'publishing' === (string) $job['phase']
					&& 'completed' === (string) ( $job['pending_terminal']['status'] ?? '' );
				if ( ! $early_timeout && ( '' === $stored_id || ! hash_equals( $stored_id, $transaction_id ) ) ) {
					return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation does not match this job.', 409 );
				}
				if ( $this->is_terminal( $job ) ) {
					if (
						in_array( (string) $job['status'], array( 'rolled_back', 'cancelled' ), true )
						&& hash_equals( (string) ( $job['terminal_reason'] ?? '' ), $reason )
					) {
						return $this->status_payload( $job, array( 'replayed' => true ) );
					}

					return $this->error( 'digitalogic_pricing_apply_confirmation_closed', 'Pricing confirmation is already closed.', 409 );
				}
				if ( ! $early_timeout && 'awaiting_ack' !== (string) $job['phase'] ) {
					$rollback_pending = 'publishing' === (string) $job['phase'] && in_array( (string) ( $job['pending_terminal']['status'] ?? '' ), array( 'rolled_back', 'cancelled' ), true );
					if ( ( 'recovering' === (string) $job['status'] || $rollback_pending ) && hash_equals( (string) $job['recovery_reason'], $reason ) ) {
						return $this->status_payload( $job, array( 'replayed' => true ) );
					}

					return $this->error( 'digitalogic_pricing_apply_confirmation_not_waiting', 'Pricing apply is not awaiting acknowledgement.', 409 );
				}

				if ( $early_timeout ) {
					$signal = is_array( $job['confirmation_signal'] ?? null ) ? $job['confirmation_signal'] : null;
					if ( is_array( $signal ) ) {
						if (
							'rollback' === (string) ( $signal['type'] ?? '' )
							&& hash_equals( (string) ( $signal['transaction_id'] ?? '' ), $transaction_id )
							&& hash_equals( (string) ( $signal['reason'] ?? '' ), $reason )
						) {
							return $this->status_payload( $job, array( 'replayed' => true ) );
						}

						return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'A different confirmation signal is already pending.', 409 );
					}
					$job['confirmation_signal']  = array(
						'type'           => 'rollback',
						'transaction_id' => $transaction_id,
						'reason'         => $reason,
					);
					$job['recovery_deadline_at'] = max( (int) $job['recovery_deadline_at'], $this->now() + self::RECOVERY_DEADLINE_SECONDS );
					$job['updated_at']           = $this->now();
					$registry['jobs'][ $job_id ] = $job;
					$stored                      = $this->save_registry( $registry );

					return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
				}
				$job['confirmation']['status'] = 'rollback_requested';
				$job['recovery_reason']        = $reason;
				$job['status']                 = 'recovering';
				$job['phase']                  = $job['settings_restored'] ? 'rollback_products' : 'rollback_settings';
				$job['phase_attempts']         = 0;
				$job['pending_terminal']       = null;
				$job['outbox']['state']        = 'not_ready';
				$job['updated_at']             = $this->now();
				$registry['jobs'][ $job_id ]   = $job;
				$stored                        = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
			}
		);
		if ( is_wp_error( $status ) || ! is_array( $status ) || ! empty( $status['terminal'] ) ) {
			return $status;
		}
		$schedule_fence = $this->schedule_failure_fence( $job_id );
		if ( ! $this->schedule_worker( $job_id, 1 ) ) {
			$recorded = $this->record_schedule_failure( $job_id, 'worker', $schedule_fence );
			return is_array( $recorded ) ? $recorded : $status;
		}

		return $status;
	}

	/** Execute at most one externally effectful phase. */
	public function run_job( $job_id ) {
		$claim = $this->claim_job( (string) $job_id );
		if ( is_wp_error( $claim ) || ! is_array( $claim ) || empty( $claim['claimed'] ) ) {
			return;
		}
		if ( ! empty( $claim['terminal'] ) ) {
			$this->unschedule_job( (string) $job_id );
			return;
		}

		$outcome = $this->execute_phase( $claim['job'] );
		$status  = $this->complete_claim( $claim['job']['job_id'], $claim['lease_token'], $outcome );
		if ( is_wp_error( $status ) || ! is_array( $status ) ) {
			return;
		}
		if ( ! empty( $status['terminal'] ) ) {
			$this->unschedule_job( (string) $job_id );
			return;
		}
		if ( 'awaiting_ack' === (string) ( $status['phase'] ?? '' ) ) {
			return;
		}
		$schedule_fence = $this->schedule_failure_fence( (string) $job_id );
		if ( ! $this->schedule_worker( (string) $job_id, 1 ) ) {
			$this->record_schedule_failure( (string) $job_id, 'worker', $schedule_fence );
		}
	}

	/**
	 * Recover unarmed, stalled, and terminal-delivery work independently.
	 */
	public function recover_unarmed_admissions() {
		$cached = get_option( self::REGISTRY_OPTION, array() );
		if ( ! is_array( $cached ) || empty( $cached['jobs'] ) ) {
			return;
		}

		$candidates = $this->with_registry_lock(
			function ( $registry ) {
				$now        = $this->now();
				$unarmed    = array();
				$stalled    = array();
				$deliveries = array();
				foreach ( $registry['jobs'] as $job_id => $job ) {
					if ( ! is_array( $job ) || $this->is_terminal( $job ) ) {
						continue;
					}
					if ( 'awaiting_ack' === (string) ( $job['phase'] ?? '' ) ) {
						continue;
					}
					if (
						'armed' !== (string) ( $job['schedule_state'] ?? '' )
						&& (int) ( $job['schedule_lease_expires_at'] ?? 0 ) <= $now
						&& count( $unarmed ) < 5
					) {
						$unarmed[] = (string) $job_id;
					}
					if (
						(int) ( $job['lease_expires_at'] ?? 0 ) <= $now
						&& (int) ( $job['heartbeat_at'] ?? 0 ) + self::HEARTBEAT_GRACE_SECONDS <= $now
						&& count( $stalled ) < 5
					) {
						$stalled[] = (string) $job_id;
					}
					if (
						'publishing' === (string) ( $job['phase'] ?? '' )
						&& (int) ( $job['lease_expires_at'] ?? 0 ) <= $now
						&& count( $deliveries ) < 5
					) {
						$deliveries[] = (string) $job_id;
					}
				}

				return compact( 'unarmed', 'stalled', 'deliveries' );
			}
		);
		if ( is_wp_error( $candidates ) || ! is_array( $candidates ) ) {
			return;
		}
		foreach ( (array) $candidates['unarmed'] as $job_id ) {
			$this->arm_admission( $job_id );
		}
		foreach ( (array) $candidates['stalled'] as $job_id ) {
			$this->watchdog( $job_id );
		}
		foreach ( (array) $candidates['deliveries'] as $job_id ) {
			$schedule_fence = $this->schedule_failure_fence( $job_id );
			if ( ! $this->schedule_worker( $job_id, 1 ) ) {
				$this->record_schedule_failure( $job_id, 'worker', $schedule_fence );
			}
		}
	}

	/**
	 * Resolve a killed worker without guessing whether a phase committed.
	 *
	 * @param string $job_id Exact durable job identity.
	 */
	public function watchdog( $job_id ) {
		$job_id = (string) $job_id;
		$check  = $this->with_registry_lock(
			function ( $registry ) use ( $job_id ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) || $this->is_terminal( $job ) ) {
					return array( 'terminal' => true );
				}
				if ( 'awaiting_ack' === (string) ( $job['phase'] ?? '' ) ) {
					return array(
						'terminal' => false,
						'stale'    => false,
						'waiting'  => true,
					);
				}
				$now = $this->now();
				if ( (int) $job['lease_expires_at'] > $now || (int) $job['heartbeat_at'] + self::HEARTBEAT_GRACE_SECONDS > $now ) {
					return array(
						'terminal' => false,
						'stale'    => false,
					);
				}

				return array(
					'terminal' => false,
					'stale'    => true,
				);
			}
		);
		if ( is_wp_error( $check ) || ! is_array( $check ) ) {
			$this->reschedule_watchdog( $job_id );
			return;
		}
		if ( ! empty( $check['terminal'] ) ) {
			$this->unschedule_job( $job_id );
			return;
		}
		if ( ! empty( $check['waiting'] ) ) {
			return;
		}
		if ( empty( $check['stale'] ) ) {
			$this->reschedule_watchdog( $job_id );
			return;
		}

		$probe = $this->probe_pricing_locks();
		$state = $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $probe ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) || $this->is_terminal( $job ) ) {
					return array( 'terminal' => true );
				}
				if ( 'awaiting_ack' === (string) ( $job['phase'] ?? '' ) ) {
					return array(
						'terminal' => false,
						'stale'    => false,
						'waiting'  => true,
					);
				}
				$now = $this->now();
				if ( (int) $job['heartbeat_at'] + self::HEARTBEAT_GRACE_SECONDS > $now ) {
					return array(
						'terminal' => false,
						'retry'    => false,
					);
				}
				if ( is_wp_error( $probe ) || true === $probe ) {
					++$job['watchdog_failures'];
					$job['last_error'] = $this->safe_error(
						is_wp_error( $probe )
							? $probe
							: $this->error( 'digitalogic_pricing_apply_lock_held', 'A pricing lock remains held.', 503, array( 'retryable' => true ) )
					);
					if ( (int) $job['watchdog_failures'] >= self::MAX_WATCHDOG_FAILURES || $now >= (int) $job['recovery_deadline_at'] ) {
						$job = $this->queue_outcome_unknown_publish( $job, 'readback_required', $job['last_error'] );
					}
				} else {
					$unacknowledged                   = ! empty( $job['lease_token'] );
					$job['watchdog_failures']         = 0;
					$job['lease_token']               = '';
					$job['lease_expires_at']          = 0;
					$job['heartbeat_at']              = $now;
					$job['phase_resolution_required'] = $unacknowledged || ! empty( $job['phase_resolution_required'] );
					if ( empty( $job['phase_resolution_required'] ) && 'publishing' !== (string) $job['phase'] ) {
						if ( ! empty( $job['cancel_requested'] ) ) {
							$job = $this->move_to_recovery_or_publish( $job, 'cancelled' );
						} elseif ( $now >= (int) $job['deadline_at'] ) {
							$job = $this->move_to_recovery_or_publish( $job, 'deadline_exceeded' );
						}
					}
				}
				$job['updated_at']           = $now;
				$registry['jobs'][ $job_id ] = $job;
				$stored                      = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : array(
					'terminal' => $this->is_terminal( $job ),
					'retry'    => ! $this->is_terminal( $job ),
				);
			}
		);
		if ( is_wp_error( $state ) || ! is_array( $state ) ) {
			$this->reschedule_watchdog( $job_id );
			return;
		}
		if ( ! empty( $state['terminal'] ) ) {
			$this->unschedule_job( $job_id );
			return;
		}
		if ( ! empty( $state['waiting'] ) ) {
			return;
		}
		if ( ! empty( $state['retry'] ) ) {
			$schedule_fence = $this->schedule_failure_fence( $job_id );
			if ( ! $this->schedule_worker( $job_id, 1 ) ) {
				$this->record_schedule_failure( $job_id, 'worker', $schedule_fence );
			}
		}
		$this->reschedule_watchdog( $job_id );
	}

	/** Claim one finite worker lease. */
	private function claim_job( $job_id ) {
		return $this->with_registry_lock(
			function ( $registry ) use ( $job_id ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
				}
				if ( $this->is_terminal( $job ) ) {
					return array(
						'claimed'  => true,
						'terminal' => true,
						'job'      => $job,
					);
				}
				if ( 'awaiting_ack' === (string) ( $job['phase'] ?? '' ) ) {
					return array( 'claimed' => false );
				}

				$now         = $this->now();
				$stale_claim = ! empty( $job['lease_token'] );
				if ( (int) $job['lease_expires_at'] > $now ) {
					return array( 'claimed' => false );
				}
				if ( $stale_claim ) {
					$job['phase_resolution_required'] = true;
				}
				if ( $now >= (int) $job['recovery_deadline_at'] ) {
					$job = $this->queue_outcome_unknown_publish(
						$job,
						'readback_required',
						$this->safe_error( $this->error( 'digitalogic_pricing_apply_recovery_deadline', 'Pricing apply recovery deadline expired.', 502 ) )
					);
				}
				if (
					empty( $job['phase_resolution_required'] )
					&& 'recovering' !== (string) $job['status']
					&& 'publishing' !== (string) $job['phase']
				) {
					if ( ! empty( $job['cancel_requested'] ) ) {
						$job = $this->move_to_recovery_or_publish( $job, 'cancelled' );
					} elseif ( $now >= (int) $job['deadline_at'] ) {
						$job = $this->move_to_recovery_or_publish( $job, 'deadline_exceeded' );
					}
				}

				$token = hash( 'sha256', $job_id . '|' . $now . '|' . $this->new_entropy() );
				if ( 'arming' === (string) ( $job['schedule_state'] ?? '' ) ) {
					$job['schedule_state'] = 'armed';
				}
				$job['schedule_token']            = '';
				$job['schedule_lease_expires_at'] = 0;
				$job['lease_token']               = $token;
				$job['lease_expires_at']          = $now + self::LEASE_SECONDS;
				$job['heartbeat_at']              = $now;
				$job['updated_at']                = $now;
				$job['schedule_failures']         = 0;
				if ( in_array( (string) $job['phase'], array( 'rollback_settings', 'rollback_products', 'rollback_verifying' ), true ) ) {
					$job['status'] = 'recovering';
				} elseif ( 'publishing' !== (string) $job['status'] ) {
					$job['status'] = 'running';
				}
				if ( 'publishing' === (string) $job['phase'] ) {
					$job['status']             = 'publishing';
					$job['outbox']['state']    = 'emitting';
					$job['outbox']['attempts'] = (int) $job['outbox']['attempts'] + 1;
				}
				$registry['jobs'][ $job_id ] = $job;
				$stored                      = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : array(
					'claimed'     => true,
					'terminal'    => false,
					'lease_token' => $token,
					'job'         => $job,
				);
			}
		);
	}

	/** Run one phase outside the registry lock. */
	private function execute_phase( $job ) {
		try {
			switch ( (string) $job['phase'] ) {
				case 'settings':
					if ( empty( $job['phase_resolution_required'] ) ) {
						$scope = $this->normalized_scope( $this->read_pricing_scope( $job['operation_binding']['source'] ) );
						if ( is_wp_error( $scope ) ) {
							return array(
								'ok'    => false,
								'error' => $scope,
							);
						}
						if ( ! $this->scope_matches_pin( $scope, $job['source_pin'] ) ) {
							return array(
								'ok'    => false,
								'error' => $this->error( 'digitalogic_pricing_apply_scope_changed', 'The pinned pricing scope changed before settings commit.', 409 ),
							);
						}
					}
					$result = $this->commit_settings_phase(
						$job['desired_settings'],
						$job['operation_binding']['source'],
						$job['expected_state_revision'],
						'pricing-apply-settings:' . $job['job_id'],
						$job['request_context'],
						$job['operation_metadata']['source_context']
					);
					if ( is_wp_error( $result ) ) {
						return array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => $this->error_requires_phase_resolution( $result ),
						);
					}
					$result = $this->normalize_settings_phase_result( $result, $job );

					return is_wp_error( $result )
						? array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => true,
						)
						: array(
							'ok'     => true,
							'kind'   => 'settings',
							'result' => $result,
						);

				case 'repricing':
					$batch = array_slice( $job['codes'], (int) $job['forward_cursor'], self::BATCH_SIZE );
					if ( empty( $batch ) ) {
						return array(
							'ok'   => true,
							'kind' => 'forward_complete',
						);
					}
					$index  = intdiv( (int) $job['forward_cursor'], self::BATCH_SIZE );
					$result = $this->reprice_batch(
						$job['desired_settings'],
						$batch,
						$job['settings_phase']['previous_catalog_revision'],
						$job['expected_scope_revision'],
						$job['source_pin']['code_digest'],
						'pricing-apply-forward:' . $job['job_id'] . ':' . $index,
						$job['operation_binding']['source']
					);
					if ( is_wp_error( $result ) ) {
						return array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => $this->error_requires_phase_resolution( $result ),
						);
					}
					$result = $this->normalize_batch_result( $result, count( $batch ) );

					return is_wp_error( $result )
						? array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => true,
						)
						: array(
							'ok'     => true,
							'kind'   => 'forward_batch',
							'result' => $result,
						);

				case 'verifying':
					return $this->verify_forward( $job );

				case 'rollback_settings':
					$result = $this->restore_settings( $job );
					if ( is_wp_error( $result ) ) {
						return array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => $this->error_requires_phase_resolution( $result ),
						);
					}
					$result = $this->normalize_settings_phase_result( $result, $job, true );

					return is_wp_error( $result )
						? array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => true,
						)
						: array(
							'ok'     => true,
							'kind'   => 'settings_restored',
							'result' => $result,
						);

				case 'rollback_products':
					$remaining = max( 0, (int) $job['forward_cursor'] - (int) $job['rollback_cursor'] );
					$batch     = array_slice( $job['codes'], (int) $job['rollback_cursor'], min( self::BATCH_SIZE, $remaining ) );
					if ( empty( $batch ) ) {
						return array(
							'ok'   => true,
							'kind' => 'rollback_complete',
						);
					}
					$index  = intdiv( (int) $job['rollback_cursor'], self::BATCH_SIZE );
					$result = $this->reprice_batch(
						$job['previous_settings'],
						$batch,
						$job['settings_phase']['catalog_revision'],
						$job['expected_scope_revision'],
						$job['source_pin']['code_digest'],
						'pricing-apply-rollback:' . $job['job_id'] . ':' . $index,
						$job['operation_binding']['source']
					);
					if ( is_wp_error( $result ) ) {
						return array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => $this->error_requires_phase_resolution( $result ),
						);
					}
					$result = $this->normalize_batch_result( $result, count( $batch ) );

					return is_wp_error( $result )
						? array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => true,
						)
						: array(
							'ok'     => true,
							'kind'   => 'rollback_batch',
							'result' => $result,
						);

				case 'rollback_verifying':
					return $this->verify_rollback( $job );

				case 'publishing':
					$result = $this->publish_outbox( $job );
					if ( ! is_wp_error( $result ) ) {
						$result = $this->normalize_publish_result( $result, $job );
					}
					return is_wp_error( $result )
						? array(
							'ok'        => false,
							'error'     => $result,
							'uncertain' => $this->error_requires_phase_resolution( $result ),
						)
						: array(
							'ok'     => true,
							'kind'   => 'published',
							'result' => is_array( $result ) ? $result : array(),
						);
			}
		} catch ( Throwable $exception ) {
			$effect_phase = in_array(
				(string) ( $job['phase'] ?? '' ),
				array( 'settings', 'repricing', 'rollback_settings', 'rollback_products', 'publishing' ),
				true
			);

			return array(
				'ok'        => false,
				'uncertain' => $effect_phase,
				'error'     => $this->error(
					'digitalogic_pricing_apply_unexpected_failure',
					'Pricing apply phase failed unexpectedly.',
					500,
					array(
						'exception' => get_class( $exception ),
						'retryable' => true,
					)
				),
			);
		}

		return array(
			'ok'    => false,
			'error' => $this->error( 'digitalogic_pricing_apply_phase_invalid', 'Pricing apply phase is invalid.', 500 ),
		);
	}

	/** Persist a phase result under its exact lease. */
	private function complete_claim( $job_id, $lease_token, $outcome ) {
		return $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $lease_token, $outcome ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
				}
				if ( ! hash_equals( (string) $job['lease_token'], (string) $lease_token ) ) {
					return $this->error( 'digitalogic_pricing_apply_lease_lost', 'Pricing apply job lease changed before result storage.', 409 );
				}

				$resolving               = ! empty( $job['phase_resolution_required'] ) || ! empty( $outcome['uncertain'] );
				$job['lease_token']      = '';
				$job['lease_expires_at'] = 0;
				$job['heartbeat_at']     = $this->now();
				$job['updated_at']       = $this->now();
				if ( 'rollback' === (string) ( $job['confirmation_signal']['type'] ?? '' ) ) {
					$job = $this->apply_pending_confirmation_rollback( $job, $outcome );
				} elseif ( empty( $outcome['ok'] ) ) {
					if ( 'publishing' === (string) $job['phase'] ) {
						$job['outbox']['state'] = 'pending';
					}
					if ( $resolving ) {
						$job['phase_resolution_required'] = true;
						$job                              = $this->apply_phase_resolution_error( $job, $outcome['error'] ?? null );
					} else {
						$job = $this->apply_phase_error( $job, $outcome['error'] ?? null );
					}
				} else {
					$job['phase_resolution_required'] = false;
					$job['phase_attempts']            = 0;
					$job['last_error']                = null;
					$job                              = $this->apply_phase_success( $job, $outcome );
				}

				if (
					! $this->is_terminal( $job )
					&& 'publishing' !== (string) $job['phase']
					&& 'awaiting_ack' !== (string) $job['phase']
					&& 'recovering' !== (string) $job['status']
					&& empty( $job['phase_resolution_required'] )
				) {
					if ( ! empty( $job['cancel_requested'] ) ) {
						$job = $this->move_to_recovery_or_publish( $job, 'cancelled' );
					} elseif ( $this->now() >= (int) $job['deadline_at'] ) {
						$job = $this->move_to_recovery_or_publish( $job, 'deadline_exceeded' );
					}
				}
				$registry['jobs'][ $job_id ] = $job;
				$stored                      = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
			}
		);
	}

	/** Reconcile an early timeout only after the exact publisher lease finishes. */
	private function apply_pending_confirmation_rollback( $job, $outcome ) {
		$signal         = $job['confirmation_signal'];
		$transaction_id = (string) ( $signal['transaction_id'] ?? '' );
		$confirmation   = null;
		if (
			! empty( $outcome['ok'] )
			&& 'published' === (string) ( $outcome['kind'] ?? '' )
			&& ! empty( $outcome['result']['awaiting_ack'] )
		) {
			$confirmation = $this->normalize_confirmation_projection( $outcome['result']['confirmation'] ?? array(), $transaction_id );
			if (
				is_wp_error( $confirmation )
				|| ! $this->confirmation_matches_consumer( $confirmation, $job['ack_consumer'] ?? array() )
				|| ! $this->confirmation_matches_settings_phase( $confirmation, $job )
			) {
				$error                      = is_wp_error( $confirmation ) ? $confirmation : $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Timed-out confirmation does not match the publishing phase.', 409 );
				$job['confirmation_signal'] = null;

				return $this->queue_outcome_unknown_publish( $job, 'confirmation_readback_required', $this->safe_error( $error ) );
			}
		}
		if ( ! is_array( $confirmation ) ) {
			$confirmation = array(
				'schema'         => 'digitalogic.pricing-confirmation',
				'transaction_id' => $transaction_id,
			);
		} else {
			$job['recovery_deadline_at'] = max( (int) $job['recovery_deadline_at'], (int) $confirmation['recovery_deadline'] );
		}
		$confirmation['status']           = 'rollback_requested';
		$job['confirmation']              = $confirmation;
		$job['confirmation_signal']       = null;
		$job['recovery_reason']           = sanitize_key( (string) ( $signal['reason'] ?? 'excel_ack_timeout' ) );
		$job['status']                    = 'recovering';
		$job['phase']                     = $job['settings_restored'] ? 'rollback_products' : 'rollback_settings';
		$job['phase_attempts']            = 0;
		$job['pending_terminal']          = null;
		$job['outbox']['state']           = 'not_ready';
		$job['last_error']                = null;
		$job['phase_resolution_required'] = false;

		return $job;
	}

	/** Advance a successful phase exactly once. */
	private function apply_phase_success( $job, $outcome ) {
		$kind   = (string) ( $outcome['kind'] ?? '' );
		$result = is_array( $outcome['result'] ?? null ) ? $outcome['result'] : array();
		switch ( $kind ) {
			case 'settings':
				$job['settings_phase']          = $result;
				$job['settings_committed']      = true;
				$job['expected_scope_revision'] = $job['source_pin']['state_revision'];
				$job['phase']                   = 'repricing';
				break;
			case 'forward_batch':
				$job['forward_cursor']         += (int) $result['row_count'];
				$job['expected_scope_revision'] = $result['scope_revision'];
				$job['aggregate']               = $this->merge_aggregate( $job['aggregate'], $result['pricing_results'] );
				if ( is_array( $result['cache_plan'] ?? null ) ) {
					$job['aggregate']['cache_plan'] = $result['cache_plan'];
				}
				$job['phase'] = $job['forward_cursor'] >= count( $job['codes'] ) ? 'verifying' : 'repricing';
				break;
			case 'forward_complete':
				$job['phase'] = 'verifying';
				break;
			case 'verified':
				if ( ! empty( $job['cancel_requested'] ) ) {
					$job = $this->move_to_recovery_or_publish( $job, 'cancelled' );
				} elseif ( $this->now() >= (int) $job['deadline_at'] ) {
					$job = $this->move_to_recovery_or_publish( $job, 'deadline_exceeded' );
				} else {
					$job = $this->queue_terminal_publish( $job, 'completed', 'completed', null );
				}
				break;
			case 'settings_restored':
				$job['settings_restored'] = true;
				$job['phase']             = (int) $job['forward_cursor'] > 0 ? 'rollback_products' : 'rollback_verifying';
				break;
			case 'rollback_batch':
				$job['rollback_cursor']        += (int) $result['row_count'];
				$job['expected_scope_revision'] = $result['scope_revision'];
				$job['phase']                   = $job['rollback_cursor'] >= $job['forward_cursor'] ? 'rollback_verifying' : 'rollback_products';
				break;
			case 'rollback_complete':
				$job['phase'] = 'rollback_verifying';
				break;
			case 'rollback_verified':
				$status = 'cancelled' === (string) $job['recovery_reason'] ? 'cancelled' : 'rolled_back';
				$job    = $this->queue_terminal_publish( $job, $status, (string) $job['recovery_reason'], $job['last_error'] );
				break;
			case 'published':
				$pending = is_array( $job['pending_terminal'] ?? null ) ? $job['pending_terminal'] : array();
				$status  = (string) ( $pending['status'] ?? 'outcome_unknown' );
				$reason  = (string) ( $pending['reason'] ?? 'event_delivery_readback_required' );
				$error   = is_array( $pending['error'] ?? null ) ? $pending['error'] : null;
				if ( 'completed' === $status && ! empty( $result['awaiting_ack'] ) ) {
					$confirmation = $this->normalize_confirmation_projection( $result['confirmation'] ?? array() );
					if (
						is_wp_error( $confirmation )
						|| 'awaiting_ack' !== (string) ( $confirmation['status'] ?? '' )
						|| ! $this->confirmation_matches_consumer( $confirmation, $job['ack_consumer'] ?? array() )
						|| ! $this->confirmation_matches_settings_phase( $confirmation, $job )
						|| (int) ( $confirmation['ack_deadline'] ?? 0 ) <= $this->now()
						|| (int) ( $confirmation['recovery_deadline'] ?? 0 ) <= $this->now()
					) {
						if ( ! is_wp_error( $confirmation ) ) {
							$confirmation = $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation does not match the pinned acknowledgement consumer.', 409, array( 'retryable' => true ) );
						}
						$job['last_error']                = $this->safe_error( $confirmation );
						$job['phase_resolution_required'] = true;
						break;
					}
					$job['recovery_deadline_at'] = max( (int) $job['recovery_deadline_at'], (int) $confirmation['recovery_deadline'] );
					if ( 'acknowledged' === (string) ( $job['confirmation']['status'] ?? '' ) ) {
						if ( ! $this->same_confirmation_identity( $job['confirmation'], $confirmation ) ) {
							$job['last_error']                = $this->safe_error( $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Early acknowledgement does not match the staged confirmation.', 409 ) );
							$job['phase_resolution_required'] = true;
							break;
						}
						$apply_result                 = is_array( $result['result'] ?? null ) ? $result['result'] : array();
						$apply_result['confirmation'] = $job['confirmation'];
						$job['terminal_result']       = $apply_result;
						$job['delivery_result']       = $result;
						$job                          = $this->queue_terminal_publish( $job, 'completed', 'completed', null );
						break;
					}
					$apply_result                 = is_array( $result['result'] ?? null ) ? $result['result'] : array();
					$apply_result['confirmation'] = $confirmation;
					$job['confirmation']          = $confirmation;
					$job['terminal_result']       = $apply_result;
					$job['delivery_result']       = $result;
					$job['status']                = 'awaiting_ack';
					$job['phase']                 = 'awaiting_ack';
					$job['outbox']['state']       = 'awaiting_ack';
					$job['outbox']['attempts']    = 0;
					break;
				}
				$published_result = array_key_exists( 'awaiting_ack', $result ) && is_array( $result['result'] ?? null ) ? $result['result'] : $result;
				if ( 'rolled_back' === $status && '' !== (string) ( $job['confirmation']['transaction_id'] ?? '' ) ) {
					$transaction_id = (string) ( $job['confirmation']['transaction_id'] ?? '' );
					$closed         = $this->normalize_confirmation_projection( $published_result['confirmation'] ?? array(), $transaction_id );
					$valid_closed   = ! is_wp_error( $closed )
						&& 'rolled_back' === (string) $closed['status']
						&& $this->confirmation_matches_consumer( $closed, $job['ack_consumer'] ?? array() )
						&& $this->confirmation_matches_closed_job( $closed, $job );
					if ( $valid_closed && isset( $job['confirmation']['committed_revision'] ) ) {
						$valid_closed = $this->same_confirmation_commit_identity( $job['confirmation'], $closed );
					}
					if ( ! $valid_closed ) {
						$error                            = is_wp_error( $closed ) ? $closed : $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Closed rollback confirmation does not match the bounded job.', 409, array( 'retryable' => true ) );
						$job['last_error']                = $this->safe_error( $error );
						$job['phase_resolution_required'] = true;
						$job['outbox']['state']           = 'pending';
						break;
					}
					$job['confirmation'] = $closed;
				}
				$job['outbox']['state']      = 'emitted';
				$job['outbox']['emitted_at'] = $this->now();
				$job['delivery_result']      = $published_result;
				if ( 'completed' === $status ) {
					$job['terminal_result'] = $published_result;
				}
				$job = $this->terminal_job( $job, $status, $reason, $error );
				break;
		}

		return $job;
	}

	/** Retry a known phase failure or enter bounded compensation. */
	private function apply_phase_error( $job, $error ) {
		$error             = is_wp_error( $error ) ? $error : $this->error( 'digitalogic_pricing_apply_phase_failed', 'Pricing apply phase failed.', 500 );
		$job['last_error'] = $this->safe_error( $error );
		++$job['phase_attempts'];
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['readback_required'] ) ) {
			return $this->queue_outcome_unknown_publish( $job, 'readback_required', $job['last_error'] );
		}

		if ( 'publishing' === (string) $job['phase'] ) {
			if (
				(int) $job['outbox']['attempts'] < self::MAX_OUTBOX_ATTEMPTS
				&& $this->now() < (int) $job['recovery_deadline_at']
				&& $this->is_retryable_error( $error )
			) {
				return $job;
			}
			return $this->queue_outcome_unknown_publish( $job, 'event_delivery_readback_required', $job['last_error'] );
		}

		$recovering = in_array( (string) $job['phase'], array( 'rollback_settings', 'rollback_products', 'rollback_verifying' ), true );
		$limit      = $recovering ? (int) $job['recovery_deadline_at'] : (int) $job['deadline_at'];
		if ( (int) $job['phase_attempts'] < self::MAX_PHASE_ATTEMPTS && $this->now() < $limit && $this->is_retryable_error( $error ) ) {
			return $job;
		}
		if ( $recovering ) {
			return $this->queue_outcome_unknown_publish( $job, 'readback_required', $job['last_error'] );
		}
		if ( $this->job_has_effects( $job ) ) {
			$job['recovery_reason'] = (string) $error->get_error_code();
			$job['status']          = 'recovering';
			$job['phase']           = $job['settings_restored'] ? 'rollback_products' : 'rollback_settings';
			$job['phase_attempts']  = 0;

			return $job;
		}

		return $this->queue_terminal_publish( $job, 'failed', (string) $error->get_error_code(), $job['last_error'] );
	}

	/** Resolve a phase whose prior worker lost its durable acknowledgement. */
	private function apply_phase_resolution_error( $job, $error ) {
		$error                            = is_wp_error( $error ) ? $error : $this->error( 'digitalogic_pricing_apply_phase_failed', 'Pricing apply phase failed.', 500 );
		$job['last_error']                = $this->safe_error( $error );
		$job['phase_resolution_required'] = true;
		++$job['phase_attempts'];
		$publishing = 'publishing' === (string) $job['phase'];
		$attempts   = $publishing ? (int) $job['outbox']['attempts'] : (int) $job['phase_attempts'];
		$maximum    = $publishing ? self::MAX_OUTBOX_ATTEMPTS : self::MAX_PHASE_ATTEMPTS;
		$limit      = in_array( (string) $job['phase'], array( 'rollback_settings', 'rollback_products', 'rollback_verifying', 'publishing' ), true )
			? (int) $job['recovery_deadline_at']
			: (int) $job['deadline_at'];
		if ( $attempts < $maximum && $this->now() < $limit && $this->is_retryable_error( $error ) ) {
			return $job;
		}

		return $this->queue_outcome_unknown_publish(
			$job,
			$publishing ? 'event_delivery_readback_required' : 'readback_required',
			$job['last_error']
		);
	}

	/** Verify all forward effects against exact pinned readback. */
	private function verify_forward( $job ) {
		$state = $this->read_canonical_state();
		$scope = $this->normalized_scope( $this->read_pricing_scope( $job['operation_binding']['source'] ) );
		if ( is_wp_error( $state ) || is_wp_error( $scope ) ) {
			return array(
				'ok'    => false,
				'error' => is_wp_error( $state ) ? $state : $scope,
			);
		}
		if (
			! is_array( $state )
			|| ! hash_equals( (string) $job['settings_phase']['state_revision'], (string) ( $state['state_revision'] ?? '' ) )
			|| ! hash_equals( (string) $job['expected_scope_revision'], (string) $scope['state_revision'] )
			|| ! hash_equals( (string) $job['source_pin']['code_digest'], (string) $scope['code_digest'] )
			|| (int) $job['source_pin']['row_count'] !== (int) $scope['row_count']
			|| count( $job['codes'] ) !== (int) $job['forward_cursor']
			|| (int) $scope['pending_products'] > 0
			|| (int) $scope['deferred_ambiguous'] > 0
		) {
			return array(
				'ok'    => false,
				'error' => $this->error( 'digitalogic_pricing_apply_readback_failed', 'Final pricing readback did not match the pinned job state.', 502 ),
			);
		}

		return array(
			'ok'   => true,
			'kind' => 'verified',
		);
	}

	/** Verify that compensation restored settings and every processed code. */
	private function verify_rollback( $job ) {
		$state = $this->read_canonical_state();
		$scope = $this->normalized_scope( $this->read_pricing_scope( $job['operation_binding']['source'] ) );
		if ( is_wp_error( $state ) || is_wp_error( $scope ) ) {
			return array(
				'ok'    => false,
				'error' => is_wp_error( $state ) ? $state : $scope,
			);
		}
		if (
			! is_array( $state )
			|| ! hash_equals( (string) $job['expected_state_revision'], (string) ( $state['state_revision'] ?? '' ) )
			|| ! hash_equals( (string) $job['expected_scope_revision'], (string) $scope['state_revision'] )
			|| ! hash_equals( (string) $job['source_pin']['code_digest'], (string) $scope['code_digest'] )
			|| (int) $job['source_pin']['row_count'] !== (int) $scope['row_count']
			|| (int) $job['rollback_cursor'] !== (int) $job['forward_cursor']
			|| (int) $scope['pending_products'] > 0
			|| (int) $scope['deferred_ambiguous'] > 0
		) {
			return array(
				'ok'    => false,
				'error' => $this->error( 'digitalogic_pricing_apply_rollback_readback_failed', 'Pricing compensation requires authoritative readback.', 502 ),
			);
		}

		return array(
			'ok'   => true,
			'kind' => 'rollback_verified',
		);
	}

	/** Restore the original settings through the same stable receipt seam. */
	private function restore_settings( $job ) {
		if ( empty( $job['settings_changed'] ) ) {
			return array(
				'status'                    => 'already_restored',
				'state_revision'            => $job['expected_state_revision'],
				'previous_catalog_revision' => $job['settings_phase']['catalog_revision'],
				'catalog_revision'          => $job['settings_phase']['previous_catalog_revision'],
				'replayed'                  => true,
			);
		}
		if ( empty( $job['phase_resolution_required'] ) ) {
			$current = $this->read_canonical_state();
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			if ( is_array( $current ) && hash_equals( (string) $job['expected_state_revision'], (string) ( $current['state_revision'] ?? '' ) ) ) {
				return array(
					'status'                    => 'already_restored',
					'state_revision'            => $current['state_revision'],
					'previous_catalog_revision' => $job['settings_phase']['catalog_revision'],
					'catalog_revision'          => (string) ( $current['shipping']['catalog_revision'] ?? $job['settings_phase']['previous_catalog_revision'] ),
					'replayed'                  => true,
				);
			}
		}

		$restore_settings                              = $job['previous_settings'];
		$restore_settings['shipping_catalog_revision'] = $job['settings_phase']['catalog_revision'];

		return $this->commit_settings_phase(
			$restore_settings,
			$job['operation_binding']['source'],
			$job['settings_phase']['state_revision'],
			'pricing-apply-restore:' . $job['job_id'],
			$job['request_context'],
			$job['operation_metadata']['source_context']
		);
	}

	/** Prepare normalized settings against the exact current canonical state. */
	private function prepare_apply( $settings, $expected_revision ) {
		$current = $this->read_canonical_state();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! is_array( $current ) || ! is_array( $current['settings'] ?? null ) || ! is_string( $current['state_revision'] ?? null ) ) {
			return $this->error( 'digitalogic_pricing_apply_state_invalid', 'Canonical pricing state is incomplete.', 502 );
		}
		if ( ! hash_equals( (string) $expected_revision, (string) $current['state_revision'] ) ) {
			return $this->error(
				'digitalogic_pricing_apply_state_revision_conflict',
				'Pricing settings changed after the accepted preview.',
				409,
				array( 'current_state_revision' => (string) $current['state_revision'] )
			);
		}
		$desired = $this->prepare_settings( $settings );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}
		if ( ! is_array( $desired ) ) {
			return $this->error( 'digitalogic_pricing_apply_settings_invalid', 'Normalized pricing settings are incomplete.', 502 );
		}
		$catalog_revision = (string) ( $current['shipping']['catalog_revision'] ?? '' );
		if ( '' === $catalog_revision ) {
			return $this->error( 'digitalogic_pricing_apply_catalog_revision_missing', 'Canonical catalog revision is unavailable.', 502 );
		}

		return array(
			'previous_settings'         => $current['settings'],
			'settings'                  => $desired,
			'settings_changed'          => ! hash_equals( $this->digest( $current['settings'] ), $this->digest( $desired ) ),
			'previous_catalog_revision' => $catalog_revision,
		);
	}

	/** Validate and deterministically order an internal pricing scope. */
	private function normalized_scope( $scope ) {
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		if ( ! is_array( $scope ) || ! is_array( $scope['codes'] ?? null ) || ! is_array( $scope['sources'] ?? null ) ) {
			return $this->error( 'digitalogic_pricing_apply_scope_invalid', 'Pricing apply scope is incomplete.', 502 );
		}
		$state_revision = (string) ( $scope['state_revision'] ?? '' );
		$code_digest    = (string) ( $scope['code_digest'] ?? '' );
		if ( '' === $state_revision || '' === $code_digest ) {
			return $this->error( 'digitalogic_pricing_apply_scope_invalid', 'Pricing apply scope pins are incomplete.', 502 );
		}
		$codes = array();
		foreach ( $scope['codes'] as $code ) {
			if ( ! is_string( $code ) || '' === trim( $code ) || strlen( $code ) > 191 ) {
				return $this->error( 'digitalogic_pricing_apply_scope_invalid', 'Pricing apply scope contains an invalid Product Code.', 502 );
			}
			$codes[] = trim( $code );
		}
		$codes = array_values( array_unique( $codes ) );
		sort( $codes, SORT_STRING );
		if ( (int) ( $scope['row_count'] ?? -1 ) !== count( $codes ) ) {
			return $this->error( 'digitalogic_pricing_apply_scope_invalid', 'Pricing apply scope row count does not match its Product Codes.', 502 );
		}

		return array(
			'state_revision'     => $state_revision,
			'code_digest'        => $code_digest,
			'row_count'          => count( $codes ),
			'codes'              => $codes,
			'sources'            => array_values( $scope['sources'] ),
			'pending_products'   => max( 0, (int) ( $scope['pending_products'] ?? 0 ) ),
			'deferred_ambiguous' => max( 0, (int) ( $scope['deferred_ambiguous'] ?? 0 ) ),
		);
	}

	/** Whether a live scope retains the admission pins. */
	private function scope_matches_pin( $scope, $pin ) {
		return is_array( $scope )
			&& is_array( $pin )
			&& hash_equals( (string) ( $pin['state_revision'] ?? '' ), (string) ( $scope['state_revision'] ?? '' ) )
			&& hash_equals( (string) ( $pin['code_digest'] ?? '' ), (string) ( $scope['code_digest'] ?? '' ) )
			&& (int) ( $pin['row_count'] ?? -1 ) === (int) ( $scope['row_count'] ?? -2 );
	}

	/** Validate a settings receipt before advancing a durable cursor. */
	private function normalize_settings_phase_result( $result, $job, $restoring = false ) {
		if ( ! is_array( $result ) || ! is_string( $result['state_revision'] ?? null ) || '' === $result['state_revision'] ) {
			return $this->uncertain_receipt_error( 'settings' );
		}
		$previous_catalog = (string) ( $result['previous_catalog_revision'] ?? '' );
		$catalog          = (string) ( $result['catalog_revision'] ?? '' );
		if ( '' === $previous_catalog ) {
			$previous_catalog = $restoring
				? (string) ( $job['settings_phase']['catalog_revision'] ?? '' )
				: (string) ( $job['previous_catalog_revision'] ?? '' );
		}
		if ( '' === $catalog && empty( $job['settings_changed'] ) ) {
			$catalog = $previous_catalog;
		}
		if ( '' === $previous_catalog || '' === $catalog ) {
			return $this->uncertain_receipt_error( 'settings' );
		}
		$result['previous_catalog_revision'] = $previous_catalog;
		$result['catalog_revision']          = $catalog;

		return $result;
	}

	/** Validate that a publisher returned a durable receipt for this exact outbox. */
	private function normalize_publish_result( $result, $job ) {
		if ( ! is_array( $result ) || array_is_list( $result ) ) {
			return $this->invalid_publish_receipt();
		}
		$waiting_wrapper = array_key_exists( 'awaiting_ack', $result );
		if ( $waiting_wrapper && ! is_bool( $result['awaiting_ack'] ) ) {
			return $this->invalid_publish_receipt();
		}
		$awaiting_ack = $waiting_wrapper && true === $result['awaiting_ack'];
		$payload      = $waiting_wrapper ? ( $result['result'] ?? null ) : $result;
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			return $this->invalid_publish_receipt();
		}
		$terminal_status = (string) ( $job['pending_terminal']['status'] ?? '' );
		$event_id        = (string) ( $job['outbox']['event_id'] ?? '' );
		$effect_id       = 'sha256:' . hash( 'sha256', "pricing-apply-terminal\0" . $event_id . "\0" . (string) ( $job['request_fingerprint'] ?? '' ) );
		if ( ! hash_equals( (string) $job['job_id'], (string) ( $payload['job_id'] ?? '' ) ) ) {
			return $this->invalid_publish_receipt();
		}
		if (
			! hash_equals( (string) $job['request_id'], (string) ( $payload['request_id'] ?? '' ) )
			|| ! hash_equals( $event_id, (string) ( $payload['event_id'] ?? '' ) )
			|| ! hash_equals( $effect_id, (string) ( $payload['effect_id'] ?? '' ) )
			|| ! $this->same_source_scope( $job['operation_binding']['source'] ?? array(), $payload['source'] ?? array() )
		) {
			return $this->invalid_publish_receipt();
		}
		if ( 'completed' === $terminal_status ) {
			if (
				'digitalogic.pricing-sync-apply' !== (string) ( $payload['schema'] ?? '' )
				|| ! in_array( (string) ( $payload['status'] ?? '' ), array( 'applied', 'reconciled' ), true )
			) {
				return $this->invalid_publish_receipt();
			}
		} elseif (
			'digitalogic.pricing-apply-terminal' !== (string) ( $payload['schema'] ?? '' )
			|| ! hash_equals( $terminal_status, (string) ( $payload['status'] ?? '' ) )
			|| ! hash_equals( (string) ( $job['pending_terminal']['reason'] ?? '' ), (string) ( $payload['terminal_reason'] ?? '' ) )
		) {
			return $this->invalid_publish_receipt();
		}
		if ( $awaiting_ack ) {
			if ( 'completed' !== $terminal_status || ! is_array( $result['confirmation'] ?? null ) ) {
				return $this->invalid_publish_receipt();
			}
		} else {
			if ( 'completed' === $terminal_status ) {
				$stored_confirmation = $job['confirmation'] ?? null;
				$confirmation        = $this->normalize_confirmation_projection(
					$payload['confirmation'] ?? array(),
					(string) ( $stored_confirmation['transaction_id'] ?? '' )
				);
				if ( is_array( $confirmation ) && 'replayed' === (string) $confirmation['status'] ) {
					$confirmation['status'] = 'acknowledged';
				}
				if (
					! is_array( $stored_confirmation )
					|| 'acknowledged' !== (string) ( $stored_confirmation['status'] ?? '' )
					|| is_wp_error( $confirmation )
					|| 'acknowledged' !== (string) ( $confirmation['status'] ?? '' )
					|| ! $this->same_confirmation_identity( $stored_confirmation, $confirmation )
					|| ! $this->confirmation_matches_consumer( $confirmation, $job['ack_consumer'] ?? array() )
					|| ! $this->confirmation_matches_settings_phase( $confirmation, $job )
				) {
					return $this->invalid_publish_receipt();
				}
			}
			if ( true !== ( $payload['event_delivery']['durable'] ?? null ) ) {
				return $this->invalid_publish_receipt();
			}
		}

		return $result;
	}

	/** Mark a malformed effect receipt as ambiguous and exactly retryable. */
	private function invalid_publish_receipt() {
		return $this->error(
			'digitalogic_pricing_apply_outbox_receipt_invalid',
			'The pricing apply outbox returned an invalid durable receipt.',
			502,
			array(
				'retryable' => true,
				'uncertain' => true,
			)
		);
	}

	/** Validate an exact batch receipt before moving either cursor. */
	private function normalize_batch_result( $result, $expected_rows ) {
		if ( ! is_array( $result ) || (int) ( $result['row_count'] ?? -1 ) !== (int) $expected_rows ) {
			return $this->uncertain_receipt_error( 'batch' );
		}
		$scope_revision = (string) ( $result['receiver_revision'] ?? $result['state_revision'] ?? '' );
		if ( '' === $scope_revision || ! is_array( $result['pricing_results'] ?? null ) ) {
			return $this->uncertain_receipt_error( 'batch' );
		}
		$result['scope_revision'] = $scope_revision;

		return $result;
	}

	/** Build an error for a receipt that may follow a committed effect. */
	private function uncertain_receipt_error( $phase ) {
		return $this->error(
			'digitalogic_pricing_apply_receipt_invalid',
			'Pricing apply returned an incomplete durable receipt.',
			502,
			array(
				'phase'             => sanitize_key( (string) $phase ),
				'readback_required' => true,
			)
		);
	}

	/** Move a resolved saga outcome into the one authoritative publish phase. */
	private function queue_terminal_publish( $job, $status, $reason, $error ) {
		$job['pending_terminal']          = array(
			'status' => (string) $status,
			'reason' => sanitize_key( (string) $reason ),
			'error'  => is_array( $error ) ? $error : null,
		);
		$job['phase']                     = 'publishing';
		$job['status']                    = 'publishing';
		$job['phase_attempts']            = 0;
		$job['phase_resolution_required'] = false;
		$job['outbox']['state']           = 'pending';
		$job['updated_at']                = $this->now();

		return $job;
	}

	/** Keep an unresolved outcome nonterminal until its terminal event is durable. */
	private function queue_outcome_unknown_publish( $job, $reason, $error ) {
		$event_id                   = 'pricing-apply-outcome-unknown:' . (string) $job['job_id'];
		$job['confirmation_signal'] = null;
		$job['lease_token']         = '';
		$job['lease_expires_at']    = 0;
		if (
			hash_equals( $event_id, (string) ( $job['outbox']['event_id'] ?? '' ) )
			&& 'outcome_unknown' === (string) ( $job['pending_terminal']['status'] ?? '' )
		) {
			return $job;
		}
		$job['outbox']['event_id']   = $event_id;
		$job['outbox']['attempts']   = 0;
		$job['outbox']['emitted_at'] = 0;
		$job['delivery_result']      = null;

		return $this->queue_terminal_publish( $job, 'outcome_unknown', $reason, $error );
	}

	/** Choose compensation when an effect exists, otherwise publish failure. */
	private function move_to_recovery_or_publish( $job, $reason ) {
		if ( 'publishing' === (string) ( $job['phase'] ?? '' ) ) {
			return $job;
		}
		if ( $this->job_has_effects( $job ) ) {
			$job['recovery_reason'] = sanitize_key( (string) $reason );
			$job['status']          = 'recovering';
			$job['phase']           = $job['settings_restored'] ? 'rollback_products' : 'rollback_settings';
			$job['phase_attempts']  = 0;

			return $job;
		}

		$status = 'cancelled' === (string) $reason ? 'cancelled' : 'failed';

		return $this->queue_terminal_publish( $job, $status, $reason, $job['last_error'] );
	}

	/** Mark an acknowledged or irrecoverably ambiguous job terminal. */
	private function terminal_job( $job, $status, $reason, $error ) {
		$job['status']                    = (string) $status;
		$job['phase']                     = 'terminal';
		$job['terminal_reason']           = sanitize_key( (string) $reason );
		$job['terminal_at']               = $this->now();
		$job['updated_at']                = $this->now();
		$job['heartbeat_at']              = $this->now();
		$job['lease_token']               = '';
		$job['lease_expires_at']          = 0;
		$job['phase_resolution_required'] = false;
		$job['schedule_state']            = 'terminal';
		$job['schedule_token']            = '';
		$job['schedule_lease_expires_at'] = 0;
		if ( null !== $error ) {
			$job['last_error'] = $error;
		}

		return $job;
	}

	/** Return an exact request replay from the request index. */
	private function existing_request( $registry, $request ) {
		$record    = $registry['requests'][ $request['request_id'] ] ?? null;
		$tombstone = $registry['tombstones'][ $request['request_id'] ] ?? null;
		if ( ! is_array( $record ) && ! is_array( $tombstone ) ) {
			return null;
		}
		$fingerprint = is_array( $record ) ? (string) ( $record['request_fingerprint'] ?? '' ) : (string) ( $tombstone['request_fingerprint'] ?? '' );
		if ( ! hash_equals( $fingerprint, (string) $request['request_fingerprint'] ) ) {
			return $this->error( 'digitalogic_pricing_apply_request_id_conflict', 'The request ID was already used for another pricing apply.', 409 );
		}
		$job_id = is_array( $record ) ? (string) ( $record['job_id'] ?? '' ) : (string) ( $tombstone['job_id'] ?? '' );
		$job    = $registry['jobs'][ $job_id ] ?? null;
		if ( ! is_array( $job ) ) {
			if ( is_array( $tombstone ) ) {
				return $this->tombstone_status(
					$tombstone,
					array(
						'replayed' => true,
						'accepted' => 'schedule_failed' !== (string) ( $tombstone['terminal_reason'] ?? '' ),
					)
				);
			}

			return $this->error( 'digitalogic_pricing_apply_registry_incomplete', 'The durable request has no pricing apply job.', 500 );
		}

		return $this->status_payload(
			$job,
			array(
				'replayed' => true,
				'accepted' => 'schedule_failed' !== (string) ( $job['terminal_reason'] ?? '' ),
			)
		);
	}

	/** Fence unresolved outcomes and enforce one active mutation. */
	private function fresh_admission_blocker( $registry ) {
		foreach ( $registry['jobs'] as $job_id => $job ) {
			if ( is_array( $job ) && $this->requires_authoritative_readback( $job ) ) {
				return $this->error(
					'digitalogic_pricing_apply_readback_required',
					'A prior pricing apply has an unresolved outcome.',
					409,
					array(
						'blocking_job_id'   => (string) $job_id,
						'blocking_status'   => (string) ( $job['status'] ?? 'outcome_unknown' ),
						'readback_required' => true,
						'retryable'         => false,
					)
				);
			}
		}
		foreach ( $registry['jobs'] as $job_id => $job ) {
			if ( is_array( $job ) && ! $this->is_terminal( $job ) ) {
				return $this->error(
					'digitalogic_pricing_apply_busy',
					'Another pricing apply job is active.',
					503,
					array(
						'job_id'      => (string) $job_id,
						'retryable'   => true,
						'retry_after' => 5,
					)
				);
			}
		}

		return null;
	}

	/** Re-arm an admission recovered by exact replay. */
	private function ensure_admission_armed( $status ) {
		if ( ! empty( $status['terminal'] ) || 'armed' === (string) ( $status['schedule_state'] ?? '' ) ) {
			return $status;
		}
		$extras = array_intersect_key(
			$status,
			array(
				'accepted' => true,
				'replayed' => true,
			)
		);
		$armed  = $this->arm_admission( (string) $status['job_id'] );

		return is_wp_error( $armed ) ? $armed : array_merge( $armed, $extras );
	}

	/** Persist a finite arming lease around both scheduler calls. */
	private function arm_admission( $job_id ) {
		$claim = $this->with_registry_lock(
			function ( $registry ) use ( $job_id ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
				}
				if ( $this->is_terminal( $job ) || 'armed' === (string) ( $job['schedule_state'] ?? '' ) ) {
					return array(
						'claimed' => false,
						'status'  => $this->status_payload( $job ),
					);
				}
				if ( 'arming' === (string) ( $job['schedule_state'] ?? '' ) && (int) ( $job['schedule_lease_expires_at'] ?? 0 ) > $this->now() ) {
					return array(
						'claimed' => false,
						'status'  => $this->status_payload( $job ),
					);
				}

				$token                            = hash( 'sha256', $job_id . '|schedule|' . $this->now() . '|' . $this->new_entropy() );
				$job['schedule_state']            = 'arming';
				$job['schedule_token']            = $token;
				$job['schedule_lease_expires_at'] = $this->now() + self::ADMISSION_ARM_GRACE;
				$job['updated_at']                = $this->now();
				$registry['jobs'][ $job_id ]      = $job;
				$stored                           = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : array(
					'claimed' => true,
					'token'   => $token,
				);
			}
		);
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( empty( $claim['claimed'] ) ) {
			return $claim['status'];
		}

		$watchdog_scheduled = $this->schedule_watchdog( $job_id, self::HEARTBEAT_GRACE_SECONDS );
		$worker_scheduled   = $this->schedule_worker( $job_id, 1 );
		if ( ! $watchdog_scheduled && ! $worker_scheduled ) {
			$failed = $this->terminalize_scheduler_admission_failure( $job_id, (string) $claim['token'] );
			if ( is_array( $failed ) && ! empty( $failed['terminal'] ) && empty( $failed['accepted'] ) ) {
				$this->unschedule_job( $job_id );
			}

			return $failed;
		}

		return $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $claim, $watchdog_scheduled, $worker_scheduled ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
				}
				if ( ! hash_equals( (string) ( $job['schedule_token'] ?? '' ), (string) $claim['token'] ) ) {
					return $this->status_payload( $job, array( 'accepted' => true ) );
				}
				$job['schedule_state']            = 'armed';
				$job['schedule_token']            = '';
				$job['schedule_lease_expires_at'] = 0;
				$job['updated_at']                = $this->now();
				if ( ! $watchdog_scheduled || ! $worker_scheduled ) {
					++$job['schedule_failures'];
					$job['last_error'] = $this->safe_error(
						$this->error( 'digitalogic_pricing_apply_schedule_partial', 'Only one durable pricing wake could be scheduled.', 503, array( 'retryable' => true ) )
					);
				}
				$registry['jobs'][ $job_id ] = $job;
				$stored                      = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job, array( 'accepted' => true ) );
			}
		);
	}

	/** Persist an initial scheduling failure as an exact replay. */
	private function terminalize_scheduler_admission_failure( $job_id, $schedule_token ) {
		return $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $schedule_token ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) ) {
					return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
				}
				if ( ! hash_equals( (string) ( $job['schedule_token'] ?? '' ), (string) $schedule_token ) ) {
					return $this->status_payload( $job, array( 'accepted' => true ) );
				}
				$error                    = $this->safe_error( $this->error( 'digitalogic_pricing_apply_schedule_failed', 'The durable pricing worker could not be scheduled.', 503 ) );
				$job['schedule_failures'] = (int) $job['schedule_failures'] + 1;
				$pristine                 = ! $this->job_has_effects( $job )
					&& empty( $job['phase_resolution_required'] )
					&& empty( $job['lease_token'] )
					&& 'queued' === (string) $job['status']
					&& 'settings' === (string) $job['phase']
					&& 'arming' === (string) $job['schedule_state'];
				if ( $pristine ) {
					$job['outbox']['state'] = 'failed';
					$job                    = $this->terminal_job( $job, 'failed', 'schedule_failed', $error );
					$accepted               = false;
				} elseif ( (int) $job['schedule_failures'] >= self::MAX_SCHEDULE_FAILURES || $this->now() >= (int) $job['recovery_deadline_at'] ) {
					$job      = $this->queue_outcome_unknown_publish( $job, 'readback_required', $error );
					$accepted = true;
				} else {
					$job['schedule_state']            = 'unarmed';
					$job['schedule_token']            = '';
					$job['schedule_lease_expires_at'] = 0;
					$job['last_error']                = $error;
					$job['updated_at']                = $this->now();
					$accepted                         = true;
				}
				$registry['jobs'][ $job_id ] = $job;
				$stored                      = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job, array( 'accepted' => $accepted ) );
			}
		);
	}

	/** Bind a continuation scheduling attempt to the exact durable job state. */
	private function schedule_failure_fence( $job_id ) {
		$fence = $this->with_registry_lock(
			function ( $registry ) use ( $job_id ) {
				$job = $registry['jobs'][ $job_id ] ?? null;

				return is_array( $job ) && ! $this->is_terminal( $job ) ? $this->digest( $job ) : '';
			}
		);

		return is_string( $fence ) ? $fence : '';
	}

	/** Record a failed continuation only while its exact state fence still matches. */
	private function record_schedule_failure( $job_id, $kind = 'worker', $expected_fence = '' ) {
		$kind           = 'watchdog' === $kind ? 'watchdog' : 'worker';
		$expected_fence = (string) $expected_fence;
		$status         = $this->with_registry_lock(
			function ( $registry ) use ( $job_id, $kind, $expected_fence ) {
				$job = $registry['jobs'][ $job_id ] ?? null;
				if ( ! is_array( $job ) || $this->is_terminal( $job ) ) {
					return null;
				}
				if (
					'awaiting_ack' === (string) ( $job['phase'] ?? '' )
					|| '' === $expected_fence
					|| ! hash_equals( $expected_fence, $this->digest( $job ) )
				) {
					return $this->status_payload( $job );
				}
				$job['schedule_failures'] = (int) $job['schedule_failures'] + 1;
				$error                    = $this->safe_error(
					$this->error(
						'watchdog' === $kind ? 'digitalogic_pricing_apply_watchdog_schedule_failed' : 'digitalogic_pricing_apply_schedule_failed',
						'The next durable pricing wake could not be scheduled.',
						503,
						array( 'retryable' => true )
					)
				);
				$leased                   = '' !== (string) ( $job['lease_token'] ?? '' );
				if ( $leased ) {
					$job['last_error'] = $error;
				} elseif ( (int) $job['schedule_failures'] >= self::MAX_SCHEDULE_FAILURES || $this->now() >= (int) $job['recovery_deadline_at'] ) {
					$unknown = $this->job_has_effects( $job ) || ! empty( $job['phase_resolution_required'] ) || 'publishing' === (string) $job['phase'];
					$job     = $unknown
						? $this->queue_outcome_unknown_publish( $job, 'readback_required', $error )
						: $this->terminal_job( $job, 'failed', 'schedule_failed', $error );
				} else {
					if ( 'watchdog' === $kind ) {
						$job['schedule_state']            = 'unarmed';
						$job['schedule_token']            = '';
						$job['schedule_lease_expires_at'] = 0;
					}
					$job['last_error'] = $error;
				}
				$job['updated_at']           = $this->now();
				$registry['jobs'][ $job_id ] = $job;
				$stored                      = $this->save_registry( $registry );

				return is_wp_error( $stored ) ? $stored : $this->status_payload( $job );
			}
		);
		if ( is_array( $status ) && ! empty( $status['terminal'] ) ) {
			$this->unschedule_job( $job_id );
		}

		return $status;
	}

	/** Keep watchdog recovery durable when its successor cannot be queued. */
	private function reschedule_watchdog( $job_id ) {
		$schedule_fence = $this->schedule_failure_fence( $job_id );
		if ( ! $this->schedule_watchdog( $job_id, self::HEARTBEAT_GRACE_SECONDS ) ) {
			$this->record_schedule_failure( $job_id, 'watchdog', $schedule_fence );
		}
	}

	/** Normalize the exact source-bound acknowledgement consumer. */
	private function normalized_ack_consumer( $consumer, $source ) {
		if ( is_wp_error( $consumer ) ) {
			return $consumer;
		}
		if ( ! is_array( $consumer ) || array_is_list( $consumer ) ) {
			return $this->error( 'digitalogic_pricing_apply_ack_consumer_unavailable', 'The source-bound acknowledgement consumer is unavailable.', 409 );
		}
		$consumer_source = is_array( $consumer['source'] ?? null ) ? $consumer['source'] : array(
			'id'      => $consumer['source_id'] ?? '',
			'dataset' => $consumer['dataset'] ?? '',
		);
		$consumer_id     = $this->normalize_context_token( $consumer['consumer_id'] ?? null, 64 );
		$channel         = $this->normalize_context_token( $consumer['channel'] ?? null, 64 );
		$capability      = sanitize_key( (string) ( $consumer['capability'] ?? '' ) );
		if ( ! $this->same_source_scope( $source, $consumer_source ) || '' === $consumer_id || '' === $channel || 'pricing_settings_ack' !== $capability ) {
			return $this->error( 'digitalogic_pricing_apply_ack_consumer_conflict', 'The acknowledgement consumer does not match the bound source.', 409 );
		}

		return array(
			'consumer_id' => $consumer_id,
			'channel'     => $channel,
			'capability'  => $capability,
			'source_id'   => (string) $consumer_source['id'],
			'dataset'     => (string) $consumer_source['dataset'],
		);
	}

	/** Normalize one durable confirmation projection without retaining settings. */
	private function normalize_confirmation_projection( $confirmation, $expected_id = '' ) {
		if ( ! is_array( $confirmation ) || array_is_list( $confirmation ) ) {
			return $this->error( 'digitalogic_pricing_apply_confirmation_invalid', 'Pricing confirmation projection is invalid.', 502 );
		}
		$transaction_id = $this->normalize_confirmation_id( $confirmation['transaction_id'] ?? '' );
		if ( is_wp_error( $transaction_id ) ) {
			return $transaction_id;
		}
		if ( '' !== (string) $expected_id && ! hash_equals( (string) $expected_id, $transaction_id ) ) {
			return $this->error( 'digitalogic_pricing_apply_confirmation_conflict', 'Pricing confirmation identity changed.', 409 );
		}
		$schema = (string) ( $confirmation['schema'] ?? '' );
		$status = sanitize_key( (string) ( $confirmation['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'awaiting_ack', 'acknowledged', 'replayed', 'rollback_requested', 'rolling_back', 'rolled_back' ), true ) ) {
			return $this->error( 'digitalogic_pricing_apply_confirmation_invalid', 'Pricing confirmation status is invalid.', 502 );
		}
		if ( 'digitalogic.pricing-confirmation' !== $schema ) {
			return $this->error( 'digitalogic_pricing_apply_confirmation_invalid', 'Pricing confirmation schema is invalid.', 502 );
		}
		$ack_deadline      = max( 0, (int) ( $confirmation['ack_deadline'] ?? 0 ) );
		$recovery_deadline = max( 0, (int) ( $confirmation['recovery_deadline'] ?? 0 ) );
		$ack_path          = substr( (string) ( $confirmation['ack_path'] ?? '' ), 0, 255 );
		$consumer_id       = $this->normalize_context_token( $confirmation['consumer_id'] ?? '', 64 );
		$channel           = $this->normalize_context_token( $confirmation['channel'] ?? '', 64 );
		if (
			$ack_deadline <= 0
			|| $recovery_deadline < $ack_deadline
			|| '/wp-json/digitalogic/pricing/sync/ack' !== $ack_path
			|| '' === $consumer_id
			|| '' === $channel
		) {
			return $this->error( 'digitalogic_pricing_apply_confirmation_invalid', 'Pricing confirmation delivery identity is invalid.', 502 );
		}
		$result = array(
			'schema'            => $schema,
			'status'            => $status,
			'transaction_id'    => $transaction_id,
			'ack_deadline'      => $ack_deadline,
			'recovery_deadline' => $recovery_deadline,
			'ack_path'          => $ack_path,
			'consumer_id'       => $consumer_id,
			'channel'           => $channel,
		);
		foreach ( array( 'previous_revision', 'committed_revision', 'current_revision', 'committed_settings_digest' ) as $field ) {
			$value = (string) ( $confirmation[ $field ] ?? '' );
			if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $value ) ) {
				return $this->error( 'digitalogic_pricing_apply_confirmation_invalid', 'Pricing confirmation revision is invalid.', 502, array( 'field' => $field ) );
			}
			$result[ $field ] = $value;
		}

		return $result;
	}

	/** Confirm that a projection belongs to the exact source-bound consumer pin. */
	private function confirmation_matches_consumer( $confirmation, $consumer ) {
		return is_array( $confirmation )
			&& is_array( $consumer )
			&& '' !== (string) ( $consumer['consumer_id'] ?? '' )
			&& hash_equals( (string) $consumer['consumer_id'], (string) ( $confirmation['consumer_id'] ?? '' ) )
			&& '' !== (string) ( $consumer['channel'] ?? '' )
			&& hash_equals( (string) $consumer['channel'], (string) ( $confirmation['channel'] ?? '' ) );
	}

	/** Compare every immutable field in a staged and acknowledged projection. */
	private function same_confirmation_identity( $stored, $received ) {
		if ( ! is_array( $stored ) || ! is_array( $received ) ) {
			return false;
		}
		foreach (
			array(
				'schema',
				'transaction_id',
				'previous_revision',
				'committed_revision',
				'current_revision',
				'committed_settings_digest',
				'ack_deadline',
				'recovery_deadline',
				'ack_path',
				'consumer_id',
				'channel',
			) as $field
		) {
			if ( ! array_key_exists( $field, $stored ) || ! array_key_exists( $field, $received ) || ! hash_equals( (string) $stored[ $field ], (string) $received[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/** Compare immutable commit identity while allowing restored current_revision. */
	private function same_confirmation_commit_identity( $stored, $received ) {
		if ( ! is_array( $stored ) || ! is_array( $received ) ) {
			return false;
		}
		foreach ( array( 'schema', 'transaction_id', 'previous_revision', 'committed_revision', 'committed_settings_digest', 'ack_deadline', 'recovery_deadline', 'ack_path', 'consumer_id', 'channel' ) as $field ) {
			if ( ! array_key_exists( $field, $stored ) || ! array_key_exists( $field, $received ) || ! hash_equals( (string) $stored[ $field ], (string) $received[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/** Bind an early internal ACK to the settings phase before publisher storage. */
	private function confirmation_matches_settings_phase( $confirmation, $job ) {
		return is_array( $job['settings_phase'] ?? null )
			&& hash_equals( (string) ( $job['settings_phase']['previous_state_revision'] ?? '' ), (string) ( $confirmation['previous_revision'] ?? '' ) )
			&& hash_equals( (string) ( $job['settings_phase']['state_revision'] ?? '' ), (string) ( $confirmation['committed_revision'] ?? '' ) )
			&& hash_equals( (string) ( $job['settings_phase']['state_revision'] ?? '' ), (string) ( $confirmation['current_revision'] ?? '' ) )
			&& hash_equals( $this->digest( (array) ( $job['settings_phase']['settings'] ?? array() ) ), (string) ( $confirmation['committed_settings_digest'] ?? '' ) );
	}

	/** Validate the exact closed projection after bounded rollback readback. */
	private function confirmation_matches_closed_job( $confirmation, $job ) {
		return is_array( $job['settings_phase'] ?? null )
			&& hash_equals( (string) ( $job['settings_phase']['previous_state_revision'] ?? '' ), (string) ( $confirmation['previous_revision'] ?? '' ) )
			&& hash_equals( (string) ( $job['settings_phase']['state_revision'] ?? '' ), (string) ( $confirmation['committed_revision'] ?? '' ) )
			&& hash_equals( (string) ( $job['expected_state_revision'] ?? '' ), (string) ( $confirmation['current_revision'] ?? '' ) )
			&& hash_equals( $this->digest( (array) ( $job['settings_phase']['settings'] ?? array() ) ), (string) ( $confirmation['committed_settings_digest'] ?? '' ) );
	}

	/** Normalize only a canonical job identity for internal confirmation signals. */
	private function normalize_job_id( $job_id ) {
		if ( ! is_string( $job_id ) || 1 !== preg_match( '/\Ajob_[a-f0-9]{32}\z/D', $job_id ) ) {
			return $this->error( 'digitalogic_pricing_apply_identifier_invalid', 'A valid job_id is required.', 400 );
		}

		return $job_id;
	}

	/** Normalize a durable Excel confirmation identity. */
	private function normalize_confirmation_id( $transaction_id ) {
		$transaction_id = is_string( $transaction_id ) ? trim( $transaction_id ) : '';
		if ( 1 !== preg_match( '/\Aptx_[a-f0-9]{32}\z/D', $transaction_id ) ) {
			return $this->error( 'digitalogic_pricing_apply_confirmation_invalid', 'A valid confirmation transaction is required.', 400 );
		}

		return $transaction_id;
	}

	/** Normalize request material needed for exact replay without live reads. */
	private function normalize_request( $settings, $expected_revision, $request_id, $binding, $request_context ) {
		if ( ! is_array( $settings ) || array_is_list( $settings ) ) {
			return $this->error( 'digitalogic_pricing_apply_settings_invalid', 'Pricing settings must be an object.', 400 );
		}
		$request_id = $this->normalize_identifier( $request_id );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}
		if ( ! is_string( $expected_revision ) || 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $expected_revision ) ) {
			return $this->error( 'digitalogic_pricing_apply_revision_invalid', 'Expected pricing state revision is required.', 400 );
		}
		$context = $this->normalize_request_context( $request_context, $request_id );
		$binding = $this->normalize_binding( $binding, $context );
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}
		$settings              = $this->canonical_request_value( $settings );
		$operation_fingerprint = $this->digest(
			array(
				'settings'                => $settings,
				'expected_state_revision' => $expected_revision,
				'operation_binding'       => $binding,
			)
		);

		return array(
			'settings'                => $settings,
			'expected_state_revision' => $expected_revision,
			'request_id'              => $request_id,
			'request_context'         => $context,
			'operation_binding'       => $binding,
			'operation_fingerprint'   => $operation_fingerprint,
			'request_fingerprint'     => $this->digest(
				array(
					'operation_fingerprint' => $operation_fingerprint,
					'request_id'            => $request_id,
				)
			),
		);
	}

	/** Normalize the semantic binding while ignoring additive provider fields. */
	private function normalize_binding( $binding, $context ) {
		if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
			return $this->error( 'digitalogic_pricing_apply_binding_invalid', 'Pricing apply binding must be an object.', 400 );
		}
		$source = $this->normalize_bound_source( $binding['source'] ?? null );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$preview_digest = is_string( $binding['preview_digest'] ?? null ) ? trim( $binding['preview_digest'] ) : '';
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $preview_digest ) || 'APPLY' !== ( $binding['confirmation'] ?? null ) ) {
			return $this->error( 'digitalogic_pricing_apply_binding_invalid', 'Pricing apply preview or confirmation is invalid.', 400 );
		}
		$client_id = $binding['client_id'] ?? $context['client_id'];
		$channel   = $binding['channel'] ?? $context['channel'];
		$client_id = $this->normalize_context_token( $client_id, 64 );
		$channel   = $this->normalize_context_token( $channel, 64 );
		if ( '' === $client_id || '' === $channel ) {
			return $this->error( 'digitalogic_pricing_apply_binding_invalid', 'Pricing apply client identity is invalid.', 400 );
		}

		return array(
			'source'         => $source,
			'preview_digest' => $preview_digest,
			'confirmation'   => 'APPLY',
			'client_id'      => $client_id,
			'channel'        => $channel,
		);
	}

	/** Normalize live-only metadata without an exact-key/provider gate. */
	private function normalize_metadata( $metadata ) {
		if ( ! is_array( $metadata ) || array_is_list( $metadata ) ) {
			return $this->error( 'digitalogic_pricing_apply_metadata_invalid', 'Pricing apply metadata must be an object.', 400 );
		}
		$source = $metadata['source_context'] ?? null;
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			return $this->error( 'digitalogic_pricing_apply_metadata_invalid', 'Pricing apply source metadata is invalid.', 400 );
		}
		$id      = $this->normalize_source_token( $source['id'] ?? null );
		$dataset = $this->normalize_source_token( $source['dataset'] ?? null );
		if ( '' === $id || '' === $dataset ) {
			return $this->error( 'digitalogic_pricing_apply_metadata_invalid', 'Pricing apply source metadata is invalid.', 400 );
		}
		$submitted = is_string( $source['submitted_revision'] ?? null ) ? trim( $source['submitted_revision'] ) : '';
		$current   = is_string( $source['current_revision'] ?? null ) ? trim( $source['current_revision'] ) : '';
		if (
			1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $submitted )
			|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $current )
			|| ! is_bool( $source['revision_matches_current'] ?? null )
			|| ! is_bool( $source['revision_capability'] ?? null )
		) {
			return $this->error( 'digitalogic_pricing_apply_metadata_invalid', 'Pricing apply source revision context is invalid.', 400 );
		}
		$expires_at = (int) ( $metadata['preview_expires_at'] ?? 0 );
		if ( $expires_at <= 0 ) {
			return $this->error( 'digitalogic_pricing_apply_metadata_invalid', 'Pricing apply preview expiry is invalid.', 400 );
		}
		$warnings = is_array( $metadata['warnings'] ?? null ) ? array_slice( array_values( $metadata['warnings'] ), 0, 50 ) : array();
		foreach ( $warnings as $warning ) {
			if ( ! is_array( $warning ) ) {
				return $this->error( 'digitalogic_pricing_apply_metadata_invalid', 'Pricing apply warning metadata is invalid.', 400 );
			}
		}

		return array(
			'source_context'     => array(
				'id'                       => $id,
				'dataset'                  => $dataset,
				'submitted_revision'       => $submitted,
				'current_revision'         => $current,
				'revision_matches_current' => (bool) $source['revision_matches_current'],
				'revision_capability'      => (bool) $source['revision_capability'],
			),
			'preview_expires_at' => $expires_at,
			'warnings'           => $warnings,
		);
	}

	/** Normalize the immutable source representation; only id/dataset authorize. */
	private function normalize_bound_source( $source ) {
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			return $this->error( 'digitalogic_pricing_apply_source_invalid', 'Pricing apply source identity is invalid.', 400 );
		}
		$id      = $this->normalize_source_token( $source['id'] ?? null );
		$dataset = $this->normalize_source_token( $source['dataset'] ?? null );
		if ( '' === $id || '' === $dataset ) {
			return $this->error( 'digitalogic_pricing_apply_source_invalid', 'Pricing apply source identity is invalid.', 400 );
		}
		$normalized = array(
			'id'      => $id,
			'dataset' => $dataset,
		);
		if ( is_string( $source['revision'] ?? null ) && strlen( trim( $source['revision'] ) ) <= 191 ) {
			$normalized['revision'] = trim( $source['revision'] );
		} else {
			$normalized['revision'] = '';
		}
		if ( is_bool( $source['revision_capability'] ?? null ) ) {
			$normalized['revision_capability'] = (bool) $source['revision_capability'];
		}

		return $normalized;
	}

	/** Normalize source material used solely for authorization. */
	private function normalize_auth_source( $source ) {
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			return $this->source_not_found_error();
		}
		$id      = $this->normalize_source_token( $source['id'] ?? null );
		$dataset = $this->normalize_source_token( $source['dataset'] ?? null );
		if ( '' === $id || '' === $dataset ) {
			return $this->source_not_found_error();
		}

		return array(
			'id'      => $id,
			'dataset' => $dataset,
		);
	}

	/** Normalize nonsecret request trace context. */
	private function normalize_request_context( $context, $request_id ) {
		$context   = is_array( $context ) ? $context : array();
		$client_id = $this->normalize_context_token( $context['client_id'] ?? 'digitalogic-wp', 64 );
		$channel   = $this->normalize_context_token( $context['channel'] ?? 'pricing-sync', 64 );

		return array(
			'client_id'  => '' === $client_id ? 'digitalogic-wp' : $client_id,
			'channel'    => '' === $channel ? 'pricing-sync' : $channel,
			'request_id' => $request_id,
		);
	}

	/** Normalize one bounded client/channel token. */
	private function normalize_context_token( $value, $length ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $value ) ) {
			return '';
		}

		return substr( $value, 0, (int) $length );
	}

	/** Normalize one opaque source id/dataset without provider assumptions. */
	private function normalize_source_token( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );

		return '' !== $value && strlen( $value ) <= 191 ? $value : '';
	}

	/** Recursively trim strings and order associative keys for replay identity. */
	private function canonical_request_value( $value ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonical_request_value( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/** Compare only the mandatory authenticated source scope. */
	private function same_source_scope( $left, $right ) {
		return is_array( $left )
			&& is_array( $right )
			&& '' !== (string) ( $left['id'] ?? '' )
			&& '' !== (string) ( $left['dataset'] ?? '' )
			&& hash_equals( (string) $left['id'], (string) ( $right['id'] ?? '' ) )
			&& hash_equals( (string) $left['dataset'], (string) ( $right['dataset'] ?? '' ) );
	}

	/** Locate by the canonical job id or exact request index. */
	private function locate_job( $registry, $identifier ) {
		if ( isset( $registry['jobs'][ $identifier ] ) && is_array( $registry['jobs'][ $identifier ] ) ) {
			return array( 'job' => $registry['jobs'][ $identifier ] );
		}
		$request = $registry['requests'][ $identifier ] ?? null;
		if ( is_array( $request ) && isset( $registry['jobs'][ $request['job_id'] ] ) && is_array( $registry['jobs'][ $request['job_id'] ] ) ) {
			return array( 'job' => $registry['jobs'][ $request['job_id'] ] );
		}
		if ( is_array( $registry['tombstones'][ $identifier ] ?? null ) ) {
			return array( 'tombstone' => $registry['tombstones'][ $identifier ] );
		}
		$request_id = (string) ( $registry['tombstone_jobs'][ $identifier ] ?? '' );
		if ( '' !== $request_id && is_array( $registry['tombstones'][ $request_id ] ?? null ) ) {
			return array( 'tombstone' => $registry['tombstones'][ $request_id ] );
		}

		return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
	}

	/** Resolve a compact terminal replay by its canonical job identity. */
	private function tombstone_by_job( $registry, $job_id ) {
		$request_id = (string) ( $registry['tombstone_jobs'][ $job_id ] ?? '' );

		return '' !== $request_id && is_array( $registry['tombstones'][ $request_id ] ?? null )
			? $registry['tombstones'][ $request_id ]
			: null;
	}

	/** Render an immutable compact terminal replay. */
	private function tombstone_status( $tombstone, $extra = array() ) {
		$payload = is_array( $tombstone['payload'] ?? null ) ? $tombstone['payload'] : array();
		if ( empty( $payload ) ) {
			return $this->error( 'digitalogic_pricing_apply_registry_incomplete', 'The durable replay record is incomplete.', 500 );
		}
		if ( array_key_exists( 'replayed', $extra ) ) {
			$payload['replayed'] = (bool) $extra['replayed'];
		}
		if ( array_key_exists( 'accepted', $extra ) ) {
			$payload['accepted'] = (bool) $extra['accepted'];
		}

		return $payload;
	}

	/** Render the bounded provider-neutral job contract. */
	private function status_payload( $job, $extra = array() ) {
		$total      = count( (array) ( $job['codes'] ?? array() ) );
		$request_id = (string) ( $job['request_id'] ?? '' );
		$lookup     = '' !== $request_id ? $request_id : (string) ( $job['job_id'] ?? '' );
		$source     = is_array( $job['operation_binding']['source'] ?? null ) ? $job['operation_binding']['source'] : array();
		$query      = '?source_id=' . rawurlencode( (string) ( $source['id'] ?? '' ) )
			. '&source_dataset=' . rawurlencode( (string) ( $source['dataset'] ?? '' ) );
		if ( '' !== (string) ( $source['revision'] ?? '' ) ) {
			$query .= '&source_revision=' . rawurlencode( (string) $source['revision'] );
		}
		$path     = '/wp-json/digitalogic/pricing/sync/jobs/' . rawurlencode( $lookup ) . $query;
		$terminal = $this->is_terminal( $job );
		$result   = $terminal && 'completed' === (string) ( $job['status'] ?? '' ) && is_array( $job['terminal_result'] ?? null )
			? $job['terminal_result']
			: null;
		$data     = array(
			'schema'                  => 'digitalogic.pricing-apply-job',
			'job_id'                  => (string) ( $job['job_id'] ?? '' ),
			'request_id'              => $request_id,
			'idempotency_key'         => $request_id,
			'status'                  => (string) ( $job['status'] ?? '' ),
			'phase'                   => (string) ( $job['phase'] ?? '' ),
			'terminal'                => $terminal,
			'expected_state_revision' => (string) ( $job['expected_state_revision'] ?? '' ),
			'preview_digest'          => (string) ( $job['operation_binding']['preview_digest'] ?? '' ),
			'source'                  => $source,
			'created_at'              => $this->format_time( $job['created_at'] ?? 0 ),
			'updated_at'              => $this->format_time( $job['updated_at'] ?? 0 ),
			'heartbeat_at'            => $this->format_time( $job['heartbeat_at'] ?? 0 ),
			'deadline_at'             => $this->format_time( $job['deadline_at'] ?? 0 ),
			'recovery_deadline_at'    => $this->format_time( $job['recovery_deadline_at'] ?? 0 ),
			'progress'                => array(
				'total_products'     => $total,
				'processed_products' => min( $total, max( 0, (int) ( $job['forward_cursor'] ?? 0 ) ) ),
				'rollback_products'  => min( max( 0, (int) ( $job['forward_cursor'] ?? 0 ) ), max( 0, (int) ( $job['rollback_cursor'] ?? 0 ) ) ),
				'completed_percent'  => $total > 0 ? (int) floor( 100 * min( $total, max( 0, (int) ( $job['forward_cursor'] ?? 0 ) ) ) / $total ) : 100,
			),
			'cancel_requested'        => ! empty( $job['cancel_requested'] ),
			'replayed'                => ! empty( $extra['replayed'] ),
			'retry_after'             => $terminal ? null : 2,
			'readback_required'       => $this->requires_authoritative_readback( $job ),
			'terminal_reason'         => (string) ( $job['terminal_reason'] ?? '' ),
			'error'                   => is_array( $job['last_error'] ?? null ) ? $job['last_error'] : null,
			'event_delivery'          => array(
				'event_id'   => (string) ( $job['outbox']['event_id'] ?? '' ),
				'state'      => (string) ( $job['outbox']['state'] ?? '' ),
				'attempts'   => (int) ( $job['outbox']['attempts'] ?? 0 ),
				'emitted_at' => (int) ( $job['outbox']['emitted_at'] ?? 0 ),
			),
			'confirmation'            => is_array( $job['confirmation'] ?? null ) ? $job['confirmation'] : null,
			'schedule_state'          => (string) ( $job['schedule_state'] ?? 'unarmed' ),
			'status_url'              => $path,
			'cancel_url'              => $path,
			'result'                  => $result,
		);
		if ( array_key_exists( 'accepted', $extra ) ) {
			$data['accepted'] = (bool) $extra['accepted'];
		}

		return $data;
	}

	/** Format a stored epoch without leaking invalid dates. */
	private function format_time( $value ) {
		$value = (int) $value;

		return $value > 0 ? gmdate( 'c', $value ) : '';
	}

	/** Run a callback under a zero-wait database advisory lock. */
	private function with_registry_lock( $callback ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return $this->error( 'digitalogic_pricing_apply_registry_unavailable', 'Pricing apply storage is unavailable.', 503 );
		}
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$name   = substr( self::LOCK_NAME . '_' . md5( $prefix ), 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Zero-wait advisory lock on this connection.
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $locked ) {
			return $this->error(
				'digitalogic_pricing_apply_registry_busy',
				'Pricing apply registry is busy.',
				503,
				array(
					'retryable'   => true,
					'retry_after' => 1,
				)
			);
		}
		try {
			return call_user_func( $callback, $this->load_registry() );
		} catch ( Throwable $exception ) {
			return $this->error(
				'digitalogic_pricing_apply_registry_failure',
				'Pricing apply registry failed.',
				500,
				array( 'exception' => get_class( $exception ) )
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release on the same live connection.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/** Load a normalized registry without migrating legacy dialects. */
	private function load_registry() {
		$registry                   = get_option( self::REGISTRY_OPTION, array() );
		$registry                   = is_array( $registry ) ? $registry : array();
		$registry['schema']         = 'digitalogic.pricing-apply-job-registry';
		$registry['jobs']           = is_array( $registry['jobs'] ?? null ) ? $registry['jobs'] : array();
		$registry['requests']       = is_array( $registry['requests'] ?? null ) ? $registry['requests'] : array();
		$registry['tombstones']     = is_array( $registry['tombstones'] ?? null ) ? $registry['tombstones'] : array();
		$registry['tombstone_jobs'] = is_array( $registry['tombstone_jobs'] ?? null ) ? $registry['tombstone_jobs'] : array();

		return $registry;
	}

	/** Persist, prune, and exactly read back the bounded registry. */
	private function save_registry( $registry ) {
		$now                    = $this->now();
		$registry['tombstones'] = is_array( $registry['tombstones'] ?? null ) ? $registry['tombstones'] : array();
		foreach ( $registry['jobs'] as $job_id => $job ) {
			if ( ! is_array( $job ) || ( $this->is_terminal( $job ) && (int) ( $job['terminal_at'] ?? 0 ) + self::TERMINAL_RETENTION < $now ) ) {
				unset( $registry['jobs'][ $job_id ] );
			}
		}
		foreach ( $registry['tombstones'] as $request_id => $tombstone ) {
			if ( ! is_array( $tombstone ) || (int) ( $tombstone['expires_at'] ?? 0 ) < $now ) {
				unset( $registry['tombstones'][ $request_id ] );
			}
		}
		if ( count( $registry['jobs'] ) > self::MAX_JOBS ) {
			uasort(
				$registry['jobs'],
				static function ( $left, $right ) {
					return (int) ( $left['updated_at'] ?? 0 ) <=> (int) ( $right['updated_at'] ?? 0 );
				}
			);
			foreach ( array_keys( $registry['jobs'] ) as $job_id ) {
				if ( count( $registry['jobs'] ) <= self::MAX_JOBS ) {
					break;
				}
				$job = $registry['jobs'][ $job_id ];
				if ( $this->is_terminal( $job ) && ! $this->requires_authoritative_readback( $job ) ) {
					$tombstone = $this->compact_tombstone( $job );
					if ( (int) $tombstone['expires_at'] >= $now ) {
						$registry['tombstones'][ $tombstone['request_id'] ] = $tombstone;
					}
					unset( $registry['jobs'][ $job_id ] );
				}
			}
		}
		foreach ( $registry['requests'] as $request_id => $request ) {
			if (
				! is_array( $request )
				|| ( ! isset( $registry['jobs'][ $request['job_id'] ] ) && ! isset( $registry['tombstones'][ $request_id ] ) )
			) {
				unset( $registry['requests'][ $request_id ] );
			}
		}
		$registry['tombstone_jobs'] = array();
		foreach ( $registry['tombstones'] as $request_id => $tombstone ) {
			$job_id = (string) ( $tombstone['job_id'] ?? '' );
			if ( '' !== $job_id ) {
				$registry['tombstone_jobs'][ $job_id ] = (string) $request_id;
			}
		}
		$registry['needs_schedule_recovery'] = false;
		foreach ( $registry['jobs'] as $job ) {
			if (
				is_array( $job )
				&& ! $this->is_terminal( $job )
				&& ( 'armed' !== (string) ( $job['schedule_state'] ?? '' ) || 'publishing' === (string) ( $job['phase'] ?? '' ) )
			) {
				$registry['needs_schedule_recovery'] = true;
				break;
			}
		}

		update_option( self::REGISTRY_OPTION, $registry, false );
		$readback = get_option( self::REGISTRY_OPTION, array() );
		if ( ! is_array( $readback ) || maybe_serialize( $registry ) !== maybe_serialize( $readback ) ) {
			return $this->error( 'digitalogic_pricing_apply_registry_write_failed', 'Pricing apply state failed durable readback.', 503 );
		}

		return true;
	}

	/** Reduce a terminal job to the exact public replay needed for seven days. */
	private function compact_tombstone( $job ) {
		$terminal_at = max( 1, (int) ( $job['terminal_at'] ?? $this->now() ) );
		$request_id  = (string) ( $job['request_id'] ?? '' );

		return array(
			'schema'              => 'digitalogic.pricing-apply-job-tombstone',
			'job_id'              => (string) ( $job['job_id'] ?? '' ),
			'request_id'          => $request_id,
			'request_fingerprint' => (string) ( $job['request_fingerprint'] ?? '' ),
			'source'              => is_array( $job['operation_binding']['source'] ?? null ) ? $job['operation_binding']['source'] : array(),
			'terminal_reason'     => (string) ( $job['terminal_reason'] ?? '' ),
			'stored_at'           => $terminal_at,
			'expires_at'          => $terminal_at + self::TERMINAL_RETENTION,
			'payload'             => $this->status_payload( $job ),
		);
	}

	/** Schedule an independent WP-Cron and Action Scheduler worker wake. */
	protected function schedule_worker( $job_id, $delay ) {
		$timestamp = $this->now() + max( 1, (int) $delay );
		$args      = array( (string) $job_id );
		$cron      = $this->schedule_cron_wake( self::WORKER_HOOK, $timestamp, $args );
		$action    = false;
		if ( (int) $delay <= 1 && function_exists( 'as_enqueue_async_action' ) ) {
			$action = (bool) as_enqueue_async_action( self::WORKER_HOOK, $args, self::ACTION_GROUP, false );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			$action = (bool) as_schedule_single_action( $timestamp, self::WORKER_HOOK, $args, self::ACTION_GROUP, false );
		}

		return $cron || $action;
	}

	/** Schedule an independent WP-Cron and Action Scheduler watchdog wake. */
	protected function schedule_watchdog( $job_id, $delay ) {
		$timestamp = $this->now() + max( 1, (int) $delay );
		$args      = array( (string) $job_id );
		$cron      = $this->schedule_cron_wake( self::WATCHDOG_HOOK, $timestamp, $args );
		$action    = false;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action = (bool) as_schedule_single_action( $timestamp, self::WATCHDOG_HOOK, $args, self::ACTION_GROUP, false );
		}

		return $cron || $action;
	}

	/** Schedule the independent WordPress safety copy. */
	private function schedule_cron_wake( $hook, $timestamp, $args ) {
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( $hook, $args ) ) {
			return true;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args, true );

		return ! is_wp_error( $scheduled ) && true === $scheduled;
	}

	/** Remove exact worker/watchdog wakes for one terminal job. */
	protected function unschedule_job( $job_id ) {
		$args = array( (string) $job_id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::WORKER_HOOK, $args, self::ACTION_GROUP );
			as_unschedule_all_actions( self::WATCHDOG_HOOK, $args, self::ACTION_GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::WORKER_HOOK, $args );
			wp_clear_scheduled_hook( self::WATCHDOG_HOOK, $args );
		}
	}

	/** Normalize settings through the production service. */
	protected function prepare_settings( $settings ) {
		return Digitalogic_Excel_Pricing_Sync::instance()->prepare_internal_settings( $settings );
	}

	/** Read canonical settings state. */
	protected function read_canonical_state() {
		return Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
	}

	/** Read the adapter-owned internal scope. */
	protected function read_pricing_scope( $source ) {
		return Digitalogic_Excel_Pricing_Sync::instance()->pricing_apply_scope( $source );
	}

	/** Resolve the exact source-bound Excel acknowledgement consumer. */
	protected function read_ack_consumer( $source ) {
		return Digitalogic_Excel_Pricing_Sync::instance()->pricing_apply_ack_consumer( $source );
	}

	/** Commit one receipt-backed settings phase. */
	protected function commit_settings_phase( $settings, $source, $expected_revision, $operation_id, $request_context, $source_context = array() ) {
		return Digitalogic_Excel_Pricing_Sync::instance()->commit_internal_settings_phase(
			$settings,
			$source,
			$expected_revision,
			$operation_id,
			$request_context,
			$source_context
		);
	}

	/** Reprice one exact receipt-backed batch. */
	protected function reprice_batch( $settings, $codes, $previous_catalog_revision, $expected_scope_revision, $expected_code_digest, $operation_id, $source ) {
		return Digitalogic_Excel_Pricing_Sync::instance()->reprice_internal_batch(
			$settings,
			$codes,
			$previous_catalog_revision,
			$expected_scope_revision,
			$expected_code_digest,
			$operation_id,
			$source
		);
	}

	/** Publish/replay the one authoritative terminal outbox. */
	protected function publish_outbox( $job ) {
		return Digitalogic_Excel_Pricing_Sync::instance()->publish_pricing_apply_outbox( $job );
	}

	/** Probe all adapter-backed pricing locks without waiting. */
	protected function probe_pricing_locks() {
		return Digitalogic_Excel_Pricing_Sync::instance()->lock_is_held();
	}

	/** Current epoch, overridable by deterministic tests. */
	protected function now() {
		return time();
	}

	/** Stable suffix-free job identity. */
	protected function new_job_id() {
		return 'job_' . substr( hash( 'sha256', $this->now() . '|' . $this->new_entropy() ), 0, 32 );
	}

	/** Entropy wrapper for tests. */
	protected function new_entropy() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $exception ) {
			return wp_generate_uuid4();
		}
	}

	/** Normalize a public job/request identity. */
	private function normalize_identifier( $identifier ) {
		if ( ! is_string( $identifier ) || 1 !== preg_match( '/\A[a-zA-Z0-9._:-]{8,128}\z/D', $identifier ) ) {
			return $this->error( 'digitalogic_pricing_apply_identifier_invalid', 'A valid request_id or job_id is required.', 400 );
		}

		return $identifier;
	}

	/** Whether the durable record proves any pricing effect. */
	private function job_has_effects( $job ) {
		return ( ! empty( $job['settings_changed'] ) && ! empty( $job['settings_committed'] ) )
			|| (int) ( $job['forward_cursor'] ?? 0 ) > 0;
	}

	/** Whether one durable record is terminal. */
	private function is_terminal( $job ) {
		return $this->is_terminal_status( (string) ( $job['status'] ?? '' ) );
	}

	/** Whether a status is terminal. */
	private function is_terminal_status( $status ) {
		return in_array( (string) $status, array( 'completed', 'failed', 'cancelled', 'rolled_back', 'outcome_unknown' ), true );
	}

	/** Whether a terminal ambiguity must fence every fresh mutation. */
	private function requires_authoritative_readback( $job ) {
		$status = (string) ( $job['status'] ?? '' );
		$reason = (string) ( $job['terminal_reason'] ?? '' );

		return 'outcome_unknown' === $status || 'readback_required' === $reason || str_ends_with( $reason, '_readback_required' );
	}

	/** Build the initial bounded repricing aggregate. */
	private function empty_aggregate() {
		return array(
			'schema'                   => 'digitalogic.pricing-apply-aggregate',
			'status'                   => 'reconciled',
			'batches'                  => 0,
			'changed_products'         => 0,
			'updated_products'         => 0,
			'already_current_products' => 0,
			'deferred_missing'         => 0,
			'deferred_ambiguous'       => 0,
			'pending_products'         => 0,
			'warning_count'            => 0,
			'warnings'                 => array(),
			'sources'                  => array(),
			'cache_plan'               => array(),
		);
	}

	/** Merge one bounded batch result; final-state gauges are replaced. */
	private function merge_aggregate( $aggregate, $result ) {
		$result = is_array( $result ) ? $result : array();
		++$aggregate['batches'];
		foreach ( array( 'changed_products', 'updated_products', 'already_current_products', 'warning_count' ) as $field ) {
			$aggregate[ $field ] = (int) ( $aggregate[ $field ] ?? 0 ) + (int) ( $result[ $field ] ?? 0 );
		}
		foreach ( array( 'deferred_missing', 'deferred_ambiguous', 'pending_products' ) as $field ) {
			$aggregate[ $field ] = (int) ( $result[ $field ] ?? 0 );
		}
		foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
			if ( count( $aggregate['warnings'] ) >= 50 ) {
				break;
			}
			if ( is_array( $warning ) ) {
				$aggregate['warnings'][] = $warning;
			}
		}
		foreach ( (array) ( $result['sources'] ?? array() ) as $source_result ) {
			if ( ! is_array( $source_result ) || ! is_array( $source_result['source'] ?? null ) ) {
				continue;
			}
			$source = $source_result['source'];
			$key    = (string) ( $source['id'] ?? '' ) . "\0" . (string) ( $source['dataset'] ?? '' );
			if ( "\0" === $key ) {
				continue;
			}
			$existing_index = null;
			foreach ( $aggregate['sources'] as $index => $existing ) {
				$existing_source = is_array( $existing['source'] ?? null ) ? $existing['source'] : array();
				$existing_key    = (string) ( $existing_source['id'] ?? '' ) . "\0" . (string) ( $existing_source['dataset'] ?? '' );
				if ( hash_equals( $key, $existing_key ) ) {
					$existing_index = $index;
					break;
				}
			}
			if ( null === $existing_index ) {
				$aggregate['sources'][] = array(
					'source'                  => $source,
					'event_id'                => (string) ( $source_result['event_id'] ?? '' ),
					'batch_count'             => 0,
					'target_products'         => 0,
					'woocommerce'             => array(
						'attempted'        => 0,
						'updated'          => 0,
						'already_applied'  => 0,
						'missing'          => 0,
						'ambiguous'        => 0,
						'failed'           => 0,
						'errors'           => array(),
						'errors_truncated' => 0,
					),
					'deferred_reconciliation' => array(),
				);
				$existing_index         = count( $aggregate['sources'] ) - 1;
			}
			$aggregate['sources'][ $existing_index ] = $this->merge_source_result( $aggregate['sources'][ $existing_index ], $source_result );
		}
		usort(
			$aggregate['sources'],
			static function ( $left, $right ) {
				return strcmp(
					wp_json_encode( $left['source'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					wp_json_encode( $right['source'] ?? array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				);
			}
		);

		return $aggregate;
	}

	/** Merge one source projection without multiplying gauges. */
	private function merge_source_result( $aggregate_source, $source_result ) {
		++$aggregate_source['batch_count'];
		$aggregate_source['target_products'] += (int) ( $source_result['target_products'] ?? 0 );
		$woocommerce                          = is_array( $source_result['woocommerce'] ?? null ) ? $source_result['woocommerce'] : array();
		foreach ( array( 'attempted', 'updated', 'already_applied', 'missing', 'ambiguous', 'failed' ) as $field ) {
			$aggregate_source['woocommerce'][ $field ] += (int) ( $woocommerce[ $field ] ?? 0 );
		}
		foreach ( (array) ( $woocommerce['errors'] ?? array() ) as $error ) {
			if ( count( $aggregate_source['woocommerce']['errors'] ) >= 50 || ! is_array( $error ) ) {
				++$aggregate_source['woocommerce']['errors_truncated'];
				continue;
			}
			$aggregate_source['woocommerce']['errors'][] = $error;
		}
		$aggregate_source['woocommerce']['errors_truncated'] += (int) ( $woocommerce['errors_truncated'] ?? 0 );
		$aggregate_source['deferred_reconciliation']          = is_array( $source_result['deferred_reconciliation'] ?? null )
			? $source_result['deferred_reconciliation']
			: array();

		return $aggregate_source;
	}

	/** Convert an error to a bounded machine-readable record. */
	private function safe_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return null;
		}
		$data = $error->get_error_data();
		$data = is_array( $data ) ? array_intersect_key(
			$data,
			array(
				'status'                    => true,
				'retryable'                 => true,
				'retry_after'               => true,
				'readback_required'         => true,
				'current_state_revision'    => true,
				'current_receiver_revision' => true,
				'phase'                     => true,
			)
		) : array();

		return array(
			'code'    => sanitize_key( (string) $error->get_error_code() ),
			'message' => substr( wp_strip_all_tags( (string) $error->get_error_message() ), 0, 300 ),
			'details' => $data,
		);
	}

	/** Whether the same exact receipt identity may be retried. */
	private function is_retryable_error( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data = $error->get_error_data();
		if ( is_array( $data ) && ( ! empty( $data['retryable'] ) || ! empty( $data['uncertain'] ) ) ) {
			return true;
		}

		return in_array(
			(string) $error->get_error_code(),
			array(
				'digitalogic_excel_sync_busy',
				'digitalogic_product_sync_busy',
				'digitalogic_pricing_apply_registry_busy',
				'digitalogic_pricing_apply_event_storage_unavailable',
				'digitalogic_excel_sync_commit_ambiguous',
				'digitalogic_excel_sync_rollback_failed',
			),
			true
		);
	}

	/** Whether an exact phase must replay its receipt before any state decision. */
	private function error_requires_phase_resolution( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['uncertain'] ) ) {
			return true;
		}

		return in_array(
			(string) $error->get_error_code(),
			array( 'digitalogic_excel_sync_commit_ambiguous', 'digitalogic_excel_sync_rollback_failed' ),
			true
		);
	}

	/** Deterministic SHA-256 identity. */
	private function digest( $value ) {
		$value = $this->sort_recursive( $value );

		return 'sha256:' . hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** Recursively sort associative keys. */
	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_recursive( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/** Build a source-scoped not-found response without leaking existence. */
	private function source_not_found_error() {
		return $this->error( 'digitalogic_pricing_apply_job_not_found', 'Pricing apply job was not found.', 404 );
	}

	/** Build a machine-readable WordPress error. */
	private function error( $code, $message, $status, $details = array() ) {
		return new WP_Error( $code, (string) $message, array_merge( array( 'status' => (int) $status ), $details ) );
	}
}
