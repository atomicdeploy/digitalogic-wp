<?php
/**
 * Storefront currency shortcodes.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the versioned USD/CNY storefront cards.
 */
final class Digitalogic_Currency_Shortcodes {

	/**
	 * Singleton instance.
	 *
	 * @var Digitalogic_Currency_Shortcodes|null
	 */
	private static $instance = null;

	/**
	 * Currency options service.
	 *
	 * @var Digitalogic_Options
	 */
	private $options;

	/**
	 * Get the singleton instance.
	 *
	 * @return Digitalogic_Currency_Shortcodes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self( Digitalogic_Options::instance() );
		}

		return self::$instance;
	}

	/**
	 * Construct the shortcode registrar.
	 *
	 * @param Digitalogic_Options $options Currency options service.
	 */
	private function __construct( Digitalogic_Options $options ) {
		$this->options = $options;

		// Run after child-theme file-level registrations so the versioned plugin
		// implementation remains authoritative during the migration from theme code.
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	/**
	 * Register the public currency-card shortcodes.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'dollar_rate', array( $this, 'render_dollar_rate' ) );
		add_shortcode( 'yuan_rate', array( $this, 'render_yuan_rate' ) );
	}

	/**
	 * Render the USD exchange-rate card.
	 *
	 * @return string
	 */
	public function render_dollar_rate() {
		return $this->render_card( 'USD', $this->options->get_dollar_price(), '$', 'us.svg' );
	}

	/**
	 * Render the CNY exchange-rate card.
	 *
	 * @return string
	 */
	public function render_yuan_rate() {
		return $this->render_card( 'CNY', $this->options->get_yuan_price(), '¥', 'cn.svg' );
	}

	/**
	 * Render one currency card with the canonical effective-date formatter.
	 *
	 * @param string $currency Currency code.
	 * @param float  $rate     Exchange rate.
	 * @param string $symbol   Display symbol.
	 * @param string $flag     Flag filename in the site's uploads directory.
	 * @return string
	 */
	private function render_card( $currency, $rate, $symbol, $flag ) {
		$date_format = apply_filters( 'digitalogic_currency_card_date_format', 'Y/m/d', $currency );
		$date        = $this->options->get_update_date_formatted( $date_format );
		$flag_url    = content_url( '/uploads/2025/10/' . $flag );
		$flag_url    = apply_filters( 'digitalogic_currency_card_flag_url', $flag_url, $currency );

		return sprintf(
			'<div class="currency-box"><div class="flag-circle"><img src="%1$s" alt="%2$s" width="24" height="24"></div><div class="currency-info"><div dir="ltr" class="price">%3$s %4$s</div><div dir="ltr" class="date">%5$s</div></div></div>',
			esc_url( $flag_url ),
			esc_attr( $currency ),
			esc_html( number_format( (float) $rate ) ),
			esc_html( $symbol ),
			esc_html( $date )
		);
	}
}
