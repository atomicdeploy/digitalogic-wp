<?php

use Digitalogic\Pricing\Calculator;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pricing/Calculator.php';

final class SharedPricingCalculatorTest extends TestCase {
	public function test_source_decimal_cannot_hide_a_trailing_newline(): void {
		$this->expectException( InvalidArgumentException::class );
		( new Calculator() )->evaluate( $this->foreign( array( 'price_source_amount' => "10\n" ) ) );
	}

	private function foreign( array $changes = array() ): array {
		return array_replace(
			array(
				'price_source_kind'              => 'foreign_price',
				'price_source_currency'          => 'CNY',
				'price_source_amount'            => '24.5',
				'weight_grams'                   => '240',
				'shipping_method_id'             => 'air_express',
				'shipping_price_per_kg'          => '120',
				'shipping_price_per_kg_currency' => 'CNY',
				'markup_percent'                 => '30',
				'irt_per_cny'                    => '29000',
				'price_rounding_digits'          => 0,
				'price_rounding_mode'            => 'nearest_half_up',
			),
			$changes
		);
	}

	public function test_same_freight_value_in_cny_and_irr_has_one_result(): void {
		$engine = new Calculator();
		$cny    = $engine->evaluate( $this->foreign() );
		$irr    = $engine->evaluate(
			$this->foreign(
				array(
					'shipping_price_per_kg'          => '34800000',
					'shipping_price_per_kg_currency' => 'IRR',
				)
			)
		);
		self::assertSame( 2009410, $cny['value'] );
		self::assertSame( $cny, $irr );
	}

	public function test_rounds_once_after_all_costs_and_markup(): void {
		$result = ( new Calculator() )->evaluate(
			$this->foreign(
				array(
					'irt_per_cny'           => '34000',
					'price_rounding_digits' => 2,
				)
			)
		);
		self::assertSame( 2355900, $result['value'] );
	}

	public function test_domestic_half_up_tie_does_not_need_foreign_dependencies(): void {
		$result = ( new Calculator() )->evaluate(
			array(
				'price_source_kind'              => 'partner_price',
				'price_source_currency'          => 'IRR',
				'price_source_amount'            => '1234500',
				'weight_grams'                   => '1',
				'shipping_method_id'             => 'domestic',
				'shipping_price_per_kg'          => '0',
				'shipping_price_per_kg_currency' => 'IRR',
				'markup_percent'                 => '0',
				'price_rounding_digits'          => 2,
				'price_rounding_mode'            => 'nearest_half_up',
			)
		);
		self::assertSame( 123500, $result['value'] );
	}

	public function test_direct_sale_cannot_silently_round_fractional_toman(): void {
		$row = array(
			'price_source_kind'              => 'sale_price_direct',
			'price_source_currency'          => 'IRR',
			'price_source_amount'            => '12345',
			'weight_grams'                   => '1',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => '0',
			'shipping_price_per_kg_currency' => 'IRR',
		);
		self::assertFalse( ( new Calculator() )->evaluate( $row )['available'] );
		$row['price_source_amount'] = '12340';
		self::assertSame( 1234, ( new Calculator() )->evaluate( $row )['value'] );
	}

	public function test_zero_weight_is_unavailable_but_zero_stock_does_not_change_price(): void {
		$engine = new Calculator();
		self::assertFalse( $engine->evaluate( $this->foreign( array( 'weight_grams' => '0' ) ) )['available'] );
		self::assertSame( 2009410, $engine->evaluate( $this->foreign( array( 'total_stock' => 0 ) ) )['value'] );
	}

	public function test_every_price_route_requires_weight_but_domestic_needs_no_cny(): void {
		foreach ( array( 'foreign_price', 'partner_price', 'sale_price_direct' ) as $kind ) {
			$row = $this->foreign();
			if ( 'foreign_price' !== $kind ) {
				$row['price_source_kind'] = $kind;
				$row['price_source_currency'] = 'IRR';
				$row['price_source_amount'] = '10000';
				$row['shipping_method_id'] = 'domestic';
				$row['shipping_price_per_kg'] = '0';
				$row['shipping_price_per_kg_currency'] = 'IRR';
				unset( $row['irt_per_cny'] );
			}
			foreach ( array( null, '', '0' ) as $weight ) {
				$missing = $row;
				$missing['weight_grams'] = $weight;
				$result = ( new Calculator() )->evaluate( $missing );
				self::assertFalse( $result['available'], $kind );
				self::assertContains( 'weight_grams', $result['missing'], $kind );
			}
			$missing = $row;
			unset( $missing['weight_grams'] );
			self::assertFalse( ( new Calculator() )->evaluate( $missing )['available'], $kind );
			self::assertTrue( ( new Calculator() )->evaluate( $row )['available'], $kind );
		}
	}

	public function test_optional_provider_fields_do_not_change_the_calculation(): void {
		$engine = new Calculator();
		self::assertSame(
			$engine->evaluate( $this->foreign() ),
			$engine->evaluate(
				$this->foreign(
					array(
						'extra_provider_metadata' => array( 'source' => 'test' ),
						'schema'                  => 'descriptive only',
					)
				)
			)
		);
	}

	public function test_rounding_digits_do_not_coerce_invalid_input_types(): void {
		foreach ( array( true, false, 1.0, array(), new stdClass(), '1.0', '-1', '10' ) as $digits ) {
			$result = ( new Calculator() )->evaluate( $this->foreign( array( 'price_rounding_digits' => $digits ) ) );
			self::assertFalse( $result['available'] );
		}
	}

	public function test_invalid_currency_is_rejected_without_wordpress_error_dependency(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'price_source_currency' );
		( new Calculator() )->evaluate( $this->foreign( array( 'price_source_currency' => 'USD' ) ) );
	}
}
