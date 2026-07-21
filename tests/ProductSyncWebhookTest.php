<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncWebhookTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array(
            'digitalogic_webhook_urls' => array('https://automation.digitalogic.test/events'),
            'digitalogic_webhook_secret' => 'observer-secret',
        );
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_remote_posts'] = array();
        $GLOBALS['digitalogic_test_remote_post_results'] = array();
        $this->resetSingleton(Digitalogic_Webhooks::class);
        $this->resetSingleton(Digitalogic_Shipping_Method_Service::class);
    }

    public function test_observer_emits_bounded_current_contract_summary(): void {
        $result = array(
            'status' => 'accepted',
            'retryable' => false,
            'pending_products' => 0,
            'deferred_products' => 2,
            'woocommerce' => array('attempted' => 2, 'missing' => 2),
        );
        $envelope = array(
            'schema' => 'patris.product-sync',
            'event_id' => 'sha256:' . str_repeat('a', 64),
            'event_type' => 'snapshot',
            'source' => array('id' => 'patris-export', 'dataset' => 'ALLANBAR'),
        );

        $this->assertTrue(Digitalogic_Webhooks::instance()->product_sync_applied($result, $envelope));
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $body = $GLOBALS['digitalogic_test_remote_posts'][0]['args']['body'];
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('patris.product_sync.applied', $payload['event']);
        $this->assertSame(
            array('schema', 'event_id', 'event_type', 'source', 'status', 'retryable', 'pending_products', 'deferred_products', 'woocommerce'),
            array_keys($payload['data'])
        );
        $this->assertSame('patris.product-sync', $payload['data']['schema']);
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
