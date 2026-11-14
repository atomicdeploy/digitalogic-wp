<?php
/**
 * Provider cart metadata must not become a blanket identity-merge bypass.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileAccountMerge;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileAccountMerge.php';

/** Canonical provider defaults are distinct from actual payment selections. */
final class MobileMergeCartMetadataTest extends TestCase {

	/** Ordinary restored Woo lines contain these audited provider-generated values. */
	public function test_default_provider_metadata_is_accepted_without_rewriting_it(): void {
		$line = array(
			'product_id'          => 101,
			'variation_id'        => 0,
			'quantity'            => 2,
			'is_deposit'          => false,
			'payment_plan'        => 0,
			'tiered_pricing_data' => array( 'total_item_quantity' => 3 ),
		);
		$copy = $line;
		self::assertFalse( MobileAccountMerge::hasUnsupportedCartOptions( array( $line ) ) );
		self::assertSame( $copy, $line );
		self::assertFalse( MobileAccountMerge::hasUnsupportedCartOptions( array() ) );
		self::assertFalse(
			MobileAccountMerge::hasUnsupportedCartOptions(
				array(
					array(
						'product_id' => 101,
						'quantity'   => 1,
					),
				)
			)
		);
	}

	/** No active deposit or selected installment plan can be converted to full payment. */
	public function test_active_or_noncanonical_deposit_values_are_rejected(): void {
		foreach ( array( true, 1, 0, 'no', 'false', '', null ) as $value ) {
			self::assertTrue( MobileAccountMerge::hasUnsupportedCartOptions( array( array( 'is_deposit' => $value ) ) ) );
		}
		foreach ( array( 7, -1, '0', 0.0, false, null ) as $value ) {
			self::assertTrue( MobileAccountMerge::hasUnsupportedCartOptions( array( array( 'payment_plan' => $value ) ) ) );
		}
		self::assertTrue(
			MobileAccountMerge::hasUnsupportedCartOptions(
				array(
					array(
						'is_deposit'   => true,
						'payment_plan' => 0,
					),
				)
			)
		);
	}

	/** A quantity cache is not permission to copy a plugin price or unknown setting. */
	public function test_tier_cache_accepts_only_the_known_derived_quantity_shape(): void {
		foreach ( array(
			null,
			false,
			array(),
			array( 'total_item_quantity' => '2' ),
			array( 'total_item_quantity' => 2.0 ),
			array( 'total_item_quantity' => 0 ),
			array( 'total_item_quantity' => -1 ),
			array(
				'total_item_quantity' => 2,
				'price'               => 100,
			),
			array( 'unknown' => 2 ),
		) as $tier ) {
			self::assertTrue( MobileAccountMerge::hasUnsupportedCartOptions( array( array( 'tiered_pricing_data' => $tier ) ) ) );
		}
	}

	/** Unknown metadata remains protected even when its present value is empty. */
	public function test_unknown_cart_options_are_not_silently_discarded(): void {
		foreach ( array(
			'engraving'      => '',
			'booking'        => array(),
			'deposit_amount' => 0,
			'full_amount'    => 100,
		) as $key => $value ) {
			self::assertTrue( MobileAccountMerge::hasUnsupportedCartOptions( array( array( $key => $value ) ) ) );
		}
		self::assertTrue( MobileAccountMerge::hasUnsupportedCartOptions( array( 'invalid line' ) ) );
	}

	/** The receiving cart must not absorb ordinary source quantities into an active plan. */
	public function test_target_selected_options_block_review_when_source_cart_will_move(): void {
		$now       = time();
		$session   = array(
			'id'          => str_repeat( 'a', 32 ),
			'auth_method' => 'google',
			'verified_at' => $now,
		);
		$context   = array(
			'source'           => 901,
			'target'           => 902,
			'phone'            => '+980000000000',
			'google_until'     => $now + 600,
			'phone_until'      => $now + 600,
			'target_protected' => false,
		);
		$inventory = array(
			'counts'              => array( 'cart_lines' => 2 ),
			'pending'             => 0,
			'unsupported_history' => array(),
		);
		$target    = $inventory;
		$target['unsupported_history']['custom_cart_options'] = 1;
		$plan = MobileAccountMerge::presentation( $session, $context, $inventory, $target );
		self::assertSame( 'blocked', $plan['status'] );
		self::assertContains( 'provider_migration_required', $plan['blockers'] );
		self::assertNull( $plan['plan_id'] );
	}
}
