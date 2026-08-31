<?php
/**
 * Plugin Name: Digitalogic WooCommerce SKU Guard Loader
 * Description: Loads the canonical Digitalogic SKU guard before ordinary plugins and fails closed if its implementation is unavailable.
 * Version: 2.0.1
 * Author: Digitalogic
 */

defined( 'ABSPATH' ) || exit;

$digitalogic_sku_guard_file = WP_PLUGIN_DIR . '/digitalogic-wp/includes/class-digitalogic-sku-guard.php';
if ( is_readable( $digitalogic_sku_guard_file ) ) {
	require_once $digitalogic_sku_guard_file;
	Digitalogic_SKU_Guard::instance();
	return;
}

// A missing implementation must disable SKU creation/changes, not silently weaken integrity.
error_log( '[Digitalogic SKU Guard] Canonical implementation missing; SKU writes are fail-closed.' );
add_filter( 'wc_product_pre_has_unique_sku', '__return_false', PHP_INT_MAX, 3 );

$digitalogic_block_sku_key_write = static function ( $check, $object_id, $meta_key ) {
	unset( $object_id );
	return '_sku' === (string) $meta_key ? false : $check;
};
add_filter( 'add_post_metadata', $digitalogic_block_sku_key_write, PHP_INT_MAX, 5 );
add_filter( 'update_post_metadata', $digitalogic_block_sku_key_write, PHP_INT_MAX, 5 );

$digitalogic_block_sku_mid_write = static function ( $check, $meta_id, $meta_value, $meta_key ) {
	unset( $meta_value );
	$meta = get_metadata_by_mid( 'post', (int) $meta_id );
	$key  = false === $meta_key || null === $meta_key
		? ( is_object( $meta ) ? (string) $meta->meta_key : '' )
		: (string) $meta_key;
	return '_sku' === $key ? false : $check;
};
add_filter( 'update_post_metadata_by_mid', $digitalogic_block_sku_mid_write, PHP_INT_MAX, 4 );
