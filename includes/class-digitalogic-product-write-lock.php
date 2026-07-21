<?php
/**
 * Shared WooCommerce product-write lock.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes existing-product writes across Digitalogic and normal WooCommerce
 * CRUD requests on the current WordPress database connection.
 */
final class Digitalogic_Product_Write_Lock {

	private const LOCK_TIMEOUT_SECONDS = 15;

	/**
	 * Shared coordinator instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Nested ownership depth by product ID.
	 *
	 * @var array<int,int>
	 */
	private $depths = array();

	/**
	 * Product lock stacks by WooCommerce object ID.
	 *
	 * @var array<int,array<int,int>>
	 */
	private $hook_locks = array();

	/** Return the shared lock coordinator. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register around-save hooks for every WooCommerce product CRUD writer. */
	private function __construct() {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'acquire_for_woocommerce_save' ), 1, 1 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'release_after_woocommerce_save' ), PHP_INT_MAX, 1 );
		add_action( 'woocommerce_product_before_set_stock', array( $this, 'acquire_for_woocommerce_save' ), 1, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'release_after_woocommerce_save' ), PHP_INT_MAX, 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( $this, 'acquire_for_woocommerce_save' ), 1, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'release_after_woocommerce_save' ), PHP_INT_MAX, 1 );
		add_action( 'shutdown', array( $this, 'release_all' ), PHP_INT_MAX );
	}

	/**
	 * Run a callback while the existing product is exclusively locked.
	 *
	 * Nested WooCommerce saves in the callback reuse the same database lock. The
	 * finally block also drains a hook acquisition when a third-party save hook
	 * throws before WooCommerce can emit its normal after-save action.
	 *
	 * @param int      $product_id Product ID.
	 * @param callable $callback Callback to run.
	 * @param int      $timeout Maximum lock wait in seconds.
	 * @return mixed|WP_Error
	 */
	public function with_product_lock( $product_id, $callback, $timeout = self::LOCK_TIMEOUT_SECONDS ) {
		$product_id = absint( $product_id );
		$baseline   = $this->depths[ $product_id ] ?? 0;
		$acquired   = $this->acquire( $product_id, $timeout );
		if ( is_wp_error( $acquired ) ) {
			return $acquired;
		}

		try {
			return call_user_func( $callback );
		} finally {
			while ( ( $this->depths[ $product_id ] ?? 0 ) > $baseline ) {
				$this->release( $product_id );
			}
		}
	}

	/**
	 * Acquire a site-scoped advisory lock for one existing product.
	 *
	 * @param int $product_id Product ID.
	 * @param int $timeout Maximum lock wait in seconds.
	 * @return true|WP_Error
	 */
	public function acquire( $product_id, $timeout = self::LOCK_TIMEOUT_SECONDS ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return new WP_Error(
				'digitalogic_product_write_lock_invalid',
				__( 'An existing product is required for a guarded write.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}
		if ( ( $this->depths[ $product_id ] ?? 0 ) > 0 ) {
			++$this->depths[ $product_id ];
			return true;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return new WP_Error(
				'product_write_lock_unavailable',
				__( 'The product write lock service is unavailable.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$prepared = $wpdb->prepare(
			'SELECT GET_LOCK(%s, %d)',
			$this->lock_name( $product_id ),
			max( 0, (int) $timeout )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot be cached.
		$locked = false !== $prepared ? $wpdb->get_var( $prepared ) : false;
		if ( 1 !== (int) $locked ) {
			return new WP_Error(
				'product_write_lock_busy',
				__( 'This product is being updated by another request. Retry the unchanged request.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$this->depths[ $product_id ] = 1;
		return true;
	}

	/**
	 * Release one nested ownership level.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function release( $product_id ) {
		$product_id = absint( $product_id );
		if ( ( $this->depths[ $product_id ] ?? 0 ) <= 0 ) {
			return;
		}

		--$this->depths[ $product_id ];
		if ( $this->depths[ $product_id ] > 0 ) {
			return;
		}
		unset( $this->depths[ $product_id ] );

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		$prepared = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $product_id ) );
		if ( false !== $prepared ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Advisory locks are connection state and cannot be cached.
			$wpdb->get_var( $prepared );
		}
	}

	/**
	 * Acquire from WooCommerce's product CRUD or direct-stock boundary.
	 *
	 * @param WC_Product $product Product being saved.
	 * @throws RuntimeException When another request owns the product lock.
	 * @return void
	 */
	public function acquire_for_woocommerce_save( $product ) {
		$product_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? absint( $product->get_id() ) : 0;
		if ( $product_id <= 0 ) {
			return;
		}

		$acquired = $this->acquire( $product_id );
		if ( is_wp_error( $acquired ) ) {
			throw new RuntimeException( 'WooCommerce product write lock is busy.' );
		}

		$object_id                        = spl_object_id( $product );
		$this->hook_locks[ $object_id ][] = $product_id;
	}

	/**
	 * Release the matching WooCommerce product or stock acquisition.
	 *
	 * @param WC_Product $product Product that was saved.
	 * @return void
	 */
	public function release_after_woocommerce_save( $product ) {
		if ( ! is_object( $product ) ) {
			return;
		}
		$object_id = spl_object_id( $product );
		if ( empty( $this->hook_locks[ $object_id ] ) ) {
			return;
		}

		$product_id = array_pop( $this->hook_locks[ $object_id ] );
		if ( empty( $this->hook_locks[ $object_id ] ) ) {
			unset( $this->hook_locks[ $object_id ] );
		}
		$this->release( $product_id );
	}

	/** Release any leaked hook lock at request shutdown. */
	public function release_all() {
		foreach ( array_keys( $this->depths ) as $product_id ) {
			while ( ( $this->depths[ $product_id ] ?? 0 ) > 0 ) {
				$this->release( $product_id );
			}
		}
		$this->hook_locks = array();
	}

	/**
	 * Build a deterministic lock name within MySQL's 64-byte cap.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function lock_name( $product_id ) {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';

		return substr( 'digitalogic_product_' . md5( $prefix ) . '_' . absint( $product_id ), 0, 64 );
	}
}
