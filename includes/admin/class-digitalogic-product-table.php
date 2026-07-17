<?php
/**
 * WooCommerce product lookup-table maintenance.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run explicit catalog maintenance without widening a row action.
 */
class Digitalogic_Product_Table {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared handler.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Prevent direct construction. */
	private function __construct() {
	}

	/**
	 * Regenerate WooCommerce product lookup data.
	 *
	 * A nonempty ID list is always a bounded row operation. An empty list is an
	 * explicit whole-catalog maintenance request retained for existing callers.
	 *
	 * @param array $product_ids Product IDs to update.
	 * @return bool|WP_Error
	 */
	public function regenerate_lookup_tables( $product_ids = array() ) {
		if ( empty( $product_ids ) ) {
			if ( ! function_exists( 'wc_update_product_lookup_tables' ) ) {
				return new WP_Error(
					'digitalogic_product_lookup_rebuild_unavailable',
					__( 'WooCommerce product lookup-table maintenance is unavailable.', 'digitalogic' )
				);
			}

			wc_update_product_lookup_tables();

			return true;
		}

		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		if ( empty( $product_ids ) ) {
			return true;
		}

		try {
			$data_store = class_exists( 'WC_Data_Store' ) ? WC_Data_Store::load( 'product' ) : null;
		} catch ( Throwable ) {
			return $this->unsupported_error();
		}

		if ( ! $this->supports_row_refresh( $data_store ) ) {
			return $this->unsupported_error();
		}

		foreach ( $product_ids as $product_id ) {
			$data_store->refresh_product_lookup_table( $product_id );
		}

		return true;
	}

	/**
	 * Whether WooCommerce exposes a public per-row refresh method.
	 *
	 * @return bool
	 */
	public function supports_per_product_refresh() {
		try {
			$data_store = class_exists( 'WC_Data_Store' ) ? WC_Data_Store::load( 'product' ) : null;
		} catch ( Throwable ) {
			return false;
		}

		return $this->supports_row_refresh( $data_store );
	}

	/**
	 * Inspect the underlying store instead of trusting the magic proxy.
	 *
	 * WooCommerce's WC_Data_Store implements __call(), which makes a normal
	 * is_callable() check return true even when older product stores do not
	 * implement the requested method.
	 *
	 * @param object|null $data_store WooCommerce data-store proxy or test store.
	 * @return bool
	 */
	private function supports_row_refresh( $data_store ) {
		if ( ! is_object( $data_store ) ) {
			return false;
		}

		if ( method_exists( $data_store, 'has_callable' ) ) {
			return (bool) $data_store->has_callable( 'refresh_product_lookup_table' );
		}

		$class_name = method_exists( $data_store, 'get_current_class_name' )
			? $data_store->get_current_class_name()
			: get_class( $data_store );
		if ( ! is_string( $class_name ) || ! class_exists( $class_name ) || ! method_exists( $class_name, 'refresh_product_lookup_table' ) ) {
			return false;
		}

		try {
			$method = new ReflectionMethod( $class_name, 'refresh_product_lookup_table' );
		} catch ( ReflectionException ) {
			return false;
		}

		return $method->isPublic();
	}

	/**
	 * Build the stable refusal used when a bounded refresh is unavailable.
	 *
	 * @return WP_Error
	 */
	private function unsupported_error() {
		return new WP_Error(
			'digitalogic_product_lookup_row_refresh_unsupported',
			__( 'This WooCommerce version cannot safely refresh only one product lookup row.', 'digitalogic' )
		);
	}
}
