<?php
/**
 * Native RegistrationStore adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.WP.GlobalVariablesOverride -- The identity transaction installs a private cache and restores the original in finally.

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

/** Verified mobile enrollment into the existing WordPress/Digits customer identity. */
final class MobileRegistrationStore {
	/**
	 * Resolve an adapter-owned table from the closed table-name allowlist.
	 *
	 * @return string Native adapter result.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_registration';}
	/**
	 * Create or verify the supporting metadata schema without replacing canonical business records.
	 *
	 * @return void Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
            token_hash char(64) NOT NULL,
            phone_ciphertext text NOT NULL,
            request_hash char(64) DEFAULT NULL,
            state varchar(16) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            session_ciphertext longtext DEFAULT NULL,
            continuation_hash char(64) DEFAULT NULL,
            effects_ciphertext longtext DEFAULT NULL,
            effects_count int unsigned NOT NULL DEFAULT 0,
            effects_cursor int unsigned NOT NULL DEFAULT 0,
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB {$charset};"
		);
		$otp = MobileIdentityStore::table( 'otp' );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $otp, $wpdb->esc_like( 'phone_ciphertext' ) ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Targeted upgrade of the adapter-owned OTP metadata table.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD phone_ciphertext text DEFAULT NULL', $otp ) );}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $otp, $wpdb->esc_like( 'phone_ciphertext' ) ) ) ) {
			throw new \RuntimeException( 'Enrollment proof storage unavailable.' );}
		update_option( 'digitalogic_mobile_registration_schema', '1', false );
	}
	/**
	 * Report whether the deployed adapter and its required provider are available.
	 *
	 * @return bool Native adapter result.
	 */
	public static function enabled(): bool {
		$fields = get_option( 'dig_reg_fields', array() );
		return get_option( 'digitalogic_mobile_registration_schema' ) === '1' && (string) get_option( 'dig_enable_registration' ) === '1' && (string) get_option( 'dig_mobilein_uname' ) === '1' && (string) ( $fields['dig_reg_mobilenumber'] ?? '' ) === '2' && (string) ( $fields['dig_reg_email'] ?? '' ) === '0' && (string) ( $fields['dig_reg_password'] ?? '' ) === '0';
	}
	/**
	 * Encrypt short-lived proof state with an explicit purpose-separated key.
	 *
	 * @param string $value Value.
	 * @param string $purpose Purpose.
	 * @return string Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function seal( string $value, string $purpose ): string {
		$nonce  = random_bytes( 12 );
		$tag    = '';
		$key    = hash( 'sha256', wp_salt( 'auth' ) . ':native-enrollment:' . $purpose, true );
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'digitalogic-native-enrollment-v1' );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'Enrollment encryption unavailable.' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary proof/JWK encoding; no executable code is decoded.
		}return base64_encode( $nonce . $tag . $cipher );
	}
	/**
	 * Authenticate and decrypt only the matching purpose-bound proof state.
	 *
	 * @param string $value Value.
	 * @param string $purpose Purpose.
	 * @return string|null Native adapter result.
	 */
	public static function open( string $value, string $purpose ): ?string {
		if ( strlen( $value ) > 20000 ) {
			return null;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary proof/JWK encoding; no executable code is decoded.
		}$raw = base64_decode( $value, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}$key  = hash( 'sha256', wp_salt( 'auth' ) . ':native-enrollment:' . $purpose, true );
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'digitalogic-native-enrollment-v1' );
		return false === $plain ? null : $plain;
	}
	/**
	 * Project the existing canonical registration terms.
	 *
	 * @return array Native adapter result.
	 */
	public static function terms(): array {
		$page_id = wc_terms_and_conditions_page_id();
		$page    = $page_id ? get_post( $page_id ) : null;
		return array(
			'required' => wc_terms_and_conditions_checkbox_enabled(),
			'label'    => wp_strip_all_tags( wc_get_terms_and_conditions_checkbox_text() ),
			'text'     => $page ? wp_strip_all_tags( strip_shortcodes( $page->post_content ) ) : '',
		);
	}
	/**
	 * Reserve a short-lived explicit registration continuation after phone proof.
	 *
	 * @param array $challenge Challenge.
	 * @return array|null Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function begin( array $challenge ): ?array {
		if ( ! self::enabled() || empty( $challenge['phone_ciphertext'] ) ) {
			return null;}
		$phone = self::open( $challenge['phone_ciphertext'], 'phone' );
		if ( ! $phone || MobileIdentityStore::phone( $phone ) !== $phone || ! hash_equals( $challenge['phone_hash'], MobileIdentityStore::hash( $phone ) ) ) {
			return null;}
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE expires_at<%d AND (state<>'registered' OR effects_cursor>=effects_count) LIMIT 100", $table, time() ) );
		$token   = bin2hex( random_bytes( 32 ) );
		$expires = time() + 600;
		if ( $wpdb->insert(
			$table,
			array(
				'token_hash'        => hash( 'sha256', $token ),
				'phone_ciphertext'  => $challenge['phone_ciphertext'],
				'continuation_hash' => $challenge['continuation_hash'] ?? null,
				'state'             => 'verified',
				'expires_at'        => $expires,
			)
		) !== 1 ) {
			throw new \RuntimeException( 'Enrollment proof could not be saved.' );}
		return array(
			'registered'         => false,
			'registration_token' => $token,
			'expires_at'         => gmdate( 'c', $expires ),
			'terms'              => self::terms(),
		);
	}
	/**
	 * Complete the immutable registration operation without inventing human credentials.
	 *
	 * @param string      $token Opaque or signed identity proof; never logged.
	 * @param string      $request_id Request id.
	 * @param string      $device Customer-visible session name.
	 * @param bool        $terms_accepted Terms accepted.
	 * @return mixed Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 *
	 * @param string|null $continuation_token Purpose-bound Google continuation, if provided.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function complete( string $token, string $request_id, string $device, bool $terms_accepted, ?string $continuation_token = null ) {
		if ( ! self::enabled() || ! preg_match( '/\A[a-f0-9]{64}\z/D', $token ) || ! MobileRequestStore::validRequestId( $request_id ) ) {
			return new \WP_Error( 'invalid_registration', 'درخواست عضویت معتبر نیست.', array( 'status' => 400 ) );}
		if ( self::terms()['required'] && ! $terms_accepted ) {
			return new \WP_Error( 'terms_required', 'شرایط عضویت را تأیید کنید.', array( 'status' => 422 ) );}
		global $wpdb;
		$table        = self::table();
		$hash         = hash( 'sha256', $token );
		$request_hash = MobileIdentityStore::hash( $request_id . ':' . mb_substr( $device, 0, 80 ) );
		$row          = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s AND expires_at>%d', $table, $hash, time() ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'registration_expired', 'شماره موبایل را دوباره تأیید کنید.', array( 'status' => 410 ) );}
		$phone = self::open( $row['phone_ciphertext'], 'phone' );
		if ( ! $phone || MobileIdentityStore::phone( $phone ) !== $phone ) {
			return new \WP_Error( 'invalid_registration', 'تأیید شماره معتبر نیست.', array( 'status' => 401 ) );}
		$lock = 'dgl-enroll-' . substr( MobileIdentityStore::hash( $phone ), 0, 40 );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== 1 ) {
			return new \WP_Error( 'registration_in_progress', 'درخواست در حال بررسی است؛ با همان شناسه ادامه دهید.', array( 'status' => 409 ) );}
		$new_user       = 0;
		$original_cache = $GLOBALS['wp_object_cache'];
		try {
			$GLOBALS['wp_object_cache'] = new MobileMergeObjectCache( null, (array) ( $original_cache->global_groups ?? array() ), (array) ( $original_cache->non_persistent_groups ?? array() ) );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Registration transaction unavailable.' ); }
			MobileMergeEffects::begin( $hash );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s FOR UPDATE', $table, $hash ), ARRAY_A );
			if ( ! $row || $row['expires_at'] <= time() ) {
				throw new \RuntimeException( 'registration_expired' );}
			if ( 'registered' === $row['state'] ) {
				if ( ( ! empty( $row['continuation_hash'] ) || null !== $continuation_token ) && ( ! is_string( $continuation_token ) || ! hash_equals( (string) ( $row['continuation_hash'] ?? '' ), hash( 'sha256', $continuation_token ) ) ) ) {
					throw new \RuntimeException( 'identity_conflict' ); }
				$wpdb->query( 'COMMIT' );
				if ( ! hash_equals( $row['request_hash'], $request_hash ) ) {
					return new \WP_Error( 'request_id_conflict', 'این تأیید قبلاً با درخواست دیگری استفاده شده است.', array( 'status' => 409 ) );}
				$session = json_decode( self::open( $row['session_ciphertext'], 'session' ) ?? '', true );
				return is_array( $session ) && MobileIdentityStore::authenticate( 'Bearer ' . ( $session['access_token'] ?? '' ) ) ? $session + array(
					'user_id'  => (int) $row['user_id'],
					'replayed' => true,
				) : new \WP_Error( 'registration_expired', 'دوباره وارد حساب شوید.', array( 'status' => 410 ) );
			}
			$continuation = null;
			if ( ! empty( $row['continuation_hash'] ) || null !== $continuation_token ) {
				$continuation = MobileGoogleContinuation::read( $continuation_token );
				if ( is_wp_error( $continuation ) || ! hash_equals( (string) ( $row['continuation_hash'] ?? '' ), $continuation['token_hash'] ) || 'phone_verified' !== $continuation['state'] || ! hash_equals( (string) $continuation['phone_hash'], MobileIdentityStore::hash( $phone ) ) ) {
					throw new \RuntimeException( 'identity_conflict' ); }
			}
			$user_id = MobileIdentityStore::ownerOfPhone( $phone );
			if ( $continuation && 'phone_verified' !== $continuation['state'] ) {
				throw new \RuntimeException( 'identity_conflict' ); }
			if ( $user_id < 0 ) {
				throw new \RuntimeException( 'identity_conflict' );}
			if ( 0 === $user_id ) {
				if ( $continuation ) {
					$owners = MobileIdentityResolver::emailOwners( $continuation['proof']['email'] );
					if ( null === $owners || $owners ) {
						throw new \RuntimeException( 'identity_conflict' ); }
				}
				$username = sanitize_user( ltrim( $phone, '+' ), true );
				if ( username_exists( $username ) ) {
					throw new \RuntimeException( 'identity_conflict' );}
				$userdata       = array(
					'user_login'   => $username,
					'user_pass'    => '',
					'user_email'   => '',
					'display_name' => $username,
					'role'         => 'customer',
					'meta_input'   => array(
						'digits_phone'                     => $phone,
						'digits_phone_no'                  => substr( $phone, 3 ),
						'digt_countrycode'                 => '+98',
						'billing_phone'                    => '0' . substr( $phone, 3 ),
						'_digitalogic_mobile_passwordless' => 1,
					),
				);
				$creation_error = MobileWebsiteIdentityGuard::validateNewUser( $userdata );
				if ( is_wp_error( $creation_error ) ) {
					throw new \RuntimeException( 'identity_conflict' ); }
				$user_id = wp_insert_user( $userdata );
				if ( is_wp_error( $user_id ) ) {
					throw new \RuntimeException( 'registration_failed' );
				}$new_user = (int) $user_id;
				// A deliberately invalid password hash: no generated human credential and no password login.
				if ( $wpdb->update( $wpdb->users, array( 'user_pass' => '!digitalogic-passwordless-mobile-v1' ), array( 'ID' => $user_id ) ) !== 1 ) {
					throw new \RuntimeException( 'registration_failed' );
				}clean_user_cache( $user_id );
				if ( MobileIdentityStore::ownerOfPhone( $phone ) !== (int) $user_id ) {
					throw new \RuntimeException( 'identity_conflict' );}
				do_action( 'woocommerce_created_customer', $user_id, $userdata, false );
			}
			if ( $continuation ) {
				MobileGoogleContinuation::bindLocked( $continuation, (int) $user_id, $phone ); }
			$session = MobileIdentityStore::issueSession( (int) $user_id, $device, 'otp' );
			$payload = $session + array(
				'registered' => true,
				'user_id'    => (int) $user_id,
				'created'    => $new_user > 0,
			);
			if ( $continuation && 1 !== $wpdb->update(
				MobileGoogleContinuation::table(),
				array(
					'state'             => 'linked',
					'user_id'           => $user_id,
					'result_ciphertext' => self::seal( wp_json_encode( $payload ), 'google-continuation-result' ),
				),
				array(
					'token_hash' => $continuation['token_hash'],
					'state'      => 'phone_verified',
				)
			) ) {
				throw new \RuntimeException( 'registration_uncertain' ); }
			if ( $wpdb->update(
				$table,
				array(
					'state'              => 'registered',
					'request_hash'       => $request_hash,
					'user_id'            => $user_id,
					'session_ciphertext' => self::seal( wp_json_encode( $payload ), 'session' ),
					'effects_ciphertext' => MobileMergeEffects::seal(),
					'effects_count'      => MobileMergeEffects::count(),
				),
				array( 'token_hash' => $hash )
			) !== 1 || $wpdb->query( 'COMMIT' ) === false ) {
				throw new \RuntimeException( 'registration_uncertain' );}
			return $payload;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $new_user ) {
				clean_user_cache( $new_user );
			}return new \WP_Error( in_array( $error->getMessage(), array( 'registration_expired', 'identity_conflict' ), true ) ? $error->getMessage() : 'registration_uncertain', 'عضویت کامل نشده است؛ با همان شناسه وضعیت را دوباره بررسی کنید.', array( 'status' => 409 ) );
		} finally {
			MobileMergeEffects::stop();
			$GLOBALS['wp_object_cache'] = $original_cache;
			if ( ( $user_id ?? 0 ) > 0 ) {
				clean_user_cache( (int) $user_id ); }
			if ( $new_user ) {
				clean_user_cache( $new_user ); }
			MobileMergeEffects::schedulePending();
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}
}
