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

    public function test_matching_version_still_repairs_a_missing_stored_route(): void {
        $panel = $this->panel();
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
}
