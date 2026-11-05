<?php

namespace Digitalogic\Laravel\Services;

/**
 * Token/revision metadata. items is a reconciled projection of the authoritative Woo cart.
 */
final class MobileCartStore {

	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'carts', 'cart_tokens', 'cart_operations' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown cart table.' );
		}
		return $wpdb->prefix . 'digitalogic_mobile_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		include_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset    = $wpdb->get_charset_collate();
		$carts      = self::table( 'carts' );
		$tokens     = self::table( 'cart_tokens' );
		$operations = self::table( 'cart_operations' );
		dbDelta(
			"CREATE TABLE {$carts} (
			id char(32) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			items longtext NOT NULL,
			revision bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id)
		) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$tokens} (
			token_hash char(64) NOT NULL,
			cart_id char(32) NOT NULL,
			expires_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (token_hash),
			KEY cart_id (cart_id),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$operations} (
			operation_key char(64) NOT NULL,
			cart_id char(32) NOT NULL,
			payload_hash char(64) NOT NULL,
			applied_revision bigint(20) unsigned NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (operation_key),
			KEY cart_id (cart_id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};"
		);
		foreach ( array( $carts, $tokens, $operations ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				throw new \RuntimeException( 'Cart storage unavailable.' );
			}
		}
		if ( get_option( 'digitalogic_mobile_cart_schema' ) === '1' && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$carts}" ) > 0 ) {
			throw new \RuntimeException( 'Legacy cart intent must be migrated before enabling Woo cart adapter.' ); }
		update_option( 'digitalogic_mobile_cart_schema', '2', false );
	}

	public static function create( ?int $user_id = null ): array {
		global $wpdb;
		self::cleanup();
		$table = self::table( 'carts' );
		if ( $user_id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d", $user_id ), ARRAY_A );
			if ( $existing ) {
				return $existing;
			}
		}
		$row = array(
			'id'         => bin2hex( random_bytes( 16 ) ),
			'user_id'    => $user_id,
			'items'      => '[]',
			'revision'   => 0,
			'updated_at' => time(),
		);
		if ( $user_id ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (id,user_id,items,revision,updated_at) VALUES (%s,%d,'[]',0,%d)", $row['id'], $user_id, time() ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d", $user_id ), ARRAY_A );
		} elseif ( $wpdb->insert( $table, $row ) !== 1 ) {
			throw new \RuntimeException( 'Cart could not be created.' );
		}
		if ( ! $row ) {
			throw new \RuntimeException( 'Cart unavailable.' );
		}
		return $row;
	}

	public static function refresh( array $cart ): array {
		global $wpdb;
		$table = self::table( 'carts' );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%s FOR UPDATE", $cart['id'] ), ARRAY_A );
			if ( ! $current ) {
				throw new \RuntimeException( 'Cart disappeared.' );
			}$current = MobileWooCart::reconcile( $current );
			$wpdb->query( 'COMMIT' );
			return $current;} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;}
	}

	public static function token( string $cart_id ): string {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		if ( $wpdb->insert(
			self::table( 'cart_tokens' ),
			array(
				'token_hash' => hash( 'sha256', $token ),
				'cart_id'    => $cart_id,
				'expires_at' => time() + 90 * DAY_IN_SECONDS,
			)
		) !== 1 ) {
			throw new \RuntimeException( 'Cart token could not be saved.' );
		}
		return $token;
	}

	public static function byToken( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/\A[a-f0-9]{64}\z/D', $token ) ) {
			return null;
		}
		$carts  = self::table( 'carts' );
		$tokens = self::table( 'cart_tokens' );
		$hash   = hash( 'sha256', $token );
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT c.*,t.expires_at AS token_expires FROM {$carts} c JOIN {$tokens} t ON t.cart_id=c.id WHERE t.token_hash=%s AND t.expires_at>%d", $hash, time() ), ARRAY_A );
		if ( $row && (int) $row['token_expires'] < time() + 89 * DAY_IN_SECONDS ) {
			$wpdb->update( $tokens, array( 'expires_at' => time() + 90 * DAY_IN_SECONDS ), array( 'token_hash' => $hash ) );
		}
		return $row;
	}

	private static function cleanup(): void {
		global $wpdb;
		$tokens     = self::table( 'cart_tokens' );
		$operations = self::table( 'cart_operations' );
		$carts      = self::table( 'carts' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE expires_at<%d LIMIT 100", time() ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$operations} WHERE created_at<%d LIMIT 100", time() - 90 * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$carts} WHERE user_id IS NULL AND updated_at<%d AND id NOT IN (SELECT cart_id FROM {$tokens}) LIMIT 50", time() - 90 * DAY_IN_SECONDS ) );
	}

	/**
	 * Touched-item comparisons merge independent device edits without replacing a whole cart.
	 */
	public static function mergePatch( array $current, array $changes ): array {
		$quantities = array();
		foreach ( $current as $item ) {
			$quantities[ (int) $item['id'] ] = (int) $item['quantity'];
		}
		$conflicts = array();
		foreach ( $changes as $change ) {
			$id       = $change['id'];
			$quantity = $quantities[ $id ] ?? 0;
			if ( $quantity !== $change['base_quantity'] && $quantity !== $change['quantity'] ) {
				$conflicts[] = array(
					'id'                 => $id,
					'base_quantity'      => $change['base_quantity'],
					'current_quantity'   => $quantity,
					'requested_quantity' => $change['quantity'],
				);
			}
		}
		if ( $conflicts ) {
			return array(
				'items'     => $current,
				'conflicts' => $conflicts,
			);
		}
		foreach ( $changes as $change ) {
			if ( $change['quantity'] === 0 ) {
				unset( $quantities[ $change['id'] ] );
			} else {
				$quantities[ $change['id'] ] = $change['quantity'];
			}
		}
		ksort( $quantities, SORT_NUMERIC );
		$items = array();
		foreach ( $quantities as $id => $quantity ) {
			$items[] = array(
				'id'       => $id,
				'quantity' => $quantity,
			);
		}
		return array(
			'items'     => $items,
			'conflicts' => array(),
		);
	}

	public static function changes( $input ): ?array {
		if ( ! is_array( $input ) || ! array_is_list( $input ) || count( $input ) < 1 || count( $input ) > 40 ) {
			return null;
		}
		$seen = array();
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'id', 'quantity', 'base_quantity' ) ) || ! is_int( $item['id'] ?? null ) || $item['id'] <= 0 || ! is_int( $item['quantity'] ?? null ) || $item['quantity'] < 0 || $item['quantity'] > 999 || ! is_int( $item['base_quantity'] ?? null ) || $item['base_quantity'] < 0 || $item['base_quantity'] > 999 || isset( $seen[ $item['id'] ] ) ) {
				return null;
			}
			$seen[ $item['id'] ] = true;
		}
		usort( $input, static fn( $left, $right ) => $left['id'] <=> $right['id'] );
		return $input;
	}
}
