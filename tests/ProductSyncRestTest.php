<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncRestTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_options'] = array(
            Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION => 'receiver-secret',
        );
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $this->resetSingleton(Digitalogic_REST_API::class);
        $this->resetSingleton(Digitalogic_Product_Sync_Receiver::class);
    }

    public function test_registers_only_exact_patris_machine_routes(): void {
        Digitalogic_REST_API::instance()->register_routes();
        $routes = array();
        foreach ($GLOBALS['digitalogic_test_routes'] as $route) {
            $routes[$route['namespace'] . $route['route']] = $route['args'];
        }

        $this->assertArrayHasKey('digitalogic/patris/product-sync', $routes);
        $this->assertArrayHasKey('digitalogic/integration/catalog', $routes);
        $this->assertArrayHasKey('digitalogic/integration/pricing-assignments/batch', $routes);
        $this->assertArrayHasKey('digitalogic/integration/products/by-code/(?P<code>[^/]+)/pricing', $routes);
        $this->assertCount(4, array_filter(
            array_keys($routes),
            static fn($route) => str_starts_with($route, 'digitalogic/patris/')
                || str_starts_with($route, 'digitalogic/integration/')
        ));
    }

    public function test_product_sync_uses_header_secret_and_optional_identity_headers(): void {
        $api = Digitalogic_REST_API::instance();
        $payload = json_decode(
            file_get_contents(__DIR__ . '/fixtures/patris-product-sync-golden.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $request = new WP_REST_Request(
            array(),
            $payload,
            array(
                'X-Digitalogic-Product-Sync-Secret' => 'receiver-secret',
                'X-Patris-Contract' => $payload['schema'],
                'X-Patris-Event-ID' => $payload['event_id'],
            ),
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );

        $this->assertTrue($api->check_patris_product_sync_permission($request));
        $response = $api->receive_patris_product_sync($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('accepted', $response->get_data()['data']['status']);

        $bad = new WP_REST_Request(
            array(),
            $payload,
            array('X-Patris-Event-ID' => 'sha256:' . str_repeat('f', 64)),
            json_encode($payload)
        );
        $response = $api->receive_patris_product_sync($bad);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('digitalogic_product_sync_header_mismatch', $response->get_data()['code']);
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
