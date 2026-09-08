<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores only mutation audit/idempotency records and reviewed source mapping
 * overrides. It never stores a catalog snapshot or customer payload.
 */
final class Store {

	private const SCHEMA_VERSION = '1';
	private const SCHEMA_OPTION  = 'digitalogic_viewer_bridge_schema_version';
	private const CLEANUP_HOOK   = 'digitalogic_viewer_bridge_cleanup';

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$audit   = self::audit_table();
		$mapping = self::mapping_table();

		dbDelta(
			"CREATE TABLE {$audit} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                request_key varchar(64) NOT NULL,
                actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action_name varchar(80) NOT NULL,
                entity_type varchar(24) NOT NULL,
                entity_id varchar(128) NOT NULL,
                before_revision varchar(72) NOT NULL DEFAULT '',
                after_revision varchar(72) NOT NULL DEFAULT '',
                payload_json longtext NULL,
                result_json longtext NULL,
                status varchar(20) NOT NULL DEFAULT 'processing',
                created_at datetime NOT NULL,
                completed_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_key (request_key),
                KEY entity_lookup (entity_type, entity_id),
                KEY created_at (created_at)
            ) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$mapping} (
                patris_code varchar(80) NOT NULL,
                woo_term_id bigint(20) unsigned NOT NULL,
                revision varchar(72) NOT NULL,
                updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (patris_code),
                KEY woo_term_id (woo_term_id)
            ) {$collate};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		self::add_capabilities();
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function register_maintenance(): void {
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
	}

	public static function cleanup(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::audit_table() . ' WHERE created_at < %s',
				$cutoff
			)
		);
	}

	/**
	 * @return array{state:string,row?:array,response?:array}|WP_Error
	 */
	public static function begin_action(
		string $request_key,
		string $action,
		string $entity_type,
		string $entity_id,
		string $before_revision,
		array $safe_payload
	) {
		global $wpdb;
		$request_key = strtolower( trim( $request_key ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._:-]{15,63}$/', $request_key ) ) {
			return new WP_Error(
				'digitalogic_viewer_idempotency_key_invalid',
				__( 'A 16–64 character Idempotency-Key is required.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 400 )
			);
		}

		$payload_json = wp_json_encode(
			self::bounded_json_value( $safe_payload ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$payload_json = is_string( $payload_json ) ? $payload_json : '{}';
		$operation    = array(
			'action_name'     => substr( sanitize_key( $action ), 0, 80 ),
			'entity_type'     => substr( sanitize_key( $entity_type ), 0, 24 ),
			'entity_id'       => substr( sanitize_text_field( $entity_id ), 0, 128 ),
			'before_revision' => substr( $before_revision, 0, 72 ),
			'payload_json'    => $payload_json,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		$existing     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::audit_table() . ' WHERE request_key = %s',
				$request_key
			),
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			foreach ( $operation as $field => $expected ) {
				if ( ! hash_equals( (string) $existing[ $field ], (string) $expected ) ) {
					return new WP_Error(
						'digitalogic_viewer_idempotency_key_reused',
						__(
							'This Idempotency-Key belongs to a different operation.',
							'digitalogic-viewer-bridge'
						),
						array( 'status' => 409 )
					);
				}
			}
			if ( $existing['status'] === 'completed' ) {
				$response = json_decode( (string) $existing['result_json'], true );
				return array(
					'state'    => 'replay',
					'row'      => $existing,
					'response' => is_array( $response ) ? $response : array(),
				);
			}
			if ( $existing['status'] === 'failed' ) {
				$response = json_decode( (string) $existing['result_json'], true );
				$failure  = is_array( $response['error'] ?? null )
					? $response['error']
					: array();
				return new WP_Error(
					sanitize_key(
						(string) (
							$failure['code']
							?? 'digitalogic_viewer_previous_action_failed'
						)
					),
					sanitize_text_field(
						(string) ( $failure['message'] ?? 'The previous action failed.' )
					),
					array(
						'status'           => max( 400, min( 599, (int) ( $failure['status'] ?? 409 ) ) ),
						'idempotentReplay' => true,
					)
				);
			}
			return new WP_Error(
				'digitalogic_viewer_action_in_progress',
				__( 'An action with this Idempotency-Key is already in progress.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 409 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		$inserted = $wpdb->insert(
			self::audit_table(),
			array(
				'request_key'     => $request_key,
				'actor_id'        => get_current_user_id(),
				'action_name'     => $operation['action_name'],
				'entity_type'     => $operation['entity_type'],
				'entity_id'       => $operation['entity_id'],
				'before_revision' => $operation['before_revision'],
				'payload_json'    => $operation['payload_json'],
				'status'          => 'processing',
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $inserted !== 1 ) {
			return new WP_Error(
				'digitalogic_viewer_audit_write_failed',
				__( 'The guarded action could not acquire its audit record.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
		}

		return array(
			'state' => 'new',
			'row'   => array(
				'id'          => (int) $wpdb->insert_id,
				'request_key' => $request_key,
			),
		);
	}

	public static function complete_action(
		int $audit_id,
		string $after_revision,
		array $safe_response
	): bool {
		global $wpdb;
		$encoded = wp_json_encode(
			self::bounded_json_value( $safe_response ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		return false !== $wpdb->update(
			self::audit_table(),
			array(
				'after_revision' => substr( $after_revision, 0, 72 ),
				'result_json'    => is_string( $encoded ) ? $encoded : '{}',
				'status'         => 'completed',
				'completed_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $audit_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function fail_action( int $audit_id, WP_Error $error ): void {
		global $wpdb;
		$error_data   = $error->get_error_data();
		$error_status = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 500 ) : 500;
		$safe         = array(
			'error' => array(
				'code'    => sanitize_key( $error->get_error_code() ),
				'message' => sanitize_text_field( $error->get_error_message() ),
				'status'  => max( 400, min( 599, $error_status ) ),
			),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		$wpdb->update(
			self::audit_table(),
			array(
				'result_json'  => wp_json_encode( $safe ),
				'status'       => 'failed',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $audit_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<string,array{termId:int,revision:string,updatedAt:string}>
	 */
	public static function mappings(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		$rows = $wpdb->get_results(
			'SELECT patris_code, woo_term_id, revision, updated_at FROM ' . self::mapping_table(),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$code = (string) ( $row['patris_code'] ?? '' );
			if ( $code === '' ) {
				continue;
			}
			$out[ $code ] = array(
				'termId'    => absint( $row['woo_term_id'] ?? 0 ),
				'revision'  => (string) ( $row['revision'] ?? '' ),
				'updatedAt' => mysql_to_rfc3339( (string) ( $row['updated_at'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * @return string|WP_Error New mapping revision.
	 */
	public static function put_mapping( string $patris_code, int $term_id ) {
		global $wpdb;
		$patris_code = self::patris_code( $patris_code );
		if ( $patris_code === '' || $term_id < 1 ) {
			return new WP_Error( 'digitalogic_viewer_mapping_invalid', 'Invalid mapping.', array( 'status' => 400 ) );
		}
		$revision = Revision::hash(
			array(
				'patrisCode' => $patris_code,
				'termId'     => $term_id,
				'at'         => gmdate( 'c' ),
				'nonce'      => wp_generate_uuid4(),
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		$result   = $wpdb->replace(
			self::mapping_table(),
			array(
				'patris_code' => $patris_code,
				'woo_term_id' => $term_id,
				'revision'    => $revision,
				'updated_by'  => get_current_user_id(),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s' )
		);
		return $result === false
			? new WP_Error( 'digitalogic_viewer_mapping_write_failed', 'Mapping write failed.', array( 'status' => 503 ) )
			: $revision;
	}

	public static function delete_mapping( string $patris_code ): bool {
		global $wpdb;
		$patris_code = self::patris_code( $patris_code );
		if ( $patris_code === '' ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dedicated audit/mapping table uses direct persistence and current database reads; no WordPress object API owns these rows.
		return false !== $wpdb->delete(
			self::mapping_table(),
			array( 'patris_code' => $patris_code ),
			array( '%s' )
		);
	}

	public static function patris_code( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^[A-Za-z0-9._:-]{1,80}$/', $value ) ? $value : '';
	}

	private static function add_capabilities(): void {
		add_role(
			'digitalogic_viewer_service',
			__( 'Digitalogic Viewer Service', 'digitalogic-viewer-bridge' ),
			array(
				'read'                      => true,
				'digitalogic_viewer_read'   => true,
				'digitalogic_viewer_manage' => true,
			)
		);
		$service_role = get_role( 'digitalogic_viewer_service' );
		if ( $service_role ) {
			$service_role->add_cap( 'read' );
			$service_role->add_cap( 'digitalogic_viewer_read' );
			$service_role->add_cap( 'digitalogic_viewer_manage' );
		}
	}

	private static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_viewer_audit';
	}

	private static function mapping_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_viewer_category_map';
	}

	/**
	 * Recursively bound audit JSON to scalar/array data and 16 KB.
	 *
	 * @return mixed
	 */
	private static function bounded_json_value( $value, int $depth = 0 ) {
		if ( $depth > 5 ) {
			return null;
		}
		if ( is_scalar( $value ) || $value === null ) {
			return is_string( $value ) ? mb_substr( $value, 0, 1000 ) : $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$result = array();
		foreach ( array_slice( $value, 0, 100, true ) as $key => $item ) {
			$result[ is_int( $key ) ? $key : sanitize_key( (string) $key ) ] =
				self::bounded_json_value( $item, $depth + 1 );
		}
		$encoded = wp_json_encode( $result );
		return is_string( $encoded ) && strlen( $encoded ) <= 16384 ? $result : array();
	}
}
