<?php

use Digitalogic\Pricing\Calculator;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-pricing.php';

final class DynamicCurrencyPricingTest extends TestCase {

	protected function setUp(): void {
		foreach ( array( 'digitalogic_test_options', 'digitalogic_test_option_cache', 'digitalogic_test_posts', 'digitalogic_test_wc_products' ) as $key ) {
			$GLOBALS[ $key ] = array();
		}
		update_option( 'options_dollar_price', '1.25' );
		update_option( 'options_yuan_price', '2.5' );
		$GLOBALS['digitalogic_test_posts'][991] = array(
			'post_type' => 'product',
			'meta'      => array(),
		);
	}

	private function policy( array $changes = array() ): array {
		return array_replace(
			array(
				'currency'      => 'USD',
				'base_price'    => '10',
				'exchange_rate' => '1.25',
				'markup'        => '10',
				'markup_type'   => 'percentage',
			),
			$changes
		);
	}

	public function test_exact_decimal_policy_rounds_once_and_preserves_large_decimal_values(): void {
		$cases = array(
			array( array(), '13.75' ),
			array(
				array(
					'base_price'    => '1.005',
					'exchange_rate' => '1',
					'markup'        => '0',
				),
				'1.01',
			),
			array(
				array(
					'base_price'    => '0.004',
					'exchange_rate' => '1',
					'markup'        => '100',
				),
				'0.01',
			),
			array(
				array(
					'currency'      => 'CNY',
					'base_price'    => '2.345',
					'exchange_rate' => '2',
					'markup'        => '0.015',
					'markup_type'   => 'fixed',
				),
				'4.71',
			),
			array(
				array(
					'base_price'    => '999999999999999.99',
					'exchange_rate' => '1',
					'markup'        => '0',
				),
				'999999999999999.99',
			),
			array(
				array(
					'base_price'    => '1',
					'exchange_rate' => '1',
					'markup'        => '1500',
				),
				'16.00',
			),
		);
		foreach ( $cases as list( $changes, $expected ) ) {
			$result = ( new Calculator() )->evaluate_currency_markup( $this->policy( $changes ) );
			self::assertTrue( $result['available'] );
			self::assertSame( $expected, $result['value'] );
		}
	}

	public function test_read_and_write_share_currency_selection_markup_and_rounding(): void {
		$pricing = Digitalogic_Pricing::instance();
		$cases   = array(
			array( 'usd', '1.004', '0', 'percentage', '1.26' ),
			array( 'cny', '2.345', '0.015', 'fixed', '5.88' ),
			array( 'usd', '10', '10', 'percentage', '13.75' ),
			array( 'usd', '10', '', '', '12.50' ),
			array( 'usd', '10', '2', 'existing-fixed-mode', '14.50' ),
		);
		foreach ( $cases as list( $currency, $base, $markup, $mode, $expected ) ) {
			self::assertTrue( $pricing->set_dynamic_pricing( 991, $currency, $base, $markup, $mode ) );
			$product = wc_get_product( 991 );
			self::assertSame( $expected, $product->get_regular_price() );
			self::assertSame( $expected, $pricing->calculate_dynamic_price( 'old', $product ) );
		}
	}

	public function test_invalid_configuration_never_mutates_or_saves_product(): void {
		$pricing = Digitalogic_Pricing::instance();
		self::assertTrue( $pricing->set_dynamic_pricing( 991, 'usd', '10', '10', 'percentage' ) );
		$product = wc_get_product( 991 );
		$before  = $product->meta;
		$saves   = $product->save_count;
		foreach ( array( array( 'eur', '10', '0' ), array( 'usd', 'bad', '0' ), array( 'usd', '0', '0' ), array( 'usd', '10', '-1' ), array( 'usd', '10', INF ) ) as list( $currency, $base, $markup ) ) {
			self::assertFalse( $pricing->set_dynamic_pricing( 991, $currency, $base, $markup ) );
			self::assertSame( $before, $product->meta );
			self::assertSame( $saves, $product->save_count );
		}
		update_option( 'options_dollar_price', 0 );
		self::assertFalse( $pricing->set_dynamic_pricing( 991, 'usd', '10' ) );
		self::assertSame( $before, $product->meta );
		self::assertSame( $saves, $product->save_count );
		self::assertSame( 'fallback', $pricing->calculate_dynamic_price( 'fallback', $product ) );
	}

	public function test_disabled_or_managed_products_keep_existing_price_and_owner(): void {
		$pricing = Digitalogic_Pricing::instance();
		$product = wc_get_product( 991 );
		self::assertSame( 'old', $pricing->calculate_dynamic_price( 'old', $product ) );
		$product->update_meta_data( '_digitalogic_patris_product_code', 'TEST-991' );
		$product->update_meta_data( '_digitalogic_patris_record_hash', 'sha256:' . str_repeat( 'a', 64 ) );
		$product->update_meta_data( '_digitalogic_dynamic_pricing', 'yes' );
		self::assertFalse( $pricing->set_dynamic_pricing( 991, 'usd', '10' ) );
		self::assertSame( 'canonical', $pricing->calculate_dynamic_price( 'canonical', $product ) );
		self::assertSame( 0, $product->save_count );
	}

	public function test_policy_validates_its_explicit_modes_and_missing_fields(): void {
		$result = ( new Calculator() )->evaluate_currency_markup( array() );
		self::assertFalse( $result['available'] );
		self::assertContains( 'exchange_rate', $result['missing'] );
		$this->expectException( InvalidArgumentException::class );
		( new Calculator() )->evaluate_currency_markup( $this->policy( array( 'markup_type' => 'unknown' ) ) );
	}
}
