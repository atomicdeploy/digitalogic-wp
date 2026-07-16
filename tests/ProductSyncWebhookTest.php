<?php

use PHPUnit\Framework\TestCase;

// phpcs:disable -- Match the established PHPUnit fixture style in this baseline-managed test suite.
final class ProductSyncWebhookTest extends TestCase {
    private static $fixture_json;
    private static $fixture;

    public static function setUpBeforeClass(): void {
        self::$fixture_json = file_get_contents(__DIR__ . '/fixtures/patris-product-sync-v1-golden.json');
        self::$fixture = json_decode(self::$fixture_json, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_post_meta_cache'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_remote_posts'] = array();
        $GLOBALS['digitalogic_test_remote_post_results'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

        $this->resetSingleton(Digitalogic_Webhooks::class);
        $this->resetSingleton(Digitalogic_Product_Sync_Receiver::class);
        $this->resetSingleton(Digitalogic_Product_Identifier_Resolver::class);
    }

    public function test_safe_bounded_projection_reuses_shared_signing_transport(): void {
        $this->configureWebhook();
        $webhooks = Digitalogic_Webhooks::instance();
        $unsafe = 'DO-NOT-EXPOSE-RAW-SECRET';
        $result = array(
            'status' => 'accepted',
            'retryable' => false,
            'pending_products' => 0,
            'deferred_products' => PHP_INT_MAX,
            'woocommerce' => array(
                'attempted' => PHP_INT_MAX,
                'updated' => -1,
                'already_applied' => 2,
                'missing' => 3,
                'ambiguous' => 4,
                'failed' => 5,
                'errors_truncated' => 101,
                'errors' => array(array('product_code' => $unsafe)),
            ),
            'products' => array(array('name' => $unsafe)),
            'request_body' => $unsafe,
            'credentials' => $unsafe,
            'receiver_state' => $unsafe,
        );
        $envelope = $this->actionEnvelope();
        $envelope['products'] = array(array('name' => $unsafe));
        $envelope['headers'] = array('Authorization' => $unsafe);
        $envelope['endpoint_path'] = '/private/' . $unsafe;

        $this->assertTrue($webhooks->product_sync_applied($result, $envelope));
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $request = $GLOBALS['digitalogic_test_remote_posts'][0];
        $payload = json_decode($request['args']['body'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('patris.product_sync.applied', $payload['event']);
        $this->assertSame(array(
            'schema',
            'schema_version',
            'event_id',
            'event_type',
            'source',
            'status',
            'retryable',
            'pending_products',
            'deferred_products',
            'woocommerce',
        ), array_keys($payload['data']));
        $this->assertSame('digitalogic.product-sync', $payload['data']['schema']);
        $this->assertSame('1.0', $payload['data']['schema_version']);
        $this->assertSame(array('id', 'dataset'), array_keys($payload['data']['source']));
        $this->assertSame(10000, $payload['data']['deferred_products']);
        $this->assertSame(10000, $payload['data']['woocommerce']['attempted']);
        $this->assertSame(0, $payload['data']['woocommerce']['updated']);
        $this->assertSame(array(
            'attempted',
            'updated',
            'already_applied',
            'missing',
            'ambiguous',
            'failed',
            'errors_truncated',
        ), array_keys($payload['data']['woocommerce']));
        $this->assertStringNotContainsString($unsafe, $request['args']['body']);
        $this->assertFalse($request['args']['blocking']);
        $this->assertSame(10, $request['args']['timeout']);
        $this->assertSame('patris.product_sync.applied', $request['args']['headers']['X-Digitalogic-Event']);
        $this->assertSame(
            hash_hmac('sha256', $request['args']['body'], 'observer-test-secret'),
            $request['args']['headers']['X-Digitalogic-Signature']
        );
        $this->assertTrue(Digitalogic_Webhooks::verify_signature(
            $request['args']['body'],
            $request['args']['headers']['X-Digitalogic-Signature']
        ));

        $path_envelope = $this->actionEnvelope();
        $path_envelope['source']['dataset'] = 'C:\\private\\kala.db';
        $this->assertTrue($webhooks->product_sync_applied($result, $path_envelope));
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);

        $query_envelope = $this->actionEnvelope();
        $query_envelope['source']['dataset'] = 'kala.db?token=' . $unsafe;
        $this->assertTrue($webhooks->product_sync_applied($result, $query_envelope));
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
    }

    public function test_accepted_deferred_event_is_observed_once_and_terminal_replay_is_suppressed(): void {
        $this->configureWebhook();
        Digitalogic_Webhooks::instance();

        $accepted = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);
        $replayed = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);

        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame(2, $accepted['deferred_products']);
        $this->assertSame('replayed', $replayed['status']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $data = $this->observerPayload(0)['data'];
        $this->assertSame('accepted', $data['status']);
        $this->assertFalse($data['retryable']);
        $this->assertSame(0, $data['pending_products']);
        $this->assertSame(2, $data['deferred_products']);
        $this->assertSame(2, $data['woocommerce']['missing']);
    }

    public function test_transient_retry_emits_partial_then_recovered_without_terminal_duplicate(): void {
        $this->configureWebhook();
        Digitalogic_Webhooks::instance();
        foreach (self::$fixture['products'] as $index => $product) {
            $GLOBALS['digitalogic_test_posts'][880 + $index] = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
            );
        }
        $GLOBALS['digitalogic_test_wc_save_failures'] = array(881);

        $partial = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);
        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $recovered = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);
        $replayed = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);

        $this->assertSame('partially_applied', $partial['status']);
        $this->assertSame('recovered', $recovered['status']);
        $this->assertSame('replayed', $replayed['status']);
        $this->assertCount(2, $GLOBALS['digitalogic_test_remote_posts']);
        $this->assertSame('partially_applied', $this->observerPayload(0)['data']['status']);
        $this->assertTrue($this->observerPayload(0)['data']['retryable']);
        $this->assertSame(1, $this->observerPayload(0)['data']['pending_products']);
        $this->assertSame('recovered', $this->observerPayload(1)['data']['status']);
        $this->assertFalse($this->observerPayload(1)['data']['retryable']);
        $this->assertSame(0, $this->observerPayload(1)['data']['pending_products']);
    }

    public function test_no_webhook_configuration_keeps_direct_sync_authoritative(): void {
        Digitalogic_Webhooks::instance();

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);

        $this->assertSame('accepted', $result['status']);
        $this->assertTrue($result['persistence_verified']);
        $this->assertArrayNotHasKey('delivery_warnings', $result);
        $this->assertEmpty($GLOBALS['digitalogic_test_remote_posts']);
    }

    public function test_observer_transport_failure_cannot_change_committed_receiver_outcome(): void {
        $this->configureWebhook();
        $GLOBALS['digitalogic_test_remote_post_results'][] = new RuntimeException(
            'Injected observer transport exception.'
        );
        Digitalogic_Webhooks::instance();

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(self::$fixture_json);

        $this->assertSame('accepted', $result['status']);
        $this->assertTrue($result['persistence_verified']);
        $this->assertFalse($result['retryable']);
        $this->assertArrayNotHasKey('delivery_warnings', $result);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state(
            'synthetic-fixture',
            'synthetic-kala.db'
        );
        $this->assertSame($result['event_id'], $state['last_event_id']);
    }

    private function configureWebhook(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls'] = array(
            'https://automation.digitalogic.test/webhook/wordpress-events',
        );
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_secret'] = 'observer-test-secret';
    }

    private function actionEnvelope(): array {
        return array(
            'schema' => 'digitalogic.product-sync',
            'schema_version' => '1.0',
            'event_id' => 'sha256:' . str_repeat('a', 64),
            'event_type' => 'snapshot',
            'source' => array(
                'id' => 'patris-office',
                'dataset' => 'kala.db',
                'revision' => 'sha256:' . str_repeat('b', 64),
            ),
            'generated_at' => '2026-07-16T12:00:00Z',
        );
    }

    private function observerPayload($index): array {
        return json_decode(
            $GLOBALS['digitalogic_test_remote_posts'][$index]['args']['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
// phpcs:enable
