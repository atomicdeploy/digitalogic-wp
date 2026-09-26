<?php
/**
 * Human-friendly Persian time periods.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Replace abbreviated 12-hour meridiems with contextual Persian day periods. */
final class Digitalogic_Persian_Time_Formatter {

	/** Register global date/time and delivery-slot presentation filters. */
	public static function init(): void {
		add_filter( 'wp_date', array( self::class, 'humanize' ), 20 );
		add_filter( 'date_i18n', array( self::class, 'humanize' ), 20 );
		add_filter( 'iconic_wds_timeslot', array( self::class, 'format_timeslot' ) );
		add_filter( 'iconic_wds_timeslots', array( self::class, 'format_timeslots' ) );
		add_filter( 'iconic_wds_shortcode_get_order_time', array( self::class, 'humanize' ), 20 );
		add_filter( 'iconic_wds_shortcode_get_order_date_time', array( self::class, 'humanize' ), 20 );
		add_filter( 'woocommerce_get_order_item_totals', array( self::class, 'format_order_totals' ), 99 );
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
		if ( isset( $rows['iconic_wds_order_time']['value'] ) && is_string( $rows['iconic_wds_order_time']['value'] ) ) {
			$rows['iconic_wds_order_time']['value'] = self::humanize( $rows['iconic_wds_order_time']['value'] );
		}
		return $rows;
	}
}
