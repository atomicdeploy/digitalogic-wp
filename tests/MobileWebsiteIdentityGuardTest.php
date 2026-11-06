<?php
/**
 * Pre-insert normalized registration collision fixtures without creating users.
 *
 * @package Digitalogic
 */

// phpcs:disable Universal.Namespaces -- Isolated namespaced wp_die double must not alter global WordPress behavior.
namespace Digitalogic\Laravel\Services {
	/**
	 * Replace only this service namespace's abort function for isolated tests.
	 *
	 * @param mixed $failure Safe structured failure.
	 * @param mixed $title Display title.
	 * @param array $args HTTP response arguments.
	 * @throws \RuntimeException To model WordPress's terminating response.
	 */
	function wp_die( $failure, $title, $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Namespaced test fixture only.
		$GLOBALS['website_guard_test_abort'] = array( $failure, $args );
		if ( empty( $GLOBALS['website_guard_test_return'] ) ) {
			throw new \RuntimeException( 'fixture registration aborted' );
		}
	}
}

namespace {
	use Digitalogic\Laravel\Services\MobileWebsiteIdentityGuard as Guard;
	use PHPUnit\Framework\TestCase;

	// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile -- Named PHPUnit class with a private in-memory database fixture.
	require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
	require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityResolver.php';
	require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileWebsiteIdentityGuard.php';

	// phpcs:disable -- Test-only SQL fixture is intentionally compact and has no mutations.
	final class Digitalogic_WebsiteIdentityGuard_Fixture_DB {
		public string $users = 'fixture_users', $usermeta = 'fixture_usermeta', $last_error = '';
		public array $emails = [], $phones = [], $queries = [];
		public bool $unavailable = false;
		public function get_results($sql, $format) {
			$this->queries[] = $sql;
			$this->last_error = $this->unavailable ? 'fixture unavailable' : '';
			if ($this->unavailable) return null;
			return str_contains($sql, 'user_email') ? $this->emails : $this->phones;
		}
		public function __call($name, $arguments) { throw new LogicException('Unexpected mutation or identity lookup.'); }
	}
	// phpcs:enable
	// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile -- Named PHPUnit class and isolated fixture.
	// phpcs:disable Universal.Namespaces, WordPress.Security.NonceVerification, WordPress.DB.SlowDBQuery -- Namespaced abort double and literal metadata/request fixtures only; no live form or database operations.

	/** Guards are discovery-only and cannot grant any existing account authority. */
	final class MobileWebsiteIdentityGuardTest extends TestCase {
		/**
		 * Fixture-only lookup store.
		 *
		 * @var Digitalogic_WebsiteIdentityGuard_Fixture_DB
		 */
		private Digitalogic_WebsiteIdentityGuard_Fixture_DB $database;
		/**
		 * Original globals restored after each fixture.
		 *
		 * @var array
		 */
		private array $saved;

		/** Isolate all database, metadata, filters and request fixtures. */
		protected function setUp(): void {
			$this->saved                           = array(
				'wpdb'    => $GLOBALS['wpdb'],
				'meta'    => $GLOBALS['digitalogic_test_user_meta'],
				'filters' => $GLOBALS['digitalogic_test_filters'],
				'post'    => $_POST,
				'server'  => $_SERVER,
			);
			$this->database                        = new Digitalogic_WebsiteIdentityGuard_Fixture_DB();
			$GLOBALS['wpdb']                       = $this->database;
			$GLOBALS['digitalogic_test_user_meta'] = array();
			$GLOBALS['website_guard_test_abort']   = null;
			$GLOBALS['website_guard_test_return']  = false;
			$_POST                                 = array();
			$_SERVER['REQUEST_METHOD']             = 'POST';
		}

		/** Restore the shared PHPUnit environment without retaining fixture identities. */
		protected function tearDown(): void {
			$GLOBALS['wpdb']                       = $this->saved['wpdb'];
			$GLOBALS['digitalogic_test_user_meta'] = $this->saved['meta'];
			$GLOBALS['digitalogic_test_filters']   = $this->saved['filters'];
			$_POST                                 = $this->saved['post'];
			$_SERVER                               = $this->saved['server'];
			unset( $GLOBALS['website_guard_test_abort'], $GLOBALS['website_guard_test_return'] );
		}

		/**
		 * Verify safe rejection without exposing matched identity IDs.
		 *
		 * @param string $code Expected code.
		 * @param mixed  $result Actual validation result.
		 */
		private function errorIs( string $code, $result ): void {
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( $code, $result->get_error_code() );
			self::assertSame( array( 'status' ), array_keys( $result->get_error_data() ) );
		}

		/** Case, Gmail dots/plus and googlemail spelling all resolve existing candidates. */
		public function test_normalized_email_duplicate_blocks_without_authenticating_or_exposing_owner(): void {
			$this->database->emails = array(
				array(
					'ID'         => 72,
					'user_email' => 'existing.person@gmail.com',
				),
			);
			foreach ( array( 'EXISTING.PERSON@GMAIL.COM', 'existingperson+shop@gmail.com', 'existing.person@googlemail.com' ) as $email ) {
				$this->errorIs( 'identity_email_exists', Guard::validateNewUser( array( 'user_email' => $email ) ) );
			}
			self::assertCount( 1, $this->database->emails );
		}

		/** Canonical Digits representations are compared; billing contacts are not identities. */
		public function test_normalized_authoritative_phone_blocks_but_billing_phone_does_not(): void {
			$this->database->phones = array(
				array(
					'user_id'    => 73,
					'meta_key'   => 'digits_phone',
					'meta_value' => '۰۹۱۲۳۴۵۶۷۸۹',
				),
			);
			foreach ( array( '+989123456789', '00989123456789', '0912 345 6789', '9123456789' ) as $phone ) {
				$this->errorIs( 'identity_phone_exists', Guard::validateNewUser( array( 'meta_input' => array( 'digits_phone_no' => $phone ) ) ) );
			}
			self::assertNull( Guard::validateNewUser( array( 'meta_input' => array( 'billing_phone' => '+989123456789' ) ) ) );
		}

		/** Contradictory incoming fields and ambiguous stored owners never create a new user. */
		public function test_conflicting_phone_fields_or_stored_owners_fail_closed(): void {
			foreach ( array(
				array(
					'digits_phone'    => '+989123456789',
					'digits_phone_no' => '9123456788',
				),
				array(
					'digits_phone'     => '+989123456789',
					'digt_countrycode' => '+44',
				),
				array( 'digits_phone' => array( 'invalid' ) ),
			) as $meta ) {
				$this->errorIs( 'identity_phone_conflict', Guard::validateNewUser( array( 'meta_input' => $meta ) ) );
			}
			$this->database->phones = array(
				array(
					'user_id'    => 73,
					'meta_key'   => 'digits_phone',
					'meta_value' => '+989123456789',
				),
				array(
					'user_id'    => 74,
					'meta_key'   => 'digits_phone',
					'meta_value' => '09123456789',
				),
			);
			$this->errorIs( 'identity_phone_conflict', Guard::validateNewUser( array( 'meta_input' => array( 'digits_phone' => '+989123456789' ) ) ) );
		}

		/** New unique identities pass unchanged; absence alone never triggers a creation here. */
		public function test_unique_or_empty_identity_leaves_creation_data_unchanged(): void {
			$data = array(
				'user_email'   => 'new@example.test',
				'display_name' => 'fixture',
			);
			$raw  = array(
				'meta_input' => array(
					'digits_phone'     => '+989123456789',
					'digits_phone_no'  => '9123456789',
					'digt_countrycode' => '+۹۸',
				),
			);
			self::assertSame( $data, Guard::preInsert( $data, false, null, $raw ) );
			self::assertNull( Guard::validateNewUser( array() ) );
			self::assertNull( $GLOBALS['website_guard_test_abort'] );
		}

		/** Core executes this filter before INSERT; an explicit abort prevents that statement. */
		public function test_collision_aborts_before_simulated_core_insert_and_uses_final_email(): void {
			$this->database->emails = array(
				array(
					'ID'         => 72,
					'user_email' => 'existing.person@gmail.com',
				),
			);
			$inserts                = 0;
			try {
				Guard::preInsert( array( 'user_email' => 'existingperson+new@gmail.com' ), false, null, array( 'user_email' => 'other@example.test' ) );
				++$inserts;
				self::fail( 'Core INSERT must not be reached.' );
			} catch ( \RuntimeException $error ) {
				self::assertSame( 'fixture registration aborted', $error->getMessage() );
			}
			self::assertSame( 0, $inserts );
			self::assertSame( 409, $GLOBALS['website_guard_test_abort'][1]['response'] );
			$this->errorIs( 'identity_email_exists', $GLOBALS['website_guard_test_abort'][0] );
		}

		/** Even a nonstandard returning wp_die handler cannot let core insert duplicate data. */
		public function test_returning_abort_handler_still_produces_core_empty_data_rejection(): void {
			$this->database->emails               = array(
				array(
					'ID'         => 72,
					'user_email' => 'old@example.test',
				),
			);
			$GLOBALS['website_guard_test_return'] = true;
			self::assertSame( array(), Guard::preInsert( array( 'user_email' => 'old@example.test' ), false, null, array() ) );
		}

		/** Existing profile edits are intentionally outside a create-only guard. */
		public function test_updates_are_preserved_without_identity_queries(): void {
			$data = array(
				'user_email'   => 'existing@example.test',
				'display_name' => 'updated fixture',
			);
			self::assertSame( $data, Guard::preInsert( $data, true, 72, array( 'meta_input' => 'not inspected for updates' ) ) );
			self::assertSame( array(), $this->database->queries );
		}

		/** Broken lookup cannot turn an unknown identity into a new one. */
		public function test_lookup_failure_rejects_creation_with_no_partial_mutations(): void {
			$this->database->unavailable = true;
			$this->errorIs( 'identity_lookup_unavailable', Guard::validateNewUser( array( 'user_email' => 'new@example.test' ) ) );
			$this->errorIs( 'identity_phone_conflict', Guard::validateNewUser( array( 'meta_input' => array( 'digits_phone' => '+989123456789' ) ) ) );
		}

		/** Provider errors survive; nice registration paths receive a regular WP_Error. */
		public function test_registration_error_filters_preserve_existing_errors(): void {
			$existing = new \WP_Error( 'provider_error', 'fixture validation' );
			self::assertSame( $existing, Guard::registrationErrors( $existing, 'fixture', 'anything@example.test' ) );
			self::assertSame( array(), $this->database->queries );
			$this->database->emails = array(
				array(
					'ID'         => 72,
					'user_email' => 'old@example.test',
				),
			);
			$this->errorIs( 'identity_email_exists', Guard::registrationErrors( new \WP_Error(), 'fixture', 'old@example.test' ) );
		}

		/** Raw request fields are never treated as authoritative provider metadata. */
		public function test_registration_does_not_hook_auth_discovery_or_guess_posted_phone_fields(): void {
			$GLOBALS['digitalogic_test_filters'] = array();
			Guard::register();
			self::assertSame( array( 'registration_errors', 'woocommerce_registration_errors', 'wp_pre_insert_user_data' ), array_keys( $GLOBALS['digitalogic_test_filters'] ) );
			self::assertSame( 4, $GLOBALS['digitalogic_test_filters']['wp_pre_insert_user_data'][0]['accepted_args'] );
			$_POST = array(
				'digits_phone' => '+989123456789',
				'user_email'   => 'existing@example.test',
			);
			self::assertNull( Guard::validateNewUser( array() ) );
			self::assertSame( array(), $this->database->queries );
		}

		/** Observed Digits signup can only veto a new core insert without late metadata. */
		public function test_observed_signup_hint_vetoes_duplicate_without_canonical_meta_input(): void {
			$this->database->phones               = array(
				array(
					'user_id'    => 73,
					'meta_key'   => 'digits_phone',
					'meta_value' => '+989123456789',
				),
			);
			$GLOBALS['website_guard_test_return'] = true;
			foreach ( array( '۰۹۱۲ ۳۴۵ ۶۷۸۹', '9123456789', '+989123456789' ) as $phone ) {
				$_POST = array(
					'action'           => 'digits_forms_ajax',
					'type'             => 'register',
					'digits'           => '1',
					'phone'            => $phone,
					'digt_countrycode' => '+۹۸',
				);
				self::assertSame( array(), Guard::preInsert( array( 'user_email' => '' ), false, null, array() ) );
				$this->errorIs( 'identity_phone_exists', $GLOBALS['website_guard_test_abort'][0] );
				self::assertSame( 409, $GLOBALS['website_guard_test_abort'][1]['response'] );
			}
			// The hint never becomes canonical metadata and cannot grant a session.
			self::assertSame( array(), $GLOBALS['digitalogic_test_user_meta'] );
		}

		/** Wrong or non-scalar signatures, login requests and updates are untouched. */
		public function test_hint_requires_exact_signup_signature_and_new_insert(): void {
			$signup = array(
				'action'           => 'digits_forms_ajax',
				'type'             => 'register',
				'digits'           => '1',
				'phone'            => '09123456789',
				'digt_countrycode' => '+98',
			);
			$data   = array( 'user_email' => '' );
			foreach ( array( array( 'action', 'digits_forms_ajax_extra' ), array( 'action', array( 'digits_forms_ajax' ) ), array( 'type', 'login' ), array( 'type', array( 'register' ) ), array( 'digits', 1 ), array( 'digits', array( '1' ) ) ) as $change ) {
				$_POST = array_replace( $signup, array( $change[0] => $change[1] ) );
				self::assertSame( $data, Guard::preInsert( $data, false, null, array() ) );
			}
			$_POST                     = $signup;
			$_SERVER['REQUEST_METHOD'] = 'GET';
			self::assertSame( $data, Guard::preInsert( $data, false, null, array() ) );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			self::assertSame( $data, Guard::preInsert( $data, true, 73, array() ) );
			self::assertSame( array(), $this->database->queries );
		}

		/** Missing, structured, malformed or unsupported-country hints cannot bypass validation. */
		public function test_exact_signup_missing_or_malformed_hint_is_rejected(): void {
			$signup                               = array(
				'action'           => 'digits_forms_ajax',
				'type'             => 'register',
				'digits'           => '1',
				'phone'            => '09123456789',
				'digt_countrycode' => '+98',
			);
			$GLOBALS['website_guard_test_return'] = true;
			foreach ( array( array( 'phone', null ), array( 'phone', array( '09123456789' ) ), array( 'phone', 'not-a-phone' ), array( 'phone', '' ), array( 'digt_countrycode', null ), array( 'digt_countrycode', array( '+98' ) ), array( 'digt_countrycode', '+44' ) ) as $change ) {
				$_POST = array_replace( $signup, array( $change[0] => $change[1] ) );
				self::assertSame( array(), Guard::preInsert( array( 'user_email' => '' ), false, null, array() ) );
				$this->errorIs( 'invalid_registration_identity', $GLOBALS['website_guard_test_abort'][0] );
				self::assertSame( 422, $GLOBALS['website_guard_test_abort'][1]['response'] );
			}
			self::assertSame( array(), $this->database->queries );
		}

		/** A unique valid hint leaves the caller data and empty metadata exactly unchanged. */
		public function test_unique_signup_hint_does_not_populate_metadata_or_authenticate(): void {
			$_POST = array(
				'action'           => 'digits_forms_ajax',
				'type'             => 'register',
				'digits'           => '1',
				'phone'            => '09123456789',
				'digt_countrycode' => '+98',
			);
			$data  = array(
				'user_email'   => '',
				'display_name' => 'fixture',
			);
			self::assertSame( $data, Guard::preInsert( $data, false, null, array() ) );
			self::assertSame( array(), $GLOBALS['digitalogic_test_user_meta'] );
			self::assertNull( $GLOBALS['website_guard_test_abort'] );
			self::assertCount( 1, $this->database->queries );
		}
	}
}
