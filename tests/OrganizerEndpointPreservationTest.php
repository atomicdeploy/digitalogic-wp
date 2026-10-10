<?php
/**
 * Organizer endpoint package-preservation tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Keep the production Organizer route inside every replacement package. */
final class OrganizerEndpointPreservationTest extends TestCase {

	/** The canonical class keeps its exact protected route and capability. */
	public function test_endpoint_contract_is_available_from_the_canonical_package(): void {
		$this->assertTrue( class_exists( Digitalogic_Organizer_Products_Endpoint::class ) );
		$this->assertSame( 'digitalogic_read_organizer_products', Digitalogic_Organizer_Products_Endpoint::CAPABILITY );
		$this->assertSame( '/digitalogic/integration/organizer-products', Digitalogic_Organizer_Products_Endpoint::ROUTE );
	}

	/** The plugin bootstrap must load the adapter, not a server-only patch. */
	public function test_plugin_bootstrap_requires_the_packaged_loader(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/digitalogic.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertIsString( $source );
		$this->assertStringContainsString( "includes/digitalogic-organizer-products-loader.php';", $source );
		$this->assertFileExists( dirname( __DIR__ ) . '/includes/class-digitalogic-organizer-products-endpoint.php' );
		$this->assertFileExists( dirname( __DIR__ ) . '/includes/digitalogic-organizer-products-loader.php' );
		$loader = file_get_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
			dirname( __DIR__ ) . '/includes/digitalogic-organizer-products-loader.php'
		);
		$this->assertStringContainsString( "get_role( 'digitalogic_viewer_service' )", $loader );
		$this->assertStringContainsString( 'Digitalogic_Organizer_Products_Endpoint::CAPABILITY', $loader );
	}
}
