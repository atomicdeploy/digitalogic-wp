<?php
/**
 * Native RegistrationContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileRegistrationStore as Store;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRegistrationStore.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';
/** Regression coverage for the native adapter. */
final class MobileRegistrationContractTest extends TestCase {
	/**
	 * Verify proof and cached session encryption are authenticated and separated.
	 *
	 * @return void Native adapter result.
	 */
	public function test_proof_and_cached_session_encryption_are_authenticated_and_separated(): void {
		$cipher = Store::seal( 'fixture-text-not-a-phone', 'phone' );
		self::assertSame( 'fixture-text-not-a-phone', Store::open( $cipher, 'phone' ) );
		self::assertNull( Store::open( $cipher, 'session' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary proof/JWK encoding; no executable code is decoded.
		$raw     = base64_decode( $cipher );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary proof/JWK encoding; no executable code is decoded.
		self::assertNull( Store::open( base64_encode( $raw ), 'phone' ) );
		self::assertNull( Store::open( 'not-base64', 'phone' ) );
		self::assertNotSame( $cipher, Store::seal( 'fixture-text-not-a-phone', 'phone' ) );
	}
	/**
	 * Verify registration route is bounded and post only.
	 *
	 * @return void Native adapter result.
	 */
	public function test_registration_route_is_bounded_and_post_only(): void {
		self::assertSame( '/_digitalogic/mobile/auth/register', Digitalogic_Mobile_API::route( '/app-api/auth/register' ) );
		self::assertSame( array( 'POST' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/auth/register' ) );
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/auth/register/admin' ) );
	}
}
