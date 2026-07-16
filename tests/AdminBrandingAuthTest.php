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

require_once dirname(__DIR__) . '/includes/integrations/class-admin-branding.php';

final class AdminBrandingAuthTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_locale'] = 'en_US';
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
        $this->assertStringNotContainsString('mobile', strtolower($english['recoveryIdentity']));
        $this->assertSame('نام کاربری یا نشانی ایمیل', $persian['recoveryIdentity']);
        $this->assertStringNotContainsString('موبایل', $persian['recoveryIdentity']);
    }
}
