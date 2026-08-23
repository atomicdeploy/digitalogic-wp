<?php
/**
 * Checkout date compatibility tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verify the WP-Parsidate bypass is exact and fail-closed.
 */
final class CheckoutDateCompatibilityTest extends TestCase {
	/** The filter registers exactly once on its Living hook. */
	public function test_init_registers_the_filter_once(): void {
		$before = count( $GLOBALS['digitalogic_test_filters']['wp_parsidate_hook_deactivator_check_disable'] ?? array() );

		Digitalogic_Checkout_Date_Compatibility::init();
		Digitalogic_Checkout_Date_Compatibility::init();

		$registered = $GLOBALS['digitalogic_test_filters']['wp_parsidate_hook_deactivator_check_disable'] ?? array();
		$this->assertCount( $before + 1, $registered );
		$this->assertSame(
			array( Digitalogic_Checkout_Date_Compatibility::class, 'disable_parsidate_for_delivery_machine_date' ),
			$registered[ $before ]['callback']
		);
	}

	/** An earlier integration's decision is never weakened. */
	public function test_existing_disable_decision_is_preserved(): void {
		$this->assertTrue( Digitalogic_Checkout_Date_Compatibility::disable_parsidate_for_delivery_machine_date( true ) );
	}

	/** A normal test/runtime caller cannot disable WP-Parsidate accidentally. */
	public function test_filter_fails_closed_outside_delivery_slots(): void {
		$this->assertFalse( Digitalogic_Checkout_Date_Compatibility::disable_parsidate_for_delivery_machine_date( false ) );
	}

	/**
	 * Delivery Slots receives Gregorian ASCII values for every machine format it uses.
	 *
	 * @param string $date_function WordPress date function.
	 * @param string $format        Machine format.
	 */
	#[DataProvider( 'machine_format_provider' )]
	public function test_delivery_slots_machine_formats_bypass_parsidate( string $date_function, string $format ): void {
		$this->assertTrue(
			Digitalogic_Checkout_Date_Compatibility::is_delivery_machine_date_trace(
				self::trace( $date_function, $format, 'Iconic_WDS\\Dates' )
			)
		);
	}

	/** Machine-format coverage from the active Delivery Slots calculation paths. */
	public static function machine_format_provider(): array {
		return array(
			'date_i18n compact date' => array( 'date_i18n', 'Ymd' ),
			'wp_date compact date'   => array( 'wp_date', 'Ymd' ),
			'ISO date'               => array( 'date_i18n', 'Y-m-d' ),
			'month and day'          => array( 'date_i18n', 'md' ),
			'weekday number'         => array( 'wp_date', 'w' ),
		);
	}

	/**
	 * Customer-facing Jalali/Persian display dates stay enabled.
	 *
	 * @param string $format Customer-facing format.
	 */
	#[DataProvider( 'display_format_provider' )]
	public function test_delivery_slots_display_formats_do_not_bypass_parsidate( string $format ): void {
		$this->assertFalse(
			Digitalogic_Checkout_Date_Compatibility::is_delivery_machine_date_trace(
				self::trace( 'date_i18n', $format, 'Iconic_WDS\\Dates' )
			)
		);
	}

	/** Formats customers see must remain localized. */
	public static function display_format_provider(): array {
		return array(
			'checkout date' => array( 'd/m/Y' ),
			'date label'    => array( 'D, jS M' ),
			'long label'    => array( 'd/m/Y l w' ),
			'admin label'   => array( 'F j, Y' ),
		);
	}

	/** Unrelated plugins retain WP-Parsidate conversion even for the same format. */
	public function test_unrelated_machine_date_does_not_bypass_parsidate(): void {
		$this->assertFalse(
			Digitalogic_Checkout_Date_Compatibility::is_delivery_machine_date_trace(
				self::trace( 'date_i18n', 'Ymd', 'Unrelated\\Calendar' )
			)
		);
	}

	/** A Delivery Slots frame without an observable WordPress format fails closed. */
	public function test_missing_date_frame_does_not_bypass_parsidate(): void {
		$this->assertFalse(
			Digitalogic_Checkout_Date_Compatibility::is_delivery_machine_date_trace(
				array(
					array(
						'class'    => 'Iconic_WDS\\Dates',
						'function' => 'get_upcoming_bookable_dates',
					),
				)
			)
		);
	}

	/**
	 * Build the relevant subset of a PHP date filter trace.
	 *
	 * @param string $date_function WordPress date function.
	 * @param string $format        Requested format.
	 * @param string $caller_class  Calling class.
	 */
	private static function trace( string $date_function, string $format, string $caller_class ): array {
		return array(
			array(
				'function' => 'apply_filters',
				'args'     => array( 'wp_parsidate_hook_deactivator_check_disable', false ),
			),
			array(
				'function' => $date_function,
				'args'     => array( $format, 1787497421 ),
			),
			array(
				'class'    => $caller_class,
				'function' => 'get_upcoming_bookable_dates',
			),
		);
	}
}
