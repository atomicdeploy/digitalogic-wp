<?php
/**
 * Release provenance contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Keep the installable plugin version and changelog release identity aligned. */
final class ReleaseVersionTest extends TestCase {

	/** Header, runtime constant, and changelog must identify the same patch release. */
	public function test_plugin_release_version_is_consistent_and_newer_than_production_baseline(): void {
		$plugin    = file_get_contents( dirname( __DIR__ ) . '/digitalogic.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$changelog = file_get_contents( dirname( __DIR__ ) . '/CHANGELOG.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $plugin );
		$this->assertIsString( $changelog );
		$this->assertSame( 1, preg_match( '/^[ \t]*\*[ \t]+Version:[ \t]*([^\s]+)/m', $plugin, $header ) );
		$this->assertSame( 1, preg_match( "/define\([ \t]*'DIGITALOGIC_VERSION',[ \t]*'([^']+)'[ \t]*\)/", $plugin, $constant ) );
		$this->assertSame( $header[1], $constant[1] );
		$this->assertTrue( version_compare( $header[1], '1.8.3', '>' ) );
		$this->assertMatchesRegularExpression(
			'/^## \[' . preg_quote( $header[1], '/' ) . '\](?: - \d{4}-\d{2}-\d{2})?$/m',
			$changelog
		);
	}
}
