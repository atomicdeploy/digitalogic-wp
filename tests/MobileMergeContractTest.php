<?php
/**
 * Isolated native merge contract regressions.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;
use Digitalogic\Laravel\Services\MobileAccountMerge;
use Digitalogic\Laravel\Services\MobileMergeOperation;
use Digitalogic\Laravel\Services\MobileRegistrationStore;
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRegistrationStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileAccountMerge.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileMergeOperation.php';

/** No production account or provider transport is used. */
final class MobileMergeContractTest extends TestCase {
	/** Exercise isolated contract state. */
	public static function fixtures(): array {
		$now                            = time();
		$session                        = array(
			'id'          => str_repeat( 'a', 32 ),
			'user_id'     => 901,
			'auth_method' => 'google',
			'verified_at' => $now,
		);
		$context                        = array(
			'source'           => 901,
			'target'           => 902,
			'phone'            => '+980000000000',
			'subject'          => '000000000000',
			'phone_proof_id'   => str_repeat( 'b', 32 ),
			'phone_proof_at'   => $now,
			'google_proof_at'  => $now,
			'google_until'     => $now + 600,
			'phone_until'      => $now + 600,
			'target_protected' => true,
		);
		$inventory                      = array(
			'counts'              => array(
				'cart_lines' => 0,
				'orders'     => 0,
				'favorites'  => 0,
				'requests'   => 0,
				'tickets'    => 0,
				'drafts'     => 0,
			),
			'pending'             => 0,
			'unsupported_history' => array(),
		);
		$source                         = $inventory;
		$source['counts']['cart_lines'] = 2;
		$operation                      = '00000000-0000-4000-8000-000000000001';
		return array(
			'metadata'        => array(
				'synthetic'    => true,
				'generated_at' => gmdate( 'c', $now ),
				'clock_epoch'  => $now,
				'note'         => 'Real application serializers; synthetic non-routable identity; no canonical provider mutation.',
			),
			'proof_required'  => MobileAccountMerge::presentation( $session, new WP_Error( 'phone_proof_required' ) ),
			'review_required' => MobileAccountMerge::presentation( $session, $context, $source, $inventory ),
			'merged'          => MobileMergeOperation::acceptedResponse(
				$operation,
				$context,
				array(
					'id'           => 902,
					'display_name' => 'Fixture',
					'identity'     => array(
						'phone_verified'              => true,
						'google_linked'               => true,
						'requires_phone_verification' => false,
					),
				),
				array(
					'access_token' => str_repeat( '0', 64 ),
					'session_id'   => str_repeat( '0', 32 ),
					'expires_at'   => gmdate( 'c', $now + 3600 ),
					'token_type'   => 'Bearer',
				)
			),
			'rejected'        => MobileMergeOperation::rejectedResponse(
				array(
					'request_id'   => $operation,
					'failure_code' => 'merge_plan_changed',
				)
			),
		);
	}
	/**
	 * Verify real contract round trip and snapshot boundaries.
	 */
	public function test_real_contract_round_trip_and_snapshot_boundaries(): void {
		$fixtures = self::fixtures();
		self::assertSame( array( 'phone' ), $fixtures['proof_required']['required_proofs'] );
		self::assertNotNull( $fixtures['proof_required']['proof_until']['google'] );
		$plan = $fixtures['review_required'];
		self::assertSame( 'review_required', $plan['status'] );
		self::assertSame( 2, $plan['records']['source']['cart_lines'] );
		self::assertLessThan( 2048, strlen( $plan['plan_id'] ) );
		$claim = json_decode( MobileRegistrationStore::open( $plan['plan_id'], 'account-merge-plan' ), true );
		self::assertSame( $plan['revision'], $claim['revision'] );
		self::assertSame( 902, $claim['target'] );
		self::assertNull( MobileRegistrationStore::open( $plan['plan_id'], 'another-purpose' ) );
		self::assertSame( 902, $fixtures['merged']['profile']['id'] );
		self::assertArrayNotHasKey( 'order', $fixtures['rejected'] );
	}
	/**
	 * Verify geographic address is never built from two accounts.
	 */
	public function test_geographic_address_is_never_built_from_two_accounts(): void {
		$source = array(
			'first_name' => 'Fixture',
			'country'    => 'IR',
			'state'      => 'THR',
			'city'       => 'Tehran',
			'address_1'  => 'Synthetic street',
		);
		self::assertSame( array( 'first_name' => 'Fixture' ), MobileAccountMerge::addressPatch( $source, array( 'country' => 'DE' ) ) );
		self::assertSame( $source, MobileAccountMerge::addressPatch( $source, array() ) );
	}
	/**
	 * Verify immutable intent hash ignores json key order only.
	 */
	public function test_immutable_intent_hash_ignores_json_key_order_only(): void {
		$a = array(
			'choices'      => array(
				'cart'      => 'combine',
				'addresses' => 'keep_target_fill_empty',
			),
			'operation_id' => 'fixture',
		);
		$b = array(
			'operation_id' => 'fixture',
			'choices'      => array(
				'addresses' => 'keep_target_fill_empty',
				'cart'      => 'combine',
			),
		);
		self::assertSame( MobileMergeOperation::payloadHash( $a ), MobileMergeOperation::payloadHash( $b ) );
		$b['choices']['cart'] = 'discard';
		self::assertNotSame( MobileMergeOperation::payloadHash( $a ), MobileMergeOperation::payloadHash( $b ) );
	}
}
