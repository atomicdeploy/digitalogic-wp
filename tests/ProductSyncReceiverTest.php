<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncReceiverTest extends TestCase {
    private static $golden;

    public static function setUpBeforeClass(): void {
        $path = __DIR__ . '/fixtures/patris-product-sync-v1-golden.json';
        self::$golden = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
    }

    public function test_accepts_the_go_generated_synthetic_fixture_with_exact_hashes(): void {
        $path = __DIR__ . '/fixtures/patris-product-sync-v1-golden.json';
        $this->assertSame(
            '409f065af9c8b4adde8410e052a6ca2b11c4c2e6358f73c07db3165f20ba64ec',
            hash_file('sha256', $path)
        );

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(file_get_contents($path));

        $this->assertNotInstanceOf(WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '');
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('sha256:b659a592853f5709fd3ee0d52f7e58738cf3d109fe58d538257738a6dd157dff', $result['event_id']);
        $this->assertSame(2, $result['stored_products']);
        $this->assertSame(2, $result['woocommerce']['missing']);
        $this->assertCount(2, $result['woocommerce']['errors']);
        $this->assertSame(0, $result['woocommerce']['errors_truncated']);
        $source = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('synthetic-fixture', 'synthetic-kala.db');
        $this->assertSame('sha256:eec5251e15aa8d8d29736650afdc0edfa9d2576fe60427af22afe5d7119f7294', $source['source']['revision']);
        $this->assertSame(
            'sha256:fb1fc9e10312f7aaa4659550525a0f9b4536f375e4d005ee1f2e1658a90e4976',
            $source['products']['SYNTH-PRICED-001']['record_hash']
        );
        $this->assertSame('24.5', $source['products']['SYNTH-PRICED-001']['foreign_price']);
        $this->assertSame('240', $source['products']['SYNTH-PRICED-001']['weight_grams']);
        $this->assertSame(2009410, $source['products']['SYNTH-PRICED-001']['final_price']);
    }

    public function test_snapshot_persists_before_event_and_uses_shared_exact_woo_writer(): void {
        $product = $this->product(1);
        $code = $product['product_code'];
        $GLOBALS['digitalogic_test_posts'][700] = array(
            'post_type' => 'product',
            'post_status' => 'draft',
            'meta' => array('_sku' => $code),
        );
        $persisted_before_event = false;
        add_action('digitalogic_product_sync_v1_applied', function() use (&$persisted_before_event) {
            $persisted_before_event = !empty(get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array()));
        }, 10, 2);

        $envelope = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00.123456789Z');
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertTrue($persisted_before_event);
        $this->assertSame(1, $result['woocommerce']['updated'], wp_json_encode($result['woocommerce']));
        $this->assertSame(array(700), $GLOBALS['digitalogic_test_wc_product_saves']);
        $woo = $GLOBALS['digitalogic_test_wc_products'][700];
        $this->assertSame($product['record_hash'], $woo->meta['_digitalogic_patris_record_hash']);
        if (null === $product['final_price']) {
            $this->assertNull($woo->price);
        } else {
            $this->assertSame((string) $product['final_price'], $woo->price);
        }
        $this->assertArrayNotHasKey('_digitalogic_patris_last_feed', $woo->meta);
        $this->assertSame(1, $GLOBALS['wpdb']->acquire_count);
        $this->assertSame(1, $GLOBALS['wpdb']->release_count);
        $this->assertContains(array(Digitalogic_Product_Sync_Receiver::STATE_OPTION, 'options'), $GLOBALS['digitalogic_test_cache_deletes']);
        $this->assertContains(array('notoptions', 'options'), $GLOBALS['digitalogic_test_cache_deletes']);
        $this->assertContains(array('alloptions', 'options'), $GLOBALS['digitalogic_test_cache_deletes']);
    }

    public function test_exact_replay_causes_no_second_write_woo_save_or_domain_event(): void {
        $product = $this->product(0);
        $envelope = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');

        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $option_events = count($GLOBALS['digitalogic_test_actions']['update_option_' . Digitalogic_Product_Sync_Receiver::STATE_OPTION] ?? array());
        $domain_events = count($GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_v1_applied'] ?? array());
        $transaction_queries = count($GLOBALS['wpdb']->queries);
        $second = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('replayed', $second['status']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($option_events, count($GLOBALS['digitalogic_test_actions']['update_option_' . Digitalogic_Product_Sync_Receiver::STATE_OPTION] ?? array()));
        $this->assertSame($domain_events, count($GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_v1_applied'] ?? array()));
        $this->assertSame($transaction_queries, count($GLOBALS['wpdb']->queries));
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_throwing_domain_listener_does_not_turn_committed_event_into_failure(): void {
        $product = $this->product(0);
        $envelope = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        add_action('digitalogic_product_sync_v1_applied', static function() {
            throw new RuntimeException('injected listener failure');
        }, 10, 2);

        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $second = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);

        $this->assertSame('accepted', $first['status']);
        $this->assertSame('digitalogic_product_sync_listener_failed', $first['delivery_warnings'][0]['code']);
        $this->assertSame('replayed', $second['status']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_v1_applied']);
    }

    public function test_update_merges_instead_of_replacing_snapshot(): void {
        $first = $this->product(0);
        $second = $this->product(1);
        $snapshot = $this->envelope('snapshot', array($first), array($first), '2026-07-16T10:00:00Z');
        $this->assertSame('accepted', Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot)['status']);

        $update = $this->envelope('update', array($second), array($first, $second), '2026-07-16T10:01:00Z');
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($update);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');

        $this->assertSame('accepted', $result['status']);
        $this->assertCount(2, $state['products']);
        $this->assertArrayHasKey($first['product_code'], $state['products']);
        $this->assertArrayHasKey($second['product_code'], $state['products']);
    }

    public function test_deletion_only_update_removes_receiver_entry_but_never_woo_product(): void {
        $first = $this->product(0);
        $second = $this->product(1);
        $GLOBALS['digitalogic_test_posts'][801] = array('post_type' => 'product', 'post_status' => 'publish', 'meta' => array('_sku' => $first['product_code']));
        $snapshot = $this->envelope('snapshot', array($first, $second), array($first, $second), '2026-07-16T10:00:00Z');
        Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot);
        $saves_before_delete = count($GLOBALS['digitalogic_test_wc_product_saves']);

        $delete = $this->envelope(
            'update',
            array(),
            array($second),
            '2026-07-16T10:02:00Z',
            array(array('product_code' => $first['product_code'], 'deleted' => true))
        );
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($delete);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(0, $result['received_products']);
        $this->assertSame(1, $result['deleted_codes']);
        $this->assertArrayNotHasKey($first['product_code'], $state['products']);
        $this->assertArrayHasKey($second['product_code'], $state['products']);
        $this->assertArrayHasKey(801, $GLOBALS['digitalogic_test_posts']);
        $this->assertSame($saves_before_delete, count($GLOBALS['digitalogic_test_wc_product_saves']));
    }

    public function test_snapshot_preserves_quarantined_code_then_replaces_other_entries(): void {
        $first = $this->product(0);
        $second = $this->product(1);
        $snapshot = $this->envelope('snapshot', array($first, $second), array($first, $second), '2026-07-16T10:00:00Z');
        Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot);

        $replacement = $this->envelope(
            'snapshot',
            array(),
            array(),
            '2026-07-16T10:03:00Z',
            array(),
            array($first['product_code'])
        );
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($replacement);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');

        $this->assertSame(1, $result['preserved_quarantined']);
        $this->assertCount(1, $state['products']);
        $this->assertArrayHasKey($first['product_code'], $state['products']);
        $this->assertArrayNotHasKey($second['product_code'], $state['products']);
    }

    public function test_rejects_recursive_raw_keys_and_numeric_product_codes(): void {
        $product = $this->product(0);
        $envelope = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        $envelope['products'][0]['metadata'] = array('sHaRh-2' => 'raw');
        $raw = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $this->assertSame('digitalogic_product_sync_raw_key_forbidden', $raw->get_error_code());

        $numeric = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:01Z');
        $numeric['products'][0]['product_code'] = 123;
        $invalid_code = Digitalogic_Product_Sync_Receiver::instance()->receive($numeric);
        $this->assertSame('digitalogic_product_sync_field_invalid', $invalid_code->get_error_code());

        $padded = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:02Z');
        $padded['products'][0]['product_code'] = ' ' . $product['product_code'];
        $invalid_padding = Digitalogic_Product_Sync_Receiver::instance()->receive($padded);
        $this->assertSame('digitalogic_product_sync_field_invalid', $invalid_padding->get_error_code());
    }

    public function test_rejects_record_event_and_source_hash_tampering(): void {
        $product = $this->product(0);
        $record = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        $record['products'][0]['name'] .= ' changed';
        $record_error = Digitalogic_Product_Sync_Receiver::instance()->receive($record);
        $this->assertSame('digitalogic_product_sync_record_hash_mismatch', $record_error->get_error_code());

        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        $event['event_id'] = 'sha256:' . str_repeat('0', 64);
        $event_error = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('digitalogic_product_sync_event_hash_mismatch', $event_error->get_error_code());

        $source = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        $source['source']['revision'] = 'sha256:' . str_repeat('1', 64);
        $source['event_id'] = $this->eventId($source);
        $source_error = Digitalogic_Product_Sync_Receiver::instance()->receive($source);
        $this->assertSame('digitalogic_product_sync_source_revision_mismatch', $source_error->get_error_code());
    }

    public function test_rejects_unsupported_contract_currency_formula_and_types(): void {
        $product = $this->product(0);
        $base = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        $cases = array(
            array('schema_version', '2.0', 'digitalogic_product_sync_version_unsupported'),
            array('local_currency', 'IRR', 'digitalogic_product_sync_currency_unsupported'),
            array('formula_id', 'other_v1', 'digitalogic_product_sync_formula_unsupported'),
            array('formula_revision', '2.0.0', 'digitalogic_product_sync_formula_revision_unsupported'),
        );
        foreach ($cases as $case) {
            $payload = $base;
            $payload[$case[0]] = $case[1];
            $error = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
            $this->assertSame($case[2], $error->get_error_code());
        }

        $GLOBALS['digitalogic_test_wc_currency'] = 'IRR';
        $currency_error = Digitalogic_Product_Sync_Receiver::instance()->receive($base);
        $this->assertSame('digitalogic_product_sync_store_currency_mismatch', $currency_error->get_error_code());
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';

        $typed = $base;
        $typed['products'][0]['warehouse_stock'] = array('1' => '5');
        $error = Digitalogic_Product_Sync_Receiver::instance()->receive($typed);
        $this->assertSame('digitalogic_product_sync_field_invalid', $error->get_error_code());
    }

    public function test_rejects_update_without_baseline_and_stale_or_conflicting_events(): void {
        $first = $this->product(0);
        $second = $this->product(1);
        $update = $this->envelope('update', array($first), array($first), '2026-07-16T10:00:00Z');
        $missing = Digitalogic_Product_Sync_Receiver::instance()->receive($update);
        $this->assertSame('digitalogic_product_sync_baseline_required', $missing->get_error_code());

        $snapshot = $this->envelope('snapshot', array($first), array($first), '2026-07-16T10:05:00Z');
        Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot);
        $stale = $this->envelope('update', array($second), array($first, $second), '2026-07-16T10:04:59.999999999Z');
        $stale_error = Digitalogic_Product_Sync_Receiver::instance()->receive($stale);
        $this->assertSame('digitalogic_product_sync_stale_event', $stale_error->get_error_code());

        $conflict = $this->envelope('update', array($second), array($first, $second), '2026-07-16T10:05:00Z');
        $conflict_error = Digitalogic_Product_Sync_Receiver::instance()->receive($conflict);
        $this->assertSame('digitalogic_product_sync_order_conflict', $conflict_error->get_error_code());
    }

    public function test_lock_and_storage_failures_are_reported_without_domain_event(): void {
        $product = $this->product(0);
        $envelope = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:00Z');
        $GLOBALS['wpdb']->acquire_result = 0;
        $busy = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $this->assertSame('digitalogic_product_sync_busy', $busy->get_error_code());

        $GLOBALS['wpdb']->acquire_result = 1;
        $GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Product_Sync_Receiver::STATE_OPTION;
        $failed = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $this->assertSame('digitalogic_product_sync_storage_failed', $failed->get_error_code());
        $this->assertFalse(get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, false));
        $this->assertContains('ROLLBACK', $GLOBALS['wpdb']->queries);
        $this->assertEmpty($GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_v1_applied'] ?? array());

        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array('START TRANSACTION');
        $unavailable = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $this->assertSame('digitalogic_product_sync_transaction_unavailable', $unavailable->get_error_code());

        $GLOBALS['digitalogic_test_transaction_failures'] = array('COMMIT');
        $commit = Digitalogic_Product_Sync_Receiver::instance()->receive($envelope);
        $this->assertSame('digitalogic_product_sync_commit_failed', $commit->get_error_code());
        $this->assertFalse(get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, false));
    }

    private function product($index) {
        return self::$golden['products'][$index];
    }

    private function envelope($type, $event_products, $full_products, $generated_at, $deleted = array(), $quarantined = array()) {
        usort($event_products, static function($left, $right) {
            return strcmp($left['product_code'], $right['product_code']);
        });
        usort($deleted, static function($left, $right) {
            return strcmp($left['product_code'], $right['product_code']);
        });
        sort($quarantined, SORT_STRING);
        $source = array(
            'id' => 'receiver-tests',
            'dataset' => 'kala.db',
            'revision' => $this->sourceRevision($full_products, $quarantined),
        );
        $envelope = array(
            'schema' => 'digitalogic.product-sync',
            'schema_version' => '1.0',
            'event' => 'digitalogic.product-sync',
            'event_type' => $type,
            'event_id' => '',
            'local_currency' => 'IRT',
            'formula_id' => 'landed_price_v1',
            'formula_revision' => '1.0.0',
            'formula_version' => 'landed_price_v1',
            'source' => $source,
            'generated_at' => $generated_at,
            'products' => array_values($event_products),
        );
        if (!empty($deleted)) {
            $envelope['deleted_codes'] = $deleted;
        }
        if (!empty($quarantined)) {
            $envelope['quarantined_codes'] = $quarantined;
        }
        $envelope['event_id'] = $this->eventId($envelope);

        return $envelope;
    }

    private function sourceRevision($products, $quarantined) {
        $lines = array();
        foreach ($products as $product) {
            if (in_array($product['product_code'], $quarantined, true)) {
                continue;
            }
            $lines[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        sort($lines, SORT_STRING);
        sort($quarantined, SORT_STRING);
        foreach ($quarantined as $code) {
            $lines[] = 'quarantined=' . $code;
        }

        return 'sha256:' . hash('sha256', implode("\n", $lines));
    }

    private function eventId($envelope) {
        $hashes = array();
        foreach ($envelope['products'] as $product) {
            $hashes[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        sort($hashes, SORT_STRING);
        $identity = array(
            'schema' => $envelope['schema'],
            'schema_version' => $envelope['schema_version'],
            'event_type' => $envelope['event_type'],
            'local_currency' => $envelope['local_currency'],
            'formula_id' => $envelope['formula_id'],
            'formula_revision' => $envelope['formula_revision'],
            'source' => array(
                'id' => $envelope['source']['id'],
                'dataset' => $envelope['source']['dataset'],
                'revision' => $envelope['source']['revision'],
            ),
            'products' => $hashes,
        );
        if (!empty($envelope['deleted_codes'])) {
            $identity['deleted_codes'] = $envelope['deleted_codes'];
        }
        if (!empty($envelope['quarantined_codes'])) {
            $identity['quarantined_codes'] = $envelope['quarantined_codes'];
        }

        return 'sha256:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
