<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Preserve the published integration filename.
/**
 * Inbound-call phone verification and supplemental contact points.
 *
 * @package Digitalogic
 */

// PHPCS cannot infer the safety of the reviewed plugin-table SQL, custom capabilities,
// binary-secret encoding, bounded request validation, or legacy integration filenames.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.WP.Capabilities.Unknown,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.WP.I18n.MissingTranslatorsComment,WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable Squiz.Commenting.FileComment.MissingPackageTag,WordPress.Files.FileName.InvalidClassFileName,Generic.Commenting.DocComment.MissingShort
// phpcs:disable Universal.Operators.DisallowShortTernary.Found,WordPress.PHP.YodaConditions.NotYoda

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns call-verification challenges, the signed PBX callback, and contact UI.
 */
final class Digitalogic_Call_Verification {

	private const REST_NAMESPACE        = 'digitalogic/v1';
	private const ROUTE                 = '/call-verification';
	private const PBX_ROUTE             = '/call-verification/pbx-confirm';
	private const PBX_CANONICAL_PATH    = '/wp-json/digitalogic/v1/call-verification/pbx-confirm';
	private const PBX_KEY_ID            = 'v1';
	private const SITE_ID               = 'digitalogic.ir';
	private const ACCESS_DID            = '+982166754123';
	private const DIAL_DISPLAY          = '021-66754123';
	private const DIAL_TEL              = '+982166754123';
	private const IVR_OPTION            = '2';
	private const CHALLENGE_TTL         = 600;
	private const CONSUME_TTL           = 120;
	private const COOKIE_NAME           = 'digitalogic_call_binding';
	private const CSRF_HEADER           = 'x-digitalogic-csrf';
	private const PBX_SKEW_SECONDS      = 60;
	private const MAX_ACTIVE_PER_PHONE  = 3;
	private const MAX_CONTACTS_PER_KIND = 10;
	private const CLEANUP_ACTION        = 'digitalogic_pbx_cleanup';

	/** @var self|null */
	private static $instance = null;

	/** @var bool|null In-request result of the most recent schema installation. */
	private static $schema_health = null;

	/**
	 * Singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_no_store_headers' ), 10, 3 );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ), 40 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_account_assets' ), 40 );
		add_action( 'login_footer', array( $this, 'render_login_verification' ) );
		add_action( 'woocommerce_after_edit_account_form', array( $this, 'render_account_contacts' ) );
		add_action( 'show_user_profile', array( $this, 'render_admin_contacts' ) );
		add_action( 'edit_user_profile', array( $this, 'render_admin_contacts' ) );
		add_action( 'personal_options_update', array( $this, 'save_admin_contacts' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_admin_contacts' ) );
		add_action( 'deleted_user', array( $this, 'cleanup_deleted_user' ), 10, 1 );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( self::CLEANUP_ACTION, array( $this, 'cleanup_retention' ) );
		add_action( 'admin_notices', array( $this, 'configuration_notice' ) );
	}

	/**
	 * Install and verify dedicated security and contact tables.
	 *
	 * @return bool Whether every table and the cleanup schedule are ready.
	 */
	public static function install(): bool {
		global $wpdb;
		self::$schema_health = false;

		$collate    = $wpdb->get_charset_collate();
		$contacts   = self::table( 'contact_points' );
		$audit      = self::table( 'contact_consent_audit' );
		$challenges = self::table( 'phone_challenges' );
		$rates      = self::table( 'verification_rates' );
		$nonces     = self::table( 'pbx_nonces' );
		$events     = self::table( 'pbx_events' );

		$sql = array(
			"CREATE TABLE $contacts (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id bigint(20) UNSIGNED NOT NULL,
				kind varchar(10) NOT NULL,
				value_encrypted longtext NOT NULL,
				value_hash char(64) NOT NULL,
				label varchar(100) NOT NULL DEFAULT '',
				is_primary tinyint(1) NOT NULL DEFAULT 0,
				login_enabled tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'unverified',
				verification_method varchar(30) NOT NULL DEFAULT '',
				verified_at datetime DEFAULT NULL,
				voice_opt_in tinyint(1) NOT NULL DEFAULT 0,
				voice_events longtext DEFAULT NULL,
				admin_suppressed tinyint(1) NOT NULL DEFAULT 0,
				consent_actor_id bigint(20) UNSIGNED DEFAULT NULL,
				consent_source varchar(100) NOT NULL DEFAULT '',
				consented_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_contact (user_id,kind,value_hash),
				KEY value_lookup (kind,value_hash,status),
				KEY user_kind_status (user_id,kind,status)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $audit (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				contact_id bigint(20) UNSIGNED NOT NULL,
				user_id bigint(20) UNSIGNED NOT NULL,
				actor_id bigint(20) UNSIGNED NOT NULL,
				actor_type varchar(20) NOT NULL,
				old_voice_opt_in tinyint(1) NOT NULL,
				new_voice_opt_in tinyint(1) NOT NULL,
				old_admin_suppressed tinyint(1) NOT NULL,
				new_admin_suppressed tinyint(1) NOT NULL,
				old_voice_events longtext NOT NULL,
				new_voice_events longtext NOT NULL,
				reason varchar(255) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY contact_created (contact_id,created_at),
				KEY user_created (user_id,created_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $challenges (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id char(36) NOT NULL,
				purpose varchar(30) NOT NULL,
				user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				contact_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
				phone_encrypted longtext NOT NULL,
				phone_hash char(64) NOT NULL,
				code_mac char(64) NOT NULL,
				binding_mac char(64) NOT NULL,
				csrf_mac char(64) NOT NULL,
				request_ip_hash char(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				failed_attempts smallint UNSIGNED NOT NULL DEFAULT 0,
				verified_call_hash char(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				verified_at datetime DEFAULT NULL,
				consume_deadline datetime DEFAULT NULL,
				consumed_at datetime DEFAULT NULL,
				version int UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY pending_phone (phone_hash,status,expires_at),
				KEY user_purpose (user_id,purpose,status)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $rates (
				bucket_key char(64) NOT NULL,
				bucket_name varchar(50) NOT NULL,
				window_started datetime NOT NULL,
				counter int UNSIGNED NOT NULL DEFAULT 0,
				lock_until datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (bucket_key,bucket_name),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $nonces (
				nonce_hash char(64) NOT NULL,
				key_id varchar(30) NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (nonce_hash),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $events (
				event_id varchar(100) NOT NULL,
				call_hash char(64) NOT NULL,
				result_json longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				KEY created_at (created_at)
			) ENGINE=InnoDB $collate;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$requirements = array(
			$contacts   => array(
				'columns' => array( 'id', 'user_id', 'kind', 'value_encrypted', 'value_hash', 'label', 'is_primary', 'login_enabled', 'status', 'verification_method', 'verified_at', 'voice_opt_in', 'voice_events', 'admin_suppressed', 'consent_actor_id', 'consent_source', 'consented_at', 'created_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'user_contact', 'value_lookup', 'user_kind_status' ),
			),
			$audit      => array(
				'columns' => array( 'id', 'contact_id', 'user_id', 'actor_id', 'actor_type', 'old_voice_opt_in', 'new_voice_opt_in', 'old_admin_suppressed', 'new_admin_suppressed', 'old_voice_events', 'new_voice_events', 'reason', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'contact_created', 'user_created' ),
			),
			$challenges => array(
				'columns' => array( 'id', 'public_id', 'purpose', 'user_id', 'contact_id', 'phone_encrypted', 'phone_hash', 'code_mac', 'binding_mac', 'csrf_mac', 'request_ip_hash', 'status', 'failed_attempts', 'verified_call_hash', 'created_at', 'expires_at', 'verified_at', 'consume_deadline', 'consumed_at', 'version' ),
				'indexes' => array( 'PRIMARY', 'public_id', 'pending_phone', 'user_purpose' ),
			),
			$rates      => array(
				'columns' => array( 'bucket_key', 'bucket_name', 'window_started', 'counter', 'lock_until', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'updated_at' ),
			),
			$nonces     => array(
				'columns' => array( 'nonce_hash', 'key_id', 'created_at', 'expires_at' ),
				'indexes' => array( 'PRIMARY', 'expires_at' ),
			),
			$events     => array(
				'columns' => array( 'event_id', 'call_hash', 'result_json', 'created_at' ),
				'indexes' => array( 'PRIMARY', 'created_at' ),
			),
		);
		foreach ( $requirements as $table => $required ) {
			if ( ! self::verify_storage_table( $table, $required['columns'], $required['indexes'] ) ) {
				return false;
			}
		}
		if ( ! self::ensure_cleanup_schedule() ) {
			return false;
		}
		self::$schema_health = true;
		return true;
	}

	/**
	 * Verify table presence, required names, and transactional storage.
	 *
	 * @param string $table            Full table name.
	 * @param array  $required_columns Required column names.
	 * @param array  $required_indexes Required index names.
	 * @return bool
	 */
	private static function verify_storage_table( string $table, array $required_columns, array $required_indexes ): bool {
		global $wpdb;
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		if ( ! is_array( $status ) ) {
			return false;
		}
		if ( 'INNODB' !== strtoupper( (string) ( $status['Engine'] ?? '' ) ) ) {
			if ( false === $wpdb->query( 'ALTER TABLE ' . $quoted . ' ENGINE=InnoDB' ) ) {
				return false;
			}
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( ! is_array( $status ) || 'INNODB' !== strtoupper( (string) ( $status['Engine'] ?? '' ) ) ) {
				return false;
			}
		}
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $quoted, 0 );
		if ( ! is_array( $columns ) || ! empty( array_diff( $required_columns, $columns ) ) ) {
			return false;
		}
		$index_rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $quoted, ARRAY_A );
		if ( ! is_array( $index_rows ) ) {
			return false;
		}
		$indexes = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $row ): string {
							return is_array( $row ) ? (string) ( $row['Key_name'] ?? '' ) : '';
						},
						$index_rows
					)
				)
			)
		);
		return empty( array_diff( $required_indexes, $indexes ) );
	}

	/**
	 * Install and verify the daily retention task.
	 *
	 * @return bool
	 */
	private static function ensure_cleanup_schedule(): bool {
		if ( wp_next_scheduled( self::CLEANUP_ACTION ) ) {
			return true;
		}
		$result = wp_schedule_event( time() + 300, 'daily', self::CLEANUP_ACTION, array(), true );
		return ! is_wp_error( $result ) && ( true === $result || false !== wp_next_scheduled( self::CLEANUP_ACTION ) );
	}

	/**
	 * Whether the verified PBX schema version is usable in this request.
	 *
	 * @return bool
	 */
	public static function is_schema_ready(): bool {
		$expected = defined( 'DIGITALOGIC_PBX_SCHEMA_VERSION' ) ? (string) DIGITALOGIC_PBX_SCHEMA_VERSION : '3';
		return false !== self::$schema_health && $expected === (string) get_option( 'digitalogic_pbx_schema_version', '' );
	}

	/**
	 * Force all PBX features closed after any portion of installation fails.
	 */
	public static function mark_schema_unready(): void {
		self::$schema_health = false;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_challenge' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE . '/(?P<id>[a-f0-9-]{36})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'challenge_status' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'cancel_challenge' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE . '/(?P<id>[a-f0-9-]{36})/consume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'consume_challenge' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::PBX_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pbx_confirm' ),
				'permission_callback' => array( $this, 'authorize_pbx' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rest_contacts' ),
					'permission_callback' => array( $this, 'authorize_user_rest' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'rest_add_email' ),
					'permission_callback' => array( $this, 'authorize_user_rest' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'rest_update_contact' ),
					'permission_callback' => array( $this, 'authorize_user_rest' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'rest_delete_contact' ),
					'permission_callback' => array( $this, 'authorize_user_rest' ),
				),
			)
		);
	}

	/**
	 * Create a bound six-digit challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_challenge( WP_REST_Request $request ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'digitalogic_pbx_config', __( 'Call verification is not configured.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! $this->same_origin( $request ) ) {
			return new WP_Error( 'digitalogic_call_origin', __( 'The request origin is not allowed.', 'digitalogic' ), array( 'status' => 403 ) );
		}

		$purpose = sanitize_key( (string) $request->get_param( 'purpose' ) );
		if ( ! in_array( $purpose, array( 'login', 'add_contact' ), true ) ) {
			return new WP_Error( 'digitalogic_call_purpose', __( 'Unsupported verification purpose.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		if ( 'add_contact' === $purpose && ( $user_id < 1 || ! $this->valid_rest_nonce( $request ) ) ) {
			return new WP_Error( 'digitalogic_call_login_required', __( 'Please sign in before adding a contact.', 'digitalogic' ), array( 'status' => 401 ) );
		}

		$phone = Digitalogic_PBX_Phone::normalize( $request->get_param( 'phone' ) );
		if ( '' === $phone ) {
			return new WP_Error( 'digitalogic_call_phone', __( 'Enter a valid Iranian mobile or landline number with its area code.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$binding = $this->browser_binding();
		if ( is_wp_error( $binding ) ) {
			return $binding;
		}

		$phone_hash = self::lookup_hash( 'phone', $phone );
		if ( 'add_contact' === $purpose ) {
			$capacity = $this->contact_capacity( $user_id, 'phone', $phone_hash );
			if ( is_wp_error( $capacity ) ) {
				return $capacity;
			}
		}
		$binding_mac = self::lookup_hash( 'binding', $binding );
		$ip_hash     = self::lookup_hash( 'ip', $this->request_ip() );
		$rate_error  = $this->enforce_creation_limits( $phone_hash, $binding_mac, $ip_hash );
		if ( is_wp_error( $rate_error ) ) {
			return $rate_error;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$active_value     = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table( 'phone_challenges' ) . " WHERE phone_hash = %s AND status = 'pending' AND expires_at > UTC_TIMESTAMP()",
				$phone_hash
			)
		);
		if ( '' !== (string) $wpdb->last_error || null === $active_value ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$active = (int) $active_value;
		if ( $active >= self::MAX_ACTIVE_PER_PHONE ) {
			return $this->rate_error( 60 );
		}

		$public_id       = wp_generate_uuid4();
		$code            = (string) random_int( 100000, 999999 );
		$csrf            = self::random_token();
		$now             = time();
		$phone_encrypted = self::encrypt( $phone );
		if ( '' === $phone_encrypted ) {
			return new WP_Error( 'digitalogic_call_crypto', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$inserted = $wpdb->insert(
			self::table( 'phone_challenges' ),
			array(
				'public_id'       => $public_id,
				'purpose'         => $purpose,
				'user_id'         => $user_id,
				'contact_id'      => 0,
				'phone_encrypted' => $phone_encrypted,
				'phone_hash'      => $phone_hash,
				'code_mac'        => self::code_mac( $public_id, $code ),
				'binding_mac'     => $binding_mac,
				'csrf_mac'        => self::lookup_hash( 'csrf', $csrf ),
				'request_ip_hash' => $ip_hash,
				'status'          => 'pending',
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'      => gmdate( 'Y-m-d H:i:s', $now + self::CHALLENGE_TTL ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'challenge_id' => $public_id,
				'code'         => $code,
				'csrf_token'   => $csrf,
				'expires_in'   => self::CHALLENGE_TTL,
				'dial'         => array(
					'display' => self::DIAL_DISPLAY,
					'tel'     => self::DIAL_TEL,
				),
				'ivr_option'   => self::IVR_OPTION,
			),
			201
		);
	}

	/**
	 * Return only the state of a browser-bound challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function challenge_status( WP_REST_Request $request ) {
		if ( ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_pbx_schema', __( 'Call verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$row = $this->bound_challenge( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$row = $this->expire_if_needed( $row );
		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => $row->status,
			),
			200
		);
	}

	/**
	 * Cancel a pending challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_challenge( WP_REST_Request $request ) {
		if ( ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_pbx_schema', __( 'Call verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! $this->same_origin( $request ) ) {
			return new WP_Error( 'digitalogic_call_origin', __( 'The request origin is not allowed.', 'digitalogic' ), array( 'status' => 403 ) );
		}
		$row = $this->bound_challenge( $request );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		global $wpdb;
		$cancelled = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'cancelled', version = version + 1 WHERE id = %d AND status = 'pending'",
				$row->id
			)
		);
		if ( false === $cancelled ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Atomically consume a verified challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function consume_challenge( WP_REST_Request $request ) {
		if ( ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_pbx_schema', __( 'Call verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! $this->same_origin( $request ) ) {
			return new WP_Error( 'digitalogic_call_origin', __( 'The request origin is not allowed.', 'digitalogic' ), array( 'status' => 403 ) );
		}
		$bound = $this->bound_challenge( $request );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$consume_rate = $this->rate_hit( 'consume-10m', self::lookup_hash( 'consume', $bound->binding_mac . '|' . $this->request_ip() ), 5, 600 );
		if ( is_wp_error( $consume_rate ) ) {
			return $consume_rate;
		}

		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'phone_challenges' ) . ' WHERE id = %d FOR UPDATE',
				$bound->id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! $row || 'verified' !== $row->status || strtotime( (string) $row->consume_deadline . ' UTC' ) < time() ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_call_not_verified', __( 'The verification is not ready or has expired.', 'digitalogic' ), array( 'status' => 409 ) );
		}

		$phone = self::decrypt( (string) $row->phone_encrypted );
		if ( '' === $phone ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		$user       = null;
		$contact_id = 0;
		if ( 'login' === $row->purpose ) {
			$user = $this->resolve_login_user( $phone, (string) $row->phone_hash );
			if ( is_wp_error( $user ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $user;
			}

			$filtered = $this->authenticate_verified_user( $user );
			if ( is_wp_error( $filtered ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $filtered;
			}
			$user = $filtered;
		} elseif ( 'add_contact' === $row->purpose ) {
			if ( get_current_user_id() !== (int) $row->user_id || ! $this->valid_rest_nonce( $request ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_call_owner', __( 'This verification belongs to another account.', 'digitalogic' ), array( 'status' => 403 ) );
			}
			$contact_id = $this->upsert_verified_phone( (int) $row->user_id, $phone, (string) $row->phone_hash );
			if ( $contact_id < 1 ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_contact_storage', __( 'The verified number could not be saved.', 'digitalogic' ), array( 'status' => 503 ) );
			}
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'consumed', consumed_at = UTC_TIMESTAMP(), version = version + 1 WHERE id = %d AND status = 'verified'",
				$row->id
			)
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_call_consumed', __( 'This verification has already been used.', 'digitalogic' ), array( 'status' => 409 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		if ( $user instanceof WP_User ) {
			wp_set_current_user( $user->ID, $user->user_login );
			wp_set_auth_cookie( $user->ID, false, is_ssl() );
			do_action( 'wp_login', $user->user_login, $user );

			return new WP_REST_Response(
				array(
					'success'       => true,
					'authenticated' => true,
					'redirect_url'  => home_url( '/my-account/' ),
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'contact_id' => $contact_id,
				'reload'     => true,
			),
			200
		);
	}

	/**
	 * Validate the signed PBX request before the callback runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function authorize_pbx( WP_REST_Request $request ) {
		if ( ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_pbx_schema', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$raw_body = $request->get_body();
		if ( '' === $raw_body || strlen( $raw_body ) > 4096 ) {
			return new WP_Error( 'digitalogic_pbx_body', __( 'PBX request body is invalid.', 'digitalogic' ), array( 'status' => 413 ) );
		}
		$content_type = strtolower( trim( (string) $request->get_header( 'content-type' ) ) );
		if ( 1 !== preg_match( '/^application\/json(?:\s*;[^\r\n]*)?$/', $content_type ) ) {
			return new WP_Error( 'digitalogic_pbx_body', __( 'PBX request body is invalid.', 'digitalogic' ), array( 'status' => 415 ) );
		}
		$key_id    = (string) $request->get_header( 'x-pbx-key-id' );
		$timestamp = (string) $request->get_header( 'x-pbx-timestamp' );
		$nonce     = (string) $request->get_header( 'x-pbx-nonce' );
		$signature = (string) $request->get_header( 'x-pbx-signature' );
		if ( self::PBX_KEY_ID !== $key_id || 1 !== preg_match( '/^[0-9]{10}$/', $timestamp ) || abs( time() - (int) $timestamp ) > self::PBX_SKEW_SECONDS ) {
			return new WP_Error( 'digitalogic_pbx_auth', __( 'PBX authentication failed.', 'digitalogic' ), array( 'status' => 401 ) );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{32,128}$/', $nonce ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'digitalogic_pbx_auth', __( 'PBX authentication failed.', 'digitalogic' ), array( 'status' => 401 ) );
		}

		$secret = self::pbx_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'digitalogic_pbx_config', __( 'PBX verification is not configured.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$canonical = self::pbx_canonical_string( $timestamp, $nonce, $raw_body );
		$expected  = hash_hmac( 'sha256', $canonical, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'digitalogic_pbx_auth', __( 'PBX authentication failed.', 'digitalogic' ), array( 'status' => 401 ) );
		}

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table( 'pbx_nonces' ) . ' WHERE expires_at < UTC_TIMESTAMP()' );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table( 'pbx_nonces' ) . ' (nonce_hash, key_id, created_at, expires_at) VALUES (%s, %s, %s, %s)',
				self::lookup_hash( 'pbx-nonce', $key_id . '|' . $nonce ),
				$key_id,
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() + 600 )
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( 0 === (int) $inserted ) {
			return new WP_Error( 'digitalogic_pbx_replay', __( 'PBX request replay rejected.', 'digitalogic' ), array( 'status' => 409 ) );
		}
		if ( 1 !== (int) $inserted ) {
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		return true;
	}

	/**
	 * Confirm an exact ANI/code match from the trusted PBX helper.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pbx_confirm( WP_REST_Request $request ) {
		$payload  = json_decode( $request->get_body(), true );
		$rejected = self::pbx_result( false );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( $rejected, 400 );
		}

		$required_keys  = array( 'schema', 'site_id', 'event_id', 'call_id', 'called_number', 'caller_number', 'code', 'occurred_at' );
		$payload_keys   = array_keys( $payload );
		$has_exact_keys = count( $required_keys ) === count( $payload_keys )
			&& empty( array_diff( $required_keys, $payload_keys ) )
			&& empty( array_diff( $payload_keys, $required_keys ) );
		$event_id       = is_string( $payload['event_id'] ?? null ) ? $payload['event_id'] : '';
		$call_id        = is_string( $payload['call_id'] ?? null ) ? $payload['call_id'] : '';
		$valid_event    = 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $event_id );
		$valid_call     = 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $call_id );
		if ( ! $has_exact_keys || ! $valid_event || ! $valid_call ) {
			return new WP_REST_Response( $rejected, 400 );
		}

		$raw_body = $request->get_body();
		$claim    = $this->claim_pbx_event( $event_id, $call_id, $raw_body );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( is_array( $claim ) ) {
			return new WP_REST_Response( $claim, 200 );
		}

		// Serialize exact duplicate/recovery requests on their event row. The
		// rate reservation, challenge CAS, and final event result then share the
		// same transaction, so no duplicate can bypass or poison an in-flight
		// attempt.
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$wpdb->last_error = '';
		$event_row        = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT call_hash,result_json FROM ' . self::table( 'pbx_events' ) . ' WHERE event_id = %s FOR UPDATE',
				$event_id
			)
		);
		if ( ! $event_row || '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! hash_equals( (string) $event_row->call_hash, self::pbx_event_call_hash( $call_id, $raw_body ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_pbx_event_conflict', __( 'PBX event conflict.', 'digitalogic' ), array( 'status' => 409 ) );
		}
		$stored_result = json_decode( (string) $event_row->result_json, true );
		if ( self::is_completed_pbx_result( $stored_result ) ) {
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
			}
			return new WP_REST_Response( $stored_result, 200 );
		}
		if ( array( 'processing' => true ) !== $stored_result ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		$signed_timestamp = (int) $request->get_header( 'x-pbx-timestamp' );
		$validated        = self::validate_pbx_payload( $payload, $signed_timestamp );
		if ( is_wp_error( $validated ) ) {
			return $this->commit_pbx_event_transaction( $event_id, $rejected );
		}
		$code   = $validated['code'];
		$caller = $validated['caller'];

		$phone_hash = self::lookup_hash( 'phone', $caller );
		// Reserve both limits before evaluating the code. Successful attempts
		// conservatively consume capacity too; this prevents concurrent wrong
		// codes from all passing a check-then-increment race.
		$reservation = $this->reserve_pbx_attempt_capacity( $phone_hash );
		if ( is_wp_error( $reservation ) ) {
			if ( 'digitalogic_call_rate' === $reservation->get_error_code() ) {
				return $this->commit_pbx_event_transaction( $event_id, $rejected );
			}
			$wpdb->query( 'ROLLBACK' );
			return $reservation;
		}
		$wpdb->last_error = '';
		$recovery_rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, public_id, phone_encrypted, code_mac, status, verified_call_hash, version FROM ' . self::table( 'phone_challenges' ) . " WHERE phone_hash = %s AND verified_call_hash = %s AND status IN ('verified','consumed') ORDER BY created_at DESC LIMIT 1 FOR UPDATE",
				$phone_hash,
				self::lookup_hash( 'call', $call_id )
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $recovery_rows ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$wpdb->last_error = '';
		$pending_rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, public_id, phone_encrypted, code_mac, status, verified_call_hash, version FROM ' . self::table( 'phone_challenges' ) . " WHERE phone_hash = %s AND status = 'pending' AND expires_at > UTC_TIMESTAMP() ORDER BY created_at DESC LIMIT %d FOR UPDATE",
				$phone_hash,
				self::MAX_ACTIVE_PER_PHONE
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $pending_rows ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$rows     = array_merge( $recovery_rows, $pending_rows );
		$verified = false;
		foreach ( (array) $rows as $row ) {
			$candidate_phone = self::decrypt( (string) $row->phone_encrypted );
			if ( '' === $candidate_phone || ! hash_equals( $candidate_phone, $caller )
				|| ! hash_equals( (string) $row->code_mac, self::code_mac( (string) $row->public_id, $code ) ) ) {
				continue;
			}
			if ( in_array( (string) $row->status, array( 'verified', 'consumed' ), true ) ) {
				$verified = hash_equals( (string) $row->verified_call_hash, self::lookup_hash( 'call', $call_id ) );
				if ( $verified ) {
					break;
				}
				continue;
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'verified', verified_at = UTC_TIMESTAMP(), consume_deadline = %s, verified_call_hash = %s, version = version + 1 WHERE id = %d AND status = 'pending' AND version = %d AND expires_at > UTC_TIMESTAMP()",
					gmdate( 'Y-m-d H:i:s', time() + self::CONSUME_TTL ),
					self::lookup_hash( 'call', $call_id ),
					$row->id,
					$row->version
				)
			);
			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
			}
			$verified = 1 === (int) $updated;
			break;
		}

		return $this->commit_pbx_event_transaction( $event_id, self::pbx_result( $verified ) );
	}

	/**
	 * Authorize user contact routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function authorize_user_rest( WP_REST_Request $request ) {
		if ( ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_pbx_schema', __( 'Contact management is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( get_current_user_id() < 1 || ! $this->valid_rest_nonce( $request ) ) {
			return new WP_Error( 'digitalogic_contact_auth', __( 'Authentication is required.', 'digitalogic' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * List the current user's contacts.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_contacts(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success'  => true,
				'contacts' => $this->contacts_for_user( get_current_user_id() ),
			),
			200
		);
	}

	/**
	 * Store an additional e-mail without claiming it is verified.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_add_email( WP_REST_Request $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'digitalogic_contact_email', __( 'Enter a valid email address.', 'digitalogic' ), array( 'status' => 400 ) );
		}
		$label           = self::bounded_text( (string) $request->get_param( 'label' ), 100 );
		$email_hash      = self::lookup_hash( 'email', strtolower( $email ) );
		$encrypted_email = self::encrypt( strtolower( $email ) );
		if ( '' === $encrypted_email ) {
			return new WP_Error( 'digitalogic_contact_crypto', __( 'The contact could not be saved.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		global $wpdb;
		$user_id = get_current_user_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->contact_storage_error();
		}
		// Lock the user's indexed kind range so concurrent inserts cannot both
		// pass the per-kind capacity check.
		$wpdb->last_error = '';
		$locked           = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND kind = 'email' FOR UPDATE",
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $locked ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		$capacity = $this->contact_capacity( $user_id, 'email', $email_hash );
		if ( is_wp_error( $capacity ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $capacity;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::table( 'contact_points' ) . " (user_id,kind,value_encrypted,value_hash,label,status,created_at,updated_at) VALUES (%d,'email',%s,%s,%s,'unverified',%s,%s) ON DUPLICATE KEY UPDATE value_encrypted=VALUES(value_encrypted),label=VALUES(label),status='unverified',verification_method='',verified_at=NULL,updated_at=VALUES(updated_at)",
			$user_id,
			$encrypted_email,
			$email_hash,
			$label,
			$now,
			$now
		);
		if ( false === $wpdb->query( $sql ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		return new WP_REST_Response(
			array(
				'success'  => true,
				'contacts' => $this->contacts_for_user( $user_id ),
			),
			201
		);
	}

	/**
	 * Update labels and voice preferences for an owned contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_update_contact( WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->contact_storage_error();
		}
		$wpdb->last_error = '';
		$contact          = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . ' WHERE id = %d AND user_id = %d FOR UPDATE',
				(int) $request->get_param( 'id' ),
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		if ( ! $contact ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_contact_missing', __( 'Contact not found.', 'digitalogic' ), array( 'status' => 404 ) );
		}

		$voice_requested = null !== $request->get_param( 'voice_opt_in' ) || null !== $request->get_param( 'voice_events' );
		if ( $voice_requested && ( 'phone' !== $contact->kind || 'verified' !== $contact->status ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_contact_voice', __( 'Voice preferences require a verified phone number.', 'digitalogic' ), array( 'status' => 409 ) );
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$updates = array();
		$formats = array();
		if ( null !== $request->get_param( 'label' ) ) {
			$label = self::bounded_text( (string) $request->get_param( 'label' ), 100 );
			if ( ! hash_equals( (string) $contact->label, $label ) ) {
				$updates['label'] = $label;
				$formats[]        = '%s';
			}
		}

		$old_opt_in      = (int) $contact->voice_opt_in;
		$new_opt_in      = null === $request->get_param( 'voice_opt_in' ) ? $old_opt_in : ( rest_sanitize_boolean( $request->get_param( 'voice_opt_in' ) ) ? 1 : 0 );
		$old_events      = self::canonical_event_preferences( $contact->voice_events );
		$new_events      = null === $request->get_param( 'voice_events' ) ? $old_events : self::canonical_event_preferences( $request->get_param( 'voice_events' ) );
		$consent_changed = $voice_requested && ( $old_opt_in !== $new_opt_in || $old_events !== $new_events );
		if ( $consent_changed ) {
			$updates['voice_opt_in']     = $new_opt_in;
			$formats[]                   = '%d';
			$updates['voice_events']     = wp_json_encode( $new_events );
			$formats[]                   = '%s';
			$updates['consent_actor_id'] = $user_id;
			$formats[]                   = '%d';
			$updates['consent_source']   = 'customer_profile';
			$formats[]                   = '%s';
			$updates['consented_at']     = $now;
			$formats[]                   = '%s';
		}

		if ( ! empty( $updates ) ) {
			$updates['updated_at'] = $now;
			$formats[]             = '%s';
			$updated               = $wpdb->update(
				self::table( 'contact_points' ),
				$updates,
				array(
					'id'      => (int) $contact->id,
					'user_id' => $user_id,
				),
				$formats,
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->contact_storage_error();
			}
		}
		if ( $consent_changed && ! $this->insert_consent_audit(
			$contact,
			$user_id,
			'customer',
			$old_opt_in,
			$new_opt_in,
			(int) $contact->admin_suppressed,
			(int) $contact->admin_suppressed,
			$old_events,
			$new_events,
			'customer self-service'
		) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		return new WP_REST_Response(
			array(
				'success'  => true,
				'contacts' => $this->contacts_for_user( $user_id ),
			),
			200
		);
	}

	/**
	 * Revoke an owned supplemental contact.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_delete_contact( WP_REST_Request $request ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->contact_storage_error();
		}
		$wpdb->last_error = '';
		$contact          = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . ' WHERE id = %d AND user_id = %d FOR UPDATE',
				(int) $request->get_param( 'id' ),
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		if ( ! $contact || 'revoked' === $contact->status ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_contact_missing', __( 'Contact not found.', 'digitalogic' ), array( 'status' => 404 ) );
		}
		$old_events = self::canonical_event_preferences( $contact->voice_events );
		$updated    = $wpdb->update(
			self::table( 'contact_points' ),
			array(
				'status'        => 'revoked',
				'voice_opt_in'  => 0,
				'voice_events'  => '[]',
				'login_enabled' => 0,
				'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'      => (int) $contact->id,
				'user_id' => $user_id,
			),
			array( '%s', '%d', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);
		if ( 1 !== (int) $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		if ( 'phone' === $contact->kind && ! $this->insert_consent_audit(
			$contact,
			$user_id,
			'customer',
			(int) $contact->voice_opt_in,
			0,
			(int) $contact->admin_suppressed,
			(int) $contact->admin_suppressed,
			$old_events,
			array(),
			'customer removed contact'
		) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->contact_storage_error();
		}
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Return a deterministic canonical request string for the PBX helper.
	 *
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce     Request nonce.
	 * @param string $body      Exact raw request body.
	 * @return string
	 */
	public static function pbx_canonical_string( string $timestamp, string $nonce, string $body ): string {
		return "v1\nPOST\n" . self::PBX_CANONICAL_PATH . "\n{$timestamp}\n{$nonce}\n" . hash( 'sha256', $body );
	}

	/**
	 * The AGI protocol treats only an actual match as successful.
	 *
	 * @param bool $verified Whether ANI and code matched atomically.
	 * @return array{success:bool,verified:bool}
	 */
	public static function pbx_result( bool $verified ): array {
		return array(
			'success'  => $verified,
			'verified' => $verified,
		);
	}

	/**
	 * Validate the exact signed callback payload contract.
	 *
	 * @param mixed $payload          Decoded JSON payload.
	 * @param int   $signed_timestamp Authenticated request timestamp.
	 * @return array{event_id:string,call_id:string,caller:string,code:string}|WP_Error
	 */
	public static function validate_pbx_payload( $payload, int $signed_timestamp ) {
		$required_keys = array( 'schema', 'site_id', 'event_id', 'call_id', 'called_number', 'caller_number', 'code', 'occurred_at' );
		if ( ! is_array( $payload ) || count( $required_keys ) !== count( $payload )
			|| ! empty( array_diff( $required_keys, array_keys( $payload ) ) )
			|| ! empty( array_diff( array_keys( $payload ), $required_keys ) ) ) {
			return new WP_Error( 'digitalogic_pbx_payload', __( 'PBX payload is invalid.', 'digitalogic' ) );
		}
		$event_id           = is_string( $payload['event_id'] ) ? $payload['event_id'] : '';
		$call_id            = is_string( $payload['call_id'] ) ? $payload['call_id'] : '';
		$code               = self::ascii_digits( is_string( $payload['code'] ) ? $payload['code'] : '' );
		$called             = is_string( $payload['called_number'] ) ? self::normalize_called_did( $payload['called_number'] ) : '';
		$caller             = is_string( $payload['caller_number'] ) ? Digitalogic_PBX_Phone::normalize_trusted_ani( $payload['caller_number'] ) : '';
		$occurred_at        = is_string( $payload['occurred_at'] ) ? $payload['occurred_at'] : '';
		$occurred_timestamp = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $occurred_at ) ? strtotime( $occurred_at ) : false;
		$valid              = 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $event_id )
			&& 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $call_id )
			&& 'phone-verification.v1' === $payload['schema']
			&& self::SITE_ID === $payload['site_id']
			&& self::ACCESS_DID === $called
			&& '' !== $caller
			&& 1 === preg_match( '/^[1-9][0-9]{5}$/', $code )
			&& false !== $occurred_timestamp
			&& abs( $signed_timestamp - $occurred_timestamp ) <= 180;
		if ( ! $valid ) {
			return new WP_Error( 'digitalogic_pbx_payload', __( 'PBX payload is invalid.', 'digitalogic' ) );
		}
		return array(
			'event_id' => $event_id,
			'call_id'  => $call_id,
			'caller'   => $caller,
			'code'     => $code,
		);
	}

	/**
	 * Return the contact table name for the notification worker.
	 *
	 * @return string
	 */
	public static function contact_table(): string {
		return self::table( 'contact_points' );
	}

	/**
	 * Fetch an active contact by id.
	 *
	 * @param int $contact_id Contact id.
	 * @return object|null
	 */
	public static function contact_by_id( int $contact_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . " WHERE id = %d AND kind = 'phone' AND status = 'verified'",
				$contact_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! $row ) {
			return null;
		}

		$row->phone = self::decrypt( (string) $row->value_encrypted );
		return '' === $row->phone ? null : $row;
	}

	/**
	 * Return verified, currently opted-in contacts for a customer.
	 *
	 * @param int    $user_id Customer id.
	 * @param string $event   WooCommerce status slug.
	 * @return array<object>
	 */
	public static function voice_contacts_for_user( int $user_id, string $event ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND kind = 'phone' AND status = 'verified' AND voice_opt_in = 1 AND admin_suppressed = 0",
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return array();
		}
		$contacts = array();
		foreach ( (array) $rows as $row ) {
			if ( ! self::contact_allows_voice_event( $row, $event ) ) {
				continue;
			}
			$row->phone = self::decrypt( (string) $row->value_encrypted );
			if ( '' !== $row->phone ) {
				$contacts[] = $row;
			}
		}

		return $contacts;
	}

	/**
	 * Require affirmative contact consent for the exact event.
	 *
	 * @param object $contact Stored contact row.
	 * @param string $event   Order status slug.
	 * @return bool
	 */
	public static function contact_allows_voice_event( $contact, string $event ): bool {
		if ( ! is_object( $contact )
			|| ! property_exists( $contact, 'kind' ) || 'phone' !== (string) $contact->kind
			|| ! property_exists( $contact, 'status' ) || 'verified' !== (string) $contact->status
			|| ! property_exists( $contact, 'voice_opt_in' ) || ! in_array( $contact->voice_opt_in, array( 1, '1' ), true )
			|| ! property_exists( $contact, 'admin_suppressed' ) || ! in_array( $contact->admin_suppressed, array( 0, '0' ), true )
			|| ! property_exists( $contact, 'voice_events' ) || ! is_string( $contact->voice_events ) ) {
			return false;
		}
		$events = json_decode( $contact->voice_events, true );
		return is_array( $events ) && 1 === (int) ( $events[ $event ] ?? 0 );
	}

	/**
	 * Load front-end assets and localized configuration.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'digitalogic-call-verification',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/call-verification.css',
			array(),
			DIGITALOGIC_VERSION
		);
		wp_enqueue_script(
			'digitalogic-call-verification',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/call-verification.js',
			array(),
			DIGITALOGIC_VERSION,
			true
		);
		wp_localize_script(
			'digitalogic-call-verification',
			'DigitalogicCallVerification',
			array(
				'challengeUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::ROUTE ) ),
				'contactsUrl'  => esc_url_raw( rest_url( self::REST_NAMESPACE . '/contacts' ) ),
				'wpNonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'messages'     => array(
					'start'       => __( 'Verify by calling', 'digitalogic' ),
					'waiting'     => __( 'Waiting for your call…', 'digitalogic' ),
					'verified'    => __( 'Phone number verified. Finishing…', 'digitalogic' ),
					'expired'     => __( 'This code expired. Please request another code.', 'digitalogic' ),
					'error'       => __( 'Verification could not be completed. Please try again.', 'digitalogic' ),
					'confirmDrop' => __( 'Removing this contact also disables voice notifications. Continue?', 'digitalogic' ),
				),
			)
		);
	}

	/**
	 * Enqueue assets only on WooCommerce account pages.
	 */
	public function maybe_enqueue_account_assets(): void {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Render the login alternative without modifying the Digits plugin.
	 */
	public function render_login_verification(): void {
		if ( ! self::is_configured() ) {
			return;
		}
		?>
		<section class="digitalogic-call-verification" data-digitalogic-call-widget data-purpose="login">
			<button type="button" class="button digitalogic-call-toggle"><?php esc_html_e( 'Verify by calling', 'digitalogic' ); ?></button>
			<div class="digitalogic-call-panel" hidden>
				<label>
					<span><?php esc_html_e( 'Mobile or landline number', 'digitalogic' ); ?></span>
					<input type="tel" inputmode="tel" autocomplete="tel" data-call-phone placeholder="02112345678">
				</label>
				<button type="button" class="button button-primary" data-call-start><?php esc_html_e( 'Create call code', 'digitalogic' ); ?></button>
				<div class="digitalogic-call-instructions" data-call-instructions hidden aria-live="polite">
					<p><?php esc_html_e( 'Call', 'digitalogic' ); ?> <a dir="ltr" href="tel:+982166754123">021-66754123</a>.</p>
					<p><?php printf( esc_html__( 'Choose IVR option %s, then enter this six-digit code:', 'digitalogic' ), '<strong dir="ltr">2</strong>' ); ?></p>
					<output class="digitalogic-call-code" data-call-code dir="ltr"></output>
					<p data-call-status><?php esc_html_e( 'Waiting for your call…', 'digitalogic' ); ?></p>
					<button type="button" class="button-link" data-call-cancel><?php esc_html_e( 'Cancel', 'digitalogic' ); ?></button>
				</div>
				<p class="digitalogic-call-error" data-call-error role="alert" hidden></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Render supplemental contacts in WooCommerce My Account.
	 */
	public function render_account_contacts(): void {
		if ( ! self::is_schema_ready() || ! is_user_logged_in() ) {
			return;
		}
		$contacts = $this->contacts_for_user( get_current_user_id() );
		?>
		<section class="digitalogic-contact-points" data-digitalogic-contacts>
			<h2><?php esc_html_e( 'Additional contact information', 'digitalogic' ); ?></h2>
			<p><?php esc_html_e( 'Add more email addresses or verify mobile and landline numbers. Voice notifications are off unless you enable them for a verified number.', 'digitalogic' ); ?></p>
			<div data-contact-list>
				<?php foreach ( $contacts as $contact ) : ?>
					<?php $this->render_contact_row( $contact ); ?>
				<?php endforeach; ?>
			</div>
			<div class="digitalogic-add-email" data-add-email>
				<label><?php esc_html_e( 'Additional email', 'digitalogic' ); ?> <input type="email" data-contact-email></label>
				<label><?php esc_html_e( 'Label', 'digitalogic' ); ?> <input type="text" data-contact-label maxlength="100"></label>
				<button type="button" class="button" data-add-email-submit><?php esc_html_e( 'Add email', 'digitalogic' ); ?></button>
			</div>
			<?php if ( self::is_configured() ) : ?>
			<section class="digitalogic-call-verification" data-digitalogic-call-widget data-purpose="add_contact">
				<button type="button" class="button digitalogic-call-toggle"><?php esc_html_e( 'Add and verify a phone number by calling', 'digitalogic' ); ?></button>
				<div class="digitalogic-call-panel" hidden>
					<label><?php esc_html_e( 'Mobile or landline number (include area code)', 'digitalogic' ); ?> <input type="tel" inputmode="tel" data-call-phone></label>
					<button type="button" class="button" data-call-start><?php esc_html_e( 'Create call code', 'digitalogic' ); ?></button>
					<div class="digitalogic-call-instructions" data-call-instructions hidden aria-live="polite">
						<p><?php esc_html_e( 'Call', 'digitalogic' ); ?> <a dir="ltr" href="tel:+982166754123">021-66754123</a>.</p>
						<p><?php printf( esc_html__( 'Choose IVR option %s, then enter this six-digit code:', 'digitalogic' ), '<strong dir="ltr">2</strong>' ); ?></p>
						<output class="digitalogic-call-code" data-call-code dir="ltr"></output>
						<p data-call-status><?php esc_html_e( 'Waiting for your call…', 'digitalogic' ); ?></p>
						<button type="button" class="button-link" data-call-cancel><?php esc_html_e( 'Cancel', 'digitalogic' ); ?></button>
					</div>
					<p class="digitalogic-call-error" data-call-error role="alert" hidden></p>
				</div>
			</section>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render a customer contact row.
	 *
	 * @param array $contact Public contact representation.
	 */
	private function render_contact_row( array $contact ): void {
		$is_voice = 'phone' === $contact['kind'] && 'verified' === $contact['status'];
		?>
		<div class="digitalogic-contact-row" data-contact-id="<?php echo esc_attr( (string) $contact['id'] ); ?>">
			<span dir="ltr"><?php echo esc_html( $contact['value'] ); ?></span>
			<span><?php echo esc_html( $contact['label'] ); ?></span>
			<small><?php echo esc_html( $contact['status_label'] ); ?></small>
			<?php if ( $is_voice ) : ?>
				<label><input type="checkbox" data-contact-voice <?php checked( $contact['voice_opt_in'] ); ?>> <?php esc_html_e( 'Voice order updates', 'digitalogic' ); ?></label>
				<div class="digitalogic-contact-events">
					<?php foreach ( array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) as $event ) : ?>
						<?php $event_enabled = ! empty( $contact['voice_events'][ $event ] ); ?>
						<label><input type="checkbox" value="<?php echo esc_attr( $event ); ?>" data-contact-event <?php checked( $event_enabled ); ?>> <?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $event ) : ucfirst( str_replace( '-', ' ', $event ) ) ); ?></label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<button type="button" class="button-link-delete" data-contact-delete><?php esc_html_e( 'Remove', 'digitalogic' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render voice controls on a WordPress user profile.
	 *
	 * @param WP_User $user Profile owner.
	 */
	public function render_admin_contacts( $user ): void {
		if ( ! self::is_schema_ready() || ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$contacts = $this->contacts_for_user( (int) $user->ID );
		wp_nonce_field( 'digitalogic_admin_contacts_' . $user->ID, 'digitalogic_admin_contacts_nonce' );
		?>
		<h2><?php esc_html_e( 'Digitalogic verified contacts', 'digitalogic' ); ?></h2>
		<table class="form-table" role="presentation"><tbody>
		<?php foreach ( $contacts as $contact ) : ?>
			<tr>
				<th><span dir="ltr"><?php echo esc_html( $contact['value'] ); ?></span></th>
				<td>
					<?php echo esc_html( $contact['status_label'] ); ?>
					<?php if ( 'phone' === $contact['kind'] && 'verified' === $contact['status'] ) : ?>
						<label><input type="checkbox" name="digitalogic_contacts[<?php echo esc_attr( (string) $contact['id'] ); ?>][voice_opt_in]" value="1" <?php checked( $contact['voice_opt_in'] ); ?>> <?php esc_html_e( 'Voice notifications enabled', 'digitalogic' ); ?></label>
						<label><input type="checkbox" name="digitalogic_contacts[<?php echo esc_attr( (string) $contact['id'] ); ?>][admin_suppressed]" value="1" <?php checked( $contact['admin_suppressed'] ); ?>> <?php esc_html_e( 'Administratively suppress calls', 'digitalogic' ); ?></label>
						<fieldset><legend><?php esc_html_e( 'Explicitly allowed voice events', 'digitalogic' ); ?></legend>
						<?php foreach ( array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) as $event ) : ?>
							<label><input type="checkbox" name="digitalogic_contacts[<?php echo esc_attr( (string) $contact['id'] ); ?>][voice_events][]" value="<?php echo esc_attr( $event ); ?>" <?php checked( ! empty( $contact['voice_events'][ $event ] ) ); ?>> <?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $event ) : ucfirst( str_replace( '-', ' ', $event ) ) ); ?></label>
						<?php endforeach; ?>
						</fieldset>
						<p><label><?php esc_html_e( 'Reason for administrative consent change', 'digitalogic' ); ?> <input type="text" class="regular-text" maxlength="255" name="digitalogic_contacts[<?php echo esc_attr( (string) $contact['id'] ); ?>][reason]" value=""></label></p>
						<p class="description"><?php esc_html_e( 'A reason is required when enabling or expanding voice-call consent.', 'digitalogic' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	/**
	 * Save administrator voice controls.
	 *
	 * @param int $user_id Profile owner.
	 */
	public function save_admin_contacts( int $user_id ): void {
		if ( ! self::is_schema_ready() || ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_POST['digitalogic_admin_contacts_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['digitalogic_admin_contacts_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'digitalogic_admin_contacts_' . $user_id ) ) {
			return;
		}
		$posted = isset( $_POST['digitalogic_contacts'] ) && is_array( $_POST['digitalogic_contacts'] ) ? wp_unslash( $_POST['digitalogic_contacts'] ) : array();
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			$this->set_admin_contact_error( __( 'The contact preferences could not be saved.', 'digitalogic' ) );
			return;
		}
		$wpdb->last_error = '';
		$contacts         = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND kind = 'phone' AND status = 'verified' FOR UPDATE",
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $contacts ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->set_admin_contact_error( __( 'The contact preferences could not be saved.', 'digitalogic' ) );
			return;
		}
		$actor_id = get_current_user_id();
		$now      = gmdate( 'Y-m-d H:i:s' );
		foreach ( $contacts as $contact ) {
			$id = (int) $contact->id;
			if ( ! isset( $posted[ $id ] ) || ! is_array( $posted[ $id ] ) ) {
				continue;
			}
			$settings       = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : array();
			$old_opt_in     = (int) $contact->voice_opt_in;
			$new_opt_in     = empty( $settings['voice_opt_in'] ) ? 0 : 1;
			$old_suppressed = (int) $contact->admin_suppressed;
			$new_suppressed = empty( $settings['admin_suppressed'] ) ? 0 : 1;
			$old_events     = self::canonical_event_preferences( $contact->voice_events );
			$new_events     = self::canonical_event_preferences( $settings['voice_events'] ?? array() );
			$changed        = $old_opt_in !== $new_opt_in || $old_suppressed !== $new_suppressed || $old_events !== $new_events;
			if ( ! $changed ) {
				continue;
			}
			$reason = self::bounded_text( (string) ( $settings['reason'] ?? '' ), 255 );
			if ( self::consent_expands( $old_opt_in, $new_opt_in, $old_suppressed, $new_suppressed, $old_events, $new_events ) && '' === $reason ) {
				$wpdb->query( 'ROLLBACK' );
				$this->set_admin_contact_error( __( 'A reason is required when an administrator enables or expands voice-call consent.', 'digitalogic' ) );
				return;
			}
			if ( '' === $reason ) {
				$reason = 'administrator safety reduction';
			}
			$updated = $wpdb->update(
				self::table( 'contact_points' ),
				array(
					'voice_opt_in'     => $new_opt_in,
					'admin_suppressed' => $new_suppressed,
					'voice_events'     => wp_json_encode( $new_events ),
					'consent_actor_id' => $actor_id,
					'consent_source'   => 'administrator_profile',
					'consented_at'     => $now,
					'updated_at'       => $now,
				),
				array(
					'id'      => $id,
					'user_id' => $user_id,
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $updated || ! $this->insert_consent_audit(
				$contact,
				$actor_id,
				'administrator',
				$old_opt_in,
				$new_opt_in,
				$old_suppressed,
				$new_suppressed,
				$old_events,
				$new_events,
				$reason
			) ) {
				$wpdb->query( 'ROLLBACK' );
				$this->set_admin_contact_error( __( 'The contact preferences could not be saved.', 'digitalogic' ) );
				return;
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			$this->set_admin_contact_error( __( 'The contact preferences could not be saved.', 'digitalogic' ) );
		}
	}

	/**
	 * Erase supplemental contact PII and invalidate outstanding challenges.
	 *
	 * @param int $user_id Deleted user id.
	 */
	public function cleanup_deleted_user( int $user_id ): void {
		if ( ! self::is_schema_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( self::table( 'contact_points' ), array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->delete( self::table( 'contact_consent_audit' ), array( 'user_id' => $user_id ), array( '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'cancelled', version = version + 1 WHERE user_id = %d AND status IN ('pending','verified')",
				$user_id
			)
		);
	}

	/**
	 * Schedule bounded retention cleanup once daily.
	 */
	public function schedule_cleanup(): void {
		if ( ! self::is_schema_ready() ) {
			return;
		}
		if ( ! self::ensure_cleanup_schedule() ) {
			set_transient( 'digitalogic_pbx_schedule_error', __( 'Digitalogic PBX retention scheduling failed.', 'digitalogic' ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Remove terminal security records and revoked PII after bounded periods.
	 */
	public function cleanup_retention(): void {
		if ( ! self::is_schema_ready() ) {
			return;
		}
		global $wpdb;
		$users      = '`' . str_replace( '`', '``', isset( $wpdb->users ) ? $wpdb->users : $wpdb->prefix . 'users' ) . '`';
		$contacts   = '`' . str_replace( '`', '``', self::table( 'contact_points' ) ) . '`';
		$audit      = '`' . str_replace( '`', '``', self::table( 'contact_consent_audit' ) ) . '`';
		$challenges = '`' . str_replace( '`', '``', self::table( 'phone_challenges' ) ) . '`';
		// deleted_user is one-shot, so the daily pass retries any deletion that
		// coincided with a transient schema/database outage.
		$wpdb->query( "UPDATE $challenges AS challenge LEFT JOIN $users AS live_user ON live_user.ID = challenge.user_id SET challenge.status = 'cancelled', challenge.version = challenge.version + 1 WHERE challenge.user_id > 0 AND live_user.ID IS NULL AND challenge.status IN ('pending','verified')" );
		$wpdb->query( "DELETE contact FROM $contacts AS contact LEFT JOIN $users AS live_user ON live_user.ID = contact.user_id WHERE live_user.ID IS NULL" );
		$wpdb->query( "DELETE audit_row FROM $audit AS audit_row LEFT JOIN $users AS live_user ON live_user.ID = audit_row.user_id WHERE live_user.ID IS NULL" );
		$wpdb->query( 'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'expired', version = version + 1 WHERE status = 'pending' AND expires_at < UTC_TIMESTAMP()" );
		$wpdb->query( 'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'expired', version = version + 1 WHERE status = 'verified' AND consume_deadline < UTC_TIMESTAMP()" );
		$wpdb->query( 'DELETE FROM ' . self::table( 'phone_challenges' ) . " WHERE status IN ('expired','cancelled','consumed') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" );
		$wpdb->query( 'DELETE FROM ' . self::table( 'pbx_nonces' ) . ' WHERE expires_at < UTC_TIMESTAMP()' );
		$wpdb->query( 'DELETE FROM ' . self::table( 'pbx_events' ) . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)' );
		$wpdb->query( 'DELETE FROM ' . self::table( 'verification_rates' ) . ' WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)' );
		$wpdb->query( 'DELETE FROM ' . self::table( 'contact_points' ) . " WHERE status = 'revoked' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)" );
		$wpdb->query( 'DELETE FROM ' . self::table( 'contact_consent_audit' ) . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 365 DAY)' );
	}

	/**
	 * Warn administrators while verification is intentionally unavailable.
	 */
	public function configuration_notice(): void {
		$error_key     = 'digitalogic_contact_admin_error_' . get_current_user_id();
		$contact_error = get_transient( $error_key );
		if ( is_string( $contact_error ) && '' !== $contact_error ) {
			delete_transient( $error_key );
			?>
			<div class="notice notice-error"><p><?php echo esc_html( $contact_error ); ?></p></div>
			<?php
		}
		$schedule_error = get_transient( 'digitalogic_pbx_schedule_error' );
		if ( is_string( $schedule_error ) && '' !== $schedule_error && current_user_can( 'manage_options' ) ) {
			delete_transient( 'digitalogic_pbx_schedule_error' );
			?>
			<div class="notice notice-error"><p><?php echo esc_html( $schedule_error ); ?></p></div>
			<?php
		}
		if ( ! self::is_schema_ready() && current_user_can( 'manage_options' ) ) {
			?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Digitalogic PBX features are disabled because the verified database migration or recovery schedule is incomplete.', 'digitalogic' ); ?></p></div>
			<?php
			return;
		}
		if ( self::is_configured() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Digitalogic call verification is hidden because DIGITALOGIC_PBX_VERIFY_SECRET is missing or invalid.', 'digitalogic' ); ?></p></div>
		<?php
	}

	/**
	 * Return public representations of a user's contacts.
	 *
	 * @param int $user_id User id.
	 * @return array<int,array<string,mixed>>
	 */
	private function contacts_for_user( int $user_id ): array {
		global $wpdb;
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND status <> 'revoked' ORDER BY kind, is_primary DESC, id",
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return array();
		}
		$result = array();
		foreach ( (array) $rows as $row ) {
			$value = self::decrypt( (string) $row->value_encrypted );
			if ( '' === $value ) {
				continue;
			}
			$result[] = array(
				'id'               => (int) $row->id,
				'kind'             => (string) $row->kind,
				'value'            => 'phone' === $row->kind ? Digitalogic_PBX_Phone::display( $value ) : $value,
				'label'            => (string) $row->label,
				'status'           => (string) $row->status,
				'status_label'     => 'verified' === $row->status ? __( 'Verified', 'digitalogic' ) : __( 'Unverified', 'digitalogic' ),
				'voice_opt_in'     => (bool) $row->voice_opt_in,
				'admin_suppressed' => (bool) $row->admin_suppressed,
				'voice_events'     => json_decode( (string) $row->voice_events, true ) ?: array(),
			);
		}

		return $result;
	}

	/**
	 * Return an owned contact row.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $user_id    Owner id.
	 * @return object|null
	 */
	private function owned_contact( int $contact_id, int $user_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$contact          = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table( 'contact_points' ) . ' WHERE id = %d AND user_id = %d',
				$contact_id,
				$user_id
			)
		);
		return '' === (string) $wpdb->last_error ? $contact : null;
	}

	/**
	 * Canonicalize a stored or submitted event allowlist.
	 *
	 * @param mixed $value JSON text or request array.
	 * @return array<string,int>
	 */
	private static function canonical_event_preferences( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}
		return Digitalogic_Voice_Notifications::sanitize_event_preferences( $value );
	}

	/**
	 * Determine whether an administrator is broadening effective consent.
	 *
	 * @param int   $old_opt_in       Previous customer opt-in.
	 * @param int   $new_opt_in       New customer opt-in.
	 * @param int   $old_suppressed   Previous administrative suppression.
	 * @param int   $new_suppressed   New administrative suppression.
	 * @param array $old_events       Previous event allowlist.
	 * @param array $new_events       New event allowlist.
	 * @return bool
	 */
	private static function consent_expands( int $old_opt_in, int $new_opt_in, int $old_suppressed, int $new_suppressed, array $old_events, array $new_events ): bool {
		return ( 0 === $old_opt_in && 1 === $new_opt_in )
			|| ( 1 === $old_suppressed && 0 === $new_suppressed )
			|| ! empty( array_diff_key( $new_events, $old_events ) );
	}

	/**
	 * Append an immutable consent audit row inside the caller's transaction.
	 *
	 * @param object $contact          Contact row.
	 * @param int    $actor_id         Acting user id.
	 * @param string $actor_type       Customer or administrator.
	 * @param int    $old_opt_in       Previous customer opt-in.
	 * @param int    $new_opt_in       New customer opt-in.
	 * @param int    $old_suppressed   Previous administrative suppression.
	 * @param int    $new_suppressed   New administrative suppression.
	 * @param array  $old_events       Previous event allowlist.
	 * @param array  $new_events       New event allowlist.
	 * @param string $reason           Immutable change reason.
	 * @return bool
	 */
	private function insert_consent_audit( $contact, int $actor_id, string $actor_type, int $old_opt_in, int $new_opt_in, int $old_suppressed, int $new_suppressed, array $old_events, array $new_events, string $reason ): bool {
		global $wpdb;
		$inserted = $wpdb->insert(
			self::table( 'contact_consent_audit' ),
			array(
				'contact_id'           => (int) $contact->id,
				'user_id'              => (int) $contact->user_id,
				'actor_id'             => $actor_id,
				'actor_type'           => $actor_type,
				'old_voice_opt_in'     => $old_opt_in,
				'new_voice_opt_in'     => $new_opt_in,
				'old_admin_suppressed' => $old_suppressed,
				'new_admin_suppressed' => $new_suppressed,
				'old_voice_events'     => wp_json_encode( $old_events ),
				'new_voice_events'     => wp_json_encode( $new_events ),
				'reason'               => self::bounded_text( $reason, 255 ),
				'created_at'           => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return 1 === (int) $inserted;
	}

	/**
	 * Return a generic storage failure without leaking database details.
	 *
	 * @return WP_Error
	 */
	private function contact_storage_error(): WP_Error {
		return new WP_Error( 'digitalogic_contact_storage', __( 'The contact preferences could not be saved.', 'digitalogic' ), array( 'status' => 503 ) );
	}

	/**
	 * Persist an administrator-facing error across the profile redirect.
	 *
	 * @param string $message Safe localized message.
	 */
	private function set_admin_contact_error( string $message ): void {
		set_transient( 'digitalogic_contact_admin_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
	}

	/**
	 * Sanitize and bound a database text field without splitting UTF-8.
	 *
	 * @param string $value Submitted text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function bounded_text( string $value, int $limit ): string {
		$value = sanitize_text_field( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}

	/**
	 * Enforce a bounded number of supplemental contacts per kind.
	 *
	 * @param int    $user_id    Owner id.
	 * @param string $kind       Contact kind.
	 * @param string $value_hash Candidate lookup hash.
	 * @return true|WP_Error
	 */
	private function contact_capacity( int $user_id, string $kind, string $value_hash ) {
		global $wpdb;
		$wpdb->last_error = '';
		$existing_value   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table( 'contact_points' ) . ' WHERE user_id = %d AND kind = %s AND value_hash = %s',
				$user_id,
				$kind,
				$value_hash
			)
		);
		if ( '' !== (string) $wpdb->last_error || null === $existing_value ) {
			return $this->contact_storage_error();
		}
		$existing = (int) $existing_value;
		if ( $existing > 0 ) {
			return true;
		}
		$wpdb->last_error = '';
		$count_value      = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND kind = %s AND status <> 'revoked'",
				$user_id,
				$kind
			)
		);
		if ( '' !== (string) $wpdb->last_error || null === $count_value ) {
			return $this->contact_storage_error();
		}
		$count = (int) $count_value;
		if ( $count >= self::MAX_CONTACTS_PER_KIND ) {
			return new WP_Error( 'digitalogic_contact_limit', __( 'You have reached the limit for additional contacts of this type.', 'digitalogic' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/**
	 * Resolve exactly one account only after PBX ownership proof.
	 *
	 * @param string $phone      Canonical phone.
	 * @param string $phone_hash Lookup hash.
	 * @return WP_User|WP_Error
	 */
	private function resolve_login_user( string $phone, string $phone_hash ) {
		global $wpdb;
		$wpdb->last_error = '';
		$user_ids         = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT user_id FROM ' . self::table( 'contact_points' ) . " WHERE kind = 'phone' AND value_hash = %s AND status = 'verified' AND login_enabled = 1",
				$phone_hash
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $user_ids ) ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		$national     = Digitalogic_PBX_Phone::to_national( $phone );
		$significant  = substr( $phone, 3 );
		$values       = array_values( array_unique( array( $phone, '98' . $significant, $national, $significant ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// This lookup runs only after live PBX proof and is restricted to
		// explicit authentication identities. WooCommerce billing/order phone
		// metadata is contact data and must never select a login account.
		$sql              = "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ('digits_phone','digits_phone_no') AND meta_value IN ({$placeholders})";
		$wpdb->last_error = '';
		$digits_ids       = $wpdb->get_col( $wpdb->prepare( $sql, ...$values ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $digits_ids ) ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$user_ids = array_values( array_unique( array_map( 'absint', array_merge( (array) $user_ids, (array) $digits_ids ) ) ) );
		$user_ids = array_values( array_filter( $user_ids ) );
		if ( 1 !== count( $user_ids ) ) {
			$status = count( $user_ids ) > 1 ? 409 : 403;
			return new WP_Error( 'digitalogic_call_account', __( 'No unique account is available for this verified number.', 'digitalogic' ), array( 'status' => $status ) );
		}

		$user = get_userdata( $user_ids[0] );
		return $user instanceof WP_User ? $user : new WP_Error( 'digitalogic_call_account', __( 'No unique account is available for this verified number.', 'digitalogic' ), array( 'status' => 403 ) );
	}

	/**
	 * Apply account-policy authentication filters after PBX ownership proof.
	 *
	 * WordPress Zero Spam's core-login callback requires a form honeypot or login-page
	 * intent cookie. Neither exists in this REST flow, whose equivalent anti-automation
	 * controls are the signed PBX proof, browser binding, CSRF token, rate limits, and
	 * single-use transaction. Suspend only that form-specific callback for this call;
	 * every other account/security filter still runs and the callback is always restored.
	 *
	 * @param WP_User $user Resolved user.
	 * @return WP_User|WP_Error
	 */
	private function authenticate_verified_user( WP_User $user ) {
		$removed = $this->suspend_zerospam_login_filter();
		try {
			return apply_filters( 'wp_authenticate_user', $user, '' );
		} finally {
			foreach ( $removed as $filter ) {
				add_filter( 'wp_authenticate_user', $filter['callback'], $filter['priority'], $filter['accepted_args'] );
			}
		}
	}

	/**
	 * Remove only Zero Spam's core form validator from this request's auth-filter pass.
	 *
	 * @return array<int,array{callback:callable,priority:int,accepted_args:int}> Removed callbacks.
	 */
	private function suspend_zerospam_login_filter(): array {
		global $wp_filter;
		$hook = $wp_filter['wp_authenticate_user'] ?? null;
		if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return array();
		}

		$removed = array();
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}
			foreach ( $callbacks as $registered ) {
				$callback = is_array( $registered ) ? ( $registered['function'] ?? null ) : null;
				if ( ! is_array( $callback ) || 2 !== count( $callback ) || ! is_object( $callback[0] )
					|| 'ZeroSpam\\Modules\\Login\\Login' !== get_class( $callback[0] ) || 'process_form' !== $callback[1] ) {
					continue;
				}
				$numeric_priority = (int) $priority;
				if ( remove_filter( 'wp_authenticate_user', $callback, $numeric_priority ) ) {
					$removed[] = array(
						'callback'      => $callback,
						'priority'      => $numeric_priority,
						'accepted_args' => (int) ( $registered['accepted_args'] ?? 2 ),
					);
				}
			}
		}

		return $removed;
	}

	/**
	 * Insert or restore a verified supplemental phone.
	 *
	 * @param int    $user_id    User id.
	 * @param string $phone      Canonical phone.
	 * @param string $phone_hash Lookup hash.
	 * @return int
	 */
	private function upsert_verified_phone( int $user_id, string $phone, string $phone_hash ): int {
		global $wpdb;
		$wpdb->last_error = '';
		$locked           = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND kind = 'phone' FOR UPDATE",
				$user_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $locked ) ) {
			return 0;
		}
		if ( is_wp_error( $this->contact_capacity( $user_id, 'phone', $phone_hash ) ) ) {
			return 0;
		}
		$now             = gmdate( 'Y-m-d H:i:s' );
		$encrypted_phone = self::encrypt( $phone );
		if ( '' === $encrypted_phone ) {
			return 0;
		}
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::table( 'contact_points' ) . " (user_id,kind,value_encrypted,value_hash,status,verification_method,verified_at,voice_opt_in,login_enabled,created_at,updated_at) VALUES (%d,'phone',%s,%s,'verified','inbound_call',%s,0,1,%s,%s) ON DUPLICATE KEY UPDATE value_encrypted=VALUES(value_encrypted),status='verified',verification_method='inbound_call',verified_at=VALUES(verified_at),login_enabled=1,updated_at=VALUES(updated_at)",
			$user_id,
			$encrypted_phone,
			$phone_hash,
			$now,
			$now,
			$now
		);
		if ( false === $wpdb->query( $sql ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table( 'contact_points' ) . " WHERE user_id = %d AND kind = 'phone' AND value_hash = %s",
				$user_id,
				$phone_hash
			)
		);
	}

	/**
	 * Find and authenticate a browser-bound challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return object|WP_Error
	 */
	private function bound_challenge( WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'id' );
		$binding = $this->browser_binding( false );
		$csrf    = (string) $request->get_header( self::CSRF_HEADER );
		if ( '' === $binding || 1 !== preg_match( '/^[A-Za-z0-9_-]{32,128}$/', $csrf ) ) {
			return new WP_Error( 'digitalogic_call_missing', __( 'Verification not found.', 'digitalogic' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$wpdb->last_error = '';
		$row              = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'phone_challenges' ) . ' WHERE public_id = %s', $id ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'digitalogic_call_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! $row || ! hash_equals( (string) $row->binding_mac, self::lookup_hash( 'binding', $binding ) ) || ! hash_equals( (string) $row->csrf_mac, self::lookup_hash( 'csrf', $csrf ) ) ) {
			return new WP_Error( 'digitalogic_call_missing', __( 'Verification not found.', 'digitalogic' ), array( 'status' => 404 ) );
		}

		return $row;
	}

	/**
	 * Mark an overdue challenge expired.
	 *
	 * @param object $row Challenge row.
	 * @return object
	 */
	private function expire_if_needed( $row ) {
		if ( 'pending' === $row->status && strtotime( (string) $row->expires_at . ' UTC' ) <= time() ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'expired', version = version + 1 WHERE id = %d AND status = 'pending'", $row->id ) );
			$row->status = 'expired';
		}
		if ( 'verified' === $row->status && strtotime( (string) $row->consume_deadline . ' UTC' ) <= time() ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'phone_challenges' ) . " SET status = 'expired', version = version + 1 WHERE id = %d AND status = 'verified'", $row->id ) );
			$row->status = 'expired';
		}

		return $row;
	}

	/**
	 * Enforce conservative database-backed creation limits.
	 *
	 * @param string $phone_hash   Phone hash.
	 * @param string $binding_hash Binding hash.
	 * @param string $ip_hash      IP hash.
	 * @return true|WP_Error
	 */
	private function enforce_creation_limits( string $phone_hash, string $binding_hash, string $ip_hash ) {
		$limits = array(
			array( 'phone-15m', $phone_hash, 3, 15 * MINUTE_IN_SECONDS ),
			array( 'phone-hour', $phone_hash, 6, HOUR_IN_SECONDS ),
			array( 'phone-day', $phone_hash, 10, DAY_IN_SECONDS ),
			array( 'browser-15m', $binding_hash, 5, 15 * MINUTE_IN_SECONDS ),
			array( 'ip-15m', $ip_hash, 20, 15 * MINUTE_IN_SECONDS ),
		);
		foreach ( $limits as $limit ) {
			$result = $this->rate_hit( $limit[0], $limit[1], $limit[2], $limit[3] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Reserve both signed PBX attempt windows inside the event transaction.
	 *
	 * @param string $phone_hash Installation-keyed canonical caller hash.
	 * @return true|WP_Error
	 */
	private function reserve_pbx_attempt_capacity( string $phone_hash ) {
		global $wpdb;
		$now     = time();
		$windows = array(
			array(
				'name'    => 'pbx-fail-15m',
				'seconds' => 15 * MINUTE_IN_SECONDS,
				'limit'   => 5,
			),
			array(
				'name'    => 'pbx-fail-hour',
				'seconds' => HOUR_IN_SECONDS,
				'limit'   => 10,
			),
		);
		foreach ( $windows as &$window ) {
			$window['started'] = $now - ( $now % $window['seconds'] );
			$window['key']     = self::lookup_hash( 'rate', $window['name'] . '|' . $phone_hash . '|' . $window['started'] );
		}
		unset( $window );

		foreach ( $windows as $window ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::table( 'verification_rates' ) . ' (bucket_key,bucket_name,window_started,counter,updated_at) VALUES (%s,%s,%s,0,%s)',
					$window['key'],
					$window['name'],
					gmdate( 'Y-m-d H:i:s', $window['started'] ),
					gmdate( 'Y-m-d H:i:s', $now )
				)
			);
			if ( false === $inserted ) {
				return new WP_Error( 'digitalogic_call_rate_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
			}
		}

		foreach ( $windows as &$window ) {
			$wpdb->last_error = '';
			$counter          = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT counter FROM ' . self::table( 'verification_rates' ) . ' WHERE bucket_key = %s AND bucket_name = %s FOR UPDATE',
					$window['key'],
					$window['name']
				)
			);
			if ( '' !== (string) $wpdb->last_error || null === $counter ) {
				return new WP_Error( 'digitalogic_call_rate_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
			}
			$window['counter'] = (int) $counter;
			if ( $window['counter'] >= $window['limit'] ) {
				return $this->rate_error( max( 1, $window['started'] + $window['seconds'] - $now ) );
			}
		}
		unset( $window );

		foreach ( $windows as $window ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table( 'verification_rates' ) . ' SET counter = counter + 1, updated_at = %s WHERE bucket_key = %s AND bucket_name = %s AND counter = %d',
					gmdate( 'Y-m-d H:i:s', $now ),
					$window['key'],
					$window['name'],
					$window['counter']
				)
			);
			if ( 1 !== (int) $updated ) {
				return new WP_Error( 'digitalogic_call_rate_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
			}
		}
		return true;
	}

	/**
	 * Atomically increment or inspect a fixed-window rate counter.
	 *
	 * @param string $name       Bucket name.
	 * @param string $identifier Privacy-safe identifier.
	 * @param int    $limit      Maximum events.
	 * @param int    $window     Window seconds.
	 * @param bool   $increment  Whether to consume capacity.
	 * @return true|WP_Error
	 */
	private function rate_hit( string $name, string $identifier, int $limit, int $window, bool $increment = true ) {
		global $wpdb;
		$now          = time();
		$window_start = $now - ( $now % $window );
		$key          = self::lookup_hash( 'rate', $name . '|' . $identifier . '|' . $window_start );
		if ( $increment ) {
			$sql = $wpdb->prepare(
				'INSERT INTO ' . self::table( 'verification_rates' ) . ' (bucket_key,bucket_name,window_started,counter,updated_at) VALUES (%s,%s,%s,1,%s) ON DUPLICATE KEY UPDATE counter=counter+1,updated_at=VALUES(updated_at)',
				$key,
				$name,
				gmdate( 'Y-m-d H:i:s', $window_start ),
				gmdate( 'Y-m-d H:i:s', $now )
			);
			if ( false === $wpdb->query( $sql ) ) {
				return new WP_Error( 'digitalogic_call_rate_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
			}
		}
		$wpdb->last_error = '';
		$count_value      = $wpdb->get_var( $wpdb->prepare( 'SELECT counter FROM ' . self::table( 'verification_rates' ) . ' WHERE bucket_key = %s AND bucket_name = %s', $key, $name ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'digitalogic_call_rate_storage', __( 'Verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		$count    = (int) $count_value;
		$exceeded = $increment ? $count > $limit : $count >= $limit;
		return $exceeded ? $this->rate_error( max( 1, $window_start + $window - $now ) ) : true;
	}

	/**
	 * Create a standard 429 response.
	 *
	 * @param int $retry_after Seconds.
	 * @return WP_Error
	 */
	private function rate_error( int $retry_after ): WP_Error {
		return new WP_Error(
			'digitalogic_call_rate',
			__( 'Too many verification attempts. Please wait and try again.', 'digitalogic' ),
			array(
				'status'      => 429,
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * Read or mint the opaque HttpOnly browser binding.
	 *
	 * @param bool $create Create when absent.
	 * @return string|WP_Error
	 */
	private function browser_binding( bool $create = true ) {
		$current = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $current ) ) {
			return $current;
		}
		if ( ! $create || headers_sent() ) {
			return $create ? new WP_Error( 'digitalogic_call_cookie', __( 'Cookies are required for call verification.', 'digitalogic' ), array( 'status' => 400 ) ) : '';
		}
		$value  = self::random_token();
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => 0,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $value;
		return $value;
	}

	/**
	 * Require the request Origin or Referer to match the configured site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function same_origin( WP_REST_Request $request ): bool {
		$source = (string) $request->get_header( 'origin' );
		if ( '' === $source ) {
			$source = (string) $request->get_header( 'referer' );
		}
		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $source );
		if ( ! is_array( $expected ) || ! is_array( $actual ) ) {
			return false;
		}
		$expected_port = isset( $expected['port'] ) ? (int) $expected['port'] : ( 'https' === ( $expected['scheme'] ?? '' ) ? 443 : 80 );
		$actual_port   = isset( $actual['port'] ) ? (int) $actual['port'] : ( 'https' === ( $actual['scheme'] ?? '' ) ? 443 : 80 );
		return strtolower( (string) ( $expected['scheme'] ?? '' ) ) === strtolower( (string) ( $actual['scheme'] ?? '' ) )
			&& strtolower( (string) ( $expected['host'] ?? '' ) ) === strtolower( (string) ( $actual['host'] ?? '' ) )
			&& $expected_port === $actual_port;
	}

	/**
	 * Check a REST nonce for authenticated contact mutations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function valid_rest_nonce( WP_REST_Request $request ): bool {
		return (bool) wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' );
	}

	/**
	 * Best available request IP; it is used only after keyed hashing.
	 *
	 * @return string
	 */
	private function request_ip(): string {
		$value = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $value, FILTER_VALIDATE_IP ) ? $value : 'unknown';
	}

	/**
	 * Atomically claim an idempotency event or return its completed result.
	 *
	 * @param string $event_id Event id.
	 * @param string $call_id  PBX call id.
	 * @param string $raw_body Exact signed body bytes.
	 * @return int|array|WP_Error One for a new event, two for exact recovery.
	 */
	private function claim_pbx_event( string $event_id, string $call_id, string $raw_body ) {
		global $wpdb;
		$processing = wp_json_encode( array( 'processing' => true ) );
		$call_hash  = self::pbx_event_call_hash( $call_id, $raw_body );
		$inserted   = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table( 'pbx_events' ) . ' (event_id,call_hash,result_json,created_at) VALUES (%s,%s,%s,%s)',
				$event_id,
				$call_hash,
				$processing,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $inserted ) {
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( 1 === (int) $inserted ) {
			return 1;
		}

		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT call_hash,result_json FROM ' . self::table( 'pbx_events' ) . ' WHERE event_id = %s',
				$event_id
			)
		);
		if ( ! $row || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		if ( ! hash_equals( (string) $row->call_hash, $call_hash ) ) {
			return new WP_Error( 'digitalogic_pbx_event_conflict', __( 'PBX event conflict.', 'digitalogic' ), array( 'status' => 409 ) );
		}
		$result = json_decode( (string) $row->result_json, true );
		if ( self::is_completed_pbx_result( $result ) ) {
			return $result;
		}
		// A matching processing sentinel enters the event-row-locked transaction;
		// duplicates then either observe the first result or recover a rolled-back
		// attempt without bypassing its rate reservation.
		return 2;
	}

	/**
	 * Bind an event id to the exact call and signed body without retaining PII.
	 *
	 * @param string $call_id  PBX call id.
	 * @param string $raw_body Exact signed body bytes.
	 * @return string
	 */
	private static function pbx_event_call_hash( string $call_id, string $raw_body ): string {
		return self::lookup_hash( 'call-event', $call_id . '|' . hash( 'sha256', $raw_body ) );
	}

	/**
	 * Recognize only the callback's exact persisted terminal result shape.
	 *
	 * @param mixed $result Decoded event result.
	 * @return bool
	 */
	private static function is_completed_pbx_result( $result ): bool {
		return is_array( $result ) && count( $result ) === 2
			&& array_key_exists( 'success', $result ) && is_bool( $result['success'] )
			&& array_key_exists( 'verified', $result ) && is_bool( $result['verified'] );
	}

	/**
	 * Persist the event result and commit the caller's challenge transaction.
	 *
	 * @param string $event_id Event id.
	 * @param array  $result   Exact callback result.
	 * @return WP_REST_Response|WP_Error
	 */
	private function commit_pbx_event_transaction( string $event_id, array $result ) {
		global $wpdb;
		if ( ! $this->complete_pbx_event( $event_id, $result ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_pbx_storage', __( 'PBX verification is temporarily unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Complete only a matching processing sentinel and report persistence.
	 *
	 * @param string $event_id Event id.
	 * @param array  $result   Final response.
	 */
	private function complete_pbx_event( string $event_id, array $result ): bool {
		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table( 'pbx_events' ) . ' SET result_json = %s WHERE event_id = %s AND result_json = %s',
				wp_json_encode( $result ),
				$event_id,
				wp_json_encode( array( 'processing' => true ) )
			)
		);
		if ( 1 === (int) $updated ) {
			return true;
		}
		$wpdb->last_error = '';
		$stored           = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT result_json FROM ' . self::table( 'pbx_events' ) . ' WHERE event_id = %s',
				$event_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}
		$decoded = is_string( $stored ) ? json_decode( $stored, true ) : null;
		return is_array( $decoded ) && $decoded === $result;
	}

	/**
	 * Normalize only the site's inbound DID; customer blocking is intentional.
	 *
	 * @param mixed $value Raw called number.
	 * @return string
	 */
	private static function normalize_called_did( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value   = self::ascii_digits( trim( (string) $value ) );
		$allowed = array( '+982166754123', '00982166754123', '982166754123', '02166754123' );
		return in_array( $value, $allowed, true ) ? self::ACCESS_DID : '';
	}

	/**
	 * Apply no-store semantics to security- and PII-bearing REST responses.
	 *
	 * @param mixed           $response REST response.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function add_no_store_headers( $response, $server, WP_REST_Request $request ) {
		$route = $request->get_route();
		if ( str_starts_with( $route, '/' . self::REST_NAMESPACE . self::ROUTE )
			|| str_starts_with( $route, '/' . self::REST_NAMESPACE . '/contacts' ) ) {
			if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
				$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
				$response->header( 'Pragma', 'no-cache' );
			}
		}
		return $response;
	}

	/**
	 * Strictly decode the base64 verification secret from wp-config.php.
	 *
	 * @return string Raw key bytes or empty string.
	 */
	private static function pbx_secret(): string {
		if ( ! defined( 'DIGITALOGIC_PBX_VERIFY_SECRET' ) || ! is_string( DIGITALOGIC_PBX_VERIFY_SECRET ) ) {
			return '';
		}
		return self::decode_pbx_secret( DIGITALOGIC_PBX_VERIFY_SECRET );
	}

	/**
	 * Whether the inbound verification trust boundary is usable.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return self::is_schema_ready() && '' !== self::pbx_secret();
	}

	/**
	 * Strictly decode a canonical standard-base64 key for tests and config checks.
	 *
	 * @param mixed $encoded Base64 text.
	 * @return string Raw bytes or empty string.
	 */
	public static function decode_pbx_secret( $encoded ): string {
		if ( ! is_string( $encoded ) || '' === $encoded || 0 !== strlen( $encoded ) % 4
			|| 1 !== preg_match( '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/', $encoded ) ) {
			return '';
		}
		$decoded = base64_decode( $encoded, true );
		return is_string( $decoded ) && strlen( $decoded ) >= 32 && hash_equals( base64_encode( $decoded ), $encoded ) ? $decoded : '';
	}

	/**
	 * Encrypt contact PII at rest with an installation-specific key.
	 *
	 * @param string $plain Plaintext.
	 * @return string
	 */
	private static function encrypt( string $plain ): string {
		$key = self::key( 'contact-encryption' );
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return is_string( $cipher ) ? 'o1:' . base64_encode( $iv . $tag . $cipher ) : '';
		}
		return '';
	}

	/**
	 * Decrypt contact PII.
	 *
	 * @param string $stored Stored envelope.
	 * @return string
	 */
	private static function decrypt( string $stored ): string {
		$key = self::key( 'contact-encryption' );
		if ( str_starts_with( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, 3 ), true );
			if ( ! is_string( $raw ) || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
			return is_string( $plain ) ? $plain : '';
		}
		if ( str_starts_with( $stored, 'o1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 3 ), true );
			if ( ! is_string( $raw ) || strlen( $raw ) <= 28 ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return is_string( $plain ) ? $plain : '';
		}
		return '';
	}

	/**
	 * Derive an installation-specific binary key.
	 *
	 * @param string $purpose Domain separator.
	 * @return string
	 */
	private static function key( string $purpose ): string {
		return hash( 'sha256', 'digitalogic|' . $purpose . '|' . wp_salt( 'auth' ), true );
	}

	/**
	 * Generate a keyed equality lookup without retaining plaintext.
	 *
	 * @param string $purpose Domain separator.
	 * @param string $value   Value.
	 * @return string
	 */
	private static function lookup_hash( string $purpose, string $value ): string {
		return hash_hmac( 'sha256', $purpose . "\n" . $value, self::key( 'lookup' ) );
	}

	/**
	 * Bind a code to its opaque challenge id.
	 *
	 * @param string $public_id Challenge id.
	 * @param string $code      Six-digit code.
	 * @return string
	 */
	private static function code_mac( string $public_id, string $code ): string {
		return hash_hmac( 'sha256', $public_id . "\n" . $code, self::key( 'verification-code' ) );
	}

	/**
	 * Generate a URL-safe 256-bit token.
	 *
	 * @return string
	 */
	private static function random_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Translate Persian and Arabic-Indic digits.
	 *
	 * @param string $value Input.
	 * @return string
	 */
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

	/**
	 * Construct a plugin-owned table name.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	private static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_' . $suffix;
	}
}
