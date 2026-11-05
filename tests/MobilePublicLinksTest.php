<?php
/**
 * Reject unsafe and ambiguous public navigation URLs.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileStorefrontInfo;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileStorefrontInfo.php';

/** Public URL equivalence and action isolation. */
final class MobilePublicLinksTest extends TestCase {
	/**
	 * Encoded Persian paths compare without permitting query/action smuggling.
	 *
	 * @return void Native adapter result.
	 */
	public function test_only_exact_same_host_read_paths_are_normalized(): void {
		self::assertSame( '/product/برد/', MobileStorefrontInfo::public_path( 'https://digitalogic.ir/product/%D8%A8%D8%B1%D8%AF/', 'digitalogic.ir' ) );
		self::assertSame( '/product/nano/', MobileStorefrontInfo::public_path( 'https://digitalogic.ir/product/nano', 'digitalogic.ir' ) );
		foreach ( array(
			'http://digitalogic.ir/product/nano/',
			'https://digitalogic.ir.attacker.example/product/nano/',
			'https://digitalogic.ir:443/product/nano/',
			'https://someone@digitalogic.ir/product/nano/',
			'https://digitalogic.ir/product/nano/?add-to-cart=1',
			'https://digitalogic.ir/product/nano/#callback',
			'https://digitalogic.ir/product/../checkout/',
			'https://digitalogic.ir/product/%2e%2e/checkout/',
			'https://digitalogic.ir/product/nano%2fcheckout/',
			'https://digitalogic.ir/product/nano%250a/',
			'https://digitalogic.ir/product/nano%0a/',
			'https://digitalogic.ir//product/nano/',
		) as $url ) {
			self::assertNull( MobileStorefrontInfo::public_path( $url, 'digitalogic.ir' ) );
		}
	}
}
