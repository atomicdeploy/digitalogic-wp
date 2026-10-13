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
	/** Keep explicit foreign offers and Iranian units distinct without conversion. */
	public function test_observed_prices_preserve_currency_and_unknowns(): void {
		$method = new ReflectionMethod( Digitalogic_Product_Research::class, 'price' );
		foreach ( array( 'PLN', 'IRR', 'IRT', 'HKD', 'AFN' ) as $currency ) {
			$this->assertSame( array( '97.00', $currency ), $method->invoke( null, '97.00', $currency ) );
		}
		$this->assertSame( array( null, null ), $method->invoke( null, null, 'PLN' ) );
		$this->expectException( RuntimeException::class );
		$method->invoke( null, '97.00', 'zł' );
	}

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

	/** Child code changes invalidate research even when the parent stays unchanged. */
	public function test_child_code_changes_invalidate_the_parent_fingerprint(): void {
		$GLOBALS['digitalogic_test_posts'][9901] = array(
			'post_type'    => 'product',
			'product_type' => 'variable',
			'post_title'   => 'Research parent',
			'meta'         => array( '_sku' => 'PARENT-9901' ),
		);
		$GLOBALS['digitalogic_test_posts'][9902] = array(
			'post_type'   => 'product_variation',
			'post_parent' => 9901,
			'meta'        => array( '_sku' => 'CHILD-A' ),
		);
		unset( $GLOBALS['digitalogic_test_wc_products'][9901], $GLOBALS['digitalogic_test_wc_products'][9902] );
		$before = Digitalogic_Product_Research::identity( 9901 );
		$GLOBALS['digitalogic_test_posts'][9902]['meta']['_sku'] = 'CHILD-B';
		unset( $GLOBALS['digitalogic_test_wc_products'][9902] );
		$this->assertNotSame( $before, Digitalogic_Product_Research::identity( 9901 ) );
		unset( $GLOBALS['digitalogic_test_posts'][9901], $GLOBALS['digitalogic_test_posts'][9902], $GLOBALS['digitalogic_test_wc_products'][9901], $GLOBALS['digitalogic_test_wc_products'][9902] );
	}

	/** Reject a foreign child before any database mutation. */
	public function test_external_variation_cannot_be_attached_to_another_parent(): void {
		$method = new ReflectionMethod( Digitalogic_Product_Research::class, 'save_result' );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'External variant must belong to the exact selected parent.' );
		$method->invoke(
			null,
			101,
			array(
				'variation_id' => 999999,
				'url'          => 'https://example.com/product',
			)
		);
	}
}
