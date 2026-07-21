<?php

use PHPUnit\Framework\TestCase;

final class PanelRoutingTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_rewrite_rules'] = array();
        $GLOBALS['digitalogic_test_rewrite_flushes'] = array();
    }

    public function test_panel_routes_are_registered_at_the_top(): void {
        $this->panel()->register_route();

        $this->assertSame(array(
            '^panel/?$' => array(
                'query' => 'index.php?digitalogic_panel=1',
                'after' => 'top',
            ),
            '^panel/(.+)/?$' => array(
                'query' => 'index.php?digitalogic_panel=1&digitalogic_panel_path=$matches[1]',
                'after' => 'top',
            ),
            '^panell/?$' => array(
                'query' => 'index.php?digitalogic_panel=1&digitalogic_panel_legacy=1',
                'after' => 'top',
            ),
            '^panell/(.+)/?$' => array(
                'query' => 'index.php?digitalogic_panel=1&digitalogic_panel_legacy=1&digitalogic_panel_path=$matches[1]',
                'after' => 'top',
            ),
        ), $GLOBALS['digitalogic_test_rewrite_rules']);
    }

    public function test_wp_cli_registers_routes_without_self_healing_stale_rules(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_panel_rewrite_version'] = 'stale';
        $GLOBALS['digitalogic_test_options']['rewrite_rules'] = array();
        $GLOBALS['digitalogic_test_option_cache']['rewrite_rules'] = array();

        $this->panel()->register_route();

        $this->assertCount(4, $GLOBALS['digitalogic_test_rewrite_rules']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_rewrite_flushes']);
        $this->assertSame('stale', $GLOBALS['digitalogic_test_options']['digitalogic_panel_rewrite_version']);
    }

    public function test_wp_cli_does_not_register_the_web_delivery_channel(): void {
        $service = Digitalogic_Shipping_Method_Service::instance();
        $service->unregister_delivery_channel('panel');

        $panelReflection = new ReflectionClass(Digitalogic_Panel::class);
        $panelReflection->getProperty('instance')->setValue(null, null);
        Digitalogic_Panel::instance();

        $serviceReflection = new ReflectionClass($service);
        $channels = $serviceReflection->getProperty('delivery_channels')->getValue($service);

        $this->assertArrayNotHasKey('panel', $channels);
    }

    public function test_web_request_still_repairs_a_missing_stored_route(): void {
        $panel = $this->webPanel();
        $panel->register_route();

        $version = $GLOBALS['digitalogic_test_options']['digitalogic_panel_rewrite_version'];
        $stored_rules = array();
        foreach ($GLOBALS['digitalogic_test_rewrite_rules'] as $pattern => $rule) {
            $stored_rules[$pattern] = $rule['query'];
        }

        $GLOBALS['digitalogic_test_options']['rewrite_rules'] = $stored_rules;
        $GLOBALS['digitalogic_test_option_cache']['rewrite_rules'] = $stored_rules;
        $GLOBALS['digitalogic_test_rewrite_flushes'] = array();
        $panel->register_route();
        $this->assertSame(array(), $GLOBALS['digitalogic_test_rewrite_flushes']);

        unset($stored_rules['^panel/?$']);
        $GLOBALS['digitalogic_test_options']['rewrite_rules'] = $stored_rules;
        $GLOBALS['digitalogic_test_option_cache']['rewrite_rules'] = $stored_rules;
        $panel->register_route();

        $this->assertSame(array(false), $GLOBALS['digitalogic_test_rewrite_flushes']);
        $this->assertSame($version, $GLOBALS['digitalogic_test_options']['digitalogic_panel_rewrite_version']);
    }

    private function panel(): Digitalogic_Panel {
        return (new ReflectionClass(Digitalogic_Panel::class))->newInstanceWithoutConstructor();
    }

    private function webPanel(): Digitalogic_Panel {
        return new class extends Digitalogic_Panel {
            public function __construct() {
            }

            protected function is_wp_cli_request() {
                return false;
            }
        };
    }
}
