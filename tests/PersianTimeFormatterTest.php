<?php
/**
 * Tests for the general contextual Persian time formatter.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-persian-time-formatter.php';

/** Verify hour-aware labels across every supported numeral system. */
final class PersianTimeFormatterTest extends TestCase {
	/** Reset the lightweight WordPress filter registry. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_filters'] = array();
	}

	/** The general WordPress date formatters share the same presentation rule. */
	public function test_registers_global_wordpress_time_filters(): void {
		Digitalogic_Persian_Time_Formatter::init();

		$this->assertArrayHasKey( 'wp_date', $GLOBALS['digitalogic_test_filters'] );
		$this->assertArrayHasKey( 'date_i18n', $GLOBALS['digitalogic_test_filters'] );
	}

	/** Morning markers become the full Persian word. */
	public function test_morning_period(): void {
		$this->assertSame( '07:00 صبح - 07:30 صبح', Digitalogic_Persian_Time_Formatter::humanize( '07:00 ق.ظ. - 07:30 ق.ظ.' ) );
	}

	/** Noon and afternoon labels are selected from the associated hour. */
	public function test_post_meridiem_period_depends_on_hour(): void {
		$this->assertSame( '12:00 ظهر - 03:30 ظهر', Digitalogic_Persian_Time_Formatter::humanize( '12:00 ب.ظ. - 03:30 ب.ظ.' ) );
		$this->assertSame( '04:00 عصر - 07:30 عصر', Digitalogic_Persian_Time_Formatter::humanize( '04:00 ب.ظ. - 07:30 ب.ظ.' ) );
	}

	/** Persian and Arabic digits remain unchanged in the visible value. */
	public function test_localized_digits_are_preserved(): void {
		$this->assertSame( '۱۱:۳۰ صبح تا ۱۲:۳۰ ظهر', Digitalogic_Persian_Time_Formatter::humanize( '۱۱:۳۰ ق.ظ تا ۱۲:۳۰ ب.ظ' ) );
		$this->assertSame( '٠٤:٠٠ عصر', Digitalogic_Persian_Time_Formatter::humanize( '٠٤:٠٠ ب.ظ.' ) );
	}

	/** English 12-hour markers from nonlocalized plugins use the same labels. */
	public function test_english_meridiems_are_humanized(): void {
		$this->assertSame( '09:15 صبح - 12:30 ظهر', Digitalogic_Persian_Time_Formatter::humanize( '09:15 AM - 12:30 p.m.' ) );
		$this->assertSame( '05:45 عصر', Digitalogic_Persian_Time_Formatter::humanize( '05:45 PM' ) );
	}

	/** Vendor slot presentation changes without touching the stable slot value. */
	public function test_timeslot_identifier_is_preserved(): void {
		$slot = Digitalogic_Persian_Time_Formatter::format_timeslot(
			array(
				'value'              => 'slot-4|0.00',
				'formatted'          => '04:00 ب.ظ. - 05:00 ب.ظ.',
				'formatted_with_fee' => '04:00 ب.ظ. - 05:00 ب.ظ. (+۲۰٬۰۰۰ تومان)',
			)
		);

		$this->assertSame( 'slot-4|0.00', $slot['value'] );
		$this->assertSame( '04:00 عصر - 05:00 عصر', $slot['formatted'] );
		$this->assertSame( '04:00 عصر - 05:00 عصر (+۲۰٬۰۰۰ تومان)', $slot['formatted_with_fee'] );
	}
}
