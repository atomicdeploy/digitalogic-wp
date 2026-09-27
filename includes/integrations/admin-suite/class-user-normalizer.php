<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class User_Normalizer {

	private const AUDIT_TRANSIENT = 'digitalogic_admin_users_audit_v1';
	private const BATCH_SIZE      = 500;
	private const PHONE_META_KEYS = array( 'digits_phone_no', 'digits_phone', 'billing_phone' );
	private const ALIAS_ROLES     = array( 'customer', 'subscriber' );

	/**
	 * Audit or normalize user data.
	 *
	 * The returned structure contains aggregate counters only. It never returns
	 * user IDs, logins, e-mail addresses, names, or phone values.
	 *
	 * @return array<string,int>
	 */
	public static function run( bool $apply = false ): array {
		$counts          = self::empty_counts();
		$records         = array();
		$owners_by_phone = array();
		$offset          = 0;

		do {
			$users = get_users(
				array(
					'number'      => self::BATCH_SIZE,
					'offset'      => $offset,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'fields'      => 'all',
					'count_total' => false,
				)
			);

			if ( $users === array() ) {
				break;
			}

			$user_ids = array_map( static fn ( \WP_User $user ): int => (int) $user->ID, $users );
			update_meta_cache( 'user', $user_ids );

			foreach ( $users as $user ) {
				++$counts['users_scanned'];
				$record    = self::inspect_user( $user );
				$records[] = $record;

				if ( $record['valid_phone_count'] === 0 ) {
					if ( $record['has_phone_data'] ) {
						++$counts['invalid_phone_users'];
					}
					continue;
				}

				++$counts['users_with_valid_mobile'];

				if ( $record['valid_phone_count'] > 1 ) {
					++$counts['conflicting_phone_users'];
				}

				// Every valid value participates in duplicate detection, even
				// when its owner also has conflicting metadata. This prevents a
				// second account from claiming one side of that conflict.
				foreach ( $record['phones'] as $phone ) {
					if ( ! isset( $owners_by_phone[ $phone ] ) ) {
						$owners_by_phone[ $phone ] = array();
					}
					$owners_by_phone[ $phone ][] = $record['id'];
				}
			}

			$offset += count( $users );
		} while ( count( $users ) === self::BATCH_SIZE );

		$duplicate_phones   = array();
		$duplicate_user_ids = array();
		foreach ( $owners_by_phone as $phone => $owners ) {
			if ( count( $owners ) > 1 ) {
				$duplicate_phones[ $phone ] = true;
				++$counts['duplicate_phone_values'];
				foreach ( $owners as $owner_id ) {
					$duplicate_user_ids[ (int) $owner_id ] = true;
				}
			}
		}
		$counts['duplicate_phone_users'] = count( $duplicate_user_ids );

		foreach ( $records as $record ) {
			self::process_phone_record( $record, $duplicate_phones, $apply, $counts );
			self::process_alias_record( $record, $apply, $counts );
		}

		if ( $apply ) {
			delete_transient( self::AUDIT_TRANSIENT );
		}

		return $counts;
	}

	/** @return array<string,int> */
	public static function audit_counts( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = get_transient( self::AUDIT_TRANSIENT );
			if ( is_array( $cached ) ) {
				return array_map( 'intval', $cached );
			}
		}

		$counts = self::run( false );
		set_transient( self::AUDIT_TRANSIENT, $counts, 10 * MINUTE_IN_SECONDS );

		return $counts;
	}

	/**
	 * Convert an Iranian mobile representation to the Digits local form.
	 *
	 * @return string|null 9xxxxxxxxx or null when the value is not unambiguous.
	 */
	public static function canonical_mobile( mixed $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( self::ascii_digits( (string) $value ) );
		if ( $value === '' ) {
			return null;
		}

		// A name containing a phone-like substring is not "equal to a phone".
		// Permit presentation punctuation only, and reject labels/extensions.
		if ( preg_match( '/^[0-9+()\.\-\s]+$/', $value ) !== 1 ) {
			return null;
		}

		$digits = preg_replace( '/[^0-9]+/', '', $value );
		if ( ! is_string( $digits ) || $digits === '' ) {
			return null;
		}

		if ( str_starts_with( $digits, '0098' ) ) {
			$digits = substr( $digits, 4 );
		} elseif ( str_starts_with( $digits, '98' ) && in_array( strlen( $digits ), array( 12, 13 ), true ) ) {
			$digits = substr( $digits, 2 );
		}

		if ( str_starts_with( $digits, '0' ) && strlen( $digits ) === 11 ) {
			$digits = substr( $digits, 1 );
		}

		return preg_match( '/^9[0-9]{9}$/', $digits ) === 1 ? $digits : null;
	}

	/** @return array<string,int> */
	private static function empty_counts(): array {
		return array(
			'users_scanned'               => 0,
			'users_with_valid_mobile'     => 0,
			'invalid_phone_users'         => 0,
			'conflicting_phone_users'     => 0,
			'duplicate_phone_values'      => 0,
			'duplicate_phone_users'       => 0,
			'eligible_phone_users'        => 0,
			'phone_users_needing_change'  => 0,
			'phone_fields_needing_change' => 0,
			'phone_users_changed'         => 0,
			'phone_fields_changed'        => 0,
			'alias_users_needing_change'  => 0,
			'alias_fields_needing_change' => 0,
			'alias_users_changed'         => 0,
			'alias_fields_changed'        => 0,
			'errors'                      => 0,
		);
	}

	/**
	 * @return array{
	 *   id:int,
	 *   roles:string[],
	 *   display_name:string,
	 *   user_nicename:string,
	 *   nickname:string,
	 *   phone_values:array<string,string[]>,
	 *   phones:string[],
	 *   phone:string,
	 *   valid_phone_count:int,
	 *   has_phone_data:bool
	 * }
	 */
	private static function inspect_user( \WP_User $user ): array {
		$valid          = array();
		$phone_values   = array();
		$has_phone_data = false;

		foreach ( self::PHONE_META_KEYS as $key ) {
			$all_values           = get_user_meta( (int) $user->ID, $key, false );
			$phone_values[ $key ] = array_values(
				array_map(
					static fn ( mixed $raw_value ): string => is_scalar( $raw_value ) ? (string) $raw_value : '',
					$all_values
				)
			);

			foreach ( $all_values as $raw_value ) {
				if ( is_scalar( $raw_value ) && trim( (string) $raw_value ) !== '' ) {
					$has_phone_data = true;
				}

				$canonical = self::canonical_mobile( $raw_value );
				if ( $canonical !== null ) {
					$valid[ $canonical ] = true;
				}
			}
		}

		$country_values                   = get_user_meta( (int) $user->ID, 'digt_countrycode', false );
		$phone_values['digt_countrycode'] = array_values(
			array_map(
				static fn ( mixed $raw_value ): string => is_scalar( $raw_value ) ? (string) $raw_value : '',
				$country_values
			)
		);
		$phones                           = array_keys( $valid );

		return array(
			'id'                => (int) $user->ID,
			'roles'             => array_values( array_map( 'strval', (array) $user->roles ) ),
			'display_name'      => (string) $user->display_name,
			'user_nicename'     => (string) $user->user_nicename,
			'nickname'          => (string) get_user_meta( (int) $user->ID, 'nickname', true ),
			'phone_values'      => $phone_values,
			'phones'            => $phones,
			'phone'             => count( $phones ) === 1 ? $phones[0] : '',
			'valid_phone_count' => count( $phones ),
			'has_phone_data'    => $has_phone_data,
		);
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<string,bool>  $duplicate_phones
	 * @param array<string,int>   $counts
	 */
	private static function process_phone_record( array $record, array $duplicate_phones, bool $apply, array &$counts ): void {
		if ( $record['valid_phone_count'] !== 1 || isset( $duplicate_phones[ $record['phone'] ] ) ) {
			return;
		}

		++$counts['eligible_phone_users'];
		$phone   = (string) $record['phone'];
		$desired = array(
			'digits_phone_no'  => $phone,
			'digits_phone'     => '+98' . $phone,
			'digt_countrycode' => '+98',
		);

		$billing_values    = isset( $record['phone_values']['billing_phone'] ) && is_array( $record['phone_values']['billing_phone'] )
			? $record['phone_values']['billing_phone']
			: array();
		$has_billing_phone = array_filter(
			$billing_values,
			static fn ( mixed $value ): bool => is_scalar( $value ) && trim( (string) $value ) !== ''
		) !== array();

		// Create WooCommerce billing data only for customer accounts. For all
		// other roles, normalize billing_phone only when that field already
		// exists; Digits-only administrators should not acquire commerce data.
		if ( $has_billing_phone || in_array( 'customer', (array) $record['roles'], true ) ) {
			$desired['billing_phone'] = '0' . $phone;
		}
		$changes = array();

		foreach ( $desired as $key => $value ) {
			$current_values = isset( $record['phone_values'][ $key ] ) && is_array( $record['phone_values'][ $key ] )
				? $record['phone_values'][ $key ]
				: array();
			$all_canonical  = $current_values !== array()
				&& array_reduce(
					$current_values,
					static fn ( bool $carry, mixed $current ): bool => $carry && (string) $current === $value,
					true
				);

			if ( ! $all_canonical ) {
				$changes[ $key ] = $value;
			}
		}

		if ( $changes === array() ) {
			return;
		}

		++$counts['phone_users_needing_change'];
		$counts['phone_fields_needing_change'] += count( $changes );

		if ( ! $apply ) {
			return;
		}

		$changed_for_user = 0;
		foreach ( $changes as $key => $value ) {
			$result = update_user_meta( (int) $record['id'], $key, $value );
			if ( $result === false ) {
				++$counts['errors'];
				continue;
			}

			++$changed_for_user;
			++$counts['phone_fields_changed'];
		}

		if ( $changed_for_user > 0 ) {
			++$counts['phone_users_changed'];
		}
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<string,int>   $counts
	 */
	private static function process_alias_record( array $record, bool $apply, array &$counts ): void {
		if ( ! self::has_only_alias_roles( (array) $record['roles'] ) ) {
			return;
		}

		$alias   = self::stable_alias( (int) $record['id'] );
		$changes = array();

		if ( self::canonical_mobile( (string) $record['display_name'] ) !== null ) {
			$changes['display_name'] = 'Member ' . $alias['token'];
		}
		if ( self::canonical_mobile( (string) $record['user_nicename'] ) !== null ) {
			$changes['user_nicename'] = $alias['slug'];
		}
		if ( self::canonical_mobile( (string) $record['nickname'] ) !== null ) {
			$changes['nickname'] = $alias['slug'];
		}

		if ( $changes === array() ) {
			return;
		}

		++$counts['alias_users_needing_change'];
		$counts['alias_fields_needing_change'] += count( $changes );

		if ( ! $apply ) {
			return;
		}

		$changed_for_user = 0;
		$user_update      = array( 'ID' => (int) $record['id'] );
		foreach ( array( 'display_name', 'user_nicename' ) as $field ) {
			if ( isset( $changes[ $field ] ) ) {
				$user_update[ $field ] = $changes[ $field ];
			}
		}

		if ( count( $user_update ) > 1 ) {
			$result = wp_update_user( $user_update );
			if ( is_wp_error( $result ) ) {
				++$counts['errors'];
			} else {
				$table_field_count               = count( $user_update ) - 1;
				$changed_for_user               += $table_field_count;
				$counts['alias_fields_changed'] += $table_field_count;
			}
		}

		if ( isset( $changes['nickname'] ) ) {
			$result = update_user_meta( (int) $record['id'], 'nickname', $changes['nickname'] );
			if ( $result === false ) {
				++$counts['errors'];
			} else {
				++$changed_for_user;
				++$counts['alias_fields_changed'];
			}
		}

		if ( $changed_for_user > 0 ) {
			++$counts['alias_users_changed'];
		}
	}

	/** @param string[] $roles */
	private static function has_only_alias_roles( array $roles ): bool {
		if ( $roles === array() || array_intersect( $roles, self::ALIAS_ROLES ) === array() ) {
			return false;
		}

		return array_diff( $roles, self::ALIAS_ROLES ) === array();
	}

	/** @return array{token:string,slug:string} */
	private static function stable_alias( int $user_id ): array {
		$token = substr( hash_hmac( 'sha256', 'digitalogic-admin-user:' . $user_id, wp_salt( 'auth' ) ), 0, 16 );

		return array(
			'token' => $token,
			'slug'  => 'member-' . $token,
		);
	}

	private static function ascii_digits( string $value ): string {
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
}
