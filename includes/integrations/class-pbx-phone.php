<?php
/**
 * Iranian phone-number normalization for PBX integrations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes Iranian mobile and fixed-line numbers without vendor coupling.
 */
final class Digitalogic_PBX_Phone {

	/** @var string[] Iranian fixed-line area codes in national format. */
	private const AREA_CODES = array(
		'011',
		'013',
		'017',
		'021',
		'023',
		'024',
		'025',
		'026',
		'028',
		'031',
		'034',
		'035',
		'038',
		'041',
		'044',
		'045',
		'051',
		'054',
		'056',
		'058',
		'061',
		'066',
		'071',
		'074',
		'076',
		'077',
		'081',
		'083',
		'084',
		'086',
		'087',
	);

	/** @var string[] Numbers that are service entry points, not customer contacts. */
	private const BLOCKED_NUMBERS = array( '+982166754123' );

	/**
	 * Normalize customer input to E.164.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty when invalid or unsupported.
	 */
	public static function normalize( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( self::ascii_digits( (string) $value ) );
		if ( '' === $value || 1 === preg_match( '/(?:ext\.?|extension|داخلی|#|\*)/iu', $value ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[0-9+().\-\s]+$/', $value ) ) {
			return '';
		}

		$compact = preg_replace( '/[().\-\s]+/', '', $value );
		if ( ! is_string( $compact ) || '' === $compact ) {
			return '';
		}

		if ( str_starts_with( $compact, '+' ) ) {
			if ( ! str_starts_with( $compact, '+98' ) ) {
				return '';
			}
			$national_significant = substr( $compact, 3 );
		} elseif ( str_starts_with( $compact, '0098' ) ) {
			$national_significant = substr( $compact, 4 );
		} elseif ( str_starts_with( $compact, '98' ) ) {
			$national_significant = substr( $compact, 2 );
		} elseif ( str_starts_with( $compact, '0' ) ) {
			$national_significant = substr( $compact, 1 );
		} else {
			return '';
		}

		if ( 1 !== preg_match( '/^[0-9]{10}$/', $national_significant ) ) {
			return '';
		}

		$national    = '0' . $national_significant;
		$is_mobile   = 1 === preg_match( '/^09[0-9]{9}$/', $national );
		$is_landline = ! $is_mobile && in_array( substr( $national, 0, 3 ), self::AREA_CODES, true );
		if ( ! $is_mobile && ! $is_landline ) {
			return '';
		}

		$e164 = '+98' . $national_significant;
		return in_array( $e164, self::BLOCKED_NUMBERS, true ) ? '' : $e164;
	}

	/**
	 * Normalize the carrier-provided ANI. Presentation punctuation is rejected.
	 *
	 * @param mixed $value Raw ANI.
	 * @return string
	 */
	public static function normalize_trusted_ani( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( self::ascii_digits( (string) $value ) );
		if ( 1 === preg_match( '/^[0-9]{8}$/', $value ) ) {
			return self::normalize( '021' . $value );
		}

		// TCI occasionally presents an international ANI as 098 + ten
		// significant digits.  This carrier-only form is deliberately not
		// accepted by normalize(), which handles untrusted customer input.
		if ( 1 === preg_match( '/^098([0-9]{10})$/', $value, $matches ) ) {
			return self::normalize( '98' . $matches[1] );
		}
		if ( 1 !== preg_match( '/^(?:\+98|0098|98|0)[0-9]+$/', $value ) ) {
			return '';
		}

		return self::normalize( $value );
	}

	/**
	 * Convert E.164 to national format.
	 *
	 * @param mixed $value Phone number.
	 * @return string
	 */
	public static function to_national( $value ): string {
		$e164 = self::normalize( $value );
		return '' === $e164 ? '' : '0' . substr( $e164, 3 );
	}

	/**
	 * Convert a verified number to the local Asterisk external-call route.
	 *
	 * @param mixed $value Phone number.
	 * @return string
	 */
	public static function to_pbx_target( $value ): string {
		$national = self::to_national( $value );
		return '' === $national ? '' : '9' . $national;
	}

	/**
	 * Format a canonical phone for display.
	 *
	 * @param mixed $value Phone number.
	 * @return string
	 */
	public static function display( $value ): string {
		$national = self::to_national( $value );
		if ( '' === $national ) {
			return '';
		}

		if ( str_starts_with( $national, '09' ) ) {
			return substr( $national, 0, 4 ) . '-' . substr( $national, 4, 3 ) . '-' . substr( $national, 7 );
		}

		return substr( $national, 0, 3 ) . '-' . substr( $national, 3 );
	}

	/**
	 * Return a privacy-preserving representation.
	 *
	 * @param mixed $value Phone number.
	 * @return string
	 */
	public static function mask( $value ): string {
		$national = self::to_national( $value );
		return '' === $national ? '' : substr( $national, 0, 3 ) . str_repeat( '*', 4 ) . substr( $national, -4 );
	}

	/**
	 * Translate Persian and Arabic-Indic digits.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	private static function ascii_digits( string $value ): string {
		return strtr(
			$value,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
	}
}
