<?php
namespace Digitalogic\Laravel\Services;

/** Verified mobile enrollment into the existing WordPress/Digits customer identity. */
final class MobileRegistrationStore {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_registration';}
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
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB {$charset};"
		);
		$otp = MobileIdentityStore::table( 'otp' );
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$otp} LIKE 'phone_ciphertext'" ) ) {
			$wpdb->query( "ALTER TABLE {$otp} ADD phone_ciphertext text DEFAULT NULL" );}
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$otp} LIKE 'phone_ciphertext'" ) ) {
			throw new \RuntimeException( 'Enrollment proof storage unavailable.' );}
		update_option( 'digitalogic_mobile_registration_schema', '1', false );
	}
	public static function enabled(): bool {
		$fields = get_option( 'dig_reg_fields', array() );
		return get_option( 'digitalogic_mobile_registration_schema' ) === '1' && (string) get_option( 'dig_enable_registration' ) === '1' && (string) get_option( 'dig_mobilein_uname' ) === '1' && (string) ( $fields['dig_reg_mobilenumber'] ?? '' ) === '2' && (string) ( $fields['dig_reg_email'] ?? '' ) === '0' && (string) ( $fields['dig_reg_password'] ?? '' ) === '0';
	}
	public static function seal( string $value, string $purpose ): string {
		$nonce  = random_bytes( 12 );
		$tag    = '';
		$key    = hash( 'sha256', wp_salt( 'auth' ) . ':native-enrollment:' . $purpose, true );
		$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'digitalogic-native-enrollment-v1' );
		if ( $cipher === false ) {
			throw new \RuntimeException( 'Enrollment encryption unavailable.' );
		}return base64_encode( $nonce . $tag . $cipher );
	}
	public static function open( string $value, string $purpose ): ?string {
		if ( strlen( $value ) > 20000 ) {
			return null;
		}$raw = base64_decode( $value, true );
		if ( $raw === false || strlen( $raw ) < 29 ) {
			return null;
		}$key  = hash( 'sha256', wp_salt( 'auth' ) . ':native-enrollment:' . $purpose, true );
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'digitalogic-native-enrollment-v1' );
		return $plain === false ? null : $plain;
	}
	public static function terms(): array {
		$page_id = wc_terms_and_conditions_page_id();
		$page    = $page_id ? get_post( $page_id ) : null;
		return array(
			'required' => wc_terms_and_conditions_checkbox_enabled(),
			'label'    => wp_strip_all_tags( wc_get_terms_and_conditions_checkbox_text() ),
			'text'     => $page ? wp_strip_all_tags( strip_shortcodes( $page->post_content ) ) : '',
		);
	}
	public static function begin( array $challenge ): ?array {
		if ( ! self::enabled() || empty( $challenge['phone_ciphertext'] ) ) {
			return null;}
		$phone = self::open( $challenge['phone_ciphertext'], 'phone' );
		if ( ! $phone || MobileIdentityStore::phone( $phone ) !== $phone || ! hash_equals( $challenge['phone_hash'], MobileIdentityStore::hash( $phone ) ) ) {
			return null;}
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at<%d LIMIT 100", time() ) );
		$token   = bin2hex( random_bytes( 32 ) );
		$expires = time() + 600;
		if ( $wpdb->insert(
			$table,
			array(
				'token_hash'       => hash( 'sha256', $token ),
				'phone_ciphertext' => $challenge['phone_ciphertext'],
				'state'            => 'verified',
				'expires_at'       => $expires,
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
	public static function complete( string $token, string $request_id, string $device, bool $terms_accepted ) {
		if ( ! self::enabled() || ! preg_match( '/\A[a-f0-9]{64}\z/D', $token ) || ! MobileRequestStore::validRequestId( $request_id ) ) {
			return new \WP_Error( 'invalid_registration', 'درخواست عضویت معتبر نیست.', array( 'status' => 400 ) );}
		if ( self::terms()['required'] && ! $terms_accepted ) {
			return new \WP_Error( 'terms_required', 'شرایط عضویت را تأیید کنید.', array( 'status' => 422 ) );}
		global $wpdb;
		$table        = self::table();
		$hash         = hash( 'sha256', $token );
		$request_hash = MobileIdentityStore::hash( $request_id . ':' . mb_substr( $device, 0, 80 ) );
		$row          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash=%s AND expires_at>%d", $hash, time() ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'registration_expired', 'شماره موبایل را دوباره تأیید کنید.', array( 'status' => 410 ) );}
		$phone = self::open( $row['phone_ciphertext'], 'phone' );
		if ( ! $phone || MobileIdentityStore::phone( $phone ) !== $phone ) {
			return new \WP_Error( 'invalid_registration', 'تأیید شماره معتبر نیست.', array( 'status' => 401 ) );}
		$lock = 'dgl-enroll-' . substr( MobileIdentityStore::hash( $phone ), 0, 40 );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== 1 ) {
			return new \WP_Error( 'registration_in_progress', 'درخواست در حال بررسی است؛ با همان شناسه ادامه دهید.', array( 'status' => 409 ) );}
		$new_user = 0;
		try {
			$wpdb->query( 'START TRANSACTION' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash=%s FOR UPDATE", $hash ), ARRAY_A );
			if ( ! $row || $row['expires_at'] <= time() ) {
				throw new \RuntimeException( 'registration_expired' );}
			if ( $row['state'] === 'registered' ) {
				$wpdb->query( 'COMMIT' );
				if ( ! hash_equals( $row['request_hash'], $request_hash ) ) {
					return new \WP_Error( 'request_id_conflict', 'این تأیید قبلاً با درخواست دیگری استفاده شده است.', array( 'status' => 409 ) );}
				$session = json_decode( self::open( $row['session_ciphertext'], 'session' ) ?? '', true );
				return is_array( $session ) ? $session + array(
					'user_id'  => (int) $row['user_id'],
					'replayed' => true,
				) : new \WP_Error( 'registration_expired', 'دوباره وارد حساب شوید.', array( 'status' => 410 ) );
			}
			$user_id = MobileIdentityStore::ownerOfPhone( $phone );
			if ( $user_id < 0 ) {
				throw new \RuntimeException( 'identity_conflict' );}
			if ( $user_id === 0 ) {
				$username = sanitize_user( ltrim( $phone, '+' ), true );
				if ( username_exists( $username ) ) {
					throw new \RuntimeException( 'identity_conflict' );}
				$userdata = array(
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
				$user_id  = wp_insert_user( $userdata );
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
			$session = MobileIdentityStore::issueSession( (int) $user_id, $device, 'otp' );
			$payload = $session + array(
				'registered' => true,
				'user_id'    => (int) $user_id,
				'created'    => $new_user > 0,
			);
			if ( $wpdb->update(
				$table,
				array(
					'state'              => 'registered',
					'request_hash'       => $request_hash,
					'user_id'            => $user_id,
					'session_ciphertext' => self::seal( wp_json_encode( $payload ), 'session' ),
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
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}
}
