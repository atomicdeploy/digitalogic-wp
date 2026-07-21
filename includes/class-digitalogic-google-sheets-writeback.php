<?php
/**
 * Safe, bounded Google Sheets change-preview and apply service.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies an explicit, optimistic-concurrency-controlled Sheets change set.
 *
 * Products and Categories remain read-only catalog projections. This service
 * accepts changes only through a separate contract with a deliberately small
 * allowlist and exact Patris Code identity.
 */
final class Digitalogic_Google_Sheets_Writeback {

	public const SCHEMA      = 'digitalogic.google-sheets-writeback';
	public const MAX_CHANGES = 50;

	private const IDEMPOTENCY_TTL = 604800;
	private const LOCK_TTL        = 120;
	private const RESULT_PREFIX   = 'digitalogic_gswb_result_';
	private const LOCK_PREFIX     = 'digitalogic_gswb_lock_';

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Per-process entropy for idempotency owner tokens.
	 *
	 * @var int
	 */
	private static $owner_sequence = 0;

	/**
	 * Return the shared service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return the exact editable field contract.
	 *
	 * @return array
	 */
	public function get_allowed_fields() {
		return array(
			'regular_price'      => 'positive_decimal',
			'sale_price'         => 'nullable_positive_decimal',
			'stock_quantity'     => 'non_negative_integer',
			'stock_status'       => 'stock_status',
			'shipping_method_id' => 'nullable_shipping_method_id',
			'profit_percent'     => 'nullable_percentage',
		);
	}

	/**
	 * Validate and project a change set without mutating products.
	 *
	 * @param array $payload Decoded JSON object.
	 * @return array|WP_Error
	 */
	public function preview( $payload ) {
		return $this->process( $payload, 'preview' );
	}

	/**
	 * Apply a validated change set with row-level concurrency checks.
	 *
	 * @param array $payload Decoded JSON object.
	 * @return array|WP_Error
	 */
	public function apply( $payload ) {
		return $this->process( $payload, 'apply' );
	}

	/**
	 * Process one preview or apply request.
	 *
	 * @param array  $payload Request body.
	 * @param string $mode    preview or apply.
	 * @return array|WP_Error
	 */
	private function process( $payload, $mode ) {
		$normalized = $this->normalize_envelope( $payload );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$request_hash = 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'mode'    => $mode,
					'changes' => $normalized['canonical_changes'],
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
		$claim        = $this->claim_idempotency( $mode, $normalized['idempotency_key'], $request_hash );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( isset( $claim['replay'] ) ) {
			$replay             = $claim['replay'];
			$replay['replayed'] = true;

			return $replay;
		}

		$prepared = $this->prepare_changes( $normalized['changes'], $claim );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$results = array();
		foreach ( $prepared as $row ) {
			$heartbeat = $this->heartbeat_idempotency( $claim );
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
			if ( 'apply' === $mode && 'ready' === $row['result']['status'] ) {
				$results[] = $this->apply_prepared_change(
					$row,
					$normalized['idempotency_key']
				);
			} else {
				$results[] = $row['result'];
			}
			$heartbeat = $this->heartbeat_idempotency( $claim );
			if ( is_wp_error( $heartbeat ) ) {
				return $heartbeat;
			}
		}

		$data = array(
			'schema'          => self::SCHEMA,
			'schema_version'  => '1.0',
			'mode'            => $mode,
			'idempotency_key' => $normalized['idempotency_key'],
			'replayed'        => false,
			'allowed_fields'  => array_keys( $this->get_allowed_fields() ),
			'maximum_changes' => self::MAX_CHANGES,
			'summary'         => $this->summarize( count( $normalized['changes'] ), $results ),
			'results'         => $results,
		);

		$completed = $this->complete_idempotency( $claim, $request_hash, $data );
		if ( is_wp_error( $completed ) ) {
			return $completed;
		}

		return $data;
	}

	/**
	 * Validate the batch envelope and build a stable field order.
	 *
	 * @param mixed $payload Request body.
	 * @return array|WP_Error
	 */
	private function normalize_envelope( $payload ) {
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			return $this->error(
				'digitalogic_sheets_writeback_shape_invalid',
				__( 'The write-back body must be a JSON object.', 'digitalogic' ),
				400
			);
		}
		$unknown_envelope_keys = array_diff( array_keys( $payload ), array( 'idempotency_key', 'changes' ) );
		if ( $unknown_envelope_keys ) {
			return $this->error(
				'digitalogic_sheets_writeback_envelope_field_forbidden',
				__( 'The write-back envelope contains unsupported fields.', 'digitalogic' ),
				400,
				array( 'fields' => array_values( array_map( 'strval', $unknown_envelope_keys ) ) )
			);
		}

		$idempotency_key = $payload['idempotency_key'] ?? null;
		if ( ! is_string( $idempotency_key ) ) {
			return $this->error(
				'digitalogic_sheets_writeback_idempotency_required',
				__( 'A string idempotency_key is required.', 'digitalogic' ),
				400
			);
		}
		$idempotency_key = trim( $idempotency_key );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $idempotency_key ) ) {
			return $this->error(
				'digitalogic_sheets_writeback_idempotency_invalid',
				__( 'The idempotency_key must contain 8 to 128 safe identifier characters.', 'digitalogic' ),
				400
			);
		}

		$changes = $payload['changes'] ?? null;
		if ( ! is_array( $changes ) || ! array_is_list( $changes ) || empty( $changes ) ) {
			return $this->error(
				'digitalogic_sheets_writeback_changes_invalid',
				__( 'changes must be a non-empty ordered JSON list.', 'digitalogic' ),
				400
			);
		}
		if ( count( $changes ) > self::MAX_CHANGES ) {
			return $this->error(
				'digitalogic_sheets_writeback_too_large',
				sprintf(
					/* translators: %d: maximum changes per request. */
					__( 'Google Sheets write-back requests are limited to %d rows.', 'digitalogic' ),
					self::MAX_CHANGES
				),
				413,
				array( 'maximum_changes' => self::MAX_CHANGES )
			);
		}

		$stable = array();
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && isset( $change['fields'] ) && is_array( $change['fields'] ) ) {
				ksort( $change['fields'], SORT_STRING );
			}
			$stable[] = $change;
		}

		return array(
			'idempotency_key'   => $idempotency_key,
			'changes'           => $stable,
			'canonical_changes' => $this->canonicalize_request_changes( $stable ),
		);
	}

	/**
	 * Build typed, order-independent request material for idempotency hashing.
	 *
	 * @param array $changes Normalized request rows.
	 * @return array
	 */
	private function canonicalize_request_changes( $changes ) {
		$canonical = array();
		foreach ( $changes as $change ) {
			$row = $this->canonicalize_hash_value( $change );
			if ( is_array( $change ) && isset( $change['fields'] ) && is_array( $change['fields'] ) && ! array_is_list( $change['fields'] ) ) {
				$fields = $this->normalize_fields( $change['fields'] );
				if ( ! is_wp_error( $fields ) ) {
					$row['fields'] = $fields;
				}
			}
			if ( isset( $row['sync_key'] ) && is_string( $row['sync_key'] ) ) {
				$row['sync_key'] = trim( $row['sync_key'] );
			}
			if ( isset( $row['patris_code'] ) && is_string( $row['patris_code'] ) ) {
				$row['patris_code'] = trim( $row['patris_code'] );
			}
			$canonical[] = $this->canonicalize_hash_value( $row );
		}
		return $canonical;
	}

	/**
	 * Recursively sort object keys while preserving JSON-list order.
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return mixed
	 */
	private function canonicalize_hash_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize_hash_value' ), $value );
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->canonicalize_hash_value( $child );
		}

		return $value;
	}

	/**
	 * Preflight every row and retain private application state separately.
	 *
	 * @param array      $changes Normalized ordered changes.
	 * @param array|null $claim   Active idempotency owner claim.
	 * @return array
	 */
	private function prepare_changes( $changes, $claim = null ) {
		$sync_key_counts = array();
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && is_scalar( $change['sync_key'] ?? null ) ) {
				$key = trim( (string) $change['sync_key'] );
				if ( '' !== $key ) {
					$sync_key_counts[ $key ] = 1 + ( $sync_key_counts[ $key ] ?? 0 );
				}
			}
		}

		$prepared = array();
		foreach ( array_values( $changes ) as $index => $change ) {
			if ( is_array( $claim ) ) {
				$heartbeat = $this->heartbeat_idempotency( $claim );
				if ( is_wp_error( $heartbeat ) ) {
					return $heartbeat;
				}
			}
			$sync_key   = is_array( $change ) && is_scalar( $change['sync_key'] ?? null )
				? trim( (string) $change['sync_key'] )
				: '';
			$prepared[] = $this->prepare_change(
				$change,
				$index,
				'' !== $sync_key && 1 < ( $sync_key_counts[ $sync_key ] ?? 0 )
			);
			if ( is_array( $claim ) ) {
				$heartbeat = $this->heartbeat_idempotency( $claim );
				if ( is_wp_error( $heartbeat ) ) {
					return $heartbeat;
				}
			}
		}

		return $prepared;
	}

	/**
	 * Preflight one row.
	 *
	 * @param mixed $change       Change object.
	 * @param int   $index        Request row index.
	 * @param bool  $is_duplicate Whether the sync key is duplicated.
	 * @return array
	 */
	private function prepare_change( $change, $index, $is_duplicate ) {
		if ( ! is_array( $change ) || array_is_list( $change ) ) {
			return $this->prepared_error( $index, '', '', 'invalid', 'row_shape_invalid', __( 'Each change must be a JSON object.', 'digitalogic' ) );
		}
		$unknown_row_keys = array_diff(
			array_keys( $change ),
			array( 'sync_key', 'patris_code', 'expected_record_revision', 'fields' )
		);
		if ( $unknown_row_keys ) {
			return $this->prepared_error(
				$index,
				'',
				'',
				'invalid',
				'row_field_forbidden',
				__( 'The change row contains unsupported fields.', 'digitalogic' ),
				array( 'forbidden_fields' => array_values( array_map( 'strval', $unknown_row_keys ) ) )
			);
		}

		$sync_key    = $this->strict_identifier( $change['sync_key'] ?? null );
		$patris_code = $this->strict_identifier( $change['patris_code'] ?? null );
		if ( null === $sync_key || null === $patris_code ) {
			return $this->prepared_error( $index, (string) ( $sync_key ?? '' ), (string) ( $patris_code ?? '' ), 'invalid', 'identity_invalid', __( 'sync_key and patris_code must be non-empty exact strings.', 'digitalogic' ) );
		}
		if ( $sync_key !== $patris_code ) {
			return $this->prepared_error( $index, $sync_key, $patris_code, 'invalid', 'identity_mismatch', __( 'sync_key must exactly equal patris_code for editable rows.', 'digitalogic' ) );
		}
		if ( $is_duplicate ) {
			return $this->prepared_error( $index, $sync_key, $patris_code, 'invalid', 'duplicate_sync_key', __( 'Duplicate sync_key values are not allowed in one change set.', 'digitalogic' ) );
		}

		$expected_revision = $change['expected_record_revision'] ?? null;
		if ( ! is_string( $expected_revision ) || ! preg_match( '/^sha256:[0-9a-f]{64}$/', $expected_revision ) ) {
			return $this->prepared_error( $index, $sync_key, $patris_code, 'invalid', 'expected_revision_invalid', __( 'expected_record_revision must be a sha256 catalog revision.', 'digitalogic' ) );
		}

		$fields = $change['fields'] ?? null;
		if ( ! is_array( $fields ) || array_is_list( $fields ) || empty( $fields ) ) {
			return $this->prepared_error( $index, $sync_key, $patris_code, 'invalid', 'fields_invalid', __( 'fields must be a non-empty JSON object.', 'digitalogic' ) );
		}
		if ( count( $fields ) > count( $this->get_allowed_fields() ) ) {
			return $this->prepared_error( $index, $sync_key, $patris_code, 'invalid', 'fields_too_large', __( 'Too many editable fields were supplied.', 'digitalogic' ) );
		}

		$desired = $this->normalize_fields( $fields );
		if ( is_wp_error( $desired ) ) {
			return $this->prepared_error_from_wp_error( $index, $sync_key, $patris_code, $desired );
		}

		$projected = $this->project_product( $patris_code );
		if ( is_wp_error( $projected ) ) {
			return $this->prepared_error_from_wp_error( $index, $sync_key, $patris_code, $projected );
		}
		if ( $projected['row']['sync_key'] !== $sync_key || ( $projected['row']['patris_code'] ?? null ) !== $patris_code ) {
			return $this->prepared_error( $index, $sync_key, $patris_code, 'invalid', 'resolved_identity_mismatch', __( 'The exact Patris Code did not resolve to the requested catalog row.', 'digitalogic' ) );
		}

		$current_revision = (string) $projected['row']['record_revision'];
		$current_values   = $this->editable_values( $projected['product'], $projected['row'] );
		$requested_before = array_intersect_key( $current_values, $desired );
		if ( array_key_exists( 'profit_percent', $desired ) ) {
			$profit_state = $this->capture_profit_state( $projected['product'] );
			if ( ! $this->profit_state_is_allowlist_representable( $profit_state ) ) {
				$requested_before['profit_percent_state'] = $this->public_profit_state( $profit_state );
			}
		}
		if ( $expected_revision !== $current_revision ) {
			$result                    = $this->base_result( $index, $sync_key, $patris_code );
			$result['woocommerce_id']  = $projected['product_id'];
			$result['status']          = 'conflict';
			$result['code']            = 'record_revision_conflict';
			$result['message']         = __( 'The product changed after this Sheet row was read. Refresh before applying.', 'digitalogic' );
			$result['before']          = $this->present_values( $requested_before );
			$result['after']           = $this->present_values( $desired );
			$result['record_revision'] = $current_revision;

			return array( 'result' => $result );
		}

		$changed_fields = array();
		foreach ( $desired as $field => $value ) {
			$clears_existing_profit_state = 'profit_percent' === $field
				&& null === $value
				&& (
					metadata_exists( 'post', $projected['product_id'], '_digitalogic_markup' )
					|| metadata_exists( 'post', $projected['product_id'], '_digitalogic_markup_type' )
				);
			if ( $clears_existing_profit_state || ! $this->field_values_equal( $field, $current_values[ $field ], $value ) ) {
				$changed_fields[] = $field;
			}
		}

		$result                    = $this->base_result( $index, $sync_key, $patris_code );
		$result['woocommerce_id']  = $projected['product_id'];
		$result['before']          = $this->present_values( $requested_before );
		$result['after']           = $this->present_values( $desired );
		$result['record_revision'] = $current_revision;
		$result['changed_fields']  = $changed_fields;
		if ( empty( $changed_fields ) ) {
			$result['status']  = 'unchanged';
			$result['code']    = 'unchanged';
			$result['message'] = __( 'The requested values already match the current product.', 'digitalogic' );

			return array( 'result' => $result );
		}

		$context_validation = $this->validate_product_context( $projected['product'], $desired, $changed_fields );
		if ( is_wp_error( $context_validation ) ) {
			return $this->prepared_error_from_wp_error(
				$index,
				$sync_key,
				$patris_code,
				$context_validation,
				array(
					'woocommerce_id'  => $projected['product_id'],
					'before'          => $this->present_values( $requested_before ),
					'after'           => $this->present_values( $desired ),
					'record_revision' => $current_revision,
				)
			);
		}

		$result['status']  = 'ready';
		$result['code']    = 'ready';
		$result['message'] = __( 'The change passed identity, type, and revision checks.', 'digitalogic' );

		return array(
			'result'            => $result,
			'desired'           => $desired,
			'changed_fields'    => $changed_fields,
			'expected_revision' => $expected_revision,
			'product_id'        => $projected['product_id'],
		);
	}

	/**
	 * Apply one preflighted row and compensate on a later failure.
	 *
	 * @param array  $prepared       Prepared row.
	 * @param string $idempotency_key Request key for audit correlation.
	 * @return array
	 */
	private function apply_prepared_change( $prepared, $idempotency_key ) {
		global $wpdb;
		$result         = $prepared['result'];
		$prefix         = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$sync_lock_name = substr( 'digitalogic_product_sync_' . md5( $prefix ), 0, 64 );
		if ( ! $this->acquire_named_advisory_lock( $sync_lock_name, 15 ) ) {
			$result['status']    = 'failed';
			$result['code']      = 'product_sync_lock_busy';
			$result['message']   = __( 'A Patris product sync is in progress. Retry the unchanged request.', 'digitalogic' );
			$result['retryable'] = true;

			return $result;
		}

		try {
			$locked = Digitalogic_Product_Write_Lock::instance()->with_product_lock(
				$prepared['product_id'],
				function () use ( $prepared, $idempotency_key ) {
					return $this->apply_prepared_change_locked( $prepared, $idempotency_key );
				},
				5
			);
			if ( is_wp_error( $locked ) ) {
				$result = $this->application_error_result( $result, $locked );
				$data   = $locked->get_error_data();
				if ( is_array( $data ) && ! empty( $data['retryable'] ) ) {
					$result['retryable'] = true;
				}
				return $result;
			}

			return $locked;
		} finally {
			$this->release_named_advisory_lock( $sync_lock_name );
		}
	}

	/**
	 * Apply one row while its product advisory lock is held.
	 *
	 * @param array  $prepared        Prepared row.
	 * @param string $idempotency_key Request key for audit correlation.
	 * @return array
	 */
	private function apply_prepared_change_locked( $prepared, $idempotency_key ) {
		$result      = $prepared['result'];
		$patris_code = $result['patris_code'];
		$projected   = $this->project_product( $patris_code );
		if ( is_wp_error( $projected ) ) {
			return $this->application_error_result( $result, $projected );
		}
		if ( (int) $prepared['product_id'] !== (int) $projected['product_id'] ) {
			$result['status']    = 'conflict';
			$result['code']      = 'product_identity_conflict';
			$result['message']   = __( 'The Patris Code resolved to a different product during apply. No fields were written.', 'digitalogic' );
			$result['retryable'] = false;

			return $result;
		}
		if ( $prepared['expected_revision'] !== (string) $projected['row']['record_revision'] ) {
			$result['status']          = 'conflict';
			$result['code']            = 'record_revision_conflict';
			$result['message']         = __( 'The product changed during apply. No fields were written.', 'digitalogic' );
			$result['record_revision'] = (string) $projected['row']['record_revision'];

			return $result;
		}

		$product        = $projected['product'];
		$snapshot       = $this->capture_snapshot( $product, $projected['row'] );
		$product_fields = array_values( array_diff( $prepared['changed_fields'], array( 'shipping_method_id' ) ) );
		$applied_fields = $product_fields;
		$failure        = null;
		try {
			$this->apply_product_fields( $product, $prepared['desired'], $prepared['changed_fields'] );
			if ( in_array( 'shipping_method_id', $prepared['changed_fields'], true ) ) {
				$shipping = Digitalogic_Shipping_Method_Service::instance()->compare_and_assign_product_by_code(
					$patris_code,
					$snapshot['shipping_method_id'],
					$prepared['desired']['shipping_method_id']
				);
				if ( is_wp_error( $shipping ) ) {
					$failure = $shipping;
				} elseif ( ! empty( $shipping['changed'] ) ) {
					$applied_fields[] = 'shipping_method_id';
				}
			}
		} catch ( Throwable $throwable ) {
			$failure = $this->error(
				'digitalogic_sheets_writeback_apply_failed',
				__( 'WooCommerce could not save the requested product fields.', 'digitalogic' ),
				500
			);
		}

		if ( is_wp_error( $failure ) ) {
			$rollback           = $this->restore_snapshot(
				$patris_code,
				$snapshot,
				$applied_fields,
				$prepared['desired']
			);
			$result             = $this->application_error_result( $result, $failure );
			$result['rollback'] = $rollback;

			return $result;
		}

		$after = $this->project_product( $patris_code );
		if ( is_wp_error( $after ) ) {
			$rollback           = $this->restore_snapshot( $patris_code, $snapshot, $applied_fields, $prepared['desired'] );
			$result['status']   = 'failed';
			$result['code']     = 'post_apply_verification_failed';
			$result['message']  = __( 'The product could not be projected after the write; compensation was attempted.', 'digitalogic' );
			$result['rollback'] = $rollback;

			return $result;
		}

		$after_values = $this->editable_values( $after['product'], $after['row'] );
		$after_subset = array_intersect_key( $after_values, $prepared['desired'] );
		$mismatched   = $this->mismatched_applied_fields(
			$after['product'],
			$after_subset,
			$prepared['desired'],
			$prepared['changed_fields']
		);
		if ( $mismatched ) {
			$rollback                    = $this->restore_snapshot( $patris_code, $snapshot, $applied_fields, $prepared['desired'] );
			$result['status']            = 'conflict';
			$result['code']              = 'post_apply_value_conflict';
			$result['message']           = __( 'A concurrent writer or save hook changed requested fields during apply.', 'digitalogic' );
			$result['after']             = $this->present_values( $after_subset );
			$result['record_revision']   = (string) $after['row']['record_revision'];
			$result['mismatched_fields'] = $mismatched;
			$result['rollback']          = $rollback;

			return $result;
		}
		try {
			$audit_id = Digitalogic_Logger::instance()->log(
				'google_sheets_writeback_apply',
				'product',
				$prepared['product_id'],
				array(
					'idempotency_key' => $idempotency_key,
					'record_revision' => $prepared['expected_revision'],
					'fields'          => $result['before'],
				),
				array(
					'idempotency_key' => $idempotency_key,
					'record_revision' => $after['row']['record_revision'],
					'fields'          => $this->present_values( $after_subset ),
				),
				'Applied an optimistic Google Sheets write-back row.'
			);
		} catch ( Throwable $throwable ) {
			$audit_id = false;
		}
		if ( false === $audit_id ) {
			$rollback           = $this->restore_snapshot( $patris_code, $snapshot, $applied_fields, $prepared['desired'] );
			$result['status']   = 'failed';
			$result['code']     = 'audit_log_failed';
			$result['message']  = __( 'The audit record could not be stored; compensation was attempted.', 'digitalogic' );
			$result['rollback'] = $rollback;

			return $result;
		}

		$result['status']          = 'applied';
		$result['code']            = 'applied';
		$result['message']         = __( 'The product change was applied and audited.', 'digitalogic' );
		$result['after']           = $this->present_values( $after_subset );
		$result['record_revision'] = (string) $after['row']['record_revision'];
		$result['audit_id']        = $audit_id;
		$profit_recovery_available = ! in_array( 'profit_percent', $prepared['changed_fields'], true )
			|| $this->profit_state_is_allowlist_representable( $this->snapshot_profit_state( $snapshot ) );
		$result['rollback']        = array(
			'available'                => $profit_recovery_available,
			'sync_key'                 => $patris_code,
			'patris_code'              => $patris_code,
			'expected_record_revision' => (string) $after['row']['record_revision'],
			'fields'                   => $this->rollback_values( $snapshot, $prepared['changed_fields'] ),
		);
		if ( ! $profit_recovery_available ) {
			$result['rollback']['unavailable_reason']    = 'legacy_profit_state_not_representable';
			$result['rollback']['previous_profit_state'] = $this->public_profit_state( $this->snapshot_profit_state( $snapshot ) );
		}

		return $result;
	}

	/**
	 * Identify requested fields not represented by the post-save projection.
	 *
	 * @param WC_Product $product        Reprojected product.
	 * @param array      $after_values   Requested post-save subset.
	 * @param array      $desired        Normalized requested values.
	 * @param array      $changed_fields Fields written by this request.
	 * @return array
	 */
	private function mismatched_applied_fields( $product, $after_values, $desired, $changed_fields ) {
		$mismatched = array();
		foreach ( $changed_fields as $field ) {
			if ( 'profit_percent' === $field ) {
				if ( ! $this->profit_states_equal( $this->capture_profit_state( $product ), $this->desired_profit_state( $desired[ $field ] ) ) ) {
					$mismatched[] = $field;
				}
			} elseif ( ! array_key_exists( $field, $after_values ) || ! $this->field_values_equal( $field, $after_values[ $field ], $desired[ $field ] ) ) {
				$mismatched[] = $field;
			}
		}

		return $mismatched;
	}

	/**
	 * Normalize and type-check editable fields.
	 *
	 * @param array $fields Raw field object.
	 * @return array|WP_Error
	 */
	private function normalize_fields( $fields ) {
		$allowed = $this->get_allowed_fields();
		$unknown = array_diff( array_keys( $fields ), array_keys( $allowed ) );
		if ( $unknown ) {
			return $this->error(
				'digitalogic_sheets_writeback_field_forbidden',
				sprintf(
					/* translators: %s: comma-separated field keys. */
					__( 'These fields are not editable through Google Sheets: %s', 'digitalogic' ),
					implode( ', ', $unknown )
				),
				400,
				array( 'fields' => array_values( $unknown ) )
			);
		}

		$normalized = array();
		foreach ( $fields as $field => $value ) {
			switch ( $field ) {
				case 'regular_price':
					$value = $this->decimal( $value, false, 'regular_price', '0.000001', '999999999999999' );
					break;
				case 'sale_price':
					$value = $this->decimal( $value, true, 'sale_price', '0.000001', '999999999999999' );
					break;
				case 'profit_percent':
					$value = $this->decimal( $value, true, 'profit_percent', '0', '1000' );
					break;
				case 'stock_quantity':
					if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]{0,9})$/', trim( $value ) ) ) ) {
						$value = $this->error( 'stock_quantity_invalid', __( 'stock_quantity must be an integer from 0 to 1,000,000,000.', 'digitalogic' ), 400 );
					} else {
						$value = (int) $value;
						if ( $value < 0 || $value > 1000000000 ) {
							$value = $this->error( 'stock_quantity_invalid', __( 'stock_quantity must be an integer from 0 to 1,000,000,000.', 'digitalogic' ), 400 );
						}
					}
					break;
				case 'stock_status':
					if ( ! is_string( $value ) || ! in_array( $value, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
						$value = $this->error( 'stock_status_invalid', __( 'stock_status must be instock, outofstock, or onbackorder.', 'digitalogic' ), 400 );
					}
					break;
				case 'shipping_method_id':
					if ( null === $value || '' === $value ) {
						$value = null;
					} elseif ( ! is_string( $value ) || ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/', $value ) ) {
						$value = $this->error( 'shipping_method_id_invalid', __( 'shipping_method_id must be null or a canonical method ID.', 'digitalogic' ), 400 );
					}
					break;
			}

			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$normalized[ $field ] = $value;
		}
		ksort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Validate fields that depend on the current WooCommerce product.
	 *
	 * @param WC_Product $product        Product object.
	 * @param array      $desired        Normalized requested values.
	 * @param array      $changed_fields Changed field keys.
	 * @return true|WP_Error
	 */
	private function validate_product_context( $product, $desired, $changed_fields ) {
		$price_fields = array_intersect( $changed_fields, array( 'regular_price', 'sale_price' ) );
		$stock_fields = array_intersect( $changed_fields, array( 'stock_quantity', 'stock_status' ) );
		if ( ( $price_fields || $stock_fields ) && ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
			return $this->error(
				'product_type_not_editable',
				__( 'Direct price and stock writes are limited to simple products and variations.', 'digitalogic' ),
				409
			);
		}
		if ( in_array( 'stock_quantity', $changed_fields, true ) && true !== $product->get_manage_stock() ) {
			return $this->error(
				'manage_stock_disabled',
				__( 'stock_quantity cannot be changed until WooCommerce stock management is enabled for this exact product.', 'digitalogic' ),
				409
			);
		}

		$regular = array_key_exists( 'regular_price', $desired )
			? $desired['regular_price']
			: $this->canonical_existing_decimal( $product->get_regular_price() );
		$sale    = array_key_exists( 'sale_price', $desired )
			? $desired['sale_price']
			: $this->canonical_existing_decimal( $product->get_sale_price() );
		if ( null !== $sale && '' !== $sale && ( null === $regular || '' === $regular || 0 < $this->compare_canonical_decimals( $sale, $regular ) ) ) {
			return $this->error(
				'sale_price_exceeds_regular_price',
				__( 'sale_price cannot exceed the effective regular_price.', 'digitalogic' ),
				409
			);
		}

		if ( in_array( 'shipping_method_id', $changed_fields, true ) && null !== $desired['shipping_method_id'] ) {
			$method = Digitalogic_Shipping_Method_Service::instance()->get_method( $desired['shipping_method_id'] );
			if ( is_wp_error( $method ) ) {
				return $method;
			}
			if ( empty( $method['enabled'] ) ) {
				return $this->error( 'shipping_method_disabled', __( 'Disabled shipping methods cannot be newly assigned.', 'digitalogic' ), 409 );
			}
		}

		return true;
	}

	/**
	 * Apply all non-shipping values through one WooCommerce save.
	 *
	 * @param WC_Product $product        Product object.
	 * @param array      $desired        Requested values.
	 * @param array      $changed_fields Changed fields.
	 * @return void
	 */
	private function apply_product_fields( $product, $desired, $changed_fields ) {
		$product_changed = false;
		if ( in_array( 'regular_price', $changed_fields, true ) ) {
			$product->set_regular_price( $desired['regular_price'] );
			$product_changed = true;
		}
		if ( in_array( 'sale_price', $changed_fields, true ) ) {
			$product->set_sale_price( $desired['sale_price'] ?? '' );
			$product_changed = true;
		}
		if ( in_array( 'stock_quantity', $changed_fields, true ) ) {
			$product->set_stock_quantity( $desired['stock_quantity'] );
			$product_changed = true;
		}
		if ( in_array( 'stock_status', $changed_fields, true ) ) {
			$product->set_stock_status( $desired['stock_status'] );
			$product_changed = true;
		}
		if ( in_array( 'profit_percent', $changed_fields, true ) ) {
			if ( null === $desired['profit_percent'] ) {
				$product->delete_meta_data( '_digitalogic_markup' );
				$product->delete_meta_data( '_digitalogic_markup_type' );
			} else {
				$product->update_meta_data( '_digitalogic_markup', $desired['profit_percent'] );
				$product->update_meta_data( '_digitalogic_markup_type', 'percentage' );
			}
			$product_changed = true;
		}

		if ( $product_changed ) {
			$product->save();
		}
	}

	/**
	 * Resolve a product by exact Patris Code and project its current catalog row.
	 *
	 * @param string $patris_code Exact code.
	 * @return array|WP_Error
	 */
	private function project_product( $patris_code ) {
		$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
			array( 'patris_code' => $patris_code )
		);
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( 'patris_code' !== $resolved['resolved_by'] || $patris_code !== $resolved['patris_code'] ) {
			return $this->error( 'exact_patris_resolution_required', __( 'Editable rows require an exact, unique Patris Code.', 'digitalogic' ), 409 );
		}

		$product_id = (int) $resolved['woocommerce_id'];
		$product    = wc_get_product( $product_id );
		$canonical  = Digitalogic_Product_Manager::instance()->get_product( $product_id );
		if ( ! $product || ! is_array( $canonical ) ) {
			return $this->error( 'product_unavailable', __( 'The resolved product is no longer available.', 'digitalogic' ), 404 );
		}

		$projection = Digitalogic_Google_Sheets_Catalog::instance()->transform_products( array( $canonical ) );
		if ( is_wp_error( $projection ) ) {
			return $projection;
		}
		if ( 1 !== count( $projection['rows'] ?? array() ) ) {
			return $this->error( 'catalog_projection_failed', __( 'The exact product could not be projected into the Sheets catalog.', 'digitalogic' ), 500 );
		}

		return array(
			'product_id' => $product_id,
			'product'    => $product,
			'row'        => $projection['rows'][0],
		);
	}

	/**
	 * Capture exact mutable state for compensation and a rollback manifest.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $row     Current catalog row.
	 * @return array
	 */
	private function capture_snapshot( $product, $row ) {
		$product_id = (int) $product->get_id();
		$shipping   = isset( $row['shipping_method_id'] ) && is_scalar( $row['shipping_method_id'] )
			? trim( (string) $row['shipping_method_id'] )
			: '';

		return array(
			'regular_price'      => (string) $product->get_regular_price(),
			'sale_price'         => (string) $product->get_sale_price(),
			'price'              => (string) $product->get_price(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => (string) $product->get_stock_status(),
			'markup_exists'      => metadata_exists( 'post', $product_id, '_digitalogic_markup' ),
			'markup'             => $product->get_meta( '_digitalogic_markup', true ),
			'markup_type_exists' => metadata_exists( 'post', $product_id, '_digitalogic_markup_type' ),
			'markup_type'        => $product->get_meta( '_digitalogic_markup_type', true ),
			'shipping_method_id' => $shipping,
		);
	}

	/**
	 * Compensate a partially applied row.
	 *
	 * @param string $patris_code   Exact code.
	 * @param array  $snapshot      Captured state.
	 * @param array  $changed_fields Fields owned by this request.
	 * @param array  $desired        Values attempted by this request.
	 * @return array
	 */
	private function restore_snapshot( $patris_code, $snapshot, $changed_fields, $desired ) {
		$errors          = array();
		$restored_fields = array();
		$original_fields = array();
		$skipped_fields  = array();
		$projected       = $this->project_product( $patris_code );
		if ( is_wp_error( $projected ) ) {
			return array(
				'attempted'               => true,
				'success'                 => false,
				'restored_fields'         => array(),
				'already_original_fields' => array(),
				'skipped_fields'          => array(),
				'errors'                  => array( 'product_reload_failed' ),
			);
		}

		$product        = $projected['product'];
		$current_values = $this->editable_values( $product, $projected['row'] );
		$to_restore     = array();
		foreach ( $changed_fields as $field ) {
			if ( 'shipping_method_id' === $field || 'profit_percent' === $field ) {
				continue;
			}
			$current  = $current_values[ $field ];
			$original = $snapshot[ $field ];
			if ( $this->field_values_equal( $field, $current, $original ) ) {
				$original_fields[] = $field;
			} elseif ( $this->field_values_equal( $field, $current, $desired[ $field ] ) ) {
				$to_restore[] = $field;
			} else {
				$skipped_fields[ $field ] = 'current_value_changed';
			}
		}

		if ( in_array( 'profit_percent', $changed_fields, true ) ) {
			$current_profit  = $this->capture_profit_state( $product );
			$original_profit = $this->snapshot_profit_state( $snapshot );
			$desired_profit  = $this->desired_profit_state( $desired['profit_percent'] );
			if ( $this->profit_states_equal( $current_profit, $original_profit ) ) {
				$original_fields[] = 'profit_percent';
			} elseif ( $this->profit_states_equal( $current_profit, $desired_profit ) ) {
				$to_restore[] = 'profit_percent';
			} else {
				$skipped_fields['profit_percent'] = 'current_value_changed';
			}
		}

		if ( $to_restore ) {
			try {
				foreach ( $to_restore as $field ) {
					switch ( $field ) {
						case 'regular_price':
							$product->set_regular_price( $snapshot['regular_price'] );
							break;
						case 'sale_price':
							$product->set_sale_price( $snapshot['sale_price'] );
							break;
						case 'stock_quantity':
							$product->set_stock_quantity( $snapshot['stock_quantity'] );
							break;
						case 'stock_status':
							$product->set_stock_status( $snapshot['stock_status'] );
							break;
						case 'profit_percent':
							$this->restore_profit_state( $product, $snapshot );
							break;
					}
				}
				$product->save();
				$restored_fields = array_merge( $restored_fields, $to_restore );
			} catch ( Throwable $throwable ) {
				$errors[] = 'product_restore_failed';
			}
		}

		if ( in_array( 'shipping_method_id', $changed_fields, true ) ) {
			$original_shipping = '' === $snapshot['shipping_method_id'] ? null : $snapshot['shipping_method_id'];
			$shipping          = Digitalogic_Shipping_Method_Service::instance()->compare_and_assign_product_by_code(
				$patris_code,
				$desired['shipping_method_id'],
				$original_shipping
			);
			if ( is_wp_error( $shipping ) ) {
				$data     = $shipping->get_error_data();
				$current  = is_array( $data ) && array_key_exists( 'current_shipping_method_id', $data )
					? (string) $data['current_shipping_method_id']
					: null;
				$original = null === $original_shipping ? '' : (string) $original_shipping;
				if ( 'digitalogic_shipping_assignment_conflict' === $shipping->get_error_code() && null !== $current ) {
					if ( $current === $original ) {
						$original_fields[] = 'shipping_method_id';
					} else {
						$skipped_fields['shipping_method_id'] = 'current_value_changed';
					}
				} else {
					$errors[] = 'shipping_restore_failed';
				}
			} elseif ( ! empty( $shipping['changed'] ) ) {
				$restored_fields[] = 'shipping_method_id';
			} else {
				$original_fields[] = 'shipping_method_id';
			}
		}

		return array(
			'attempted'               => true,
			'success'                 => empty( $errors ) && empty( $skipped_fields ),
			'restored_fields'         => array_values( array_unique( $restored_fields ) ),
			'already_original_fields' => array_values( array_unique( $original_fields ) ),
			'skipped_fields'          => $skipped_fields,
			'errors'                  => array_values( array_unique( $errors ) ),
		);
	}

	/**
	 * Capture exact profit override metadata.
	 *
	 * @param WC_Product $product Product instance.
	 * @return array
	 */
	private function capture_profit_state( $product ) {
		$product_id = (int) $product->get_id();

		return array(
			'markup_exists'      => metadata_exists( 'post', $product_id, '_digitalogic_markup' ),
			'markup'             => (string) $product->get_meta( '_digitalogic_markup', true ),
			'markup_type_exists' => metadata_exists( 'post', $product_id, '_digitalogic_markup_type' ),
			'markup_type'        => (string) $product->get_meta( '_digitalogic_markup_type', true ),
		);
	}

	/**
	 * Convert a captured product snapshot to its profit-state shape.
	 *
	 * @param array $snapshot Captured product state.
	 * @return array
	 */
	private function snapshot_profit_state( $snapshot ) {
		return array(
			'markup_exists'      => (bool) $snapshot['markup_exists'],
			'markup'             => (string) $snapshot['markup'],
			'markup_type_exists' => (bool) $snapshot['markup_type_exists'],
			'markup_type'        => (string) $snapshot['markup_type'],
		);
	}

	/**
	 * Convert a requested profit value to the metadata state written by apply.
	 *
	 * @param string|null $profit_percent Normalized requested percentage.
	 * @return array
	 */
	private function desired_profit_state( $profit_percent ) {
		if ( null === $profit_percent ) {
			return array(
				'markup_exists'      => false,
				'markup'             => '',
				'markup_type_exists' => false,
				'markup_type'        => '',
			);
		}

		return array(
			'markup_exists'      => true,
			'markup'             => (string) $profit_percent,
			'markup_type_exists' => true,
			'markup_type'        => 'percentage',
		);
	}

	/**
	 * Compare profit metadata without treating malformed/fixed state as null.
	 *
	 * @param array $left  First profit state.
	 * @param array $right Second profit state.
	 * @return bool
	 */
	private function profit_states_equal( $left, $right ) {
		foreach ( array( 'markup_exists', 'markup_type_exists' ) as $flag ) {
			if ( (bool) $left[ $flag ] !== (bool) $right[ $flag ] ) {
				return false;
			}
		}
		if ( $left['markup_exists'] && (string) $left['markup'] !== (string) $right['markup'] ) {
			return false;
		}

		return ! $left['markup_type_exists'] || (string) $left['markup_type'] === (string) $right['markup_type'];
	}

	/**
	 * Whether profit state can be recreated through the public allowlist.
	 *
	 * @param array $state Profit metadata state.
	 * @return bool
	 */
	private function profit_state_is_allowlist_representable( $state ) {
		if ( ! $state['markup_exists'] && ! $state['markup_type_exists'] ) {
			return true;
		}
		if ( ! $state['markup_exists'] || ! $state['markup_type_exists'] || 'percentage' !== (string) $state['markup_type'] ) {
			return false;
		}
		$value = $this->canonical_existing_decimal( $state['markup'] );

		return null !== $value
			&& 0 <= $this->compare_canonical_decimals( $value, '0' )
			&& 0 >= $this->compare_canonical_decimals( $value, '1000' );
	}

	/**
	 * Return bounded legacy profit metadata for truthful audit/recovery output.
	 *
	 * @param array $state Profit metadata state.
	 * @return array
	 */
	private function public_profit_state( $state ) {
		return array(
			'markup_present'      => (bool) $state['markup_exists'],
			'markup'              => substr( (string) $state['markup'], 0, 191 ),
			'markup_type_present' => (bool) $state['markup_type_exists'],
			'markup_type'         => substr( (string) $state['markup_type'], 0, 64 ),
		);
	}

	/**
	 * Restore the snapshot's exact profit metadata.
	 *
	 * @param WC_Product $product  Product instance.
	 * @param array      $snapshot Captured product state.
	 * @return void
	 */
	private function restore_profit_state( $product, $snapshot ) {
		if ( $snapshot['markup_exists'] ) {
			$product->update_meta_data( '_digitalogic_markup', $snapshot['markup'] );
		} else {
			$product->delete_meta_data( '_digitalogic_markup' );
		}
		if ( $snapshot['markup_type_exists'] ) {
			$product->update_meta_data( '_digitalogic_markup_type', $snapshot['markup_type'] );
		} else {
			$product->delete_meta_data( '_digitalogic_markup_type' );
		}
	}

	/**
	 * Build public rollback field values from the raw snapshot.
	 *
	 * @param array $snapshot       Captured state.
	 * @param array $changed_fields Changed field keys.
	 * @return array
	 */
	private function rollback_values( $snapshot, $changed_fields ) {
		$values = array();
		foreach ( $changed_fields as $field ) {
			switch ( $field ) {
				case 'regular_price':
				case 'sale_price':
				case 'stock_quantity':
				case 'stock_status':
					$values[ $field ] = $snapshot[ $field ];
					break;
				case 'shipping_method_id':
					$values[ $field ] = '' === $snapshot['shipping_method_id'] ? null : $snapshot['shipping_method_id'];
					break;
				case 'profit_percent':
					$values[ $field ] = $snapshot['markup_exists'] && 'percentage' === $snapshot['markup_type']
						? $this->present_decimal( $this->canonical_existing_decimal( $snapshot['markup'] ) )
						: null;
					break;
			}
		}

		return $this->present_values( $values );
	}

	/**
	 * Return current editable values. Profit is the product override, not the
	 * effective global default displayed on the managed Products tab.
	 *
	 * @param WC_Product $product Product object.
	 * @param array      $row     Catalog row.
	 * @return array
	 */
	private function editable_values( $product, $row ) {
		$product_id      = (int) $product->get_id();
		$profit_override = null;
		if (
			metadata_exists( 'post', $product_id, '_digitalogic_markup' )
			&& 'percentage' === (string) $product->get_meta( '_digitalogic_markup_type', true )
		) {
			$profit_override = $this->canonical_existing_decimal( $product->get_meta( '_digitalogic_markup', true ) );
		}

		return array(
			'regular_price'      => $this->canonical_existing_decimal( $product->get_regular_price() ),
			'sale_price'         => $this->canonical_existing_decimal( $product->get_sale_price() ),
			'stock_quantity'     => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'stock_status'       => (string) $product->get_stock_status(),
			'shipping_method_id' => isset( $row['shipping_method_id'] ) && '' !== (string) $row['shipping_method_id'] ? (string) $row['shipping_method_id'] : null,
			'profit_percent'     => $profit_override,
		);
	}

	/**
	 * Compare one normalized field value.
	 *
	 * @param string $field   Field key.
	 * @param mixed  $current Current canonical value.
	 * @param mixed  $desired Desired canonical value.
	 * @return bool
	 */
	private function field_values_equal( $field, $current, $desired ) {
		if ( in_array( $field, array( 'regular_price', 'sale_price', 'profit_percent' ), true ) ) {
			$current = null === $current || '' === $current ? null : $this->canonical_existing_decimal( $current );
			$desired = null === $desired || '' === $desired ? null : $this->canonical_existing_decimal( $desired );
		}

		return $current === $desired;
	}

	/**
	 * Preserve canonical decimals as exact JSON strings for Sheets.
	 *
	 * @param array $values Canonical field values.
	 * @return array
	 */
	private function present_values( $values ) {
		$presented = array();
		foreach ( $values as $field => $value ) {
			$presented[ $field ] = in_array( $field, array( 'regular_price', 'sale_price', 'profit_percent' ), true )
				? $this->present_decimal( $value )
				: $value;
		}

		return $presented;
	}

	/**
	 * Present one canonical decimal as an exact string or null.
	 *
	 * @param mixed $value Canonical decimal value.
	 * @return string|null
	 */
	private function present_decimal( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return $this->canonical_decimal_string( (string) $value );
	}

	/**
	 * Normalize an existing decimal without accepting malformed data as zero.
	 *
	 * @param mixed $value Existing value.
	 * @return string|null
	 */
	private function canonical_existing_decimal( $value ) {
		if ( null === $value || '' === $value || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return null;
		}

		return $this->canonical_decimal_string( (string) $value );
	}

	/**
	 * Validate one decimal request value.
	 *
	 * @param mixed  $value    Requested value.
	 * @param bool   $nullable Whether an explicit null is valid.
	 * @param string $field    Field name for errors.
	 * @param string $minimum  Inclusive canonical minimum.
	 * @param string $maximum  Inclusive canonical maximum.
	 * @return string|null|WP_Error
	 */
	private function decimal( $value, $nullable, $field, $minimum, $maximum ) {
		if ( $nullable && ( null === $value || '' === $value ) ) {
			return null;
		}
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) ) {
			/* translators: %s: editable decimal field name. */
			return $this->error( $field . '_invalid', sprintf( __( '%s must be a decimal number.', 'digitalogic' ), $field ), 400 );
		}
		$text = trim( (string) $value );
		if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,6})?$/', $text ) ) {
			/* translators: %s: editable decimal field name. */
			return $this->error( $field . '_invalid', sprintf( __( '%s has an invalid decimal format.', 'digitalogic' ), $field ), 400 );
		}
		$canonical = $this->canonical_decimal_string( $text );
		if ( 0 > $this->compare_canonical_decimals( $canonical, $minimum ) || 0 < $this->compare_canonical_decimals( $canonical, $maximum ) ) {
			/* translators: %s: editable decimal field name. */
			return $this->error( $field . '_out_of_range', sprintf( __( '%s is outside its safe range.', 'digitalogic' ), $field ), 400 );
		}

		return $canonical;
	}

	/**
	 * Compare two canonical non-negative decimal strings without float loss.
	 *
	 * @param string $left  Canonical decimal.
	 * @param string $right Canonical decimal.
	 * @return int Negative, zero, or positive.
	 */
	private function compare_canonical_decimals( $left, $right ) {
		$left_parts  = array_pad( explode( '.', (string) $left, 2 ), 2, '' );
		$right_parts = array_pad( explode( '.', (string) $right, 2 ), 2, '' );
		if ( strlen( $left_parts[0] ) !== strlen( $right_parts[0] ) ) {
			return strlen( $left_parts[0] ) <=> strlen( $right_parts[0] );
		}
		$integer_order = strcmp( $left_parts[0], $right_parts[0] );
		if ( 0 !== $integer_order ) {
			return $integer_order;
		}
		$scale = max( strlen( $left_parts[1] ), strlen( $right_parts[1] ) );

		return strcmp( str_pad( $left_parts[1], $scale, '0' ), str_pad( $right_parts[1], $scale, '0' ) );
	}

	/**
	 * Canonicalize a valid non-negative decimal string.
	 *
	 * @param string $text Valid decimal text.
	 * @return string
	 */
	private function canonical_decimal_string( $text ) {
		$text = trim( (string) $text );
		if ( str_contains( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		$text = ltrim( $text, '0' );
		if ( '' === $text || str_starts_with( $text, '.' ) ) {
			$text = '0' . $text;
		}

		return $text;
	}

	/**
	 * Normalize a string identity without numeric coercion.
	 *
	 * @param mixed $value Candidate identity.
	 * @return string|null
	 */
	private function strict_identifier( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );

		return '' !== $value && strlen( $value ) <= 191 && ! preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : null;
	}

	/**
	 * Acquire an atomic, mode-scoped idempotency reservation.
	 *
	 * @param string $mode            preview or apply.
	 * @param string $idempotency_key Client request key.
	 * @param string $request_hash    Stable normalized payload hash.
	 * @return array|WP_Error
	 */
	private function claim_idempotency( $mode, $idempotency_key, $request_hash ) {
		$key_hash   = hash( 'sha256', $mode . "\0" . $idempotency_key );
		$result_key = self::RESULT_PREFIX . $key_hash;
		$lock_key   = self::LOCK_PREFIX . $key_hash;
		$mutex_name = 'idempotency:' . $key_hash;
		if ( ! $this->acquire_advisory_lock( $mutex_name, 3 ) ) {
			return $this->error( 'idempotency_mutex_busy', __( 'The idempotency coordinator is busy. Retry the unchanged request.', 'digitalogic' ), 409, array( 'retryable' => true ) );
		}

		try {
			$completed = get_transient( $result_key );
			if ( is_array( $completed ) ) {
				if ( ( $completed['request_hash'] ?? '' ) !== $request_hash ) {
					return $this->error( 'idempotency_key_reused', __( 'That idempotency_key was already used for a different request.', 'digitalogic' ), 409 );
				}

				return array( 'replay' => $completed['data'] );
			}

			$existing = get_option( $lock_key, null );
			if ( is_array( $existing ) ) {
				$last_heartbeat = (int) ( $existing['heartbeat_at'] ?? $existing['created_at'] ?? 0 );
				if ( $last_heartbeat + self::LOCK_TTL < time() ) {
					delete_option( $lock_key );
				} elseif ( ( $existing['request_hash'] ?? '' ) !== $request_hash ) {
					return $this->error( 'idempotency_key_reused', __( 'That idempotency_key is reserved for a different request.', 'digitalogic' ), 409 );
				} else {
					return $this->error( 'idempotency_request_in_progress', __( 'A request with that idempotency_key is still in progress.', 'digitalogic' ), 409, array( 'retryable' => true ) );
				}
			}

			++self::$owner_sequence;
			$owner_token = hash( 'sha256', wp_generate_uuid4() . "\0" . microtime( true ) . "\0" . self::$owner_sequence );
			$lock        = array(
				'owner_token'        => $owner_token,
				'request_hash'       => $request_hash,
				'created_at'         => time(),
				'heartbeat_at'       => time(),
				'heartbeat_sequence' => 0,
			);
			if ( ! add_option( $lock_key, $lock, '', false ) ) {
				return $this->error( 'idempotency_reservation_failed', __( 'The idempotency reservation could not be acquired.', 'digitalogic' ), 409, array( 'retryable' => true ) );
			}

			return array(
				'lock_key'    => $lock_key,
				'result_key'  => $result_key,
				'mutex_name'  => $mutex_name,
				'owner_token' => $owner_token,
			);
		} finally {
			$this->release_advisory_lock( $mutex_name );
		}
	}

	/**
	 * Refresh a reservation only while this exact owner still holds it.
	 *
	 * @param array $claim Idempotency claim.
	 * @return true|WP_Error
	 */
	private function heartbeat_idempotency( $claim ) {
		if ( ! $this->acquire_advisory_lock( $claim['mutex_name'], 3 ) ) {
			return $this->error(
				'idempotency_heartbeat_busy',
				__( 'The request reservation could not be refreshed safely.', 'digitalogic' ),
				409,
				array(
					'retryable'        => true,
					'may_have_applied' => true,
				)
			);
		}

		try {
			$existing = get_option( $claim['lock_key'], null );
			if ( ! is_array( $existing ) || ( $existing['owner_token'] ?? '' ) !== $claim['owner_token'] ) {
				return $this->error(
					'idempotency_reservation_lost',
					__( 'The request reservation changed before completion.', 'digitalogic' ),
					409,
					array(
						'retryable'        => true,
						'may_have_applied' => true,
					)
				);
			}
			$existing['heartbeat_at']       = time();
			$existing['heartbeat_sequence'] = 1 + (int) ( $existing['heartbeat_sequence'] ?? 0 );
			if ( ! update_option( $claim['lock_key'], $existing, false ) ) {
				return $this->error(
					'idempotency_heartbeat_failed',
					__( 'The request reservation could not be refreshed safely.', 'digitalogic' ),
					500,
					array(
						'retryable'        => true,
						'may_have_applied' => true,
					)
				);
			}

			return true;
		} finally {
			$this->release_advisory_lock( $claim['mutex_name'] );
		}
	}

	/**
	 * Persist a replayable result before releasing its reservation.
	 *
	 * @param array  $claim        Idempotency reservation.
	 * @param string $request_hash Stable normalized payload hash.
	 * @param array  $data         Completed public response.
	 * @return true|WP_Error
	 */
	private function complete_idempotency( $claim, $request_hash, $data ) {
		if ( ! $this->acquire_advisory_lock( $claim['mutex_name'], 3 ) ) {
			return $this->error(
				'idempotency_completion_busy',
				__( 'The completed request could not be recorded for replay yet.', 'digitalogic' ),
				500,
				array(
					'retryable'        => true,
					'may_have_applied' => true,
				)
			);
		}

		try {
			$existing = get_option( $claim['lock_key'], null );
			if ( ! is_array( $existing ) || ( $existing['owner_token'] ?? '' ) !== $claim['owner_token'] ) {
				return $this->error(
					'idempotency_reservation_lost',
					__( 'The request reservation changed before completion.', 'digitalogic' ),
					409,
					array(
						'retryable'        => true,
						'may_have_applied' => true,
					)
				);
			}
			$stored = set_transient(
				$claim['result_key'],
				array(
					'request_hash' => $request_hash,
					'data'         => $data,
				),
				self::IDEMPOTENCY_TTL
			);
			if ( ! $stored ) {
				return $this->error(
					'idempotency_result_store_failed',
					__( 'The result could not be stored for safe replay. The reservation remains held temporarily.', 'digitalogic' ),
					500,
					array(
						'retryable'        => true,
						'may_have_applied' => true,
					)
				);
			}
			delete_option( $claim['lock_key'] );

			return true;
		} finally {
			$this->release_advisory_lock( $claim['mutex_name'] );
		}
	}

	/**
	 * Acquire a short, connection-scoped MySQL advisory lock.
	 *
	 * @param string $scope   Logical lock scope.
	 * @param int    $timeout Maximum wait in seconds.
	 * @return bool
	 */
	private function acquire_advisory_lock( $scope, $timeout ) {
		$name = 'digitalogic_gswb_' . substr( hash( 'sha256', (string) $scope ), 0, 40 );

		return $this->acquire_named_advisory_lock( $name, $timeout );
	}

	/**
	 * Release a connection-scoped MySQL advisory lock.
	 *
	 * @param string $scope Logical lock scope.
	 * @return void
	 */
	private function release_advisory_lock( $scope ) {
		$name = 'digitalogic_gswb_' . substr( hash( 'sha256', (string) $scope ), 0, 40 );
		$this->release_named_advisory_lock( $name );
	}

	/**
	 * Acquire an exact MySQL advisory lock name.
	 *
	 * @param string $name    MySQL lock name.
	 * @param int    $timeout Maximum wait in seconds.
	 * @return bool
	 */
	private function acquire_named_advisory_lock( $name, $timeout ) {
		global $wpdb;
		$prepared = $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', substr( (string) $name, 0, 64 ), (int) $timeout );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory locks are connection state and are not cacheable.
		return false !== $prepared && 1 === (int) $wpdb->get_var( $prepared );
	}

	/**
	 * Release an exact MySQL advisory lock name.
	 *
	 * @param string $name MySQL lock name.
	 * @return void
	 */
	private function release_named_advisory_lock( $name ) {
		global $wpdb;
		$prepared = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', substr( (string) $name, 0, 64 ) );
		if ( false !== $prepared ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory locks are connection state and are not cacheable.
			$wpdb->get_var( $prepared );
		}
	}

	/**
	 * Build status counts for the typed result list.
	 *
	 * @param int   $received Requested row count.
	 * @param array $results  Public row results.
	 * @return array
	 */
	private function summarize( $received, $results ) {
		$summary = array(
			'received'  => (int) $received,
			'ready'     => 0,
			'unchanged' => 0,
			'applied'   => 0,
			'conflicts' => 0,
			'invalid'   => 0,
			'failed'    => 0,
		);
		foreach ( $results as $result ) {
			$status = $result['status'] ?? 'failed';
			if ( 'conflict' === $status ) {
				++$summary['conflicts'];
			} elseif ( array_key_exists( $status, $summary ) ) {
				++$summary[ $status ];
			} else {
				++$summary['failed'];
			}
		}

		return $summary;
	}

	/**
	 * Base public per-row result.
	 *
	 * @param int    $index       Zero-based request index.
	 * @param string $sync_key    Exact synchronization key.
	 * @param string $patris_code Exact Patris Code.
	 * @return array
	 */
	private function base_result( $index, $sync_key, $patris_code ) {
		return array(
			'index'           => (int) $index,
			'sync_key'        => (string) $sync_key,
			'patris_code'     => (string) $patris_code,
			'woocommerce_id'  => null,
			'status'          => 'invalid',
			'code'            => 'invalid',
			'message'         => '',
			'changed_fields'  => array(),
			'before'          => array(),
			'after'           => array(),
			'record_revision' => null,
			'rollback'        => null,
			'audit_id'        => null,
		);
	}

	/**
	 * Build one preflight error row.
	 *
	 * @param int    $index       Zero-based request index.
	 * @param string $sync_key    Exact synchronization key.
	 * @param string $patris_code Exact Patris Code.
	 * @param string $status      Typed row status.
	 * @param string $code        Machine error code.
	 * @param string $message     Human-readable message.
	 * @param array  $extra       Additional public result fields.
	 * @return array
	 */
	private function prepared_error( $index, $sync_key, $patris_code, $status, $code, $message, $extra = array() ) {
		$result            = array_merge( $this->base_result( $index, $sync_key, $patris_code ), $extra );
		$result['status']  = $status;
		$result['code']    = $code;
		$result['message'] = $message;

		return array( 'result' => $result );
	}

	/**
	 * Convert a WP_Error into one typed row.
	 *
	 * @param int      $index       Zero-based request index.
	 * @param string   $sync_key    Exact synchronization key.
	 * @param string   $patris_code Exact Patris Code.
	 * @param WP_Error $error       Service error.
	 * @param array    $extra       Additional public result fields.
	 * @return array
	 */
	private function prepared_error_from_wp_error( $index, $sync_key, $patris_code, $error, $extra = array() ) {
		$public = $this->public_error_details( $error );

		return $this->prepared_error(
			$index,
			$sync_key,
			$patris_code,
			$public['status'],
			$public['code'],
			$public['message'],
			$extra
		);
	}

	/**
	 * Convert an apply-time WP_Error while preserving row identity.
	 *
	 * @param array    $result Existing public row result.
	 * @param WP_Error $error  Apply-time error.
	 * @return array
	 */
	private function application_error_result( $result, $error ) {
		$public            = $this->public_error_details( $error );
		$result['status']  = $public['status'];
		$result['code']    = $public['code'];
		$result['message'] = $public['message'];

		return $result;
	}

	/**
	 * Convert an internal service error to a bounded, non-sensitive row error.
	 *
	 * @param WP_Error $error Internal error.
	 * @return array
	 */
	private function public_error_details( $error ) {
		$data        = $error->get_error_data();
		$http_status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$code        = preg_replace( '/[^a-z0-9_.-]/', '_', strtolower( (string) $error->get_error_code() ) );
		$code        = substr( (string) $code, 0, 96 );
		if ( '' === $code ) {
			$code = 'service_error';
		}
		if ( 400 === $http_status ) {
			return array(
				'status'  => 'invalid',
				'code'    => $code,
				'message' => __( 'The row contains invalid editable data.', 'digitalogic' ),
			);
		}
		if ( 409 === $http_status ) {
			return array(
				'status'  => 'conflict',
				'code'    => $code,
				'message' => __( 'The row conflicts with the current product state.', 'digitalogic' ),
			);
		}

		return array(
			'status'  => 'failed',
			'code'    => $code,
			'message' => __( 'The product could not be read or updated safely.', 'digitalogic' ),
		);
	}

	/**
	 * Construct a service error with an HTTP status.
	 *
	 * @param string $code    Machine error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP response status.
	 * @param array  $details Additional safe error details.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status, $details = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge( array( 'status' => (int) $status ), $details )
		);
	}
}
