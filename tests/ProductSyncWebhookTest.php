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
        // phpcs:disable -- Bulk product-sync regression globals follow the established test fixture style.
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_post_meta_cache'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $GLOBALS['digitalogic_test_wc_after_save'] = null;
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $this->resetSingleton(Digitalogic_Webhooks::class);
        $this->resetSingleton(Digitalogic_Shipping_Method_Service::class);
        $this->resetSingleton(Digitalogic_Product_Sync_Receiver::class);
        $this->resetSingleton(Digitalogic_Product_Manager::class);
        // phpcs:enable
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

    // phpcs:disable -- Bulk product-sync regression fixtures follow the established PHPUnit style.
    public function test_receiver_suppresses_per_row_http_and_restores_ordinary_product_webhooks(): void {
        $products = array();
        foreach (array(701, 702, 703) as $product_id) {
            $product_code = 'SYNC-' . $product_id;
            $GLOBALS['digitalogic_test_posts'][$product_id] = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'meta' => array('_digitalogic_patris_product_code' => $product_code),
            );
            $product = array(
                'product_code' => $product_code,
                'total_stock' => $product_id - 694,
                'warnings' => array(),
            );
            $product['record_hash'] = $this->recordHash($product);
            $products[] = $product;
        }

        $webhooks = Digitalogic_Webhooks::instance();
        $emit_product_hooks = null;
        $emit_product_hooks = static function ($saved_product) use (&$emit_product_hooks) {
            $GLOBALS['digitalogic_test_wc_after_save'] = $emit_product_hooks;
            do_action('woocommerce_before_product_object_save', $saved_product);
            do_action('woocommerce_update_product', $saved_product->get_id());
            do_action('woocommerce_product_set_stock', $saved_product);
        };
        $GLOBALS['digitalogic_test_wc_after_save'] = $emit_product_hooks;

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot($products)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertSame(3, $result['woocommerce']['updated']);
        $this->assertCount(3, $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $summary = json_decode(
            $GLOBALS['digitalogic_test_remote_posts'][0]['args']['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('patris.product_sync.applied', $summary['event']);

        $webhooks->product_updated(701);

        $this->assertCount(2, $GLOBALS['digitalogic_test_remote_posts']);
        $ordinary = json_decode(
            $GLOBALS['digitalogic_test_remote_posts'][1]['args']['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('product.updated', $ordinary['event']);
    }

    public function test_product_webhook_guard_restores_delivery_after_exception(): void {
        $GLOBALS['digitalogic_test_posts'][701] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => 'SYNC-701'),
        );
        $webhooks = Digitalogic_Webhooks::instance();

        try {
            $webhooks->without_product_change_webhooks(static function () use ($webhooks) {
                $webhooks->product_updated(701);
                throw new RuntimeException('Injected bulk failure.');
            });
            $this->fail('The injected bulk failure should escape the guard.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected bulk failure.', $exception->getMessage());
        }

        $this->assertCount(0, $GLOBALS['digitalogic_test_remote_posts']);
        $webhooks->product_updated(701);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
    }

    private function snapshot($products): array {
        $material = array_map(
            static fn($product) => $product['product_code'] . '=' . $product['record_hash'],
            $products
        );
        sort($material, SORT_STRING);
        $source = array(
            'id' => 'tests',
            'dataset' => 'ALLANBAR',
            'revision' => 'sha256:' . hash('sha256', implode("\n", $material)),
        );
        $identity = array(
            'schema' => 'patris.product-sync',
            'event_type' => 'snapshot',
            'source' => $source,
            'generated_at' => '2026-07-27T00:00:00Z',
            'products' => $material,
            'categories' => array(),
            'excluded_codes' => array(),
            'quarantined_codes' => array(),
        );

        return array(
            'schema' => 'patris.product-sync',
            'event_type' => 'snapshot',
            'event_id' => 'sha256:' . hash(
                'sha256',
                json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            'source' => $source,
            'generated_at' => '2026-07-27T00:00:00Z',
            'products' => $products,
            'categories' => array(),
            'excluded_codes' => array(),
            'quarantined_codes' => array(),
            'warnings' => array(),
        );
    }

    private function recordHash($record): string {
        ksort($record, SORT_STRING);

        return 'sha256:' . hash(
            'sha256',
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    // phpcs:enable

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
