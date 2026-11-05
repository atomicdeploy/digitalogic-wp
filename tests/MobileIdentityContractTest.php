<?php

use Digitalogic\Laravel\Services\MobileIdentityStore;
use Digitalogic\Laravel\Services\MobileGoogleIdentity;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileGoogleIdentity.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-smsir-integration.php';

final class MobileIdentityContractTest extends TestCase {
	public function test_linking_requires_recent_explicit_proof_not_session_age(): void {
		self::assertFalse( MobileIdentityStore::freshProof( array( 'created_at' => 1000 ), 1000 ) );
		self::assertFalse( MobileIdentityStore::freshProof( array( 'auth_method' => 'legacy', 'verified_at' => 1000 ), 1000 ) );
		self::assertFalse( MobileIdentityStore::freshProof( array( 'auth_method' => 'google', 'verified_at' => 399 ), 1000 ) );
		self::assertFalse( MobileIdentityStore::freshProof( array( 'auth_method' => 'otp', 'verified_at' => 1031 ), 1000 ) );
		self::assertTrue( MobileIdentityStore::freshProof( array( 'auth_method' => 'google', 'verified_at' => 400 ), 1000 ) );
		self::assertTrue( MobileIdentityStore::freshProof( array( 'auth_method' => 'otp', 'verified_at' => 1000 ), 1000 ) );
		foreach ( array( 'phone/request', 'phone/verify', 'reauth/google' ) as $endpoint ) {
			$path = '/app-api/account/' . $endpoint;
			$route = Digitalogic_Mobile_API::route( $path );
			self::assertSame( '/_digitalogic/mobile/account/' . $endpoint, $route );
			self::assertSame( array( 'POST' ), Digitalogic_Mobile_API::methods( $route ) );
		}
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/account/merge' ) );
	}
	public function test_sms_template_allows_only_a_single_fixed_otp_mapping(): void {
		self::assertSame( 'OTP', Digitalogic_Smsir_Integration::native_otp_parameter( 'OTP:%OTP%' ) );
		self::assertSame( 'CODE', Digitalogic_Smsir_Integration::native_otp_parameter( ' CODE: %OTP% ' ) );
		foreach ( array( 'OTP:%MESSAGE%', 'OTP:%OTP%;name:other', array( 'OTP' ), '', null ) as $value ) {
			self::assertNull( Digitalogic_Smsir_Integration::native_otp_parameter( $value ) );}
	}
	public function test_phone_normalization_is_strict_and_locale_aware(): void {
		foreach ( array( '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '0912 345 6789', '+989123456789', '00989123456789', '989123456789' ) as $phone ) {
			self::assertSame( '+989123456789', MobileIdentityStore::phone( $phone ) );
		}
		foreach ( array( '0912345678', '+441234567890', '09123456789 extra', '9e10', '091234567890', '' ) as $phone ) {
			self::assertNull( MobileIdentityStore::phone( $phone ) );}
	}

	public function test_route_method_bounds_exclude_privileged_or_other_accounts(): void {
		self::assertSame( '/_digitalogic/mobile/auth/google', Digitalogic_Mobile_API::route( '/app-api/auth/google' ) );
		self::assertSame( array( 'POST' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/auth/otp/request' ) );
		self::assertSame( array( 'GET', 'HEAD', 'PUT' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/account/profile' ) );
		self::assertSame( array( 'GET', 'HEAD' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/account/orders' ) );
		foreach ( array( '/app-api/account/123/profile', '/app-api/auth/admin', '/app-api/account/orders/123', '/app-api/auth/../panel' ) as $path ) {
			self::assertNull( Digitalogic_Mobile_API::route( $path ) );}
	}

	public function test_google_verification_requires_signature_audience_issuer_expiry_and_verified_identity(): void {
		$config = tempnam( sys_get_temp_dir(), 'dgl-openssl-' );
		file_put_contents( $config, "[req]\ndistinguished_name=req_distinguished_name\n[req_distinguished_name]\n" );
		try {
			$key = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
					'config'           => $config,
				)
			);
			openssl_pkey_export( $key, $private, null, array( 'config' => $config ) );
		} finally {
			unlink( $config ); }
		$details = openssl_pkey_get_details( $key );
		$encode  = static fn( $value )=>rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
		$jwks    = array(
			'keys' => array(
				array(
					'kty' => 'RSA',
					'alg' => 'RS256',
					'use' => 'sig',
					'kid' => 'sandbox',
					'n'   => $encode( $details['rsa']['n'] ),
					'e'   => $encode( $details['rsa']['e'] ),
				),
			),
		);
		$claims  = array(
			'iss'            => 'https://accounts.google.com',
			'aud'            => 'web-client',
			'azp'            => 'android-client',
			'iat'            => time(),
			'exp'            => time() + 300,
			'email_verified' => true,
			'email'          => 'fixture@example.test',
			'sub'            => '123456789',
		);
		$sign    = static fn( $value )=>JWT::encode( $value, $private, 'RS256', 'sandbox' );
		self::assertSame( '123456789', MobileGoogleIdentity::verify( $sign( $claims ), $jwks, 'web-client', 'android-client' )['sub'] );
		foreach ( array( array( 'aud' => 'other-client' ), array( 'iss' => 'https://attacker.example' ), array( 'azp' => 'other-android' ), array( 'email_verified' => false ), array( 'exp' => time() - 1 ), array( 'iat' => time() - 3700 ), array( 'sub' => 'invalid' ) ) as $override ) {
			try {
				MobileGoogleIdentity::verify( $sign( array_merge( $claims, $override ) ), $jwks, 'web-client', 'android-client' );
				self::fail( 'Invalid identity was accepted.' );
			} catch ( UnexpectedValueException $expected ) {
				self::assertNotEmpty( $expected->getMessage() );}
		}
		$token    = $sign( $claims );
		$parts    = explode( '.', $token );
		$parts[2] = str_repeat( 'A', strlen( $parts[2] ) );
		$this->expectException( UnexpectedValueException::class );
		MobileGoogleIdentity::verify( implode( '.', $parts ), $jwks, 'web-client', 'android-client' );
	}
}
