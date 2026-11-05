<?php
/**
 * Native WooSession adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

/** The installed WooCommerce session storage, selected by authenticated owner instead of browser cookie. */
final class MobileWooSession extends \WC_Session_Handler {
	/**
	 * Read only.
	 *
	 * @var bool
	 */
	private bool $read_only = true;
	/**
	 * Bind the adapter to its verified context while preserving the surrounding WordPress runtime.
	 *
	 * @param array $cart Cart.
	 * @return void Native adapter result.
	 */
	public function __construct( array $cart ) {
		parent::__construct();
		$this->_customer_id = self::key( $cart );
		$this->_data        = (array) $this->get_session( $this->_customer_id, array() );
		if ( ! empty( $cart['user_id'] ) && ! array_key_exists( 'cart', $this->_data ) ) {
			$persistent = get_user_meta( (int) $cart['user_id'], '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
			if ( is_array( $persistent ) && isset( $persistent['cart'] ) ) {
				$this->set( 'cart', $persistent['cart'] );}
		}
		if ( empty( $cart['user_id'] ) ) {
			$this->_session_expiration = time() + 90 * DAY_IN_SECONDS;
			$this->_session_expiring   = time() + 89 * DAY_IN_SECONDS;}
	}
	/**
	 * Bind an immutable operation identifier to its verified owner.
	 *
	 * @param array $cart Cart.
	 * @return string Native adapter result.
	 */
	public static function key( array $cart ): string {
		return ! empty( $cart['user_id'] ) ? (string) (int) $cart['user_id'] : 't_' . substr( $cart['id'], 0, 30 );}
	/**
	 * Report that this scoped native Woo session exists.
	 *
	 * @return mixed Native adapter result.
	 */
	public function has_session() {
		return true;}
	/**
	 * Initialize the scoped native session without exchanging browser credentials.
	 *
	 * @return mixed Native adapter result.
	 */
	public function init() {}
	/**
	 * Keep native bearer authorization isolated from browser session cookies.
	 *
	 * @param mixed $set Set.
	 * @return mixed Native adapter result.
	 */
	public function set_customer_session_cookie( $set ) {}
	/**
	 * Suppress implicit cookie-session writes; explicit persistence owns this operation.
	 *
	 * @param mixed $old_session_key Old session key.
	 * @return mixed Native adapter result.
	 */
	public function save_data( $old_session_key = '' ) {
		if ( ! $this->read_only ) {
			parent::save_data( $old_session_key );}}
	/**
	 * Persist the explicitly modified canonical Woo session.
	 *
	 * @return void Native adapter result.
	 */
	public function persist(): void {
		$this->read_only = false;
		try {
			parent::save_data();
		} finally {
			$this->read_only = true;}}
}
