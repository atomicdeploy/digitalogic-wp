<?php
/**
 * Plugin Name: Digitalogic Patris catalog backfill bootstrap
 * Description: Loads the shared Digitalogic implementation; contains no materialization logic.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$digitalogic_backfill_file = WP_PLUGIN_DIR . '/digitalogic-wp/includes/class-patris-catalog-backfill.php';
if ( is_file( $digitalogic_backfill_file ) ) {
	require_once $digitalogic_backfill_file;
	Digitalogic_Patris_Catalog_Backfill::instance();
}
unset( $digitalogic_backfill_file );
