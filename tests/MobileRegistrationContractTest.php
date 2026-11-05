<?php
use Digitalogic\Laravel\Services\MobileRegistrationStore as Store;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRegistrationStore.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';
final class MobileRegistrationContractTest extends TestCase {
	public function test_proof_and_cached_session_encryption_are_authenticated_and_separated(): void {
		$cipher = Store::seal( 'fixture-text-not-a-phone', 'phone' );
		self::assertSame( 'fixture-text-not-a-phone', Store::open( $cipher, 'phone' ) );
		self::assertNull( Store::open( $cipher, 'session' ) );
		$raw     = base64_decode( $cipher );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );
		self::assertNull( Store::open( base64_encode( $raw ), 'phone' ) );
		self::assertNull( Store::open( 'not-base64', 'phone' ) );
		self::assertNotSame( $cipher, Store::seal( 'fixture-text-not-a-phone', 'phone' ) );
	}
	public function test_registration_route_is_bounded_and_post_only(): void {
		self::assertSame( '/_digitalogic/mobile/auth/register', Digitalogic_Mobile_API::route( '/app-api/auth/register' ) );
		self::assertSame( array( 'POST' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/auth/register' ) );
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/auth/register/admin' ) );
	}
}
