<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncRestTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_options'] = array(
            'digitalogic_patris_feed_push_token' => 'receiver-secret',
        );
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
    }

    public function test_registers_dedicated_route_without_changing_legacy_callback(): void {
        $api = Digitalogic_REST_API::instance();
        $api->register_routes();
        $routes = array();
        foreach ($GLOBALS['digitalogic_test_routes'] as $route) {
            $routes[$route['route']] = $route['args'];
        }

        $this->assertArrayHasKey('/patris/product-sync', $routes);
        $this->assertArrayHasKey('/patris/push', $routes);
        $this->assertSame('receive_patris_product_sync', $routes['/patris/product-sync']['callback'][1]);
        $this->assertSame('push_patris', $routes['/patris/push']['callback'][1]);
        $this->assertNotSame($routes['/patris/product-sync']['callback'], $routes['/patris/push']['callback']);
    }

    public function test_v1_token_is_header_only_and_write_scope_remains_supported(): void {
        $api = Digitalogic_REST_API::instance();
        $query_token = new WP_REST_Request(array('token' => 'receiver-secret'));
        $header_token = new WP_REST_Request(array(), array(), array('X-Digitalogic-Token' => 'receiver-secret'));

        $this->assertFalse($api->check_patris_product_sync_permission($query_token));
        $this->assertTrue($api->check_patris_product_sync_permission($header_token));

        $GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;
        $this->assertTrue($api->check_patris_product_sync_permission(new WP_REST_Request()));
    }

    public function test_rest_callback_accepts_empty_snapshot_and_checks_headers(): void {
        $payload = $this->emptySnapshot();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = array(
            'X-Digitalogic-Token' => 'receiver-secret',
            'X-Patris-Contract' => $payload['schema'],
            'X-Patris-Contract-Version' => $payload['schema_version'],
            'X-Patris-Event-ID' => $payload['event_id'],
        );
        $request = new WP_REST_Request(array(), $payload, $headers, $body);

        $response = Digitalogic_REST_API::instance()->receive_patris_product_sync($request);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
        $this->assertSame('accepted', $response->get_data()['data']['status']);

        $headers['X-Patris-Event-ID'] = 'sha256:' . str_repeat('f', 64);
        $mismatch = Digitalogic_REST_API::instance()->receive_patris_product_sync(
            new WP_REST_Request(array(), $payload, $headers, $body)
        );
        $this->assertSame(400, $mismatch->get_status());
        $this->assertSame('digitalogic_product_sync_header_mismatch', $mismatch->get_data()['code']);
    }

    public function test_rest_callback_returns_validation_status_and_code(): void {
        $payload = $this->emptySnapshot();
        $payload['local_currency'] = 'IRR';
        $request = new WP_REST_Request(
            array(),
            $payload,
            array('X-Digitalogic-Token' => 'receiver-secret'),
            json_encode($payload)
        );
        $response = Digitalogic_REST_API::instance()->receive_patris_product_sync($request);

        $this->assertSame(422, $response->get_status());
        $this->assertFalse($response->get_data()['success']);
        $this->assertSame('digitalogic_product_sync_currency_unsupported', $response->get_data()['code']);
    }

    private function emptySnapshot() {
        $revision = 'sha256:' . hash('sha256', '');
        $source = array('id' => 'rest-tests', 'dataset' => 'kala.db', 'revision' => $revision);
        $identity = array(
            'schema' => 'digitalogic.product-sync',
            'schema_version' => '1.0',
            'event_type' => 'snapshot',
            'local_currency' => 'IRT',
            'formula_id' => 'landed_price_v1',
            'formula_revision' => '1.0.0',
            'source' => $source,
            'products' => array(),
        );

        return array(
            'schema' => 'digitalogic.product-sync',
            'schema_version' => '1.0',
            'event' => 'digitalogic.product-sync',
            'event_type' => 'snapshot',
            'event_id' => 'sha256:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES)),
            'local_currency' => 'IRT',
            'formula_id' => 'landed_price_v1',
            'formula_revision' => '1.0.0',
            'formula_version' => 'landed_price_v1',
            'source' => $source,
            'generated_at' => '2026-07-16T10:00:00Z',
            'products' => array(),
        );
    }
}
