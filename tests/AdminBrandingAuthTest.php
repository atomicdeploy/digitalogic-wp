<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

if (!defined('DIGITALOGIC_PLUGIN_URL')) {
    define('DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/');
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return !empty($GLOBALS['digitalogic_test_is_user_logged_in']);
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
        $GLOBALS['digitalogic_test_login_language_args'] =
            apply_filters('login_language_dropdown_args', ['selected' => determine_locale()]);
    }
}

if (!function_exists('get_available_languages')) {
    function get_available_languages() {
        return ['fa_IR', 'de_DE'];
    }
}

if (!function_exists('get_site_transient')) {
    function get_site_transient($name) {
        return $GLOBALS['digitalogic_test_site_transients'][$name] ?? false;
    }
}

require_once dirname(__DIR__) . '/includes/integrations/class-admin-branding.php';
require_once dirname(__DIR__) . '/includes/integrations/class-auth-page.php';

final class AdminBrandingAuthTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_locale']            = 'en_US';
        $GLOBALS['digitalogic_test_is_user_logged_in'] = false;
        $GLOBALS['digitalogic_test_current_user_id']    = 0;
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
        $this->assertSame('https://digitalogic.test/' . rest_get_url_prefix() . '/digitalogic/v1/login-slides', $config['loginSlidesUrl']);
        $this->assertSame(array(), $config['rememberedAccount']);
        $this->assertFalse($config['showLoginThemeSwitcher']);
    }

    public function test_theme_switcher_can_be_enabled_while_the_language_switcher_stays_off(): void {
        $callback = static fn() => true;
        add_filter('digitalogic_login_show_theme_switcher', $callback);
        try {
            $config = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_config');
            $this->assertTrue($config->invoke(null, true, false)['showLoginThemeSwitcher']);
            $this->test_custom_footer_suppresses_remote_language_discovery_only_while_rendering();
        } finally {
            remove_filter('digitalogic_login_show_theme_switcher', $callback);
        }
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

    public function test_enabled_language_switcher_uses_only_installed_languages_even_on_a_cache_miss(): void {
        $callback = static fn() => true;
        add_filter('digitalogic_login_show_language_switcher', $callback);
        try {
            $method = new ReflectionMethod(Digitalogic_Plugin_Auth_Routes::class, 'render_login_footer');
            $method->invoke(null);

            $args = $GLOBALS['digitalogic_test_login_language_args'];
            $this->assertEmpty($GLOBALS['digitalogic_test_login_footer_filters']);
            $this->assertSame(['fa_IR', 'de_DE'], $args['languages']);
            $this->assertFalse($args['show_available_translations']);
            $this->assertNotEmpty($args['translations'], 'An empty array would trigger remote discovery.');
            $this->assertSame('en_US', $args['selected']);
            $this->assertEmpty($GLOBALS['digitalogic_test_filters']['login_language_dropdown_args'] ?? []);

            $config = new ReflectionMethod(Digitalogic_Plugin_Admin_Branding::class, 'client_config');
            $this->assertFalse($config->invoke(null, true, false)['showLoginThemeSwitcher']);
        } finally {
            remove_filter('digitalogic_login_show_language_switcher', $callback);
        }
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
