<?php
/**
 * Reject normalized identity duplicates before website account creation.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and explicit native helper contract.

/**
 * Discovery can stop creation; it never authenticates or links an existing user.
 *
 * Installed core user.php invokes wp_pre_insert_user_data before the users INSERT
 * and provides raw meta_input. The observed Digits signup form also supplies an
 * untrusted phone hint that may veto creation, never grant identity or populate
 * metadata. Encoded non-core/direct-SQL creation paths remain unproven.
 */
final class MobileWebsiteIdentityGuard {
	/** Attach only creation guards; deliberately never change email_exists discovery. */
	public static function register(): void {
		add_filter( 'registration_errors', array( self::class, 'registrationErrors' ), 99, 3 );
		add_filter( 'woocommerce_registration_errors', array( self::class, 'registrationErrors' ), 99, 3 );
		add_filter( 'wp_pre_insert_user_data', array( self::class, 'preInsert' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Direct validation for controlled native enrollment before calling wp_insert_user.
	 *
	 * This is a current-state guard, not a replacement for the caller's enrollment
	 * lock or canonical provider validation. No account IDs are returned to the user.
	 *
	 * @param array $userdata Intended canonical user_email and optional Digits meta_input.
	 * @return \WP_Error|null Safe creation rejection, or no discovered collision.
	 */
	public static function validateNewUser( array $userdata ): ?\WP_Error {
		$email = $userdata['user_email'] ?? '';
		if ( ! is_string( $email ) ) {
			return self::error( 'invalid_registration_identity', 422 );
		}
		if ( '' !== trim( $email ) ) {
			if ( null === MobileIdentityResolver::emailKey( $email ) ) {
				return self::error( 'invalid_registration_identity', 422 );
			}
			$owners = MobileIdentityResolver::emailOwners( $email );
			if ( null === $owners ) {
				return self::error( 'identity_lookup_unavailable', 503 );
			}
			if ( $owners ) {
				return self::error( 'identity_email_exists', 409 );
			}
		}
		$meta = $userdata['meta_input'] ?? array();
		if ( ! is_array( $meta ) ) {
			return self::error( 'invalid_registration_identity', 422 );
		}
		$phones = array();
		foreach ( array( 'digits_phone', 'digits_phone_no' ) as $key ) {
			if ( ! array_key_exists( $key, $meta ) || '' === $meta[ $key ] ) {
				continue;
			}
			$phone = MobileIdentityResolver::storedPhone( $meta[ $key ] );
			if ( null === $phone ) {
				return self::error( 'identity_phone_conflict', 409 );
			}
			$phones[ $phone ] = true;
		}
		if ( ! $phones ) {
			return null;
		}
		if ( count( $phones ) !== 1 ) {
			return self::error( 'identity_phone_conflict', 409 );
		}
		if ( isset( $meta['digt_countrycode'] ) && '' !== $meta['digt_countrycode'] ) {
			if ( ! is_string( $meta['digt_countrycode'] ) ) {
				return self::error( 'identity_phone_conflict', 409 );
			}
			$country = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{2066}-\x{2069}\s]+/u', '', MobileIdentityStore::digits( $meta['digt_countrycode'] ) );
			if ( ! in_array( $country, array( '+98', '98', '0098' ), true ) ) {
				return self::error( 'identity_phone_conflict', 409 );
			}
		}
		$owner = MobileIdentityResolver::phoneOwner( array_key_first( $phones ) );
		if ( $owner < 0 ) {
			return self::error( 'identity_phone_conflict', 409 );
		}
		return $owner > 0 ? self::error( 'identity_phone_exists', 409 ) : null;
	}

	/**
	 * Let core and Woo return a normal registration error before their create call.
	 *
	 * @param \WP_Error $errors Existing provider validation errors.
	 * @param string    $username Sanitized proposed username; not an identity proof.
	 * @param string    $email Proposed email.
	 * @return \WP_Error Existing errors or normalized collision rejection.
	 */
	public static function registrationErrors( $errors, $username, $email ) {
		if ( is_wp_error( $errors ) && $errors->get_error_code() ) {
			return $errors;
		}
		return self::validateNewUser( array( 'user_email' => $email ) ) ?? $errors;
	}

	/**
	 * Abort creation before core's INSERT while preserving all existing-user updates.
	 *
	 * The wp_pre_insert_user_data hook cannot accept WP_Error as an ordinary result.
	 * wp_die supplies a structured 409/422/503 response; the empty fallback also
	 * prevents INSERT if a custom die handler unexpectedly returns.
	 *
	 * @param array    $data Final users-table values.
	 * @param bool     $update Whether this is an update, never guarded here.
	 * @param int|null $user_id Existing identity for an update.
	 * @param array    $userdata Raw canonical metadata passed to core.
	 * @return array Unchanged creation/update data or an empty abort fallback.
	 */
	public static function preInsert( $data, $update, $user_id, $userdata ) {
		if ( $update || ! is_array( $data ) || ! is_array( $userdata ) ) {
			return $data;
		}
		$failure = self::validateNewUser(
			array(
				'user_email' => $data['user_email'] ?? '',
				'meta_input' => $userdata['meta_input'] ?? array(),
			)
		);
		if ( null === $failure ) {
			$failure = self::signupHintFailure();
		}
		if ( null === $failure ) {
			return $data;
		}
		wp_die( $failure, 'بررسی حساب', array( 'response' => $failure->get_error_data()['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP_Error contains only fixed local messages and a bounded HTTP status, never submitted identity values.
		return array();
	}

	/**
	 * Veto only the exact serialized signup shape observed on the public website.
	 *
	 * This hint is NOT a provider proof. It never returns an owner, mutates user
	 * metadata, authorizes login, or applies to updates/login/recovery requests.
	 * A provider using direct SQL rather than core wp_insert_user is not covered.
	 *
	 * @return \WP_Error|null Safe rejection or no observed signup collision.
	 */
	private static function signupHintFailure(): ?\WP_Error {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Untrusted data only vetoes insertion: exact signature comparisons and strict phone/country normalization below deliberately reject altered input rather than sanitize it into a match. Provider owns nonce/OTP verification.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| 'digits_forms_ajax' !== ( $_POST['action'] ?? null )
			|| 'register' !== ( $_POST['type'] ?? null )
			|| '1' !== ( $_POST['digits'] ?? null ) ) {
			return null;
		}
		$phone   = $_POST['phone'] ?? null;
		$country = $_POST['digt_countrycode'] ?? null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! is_string( $phone ) || ! is_string( $country ) ) {
			return self::error( 'invalid_registration_identity', 422 );
		}
		$country = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{2066}-\x{2069}\s]+/u', '', MobileIdentityStore::digits( $country ) );
		if ( ! in_array( $country, array( '+98', '98', '0098' ), true ) ) {
			return self::error( 'invalid_registration_identity', 422 );
		}
		$normalized = MobileIdentityResolver::storedPhone( $phone );
		if ( null === $normalized ) {
			return self::error( 'invalid_registration_identity', 422 );
		}
		$owner = MobileIdentityResolver::phoneOwner( $normalized );
		if ( $owner < 0 ) {
			return self::error( 'identity_phone_conflict', 409 );
		}
		return $owner > 0 ? self::error( 'identity_phone_exists', 409 ) : null;
	}

	/**
	 * Keep identity discovery errors useful without returning another account's data.
	 *
	 * @param string $code Stable rejection code.
	 * @param int    $status Appropriate HTTP status.
	 * @return \WP_Error Safe Persian registration error.
	 */
	private static function error( string $code, int $status ): \WP_Error {
		$message = match ( $code ) {
			'identity_email_exists' => 'با این ایمیل حسابی وجود دارد. وارد حساب قبلی شوید.',
			'identity_phone_exists' => 'با این شماره حسابی وجود دارد. از ورود با شماره همراه استفاده کنید.',
			'identity_phone_conflict' => 'اطلاعات شماره همراه نیاز به بررسی دارد؛ حساب تازه ساخته نشد.',
			'identity_lookup_unavailable' => 'بررسی حساب موجود اکنون ممکن نیست. کمی بعد دوباره تلاش کنید.',
			default => 'اطلاعات ایمیل یا شماره همراه معتبر نیست.',
		};
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
