<?php
/**
 * Native IdentityStore adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

/** Native customer identities are isolated from WordPress browser sessions. */
final class MobileIdentityStore {
	/**
	 * Create or verify the supporting metadata schema without replacing canonical business records.
	 *
	 * @return void Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset   = $wpdb->get_charset_collate();
		$otp       = $wpdb->prefix . 'digitalogic_mobile_otp';
		$sessions  = $wpdb->prefix . 'digitalogic_mobile_sessions';
		$favorites = $wpdb->prefix . 'digitalogic_mobile_favorites';
		dbDelta(
			"CREATE TABLE {$otp} (
			id char(32) NOT NULL,
			request_hash char(64) NOT NULL,
			phone_hash char(64) NOT NULL,
			phone_ciphertext text DEFAULT NULL,
			purpose varchar(20) NOT NULL DEFAULT 'login',
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_session_id char(32) NOT NULL DEFAULT '',
			result varchar(32) NOT NULL DEFAULT '',
			user_id bigint(20) NOT NULL DEFAULT 0,
			code_hash char(64) NOT NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			state varchar(16) NOT NULL DEFAULT 'pending',
			created_at bigint(20) unsigned NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_hash (request_hash),
			KEY expires_at (expires_at)
		) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$sessions} (
			id char(32) NOT NULL,
			token_hash char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			auth_epoch char(64) NOT NULL,
			auth_method varchar(20) NOT NULL DEFAULT 'legacy',
			verified_at bigint(20) unsigned NOT NULL DEFAULT 0,
			device_name varchar(80) NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			last_seen_at bigint(20) unsigned NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$favorites} (
			user_id bigint(20) unsigned NOT NULL,
			items longtext NOT NULL,
			revision char(64) NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (user_id)
		) {$charset};"
		);
		foreach ( array( $otp, $sessions, $favorites ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new \RuntimeException( 'Native identity schema not ready.' );
			}
		}
		foreach ( array(
			$otp      => array( 'purpose', 'actor_user_id', 'actor_session_id', 'result', 'phone_ciphertext' ),
			$sessions => array( 'auth_method', 'verified_at' ),
		) as $table => $columns ) {
			foreach ( $columns as $column ) {
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) ) ) {
					throw new \RuntimeException( 'Native identity upgrade incomplete.' );
				}
			}
		}
		update_option( 'digitalogic_mobile_identity_schema', '2', false );
		MobileGoogleChallenge::install();
	}

	/**
	 * Normalize Persian and Arabic digits for identity and form validation.
	 *
	 * @param string $value Value.
	 * @return string Native adapter result.
	 */
	public static function digits( string $value ): string {
		return strtr(
			$value,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
	}

	/**
	 * Normalize Iranian mobile input without accepting alternate countries or trailing text.
	 *
	 * @param string $value Value.
	 * @return string|null Native adapter result.
	 */
	public static function phone( string $value ): ?string {
		$value = preg_replace( '/[\s()-]+/u', '', self::digits( trim( $value ) ) );
		if ( preg_match( '/\A09[0-9]{9}\z/D', $value ) ) {
			return '+98' . substr( $value, 1 );
		}
		if ( str_starts_with( $value, '0098' ) ) {
			$value = '+' . substr( $value, 2 );
		} elseif ( preg_match( '/\A989[0-9]{9}\z/D', $value ) ) {
			$value = '+' . $value;
		}
		return preg_match( '/\A\+989[0-9]{9}\z/D', $value ) ? $value : null;
	}

	/**
	 * Create a keyed identifier without retaining raw security-sensitive input.
	 *
	 * @param string $value Value.
	 * @return string Native adapter result.
	 */
	public static function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/**
	 * Resolve an adapter-owned table from the closed table-name allowlist.
	 *
	 * @param string $name Name.
	 * @return string Native adapter result.
	 * @throws \InvalidArgumentException When the authoritative operation cannot be completed safely.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'otp', 'sessions', 'favorites' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown native identity table.' );
		}
		return $wpdb->prefix . 'digitalogic_mobile_' . $name;
	}

	/**
	 * Phone identity comes only from Digits verification metadata, never billing.
	 *
	 * @param string $phone Normalized phone identity.
	 * @return int Native adapter result.
	 */
	public static function ownerOfPhone( string $phone ): int {
		$users = get_users(
			array(
				'number'     => 2,
				'fields'     => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact existing Digits binding, limited to two matches to reject ambiguity.
				'meta_query' => array(
					array(
						'key'   => 'digits_phone',
						'value' => $phone,
					),
				),
			)
		);
		return count( $users ) === 1 ? (int) $users[0] : ( count( $users ) > 1 ? -1 : 0 );
	}

	/**
	 * Authenticate a native bearer against current session and WordPress password authority.
	 *
	 * @param string $header Header.
	 * @return array|null Native adapter result.
	 */
	public static function authenticate( string $header ): ?array {
		global $wpdb;
		if ( ! preg_match( '/\ABearer ([a-f0-9]{64})\z/iD', $header, $match ) ) {
			return null;
		}
		$table = self::table( 'sessions' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s AND expires_at>%d', $table, hash( 'sha256', $match[1] ), time() ), ARRAY_A );
		$user  = $row ? get_user_by( 'id', (int) $row['user_id'] ) : false;
		if ( ! $user || ! hash_equals( $row['auth_epoch'], self::hash( $user->user_pass ) ) ) {
			return null;
		}
		if ( (int) $row['last_seen_at'] < time() - 60 ) {
			$wpdb->update( $table, array( 'last_seen_at' => time() ), array( 'id' => $row['id'] ), array( '%d' ), array( '%s' ) );
		}
		return $row;
	}

	/**
	 * Long-lived sockets recheck database authority without a stale WordPress user cache.
	 *
	 * @param string $id Exact scoped identifier.
	 * @param int    $user_id Verified WordPress owner.
	 * @return bool Native adapter result.
	 */
	public static function sessionIsCurrent( string $id, int $user_id ): bool {
		global $wpdb;
		if ( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='digitalogic_mobile_native_auth'" ) !== '1' ) {
			return false; }
		$table = self::table( 'sessions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT s.auth_epoch,u.user_pass FROM %i s JOIN {$wpdb->users} u ON u.ID=s.user_id WHERE s.id=%s AND s.user_id=%d AND s.expires_at>%d", $table, $id, $user_id, time() ), ARRAY_A );
		return $row && hash_equals( $row['auth_epoch'], self::hash( $row['user_pass'] ) );
	}

	/**
	 * Issue an owner-bound native session with hashed storage and explicit identity-proof freshness.
	 *
	 * @param int      $user_id Verified WordPress owner.
	 * @param string   $device Customer-visible session name.
	 * @param string   $method Method.
	 * @param int|null $proof_at Proof at.
	 * @return array Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function issueSession( int $user_id, string $device, string $method = 'legacy', ?int $proof_at = null ): array {
		global $wpdb;
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			throw new \RuntimeException( 'Account unavailable.' );
		}
		$token         = bin2hex( random_bytes( 32 ) );
		$id            = bin2hex( random_bytes( 16 ) );
		$expires       = time() + 30 * DAY_IN_SECONDS;
		$session_table = self::table( 'sessions' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<%d LIMIT 100', $session_table, time() ) );
		$created = $wpdb->insert(
			self::table( 'sessions' ),
			array(
				'id'           => $id,
				'token_hash'   => hash( 'sha256', $token ),
				'user_id'      => $user_id,
				'auth_epoch'   => self::hash( $user->user_pass ),
				'auth_method'  => in_array( $method, array( 'otp', 'google' ), true ) ? $method : 'legacy',
				'verified_at'  => in_array( $method, array( 'otp', 'google' ), true ) ? min( time(), $proof_at ?? time() ) : 0,
				'device_name'  => mb_substr( sanitize_text_field( $device ), 0, 80 ),
				'created_at'   => time(),
				'last_seen_at' => time(),
				'expires_at'   => $expires,
			)
		);
		if ( 1 !== $created ) {
			throw new \RuntimeException( 'Session could not be saved.' );
		}
		return array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'session_id'   => $id,
			'expires_at'   => gmdate( 'c', $expires ),
		);
	}

	/**
	 * A session's creation time is not evidence of a recent identity challenge.
	 *
	 * @param array    $session Authenticated native session metadata.
	 * @param int|null $now Now.
	 * @return bool Native adapter result.
	 */
	public static function freshProof( array $session, ?int $now = null ): bool {
		$now      = $now ?? time();
		$verified = (int) ( $session['verified_at'] ?? 0 );
		return in_array( $session['auth_method'] ?? '', array( 'google', 'otp' ), true ) && $verified >= $now - 600 && $verified <= $now + 30;
	}

	/**
	 * Claim a verified Google token once for native session issuance.
	 *
	 * @param string $token Opaque or signed identity proof; never logged.
	 * @param int    $expires Expires.
	 * @return bool Native adapter result.
	 */
	public static function claimGoogleToken( string $token, int $expires ): bool {
		global $wpdb;
		$prefix = '_dgl_mobile_google_used_';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d LIMIT 100", $wpdb->esc_like( $prefix ) . '%', time() ) );
		return add_option( $prefix . hash( 'sha256', $token ), (string) $expires, '', false );
	}

	/**
	 * Atomic SQL counters enforce limits across PHP workers; only keyed scope hashes persist.
	 *
	 * @param string $scope Scope.
	 * @param int    $limit Limit.
	 * @param int    $seconds Seconds.
	 * @return bool Native adapter result.
	 */
	public static function admit( string $scope, int $limit, int $seconds ): bool {
		global $wpdb;
		$key = '_dgl_mobile_auth_rate_' . ( intdiv( time(), $seconds ) * $seconds ) . '_' . self::hash( $scope . ':' . $seconds );
		if ( random_int( 1, 50 ) === 1 ) {
			// The epoch is always a bucket number; drop only counters older than a day.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(SUBSTRING(option_name,23),'_',1) AS UNSIGNED)<%d LIMIT 200", $wpdb->esc_like( '_dgl_mobile_auth_rate_' ) . '%', time() - DAY_IN_SECONDS ) );
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'0','off')", $key ) );
		return $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=CAST(option_value AS UNSIGNED)+1 WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d", $key, $limit ) ) === 1;
	}
}
