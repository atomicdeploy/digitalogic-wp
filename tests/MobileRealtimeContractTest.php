<?php
/**
 * Native RealtimeContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Regression coverage for the native adapter. */
final class MobileRealtimeContractTest extends TestCase {
	/**
	 * Verify public events never inherit panel payload or customer events.
	 *
	 * @return void Native adapter result.
	 */
	public function test_public_events_never_inherit_panel_payload_or_customer_events(): void {
		$project = Digitalogic_WebSocket_Server::project_shopper_event(
			array(
				'id'   => 42,
				'name' => 'product.updated',
				'time' => 'now',
				'data' => array(
					'id'             => 123,
					'cost'           => 'secret',
					'supplier_token' => 'private',
				),
			),
			0
		);
		self::assertSame(
			array(
				'scope'      => 'product',
				'product_id' => 123,
				'object_id'  => 123,
				'parent_id'  => 0,
				'change'     => 'updated',
			),
			$project['data']
		);
		foreach ( array( 'user.updated', 'panel.event', 'mobile.order.updated', 'admin.settings.updated' ) as $name ) {
			self::assertNull(
				Digitalogic_WebSocket_Server::project_shopper_event(
					array(
						'id'   => 43,
						'name' => $name,
						'data' => array(
							'id'      => 77,
							'user_id' => 77,
						),
					),
					0
				)
			);}
	}

	/**
	 * Verify native identity receives only own small invalidation.
	 *
	 * @return void Native adapter result.
	 */
	public function test_native_identity_receives_only_own_small_invalidation(): void {
		$event = array(
			'id'   => 44,
			'name' => 'mobile.order.updated',
			'data' => array(
				'user_id'  => 77,
				'order_id' => 123,
				'status'   => 'completed',
				'address'  => 'private',
			),
		);
		self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 78 ) );
		self::assertSame(
			array(
				'scope'    => 'orders',
				'order_id' => 123,
				'status'   => 'completed',
			),
			Digitalogic_WebSocket_Server::project_shopper_event( $event, 77 )['data']
		);
		self::assertNull(
			Digitalogic_WebSocket_Server::project_shopper_event(
				array(
					'id'   => 45,
					'name' => 'user.updated',
					'data' => array( 'id' => 78 ),
				),
				77
			)
		);
		self::assertSame(
			array( 'scope' => 'profile' ),
			Digitalogic_WebSocket_Server::project_shopper_event(
				array(
					'id'   => 45,
					'name' => 'user.updated',
					'data' => array(
						'id'    => 77,
						'email' => 'private',
					),
				),
				77
			)['data']
		);
	}

	/**
	 * Verify guest shopper auth cannot select a panel or machine principal.
	 *
	 * @return void Native adapter result.
	 */
	public function test_guest_shopper_auth_cannot_select_a_panel_or_machine_principal(): void {
		$context = Digitalogic_WebSocket_Auth::authenticate_context( array( 'sec-websocket-protocol' => 'digitalogic.shopper' ), array() );
		self::assertSame( 'mobile_shopper', $context['principal'] );
		self::assertSame( 0, $context['user_id'] );
		self::assertSame( '', $context['native_session_id'] );
		self::assertFalse( Digitalogic_WebSocket_Auth::authenticate_context( array( 'sec-websocket-protocol' => 'digitalogic.shopper' ), array( 'token' => 'anything' ) )['authenticated'] );
		self::assertFalse(
			Digitalogic_WebSocket_Auth::authenticate_context(
				array(
					'sec-websocket-protocol'       => 'digitalogic.shopper',
					'x-patris-product-sync-secret' => 'anything',
				),
				array()
			)['authenticated']
		);
	}
}
