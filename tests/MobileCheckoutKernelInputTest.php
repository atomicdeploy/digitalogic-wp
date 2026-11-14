<?php
/**
 * Native quote JSON must survive the real Laravel HTTP middleware pipeline.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Application;
use Digitalogic\Laravel\Http\Controllers\MobileCheckoutController;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Covers the HTTP boundary omitted by direct-controller commerce fixtures. */
final class MobileCheckoutKernelInputTest extends TestCase {

	/** Explicit null remains invalid; only empty strings on the quote JSON route are preserved. */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_quote_input_survives_kernel_without_changing_other_routes(): void {
		$app    = Application::bootShared();
		$kernel = $app->make( Kernel::class );
		$probe  = static function ( Request $request ) {
			$data = $request->isJson() ? $request->json()->all() : $request->request->all();
			return new \Symfony\Component\HttpFoundation\JsonResponse(
				array(
					'data'  => $data,
					'valid' => null !== MobileCheckoutController::normalizeInput( $data ),
				)
			);
		};
		// Test-process routes only: no fixture endpoint is added to production.
		foreach ( array( '/_digitalogic/mobile/checkout/quote', '/_digitalogic/mobile/account/profile', '/_digitalogic/bridge/input-test', '/_digitalogic/mobile/checkout/quote-extra' ) as $path ) {
			$app['router']->post( $path, $probe );
		}
		$payload = array(
			'cart_revision'             => 4,
			'billing'                   => array(
				'first_name' => '  Fixture  ',
				'company'    => '',
				'address_1'  => "First line\nSecond line",
				'address_2'  => '',
				'country'    => 'IR',
			),
			'shipping'                  => array(
				'phone'     => '',
				'address_2' => '',
			),
			'ship_to_different_address' => false,
			'shipping_methods'          => array( '0' => 'local_pickup:1' ),
			'payment_method'            => '',
			'coupons'                   => array(),
			'customer_note'             => '',
			'address_locations'         => array(
				'billing'  => null,
				'shipping' => null,
			),
		);
		$send    = static function ( string $path, array $input, bool $json = true ) use ( $kernel ): array {
			$request = Request::create( $path, 'POST', $json ? array() : $input, array(), array(), array(), $json ? wp_json_encode( $input ) : null );
			$request->headers->set( 'Accept', 'application/json' );
			$request->headers->set( 'Content-Type', $json ? 'application/json' : 'application/x-www-form-urlencoded' );
			$response = $kernel->handle( $request );
			self::assertSame( 200, $response->getStatusCode() );
			return json_decode( $response->getContent(), true, 512, JSON_THROW_ON_ERROR );
		};
		$quote   = $send( '/_digitalogic/mobile/checkout/quote', $payload );
		self::assertTrue( $quote['valid'] );
		self::assertSame( '', $quote['data']['billing']['company'] );
		self::assertSame( '', $quote['data']['billing']['address_2'] );
		self::assertSame( '', $quote['data']['shipping']['phone'] );
		self::assertSame( '', $quote['data']['payment_method'] );
		self::assertSame( '', $quote['data']['customer_note'] );
		self::assertNull( $quote['data']['address_locations']['billing'] );
		self::assertSame( 'Fixture', $quote['data']['billing']['first_name'] );
		self::assertSame( "First line\nSecond line", $quote['data']['billing']['address_1'] );
		$explicit_null                       = $payload;
		$explicit_null['billing']['company'] = null;
		self::assertFalse( $send( '/_digitalogic/mobile/checkout/quote', $explicit_null )['valid'] );
		foreach ( array( '/_digitalogic/mobile/account/profile', '/_digitalogic/bridge/input-test', '/_digitalogic/mobile/checkout/quote-extra' ) as $path ) {
			$unchanged = $send( $path, $payload );
			self::assertNull( $unchanged['data']['billing']['company'] );
			self::assertFalse( $unchanged['valid'] );
		}
		$form = $send( '/_digitalogic/mobile/checkout/quote', $payload, false );
		self::assertNull( $form['data']['billing']['company'] );
		self::assertFalse( $form['valid'] );
	}
}
