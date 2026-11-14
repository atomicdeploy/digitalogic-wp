<?php
/**
 * Durable customer destinations and operation metadata; Woo remains contact/order authority.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 native adapter contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Exact owner/revision reads and operation receipts must not use shared caches.

/** Saved destination records belong to an existing canonical WordPress customer. */
final class MobileAddressBookStore {

	/**
	 * Resolve only the closed address storage inventory.
	 *
	 * @param string $name Closed table name.
	 * @throws \InvalidArgumentException For unsupported storage.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'addresses', 'address_books', 'address_operations' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown address storage.' );
		}
		return $wpdb->prefix . 'digitalogic_customer_' . $name;
	}

	/**
	 * Explicit schema installation never silently migrates or modifies a customer.
	 *
	 * @throws \RuntimeException If transactional storage is not available.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset    = $wpdb->get_charset_collate();
		$addresses  = self::table( 'addresses' );
		$books      = self::table( 'address_books' );
		$operations = self::table( 'address_operations' );
		dbDelta(
			"CREATE TABLE {$addresses} (
			id char(32) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(80) NOT NULL,
			address_json text NOT NULL,
			revision bigint(20) unsigned NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			deleted_at bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_active (user_id,deleted_at,id)
		) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$books} (
			user_id bigint(20) unsigned NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 0,
			billing_id char(32) DEFAULT NULL,
			shipping_id char(32) DEFAULT NULL,
			billing_hash char(64) NOT NULL DEFAULT '',
			shipping_hash char(64) NOT NULL DEFAULT '',
			legacy_migrated tinyint unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (user_id)
		) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$operations} (
			operation_key char(64) NOT NULL,
			request_id char(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			payload_hash char(64) NOT NULL,
			state varchar(16) NOT NULL,
			response_ciphertext longtext DEFAULT NULL,
			effects_ciphertext longtext DEFAULT NULL,
			effects_count int unsigned NOT NULL DEFAULT 0,
			effects_cursor int unsigned NOT NULL DEFAULT 0,
			created_at bigint(20) unsigned NOT NULL,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (operation_key),
			KEY user_state (user_id,state,created_at)
		) ENGINE=InnoDB {$charset};"
		);
		self::assertTransactional();
		update_option( 'digitalogic_customer_address_schema', '1', false );
	}

	/**
	 * Verify every known affected canonical table before atomic geography changes.
	 *
	 * @throws \RuntimeException If a provider cannot preserve transaction rollback.
	 */
	public static function assertTransactional(): void {
		global $wpdb;
		foreach ( array( self::table( 'addresses' ), self::table( 'address_books' ), self::table( 'address_operations' ), $wpdb->users, $wpdb->usermeta, $wpdb->options, $wpdb->prefix . 'wc_customer_lookup' ) as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $table ), ARRAY_A );
			if ( ! $row || 0 !== strcasecmp( $row['Engine'] ?? '', 'InnoDB' ) ) {
				throw new \RuntimeException( 'Address transaction storage unavailable.' );
			}
		}
	}

	/**
	 * Read an owned active destination without creating or changing any row.
	 *
	 * @param int    $owner Authenticated WordPress customer.
	 * @param string $id Opaque destination identifier.
	 * @return array|null Owned raw record or missing.
	 */
	public static function find( int $owner, string $id ): ?array {
		global $wpdb;
		if ( $owner < 1 || ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d AND deleted_at IS NULL', self::table( 'addresses' ), $id, $owner ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Serialize a validated row without exposing its owner, internal journal or projection hash.
	 *
	 * @param array $row Owned canonical destination.
	 * @throws \RuntimeException If stored destination data is not readable.
	 */
	public static function present( array $row ): array {
		$address = json_decode( $row['address_json'], true );
		if ( ! is_array( $address ) || array_diff( array_keys( $address ), array_merge( MobileSavedAddress::FIELDS, array( 'location' ) ) ) ) {
			throw new \RuntimeException( 'Stored destination is invalid.' );
		}
		return array_merge(
			$address,
			array(
				'id'         => $row['id'],
				'label'      => $row['label'],
				'revision'   => (int) $row['revision'],
				'created_at' => gmdate( 'c', (int) $row['created_at'] ),
				'updated_at' => gmdate( 'c', (int) $row['updated_at'] ),
			)
		);
	}

	/**
	 * List a bounded owner collection; reading never creates a default or migrates Woo metadata.
	 *
	 * @param int $owner Authenticated WordPress customer.
	 * @throws \RuntimeException If an authoritative read cannot be completed.
	 */
	public static function listing( int $owner ): array {
		global $wpdb;
		if ( $owner < 1 ) {
			throw new \RuntimeException( 'Address owner required.' );
		}
		$book = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id=%d', self::table( 'address_books' ), $owner ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Address defaults unavailable.' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id=%d AND deleted_at IS NULL ORDER BY created_at,id LIMIT 101', self::table( 'addresses' ), $owner ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error || count( $rows ) > 100 ) {
			throw new \RuntimeException( 'Address collection unavailable.' );
		}
		return array(
			'revision' => (int) ( $book['revision'] ?? 0 ),
			'items'    => array_map( array( self::class, 'present' ), $rows ),
			'defaults' => array(
				'billing_id'  => $book['billing_id'] ?? null,
				'shipping_id' => $book['shipping_id'] ?? null,
			),
		);
	}

	/**
	 * Freeze exact owner and revision at quote time; never substitute a newer address silently.
	 *
	 * @param int   $owner Authenticated checkout owner.
	 * @param mixed $selection Requested billing/shipping record references.
	 * @return array|\WP_Error Immutable geographic snapshot and resolved references.
	 */
	public static function resolve( int $owner, $selection ) {
		if ( ! is_array( $selection ) || array_diff( array_keys( $selection ), array( 'billing', 'shipping' ) ) ) {
			return new \WP_Error( 'invalid_address_selection', 'نشانی انتخاب‌شده معتبر نیست.' );
		}
		$result = array(
			'references' => array(),
			'addresses'  => array(),
			'locations'  => array(),
		);
		foreach ( $selection as $group => $reference ) {
			if ( ! is_array( $reference ) || array_diff( array_keys( $reference ), array( 'id', 'revision' ) ) || ! is_string( $reference['id'] ?? null ) || ! is_int( $reference['revision'] ?? null ) || $reference['revision'] < 1 ) {
				return new \WP_Error( 'invalid_address_selection', 'نشانی انتخاب‌شده معتبر نیست.' );
			}
			$row = self::find( $owner, $reference['id'] );
			if ( ! $row || (int) $row['revision'] !== $reference['revision'] ) {
				return new \WP_Error( 'address_changed', 'نشانی انتخاب‌شده تغییر کرده است؛ دوباره آن را بررسی کنید.', array( 'status' => 409 ) );
			}
			$address = self::present( $row );
			$valid   = MobileSavedAddress::validate( $address );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$result['references'][ $group ] = array(
				'id'       => $row['id'],
				'revision' => (int) $row['revision'],
			);
			$result['addresses'][ $group ]  = array_intersect_key( $address, array_fill_keys( MobileSavedAddress::FIELDS, true ) );
			$result['locations'][ $group ]  = $address['location'] ?? null;
		}
		return $result;
	}
}
