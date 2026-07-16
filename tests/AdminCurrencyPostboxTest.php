<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

if (!defined('DIGITALOGIC_PLUGIN_URL')) {
    define('DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/');
}

if (!defined('DIGITALOGIC_VERSION')) {
    define('DIGITALOGIC_VERSION', 'test');
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $dependencies = array(), $version = false, $in_footer = false) {
        $GLOBALS['digitalogic_test_enqueued_scripts'][$handle] = compact('src', 'dependencies', 'version', 'in_footer');
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $data) {
        $GLOBALS['digitalogic_test_localized_scripts'][$handle][$object_name] = $data;

        return true;
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        return $GLOBALS['digitalogic_test_current_screen'];
    }
}

if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default') {
        $GLOBALS['digitalogic_test_meta_boxes'][$id] = array(
            'title' => $title,
            'callback' => $callback,
            'screen' => is_object($screen) ? $screen->id : $screen,
            'context' => $context,
            'priority' => $priority,
        );
    }
}

require_once dirname(__DIR__) . '/includes/admin/class-admin.php';

final class AdminCurrencyPostboxTest extends TestCase {
    private $admin;
    private $enqueue_assets;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_enqueued_scripts'] = array();
        $GLOBALS['digitalogic_test_localized_scripts'] = array();
        $GLOBALS['digitalogic_test_meta_boxes'] = array();
        $GLOBALS['digitalogic_test_current_screen'] = (object) array(
            'id' => 'digitalogic_page_price-settings',
        );

        $instance = new ReflectionProperty(Digitalogic_Admin::class, 'instance');
        $instance->setValue(null, null);

        $this->admin = Digitalogic_Admin::instance();

        $currency_hook = new ReflectionProperty(Digitalogic_Admin::class, 'currency_page_hook');
        $currency_hook->setValue($this->admin, 'digitalogic_page_price-settings');

        $this->enqueue_assets = new ReflectionMethod(Digitalogic_Admin::class, 'enqueue_currency_postbox_assets');
    }

    public function test_postbox_assets_are_enqueued_only_for_the_currency_page(): void {
        $this->enqueue_assets->invoke($this->admin, 'digitalogic_page_price-settings');

        $this->assertArrayHasKey('digitalogic-currency-postboxes', $GLOBALS['digitalogic_test_enqueued_scripts']);
        $this->assertSame(
            array('jquery', 'postbox'),
            $GLOBALS['digitalogic_test_enqueued_scripts']['digitalogic-currency-postboxes']['dependencies']
        );
        $this->assertSame(
            'digitalogic_page_price-settings',
            $GLOBALS['digitalogic_test_localized_scripts']['digitalogic-currency-postboxes']['digitalogicCurrencyPostboxes']['screenId']
        );

        $GLOBALS['digitalogic_test_enqueued_scripts'] = array();
        $GLOBALS['digitalogic_test_localized_scripts'] = array();

        $this->enqueue_assets->invoke($this->admin, 'digitalogic_page_product-list');

        $this->assertSame(array(), $GLOBALS['digitalogic_test_enqueued_scripts']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_localized_scripts']);
    }

    public function test_currency_page_registers_native_meta_boxes_in_two_columns(): void {
        $this->admin->register_currency_meta_boxes();

        $this->assertSame(
            array(
                'digitalogic-currency-update',
                'digitalogic-currency-last-update',
                'digitalogic-currency-rates',
                'digitalogic-currency-options',
            ),
            array_keys($GLOBALS['digitalogic_test_meta_boxes'])
        );
        $this->assertSame('side', $GLOBALS['digitalogic_test_meta_boxes']['digitalogic-currency-update']['context']);
        $this->assertSame('side', $GLOBALS['digitalogic_test_meta_boxes']['digitalogic-currency-last-update']['context']);
        $this->assertSame('normal', $GLOBALS['digitalogic_test_meta_boxes']['digitalogic-currency-rates']['context']);
        $this->assertSame('normal', $GLOBALS['digitalogic_test_meta_boxes']['digitalogic-currency-options']['context']);

        foreach ($GLOBALS['digitalogic_test_meta_boxes'] as $meta_box) {
            $this->assertSame('digitalogic_page_price-settings', $meta_box['screen']);
        }
    }

    public function test_currency_view_delegates_layout_and_controls_to_wordpress_core(): void {
        $view = file_get_contents(dirname(__DIR__) . '/includes/admin/views/currency.php');

        $this->assertStringContainsString('class="metabox-holder columns-2"', $view);
        $this->assertStringContainsString("do_meta_boxes(\$current_screen, 'side', null)", $view);
        $this->assertStringContainsString("do_meta_boxes(\$current_screen, 'normal', null)", $view);
        $this->assertStringNotContainsString('class="postbox"', $view);
        $this->assertStringNotContainsString('<script', $view);
    }
}
