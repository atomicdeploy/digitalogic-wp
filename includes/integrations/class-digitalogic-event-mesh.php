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
	private const PRESENCE_FRESH_SECONDS   = 600;
	private const SESSION_FRESH_SECONDS    = 180;

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
			$event_source = isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : array();
			$is_revision  = static function ( $value ) {
				return is_string( $value ) && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $value );
			};
			if (
				'patris_pricing' !== $service
				|| Digitalogic_Pricing_Snapshot::SCHEMA_VERSION !== (int) ( $data['schema_version'] ?? 0 )
				|| Digitalogic_Pricing_Snapshot::PROJECTION !== (string) ( $data['projection'] ?? '' )
				|| ! $is_revision( $event_source['revision'] ?? null )
				|| ! $is_revision( $data['idempotency_key'] ?? null )
				|| ! in_array( $service, (array) ( $audience['services'] ?? array() ), true )
			) {
				return false;
			}
			if ( 'pricing.state.changed' === $name ) {
				$state_revision = (string) ( $data['state_revision'] ?? '' );
				if (
					Digitalogic_Pricing_Snapshot::STATE_EVENT_SCHEMA !== (string) ( $data['schema'] ?? '' )
					|| '/wp-json/digitalogic/pricing/sync/revision' !== (string) ( $data['revision_path'] ?? '' )
					|| ! $is_revision( $state_revision )
					|| '"' . $state_revision . '"' !== (string) ( $data['etag'] ?? '' )
					|| ! $is_revision( $data['catalog_revision'] ?? null )
					|| ! $is_revision( $data['pricing_state_revision'] ?? null )
					|| ! $is_revision( $data['pricing_policy_revision'] ?? null )
					|| ! in_array( (string) ( $data['cause'] ?? '' ), array( 'projection-invalidated', 'freshness-boundary' ), true )
				) {
					return false;
				}
			} elseif ( in_array( $name, array( 'pricing.source.changed', 'pricing.source.removed' ), true ) ) {
				$change   = (string) ( $data['change'] ?? '' );
				$previous = $data['previous_source_revision'] ?? null;
				if (
					Digitalogic_Pricing_Snapshot::SOURCE_EVENT_SCHEMA !== (string) ( $data['schema'] ?? '' )
					|| '/wp-json/digitalogic/pricing/sync/revision' !== (string) ( $data['revision_path'] ?? '' )
					|| ! in_array( $change, array( 'added', 'changed', 'removed' ), true )
					|| ( 'pricing.source.removed' === $name ) !== ( 'removed' === $change )
					|| ( null !== $previous && ! $is_revision( $previous ) )
					|| empty( $data['revision_validation_required'] )
				) {
					return false;
				}
			} elseif ( 'pricing.snapshot.build.terminal' === $name ) {
				$allowed_keys  = array(
					'schema',
					'schema_version',
					'projection',
					'build_id',
					'request_id',
					'status',
					'source',
					'state_revision',
					'pricing_state_revision',
					'catalog_revision',
					'snapshot_token',
					'snapshot_revision',
					'digest',
					'snapshot_path',
					'code',
					'retryable',
					'idempotency_key',
					'audience',
				);
				$status        = (string) ( $data['status'] ?? '' );
				$expected_path = '/wp-json/digitalogic/pricing/sync/snapshots/'
					. rawurlencode( (string) ( $data['snapshot_token'] ?? '' ) )
					. '?source_id=' . rawurlencode( (string) ( $event_source['id'] ?? '' ) )
					. '&source_dataset=' . rawurlencode( (string) ( $event_source['dataset'] ?? '' ) )
					. '&source_revision=' . rawurlencode( (string) ( $event_source['revision'] ?? '' ) );
				if (
					Digitalogic_Pricing_Snapshot::TERMINAL_EVENT_SCHEMA !== (string) ( $data['schema'] ?? '' )
					|| array_diff( array_keys( $data ), $allowed_keys )
					|| 3 !== count( $event_source )
					|| array_diff( array( 'id', 'dataset', 'revision' ), array_keys( $event_source ) )
					|| array_diff( array_keys( $event_source ), array( 'id', 'dataset', 'revision' ) )
					|| array( 'services' => array( 'patris_pricing' ) ) !== $audience
					|| 1 !== preg_match( '/\Abuild_[a-f0-9]{32}\z/D', (string) ( $data['build_id'] ?? '' ) )
					|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', (string) ( $data['request_id'] ?? '' ) )
					|| ! in_array( $status, array( 'ready', 'failed', 'cancelled' ), true )
					|| ! $is_revision( $data['state_revision'] ?? null )
					|| ! $is_revision( $data['pricing_state_revision'] ?? null )
					|| ! $is_revision( $data['catalog_revision'] ?? null )
					|| ! is_bool( $data['retryable'] ?? null )
				) {
					return false;
				}
				if ( 'ready' === $status ) {
					if (
						1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', (string) ( $data['snapshot_token'] ?? '' ) )
						|| ! $is_revision( $data['snapshot_revision'] ?? null )
						|| ! $is_revision( $data['digest'] ?? null )
						|| ! hash_equals( (string) $data['snapshot_revision'], (string) $data['digest'] )
						|| ! hash_equals( $expected_path, (string) ( $data['snapshot_path'] ?? '' ) )
						|| '' !== (string) ( $data['code'] ?? '' )
						|| ! empty( $data['retryable'] )
					) {
						return false;
					}
				} elseif (
					'' === (string) ( $data['code'] ?? '' )
					|| strlen( (string) $data['code'] ) > 128
					|| 1 !== preg_match( '/\A[a-z0-9_:-]+\z/D', (string) $data['code'] )
					|| isset( $data['snapshot_token'] )
					|| isset( $data['snapshot_revision'] )
					|| isset( $data['digest'] )
					|| isset( $data['snapshot_path'] )
				) {
					return false;
				}
			} else {
				return false;
			}

			return '' !== (string) ( $service_source['id'] ?? '' )
				&& '' !== (string) ( $service_source['dataset'] ?? '' )
				&& hash_equals( (string) $service_source['id'], (string) ( $event_source['id'] ?? '' ) )
				&& hash_equals( (string) $service_source['dataset'], (string) ( $event_source['dataset'] ?? '' ) );
		}
		if ( ! $audience ) {
			return true;
		}

		if ( ! empty( $audience['broadcast'] ) ) {
			return true;
		}
		if ( $user_id > 0 && in_array( $user_id, array_map( 'absint', (array) ( $audience['users'] ?? array() ) ), true ) ) {
			return true;
		}
		$device_id = self::sanitize_device_id( $device_id );
		if ( '' !== $device_id && in_array( $device_id, (array) ( $audience['devices'] ?? array() ), true ) ) {
			return true;
		}
		$operator_key = '' !== $device_id ? self::operator_for_device( $device_id ) : '';
		return '' !== $operator_key && in_array( $operator_key, (array) ( $audience['operators'] ?? array() ), true );
	}

	public static function sanitize_notification( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$title   = self::text( $payload['title'] ?? '', 120 );
		$message = self::text( $payload['message'] ?? $payload['body'] ?? '', 1000 );
		if ( '' === $title && '' === $message ) {
			return new WP_Error( 'digitalogic_notification_empty', __( 'A notification title or message is required.', 'digitalogic' ), array( 'status' => 400 ) );
		}

		$audience = self::sanitize_audience( $payload['audience'] ?? array() );
		if ( empty( $audience['broadcast'] ) && empty( $audience['users'] ) && empty( $audience['devices'] ) && empty( $audience['operators'] ) ) {
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

		$level      = sanitize_key( (string) ( $payload['level'] ?? 'info' ) );
		$expires_at = self::iso_time( $payload['expires_at'] ?? gmdate( 'c', time() + HOUR_IN_SECONDS ) );

		return array(
			'notification_id' => self::identifier( $payload['notification_id'] ?? $correlation_id, 80 ),
			'correlation_id'  => $correlation_id,
			'title'           => $title,
			'message'         => $message,
			'level'           => in_array( $level, array( 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info',
			'audience'        => $audience,
			'actions'         => $actions,
			'fields'          => $fields,
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
		$audience = is_array( $audience ) ? $audience : array();
		return array(
			'broadcast' => ! empty( $audience['broadcast'] ),
			'users'     => array_values( array_unique( array_filter( array_map( 'absint', array_slice( (array) ( $audience['users'] ?? array() ), 0, 50 ) ) ) ) ),
			'devices'   => array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_device_id' ), array_slice( (array) ( $audience['devices'] ?? array() ), 0, 50 ) ) ) ) ),
			'operators' => array_values( array_unique( array_filter( array_map( 'sanitize_key', array_slice( (array) ( $audience['operators'] ?? array() ), 0, 50 ) ) ) ) ),
		);
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
