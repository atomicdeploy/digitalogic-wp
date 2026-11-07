<?php
/**
 * Provision a reviewed chip reference with wp eval-file.
 *
 * @package Digitalogic
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	WP_CLI::error( 'Use an existing administrator.' );
}

if ( array( 'microchip-enc28j60' ) !== $args ) {
	WP_CLI::error( 'Pass a supported chip slug: microchip-enc28j60.' );
}

try {
	$reference = Digitalogic_Chip_Reference::provision( require __DIR__ . '/enc28j60-chip-reference.php' );
	WP_CLI::success( sprintf( 'Stored %d sourced specifications for %s.', count( $reference['specifications'] ), $reference['slug'] ) );
} catch ( Throwable $error ) {
	WP_CLI::error( $error->getMessage() );
}
