<?php
/**
 * Native RequestStore adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

/** Native adapter over canonical WordPress and WooCommerce authority. */
final class MobileRequestStore {
	/**
	 * Resolve an adapter-owned table from the closed table-name allowlist.
	 *
	 * @param string $name Name.
	 * @return string Native adapter result.
	 * @throws \InvalidArgumentException When the authoritative operation cannot be completed safely.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'uploads', 'requests', 'drafts' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown mobile table.' );
		}return $wpdb->prefix . 'digitalogic_mobile_' . $name;}
	/**
	 * Create or verify the supporting metadata schema without replacing canonical business records.
	 *
	 * @return void Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$uploads  = self::table( 'uploads' );
		$requests = self::table( 'requests' );
		$drafts   = self::table( 'drafts' );
		dbDelta(
			"CREATE TABLE {$uploads} (
			id char(32) NOT NULL,
			request_hash char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			form_type varchar(16) NOT NULL,
			filename varchar(255) NOT NULL,
			size bigint(20) unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			path text NOT NULL,
			state varchar(16) NOT NULL DEFAULT 'uploading',
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_hash (request_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$requests} (
			request_hash char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			payload_hash char(64) NOT NULL,
			result longtext NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (request_hash),
			KEY user_id (user_id)
		) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$drafts} (
			id char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			data longtext NOT NULL,
			revision char(64) NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id,user_id),
			KEY user_id (user_id)
		) ENGINE=InnoDB {$charset};"
		);
		foreach ( array( $uploads, $requests, $drafts ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new \RuntimeException( 'Mobile requests schema unavailable.' );}
		}
		update_option( 'digitalogic_mobile_requests_schema', '1', false );
	}
	/**
	 * Bind a client operation identifier to the authenticated owner.
	 *
	 * @param int    $user_id Verified WordPress owner.
	 * @param string $id Exact scoped identifier.
	 * @return string Native adapter result.
	 */
	public static function requestKey( int $user_id, string $id ): string {
		return MobileIdentityStore::hash( 'request:' . $user_id . ':' . $id );}
	/**
	 * Validate the bounded immutable client operation identifier.
	 *
	 * @param mixed $id Exact scoped identifier.
	 * @return bool Native adapter result.
	 */
	public static function validRequestId( $id ): bool {
		return is_string( $id ) && preg_match( '/\A[A-Za-z0-9-]{16,64}\z/D', $id ) === 1;}
	/**
	 * Resolve the private upload directory outside the public web root.
	 *
	 * @return string Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function privateDirectory(): string {
		$base = \Digitalogic_Storefront_Order_Forms::instance()->private_upload_directory();
		if ( is_wp_error( $base ) ) {
			throw new \RuntimeException( 'Private storage unavailable.' );}
		$real = realpath( $base );
		$web  = realpath( ABSPATH );
		if ( ! $real || ! $web || str_starts_with( $real . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR ) ) {
			throw new \RuntimeException( 'Mobile uploads require outside-webroot storage.' );}
		$directory = $real . '/mobile';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0750, true ) ) {
			throw new \RuntimeException( 'Private storage unavailable.' );
		}return $directory;
	}
	/**
	 * Verify uploaded file signatures against the permitted form attachment types.
	 *
	 * @param string $path Path.
	 * @param string $extension Extension.
	 * @return bool Native adapter result.
	 */
	public static function fileTypeMatches( string $path, string $extension ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
		$head = file_get_contents( $path, false, null, 0, 4096 );
		if ( ! is_string( $head ) ) {
			return false;}
		return match ( $extension ) {
			'zip', 'xlsx'=>str_starts_with( $head, "PK\x03\x04" ) || str_starts_with( $head, "PK\x05\x06" ), 'rar'=>str_starts_with( $head, "Rar!\x1a\x07" ), '7z'=>str_starts_with( $head, "7z\xbc\xaf\x27\x1c" ), 'pdf'=>str_starts_with( $head, '%PDF-' ), 'xls'=>str_starts_with( $head, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" ), 'csv'=> ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $head ), default=>false};
	}

	/**
	 * Bound orphan retention; consumed files belong to the canonical request lifecycle.
	 *
	 * @return void Native adapter result.
	 * @throws \Throwable After rollback when the underlying authoritative operation fails.
	 */
	public static function cleanupUploads(): void {
		global $wpdb;
		$table     = self::table( 'uploads' );
		$directory = self::privateDirectory();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,path FROM %i WHERE state<>'consumed' AND (expires_at<%d OR state='failed') LIMIT 50 FOR UPDATE", $table, time() ), ARRAY_A );
			foreach ( $rows as $row ) {
				$expected = $directory . '/' . $row['id'] . '.part';
				if ( $row['path'] !== $expected ) {
					continue;}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Bounded private streams require local offsets, permissions and locks unavailable in WP_Filesystem.
				if ( is_file( $expected ) && ! unlink( $expected ) ) {
					continue;}
				$wpdb->delete( $table, array( 'id' => $row['id'] ) );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;}
	}
}
