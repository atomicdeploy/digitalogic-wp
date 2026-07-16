<?php
/**
 * Locale-aware number presentation helpers.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats legacy summary values without changing their currency semantics.
 */
final class Digitalogic_Number_Formatter {

	private const PERSIAN_DIGITS = array(
		'0' => '۰',
		'1' => '۱',
		'2' => '۲',
		'3' => '۳',
		'4' => '۴',
		'5' => '۵',
		'6' => '۶',
		'7' => '۷',
		'8' => '۸',
		'9' => '۹',
	);

	/**
	 * Format a legacy summary value as a rounded integer.
	 *
	 * This is presentation-only: it does not change stored values, currency
	 * codes, or WooCommerce symbols.
	 *
	 * @param int|float|numeric-string|null $number Number to display.
	 * @return string
	 */
	public static function format_integer( $number ) {
		$number    = is_numeric( $number ) ? (float) $number : 0.0;
		$formatted = number_format_i18n( round( $number, 0, PHP_ROUND_HALF_UP ), 0 );

		if ( ! self::uses_persian_digits() ) {
			return $formatted;
		}

		return strtr( $formatted, self::PERSIAN_DIGITS );
	}

	/**
	 * Determine whether the active request locale uses Persian digits.
	 *
	 * @return bool
	 */
	private static function uses_persian_digits() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = strtolower( str_replace( '_', '-', (string) $locale ) );

		return str_starts_with( $locale, 'fa' );
	}
}
