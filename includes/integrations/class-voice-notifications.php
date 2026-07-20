<?php
/**
 * Opt-in WooCommerce order announcements through the local PBX service.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues and dispatches consent-aware voice announcements.
 */
final class Digitalogic_Voice_Notifications {

	private const OPTION                    = 'digitalogic_voice_notification_settings';
	private const GLOBAL_OPTION             = 'digitalogic_voice_notifications_enabled';
	private const ACTION                    = 'digitalogic_voice_dispatch_job';
	private const ACTION_GROUP              = 'digitalogic-voice';
	private const RECONCILE_ACTION          = 'digitalogic_voice_reconcile_pending';
	private const RECONCILE_WATCHDOG_ACTION = 'digitalogic_voice_reconcile_watchdog';
	private const ADMIN_SLUG                = 'digitalogic-voice-notifications';
	private const CALLOUT_URL               = 'http://127.0.0.1:8789/call';
	private const MAX_TEMPLATE_BYTES        = 800;
	private const MAX_RENDERED_BYTES        = 1400;
	private const ALLOWED_PLACEHOLDERS      = array( '{first_name}', '{order_number}', '{order_status}', '{site_name}' );
	private const STATUS_SLUGS              = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );

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
		add_action( 'woocommerce_order_status_changed', array( $this, 'queue_order_status' ), 30, 4 );
		add_action( self::ACTION, array( $this, 'dispatch_job' ), 10, 1 );
		add_action( 'deleted_user', array( $this, 'cancel_deleted_user_jobs' ), 10, 1 );
		add_action( 'digitalogic_pbx_cleanup', array( $this, 'cleanup_retention' ) );
		add_action( self::RECONCILE_ACTION, array( $this, 'reconcile_pending_jobs' ) );
		add_action( self::RECONCILE_WATCHDOG_ACTION, array( $this, 'reconcile_pending_jobs' ) );
		add_action( 'init', array( $this, 'schedule_reconciliation' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'save_settings' ) );
	}

	/**
	 * Create and verify the idempotent job tables and disabled defaults.
	 *
	 * @return bool Whether storage and reconciliation schedules are ready.
	 */
	public static function install(): bool {
		global $wpdb;
		self::$schema_health = false;
		$table               = self::table();
		$rate_table          = self::rate_table();
		$collate             = $wpdb->get_charset_collate();
		$sql                 = "CREATE TABLE $table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			idempotency_key char(64) NOT NULL,
			order_id bigint(20) UNSIGNED NOT NULL,
			user_id bigint(20) UNSIGNED NOT NULL,
			contact_id bigint(20) UNSIGNED NOT NULL,
			status_slug varchar(40) NOT NULL,
			template_version int UNSIGNED NOT NULL DEFAULT 1,
			template_snapshot longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempt_count smallint UNSIGNED NOT NULL DEFAULT 0,
			scheduled_at datetime NOT NULL,
			started_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY due_jobs (status,scheduled_at),
			KEY contact_sent (contact_id,status,sent_at),
			KEY order_status (order_id,status_slug)
		) ENGINE=InnoDB $collate;";
		$rate_sql            = "CREATE TABLE $rate_table (
			bucket_key char(64) NOT NULL,
			contact_id bigint(20) UNSIGNED NOT NULL,
			window_name varchar(10) NOT NULL,
			window_started datetime NOT NULL,
			counter int UNSIGNED NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (bucket_key),
			KEY contact_window (contact_id,window_name,window_started),
			KEY updated_at (updated_at)
		) ENGINE=InnoDB $collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $rate_sql );

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', 'no' );
		}
		if ( false === get_option( self::GLOBAL_OPTION, false ) ) {
			add_option( self::GLOBAL_OPTION, '0', '', 'no' );
		}
		$jobs_ready  = self::verify_storage_table(
			$table,
			array( 'id', 'idempotency_key', 'order_id', 'user_id', 'contact_id', 'status_slug', 'template_version', 'template_snapshot', 'status', 'attempt_count', 'scheduled_at', 'started_at', 'sent_at', 'last_error', 'created_at', 'updated_at' ),
			array( 'PRIMARY', 'idempotency_key', 'due_jobs', 'contact_sent', 'order_status' )
		);
		$rates_ready = self::verify_storage_table(
			$rate_table,
			array( 'bucket_key', 'contact_id', 'window_name', 'window_started', 'counter', 'updated_at' ),
			array( 'PRIMARY', 'contact_window', 'updated_at' )
		);
		if ( ! $jobs_ready || ! $rates_ready || ! self::ensure_reconciliation_schedules() ) {
			return false;
		}
		self::$schema_health = true;
		return true;
	}

	/**
	 * Verify table names and transactional storage after dbDelta.
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
	 * Install both the five-minute chain and an hourly recovery watchdog.
	 *
	 * @return bool
	 */
	private static function ensure_reconciliation_schedules(): bool {
		if ( ! wp_next_scheduled( self::RECONCILE_ACTION ) ) {
			$result = wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RECONCILE_ACTION, array(), true );
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}
		if ( ! wp_next_scheduled( self::RECONCILE_WATCHDOG_ACTION ) ) {
			$result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::RECONCILE_WATCHDOG_ACTION, array(), true );
			if ( is_wp_error( $result ) ) {
				return false;
			}
		}
		return false !== wp_next_scheduled( self::RECONCILE_ACTION ) && false !== wp_next_scheduled( self::RECONCILE_WATCHDOG_ACTION );
	}

	/**
	 * Whether the verified voice schema is usable in this request.
	 *
	 * @return bool
	 */
	private static function schema_ready(): bool {
		return false !== self::$schema_health && Digitalogic_Call_Verification::is_schema_ready();
	}

	/**
	 * Force outbound functionality closed after an install failure.
	 */
	public static function mark_schema_unready(): void {
		self::$schema_health = false;
	}

	/**
	 * Return safely merged settings.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$saved  = get_option( self::OPTION, array() );
		$result = self::normalize_settings_payload( $saved );
		if ( is_wp_error( $result ) ) {
			$result = self::defaults();
		}
		$result['global_enabled'] = '1' === (string) get_option( self::GLOBAL_OPTION, '0' ) ? 1 : 0;
		return $result;
	}

	/**
	 * Validate and canonicalize the full non-cache policy payload.
	 *
	 * @param mixed $saved Stored option value.
	 * @return array|WP_Error
	 */
	private static function normalize_settings_payload( $saved ) {
		$defaults = self::defaults();
		if ( ! is_array( $saved ) || ! isset( $saved['statuses'] ) || ! is_array( $saved['statuses'] ) ) {
			return new WP_Error( 'digitalogic_voice_policy', 'invalid voice policy' );
		}
		$binary      = static function ( $value ) {
			if ( in_array( $value, array( 0, '0', false ), true ) ) {
				return 0;
			}
			if ( in_array( $value, array( 1, '1', true ), true ) ) {
				return 1;
			}
			return null;
		};
		$global      = $binary( $saved['global_enabled'] ?? null );
		$hourly      = filter_var( $saved['hourly_limit'] ?? null, FILTER_VALIDATE_INT );
		$daily       = filter_var( $saved['daily_limit'] ?? null, FILTER_VALIDATE_INT );
		$quiet_start = (string) ( $saved['quiet_start'] ?? '' );
		$quiet_end   = (string) ( $saved['quiet_end'] ?? '' );
		if ( null === $global || false === $hourly || false === $daily || $hourly < 1 || $hourly > 10 || $daily < 1 || $daily > 30
			|| self::sanitize_time( $quiet_start, '' ) !== $quiet_start || self::sanitize_time( $quiet_end, '' ) !== $quiet_end ) {
			return new WP_Error( 'digitalogic_voice_policy', 'invalid voice policy' );
		}
		if ( ! empty( array_diff( self::STATUS_SLUGS, array_keys( $saved['statuses'] ) ) ) ) {
			return new WP_Error( 'digitalogic_voice_policy', 'incomplete voice policy' );
		}
		$result = array(
			'global_enabled' => $global,
			'quiet_start'    => $quiet_start,
			'quiet_end'      => $quiet_end,
			'hourly_limit'   => (int) $hourly,
			'daily_limit'    => (int) $daily,
			'statuses'       => array(),
		);
		foreach ( self::STATUS_SLUGS as $slug ) {
			$status = $saved['statuses'][ $slug ] ?? null;
			if ( ! is_array( $status ) ) {
				return new WP_Error( 'digitalogic_voice_policy', 'invalid voice status policy' );
			}
			$enabled            = $binary( $status['enabled'] ?? null );
			$version            = filter_var( $status['version'] ?? null, FILTER_VALIDATE_INT );
			$template           = $status['template'] ?? null;
			$validated_template = is_string( $template ) ? self::sanitize_template( $template ) : new WP_Error( 'digitalogic_voice_policy' );
			if ( null === $enabled || false === $version || $version < 1 || is_wp_error( $validated_template ) || ! hash_equals( $template, $validated_template ) ) {
				return new WP_Error( 'digitalogic_voice_policy', 'invalid voice status policy' );
			}
			$result['statuses'][ $slug ] = array(
				'enabled'  => $enabled,
				'template' => $validated_template,
				'version'  => (int) $version,
			);
		}
		return $result;
	}

	/**
	 * Read one option directly from wp_options, bypassing object caches.
	 *
	 * @param string $name Option name.
	 * @return mixed|WP_Error
	 */
	private static function option_from_database( string $name ) {
		global $wpdb;
		$options          = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$quoted           = '`' . str_replace( '`', '``', $options ) . '`';
		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare( 'SELECT option_value FROM ' . $quoted . ' WHERE option_name = %s LIMIT 1', $name ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return new WP_Error( 'digitalogic_voice_policy', 'voice policy unavailable' );
		}
		$value = $row['option_value'];
		if ( is_string( $value ) && str_starts_with( $value, 'a:' ) ) {
			$value = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Direct options read requires safe class-disabled decoding.
			return is_array( $value ) ? $value : new WP_Error( 'digitalogic_voice_policy', 'voice policy serialization invalid' );
		}
		return $value;
	}

	/**
	 * Read and validate the serialized settings directly from MySQL.
	 *
	 * @return array|WP_Error
	 */
	private static function settings_payload_from_database() {
		$raw = self::option_from_database( self::OPTION );
		return is_wp_error( $raw ) ? $raw : self::normalize_settings_payload( $raw );
	}

	/**
	 * Read the independent global kill switch directly from MySQL.
	 *
	 * @return bool|null Null means unavailable/invalid and therefore closed.
	 */
	private static function global_enabled_from_database(): ?bool {
		$raw = self::option_from_database( self::GLOBAL_OPTION );
		if ( is_wp_error( $raw ) || ! in_array( (string) $raw, array( '0', '1' ), true ) ) {
			return null;
		}
		return '1' === (string) $raw;
	}

	/**
	 * Read every dispatch policy input directly from MySQL.
	 *
	 * @return array|WP_Error
	 */
	private static function settings_from_database() {
		$settings = self::settings_payload_from_database();
		$global   = self::global_enabled_from_database();
		if ( is_wp_error( $settings ) || null === $global ) {
			return new WP_Error( 'digitalogic_voice_policy', 'voice policy unavailable' );
		}
		$settings['global_enabled'] = $global ? 1 : 0;
		return $settings;
	}

	/**
	 * Normalize per-contact status preferences.
	 *
	 * @param mixed $value Submitted preferences.
	 * @return array<string,int>
	 */
	public static function sanitize_event_preferences( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$preferences = array();
		foreach ( self::STATUS_SLUGS as $slug ) {
			$enabled = array_is_list( $value ) ? in_array( $slug, $value, true ) : ! empty( $value[ $slug ] );
			if ( $enabled ) {
				$preferences[ $slug ] = 1;
			}
		}
		return $preferences;
	}

	/**
	 * Strictly validate a plain-text message template.
	 *
	 * @param mixed $value Submitted template.
	 * @return string|WP_Error
	 */
	public static function sanitize_template( $value ) {
		$template = trim( (string) $value );
		if ( '' === $template || strlen( $template ) > self::MAX_TEMPLATE_BYTES || wp_strip_all_tags( $template ) !== $template ) {
			return new WP_Error( 'digitalogic_voice_template', __( 'Voice templates must be plain text between 1 and 800 bytes.', 'digitalogic' ) );
		}
		preg_match_all( '/\{[^{}]+\}/u', $template, $matches );
		foreach ( array_unique( $matches[0] ) as $placeholder ) {
			if ( ! in_array( $placeholder, self::ALLOWED_PLACEHOLDERS, true ) ) {
				return new WP_Error( 'digitalogic_voice_placeholder', __( 'The voice template contains an unsupported placeholder.', 'digitalogic' ) );
			}
		}
		$without_allowed = str_replace( self::ALLOWED_PLACEHOLDERS, '', $template );
		if ( str_contains( $without_allowed, '{' ) || str_contains( $without_allowed, '}' ) ) {
			return new WP_Error( 'digitalogic_voice_placeholder', __( 'The voice template contains malformed braces.', 'digitalogic' ) );
		}
		return $template;
	}

	/**
	 * Queue one idempotent job per eligible verified contact.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param mixed  $order      Order object.
	 */
	public function queue_order_status( int $order_id, string $old_status, string $new_status, $order ): void {
		$settings        = self::settings();
		$status_settings = $settings['statuses'][ $new_status ] ?? null;
		if ( empty( $settings['global_enabled'] ) || ! self::callout_configuration_ready()
			|| ! is_array( $status_settings ) || empty( $status_settings['enabled'] ) ) {
			return;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id < 1 ) {
			return;
		}

		$template = self::sanitize_template( $status_settings['template'] ?? '' );
		if ( is_wp_error( $template ) ) {
			return;
		}
		$version  = max( 1, (int) ( $status_settings['version'] ?? 1 ) );
		$contacts = Digitalogic_Call_Verification::voice_contacts_for_user( $user_id, $new_status );
		global $wpdb;
		foreach ( $contacts as $contact ) {
			$idempotency_key = hash( 'sha256', implode( '|', array( 'v1', $order_id, $new_status, (int) $contact->id, $version ) ) );
			$now             = gmdate( 'Y-m-d H:i:s' );
			$inserted        = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::table() . " (idempotency_key,order_id,user_id,contact_id,status_slug,template_version,template_snapshot,status,scheduled_at,created_at,updated_at) VALUES (%s,%d,%d,%d,%s,%d,%s,'pending',%s,%s,%s)",
					$idempotency_key,
					$order_id,
					$user_id,
					(int) $contact->id,
					$new_status,
					$version,
					$template,
					$now,
					$now,
					$now
				)
			);
			if ( 1 === (int) $inserted ) {
				$job_id = (int) $wpdb->insert_id;
				if ( ! self::schedule( $job_id, time() + 2 ) ) {
					$wpdb->update(
						self::table(),
						array(
							'last_error' => 'scheduler deferred to reconciliation',
							'updated_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array(
							'id'     => $job_id,
							'status' => 'pending',
						),
						array( '%s', '%s' ),
						array( '%d', '%s' )
					);
				}
			}
		}
	}

	/**
	 * Re-check every policy and dispatch a single call with zero PBX retries.
	 *
	 * @param int $job_id Job id.
	 */
	public function dispatch_job( int $job_id ): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE id = %d AND status = 'pending'", $job_id ) );
		if ( ! $job ) {
			return;
		}
		$context = $this->live_job_context( $job );
		if ( is_wp_error( $context ) ) {
			$this->finish_job( $job_id, 'cancelled', $context->get_error_message() );
			return;
		}
		$next_allowed = self::next_allowed_time( $context['settings'] );
		if ( $next_allowed > time() + 5 ) {
			$this->postpone_job( $job_id, $next_allowed );
			return;
		}

		// Re-fetch immediately before claiming and reserving capacity so an
		// opt-out does not ordinarily consume a rate slot.
		$context = $this->live_job_context( $job );
		if ( is_wp_error( $context ) ) {
			$this->finish_job( $job_id, 'cancelled', $context->get_error_message() );
			return;
		}
		$next_allowed = self::next_allowed_time( $context['settings'] );
		if ( $next_allowed > time() + 5 ) {
			$this->postpone_job( $job_id, $next_allowed );
			return;
		}
		$target = Digitalogic_PBX_Phone::to_pbx_target( $context['contact']->phone );
		if ( '' === $target ) {
			$this->finish_job( $job_id, 'failed', 'invalid verified target' );
			return;
		}
		$message = self::render_message( (string) $job->template_snapshot, $context['order'], (string) $job->status_slug );
		if ( '' === $message ) {
			$this->finish_job( $job_id, 'failed', 'empty rendered message' );
			return;
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'processing', started_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'pending'",
				$job_id
			)
		);
		if ( 1 !== (int) $claimed ) {
			return;
		}
		$rate_reservation = $this->reserve_rate( (string) $context['contact']->value_hash, (int) $context['contact']->id, $context['settings'] );
		if ( is_wp_error( $rate_reservation ) ) {
			$this->finish_job( $job_id, 'failed', $rate_reservation->get_error_message() );
			return;
		}
		if ( $rate_reservation > 0 ) {
			$this->postpone_job( $job_id, time() + $rate_reservation, 'processing' );
			return;
		}

		$attempted = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET attempt_count = attempt_count + 1, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'processing'",
				$job_id
			)
		);
		if ( 1 !== (int) $attempted ) {
			$this->finish_job( $job_id, 'failed', 'voice attempt could not be recorded' );
			return;
		}

		// A reservation is intentionally retained if this last direct-database
		// gate observes a concurrent opt-out. Keep it after all job writes and
		// immediately before the network boundary.
		$final_context = $this->live_job_context( $job );
		if ( is_wp_error( $final_context ) ) {
			$this->finish_job( $job_id, 'cancelled', $final_context->get_error_message() );
			return;
		}
		$config  = $final_context['config'];
		$target  = Digitalogic_PBX_Phone::to_pbx_target( $final_context['contact']->phone );
		$message = self::render_message( (string) $job->template_snapshot, $final_context['order'], (string) $job->status_slug );
		if ( '' === $target || '' === $message ) {
			$this->finish_job( $job_id, 'cancelled', 'live call context became invalid' );
			return;
		}
		$final_next_allowed = self::next_allowed_time( $final_context['settings'] );
		if ( $final_next_allowed > time() ) {
			$this->postpone_job( $job_id, $final_next_allowed, 'processing' );
			return;
		}
		$response = wp_remote_post(
			$config['url'],
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'limit_response_size' => 16384,
				'headers'             => array(
					'Authorization' => 'Bearer ' . $config['token'],
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'                => wp_json_encode(
					self::callout_payload( $target, $message, (int) $job->order_id ),
					JSON_UNESCAPED_UNICODE
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->finish_job( $job_id, 'failed', 'PBX request failed' );
			return;
		}
		$code         = (int) wp_remote_retrieve_response_code( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body         = (string) wp_remote_retrieve_body( $response );
		if ( ! self::callout_acknowledged( $code, $content_type, $body ) ) {
			$this->finish_job( $job_id, 'failed', 'PBX did not explicitly acknowledge the call' );
			return;
		}
		$this->finish_job( $job_id, 'sent', '' );
	}

	/**
	 * Re-fetch every mutable authorization input for an outbound job.
	 *
	 * @param object $job Stored job.
	 * @return array|WP_Error
	 */
	private function live_job_context( $job ) {
		$settings = self::settings_from_database();
		if ( is_wp_error( $settings ) ) {
			return new WP_Error( 'digitalogic_voice_policy', 'voice policy unavailable' );
		}
		$status_settings = $settings['statuses'][ $job->status_slug ] ?? null;
		$config          = self::callout_config();
		if ( empty( $settings['global_enabled'] ) || ! is_array( $status_settings )
			|| empty( $status_settings['enabled'] ) || is_wp_error( $config )
			|| (int) ( $status_settings['version'] ?? 0 ) !== (int) $job->template_version
			|| ! hash_equals( (string) ( $status_settings['template'] ?? '' ), (string) $job->template_snapshot ) ) {
			return new WP_Error( 'digitalogic_voice_disabled', 'voice policy disabled' );
		}
		$user = get_userdata( (int) $job->user_id );
		if ( ! is_object( $user ) || (int) $user->ID !== (int) $job->user_id ) {
			return new WP_Error( 'digitalogic_voice_user', 'customer account unavailable' );
		}
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $job->order_id ) : null;
		$contact = Digitalogic_Call_Verification::contact_by_id( (int) $job->contact_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) || ! method_exists( $order, 'get_status' )
			|| (int) $order->get_customer_id() !== (int) $job->user_id
			|| (string) $order->get_status() !== (string) $job->status_slug || ! $contact
			|| (int) $contact->user_id !== (int) $job->user_id
			|| ! Digitalogic_Call_Verification::contact_allows_voice_event( $contact, (string) $job->status_slug ) ) {
			return new WP_Error( 'digitalogic_voice_context', 'live order/contact consent unavailable' );
		}

		return array(
			'settings' => $settings,
			'config'   => $config,
			'order'    => $order,
			'contact'  => $contact,
		);
	}

	/**
	 * Cancel queued jobs after WordPress confirms a user deletion.
	 *
	 * @param int $user_id Deleted user id.
	 */
	public function cancel_deleted_user_jobs( int $user_id ): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'cancelled', last_error = 'customer account deleted', updated_at = UTC_TIMESTAMP() WHERE user_id = %d AND status IN ('pending','processing')",
				$user_id
			)
		);
	}

	/**
	 * Ensure a durable scanner exists even when one direct scheduling call fails.
	 */
	public function schedule_reconciliation(): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		if ( ! self::ensure_reconciliation_schedules() ) {
			set_transient( 'digitalogic_pbx_schedule_error', __( 'Digitalogic voice-queue recovery scheduling failed.', 'digitalogic' ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Requeue bounded due work. Duplicate actions are safe because dispatch uses CAS.
	 */
	public function reconcile_pending_jobs(): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query(
			'UPDATE ' . self::table() . " SET status = 'failed', last_error = 'stale processing state', updated_at = UTC_TIMESTAMP() WHERE status = 'processing' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
		);
		$ids = $wpdb->get_col(
			'SELECT id FROM ' . self::table() . " WHERE status = 'pending' AND scheduled_at <= UTC_TIMESTAMP() ORDER BY scheduled_at, id LIMIT 100"
		);
		foreach ( (array) $ids as $offset => $job_id ) {
			self::schedule( (int) $job_id, time() + min( 30, (int) $offset ) );
		}
		self::ensure_reconciliation_schedules();
	}

	/**
	 * Bound storage for terminal jobs and rate reservations.
	 */
	public function cleanup_retention(): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		global $wpdb;
		$jobs  = '`' . str_replace( '`', '``', self::table() ) . '`';
		$users = '`' . str_replace( '`', '``', isset( $wpdb->users ) ? $wpdb->users : $wpdb->prefix . 'users' ) . '`';
		$wpdb->query( "UPDATE $jobs AS job LEFT JOIN $users AS live_user ON live_user.ID = job.user_id SET job.status = 'cancelled', job.last_error = 'customer account deleted', job.updated_at = UTC_TIMESTAMP() WHERE live_user.ID IS NULL AND job.status IN ('pending','processing')" );
		$wpdb->query( 'DELETE FROM ' . self::table() . " WHERE status IN ('sent','failed','cancelled') AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)" );
		$wpdb->query( 'DELETE FROM ' . self::rate_table() . ' WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)' );
	}

	/**
	 * Register the administrator settings page.
	 */
	public function admin_menu(): void {
		add_submenu_page(
			'digitalogic',
			__( 'Voice notifications', 'digitalogic' ),
			__( 'Voice notifications', 'digitalogic' ),
			'manage_woocommerce',
			self::ADMIN_SLUG,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Persist and directly verify the independent global kill switch.
	 *
	 * @param bool $enabled Desired state.
	 * @return bool
	 */
	private static function set_global_enabled( bool $enabled ): bool {
		$desired = $enabled ? '1' : '0';
		update_option( self::GLOBAL_OPTION, $desired, false );
		wp_cache_delete( self::GLOBAL_OPTION, 'options' );
		if ( self::global_enabled_from_database() === $enabled ) {
			return true;
		}

		// Emergency cache/write fallback: update the exact options row and
		// verify MySQL again. This path never enables unless readback agrees.
		global $wpdb;
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$updated = $wpdb->update(
			$options,
			array(
				'option_value' => $desired,
				'autoload'     => 'no',
			),
			array( 'option_name' => self::GLOBAL_OPTION ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		if ( 0 === $updated ) {
			$updated = $wpdb->insert(
				$options,
				array(
					'option_name'  => self::GLOBAL_OPTION,
					'option_value' => $desired,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			);
		}
		wp_cache_delete( self::GLOBAL_OPTION, 'options' );
		return false !== $updated && self::global_enabled_from_database() === $enabled;
	}

	/**
	 * Persist the full policy while global dispatch is already off.
	 *
	 * @param array $settings Canonical settings.
	 * @return bool
	 */
	private static function persist_settings_verified( array $settings ): bool {
		$settings['global_enabled'] = 0;
		$canonical                  = self::normalize_settings_payload( $settings );
		if ( is_wp_error( $canonical ) || $canonical !== $settings ) {
			return false;
		}
		update_option( self::OPTION, $canonical, false );
		wp_cache_delete( self::OPTION, 'options' );
		$stored = self::settings_payload_from_database();
		return ! is_wp_error( $stored ) && $stored === $canonical;
	}

	/**
	 * Enforce off → verified policy → optional verified on ordering.
	 *
	 * @param array $settings    Canonical settings.
	 * @param bool  $enable_site Whether the admin requested final enablement.
	 * @return bool
	 */
	private static function persist_voice_policy( array $settings, bool $enable_site ): bool {
		if ( ! self::set_global_enabled( false ) || ! self::persist_settings_verified( $settings ) ) {
			self::set_global_enabled( false );
			return false;
		}
		if ( $enable_site && ( ! self::callout_configuration_ready() || ! self::set_global_enabled( true ) ) ) {
			self::set_global_enabled( false );
			return false;
		}
		return true;
	}

	/**
	 * Save bounded settings and strictly validated templates.
	 */
	public function save_settings(): void {
		if ( ! isset( $_POST['digitalogic_voice_save'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'digitalogic_voice_settings' );
		if ( ! self::set_global_enabled( false ) ) {
			add_settings_error( self::OPTION, 'storage', __( 'The global voice-call switch could not be verified off. No other voice settings were changed.', 'digitalogic' ), 'error' );
			return;
		}
		if ( ! self::schema_ready() ) {
			add_settings_error( self::OPTION, 'schema', __( 'Voice notifications remain disabled until the verified PBX database migration and recovery schedules are ready.', 'digitalogic' ), 'error' );
			return;
		}
		$posted  = isset( $_POST['digitalogic_voice'] ) && is_array( $_POST['digitalogic_voice'] ) ? wp_unslash( $_POST['digitalogic_voice'] ) : array();
		$current = self::settings_payload_from_database();
		if ( is_wp_error( $current ) ) {
			add_settings_error( self::OPTION, 'storage', __( 'Voice notification settings could not be saved. The global call switch remains off.', 'digitalogic' ), 'error' );
			return;
		}
		$next                   = $current;
		$next['global_enabled'] = 0;
		$enable_site            = ! empty( $posted['global_enabled'] );
		if ( $enable_site && ! self::callout_configuration_ready() ) {
			$enable_site = false;
			add_settings_error( self::OPTION, 'callout-config', __( 'Configure the server-only PBX callout URL and token before enabling voice notifications.', 'digitalogic' ), 'error' );
		}
		$next['quiet_start']  = self::sanitize_time( $posted['quiet_start'] ?? '', '09:00' );
		$next['quiet_end']    = self::sanitize_time( $posted['quiet_end'] ?? '', '21:00' );
		$next['hourly_limit'] = max( 1, min( 10, absint( $posted['hourly_limit'] ?? 2 ) ) );
		$next['daily_limit']  = max( 1, min( 30, absint( $posted['daily_limit'] ?? 5 ) ) );
		$templates_valid      = true;
		foreach ( self::STATUS_SLUGS as $slug ) {
			$status_posted = isset( $posted['statuses'][ $slug ] ) && is_array( $posted['statuses'][ $slug ] ) ? $posted['statuses'][ $slug ] : array();
			$template      = self::sanitize_template( $status_posted['template'] ?? $current['statuses'][ $slug ]['template'] );
			if ( is_wp_error( $template ) ) {
				add_settings_error( self::OPTION, 'template-' . $slug, $template->get_error_message(), 'error' );
				$templates_valid = false;
				continue;
			}
			$changed                   = ! hash_equals( (string) $current['statuses'][ $slug ]['template'], $template );
			$next['statuses'][ $slug ] = array(
				'enabled'  => empty( $status_posted['enabled'] ) ? 0 : 1,
				'template' => $template,
				'version'  => $changed ? (int) $current['statuses'][ $slug ]['version'] + 1 : (int) $current['statuses'][ $slug ]['version'],
			);
		}
		if ( ! $templates_valid || ! self::persist_voice_policy( $next, $enable_site ) ) {
			self::set_global_enabled( false );
			add_settings_error( self::OPTION, 'storage', __( 'Voice notification settings could not be saved. The global call switch remains off.', 'digitalogic' ), 'error' );
			return;
		}
		add_settings_error( self::OPTION, 'saved', __( 'Voice notification settings saved.', 'digitalogic' ), 'updated' );
	}

	/**
	 * Render global/status controls and editable Persian templates.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings     = self::settings();
		$config_ready = self::callout_configuration_ready();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PBX voice notifications', 'digitalogic' ); ?></h1>
			<p><?php esc_html_e( 'Calls are made only to verified contacts with effective consent. The global switch and every order status are disabled by default.', 'digitalogic' ); ?></p>
			<?php settings_errors( self::OPTION ); ?>
			<form method="post">
				<?php wp_nonce_field( 'digitalogic_voice_settings' ); ?>
				<input type="hidden" name="digitalogic_voice_save" value="1">
				<table class="form-table" role="presentation"><tbody>
					<tr><th><?php esc_html_e( 'Global kill switch', 'digitalogic' ); ?></th><td><label><input type="checkbox" name="digitalogic_voice[global_enabled]" value="1" <?php checked( $settings['global_enabled'] ); ?> <?php disabled( ! $config_ready ); ?>> <?php esc_html_e( 'Allow queued PBX calls', 'digitalogic' ); ?></label>
					<?php
					if ( ! $config_ready ) :
						?>
						<p class="description"><?php esc_html_e( 'Server-only PBX callout configuration is missing or invalid.', 'digitalogic' ); ?></p><?php endif; ?></td></tr>
					<tr><th><?php esc_html_e( 'Calling window (Tehran)', 'digitalogic' ); ?></th><td><input type="time" name="digitalogic_voice[quiet_start]" value="<?php echo esc_attr( $settings['quiet_start'] ); ?>"> – <input type="time" name="digitalogic_voice[quiet_end]" value="<?php echo esc_attr( $settings['quiet_end'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Per-number rate limits', 'digitalogic' ); ?></th><td><input type="number" min="1" max="10" name="digitalogic_voice[hourly_limit]" value="<?php echo esc_attr( (string) $settings['hourly_limit'] ); ?>"> / <?php esc_html_e( 'hour', 'digitalogic' ); ?>; <input type="number" min="1" max="30" name="digitalogic_voice[daily_limit]" value="<?php echo esc_attr( (string) $settings['daily_limit'] ); ?>"> / <?php esc_html_e( 'day', 'digitalogic' ); ?></td></tr>
				</tbody></table>
				<h2><?php esc_html_e( 'Order status messages', 'digitalogic' ); ?></h2>
				<p><code>{first_name}</code> <code>{order_number}</code> <code>{order_status}</code> <code>{site_name}</code></p>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Status', 'digitalogic' ); ?></th><th><?php esc_html_e( 'Enabled', 'digitalogic' ); ?></th><th><?php esc_html_e( 'Persian message template', 'digitalogic' ); ?></th></tr></thead><tbody>
				<?php foreach ( self::STATUS_SLUGS as $slug ) : ?>
					<tr><th><?php echo esc_html( self::status_label( $slug ) ); ?></th><td><input type="checkbox" name="digitalogic_voice[statuses][<?php echo esc_attr( $slug ); ?>][enabled]" value="1" <?php checked( $settings['statuses'][ $slug ]['enabled'] ); ?>></td><td><textarea class="large-text" rows="2" maxlength="800" name="digitalogic_voice[statuses][<?php echo esc_attr( $slug ); ?>][template]"><?php echo esc_textarea( $settings['statuses'][ $slug ]['template'] ); ?></textarea></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button( __( 'Save voice settings', 'digitalogic' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a validated template against an order.
	 *
	 * @param string $template Template.
	 * @param object $order    WooCommerce order.
	 * @param string $status   Status slug.
	 * @return string
	 */
	public static function render_message( string $template, $order, string $status ): string {
		$valid = self::sanitize_template( $template );
		if ( is_wp_error( $valid ) || ! is_object( $order ) ) {
			return '';
		}
		$first_name   = method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '';
		$order_number = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '';
		$rendered     = trim(
			strtr(
				$valid,
				array(
					'{first_name}'   => wp_strip_all_tags( $first_name ),
					'{order_number}' => preg_replace( '/[^0-9A-Za-z._-]/', '', $order_number ),
					'{order_status}' => wp_strip_all_tags( self::status_label( $status ) ),
					'{site_name}'    => wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				)
			)
		);
		return strlen( $rendered ) <= self::MAX_RENDERED_BYTES ? $rendered : '';
	}

	/**
	 * Require the local service's exact 2xx JSON acknowledgement.
	 *
	 * @param int    $status_code HTTP status.
	 * @param string $content_type Response Content-Type.
	 * @param string $body         Response body.
	 * @return bool
	 */
	public static function callout_acknowledged( int $status_code, string $content_type, string $body ): bool {
		$media_type = strtolower( trim( explode( ';', $content_type, 2 )[0] ) );
		if ( $status_code < 200 || $status_code >= 300 || 'application/json' !== $media_type || strlen( $body ) > 16384 ) {
			return false;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) && true === ( $data['ok'] ?? null );
	}

	/**
	 * Construct the only permitted outbound call payload.
	 *
	 * @param string $target   PBX route target.
	 * @param string $message  Rendered message.
	 * @param int    $order_id Order id for a non-PII label.
	 * @return array
	 */
	public static function callout_payload( string $target, string $message, int $order_id ): array {
		return array(
			'to'          => $target,
			'text'        => $message,
			'language'    => 'fa',
			'label'       => 'woocommerce-order-' . $order_id,
			'max_retries' => 0,
		);
	}

	/**
	 * Disabled-by-default settings.
	 *
	 * @return array
	 */
	private static function defaults(): array {
		$templates = array(
			'pending'    => 'سفارش {order_number} شما در {site_name} ثبت شد و در انتظار پرداخت است.',
			'processing' => 'سفارش {order_number} شما در حال پردازش است.',
			'on-hold'    => 'سفارش {order_number} شما در انتظار بررسی است.',
			'completed'  => 'سفارش {order_number} شما تکمیل شد. از خرید شما سپاسگزاریم.',
			'cancelled'  => 'سفارش {order_number} شما لغو شد.',
			'refunded'   => 'مبلغ سفارش {order_number} بازپرداخت شد.',
			'failed'     => 'پرداخت سفارش {order_number} ناموفق بود. لطفاً وضعیت سفارش را بررسی کنید.',
		);
		$statuses  = array();
		foreach ( self::STATUS_SLUGS as $slug ) {
			$statuses[ $slug ] = array(
				'enabled'  => 0,
				'template' => $templates[ $slug ],
				'version'  => 1,
			);
		}
		return array(
			'global_enabled' => 0,
			'quiet_start'    => '09:00',
			'quiet_end'      => '21:00',
			'hourly_limit'   => 2,
			'daily_limit'    => 5,
			'statuses'       => $statuses,
		);
	}

	/**
	 * Resolve the hard-coded server configuration boundary.
	 *
	 * @return array|WP_Error
	 */
	private static function callout_config() {
		if ( ! self::schema_ready() ) {
			return new WP_Error( 'digitalogic_voice_schema', __( 'PBX storage and recovery schedules are not ready.', 'digitalogic' ) );
		}
		if ( ! defined( 'DIGITALOGIC_PBX_CALLOUT_URL' ) || ! defined( 'DIGITALOGIC_PBX_CALLOUT_TOKEN' ) ) {
			return new WP_Error( 'digitalogic_voice_config', __( 'PBX callout is not configured.', 'digitalogic' ) );
		}
		$url         = (string) DIGITALOGIC_PBX_CALLOUT_URL;
		$token       = (string) DIGITALOGIC_PBX_CALLOUT_TOKEN;
		$valid_token = 1 === preg_match( '/^[\x21-\x7E]{32,512}$/D', $token );
		if ( ! hash_equals( self::CALLOUT_URL, $url ) || ! $valid_token ) {
			return new WP_Error( 'digitalogic_voice_config', __( 'PBX callout configuration is invalid.', 'digitalogic' ) );
		}
		return array(
			'url'   => $url,
			'token' => $token,
		);
	}

	/**
	 * Whether administrators may enable the global outbound switch.
	 *
	 * @return bool
	 */
	public static function callout_configuration_ready(): bool {
		return ! is_wp_error( self::callout_config() );
	}

	/**
	 * Compute the next time inside the allowed Tehran calling window.
	 *
	 * @param array $settings Settings.
	 * @return int Unix timestamp.
	 */
	private static function next_allowed_time( array $settings ): int {
		$timezone = new DateTimeZone( 'Asia/Tehran' );
		$now      = new DateTimeImmutable( 'now', $timezone );
		$start    = self::sanitize_time( $settings['quiet_start'] ?? '', '09:00' );
		$end      = self::sanitize_time( $settings['quiet_end'] ?? '', '21:00' );
		$current  = $now->format( 'H:i' );
		if ( $start < $end ) {
			if ( $current >= $start && $current < $end ) {
				return time();
			}
			$day = $current < $start ? $now : $now->modify( '+1 day' );
			return $day->setTime( (int) substr( $start, 0, 2 ), (int) substr( $start, 3, 2 ) )->getTimestamp();
		}
		if ( $current >= $start || $current < $end ) {
			return time();
		}
		return $now->setTime( (int) substr( $start, 0, 2 ), (int) substr( $start, 3, 2 ) )->getTimestamp();
	}

	/**
	 * Atomically reserve hourly and daily outbound capacity before the network.
	 *
	 * @param string $phone_hash Installation-keyed canonical phone hash.
	 * @param int    $contact_id Representative contact id for diagnostics.
	 * @param array  $settings   Settings.
	 * @return int|WP_Error Zero when reserved, otherwise delay seconds.
	 */
	private function reserve_rate( string $phone_hash, int $contact_id, array $settings ) {
		global $wpdb;
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $phone_hash ) ) {
			return new WP_Error( 'digitalogic_voice_rate', __( 'Voice rate reservation is unavailable.', 'digitalogic' ) );
		}
		$now     = time();
		$windows = array(
			array(
				'name'    => 'hour',
				'seconds' => HOUR_IN_SECONDS,
				'limit'   => (int) $settings['hourly_limit'],
			),
			array(
				'name'    => 'day',
				'seconds' => DAY_IN_SECONDS,
				'limit'   => (int) $settings['daily_limit'],
			),
		);
		foreach ( $windows as &$window ) {
			$window['started'] = $now - ( $now % $window['seconds'] );
			// value_hash is already a keyed digest of the canonical number, so
			// duplicate contacts/accounts share one physical-number allowance.
			$window['key'] = hash( 'sha256', implode( '|', array( 'v2', $phone_hash, $window['name'], $window['started'] ) ) );
		}
		unset( $window );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'digitalogic_voice_rate', __( 'Voice rate reservation is unavailable.', 'digitalogic' ) );
		}
		foreach ( $windows as $window ) {
			$inserted = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . self::rate_table() . ' (bucket_key,contact_id,window_name,window_started,counter,updated_at) VALUES (%s,%d,%s,%s,0,%s)',
					$window['key'],
					$contact_id,
					$window['name'],
					gmdate( 'Y-m-d H:i:s', $window['started'] ),
					gmdate( 'Y-m-d H:i:s', $now )
				)
			);
			if ( false === $inserted ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_voice_rate', __( 'Voice rate reservation is unavailable.', 'digitalogic' ) );
			}
		}

		$delay = 0;
		foreach ( $windows as &$window ) {
			$counter = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT counter FROM ' . self::rate_table() . ' WHERE bucket_key = %s FOR UPDATE',
					$window['key']
				)
			);
			if ( null === $counter ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_voice_rate', __( 'Voice rate reservation is unavailable.', 'digitalogic' ) );
			}
			$window['counter'] = (int) $counter;
			if ( $window['counter'] >= $window['limit'] ) {
				$delay = max( $delay, $window['started'] + $window['seconds'] - $now + 1 );
			}
		}
		unset( $window );
		if ( $delay > 0 ) {
			$wpdb->query( 'ROLLBACK' );
			return $delay;
		}

		foreach ( $windows as $window ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::rate_table() . ' SET counter = counter + 1, updated_at = %s WHERE bucket_key = %s AND counter = %d',
					gmdate( 'Y-m-d H:i:s', $now ),
					$window['key'],
					$window['counter']
				)
			);
			if ( 1 !== (int) $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'digitalogic_voice_rate', __( 'Voice rate reservation is unavailable.', 'digitalogic' ) );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'digitalogic_voice_rate', __( 'Voice rate reservation is unavailable.', 'digitalogic' ) );
		}
		return 0;
	}

	/**
	 * Postpone a policy-limited job without counting it as a delivery retry.
	 *
	 * @param int    $job_id      Job id.
	 * @param int    $timestamp   Next attempt.
	 * @param string $from_status Current job status.
	 */
	private function postpone_job( int $job_id, int $timestamp, string $from_status = 'pending' ): void {
		global $wpdb;
		$updated = $wpdb->update(
			self::table(),
			array(
				'status'       => 'pending',
				'started_at'   => null,
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $job_id,
				'status' => $from_status,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 === (int) $updated ) {
			self::schedule( $job_id, $timestamp );
		}
	}

	/**
	 * Finalize a job without persisting phone or message content.
	 *
	 * @param int    $job_id Job id.
	 * @param string $status Final status.
	 * @param string $error  Redacted diagnostic.
	 */
	private function finish_job( int $job_id, string $status, string $error ): void {
		global $wpdb;
		$data    = array(
			'status'     => $status,
			'last_error' => substr( sanitize_text_field( $error ), 0, 255 ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		$formats = array( '%s', '%s', '%s' );
		if ( 'sent' === $status ) {
			$data['sent_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[]       = '%s';
		}
		$wpdb->update( self::table(), $data, array( 'id' => $job_id ), $formats, array( '%d' ) );
	}

	/**
	 * Use Action Scheduler when available, otherwise WP-Cron.
	 *
	 * @param int $job_id    Job id.
	 * @param int $timestamp Run time.
	 * @return bool Whether either scheduler accepted or already has the job.
	 */
	private static function schedule( int $job_id, int $timestamp ): bool {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// Do not use Action Scheduler's "unique" flag. WooCommerce versions
			// in production de-duplicate by hook/group without considering args,
			// which would strand every job after the first one site-wide.
			$action_id = as_schedule_single_action( $timestamp, self::ACTION, array( $job_id ), self::ACTION_GROUP, false );
			if ( is_numeric( $action_id ) && (int) $action_id > 0 ) {
				return true;
			}
		}
		if ( wp_next_scheduled( self::ACTION, array( $job_id ) ) ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( $timestamp, self::ACTION, array( $job_id ), true );
		return ! is_wp_error( $scheduled ) && true === $scheduled;
	}

	/**
	 * Normalize an HH:MM field.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Default.
	 * @return string
	 */
	private static function sanitize_time( $value, string $fallback ): string {
		$value = (string) $value;
		return 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value ) ? $value : $fallback;
	}

	/**
	 * Localized WooCommerce status label.
	 *
	 * @param string $slug Status slug.
	 * @return string
	 */
	private static function status_label( string $slug ): string {
		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $slug ) : ucfirst( str_replace( '-', ' ', $slug ) );
	}

	/**
	 * Job table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_voice_jobs';
	}

	/**
	 * Atomic outbound rate-reservation table name.
	 *
	 * @return string
	 */
	private static function rate_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_voice_rate_reservations';
	}
}
