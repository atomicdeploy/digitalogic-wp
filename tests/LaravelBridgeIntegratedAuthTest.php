<?php

use PHPUnit\Framework\TestCase;

final class LaravelBridgeIntegratedAuthTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
    }

    public function test_same_origin_panel_uses_wordpress_session_without_handoff_code(): void {
        $bridge = $this->bridge();
        $url = $bridge->get_panel_auth_url(array(
            'code' => 'must-not-leave-wordpress',
            'return_to' => '/products',
        ));

        $this->assertTrue($bridge->uses_integrated_panel());
        $this->assertSame('https://digitalogic.test/panel/?return_to=%2Fproducts', $url);
        $this->assertStringNotContainsString('code=', $url);
    }

    public function test_external_panel_configuration_is_ignored(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_laravel_panel_url'] = 'https://panel.example.test';

        $bridge = $this->bridge();
        $url = $bridge->get_panel_auth_url(array('code' => 'legacy-code'));

        $this->assertTrue($bridge->uses_integrated_panel());
        $this->assertSame('https://digitalogic.test/panel/', $url);
        $this->assertStringNotContainsString('code=', $url);
    }

    public function test_different_port_configuration_is_ignored(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_laravel_panel_url'] = 'https://digitalogic.test:8443';

        $this->assertSame('https://digitalogic.test/panel', $this->bridge()->get_panel_url());
    }

    public function test_bridge_source_has_no_panel_token_or_session_handoff(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-laravel-bridge.php');

        $this->assertStringNotContainsString('TOKEN_OPTION', $source);
        $this->assertStringNotContainsString('session/consume', $source);
        $this->assertStringNotContainsString('x-digitalogic-panel-token', strtolower($source));
        $this->assertStringNotContainsString('create_session_handoff', $source);
        $this->assertStringNotContainsString("add_action('rest_api_init'", $source);

        $panel_source = file_get_contents(dirname(__DIR__) . '/includes/panel/class-panel.php');
        $this->assertStringContainsString('Digitalogic_Laravel_Bridge::instance()->boot_for_panel()', $panel_source);
    }

    private function bridge(): Digitalogic_Laravel_Bridge {
        return (new ReflectionClass(Digitalogic_Laravel_Bridge::class))->newInstanceWithoutConstructor();
    }
}
