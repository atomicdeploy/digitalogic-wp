<?php
/**
 * Audited canonical Product Code editor.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs one bounded, revision-checked Product Code edit.
 *
 * The canonical code remains owned by an active product-sync source. This
 * service only edits WooCommerce-only/unmanaged identities; a source-backed
 * row must be corrected upstream and delivered through the normal sync path.
 */
final class Digitalogic_Product_Code_Editor {

	public const SCHEMA   = 'digitalogic.product-code-edit';
	public const META_KEY = '_digitalogic_patris_product_code';

	private const AUDIT_OPTION          = 'digitalogic_product_code_edit_operations';
	private const OPERATION_OPTION_STEM = 'digitalogic_product_code_edit_';
	private const MAX_AUDIT_RECORDS     = 1024;
	private const MAX_CODE_BYTES        = 191;
	private const REQUEST_ID_PATTERN    = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D';
	private const REVISION_PATTERN      = '/\Asha256:[a-f0-9]{64}\z/D';
	private const RECORD_HASH_PATTERN   = '/\Asha256:[a-f0-9]{64}\z/D';

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cache-bypassed source state used only for one admin response.
	 *
	 * @var array|WP_Error|null
	 */
	private $editability_source_state = null;

	/**
	 * Whether the admin-response source-state cache has been populated.
	 *
	 * @var bool
	 */
	private $editability_source_state_loaded = false;

	/** Return the shared editor. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Prevent external construction. */
	private function __construct() {}

	/**
	 * Build the optimistic-concurrency revision for one exact code value.
	 *
	 * @param int    $product_id WooCommerce product or variation ID.
	 * @param string $product_code Exact code, including any leading zeroes.
	 * @return string
	 */
	public function revision_for( $product_id, $product_code ) {
		$material = array(
			'schema'       => self::SCHEMA,
			'product_id'   => (string) absint( $product_id ),
			'product_code' => (string) $product_code,
		);

		return 'sha256:' . hash(
			'sha256',
			wp_json_encode( $material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Return fail-closed editability for one admin product row.
	 *
	 * This is a UI hint only. The mutation repeats every exact guard while the
	 * shared source/identity lock is held.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $product_code Product Code rendered by WooCommerce.
	 * @return array{editable:bool,reason:string}
	 */
	public function editability_for( $product_id, $product_code ) {
		$readback = $this->read_exact_product_code( $product_id );
		if ( is_wp_error( $readback ) || ! $readback['product_exists'] ) {
			return array(
				'editable' => false,
				'reason'   => 'state_unavailable',
			);
		}
		if ( $readback['duplicate_rows'] ) {
			return array(
				'editable' => false,
				'reason'   => 'metadata_conflict',
			);
		}
		if ( ! hash_equals( (string) $product_code, $readback['product_code'] ) ) {
			return array(
				'editable' => false,
				'reason'   => 'state_changed',
			);
		}

		if ( ! $this->editability_source_state_loaded ) {
			$this->editability_source_state        = $this->read_exact_source_state();
			$this->editability_source_state_loaded = true;
		}
		if ( is_wp_error( $this->editability_source_state ) ) {
			return array(
				'editable' => false,
				'reason'   => 'source_state_unavailable',
			);
		}

		$guard = $this->source_guard(
			$product_id,
			$readback['product_code'],
			$readback['product_code'],
			$readback,
			$this->editability_source_state
		);
		if ( is_wp_error( $guard ) ) {
			return array(
				'editable' => false,
				'reason'   => 'digitalogic_product_code_source_managed' === $guard->get_error_code()
					? 'source_managed'
					: 'source_state_unavailable',
			);
		}

		return array(
			'editable' => true,
			'reason'   => '',
		);
	}

	/** Start a fresh bounded admin read batch. */
	public function reset_editability_cache() {
		$this->editability_source_state        = null;
		$this->editability_source_state_loaded = false;
	}

	/**
	 * Apply one explicit Product Code edit.
	 *
	 * Required payload fields are product_id, expected_code, product_code,
	 * if_match, and request_id. String-typed codes are required so leading
	 * zeroes cannot be lost before the service boundary.
	 *
	 * @param mixed $payload Command payload.
	 * @return array|WP_Error
	 */
	public function edit( $payload ) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error(
				'digitalogic_product_code_forbidden',
				__( 'You are not allowed to edit Product Codes.', 'digitalogic' ),
				403
			);
		}

		$validated = $this->validate_request( $payload );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $this->with_source_identity_lock(
			function () use ( $validated ) {
				return $this->with_operation_lock(
					function () use ( $validated ) {
						return $this->edit_with_operation_lock( $validated );
					}
				);
			}
		);
	}

	/**
	 * Validate one request without touching product state.
	 *
	 * @param mixed $payload Raw request.
	 * @return array|WP_Error
	 */
	private function validate_request( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->error(
				'digitalogic_product_code_payload_invalid',
				__( 'The Product Code request must be an object.', 'digitalogic' ),
				400
			);
		}

		$required = array( 'product_id', 'expected_code', 'product_code', 'if_match', 'request_id' );
		$missing  = array_values( array_diff( $required, array_keys( $payload ) ) );
		if ( ! empty( $missing ) ) {
			return $this->error(
				'digitalogic_product_code_fields_required',
				__( 'The Product Code request is missing required fields.', 'digitalogic' ),
				400,
				array( 'fields' => $missing )
			);
		}

		$product_id = is_int( $payload['product_id'] ) || is_string( $payload['product_id'] )
			? (string) $payload['product_id']
			: '';
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $product_id ) || (string) absint( $product_id ) !== $product_id ) {
			return $this->error(
				'digitalogic_product_code_product_invalid',
				__( 'An existing WooCommerce product or variation ID is required.', 'digitalogic' ),
				400
			);
		}

		$expected_code = $this->normalize_code( $payload['expected_code'], true );
		if ( is_wp_error( $expected_code ) ) {
			return $expected_code;
		}
		$product_code = $this->normalize_code( $payload['product_code'], false );
		if ( is_wp_error( $product_code ) ) {
			return $product_code;
		}

		$if_match = is_string( $payload['if_match'] ) ? $payload['if_match'] : '';
		if ( 1 !== preg_match( self::REVISION_PATTERN, $if_match ) ) {
			return $this->error(
				'digitalogic_product_code_if_match_invalid',
				__( 'A valid Product Code If-Match revision is required.', 'digitalogic' ),
				400
			);
		}

		$request_id = is_string( $payload['request_id'] ) ? $payload['request_id'] : '';
		if ( 1 !== preg_match( self::REQUEST_ID_PATTERN, $request_id ) ) {
			return $this->error(
				'digitalogic_product_code_request_id_invalid',
				__( 'request_id must contain 8 to 128 safe identifier characters.', 'digitalogic' ),
				400
			);
		}

		$request                = array(
			'product_id'    => (int) $product_id,
			'expected_code' => $expected_code,
			'product_code'  => $product_code,
			'if_match'      => $if_match,
			'request_id'    => $request_id,
		);
		$request['fingerprint'] = 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'schema'        => self::SCHEMA,
					'product_id'    => (string) $request['product_id'],
					'expected_code' => $request['expected_code'],
					'product_code'  => $request['product_code'],
					'if_match'      => $request['if_match'],
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);

		return $request;
	}

	/**
	 * Normalize an exact code without coercing numbers or trimming silently.
	 *
	 * @param mixed $value Raw value.
	 * @param bool  $allow_empty Whether the expected previous value may be empty.
	 * @return string|WP_Error
	 */
	private function normalize_code( $value, $allow_empty ) {
		if ( ! is_string( $value ) ) {
			return $this->error(
				'digitalogic_product_code_string_required',
				__( 'Product Codes must be strings so leading zeroes are preserved.', 'digitalogic' ),
				400
			);
		}
		if ( trim( $value ) !== $value ) {
			return $this->error(
				'digitalogic_product_code_whitespace_invalid',
				__( 'Product Codes cannot contain leading or trailing whitespace.', 'digitalogic' ),
				400
			);
		}
		if ( ( ! $allow_empty && '' === $value ) || strlen( $value ) > self::MAX_CODE_BYTES || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return $this->error(
				'digitalogic_product_code_value_invalid',
				__( 'The Product Code is empty, too long, or contains control characters.', 'digitalogic' ),
				400
			);
		}

		return $value;
	}

	/**
	 * Resolve idempotency before evaluating current state.
	 *
	 * @param array $request Validated request.
	 * @return array|WP_Error
	 */
	private function edit_with_operation_lock( $request ) {
		$existing = $this->operation_record( $request['request_id'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! empty( $existing ) ) {
			if ( ! hash_equals( (string) ( $existing['request_fingerprint'] ?? '' ), $request['fingerprint'] ) ) {
				return $this->error(
					'digitalogic_product_code_request_id_reused',
					__( 'That request_id is already bound to a different Product Code edit.', 'digitalogic' ),
					409
				);
			}
			$status = (string) ( $existing['status'] ?? '' );
			if ( ! $this->operation_record_is_valid( $existing, $request, $status ) ) {
				return $this->audit_unavailable();
			}
			if ( 'completed' === $status ) {
				$replay             = $existing['result'];
				$replay['replayed'] = true;
				return $replay;
			}
			if ( 'outcome_unknown' === $status ) {
				return $this->error(
					'digitalogic_product_code_outcome_unknown',
					__( 'The earlier Product Code edit has an unknown outcome and requires exact readback.', 'digitalogic' ),
					409,
					array(
						'retryable'        => false,
						'backup_reference' => (string) ( $existing['backup_reference'] ?? '' ),
					)
				);
			}
			if ( ! in_array( $status, array( 'in_progress', 'failed_retryable' ), true ) ) {
				return $this->audit_unavailable();
			}
		}

		return Digitalogic_Product_Write_Lock::instance()->with_product_lock(
			$request['product_id'],
			function () use ( $request, $existing ) {
				return $this->edit_with_product_lock( $request, $existing );
			},
			0
		);
	}

	/**
	 * Validate and mutate while both fail-fast locks are held.
	 *
	 * @param array $request Validated request.
	 * @param array $existing Existing retryable/in-progress record.
	 * @return array|WP_Error
	 */
	private function edit_with_product_lock( $request, $existing ) {
		$before = $this->read_exact_product_code( $request['product_id'] );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( ! $before['product_exists'] ) {
			return $this->error(
				'digitalogic_product_code_product_not_found',
				__( 'The WooCommerce product or variation no longer exists.', 'digitalogic' ),
				404
			);
		}
		if ( $before['duplicate_rows'] ) {
			return $this->error(
				'digitalogic_product_code_meta_conflict',
				__( 'The product has conflicting Product Code metadata rows and must be reconciled before editing.', 'digitalogic' ),
				409,
				array( 'row_count' => $before['row_count'] )
			);
		}

		$current_revision = $this->revision_for( $request['product_id'], $before['product_code'] );
		if ( ! empty( $existing ) && in_array( (string) ( $existing['status'] ?? '' ), array( 'in_progress', 'failed_retryable' ), true ) ) {
			$recovered = $this->recover_existing_operation( $request, $existing, $before, $current_revision );
			if ( null !== $recovered ) {
				return $recovered;
			}
		}

		if ( ! hash_equals( $request['expected_code'], $before['product_code'] ) ) {
			return $this->precondition_error( $before, $current_revision, 'expected_code' );
		}
		if ( ! hash_equals( $request['if_match'], $current_revision ) ) {
			return $this->precondition_error( $before, $current_revision, 'if_match' );
		}

		if ( hash_equals( $before['product_code'], $request['product_code'] ) ) {
			$result = $this->success_result( $request, $before, $before, false, '' );
			$stored = $this->complete_operation( $request, $before, $result, 1 );
			return is_wp_error( $stored ) ? $stored : $result;
		}

		$source_guard = $this->source_guard( $request['product_id'], $before['product_code'], $request['product_code'], $before );
		if ( is_wp_error( $source_guard ) ) {
			return $source_guard;
		}

		$conflicts = $this->find_code_conflicts( $request['product_id'], $request['product_code'] );
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		if ( ! empty( $conflicts ) ) {
			return $this->error(
				'digitalogic_product_code_not_unique',
				__( 'That exact Product Code already belongs to another product or variation.', 'digitalogic' ),
				409,
				array( 'woocommerce_ids' => array_column( $conflicts, 'ID' ) )
			);
		}

		$attempts         = max( 1, (int) ( $existing['attempts'] ?? 0 ) + 1 );
		$backup_reference = $this->backup_reference( $request, $before );
		$claim            = array(
			'schema'              => self::SCHEMA,
			'status'              => 'in_progress',
			'request_fingerprint' => $request['fingerprint'],
			'product_id'          => $request['product_id'],
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'backup_reference'    => $backup_reference,
			'rollback_data'       => array(
				'meta_exists'  => $before['meta_exists'],
				'product_code' => $before['product_code'],
				'revision'     => $current_revision,
			),
			'actor_id'            => get_current_user_id(),
			'attempts'            => $attempts,
			'updated_at'          => gmdate( 'c' ),
		);
		$stored           = $this->store_operation( $request['request_id'], $claim );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$updated = update_post_meta( $request['product_id'], self::META_KEY, $request['product_code'] );
		$this->invalidate_product_identity_cache( $request['product_id'] );
		$after = $this->read_exact_product_code( $request['product_id'] );
		if (
			false === $updated
			|| is_wp_error( $after )
			|| ! $this->readback_matches( $after, $request['product_code'] )
		) {
			return $this->rollback_after_failure(
				$request,
				$before,
				$claim,
				'digitalogic_product_code_readback_failed',
				__( 'The Product Code write did not pass exact database readback.', 'digitalogic' )
			);
		}

		$post_conflicts = $this->find_code_conflicts( $request['product_id'], $request['product_code'] );
		if ( is_wp_error( $post_conflicts ) || ! empty( $post_conflicts ) ) {
			return $this->rollback_after_failure(
				$request,
				$before,
				$claim,
				'digitalogic_product_code_uniqueness_readback_failed',
				__( 'The Product Code no longer passes the exact uniqueness readback.', 'digitalogic' )
			);
		}

		$result = $this->success_result( $request, $before, $after, true, $backup_reference );
		$stored = $this->complete_operation( $request, $before, $result, $attempts );
		if ( is_wp_error( $stored ) ) {
			// The exact after-state and invariants are already verified. Rolling it
			// back here could contradict a terminal record that was stored but whose
			// readback failed transiently. Keep the same request ID for recovery.
			return $this->error(
				'digitalogic_product_code_completion_pending',
				__( 'The Product Code changed, but its terminal audit readback is pending. Retry the unchanged request.', 'digitalogic' ),
				503,
				array(
					'retryable'        => true,
					'backup_reference' => $backup_reference,
				)
			);
		}

		try {
			Digitalogic_Logger::instance()->log(
				'update_product_code',
				'product',
				$request['product_id'],
				wp_json_encode(
					array(
						'product_code' => $before['product_code'],
						'revision'     => $current_revision,
					)
				),
				wp_json_encode(
					array(
						'product_code' => $after['product_code'],
						'revision'     => $result['revision'],
					)
				),
				'Updated canonical Product Code; audit ' . $backup_reference
			);
		} catch ( Throwable $exception ) {
			// The verified durable operation must still return its terminal result.
			unset( $exception );
		}
		return $result;
	}

	/**
	 * Recover an interrupted idempotent request from exact database state.
	 *
	 * Returning null means the original value is intact and a bounded retry may
	 * proceed. Any other value is a terminal response.
	 *
	 * @param array  $request Validated request.
	 * @param array  $existing Existing operation record.
	 * @param array  $current Exact database readback.
	 * @param string $current_revision Current revision.
	 * @return array|WP_Error|null
	 */
	private function recover_existing_operation( $request, $existing, $current, $current_revision ) {
		if ( $this->readback_matches( $current, $request['product_code'] ) ) {
			$source_guard = $this->source_guard(
				$request['product_id'],
				(string) ( $existing['rollback_data']['product_code'] ?? $request['expected_code'] ),
				$request['product_code'],
				$current
			);
			if ( is_wp_error( $source_guard ) ) {
				if ( 'digitalogic_product_code_source_managed' === $source_guard->get_error_code() ) {
					return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'recovery_source_conflict' );
				}

				return $source_guard;
			}

			$conflicts = $this->find_code_conflicts( $request['product_id'], $request['product_code'] );
			if ( is_wp_error( $conflicts ) ) {
				return $conflicts;
			}
			if ( ! empty( $conflicts ) ) {
				return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'recovery_uniqueness_conflict' );
			}

			$before              = array(
				'product_exists'        => true,
				'meta_exists'           => ! empty( $existing['rollback_data']['meta_exists'] ),
				'product_code'          => (string) ( $existing['rollback_data']['product_code'] ?? $request['expected_code'] ),
				'record_hash'           => '',
				'record_hash_row_count' => 0,
				'row_count'             => 1,
				'duplicate_rows'        => false,
			);
			$result              = $this->success_result(
				$request,
				$before,
				$current,
				true,
				(string) ( $existing['backup_reference'] ?? '' )
			);
			$result['recovered'] = true;
			$stored              = $this->complete_operation( $request, $before, $result, (int) ( $existing['attempts'] ?? 1 ) );
			return is_wp_error( $stored ) ? $stored : $result;
		}

		$rollback_code = (string) ( $existing['rollback_data']['product_code'] ?? '' );
		$rollback_rev  = (string) ( $existing['rollback_data']['revision'] ?? '' );
		if ( hash_equals( $rollback_code, $current['product_code'] ) && hash_equals( $rollback_rev, $current_revision ) ) {
			return null;
		}

		return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'recovery_state_mismatch' );
	}

	/**
	 * Persist an interrupted request as terminally ambiguous.
	 *
	 * @param array  $request Validated request.
	 * @param array  $existing Existing operation record.
	 * @param array  $current Exact database readback.
	 * @param string $reason Safe machine reason.
	 * @return WP_Error
	 */
	private function mark_operation_outcome_unknown( $request, $existing, $current, $reason ) {
		$unknown                       = $existing;
		$unknown['status']             = 'outcome_unknown';
		$unknown['failure_code']       = (string) $reason;
		$unknown['observed_code_hash'] = 'sha256:' . hash( 'sha256', (string) ( $current['product_code'] ?? '' ) );
		$unknown['updated_at']         = gmdate( 'c' );
		$stored                        = $this->store_operation( $request['request_id'], $unknown );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $this->error(
			'digitalogic_product_code_outcome_unknown',
			__( 'The interrupted Product Code edit cannot be finalized safely and requires exact reconciliation.', 'digitalogic' ),
			409,
			array(
				'retryable'        => false,
				'reason'           => (string) $reason,
				'backup_reference' => (string) ( $existing['backup_reference'] ?? '' ),
			)
		);
	}

	/**
	 * Reject source-owned or source-conflicting identity changes.
	 *
	 * @param int        $product_id Target ID.
	 * @param string     $expected_code Current code.
	 * @param string     $product_code Desired code.
	 * @param array      $provenance Exact current Product Code/provenance readback.
	 * @param array|null $state Exact source state supplied by a read-only UI cache.
	 * @return array|WP_Error
	 */
	private function source_guard( $product_id, $expected_code, $product_code, $provenance, $state = null ) {
		$reasons           = array();
		$sources           = array();
		$record_hash_count = is_array( $provenance ) ? (int) ( $provenance['record_hash_row_count'] ?? 0 ) : -1;
		$record_hash       = is_array( $provenance ) ? (string) ( $provenance['record_hash'] ?? '' ) : '';
		if ( $record_hash_count < 0 ) {
			return $this->source_state_malformed( 'product_provenance' );
		}
		if ( $record_hash_count > 1 ) {
			$reasons[] = 'duplicate_record_hash_provenance';
		} elseif ( 1 === $record_hash_count ) {
			if ( '' === $record_hash ) {
				$reasons[] = 'empty_record_hash_provenance';
			} else {
				$reasons[] = 1 === preg_match( self::RECORD_HASH_PATTERN, $record_hash )
					? 'managed_record_hash'
					: 'invalid_record_hash_provenance';
			}
		}
		if ( ! empty( $reasons ) ) {
			return $this->error(
				'digitalogic_product_code_source_managed',
				__( 'This Product Code is governed by the current source. Correct it upstream and deliver the reviewed source revision.', 'digitalogic' ),
				409,
				array(
					'reasons' => array_values( array_unique( $reasons ) ),
					'sources' => array(),
				)
			);
		}

		if ( null === $state ) {
			$state = $this->read_exact_source_state();
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}
		if ( ! is_array( $state ) || ! is_array( $state['sources'] ?? null ) ) {
			return $this->source_state_malformed( 'sources' );
		}

		foreach ( array_values( $state['sources'] ) as $source_index => $source_state ) {
			if ( ! is_array( $source_state ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . ']' );
			}
			if ( ! is_array( $source_state['source'] ?? null ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].source' );
			}
			$source = $source_state['source'];
			foreach ( array( 'id', 'dataset', 'revision' ) as $source_field ) {
				if ( ! is_string( $source[ $source_field ] ?? null ) || '' === $source[ $source_field ] ) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].source.' . $source_field );
				}
			}
			if ( 1 !== preg_match( self::REVISION_PATTERN, $source['revision'] ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].source.revision' );
			}
			if ( ! is_array( $source_state['products'] ?? null ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].products' );
			}
			$products = $source_state['products'];
			$matched  = false;
			foreach ( $products as $product_index => $source_product ) {
				if (
					! is_array( $source_product )
					|| ! is_string( $source_product['product_code'] ?? null )
					|| '' === $source_product['product_code']
					|| ! hash_equals( (string) $product_index, $source_product['product_code'] )
					|| ! is_string( $source_product['record_hash'] ?? null )
					|| 1 !== preg_match( self::RECORD_HASH_PATTERN, $source_product['record_hash'] )
				) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].products[' . $product_index . ']' );
				}
				$source_code = $source_product['product_code'];
				if ( hash_equals( $expected_code, $source_code ) ) {
					$reasons[] = 'current_code_in_source';
					$matched   = true;
				}
				if ( hash_equals( $product_code, $source_code ) ) {
					$reasons[] = 'desired_code_in_source';
					$matched   = true;
				}
			}
			foreach ( array( 'applied_products', 'pending_products', 'deferred_products' ) as $set_name ) {
				if ( ! is_array( $source_state[ $set_name ] ?? null ) ) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name );
				}
				foreach ( $source_state[ $set_name ] as $entry_index => $entry ) {
					if (
						! is_array( $entry )
						|| ! is_string( $entry['product_code'] ?? null )
						|| '' === $entry['product_code']
						|| ! hash_equals( (string) $entry_index, $entry['product_code'] )
						|| ! is_string( $entry['record_hash'] ?? null )
						|| 1 !== preg_match( self::RECORD_HASH_PATTERN, $entry['record_hash'] )
					) {
						return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name . '[' . $entry_index . ']' );
					}
					$entry_code = $entry['product_code'];
					$entry_id   = is_int( $entry['woocommerce_id'] ?? null ) || is_string( $entry['woocommerce_id'] ?? null )
						? (string) $entry['woocommerce_id']
						: '';
					if (
						( 'applied_products' === $set_name || array_key_exists( 'woocommerce_id', $entry ) )
						&& ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $entry_id ) || (string) absint( $entry_id ) !== $entry_id )
					) {
						return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name . '[' . $entry_index . '].woocommerce_id' );
					}
					if ( (string) $product_id === $entry_id ) {
						$reasons[] = 'product_bound_to_source';
						$matched   = true;
					}
					if ( '' !== $entry_code && hash_equals( $product_code, $entry_code ) ) {
						$reasons[] = 'desired_code_in_delivery_state';
						$matched   = true;
					}
				}
			}
			if ( $matched ) {
				$sources[] = array(
					'id'       => (string) ( $source['id'] ?? '' ),
					'dataset'  => (string) ( $source['dataset'] ?? '' ),
					'revision' => (string) ( $source['revision'] ?? '' ),
				);
			}
		}

		$reasons = array_values( array_unique( $reasons ) );
		if ( ! empty( $reasons ) ) {
			return $this->error(
				'digitalogic_product_code_source_managed',
				__( 'This Product Code is governed by the current source. Correct it upstream and deliver the reviewed source revision.', 'digitalogic' ),
				409,
				array(
					'reasons' => $reasons,
					'sources' => $sources,
				)
			);
		}

		return array( 'managed' => false );
	}

	/**
	 * Read product-sync source ownership directly from the options table.
	 *
	 * The source guard must not trust a stale object-cache entry while deciding
	 * whether a canonical identity is safe to edit.
	 *
	 * @return array|WP_Error
	 */
	private function read_exact_source_state() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $this->database_unavailable();
		}
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table name cannot be a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_source_state */
			SELECT option_value
			FROM {$options}
			WHERE option_name = %s
			LIMIT 1",
			Digitalogic_Product_Sync_Receiver::STATE_OPTION
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->database_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Source ownership must bypass object caches.
		$row = $wpdb->get_row( $query, ARRAY_A );
		if ( null === $row ) {
			if ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) ) {
				return $this->error(
					'digitalogic_product_code_source_state_unavailable',
					__( 'The product-sync source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
					503,
					array( 'retryable' => true )
				);
			}
			return array( 'sources' => array() );
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return $this->error(
				'digitalogic_product_code_source_state_unavailable',
				__( 'The product-sync source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		$state = maybe_unserialize( $row['option_value'] );
		if ( ! is_array( $state ) || ! is_array( $state['sources'] ?? null ) ) {
			return $this->error(
				'digitalogic_product_code_source_state_unavailable',
				__( 'The product-sync source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		return $state;
	}

	/**
	 * Read Product Code and provenance directly from the database.
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error
	 */
	private function read_exact_product_code( $product_id ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->database_unavailable();
		}
		$posts    = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts';
		$postmeta = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table names cannot be placeholders.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_readback */
			SELECT p.ID, p.post_type, p.post_status, pm.meta_id, pm.meta_key, pm.meta_value
			FROM {$posts} p
			LEFT JOIN {$postmeta} pm
				ON pm.post_id = p.ID
				AND pm.meta_key IN (%s, %s)
			WHERE p.ID = %d
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
			ORDER BY pm.meta_key ASC, pm.meta_id ASC",
			self::META_KEY,
			'_digitalogic_patris_record_hash',
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->database_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact cache-bypassed mutation readback is required.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $this->database_unavailable();
		}
		if ( empty( $rows ) ) {
			return array(
				'product_exists'        => false,
				'meta_exists'           => false,
				'product_code'          => '',
				'record_hash'           => '',
				'record_hash_row_count' => 0,
				'row_count'             => 0,
				'duplicate_rows'        => false,
			);
		}

		$code_rows        = array();
		$record_hash_rows = array();
		foreach ( $rows as $row ) {
			if ( self::META_KEY === (string) ( $row['meta_key'] ?? '' ) ) {
				$code_rows[] = (string) ( $row['meta_value'] ?? '' );
			} elseif ( '_digitalogic_patris_record_hash' === (string) ( $row['meta_key'] ?? '' ) ) {
				$record_hash_rows[] = (string) ( $row['meta_value'] ?? '' );
			}
		}

		return array(
			'product_exists'        => true,
			'meta_exists'           => ! empty( $code_rows ),
			'product_code'          => empty( $code_rows ) ? '' : (string) end( $code_rows ),
			'record_hash'           => empty( $record_hash_rows ) ? '' : (string) end( $record_hash_rows ),
			'record_hash_row_count' => count( $record_hash_rows ),
			'row_count'             => count( $code_rows ),
			'duplicate_rows'        => count( $code_rows ) > 1,
		);
	}

	/**
	 * Query exact collisions across products and variations.
	 *
	 * @param int    $product_id Target ID.
	 * @param string $product_code Desired code.
	 * @return array|WP_Error
	 */
	private function find_code_conflicts( $product_id, $product_code ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->database_unavailable();
		}
		$posts    = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts';
		$postmeta = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table names cannot be placeholders.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_conflicts */
			SELECT DISTINCT p.ID, p.post_type
			FROM {$postmeta} pm
			INNER JOIN {$posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND BINARY pm.meta_value = BINARY %s
				AND p.ID <> %d
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
			ORDER BY p.ID ASC
			LIMIT 3",
			self::META_KEY,
			$product_code,
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->database_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact uniqueness must bypass caches.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : $this->database_unavailable();
	}

	/**
	 * Restore the exact prior presence/value and verify it from the database.
	 *
	 * @param array $before Exact backup.
	 * @param int   $product_id Product ID.
	 * @return bool
	 */
	private function restore_backup( $before, $product_id ) {
		if ( $before['meta_exists'] ) {
			update_post_meta( $product_id, self::META_KEY, $before['product_code'] );
		} else {
			delete_post_meta( $product_id, self::META_KEY );
		}
		$this->invalidate_product_identity_cache( $product_id );
		$restored = $this->read_exact_product_code( $product_id );

		return ! is_wp_error( $restored )
			&& $restored['meta_exists'] === $before['meta_exists']
			&& hash_equals( $before['product_code'], $restored['product_code'] )
			&& ! $restored['duplicate_rows'];
	}

	/**
	 * Roll back a failed write and preserve a resumable audit state.
	 *
	 * @param array  $request Request.
	 * @param array  $before Backup.
	 * @param array  $claim In-progress record.
	 * @param string $code Typed error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private function rollback_after_failure( $request, $before, $claim, $code, $message ) {
		$rollback_verified        = $this->restore_backup( $before, $request['product_id'] );
		$claim['status']          = $rollback_verified ? 'failed_retryable' : 'outcome_unknown';
		$claim['rollback']        = array(
			'attempted' => true,
			'verified'  => $rollback_verified,
		);
		$claim['failure_code']    = $code;
		$claim['updated_at']      = gmdate( 'c' );
		$claim['observed_at_end'] = $rollback_verified ? 'before' : 'unknown';
		$this->store_operation( $request['request_id'], $claim );

		return $this->error(
			$rollback_verified ? $code : 'digitalogic_product_code_outcome_unknown',
			$rollback_verified ? $message : __( 'The Product Code rollback did not pass exact readback.', 'digitalogic' ),
			$rollback_verified ? 503 : 409,
			array(
				'retryable'         => $rollback_verified,
				'rollback_verified' => $rollback_verified,
				'backup_reference'  => (string) ( $claim['backup_reference'] ?? '' ),
			)
		);
	}

	/**
	 * Narrowly invalidate only the affected product identity caches.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function invalidate_product_identity_cache( $product_id ) {
		wp_cache_delete( (int) $product_id, 'post_meta' );
		clean_post_cache( (int) $product_id );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . (int) $product_id );
		}
	}

	/**
	 * Verify the exact intended one-row state.
	 *
	 * @param array  $readback Exact database readback.
	 * @param string $product_code Intended exact code.
	 * @return bool
	 */
	private function readback_matches( $readback, $product_code ) {
		return is_array( $readback )
			&& $readback['product_exists']
			&& $readback['meta_exists']
			&& 1 === $readback['row_count']
			&& ! $readback['duplicate_rows']
			&& hash_equals( $product_code, $readback['product_code'] );
	}

	/**
	 * Build a stable backup reference without exposing unrelated data.
	 *
	 * @param array $request Validated request.
	 * @param array $before Exact before-state.
	 * @return string
	 */
	private function backup_reference( $request, $before ) {
		return 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'schema'       => self::SCHEMA,
					'product_id'   => (string) $request['product_id'],
					'product_code' => $before['product_code'],
					'meta_exists'  => $before['meta_exists'],
					'request'      => $request['fingerprint'],
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Build the public terminal result.
	 *
	 * @param array  $request Validated request.
	 * @param array  $before Exact before-state.
	 * @param array  $after Exact after-state.
	 * @param bool   $changed Whether a write occurred.
	 * @param string $backup_reference Stable backup reference.
	 * @return array
	 */
	private function success_result( $request, $before, $after, $changed, $backup_reference ) {
		return array(
			'schema'                => self::SCHEMA,
			'status'                => $changed ? 'applied' : 'unchanged',
			'changed'               => (bool) $changed,
			'replayed'              => false,
			'product_id'            => $request['product_id'],
			'previous_product_code' => $before['product_code'],
			'product_code'          => $after['product_code'],
			'previous_revision'     => $this->revision_for( $request['product_id'], $before['product_code'] ),
			'revision'              => $this->revision_for( $request['product_id'], $after['product_code'] ),
			'request_id'            => $request['request_id'],
			'request_fingerprint'   => $request['fingerprint'],
			'backup_reference'      => $backup_reference,
			'verification'          => array(
				'database_readback' => true,
				'cache_bypassed'    => true,
				'unique'            => true,
			),
		);
	}

	/**
	 * Complete and verify one durable operation record.
	 *
	 * @param array $request Validated request.
	 * @param array $before Exact before-state.
	 * @param array $result Public terminal result.
	 * @param int   $attempts Attempt count.
	 * @return true|WP_Error
	 */
	private function complete_operation( $request, $before, $result, $attempts ) {
		$record = array(
			'schema'              => self::SCHEMA,
			'status'              => 'completed',
			'request_fingerprint' => $request['fingerprint'],
			'product_id'          => $request['product_id'],
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'backup_reference'    => $result['backup_reference'],
			'rollback_data'       => array(
				'meta_exists'  => $before['meta_exists'],
				'product_code' => $before['product_code'],
				'revision'     => $this->revision_for( $request['product_id'], $before['product_code'] ),
			),
			'actor_id'            => get_current_user_id(),
			'attempts'            => max( 1, (int) $attempts ),
			'result'              => $result,
			'updated_at'          => gmdate( 'c' ),
		);

		return $this->store_operation( $request['request_id'], $record );
	}

	/**
	 * Return one operation from the bounded durable ledger.
	 *
	 * @param string $request_id Idempotency key.
	 * @return array|WP_Error
	 */
	private function operation_record( $request_id ) {
		$stored = $this->read_exact_option( $this->operation_option_name( $request_id ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! $stored['exists'] ) {
			return array();
		}

		return is_array( $stored['value'] ) ? $stored['value'] : $this->audit_unavailable();
	}

	/**
	 * Validate a durable idempotency record before trusting or recovering it.
	 *
	 * @param array  $record Stored operation record.
	 * @param array  $request Validated request.
	 * @param string $status Stored status.
	 * @return bool
	 */
	private function operation_record_is_valid( $record, $request, $status ) {
		if (
			! is_array( $record )
			|| self::SCHEMA !== (string) ( $record['schema'] ?? '' )
			|| ! in_array( $status, array( 'completed', 'in_progress', 'failed_retryable', 'outcome_unknown' ), true )
			|| (int) ( $record['product_id'] ?? 0 ) !== $request['product_id']
			|| ! hash_equals( $request['fingerprint'], (string) ( $record['request_fingerprint'] ?? '' ) )
			|| ! hash_equals( $request['expected_code'], (string) ( $record['expected_code'] ?? '' ) )
			|| ! hash_equals( $request['product_code'], (string) ( $record['product_code'] ?? '' ) )
			|| ! hash_equals( $request['if_match'], (string) ( $record['if_match'] ?? '' ) )
			|| (int) ( $record['attempts'] ?? 0 ) < 1
			|| ! is_array( $record['rollback_data'] ?? null )
			|| ! array_key_exists( 'meta_exists', $record['rollback_data'] )
			|| ! is_bool( $record['rollback_data']['meta_exists'] )
			|| ! hash_equals( $request['expected_code'], (string) ( $record['rollback_data']['product_code'] ?? '' ) )
			|| ! hash_equals( $request['if_match'], (string) ( $record['rollback_data']['revision'] ?? '' ) )
		) {
			return false;
		}

		$backup_reference = (string) ( $record['backup_reference'] ?? '' );
		if ( '' !== $backup_reference && 1 !== preg_match( self::REVISION_PATTERN, $backup_reference ) ) {
			return false;
		}
		if ( 'completed' !== $status ) {
			return '' !== $backup_reference;
		}

		$result = $record['result'] ?? null;
		if ( ! is_array( $result ) ) {
			return false;
		}
		$result_status = (string) ( $result['status'] ?? '' );
		$changed       = $result['changed'] ?? null;
		$verification  = $result['verification'] ?? null;

		return self::SCHEMA === (string) ( $result['schema'] ?? '' )
			&& in_array( $result_status, array( 'applied', 'unchanged' ), true )
			&& is_bool( $changed )
			&& ( ( 'applied' === $result_status ) === $changed )
			&& false === ( $result['replayed'] ?? null )
			&& (int) ( $result['product_id'] ?? 0 ) === $request['product_id']
			&& hash_equals( $request['expected_code'], (string) ( $result['previous_product_code'] ?? '' ) )
			&& hash_equals( $request['product_code'], (string) ( $result['product_code'] ?? '' ) )
			&& hash_equals( $request['if_match'], (string) ( $result['previous_revision'] ?? '' ) )
			&& hash_equals( $this->revision_for( $request['product_id'], $request['product_code'] ), (string) ( $result['revision'] ?? '' ) )
			&& hash_equals( $request['request_id'], (string) ( $result['request_id'] ?? '' ) )
			&& hash_equals( $request['fingerprint'], (string) ( $result['request_fingerprint'] ?? '' ) )
			&& hash_equals( $backup_reference, (string) ( $result['backup_reference'] ?? '' ) )
			&& is_array( $verification )
			&& true === ( $verification['database_readback'] ?? null )
			&& true === ( $verification['cache_bypassed'] ?? null )
			&& true === ( $verification['unique'] ?? null );
	}

	/**
	 * Store and read back one authoritative per-request operation record.
	 *
	 * The capped aggregate is only a best-effort navigation index. It must not
	 * turn a verified canonical record into a failed mutation: doing so could
	 * roll back the product after a terminal result had already become replayable.
	 *
	 * @param string $request_id Idempotency key.
	 * @param array  $record Operation record.
	 * @return true|WP_Error
	 */
	private function store_operation( $request_id, $record ) {
		$key             = hash( 'sha256', $request_id );
		$operation_name  = $this->operation_option_name( $request_id );
		$operation_write = update_option( $operation_name, $record, false );
		wp_cache_delete( $operation_name, 'options' );
		$operation_readback = $this->read_exact_option( $operation_name );
		if (
			is_wp_error( $operation_readback )
			|| ! $operation_readback['exists']
			|| ! is_array( $operation_readback['value'] )
			|| $operation_readback['value'] !== $record
			|| ( false === $operation_write && ! $operation_readback['exists'] )
		) {
			return $this->audit_unavailable();
		}

		$ledger = $this->read_exact_audit_ledger();
		if ( is_wp_error( $ledger ) ) {
			return true;
		}
		$operations = is_array( $ledger['operations'] ?? null )
			? $ledger['operations']
			: array();
		unset( $operations[ $key ] );
		$operations = array( $key => $record ) + $operations;
		$operations = array_slice( $operations, 0, self::MAX_AUDIT_RECORDS, true );
		$next       = array(
			'schema'     => self::SCHEMA,
			'operations' => $operations,
		);

		update_option( self::AUDIT_OPTION, $next, false );
		wp_cache_delete( self::AUDIT_OPTION, 'options' );
		return true;
	}

	/**
	 * Return the non-autoloaded durable option name for one request ID.
	 *
	 * The request identifier itself is never stored in an option name. Terminal
	 * records are not automatically pruned, so eviction from the bounded audit
	 * summary cannot make an old idempotency key executable again.
	 *
	 * @param string $request_id Exact request identifier.
	 * @return string
	 */
	private function operation_option_name( $request_id ) {
		return self::OPERATION_OPTION_STEM . hash( 'sha256', (string) $request_id );
	}

	/**
	 * Read one exact option directly from the database.
	 *
	 * @param string $option_name Sanitized internal option name.
	 * @return array|WP_Error Array with exists/value, or a typed failure.
	 */
	private function read_exact_option( $option_name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $this->audit_unavailable();
		}
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table name cannot be a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_operation_readback */
			SELECT option_value
			FROM {$options}
			WHERE option_name = %s
			LIMIT 1",
			(string) $option_name
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->audit_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact idempotency readback must bypass object caches.
		$row = $wpdb->get_row( $query, ARRAY_A );
		if ( null === $row ) {
			if ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) ) {
				return $this->audit_unavailable();
			}

			return array(
				'exists' => false,
				'value'  => null,
			);
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return $this->audit_unavailable();
		}

		return array(
			'exists' => true,
			'value'  => maybe_unserialize( $row['option_value'] ),
		);
	}

	/**
	 * Read the idempotency/audit ledger directly from the options table.
	 *
	 * A stale object-cache value must never cause a completed request to be
	 * executed again or cause another operation record to be discarded.
	 *
	 * @return array|WP_Error
	 */
	private function read_exact_audit_ledger() {
		$stored = $this->read_exact_option( self::AUDIT_OPTION );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! $stored['exists'] ) {
			return array(
				'schema'     => self::SCHEMA,
				'operations' => array(),
			);
		}
		$ledger = $stored['value'];
		if (
			! is_array( $ledger )
			|| self::SCHEMA !== (string) ( $ledger['schema'] ?? '' )
			|| ! is_array( $ledger['operations'] ?? null )
		) {
			return $this->audit_unavailable();
		}

		return $ledger;
	}

	/** Typed audit storage/readback failure. */
	private function audit_unavailable() {
		return $this->error(
			'digitalogic_product_code_audit_unavailable',
			__( 'The Product Code audit ledger is unavailable.', 'digitalogic' ),
			503,
			array( 'retryable' => true )
		);
	}

	/**
	 * Fail closed when nested source ownership evidence is malformed.
	 *
	 * @param string $location Safe structural location.
	 * @return WP_Error
	 */
	private function source_state_malformed( $location ) {
		return $this->error(
			'digitalogic_product_code_source_state_malformed',
			__( 'The product-sync source state is malformed, so the identity edit was stopped.', 'digitalogic' ),
			503,
			array(
				'retryable' => true,
				'location'  => (string) $location,
			)
		);
	}

	/**
	 * Build an exact precondition failure without mutating state.
	 *
	 * @param array  $before Exact current state.
	 * @param string $current_revision Exact current revision.
	 * @param string $field Failed precondition field.
	 * @return WP_Error
	 */
	private function precondition_error( $before, $current_revision, $field ) {
		return $this->error(
			'digitalogic_product_code_precondition_failed',
			__( 'The Product Code changed after it was loaded. Reload the row before editing.', 'digitalogic' ),
			412,
			array(
				'failed_field'     => $field,
				'current_code'     => $before['product_code'],
				'current_revision' => $current_revision,
				'retryable'        => false,
			)
		);
	}

	/**
	 * Run under the receiver's source-identity lock without waiting.
	 *
	 * The receiver acquires this lock before it can mutate its durable source
	 * state or WooCommerce identities. Sharing it closes the cross-product race
	 * between source delivery and the final uniqueness readback here.
	 *
	 * @param callable $callback Critical section.
	 * @return mixed|WP_Error
	 */
	private function with_source_identity_lock( $callback ) {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$locked   = $receiver->acquire_source_identity_lock( 0 );
		if ( is_wp_error( $locked ) ) {
			if ( 'digitalogic_product_sync_busy' !== $locked->get_error_code() ) {
				return $locked;
			}

			return $this->error(
				'digitalogic_product_code_source_busy',
				__( 'The catalog source is updating Product Codes. Retry the unchanged request.', 'digitalogic' ),
				503,
				array(
					'retryable'   => true,
					'retry_after' => 1,
				)
			);
		}

		try {
			return call_user_func( $callback );
		} finally {
			$receiver->release_source_identity_lock();
		}
	}

	/**
	 * Run under a site-scoped fail-fast identity/audit lock.
	 *
	 * @param callable $callback Critical section.
	 * @return mixed|WP_Error
	 */
	private function with_operation_lock( $callback ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->database_unavailable();
		}
		$prefix    = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$lock_name = substr( 'digitalogic_code_edit_' . md5( $prefix ), 0, 64 );
		$prepared  = $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory lock is connection state.
		$locked = false !== $prepared ? $wpdb->get_var( $prepared ) : false;
		if ( 1 !== (int) $locked ) {
			return $this->error(
				'digitalogic_product_code_busy',
				__( 'Another Product Code edit is active. Retry the unchanged request.', 'digitalogic' ),
				503,
				array(
					'retryable'   => true,
					'retry_after' => 1,
				)
			);
		}

		try {
			return call_user_func( $callback );
		} finally {
			$release = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name );
			if ( false !== $release ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Release the connection-scoped advisory lock.
				$wpdb->get_var( $release );
			}
		}
	}

	/** Typed database availability failure. */
	private function database_unavailable() {
		return $this->error(
			'digitalogic_product_code_database_unavailable',
			__( 'The exact Product Code database readback is unavailable.', 'digitalogic' ),
			503,
			array( 'retryable' => true )
		);
	}

	/**
	 * Construct one machine-readable error.
	 *
	 * @param string $code Machine code.
	 * @param string $message Human-readable message.
	 * @param int    $status HTTP status.
	 * @param array  $data Additional safe details.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status, $data = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => (int) $status,
					'schema' => self::SCHEMA,
				),
				$data
			)
		);
	}
}
