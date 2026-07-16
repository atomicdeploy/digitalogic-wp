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

		if ( ! is_callable( array( $data_store, 'refresh_product_lookup_table' ) ) ) {
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

		return is_callable( array( $data_store, 'refresh_product_lookup_table' ) );
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
