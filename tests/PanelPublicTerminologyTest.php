<?php
/**
 * Public panel and report terminology tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Protect customer-facing labels while retaining legacy machine keys.
 */
final class PanelPublicTerminologyTest extends TestCase {

	/**
	 * Panel dictionaries must render source-neutral labels in both languages.
	 */
	public function test_panel_translation_output_is_source_neutral(): void {
		$panel = Digitalogic_Panel::instance();

		foreach ( array( 'translations_en', 'translations_fa' ) as $method_name ) {
			$method = new ReflectionMethod( Digitalogic_Panel::class, $method_name );
			$translations = $method->invoke( $panel );
			$rendered = implode(
				"\n",
				array_map(
					'strval',
					array_filter( $translations, 'is_scalar' )
				)
			);

			$this->assertStringNotContainsString( 'Patris', $rendered );
			$this->assertStringNotContainsString( 'پاتریس', $rendered );
		}

		$english = new ReflectionMethod( Digitalogic_Panel::class, 'translations_en' );
		$english = $english->invoke( $panel );
		$this->assertSame( 'Source catalog', $english['sourceCatalog'] );
		$this->assertSame( 'Product catalog sync', $english['patrisSync'] );

		$persian = new ReflectionMethod( Digitalogic_Panel::class, 'translations_fa' );
		$persian = $persian->invoke( $panel );
		$this->assertSame( 'منبع کالا', $persian['sourceCatalog'] );
		$this->assertSame( 'همگام‌سازی کاتالوگ کالا', $persian['patrisSync'] );
	}

	/**
	 * Report output labels must describe the source without exposing its brand.
	 */
	public function test_report_category_output_is_source_neutral(): void {
		$engine = Digitalogic_Report_Engine::instance();
		$method = new ReflectionMethod( Digitalogic_Report_Engine::class, 'category_definitions' );
		$definitions = $method->invoke( $engine );

		$this->assertSame( 'In source but missing in WooCommerce', $definitions['missing_in_woocommerce'][0] );
		$this->assertSame( 'In WooCommerce but missing in source', $definitions['missing_in_patris'][0] );
		$this->assertStringNotContainsString( 'Patris', wp_json_encode( $definitions ) );
	}
}
