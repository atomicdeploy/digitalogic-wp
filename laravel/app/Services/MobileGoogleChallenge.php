<?php
/**
 * Session-bound fresh Google proof for sensitive native identity changes.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and existing Laravel contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh security metadata must bypass caches and update atomically.

/** Google challenges grant no authority until a verified signed response is consumed. */
final class MobileGoogleChallenge {

	/** Return the metadata table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_google_challenges';
	}

	/**
	 * Install security metadata independently from canonical WordPress user records.
	 *
	 * @throws \RuntimeException If the required transactional table is unavailable.
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
			user_id bigint(20) unsigned NOT NULL,
			session_id char(32) NOT NULL,
			nonce char(64) NOT NULL,
			proof_hash char(64) NOT NULL DEFAULT '',
			created_at bigint(20) unsigned NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			verified_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY request_hash (request_hash),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};"
		);
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! $status || 'InnoDB' !== $status['Engine'] ) {
			throw new \RuntimeException( 'Google challenge schema unavailable.' );
		}
		update_option( 'digitalogic_mobile_google_challenge_schema', '1', false );
	}

	/**
	 * Create or replay one bounded challenge for the same authenticated session.
	 *
	 * @param array  $session Verified native session.
	 * @param string $request_id Client idempotency UUID.
	 * @return array|\WP_Error Public challenge or definitive rejection.
	 */
	public static function create( array $session, string $request_id ) {
		global $wpdb;
		if ( ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $request_id ) ) {
			return new \WP_Error( 'invalid_request_id' );
		}
		$request_hash = MobileIdentityStore::hash( 'google-challenge:' . $session['id'] . ':' . strtolower( $request_id ) );
		$lock         = 'dgl-google-create-' . substr( $request_hash, 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			return new \WP_Error( 'reauth_busy' );
		}
		try {
			$table = self::table();
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_hash=%s', $table, $request_hash ), ARRAY_A );
			if ( ! $row ) {
				if ( ! MobileIdentityStore::admit( 'google-challenge:' . $session['user_id'], 5, 300 ) ) {
					return new \WP_Error( 'reauth_rate_limited' );
				}
				$row = array(
					'id'           => bin2hex( random_bytes( 16 ) ),
					'request_hash' => $request_hash,
					'user_id'      => (int) $session['user_id'],
					'session_id'   => $session['id'],
					'nonce'        => bin2hex( random_bytes( 32 ) ),
					'created_at'   => time(),
					'expires_at'   => time() + 300,
				);
				if ( false === $wpdb->insert( $table, $row ) ) {
					return new \WP_Error( 'reauth_not_saved' );
				}
			}
			if ( (int) $row['expires_at'] <= time() ) {
				return new \WP_Error( 'reauth_challenge_expired' );
			}
			// Retention is bounded; UUID replay is guaranteed only during the challenge lifetime.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d LIMIT 100', $table, time() - DAY_IN_SECONDS ) );
			return array(
				'challenge_id' => $row['id'],
				'nonce'        => $row['nonce'],
				'expires_at'   => gmdate( 'c', (int) $row['expires_at'] ),
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Match a verified JWT to a fresh, exact-session challenge without side effects.
	 *
	 * @param array $row Stored challenge.
	 * @param array $session Current native session.
	 * @param array $claims Cryptographically verified Google claims.
	 * @param int   $now Current server time.
	 * @return bool Whether this proof can be consumed.
	 */
	public static function matches( array $row, array $session, array $claims, int $now ): bool {
		return (int) ( $row['user_id'] ?? 0 ) === (int) ( $session['user_id'] ?? -1 )
			&& is_string( $row['session_id'] ?? null ) && hash_equals( $row['session_id'], (string) ( $session['id'] ?? '' ) )
			&& (int) ( $row['expires_at'] ?? 0 ) > $now
			&& (int) ( $row['created_at'] ?? PHP_INT_MAX ) <= $now
			&& is_string( $claims['nonce'] ?? null ) && hash_equals( (string) ( $row['nonce'] ?? '' ), $claims['nonce'] )
			&& (int) ( $claims['iat'] ?? 0 ) >= (int) $row['created_at'] - 30;
	}

	/**
	 * Atomically consume an exact proof and update only its existing native session.
	 *
	 * @param string $id Challenge identifier.
	 * @param array  $session Current verified native session.
	 * @param array  $claims Verified Google claims for the exact current owner.
	 * @param string $token Original signed proof, retained only as a keyed hash.
	 * @return int|\WP_Error Original proof timestamp or rejection.
	 */
	public static function consume( string $id, array $session, array $claims, string $token ) {
		global $wpdb;
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) ) {
			return new \WP_Error( 'invalid_reauth_challenge' );
		}
		$table    = self::table();
		$sessions = MobileIdentityStore::table( 'sessions' );
		$lock     = 'dgl-google-proof-' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) ) {
			return new \WP_Error( 'reauth_busy' );
		}
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s', $table, $id ), ARRAY_A );
			if ( ! $row || ! self::matches( $row, $session, $claims, time() ) ) {
				return new \WP_Error( 'invalid_reauth_challenge' );
			}
			$proof_hash = MobileIdentityStore::hash( 'google-proof:' . $token );
			if ( (int) $row['verified_at'] > 0 ) {
				return hash_equals( $row['proof_hash'], $proof_hash ) ? (int) $row['verified_at'] : new \WP_Error( 'reauth_challenge_used' );
			}
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				return new \WP_Error( 'reauth_not_saved' );
			}
			$now = time();
			// Lock and recheck so a concurrent logout cannot gain fresh authority.
			$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d FOR UPDATE', $sessions, $session['id'], $session['user_id'] ), ARRAY_A );
			if ( ! $current || (int) $current['expires_at'] <= $now || ! hash_equals( $current['token_hash'], $session['token_hash'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'invalid_reauth_challenge' );
			}
			$saved_session = $wpdb->update(
				$sessions,
				array(
					'auth_method' => 'google',
					'verified_at' => $now,
				),
				array(
					'id'      => $session['id'],
					'user_id' => $session['user_id'],
				)
			);
			$saved_proof   = $wpdb->update(
				$table,
				array(
					'proof_hash'  => $proof_hash,
					'verified_at' => $now,
				),
				array( 'id' => $id )
			);
			if ( false === $saved_session || 1 !== $saved_proof || false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'reauth_not_saved' );
			}
			return $now;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'reauth_not_saved' );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
