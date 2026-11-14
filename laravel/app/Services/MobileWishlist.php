<?php
/**
 * Native Wishlist adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;

/** The website's Woodmart wishlist is authoritative; native storage keeps only a sync projection. */
final class MobileWishlist {
	/**
	 * Check the installed canonical provider before exposing an adapter.
	 *
	 * @return bool Native adapter result.
	 */
	public static function available(): bool {
		return class_exists( 'XTS\\WC_Wishlist\\Wishlist' ) && class_exists( 'XTS\\WC_Wishlist\\DB_Storage' ) && (bool) get_option( 'wd_wishlist_installed' );}
	/**
	 * Report whether the deployed adapter and its required provider are available.
	 *
	 * @return bool Native adapter result.
	 */
	public static function enabled(): bool {
		return self::available() && (bool) get_option( 'digitalogic_mobile_woo_favorites', false );}
	/**
	 * Normalize desired product IDs without inferring missing identities.
	 *
	 * @param mixed $items Items.
	 * @return array|null Native adapter result.
	 */
	public static function normalize( $items ): ?array {
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 500 ) {
			return null;
		}foreach ( $items as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return null;
			}
		}$items = array_values( array_unique( $items ) );
		sort( $items, SORT_NUMERIC );
		return $items;
	}
	/**
	 * Read canonical provider product identities in deterministic order.
	 *
	 * @param array $rows Rows.
	 * @return array Native adapter result.
	 */
	private static function ids( array $rows ): array {
		$ids = array_map( static fn( $row )=> (int) $row['product_id'], $rows );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;}
	/**
	 * Project current provider state and its revision.
	 *
	 * @param array $rows Rows.
	 * @return array Native adapter result.
	 */
	private static function snapshot( array $rows ): array {
		$items = self::ids( $rows );
		return array(
			'items'    => $items,
			'revision' => hash( 'sha256', wp_json_encode( $items ) ),
		);}
	/**
	 * Reconcile native intent against the existing canonical provider state.
	 *
	 * @param int         $user_id Verified WordPress owner.
	 * @param array|null  $desired Desired.
	 * @param string|null $base Base.
	 * @return mixed Native adapter result.
	 * @throws \InvalidArgumentException When the authoritative operation cannot be completed safely.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function sync( int $user_id, ?array $desired = null, ?string $base = null ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'favorites_unavailable', 'علاقه‌مندی‌ها موقتاً در دسترس نیست.', array( 'status' => 503 ) );}
		global $wpdb;
		$lock     = 'dgl-wishlist-' . $user_id;
		$old_user = get_current_user_id();
		$table    = MobileIdentityStore::table( 'favorites' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) !== 1 ) {
			return new \WP_Error( 'favorites_busy', 'کمی بعد همگام‌سازی را ادامه دهید.', array( 'status' => 409 ) );}
		try {
			wp_set_current_user( $user_id );
			$wpdb->query( 'START TRANSACTION' );
			$wishlist = new \XTS\WC_Wishlist\Wishlist( false, $user_id, false );
			if ( ! $wishlist->get_id() ) {
				throw new \RuntimeException( 'Canonical wishlist unavailable.' );}
			if ( ! get_user_meta( $user_id, '_digitalogic_native_favorites_imported', true ) ) {
				$legacy  = $wpdb->get_var( $wpdb->prepare( 'SELECT items FROM %i WHERE user_id=%d FOR UPDATE', $table, $user_id ) );
				$legacy  = self::normalize( json_decode( $legacy ?? '[]', true ) ) ?? array();
				$current = self::ids( $wishlist->get_all() );
				foreach ( array_diff( $legacy, $current ) as $id ) {
					if ( ! $wishlist->add( $id, $wishlist->get_id() ) ) {
						throw new \RuntimeException( 'Wishlist migration incomplete.' );}
				}
				update_user_meta( $user_id, '_digitalogic_native_favorites_imported', '1' );
			}
			$rows    = $wishlist->get_all();
			$current = self::snapshot( $rows );
			if ( null !== $desired ) {
				$wanted_hash = hash( 'sha256', wp_json_encode( $desired ) );
				if ( $base !== $current['revision'] && $wanted_hash !== $current['revision'] ) {
					$wpdb->query( 'COMMIT' );
					return new \WP_Error(
						'favorites_conflict',
						'علاقه‌مندی‌ها در دستگاه دیگری تغییر کرده‌اند.',
						array(
							'status'   => 409,
							'snapshot' => $current,
						)
					);}
				$catalog = new MobileCatalogController();
				foreach ( array_diff( $desired, $current['items'] ) as $id ) {
					if ( ! $catalog->publicProduct( $id ) ) {
						throw new \InvalidArgumentException( 'invalid_product' );}
				}
				foreach ( array_diff( $desired, $current['items'] ) as $id ) {
					if ( ! $wishlist->add( $id, $wishlist->get_id() ) ) {
						throw new \RuntimeException( 'Wishlist add failed.' );}
				}
				foreach ( $rows as $row ) {
					if ( ! in_array( (int) $row['product_id'], $desired, true ) ) {
						if ( ! $wishlist->remove( (int) $row['product_id'], (int) $row['wishlist_id'] ) ) {
							throw new \RuntimeException( 'Wishlist remove failed.' );
						}do_action( 'woodmart_remove_product_from_wishlist', (int) $row['product_id'] );}
				}
				$current = self::snapshot( $wishlist->get_all() );
				if ( $current['items'] !== $desired ) {
					throw new \RuntimeException( 'Wishlist readback differs.' );}
			}
			$wpdb->replace(
				$table,
				array(
					'user_id'    => $user_id,
					'items'      => wp_json_encode( $current['items'] ),
					'revision'   => $current['revision'],
					'updated_at' => time(),
				)
			);
			if ( $wpdb->query( 'COMMIT' ) === false ) {
				throw new \RuntimeException( 'Wishlist commit uncertain.' );
			}return $current;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_delete( $user_id, 'user_meta' );
			return new \WP_Error( $error instanceof \InvalidArgumentException ? 'invalid_product' : 'favorites_write_uncertain', 'علاقه‌مندی‌ها ذخیره نشد؛ وضعیت را دوباره بررسی کنید.', array( 'status' => $error instanceof \InvalidArgumentException ? 422 : 503 ) );
		} finally {
			wp_set_current_user( $old_user );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}
}
