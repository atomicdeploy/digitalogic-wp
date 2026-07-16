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

		$record         = $this->read_record();
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
		$record = $this->read_record();
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

		return $this->issue( null, $generation, false );
	}

	/**
	 * Rotate the active credential and invalidate its old value immediately.
	 *
	 * @return array|WP_Error Secret and nonsecret metadata, or an error.
	 */
	public function rotate() {
		$record = $this->read_record();
		if ( 'active' !== $this->record_state( $record ) ) {
			return new WP_Error(
				'digitalogic_pricing_input_not_active',
				'No active pricing-input credential is available to rotate.',
				array( 'status' => 409 )
			);
		}

		return $this->issue( $record, (int) $record['generation'] + 1, true );
	}

	/**
	 * Revoke the active credential.
	 *
	 * @return array|WP_Error Nonsecret status metadata, or an error.
	 */
	public function revoke() {
		$record = $this->read_record();
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

		if ( ! update_option( self::OPTION_NAME, $record, false ) ) {
			return $this->write_error();
		}

		return $this->metadata( $record );
	}

	/**
	 * Return only nonsecret credential metadata.
	 *
	 * @return array
	 */
	public function status() {
		$record = $this->read_record();
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
	 * Generate and persist a new one-way credential record.
	 *
	 * @param array|null $previous   Previous active record during rotation.
	 * @param int        $generation New generation number.
	 * @param bool       $rotation   Whether this is a rotation.
	 * @return array|WP_Error
	 */
	private function issue( $previous, $generation, $rotation ) {
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

		if ( ! update_option( self::OPTION_NAME, $record, false ) ) {
			return $this->write_error();
		}

		return array(
			'secret'   => $token,
			'metadata' => $this->metadata( $record ),
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
	 * Return the stored record without coercion.
	 *
	 * @return mixed
	 */
	private function read_record() {
		return get_option( self::OPTION_NAME, null );
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
