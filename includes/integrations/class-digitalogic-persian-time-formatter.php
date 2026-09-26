<?php
/**
 * Human-friendly Persian time periods.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Format customer-facing dates and times without changing machine values. */
final class Digitalogic_Persian_Time_Formatter {

	/** Register global date/time and delivery-slot presentation filters. */
	public static function init(): void {
		add_filter( 'wp_date', array( self::class, 'humanize' ), 20 );
		add_filter( 'date_i18n', array( self::class, 'humanize' ), 20 );
		add_filter( 'iconic_wds_timeslot', array( self::class, 'format_timeslot' ) );
		add_filter( 'iconic_wds_timeslots', array( self::class, 'format_timeslots' ) );
		add_filter( 'iconic_wds_shortcode_get_order_date', array( self::class, 'format_date' ), 20 );
		add_filter( 'iconic_wds_shortcode_get_order_time', array( self::class, 'humanize' ), 20 );
		add_filter( 'iconic_wds_shortcode_get_order_date_time', array( self::class, 'format_date' ), 20 );
		add_filter( 'woocommerce_get_order_item_totals', array( self::class, 'format_order_totals' ), 99 );
	}

	/**
	 * Convert a customer-facing Gregorian date to one Persian Jalali format.
	 *
	 * Delivery Slots machine identifiers remain Gregorian ASCII and are handled
	 * separately by Digitalogic_Checkout_Date_Compatibility.
	 *
	 * @param mixed $value Customer-facing date or date/time text.
	 */
	public static function format_date( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return is_string( $value ) ? $value : '';
		}

		$ascii = strtr(
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

		$converted = preg_replace_callback(
			'/(?<!\d)(?<year>\d{4})[\/.\-](?<month>\d{1,2})[\/.\-](?<day>\d{1,2})(?!\d)/',
			array( self::class, 'replace_numeric_date' ),
			$ascii
		);
		$converted = is_string( $converted ) ? $converted : $ascii;
		$converted = preg_replace_callback(
			'/(?<!\d)(?<day>\d{1,2})[\/.\-](?<month>\d{1,2})[\/.\-](?<year>\d{4})(?!\d)/',
			array( self::class, 'replace_numeric_date' ),
			$converted
		);

		return self::persian_digits( self::humanize( is_string( $converted ) ? $converted : $ascii ) );
	}

	/**
	 * Replace Persian or English 12-hour markers according to the associated hour.
	 *
	 * @param string $value Formatted delivery time or time window.
	 */
	public static function humanize( string $value ): string {
		$result = preg_replace_callback(
			'/(?P<hour>[0-9۰-۹٠-٩]{1,2})(?P<minute>:[0-9۰-۹٠-٩]{2})\s*(?P<period>[قب]\s*\.\s*ظ\.?|[ap]\.?m\.?|(?:قبل|بعد)[\s‌]*از[\s‌]*ظهر)/iu',
			static function ( array $matches ): string {
				$hour   = (int) strtr(
					$matches['hour'],
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
				$period = strtolower( (string) preg_replace( '/[.\s‌]/u', '', $matches['period'] ) );
				$label  = 'صبح';
				if ( in_array( $period, array( 'بظ', 'pm', 'بعدازظهر' ), true ) ) {
					$hour_24 = 12 === $hour ? 12 : $hour + 12;
					$label   = $hour_24 < 16 ? 'ظهر' : 'عصر';
				}

				return $matches['hour'] . $matches['minute'] . ' ' . $label;
			},
			$value
		);

		return is_string( $result ) ? $result : $value;
	}

	/**
	 * Format one Delivery Slots timeslot without changing its identifier.
	 *
	 * @param mixed $timeslot Delivery Slots data.
	 * @return mixed
	 */
	public static function format_timeslot( $timeslot ) {
		if ( ! is_array( $timeslot ) ) {
			return $timeslot;
		}
		foreach ( array( 'formatted', 'formatted_with_fee' ) as $key ) {
			if ( isset( $timeslot[ $key ] ) && is_string( $timeslot[ $key ] ) ) {
				$timeslot[ $key ] = self::humanize( $timeslot[ $key ] );
			}
		}
		return $timeslot;
	}

	/**
	 * Format all Delivery Slots choices shown at checkout.
	 *
	 * @param mixed $timeslots Delivery Slots collection.
	 * @return mixed
	 */
	public static function format_timeslots( $timeslots ) {
		if ( ! is_array( $timeslots ) ) {
			return $timeslots;
		}
		foreach ( $timeslots as $key => $timeslot ) {
			$timeslots[ $key ] = self::format_timeslot( $timeslot );
		}
		return $timeslots;
	}

	/**
	 * Format the delivery-time row on thank-you and account order pages.
	 *
	 * @param mixed $rows WooCommerce order detail rows.
	 * @return mixed
	 */
	public static function format_order_totals( $rows ) {
		if ( isset( $rows['iconic_wds_order_date']['value'] ) && is_string( $rows['iconic_wds_order_date']['value'] ) ) {
			$rows['iconic_wds_order_date']['value'] = self::format_date( $rows['iconic_wds_order_date']['value'] );
		}
		if ( isset( $rows['iconic_wds_order_time']['value'] ) && is_string( $rows['iconic_wds_order_time']['value'] ) ) {
			$rows['iconic_wds_order_time']['value'] = self::humanize( $rows['iconic_wds_order_time']['value'] );
		}
		return $rows;
	}

	/**
	 * Replace one numeric date while preserving already-Jalali years.
	 *
	 * @param array<string,string> $matches Regex date components.
	 */
	private static function replace_numeric_date( array $matches ): string {
		$year  = (int) $matches['year'];
		$month = (int) $matches['month'];
		$day   = (int) $matches['day'];
		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return $matches[0];
		}

		if ( $year >= 1700 ) {
			list( $year, $month, $day ) = self::gregorian_to_jalali( $year, $month, $day );
		}

		return sprintf( '%04d/%02d/%02d', $year, $month, $day );
	}

	/**
	 * Convert a Gregorian date to the Solar Hijri calendar.
	 *
	 * @param int $year  Gregorian year.
	 * @param int $month Gregorian month.
	 * @param int $day   Gregorian day.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function gregorian_to_jalali( int $year, int $month, int $day ): array {
		$month_days = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		if ( $year > 1600 ) {
			$jalali_year = 979;
			$year       -= 1600;
		} else {
			$jalali_year = 0;
			$year       -= 621;
		}

		$adjusted_year = $month > 2 ? $year + 1 : $year;
		$days          = ( 365 * $year ) + intdiv( $adjusted_year + 3, 4 ) - intdiv( $adjusted_year + 99, 100 )
			+ intdiv( $adjusted_year + 399, 400 ) - 80 + $day + $month_days[ $month - 1 ];
		$jalali_year  += 33 * intdiv( $days, 12053 );
		$days         %= 12053;
		$jalali_year  += 4 * intdiv( $days, 1461 );
		$days         %= 1461;
		if ( $days > 365 ) {
			$jalali_year += intdiv( $days - 1, 365 );
			--$days;
			$days %= 365;
		}

		if ( $days < 186 ) {
			return array( $jalali_year, 1 + intdiv( $days, 31 ), 1 + ( $days % 31 ) );
		}

		return array( $jalali_year, 7 + intdiv( $days - 186, 30 ), 1 + ( ( $days - 186 ) % 30 ) );
	}

	/**
	 * Convert ASCII digits in a display string to Persian digits.
	 *
	 * @param string $value Display text.
	 */
	private static function persian_digits( string $value ): string {
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
}
