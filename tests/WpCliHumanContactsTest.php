<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Matches the repository PHPUnit naming convention.
/**
 * Protected human-contact WP-CLI tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/ops/wordpress-mu/digitalogic-human-contacts.php';

/**
 * Verify capability, input, and non-disclosure boundaries.
 */
final class WpCliHumanContactsTest extends TestCase {

	private const GENERIC_ERROR = 'Contact directory is unavailable.';
	private const SECRET_ROUTE  = '09120000000';
	private const SECRET_URL    = 'https://private.invalid/contact-route';

	/**
	 * Reset command output and registry fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_capabilities'] = array();
		$GLOBALS['digitalogic_test_options']      = array();
		$GLOBALS['digitalogic_test_option_cache'] = array();
		WP_CLI::$errors                           = array();
		WP_CLI::$logs                             = array();
		WP_CLI::$warnings                         = array();
	}

	/**
	 * The namespace contains only the reviewed command container.
	 */
	public function test_command_namespace_is_registered(): void {
		$this->assertArrayHasKey( 'digitalogic contacts', WP_CLI::$commands );
		$this->assertSame( 'Digitalogic_Human_Contacts_CLI', WP_CLI::$commands['digitalogic contacts'] );
	}

	/**
	 * Only the two intended subcommand methods are public.
	 */
	public function test_only_list_and_get_are_public_subcommands(): void {
		$reflection = new ReflectionClass( Digitalogic_Human_Contacts_CLI::class );
		$methods    = array();
		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( Digitalogic_Human_Contacts_CLI::class === $method->getDeclaringClass()->getName() ) {
				$methods[] = $method->getName();
			}
		}
		sort( $methods );

		$this->assertSame( array( 'get', 'list_' ), $methods );
	}

	/**
	 * Capability denial wins before malformed private state is inspected.
	 */
	public function test_administrator_capability_is_required_before_registry_read(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = self::SECRET_URL;
		$command = new Digitalogic_Human_Contacts_CLI();

		$command->list_( array(), array() );

		$this->assertSame(
			array( 'An authorized administrator is required; pass --user=<administrator>.' ),
			WP_CLI::$errors
		);
		$this->assertSame( array(), WP_CLI::$logs );
		$this->assertStringNotContainsString( self::SECRET_URL, implode( "\n", WP_CLI::$errors ) );
	}

	/**
	 * Command-specific inputs are rejected before any private state validation.
	 */
	public function test_command_specific_arguments_are_rejected(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options']        = true;
		$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = self::SECRET_URL;
		$command = new Digitalogic_Human_Contacts_CLI();

		$command->list_( array( 'unexpected' ), array( 'format' => 'json' ) );
		$command->get( array( 'david', 'unexpected' ), array() );

		$this->assertSame(
			array(
				'This command does not accept command-specific arguments.',
				'Exactly one person is required; command-specific options are not accepted.',
			),
			WP_CLI::$errors
		);
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/**
	 * List output is reconstructed only from bounded public fields.
	 */
	public function test_list_emits_only_the_exact_safe_projection(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options']        = true;
		$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = array(
			'david' => self::valid_record(),
		);
		$command = new Digitalogic_Human_Contacts_CLI();

		$command->list_( array(), array() );

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 1, WP_CLI::$logs );
		$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
		$this->assertCount( 1, $output );
		$this->assertSame(
			array(
				'person',
				'name_aliases',
				'wordpress_linked',
				'channel_aliases',
				'preferred_language',
				'timezone',
				'preferences',
				'availability_observation',
				'known_fields',
				'phone_candidate_count',
				'extension_recorded',
				'missing_routes',
				'note',
			),
			array_keys( $output[0] )
		);
		$this->assertSame( true, $output[0]['wordpress_linked'] );
		$this->assertArrayNotHasKey( 'wp_user_id', $output[0] );
		$this->assertSame( array( 'first_name', 'billing_phone' ), $output[0]['known_fields'] );
		$this->assertSame( 2, $output[0]['phone_candidate_count'] );
		$this->assertSame( true, $output[0]['extension_recorded'] );
		$this->assertArrayNotHasKey( 'mobile_alias', $output[0]['channel_aliases'] );
		$this->assertContains( 'mobile', $output[0]['missing_routes'] );
		$this->assertSame( false, $output[0]['preferences']['repeated_redial'] );
		$this->assertArrayNotHasKey( 'future_preference', $output[0]['preferences'] );
		$this->assertSame(
			array(
				'state'              => 'busy',
				'observed_at'        => '2026-09-13T10:30:00+03:30',
				'point_in_time_only' => true,
			),
			$output[0]['availability_observation']
		);

		$rendered = WP_CLI::$logs[0];
		$this->assertStringNotContainsString( self::SECRET_ROUTE, $rendered );
		$this->assertStringNotContainsString( self::SECRET_URL, $rendered );
		$this->assertStringNotContainsString( 'private_future_field', $rendered );
		$this->assertStringNotContainsString( 'numeric_private_peer', $rendered );
		$this->assertStringContainsString( 'point-in-time and may be stale', $output[0]['note'] );
	}

	/**
	 * Every controlled state supports a valid Zulu observation time.
	 */
	public function test_controlled_availability_states_are_point_in_time_only(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$command = new Digitalogic_Human_Contacts_CLI();

		foreach ( array( 'available', 'busy', 'unavailable' ) as $state ) {
			$record                             = self::valid_record();
			$record['availability_observation'] = array(
				'state'       => $state,
				'observed_at' => '2026-09-13T07:00:00Z',
			);
			$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = array(
				'david' => $record,
			);
			$GLOBALS['digitalogic_test_option_cache']                          = array();
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();

			$command->list_( array(), array() );

			$this->assertSame( array(), WP_CLI::$errors, $state );
			$this->assertCount( 1, WP_CLI::$logs, $state );
			$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
			$this->assertSame(
				array(
					'state'              => $state,
					'observed_at'        => '2026-09-13T07:00:00Z',
					'point_in_time_only' => true,
				),
				$output[0]['availability_observation'],
				$state
			);
		}
	}

	/**
	 * Missing, uncontrolled, or impossible observations become null.
	 */
	public function test_invalid_availability_observation_is_omitted(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$cases   = array(
			'missing'           => null,
			'not_array'         => self::SECRET_URL,
			'unknown_state'     => array(
				'state'       => 'permanently_busy',
				'observed_at' => '2026-09-13T07:00:00Z',
			),
			'impossible_date'   => array(
				'state'       => 'busy',
				'observed_at' => '2026-02-31T07:00:00Z',
			),
			'invalid_time'      => array(
				'state'       => 'busy',
				'observed_at' => '2026-09-13T24:00:00Z',
			),
			'invalid_minute'    => array(
				'state'       => 'busy',
				'observed_at' => '2026-09-13T07:60:00Z',
			),
			'invalid_second'    => array(
				'state'       => 'busy',
				'observed_at' => '2026-09-13T07:00:60Z',
			),
			'invalid_offset'    => array(
				'state'       => 'busy',
				'observed_at' => '2026-09-13T07:00:00+14:01',
			),
			'fractional_second' => array(
				'state'       => 'busy',
				'observed_at' => '2026-09-13T07:00:00.123Z',
			),
		);
		$command = new Digitalogic_Human_Contacts_CLI();

		foreach ( $cases as $name => $observation ) {
			$record = self::valid_record();
			if ( null === $observation ) {
				unset( $record['availability_observation'] );
			} else {
				$record['availability_observation'] = is_array( $observation )
					? array_merge( $observation, array( 'private' => self::SECRET_URL ) )
					: $observation;
			}
			$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = array(
				'david' => $record,
			);
			$GLOBALS['digitalogic_test_option_cache']                          = array();
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();

			$command->list_( array(), array() );

			$this->assertSame( array(), WP_CLI::$errors, $name );
			$this->assertCount( 1, WP_CLI::$logs, $name );
			$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
			$this->assertNull( $output[0]['availability_observation'], $name );
			$this->assertStringNotContainsString( self::SECRET_URL, WP_CLI::$logs[0], $name );
		}
	}

	/**
	 * A Persian alias resolves without exposing the other records.
	 */
	public function test_get_resolves_one_persian_alias(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$first                   = self::valid_record();
		$first['name_aliases'][] = 'کارشناس';
		$second                  = self::valid_record();
		$second['name_aliases']  = array( 'Second Person' );
		$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = array(
			'david'  => $first,
			'shokri' => $second,
		);
		$command = new Digitalogic_Human_Contacts_CLI();

		$command->get( array( 'کارشناس' ), array() );

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 1, WP_CLI::$logs );
		$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'david', $output['person'] );
	}

	/**
	 * An ambiguous safe name returns no record.
	 */
	public function test_get_fails_closed_for_an_ambiguous_name(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$first                  = self::valid_record();
		$first['name_aliases']  = array( 'Shared Name' );
		$second                 = self::valid_record();
		$second['name_aliases'] = array( 'Shared Name' );
		$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = array(
			'david'  => $first,
			'shokri' => $second,
		);
		$command = new Digitalogic_Human_Contacts_CLI();

		$command->get( array( 'Shared Name' ), array() );

		$this->assertSame( array( 'Person not found or ambiguous; inspect contacts list.' ), WP_CLI::$errors );
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/**
	 * Malformed nested values return one generic error without warnings or output.
	 */
	public function test_malformed_registry_shapes_fail_closed(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$cases   = array(
			'not_array'        => self::SECRET_URL,
			'too_many_records' => array_fill( 0, 33, self::valid_record() ),
			'bad_record'       => array( 'david' => self::SECRET_URL ),
			'bad_names'        => array( 'david' => array_merge( self::valid_record(), array( 'name_aliases' => self::SECRET_URL ) ) ),
			'too_many_names'   => array( 'david' => array_merge( self::valid_record(), array( 'name_aliases' => array_fill( 0, 13, 'Safe Name' ) ) ) ),
			'bad_phone_hints'  => array( 'david' => array_merge( self::valid_record(), array( 'phone_candidates' => self::SECRET_ROUTE ) ) ),
			'too_many_hints'   => array( 'david' => array_merge( self::valid_record(), array( 'phone_candidates' => array_fill( 0, 21, self::SECRET_ROUTE ) ) ) ),
			'bad_fields'       => array( 'david' => array_merge( self::valid_record(), array( 'wordpress_contact_fields' => self::SECRET_URL ) ) ),
			'bad_preferences'  => array( 'david' => array_merge( self::valid_record(), array( 'contact_preferences' => self::SECRET_URL ) ) ),
			'bad_wordpress_id' => array( 'david' => array_merge( self::valid_record(), array( 'wp_user_id' => self::SECRET_ROUTE ) ) ),
		);
		$command = new Digitalogic_Human_Contacts_CLI();

		foreach ( $cases as $name => $registry ) {
			$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = $registry;
			$GLOBALS['digitalogic_test_option_cache']                          = array();
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();

			$command->list_( array(), array() );

			$this->assertSame( array( self::GENERIC_ERROR ), WP_CLI::$errors, $name );
			$this->assertSame( array(), WP_CLI::$logs, $name );
			$this->assertStringNotContainsString(
				self::SECRET_ROUTE,
				implode( "\n", array_merge( WP_CLI::$errors, WP_CLI::$logs ) ),
				$name
			);
		}
	}

	/**
	 * Unsafe registry identifiers and name-like route values are never emitted.
	 */
	public function test_unsafe_identity_slots_fail_closed_without_echo(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$unsafe_name                 = self::valid_record();
		$unsafe_name['name_aliases'] = array( self::SECRET_ROUTE );
		$cases                       = array(
			'unsafe_person' => array( 'a' . self::SECRET_ROUTE => self::valid_record() ),
			'unsafe_name'   => array( 'david' => $unsafe_name ),
		);
		$command                     = new Digitalogic_Human_Contacts_CLI();

		foreach ( $cases as $name => $registry ) {
			$GLOBALS['digitalogic_test_options']['digitalogic_human_contacts'] = $registry;
			$GLOBALS['digitalogic_test_option_cache']                          = array();
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();

			$command->list_( array(), array() );

			$this->assertSame( array( self::GENERIC_ERROR ), WP_CLI::$errors, $name );
			$this->assertSame( array(), WP_CLI::$logs, $name );
			$this->assertStringNotContainsString( self::SECRET_ROUTE, implode( "\n", WP_CLI::$errors ), $name );
		}
	}

	/**
	 * The adapter remains read-only and has no REST or action surface.
	 */
	public function test_source_has_no_registry_write_rest_or_contact_action(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This is a local source fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/ops/wordpress-mu/digitalogic-human-contacts.php' );
		$this->assertIsString( $source );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:add|update|delete)_option\s*\(/', $source );
		$this->assertStringNotContainsString( 'register_rest_route', $source );
		$this->assertStringNotContainsString( 'wp_remote_', $source );
		$this->assertStringNotContainsString( 'do_action', $source );
		$this->assertDoesNotMatchRegularExpression( '/(?<![0-9])(?:\+?98|0)?9[0-9]{9}(?![0-9])/', $source );
		$this->assertDoesNotMatchRegularExpression( '/\bsips?:/i', $source );
		$this->assertDoesNotMatchRegularExpression( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $source );
	}

	/**
	 * Build one synthetic private record containing deliberate secret sentinels.
	 *
	 * @return array
	 */
	private static function valid_record() {
		return array(
			'name_aliases'             => array( 'David', 'دیوید' ),
			'wp_user_id'               => 11,
			'pbx_alias'                => 'david_extension',
			'mobile_alias'             => 'a' . self::SECRET_ROUTE,
			'sms_alias'                => 'david_sms',
			'telegram_alias'           => 'n8n_private',
			'email_alias'              => 'david_email',
			'preferred_language'       => 'en',
			'availability_observation' => array(
				'state'       => 'busy',
				'observed_at' => '2026-09-13T10:30:00+03:30',
				'private'     => self::SECRET_URL,
			),
			'contact_preferences'      => array(
				'quick_questions'       => 'telegram',
				'technical_or_critical' => 'refer_to_shokri',
				'future_preference'     => self::SECRET_URL,
			),
			'wordpress_contact_fields' => array(
				'billing_phone'        => self::SECRET_ROUTE,
				'first_name'           => 'Private Value',
				'private_future_field' => self::SECRET_URL,
			),
			'phone_candidates'         => array( self::SECRET_ROUTE, self::SECRET_URL ),
			'pbx_extension'            => self::SECRET_ROUTE,
			'numeric_private_peer'     => self::SECRET_ROUTE,
			'credential'               => self::SECRET_URL,
		);
	}
}
