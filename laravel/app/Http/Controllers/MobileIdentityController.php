<?php
/**
 * Native IdentityController adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Digitalogic\Laravel\Services\MobileIdentityStore as Store;
use Digitalogic\Laravel\Services\MobileGoogleIdentity as Google;
use Digitalogic\Laravel\Services\MobileRegistrationStore as Registration;
use Digitalogic\Laravel\Services\MobileAddresses as Addresses;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Native adapter over canonical WordPress and WooCommerce authority. */
final class MobileIdentityController {
	/**
	 * Request a login-purpose OTP through the existing configured SMS transport.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function requestOtp( Request $request ): Response {
		return $this->requestChallenge( $request );
	}

	/**
	 * Reserve an idempotent purpose-bound OTP before any one-shot SMS delivery.
	 *
	 * @param Request    $request Bounded native HTTP request.
	 * @param array|null $actor Actor.
	 * @return Response Native adapter result.
	 */
	private function requestChallenge( Request $request, ?array $actor = null ): Response {
		global $wpdb;
		if ( ! self::otpEnabled() ) {
			return $this->error( 'native_auth_unavailable', 'ورود موقتاً در دسترس نیست؛ کمی بعد دوباره تلاش کنید.', 503 );
		}
		$phone      = Store::phone( (string) $request->json( 'phone', '' ) );
		$request_id = (string) $request->json( 'request_id', '' );
		if ( null === $phone || ! preg_match( '/\A[A-Za-z0-9-]{16,64}\z/D', $request_id ) ) {
			return $this->error( 'invalid_request', 'شماره موبایل معتبر وارد کنید.', 400 );
		}
		$table        = Store::table( 'otp' );
		$phone_hash   = Store::hash( $phone );
		$request_hash = Store::hash( ( $actor ? 'phone-link:' . $actor['user_id'] . ':' . $actor['id'] . ':' : '' ) . $phone . ':' . $request_id );
		$existing     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_hash=%s', $table, $request_hash ), ARRAY_A );
		if ( $existing ) {
			return (int) $existing['expires_at'] > time() && 'used' !== $existing['state'] ? $this->challengeResponse( $existing ) : $this->error( 'challenge_expired', 'درخواست تازه‌ای برای کد ورود ثبت کنید.', 410 );
		}
		$ip = (string) sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		if ( ! Store::admit( 'otp-global', 60, 60 ) || ! Store::admit( 'otp-ip:' . $ip, 8, 60 ) || ! Store::admit( 'otp-phone:' . $phone_hash, 1, 60 ) || ! Store::admit( 'otp-phone-hour:' . $phone_hash, 5, 3600 ) ) {
			return $this->json(
				array(
					'code'        => 'rate_limited',
					'message'     => 'برای دریافت دوباره کد کمی صبر کنید.',
					'retry_after' => 60,
				),
				429
			);
		}
		$id   = bin2hex( random_bytes( 16 ) );
		$code = (string) random_int( 100000, 999999 );
		$row  = array(
			'id'               => $id,
			'request_hash'     => $request_hash,
			'phone_hash'       => $phone_hash,
			'user_id'          => Store::ownerOfPhone( $phone ),
			'purpose'          => $actor ? 'phone_link' : 'login',
			'actor_user_id'    => $actor ? (int) $actor['user_id'] : 0,
			'actor_session_id' => $actor ? $actor['id'] : '',
			'code_hash'        => Store::hash( $id . ':' . $code . ':' . $phone_hash ),
			'attempts'         => 0,
			'state'            => 'pending',
			'created_at'       => time(),
			'expires_at'       => time() + 300,
		);
		if ( $actor || Registration::enabled() ) {
			$row['phone_ciphertext'] = Registration::seal( $phone, 'phone' );}
		if ( $wpdb->insert( $table, $row ) !== 1 ) {
			return $this->error( 'request_conflict', 'درخواست در حال بررسی است؛ چند لحظه صبر کنید.', 409 );
		}
		// No retry: a transport timeout may still have delivered the message.
		$result = \Digitalogic_Smsir_Integration::send_native_login_otp( $phone, $code );
		unset( $code );
		$row['state'] = is_wp_error( $result ) ? 'pending' : 'accepted';
		$wpdb->update( $table, array( 'state' => $row['state'] ), array( 'id' => $id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<%d LIMIT 100', $table, time() - DAY_IN_SECONDS ) );
		return $this->challengeResponse( $row );
	}

	/**
	 * Consume a login proof and resolve the existing Digits identity or registration continuation.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function verifyOtp( Request $request ): Response {
		global $wpdb;
		if ( ! self::otpEnabled() ) {
			return $this->error( 'native_auth_unavailable', 'ورود موقتاً در دسترس نیست؛ کمی بعد دوباره تلاش کنید.', 503 );
		}
		$id   = (string) $request->json( 'challenge_id', '' );
		$code = Store::digits( trim( (string) $request->json( 'code', '' ) ) );
		if ( ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) || ! preg_match( '/\A[0-9]{6}\z/D', $code ) ) {
			return $this->error( 'invalid_code', 'کد شش‌رقمی معتبر وارد کنید.', 400 );
		}
		$table   = Store::table( 'otp' );
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id=%s AND purpose='login'", $table, $id ), ARRAY_A );
		$attempt = $wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts=attempts+1 WHERE id=%s AND purpose='login' AND attempts<5 AND expires_at>%d AND state IN ('pending','accepted')", $table, $id, time() ) );
		if ( ! $row || 1 !== $attempt || ! hash_equals( $row['code_hash'], Store::hash( $id . ':' . $code . ':' . $row['phone_hash'] ) ) ) {
			return $this->error( 'invalid_or_expired_code', 'کد نادرست یا منقضی شده است.', 401 );
		}
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET state='used' WHERE id=%s AND state IN ('pending','accepted') AND expires_at>%d", $table, $id, time() ) );
		if ( 1 !== $claimed ) {
			return $this->error( 'code_already_used', 'این کد قبلاً استفاده شده است.', 409 );
		}
		$user_id = (int) $row['user_id'];
		if ( 0 === $user_id ) {
			$registration = Registration::begin( $row );
			return $registration ? $this->json( $registration ) : $this->error( 'registration_proof_unavailable', 'شماره موبایل را با کد تازه‌ای تأیید کنید.', 409 ); }
		$verified_phone = $user_id > 0 ? Store::phone( (string) get_user_meta( $user_id, 'digits_phone', true ) ) : null;
		if ( null === $verified_phone || ! hash_equals( $row['phone_hash'], Store::hash( $verified_phone ) ) || Store::ownerOfPhone( $verified_phone ) !== $user_id ) {
			return $this->error( 'account_identity_changed', 'برای بررسی حساب از ورود وب‌سایت استفاده کنید.', 409 );
		}
		$session = Store::issueSession( $user_id, (string) $request->json( 'device_name', 'Android' ), 'otp' );
		return $this->json(
			array_merge(
				array(
					'registered' => true,
					'profile'    => $this->profileData( $user_id ),
				),
				$session
			)
		);
	}

	/**
	 * Complete explicit native registration under the existing Digits identity policy.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function register( Request $request ): Response {
		if ( ! self::otpEnabled() ) {
			return $this->error( 'native_auth_unavailable', 'ورود موقتاً در دسترس نیست.', 503 );}
		$input = $request->json()->all();
		if ( array_diff( array_keys( $input ), array( 'registration_token', 'request_id', 'terms_accepted', 'device_name' ) ) || ! is_string( $input['registration_token'] ?? null ) || ! is_string( $input['request_id'] ?? null ) || ! is_string( $input['device_name'] ?? 'Android' ) || ( isset( $input['terms_accepted'] ) && ! is_bool( $input['terms_accepted'] ) ) ) {
			return $this->error( 'invalid_registration', 'درخواست عضویت معتبر نیست.', 400 );}
		$result = Registration::complete( $input['registration_token'], $input['request_id'], $input['device_name'] ?? 'Android', ( $input['terms_accepted'] ?? false ) === true );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 409 ) );}
		$user_id = (int) $result['user_id'];
		unset( $result['user_id'] );
		$result['profile'] = $this->profileData( $user_id );
		return $this->json( $result );
	}

	/**
	 * Reauthenticate the current identity without switching accounts or issuing a browser cookie.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function reauthGoogle( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		if ( ! self::googleEnabled() || ! self::phoneEnabled() ) {
			return $this->error( 'google_unavailable', 'تأیید دوباره گوگل موقتاً در دسترس نیست.', 503 );
		}
		$token = $request->json( 'id_token' );
		if ( ! is_string( $token ) || strlen( $token ) > 6000 || ! Store::admit( 'reauth:' . $session['user_id'], 10, 60 ) ) {
			return $this->error( 'invalid_google_token', 'ورود گوگل تأیید نشد.', 403 );
		}
		try {
			$claims = Google::verify( $token, Google::keys(), Google::audience(), Google::presenter() );
		} catch ( \Throwable $error ) {
			return $this->error( 'invalid_google_token', 'ورود گوگل تأیید نشد؛ دوباره تلاش کنید.', 403 );
		}
		if ( Google::owner( $claims['sub'] ) !== (int) $session['user_id'] ) {
			return $this->error( 'reauth_identity_mismatch', 'همان حساب گوگل متصل به این حساب را انتخاب کنید.', 409 );
		}
		if ( $claims['iat'] < time() - 600 ) {
			return $this->error( 'reauth_required', 'ورود تازه‌ای با همین حساب گوگل انجام دهید.', 403 );
		}
		// Reuse is scoped to an already authenticated, exact subject owner. The signed
		// issuance time, not this request time, bounds the proof and cannot be renewed by replay.
		$saved = $wpdb->update(
			Store::table( 'sessions' ),
			array(
				'auth_method' => 'google',
				'verified_at' => $claims['iat'],
			),
			array(
				'id'      => $session['id'],
				'user_id' => (int) $session['user_id'],
			)
		);
		if ( false === $saved ) {
			return $this->error( 'reauth_not_saved', 'تأیید ذخیره نشد؛ دوباره تلاش کنید.', 503 );
		}
		return $this->json(
			array(
				'reauthenticated' => true,
				'verified_at'     => gmdate( 'c', $claims['iat'] ),
				'profile'         => $this->profileData( (int) $session['user_id'] ),
			)
		);
	}

	/**
	 * Request proof of the phone for this session's already verified WordPress identity.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function requestPhone( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		if ( ! self::phoneEnabled() ) {
			return $this->error( 'phone_verification_unavailable', 'تأیید موبایل موقتاً در دسترس نیست.', 503 );
		}
		if ( ! Store::freshProof( $session ) ) {
			return $this->error( 'reauth_required', 'برای اتصال شماره، هویت همین حساب را دوباره تأیید کنید.', 403 );
		}
		return $this->requestChallenge( $request, $session );
	}

	/**
	 * Consume an actor- and purpose-bound OTP; duplicates remain separate for review.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public function verifyPhone( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		if ( ! self::phoneEnabled() ) {
			return $this->error( 'phone_verification_unavailable', 'تأیید موبایل موقتاً در دسترس نیست.', 503 );
		}
		if ( ! Store::freshProof( $session ) ) {
			return $this->error( 'reauth_required', 'برای اتصال شماره، هویت همین حساب را دوباره تأیید کنید.', 403 );
		}
		$id   = $request->json( 'challenge_id' );
		$code = $request->json( 'code' );
		$code = is_string( $code ) ? Store::digits( trim( $code ) ) : '';
		if ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) || ! preg_match( '/\A[0-9]{6}\z/D', $code ) ) {
			return $this->error( 'invalid_code', 'کد شش‌رقمی معتبر وارد کنید.', 400 );
		}
		$table = Store::table( 'otp' );
		$actor = (int) $session['user_id'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id=%s AND purpose='phone_link' AND actor_user_id=%d AND actor_session_id=%s AND expires_at>%d", $table, $id, $actor, $session['id'], time() ), ARRAY_A );
		if ( ! $row ) {
			return $this->error( 'invalid_or_expired_code', 'کد نادرست یا منقضی شده است.', 403 );
		}
		$matches = hash_equals( $row['code_hash'], Store::hash( $id . ':' . $code . ':' . $row['phone_hash'] ) );
		if ( 'used' === $row['state'] && $matches && in_array( $row['result'], array( 'linked', 'review_required' ), true ) ) {
			return $this->phoneResult( $row['result'], $actor );
		}
		$attempt = $wpdb->query( $wpdb->prepare( "UPDATE %i SET attempts=attempts+1 WHERE id=%s AND attempts<5 AND state IN ('pending','accepted') AND expires_at>%d", $table, $id, time() ) );
		if ( 1 !== $attempt || ! $matches ) {
			return $this->error( 'invalid_or_expired_code', 'کد نادرست یا منقضی شده است.', 403 );
		}
		$phone = Registration::open( (string) $row['phone_ciphertext'], 'phone' );
		if ( ! $phone || Store::phone( $phone ) !== $phone || ! hash_equals( $row['phone_hash'], Store::hash( $phone ) ) ) {
			return $this->error( 'phone_proof_unavailable', 'درخواست تازه‌ای برای تأیید شماره ثبت کنید.', 409 );
		}
		// Registration shares the phone lock. The account lock prevents two simultaneous
		// verified phones from both observing an account without a phone.
		$locks = array( 'dgl-link-user-' . $actor, 'dgl-enroll-' . substr( Store::hash( $phone ), 0, 40 ) );
		$held  = array();
		try {
			foreach ( $locks as $lock ) {
				if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
					return $this->error( 'identity_busy', 'تأیید دیگری در حال انجام است؛ کمی بعد دوباره تلاش کنید.', 409 );
				}
				$held[] = $lock;
			}
			$wpdb->query( 'START TRANSACTION' );
			$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s FOR UPDATE', $table, $id ), ARRAY_A );
			if ( 'used' === $current['state'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->phoneResult( $current['result'], $actor );
			}
			clean_user_cache( $actor );
			$existing_phone = (string) get_user_meta( $actor, 'digits_phone', true );
			$owner          = Store::ownerOfPhone( $phone );
			// Never overwrite an existing different phone or transfer another account's identity.
			$result = ( $owner < 0 || ( $owner > 0 && $owner !== $actor ) || ( '' !== $existing_phone && Store::phone( $existing_phone ) !== $phone ) ) ? 'review_required' : 'linked';
			if ( 'linked' === $result ) {
				foreach ( array(
					'digits_phone'     => $phone,
					'digits_phone_no'  => substr( $phone, 3 ),
					'digt_countrycode' => '+98',
				) as $key => $value ) {
					update_user_meta( $actor, $key, $value );
				}
				clean_user_cache( $actor );
				if ( Store::ownerOfPhone( $phone ) !== $actor || get_user_meta( $actor, 'digits_phone', true ) !== $phone ) {
					throw new \RuntimeException( 'Phone ownership could not be saved.' );
				}
			}
			if ( 1 !== $wpdb->update(
				$table,
				array(
					'state'  => 'used',
					'result' => $result,
				),
				array( 'id' => $id )
			) ) {
				throw new \RuntimeException( 'Phone proof could not be consumed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'Phone verification commit failed.' );
			}
			clean_user_cache( $actor );
			return $this->phoneResult( $result, $actor );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			clean_user_cache( $actor );
			return $this->error( 'phone_verification_not_saved', 'نتیجه تأیید ذخیره نشد؛ با همان کد دوباره تلاش کنید.', 503 );
		} finally {
			foreach ( array_reverse( $held ) as $lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/**
	 * Return the durable phone-link outcome while keeping automatic merging unavailable.
	 *
	 * @param string $status HTTP or durable operation status.
	 * @param int    $actor Actor.
	 * @return Response Native adapter result.
	 */
	private function phoneResult( string $status, int $actor ): Response {
		return $this->json(
			array(
				'status'                  => $status,
				'profile'                 => $this->profileData( $actor ),
				'message'                 => 'linked' === $status ? 'شماره موبایل تأیید و به حساب متصل شد.' : 'شماره تأیید شد؛ اتصال یا ادغام این حساب‌ها نیازمند بررسی است. اطلاعات حساب‌ها حفظ شده است.',
				'account_merge_available' => false,
			)
		);
	}

	/**
	 * Read or update only the authenticated owner’s canonical WordPress/Woo profile.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function profile( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$user_id = (int) $session['user_id'];
		if ( $request->isMethod( 'PUT' ) ) {
			$input = $request->json()->all();
			if ( array_diff( array_keys( $input ), array( 'first_name', 'last_name', 'display_name', 'billing', 'shipping', 'address_locations' ) ) ) {
				return $this->error( 'unsupported_profile_field', 'تغییر این بخش حساب از وب‌سایت انجام می‌شود.', 400 );
			}
			$user = array( 'ID' => $user_id );
			foreach ( array( 'first_name', 'last_name', 'display_name' ) as $key ) {
				if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) {
					$user[ $key ] = mb_substr( sanitize_text_field( $input[ $key ] ), 0, 80 );
				}
			}
			$customer = Addresses::prepare( $user_id, $input );
			if ( is_wp_error( $customer ) ) {
				return $this->json(
					array(
						'code'    => $customer->get_error_code(),
						'message' => $customer->get_error_message(),
						'issues'  => $customer->get_error_data()['issues'] ?? array(),
					),
					422
				);
			}
			$result = wp_update_user( $user );
			if ( is_wp_error( $result ) ) {
				return $this->error( 'profile_not_saved', 'اطلاعات ذخیره نشد؛ دوباره تلاش کنید.', 409 );
			}
			try {
				$customer->save();
			} catch ( \Throwable $error ) {
				return $this->error( 'profile_not_saved', 'اطلاعات نشانی ذخیره نشد؛ دوباره تلاش کنید.', 503 );
			}
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				if ( isset( $input[ $group ] ) ) {
					do_action( 'woocommerce_customer_save_address', $user_id, $group, $input[ $group ] );
				}
			}
		}
		return $this->json( array( 'profile' => $this->profileData( $user_id ) ) );
	}

	/**
	 * Verify Google claims and resolve only an explicit existing subject binding.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function google( Request $request ): Response {
		if ( ! self::googleEnabled() ) {
			return $this->error( 'google_unavailable', 'ورود گوگل در دسترس نیست.', 503 ); }
		$token = $request->json( 'id_token', '' );
		if ( ! is_string( $token ) || strlen( $token ) > 6000 || substr_count( $token, '.' ) !== 2 ) {
			return $this->error( 'invalid_google_token', 'ورود گوگل تأیید نشد.', 401 ); }
		if ( ! Store::admit( 'google-global', 120, 60 ) || ! Store::admit( 'google-ip:' . sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) ), 20, 60 ) ) {
			return $this->error( 'rate_limited', 'کمی صبر کنید و دوباره تلاش کنید.', 429 ); }
		try {
			$keys = Google::keys();
		} catch ( \Throwable $error ) {
			return $this->error( 'google_temporarily_unavailable', 'ارتباط با گوگل موقتاً برقرار نیست.', 503 ); }
		try {
			$claims = Google::verify( $token, $keys, Google::audience(), Google::presenter() );
		} catch ( \Throwable $error ) {
			return $this->error( 'invalid_google_token', 'ورود گوگل تأیید نشد.', 401 ); }
		$user_id = Google::owner( $claims['sub'] );
		if ( 0 === $user_id ) {
			return $this->json(
				array(
					'registered'            => false,
					'registration_required' => true,
					'registration_method'   => 'phone',
				)
			); }
		// A Google token can create one native session; values never enter logs.
		if ( ! Store::claimGoogleToken( $token, $claims['exp'] ) ) {
			return $this->error( 'identity_token_used', 'ورود گوگل را دوباره آغاز کنید.', 409 ); }
		return $this->json(
			array_merge(
				array(
					'registered' => true,
					'profile'    => $this->profileData( $user_id ),
				),
				Store::issueSession( $user_id, (string) $request->json( 'device_name', 'Android' ), 'google', (int) $claims['iat'] )
			)
		);
	}

	/**
	 * List only the authenticated owner’s canonical Woo orders.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function orders( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$page   = max( 1, min( 500, (int) $request->query( 'page', 1 ) ) );
		$result = wc_get_orders(
			array(
				'customer_id' => (int) $session['user_id'],
				'limit'       => 20,
				'page'        => $page,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$items  = array();
		foreach ( $result->orders as $order ) {
			$lines = array();
			foreach ( $order->get_items() as $item ) {
				$lines[] = array(
					'id'           => $item->get_id(),
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'name'         => $item->get_name(),
					'quantity'     => $item->get_quantity(),
					'total'        => $item->get_total(),
				);
			}
			$items[] = array(
				'id'                   => $order->get_id(),
				'number'               => $order->get_order_number(),
				'status'               => $order->get_status(),
				'date_created'         => $order->get_date_created()?->date( 'c' ),
				'total'                => $order->get_total(),
				'currency'             => $order->get_currency(),
				'line_items'           => $lines,
				'payment_method_title' => $order->get_payment_method_title(),
			);
		}
		$more = $page < $result->max_num_pages;
		return $this->json(
			array(
				'items'      => $items,
				'pagination' => array(
					'page'      => $page,
					'has_more'  => $more,
					'next_page' => $more ? $page + 1 : null,
				),
			)
		);
	}

	/**
	 * List only the current owner’s active native sessions.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function sessions( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$table = Store::table( 'sessions' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id,device_name,created_at,last_seen_at,expires_at FROM %i WHERE user_id=%d AND expires_at>%d ORDER BY last_seen_at DESC LIMIT 100', $table, $session['user_id'], time() ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['current'] = $row['id'] === $session['id'];
			foreach ( array( 'created_at', 'last_seen_at', 'expires_at' ) as $key ) {
				$row[ $key ] = gmdate( 'c', (int) $row[ $key ] );
			}
		}
		return $this->json( array( 'items' => $rows ) );
	}

	/**
	 * Revoke a session only within the authenticated owner’s account.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function logout( Request $request ): Response {
		global $wpdb;
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();
		}
		$id = (string) $request->json( 'session_id', $session['id'] );
		$wpdb->delete(
			Store::table( 'sessions' ),
			array(
				'id'      => $id,
				'user_id' => (int) $session['user_id'],
			),
			array( '%s', '%d' )
		);
		return $this->json( array( 'revoked' => true ) );
	}

	/**
	 * Read or update the canonical Woodmart wishlist with revision checks.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function favorites( Request $request ): Response {
		$session = $this->session( $request );
		if ( ! $session ) {
			return $this->unauthorized();}
		if ( ! \Digitalogic\Laravel\Services\MobileWishlist::enabled() ) {
			return $this->error( 'favorites_unavailable', 'علاقه‌مندی‌ها موقتاً در دسترس نیست.', 503 );}
		$items = null;
		$base  = null;
		if ( $request->isMethod( 'PUT' ) ) {
			$input = $request->json()->all();
			$items = \Digitalogic\Laravel\Services\MobileWishlist::normalize( $input['items'] ?? null );
			$base  = $input['base_revision'] ?? null;
			if ( null === $items || ! is_string( $base ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $base ) || array_diff( array_keys( $input ), array( 'items', 'base_revision' ) ) ) {
				return $this->error( 'invalid_favorites', 'فهرست علاقه‌مندی‌ها معتبر نیست.', 400 );}
		}
		$result = \Digitalogic\Laravel\Services\MobileWishlist::sync( (int) $session['user_id'], $items, $base );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return $this->json(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				) + ( $data['snapshot'] ?? array() ),
				(int) ( $data['status'] ?? 503 )
			);}
		return $this->json( $result );
	}

	/**
	 * Report whether the deployed adapter and its required provider are available.
	 *
	 * @return bool Native adapter result.
	 */
	public static function enabled(): bool {
		return in_array( get_option( 'digitalogic_mobile_identity_schema' ), array( '1', '2' ), true ) && (bool) get_option( 'digitalogic_mobile_native_auth', false );
	}
	/**
	 * Require the current native identity schema and configured existing SMS transport.
	 *
	 * @return bool Native adapter result.
	 */
	public static function otpEnabled(): bool {
		return self::enabled() && \Digitalogic_Smsir_Integration::native_otp_configured(); }
	/**
	 * Require the current native identity schema and audited Google verification configuration.
	 *
	 * @return bool Native adapter result.
	 */
	public static function googleEnabled(): bool {
		return self::enabled() && Google::configured(); }
	/**
	 * Require the purpose-bound phone-link schema before advertising verification.
	 *
	 * @return bool Native adapter result.
	 */
	public static function phoneEnabled(): bool {
		return self::otpEnabled() && '2' === get_option( 'digitalogic_mobile_identity_schema' );
	}

	/**
	 * Authenticate the supplied native bearer only when native authentication is enabled.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return array|null Native adapter result.
	 */
	private function session( Request $request ): ?array {
		return self::enabled() ? Store::authenticate( $request->headers->get( 'Authorization', '' ) ) : null;
	}

	/**
	 * Project the current owner’s canonical profile and explicit identity verification status.
	 *
	 * @param int $id Exact scoped identifier.
	 * @return array Native adapter result.
	 */
	private function profileData( int $id ): array {
		$user    = get_user_by( 'id', $id );
		$billing = array();
		foreach ( self::billingFields() as $field ) {
			$billing[ $field ] = (string) get_user_meta( $id, 'billing_' . $field, true );}
		$phone       = Store::phone( (string) get_user_meta( $id, 'digits_phone', true ) );
		$verified    = null !== $phone && Store::ownerOfPhone( $phone ) === $id;
		$subject     = (string) get_user_meta( $id, 'auth_google_id', true );
		$email_parts = explode( '@', (string) $user->user_email, 2 );
		return array_merge(
			array(
				'id'           => $id,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'first_name'   => (string) get_user_meta( $id, 'first_name', true ),
				'last_name'    => (string) get_user_meta( $id, 'last_name', true ),
				'billing'      => $billing,
				'identity'     => array(
					'phone_verified'              => $verified,
					'google_linked'               => '' !== $subject && Google::owner( $subject ) === $id,
					'phone_masked'                => $phone ? substr( $phone, 0, 5 ) . '•••••' . substr( $phone, -3 ) : null,
					'email_masked'                => count( $email_parts ) === 2 ? mb_substr( $email_parts[0], 0, 1 ) . '•••@' . $email_parts[1] : null,
					'requires_phone_verification' => ! $verified,
				),
			),
			Addresses::presentation( new \WC_Customer( $id ) )
		);
	}

	/**
	 * Return the supported canonical Woo billing field names.
	 *
	 * @return array Native adapter result.
	 */
	private static function billingFields(): array {
		return array( 'first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
	}

	/**
	 * Return delivery state without disclosing OTP values or promising handset receipt.
	 *
	 * @param array $row Supporting durable metadata row.
	 * @return Response Native adapter result.
	 */
	private function challengeResponse( array $row ): Response {
		return $this->json(
			array(
				'challenge_id'    => $row['id'],
				'retry_after'     => max( 0, 60 - ( time() - (int) $row['created_at'] ) ),
				'expires_in'      => max( 0, (int) $row['expires_at'] - time() ),
				'expires_at'      => gmdate( 'c', (int) $row['expires_at'] ),
				'delivery_status' => 'accepted' === $row['state'] ? 'accepted' : 'pending',
			),
			202
		);
	}

	/**
	 * Reject an expired or invalid native session without disclosing account details.
	 *
	 * @return Response Native adapter result.
	 */
	private function unauthorized(): Response {
		return $this->error( 'unauthorized', 'دوباره وارد حساب شوید.', 401 );}
	/**
	 * Return a bounded error code and customer-facing message.
	 *
	 * @param string $code Bounded error or challenge code.
	 * @param string $message Message.
	 * @param int    $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 */
	private function error( string $code, string $message, int $status ): Response {
		return $this->json(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);}
	/**
	 * Return a native JSON response with the appropriate privacy headers.
	 *
	 * @param array $data Canonical or validated native data.
	 * @param int   $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 */
	private function json( array $data, int $status = 200 ): Response {
		return response()->json(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);}
}
