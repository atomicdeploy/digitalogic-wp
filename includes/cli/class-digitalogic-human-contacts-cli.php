<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Plugin Name: Digitalogic Human Contacts
 * Description: Protected human-coordination directory with safe WP-CLI discovery.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return; }

final class Digitalogic_Human_Contacts_CLI {
	private static function records() {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'An authorized administrator is required; pass --user=<administrator>.' );
		}
		$records = get_option( 'digitalogic_human_contacts', array() );
		if ( ! is_array( $records ) ) {
			WP_CLI::error( 'Contact registry is invalid.' );
		}
		return $records;
	}

	private static function alias( $value ) {
		return is_string( $value ) && preg_match( '/^[a-z][a-z0-9_-]{0,47}$/D', $value ) ? $value : null;
	}

	public static function safe_record( $person, $record ) {
		// Explicit allowlist: never include phone numbers, email/address values,
		// numeric Telegram routing or arbitrary future private registry fields.
		$fields  = is_array( $record['wordpress_contact_fields'] ?? null ) ? $record['wordpress_contact_fields'] : array();
		$names   = array_values( array_filter( $record['name_aliases'] ?? array(), 'is_string' ) );
		$aliases = array();
		foreach ( array( 'pbx_alias', 'mobile_alias', 'sms_alias', 'telegram_alias', 'email_alias' ) as $key ) {
			$value = self::alias( $record[ $key ] ?? null );
			if ( $value !== null ) {
				$aliases[ $key ] = $value;
			}
		}
		$preferences         = array();
		$allowed_preferences = array( 'telegram', 'extension_then_mobile', 'refer_to_david', 'refer_to_shokri', 'extension_then_mobile_if_away', 'telegram_or_sms' );
		foreach ( array( 'quick_questions', 'small_reports', 'technical_or_critical', 'hands_on', 'no_answer', 'sleep_hours_deferrable' ) as $key ) {
			$value = $record['contact_preferences'][ $key ] ?? null;
			if ( in_array( $value, $allowed_preferences, true ) ) {
				$preferences[ $key ] = $value;
			}
		}
		$preferences['repeated_redial'] = false;
		$observed                       = $record['availability_observation'] ?? array();
		$availability                   = null;
		if ( is_array( $observed ) && in_array( $observed['state'] ?? null, array( 'busy', 'unavailable', 'available' ), true )
			&& is_string( $observed['observed_at'] ?? null )
			&& preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $observed['observed_at'] ) ) {
			$availability = array(
				'state'              => $observed['state'],
				'observed_at'        => $observed['observed_at'],
				'point_in_time_only' => true,
			);
		}
		return array(
			'person'                   => $person,
			'name_aliases'             => $names,
			'wp_user_id'               => (int) ( $record['wp_user_id'] ?? 0 ),
			'channel_aliases'          => $aliases,
			'preferred_language'       => in_array( $record['preferred_language'] ?? null, array( 'fa', 'en' ), true ) ? $record['preferred_language'] : 'fa',
			'timezone'                 => 'Asia/Tehran',
			'preferences'              => $preferences,
			'availability_observation' => $availability,
			'known_fields'             => array_keys( $fields ),
			'phone_candidate_count'    => count( $record['phone_candidates'] ?? array() ),
			'extension_recorded'       => ! empty( $record['pbx_extension'] ),
			'missing_routes'           => array_values(
				array_filter(
					array(
						'pbx'      => empty( $aliases['pbx_alias'] ) ? 'pbx' : null,
						'mobile'   => empty( $aliases['mobile_alias'] ) ? 'mobile' : null,
						'telegram' => empty( $aliases['telegram_alias'] ) ? 'telegram' : null,
						'sms'      => empty( $aliases['sms_alias'] ) ? 'sms' : null,
					)
				)
			),
			'note'                     => 'Contact records are not action authorization. Resolve current PBX directory, prepare, execute within authorized scope, and verify replies.',
		);
	}

	/**
	 * List saved human contacts without private destinations.
	 *
	 * ## EXAMPLES
	 *     wp digitalogic contacts list --user=<administrator>
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {
		$safe = array();
		foreach ( self::records() as $person => $record ) {
			if ( is_array( $record ) ) {
				$safe[] = self::safe_record( $person, $record );
			}
		}
		WP_CLI::line( wp_json_encode( $safe, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Resolve a person name to saved channel aliases and preferences.
	 *
	 * ## OPTIONS
	 * <person>
	 * : Person key or known name, for example shokri, David or آقای شکری.
	 */
	public function get( $args, $assoc_args ) {
		$wanted  = trim( (string) ( $args[0] ?? '' ) );
		$matches = array();
		foreach ( self::records() as $person => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			foreach ( array_merge( array( $person ), $record['name_aliases'] ?? array() ) as $name ) {
				if ( is_string( $name ) && strcasecmp( $wanted, $name ) === 0 ) {
					$matches[] = self::safe_record( $person, $record );
					break;
				}
			}
		}
		if ( count( $matches ) !== 1 ) {
			WP_CLI::error( 'Person not found or ambiguous; inspect contacts list.' );
		}
		WP_CLI::line( wp_json_encode( $matches[0], JSON_UNESCAPED_UNICODE ) );
	}
}
WP_CLI::add_command( 'digitalogic contacts', 'Digitalogic_Human_Contacts_CLI' );
