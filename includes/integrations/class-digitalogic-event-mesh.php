<?php
/**
 * Durable workstation events, presence evidence, and caller context.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The reviewed tables are private plugin storage and are intentionally queried directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

/**
 * Coordinates authenticated workstation events and bounded caller context.
 */
final class Digitalogic_Event_Mesh {

	private const SERVICE_KEY_OPTION       = 'digitalogic_event_mesh_service_key_hash';
	private const ROUTER_SUBJECTS_OPTION   = 'digitalogic_event_mesh_router_subjects';
	private const OPERATOR_USERS_OPTION    = 'digitalogic_event_mesh_operator_users';
	private const EVIDENCE_OPTION          = 'digitalogic_event_mesh_unassigned_evidence';
	private const MAX_NOTIFICATION_ACTIONS = 4;
	private const MAX_NOTIFICATION_FIELDS  = 4;
	private const MAX_FIELD_OPTIONS        = 20;
	private const MAX_AUDIENCE_ATTRIBUTES  = 10;
	private const MAX_ATTRIBUTE_VALUES     = 10;
	private const PRESENCE_FRESH_SECONDS   = 600;
	private const SESSION_FRESH_SECONDS    = 180;
	private const PRICING_REVISION_PATH    = '/wp-json/digitalogic/pricing/sync/revision';
	private const PRICING_EVENT_NAMES      = array(
		'pricing.state.changed',
		'pricing.source.changed',
		'pricing.source.removed',
		'pricing.snapshot.build.terminal',
	);

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,string> */
	private static $device_operator_cache = array();

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'digitalogic_command_handlers', array( $this, 'register_commands' ), 10, 2 );
	}

	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$workstations    = self::workstations_table();
		$responses       = self::responses_table();

		$sql = "CREATE TABLE {$workstations} (
			device_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operator_key varchar(80) NOT NULL DEFAULT '',
			label varchar(191) NOT NULL DEFAULT '',
			hostname varchar(191) NOT NULL DEFAULT '',
			platform varchar(40) NOT NULL DEFAULT '',
			app_version varchar(40) NOT NULL DEFAULT '',
			session_state varchar(24) NOT NULL DEFAULT 'unknown',
			presence_state varchar(24) NOT NULL DEFAULT 'unknown',
			presence_confidence varchar(24) NOT NULL DEFAULT 'low',
			evidence longtext NULL,
			capabilities longtext NULL,
			registered_at datetime NOT NULL,
			last_seen datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (device_id),
			KEY user_id (user_id),
			KEY operator_key (operator_key),
			KEY presence_state (presence_state),
			KEY last_seen (last_seen)
		) {$charset_collate};

		CREATE TABLE {$responses} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			correlation_id varchar(80) NOT NULL DEFAULT '',
			device_id varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action_id varchar(64) NOT NULL DEFAULT '',
			values_json longtext NULL,
			responded_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY correlation_id (correlation_id),
			KEY device_id (device_id),
			UNIQUE KEY response_once (event_id, device_id),
			KEY responded_at (responded_at)
		) {$charset_collate};";

		dbDelta( $sql );

		/*
		 * A stale `notoptions` entry can survive a deployment that creates the
		 * schema marker outside the request that first looked it up. Clear the
		 * negative cache before and after the idempotent write so the current
		 * request verifies the durable database value instead of repeatedly
		 * running dbDelta.
		 */
		wp_cache_delete( 'notoptions', 'options' );
		update_option( 'digitalogic_event_mesh_schema_version', DIGITALOGIC_EVENT_MESH_SCHEMA_VERSION, false );
		wp_cache_delete( 'digitalogic_event_mesh_schema_version', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return DIGITALOGIC_EVENT_MESH_SCHEMA_VERSION === (string) get_option( 'digitalogic_event_mesh_schema_version', '' );
	}

	public static function set_service_key( string $secret ): bool {
		$secret = trim( $secret );
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 256 ) {
			return false;
		}

		$next    = hash( 'sha256', $secret );
		$current = (string) get_option( self::SERVICE_KEY_OPTION, '' );
		return ( '' !== $current && hash_equals( $current, $next ) )
			|| update_option( self::SERVICE_KEY_OPTION, $next, false );
	}

	public static function set_router_subject( string $subject, string $operator_key ): bool {
		$subject_hash = self::subject_hash( $subject );
		$operator_key = sanitize_key( $operator_key );
		if ( '' === $subject_hash || '' === $operator_key ) {
			return false;
		}
		$subjects = get_option( self::ROUTER_SUBJECTS_OPTION, array() );
		$subjects = is_array( $subjects ) ? $subjects : array();
		if ( isset( $subjects[ $subject_hash ] ) && $operator_key === $subjects[ $subject_hash ] ) {
			return true;
		}
		$subjects[ $subject_hash ] = $operator_key;
		return update_option( self::ROUTER_SUBJECTS_OPTION, $subjects, false );
	}

	public static function set_operator_user( int $user_id, string $operator_key ): bool {
		$operator_key = sanitize_key( $operator_key );
		if ( $user_id < 1 || '' === $operator_key || ! get_userdata( $user_id ) ) {
			return false;
		}
		$operators = get_option( self::OPERATOR_USERS_OPTION, array() );
		$operators = is_array( $operators ) ? $operators : array();
		if ( isset( $operators[ $user_id ] ) && $operator_key === $operators[ $user_id ] ) {
			return true;
		}
		$operators[ $user_id ] = $operator_key;
		return update_option( self::OPERATOR_USERS_OPTION, $operators, false );
	}

	public function register_routes(): void {
		register_rest_route(
			'digitalogic/v1',
			'/event-mesh/notify',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'service_permission' ),
				'callback'            => array( $this, 'notify_rest' ),
			)
		);
		register_rest_route(
			'digitalogic/v1',
			'/event-mesh/presence',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'service_permission' ),
					'callback'            => array( $this, 'presence_rest' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => array( $this, 'service_permission' ),
					'callback'            => array( $this, 'presence_evidence_rest' ),
				),
			)
		);
		register_rest_route(
			'digitalogic/v1',
			'/event-mesh/caller-context',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => array( $this, 'service_permission' ),
				'callback'            => array( $this, 'caller_context_rest' ),
			)
		);
		register_rest_route(
			'digitalogic/v1',
			'/event-mesh/responses/(?P<correlation>[A-Za-z0-9._:-]{1,80})',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'service_permission' ),
				'callback'            => array( $this, 'responses_rest' ),
			)
		);
	}

	/**
	 * Authenticate an n8n service key or a normal privileged WordPress session.
	 *
	 * @return true|WP_Error
	 */
	public function service_permission( $request ) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
		if ( Digitalogic_Access_Control::can_access_panel() && current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$provided = trim( (string) $request->get_header( 'x-digitalogic-event-key' ) );
		$expected = (string) get_option( self::SERVICE_KEY_OPTION, '' );
		if ( '' !== $provided && '' !== $expected && hash_equals( $expected, hash( 'sha256', $provided ) ) ) {
			return true;
		}

		return new WP_Error(
			'digitalogic_event_mesh_unauthorized',
			__( 'Event mesh authentication failed.', 'digitalogic' ),
			array( 'status' => 403 )
		);
	}

	public function register_commands( $commands, $transport ) {
		unset( $transport );
		$commands['digitalogic_workstation_register'] = array( $this, 'register_workstation_command' );
		$commands['digitalogic_workstation_event']    = array( $this, 'workstation_event_command' );
		$commands['digitalogic_event_response']       = array( $this, 'event_response_command' );

		return $commands;
	}

	public function register_workstation_command( $payload ) {
		$device_id = self::sanitize_device_id( $payload['device_id'] ?? '' );
		if ( '' === $device_id ) {
			return new WP_Error( 'digitalogic_invalid_device', __( 'A valid workstation device ID is required.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new WP_Error( 'digitalogic_workstation_login_required', __( 'A logged-in workstation user is required.', 'digitalogic' ), array( 'status' => 401 ) );
		}

		global $wpdb;
		$now          = current_time( 'mysql', true );
		$operator_key = self::operator_for_user( $user_id );
		$existing     = $wpdb->get_row(
			$wpdb->prepare( 'SELECT user_id, registered_at, evidence FROM ' . self::workstations_table() . ' WHERE device_id = %s', $device_id ),
			ARRAY_A
		);
		if ( is_array( $existing ) && absint( $existing['user_id'] ?? 0 ) > 0 && $user_id !== absint( $existing['user_id'] ) ) {
			return new WP_Error( 'digitalogic_workstation_owner_conflict', __( 'This workstation ID is already registered to another user.', 'digitalogic' ), array( 'status' => 409 ) );
		}
		$evidence = is_array( $existing ) ? json_decode( (string) ( $existing['evidence'] ?? '' ), true ) : array();
		$evidence = is_array( $evidence ) ? $evidence : array();
		$evidence = array_merge( $this->unassigned_evidence( $operator_key ), $evidence );
		$presence = self::resolve_presence( $evidence );

		$result = $wpdb->replace(
			self::workstations_table(),
			array(
				'device_id'           => $device_id,
				'user_id'             => $user_id,
				'operator_key'        => $operator_key,
				'label'               => self::text( $payload['label'] ?? '', 191 ),
				'hostname'            => self::text( $payload['hostname'] ?? '', 191 ),
				'platform'            => self::text( $payload['platform'] ?? '', 40 ),
				'app_version'         => self::text( $payload['app_version'] ?? '', 40 ),
				'session_state'       => self::session_state( $payload['session_state'] ?? 'unknown' ),
				'presence_state'      => $presence['state'],
				'presence_confidence' => $presence['confidence'],
				'evidence'            => wp_json_encode( $evidence ),
				'capabilities'        => wp_json_encode( self::sanitize_capabilities( $payload['capabilities'] ?? array() ) ),
				'registered_at'       => is_array( $existing ) && ! empty( $existing['registered_at'] ) ? $existing['registered_at'] : $now,
				'last_seen'           => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'digitalogic_workstation_storage', __( 'The workstation registration could not be saved.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		self::$device_operator_cache[ $device_id ] = $operator_key;

		return array(
			'device_id'    => $device_id,
			'user_id'      => $user_id,
			'operator_key' => $operator_key,
			'presence'     => $presence,
			'registered'   => true,
			'server_time'  => gmdate( 'c' ),
		);
	}

	public function workstation_event_command( $payload ) {
		$device_id = self::sanitize_device_id( $payload['device_id'] ?? '' );
		$user_id   = get_current_user_id();
		if ( '' === $device_id || $user_id < 1 || ! $this->device_belongs_to_user( $device_id, $user_id ) ) {
			return new WP_Error( 'digitalogic_workstation_forbidden', __( 'This workstation is not registered to the current user.', 'digitalogic' ), array( 'status' => 403 ) );
		}

		$source = sanitize_key( (string) ( $payload['source'] ?? 'windows_session' ) );
		if ( ! in_array( $source, array( 'windows_session', 'windows_power', 'desktop_heartbeat' ), true ) ) {
			return new WP_Error( 'digitalogic_presence_source', __( 'Unsupported workstation presence source.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$state         = self::presence_evidence_state( $payload['state'] ?? 'unknown' );
		$observed_at   = self::iso_time( $payload['observed_at'] ?? '' );
		$confidence    = self::confidence( $payload['confidence'] ?? 'high' );
		$session_state = self::session_state( $payload['session_state'] ?? $state );
		$result        = $this->update_device_evidence(
			$device_id,
			$source,
			array(
				'state'         => $state,
				'observed_at'   => $observed_at,
				'confidence'    => $confidence,
				'session_state' => $session_state,
			),
			$session_state
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$event = Digitalogic_Panel::record_event(
			'workstation.presence.changed',
			array(
				'device_id'    => $device_id,
				'operator_key' => $result['operator_key'],
				'presence'     => $result['presence'],
				'source'       => $source,
				'observed_at'  => $observed_at,
				'audience'     => array(
					'users'     => array( $user_id ),
					'devices'   => array( $device_id ),
					'operators' => array_filter( array( $result['operator_key'] ) ),
				),
			)
		);

		return array(
			'accepted' => true,
			'presence' => $result['presence'],
			'event_id' => is_array( $event ) ? (int) $event['id'] : 0,
		);
	}

	public function event_response_command( $payload ) {
		$device_id = self::sanitize_device_id( $payload['device_id'] ?? '' );
		$user_id   = get_current_user_id();
		if ( '' === $device_id || $user_id < 1 || ! $this->device_belongs_to_user( $device_id, $user_id ) ) {
			return new WP_Error( 'digitalogic_response_forbidden', __( 'This workstation cannot answer the event.', 'digitalogic' ), array( 'status' => 403 ) );
		}

		$correlation_id = self::identifier( $payload['correlation_id'] ?? '', 80 );
		$action_id      = self::identifier( $payload['action_id'] ?? '', 64 );
		$event_id       = absint( $payload['event_id'] ?? 0 );
		if ( $event_id < 1 || '' === $correlation_id || '' === $action_id ) {
			return new WP_Error( 'digitalogic_response_invalid', __( 'An event ID, correlation ID, and action ID are required.', 'digitalogic' ), array( 'status' => 400 ) );
		}
		$notification = self::notification_event( $event_id, $correlation_id, $user_id, $device_id );
		if ( is_wp_error( $notification ) ) {
			return $notification;
		}
		$notification_data = is_array( $notification['data'] ?? null ) ? $notification['data'] : array();
		$action_ids        = array_values(
			array_filter(
				array_map(
					static function ( $action ) {
						return is_array( $action ) ? self::identifier( $action['id'] ?? '', 64 ) : '';
					},
					(array) ( $notification_data['actions'] ?? array() )
				)
			)
		);
		if ( ! in_array( $action_id, $action_ids, true ) ) {
			return new WP_Error( 'digitalogic_response_action', __( 'The selected action is not available for this notification.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::responses_table() . ' WHERE event_id = %d AND device_id = %s LIMIT 1',
				$event_id,
				$device_id
			)
		);
		if ( absint( $existing ) > 0 ) {
			return array(
				'accepted'       => true,
				'duplicate'      => true,
				'correlation_id' => $correlation_id,
				'action_id'      => $action_id,
			);
		}
		$values = self::sanitize_notification_response_values(
			$payload['values'] ?? array(),
			(array) ( $notification_data['fields'] ?? array() )
		);
		foreach ( (array) ( $notification_data['fields'] ?? array() ) as $field ) {
			$field_id = is_array( $field ) ? self::identifier( $field['id'] ?? '', 64 ) : '';
			if ( is_array( $field ) && ! empty( $field['required'] ) && ( '' === $field_id || ! array_key_exists( $field_id, $values ) ) ) {
				return new WP_Error( 'digitalogic_response_required_field', __( 'A required notification response field is missing or invalid.', 'digitalogic' ), array( 'status' => 400 ) );
			}
		}
		$inserted = $wpdb->insert(
			self::responses_table(),
			array(
				'event_id'       => $event_id,
				'correlation_id' => $correlation_id,
				'device_id'      => $device_id,
				'user_id'        => $user_id,
				'action_id'      => $action_id,
				'values_json'    => wp_json_encode( $values ),
				'responded_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$duplicate = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::responses_table() . ' WHERE event_id = %d AND device_id = %s LIMIT 1',
					$event_id,
					$device_id
				)
			);
			if ( absint( $duplicate ) > 0 ) {
				return array(
					'accepted'       => true,
					'duplicate'      => true,
					'correlation_id' => $correlation_id,
					'action_id'      => $action_id,
				);
			}
			return new WP_Error( 'digitalogic_response_storage', __( 'The event response could not be saved.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		Digitalogic_Panel::record_event(
			'workstation.notification.response',
			array(
				'correlation_id' => $correlation_id,
				'device_id'      => $device_id,
				'user_id'        => $user_id,
				'action_id'      => $action_id,
				'responded_at'   => gmdate( 'c' ),
				'audience'       => array(
					'users'   => array( $user_id ),
					'devices' => array( $device_id ),
				),
			)
		);

		return array(
			'accepted'       => true,
			'correlation_id' => $correlation_id,
			'action_id'      => $action_id,
		);
	}

	public function notify_rest( $request ) {
		$notification = self::sanitize_notification( $request->get_json_params() );
		if ( is_wp_error( $notification ) ) {
			return $notification;
		}

		$result = Digitalogic_Panel::record_event_result( 'workstation.notification', $notification );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'accepted'          => true,
				'event'             => $result['event'],
				'delivery_warnings' => $result['delivery_warnings'],
			)
		);
	}

	public function presence_rest( $request ) {
		global $wpdb;
		$device_id    = self::sanitize_device_id( $request->get_param( 'device_id' ) );
		$operator_key = sanitize_key( (string) $request->get_param( 'operator_key' ) );
		$where        = array();
		$args         = array();
		if ( '' !== $device_id ) {
			$where[] = 'device_id = %s';
			$args[]  = $device_id;
		}
		if ( '' !== $operator_key ) {
			$where[] = 'operator_key = %s';
			$args[]  = $operator_key;
		}
		$sql = 'SELECT device_id, user_id, operator_key, label, hostname, platform, app_version, session_state, presence_state, presence_confidence, evidence, last_seen, updated_at FROM ' . self::workstations_table();
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY last_seen DESC LIMIT 100';
		$rows = empty( $args ) ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as &$row ) {
			$row['user_id']  = absint( $row['user_id'] ?? 0 );
			$row['evidence'] = json_decode( (string) ( $row['evidence'] ?? '' ), true );
			$row['evidence'] = is_array( $row['evidence'] ) ? $row['evidence'] : array();
			$row['presence'] = self::resolve_presence( $row['evidence'] );
			unset( $row['presence_state'], $row['presence_confidence'] );
		}

		return rest_ensure_response(
			array(
				'workstations' => $rows,
				'server_time'  => gmdate( 'c' ),
			)
		);
	}

	public function presence_evidence_rest( $request ) {
		$payload = $request->get_json_params();
		$source  = sanitize_key( (string) ( $payload['source'] ?? '' ) );
		if ( ! in_array( $source, array( 'routeros_dhcp', 'routeros_wifi', 'routeros_arp', 'manual' ), true ) ) {
			return new WP_Error( 'digitalogic_presence_source', __( 'Unsupported presence source.', 'digitalogic' ), array( 'status' => 400 ) );
		}
		$operator_key = sanitize_key( (string) ( $payload['operator_key'] ?? '' ) );
		$device_id    = self::sanitize_device_id( $payload['device_id'] ?? '' );
		$subject_hash = strtolower( trim( (string) ( $payload['subject_hash'] ?? '' ) ) );
		$subject_hash = 1 === preg_match( '/^[a-f0-9]{64}$/', $subject_hash ) ? $subject_hash : '';
		if ( str_starts_with( $source, 'routeros_' ) ) {
			$subjects     = get_option( self::ROUTER_SUBJECTS_OPTION, array() );
			$operator_key = is_array( $subjects ) && '' !== $subject_hash
				? sanitize_key( (string) ( $subjects[ $subject_hash ] ?? '' ) )
				: '';
			if ( '' === $operator_key ) {
				return rest_ensure_response(
					array(
						'accepted' => false,
						'skipped'  => true,
						'reason'   => 'unmapped_router_subject',
					)
				);
			}
		}
		if ( '' === $operator_key && '' === $device_id ) {
			return new WP_Error( 'digitalogic_presence_subject', __( 'An operator key or device ID is required.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$evidence = array(
			'state'       => self::presence_evidence_state( $payload['state'] ?? 'unknown' ),
			'observed_at' => self::iso_time( $payload['observed_at'] ?? '' ),
			'confidence'  => self::confidence( $payload['confidence'] ?? ( 'routeros_arp' === $source ? 'low' : 'medium' ) ),
			'metadata'    => self::sanitize_response_values( $payload['metadata'] ?? array() ),
		);
		$updated  = $this->update_subject_evidence( $operator_key, $device_id, $source, $evidence );

		Digitalogic_Panel::record_event(
			'operator.presence.changed',
			array(
				'operator_key' => $operator_key,
				'device_id'    => $device_id,
				'source'       => $source,
				'evidence'     => $evidence,
				'workstations' => $updated,
				'audience'     => array(
					'devices'   => array_column( $updated, 'device_id' ),
					'operators' => array_filter( array( $operator_key ) ),
				),
			)
		);

		return rest_ensure_response(
			array(
				'accepted'     => true,
				'workstations' => $updated,
			)
		);
	}

	public function caller_context_rest( $request ) {
		$phone = Digitalogic_PBX_Phone::normalize_trusted_ani( $request->get_param( 'phone' ) );
		if ( '' === $phone ) {
			return new WP_Error( 'digitalogic_caller_phone', __( 'A supported normalized caller number is required.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$customers = $this->woocommerce_customer_candidates( $phone );
		$orders    = $this->woocommerce_order_history( $phone );
		$context   = array(
			'phone'         => $phone,
			'display'       => Digitalogic_PBX_Phone::display( $phone ),
			'matched'       => ! empty( $customers ) || ! empty( $orders ),
			'customers'     => $customers,
			'recent_orders' => $orders,
			'sources'       => array(
				array(
					'name'       => 'woocommerce',
					'available'  => function_exists( 'wc_get_orders' ),
					'candidates' => count( $customers ),
					'orders'     => count( $orders ),
				),
			),
			'looked_up_at'  => gmdate( 'c' ),
		);

		$context = apply_filters( 'digitalogic_caller_context', $context, $phone, $request );
		return rest_ensure_response( is_array( $context ) ? $context : array() );
	}

	public function responses_rest( $request ) {
		global $wpdb;
		$correlation_id = self::identifier( $request['correlation'] ?? '', 80 );
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT event_id, correlation_id, device_id, user_id, action_id, values_json, responded_at FROM ' . self::responses_table() . ' WHERE correlation_id = %s ORDER BY id ASC LIMIT 100',
				$correlation_id
			),
			ARRAY_A
		);
		$rows           = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['event_id'] = absint( $row['event_id'] ?? 0 );
			$row['user_id']  = absint( $row['user_id'] ?? 0 );
			$row['values']   = json_decode( (string) ( $row['values_json'] ?? '' ), true );
			$row['values']   = is_array( $row['values'] ) ? $row['values'] : array();
			unset( $row['values_json'] );
		}

		return rest_ensure_response(
			array(
				'correlation_id' => $correlation_id,
				'responses'      => $rows,
			)
		);
	}

	public static function event_visible_to( array $event, int $user_id, string $device_id = '', string $service = '', array $service_source = array() ): bool {
		$name = (string) ( $event['name'] ?? $event['event'] ?? '' );
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		if ( 'workstation.notification' === $name ) {
			$expires_at = strtotime( (string) ( $data['expires_at'] ?? '' ) );
			if ( false !== $expires_at && $expires_at < time() ) {
				return false;
			}
		}
		$audience = isset( $data['audience'] ) && is_array( $data['audience'] ) ? $data['audience'] : array();
		if ( '' !== $service ) {
			$decision = self::pricing_event_delivery_decision( $event, $service, $service_source );
			return ! empty( $decision['visible'] );
		}
		if ( ! $audience ) {
			return true;
		}

		if ( ! empty( $audience['broadcast'] ) ) {
			return true;
		}

		$matches = array();
		$users   = array_map( 'absint', (array) ( $audience['users'] ?? array() ) );
		if ( $users ) {
			$matches[] = $user_id > 0 && in_array( $user_id, $users, true );
		}
		$device_id = self::sanitize_device_id( $device_id );
		$devices   = (array) ( $audience['devices'] ?? array() );
		if ( $devices ) {
			$matches[] = '' !== $device_id && in_array( $device_id, $devices, true );
		}
		$operator_key = '' !== $device_id ? self::operator_for_device( $device_id ) : '';
		$operators    = (array) ( $audience['operators'] ?? array() );
		if ( $operators ) {
			$matches[] = '' !== $operator_key && in_array( $operator_key, $operators, true );
		}
		$roles = array_map( 'sanitize_key', (array) ( $audience['roles'] ?? array() ) );
		if ( $roles ) {
			$user_roles = self::user_roles( $user_id );
			$matches[]  = (bool) array_intersect( $roles, $user_roles );
		}
		$attributes = is_array( $audience['attributes'] ?? null ) ? $audience['attributes'] : array();
		if ( $attributes ) {
			$matches[] = self::user_matches_attributes(
				$user_id,
				$attributes,
				'any' === (string) ( $audience['attribute_match'] ?? 'all' ) ? 'any' : 'all'
			);
		}

		if ( ! $matches ) {
			return false;
		}

		return 'all' === (string) ( $audience['match'] ?? 'any' )
			? ! in_array( false, $matches, true )
			: in_array( true, $matches, true );
	}

	/**
	 * Authorize one pricing event independently from optional representation metadata.
	 *
	 * The exact service audience and source ID/dataset remain the security boundary.
	 * Schema labels, paths, validators, component revisions, and provider extensions
	 * produce bounded diagnostics and recovery guidance without hiding a safe event.
	 *
	 * @param array  $event          Durable panel event.
	 * @param string $service        Authenticated service principal.
	 * @param array  $service_source Exact source scope authenticated at handshake.
	 * @return array
	 */
	public static function pricing_event_delivery_decision( array $event, string $service, array $service_source ): array {
		$name     = isset( $event['name'] ) && is_string( $event['name'] ) ? $event['name'] : '';
		$decision = array(
			'visible'     => false,
			'authorized'  => false,
			'blocking'    => false,
			'data'        => isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array(),
			'diagnostics' => array(),
			'recovery'    => self::pricing_recovery_plan( 'consume_event' ),
		);
		if ( self::pricing_service() !== $service || ! in_array( $name, self::PRICING_EVENT_NAMES, true ) ) {
			return $decision;
		}
		if ( ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
			return self::blocking_pricing_decision(
				$decision,
				'malformed_event_frame',
				'Pricing event data is not a structured object.'
			);
		}

		$data     = $event['data'];
		$audience = isset( $data['audience'] ) && is_array( $data['audience'] ) ? $data['audience'] : array();
		$services = isset( $audience['services'] ) && is_array( $audience['services'] )
			? array_values( array_filter( $audience['services'], 'is_string' ) )
			: array();
		if ( ! in_array( $service, $services, true ) ) {
			return $decision;
		}

		$event_source = isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : array();
		if (
			! self::valid_pricing_identity_component( $service_source['id'] ?? null )
			|| ! self::valid_pricing_identity_component( $service_source['dataset'] ?? null )
		) {
			return self::blocking_pricing_decision(
				$decision,
				'unsafe_service_identity',
				'The authenticated pricing connection has no unambiguous source scope.'
			);
		}
		if (
			! self::valid_pricing_identity_component( $event_source['id'] ?? null )
			|| ! self::valid_pricing_identity_component( $event_source['dataset'] ?? null )
		) {
			return self::blocking_pricing_decision(
				$decision,
				'unsafe_source_identity',
				'The pricing event has no unambiguous source ID and dataset.'
			);
		}
		if (
			! hash_equals( (string) $service_source['id'], (string) $event_source['id'] )
			|| ! hash_equals( (string) $service_source['dataset'], (string) $event_source['dataset'] )
		) {
			return $decision;
		}
		if ( self::pricing_payload_has_sensitive_key( $data ) ) {
			return self::blocking_pricing_decision(
				$decision,
				'unsafe_event_metadata',
				'The pricing event contains credential-bearing metadata and cannot be forwarded.'
			);
		}

		$decision['authorized']  = true;
		$decision['visible']     = true;
		$normalized              = $data;
		$diagnostics             = self::pricing_representation_diagnostics( $name, $normalized );
		$decision['data']        = $normalized;
		$decision['diagnostics'] = $diagnostics;
		$decision['recovery']    = self::pricing_recovery_for_diagnostics( $diagnostics );

		if ( 'pricing.snapshot.build.terminal' === $name ) {
			if (
				1 !== preg_match( '/\Abuild_[a-f0-9]{32}\z/D', (string) ( $data['build_id'] ?? '' ) )
				|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', (string) ( $data['request_id'] ?? '' ) )
			) {
				return self::blocking_pricing_decision(
					$decision,
					'unsafe_event_identity',
					'The terminal pricing event has no unambiguous build and request identity.'
				);
			}
		}

		return $decision;
	}

	/**
	 * Build a blocking decision without copying unsafe provider values.
	 *
	 * @param array  $decision Existing delivery decision.
	 * @param string $code     Stable diagnostic code.
	 * @param string $reason   Safe human-readable reason.
	 * @return array
	 */
	private static function blocking_pricing_decision( array $decision, string $code, string $reason ): array {
		$decision['visible']     = false;
		$decision['authorized']  = false;
		$decision['blocking']    = true;
		$decision['data']        = array();
		$decision['diagnostics'] = array(
			self::pricing_diagnostic( $code, 'ERROR', true, $reason, false, 'stop_and_reauthorize' ),
		);
		$decision['recovery']    = self::pricing_recovery_plan( 'stop_and_reauthorize' );
		return $decision;
	}

	/**
	 * Return diagnostics while removing malformed optional adapter values.
	 *
	 * @param string $name Pricing event name.
	 * @param array  $data Event data normalized by reference.
	 * @return array
	 */
	private static function pricing_representation_diagnostics( string $name, array &$data ): array {
		$diagnostics = array();
		$known       = array( 'schema', 'schema_version', 'projection', 'source', 'idempotency_key', 'audience' );
		if ( 'pricing.state.changed' === $name ) {
			$known = array_merge( $known, array( 'state_revision', 'etag', 'catalog_revision', 'pricing_state_revision', 'pricing_policy_revision', 'cause', 'revision_path' ) );
		} elseif ( in_array( $name, array( 'pricing.source.changed', 'pricing.source.removed' ), true ) ) {
			$known = array_merge( $known, array( 'change', 'previous_source_revision', 'revision_validation_required', 'revision_path' ) );
		} else {
			$known = array_merge( $known, array( 'build_id', 'request_id', 'status', 'state_revision', 'etag', 'pricing_state_revision', 'pricing_policy_revision', 'catalog_revision', 'snapshot_token', 'revision', 'row_count', 'snapshot_revision', 'digest', 'snapshot_path', 'code', 'retryable', 'revision_path' ) );
		}

		$unknown = array_values( array_diff( array_map( 'strval', array_keys( $data ) ), $known ) );
		$source  = isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : array();
		foreach ( array_diff( array_map( 'strval', array_keys( $source ) ), array( 'id', 'dataset', 'revision' ) ) as $field ) {
			$unknown[] = 'source.' . $field;
		}
		if ( $unknown ) {
			sort( $unknown, SORT_STRING );
			$diagnostics[] = self::pricing_diagnostic(
				'metadata_warning',
				'INFO',
				false,
				'Additive pricing event fields were preserved as provider extensions and ignored for authorization.',
				false,
				'consume_event',
				array( 'fields' => array_slice( array_values( array_unique( $unknown ) ), 0, 20 ) )
			);
		}

		$descriptive_missing = array();
		foreach ( array( 'schema', 'schema_version', 'projection' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) || ! is_scalar( $data[ $field ] ) || '' === (string) $data[ $field ] ) {
				if ( array_key_exists( $field, $data ) && ! is_scalar( $data[ $field ] ) ) {
					unset( $data[ $field ] );
				}
				$descriptive_missing[] = $field;
			}
		}
		if ( $descriptive_missing ) {
			$diagnostics[] = self::pricing_diagnostic(
				'metadata_warning',
				'INFO',
				false,
				'Descriptive schema or projection metadata is absent or unusable.',
				false,
				'consume_event',
				array( 'fields' => $descriptive_missing )
			);
		}

		$source_missing = array();
		self::normalize_optional_revisions( $data['source'], array( 'revision' ), $source_missing );
		if ( $source_missing ) {
			$diagnostics[] = self::pricing_diagnostic(
				'provider_capability_missing',
				'INFO',
				false,
				'An optional source revision is unavailable; exact source scope remains authorized.',
				true,
				'conditional_refresh',
				array( 'capabilities' => array( 'source_revision' ) )
			);
		}

		if ( 'pricing.state.changed' === $name ) {
			$component_missing = array();
			self::normalize_optional_revisions(
				$data,
				array( 'state_revision', 'catalog_revision', 'pricing_state_revision', 'pricing_policy_revision' ),
				$component_missing
			);
			self::normalize_optional_etag( $data, $component_missing, $diagnostics );
			self::append_optional_metadata_diagnostics( $diagnostics, $data, $component_missing, array( 'revision_path' ) );
		} elseif ( in_array( $name, array( 'pricing.source.changed', 'pricing.source.removed' ), true ) ) {
			$previous_missing = array();
			if ( array_key_exists( 'previous_source_revision', $data ) && null !== $data['previous_source_revision'] ) {
				self::normalize_optional_revisions( $data, array( 'previous_source_revision' ), $previous_missing );
			}
			$change = isset( $data['change'] ) && is_string( $data['change'] ) ? $data['change'] : '';
			if (
				! in_array( $change, array( 'added', 'changed', 'removed' ), true )
				|| ( 'pricing.source.removed' === $name ) !== ( 'removed' === $change )
			) {
				unset( $data['change'] );
				$previous_missing[] = 'change';
			}
			self::append_optional_metadata_diagnostics( $diagnostics, $data, $previous_missing, array( 'revision_path' ) );
		} else {
			$component_missing = array();
			self::normalize_optional_revisions( $data, array( 'state_revision', 'pricing_state_revision', 'pricing_policy_revision', 'catalog_revision' ), $component_missing );
			self::normalize_optional_etag( $data, $component_missing, $diagnostics );
			$status = isset( $data['status'] ) && is_string( $data['status'] ) ? $data['status'] : '';
			if ( ! in_array( $status, array( 'ready', 'failed', 'cancelled' ), true ) ) {
				unset( $data['status'] );
				$component_missing[] = 'status';
			}
			if ( 'ready' === $status ) {
				self::normalize_terminal_snapshot_metadata( $data, $component_missing, $diagnostics );
			}
			self::append_optional_metadata_diagnostics( $diagnostics, $data, $component_missing, array( 'revision_path' ) );
		}

		return $diagnostics;
	}

	/**
	 * Remove invalid optional revisions while recording absent capabilities.
	 *
	 * @param array $data    Event data normalized by reference.
	 * @param array $fields  Optional revision field names.
	 * @param array $missing Missing or unusable fields collected by reference.
	 * @return void
	 */
	private static function normalize_optional_revisions( array &$data, array $fields, array &$missing ): void {
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $data ) || ! self::is_pricing_revision( $data[ $field ] ) ) {
				unset( $data[ $field ] );
				$missing[] = $field;
			}
		}
	}

	/**
	 * Normalize ETag syntax when a comparable optional state revision exists.
	 *
	 * @param array $data        Event data normalized by reference.
	 * @param array $missing     Missing capabilities collected by reference.
	 * @param array $diagnostics Diagnostics collected by reference.
	 * @return void
	 */
	private static function normalize_optional_etag( array &$data, array &$missing, array &$diagnostics ): void {
		if ( ! isset( $data['etag'] ) || ! is_string( $data['etag'] ) ) {
			unset( $data['etag'] );
			$missing[] = 'etag';
			return;
		}
		$etag = trim( $data['etag'] );
		if ( '' === $etag || strlen( $etag ) > 256 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $etag ) ) {
			unset( $data['etag'] );
			$missing[] = 'etag';
			return;
		}

		$opaque = str_starts_with( $etag, 'W/' ) ? substr( $etag, 2 ) : $etag;
		if ( strlen( $opaque ) >= 2 && '"' === $opaque[0] && '"' === $opaque[ strlen( $opaque ) - 1 ] ) {
			$opaque = substr( $opaque, 1, -1 );
		}
		if ( isset( $data['state_revision'] ) && ! hash_equals( $data['state_revision'], $opaque ) ) {
			unset( $data['etag'] );
			$diagnostics[] = self::pricing_diagnostic(
				'metadata_warning',
				'WARNING',
				false,
				'Optional ETag metadata does not identify the advertised semantic state revision.',
				true,
				'conditional_refresh',
				array( 'fields' => array( 'etag', 'state_revision' ) )
			);
			return;
		}
		if ( isset( $data['state_revision'] ) ) {
			$data['etag'] = '"' . $data['state_revision'] . '"';
		}
	}

	/**
	 * Report missing optional fields and remove unsafe provider paths.
	 *
	 * @param array $diagnostics Diagnostics collected by reference.
	 * @param array $data        Event data normalized by reference.
	 * @param array $missing     Missing or unusable fields.
	 * @param array $path_fields Optional provider path fields.
	 * @return void
	 */
	private static function append_optional_metadata_diagnostics( array &$diagnostics, array &$data, array $missing, array $path_fields ): void {
		foreach ( $path_fields as $field ) {
			if ( ! isset( $data[ $field ] ) || ! self::safe_pricing_relative_path( $data[ $field ] ) ) {
				unset( $data[ $field ] );
				$missing[] = $field;
			}
		}
		$missing = array_values( array_unique( $missing ) );
		if ( ! $missing ) {
			return;
		}

		$diagnostics[] = self::pricing_diagnostic(
			'provider_capability_missing',
			'INFO',
			false,
			'Optional pricing event metadata is absent or unusable; use the bounded canonical refresh path.',
			true,
			'conditional_refresh',
			array( 'capabilities' => array_slice( $missing, 0, 20 ) )
		);
	}

	/**
	 * Normalize snapshot metadata without treating optional validators as identity.
	 *
	 * @param array $data        Event data normalized by reference.
	 * @param array $missing     Missing capabilities collected by reference.
	 * @param array $diagnostics Diagnostics collected by reference.
	 * @return void
	 */
	private static function normalize_terminal_snapshot_metadata( array &$data, array &$missing, array &$diagnostics ): void {
		if (
			! isset( $data['snapshot_token'] )
			|| ! is_string( $data['snapshot_token'] )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', $data['snapshot_token'] )
		) {
			unset( $data['snapshot_token'] );
			$missing[] = 'snapshot_token';
		}
		if ( ! isset( $data['revision'] ) || ! self::is_pricing_revision( $data['revision'] ) ) {
			unset( $data['revision'] );
			$missing[] = 'revision';
		}
		if ( array_key_exists( 'snapshot_revision', $data ) ) {
			unset( $data['snapshot_revision'] );
			$diagnostics[] = self::pricing_diagnostic(
				'metadata_warning',
				'INFO',
				false,
				'A retired duplicate revision label was ignored; canonical revision remains authoritative.',
				false,
				'consume_event',
				array( 'fields' => array( 'snapshot_revision' ) )
			);
		}
		if ( ! array_key_exists( 'digest', $data ) ) {
			$diagnostics[] = self::pricing_diagnostic(
				'provider_capability_missing',
				'INFO',
				false,
				'No distinct negotiated payload digest was advertised; canonical revision remains authoritative.',
				false,
				'consume_event',
				array( 'capabilities' => array( 'payload_digest' ) )
			);
		} else {
			$capabilities = class_exists( 'Digitalogic_Pricing_Adapter_Registry' )
				? Digitalogic_Pricing_Adapter_Registry::instance()->capabilities()
				: array();
			$algorithms   = array_map( 'strval', (array) ( $capabilities['digest_algorithms'] ?? array() ) );
			$algorithm    = is_string( $data['digest'] ) && str_contains( $data['digest'], ':' )
				? strtolower( strstr( $data['digest'], ':', true ) )
				: '';
			$duplicate    = isset( $data['revision'] )
				&& is_string( $data['digest'] )
				&& hash_equals( $data['revision'], $data['digest'] );
			if ( ! self::is_pricing_revision( $data['digest'] ) || ! in_array( $algorithm, $algorithms, true ) || $duplicate ) {
				unset( $data['digest'] );
				$diagnostics[] = self::pricing_diagnostic(
					'metadata_warning',
					'INFO',
					false,
					$duplicate
						? 'A digest that merely repeated canonical revision was ignored.'
						: 'An unnegotiated optional digest was ignored; canonical revision remains authoritative.',
					false,
					'consume_event',
					array( 'fields' => array( 'digest' ) )
				);
			}
		}
		if ( ! isset( $data['row_count'] ) || ! is_int( $data['row_count'] ) || $data['row_count'] < 0 ) {
			unset( $data['row_count'] );
			$missing[] = 'row_count';
		}
		if ( ! isset( $data['snapshot_path'] ) || ! self::safe_pricing_relative_path( $data['snapshot_path'] ) ) {
			unset( $data['snapshot_path'] );
			$missing[] = 'snapshot_path';
		}
	}

	/**
	 * Choose the most conservative bounded recovery requested by diagnostics.
	 *
	 * @param array $diagnostics Structured event diagnostics.
	 * @return array
	 */
	private static function pricing_recovery_for_diagnostics( array $diagnostics ): array {
		$action = 'consume_event';
		foreach ( $diagnostics as $diagnostic ) {
			$next = (string) ( $diagnostic['recovery_action'] ?? '' );
			if ( 'controlled_polling' === $next ) {
				$action = $next;
				break;
			}
			if ( 'conditional_refresh' === $next ) {
				$action = $next;
			}
		}
		return self::pricing_recovery_plan( $action );
	}

	/**
	 * Build one safe diagnostic with an explicit recovery action.
	 *
	 * @param string $code            Stable diagnostic code.
	 * @param string $severity        INFO, WARNING, or ERROR.
	 * @param bool   $blocking        Whether consumption must stop.
	 * @param string $reason          Safe human-readable reason.
	 * @param bool   $retryable       Whether bounded recovery may retry.
	 * @param string $recovery_action Consumer recovery action.
	 * @param array  $details         Safe bounded field names.
	 * @return array
	 */
	private static function pricing_diagnostic( string $code, string $severity, bool $blocking, string $reason, bool $retryable, string $recovery_action, array $details = array() ): array {
		$diagnostic = array(
			'code'            => $code,
			'severity'        => $severity,
			'blocking'        => $blocking,
			'reason'          => $reason,
			'retryable'       => $retryable,
			'recovery_action' => $recovery_action,
		);
		if ( $details ) {
			$diagnostic['details'] = $details;
		}
		return $diagnostic;
	}

	/**
	 * Return a finite recovery contract for the remote pricing adapter.
	 *
	 * @param string $action Recovery action.
	 * @return array
	 */
	private static function pricing_recovery_plan( string $action ): array {
		if ( 'stop_and_reauthorize' === $action ) {
			return array(
				'action'          => $action,
				'retryable'       => false,
				'max_attempts'    => 0,
				'timeout_seconds' => 0,
			);
		}
		if ( 'conditional_refresh' === $action ) {
			return array(
				'action'                => $action,
				'retryable'             => true,
				'max_attempts'          => 3,
				'timeout_seconds'       => 30,
				'revision_path'         => self::PRICING_REVISION_PATH,
				'fallback_action'       => 'controlled_polling',
				'poll_interval_seconds' => 5,
			);
		}
		if ( 'controlled_polling' === $action ) {
			return array(
				'action'                => $action,
				'retryable'             => true,
				'max_attempts'          => 6,
				'timeout_seconds'       => 30,
				'revision_path'         => self::PRICING_REVISION_PATH,
				'poll_interval_seconds' => 5,
			);
		}
		return array(
			'action'          => 'consume_event',
			'retryable'       => false,
			'max_attempts'    => 0,
			'timeout_seconds' => 0,
		);
	}

	/** Return the selected provider adapter's exact event principal. */
	private static function pricing_service(): string {
		return (string) Digitalogic_Pricing_Adapter_Registry::instance()->provider()->event_principal();
	}

	/**
	 * Validate an exact source identity component.
	 *
	 * @param mixed $value Source ID or dataset.
	 * @return bool
	 */
	private static function valid_pricing_identity_component( $value ): bool {
		return is_string( $value )
			&& '' !== $value
			&& trim( $value ) === $value
			&& strlen( $value ) <= 191
			&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/**
	 * Check an optional SHA-256 revision representation.
	 *
	 * @param mixed $value Revision candidate.
	 * @return bool
	 */
	private static function is_pricing_revision( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $value );
	}

	/**
	 * Check a same-origin relative provider path.
	 *
	 * @param mixed $value Path candidate.
	 * @return bool
	 */
	private static function safe_pricing_relative_path( $value ): bool {
		return is_string( $value )
			&& str_starts_with( $value, '/' )
			&& ! str_starts_with( $value, '//' )
			&& ! str_contains( $value, '\\' )
			&& 0 === preg_match( '#(?:^|/)\.\.?(?:/|\?|\#|$)#', $value )
			&& strlen( $value ) <= 2048
			&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/**
	 * Detect credential-bearing extension keys.
	 *
	 * @param mixed $value Payload node.
	 * @param int   $depth Current bounded recursion depth.
	 * @return bool
	 */
	private static function pricing_payload_has_sensitive_key( $value, int $depth = 0 ): bool {
		if ( ! is_array( $value ) || $depth > 5 ) {
			return false;
		}
		foreach ( $value as $key => $nested ) {
			$key = strtolower( (string) $key );
			if ( 1 === preg_match( '/(?:^|_)(?:secret|password|credential|authorization|cookie|api_key|access_token|refresh_token|session_token)(?:_|$)/', $key ) ) {
				return true;
			}
			if ( self::pricing_payload_has_sensitive_key( $nested, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return normalized roles without exposing user records in event payloads.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	private static function user_roles( int $user_id ): array {
		$user = self::audience_user( $user_id );
		return is_object( $user ) && isset( $user->roles )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $user->roles ) ) ) )
			: array();
	}

	/**
	 * Match a bounded set of exact user attributes entirely on the server.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param array  $attributes Sanitized exact-match attributes.
	 * @param string $match_mode any or all.
	 * @return bool
	 */
	private static function user_matches_attributes( int $user_id, array $attributes, string $match_mode ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		$results = array();
		foreach ( $attributes as $key => $allowed ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || self::protected_audience_attribute( $key ) ) {
				continue;
			}
			$actual  = self::user_attribute_values( $user_id, $key );
			$allowed = array_map(
				static function ( $value ) {
					return self::text( $value, 191 );
				},
				(array) $allowed
			);
			$results[] = (bool) array_intersect( $allowed, $actual );
		}

		if ( ! $results ) {
			return false;
		}

		return 'any' === $match_mode
			? in_array( true, $results, true )
			: ! in_array( false, $results, true );
	}

	/**
	 * Resolve safe core attributes and user meta for exact audience matching.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $key     Sanitized attribute key.
	 * @return array
	 */
	private static function user_attribute_values( int $user_id, string $key ): array {
		$user = self::audience_user( $user_id );
		if ( is_object( $user ) && in_array( $key, array( 'user_login', 'display_name', 'locale' ), true ) ) {
			if ( 'locale' === $key && function_exists( 'get_user_locale' ) ) {
				$value = get_user_locale( $user_id );
			} else {
				$value = $user->{$key} ?? '';
			}
			return array( self::text( $value, 191 ) );
		}

		$value = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, $key, true ) : '';
		$value = is_array( $value ) ? $value : array( $value );
		return array_values(
			array_unique(
				array_map(
					static function ( $item ) {
						return ( is_scalar( $item ) || null === $item ) ? self::text( $item, 191 ) : '';
					},
					$value
				)
			)
		);
	}

	/**
	 * Resolve a user object for the current SSE/WS principal.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return object|null
	 */
	private static function audience_user( int $user_id ) {
		if ( $user_id < 1 ) {
			return null;
		}
		if ( get_current_user_id() === $user_id ) {
			return wp_get_current_user();
		}
		return function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
	}

	public static function sanitize_notification( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$title   = self::text( $payload['title'] ?? '', 120 );
		$message = self::text( $payload['message'] ?? $payload['body'] ?? '', 1000 );
		if ( '' === $title && '' === $message ) {
			return new WP_Error( 'digitalogic_notification_empty', __( 'A notification title or message is required.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$audience = self::sanitize_audience( $payload['audience'] ?? array() );
		if (
			empty( $audience['broadcast'] )
			&& empty( $audience['users'] )
			&& empty( $audience['devices'] )
			&& empty( $audience['operators'] )
			&& empty( $audience['roles'] )
			&& empty( $audience['attributes'] )
		) {
			return new WP_Error( 'digitalogic_notification_audience', __( 'A notification audience is required.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$correlation_id = self::identifier( $payload['correlation_id'] ?? '', 80 );
		if ( '' === $correlation_id ) {
			$correlation_id = wp_generate_uuid4();
		}

		$actions = array();
		foreach ( array_slice( (array) ( $payload['actions'] ?? array() ), 0, self::MAX_NOTIFICATION_ACTIONS ) as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$id    = self::identifier( $action['id'] ?? '', 64 );
			$label = self::text( $action['label'] ?? '', 48 );
			if ( '' === $id || '' === $label ) {
				continue;
			}
			$style     = sanitize_key( (string) ( $action['style'] ?? 'default' ) );
			$actions[] = array(
				'id'    => $id,
				'label' => $label,
				'style' => in_array( $style, array( 'default', 'primary', 'danger' ), true ) ? $style : 'default',
			);
		}

		$fields = array();
		foreach ( array_slice( (array) ( $payload['fields'] ?? array() ), 0, self::MAX_NOTIFICATION_FIELDS ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id    = self::identifier( $field['id'] ?? '', 64 );
			$label = self::text( $field['label'] ?? '', 80 );
			$type  = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( '' === $id || '' === $label || ! in_array( $type, array( 'text', 'select', 'boolean' ), true ) ) {
				continue;
			}
			$options = array();
			if ( 'select' === $type ) {
				foreach ( array_slice( (array) ( $field['options'] ?? array() ), 0, self::MAX_FIELD_OPTIONS ) as $option ) {
					if ( ! is_array( $option ) ) {
						continue;
					}
					$value        = self::identifier( $option['value'] ?? '', 80 );
					$option_label = self::text( $option['label'] ?? '', 80 );
					if ( '' !== $value && '' !== $option_label ) {
						$options[] = array(
							'value' => $value,
							'label' => $option_label,
						);
					}
				}
			}
			$fields[] = array(
				'id'       => $id,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => $options,
			);
		}

		$level       = sanitize_key( (string) ( $payload['level'] ?? 'info' ) );
		$display     = sanitize_key( (string) ( $payload['display'] ?? $payload['presentation'] ?? 'toast' ) );
		$duration_ms = max( 1000, min( 60000, absint( $payload['duration_ms'] ?? 7000 ) ) );
		$expires_at  = self::iso_time( $payload['expires_at'] ?? gmdate( 'c', time() + HOUR_IN_SECONDS ) );
		$link        = self::sanitize_notification_link( $payload['link'] ?? array() );

		return array(
			'notification_id' => self::identifier( $payload['notification_id'] ?? $correlation_id, 80 ),
			'correlation_id'  => $correlation_id,
			'title'           => $title,
			'message'         => $message,
			'level'           => in_array( $level, array( 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info',
			'audience'        => $audience,
			'actions'         => $actions,
			'fields'          => $fields,
			'display'         => in_array( $display, array( 'toast', 'banner', 'both' ), true ) ? $display : 'toast',
			'duration_ms'     => $duration_ms,
			'dismissible'     => ! array_key_exists( 'dismissible', $payload ) || ! empty( $payload['dismissible'] ),
			'link'            => $link,
			'expires_at'      => $expires_at,
			'source'          => self::identifier( $payload['source'] ?? 'digitalogic', 80 ),
			'created_at'      => gmdate( 'c' ),
		);
	}

	public static function resolve_presence( array $evidence, ?int $now = null ): array {
		$now           = $now ?? time();
		$session       = self::fresh_evidence( $evidence['windows_session'] ?? $evidence['windows_power'] ?? null, self::SESSION_FRESH_SECONDS, $now );
		$router        = self::freshest_evidence(
			array(
				$evidence['routeros_wifi'] ?? null,
				$evidence['routeros_dhcp'] ?? null,
				$evidence['routeros_arp'] ?? null,
			),
			self::PRESENCE_FRESH_SECONDS,
			$now
		);
		$session_state = (string) ( $session['state'] ?? $session['session_state'] ?? 'unknown' );
		$router_state  = (string) ( $router['state'] ?? 'unknown' );

		if ( in_array( $session_state, array( 'unlocked', 'active', 'present' ), true ) ) {
			return array(
				'state'       => 'present',
				'confidence'  => 'high',
				'reason'      => 'windows_session_active',
				'observed_at' => $session['observed_at'],
			);
		}
		if ( in_array( $router_state, array( 'bound', 'joined', 'online', 'present' ), true ) ) {
			return array(
				'state'       => 'present',
				'confidence'  => self::confidence( $router['confidence'] ?? 'medium' ),
				'reason'      => 'router_device_present',
				'observed_at' => $router['observed_at'],
			);
		}
		if (
			in_array( $session_state, array( 'locked', 'suspended', 'away' ), true )
			&& in_array( $router_state, array( 'unbound', 'left', 'offline', 'away' ), true )
		) {
			return array(
				'state'       => 'away',
				'confidence'  => 'medium',
				'reason'      => 'session_locked_and_device_away',
				'observed_at' => max( $session['observed_at'], $router['observed_at'] ),
			);
		}

		$observed_at = (string) ( $session['observed_at'] ?? $router['observed_at'] ?? '' );
		return array(
			'state'       => 'unknown',
			'confidence'  => 'low',
			'reason'      => 'insufficient_fresh_evidence',
			'observed_at' => $observed_at,
		);
	}

	private function update_subject_evidence( string $operator_key, string $device_id, string $source, array $evidence ): array {
		global $wpdb;
		$where = array();
		$args  = array();
		if ( '' !== $device_id ) {
			$where[] = 'device_id = %s';
			$args[]  = $device_id;
		}
		if ( '' !== $operator_key ) {
			$where[] = 'operator_key = %s';
			$args[]  = $operator_key;
		}
		$sql     = 'SELECT device_id FROM ' . self::workstations_table() . ' WHERE ' . implode( ' OR ', $where ) . ' LIMIT 100';
		$rows    = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		$rows    = is_array( $rows ) ? $rows : array();
		$updated = array();
		foreach ( $rows as $row ) {
			$current_device = self::sanitize_device_id( $row['device_id'] ?? '' );
			if ( '' === $current_device ) {
				continue;
			}
			$result = $this->update_device_evidence( $current_device, $source, $evidence );
			if ( ! is_wp_error( $result ) ) {
				$updated[] = array(
					'device_id' => $current_device,
					'presence'  => $result['presence'],
				);
			}
		}

		if ( empty( $updated ) && '' !== $operator_key ) {
			$unassigned                             = get_option( self::EVIDENCE_OPTION, array() );
			$unassigned                             = is_array( $unassigned ) ? $unassigned : array();
			$unassigned[ $operator_key ][ $source ] = $evidence;
			update_option( self::EVIDENCE_OPTION, $unassigned, false );
		}

		return $updated;
	}

	private function update_device_evidence( string $device_id, string $source, array $incoming, string $session_state = '' ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT operator_key, evidence, session_state FROM ' . self::workstations_table() . ' WHERE device_id = %s', $device_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'digitalogic_workstation_missing', __( 'The workstation is not registered.', 'digitalogic' ), array( 'status' => 404 ) );
		}
		$evidence            = json_decode( (string) ( $row['evidence'] ?? '' ), true );
		$evidence            = is_array( $evidence ) ? $evidence : array();
		$evidence[ $source ] = $incoming;
		$presence            = self::resolve_presence( $evidence );
		$next_session_state  = '' !== $session_state ? $session_state : self::session_state( $row['session_state'] ?? 'unknown' );
		$updated             = $wpdb->update(
			self::workstations_table(),
			array(
				'session_state'       => $next_session_state,
				'presence_state'      => $presence['state'],
				'presence_confidence' => $presence['confidence'],
				'evidence'            => wp_json_encode( $evidence ),
				'last_seen'           => current_time( 'mysql', true ),
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'device_id' => $device_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'digitalogic_presence_storage', __( 'Presence evidence could not be saved.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		return array(
			'operator_key' => sanitize_key( (string) ( $row['operator_key'] ?? '' ) ),
			'presence'     => $presence,
		);
	}

	private function device_belongs_to_user( string $device_id, int $user_id ): bool {
		global $wpdb;
		$stored = $wpdb->get_var(
			$wpdb->prepare( 'SELECT user_id FROM ' . self::workstations_table() . ' WHERE device_id = %s', $device_id )
		);

		return $user_id > 0 && $user_id === absint( $stored );
	}

	private static function notification_event( int $event_id, string $correlation_id, int $user_id, string $device_id ) {
		foreach ( Digitalogic_Panel::get_events_since( max( 0, $event_id - 1 ) ) as $event ) {
			if ( ! is_array( $event ) || $event_id !== absint( $event['id'] ?? 0 ) ) {
				continue;
			}
			$data = is_array( $event['data'] ?? null ) ? $event['data'] : array();
			if (
				'workstation.notification' !== (string) ( $event['name'] ?? '' )
				|| $correlation_id !== self::identifier( $data['correlation_id'] ?? '', 80 )
				|| ! self::event_visible_to( $event, $user_id, $device_id )
			) {
				break;
			}
			return $event;
		}

		return new WP_Error(
			'digitalogic_response_event_unavailable',
			__( 'The notification is unavailable, expired, or not addressed to this workstation.', 'digitalogic' ),
			array( 'status' => 410 )
		);
	}

	private static function operator_for_device( string $device_id ): string {
		if ( isset( self::$device_operator_cache[ $device_id ] ) ) {
			return self::$device_operator_cache[ $device_id ];
		}
		global $wpdb;
		$operator_key                              = sanitize_key(
			(string) $wpdb->get_var(
				$wpdb->prepare( 'SELECT operator_key FROM ' . self::workstations_table() . ' WHERE device_id = %s', $device_id )
			)
		);
		self::$device_operator_cache[ $device_id ] = $operator_key;
		return $operator_key;
	}

	private static function operator_for_user( int $user_id ): string {
		$operators = get_option( self::OPERATOR_USERS_OPTION, array() );
		return is_array( $operators ) ? sanitize_key( (string) ( $operators[ $user_id ] ?? '' ) ) : '';
	}

	private function unassigned_evidence( string $operator_key ): array {
		if ( '' === $operator_key ) {
			return array();
		}
		$unassigned = get_option( self::EVIDENCE_OPTION, array() );
		return is_array( $unassigned ) && isset( $unassigned[ $operator_key ] ) && is_array( $unassigned[ $operator_key ] )
			? $unassigned[ $operator_key ]
			: array();
	}

	private function woocommerce_customer_candidates( string $phone ): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}
		$forms      = self::phone_forms( $phone );
		$meta_query = array( 'relation' => 'OR' );
		foreach ( array( 'billing_phone', 'digits_phone', 'digits_phone_no' ) as $key ) {
			foreach ( $forms as $value ) {
				$meta_query[] = array(
					'key'   => $key,
					'value' => $value,
				);
			}
		}
		$users = get_users(
			array(
				'number'     => 20,
				'fields'     => 'all',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded exact phone lookup.
				'meta_query' => $meta_query,
			)
		);
		$out   = array();
		foreach ( (array) $users as $user ) {
			if ( ! is_object( $user ) || empty( $user->ID ) ) {
				continue;
			}
			$out[] = array(
				'source'       => 'woocommerce_user',
				'customer_id'  => absint( $user->ID ),
				'display_name' => self::text( $user->display_name ?? '', 191 ),
				'company'      => self::text( get_user_meta( $user->ID, 'billing_company', true ), 191 ),
			);
		}
		return array_slice( $out, 0, 20 );
	}

	private function woocommerce_order_history( string $phone ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = array();
		foreach ( self::phone_forms( $phone ) as $form ) {
			foreach ( (array) wc_get_orders(
				array(
					'limit'         => 10,
					'orderby'       => 'date',
					'order'         => 'DESC',
					'billing_phone' => $form,
				)
			) as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
					continue;
				}
				$orders[ $order->get_id() ] = $order;
			}
		}
		uasort(
			$orders,
			static function ( $left, $right ) {
				$left_date  = method_exists( $left, 'get_date_created' ) && $left->get_date_created() ? $left->get_date_created()->getTimestamp() : 0;
				$right_date = method_exists( $right, 'get_date_created' ) && $right->get_date_created() ? $right->get_date_created()->getTimestamp() : 0;
				return $right_date <=> $left_date;
			}
		);
		$out = array();
		foreach ( array_slice( $orders, 0, 10, true ) as $order ) {
			$date  = $order->get_date_created();
			$out[] = array(
				'order_id'      => absint( $order->get_id() ),
				'status'        => sanitize_key( (string) $order->get_status() ),
				'date'          => $date ? gmdate( 'c', $date->getTimestamp() ) : '',
				'total'         => (string) $order->get_total(),
				'currency'      => sanitize_key( (string) $order->get_currency() ),
				'customer_name' => self::text( trim( $order->get_formatted_billing_full_name() ), 191 ),
				'company'       => self::text( $order->get_billing_company(), 191 ),
			);
		}
		return $out;
	}

	private static function phone_forms( string $phone ): array {
		$national    = Digitalogic_PBX_Phone::to_national( $phone );
		$significant = '' !== $national ? substr( $national, 1 ) : '';
		return array_values( array_unique( array_filter( array( $phone, $national, $significant, ltrim( $phone, '+' ) ) ) ) );
	}

	private static function subject_hash( string $subject ): string {
		$subject = strtolower( preg_replace( '/[^a-f0-9]+/', '', $subject ) );
		return strlen( $subject ) >= 8 ? hash( 'sha256', $subject ) : '';
	}

	private static function sanitize_audience( $audience ): array {
		$audience   = is_array( $audience ) ? $audience : array();
		$roles      = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', array_slice( (array) ( $audience['roles'] ?? array() ), 0, 20 ) )
				)
			)
		);
		$attributes = array();
		foreach ( array_slice( (array) ( $audience['attributes'] ?? array() ), 0, self::MAX_AUDIENCE_ATTRIBUTES, true ) as $key => $values ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || self::protected_audience_attribute( $key ) ) {
				continue;
			}
			$clean_values = array();
			foreach ( array_slice( (array) $values, 0, self::MAX_ATTRIBUTE_VALUES ) as $value ) {
				if ( is_scalar( $value ) || null === $value ) {
					$clean_values[] = self::text( $value, 191 );
				}
			}
			$clean_values = array_values( array_unique( $clean_values ) );
			if ( $clean_values ) {
				$attributes[ $key ] = $clean_values;
			}
		}
		$match           = sanitize_key( (string) ( $audience['match'] ?? 'any' ) );
		$attribute_match = sanitize_key( (string) ( $audience['attribute_match'] ?? 'all' ) );
		return array(
			'broadcast'       => ! empty( $audience['broadcast'] ),
			'match'           => 'all' === $match ? 'all' : 'any',
			'users'           => array_values( array_unique( array_filter( array_map( 'absint', array_slice( (array) ( $audience['users'] ?? array() ), 0, 50 ) ) ) ) ),
			'devices'         => array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_device_id' ), array_slice( (array) ( $audience['devices'] ?? array() ), 0, 50 ) ) ) ) ),
			'operators'       => array_values( array_unique( array_filter( array_map( 'sanitize_key', array_slice( (array) ( $audience['operators'] ?? array() ), 0, 50 ) ) ) ) ),
			'roles'           => $roles,
			'attributes'      => $attributes,
			'attribute_match' => 'any' === $attribute_match ? 'any' : 'all',
		);
	}

	/**
	 * Reject secret-bearing and WordPress authorization metadata as audience selectors.
	 *
	 * @param string $key Sanitized attribute key.
	 * @return bool
	 */
	private static function protected_audience_attribute( string $key ): bool {
		return 1 === preg_match( '/(?:pass|password|secret|token|session|capabilit|user_level|activation_key|api_key)/i', $key );
	}

	/**
	 * Keep notification links same-origin and text-only.
	 *
	 * @param mixed $link Notification link input.
	 * @return array
	 */
	private static function sanitize_notification_link( $link ): array {
		$link  = is_array( $link ) ? $link : array();
		$href  = trim( (string) ( $link['href'] ?? $link['url'] ?? '' ) );
		$label = self::text( $link['label'] ?? '', 80 );
		if ( '' === $href || '' === $label ) {
			return array();
		}

		$home_parts = wp_parse_url( home_url( '/' ) );
		$link_parts = wp_parse_url( $href );
		if ( false === $link_parts ) {
			return array();
		}
		if ( str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' ) ) {
			return array(
				'href'  => esc_url_raw( $href ),
				'label' => $label,
			);
		}
		if (
			is_array( $home_parts )
			&& isset( $home_parts['host'], $link_parts['host'] )
			&& strtolower( (string) $home_parts['host'] ) === strtolower( (string) $link_parts['host'] )
		) {
			return array(
				'href'  => esc_url_raw( $href ),
				'label' => $label,
			);
		}

		return array();
	}

	private static function sanitize_capabilities( $capabilities ): array {
		$out = array();
		foreach ( array_slice( (array) $capabilities, 0, 30 ) as $capability ) {
			$value = sanitize_key( (string) $capability );
			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function sanitize_response_values( $values ): array {
		$out = array();
		foreach ( array_slice( is_array( $values ) ? $values : array(), 0, 20, true ) as $key => $value ) {
			$key = self::identifier( $key, 64 );
			if ( '' === $key || ( ! is_scalar( $value ) && null !== $value ) ) {
				continue;
			}
			$out[ $key ] = is_bool( $value ) ? $value : self::text( $value, 1000 );
		}
		return $out;
	}

	private static function sanitize_notification_response_values( $values, array $fields ): array {
		$input = is_array( $values ) ? $values : array();
		$out   = array();
		foreach ( array_slice( $fields, 0, self::MAX_NOTIFICATION_FIELDS ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$id   = self::identifier( $field['id'] ?? '', 64 );
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( '' === $id || ! array_key_exists( $id, $input ) ) {
				continue;
			}
			if ( 'boolean' === $type ) {
				$out[ $id ] = filter_var( $input[ $id ], FILTER_VALIDATE_BOOLEAN );
				continue;
			}
			$value = self::text( $input[ $id ], 1000 );
			if ( 'select' === $type ) {
				$allowed = array_values(
					array_filter(
						array_map(
							static function ( $option ) {
								return is_array( $option ) ? self::identifier( $option['value'] ?? '', 80 ) : '';
							},
							(array) ( $field['options'] ?? array() )
						)
					)
				);
				if ( ! in_array( $value, $allowed, true ) ) {
					continue;
				}
			}
			$out[ $id ] = $value;
		}
		return $out;
	}

	private static function fresh_evidence( $evidence, int $ttl, int $now ): array {
		if ( ! is_array( $evidence ) ) {
			return array();
		}
		$observed = strtotime( (string) ( $evidence['observed_at'] ?? '' ) );
		if ( false === $observed || $observed > $now + 60 || ( $now - $observed ) > $ttl ) {
			return array();
		}
		return $evidence;
	}

	private static function freshest_evidence( array $evidence, int $ttl, int $now ): array {
		$fresh = array();
		foreach ( $evidence as $candidate ) {
			$candidate = self::fresh_evidence( $candidate, $ttl, $now );
			if ( ! empty( $candidate ) ) {
				$fresh[] = $candidate;
			}
		}
		usort(
			$fresh,
			static function ( $left, $right ) {
				return strtotime( (string) $right['observed_at'] ) <=> strtotime( (string) $left['observed_at'] );
			}
		);
		return $fresh[0] ?? array();
	}

	private static function workstations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_workstations';
	}

	private static function responses_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_event_responses';
	}

	private static function sanitize_device_id( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._:-]{7,63}$/', $value ) ? $value : '';
	}

	private static function identifier( $value, int $max ): string {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9._:-]+/', '-', $value );
		return substr( trim( (string) $value, '-.' ), 0, $max );
	}

	private static function text( $value, int $max ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	private static function confidence( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'low', 'medium', 'high' ), true ) ? $value : 'low';
	}

	private static function session_state( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'active', 'unlocked', 'locked', 'suspended', 'resumed', 'away', 'unknown' ), true ) ? $value : 'unknown';
	}

	private static function presence_evidence_state( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'active', 'unlocked', 'locked', 'suspended', 'resumed', 'present', 'away', 'bound', 'unbound', 'joined', 'left', 'online', 'offline', 'unknown' ), true )
			? $value
			: 'unknown';
	}

	private static function iso_time( $value ): string {
		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp || $timestamp > time() + 300 ) {
			$timestamp = time();
		}
		return gmdate( 'c', $timestamp );
	}
}
