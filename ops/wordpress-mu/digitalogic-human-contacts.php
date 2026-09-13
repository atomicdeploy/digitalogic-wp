<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- A must-use plugin keeps its deployment filename.
/**
 * Plugin Name: Digitalogic Human Contacts
 * Description: Protected human-coordination directory with safe WP-CLI discovery.
 * Version: 1.1.0
 * Author: Digitalogic
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Expose only bounded, non-routing contact-directory projections to WP-CLI.
	 */
	final class Digitalogic_Human_Contacts_CLI {

		private const OPTION_NAME      = 'digitalogic_human_contacts';
		private const MAX_RECORDS      = 32;
		private const MAX_NAME_ALIASES = 12;
		private const MAX_PHONE_HINTS  = 20;

		private const CHANNEL_ALIAS_KEYS = array(
			'pbx_alias',
			'mobile_alias',
			'sms_alias',
			'telegram_alias',
			'email_alias',
		);

		private const PREFERENCE_KEYS = array(
			'quick_questions',
			'small_reports',
			'technical_or_critical',
			'hands_on',
			'no_answer',
			'sleep_hours_deferrable',
		);

		private const PREFERENCE_VALUES = array(
			'telegram',
			'extension_then_mobile',
			'refer_to_david',
			'refer_to_shokri',
			'extension_then_mobile_if_away',
			'telegram_or_sms',
		);

		private const AVAILABILITY_STATES = array(
			'available',
			'busy',
			'unavailable',
		);

		private const CONTACT_FIELD_NAMES = array(
			'first_name',
			'last_name',
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'shipping_phone',
			'digits_phone',
			'digits_phone_no',
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
		);

		/**
		 * List saved human contacts without private destinations.
		 *
		 * ## EXAMPLES
		 *
		 *     wp digitalogic contacts list --user=<administrator>
		 *
		 * @subcommand list
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Command-specific named arguments.
		 * @return void
		 */
		public function list_( $args, $assoc_args ) {
			if ( ! $this->authorize() ) {
				return;
			}

			if ( ! empty( $args ) || ! empty( $assoc_args ) ) {
				WP_CLI::error( 'This command does not accept command-specific arguments.' );
				return;
			}

			$records = $this->safe_records();
			if ( null === $records ) {
				return;
			}

			$this->emit_json( $records );
		}

		/**
		 * Resolve a person name to saved channel aliases and preferences.
		 *
		 * ## OPTIONS
		 *
		 * <person>
		 * : Person key or known name.
		 *
		 * ## EXAMPLES
		 *
		 *     wp digitalogic contacts get David --user=<administrator>
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Command-specific named arguments.
		 * @return void
		 */
		public function get( $args, $assoc_args ) {
			if ( ! $this->authorize() ) {
				return;
			}

			if ( 1 !== count( $args ) || ! empty( $assoc_args ) || ! is_string( $args[0] ) ) {
				WP_CLI::error( 'Exactly one person is required; command-specific options are not accepted.' );
				return;
			}

			$wanted  = trim( $args[0] );
			$records = $this->safe_records();
			if ( null === $records ) {
				return;
			}

			$matches = array();
			foreach ( $records as $record ) {
				$names = array_merge( array( $record['person'] ), $record['name_aliases'] );
				foreach ( $names as $name ) {
					if ( 0 === strcasecmp( $wanted, $name ) ) {
						$matches[] = $record;
						break;
					}
				}
			}

			if ( 1 !== count( $matches ) ) {
				WP_CLI::error( 'Person not found or ambiguous; inspect contacts list.' );
				return;
			}

			$this->emit_json( $matches[0] );
		}

		/**
		 * Enforce the WordPress administrator capability before reading the option.
		 *
		 * @return bool
		 */
		private function authorize() {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			WP_CLI::error( 'An authorized administrator is required; pass --user=<administrator>.' );
			return false;
		}

		/**
		 * Read and reconstruct every record through the explicit public allowlist.
		 *
		 * @return array|null
		 */
		private function safe_records() {
			$records = get_option( self::OPTION_NAME, array() );
			if ( ! is_array( $records ) || count( $records ) > self::MAX_RECORDS ) {
				$this->unavailable();
				return null;
			}

			$safe_records = array();
			foreach ( $records as $person => $record ) {
				$safe_record = $this->safe_record( $person, $record );
				if ( null === $safe_record ) {
					$this->unavailable();
					return null;
				}
				$safe_records[] = $safe_record;
			}

			usort(
				$safe_records,
				static function ( $left, $right ) {
					return strcmp( $left['person'], $right['person'] );
				}
			);

			return $safe_records;
		}

		/**
		 * Rebuild one output record without forwarding private values.
		 *
		 * @param mixed $person Registry key.
		 * @param mixed $record Private registry record.
		 * @return array|null
		 */
		private function safe_record( $person, $record ) {
			$person = $this->safe_symbolic_alias( $person );
			if ( null === $person || ! is_array( $record ) ) {
				return null;
			}

			$name_aliases = $this->safe_name_aliases( $record );
			$fields       = $this->safe_known_fields( $record );
			$preferences  = $this->safe_preferences( $record );
			$availability = $this->safe_availability_observation( $record );
			$phone_hints  = $record['phone_candidates'] ?? array();
			if ( null === $name_aliases || null === $fields || null === $preferences || ! is_array( $phone_hints ) || count( $phone_hints ) > self::MAX_PHONE_HINTS ) {
				return null;
			}

			$wp_user_id = $record['wp_user_id'] ?? 0;
			if ( ! is_int( $wp_user_id ) || $wp_user_id < 0 ) {
				return null;
			}

			$channel_aliases = array();
			foreach ( self::CHANNEL_ALIAS_KEYS as $key ) {
				$alias = $this->safe_symbolic_alias( $record[ $key ] ?? null );
				if ( null !== $alias ) {
					$channel_aliases[ $key ] = $alias;
				}
			}

			$missing_routes = array();
			foreach ( array( 'pbx', 'mobile', 'telegram', 'sms' ) as $channel ) {
				if ( ! isset( $channel_aliases[ $channel . '_alias' ] ) ) {
					$missing_routes[] = $channel;
				}
			}

			$extension = $record['pbx_extension'] ?? null;
			if ( null !== $extension && ! is_scalar( $extension ) ) {
				return null;
			}

			$language = $record['preferred_language'] ?? 'fa';
			if ( ! in_array( $language, array( 'fa', 'en' ), true ) ) {
				$language = 'fa';
			}

			return array(
				'person'                   => $person,
				'name_aliases'             => $name_aliases,
				'wordpress_linked'         => $wp_user_id > 0,
				'channel_aliases'          => $channel_aliases,
				'preferred_language'       => $language,
				'timezone'                 => 'Asia/Tehran',
				'preferences'              => $preferences,
				'availability_observation' => $availability,
				'known_fields'             => $fields,
				'phone_candidate_count'    => count( $phone_hints ),
				'extension_recorded'       => null !== $extension && '' !== trim( (string) $extension ),
				'missing_routes'           => $missing_routes,
				'note'                     => 'Contact records are not action authorization. Availability observations are point-in-time and may be stale. Resolve current PBX directory, prepare, execute within authorized scope, and verify replies.',
			);
		}

		/**
		 * Rebuild a controlled point-in-time availability observation.
		 *
		 * An omitted or invalid observation becomes null. Consumers must never
		 * interpret a returned state as permanent or as contact authorization.
		 *
		 * @param array $record Private registry record.
		 * @return array|null
		 */
		private function safe_availability_observation( $record ) {
			$observation = $record['availability_observation'] ?? null;
			if ( ! is_array( $observation ) || count( $observation ) > 4 ) {
				return null;
			}

			$state       = $observation['state'] ?? null;
			$observed_at = $this->safe_observed_at( $observation['observed_at'] ?? null );
			if ( ! in_array( $state, self::AVAILABILITY_STATES, true ) || null === $observed_at ) {
				return null;
			}

			return array(
				'state'              => $state,
				'observed_at'        => $observed_at,
				'point_in_time_only' => true,
			);
		}

		/**
		 * Validate a second-precision RFC 3339 timestamp without normalizing it.
		 *
		 * @param mixed $value Candidate timestamp.
		 * @return string|null
		 */
		private function safe_observed_at( $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 25 ) {
				return null;
			}

			$matches = array();
			if ( 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:Z|([+-])([0-9]{2}):([0-9]{2}))\z/D', $value, $matches ) ) {
				return null;
			}

			if ( ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return null;
			}

			if ( (int) $matches[4] > 23 || (int) $matches[5] > 59 || (int) $matches[6] > 59 ) {
				return null;
			}

			if ( isset( $matches[8] ) ) {
				$offset_hour   = (int) $matches[8];
				$offset_minute = (int) $matches[9];
				if ( $offset_hour > 14 || $offset_minute > 59 || ( 14 === $offset_hour && 0 !== $offset_minute ) ) {
					return null;
				}
			}

			return $value;
		}

		/**
		 * Accept only bounded human-readable names without digits or route syntax.
		 *
		 * @param array $record Private registry record.
		 * @return array|null
		 */
		private function safe_name_aliases( $record ) {
			$names = $record['name_aliases'] ?? array();
			if ( ! is_array( $names ) || count( $names ) > self::MAX_NAME_ALIASES ) {
				return null;
			}

			$safe_names = array();
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) ) {
					return null;
				}

				$name = trim( $name );
				if ( '' === $name || strlen( $name ) > 192 || 1 !== preg_match( '/\A[\p{L}\p{M}][\p{L}\p{M}\p{Zs}.\'’_-]{0,95}\z/uD', $name ) ) {
					return null;
				}

				if ( ! in_array( $name, $safe_names, true ) ) {
					$safe_names[] = $name;
				}
			}

			return $safe_names;
		}

		/**
		 * Return only reviewed field names, never their private values.
		 *
		 * @param array $record Private registry record.
		 * @return array|null
		 */
		private function safe_known_fields( $record ) {
			$fields = $record['wordpress_contact_fields'] ?? array();
			if ( ! is_array( $fields ) || count( $fields ) > count( self::CONTACT_FIELD_NAMES ) ) {
				return null;
			}

			$known_fields = array();
			foreach ( self::CONTACT_FIELD_NAMES as $field_name ) {
				if ( array_key_exists( $field_name, $fields ) ) {
					$known_fields[] = $field_name;
				}
			}

			return $known_fields;
		}

		/**
		 * Reconstruct only controlled preference keys and values.
		 *
		 * @param array $record Private registry record.
		 * @return array|null
		 */
		private function safe_preferences( $record ) {
			$source = $record['contact_preferences'] ?? array();
			if ( ! is_array( $source ) || count( $source ) > count( self::PREFERENCE_KEYS ) + 1 ) {
				return null;
			}

			$preferences = array();
			foreach ( self::PREFERENCE_KEYS as $key ) {
				$value = $source[ $key ] ?? null;
				if ( in_array( $value, self::PREFERENCE_VALUES, true ) ) {
					$preferences[ $key ] = $value;
				}
			}
			$preferences['repeated_redial'] = false;

			return $preferences;
		}

		/**
		 * Validate an opaque symbolic key while excluding embedded numeric routes.
		 *
		 * @param mixed $value Candidate key.
		 * @return string|null
		 */
		private function safe_symbolic_alias( $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 48 ) {
				return null;
			}

			if ( 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,47}\z/D', $value ) || 1 === preg_match( '/[0-9]{3,}/', $value ) ) {
				return null;
			}

			return $value;
		}

		/**
		 * Emit one JSON line and fail without echoing encoder diagnostics.
		 *
		 * @param array $value Sanitized output.
		 * @return void
		 */
		private function emit_json( $value ) {
			$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $json ) ) {
				$this->unavailable();
				return;
			}

			WP_CLI::line( $json );
		}

		/**
		 * Emit the single generic private-source failure.
		 *
		 * @return void
		 */
		private function unavailable() {
			WP_CLI::error( 'Contact directory is unavailable.' );
		}
	}

	WP_CLI::add_command( 'digitalogic contacts', 'Digitalogic_Human_Contacts_CLI' );
}
