<?php
/**
 * Read-only WooCommerce base-currency status for Digitalogic integrations.
 *
 * Digitalogic and Patris exchange final prices in IRT, where one IRT is one
 * Toman (ten Iranian Rials). This service observes WooCommerce's setting but
 * never changes it or any exchange-rate option.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and publishes read-only WooCommerce base-currency status.
 */
final class Digitalogic_WooCommerce_Currency_Status {

	public const OPTION_NAME          = 'woocommerce_currency';
	public const REQUIRED_CURRENCY    = 'IRT';
	public const PRICING_UNIT         = 'toman';
	public const IRR_PER_PRICING_UNIT = 10;
	public const COMPATIBLE_STATUS    = 'ready';
	public const INCOMPATIBLE_STATUS  = 'base_currency_mismatch';
	public const INCOMPATIBLE_WARNING = 'woocommerce_base_currency_must_be_irt';

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared status service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the observer. No option write is performed here or by its hook.
	 */
	private function __construct() {
		add_action(
			'updated_option_' . self::OPTION_NAME,
			array( $this, 'handle_currency_change' ),
			10,
			3
		);
	}

	/**
	 * Return current nonsecret WooCommerce currency and compatibility metadata.
	 *
	 * @return array
	 */
	public function get_status() {
		return $this->describe( $this->read_currency() );
	}

	/**
	 * Describe a currency code without reading or changing WordPress state.
	 *
	 * @param mixed $currency Currency code.
	 * @return array
	 */
	public function describe( $currency ) {
		$code       = $this->normalize_code( $currency );
		$compatible = self::REQUIRED_CURRENCY === $code;

		return array(
			'source'                      => 'woocommerce',
			'option'                      => self::OPTION_NAME,
			'code'                        => $code,
			'symbol'                      => $this->currency_symbol( $code ),
			'unit'                        => $compatible ? self::PRICING_UNIT : null,
			'irr_per_unit'                => $compatible ? self::IRR_PER_PRICING_UNIT : null,
			'price_decimals'              => $this->price_decimals(),
			'pricing_output_currency'     => self::REQUIRED_CURRENCY,
			'pricing_output_unit'         => self::PRICING_UNIT,
			'pricing_output_irr_per_unit' => self::IRR_PER_PRICING_UNIT,
			'compatible'                  => $compatible,
			'status'                      => $compatible ? self::COMPATIBLE_STATUS : self::INCOMPATIBLE_STATUS,
			'read_only'                   => true,
			'warnings'                    => $compatible ? array() : array( self::INCOMPATIBLE_WARNING ),
		);
	}

	/**
	 * Audit and publish a WooCommerce currency change without mutating it.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $new_value New option value.
	 * @param string $option Option name.
	 * @return void
	 */
	public function handle_currency_change( $old_value, $new_value, $option = self::OPTION_NAME ) {
		if ( self::OPTION_NAME !== $option ) {
			return;
		}

		$old_code = $this->normalize_code( $old_value );
		$new_code = $this->normalize_code( $new_value );

		if ( $old_code === $new_code ) {
			return;
		}

		$status = $this->describe( $new_code );
		if ( class_exists( 'Digitalogic_Logger' ) ) {
			try {
				Digitalogic_Logger::instance()->log(
					'woocommerce_currency_change',
					'option',
					null,
					$old_code,
					$new_code,
					sprintf(
						/* translators: 1: previous currency code, 2: new currency code. */
						__( 'WooCommerce base currency changed from %1$s to %2$s.', 'digitalogic' ),
						$old_code,
						$new_code
					)
				);
			} catch ( Throwable $exception ) {
				// Currency changes must not fail because the optional audit sink did.
				do_action( 'digitalogic_currency_monitoring_failed', get_class( $exception ) );
			}
		}

		do_action( 'digitalogic_woocommerce_currency_changed', $old_code, $new_code, $status );
	}

	/**
	 * Read WooCommerce's base currency with an IRT fallback for cold contexts.
	 *
	 * @return string
	 */
	private function read_currency() {
		$value = function_exists( 'get_woocommerce_currency' )
			? get_woocommerce_currency()
			: get_option( self::OPTION_NAME, self::REQUIRED_CURRENCY );

		return $this->normalize_code( $value );
	}

	/**
	 * Normalize a WooCommerce currency code without interpreting IRR as IRT.
	 *
	 * @param mixed $value Currency code.
	 * @return string
	 */
	private function normalize_code( $value ) {
		$value = is_scalar( $value ) ? strtoupper( trim( (string) $value ) ) : '';
		$value = preg_replace( '/[^A-Z0-9_-]/', '', $value );

		return '' === $value ? 'UNKNOWN' : $value;
	}

	/**
	 * Resolve a display symbol without making it part of compatibility logic.
	 *
	 * @param string $code Currency code.
	 * @return string
	 */
	private function currency_symbol( $code ) {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = (string) get_woocommerce_currency_symbol( $code );
			if ( '' !== $symbol ) {
				return $symbol;
			}
		}

		return $code;
	}

	/**
	 * Return the configured WooCommerce display precision.
	 *
	 * @return int
	 */
	private function price_decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? absint( wc_get_price_decimals() ) : 0;
	}
}
