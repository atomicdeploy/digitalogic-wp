<?php
/**
 * Native authentication never becomes a browser/panel bearer handoff.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileCheckoutRequestAuth as Auth;
use PHPUnit\Framework\TestCase;

/** Closed route/method boundary and independent principal checks. */
final class MobileCheckoutRequestAuthTest extends TestCase {

	/** Exact native checkout methods participate; query/action and browser routes never do. */
	public function test_only_exact_https_checkout_requests_can_authenticate_early(): void {
		foreach ( array( '/app-api/checkout', '/app-api/checkout/' ) as $path ) {
			foreach ( array( 'GET', 'HEAD', 'POST' ) as $method ) {
				self::assertTrue(
					Auth::supports(
						array(
							'REQUEST_URI'    => $path,
							'REQUEST_METHOD' => $method,
							'CONTENT_TYPE'   => 'application/json; charset=UTF-8',
						),
						true
					)
				);
			}
		}
		foreach ( array( '/app-api/checkout/quote', '/app-api/checkout/quote/' ) as $path ) {
			self::assertTrue(
				Auth::supports(
					array(
						'REQUEST_URI'    => $path,
						'REQUEST_METHOD' => 'POST',
						'CONTENT_TYPE'   => 'application/json',
					),
					true
				)
			);
			self::assertFalse(
				Auth::supports(
					array(
						'REQUEST_URI'    => $path,
						'REQUEST_METHOD' => 'GET',
					),
					true
				)
			);
		}
		foreach ( array( '/panel/', '/wp-admin/', '/wp-json/wc/v3/orders', '/checkout/', '/my-account/', '/app-api/cart', '/app-api/checkout/operations/known', '/app-api/checkout-extra', '/app-api/checkout?wc-ajax=checkout', '/app-api/checkout?rest_route=/wp/v2/users', '/app-api/checkout/../account/profile', '/app-api%2fcheckout', 'https://digitalogic.ir/app-api/checkout', '//app-api/checkout' ) as $path ) {
			self::assertFalse(
				Auth::supports(
					array(
						'REQUEST_URI'    => $path,
						'REQUEST_METHOD' => 'POST',
						'CONTENT_TYPE'   => 'application/json',
					),
					true
				),
				$path
			);
		}
		foreach ( array( 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ) as $method ) {
			self::assertFalse(
				Auth::supports(
					array(
						'REQUEST_URI'    => '/app-api/checkout',
						'REQUEST_METHOD' => $method,
						'CONTENT_TYPE'   => 'application/json',
					),
					true
				)
			);
		}
		foreach ( array( '', 'application/x-www-form-urlencoded', 'text/plain', 'application/jsonp', "application/json\r\nX-Test: value" ) as $type ) {
			self::assertFalse(
				Auth::supports(
					array(
						'REQUEST_URI'    => '/app-api/checkout',
						'REQUEST_METHOD' => 'POST',
						'CONTENT_TYPE'   => $type,
					),
					true
				)
			);
		}
		self::assertFalse(
			Auth::supports(
				array(
					'REQUEST_URI'    => '/app-api/checkout',
					'REQUEST_METHOD' => 'POST',
					'CONTENT_TYPE'   => 'application/json',
				),
				false
			)
		);
	}

	/** A browser cookie alone cannot authorize native checkout or change the native owner. */
	public function test_native_principal_requires_its_own_verified_session(): void {
		self::assertSame(
			array(
				'user_id'  => 7,
				'conflict' => false,
			),
			Auth::principal( false, array( 'user_id' => 7 ) )
		);
		self::assertSame(
			array(
				'user_id'  => 7,
				'conflict' => false,
			),
			Auth::principal( 7, array( 'user_id' => 7 ) )
		);
		self::assertSame(
			array(
				'user_id'  => 0,
				'conflict' => true,
			),
			Auth::principal( 8, array( 'user_id' => 7 ) )
		);
		self::assertSame(
			array(
				'user_id'  => 0,
				'conflict' => false,
			),
			Auth::principal( 8, null )
		);
		self::assertSame(
			array(
				'user_id'  => 0,
				'conflict' => false,
			),
			Auth::principal( false, null )
		);
	}

	/** Outside the closed scope, no session lookup or principal replacement occurs. */
	public function test_unrelated_browser_and_panel_authentication_is_untouched(): void {
		$previous = $_SERVER;
		try {
			foreach ( array( '/panel/', '/wp-admin/', '/checkout/', '/my-account/', '/wp-json/wp/v2/users', '/app-api/auth/google' ) as $path ) {
				$_SERVER = array(
					'REQUEST_URI'        => $path,
					'REQUEST_METHOD'     => 'POST',
					'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat( 'a', 64 ),
				);
				self::assertSame( 81, Auth::determineUser( 81 ) );
				self::assertFalse( Auth::determineUser( false ) );
			}
		} finally {
			$_SERVER = $previous;
		}
	}
}
