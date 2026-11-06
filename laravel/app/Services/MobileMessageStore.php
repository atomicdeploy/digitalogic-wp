<?php
/**
 * Durable native operation metadata for the existing Fluent Support provider.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency and locking metadata require uncached SQL reads.

/** Keeps operation identity, never a parallel ticket or conversation store. */
final class MobileMessageStore {
	/** Return the bounded operation metadata table. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_message_operations';
	}

	/**
	 * Install operation metadata without changing Fluent Support tables.
	 *
	 * @throws \RuntimeException If the metadata table is unavailable.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			operation_key char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			request_id char(36) NOT NULL,
			payload_hash char(64) NOT NULL,
			kind varchar(16) NOT NULL,
			ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			message_id bigint(20) unsigned NOT NULL DEFAULT 0,
			state varchar(16) NOT NULL,
			error_code varchar(64) NOT NULL DEFAULT '',
			created_at bigint(20) unsigned NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (operation_key),
			KEY user_id (user_id)
		) ENGINE=InnoDB {$charset};"
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			throw new \RuntimeException( 'Message operation storage unavailable.' );
		}
		update_option( 'digitalogic_mobile_messages_schema', '1', false );
	}

	/**
	 * Validate and scope a client operation UUID to its verified WordPress owner.
	 *
	 * @param int    $user_id Verified owner.
	 * @param string $id Client UUID.
	 * @return string|null Key, or null for invalid input.
	 */
	public static function key( int $user_id, string $id ): ?string {
		return preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $id )
			? hash( 'sha256', $user_id . ':' . strtolower( $id ) ) : null;
	}

	/**
	 * Read exact current operation metadata.
	 *
	 * @param string $key Owner-scoped operation key.
	 * @return array|null Stored operation.
	 */
	public static function read( string $key ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s', self::table(), $key ), ARRAY_A );
	}

	/**
	 * Reserve an immutable operation before entering provider business code.
	 *
	 * @param array $row Validated metadata under the operation lock.
	 * @return bool Whether the reservation was persisted.
	 */
	public static function insert( array $row ): bool {
		global $wpdb;
		return 1 === $wpdb->insert( self::table(), $row );
	}

	/**
	 * Persist an operation transition and fail closed on storage failure.
	 *
	 * @param string $key Operation key.
	 * @param array  $data New metadata.
	 * @return bool Whether storage accepted the transition.
	 */
	public static function update( string $key, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = time();
		return false !== $wpdb->update( self::table(), $data, array( 'operation_key' => $key ) );
	}

	/**
	 * Serialize duplicate submissions and recovery for one operation.
	 *
	 * @param string $key Operation key.
	 * @return bool Lock acquisition result.
	 */
	public static function lock( string $key ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', 'dgl-message-' . substr( $key, 0, 48 ) ) );
	}

	/**
	 * Release the exact operation lock.
	 *
	 * @param string $key Operation key.
	 */
	public static function unlock( string $key ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'dgl-message-' . substr( $key, 0, 48 ) ) );
	}

	/**
	 * Use Fluent's own message identifier for exact crash recovery, never content matching.
	 *
	 * @param string $key Operation key.
	 * @return string Canonical provider message identifier.
	 */
	public static function providerId( string $key ): string {
		return 'digitalogic-native-' . $key . '@digitalogic.ir';
	}
}
