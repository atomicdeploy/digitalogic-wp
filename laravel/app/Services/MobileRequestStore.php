<?php
namespace Digitalogic\Laravel\Services;

final class MobileRequestStore {
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'uploads', 'requests', 'drafts' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown mobile table.' );
		}return $wpdb->prefix . 'digitalogic_mobile_' . $name;}
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
	public static function requestKey( int $user_id, string $id ): string {
		return MobileIdentityStore::hash( 'request:' . $user_id . ':' . $id );}
	public static function validRequestId( $id ): bool {
		return is_string( $id ) && preg_match( '/\A[A-Za-z0-9-]{16,64}\z/D', $id ) === 1;}
	public static function privateDirectory(): string {
		$base = \Digitalogic_Storefront_Order_Forms::instance()->private_upload_directory();
		if ( is_wp_error( $base ) ) {
			throw new \RuntimeException( 'Private storage unavailable.' );}
		$real = realpath( $base );
		$web  = realpath( ABSPATH );
		if ( ! $real || ! $web || str_starts_with( $real . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR ) ) {
			throw new \RuntimeException( 'Mobile uploads require outside-webroot storage.' );}
		$directory = $real . '/mobile';
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0750, true ) ) {
			throw new \RuntimeException( 'Private storage unavailable.' );
		}return $directory;
	}
	public static function fileTypeMatches( string $path, string $extension ): bool {
		$head = file_get_contents( $path, false, null, 0, 4096 );
		if ( ! is_string( $head ) ) {
			return false;}
		return match ( $extension ) {
			'zip', 'xlsx'=>str_starts_with( $head, "PK\x03\x04" ) || str_starts_with( $head, "PK\x05\x06" ), 'rar'=>str_starts_with( $head, "Rar!\x1a\x07" ), '7z'=>str_starts_with( $head, "7z\xbc\xaf\x27\x1c" ), 'pdf'=>str_starts_with( $head, '%PDF-' ), 'xls'=>str_starts_with( $head, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" ), 'csv'=> ! preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $head ), default=>false};
	}

	/** Bound orphan retention; consumed files belong to the canonical request lifecycle. */
	public static function cleanupUploads(): void {
		global $wpdb;$table=self::table('uploads');$directory=self::privateDirectory();
		$wpdb->query('START TRANSACTION');
		try{
			$rows=$wpdb->get_results($wpdb->prepare("SELECT id,path FROM {$table} WHERE state<>'consumed' AND (expires_at<%d OR state='failed') LIMIT 50 FOR UPDATE",time()),ARRAY_A);
			foreach($rows as $row){
				$expected=$directory.'/'.$row['id'].'.part';
				if($row['path']!==$expected){continue;}
				if(is_file($expected)&&!unlink($expected)){continue;}
				$wpdb->delete($table,array('id'=>$row['id']));
			}
			$wpdb->query('COMMIT');
		}catch(\Throwable $error){$wpdb->query('ROLLBACK');throw $error;}
	}
}
