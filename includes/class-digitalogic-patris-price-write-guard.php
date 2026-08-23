<?php
/**
 * Guard canonical Patris prices from uncoordinated WooCommerce writes.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows managed Woo price writes only while the canonical writer is active.
 */
final class Digitalogic_Patris_Price_Write_Guard {

	/**
	 * Shared service.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Nested canonical-writer depth.
	 *
	 * @var int
	 */
	private $authorized_depth = 0;

	/**
	 * Return the shared service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the WooCommerce and metadata guards.
	 */
	private function __construct() {
		add_action( 'woocommerce_before_product_object_save', array( $this, 'guard_product_save' ), 10, 2 );
		add_action( 'updated_post_meta', array( $this, 'reconcile_regular_price_metadata' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'reconcile_regular_price_metadata' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'reconcile_regular_price_metadata' ), 10, 4 );
		add_filter( 'update_post_metadata', array( $this, 'guard_price_metadata' ), 10, 5 );
		add_filter( 'add_post_metadata', array( $this, 'guard_price_metadata' ), 10, 5 );
		add_filter( 'delete_post_metadata', array( $this, 'guard_price_metadata' ), 10, 5 );
		add_filter( 'woocommerce_product_get_price', array( $this, 'canonical_visible_price' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'canonical_visible_price' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'canonical_sale_price' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'canonical_sale_price' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Run one trusted canonical price write.
	 *
	 * @param callable $callback Canonical write.
	 * @return mixed
	 */
	public function with_authorized_write( $callback ) {
		++$this->authorized_depth;
		try {
			return call_user_func( $callback );
		} finally {
			--$this->authorized_depth;
		}
	}

	/**
	 * Whether a product has a complete canonical Patris pricing identity.
	 *
	 * A legacy Product Code by itself is not enough: the record hash proves
	 * that the transformed-only receiver owns the current commercial price.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @return bool
	 */
	public function is_managed_product( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) && method_exists( $product, 'get_meta' ) ) {
			$product_code = trim( (string) $product->get_meta( '_digitalogic_patris_product_code', true ) );
			$record_hash  = trim( (string) $product->get_meta( '_digitalogic_patris_record_hash', true ) );
		} else {
			$product_id   = absint( $product );
			$product_code = trim( (string) get_post_meta( $product_id, '_digitalogic_patris_product_code', true ) );
			$record_hash  = trim( (string) get_post_meta( $product_id, '_digitalogic_patris_record_hash', true ) );
		}

		return '' !== $product_code && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $record_hash );
	}

	/**
	 * Reject managed writes and normalize every other stored Woo price tuple.
	 *
	 * @param WC_Product $product    Product being saved.
	 * @param mixed      $data_store WooCommerce data store.
	 * @return void
	 * @throws RuntimeException When an unmanaged writer changes the price.
	 */
	public function guard_product_save( $product, $data_store = null ) {
		unset( $data_store );
		if (
			$this->authorized_depth > 0
			|| ! is_object( $product )
			|| ! method_exists( $product, 'get_changes' )
		) {
			return;
		}

		$changes = $product->get_changes();
		if (
			! is_array( $changes )
			|| ! array_intersect(
				array( 'regular_price', 'sale_price', 'price' ),
				array_keys( $changes )
			)
		) {
			return;
		}

		if ( $this->is_managed_product( $product ) ) {
			throw new RuntimeException(
				'قیمت عادی این کالای Patris فقط از مسیر هماهنگ‌ساز قیمت دیجیتالاجیک قابل تغییر است.'
			);
		}

		$regular = method_exists( $product, 'get_regular_price' )
			? trim( (string) $product->get_regular_price( 'edit' ) )
			: '';
		$visible = method_exists( $product, 'get_price' )
			? trim( (string) $product->get_price( 'edit' ) )
			: '';
		if ( '' === $regular && '' !== $visible ) {
			$regular = $visible;
		}
		if ( method_exists( $product, 'set_regular_price' ) ) {
			$product->set_regular_price( $regular );
		}
		if ( method_exists( $product, 'set_sale_price' ) ) {
			$product->set_sale_price( '' );
		}
		if ( method_exists( $product, 'set_price' ) ) {
			$product->set_price( $regular );
		}
	}

	/**
	 * Reject direct Woo price metadata writes for managed products.
	 *
	 * WordPress metadata filters short-circuit when they return a non-null
	 * value. Returning false is therefore a hard, observable write failure.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Product ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Requested value.
	 * @param mixed  $prev_value Optional previous value.
	 * @return mixed
	 */
	public function guard_price_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		unset( $prev_value );
		if (
			null !== $check
			|| $this->authorized_depth > 0
			|| ! in_array( (string) $meta_key, array( '_regular_price', '_sale_price', '_price' ), true )
			|| ! $this->is_product_record( (int) $object_id )
		) {
			return $check;
		}

		if ( $this->is_managed_product( (int) $object_id ) ) {
			return false;
		}
		if ( '_sale_price' === (string) $meta_key ) {
			return '' === trim( (string) $meta_value ) ? $check : false;
		}
		if ( '_price' === (string) $meta_key ) {
			$regular = trim( (string) get_post_meta( (int) $object_id, '_regular_price', true ) );
			return hash_equals( $regular, trim( (string) $meta_value ) ) ? $check : false;
		}

		return $check;
	}

	/**
	 * Complete a direct regular-price metadata write through WooCommerce.
	 *
	 * @param mixed  $meta_id    Metadata ID or IDs.
	 * @param int    $object_id  Product ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Written or deleted value.
	 * @return void
	 */
	public function reconcile_regular_price_metadata( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if (
			$this->authorized_depth > 0
			|| '_regular_price' !== (string) $meta_key
			|| ! $this->is_product_record( (int) $object_id )
			|| $this->is_managed_product( (int) $object_id )
		) {
			return;
		}

		$product = wc_get_product( (int) $object_id );
		if ( ! $product ) {
			return;
		}
		$regular = trim( (string) get_post_meta( (int) $object_id, '_regular_price', true ) );
		try {
			$this->with_authorized_write(
				static function () use ( $product, $regular ) {
					$product->set_regular_price( $regular );
					$product->set_sale_price( '' );
					$product->set_price( $regular );
					$product->save();
				}
			);
		} catch ( Throwable $exception ) {
			do_action(
				'digitalogic_price_invariant_reconciliation_failed',
				(int) $object_id,
				get_class( $exception )
			);
		}
	}

	/**
	 * Make the customer-visible value equal to the selling/regular value.
	 *
	 * @param mixed      $price   Filtered price.
	 * @param WC_Product $product Product object.
	 * @return mixed
	 */
	public function canonical_visible_price( $price, $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_regular_price' ) ) {
			return $price;
		}
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
			return $price;
		}

		$regular = trim( (string) $product->get_regular_price( 'edit' ) );
		return '' === $regular ? $price : $regular;
	}

	/**
	 * Promotions are not a second customer-visible price in this ecosystem.
	 *
	 * @param mixed      $sale_price Filtered sale price.
	 * @param WC_Product $product    Product object.
	 * @return string
	 */
	public function canonical_sale_price( $sale_price, $product ) {
		unset( $sale_price, $product );

		return '';
	}

	/**
	 * Whether an ID is a WooCommerce product or variation.
	 *
	 * @param int $product_id Post ID.
	 * @return bool
	 */
	private function is_product_record( $product_id ) {
		return in_array( get_post_type( (int) $product_id ), array( 'product', 'product_variation' ), true );
	}
}
