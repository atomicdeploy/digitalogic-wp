<?php
/**
 * Dynamic Pricing Class
 *
 * Handles dynamic pricing calculations and display
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/pricing/Calculator.php';

class Digitalogic_Pricing {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Hook into WooCommerce pricing
		add_filter( 'woocommerce_product_get_price', array( $this, 'calculate_dynamic_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'calculate_dynamic_price' ), 10, 2 );
	}

	/**
	 * Calculate dynamic price based on currency rates
	 *
	 * @param float      $price
	 * @param WC_Product $product
	 * @return string|float Exact decimal price or the unchanged incoming price.
	 */
	public function calculate_dynamic_price( $price, $product ) {
		// Managed catalog prices have one canonical writer. Legacy runtime
		// formulas must not create a second customer-visible value.
		if (
			class_exists( 'Digitalogic_Patris_Price_Write_Guard' )
			&& Digitalogic_Patris_Price_Write_Guard::instance()->is_managed_product( $product )
		) {
			return $price;
		}

		// Check if dynamic pricing is enabled for this product
		$enable_dynamic = $product->get_meta( '_digitalogic_dynamic_pricing', true );

		if ( $enable_dynamic !== 'yes' ) {
			return $price;
		}

		$calculated_price = $this->evaluate_dynamic_price(
			$product->get_meta( '_digitalogic_currency_type', true ),
			$product->get_meta( '_digitalogic_base_price', true ),
			$product->get_meta( '_digitalogic_markup', true ),
			$product->get_meta( '_digitalogic_markup_type', true )
		);
		return null === $calculated_price ? $price : $calculated_price;
	}

	/**
	 * Update product price from foreign currency
	 *
	 * @param int    $product_id
	 * @param string $currency_type 'usd' or 'cny'
	 * @param float  $base_price
	 * @param float  $markup
	 * @param string $markup_type 'percentage' or 'fixed'
	 * @return bool
	 */
	public function set_dynamic_pricing( $product_id, $currency_type, $base_price, $markup = 0, $markup_type = 'percentage' ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}
		if (
			class_exists( 'Digitalogic_Patris_Price_Write_Guard' )
			&& Digitalogic_Patris_Price_Write_Guard::instance()->is_managed_product( $product )
		) {
			return false;
		}

		// Validate the complete policy before changing any product state.
		$calculated_price = $this->evaluate_dynamic_price( $currency_type, $base_price, $markup, $markup_type );
		if ( null === $calculated_price ) {
			return false;
		}
		$product->update_meta_data( '_digitalogic_dynamic_pricing', 'yes' );
		$product->update_meta_data( '_digitalogic_currency_type', $currency_type );
		$product->update_meta_data( '_digitalogic_base_price', $base_price );
		$product->update_meta_data( '_digitalogic_markup', $markup );
		$product->update_meta_data( '_digitalogic_markup_type', $markup_type );

		$product->set_regular_price( $calculated_price );
		// Save all product changes (meta data + price)
		$product->save();

		return true;
	}

	/**
	 * Adapt stored currency settings to the shared decimal policy.
	 *
	 * @param string $currency_type Stored currency identifier.
	 * @param mixed  $base_price Foreign currency amount.
	 * @param mixed  $markup Markup amount; an empty value means zero.
	 * @param string $markup_type Percentage or the existing fixed-amount mode.
	 * @return string|null Rounded shop-currency price, or invalid configuration.
	 */
	private function evaluate_dynamic_price( $currency_type, $base_price, $markup, $markup_type ) {
		$options = Digitalogic_Options::instance();
		switch ( $currency_type ) {
			case 'usd':
				$rate = $options->get_dollar_price();
				break;
			case 'cny':
				$rate = $options->get_yuan_price();
				break;
			default:
				return null;
		}
		try {
			$result = ( new \Digitalogic\Pricing\Calculator() )->evaluate_currency_markup(
				array(
					'currency'      => strtoupper( $currency_type ),
					'base_price'    => $base_price,
					'exchange_rate' => $rate,
					'markup'        => '' === $markup || null === $markup ? '0' : $markup,
					// Existing metadata interprets every non-percentage mode as fixed.
					'markup_type'   => 'percentage' === $markup_type ? 'percentage' : 'fixed',
				)
			);
			return $result['available'] ? $result['value'] : null;
		} catch ( \InvalidArgumentException $exception ) {
			return null;
		}
	}

	/**
	 * Bulk update prices based on new currency rates
	 *
	 * Note: For large catalogs, consider using WP-CLI command or paginating this operation
	 *
	 * @return array Results
	 */
	public function bulk_recalculate_prices() {
		// Get all products with dynamic pricing enabled using WooCommerce query
		// Note: Using limit -1 fetches all products. For large catalogs (>1000 products),
		// consider using the WP-CLI command which can handle timeouts better
		$args = array(
			'limit'      => -1,
			'return'     => 'ids',
			'meta_query' => array(
				array(
					'key'     => '_digitalogic_dynamic_pricing',
					'value'   => 'yes',
					'compare' => '=',
				),
			),
		);

		$product_ids = wc_get_products( $args );

		$results = array(
			'success' => 0,
			'failed'  => 0,
			'total'   => count( $product_ids ),
		);

		// Process products one by one to avoid memory issues
		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				++$results['failed'];
				continue;
			}

			$currency_type = $product->get_meta( '_digitalogic_currency_type', true );
			$base_price    = $product->get_meta( '_digitalogic_base_price', true );
			$markup        = $product->get_meta( '_digitalogic_markup', true );
			$markup_type   = $product->get_meta( '_digitalogic_markup_type', true );

			if ( $this->set_dynamic_pricing( $product_id, $currency_type, $base_price, $markup, $markup_type ) ) {
				++$results['success'];
			} else {
				++$results['failed'];
			}

			// Free memory after each product
			unset( $product );
		}

		// Log the bulk update
		Digitalogic_Logger::instance()->log(
			'bulk_recalculate_prices',
			'product',
			null,
			null,
			json_encode( $results ),
			'Bulk recalculated prices for ' . $results['success'] . ' products'
		);

		return $results;
	}
}
