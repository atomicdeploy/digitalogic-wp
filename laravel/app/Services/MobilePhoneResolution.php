<?php
/**
 * Durable, non-authorizing outcomes for verified native phone conflicts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.

/** Conflict explanations never transfer identities, roles, sessions or history. */
final class MobilePhoneResolution {
	/**
	 * Identify accounts requiring a separately reviewed privilege-preserving operation.
	 *
	 * @param int $id Existing WordPress user ID.
	 * @return bool Whether the account has protected privileges.
	 */
	public static function protectedAccount( int $id ): bool {
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return true;
		}
		foreach ( array( 'manage_options', 'manage_woocommerce', 'edit_others_posts', 'promote_users', 'delete_users' ) as $capability ) {
			if ( ! empty( $user->allcaps[ $capability ] ) ) {
				return true;
			}
		}
		return (bool) array_intersect( $user->roles, array( 'administrator', 'shop_manager', 'editor' ) );
	}

	/**
	 * Describe the exact conflict at verification time without disclosing another account.
	 *
	 * @param int    $actor Current verified Google/native owner.
	 * @param int    $owner Exact phone owner, zero absent or negative ambiguous.
	 * @param string $existing_phone Actor's current Digits phone.
	 * @param string $phone Actor's verified submitted phone.
	 * @return array Customer-facing reason and supported actions only.
	 */
	public static function describe( int $actor, int $owner, string $existing_phone, string $phone ): array {
		if ( $owner < 0 ) {
			$reason = 'ambiguous_phone_owner';
		} elseif ( $owner > 0 && $owner !== $actor ) {
			$reason = self::protectedAccount( $actor ) || self::protectedAccount( $owner ) ? 'protected_phone_account' : 'phone_account_exists';
		} elseif ( '' !== $existing_phone && MobileIdentityStore::phone( $existing_phone ) !== $phone ) {
			$reason = 'current_phone_differs';
		} else {
			$reason = 'review_required';
		}
		return self::explanation( $reason );
	}

	/**
	 * Provide concrete existing actions without promising an unimplemented merge.
	 *
	 * @param string $reason Durable conflict category.
	 * @return array Non-authorizing UI guidance.
	 */
	public static function explanation( string $reason ): array {
		$existing = in_array( $reason, array( 'protected_phone_account', 'phone_account_exists' ), true );
		$merge    = $existing && MobileAccountMerge::enabled();
		$message  = 'protected_phone_account' === $reason
			? 'این شماره به حسابی با دسترسی ویژه متصل است. برای استفاده از آن، وارد حساب قبلی شوید.'
			: 'برای استفاده از حساب قبلی، ورود با شماره را انتخاب کنید. تا ورود موفق، حساب فعلی باز می‌ماند و اطلاعات هر دو حساب حفظ می‌شود.';
		return array(
			'reason'          => $reason,
			'title'           => $existing ? 'این شماره حساب دیگری دارد' : 'اتصال شماره نیاز به بررسی دارد',
			'message'         => $merge ? 'گوگل و شماره شما به دو حساب متصل‌اند. با تأیید هویت، اطلاعات قابل انتقال را بررسی و حساب‌ها را یکپارچه کنید.' : ( $existing ? $message : 'شماره تأیید شده است، اما اتصال آن به حساب فعلی انجام نشده است. می‌توانید شماره دیگری را تأیید کنید؛ اطلاعات حساب فعلی حفظ می‌شود.' ),
			'allowed_actions' => $merge ? array( 'merge_accounts', 'try_another_phone' ) : ( $existing ? array( 'sign_in_phone', 'try_another_phone' ) : array( 'try_another_phone' ) ),
			'assessed_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * Read the latest same-session outcome after an app update without renewing its proof.
	 *
	 * @param array $session Authenticated native session.
	 * @return array|null Own prior submission and durable explanation, never target identity.
	 */
	public static function pending( array $session ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Current exact-session security outcome; no public cache or other-customer query.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE purpose='phone_link' AND actor_user_id=%d AND actor_session_id=%s AND state='used' ORDER BY GREATEST(created_at,verified_at) DESC,verified_at DESC,created_at DESC,(result='linked') DESC,id DESC LIMIT 1", MobileIdentityStore::table( 'otp' ), $session['user_id'], $session['id'] ), ARRAY_A );
		if ( ! $row || 'review_required' !== $row['result'] ) {
			return null;
		}
		$resolution = json_decode( $row['resolution'] ?? '', true );
		if ( ! is_array( $resolution ) ) {
			$resolution = self::explanation( 'review_required' );
		}
		$phone = MobileRegistrationStore::open( (string) $row['phone_ciphertext'], 'phone' );
		if ( ! $phone || ! hash_equals( $row['phone_hash'], MobileIdentityStore::hash( $phone ) ) ) {
			$phone = null;
		}
		if ( $phone ) {
			$owner    = MobileIdentityStore::ownerOfPhone( $phone );
			$existing = (string) get_user_meta( (int) $session['user_id'], 'digits_phone', true );
			if ( $owner === (int) $session['user_id'] && MobileIdentityStore::phone( $existing ) === $phone ) {
				return null;
			}
			$current = self::describe( (int) $session['user_id'], $owner, $existing, $phone );
			if ( $current['reason'] !== $resolution['reason'] || ( $resolution['allowed_actions'] ?? array() ) !== $current['allowed_actions'] ) {
				// Explain changed current ownership without rewriting the stored proof outcome.
				$resolution = $current;
			}
		}
		return array(
			'status'       => 'review_required',
			'challenge_id' => $row['id'],
			'phone'        => $phone,
			'verified_at'  => (int) ( $row['verified_at'] ?? 0 ) > 0 ? gmdate( 'c', (int) $row['verified_at'] ) : null,
			'resolution'   => $resolution,
		);
	}
}
