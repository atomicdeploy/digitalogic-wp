<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return isset($GLOBALS['digitalogic_test_locale'])
            ? (string) $GLOBALS['digitalogic_test_locale']
            : 'en_US';
    }
}

if (!function_exists('get_locale')) {
    function get_locale() {
        return determine_locale();
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0) {
        $locale = strtolower(str_replace('_', '-', determine_locale()));
        $persian = str_starts_with($locale, 'fa');

        return number_format(
            (float) $number,
            (int) $decimals,
            $persian ? '٫' : '.',
            $persian ? '٬' : ','
        );
    }
}

require_once dirname(__DIR__) . '/includes/class-digitalogic-number-formatter.php';

final class NumberFormatterTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_locale'] = 'en_US';
    }

    public function test_english_values_are_half_up_rounded_with_thousands_separators(): void {
        $this->assertSame('42,000', Digitalogic_Number_Formatter::format_integer('42000.49'));
        $this->assertSame('42,001', Digitalogic_Number_Formatter::format_integer('42000.50'));
        $this->assertSame('-1,235', Digitalogic_Number_Formatter::format_integer('-1234.5'));
    }

    public function test_persian_locales_use_persian_digits(): void {
        $GLOBALS['digitalogic_test_locale'] = 'fa_IR';
        $this->assertSame('۴۲٬۰۰۱', Digitalogic_Number_Formatter::format_integer('42000.5'));

        $GLOBALS['digitalogic_test_locale'] = 'fa_AF';
        $this->assertSame('۱٬۲۳۵', Digitalogic_Number_Formatter::format_integer('1234.5'));
    }

    public function test_invalid_or_missing_summary_values_render_as_zero(): void {
        $this->assertSame('0', Digitalogic_Number_Formatter::format_integer(null));
        $this->assertSame('0', Digitalogic_Number_Formatter::format_integer('not-a-number'));
    }

    public function test_legacy_views_share_the_formatter_and_drop_two_decimal_rendering(): void {
        $dashboard = file_get_contents(dirname(__DIR__) . '/includes/admin/views/dashboard.php');
        $status = file_get_contents(dirname(__DIR__) . '/includes/admin/views/status.php');

        $this->assertSame(3, substr_count($dashboard, 'Digitalogic_Number_Formatter::format_integer'));
        $this->assertSame(4, substr_count($status, 'Digitalogic_Number_Formatter::format_integer'));
        $this->assertStringNotContainsString('number_format($dollar_price, 2)', $dashboard);
        $this->assertStringNotContainsString('number_format_i18n($dollar_price, 2)', $status);
    }

    public function test_formatter_does_not_override_or_relabel_woocommerce_currency(): void {
        $formatter = file_get_contents(dirname(__DIR__) . '/includes/class-digitalogic-number-formatter.php');
        $plugin = file_get_contents(dirname(__DIR__) . '/digitalogic.php');

        $this->assertStringNotContainsString('woocommerce_currency_symbol', $plugin);
        $this->assertStringNotContainsString('get_woocommerce_currency', $formatter);
        $this->assertStringNotContainsString('IRR', $formatter);
        $this->assertStringNotContainsString('IRT', $formatter);
    }
}
