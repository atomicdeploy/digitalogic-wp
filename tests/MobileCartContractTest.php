<?php
/**
 * Native CartContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileCartStore as Store;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileCartStore.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';

/** Regression coverage for the native adapter. */
final class MobileCartContractTest extends TestCase {

	/**
	 * Verify independent device edits merge without overwriting other lines.
	 *
	 * @return void Native adapter result.
	 */
	public function test_independent_device_edits_merge_without_overwriting_other_lines(): void {
		$current = array(
			array(
				'id'       => 1,
				'quantity' => 4,
			),
			array(
				'id'       => 2,
				'quantity' => 8,
			),
		);
		$result  = Store::mergePatch(
			$current,
			array(
				array(
					'id'            => 1,
					'quantity'      => 5,
					'base_quantity' => 4,
				),
			)
		);
		self::assertSame(
			array(
				array(
					'id'       => 1,
					'quantity' => 5,
				),
				array(
					'id'       => 2,
					'quantity' => 8,
				),
			),
			$result['items']
		);
		self::assertSame( array(), $result['conflicts'] );
	}
	/**
	 * Verify same item conflict is atomic and has three way quantities.
	 *
	 * @return void Native adapter result.
	 */
	public function test_same_item_conflict_is_atomic_and_has_three_way_quantities(): void {
		$current = array(
			array(
				'id'       => 1,
				'quantity' => 4,
			),
			array(
				'id'       => 2,
				'quantity' => 8,
			),
		);
		$result  = Store::mergePatch(
			$current,
			array(
				array(
					'id'            => 1,
					'quantity'      => 5,
					'base_quantity' => 2,
				),
				array(
					'id'            => 2,
					'quantity'      => 0,
					'base_quantity' => 8,
				),
			)
		);
		self::assertSame( $current, $result['items'] );
		self::assertSame(
			array(
				array(
					'id'                 => 1,
					'base_quantity'      => 2,
					'current_quantity'   => 4,
					'requested_quantity' => 5,
				),
			),
			$result['conflicts']
		);
	}
	/**
	 * Verify already applied quantity and removal are idempotent.
	 *
	 * @return void Native adapter result.
	 */
	public function test_already_applied_quantity_and_removal_are_idempotent(): void {
		self::assertSame(
			array(
				'items'     => array(
					array(
						'id'       => 1,
						'quantity' => 5,
					),
				),
				'conflicts' => array(),
			),
			Store::mergePatch(
				array(
					array(
						'id'       => 1,
						'quantity' => 5,
					),
				),
				array(
					array(
						'id'            => 1,
						'quantity'      => 5,
						'base_quantity' => 2,
					),
					array(
						'id'            => 2,
						'quantity'      => 0,
						'base_quantity' => 8,
					),
				)
			)
		);
	}
	/**
	 * Verify mutation rejects client prices invalid quantities and duplicate ids.
	 *
	 * @return void Native adapter result.
	 */
	public function test_mutation_rejects_client_prices_invalid_quantities_and_duplicate_ids(): void {
		$valid = array(
			'id'            => 7,
			'quantity'      => 3,
			'base_quantity' => 1,
		);
		self::assertSame( array( $valid ), Store::changes( array( $valid ) ) );
		foreach ( array( array( $valid + array( 'price' => '1' ) ), array( $valid, $valid ), array( array_merge( $valid, array( 'quantity' => -1 ) ) ), array( array_merge( $valid, array( 'quantity' => 1000 ) ) ), array( array_merge( $valid, array( 'id' => '7' ) ) ), array(), null ) as $invalid ) {
			self::assertNull( Store::changes( $invalid ) );
		}
	}
	/**
	 * Verify retired browser checkout paths are not dispatchable.
	 *
	 * @return void Native adapter result.
	 */
	public function test_retired_browser_checkout_paths_are_not_dispatchable(): void {
		foreach ( array( '/app-api/checkout/preview', '/app-api/checkout/redeem', '/app-api/cart/checkout' ) as $path ) {
			self::assertNull( Digitalogic_Mobile_API::route( $path ) );
		}
		self::assertSame( '/_digitalogic/mobile/cart', Digitalogic_Mobile_API::route( '/app-api/cart' ) );
		self::assertContains( 'PATCH', Digitalogic_Mobile_API::methods( '/_digitalogic/mobile/cart' ) );
	}
}
