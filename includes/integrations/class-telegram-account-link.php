<?php
/**
 * WordPress-account linking for the Digitalogic customer assistant.
 *
 * User-facing flows stay inside the normal WooCommerce account session. The
 * external consumer gets only a signed, pseudonymous eligibility result.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides short-lived account-link tokens and a signed server boundary.
 */
final class Digitalogic_Telegram_Account_Link {

	private const REST_NAMESPACE       = 'digitalogic/v1';
	private const CONSUME_ROUTE        = '/assistant/account/consume';
	private const STATUS_ROUTE         = '/assistant/account/status';
	private const ACCOUNT_ENDPOINT     = 'assistant-account';
	private const SCHEMA_OPTION        = 'digitalogic_assistant_account_schema_version';
	private const REWRITE_OPTION       = 'digitalogic_assistant_account_rewrite_version';
	private const SERVER_SECRET        = 'DIGITALOGIC_ASSISTANT_HMAC_SECRET';
	private const SERVER_SECRET_FILE   = '/etc/digitalogic/assistant-hmac.secret';
	private const TOKEN_TTL            = 600;
	private const SERVER_CLOCK_SKEW    = 60;
	private const SERVER_NONCE_TTL     = 180;
	private const USER_RATE_LIMIT      = 3;
	private const IP_RATE_LIMIT        = 20;
	private const RATE_WINDOW          = 600;
	private const STATUS_RATE_LIMIT    = 60;
	private const STATUS_RATE_WINDOW   = 60;
	private const CONSUME_RATE_LIMIT   = 10;
	private const CONSUME_RATE_WINDOW  = 600;
	private const TERMINAL_RETENTION   = 86400;
	private const AUDIT_RETENTION      = 7776000;
	private const ACCOUNT_NONCE_ACTION = 'digitalogic_assistant_account';
	private const CLEANUP_ACTION        = 'digitalogic_assistant_account_cleanup';
	private const CUSTOMER_ROLES        = array( 'customer', 'subscriber' );
	private const STAFF_ROLES           = array( 'administrator', 'shop_manager', 'editor_and_store_manager' );

	/** @var self|null */
	private static $instance = null;

	/** @var bool|null */
	private static $schema_ready = null;

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
	 * Register runtime hooks.
	 */
	private function __construct() {
		// Data lifecycle and privacy duties remain active even if WooCommerce is
		// temporarily disabled. Customer UI and signed API operations do not.
		add_action( 'deleted_user', array( $this, 'cleanup_deleted_user' ), 10, 1 );
		add_action( self::CLEANUP_ACTION, array( $this, 'cleanup_storage' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );

		if ( ! self::woocommerce_ready() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_account_endpoint' ), 8 );
		add_action( 'init', array( $this, 'maybe_refresh_rewrite_rules' ), 99 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_no_store_headers' ), 10, 3 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_item' ), 30 );
		add_action( 'woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render_account_endpoint' ) );
		add_action( 'woocommerce_account_dashboard', array( $this, 'render_account_card' ), 35 );
		add_action( 'template_redirect', array( $this, 'maybe_disable_account_cache' ), 5 );
	}

	/**
	 * Create and verify the dedicated transactional storage.
	 *
	 * @return bool
	 */
	public static function install(): bool {
		global $wpdb;

		self::$schema_ready = false;
		$installed_version  = (string) get_option( self::SCHEMA_OPTION, '' );
		$collate            = $wpdb->get_charset_collate();
		$links              = self::table( 'links' );
		$tokens             = self::table( 'tokens' );
		$nonces             = self::table( 'nonces' );
		$rates              = self::table( 'rates' );
		$audit              = self::table( 'audit' );

		$statements = array(
			"CREATE TABLE $links (
				user_id bigint(20) UNSIGNED NOT NULL,
				user_id_hash char(64) NOT NULL,
				telegram_id_hash char(64) DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'unlinked',
				linked_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				checked_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (user_id),
				UNIQUE KEY user_identity (user_id_hash),
				UNIQUE KEY telegram_identity (telegram_id_hash),
				KEY status_updated (status,updated_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $tokens (
				token_hash char(64) NOT NULL,
				user_id bigint(20) UNSIGNED NOT NULL,
				pending_slot tinyint(1) UNSIGNED DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				consumed_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (token_hash),
				UNIQUE KEY user_pending_slot (user_id,pending_slot),
				KEY user_status_expiry (user_id,status,expires_at),
				KEY status_updated (status,updated_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $nonces (
				nonce_hash char(64) NOT NULL,
				created_at datetime NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (nonce_hash),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $rates (
				bucket_hash char(64) NOT NULL,
				window_started datetime NOT NULL,
				request_count int(10) UNSIGNED NOT NULL DEFAULT 0,
				blocked_until datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (bucket_hash),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $audit (
				id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				event_name varchar(40) NOT NULL,
				user_ref char(64) NOT NULL,
				identity_ref char(64) NOT NULL DEFAULT '',
				outcome varchar(20) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_created (user_ref,created_at),
				KEY event_created (event_name,created_at)
			) ENGINE=InnoDB $collate;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}
		if ( ! self::migrate_legacy_tokens( $installed_version, $tokens ) ) {
			delete_option( self::SCHEMA_OPTION );
			return false;
		}

		$column_spec = static function ( string $type, bool $nullable, $default = null, string $extra = '' ): array {
			return array(
				'type'     => strtolower( preg_replace( '/\s+/', ' ', trim( $type ) ) ),
				'nullable' => $nullable,
				'default'  => $default,
				'extra'    => strtolower( preg_replace( '/\s+/', ' ', trim( $extra ) ) ),
			);
		};

		$requirements = array(
			$links  => array(
				'columns' => array(
					'user_id'          => $column_spec( 'bigint(20) unsigned', false ),
					'user_id_hash'     => $column_spec( 'char(64)', false ),
					'telegram_id_hash' => $column_spec( 'char(64)', true ),
					'status'           => $column_spec( 'varchar(20)', false, 'unlinked' ),
					'linked_at'        => $column_spec( 'datetime', true ),
					'revoked_at'       => $column_spec( 'datetime', true ),
					'checked_at'       => $column_spec( 'datetime', true ),
					'updated_at'       => $column_spec( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'           => array( 'unique' => true, 'columns' => array( 'user_id' ) ),
					'user_identity'     => array( 'unique' => true, 'columns' => array( 'user_id_hash' ) ),
					'telegram_identity' => array( 'unique' => true, 'columns' => array( 'telegram_id_hash' ) ),
					'status_updated'    => array( 'unique' => false, 'columns' => array( 'status', 'updated_at' ) ),
				),
			),
			$tokens => array(
				'columns' => array(
					'token_hash'  => $column_spec( 'char(64)', false ),
					'user_id'     => $column_spec( 'bigint(20) unsigned', false ),
					'pending_slot' => $column_spec( 'tinyint(1) unsigned', true ),
					'status'      => $column_spec( 'varchar(20)', false, 'pending' ),
					'created_at'  => $column_spec( 'datetime', false ),
					'expires_at'  => $column_spec( 'datetime', false ),
					'consumed_at' => $column_spec( 'datetime', true ),
					'updated_at'  => $column_spec( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'            => array( 'unique' => true, 'columns' => array( 'token_hash' ) ),
					'user_pending_slot'  => array( 'unique' => true, 'columns' => array( 'user_id', 'pending_slot' ) ),
					'user_status_expiry' => array( 'unique' => false, 'columns' => array( 'user_id', 'status', 'expires_at' ) ),
					'status_updated'     => array( 'unique' => false, 'columns' => array( 'status', 'updated_at' ) ),
				),
			),
			$nonces => array(
				'columns' => array(
					'nonce_hash' => $column_spec( 'char(64)', false ),
					'created_at' => $column_spec( 'datetime', false ),
					'expires_at' => $column_spec( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'    => array( 'unique' => true, 'columns' => array( 'nonce_hash' ) ),
					'expires_at' => array( 'unique' => false, 'columns' => array( 'expires_at' ) ),
				),
			),
			$rates  => array(
				'columns' => array(
					'bucket_hash'    => $column_spec( 'char(64)', false ),
					'window_started' => $column_spec( 'datetime', false ),
					'request_count'  => $column_spec( 'int(10) unsigned', false, '0' ),
					'blocked_until'  => $column_spec( 'datetime', true ),
					'updated_at'     => $column_spec( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'    => array( 'unique' => true, 'columns' => array( 'bucket_hash' ) ),
					'updated_at' => array( 'unique' => false, 'columns' => array( 'updated_at' ) ),
				),
			),
			$audit  => array(
				'columns' => array(
					'id'           => $column_spec( 'bigint(20) unsigned', false, null, 'auto_increment' ),
					'event_name'   => $column_spec( 'varchar(40)', false ),
					'user_ref'     => $column_spec( 'char(64)', false ),
					'identity_ref' => $column_spec( 'char(64)', false, '' ),
					'outcome'      => $column_spec( 'varchar(20)', false ),
					'created_at'   => $column_spec( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'       => array( 'unique' => true, 'columns' => array( 'id' ) ),
					'user_created'  => array( 'unique' => false, 'columns' => array( 'user_ref', 'created_at' ) ),
					'event_created' => array( 'unique' => false, 'columns' => array( 'event_name', 'created_at' ) ),
				),
			),
		);

		foreach ( $requirements as $table => $required ) {
			if ( ! self::verify_storage_table( $table, $required['columns'], $required['indexes'] ) ) {
				delete_option( self::SCHEMA_OPTION );
				return false;
			}
		}
		if ( ! self::ensure_cleanup_schedule() ) {
			delete_option( self::SCHEMA_OPTION );
			return false;
		}

		update_option( self::SCHEMA_OPTION, DIGITALOGIC_ASSISTANT_ACCOUNT_SCHEMA_VERSION, false );
		self::$schema_ready = DIGITALOGIC_ASSISTANT_ACCOUNT_SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' );

		return self::$schema_ready;
	}

	/**
	 * Add the WooCommerce account endpoint before rewrite rules are flushed.
	 */
	public static function register_account_endpoint(): void {
		if ( ! self::woocommerce_ready() ) {
			return;
		}
		add_rewrite_endpoint( self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Remove only this feature's scheduled cleanup on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_ACTION );
	}

	/**
	 * Refresh account rewrites once after a feature upgrade.
	 */
	public function maybe_refresh_rewrite_rules(): void {
		if ( ! self::woocommerce_ready() ) {
			return;
		}
		if ( DIGITALOGIC_ASSISTANT_ACCOUNT_SCHEMA_VERSION === (string) get_option( self::REWRITE_OPTION, '' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, DIGITALOGIC_ASSISTANT_ACCOUNT_SCHEMA_VERSION, false );
	}

	/**
	 * Whether the installed schema is ready for use.
	 *
	 * @return bool
	 */
	public static function is_schema_ready(): bool {
		if ( null === self::$schema_ready ) {
			self::$schema_ready = defined( 'DIGITALOGIC_ASSISTANT_ACCOUNT_SCHEMA_VERSION' )
				&& DIGITALOGIC_ASSISTANT_ACCOUNT_SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '' );
		}

		return self::$schema_ready;
	}

	/**
	 * Register the two signed server-to-server endpoints.
	 */
	public function register_rest_routes(): void {
		if ( ! self::woocommerce_ready() ) {
			return;
		}
		register_rest_route(
			self::REST_NAMESPACE,
			self::CONSUME_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'consume_link' ),
				'permission_callback' => array( $this, 'authorize_server_request' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::STATUS_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'account_status' ),
				'permission_callback' => array( $this, 'authorize_server_request' ),
			)
		);
	}

	/**
	 * Authenticate a server request over its exact raw body.
	 *
	 * The signature is lowercase hex HMAC-SHA256 over:
	 * METHOD + "\n" + ROUTE + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + RAW_BODY
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_server_request( WP_REST_Request $request ) {
		if ( ! self::woocommerce_ready() || ! self::supports_site_topology() || ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_assistant_unavailable', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		$secret = self::server_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'digitalogic_assistant_unavailable', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( 0 !== strpos( $content_type, 'application/json' ) ) {
			return new WP_Error( 'digitalogic_assistant_content_type', 'درخواست معتبر نیست.', array( 'status' => 415 ) );
		}

		$raw_body = (string) $request->get_body();
		if ( '' === $raw_body || strlen( $raw_body ) > 4096 ) {
			return new WP_Error( 'digitalogic_assistant_body', 'درخواست معتبر نیست.', array( 'status' => 400 ) );
		}

		$timestamp = trim( (string) $request->get_header( 'x-digitalogic-timestamp' ) );
		$nonce     = trim( (string) $request->get_header( 'x-digitalogic-nonce' ) );
		$signature = strtolower( trim( (string) $request->get_header( 'x-digitalogic-signature' ) ) );

		if ( ! preg_match( '/^[0-9]{10}$/', $timestamp )
			|| ! preg_match( '/^[A-Za-z0-9_-]{22,64}$/', $nonce )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return new WP_Error( 'digitalogic_assistant_auth', 'دسترسی مجاز نیست.', array( 'status' => 401 ) );
		}

		$request_time = (int) $timestamp;
		if ( abs( time() - $request_time ) > self::SERVER_CLOCK_SKEW ) {
			return new WP_Error( 'digitalogic_assistant_auth', 'دسترسی مجاز نیست.', array( 'status' => 401 ) );
		}

		$canonical = strtoupper( (string) $request->get_method() )
			. "\n" . (string) $request->get_route()
			. "\n" . $timestamp
			. "\n" . $nonce
			. "\n" . $raw_body;
		$expected  = hash_hmac( 'sha256', $canonical, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'digitalogic_assistant_auth', 'دسترسی مجاز نیست.', array( 'status' => 401 ) );
		}

		$nonce_result = $this->claim_server_nonce( $nonce, $secret );
		if ( is_wp_error( $nonce_result ) ) {
			return $nonce_result;
		}

		return true;
	}

	/**
	 * Atomically consume a one-time link token and bind an external identity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function consume_link( WP_REST_Request $request ) {
		global $wpdb;

		$payload     = $request->get_json_params();
		$token       = is_array( $payload ) ? (string) ( $payload['token'] ?? '' ) : '';
		$telegram_id = is_array( $payload ) ? (string) ( $payload['telegram_id'] ?? '' ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) || ! self::valid_telegram_id( $telegram_id ) ) {
			return new WP_Error( 'digitalogic_assistant_request', 'درخواست معتبر نیست.', array( 'status' => 400 ) );
		}

		$token_hash    = self::token_hash( $token );
		$identity_hash = self::identity_hash( $telegram_id );
		$tokens         = self::table( 'tokens' );
		$links          = self::table( 'links' );
		$now            = self::utc_now();
		$rate_result    = $this->enforce_signed_identity_rate( 'consume', $identity_hash );
		if ( is_wp_error( $rate_result ) ) {
			return $rate_result;
		}

		// Read only a user hint first, then take locks in the same stable order as
		// issuance and revocation: account row, token row, external identity.
		$hint_user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM $tokens
				WHERE token_hash = %s AND status = 'pending' AND pending_slot = 1",
				$token_hash
			)
		);
		if ( $hint_user_id < 1 ) {
			return new WP_Error( 'digitalogic_assistant_token', 'کد اتصال معتبر نیست یا منقضی شده است.', array( 'status' => 400 ) );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}
		$locked_user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM $links WHERE user_id = %d FOR UPDATE",
				$hint_user_id
			)
		);
		if ( null === $locked_user_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_token', 'کد اتصال معتبر نیست یا منقضی شده است.', array( 'status' => 400 ) );
		}

		$token_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT token_hash,user_id,pending_slot,status,expires_at
				FROM $tokens WHERE token_hash = %s FOR UPDATE",
				$token_hash
			),
			ARRAY_A
		);

		if ( ! is_array( $token_row )
			|| (int) $token_row['user_id'] !== $hint_user_id
			|| 1 !== (int) $token_row['pending_slot']
			|| 'pending' !== (string) $token_row['status']
			|| strtotime( (string) $token_row['expires_at'] . ' UTC' ) <= time() ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_token', 'کد اتصال معتبر نیست یا منقضی شده است.', array( 'status' => 400 ) );
		}

		$user_id = $hint_user_id;
		$user    = self::active_registered_user( $user_id );
		if ( ! $user ) {
			$token_result = $wpdb->update(
				$tokens,
				array(
					'pending_slot' => null,
					'status'       => 'rejected',
					'updated_at'   => $now,
				),
				array(
					'token_hash'  => $token_hash,
					'status'      => 'pending',
					'pending_slot' => 1,
				),
				array( null, '%s', '%s' ),
				array( '%s', '%s', '%d' )
			);
			if ( false === $token_result || ! $this->write_audit( 'link_rejected', $user_id, $identity_hash, 'inactive' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
			}
			return new WP_Error( 'digitalogic_assistant_account', 'این حساب شرایط استفاده از اتصال را ندارد.', array( 'status' => 403 ) );
		}

		$identity_owner = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM $links WHERE telegram_id_hash = %s FOR UPDATE",
				$identity_hash
			)
		);
		if ( null !== $identity_owner && (int) $identity_owner !== $user_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_identity', 'این شناسه قبلاً به حساب دیگری متصل شده است.', array( 'status' => 409 ) );
		}

		$link_result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $links
					(user_id,user_id_hash,telegram_id_hash,status,linked_at,revoked_at,checked_at,updated_at)
				VALUES (%d,%s,%s,'linked',%s,NULL,%s,%s)
				ON DUPLICATE KEY UPDATE
					user_id_hash = VALUES(user_id_hash),
					telegram_id_hash = VALUES(telegram_id_hash),
					status = 'linked',
					linked_at = VALUES(linked_at),
					revoked_at = NULL,
					checked_at = VALUES(checked_at),
					updated_at = VALUES(updated_at)",
				$user_id,
				self::private_hash( 'user-identity', (string) $user_id ),
				$identity_hash,
				$now,
				$now,
				$now
			)
		);
		if ( false === $link_result ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		$token_result = $wpdb->update(
			$tokens,
			array(
				'pending_slot' => null,
				'status'       => 'consumed',
				'consumed_at'  => $now,
				'updated_at'   => $now,
			),
			array(
				'token_hash'  => $token_hash,
				'status'      => 'pending',
				'pending_slot' => 1,
			),
			array( null, '%s', '%s', '%s' ),
			array( '%s', '%s', '%d' )
		);
		if ( 1 !== $token_result || ! $this->write_audit( 'link_bound', $user_id, $identity_hash, 'success' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}
		$this->cleanup_storage();

		return $this->no_store_response( self::eligibility_payload( $user, true ), 200 );
	}

	/**
	 * Return current eligibility for an already linked identity.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function account_status( WP_REST_Request $request ) {
		global $wpdb;

		$payload     = $request->get_json_params();
		$telegram_id = is_array( $payload ) ? (string) ( $payload['telegram_id'] ?? '' ) : '';
		if ( ! self::valid_telegram_id( $telegram_id ) ) {
			return new WP_Error( 'digitalogic_assistant_request', 'درخواست معتبر نیست.', array( 'status' => 400 ) );
		}

		$identity_hash = self::identity_hash( $telegram_id );
		$links         = self::table( 'links' );
		$rate_result   = $this->enforce_signed_identity_rate( 'status', $identity_hash );
		if ( is_wp_error( $rate_result ) ) {
			return $rate_result;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}
		$link = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id,status FROM $links WHERE telegram_id_hash = %s LIMIT 1 FOR UPDATE",
				$identity_hash
			),
			ARRAY_A
		);

		if ( ! is_array( $link ) || 'linked' !== (string) $link['status'] ) {
			$wpdb->query( 'COMMIT' );
			return $this->no_store_response( self::eligibility_payload( null, false ), 200 );
		}

		$user_id = (int) $link['user_id'];
		$user    = self::active_registered_user( $user_id );
		if ( ! $user ) {
			$now     = self::utc_now();
			$updated = $wpdb->update(
				$links,
				array(
					'telegram_id_hash' => null,
					'status'           => 'disabled',
					'revoked_at'       => $now,
					'checked_at'       => $now,
					'updated_at'       => $now,
				),
				array(
					'user_id'           => $user_id,
					'telegram_id_hash' => $identity_hash,
					'status'            => 'linked',
				),
				array( null, '%s', '%s', '%s', '%s' ),
				array( '%d', '%s', '%s' )
			);
			if ( false === $updated || ! $this->write_audit( 'link_disabled', $user_id, $identity_hash, 'inactive' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
			}
			$wpdb->query( 'COMMIT' );
			return $this->no_store_response( self::eligibility_payload( null, false ), 200 );
		}

		$checked_at = self::utc_now();
		$updated    = $wpdb->update(
			$links,
			array(
				'checked_at' => $checked_at,
				'updated_at' => $checked_at,
			),
			array(
				'user_id'           => $user_id,
				'telegram_id_hash' => $identity_hash,
				'status'            => 'linked',
			),
			array( '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		return $this->no_store_response( self::eligibility_payload( $user, true ), 200 );
	}

	/**
	 * Insert the Persian account menu entry before logout.
	 *
	 * @param array $items Existing items.
	 * @return array
	 */
	public function add_account_menu_item( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ACCOUNT_ENDPOINT ] = 'اتصال همراه دیجیتالاجیک';
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render a compact account-dashboard card.
	 */
	public function render_account_card(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
		<section class="digitalogic-assistant-account-card" dir="rtl" style="margin:1.5rem 0;padding:1.25rem;border:1px solid currentColor;border-radius:12px">
			<h2 style="margin-top:0">همراه دیجیتالاجیک</h2>
			<p>برای دریافت پاسخ‌ها و خدمات مرتبط با حساب خود، حساب کاربری را به گفتگوی رسمی همراه دیجیتالاجیک متصل کنید.</p>
			<p><a class="button" href="<?php echo esc_url( wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT ) ); ?>">مدیریت اتصال</a></p>
		</section>
		<?php
	}

	/**
	 * Render and process the WooCommerce account linking page.
	 */
	public function render_account_endpoint(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id     = get_current_user_id();
		$issued_code = '';
		$message     = '';
		$is_error    = false;

		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			$nonce  = isset( $_POST['digitalogic_assistant_nonce'] )
				? sanitize_text_field( wp_unslash( $_POST['digitalogic_assistant_nonce'] ) )
				: '';
			$action = isset( $_POST['digitalogic_assistant_action'] )
				? sanitize_key( wp_unslash( $_POST['digitalogic_assistant_action'] ) )
				: '';

			if ( ! wp_verify_nonce( $nonce, self::ACCOUNT_NONCE_ACTION ) ) {
				$message  = 'درخواست معتبر نیست. صفحه را تازه کنید و دوباره تلاش کنید.';
				$is_error = true;
			} elseif ( 'create' === $action ) {
				$result = $this->create_link_token( $user_id );
				if ( is_wp_error( $result ) ) {
					$message  = $this->persian_account_error( $result );
					$is_error = true;
				} else {
					$issued_code = $result;
					$message     = 'کد اتصال تازه ساخته شد و تا ۱۰ دقیقه اعتبار دارد.';
				}
			} elseif ( 'revoke' === $action ) {
				$result = $this->revoke_user_link( $user_id );
				if ( is_wp_error( $result ) ) {
					$message  = 'لغو اتصال انجام نشد. کمی بعد دوباره تلاش کنید.';
					$is_error = true;
				} else {
					$message = 'اتصال همراه دیجیتالاجیک با این حساب لغو شد.';
				}
			}
		}

		$state = $this->user_link_state( $user_id );
		?>
		<section class="digitalogic-assistant-account" dir="rtl">
			<h2>همراه دیجیتالاجیک</h2>
			<p>این اتصال فقط برای شناسایی امن حساب شما در گفتگوی رسمی همراه دیجیتالاجیک است و دسترسی مدیریتی ایجاد نمی‌کند.</p>

			<?php if ( '' !== $message ) : ?>
				<div class="woocommerce-<?php echo $is_error ? 'error' : 'message'; ?>" role="<?php echo $is_error ? 'alert' : 'status'; ?>">
					<?php echo esc_html( $message ); ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $issued_code ) : ?>
				<div style="margin:1rem 0;padding:1rem;border:1px solid currentColor;border-radius:10px">
					<p><strong>کد یک‌بارمصرف اتصال</strong></p>
					<p><code dir="ltr" style="display:inline-block;word-break:break-all;user-select:all"><?php echo esc_html( $issued_code ); ?></code></p>
					<p>این کد را فقط در گفتگوی رسمی همراه دیجیتالاجیک وارد کنید. کد را برای شخص دیگری نفرستید.</p>
				</div>
			<?php endif; ?>

			<p>
				وضعیت:
				<strong><?php echo 'linked' === $state ? 'متصل' : ( 'pending' === $state ? 'در انتظار اتصال' : 'متصل نیست' ); ?></strong>
			</p>

			<?php if ( self::is_operational() ) : ?>
				<form method="post">
					<?php wp_nonce_field( self::ACCOUNT_NONCE_ACTION, 'digitalogic_assistant_nonce' ); ?>
					<input type="hidden" name="digitalogic_assistant_action" value="create">
					<button class="button" type="submit">ساخت کد اتصال تازه</button>
				</form>
				<?php if ( 'linked' === $state || 'pending' === $state ) : ?>
					<form method="post" style="margin-top:.75rem">
						<?php wp_nonce_field( self::ACCOUNT_NONCE_ACTION, 'digitalogic_assistant_nonce' ); ?>
						<input type="hidden" name="digitalogic_assistant_action" value="revoke">
						<button class="button" type="submit">لغو اتصال</button>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<p role="status">این قابلیت موقتاً در دسترس نیست.</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Mark the account-link page private before template output starts.
	 */
	public function maybe_disable_account_cache(): void {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ACCOUNT_ENDPOINT ) ) {
			nocache_headers();
		}
	}

	/**
	 * Ensure responses from these routes are never cached.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public function add_no_store_headers( $response, $server, $request ) {
		unset( $server );
		if ( $request instanceof WP_REST_Request
			&& 0 === strpos( (string) $request->get_route(), '/' . self::REST_NAMESPACE . '/assistant/account/' ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'Pragma', 'no-cache' );
		}

		return $response;
	}

	/**
	 * Register the WordPress privacy exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_privacy_exporter( array $exporters ): array {
		$exporters['digitalogic-assistant-account'] = array(
			'exporter_friendly_name' => 'اتصال همراه دیجیتالاجیک',
			'callback'               => array( $this, 'privacy_export' ),
		);
		return $exporters;
	}

	/**
	 * Register the WordPress privacy eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_privacy_eraser( array $erasers ): array {
		$erasers['digitalogic-assistant-account'] = array(
			'eraser_friendly_name' => 'اتصال همراه دیجیتالاجیک',
			'callback'             => array( $this, 'privacy_erase' ),
		);
		return $erasers;
	}

	/**
	 * Export only account-link state and retention metadata.
	 *
	 * @param string $email_address Account email used by the WordPress privacy tool.
	 * @param int    $page          Page.
	 * @return array
	 */
	public function privacy_export( string $email_address, int $page = 1 ): array {
		global $wpdb;

		if ( $page > 1 || ! self::is_schema_ready() ) {
			return array( 'data' => array(), 'done' => true );
		}

		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user instanceof WP_User ) {
			return array( 'data' => array(), 'done' => true );
		}

		$user_id = (int) $user->ID;
		$links   = self::table( 'links' );
		$tokens  = self::table( 'tokens' );
		$audit   = self::table( 'audit' );
		$link    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status,linked_at,revoked_at,updated_at FROM $links WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);
		$pending_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $tokens WHERE user_id = %d AND status = 'pending' AND expires_at >= %s",
				$user_id,
				self::utc_now()
			)
		);
		$audit_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $audit WHERE user_ref = %s",
				self::private_hash( 'audit-user', (string) $user_id )
			)
		);

		if ( ! is_array( $link ) && 0 === $pending_count && 0 === $audit_count ) {
			return array( 'data' => array(), 'done' => true );
		}

		$status_labels = array(
			'linked'   => 'متصل',
			'revoked'  => 'لغوشده',
			'disabled' => 'غیرفعال',
			'unlinked' => 'متصل نیست',
		);
		$status        = is_array( $link ) ? (string) ( $link['status'] ?? 'unlinked' ) : 'unlinked';
		$fields        = array(
			array(
				'name'  => 'وضعیت اتصال',
				'value' => $status_labels[ $status ] ?? 'متصل نیست',
			),
			array(
				'name'  => 'کدهای در انتظار',
				'value' => (string) $pending_count,
			),
			array(
				'name'  => 'رویدادهای نگهداری‌شده',
				'value' => (string) $audit_count,
			),
			array(
				'name'  => 'مدت نگهداری رویدادها',
				'value' => '۹۰ روز',
			),
			array(
				'name'  => 'مدت نگهداری کدهای پایان‌یافته و محدودیت‌ها',
				'value' => 'حداکثر ۲۴ ساعت',
			),
			array(
				'name'  => 'مدت نگهداری اتصال فعال',
				'value' => 'تا زمان لغو اتصال، حذف حساب یا درخواست حذف داده',
			),
		);
		foreach ( array( 'linked_at' => 'زمان اتصال', 'revoked_at' => 'زمان لغو', 'updated_at' => 'آخرین به‌روزرسانی' ) as $field => $label ) {
			if ( is_array( $link ) && ! empty( $link[ $field ] ) ) {
				$fields[] = array(
					'name'  => $label,
					'value' => (string) $link[ $field ] . ' UTC',
				);
			}
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'digitalogic-assistant-account',
					'group_label' => 'اتصال همراه دیجیتالاجیک',
					'item_id'     => 'digitalogic-assistant-account-' . substr( self::private_hash( 'privacy-item', (string) $user_id ), 0, 16 ),
					'data'        => $fields,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erase link, token, rate, and attributable pseudonymous audit state.
	 *
	 * @param string $email_address Account email used by the WordPress privacy tool.
	 * @param int    $page          Page.
	 * @return array
	 */
	public function privacy_erase( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		if ( ! self::is_schema_ready() ) {
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( 'حذف داده‌های اتصال در حال حاضر در دسترس نیست.' ),
				'done'           => true,
			);
		}

		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		if ( ! $user instanceof WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = $this->erase_user_records( (int) $user->ID );
		return array(
			'items_removed'  => $removed,
			'items_retained' => ! $removed,
			'messages'       => $removed ? array() : array( 'حذف داده‌های اتصال انجام نشد.' ),
			'done'           => true,
		);
	}

	/**
	 * Remove account linkage when WordPress deletes the account.
	 *
	 * @param int $user_id Deleted user ID.
	 */
	public function cleanup_deleted_user( int $user_id ): void {
		$this->erase_user_records( $user_id );
	}

	/**
	 * Delete all data attributable to one WordPress account.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function erase_user_records( int $user_id ): bool {
		global $wpdb;

		if ( $user_id < 1 || ! self::is_schema_ready() ) {
			return false;
		}

		$links    = self::table( 'links' );
		$tokens   = self::table( 'tokens' );
		$rates    = self::table( 'rates' );
		$audit    = self::table( 'audit' );
		$user_ref = self::private_hash( 'audit-user', (string) $user_id );
		$rate_ref = self::private_hash( 'rate:user', (string) $user_id );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$identity_hash = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT telegram_id_hash FROM $links WHERE user_id = %d FOR UPDATE",
				$user_id
			)
		);
		$rate_refs = array( $rate_ref );
		if ( '' !== $identity_hash ) {
			$rate_refs[] = self::private_hash( 'rate:signed-status', $identity_hash );
			$rate_refs[] = self::private_hash( 'rate:signed-consume', $identity_hash );
		}
		$results = array(
			$wpdb->delete( $tokens, array( 'user_id' => $user_id ), array( '%d' ) ),
			$wpdb->delete( $links, array( 'user_id' => $user_id ), array( '%d' ) ),
			$wpdb->delete( $audit, array( 'user_ref' => $user_ref ), array( '%s' ) ),
		);
		foreach ( $rate_refs as $rate_bucket ) {
			$results[] = $wpdb->delete( $rates, array( 'bucket_hash' => $rate_bucket ), array( '%s' ) );
		}
		if ( in_array( false, $results, true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return true;
	}

	/**
	 * Create a raw token for one-time display while persisting only its HMAC.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error
	 */
	private function create_link_token( int $user_id ) {
		global $wpdb;

		if ( ! self::is_operational() ) {
			return new WP_Error( 'digitalogic_assistant_unavailable' );
		}

		$user = self::active_registered_user( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'digitalogic_assistant_ineligible' );
		}

		$rate_result = $this->enforce_creation_rate( $user_id );
		if ( is_wp_error( $rate_result ) ) {
			return $rate_result;
		}

		try {
			$token = self::base64url_encode( random_bytes( 32 ) );
		} catch ( Throwable $error ) {
			unset( $error );
			return new WP_Error( 'digitalogic_assistant_random' );
		}

		$token_hash = self::token_hash( $token );
		$links      = self::table( 'links' );
		$tokens     = self::table( 'tokens' );
		$now        = self::utc_now();
		$expires    = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage' );
		}
		$link_lock = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $links
					(user_id,user_id_hash,telegram_id_hash,status,linked_at,revoked_at,checked_at,updated_at)
				VALUES (%d,%s,NULL,'unlinked',NULL,NULL,NULL,%s)
				ON DUPLICATE KEY UPDATE
					user_id_hash = VALUES(user_id_hash),
					updated_at = VALUES(updated_at)",
				$user_id,
				self::private_hash( 'user-identity', (string) $user_id ),
				$now
			)
		);
		if ( false === $link_lock ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage' );
		}
		$locked_user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM $links WHERE user_id = %d FOR UPDATE",
				$user_id
			)
		);
		if ( (int) $locked_user_id !== $user_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		$superseded = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $tokens
				SET pending_slot = NULL, status = 'superseded', updated_at = %s
				WHERE user_id = %d AND status = 'pending'",
				$now,
				$user_id
			)
		);
		if ( false === $superseded ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		$inserted = $wpdb->insert(
			$tokens,
			array(
				'token_hash'  => $token_hash,
				'user_id'     => $user_id,
				'pending_slot' => 1,
				'status'      => 'pending',
				'created_at'  => $now,
				'expires_at'  => $expires,
				'updated_at'  => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted || ! $this->write_audit( 'link_issued', $user_id, '', 'success' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage' );
		}
		$this->cleanup_storage();

		return $token;
	}

	/**
	 * Revoke all pending codes and the current linked identity.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function revoke_user_link( int $user_id ) {
		global $wpdb;

		if ( ! self::woocommerce_ready() || ! self::supports_site_topology() || ! self::is_schema_ready() ) {
			return new WP_Error( 'digitalogic_assistant_unavailable' );
		}

		$links  = self::table( 'links' );
		$tokens = self::table( 'tokens' );
		$now    = self::utc_now();

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage' );
		}
		$link = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT telegram_id_hash FROM $links WHERE user_id = %d FOR UPDATE",
				$user_id
			),
			ARRAY_A
		);
		$identity_hash = is_array( $link ) ? (string) ( $link['telegram_id_hash'] ?? '' ) : '';

		$revoked_tokens = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $tokens
				SET pending_slot = NULL, status = 'revoked', updated_at = %s
				WHERE user_id = %d AND status = 'pending'",
				$now,
				$user_id
			)
		);
		if ( false === $revoked_tokens ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		if ( is_array( $link ) ) {
			$updated = $wpdb->update(
				$links,
				array(
					'telegram_id_hash' => null,
					'status'           => 'revoked',
					'revoked_at'       => $now,
					'checked_at'       => $now,
					'updated_at'       => $now,
				),
				array( 'user_id' => $user_id ),
				array( null, '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_assistant_storage' );
			}
		}

		if ( ! $this->write_audit( 'link_revoked', $user_id, $identity_hash, 'success' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'digitalogic_assistant_storage' );
		}
		return true;
	}

	/**
	 * Current browser-visible state without returning an identity.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function user_link_state( int $user_id ): string {
		global $wpdb;

		if ( ! self::is_schema_ready() || ! self::supports_site_topology() ) {
			return 'unlinked';
		}

		$links  = self::table( 'links' );
		$tokens = self::table( 'tokens' );
		$status = $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM $links WHERE user_id = %d", $user_id )
		);
		if ( 'linked' === $status ) {
			return 'linked';
		}

		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT token_hash FROM $tokens
				WHERE user_id = %d AND status = 'pending' AND expires_at >= %s
				ORDER BY created_at DESC LIMIT 1",
				$user_id,
				self::utc_now()
			)
		);

		return is_string( $pending ) && '' !== $pending ? 'pending' : 'unlinked';
	}

	/**
	 * Enforce durable per-account and pseudonymous per-address limits.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function enforce_creation_rate( int $user_id ) {
		$user_result = $this->hit_rate_bucket( 'user', (string) $user_id, self::USER_RATE_LIMIT, self::RATE_WINDOW );
		if ( is_wp_error( $user_result ) ) {
			return $user_result;
		}

		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		return $this->hit_rate_bucket( 'address', $address, self::IP_RATE_LIMIT, self::RATE_WINDOW );
	}

	/**
	 * Rate-limit an already signed request on a re-keyed identity fingerprint.
	 *
	 * @param string $operation     Status or consume.
	 * @param string $identity_hash Site-local identity hash, never stored directly.
	 * @return true|WP_Error
	 */
	private function enforce_signed_identity_rate( string $operation, string $identity_hash ) {
		if ( 'status' === $operation ) {
			$limit  = self::STATUS_RATE_LIMIT;
			$window = self::STATUS_RATE_WINDOW;
		} elseif ( 'consume' === $operation ) {
			$limit  = self::CONSUME_RATE_LIMIT;
			$window = self::CONSUME_RATE_WINDOW;
		} else {
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		$result = $this->hit_rate_bucket( 'signed-' . $operation, $identity_hash, $limit, $window );
		if ( ! is_wp_error( $result ) ) {
			return true;
		}
		if ( 'digitalogic_assistant_rate' === $result->get_error_code() ) {
			return new WP_Error(
				'digitalogic_assistant_signed_rate',
				'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.',
				array( 'status' => 429 )
			);
		}
		return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
	}

	/**
	 * Atomically increment one rate bucket.
	 *
	 * @param string $kind           Bucket kind.
	 * @param string $material       Raw material, never stored.
	 * @param int    $limit          Request limit.
	 * @param int    $window_seconds Fixed-window duration.
	 * @return true|WP_Error
	 */
	private function hit_rate_bucket( string $kind, string $material, int $limit, int $window_seconds ) {
		global $wpdb;

		$rates  = self::table( 'rates' );
		$bucket = self::private_hash( 'rate:' . $kind, $material );
		$now    = self::utc_now();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $window_seconds );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $rates
					(bucket_hash,window_started,request_count,blocked_until,updated_at)
				VALUES (%s,%s,1,NULL,%s)
				ON DUPLICATE KEY UPDATE
					request_count = IF(window_started < %s,1,request_count + 1),
					blocked_until = IF(window_started < %s,NULL,blocked_until),
					window_started = IF(window_started < %s,VALUES(window_started),window_started),
					updated_at = VALUES(updated_at)",
				$bucket,
				$now,
				$now,
				$cutoff,
				$cutoff,
				$cutoff
			)
		);
		if ( false === $result ) {
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT window_started,request_count,blocked_until FROM $rates WHERE bucket_hash = %s",
				$bucket
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'digitalogic_assistant_storage' );
		}

		if ( ! empty( $row['blocked_until'] ) && strtotime( (string) $row['blocked_until'] . ' UTC' ) > time() ) {
			return new WP_Error( 'digitalogic_assistant_rate' );
		}
		if ( (int) $row['request_count'] > $limit ) {
			$blocked_until = gmdate(
				'Y-m-d H:i:s',
				max( time() + 1, strtotime( (string) $row['window_started'] . ' UTC' ) + $window_seconds )
			);
			$updated = $wpdb->update(
				$rates,
				array(
					'blocked_until' => $blocked_until,
					'updated_at'    => $now,
				),
				array( 'bucket_hash' => $bucket ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'digitalogic_assistant_storage' );
			}
			return new WP_Error( 'digitalogic_assistant_rate' );
		}

		return true;
	}

	/**
	 * Claim a signed request nonce exactly once.
	 *
	 * @param string $nonce  Raw request nonce.
	 * @param string $secret Server secret.
	 * @return true|WP_Error
	 */
	private function claim_server_nonce( string $nonce, string $secret ) {
		global $wpdb;

		$nonces    = self::table( 'nonces' );
		$now       = self::utc_now();
		$expires   = gmdate( 'Y-m-d H:i:s', time() + self::SERVER_NONCE_TTL );
		$nonce_hash = hash_hmac( 'sha256', 'server-nonce:' . $nonce, $secret );

		$wpdb->query( $wpdb->prepare( "DELETE FROM $nonces WHERE expires_at < %s", $now ) );
		$inserted = $wpdb->insert(
			$nonces,
			array(
				'nonce_hash' => $nonce_hash,
				'created_at' => $now,
				'expires_at' => $expires,
			),
			array( '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT nonce_hash FROM $nonces WHERE nonce_hash = %s", $nonce_hash )
			);
			if ( is_string( $exists ) ) {
				return new WP_Error( 'digitalogic_assistant_replay', 'دسترسی مجاز نیست.', array( 'status' => 409 ) );
			}
			return new WP_Error( 'digitalogic_assistant_storage', 'این قابلیت موقتاً در دسترس نیست.', array( 'status' => 503 ) );
		}

		return true;
	}

	/**
	 * Write a deliberately pseudonymous audit row.
	 *
	 * @param string $event_name    Event.
	 * @param int    $user_id       User ID, hashed before storage.
	 * @param string $identity_hash Stored identity hash, re-keyed before audit.
	 * @param string $outcome       Outcome.
	 * @return bool
	 */
	private function write_audit( string $event_name, int $user_id, string $identity_hash, string $outcome ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table( 'audit' ),
			array(
				'event_name'   => sanitize_key( $event_name ),
				'user_ref'     => self::private_hash( 'audit-user', (string) $user_id ),
				'identity_ref' => '' === $identity_hash ? '' : self::private_hash( 'audit-identity', $identity_hash ),
				'outcome'      => sanitize_key( $outcome ),
				'created_at'   => self::utc_now(),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return 1 === $inserted;
	}

	/**
	 * Opportunistically remove expired security state.
	 */
	public function cleanup_storage(): void {
		global $wpdb;

		if ( ! self::is_schema_ready() ) {
			return;
		}

		$tokens          = self::table( 'tokens' );
		$nonces          = self::table( 'nonces' );
		$rates           = self::table( 'rates' );
		$audit           = self::table( 'audit' );
		$now             = self::utc_now();
		$terminal_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TERMINAL_RETENTION );
		$audit_cutoff    = gmdate( 'Y-m-d H:i:s', time() - self::AUDIT_RETENTION );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $tokens SET pending_slot = NULL, status = 'expired', updated_at = %s
				WHERE status = 'pending' AND expires_at < %s",
				$now,
				$now
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $tokens WHERE status <> 'pending' AND updated_at < %s",
				$terminal_cutoff
			)
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM $nonces WHERE expires_at < %s", $now ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $rates WHERE updated_at < %s", $terminal_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $audit WHERE created_at < %s", $audit_cutoff ) );
	}

	/**
	 * Return a live eligible WordPress user or fail closed.
	 *
	 * @param int $user_id User ID.
	 * @return WP_User|null
	 */
	private static function active_registered_user( int $user_id ): ?WP_User {
		// Storage is intentionally single-site: without a blog_id in every
		// identity key, a network activation could cross tenant boundaries.
		if ( $user_id < 1 || ! self::supports_site_topology() ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || 0 !== (int) $user->user_status || empty( $user->roles ) || ! user_can( $user, 'read' ) ) {
			return null;
		}

		$allowed_roles = array_merge( self::CUSTOMER_ROLES, self::STAFF_ROLES );
		$user_roles    = array_map( 'strval', (array) $user->roles );
		if ( empty( array_intersect( $user_roles, $allowed_roles ) ) ) {
			return null;
		}

		return $user;
	}

	/**
	 * Return only the minimum pseudonymous authorization projection.
	 *
	 * @param WP_User|null $user   User.
	 * @param bool         $linked Link status.
	 * @return array
	 */
	private static function eligibility_payload( ?WP_User $user, bool $linked ): array {
		if ( ! $linked || ! $user ) {
			return array(
				'linked'      => false,
				'eligible'    => false,
				'scope'       => 'none',
				'account_ref' => '',
			);
		}

		// A linked staff account receives the same customer scope here. This
		// projection is never authorization for staff or operational actions.
		return array(
			'linked'      => true,
			'eligible'    => true,
			'scope'       => 'customer',
			'account_ref' => self::private_hash( 'account-ref', (string) $user->ID ),
		);
	}

	/**
	 * Map internal account errors to a safe Persian customer message.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private function persian_account_error( WP_Error $error ): string {
		if ( 'digitalogic_assistant_rate' === $error->get_error_code() ) {
			return 'تعداد درخواست‌ها بیش از حد مجاز است. چند دقیقه بعد دوباره تلاش کنید.';
		}
		if ( 'digitalogic_assistant_ineligible' === $error->get_error_code() ) {
			return 'این حساب در حال حاضر شرایط استفاده از اتصال را ندارد.';
		}

		return 'ساخت کد اتصال انجام نشد. کمی بعد دوباره تلاش کنید.';
	}

	/**
	 * Whether both storage and an out-of-repository server secret are ready.
	 *
	 * @return bool
	 */
	private static function is_operational(): bool {
		return self::woocommerce_ready()
			&& self::supports_site_topology()
			&& self::is_schema_ready()
			&& '' !== self::server_secret();
	}

	/**
	 * Whether WooCommerce-dependent customer operations may be registered.
	 *
	 * @return bool
	 */
	private static function woocommerce_ready(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * This schema is deliberately scoped to one WordPress site.
	 *
	 * @return bool
	 */
	private static function supports_site_topology(): bool {
		return ! is_multisite();
	}

	/**
	 * Read the external signing secret without using an option or repository file.
	 *
	 * @return string
	 */
	private static function server_secret(): string {
		if ( defined( self::SERVER_SECRET ) ) {
			$secret = constant( self::SERVER_SECRET );
			return is_string( $secret ) && strlen( $secret ) >= 32 ? $secret : '';
		}

		$path = self::SERVER_SECRET_FILE;
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$contents = @file_get_contents( $path );
		if ( ! is_string( $contents ) ) {
			return '';
		}

		$secret = trim( $contents );
		return strlen( $secret ) >= 32 ? $secret : '';
	}

	/**
	 * HMAC a short-lived raw token with the external secret.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', 'link-token:' . $token, self::server_secret() );
	}

	/**
	 * HMAC an external numeric identity without retaining the raw value.
	 *
	 * @param string $telegram_id Raw identity.
	 * @return string
	 */
	private static function identity_hash( string $telegram_id ): string {
		return self::private_hash( 'external-identity', $telegram_id );
	}

	/**
	 * Domain-separated site-local pseudonymization.
	 *
	 * @param string $domain   Domain.
	 * @param string $material Raw material.
	 * @return string
	 */
	private static function private_hash( string $domain, string $material ): string {
		$key = hash_hmac( 'sha256', 'digitalogic-assistant-identity-v1', wp_salt( 'auth' ), true );
		return hash_hmac( 'sha256', $domain . ':' . $material, $key );
	}

	/**
	 * Validate the numeric external account identifier.
	 *
	 * @param string $telegram_id Identifier.
	 * @return bool
	 */
	private static function valid_telegram_id( string $telegram_id ): bool {
		return 1 === preg_match( '/^[1-9][0-9]{4,19}$/', $telegram_id );
	}

	/**
	 * Encode random bytes in the link-safe base64url alphabet.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Return a non-cacheable REST response.
	 *
	 * @param array $data   Data.
	 * @param int   $status Status.
	 * @return WP_REST_Response
	 */
	private function no_store_response( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * UTC database timestamp.
	 *
	 * @return string
	 */
	private static function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Dedicated table name.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_assistant_' . $suffix;
	}

	/**
	 * Ensure expired short-lived state is removed even when nobody creates a code.
	 *
	 * @return bool
	 */
	private static function ensure_cleanup_schedule(): bool {
		if ( wp_next_scheduled( self::CLEANUP_ACTION ) ) {
			return true;
		}

		$scheduled = wp_schedule_event( time() + 300, 'hourly', self::CLEANUP_ACTION, array(), true );
		return ! is_wp_error( $scheduled ) && ( true === $scheduled || false !== wp_next_scheduled( self::CLEANUP_ACTION ) );
	}

	/**
	 * Invalidate every schema-v1 pending code before enabling the unique slot.
	 *
	 * The raw codes were never stored, so selecting one legacy row as the
	 * survivor could authorize a code whose delivery state is unknowable.
	 *
	 * @param string $installed_version Previous schema version.
	 * @param string $tokens            Token table.
	 * @return bool
	 */
	private static function migrate_legacy_tokens( string $installed_version, string $tokens ): bool {
		global $wpdb;

		if ( '1' !== $installed_version ) {
			return true;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $tokens
				SET pending_slot = NULL, status = 'superseded', updated_at = %s
				WHERE status = 'pending'",
				self::utc_now()
			)
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return true;
	}

	/**
	 * Verify required columns/indexes and enforce InnoDB transactions.
	 *
	 * @param string $table            Table.
	 * @param array  $required_columns Exact type, null, default, and extra semantics.
	 * @param array  $required_indexes Exact index uniqueness and column order.
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

		$column_rows = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . $quoted, ARRAY_A );
		if ( ! is_array( $column_rows ) ) {
			return false;
		}
		$columns = array();
		foreach ( $column_rows as $row ) {
			if ( ! is_array( $row )
				|| empty( $row['Field'] )
				|| empty( $row['Type'] )
				|| ! array_key_exists( 'Null', $row )
				|| ! array_key_exists( 'Default', $row )
				|| ! array_key_exists( 'Extra', $row ) ) {
				return false;
			}
			$name = (string) $row['Field'];
			if ( isset( $columns[ $name ] ) ) {
				return false;
			}
			$columns[ $name ] = $row;
		}
		$actual_names   = array_keys( $columns );
		$required_names = array_keys( $required_columns );
		sort( $actual_names, SORT_STRING );
		sort( $required_names, SORT_STRING );
		if ( $actual_names !== $required_names ) {
			return false;
		}

		foreach ( $required_columns as $name => $required ) {
			$actual          = $columns[ $name ];
			$actual_type     = strtolower( (string) preg_replace( '/\s+/', ' ', trim( (string) $actual['Type'] ) ) );
			$actual_nullable = 'YES' === strtoupper( trim( (string) $actual['Null'] ) );
			$actual_extra    = strtolower( (string) preg_replace( '/\s+/', ' ', trim( (string) $actual['Extra'] ) ) );
			if ( $required['type'] !== $actual_type
				|| (bool) $required['nullable'] !== $actual_nullable
				|| $required['extra'] !== $actual_extra ) {
				return false;
			}
			if ( null === $required['default'] ) {
				if ( null !== $actual['Default'] ) {
					return false;
				}
			} elseif ( (string) $required['default'] !== (string) $actual['Default'] ) {
				return false;
			}
		}

		$index_rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $quoted, ARRAY_A );
		if ( ! is_array( $index_rows ) ) {
			return false;
		}

		$indexes = array();
		foreach ( $index_rows as $row ) {
			if ( ! is_array( $row )
				|| empty( $row['Key_name'] )
				|| empty( $row['Column_name'] )
				|| empty( $row['Seq_in_index'] )
				|| ! array_key_exists( 'Non_unique', $row )
				|| ! array_key_exists( 'Sub_part', $row )
				|| ! empty( $row['Sub_part'] ) ) {
				return false;
			}
			$name     = (string) $row['Key_name'];
			$sequence = (int) $row['Seq_in_index'];
			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = array(
					'unique'  => 0 === (int) $row['Non_unique'],
					'columns' => array(),
				);
			} elseif ( $indexes[ $name ]['unique'] !== ( 0 === (int) $row['Non_unique'] ) ) {
				return false;
			}
			if ( isset( $indexes[ $name ]['columns'][ $sequence ] ) ) {
				return false;
			}
			$indexes[ $name ]['columns'][ $sequence ] = (string) $row['Column_name'];
		}

		foreach ( $required_indexes as $name => $required ) {
			if ( ! isset( $indexes[ $name ] )
				|| (bool) $required['unique'] !== $indexes[ $name ]['unique'] ) {
				return false;
			}
			ksort( $indexes[ $name ]['columns'], SORT_NUMERIC );
			if ( array_values( $required['columns'] ) !== array_values( $indexes[ $name ]['columns'] ) ) {
				return false;
			}
		}

		return true;
	}
}
