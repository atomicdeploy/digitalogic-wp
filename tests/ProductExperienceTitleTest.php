<?php
/**
 * Product-title typography tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-product-experience.php';

/** Verify conditional Latin typography for product titles. */
final class ProductExperienceTitleTest extends TestCase {

	/** English letters, ASCII digits, and symbols use the Latin title style. */
	public function test_accepts_english_only_titles(): void {
		$titles = array(
			'TSL2561',
			'Raspberry Pi 5 Model B+',
			'ESP32-S3-WROOM-1 (N8R8)',
			'LCD 20×2',
			'OLED 0.96"',
		);

		foreach ( $titles as $title ) {
			$this->assertTrue( Digitalogic_Product_Experience::has_english_only_title( $title ), $title );
		}
	}

	/** Persian, Arabic, other alphabets, and empty titles retain the default style. */
	public function test_rejects_titles_with_non_english_letters(): void {
		$titles = array(
			'برد Raspberry Pi 4 Model B',
			'حساس TSL2561',
			'café 123',
			'Датчик TSL2561',
			'',
		);

		foreach ( $titles as $title ) {
			$this->assertFalse( Digitalogic_Product_Experience::has_english_only_title( $title ), $title );
		}
	}

	/** The English-only selector must override the inherited FaNum-capable face. */
	public function test_english_only_title_css_uses_latin_typography(): void {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/product-experience.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $css );
		$this->assertStringContainsString( '.dgl-product-title--english-only', $css );
		$this->assertStringContainsString( 'font-variant-numeric: lining-nums', $css );
		$this->assertStringContainsString( 'font-family: system-ui', $css );
	}
}
