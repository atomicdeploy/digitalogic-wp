<?php
// phpcs:ignoreFile

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyDateFormatterTest extends TestCase {
    private Digitalogic_Currency_Date_Formatter $formatter;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_locale'] = 'en_US';
        $this->formatter = Digitalogic_Currency_Date_Formatter::instance();
    }

    public function test_legacy_yymmdd_is_a_real_calendar_date_instead_of_a_unix_timestamp(): void {
        $date = $this->formatter->parse('260629');

        $this->assertInstanceOf(DateTimeImmutable::class, $date);
        $this->assertSame('2026-06-29 12:00:00 Asia/Tehran', $date->format('Y-m-d H:i:s e'));
        $this->assertSame('2026/06/29', $this->formatter->format('260629', 'Y/m/d', 'en_US'));
        $this->assertSame('۱۴۰۵/۰۴/۰۸', $this->formatter->format('260629', 'Y/m/d', 'fa_IR'));
    }

    public function test_iso_dates_and_datetimes_preserve_the_encoded_calendar_day(): void {
        $values = array(
            '2026-06-29',
            '2026-06-29T23:59+03:30',
            '2026-06-29T23:59:59.123456Z',
            '2026-06-29 00:00:00-0330',
        );

        foreach ($values as $value) {
            $this->assertSame('2026/06/29', $this->formatter->format($value, 'Y/m/d', 'en_US'), $value);
            $this->assertSame('۱۴۰۵/۰۴/۰۸', $this->formatter->format($value, 'Y/m/d', 'fa_IR'), $value);
        }
    }

    public function test_persian_and_arabic_indic_input_digits_are_normalized(): void {
        $this->assertSame('۱۴۰۵/۰۴/۰۸', $this->formatter->format('۲۶۰۶۲۹', 'Y/m/d', 'fa_IR'));
        $this->assertSame('2026/06/29', $this->formatter->format('٢٠٢٦-٠٦-٢٩', 'Y/m/d', 'en_US'));
    }

    public function test_jalali_conversion_is_correct_at_the_new_year_boundary(): void {
        $this->assertSame('۱۴۰۵/۰۱/۰۱', $this->formatter->format('2026-03-21', 'Y/m/d', 'fa_IR'));
        $this->assertSame('۱ فروردین ۱۴۰۵', $this->formatter->format('2026-03-21', 'j F Y', 'fa_AF'));
    }

    #[DataProvider('invalidDateProvider')]
    public function test_invalid_and_empty_values_are_blank_without_today_or_epoch_fallback(mixed $value): void {
        $this->assertNull($this->formatter->parse($value));
        $this->assertSame('', $this->formatter->format($value, 'Y/m/d', 'en_US'));
        $this->assertSame('', $this->formatter->format($value, 'Y/m/d', 'fa_IR'));
    }

    public static function invalidDateProvider(): array {
        return array(
            'empty' => array(''),
            'whitespace' => array('   '),
            'null' => array(null),
            'boolean' => array(false),
            'array' => array(array('260629')),
            'bad legacy month' => array('261329'),
            'bad legacy leap day' => array('250229'),
            'bad ISO day' => array('2026-06-31'),
            'bad ISO time' => array('2026-06-29T24:00:00Z'),
            'bad ISO offset' => array('2026-06-29T12:00:00+14:01'),
            'trailing data' => array('2026-06-29 garbage'),
            'epoch-like junk' => array('not-a-date'),
        );
    }

    public function test_raw_option_reader_always_prefers_unformatted_acf_storage(): void {
        $GLOBALS['digitalogic_test_options'] = array(
            'options_update_date' => '260629',
            'update_date' => '1999-01-01',
        );

        $this->assertSame('260629', $this->formatter->get_raw_update_date());

        $GLOBALS['digitalogic_test_options']['options_update_date'] = '';
        $this->assertSame('', $this->formatter->get_raw_update_date());

        unset($GLOBALS['digitalogic_test_options']['options_update_date']);
        $this->assertSame('1999-01-01', $this->formatter->get_raw_update_date());
    }

    public function test_legacy_static_formatter_routes_six_digit_values_through_the_safe_parser(): void {
        $GLOBALS['digitalogic_test_locale'] = 'fa_IR';

        $this->assertSame('۱۴۰۵/۰۴/۰۸', $this->formatter->format_timestamp('260629'));
        $this->assertSame('', $this->formatter->format_timestamp('260230'));
        $this->assertSame('', $this->formatter->format_timestamp(260230));
        $this->assertSame('', $this->formatter->format_timestamp('2026-02-30'));
        $this->assertSame('', $this->formatter->format_timestamp(''));
        $this->assertSame('', $this->formatter->format_timestamp('definitely-invalid'));
    }
}
