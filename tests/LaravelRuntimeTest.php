<?php

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- These tests verify preservation of the PHP error handler.

use Digitalogic\Laravel\Application;
use Digitalogic\Laravel\WordPressRuntime;
use Digitalogic\Pricing\Calculator;
use PHPUnit\Framework\TestCase;

final class LaravelRuntimeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']      = array();
		$GLOBALS['digitalogic_test_option_cache'] = array();
		$GLOBALS['digitalogic_test_capabilities'] = array();
	}

	public function test_wordpress_and_laravel_use_one_booted_container_and_same_calculator(): void {
		$bridge = ( new ReflectionClass( Digitalogic_Laravel_Bridge::class ) )->newInstanceWithoutConstructor();
		$app    = $bridge->boot_laravel();
		self::assertInstanceOf( Application::class, $app );
		self::assertTrue( $app->hasBeenBootstrapped() );
		self::assertSame( 'Digitalogic\\Laravel\\', $app->getNamespace() );
		self::assertSame( $app, digitalogic_laravel() );
		self::assertSame( $app, require dirname( __DIR__ ) . '/laravel/bootstrap/app.php' );
		self::assertSame( $app, $bridge->boot_laravel() );
		self::assertSame( $app->make( Calculator::class ), $app->make( Calculator::class ) );
		self::assertSame( Calculator::class, $bridge->call( static fn ( Calculator $calculator ): string => $calculator::class ) );
		self::assertInstanceOf( WordPressRuntime::class, digitalogic_wordpress() );
		self::assertTrue( digitalogic_wordpress()->loaded() );
	}

	public function test_boot_preserves_the_wordpress_error_handler(): void {
		$handler = static fn (): bool => false;
		set_error_handler( $handler );
		try {
			digitalogic_laravel();
			$actual = set_error_handler( $handler );
			self::assertSame( $handler, $actual );
			restore_error_handler();
		} finally {
			restore_error_handler();
		}
	}

	public function test_user_facing_dispatch_requires_wordpress_capabilities_even_after_boot(): void {
		$bridge = ( new ReflectionClass( Digitalogic_Laravel_Bridge::class ) )->newInstanceWithoutConstructor();
		$bridge->boot_laravel();
		$result = $bridge->call_local_laravel( '/_digitalogic/bridge/status' );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'digitalogic_laravel_forbidden', $result->get_error_code() );
	}

	public function test_trusted_callable_failure_is_bounded_and_does_not_poison_the_container(): void {
		$bridge = ( new ReflectionClass( Digitalogic_Laravel_Bridge::class ) )->newInstanceWithoutConstructor();
		$result = $bridge->call(
			static function (): void {
				throw new RuntimeException( 'test failure' );
			}
		);
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'digitalogic_laravel_call_failed', $result->get_error_code() );
		self::assertSame( 'recovered', $bridge->call( static fn (): string => 'recovered' ) );
	}

	public function test_authorized_panel_dispatch_calls_the_local_kernel(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;
		$bridge = ( new ReflectionClass( Digitalogic_Laravel_Bridge::class ) )->newInstanceWithoutConstructor();
		$result = $bridge->call_local_laravel( '/_digitalogic/bridge/status' );
		self::assertIsArray( $result );
		self::assertSame( 200, $result['status'] );
		self::assertSame( 'in_process', $result['body']['mode'] );
		self::assertSame( get_bloginfo( 'version' ), $result['body']['wordpress'] );
	}
}
