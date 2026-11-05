<?php
/**
 * Coordinate ownership and optional-location validation contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileAddresses;
use Digitalogic\Laravel\Http\Controllers\MobileCheckoutController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileAddresses.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCheckoutController.php';

/** Explicit coordinates never change customer authentication or prices. */
final class MobileAddressesTest extends TestCase {
	/**
	 * Absent pins stay absent; invalid, unbounded, and arbitrary fields fail closed.
	 *
	 * @return void Native adapter result.
	 */
	public function test_coordinate_patch_bounds_and_explicit_clear(): void {
		self::assertSame( array(), MobileAddresses::normalize_locations( array() ) );
		self::assertSame( array( 'shipping' => null ), MobileAddresses::normalize_locations( array( 'shipping' => null ) ) );
		$valid = array(
			'billing' => array(
				'lat'      => 35.6944465,
				'lon'      => 51.4132879,
				'accuracy' => 12.0,
			),
		);
		self::assertSame( $valid, MobileAddresses::normalize_locations( $valid ) );
		foreach ( array(
			array( 'owner_id' => 4 ),
			array(
				'billing' => array(
					'lat' => '35',
					'lon' => 51,
				),
			),
			array(
				'billing' => array(
					'lat' => 91,
					'lon' => 51,
				),
			),
			array(
				'billing' => array(
					'lat' => 35,
					'lon' => -181,
				),
			),
			array(
				'shipping' => array(
					'lat' => INF,
					'lon' => 51,
				),
			),
			array(
				'shipping' => array(
					'lat' => NAN,
					'lon' => 51,
				),
			),
			array(
				'shipping' => array(
					'lat'      => 35,
					'lon'      => 51,
					'accuracy' => -1,
				),
			),
			array(
				'shipping' => array(
					'lat'      => 35,
					'lon'      => 51,
					'verified' => true,
				),
			),
		) as $bad ) {
			self::assertNull( MobileAddresses::normalize_locations( $bad ) );
			self::assertNull(
				MobileCheckoutController::normalizeInput(
					array(
						'cart_revision'     => 1,
						'address_locations' => $bad,
					)
				)
			);
		}
		$quote = array(
			'cart_revision'     => 1,
			'address_locations' => $valid,
		);
		self::assertSame( $quote, MobileCheckoutController::normalizeInput( $quote ) );
	}
}
