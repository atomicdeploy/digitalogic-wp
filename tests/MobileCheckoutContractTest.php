<?php

use Digitalogic\Laravel\Http\Controllers\MobileCheckoutController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCheckoutController.php';

final class MobileCheckoutContractTest extends TestCase {
	public function test_cart_input_accepts_only_integer_product_identity_and_quantity(): void {
		self::assertSame(
			array(
				array(
					'id'       => 123,
					'quantity' => 3,
				),
			),
			MobileCheckoutController::normalizeItems(
				array(
					array(
						'id'       => 123,
						'quantity' => 1,
					),
					array(
						'id'       => 123,
						'quantity' => 2,
					),
				)
			)
		);
		foreach ( array(
			null,
			array(),
			array(
				array(
					'id'       => '123',
					'quantity' => 1,
				),
			),
			array(
				array(
					'id'       => 123,
					'quantity' => 1.5,
				),
			),
			array(
				array(
					'id'       => 123,
					'quantity' => 1,
					'price'    => 0,
				),
			),
			array(
				array(
					'id'       => 123,
					'quantity' => 1000,
				),
			),
			array(
				array(
					'id'       => 123,
					'quantity' => 999,
				),
				array(
					'id'       => 123,
					'quantity' => 1,
				),
			),
			array_fill(
				0,
				41,
				array(
					'id'       => 123,
					'quantity' => 1,
				)
			),
		) as $input ) {
			self::assertNull( MobileCheckoutController::normalizeItems( $input ) );
		}
	}

	public function test_signed_cart_rejects_modification_wrong_key_expiry_and_excess_lifetime(): void {
		$payload = array(
			'items'   => array(
				array(
					'id'       => 123,
					'quantity' => 2,
				),
			),
			'expires' => 1300,
			'nonce'   => str_repeat( 'a', 32 ),
		);
		$token   = MobileCheckoutController::sign( $payload, 'test-only-secret' );
		self::assertSame( $payload, MobileCheckoutController::verify( $token, 'test-only-secret', 1000 ) );
		self::assertNull( MobileCheckoutController::verify( $token, 'wrong-key', 1000 ) );
		self::assertNull( MobileCheckoutController::verify( $token, 'test-only-secret', 1300 ) );
		self::assertNull( MobileCheckoutController::verify( $token, 'test-only-secret', 999 ) );
		self::assertNull( MobileCheckoutController::verify( 'x' . $token, 'test-only-secret', 1000 ) );
		self::assertNull( MobileCheckoutController::verify( substr( $token, 0, -1 ) . 'z', 'test-only-secret', 1000 ) );
		$payload['items'][0]['price'] = 0;
		self::assertNull( MobileCheckoutController::verify( MobileCheckoutController::sign( $payload, 'test-only-secret' ), 'test-only-secret', 1000 ) );
	}
}
