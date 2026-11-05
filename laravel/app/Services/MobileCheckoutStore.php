<?php
/**
 * Native CheckoutStore adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

/** Durable quote/operation records. Never store a card number or gateway credential. */
final class MobileCheckoutStore {
	/**
	 * Resolve an adapter-owned table from the closed table-name allowlist.
	 *
	 * @param string $name Name.
	 * @return string Native adapter result.
	 * @throws \InvalidArgumentException When the authoritative operation cannot be completed safely.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'checkout_quotes', 'checkout_operations' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown checkout table.' );}
		return $wpdb->prefix . 'digitalogic_mobile_' . $name;
	}
	/**
	 * Create or verify the supporting metadata schema without replacing canonical business records.
	 *
	 * @return void Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset    = $wpdb->get_charset_collate();
		$quotes     = self::table( 'checkout_quotes' );
		$operations = self::table( 'checkout_operations' );
		dbDelta(
			"CREATE TABLE {$quotes} (
            id char(32) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            cart_id char(32) NOT NULL,
            cart_revision bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            fingerprint char(64) NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$operations} (
            operation_key char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            request_id varchar(64) NOT NULL,
            quote_id char(32) DEFAULT NULL,
            cart_id char(32) NOT NULL,
            payload_hash char(64) NOT NULL,
            cart_snapshot longtext DEFAULT NULL,
            state varchar(32) NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            response longtext DEFAULT NULL,
            created_at bigint(20) unsigned NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (operation_key),
            UNIQUE KEY quote_id (quote_id),
            KEY cart_state (cart_id,state),
            KEY user_id (user_id)
        ) ENGINE=InnoDB {$charset};"
		);
		foreach ( array( $quotes, $operations ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new \RuntimeException( 'Checkout storage unavailable.' );}
		}
		update_option( 'digitalogic_mobile_checkout_schema', '2', false );
	}
	/**
	 * Check for a durable operation whose result must be recovered before another mutation.
	 *
	 * @param string $cart_id Cart id.
	 * @return bool Native adapter result.
	 */
	public static function busy( string $cart_id ): bool {
		if ( ! in_array( get_option( 'digitalogic_mobile_checkout_schema' ), array( '1', '2' ), true ) ) {
			return false;}
		global $wpdb;
		$table = self::table( 'checkout_operations' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE cart_id=%s AND state IN ('validating','creating','payment_pending','uncertain') LIMIT 1", $table, $cart_id ) );
	}
	/**
	 * Bind an immutable operation identifier to its verified owner.
	 *
	 * @param int    $user_id Verified WordPress owner.
	 * @param string $request_id Request id.
	 * @return string Native adapter result.
	 */
	public static function key( int $user_id, string $request_id ): string {
		return MobileIdentityStore::hash( 'checkout:' . $user_id . ':' . $request_id );}
	/**
	 * Read one owner-scoped durable operation.
	 *
	 * @param int    $user_id Verified WordPress owner.
	 * @param string $request_id Request id.
	 * @return array|null Native adapter result.
	 */
	public static function operation( int $user_id, string $request_id ): ?array {
		global $wpdb;
		$table = self::table( 'checkout_operations' );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s AND user_id=%d', $table, self::key( $user_id, $request_id ), $user_id ), ARRAY_A ) ?? null;
	}
	/**
	 * Reserve a short-lived quote bound to current canonical checkout state.
	 *
	 * @param array  $context Verified owner and canonical cart context.
	 * @param array  $payload Payload.
	 * @param string $fingerprint Fingerprint.
	 * @return array Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function quote( array $context, array $payload, string $fingerprint ): array {
		global $wpdb;
		$table = self::table( 'checkout_quotes' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at<%d LIMIT 100', $table, time() - DAY_IN_SECONDS ) );
		$row = array(
			'id'            => bin2hex( random_bytes( 16 ) ),
			'user_id'       => (int) $context['session']['user_id'],
			'cart_id'       => $context['cart']['id'],
			'cart_revision' => (int) $context['cart']['revision'],
			'payload'       => wp_json_encode( $payload ),
			'fingerprint'   => $fingerprint,
			'expires_at'    => time() + 600,
		);
		if ( $wpdb->insert( $table, $row ) !== 1 ) {
			throw new \RuntimeException( 'Quote could not be saved.' );
		}return $row;
	}
}
