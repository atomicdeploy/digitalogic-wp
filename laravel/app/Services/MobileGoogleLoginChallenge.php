<?php
/**
 * Subject-bound public Google login challenges, isolated from native reauthentication.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 service contract.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Security metadata requires uncached, atomic authority checks.

/** A previous signed token permits a challenge, never a session or account claim. */
final class MobileGoogleLoginChallenge {

	/** Return the separate, public-only security metadata table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_google_login_challenges';
	}

	/**
	 * Install nonce metadata; canonical users and native sessions are untouched.
	 *
	 * @throws \RuntimeException When transactional metadata storage is unavailable.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id char(32) NOT NULL,
			request_hash char(64) NOT NULL,
			network_hash char(64) NOT NULL,
			subject_hash char(64) NOT NULL,
			purpose varchar(20) NOT NULL,
			user_id bigint unsigned NOT NULL DEFAULT 0,
			nonce char(64) NOT NULL,
			proof_hash char(64) NOT NULL DEFAULT '',
			created_at bigint unsigned NOT NULL,
			expires_at bigint unsigned NOT NULL,
			verified_at bigint unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY request_hash (request_hash),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};"
		);
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! $status || 'InnoDB' !== $status['Engine'] || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Public Google challenge storage unavailable.' );
		}
		update_option( 'digitalogic_mobile_google_login_challenge_schema', '1', false );
	}

	/**
	 * Replay one unexpired nonce only for the same network, UUID and verified subject.
	 *
	 * @param string $request_id Idempotency UUID.
	 * @param string $network_scope Server-selected network scope, never a client-supplied identity.
	 * @param string $expected_subject Subject from a cryptographically verified previous token.
	 * @return array|\WP_Error Public nonce or bounded rejection.
	 */
	public static function create( string $request_id, string $network_scope, string $expected_subject ) {
		global $wpdb;
		if ( ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $request_id ) || '' === trim( $network_scope ) || strlen( $network_scope ) > 256 || ! preg_match( '/\A[0-9]{1,255}\z/D', $expected_subject ) ) {
			return self::error( 'google_login_invalid_request', 400 );
		}
		$network_hash = MobileIdentityStore::hash( 'google-public-network:' . $network_scope );
		$subject_hash = MobileIdentityStore::hash( 'google-public-subject:' . $expected_subject );
		$request_hash = MobileIdentityStore::hash( 'google-public-login:' . $network_hash . ':' . strtolower( $request_id ) );
		$lock         = 'dgl-glogin-create-' . substr( $request_hash, 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			return self::error( 'google_login_busy', 409 );
		}
		try {
			$table = self::table();
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_hash=%s', $table, $request_hash ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::error( 'google_login_not_saved', 503 );
			}
			if ( $row ) {
				if ( ! self::publicRow( $row ) || ! hash_equals( $row['network_hash'], $network_hash ) || ! hash_equals( $row['subject_hash'], $subject_hash ) ) {
					return self::error( 'google_login_challenge_conflict', 409 );
				}
				if ( (int) $row['expires_at'] <= time() ) {
					return self::error( 'google_login_challenge_expired', 410 );
				}
			} else {
				if ( ! MobileIdentityStore::admit( 'google-public-login:network:' . $network_hash, 10, 300 ) || ! MobileIdentityStore::admit( 'google-public-login:global', 200, 300 ) ) {
					return self::error( 'google_login_rate_limited', 429 );
				}
				$now = time();
				$row = array(
					'id'           => bin2hex( random_bytes( 16 ) ),
					'request_hash' => $request_hash,
					'network_hash' => $network_hash,
					'subject_hash' => $subject_hash,
					'purpose'      => 'google_login',
					'user_id'      => 0,
					'nonce'        => bin2hex( random_bytes( 32 ) ),
					'proof_hash'   => '',
					'created_at'   => $now,
					'expires_at'   => $now + 300,
					'verified_at'  => 0,
				);
				if ( 1 !== $wpdb->insert( $table, $row ) ) {
					return self::error( 'google_login_not_saved', 503 );
				}
				$saved = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $table, $row['id'] ), ARRAY_A );
				if ( '' !== $wpdb->last_error || ! $saved || ! self::sameRow( $row, $saved ) ) {
					return self::error( 'google_login_not_saved', 503 );
				}
			}
			// Expired proofs have no authority; bounded cleanup never touches accounts.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<%d LIMIT 100', $table, time() - DAY_IN_SECONDS ) );
			return array(
				'challenge_id' => $row['id'],
				'nonce'        => $row['nonce'],
				'expires_at'   => gmdate( 'c', (int) $row['expires_at'] ),
			);
		} catch ( \Throwable $error ) {
			return self::error( 'google_login_not_saved', 503 );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Consume a signed nonce once; exact retries preserve original timestamps.
	 *
	 * Signature, issuer, audience, presenter, expiry and verified email are mandatory
	 * caller checks. Returned creation time must also pass its binding-activation fence.
	 *
	 * @param string $id Public challenge identifier, never an authenticated challenge.
	 * @param array  $claims Cryptographically verified fresh Google claims.
	 * @param string $token Exact signed token; only its keyed digest is retained.
	 * @return array|\WP_Error Original verified_at/created_at/expires_at epoch seconds or rejection.
	 */
	public static function consume( string $id, array $claims, string $token ) {
		global $wpdb;
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) || '' === $token || strlen( $token ) > 6000 ) {
			return self::error( 'google_login_invalid_challenge', 409 );
		}
		$lock = 'dgl-glogin-proof-' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			return self::error( 'google_login_busy', 409 );
		}
		try {
			$table = self::table();
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $table, $id ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::error( 'google_login_not_saved', 503 );
			}
			if ( ! $row || ! self::matches( $row, $claims, time() ) ) {
				return self::error( 'google_login_invalid_challenge', 409 );
			}
			$proof_hash = MobileIdentityStore::hash( 'google-public-proof:' . $token );
			if ( (int) $row['verified_at'] > 0 ) {
				return hash_equals( $row['proof_hash'], $proof_hash ) ? self::result( $row ) : self::error( 'google_login_challenge_used', 409 );
			}
			$now   = time();
			$saved = $wpdb->update(
				$table,
				array(
					'proof_hash'  => $proof_hash,
					'verified_at' => $now,
				),
				array(
					'id'          => $id,
					'purpose'     => 'google_login',
					'user_id'     => 0,
					'proof_hash'  => '',
					'verified_at' => 0,
				)
			);
			if ( false === $saved ) {
				return self::error( 'google_login_not_saved', 503 );
			}
			$readback = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $table, $id ), ARRAY_A );
			if ( '' !== $wpdb->last_error || ! $readback || ! self::matches( $readback, $claims, time() ) || ! hash_equals( $readback['proof_hash'], $proof_hash ) || (int) $readback['verified_at'] < (int) $row['created_at'] || (int) $readback['verified_at'] > time() ) {
				return self::error( 'google_login_not_saved', 503 );
			}
			return self::result( $readback );
		} catch ( \Throwable $error ) {
			return self::error( 'google_login_not_saved', 503 );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Check public purpose, exact subject/nonce and bounded proof freshness.
	 *
	 * @param array $row Stored public challenge.
	 * @param array $claims Verified signed claims.
	 * @param int   $now Current server epoch.
	 * @return bool Whether this exact proof belongs to this public challenge.
	 */
	public static function matches( array $row, array $claims, int $now ): bool {
		return self::publicRow( $row )
			&& (int) $row['created_at'] <= $now && (int) $row['expires_at'] > $now
			&& ( '' === $row['proof_hash'] ? 0 === (int) $row['verified_at'] : (int) $row['verified_at'] >= (int) $row['created_at'] && (int) $row['verified_at'] <= $now && (int) $row['verified_at'] < (int) $row['expires_at'] )
			&& is_string( $claims['sub'] ?? null ) && preg_match( '/\A[0-9]{1,255}\z/D', $claims['sub'] )
			&& hash_equals( $row['subject_hash'], MobileIdentityStore::hash( 'google-public-subject:' . $claims['sub'] ) )
			&& is_string( $claims['nonce'] ?? null ) && hash_equals( $row['nonce'], $claims['nonce'] )
			&& is_int( $claims['iat'] ?? null ) && $claims['iat'] >= (int) $row['created_at'] - 30 && $claims['iat'] <= $now + 30;
	}

	/**
	 * Validate storage purpose and lifetime independently of any caller credential.
	 *
	 * @param array $row Stored public challenge.
	 * @return bool Whether the stored purpose and lifetime are valid.
	 */
	private static function publicRow( array $row ): bool {
		return isset( $row['user_id'] ) && 0 === (int) $row['user_id'] && 'google_login' === ( $row['purpose'] ?? '' )
			&& is_string( $row['nonce'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/D', $row['nonce'] )
			&& is_string( $row['subject_hash'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/D', $row['subject_hash'] )
			&& is_string( $row['network_hash'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/D', $row['network_hash'] )
			&& is_string( $row['proof_hash'] ?? null ) && ( '' === $row['proof_hash'] || preg_match( '/\A[a-f0-9]{64}\z/D', $row['proof_hash'] ) )
			&& (int) ( $row['created_at'] ?? 0 ) > 0 && (int) ( $row['expires_at'] ?? 0 ) > (int) $row['created_at']
			&& (int) $row['expires_at'] - (int) $row['created_at'] <= 300
			&& (int) ( $row['verified_at'] ?? -1 ) >= 0;
	}

	/**
	 * Compare SQL values to the intended insert without loose type coercion.
	 *
	 * @param array $expected Intended persisted values.
	 * @param array $actual Current SQL row.
	 * @return bool Whether every intended value was persisted.
	 */
	private static function sameRow( array $expected, array $actual ): bool {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) || (string) $actual[ $key ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return original evidence timestamps without renewing proof authority.
	 *
	 * @param array $row Validated proof row.
	 * @return array Original evidence timestamps.
	 */
	private static function result( array $row ): array {
		return array(
			'verified_at' => (int) $row['verified_at'],
			'created_at'  => (int) $row['created_at'],
			'expires_at'  => (int) $row['expires_at'],
		);
	}

	/**
	 * Return a stable failure without token or identity values.
	 *
	 * @param string $code Public error code.
	 * @param int    $status HTTP status.
	 * @return \WP_Error Safe rejection.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, '', array( 'status' => $status ) );
	}
}
