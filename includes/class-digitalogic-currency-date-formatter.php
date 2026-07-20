<?php
/**
 * Currency effective-date parsing and display.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the canonical parser and locale-aware formatter for currency dates.
 */
final class Digitalogic_Currency_Date_Formatter {

	/**
	 * Singleton instance.
	 *
	 * @var Digitalogic_Currency_Date_Formatter|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Digitalogic_Currency_Date_Formatter
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Read the unformatted effective date, preferring ACF's option storage.
	 *
	 * Reading the raw option avoids ACF/wp-parsidate turning a legacy YYMMDD
	 * value into a Unix timestamp before this formatter can validate it.
	 *
	 * @return string
	 */
	public function get_raw_update_date() {
		$value = get_option( 'options_update_date', false );

		if ( false === $value ) {
			$value = get_option( 'update_date', '' );
		}

		return $this->scalar_string( $value );
	}

	/**
	 * Parse a stored currency date into a site-timezone date at noon.
	 *
	 * Accepted values are legacy YYMMDD dates (interpreted as 20YY) and
	 * strict ISO 8601 dates or date-times. Invalid values never fall back to
	 * the current date or the Unix epoch.
	 *
	 * @param mixed $value Raw stored value.
	 * @return DateTimeImmutable|null
	 */
	public function parse( $value ) {
		$value = $this->normalize_digits( trim( $this->scalar_string( $value ) ) );

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/\A([0-9]{2})([0-9]{2})([0-9]{2})\z/D', $value, $matches ) ) {
			return $this->date_from_parts(
				2000 + (int) $matches[1],
				(int) $matches[2],
				(int) $matches[3]
			);
		}

		$iso_pattern = '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})(?:(?:T| )(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]{1,6})?)?(?:Z|[+-](?:(?:0[0-9]|1[0-3]):?[0-5][0-9]|14:?00))?)?\z/D';

		if ( preg_match( $iso_pattern, $value, $matches ) ) {
			return $this->date_from_parts(
				(int) $matches[1],
				(int) $matches[2],
				(int) $matches[3]
			);
		}

		return null;
	}

	/**
	 * Format a stored currency date for a locale.
	 *
	 * @param mixed       $value  Raw stored value.
	 * @param string      $format PHP date format.
	 * @param string|null $locale Optional locale override.
	 * @return string
	 */
	public function format( $value, $format = 'Y/m/d', $locale = null ) {
		$date = $this->parse( $value );

		if ( null === $date ) {
			return '';
		}

		return $this->format_date_object( $date, $format, $locale );
	}

	/**
	 * Format a date object or Unix timestamp through the same locale renderer.
	 *
	 * Six-digit strings and ISO inputs are first treated as currency effective
	 * dates so the historical YYMMDD value can never become an epoch-era date.
	 *
	 * @param mixed       $value  DateTimeInterface, Unix timestamp, or date string.
	 * @param string      $format PHP date format.
	 * @param string|null $locale Optional locale override.
	 * @return string
	 */
	public function format_timestamp( $value, $format = 'Y/m/d', $locale = null ) {
		$effective_date = $this->parse( $value );

		if ( null !== $effective_date ) {
			return $this->format_date_object( $effective_date, $format, $locale );
		}

		$normalized_value = $this->normalize_digits( trim( $this->scalar_string( $value ) ) );

		// A malformed value that looks like one of the supported storage formats
		// is invalid, not a Unix timestamp or a lenient DateTime input.
		if ( preg_match( '/\A(?:[0-9]{6}|[0-9]{4}-[0-9]{2}-[0-9]{2})/', $normalized_value ) ) {
			return '';
		}

		if ( $value instanceof DateTimeInterface ) {
			$date = DateTimeImmutable::createFromInterface( $value )->setTimezone( $this->site_timezone() );
		} elseif ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			if ( ! is_finite( (float) $value ) ) {
				return '';
			}

			$date = ( new DateTimeImmutable( '@' . (string) (int) $value ) )->setTimezone( $this->site_timezone() );
		} else {
			$value = trim( $this->scalar_string( $value ) );

			if ( '' === $value ) {
				return '';
			}

			try {
				$date = new DateTimeImmutable( $value, $this->site_timezone() );
			} catch ( Exception $exception ) {
				return '';
			}
		}

		return $this->format_date_object( $date, $format, $locale );
	}

	/**
	 * Format an immutable date for the requested locale.
	 *
	 * @param DateTimeImmutable $date   Date to render.
	 * @param string            $format PHP date format.
	 * @param string|null       $locale Optional locale override.
	 * @return string
	 */
	private function format_date_object( DateTimeImmutable $date, $format, $locale ) {
		$format = is_string( $format ) && '' !== $format ? $format : 'Y/m/d';
		$locale = $this->resolve_locale( $locale );

		if ( 0 === stripos( str_replace( '-', '_', $locale ), 'fa' ) ) {
			return $this->format_jalali( $date, $format );
		}

		return $date->format( $format );
	}

	/**
	 * Resolve the active WordPress locale.
	 *
	 * @param string|null $locale Optional locale override.
	 * @return string
	 */
	private function resolve_locale( $locale ) {
		if ( is_string( $locale ) && '' !== trim( $locale ) ) {
			return trim( $locale );
		}

		if ( function_exists( 'determine_locale' ) ) {
			$locale = determine_locale();
		} elseif ( function_exists( 'get_locale' ) ) {
			$locale = get_locale();
		}

		return is_string( $locale ) && '' !== $locale ? $locale : 'en_US';
	}

	/**
	 * Create a validated date at noon in the WordPress timezone.
	 *
	 * @param int $year  Gregorian year.
	 * @param int $month Gregorian month.
	 * @param int $day   Gregorian day.
	 * @return DateTimeImmutable|null
	 */
	private function date_from_parts( $year, $month, $day ) {
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			sprintf( '%04d-%02d-%02d 12:00:00', $year, $month, $day ),
			$this->site_timezone()
		);

		return false === $date ? null : $date;
	}

	/**
	 * Get the configured WordPress timezone, with a safe UTC fallback.
	 *
	 * @return DateTimeZone
	 */
	private function site_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			$timezone = wp_timezone();

			if ( $timezone instanceof DateTimeZone ) {
				return $timezone;
			}
		}

		return new DateTimeZone( 'UTC' );
	}

	/**
	 * Format a Gregorian date in the Jalali calendar with Persian digits.
	 *
	 * @param DateTimeImmutable $date   Gregorian date.
	 * @param string            $format PHP-compatible date format.
	 * @return string
	 */
	private function format_jalali( DateTimeImmutable $date, $format ) {
		list( $year, $month, $day ) = $this->gregorian_to_jalali(
			(int) $date->format( 'Y' ),
			(int) $date->format( 'n' ),
			(int) $date->format( 'j' )
		);

		$months   = array(
			'فروردین',
			'اردیبهشت',
			'خرداد',
			'تیر',
			'مرداد',
			'شهریور',
			'مهر',
			'آبان',
			'آذر',
			'دی',
			'بهمن',
			'اسفند',
		);
		$weekdays = array(
			'یکشنبه',
			'دوشنبه',
			'سه‌شنبه',
			'چهارشنبه',
			'پنجشنبه',
			'جمعه',
			'شنبه',
		);
		$tokens   = array(
			'Y' => sprintf( '%04d', $year ),
			'y' => sprintf( '%02d', $year % 100 ),
			'm' => sprintf( '%02d', $month ),
			'n' => (string) $month,
			'd' => sprintf( '%02d', $day ),
			'j' => (string) $day,
			'F' => $months[ $month - 1 ],
			'M' => $months[ $month - 1 ],
			'l' => $weekdays[ (int) $date->format( 'w' ) ],
			'D' => $weekdays[ (int) $date->format( 'w' ) ],
		);

		$output  = '';
		$escaped = false;
		$length  = strlen( $format );

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $format[ $index ];

			if ( $escaped ) {
				$output .= $character;
				$escaped = false;
				continue;
			}

			if ( '\\' === $character ) {
				$escaped = true;
				continue;
			}

			$output .= isset( $tokens[ $character ] )
				? $tokens[ $character ]
				: $date->format( $character );
		}

		if ( $escaped ) {
			$output .= '\\';
		}

		return $this->to_persian_digits( $output );
	}

	/**
	 * Convert Gregorian year/month/day values to their Jalali equivalents.
	 *
	 * @param int $gregorian_year  Gregorian year.
	 * @param int $gregorian_month Gregorian month.
	 * @param int $gregorian_day   Gregorian day.
	 * @return int[] Jalali year, month, and day.
	 */
	private function gregorian_to_jalali( $gregorian_year, $gregorian_month, $gregorian_day ) {
		$gregorian_month_days = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

		if ( $gregorian_year > 1600 ) {
			$jalali_year     = 979;
			$gregorian_year -= 1600;
		} else {
			$jalali_year     = 0;
			$gregorian_year -= 621;
		}

		$adjusted_year = $gregorian_month > 2 ? $gregorian_year + 1 : $gregorian_year;
		$days          = ( 365 * $gregorian_year )
			+ intdiv( $adjusted_year + 3, 4 )
			- intdiv( $adjusted_year + 99, 100 )
			+ intdiv( $adjusted_year + 399, 400 )
			- 80
			+ $gregorian_day
			+ $gregorian_month_days[ $gregorian_month - 1 ];

		$jalali_year += 33 * intdiv( $days, 12053 );
		$days         = $days % 12053;
		$jalali_year += 4 * intdiv( $days, 1461 );
		$days         = $days % 1461;

		if ( $days > 365 ) {
			$jalali_year += intdiv( $days - 1, 365 );
			--$days;
			$days = $days % 365;
		}

		if ( $days < 186 ) {
			$jalali_month = 1 + intdiv( $days, 31 );
			$jalali_day   = 1 + ( $days % 31 );
		} else {
			$jalali_month = 7 + intdiv( $days - 186, 30 );
			$jalali_day   = 1 + ( ( $days - 186 ) % 30 );
		}

		return array( $jalali_year, $jalali_month, $jalali_day );
	}

	/**
	 * Normalize Persian and Arabic-Indic numerals to ASCII.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	private function normalize_digits( $value ) {
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

	/**
	 * Convert ASCII digits in display output to Persian digits.
	 *
	 * @param string $value Display value.
	 * @return string
	 */
	private function to_persian_digits( $value ) {
		return strtr(
			$value,
			array(
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
			)
		);
	}

	/**
	 * Safely normalize scalar option values to strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function scalar_string( $value ) {
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		return '';
	}
}
