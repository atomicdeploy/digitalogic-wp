<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

final class CurrencyDateIntegrationTest extends TestCase {
    public function test_submission_date_uses_tehran_day_and_survives_delayed_execution(): void {
        $coordinator = Digitalogic_Pricing_Coordinator::instance();
        $current = array('dollar_price' => 100, 'yuan_price' => 20);
        $submittedAt = strtotime('2026-09-08T21:00:00Z');
        $submitted = array('dollar_price' => 101, 'yuan_price' => 21, 'usd_effective_date' => '2026-08-01');
        $resolved = $coordinator->currency_submission_dates($submitted, $current, $submittedAt);
        $this->assertSame('2026-08-01', $resolved['usd_effective_date']);
        $this->assertSame('2026-09-09', $resolved['cny_effective_date']);
        $this->assertSame($resolved, $coordinator->currency_submission_dates($resolved, $current, $submittedAt + 86400));
        $this->assertSame(array('yuan_price' => 20), $coordinator->currency_submission_dates(array('yuan_price' => 20), $current, $submittedAt));
        $explicit = array('yuan_price' => 21, 'effective_date' => '2026-08-01');
        $this->assertSame($explicit, $coordinator->currency_submission_dates($explicit, $current, $submittedAt));
    }

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array(
            'options_dollar_price' => 71500,
            'options_yuan_price' => 9875,
            'options_update_date' => '260629',
        );
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_locale'] = 'en_US';
        $GLOBALS['digitalogic_test_shortcodes'] = array();
    }

    public function test_storefront_shortcodes_register_the_production_callbacks(): void {
        $shortcodes = Digitalogic_Currency_Shortcodes::instance();
        $shortcodes->register();

        $this->assertArrayHasKey('dollar_rate', $GLOBALS['digitalogic_test_shortcodes']);
        $this->assertArrayHasKey('yuan_rate', $GLOBALS['digitalogic_test_shortcodes']);
        $this->assertSame(array($shortcodes, 'render_dollar_rate'), $GLOBALS['digitalogic_test_shortcodes']['dollar_rate']);
        $this->assertSame(array($shortcodes, 'render_yuan_rate'), $GLOBALS['digitalogic_test_shortcodes']['yuan_rate']);
    }

    public function test_storefront_and_options_service_share_the_english_formatter(): void {
        $options = Digitalogic_Options::instance();
        $date = $options->get_update_date_formatted();
        $dollar = Digitalogic_Currency_Shortcodes::instance()->render_dollar_rate();
        $yuan = Digitalogic_Currency_Shortcodes::instance()->render_yuan_rate();

        $this->assertSame('2026/06/29', $date);
        $this->assertStringContainsString('<div dir="ltr" class="price" data-digitalogic-currency-rate aria-live="polite" aria-atomic="true">71,500 $</div>', $dollar);
        $this->assertStringContainsString('<div dir="ltr" class="price" data-digitalogic-currency-rate aria-live="polite" aria-atomic="true">9,875 ¥</div>', $yuan);
        $this->assertStringContainsString('<div dir="ltr" class="date" data-digitalogic-currency-date>' . $date . '</div>', $dollar);
        $this->assertStringContainsString('<div dir="ltr" class="date" data-digitalogic-currency-date>' . $date . '</div>', $yuan);
    }

    public function test_storefront_and_options_service_share_the_persian_jalali_formatter(): void {
        $GLOBALS['digitalogic_test_locale'] = 'fa_IR';

        $date = Digitalogic_Options::instance()->get_update_date_formatted();
        $output = Digitalogic_Currency_Shortcodes::instance()->render_yuan_rate();

        $this->assertSame('۱۴۰۵/۰۴/۰۸', $date);
        $this->assertStringContainsString('<div dir="ltr" class="date" data-digitalogic-currency-date>۱۴۰۵/۰۴/۰۸</div>', $output);
        $this->assertStringNotContainsString('۱۳۴۸', $output);
    }

    public function test_invalid_stored_date_renders_an_empty_safe_date_in_both_layers(): void {
        $GLOBALS['digitalogic_test_options']['options_update_date'] = '260230';

        $this->assertSame('', Digitalogic_Options::instance()->get_update_date_formatted());
        $this->assertStringContainsString(
            '<div dir="ltr" class="date" data-digitalogic-currency-date></div>',
            Digitalogic_Currency_Shortcodes::instance()->render_dollar_rate()
        );
    }

    public function test_currency_cards_keep_live_markup_and_use_site_relative_flag_assets(): void {
        $output = Digitalogic_Currency_Shortcodes::instance()->render_dollar_rate();

        $this->assertStringContainsString('class="currency-box"', $output);
        $this->assertStringContainsString('data-digitalogic-currency="USD"', $output);
        $this->assertStringContainsString('aria-live="polite" aria-atomic="true"', $output);
        $this->assertStringContainsString('class="flag-circle"', $output);
        $this->assertStringContainsString('class="currency-info"', $output);
        $this->assertStringContainsString('https://digitalogic.test/wp-content/uploads/2025/10/us.svg', $output);
        $this->assertStringContainsString('alt="USD" width="24" height="24"', $output);
    }

    public function test_admin_panel_and_storefront_all_route_through_the_options_formatter(): void {
        $admin = file_get_contents(dirname(__DIR__) . '/includes/admin/class-admin.php');
        $status = file_get_contents(dirname(__DIR__) . '/includes/admin/views/status.php');
        $panel = file_get_contents(dirname(__DIR__) . '/includes/panel/class-panel.php');
        $dispatcher = file_get_contents(dirname(__DIR__) . '/includes/class-command-dispatcher.php');
        $options = file_get_contents(dirname(__DIR__) . '/includes/class-options.php');
        $shortcodes = file_get_contents(dirname(__DIR__) . '/includes/class-digitalogic-currency-shortcodes.php');

        $this->assertSame(2, substr_count($admin, 'get_update_date_formatted()'));
        $this->assertSame(1, substr_count($status, 'get_update_date_formatted()'));
        $this->assertSame(0, substr_count($panel, 'get_update_date_formatted()'));
        $this->assertSame(1, substr_count($dispatcher, 'get_update_date_formatted()'));
        $this->assertStringContainsString('Digitalogic_Currency_Date_Formatter::instance()->format(', $options);
        $this->assertStringContainsString('$this->options->get_update_date_formatted( $date_format )', $shortcodes);
        $this->assertStringNotContainsString('parsidate', $shortcodes);
        $this->assertStringNotContainsString('strtotime', $shortcodes);
    }
}
