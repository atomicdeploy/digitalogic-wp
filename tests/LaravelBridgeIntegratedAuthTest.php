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

    public function test_explicit_external_panel_keeps_legacy_handoff_compatibility(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_laravel_panel_url'] = 'https://panel.example.test';

        $bridge = $this->bridge();
        $url = $bridge->get_panel_auth_url(array('code' => 'legacy-code'));

        $this->assertFalse($bridge->uses_integrated_panel());
        $this->assertSame('https://panel.example.test/auth/wordpress?code=legacy-code', $url);
    }

    public function test_same_host_on_a_different_port_is_not_same_origin(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_laravel_panel_url'] = 'https://digitalogic.test:8443';

        $this->assertFalse($this->bridge()->uses_integrated_panel());
    }

    private function bridge(): Digitalogic_Laravel_Bridge {
        return (new ReflectionClass(Digitalogic_Laravel_Bridge::class))->newInstanceWithoutConstructor();
    }
}
