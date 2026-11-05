<?php
namespace Digitalogic\Laravel\Services;

/** Adapter only: Woo sessions/persistent cart own shopping data; mobile tables coordinate revisions. */
final class MobileWooCart {
	public static function quantities( array $raw ): array {
		$map = array();
		foreach ( $raw as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}$id      = (int) ( ( $line['variation_id'] ?? 0 ) ?: ( $line['product_id'] ?? 0 ) );
			$quantity = (int) ( $line['quantity'] ?? 0 );
			if ( $id > 0 && $quantity > 0 ) {
				$map[ $id ] = ( $map[ $id ] ?? 0 ) + $quantity;}
		}
		ksort( $map, SORT_NUMERIC );
		$items = array();
		foreach ( $map as $id => $quantity ) {
			$items[] = array(
				'id'       => (int) $id,
				'quantity' => $quantity,
			);
		}return $items;
	}
	public static function read( array $cart ): array {
		$session = new MobileWooSession( $cart );
		return self::quantities( (array) $session->get( 'cart', array() ) );}
	/** Called under the mobile metadata row lock. Website cart changes become a new observed revision. */
	public static function reconcile( array $cart ): array {
		global $wpdb;
		$items = wp_json_encode( self::read( $cart ) );
		if ( $items !== $cart['items'] ) {
			$cart['items']      = $items;
			$cart['revision']   = (int) $cart['revision'] + 1;
			$cart['updated_at'] = time();
			if ( $wpdb->update(
				MobileCartStore::table( 'carts' ),
				array(
					'items'      => $items,
					'revision'   => $cart['revision'],
					'updated_at' => $cart['updated_at'],
				),
				array( 'id' => $cart['id'] )
			) !== 1 ) {
				throw new \RuntimeException( 'Woo cart observation could not be saved.' );}
		}
		return $cart;
	}
	/** Apply touched lines using Woo's existing cart validation/actions and persist through Woo's APIs. */
	public static function mutate( array $cart, array $changes ): array {
		$context = array(
			'cart'    => $cart,
			'session' => array( 'user_id' => (int) ( $cart['user_id'] ?? 0 ) ),
		);
		$bridge  = new MobileWooCheckout( $context, array(), true );
		try {
			$session = new \WC_Cart_Session( WC()->cart );
			$session->get_cart_from_session();
			$keys = array();
			foreach ( WC()->cart->get_cart() as $key => $line ) {
				$id            = (int) ( $line['variation_id'] ?: $line['product_id'] );
				$keys[ $id ][] = $key;}
			foreach ( $changes as $change ) {
				$id       = (int) $change['id'];
				$quantity = (int) $change['quantity'];
				$matching = $keys[ $id ] ?? array();
				if ( $quantity === 0 ) {
					foreach ( $matching as $key ) {
						WC()->cart->remove_cart_item( $key );
					}continue;}
				if ( count( $matching ) > 1 ) {
					throw new \RuntimeException( 'unsupported_cart_options' );}
				$product = wc_get_product( $id );
				if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) || ( $product->is_sold_individually() && $quantity !== 1 ) ) {
					throw new \RuntimeException( 'cart_product_unavailable' );}
				if ( $matching ) {
					$key  = $matching[0];
					$line = WC()->cart->get_cart()[ $key ];
					if ( ! apply_filters( 'woocommerce_update_cart_validation', true, $key, $line, $quantity ) ) {
						throw new \RuntimeException( 'cart_validation_failed' );
					}WC()->cart->set_quantity( $key, $quantity, false );
				} else {
					$variation = $product->is_type( 'variation' );
					if ( ! WC()->cart->add_to_cart( $variation ? $product->get_parent_id() : $id, $quantity, $variation ? $id : 0, $variation ? $product->get_variation_attributes() : array() ) ) {
						throw new \RuntimeException( 'cart_validation_failed' );}
				}
			}
			WC()->cart->calculate_totals();
			$items = self::quantities( WC()->cart->get_cart() );
			$session->set_session();
			$session->persistent_cart_update();
			WC()->session->persist();
			// Verify the real Woo table through its public reader, never the metadata projection.
			self::invalidate( $cart );
			if ( self::read( $cart ) !== $items ) {
				throw new \RuntimeException( 'woo_cart_write_uncertain' );}
			return $items;
		} finally {
			$bridge->close();}
	}
	public static function discard( array $cart ): void {
		if ( empty( $cart['user_id'] ) ) {
			( new MobileWooSession( $cart ) )->delete_session( MobileWooSession::key( $cart ) );}}
	public static function invalidate( array $cart ): void {
		wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . MobileWooSession::key( $cart ), WC_SESSION_CACHE_GROUP );
		if ( ! empty( $cart['user_id'] ) ) {
			wp_cache_delete( (int) $cart['user_id'], 'user_meta' );}
	}
}
