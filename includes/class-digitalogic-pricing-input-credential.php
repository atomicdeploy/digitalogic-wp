<?php
/**
 * Route-scoped machine credential for Patris pricing-input reads.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the credential lifecycle, one-way storage, request verification, and
 * failed-authentication throttle for the two pricing-input contracts.
 */
final class Digitalogic_Pricing_Input_Credential {
	public const OPTION_NAME   = 'digitalogic_pricing_input_machine_credential';
	public const FAILURE_LIMIT = 5;

	private const SCHEMA_VERSION       = 1;
	private const TOKEN_PREFIX         = 'dgp1';
	private const FAILURE_WINDOW       = 60;
	private const FAILURE_BLOCK_PERIOD = 300;
	private const THROTTLE_PREFIX      = 'digitalogic_pricing_input_auth_';
	private const LOCK_NAME            = 'digitalogic_pricing_input_credential_v1';
	private const LOCK_TIMEOUT_SECONDS = 3;

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared credential service.
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
	 * Authorize one exact pricing-input request.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( ! $this->request_is_allowed( $request ) ) {
			return $this->error( 'digitalogic_pricing_input_scope_denied', 403 );
		}

		$authorization = $request->get_header( 'authorization' );
		if ( ! is_string( $authorization ) || '' === $authorization ) {
			return $this->error( 'digitalogic_pricing_input_unauthorized', 401 );
		}

		$record_row = $this->read_record_db();
		if ( is_wp_error( $record_row ) ) {
			return $this->error( 'digitalogic_pricing_input_unavailable', 503 );
		}
		$record         = $record_row['value'];
		$throttle_key   = $this->throttle_key( $record );
		$throttle_state = get_transient( $throttle_key );

		$pattern = '/\A(?i:Bearer) (' . self::TOKEN_PREFIX . '\.([a-f0-9]{16})\.[A-Za-z0-9_-]{43})\z/D';
		if (
			1 === preg_match( $pattern, $authorization, $matches )
			&& 'active' === $this->record_state( $record )
		) {
			$token            = $matches[1];
			$credential_id    = $matches[2];
			$id_matches       = hash_equals( $record['credential_id'], $credential_id );
			$verifier_matches = hash_equals( $record['verifier'], hash( 'sha256', $token ) );

			if ( $id_matches && $verifier_matches ) {
				if ( false !== $throttle_state ) {
					delete_transient( $throttle_key );
				}

				return true;
			}
		}

		if ( $this->is_throttled( $throttle_state ) ) {
			return $this->error( 'digitalogic_pricing_input_throttled', 429 );
		}
		$this->record_failure( $throttle_key, $throttle_state );

		return $this->error( 'digitalogic_pricing_input_unauthorized', 401 );
	}

	/**
	 * Create a credential when none is active.
	 *
	 * @return array|WP_Error Secret and nonsecret metadata, or an error.
	 */
	public function create() {
		$observed = $this->read_record_db();
		if ( is_wp_error( $observed ) ) {
			return $observed;
		}
		$fingerprint = $this->record_fingerprint( $observed );

		return $this->with_lifecycle_lock(
			function () use ( $fingerprint ) {
				$current = $this->read_record_db();
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				if ( ! hash_equals( $fingerprint, $this->record_fingerprint( $current ) ) ) {
					return $this->concurrency_error();
				}

				$record = $current['value'];
				$state  = $this->record_state( $record );
				if ( 'active' === $state ) {
					return new WP_Error(
						'digitalogic_pricing_input_already_active',
						'An active pricing-input credential already exists; rotate it instead.',
						array( 'status' => 409 )
					);
				}
				if ( 'invalid' === $state ) {
					return $this->configuration_error();
				}

				$generation = 'revoked' === $state ? ( (int) $record['generation'] + 1 ) : 1;

				return $this->issue_locked( $current, null, $generation, false );
			}
		);
	}

	/**
	 * Rotate the active credential and invalidate its old value immediately.
	 *
	 * @return array|WP_Error Secret and nonsecret metadata, or an error.
	 */
	public function rotate() {
		$observed = $this->read_record_db();
		if ( is_wp_error( $observed ) ) {
			return $observed;
		}
		$fingerprint = $this->record_fingerprint( $observed );

		return $this->with_lifecycle_lock(
			function () use ( $fingerprint ) {
				$current = $this->read_record_db();
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				if ( ! hash_equals( $fingerprint, $this->record_fingerprint( $current ) ) ) {
					return $this->concurrency_error();
				}

				$record = $current['value'];
				if ( 'active' !== $this->record_state( $record ) ) {
					return new WP_Error(
						'digitalogic_pricing_input_not_active',
						'No active pricing-input credential is available to rotate.',
						array( 'status' => 409 )
					);
				}

				return $this->issue_locked( $current, $record, (int) $record['generation'] + 1, true );
			}
		);
	}

	/**
	 * Revoke the active credential.
	 *
	 * @return array|WP_Error Nonsecret status metadata, or an error.
	 */
	public function revoke() {
		return $this->with_lifecycle_lock(
			function () {
				$current = $this->read_record_db();
				if ( is_wp_error( $current ) ) {
					return $current;
				}

				$record = $current['value'];
				$state  = $this->record_state( $record );
				if ( 'revoked' === $state ) {
					return $this->metadata( $record );
				}
				if ( 'active' !== $state ) {
					return new WP_Error(
						'digitalogic_pricing_input_not_active',
						'No active pricing-input credential is available to revoke.',
						array( 'status' => 409 )
					);
				}

				$record['status']     = 'revoked';
				$record['revoked_at'] = $this->timestamp();
				unset( $record['verifier'] );
				$stored = $this->persist_record_locked( $current, $record );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}

				return $this->metadata( $stored );
			}
		);
	}

	/**
	 * Return only nonsecret credential metadata.
	 *
	 * @return array|WP_Error
	 */
	public function status() {
		$record_row = $this->read_record_db();
		if ( is_wp_error( $record_row ) ) {
			return $record_row;
		}
		$record = $record_row['value'];
		$state  = $this->record_state( $record );

		if ( 'active' === $state || 'revoked' === $state ) {
			return $this->metadata( $record );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'configured'     => 'invalid' === $state,
			'active'         => false,
			'state'          => $state,
			'contracts'      => $this->contracts(),
		);
	}

	/**
	 * Generate and persist a new one-way credential record while locked.
	 *
	 * @param array      $current    Authoritative current option row.
	 * @param array|null $previous   Previous active record during rotation.
	 * @param int        $generation New generation number.
	 * @param bool       $rotation   Whether this is a rotation.
	 * @return array
	 */
	private function issue_locked( $current, $previous, $generation, $rotation ) {
		try {
			$credential_id = bin2hex( random_bytes( 8 ) );
			$secret_bytes  = random_bytes( 32 );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'digitalogic_pricing_input_generation_failed',
				'The pricing-input credential could not be generated.',
				array( 'status' => 500 )
			);
		}

		$secret = rtrim( strtr( base64_encode( $secret_bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe random bytes, not code obfuscation.
		$token  = self::TOKEN_PREFIX . '.' . $credential_id . '.' . $secret;
		$now    = $this->timestamp();
		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'status'         => 'active',
			'credential_id'  => $credential_id,
			'verifier'       => hash( 'sha256', $token ),
			'created_at'     => $rotation ? $previous['created_at'] : $now,
			'rotated_at'     => $rotation ? $now : null,
			'revoked_at'     => null,
			'generation'     => (int) $generation,
		);

		$stored = $this->persist_record_locked( $current, $record );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'secret'   => $token,
			'metadata' => $this->metadata( $stored ),
		);
	}

	/**
	 * Classify a stored record without trusting malformed option data.
	 *
	 * @param mixed $record Stored option value.
	 * @return string missing, invalid, active, or revoked.
	 */
	private function record_state( $record ) {
		if ( null === $record || false === $record ) {
			return 'missing';
		}

		if ( ! is_array( $record ) ) {
			return 'invalid';
		}

		$allowed_keys = array(
			'schema_version',
			'status',
			'credential_id',
			'verifier',
			'created_at',
			'rotated_at',
			'revoked_at',
			'generation',
		);
		if ( array_diff( array_keys( $record ), $allowed_keys ) ) {
			return 'invalid';
		}
		$required_keys = array(
			'schema_version',
			'status',
			'credential_id',
			'created_at',
			'rotated_at',
			'revoked_at',
			'generation',
		);
		if ( array_diff( $required_keys, array_keys( $record ) ) ) {
			return 'invalid';
		}

		if (
			self::SCHEMA_VERSION !== ( $record['schema_version'] ?? null )
			|| ! isset( $record['credential_id'], $record['status'], $record['created_at'], $record['generation'] )
			|| ! is_string( $record['credential_id'] )
			|| ! is_string( $record['status'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{16}\z/D', $record['credential_id'] )
			|| ! is_int( $record['generation'] )
			|| $record['generation'] < 1
			|| ! $this->valid_timestamp( $record['created_at'] )
			|| ( null !== ( $record['rotated_at'] ?? null ) && ! $this->valid_timestamp( $record['rotated_at'] ) )
		) {
			return 'invalid';
		}

		if ( 'active' === $record['status'] ) {
			return isset( $record['verifier'] )
				&& is_string( $record['verifier'] )
				&& 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $record['verifier'] )
				&& null === ( $record['revoked_at'] ?? null )
					? 'active'
					: 'invalid';
		}

		if ( 'revoked' === $record['status'] ) {
			return ! array_key_exists( 'verifier', $record )
				&& $this->valid_timestamp( $record['revoked_at'] ?? null )
					? 'revoked'
					: 'invalid';
		}

		return 'invalid';
	}

	/**
	 * Serialize a lifecycle operation with a bounded database advisory lock.
	 *
	 * @param callable $callback Operation performed while the lock is held.
	 * @return mixed|WP_Error
	 */
	private function with_lifecycle_lock( $callback ) {
		$acquired = $this->acquire_lifecycle_lock();
		if ( is_wp_error( $acquired ) ) {
			return $acquired;
		}

		try {
			return call_user_func( $callback );
		} catch ( Throwable $error ) {
			return $this->lifecycle_error();
		} finally {
			$this->release_lifecycle_lock();
		}
	}

	/**
	 * Acquire the credential lifecycle advisory lock.
	 *
	 * @return true|WP_Error
	 */
	private function acquire_lifecycle_lock() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->storage_error();
		}

		try {
			$locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock intentionally bypasses caches.
				$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lifecycle_lock_name(), self::LOCK_TIMEOUT_SECONDS ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			);
		} catch ( Throwable $error ) {
			return $this->storage_error();
		}

		if ( '1' !== (string) $locked ) {
			return new WP_Error(
				'digitalogic_pricing_input_lifecycle_busy',
				'The pricing-input credential lifecycle is busy; retry later.',
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		return true;
	}

	/**
	 * Release the credential lifecycle advisory lock without masking a result.
	 *
	 * @return void
	 */
	private function release_lifecycle_lock() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}

		try {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock intentionally bypasses caches.
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lifecycle_lock_name() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			);
		} catch ( Throwable $error ) {
			// The lifecycle result is already determined; never expose internals.
			unset( $error );
		}
	}

	/**
	 * Build a site-specific advisory lock name within MySQL's 64-byte limit.
	 *
	 * @return string
	 */
	private function lifecycle_lock_name() {
		global $wpdb;

		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';

		return substr( self::LOCK_NAME . '_' . hash( 'sha256', $prefix ), 0, 64 );
	}

	/**
	 * Read the option from authoritative MySQL state, bypassing object caches.
	 *
	 * @param bool $for_update Whether to lock the row inside a transaction.
	 * @return array|WP_Error
	 */
	private function read_record_db( $for_update = false ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $this->storage_error();
		}

		$table = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$sql   = "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1";
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}

		try {
			$query = $wpdb->prepare( $sql, self::OPTION_NAME ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table and optional lock clause are trusted constants.
			$row   = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle decisions require authoritative state.
		} catch ( Throwable $error ) {
			return $this->storage_error();
		}

		if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
			return $this->storage_error();
		}
		if ( null === $row ) {
			return array(
				'exists' => false,
				'value'  => null,
			);
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return $this->storage_error();
		}

		return array(
			'exists' => true,
			'value'  => maybe_unserialize( $row['option_value'] ),
		);
	}

	/**
	 * Persist and verify a record inside both advisory and row locks.
	 *
	 * @param array $current Authoritative option row observed while locked.
	 * @param array $record  Desired validated record.
	 * @return array|WP_Error
	 */
	private function persist_record_locked( $current, $record ) {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'insert' )
			|| ! method_exists( $wpdb, 'update' )
		) {
			return $this->storage_error();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction protects verified lifecycle persistence.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->write_error();
		}

		try {
			$locked_current = $this->read_record_db( true );
			if ( is_wp_error( $locked_current ) ) {
				$this->rollback_lifecycle_write();
				return $locked_current;
			}
			if ( ! hash_equals( $this->record_fingerprint( $current ), $this->record_fingerprint( $locked_current ) ) ) {
				$this->rollback_lifecycle_write();
				return $this->concurrency_error();
			}

			$table      = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
			$serialized = maybe_serialize( $record );
			if ( $locked_current['exists'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Row is locked and read back before commit.
				$written = $wpdb->update(
					$table,
					array( 'option_value' => $serialized ),
					array( 'option_name' => self::OPTION_NAME ),
					array( '%s' ),
					array( '%s' )
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Row is inserted and read back before commit.
				$written = $wpdb->insert(
					$table,
					array(
						'option_name'  => self::OPTION_NAME,
						'option_value' => $serialized,
						'autoload'     => 'no',
					),
					array( '%s', '%s', '%s' )
				);
			}

			if ( false === $written || 0 === $written ) {
				$this->rollback_lifecycle_write();
				return $this->write_error();
			}

			$readback = $this->read_record_db( true );
			if ( is_wp_error( $readback ) || ! $readback['exists'] || $record !== $readback['value'] ) {
				$this->rollback_lifecycle_write();
				return $this->write_error();
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit follows exact readback.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$this->rollback_lifecycle_write();
				$this->invalidate_record_cache();
				return $this->write_error();
			}
		} catch ( Throwable $error ) {
			$this->rollback_lifecycle_write();
			return $this->lifecycle_error();
		}

		$this->invalidate_record_cache();

		return $readback['value'];
	}

	/**
	 * Roll back a failed lifecycle transaction without exposing DB details.
	 *
	 * @return void
	 */
	private function rollback_lifecycle_write() {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			try {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Error recovery.
			} catch ( Throwable $error ) {
				// Preserve the sanitized lifecycle error selected by the caller.
				unset( $error );
			}
		}
	}

	/**
	 * Return a stable fingerprint for optimistic pre-lock observation.
	 *
	 * @param array $row Authoritative option row.
	 * @return string
	 */
	private function record_fingerprint( $row ) {
		$material = ! empty( $row['exists'] )
			? 'present:' . maybe_serialize( $row['value'] )
			: 'missing';

		return hash( 'sha256', $material );
	}

	/**
	 * Invalidate all WordPress option-cache views after a direct DB commit.
	 *
	 * @return void
	 */
	private function invalidate_record_cache() {
		try {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		} catch ( Throwable $error ) {
			// Authentication/status use authoritative reads; cache failure is safe.
			unset( $error );
		}
	}

	/**
	 * Return nonsecret metadata for a validated active or revoked record.
	 *
	 * @param array $record Validated record.
	 * @return array
	 */
	private function metadata( $record ) {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'configured'     => true,
			'active'         => 'active' === $record['status'],
			'state'          => $record['status'],
			'credential_id'  => $record['credential_id'],
			'generation'     => $record['generation'],
			'created_at'     => $record['created_at'],
			'rotated_at'     => $record['rotated_at'],
			'revoked_at'     => $record['revoked_at'],
			'contracts'      => $this->contracts(),
		);
	}

	/**
	 * Describe the exact non-mutating contracts authorized by the credential.
	 *
	 * @return array
	 */
	private function contracts() {
		return array(
			'GET /digitalogic/v1/integration/catalog',
			'POST /digitalogic/v1/pricing-assignments/batch',
		);
	}

	/**
	 * Enforce the exact route and method tuple inside the verifier itself.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool
	 */
	private function request_is_allowed( WP_REST_Request $request ) {
		$route  = method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? $request->get_method() : '';
		$key    = strtoupper( (string) $method ) . ' ' . (string) $route;

		return in_array( $key, $this->contracts(), true );
	}

	/**
	 * Check the current client/generation failure bucket.
	 *
	 * @param mixed $state Current throttle state.
	 * @return bool
	 */
	private function is_throttled( $state ) {
		return is_array( $state )
			&& isset( $state['blocked_until'] )
			&& is_int( $state['blocked_until'] )
			&& $state['blocked_until'] > time();
	}

	/**
	 * Record one failed supplied-Authorization attempt without retaining it.
	 *
	 * @param string $key   Token-free throttle key.
	 * @param mixed  $state Current throttle state.
	 * @return void
	 */
	private function record_failure( $key, $state ) {
		$now = time();

		if (
			! is_array( $state )
			|| ! isset( $state['window_started'], $state['count'] )
			|| ! is_int( $state['window_started'] )
			|| ! is_int( $state['count'] )
			|| $state['window_started'] <= $now - self::FAILURE_WINDOW
		) {
			$state = array(
				'window_started' => $now,
				'count'          => 0,
				'blocked_until'  => 0,
			);
		}

		++$state['count'];
		if ( $state['count'] >= self::FAILURE_LIMIT ) {
			$state['blocked_until'] = $now + self::FAILURE_BLOCK_PERIOD;
		}

		$ttl = max( self::FAILURE_WINDOW, $state['blocked_until'] - $now );
		set_transient( $key, $state, $ttl );
	}

	/**
	 * Build a token-free throttle key from server-observed client metadata.
	 *
	 * @param mixed $record Current stored record.
	 * @return string
	 */
	private function throttle_key( $record ) {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';
		$state  = $this->record_state( $record );
		$scope  = ( 'active' === $state || 'revoked' === $state )
			? $record['credential_id'] . ':' . $record['generation']
			: $state;

		return self::THROTTLE_PREFIX . substr( hash( 'sha256', $remote . '|' . $scope ), 0, 40 );
	}

	/**
	 * Return a sanitized authentication error.
	 *
	 * @param string $code   Machine error code.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private function error( $code, $status ) {
		$message = 429 === $status
			? 'Pricing-input machine authentication is temporarily unavailable.'
			: 'Pricing-input machine authentication failed.';

		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Return a sanitized malformed-configuration error.
	 *
	 * @return WP_Error
	 */
	private function configuration_error() {
		return new WP_Error(
			'digitalogic_pricing_input_configuration_invalid',
			'The pricing-input credential configuration is invalid.',
			array( 'status' => 500 )
		);
	}

	/**
	 * Return a retryable optimistic-observation conflict.
	 *
	 * @return WP_Error
	 */
	private function concurrency_error() {
		return new WP_Error(
			'digitalogic_pricing_input_lifecycle_conflict',
			'The pricing-input credential changed concurrently; retry.',
			array(
				'status'    => 409,
				'retryable' => true,
			)
		);
	}

	/**
	 * Return a sanitized authoritative-storage error.
	 *
	 * @return WP_Error
	 */
	private function storage_error() {
		return new WP_Error(
			'digitalogic_pricing_input_storage_unavailable',
			'The pricing-input credential storage is unavailable.',
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
	}

	/**
	 * Return a sanitized unexpected lifecycle error.
	 *
	 * @return WP_Error
	 */
	private function lifecycle_error() {
		return new WP_Error(
			'digitalogic_pricing_input_lifecycle_failed',
			'The pricing-input credential lifecycle could not be completed.',
			array( 'status' => 500 )
		);
	}

	/**
	 * Return a sanitized option-write error.
	 *
	 * @return WP_Error
	 */
	private function write_error() {
		return new WP_Error(
			'digitalogic_pricing_input_write_failed',
			'The pricing-input credential could not be stored.',
			array( 'status' => 500 )
		);
	}

	/**
	 * Return a stable UTC timestamp.
	 *
	 * @return string
	 */
	private function timestamp() {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Validate one stored UTC timestamp.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	private function valid_timestamp( $value ) {
		return is_string( $value )
			&& 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $value );
	}
}
