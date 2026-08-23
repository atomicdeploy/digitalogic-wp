<?php
/**
 * Keep machine dates Gregorian inside the Delivery Slots calculation path.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrows WP-Parsidate's conversion bypass to Delivery Slots machine formats.
 */
final class Digitalogic_Checkout_Date_Compatibility {
	private const TRACE_LIMIT = 24;

	/**
	 * Formats that Iconic Woo Delivery Slots uses as machine values.
	 *
	 * Display formats intentionally remain outside this list so customer-facing
	 * Jalali dates and Persian digits continue to work.
	 *
	 * @var array<string>
	 */
	private const MACHINE_DATE_FORMATS = array( 'Ymd', 'Y-m-d', 'md', 'w' );

	/**
	 * Register the narrow integration once.
	 */
	public static function init(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;
		add_filter(
			'wp_parsidate_hook_deactivator_check_disable',
			array( self::class, 'disable_parsidate_for_delivery_machine_date' )
		);
	}

	/**
	 * Ask WP-Parsidate to preserve the original Gregorian machine value.
	 *
	 * WP-Parsidate exposes this boolean filter immediately before converting a
	 * WordPress date. Returning true retains WordPress's already-formatted date;
	 * no date is recalculated and neither vendor plugin is modified.
	 *
	 * @param bool $disabled Whether another integration already disabled conversion.
	 */
	public static function disable_parsidate_for_delivery_machine_date( bool $disabled ): bool {
		if ( $disabled ) {
			return true;
		}

		return self::is_delivery_machine_date_trace( debug_backtrace( 0, self::TRACE_LIMIT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- The vendor filter provides no format or caller context.
	}

	/**
	 * Decide whether a bounded call trace represents a Delivery Slots machine date.
	 *
	 * Public for deterministic tests; callers should normally use the registered
	 * WP-Parsidate filter.
	 *
	 * @param array<int,array<string,mixed>> $trace Bounded PHP call trace.
	 */
	public static function is_delivery_machine_date_trace( array $trace ): bool {
		$format              = null;
		$delivery_slots_call = false;

		foreach ( $trace as $frame ) {
			$function = isset( $frame['function'] ) && is_string( $frame['function'] ) ? $frame['function'] : '';
			$class    = isset( $frame['class'] ) && is_string( $frame['class'] ) ? $frame['class'] : '';

			if ( null === $format && in_array( $function, array( 'date_i18n', 'wp_date' ), true ) ) {
				$args = isset( $frame['args'] ) && is_array( $frame['args'] ) ? $frame['args'] : array();
				if ( isset( $args[0] ) && is_string( $args[0] ) ) {
					$format = $args[0];
				}
			}

			if ( str_starts_with( $class, 'Iconic_WDS\\' ) ) {
				$delivery_slots_call = true;
			}
		}

		return $delivery_slots_call && in_array( $format, self::MACHINE_DATE_FORMATS, true );
	}
}
