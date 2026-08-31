<?php
/**
 * Plugin Name: Digitalogic Canonical WooCommerce SKU Guard Loader
 * Description: Loads the plugin-owned SKU guard before ordinary plugins and fails closed if it is unavailable.
 * Version: 2.1.0
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$digitalogic_plugin_dir = defined( 'WP_PLUGIN_DIR' )
	? trailingslashit( WP_PLUGIN_DIR ) . 'digitalogic-wp/'
	: trailingslashit( dirname( WPMU_PLUGIN_DIR ) ) . 'plugins/digitalogic-wp/';
$digitalogic_lock_file  = $digitalogic_plugin_dir . 'includes/class-digitalogic-product-write-lock.php';
$digitalogic_guard_file = $digitalogic_plugin_dir . 'includes/class-digitalogic-sku-guard.php';

if ( is_readable( $digitalogic_lock_file ) && is_readable( $digitalogic_guard_file ) ) {
	require_once $digitalogic_lock_file;
	require_once $digitalogic_guard_file;
}

if ( class_exists( 'Digitalogic_Product_Write_Lock', false ) && class_exists( 'Digitalogic_SKU_Guard', false ) ) {
	Digitalogic_Product_Write_Lock::instance();
	Digitalogic_SKU_Guard::instance();
	return;
}

error_log( '[Digitalogic SKU Guard] Canonical implementation is unavailable; SKU writes are disabled.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Missing security boundary requires an operator-visible server record.

// An invalid-SKU uniqueness precheck must not enter WooCommerce's recursive
// suffix generator. The before-save boundary below rejects the write once.
add_filter(
	'wc_product_pre_has_unique_sku',
	static function ( $pre, $product_id, $sku ) {
		unset( $pre, $product_id, $sku );
		return true;
	},
	PHP_INT_MAX,
	3
);

add_action(
	'woocommerce_before_product_object_save',
	static function ( $product ) {
		$changes    = is_object( $product ) && method_exists( $product, 'get_changes' )
			? (array) $product->get_changes()
			: array();
		$product_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$new_sku    = is_object( $product ) && method_exists( $product, 'get_sku' )
			? (string) $product->get_sku( 'edit' )
			: '';
		if ( ! array_key_exists( 'sku', $changes ) && ! ( $product_id <= 0 && '' !== $new_sku ) ) {
			return;
		}

		throw new RuntimeException( 'The canonical SKU guard is unavailable; the SKU was not saved.' );
	},
	1,
	1
);

$digitalogic_is_sku_key        = static function ( $meta_key ) {
	return '_sku' === strtolower( (string) $meta_key );
};
$digitalogic_deny_sku_metadata = static function ( $check, $object_id, $meta_key ) use ( $digitalogic_is_sku_key ) {
	unset( $object_id );
	return $digitalogic_is_sku_key( $meta_key ) ? false : $check;
};

add_filter( 'add_post_metadata', $digitalogic_deny_sku_metadata, PHP_INT_MAX, 3 );
add_filter( 'update_post_metadata', $digitalogic_deny_sku_metadata, PHP_INT_MAX, 3 );
add_filter( 'delete_post_metadata', $digitalogic_deny_sku_metadata, PHP_INT_MAX, 3 );
add_filter(
	'update_post_metadata_by_mid',
	static function ( $check, $meta_id, $meta_value, $meta_key ) use ( $digitalogic_is_sku_key ) {
		unset( $meta_value );
		$meta        = get_metadata_by_mid( 'post', (int) $meta_id );
		$current_key = is_object( $meta ) ? (string) $meta->meta_key : '';
		$key         = false === $meta_key || null === $meta_key
			? ( is_object( $meta ) ? (string) $meta->meta_key : '' )
			: (string) $meta_key;

		return $digitalogic_is_sku_key( $current_key ) || $digitalogic_is_sku_key( $key ) ? false : $check;
	},
	PHP_INT_MAX,
	4
);
add_filter(
	'delete_post_metadata_by_mid',
	static function ( $check, $meta_id ) use ( $digitalogic_is_sku_key ) {
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		return is_object( $meta ) && $digitalogic_is_sku_key( $meta->meta_key ) ? false : $check;
	},
	PHP_INT_MAX,
	2
);
add_filter(
	'wp_insert_post_data',
	static function ( $data, $postarr, $unsanitized_postarr, $update ) use ( $digitalogic_is_sku_key ) {
		unset( $unsanitized_postarr );
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if (
			! $update
			|| $post_id <= 0
			|| in_array( (string) get_post_type( $post_id ), array( 'product', 'product_variation' ), true )
			|| ! in_array( (string) ( $data['post_type'] ?? '' ), array( 'product', 'product_variation' ), true )
		) {
			return $data;
		}
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $meta_key ) {
			if ( $digitalogic_is_sku_key( $meta_key ) ) {
				throw new RuntimeException( 'The canonical SKU guard is unavailable; product promotion was not saved.' );
			}
		}

		return $data;
	},
	PHP_INT_MAX,
	4
);

unset(
	$digitalogic_plugin_dir,
	$digitalogic_lock_file,
	$digitalogic_guard_file,
	$digitalogic_is_sku_key,
	$digitalogic_deny_sku_metadata
);
