<?php
/**
 * Import reviewed chip specifications through the installed plugin.
 *
 * @package Digitalogic
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/** CLI entry point for the manufacturer reference store. */
final class Digitalogic_Chip_Reference_CLI {
	/**
	 * Import one bundled, reviewed manufacturer reference.
	 *
	 * ## OPTIONS
	 *
	 * <chip>
	 * : Manufacturer and part slug, such as microchip-enc28j60.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic chip provision microchip-enc28j60 --user=<administrator-id>
	 *
	 * @param string[] $args Positional chip slug.
	 */
	public static function provision( array $args ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Use an existing administrator.' );
		}

		$manifests = array( 'microchip-enc28j60' => __DIR__ . '/chip-references/enc28j60.php' );
		$slug      = $args[0] ?? '';
		if ( ! isset( $manifests[ $slug ] ) ) {
			WP_CLI::error( 'Unknown chip slug. Supported: ' . implode( ', ', array_keys( $manifests ) ) . '.' );
		}

		try {
			$reference = Digitalogic_Chip_Reference::provision( require $manifests[ $slug ] );
			WP_CLI::success( sprintf( 'Stored %d sourced specifications for %s.', count( $reference['specifications'] ), $reference['slug'] ) );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
	}
}

WP_CLI::add_command( 'digitalogic chip provision', array( Digitalogic_Chip_Reference_CLI::class, 'provision' ) );
