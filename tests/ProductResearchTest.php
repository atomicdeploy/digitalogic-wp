<?php
/**
 * Research normalization and authorization contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-digitalogic-product-research.php';

/** Research commands and contract verification. */
final class ProductResearchTest extends TestCase {
	/** Preserve seller tenants while removing trivial domain spelling differences. */
	public function test_domains_deduplicate_www_case_and_trailing_dot_without_merging_tenants(): void {
		$this->assertSame( 'example.com', Digitalogic_Product_Research::domain( 'HTTPS://WWW.Example.COM./p' ) );
		$this->assertSame( 'shop123.taobao.com', Digitalogic_Product_Research::domain( 'https://shop123.taobao.com/p' ) );
		$this->assertNotSame( Digitalogic_Product_Research::domain( 'https://shop123.taobao.com/' ), Digitalogic_Product_Research::domain( 'https://shop456.taobao.com/' ) );
	}

	/** Keep variant identity in canonical URLs. */
	public function test_listing_normalization_preserves_variant_keys_and_removes_tracking(): void {
		$this->assertSame( 'https://example.com/p?id=5&sku=7', Digitalogic_Product_Research::url( 'HTTPS://Example.COM/p?utm_source=x&sku=7&id=5&srsltid=tracker#fragment' ) );
	}

	/** Reject local destinations and credential-bearing URLs. */
	public function test_unsafe_source_urls_are_rejected(): void {
		foreach ( array( 'file:///etc/passwd', 'http://localhost/p', 'http://127.0.0.1/p', 'https://user:secret@example.com/p', 'https://example.com:8080/p', 'https://router.local/p' ) as $url ) {
			try {
				Digitalogic_Product_Research::url( $url );
				$this->fail( 'Unsafe source accepted.' );
			} catch ( RuntimeException $error ) {
				$this->assertNotEmpty( $error->getMessage() );
			}
		}
	}

	/** Require the administrator boundary. */
	public function test_non_administrators_cannot_read_research(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = false;
		$this->expectException( RuntimeException::class );
		Digitalogic_Product_Research::authorize();
	}
}
