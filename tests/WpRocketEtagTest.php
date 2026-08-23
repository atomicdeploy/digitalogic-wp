<?php
/**
 * WP Rocket ETag rule contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-wp-rocket-etag.php';

/** Verify WP Rocket's generated ETag rules retain the pricing revision tag. */
final class WpRocketEtagTest extends TestCase {

	/** Reset the isolated hook registry. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_filters']['rocket_htaccess_etag'] = array();
	}

	/** The integration registers one late, one-argument generator filter. */
	public function test_registers_the_wp_rocket_generator_filter(): void {
		Digitalogic_WP_Rocket_ETag::init();

		$filters = $GLOBALS['digitalogic_test_filters']['rocket_htaccess_etag'];

		$this->assertCount( 1, $filters );
		$this->assertSame( array( Digitalogic_WP_Rocket_ETag::class, 'scope_etag_removal' ), $filters[0]['callback'] );
		$this->assertSame( 100, $filters[0]['priority'] );
		$this->assertSame( 1, $filters[0]['accepted_args'] );
	}

	/** The exact stock directive is replaced without changing FileETag. */
	public function test_exact_stock_rule_is_scoped_and_file_etag_policy_is_preserved(): void {
		$stock              = '# FileETag None is not enough for every server.' . PHP_EOL
			. '<IfModule mod_headers.c>' . PHP_EOL
			. 'Header unset ETag' . PHP_EOL
			. '</IfModule>' . PHP_EOL . PHP_EOL
			. '# Static-file policy.' . PHP_EOL
			. 'FileETag None' . PHP_EOL;
		$expected_directive = 'Header unset ETag "expr=%{THE_REQUEST} !~ m#\\\\s/+wp-json/digitalogic/pricing/sync/revision(?:[/?\\\\s])#"';

		$filtered = Digitalogic_WP_Rocket_ETag::scope_etag_removal( $stock );

		$this->assertStringContainsString( $expected_directive . PHP_EOL, $filtered );
		$this->assertStringNotContainsString( 'Header unset ETag' . PHP_EOL, $filtered );
		$this->assertStringContainsString( 'FileETag None' . PHP_EOL, $filtered );
		$this->assertSame( 1, substr_count( $filtered, $expected_directive ) );
		$this->assertStringNotContainsString( '%{REQUEST_URI}', $filtered );
	}

	/** Unexpected or ambiguous upstream rule shapes remain untouched. */
	public function test_stock_shape_mismatch_is_unchanged(): void {
		$missing   = "# No stock ETag directive.\nFileETag None\n";
		$duplicate = 'Header unset ETag' . PHP_EOL . 'Header unset ETag' . PHP_EOL . 'FileETag None' . PHP_EOL;

		$this->assertSame( $missing, Digitalogic_WP_Rocket_ETag::scope_etag_removal( $missing ) );
		$this->assertSame( $duplicate, Digitalogic_WP_Rocket_ETag::scope_etag_removal( $duplicate ) );
	}
}
