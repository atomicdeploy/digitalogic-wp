<?php
/**
 * Canonical identity comparisons without email-based account claiming.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Authentication must inspect current canonical identity rows and reject ambiguous or incomplete inventories.

/** Comparison keys discover candidates; signed subject and phone proofs grant authority. */
final class MobileIdentityResolver {
	/**
	 * Normalize stored Iranian Digits representations, rejecting labels and other countries.
	 *
	 * @param mixed $value Canonical metadata input.
	 * @return string|null Iranian E.164 identity.
	 */
	public static function storedPhone( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{2066}-\x{2069}\s().-]+/u', '', MobileIdentityStore::digits( trim( $value ) ) );
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( preg_match( '/\A9[0-9]{9}\z/D', $value ) ) {
			return '+98' . $value;
		}
		return MobileIdentityStore::phone( $value );
	}

	/**
	 * Distinguish absent, unique, and contradictory stored phone identities.
	 *
	 * @param array  $rows All authoritative Digits identity rows, not billing contacts.
	 * @param string $phone Proven canonical phone.
	 * @return int Exact owner, zero absent, negative ambiguous or inconsistent.
	 */
	public static function phoneOwnerFromRows( array $rows, string $phone ): int {
		$identities = array();
		foreach ( $rows as $row ) {
			$id  = (int) ( $row['user_id'] ?? 0 );
			$key = $row['meta_key'] ?? '';
			if ( $id < 1 || ! in_array( $key, array( 'digits_phone', 'digits_phone_no', 'digt_countrycode' ), true ) ) {
				continue;
			}
			$identities[ $id ] ??= array(
				'phones'  => array(),
				'invalid' => false,
			);
			$value               = trim( (string) ( $row['meta_value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'digt_countrycode' === $key ) {
				$value = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{2066}-\x{2069}\s]+/u', '', MobileIdentityStore::digits( $value ) );
				if ( ! in_array( $value, array( '+98', '98', '0098' ), true ) ) {
					$identities[ $id ]['invalid'] = true;
				}
				continue;
			}
			$normalized = self::storedPhone( $value );
			if ( null === $normalized ) {
				$identities[ $id ]['invalid'] = true;
			} else {
				$identities[ $id ]['phones'][ $normalized ] = true;
			}
		}
		$owners = array();
		foreach ( $identities as $id => $identity ) {
			if ( ! isset( $identity['phones'][ $phone ] ) ) {
				continue;
			}
			if ( $identity['invalid'] || count( $identity['phones'] ) !== 1 ) {
				return -1;
			}
			$owners[] = $id;
		}
		return count( $owners ) === 1 ? $owners[0] : ( $owners ? -1 : 0 );
	}

	/**
	 * Current canonical metadata lookup, bounded and fail-closed on incomplete results.
	 *
	 * @param string $phone Canonical challenged phone.
	 * @return int Unique owner, zero absent, negative ambiguous/unavailable.
	 */
	public static function phoneOwner( string $phone ): int {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT user_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ('digits_phone','digits_phone_no','digt_countrycode') ORDER BY umeta_id LIMIT 20001", ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 20000 || '' !== $wpdb->last_error ) {
			return -1;
		}
		$owner = self::phoneOwnerFromRows( $rows, $phone );
		return $owner > 0 && get_user_meta( $owner, '_digitalogic_merged_into', true ) ? -1 : $owner;
	}

	/**
	 * Compare verified email candidates while preserving their original display value.
	 *
	 * @param mixed $value Email supplied by verified provider or canonical WordPress account.
	 * @return string|null Comparison key; never sufficient account ownership proof.
	 */
	public static function emailKey( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( class_exists( '\Normalizer' ) ) {
			$value = \Normalizer::normalize( $value, \Normalizer::FORM_C );
		}
		if ( ! is_string( $value ) || strlen( $value ) > 320 || substr_count( $value, '@' ) !== 1 ) {
			return null;
		}
		list( $local, $domain ) = explode( '@', $value, 2 );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$domain = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		}
		if ( ! is_string( $domain ) || '' === $domain ) {
			return null;
		}
		$local  = mb_strtolower( $local, 'UTF-8' );
		$domain = strtolower( $domain );
		if ( 'googlemail.com' === $domain ) {
			$domain = 'gmail.com';
		}
		if ( ! filter_var( $local . '@' . $domain, FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}
		if ( 'gmail.com' === $domain ) {
			$local = str_replace( '.', '', explode( '+', $local, 2 )[0] );
		}
		return '' !== $local ? $local . '@' . $domain : null;
	}

	/**
	 * Discover existing WordPress email candidates before any enrollment decision.
	 *
	 * @param string $email Cryptographically verified provider email.
	 * @return array|null Candidate IDs, or null when inventory cannot be trusted.
	 */
	public static function emailOwners( string $email ): ?array {
		global $wpdb;
		$key = self::emailKey( $email );
		if ( null === $key ) {
			return null;
		}
		$rows = $wpdb->get_results( "SELECT ID,user_email FROM {$wpdb->users} ORDER BY ID LIMIT 20001", ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 20000 || '' !== $wpdb->last_error ) {
			return null;
		}
		$owners = array();
		foreach ( $rows as $row ) {
			if ( self::emailKey( $row['user_email'] ) === $key ) {
				$canonical = self::discoveryOwner( (int) $row['ID'] );
				if ( null === $canonical ) {
					return null; }
				$owners[] = $canonical;
			}
		}
		return array_values( array_unique( $owners ) );
	}

	/**
	 * Resolve a retired alias for candidate discovery only, never for credential authentication.
	 *
	 * @param int $id Exact request identifier.
	 */
	public static function discoveryOwner( int $id ): ?int {
		global $wpdb;
		$seen = array();
		for ( $depth = 0; $depth < 8; ++$depth ) {
			if ( isset( $seen[ $id ] ) ) {
				return null; }
			$seen[ $id ] = true;
			$target      = (int) get_user_meta( $id, '_digitalogic_merged_into', true );
			if ( $target < 1 ) {
				return $id; }
			$key = (string) get_user_meta( $id, '_digitalogic_merge_operation', true );
			if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', $key ) || '1' !== get_option( 'digitalogic_mobile_merge_schema' ) ) {
				return null; }
			$accepted = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE operation_key=%s AND source_user_id=%d AND target_user_id=%d AND state='accepted'", MobileAccountMerge::table(), $key, $id, $target ) );
			if ( '1' !== (string) $accepted || '' !== $wpdb->last_error || ! get_user_by( 'id', $target ) ) {
				return null; }
			$id = $target;
		}
		return null;
	}
}
