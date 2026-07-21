<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

if (!defined('DIGITALOGIC_PLUGIN_URL')) {
    define('DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/');
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return false;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://digitalogic.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test-nonce';
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale() {
        return isset($GLOBALS['digitalogic_test_locale'])
            ? (string) $GLOBALS['digitalogic_test_locale']
            : 'en_US';
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return (string) $url;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('df_digits_form_signup')) {
    function df_digits_form_signup() {
        return isset($GLOBALS['digitalogic_test_digits_signup_html'])
            ? (string) $GLOBALS['digitalogic_test_digits_signup_html']
            : '';
    }
}

if (!function_exists('digits_render_new_form')) {
    function digits_render_new_form($details) {
        echo isset($GLOBALS['digitalogic_test_digits_login_html'])
            ? (string) $GLOBALS['digitalogic_test_digits_login_html']
            : '';
    }
}

if (!function_exists('login_footer')) {
    function login_footer($input_id = '') {
        $GLOBALS['digitalogic_test_login_footer_input'] = (string) $input_id;
        $GLOBALS['digitalogic_test_login_footer_filters'] =
            $GLOBALS['digitalogic_test_filters']['login_display_language_dropdown'] ?? [];
    }
}

require_once dirname(__DIR__) . '/includes/integrations/class-admin-branding.php';
require_once dirname(__DIR__) . '/includes/integrations/class-auth-page.php';

final class AdminBrandingAuthTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_locale'] = 'en_US';
        unset(
            $GLOBALS['digitalogic_test_filters']['login_display_language_dropdown'],
            $GLOBALS['digitalogic_test_login_footer_input'],
            $GLOBALS['digitalogic_test_login_footer_filters']
        );
    }

    public function test_client_config_exposes_the_canonical_login_fallback(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_config');
        $config = $method->invoke(null, true, false);

        $this->assertSame('https://digitalogic.test/login/', $config['loginUrl']);
    }

    public function test_recovery_identity_labels_match_the_wordpress_backend(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_labels');
        $english = $method->invoke(null);

        $GLOBALS['digitalogic_test_locale'] = 'fa_IR';
        $persian = $method->invoke(null);

        $this->assertSame('Username or email address', $english['recoveryIdentity']);
        $this->assertSame('Back', $english['back']);
        $this->assertStringNotContainsString('mobile', strtolower($english['recoveryIdentity']));
        $this->assertSame('نام کاربری یا نشانی ایمیل', $persian['recoveryIdentity']);
        $this->assertSame('بازگشت', $persian['back']);
        $this->assertStringNotContainsString('موبایل', $persian['recoveryIdentity']);
    }

    public function test_raw_digits_registration_output_contains_a_no_javascript_login_fallback(): void {
        $GLOBALS['digitalogic_test_digits_signup_html'] = $this->raw_digits_transition();
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'digits_register_form_html');
        $html = $method->invoke(null);

        $this->assert_raw_login_fallback($html);
    }

    public function test_raw_canonical_login_output_contains_the_same_no_javascript_fallback(): void {
        $GLOBALS['digitalogic_test_digits_login_html'] = $this->raw_digits_transition();
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'digits_login_form_html');
        $html = $method->invoke(null, '');

        $this->assert_raw_login_fallback($html);
    }

    public function test_custom_footer_suppresses_remote_language_discovery_only_while_rendering(): void {
        $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'render_login_footer');
        $method->invoke(null);

        $this->assertSame('user_login', $GLOBALS['digitalogic_test_login_footer_input']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_login_footer_filters']);
        $this->assertSame(
            '__return_false',
            $GLOBALS['digitalogic_test_login_footer_filters'][0]['callback']
        );
        $this->assertEmpty(
            $GLOBALS['digitalogic_test_filters']['login_display_language_dropdown'] ?? []
        );
    }

    private function raw_digits_transition(): string {
        return '<div class="digits-form_footer">'
            . '<a href="#" class="digits-form_toggle_login_register show_login">اکنون وارد شوید</a>'
            . '</div>';
    }

    private function assert_raw_login_fallback(string $html): void {
        $this->assertStringContainsString('href="https://digitalogic.test/login/"', $html);
        $this->assertStringNotContainsString('href="#"', $html);
        $this->assertStringContainsString('class="digits-form_toggle_login_register show_login"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
