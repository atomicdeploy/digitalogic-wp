<?php
/**
 * Boundary and currency parity acceptance for final rounding.
 *
 * @package Digitalogic
 */

use Digitalogic\Pricing\Calculator;
use Digitalogic\Pricing\RoundingPolicy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pricing/Calculator.php';

/** Exercise exact thresholds, configurable tiers and calculator integration. */
final class MagnitudeRoundingTest extends TestCase {
	/** Verify tier boundaries, half-up ties and currency equivalence. */
	public function test_thresholds_ties_and_irr_equivalence(): void {
		$cases  = array(
			array( '999', '999', 0 ),
			array( '1000', '1000', 1 ),
			array( '1004', '1000', 1 ),
			array( '1005', '1010', 1 ),
			array( '9999', '10000', 1 ),
			array( '10000', '10000', 2 ),
			array( '10049', '10000', 2 ),
			array( '10050', '10100', 2 ),
			array( '99999', '100000', 2 ),
			array( '100000', '100000', 3 ),
			array( '123456', '123000', 3 ),
			array( '123500', '124000', 3 ),
			array( '999999', '1000000', 3 ),
			array( '1000000', '1000000', 4 ),
			array( '1234500', '1230000', 4 ),
			array( '1235000', '1240000', 4 ),
			array( '9999999', '10000000', 4 ),
			array( '10000000', '10000000', 5 ),
			array( '43580800', '43600000', 5 ),
			array( '100000000', '100000000', 6 ),
			array( '123456789012345', '123000000000000', 12 ),
		);
		$policy = RoundingPolicy::defaults();
		foreach ( $cases as list( $amount, $expected, $digits ) ) {
			self::assertSame( $expected, RoundingPolicy::round( $amount, 'IRT', $policy ), $amount );
			self::assertSame( $expected . '0', RoundingPolicy::round( $amount . '0', 'IRR', $policy ), 'IRR ' . $amount );
			self::assertSame(
				$digits,
				RoundingPolicy::digits_for_decimal(
					array(
						'digits' => $amount,
						'scale'  => 0,
					),
					'IRT',
					$policy
				)
			);
			self::assertSame(
				$digits + 1,
				RoundingPolicy::digits_for_decimal(
					array(
						'digits' => $amount . '0',
						'scale'  => 0,
					),
					'IRR',
					$policy
				)
			);
		}
		self::assertSame( '999', RoundingPolicy::round( '999.49', 'IRT', $policy ) );
		self::assertSame( '1000', RoundingPolicy::round( '999.5', 'IRT', $policy ) );
		self::assertSame( '10000', RoundingPolicy::round( '9999.999999999999', 'IRT', $policy ) );
	}

	/** Select the configured tier once before any rounding. */
	public function test_configurable_threshold_and_no_second_round(): void {
		$policy = array(
			'tiers'          => array(
				array(
					'threshold_irt' => '1500',
					'digits'        => 1,
				),
				array(
					'threshold_irt' => '10000',
					'digits'        => 3,
				),
			),
			'extend_decades' => false,
		);
		self::assertSame( '1499', RoundingPolicy::round( '1499', 'IRT', $policy ) );
		self::assertSame( '1510', RoundingPolicy::round( '1505', 'IRT', $policy ) );
		self::assertSame( '10000', RoundingPolicy::round( '9999', 'IRT', $policy ) );
		self::assertSame( '123000', RoundingPolicy::round( '123456', 'IRT', $policy ) );
	}

	/** All supported source routes preserve inputs and inventory. */
	public function test_all_price_routes_round_only_the_final_total_and_preserve_input(): void {
		foreach ( array( 'foreign_price', 'partner_price', 'sale_price_direct' ) as $kind ) {
			$foreign = 'foreign_price' === $kind;
			$row     = array(
				'product_code'                   => 'UNCHANGED',
				'price_source_kind'              => $kind,
				'price_source_currency'          => $foreign ? 'CNY' : 'IRR',
				'price_source_amount'            => $foreign ? '123.455' : '1234560',
				'weight_grams'                   => '1',
				'total_stock'                    => 7,
				'shipping_method_id'             => $foreign ? 'air_express' : 'domestic',
				'shipping_price_per_kg'          => $foreign ? '1' : '0',
				'shipping_price_per_kg_currency' => $foreign ? 'CNY' : 'IRR',
				'irt_per_cny'                    => '1000',
				'markup_percent'                 => '0',
				'price_rounding_digits'          => 2,
				'price_rounding_mode'            => 'nearest_half_up',
				'price_rounding_policy'          => RoundingPolicy::defaults(),
			);
			$before  = $row;
			self::assertSame( 123000, ( new Calculator() )->evaluate( $row )['value'], $kind );
			self::assertSame( $before, $row );
			$row['weight_grams'] = '0';
			self::assertFalse( ( new Calculator() )->evaluate( $row )['available'] );
		}
	}

	/** Invalid settings must fail closed. */
	public function test_invalid_policy_is_rejected_instead_of_silently_using_defaults(): void {
		$invalid = array(
			array(
				'tiers'          => array(),
				'extend_decades' => true,
			),
			array(
				'tiers'          => array(
					array(
						'threshold_irt' => '01000',
						'digits'        => 1,
					),
				),
				'extend_decades' => true,
			),
			array(
				'tiers'          => array(
					array(
						'threshold_irt' => '1000',
						'digits'        => 4,
					),
				),
				'extend_decades' => true,
			),
			array(
				'tiers'          => array(
					array(
						'threshold_irt' => '1000',
						'digits'        => -1,
					),
				),
				'extend_decades' => true,
			),
			array(
				'tiers'          => array(
					array(
						'threshold_irt' => '1000',
						'digits'        => 1,
					),
					array(
						'threshold_irt' => '1000',
						'digits'        => 2,
					),
				),
				'extend_decades' => true,
			),
		);
		foreach ( $invalid as $policy ) {
			try {
				RoundingPolicy::normalize( $policy );
				self::fail( 'Invalid rounding policy accepted.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertNotEmpty( $error->getMessage() );
			}
		}
	}
}
