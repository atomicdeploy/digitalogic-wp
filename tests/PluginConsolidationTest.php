<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Single-plugin ownership and public terminology tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

final class PluginConsolidationTest extends TestCase {

	/** Every former runtime must have an explicit canonical owner in the main plugin. */
	public function test_consolidation_manifest_maps_every_former_runtime(): void {
		$manifest = file_get_contents( dirname( __DIR__ ) . '/docs/plugin-consolidation.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $manifest );

		$former_runtimes = array(
			'digitalogic-product-experience',
			'digitalogic-admin',
			'digitalogic-viewer-bridge',
			'digitalogic-currency-storefront-freshness',
			'digitalogic-http-resilience',
			'digitalogic-human-contacts',
			'digitalogic-patris-catalog-backfill',
			'digitalogic-patris-incomplete-alert-adapter',
			'digitalogic-price-updated-display',
			'digitalogic-shatel-sms',
			'digitalogic-smsir',
			'digitalogic-woo-sku-guard',
			'organizer-login-proxy',
			'wp-rocket-cloudflare-intkey-fix',
		);

		foreach ( $former_runtimes as $runtime ) {
			$this->assertStringContainsString( '`' . $runtime . '`', $manifest );
		}
	}

	/** The repository must no longer ship deployable standalone MU PHP files. */
	public function test_repository_has_no_standalone_mu_php_files(): void {
		$root  = dirname( __DIR__ );
		$files = array_merge(
			glob( $root . '/ops/wordpress-mu/*.php' ) ?: array(),
			glob( $root . '/scripts/mu-plugins/*.php' ) ?: array()
		);
		$this->assertSame( array(), $files );
	}

	/** Accounting terminology must not leak through browser-facing CSS or JS. */
	public function test_public_assets_do_not_expose_patris_code_names(): void {
		$root = dirname( __DIR__ ) . '/assets';
		foreach ( array( 'css', 'js' ) as $type ) {
			foreach ( glob( $root . '/' . $type . '/*.' . $type ) ?: array() as $file ) {
				$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
				$this->assertIsString( $source );
				$this->assertStringNotContainsString( 'patris-code', strtolower( $source ), $file );
			}
		}
	}

	/** Variable parents must never render their aggregate model metadata. */
	public function test_variable_product_experience_suppresses_parent_model_metadata(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-product-experience.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $source );
		$this->assertSame( 2, preg_match_all( "/\\\$model\\s*=\\s*\\\$product->is_type\\( 'variable' \\)/", $source ) );
		$this->assertStringContainsString( 'data-digitalogic-context-attribute', $source );
	}

	/** Private runtime routing can survive removal of legacy MU constants. */
	public function test_private_integrations_have_non_autoloaded_runtime_option_fallbacks(): void {
		$shatel = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-shatel-sms.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$alerts = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-patris-incomplete-alert-adapter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $shatel );
		$this->assertIsString( $alerts );
		$this->assertStringContainsString( "get_option( 'digitalogic_shatel_sms_runtime'", $shatel );
		$this->assertStringContainsString( "get_option( 'digitalogic_patris_alert_adapter_runtime'", $alerts );
	}
}
