<?php
/**
 * Preserve verified Google intent through explicit phone verification.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.WP.GlobalVariablesOverride -- The identity transaction installs a private cache and restores the original in finally.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 native adapter contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Current security receipts, schemas and transaction locks bypass shared caches.

/** Preserve verified Google intent through explicit phone verification. */
final class MobileGoogleContinuation {
	/** Return the adapter-owned continuation table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_google_continuations'; }
	/**
	 * Install only required security and delivery metadata.
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
claims_ciphertext longtext NOT NULL,
state varchar(20) NOT NULL,
phone_hash char(64) DEFAULT NULL,
user_id bigint unsigned DEFAULT NULL,
result_ciphertext longtext DEFAULT NULL,
expires_at bigint unsigned NOT NULL,
PRIMARY KEY  (token_hash),
KEY expires_at (expires_at)
) ENGINE=InnoDB {$charset};"
		);
		foreach ( array( MobileIdentityStore::table( 'otp' ), MobileRegistrationStore::table() ) as $target ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $target, 'continuation_hash' ) ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD continuation_hash char(64) DEFAULT NULL', $target ) );
			}
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $target, 'continuation_hash' ) ) ) {
				throw new \RuntimeException( 'Google continuation schema unavailable.' );
			}
		}
		foreach ( array(
			'effects_ciphertext' => 'longtext DEFAULT NULL',
			'effects_count'      => 'int unsigned NOT NULL DEFAULT 0',
			'effects_cursor'     => 'int unsigned NOT NULL DEFAULT 0',
		) as $column => $definition ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', MobileRegistrationStore::table(), $column ) ) ) {
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD %i ', MobileRegistrationStore::table(), $column ) . $definition ); }
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', MobileRegistrationStore::table(), $column ) ) ) {
				throw new \RuntimeException( 'Enrollment outbox unavailable.' ); }
		}
		update_option( 'digitalogic_mobile_google_continuation_schema', '1', false );
	}
	/**
	 * Start an isolated deferred-effect scope.
	 *
	 * @param array      $claims Cryptographically verified provider claims.
	 * @param string     $raw_token Signed provider proof, never logged.
	 * @param array|null $fresh Nonce-bound original freshness timestamps.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 * @throws \UnexpectedValueException When the operation cannot preserve its verified boundary.
	 */
	public static function begin( array $claims, string $raw_token, ?array $fresh ): array {
		global $wpdb;
		$proof_at = $fresh['verified_at'] ?? $claims['iat'];
		$expires  = min( $claims['exp'], $proof_at + 600, time() + 600 );
		if ( $expires <= time() ) {
			throw new \UnexpectedValueException( 'fresh_google_required' );
		}
		$owners = MobileIdentityResolver::emailOwners( $claims['email'] );
		if ( null === $owners || count( $owners ) > 1 ) {
			throw new \UnexpectedValueException( 'identity_discovery_conflict' );
		}
		$token = MobileIdentityStore::hash( 'google-continuation:' . $raw_token );
		$hash  = hash( 'sha256', $token );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', self::table(), $hash ), ARRAY_A );
		if ( ! $row ) {
			$proof = array(
				'sub'           => $claims['sub'],
				'email'         => $claims['email'],
				'email_key'     => MobileIdentityResolver::emailKey( $claims['email'] ),
				'proof_at'      => $proof_at,
				'candidate_ids' => $owners,
				'expires_at'    => $expires,
			);
			if ( 1 !== $wpdb->insert(
				self::table(),
				array(
					'token_hash'        => $hash,
					'claims_ciphertext' => MobileRegistrationStore::seal( wp_json_encode( $proof ), 'google-continuation' ),
					'state'             => 'pending',
					'expires_at'        => $expires,
				)
			) ) {
				throw new \RuntimeException( 'Continuation unavailable.' );
			}
		} elseif ( (int) $row['expires_at'] <= time() ) {
			throw new \UnexpectedValueException( 'fresh_google_required' ); }
		return array(
			'registered'                     => false,
			'identity_verification_required' => true,
			'registration_method'            => 'phone',
			'continuation_token'             => $token,
			'continuation_expires_at'        => gmdate( 'c', $expires ),
			'existing_account_detected'      => ! empty( $owners ),
		);
	}
	/**
	 * Read current proof metadata without renewing its deadline.
	 *
	 * @param mixed $token Opaque proof, never logged.
	 */
	public static function read( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $token ) ) {
			return new \WP_Error( 'invalid_continuation', 'تأیید گوگل معتبر نیست.', array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s', self::table(), hash( 'sha256', $token ) ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return new \WP_Error( 'continuation_unavailable', 'ادامه ورود موقتاً در دسترس نیست.', array( 'status' => 503 ) ); }
		if ( ! $row || (int) $row['expires_at'] <= time() ) {
			return new \WP_Error( 'continuation_expired', 'ابتدا گوگل را دوباره تأیید کنید.', array( 'status' => 410 ) );
		}
		$proof = json_decode( MobileRegistrationStore::open( $row['claims_ciphertext'], 'google-continuation' ) ?? '', true );
		if ( ! is_array( $proof ) ) {
			return new \WP_Error( 'invalid_continuation', 'تأیید گوگل معتبر نیست.', array( 'status' => 409 ) );
		}
		return $row + array( 'proof' => $proof );
	}
	/** Called only after code verification; its exact challenge was bound to this continuation before SMS. */
	/**
	 * Apply the scoped native adapter operation.
	 *
	 * @param string $token Opaque proof, never logged.
	 * @param array  $challenge Exact purpose-bound phone proof.
	 * @param string $device Customer-visible device name.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 * @throws \UnexpectedValueException When the operation cannot preserve its verified boundary.
	 */
	public static function finishPhone( string $token, array $challenge, string $device ) {
		global $wpdb;
		$hash = hash( 'sha256', $token );
		$lock = 'dgl-google-cont-' . substr( $hash, 0, 40 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) {
			return new \WP_Error( 'identity_busy', 'کمی صبر کنید.', array( 'status' => 409 ) );
		}
		$transaction    = false;
		$held           = array();
		$original_cache = $GLOBALS['wp_object_cache'];
		try {
			$phone = MobileRegistrationStore::open( (string) ( $challenge['phone_ciphertext'] ?? '' ), 'phone' );
			if ( ! $phone || ! hash_equals( (string) ( $challenge['phone_hash'] ?? '' ), MobileIdentityStore::hash( $phone ) ) ) {
				throw new \UnexpectedValueException( 'identity_phone_changed' ); }
			$owner   = MobileIdentityStore::ownerOfPhone( $phone );
			$locks   = $owner > 0 ? array( 'dgl-link-user-' . $owner ) : array();
			$locks[] = 'dgl-enroll-' . substr( MobileIdentityStore::hash( $phone ), 0, 40 );
			foreach ( $locks as $principal_lock ) {
				if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $principal_lock ) ) ) {
					throw new \UnexpectedValueException( 'identity_busy' ); }
				$held[] = $principal_lock;
			}
			$GLOBALS['wp_object_cache'] = new MobileMergeObjectCache( null, (array) ( $original_cache->global_groups ?? array() ), (array) ( $original_cache->non_persistent_groups ?? array() ) );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'Continuation transaction unavailable.' );
			}
			$transaction = true;
			$wpdb->get_results( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key IN ('digits_phone','digits_phone_no','digt_countrycode','auth_google_id') FOR UPDATE" );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Identity rows unavailable.' ); }
			$wpdb->get_row( $wpdb->prepare( 'SELECT token_hash FROM %i WHERE token_hash=%s FOR UPDATE', self::table(), $hash ) );
			$row = self::read( $token );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			if ( ! hash_equals( (string) ( $challenge['continuation_hash'] ?? '' ), $hash ) ) {
				throw new \UnexpectedValueException( 'identity_continuation_mismatch' );
			}
			$phone = MobileRegistrationStore::open( (string) $challenge['phone_ciphertext'], 'phone' );
			if ( ! $phone || ! hash_equals( $challenge['phone_hash'], MobileIdentityStore::hash( $phone ) ) ) {
				throw new \UnexpectedValueException( 'identity_phone_changed' );
			}
			if ( in_array( $row['state'], array( 'linked', 'phone_verified' ), true ) ) {
				if ( ! hash_equals( (string) $row['phone_hash'], $challenge['phone_hash'] ) ) {
					throw new \UnexpectedValueException( 'identity_continuation_mismatch' );
				}
				$result = json_decode( MobileRegistrationStore::open( (string) $row['result_ciphertext'], 'google-continuation-result' ) ?? '', true );
				if ( ! is_array( $result ) || ( 'linked' === $row['state'] && ! MobileIdentityStore::authenticate( 'Bearer ' . ( $result['access_token'] ?? '' ) ) ) ) {
					throw new \UnexpectedValueException( 'identity_session_expired' );
				}
				return $result;
			}
			$owner  = MobileIdentityStore::ownerOfPhone( $phone );
			$emails = MobileIdentityResolver::emailOwners( $row['proof']['email'] );
			if ( $owner < 0 || null === $emails || count( $emails ) > 1 || ( $emails && ( $owner < 1 || $emails[0] !== $owner ) ) ) {
				throw new \UnexpectedValueException( 'identity_merge_required' );
			}
			if ( 0 === $owner ) {
				if ( false === $wpdb->update(
					self::table(),
					array(
						'state'      => 'phone_verified',
						'phone_hash' => $challenge['phone_hash'],
					),
					array( 'token_hash' => $hash )
				) ) {
					throw new \RuntimeException( 'Phone continuation unavailable.' );
				}
				$result = MobileRegistrationStore::begin( $challenge );
				if ( ! $result ) {
					throw new \RuntimeException( 'Registration unavailable.' );
				}
				if ( 1 !== $wpdb->update( self::table(), array( 'result_ciphertext' => MobileRegistrationStore::seal( wp_json_encode( $result ), 'google-continuation-result' ) ), array( 'token_hash' => $hash ) ) ) {
					throw new \RuntimeException( 'Registration continuation unavailable.' );
				}
			} else {
				self::bindLocked( $row, $owner, $phone );
				$result = MobileIdentityStore::issueSession( $owner, $device, 'otp' ) + array(
					'registered' => true,
					'user_id'    => $owner,
				);
				if ( 1 !== $wpdb->update(
					self::table(),
					array(
						'state'             => 'linked',
						'phone_hash'        => $challenge['phone_hash'],
						'user_id'           => $owner,
						'result_ciphertext' => MobileRegistrationStore::seal( wp_json_encode( $result ), 'google-continuation-result' ),
					),
					array( 'token_hash' => $hash )
				) ) {
					throw new \RuntimeException( 'Binding receipt unavailable.' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Binding commit uncertain.' );
			}
			$transaction = false;
			return $result;
		} catch ( \UnexpectedValueException $error ) {
			return new \WP_Error( $error->getMessage(), 'این هویت‌ها به حساب موجود مربوط‌اند؛ ایجاد حساب تازه متوقف شد.', array( 'status' => 409 ) ); } catch ( \Throwable $error ) {
			return new \WP_Error( 'identity_link_uncertain', 'اتصال هویت کامل نشد؛ دوباره وضعیت را بررسی کنید.', array( 'status' => 503 ) ); } finally {
				if ( $transaction ) {
					$wpdb->query( 'ROLLBACK' );
				}
				$GLOBALS['wp_object_cache'] = $original_cache;
				if ( ( $owner ?? 0 ) > 0 ) {
					clean_user_cache( $owner ); }
				foreach ( array_reverse( $held ) as $principal_lock ) {
					$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $principal_lock ) ); }
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	/** Caller holds its transaction and the canonical identity lock. No email-only account claim. */
	/**
	 * Apply the scoped native adapter operation.
	 *
	 * @param array  $row Durable owner-bound metadata.
	 * @param int    $user_id Verified canonical WordPress owner.
	 * @param string $phone Normalized verified phone.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 * @throws \UnexpectedValueException When the operation cannot preserve its verified boundary.
	 */
	public static function bindLocked( array $row, int $user_id, string $phone ): void {
		global $wpdb;
		if ( $row['expires_at'] <= time() || MobileIdentityStore::ownerOfPhone( $phone ) !== $user_id || get_user_meta( $user_id, '_digitalogic_merged_into', true ) ) {
			throw new \UnexpectedValueException( 'identity_phone_changed' );
		}
		$subject = $row['proof']['sub'];
		$wpdb->get_results( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key='auth_google_id' FOR UPDATE" );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Identity lock unavailable.' );
		}
		$owner    = MobileGoogleIdentity::owner( $subject );
		$existing = (string) get_user_meta( $user_id, 'auth_google_id', true );
		if ( $owner < 0 || ( $owner > 0 && $owner !== $user_id ) || ( '' !== $existing && ! hash_equals( $existing, $subject ) ) ) {
			throw new \UnexpectedValueException( 'identity_google_conflict' );
		}
		if ( 0 === $owner && ! add_user_meta( $user_id, 'auth_google_id', $subject, true ) ) {
			throw new \RuntimeException( 'Google binding unavailable.' );
		}
		update_user_meta( $user_id, '_digitalogic_google_binding_not_before', time() );
		if ( MobileGoogleIdentity::owner( $subject ) !== $user_id ) {
			throw new \RuntimeException( 'Google binding readback failed.' );
		}
	}
}
