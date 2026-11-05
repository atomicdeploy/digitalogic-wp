<?php
/**
 * Native CheckoutContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Http\Controllers\MobileCheckoutController as Controller;
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCheckoutController.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';
/** Regression coverage for the native adapter. */
final class MobileCheckoutContractTest extends TestCase {
	/**
	 * Verify quote input rejects client prices owner ids and unbounded fields.
	 *
	 * @return void Native adapter result.
	 */
	public function test_quote_input_rejects_client_prices_owner_ids_and_unbounded_fields(): void {
		$valid = array(
			'cart_revision'    => 2,
			'billing'          => array(
				'country'    => 'IR',
				'first_name' => 'نام',
			),
			'shipping_methods' => array( '0' => 'local_pickup:1' ),
			'payment_method'   => 'bacs',
			'coupons'          => array(),
		);
		self::assertSame( $valid, Controller::normalizeInput( $valid ) );
		foreach ( array( $valid + array( 'total' => 1 ), $valid + array( 'user_id' => 2 ), array_merge( $valid, array( 'cart_revision' => '2' ) ), array_merge( $valid, array( 'billing' => array( 'first_name' => array( 'bad' ) ) ) ), array_merge( $valid, array( 'customer_note' => str_repeat( 'a', 2001 ) ) ), array_merge( $valid, array( 'shipping_methods' => array( 'admin' => 'x' ) ) ) ) as $bad ) {
			self::assertNull( Controller::normalizeInput( $bad ) );}
	}
	/**
	 * Verify native routes do not reenable the retired browser handoff.
	 *
	 * @return void Native adapter result.
	 */
	public function test_native_routes_do_not_reenable_the_retired_browser_handoff(): void {
		self::assertSame( '/_digitalogic/mobile/checkout', Digitalogic_Mobile_API::route( '/app-api/checkout' ) );
		self::assertSame( array( 'GET', 'HEAD', 'POST' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/checkout' ) );
		self::assertSame( array( 'POST' ), Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/checkout/quote' ) );
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/checkout/redeem' ) );
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/checkout/preview' ) );
	}
}
