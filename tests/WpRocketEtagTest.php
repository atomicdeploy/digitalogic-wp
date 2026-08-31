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
		$GLOBALS['digitalogic_test_filters']['rocket_htaccess_etag']        = array();
		$GLOBALS['digitalogic_test_filters']['rocket_htaccess_mod_deflate'] = array();

		$GLOBALS['digitalogic_test_action_callbacks']['digitalogic_excel_pricing_settings_updated'] = array();

		$GLOBALS['digitalogic_test_rocket_clean_domain_calls'] = 0;
		$GLOBALS['digitalogic_test_rocket_clean_domain_throw'] = false;
	}

	/** The integration registers one late, one-argument generator filter. */
	public function test_registers_the_wp_rocket_generator_filter(): void {
		Digitalogic_WP_Rocket_ETag::init();

		$filters = $GLOBALS['digitalogic_test_filters']['rocket_htaccess_etag'];

		$this->assertCount( 1, $filters );
		$this->assertSame( array( Digitalogic_WP_Rocket_ETag::class, 'scope_etag_removal' ), $filters[0]['callback'] );
		$this->assertSame( 100, $filters[0]['priority'] );
		$this->assertSame( 1, $filters[0]['accepted_args'] );

		$deflate_filters = $GLOBALS['digitalogic_test_filters']['rocket_htaccess_mod_deflate'];
		$this->assertCount( 1, $deflate_filters );
		$this->assertSame( array( Digitalogic_WP_Rocket_ETag::class, 'disable_pricing_sync_compression' ), $deflate_filters[0]['callback'] );
		$this->assertSame( 100, $deflate_filters[0]['priority'] );
		$this->assertSame( 1, $deflate_filters[0]['accepted_args'] );

		$actions = $GLOBALS['digitalogic_test_action_callbacks']['digitalogic_excel_pricing_settings_updated'];
		$this->assertCount( 1, $actions );
		$this->assertSame( array( Digitalogic_WP_Rocket_ETag::class, 'purge_currency_page_cache' ), $actions[0]['callback'] );
		$this->assertSame( 30, $actions[0]['priority'] );
		$this->assertSame( 1, $actions[0]['accepted_args'] );
	}

	/** Only a verified canonical commit purges cached storefront markup. */
	public function test_canonical_currency_commit_purges_domain_and_failure_is_non_blocking(): void {
		$valid = array( 'state_revision' => 'sha256:' . str_repeat( 'c', 64 ) );

		Digitalogic_WP_Rocket_ETag::purge_currency_page_cache( array( 'state_revision' => 'invalid' ) );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_rocket_clean_domain_calls'] );

		Digitalogic_WP_Rocket_ETag::purge_currency_page_cache( $valid );
		$this->assertSame( 1, $GLOBALS['digitalogic_test_rocket_clean_domain_calls'] );

		$GLOBALS['digitalogic_test_rocket_clean_domain_throw'] = true;
		Digitalogic_WP_Rocket_ETag::purge_currency_page_cache( $valid );
		$this->assertSame( 2, $GLOBALS['digitalogic_test_rocket_clean_domain_calls'] );
	}

	/** The exact stock directive is replaced without changing FileETag. */
	public function test_exact_stock_rule_is_scoped_and_file_etag_policy_is_preserved(): void {
		$stock              = '# FileETag None is not enough for every server.' . PHP_EOL
			. '<IfModule mod_headers.c>' . PHP_EOL
			. 'Header unset ETag' . PHP_EOL
			. '</IfModule>' . PHP_EOL . PHP_EOL
			. '# Static-file policy.' . PHP_EOL
			. 'FileETag None' . PHP_EOL;
		$expected_directive = 'Header unset ETag "expr=%{THE_REQUEST} !~ m#\\\\s/+wp-json/digitalogic/pricing/sync/(?:revision|snapshots|builds)(?:[/?\\\\s])#"';

		$filtered = Digitalogic_WP_Rocket_ETag::scope_etag_removal( $stock );

		$this->assertStringContainsString( $expected_directive . PHP_EOL, $filtered );
		$this->assertStringNotContainsString( 'Header unset ETag' . PHP_EOL, $filtered );
		$this->assertStringContainsString( 'FileETag None' . PHP_EOL, $filtered );
		$this->assertSame( 1, substr_count( $filtered, $expected_directive ) );
		$this->assertStringNotContainsString( '%{REQUEST_URI}', $filtered );
	}

	/** Pricing sync is excluded from Apache compression before DEFLATE runs. */
	public function test_pricing_sync_compression_is_disabled_once_for_the_full_contract(): void {
		$stock     = '<IfModule mod_deflate.c>' . PHP_EOL
			. 'SetOutputFilter DEFLATE' . PHP_EOL
			. '</IfModule>' . PHP_EOL;
		$directive = 'SetEnvIfNoCase Request_URI "^/wp-json/digitalogic/pricing/sync/(?:revision|snapshots|builds)(?:[/?]|$)" no-gzip dont-vary';

		$filtered = Digitalogic_WP_Rocket_ETag::disable_pricing_sync_compression( $stock );

		$this->assertStringContainsString( $directive, $filtered );
		$this->assertLessThan( strpos( $filtered, 'SetOutputFilter DEFLATE' ), strpos( $filtered, $directive ) );
		$this->assertSame( 1, substr_count( $filtered, $directive ) );
		$this->assertSame( $filtered, Digitalogic_WP_Rocket_ETag::disable_pricing_sync_compression( $filtered ) );
	}

	/** Unexpected or ambiguous upstream rule shapes remain untouched. */
	public function test_stock_shape_mismatch_is_unchanged(): void {
		$missing   = "# No stock ETag directive.\nFileETag None\n";
		$duplicate = 'Header unset ETag' . PHP_EOL . 'Header unset ETag' . PHP_EOL . 'FileETag None' . PHP_EOL;

		$this->assertSame( $missing, Digitalogic_WP_Rocket_ETag::scope_etag_removal( $missing ) );
		$this->assertSame( $duplicate, Digitalogic_WP_Rocket_ETag::scope_etag_removal( $duplicate ) );

		$missing_deflate   = "# No mod_deflate block.\n";
		$duplicate_deflate = '<IfModule mod_deflate.c>' . PHP_EOL . '<IfModule mod_deflate.c>' . PHP_EOL;
		$this->assertSame( $missing_deflate, Digitalogic_WP_Rocket_ETag::disable_pricing_sync_compression( $missing_deflate ) );
		$this->assertSame( $duplicate_deflate, Digitalogic_WP_Rocket_ETag::disable_pricing_sync_compression( $duplicate_deflate ) );
	}
}
