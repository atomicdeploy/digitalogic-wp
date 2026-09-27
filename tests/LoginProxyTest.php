<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Generic authenticated login-proxy tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'DIGITALOGIC_LOGIN_PROXY_CONFIG' ) ) {
	define( 'DIGITALOGIC_LOGIN_PROXY_CONFIG', __DIR__ . '/fixtures/login-proxy.json' );
}
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-login-proxy.php';

/** Verify strict proxy trust and reusable external configuration. */
final class LoginProxyTest extends TestCase {
	/** @var array<string,mixed> */
	private array $server;

	protected function setUp(): void {
		$this->server = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
	}

	/** A trusted proxy with exact proof may forward one valid client address. */
	public function test_normalizes_verified_generic_login_proxy(): void {
		$_SERVER['REMOTE_ADDR']                 = '127.0.0.1';
		$_SERVER['HTTP_X_DIGITALOGIC_AUTH_KEY'] = 'test-only-login-proxy-secret-0000000000000001';
		$_SERVER['HTTP_X_FORWARDED_FOR']        = '203.0.113.42';

		Digitalogic_Login_Proxy::normalize();

		$this->assertSame( '203.0.113.42', $_SERVER['REMOTE_ADDR'] );
		$this->assertArrayNotHasKey( 'HTTP_X_FORWARDED_FOR', $_SERVER );
	}

	/** An untrusted peer cannot activate forwarding even with valid proof. */
	public function test_rejects_untrusted_proxy_peer(): void {
		$_SERVER['REMOTE_ADDR']                 = '198.51.100.9';
		$_SERVER['HTTP_X_DIGITALOGIC_AUTH_KEY'] = 'test-only-login-proxy-secret-0000000000000001';
		$_SERVER['HTTP_X_FORWARDED_FOR']        = '203.0.113.42';

		Digitalogic_Login_Proxy::normalize();

		$this->assertSame( '198.51.100.9', $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '203.0.113.42', $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/** Invalid proof or a forwarded chain must fail closed. */
	public function test_rejects_invalid_proof_and_forwarded_chains(): void {
		$_SERVER['REMOTE_ADDR']                 = '127.0.0.1';
		$_SERVER['HTTP_X_DIGITALOGIC_AUTH_KEY'] = str_repeat( 'x', 40 );
		$_SERVER['HTTP_X_FORWARDED_FOR']        = '203.0.113.42';
		Digitalogic_Login_Proxy::normalize();
		$this->assertSame( '127.0.0.1', $_SERVER['REMOTE_ADDR'] );

		$_SERVER['HTTP_X_DIGITALOGIC_AUTH_KEY'] = 'test-only-login-proxy-secret-0000000000000001';
		$_SERVER['HTTP_X_FORWARDED_FOR']        = '203.0.113.42, 198.51.100.3';
		Digitalogic_Login_Proxy::normalize();
		$this->assertSame( '127.0.0.1', $_SERVER['REMOTE_ADDR'] );
	}

	/** The bounded Organizer path remains only as a transition fallback. */
	public function test_source_keeps_legacy_organizer_configuration_fallback(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-login-proxy.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $source );
		$this->assertStringContainsString( '/etc/digitalogic/login-proxy.json', $source );
		$this->assertStringContainsString( '/etc/digitalogic/organizer-gateway.json', $source );
	}
}
