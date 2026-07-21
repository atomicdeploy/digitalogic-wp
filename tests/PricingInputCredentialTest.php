<?php

use PHPUnit\Framework\TestCase;

final class PricingInputCredentialTest extends TestCase {
    private $credential;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transients'] = array();
        $GLOBALS['digitalogic_test_transient_deletes'] = array();
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.60';
        $this->resetSingleton(Digitalogic_Pricing_Input_Credential::class);
        $this->credential = Digitalogic_Pricing_Input_Credential::instance();
    }

    protected function tearDown(): void {
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_bearer_authorizes_only_exact_living_pricing_routes(): void {
        $issued = $this->credential->create();
        $this->assertNotInstanceOf(WP_Error::class, $issued);
        $secret = $issued['secret'];

        $this->assertTrue($this->credential->authorize(
            $this->request('GET', '/digitalogic/integration/catalog', $secret)
        ));
        $this->assertTrue($this->credential->authorize(
            $this->request('POST', '/digitalogic/integration/pricing-assignments/batch', $secret)
        ));

        foreach (array(
            $this->request('POST', '/digitalogic/integration/catalog', $secret),
            $this->request('GET', '/digitalogic/integration/pricing-assignments/batch', $secret),
            $this->request('POST', '/digitalogic/patris/product-sync', $secret),
        ) as $request) {
            $result = $this->credential->authorize($request);
            $this->assertSame('digitalogic_pricing_input_scope_denied', $result->get_error_code());
        }
    }

    public function test_rotation_and_revocation_fail_closed(): void {
        $issued = $this->credential->create();
        $rotated = $this->credential->rotate();
        $this->assertNotSame($issued['secret'], $rotated['secret']);
        $this->assertInstanceOf(WP_Error::class, $this->credential->authorize(
            $this->request('GET', '/digitalogic/integration/catalog', $issued['secret'])
        ));
        $this->assertTrue($this->credential->authorize(
            $this->request('GET', '/digitalogic/integration/catalog', $rotated['secret'])
        ));

        $this->assertNotInstanceOf(WP_Error::class, $this->credential->revoke());
        $this->assertInstanceOf(WP_Error::class, $this->credential->authorize(
            $this->request('GET', '/digitalogic/integration/catalog', $rotated['secret'])
        ));
    }

    private function request($method, $route, $secret): WP_REST_Request {
        $request = new WP_REST_Request(
            array(),
            array(),
            array('Authorization' => 'Bearer ' . $secret)
        );
        $request->set_method($method);
        $request->set_route($route);
        return $request;
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
