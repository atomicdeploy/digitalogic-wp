<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncReceiverTest extends TestCase {
    private static $golden;
    private static $goldenV11;

    public static function setUpBeforeClass(): void {
        $path = __DIR__ . '/fixtures/patris-product-sync-v1-golden.json';
        self::$golden = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $path = __DIR__ . '/fixtures/patris-product-sync-v1.1-golden.json';
        self::$goldenV11 = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
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
        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
    }

    public function test_accepts_the_go_generated_synthetic_fixture_with_exact_hashes(): void {
        $path = __DIR__ . '/fixtures/patris-product-sync-v1-golden.json';
        $this->assertSame(
            '810bdf4d8fd5e3c2a87750a02f241363f6403736c899a625f615967fea259da5',
            hash_file('sha256', $path)
        );

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(file_get_contents($path));

        $this->assertNotInstanceOf(WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '');
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('sha256:25d5afce95dfdcf598c28f9c9639cbd54ed7f2e838a6c285844eee75d972ef06', $result['event_id']);
        $this->assertTrue($result['fully_applied']);
        $this->assertFalse($result['retryable']);
        $this->assertSame(0, $result['pending_products']);
        $this->assertSame(2, $result['deferred_products']);
        $this->assertSame(2, $result['deferred_reconciliation']['missing']);
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

    // phpcs:disable -- Match the established PHPUnit fixture style in this baseline-managed test file.
    public function test_accepts_go_generated_v11_catalog_fixture_with_exact_hashes_and_durable_state(): void {
        $path = __DIR__ . '/fixtures/patris-product-sync-v1.1-golden.json';
        $this->assertSame(
            '8a635fa63cadc2b5021879c32e3ee47ce9d4928806948c154cdeb1b744da61fd',
            hash_file('sha256', $path)
        );
        $GLOBALS['digitalogic_test_posts'][699] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => '101001001'),
        );

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(file_get_contents($path));

        $this->assertNotInstanceOf(WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '');
        $this->assertSame('accepted', $result['status']);
        $this->assertSame('sha256:4230ed302661c27a3b1bac71c60fac6a82c43bfb1ac0a7942137695b00afc2ac', $result['event_id']);
        $this->assertSame(2, $result['received_products']);
        $this->assertSame(2, $result['received_categories']);
        $this->assertSame(2, $result['stored_categories']);
        $this->assertSame(1, $result['excluded_codes']);
        $this->assertSame(1, $result['woocommerce']['updated']);
        $this->assertSame(1, $result['deferred_products']);

        $source = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('synthetic-fixture', 'synthetic-kala.db');
        $this->assertSame('1.1', $source['schema_version']);
        $this->assertSame('101001', $source['products']['101001001']['category_code']);
        $this->assertSame('101', $source['categories']['101001']['parent_code']);
        $this->assertSame(array('999010'), $source['excluded_codes']);
        $this->assertSame('101001', $GLOBALS['digitalogic_test_wc_products'][699]->meta['_digitalogic_patris_category_code']);

        $status = Digitalogic_Product_Sync_Receiver::instance()->get_status();
        $this->assertSame(2, $status['totals']['stored_categories']);
        $this->assertSame(1, $status['totals']['excluded_codes']);
        $this->assertSame('1.1', $status['sources'][0]['schema_version']);
    }
    // phpcs:enable

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
        $GLOBALS['digitalogic_test_posts'][701] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
        );
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
        $this->assertSame(array(701), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    // phpcs:disable -- Match the established PHPUnit fixture style in this baseline-managed test file.
    public function test_numeric_only_code_stays_string_typed_through_first_delivery_and_persisted_retry(): void {
        $product = $this->productWithCode(0, '113001001');
        $code = $product['product_code'];
        $GLOBALS['digitalogic_test_posts'][704] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => $code),
        );
        $GLOBALS['digitalogic_test_wc_save_failures'] = array(704);
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:30Z');

        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($event);

        $this->assertSame('partially_applied', $first['status']);
        $this->assertSame(1, $first['pending_products']);
        $this->assertSame(0, $first['deferred_products']);
        $this->assertSame('digitalogic_product_sync_woocommerce_write_failed', $first['woocommerce']['errors'][0]['code']);
        $this->assertSame($code, $first['woocommerce']['errors'][0]['product_code']);
        $this->assertIsString($first['woocommerce']['errors'][0]['product_code']);

        $state = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());
        $source_key = array_key_first($state['sources']);
        $this->assertSame(4, $state['version']);
        $this->assertSame($code, $state['sources'][$source_key]['products'][$code]['product_code']);
        $this->assertIsString($state['sources'][$source_key]['products'][$code]['product_code']);
        $this->assertSame($code, $state['sources'][$source_key]['pending_products'][$code]['product_code']);
        $this->assertIsString($state['sources'][$source_key]['pending_products'][$code]['product_code']);

        // Reproduce the pre-fix v3 shape already persisted by the live canary:
        // the numeric map key is an integer and the entry has no redundant code.
        unset($state['sources'][$source_key]['pending_products'][$code]['product_code']);
        update_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false);
        $GLOBALS['digitalogic_test_wc_save_failures'] = array();

        $retry = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $persisted = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());

        $this->assertSame('recovered', $retry['status']);
        $this->assertFalse($retry['retryable']);
        $this->assertSame(1, $retry['woocommerce']['updated']);
        $this->assertSame(array(704), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertSame($code, $persisted['sources'][$source_key]['applied_products'][$code]['product_code']);
        $this->assertIsString($persisted['sources'][$source_key]['applied_products'][$code]['product_code']);
    }

    public function test_numeric_only_deferred_code_reconciles_from_legacy_v3_state_by_exact_sku(): void {
        $product = $this->productWithCode(0, '113003030');
        $code = $product['product_code'];
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T10:00:45Z');

        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($event);

        $this->assertSame('accepted', $first['status']);
        $this->assertFalse($first['retryable']);
        $this->assertSame(0, $first['pending_products']);
        $this->assertSame(1, $first['deferred_products']);
        $this->assertSame($code, $first['woocommerce']['errors'][0]['product_code']);
        $this->assertIsString($first['woocommerce']['errors'][0]['product_code']);
        $this->assertSame($code, $first['deferred_reconciliation']['details'][0]['product_code']);
        $this->assertIsString($first['deferred_reconciliation']['details'][0]['product_code']);

        $state = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());
        $source_key = array_key_first($state['sources']);
        $this->assertSame($code, $state['sources'][$source_key]['deferred_products'][$code]['product_code']);
        unset($state['sources'][$source_key]['deferred_products'][$code]['product_code']);
        update_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false);
        $GLOBALS['digitalogic_test_posts'][705] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => $code),
        );

        $reconciled = Digitalogic_Product_Sync_Receiver::instance()->reconcile('receiver-tests', 'kala.db');
        $persisted = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());

        $this->assertSame('reconciled', $reconciled['status']);
        $this->assertSame(1, $reconciled['sources'][0]['woocommerce']['updated']);
        $this->assertSame(0, $reconciled['pending_products']);
        $this->assertSame(0, $reconciled['deferred_products']);
        $this->assertSame(array(705), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertSame($code, $persisted['sources'][$source_key]['applied_products'][$code]['product_code']);
        $this->assertIsString($persisted['sources'][$source_key]['applied_products'][$code]['product_code']);
    }

    public function test_numeric_only_code_overlap_error_projects_the_canonical_string(): void {
        $product = $this->productWithCode(0, '113001001');
        $event = $this->envelope(
            'snapshot',
            array($product),
            array($product),
            '2026-07-16T10:00:50Z',
            array(),
            array($product['product_code'])
        );

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($event);

        $this->assertSame('digitalogic_product_sync_code_overlap', $result->get_error_code());
        $this->assertSame($product['product_code'], $result->get_error_data()['product_code']);
        $this->assertIsString($result->get_error_data()['product_code']);
    }
    // phpcs:enable

    public function test_throwing_domain_listener_does_not_turn_committed_event_into_failure(): void {
        $product = $this->product(0);
        $GLOBALS['digitalogic_test_posts'][702] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
        );
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

    // phpcs:disable -- Match the established PHPUnit fixture style in this baseline-managed test file.
    public function test_v11_update_merges_products_and_replaces_complete_catalog_projection(): void {
        $first = $this->v11Product(0);
        $second = $this->v11Product(1);
        $categories = array($this->v11Category(0), $this->v11Category(1));
        $snapshot = $this->v11Envelope(
            'snapshot',
            array($first),
            array($first),
            '2026-07-16T10:00:00Z',
            $categories,
            array('999010')
        );
        $this->assertSame('accepted', Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot)['status']);

        $categories[1]['name'] = 'Renamed synthetic modules';
        $categories[1] = $this->rehashCategory($categories[1]);
        $update = $this->v11Envelope(
            'update',
            array($second),
            array($first, $second),
            '2026-07-16T10:01:00Z',
            $categories,
            array('999991')
        );
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($update);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');

        $this->assertSame('accepted', $result['status']);
        $this->assertCount(2, $state['products']);
        $this->assertSame('Renamed synthetic modules', $state['categories']['101001']['name']);
        $this->assertSame(array('999991'), $state['excluded_codes']);
        $this->assertSame($update['source']['revision'], $state['source']['revision']);
    }

    public function test_v11_rejects_category_hash_tampering_and_dangling_product_category(): void {
        $tampered = self::$goldenV11;
        $tampered['categories'][1]['name'] .= ' changed';
        $hash_error = Digitalogic_Product_Sync_Receiver::instance()->receive($tampered);
        $this->assertSame('digitalogic_product_sync_category_hash_mismatch', $hash_error->get_error_code());

        $product = $this->v11Product(0);
        $product['category_code'] = '404';
        $product = $this->rehashProduct($product);
        $event = $this->v11Envelope(
            'snapshot',
            array($product),
            array($product),
            '2026-07-16T10:02:00Z',
            array($this->v11Category(0), $this->v11Category(1)),
            array()
        );
        $reference_error = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('digitalogic_product_sync_category_reference_invalid', $reference_error->get_error_code());
    }

    public function test_contract_feature_level_change_requires_a_fresh_snapshot(): void {
        $legacy = $this->product(0);
        $snapshot = $this->envelope('snapshot', array($legacy), array($legacy), '2026-07-16T10:00:00Z');
        $this->assertSame('accepted', Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot)['status']);

        $product = $this->v11Product(0);
        $update = $this->v11Envelope(
            'update',
            array($product),
            array($product),
            '2026-07-16T10:01:00Z',
            array($this->v11Category(0), $this->v11Category(1)),
            array('999010')
        );
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($update);

        $this->assertSame('digitalogic_product_sync_baseline_required', $result->get_error_code());
        $this->assertSame('1.0', $result->get_error_data()['stored_schema_version']);
        $this->assertSame('1.1', $result->get_error_data()['received_schema_version']);
    }
    // phpcs:enable

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
        $this->assertSame('IRR', $currency_error->get_error_data()['woocommerce_base_currency']);
        $this->assertSame('IRT', $currency_error->get_error_data()['required_currency']);
        $this->assertSame('woocommerce_base_currency_must_be_irt', $currency_error->get_error_data()['warning']);
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

    public function test_independently_recomputes_landed_price_and_requires_null_when_inputs_are_incomplete(): void {
        $priced = $this->product(1);
        $priced['final_price']++;
        $priced = $this->rehashProduct($priced);
        $wrong = $this->envelope('snapshot', array($priced), array($priced), '2026-07-16T11:00:00Z');
        $wrong_result = Digitalogic_Product_Sync_Receiver::instance()->receive($wrong);
        $this->assertSame('digitalogic_product_sync_final_price_mismatch', $wrong_result->get_error_code());
        $this->assertSame(2009410, $wrong_result->get_error_data()['expected']);

        $incomplete = $this->product(0);
        $incomplete['final_price'] = 1;
        $incomplete = $this->rehashProduct($incomplete);
        $nonnull = $this->envelope('snapshot', array($incomplete), array($incomplete), '2026-07-16T11:01:00Z');
        $nonnull_result = Digitalogic_Product_Sync_Receiver::instance()->receive($nonnull);
        $this->assertSame('digitalogic_product_sync_final_price_mismatch', $nonnull_result->get_error_code());
        $this->assertNull($nonnull_result->get_error_data()['expected']);
        $this->assertContains('foreign_price', $nonnull_result->get_error_data()['missing']);
    }

    public function test_decimal_formula_uses_one_exact_half_up_integer_round_and_enforces_bounds(): void {
        $product = $this->product(1);
        $product['foreign_price'] = 1;
        $product['weight_grams'] = 1;
        $product['freight_cny_per_kg'] = 1;
        $product['markup_percent'] = 0;
        $product['irt_per_cny'] = 500;
        $product['final_price'] = 501; // (1 + 1/1000) * 500 = 500.5.
        $product = $this->rehashProduct($product);
        $GLOBALS['digitalogic_test_posts'][703] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
        );
        $accepted = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:02:00Z')
        );
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame('501', $GLOBALS['digitalogic_test_wc_products'][703]->price);

        $product['markup_percent'] = 1000.1;
        $product['final_price'] = 5506;
        $product = $this->rehashProduct($product);
        $bounded = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:03:00Z')
        );
        $this->assertSame('digitalogic_product_sync_field_invalid', $bounded->get_error_code());
        $this->assertSame('products[0].markup_percent', $bounded->get_error_data()['field']);

        $product['markup_percent'] = 0.1234567890123;
        $product = $this->rehashProduct($product);
        $scale = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:04:00Z')
        );
        $this->assertSame('digitalogic_product_sync_field_invalid', $scale->get_error_code());
        $this->assertStringContainsString('fractional digits', $scale->get_error_data()['reason']);
    }

    public function test_failed_woo_save_stays_pending_and_identical_replay_recovers(): void {
        $product = $this->product(1);
        $GLOBALS['digitalogic_test_posts'][710] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
        );
        $GLOBALS['digitalogic_test_wc_save_failures'] = array(710);
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:10:00Z');

        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('partially_applied', $first['status']);
        $this->assertSame(1, $first['woocommerce']['failed']);
        $this->assertSame(1, $first['pending_products']);
        $this->assertEmpty($GLOBALS['digitalogic_test_wc_product_saves']);

        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $second = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('recovered', $second['status']);
        $this->assertTrue($second['replayed']);
        $this->assertTrue($second['fully_applied']);
        $this->assertSame(0, $second['pending_products']);
        $this->assertSame(array(710), $GLOBALS['digitalogic_test_wc_product_saves']);

        $third = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('replayed', $third['status']);
        $this->assertSame(array(710), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_partial_batch_retries_only_the_unapplied_product(): void {
        $first_product = $this->product(0);
        $second_product = $this->product(1);
        $GLOBALS['digitalogic_test_posts'][711] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $first_product['product_code']),
        );
        $GLOBALS['digitalogic_test_posts'][712] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $second_product['product_code']),
        );
        $GLOBALS['digitalogic_test_wc_save_failures'] = array(712);
        $event = $this->envelope(
            'snapshot',
            array($first_product, $second_product),
            array($first_product, $second_product),
            '2026-07-16T11:11:00Z'
        );

        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('partially_applied', $first['status']);
        $this->assertSame(1, $first['woocommerce']['updated']);
        $this->assertSame(1, $first['woocommerce']['failed']);
        $this->assertSame(array(711), $GLOBALS['digitalogic_test_wc_product_saves']);

        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $second = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('recovered', $second['status']);
        $this->assertSame(1, $second['woocommerce']['updated']);
        $this->assertSame(array(711, 712), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_missing_product_is_terminal_until_explicit_reconciliation(): void {
        $product = $this->product(0);
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:12:00Z');
        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('accepted', $first['status']);
        $this->assertSame(1, $first['woocommerce']['missing']);
        $this->assertFalse($first['retryable']);
        $this->assertSame(0, $first['pending_products']);
        $this->assertSame(1, $first['deferred_products']);

        $GLOBALS['digitalogic_test_posts'][713] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => $product['product_code']),
        );
        $replay = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('replayed', $replay['status']);
        $this->assertSame(1, $replay['deferred_products']);
        $this->assertEmpty($GLOBALS['digitalogic_test_wc_product_saves']);

        $before = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');
        $recovered = Digitalogic_Product_Sync_Receiver::instance()->reconcile('receiver-tests', 'kala.db');
        $after = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');
        $this->assertSame('reconciled', $recovered['status']);
        $this->assertSame(1, $recovered['sources'][0]['woocommerce']['updated']);
        $this->assertSame(0, $recovered['deferred_products']);
        $this->assertSame($before['last_event_id'], $after['last_event_id']);
        $this->assertSame($before['generated_at'], $after['generated_at']);
        $this->assertSame(array(713), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_persisted_record_hash_cas_acks_without_a_duplicate_woo_save(): void {
        $product = $this->product(0);
        $GLOBALS['digitalogic_test_posts'][714] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
        );
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:13:00Z');
        $this->assertSame('accepted', Digitalogic_Product_Sync_Receiver::instance()->receive($event)['status']);
        $this->assertSame(array(714), $GLOBALS['digitalogic_test_wc_product_saves']);

        $state = Digitalogic_Product_Sync_Receiver::instance()->get_state();
        $source_key = array_key_first($state['sources']);
        unset($state['sources'][$source_key]['applied_products'][$product['product_code']]);
        $state['sources'][$source_key]['pending_products'][$product['product_code']] = array(
            'record_hash' => $product['record_hash'],
            'queued_event_id' => $event['event_id'],
            'attempts' => 0,
        );
        update_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false);

        $recovered = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('recovered', $recovered['status']);
        $this->assertSame(1, $recovered['woocommerce']['already_applied']);
        $this->assertSame(array(714), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_patris_code_is_canonical_and_cross_namespace_collision_is_deferred(): void {
        $product = $this->product(0);
        $code = $product['product_code'];
        $GLOBALS['digitalogic_test_posts'][720] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => $code),
        );
        $GLOBALS['digitalogic_test_posts'][721] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $code),
        );
        $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(array('code' => $code));
        $this->assertSame('digitalogic_product_identifier_ambiguous', $resolved->get_error_code());
        $this->assertSame('cross_namespace_collision', $resolved->get_error_data()['reason']);

        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:14:00Z');
        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('accepted', $first['status']);
        $this->assertSame(1, $first['woocommerce']['ambiguous']);
        $this->assertSame(0, $first['pending_products']);
        $this->assertSame(1, $first['deferred_products']);
        $this->assertSame('ambiguous', $first['deferred_reconciliation']['details'][0]['reason']);
        $this->assertEmpty($GLOBALS['digitalogic_test_wc_product_saves']);

        $GLOBALS['digitalogic_test_posts'][720]['meta']['_sku'] = 'OTHER-SKU';
        $replayed = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('replayed', $replayed['status']);
        $recovered = Digitalogic_Product_Sync_Receiver::instance()->reconcile('receiver-tests', 'kala.db');
        $this->assertSame('reconciled', $recovered['status']);
        $this->assertSame(array(721), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    // phpcs:disable -- Match the established PHPUnit fixture style in this baseline-managed test file.
    public function test_mixed_snapshot_retries_only_transient_work_and_new_event_reconciles_deferred(): void {
        $matched = $this->productWithCode(0, 'MIX-A-MATCHED');
        $missing = $this->productWithCode(0, 'MIX-B-MISSING');
        $ambiguous = $this->productWithCode(0, 'MIX-C-AMBIGUOUS');
        $transient = $this->productWithCode(0, 'MIX-D-TRANSIENT');
        $products = array($matched, $missing, $ambiguous, $transient);

        $GLOBALS['digitalogic_test_posts'][730] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $matched['product_code']),
        );
        $GLOBALS['digitalogic_test_posts'][731] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => $ambiguous['product_code']),
        );
        $GLOBALS['digitalogic_test_posts'][732] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $ambiguous['product_code']),
        );
        $GLOBALS['digitalogic_test_posts'][733] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $transient['product_code']),
        );
        $GLOBALS['digitalogic_test_wc_save_failures'] = array(733);

        $snapshot = $this->envelope('snapshot', $products, $products, '2026-07-16T11:15:00Z');
        $first = Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot);

        $this->assertSame('partially_applied', $first['status']);
        $this->assertTrue($first['retryable']);
        $this->assertSame(1, $first['pending_products']);
        $this->assertSame(2, $first['deferred_products']);
        $this->assertIsInt($first['deferred_products']);
        $this->assertLessThanOrEqual(2147483647, $first['deferred_products']);
        $this->assertSame(
            $first['deferred_products'],
            $first['deferred_reconciliation']['missing'] + $first['deferred_reconciliation']['ambiguous']
        );
        $this->assertSame(1, $first['woocommerce']['updated']);
        $this->assertSame(1, $first['woocommerce']['missing']);
        $this->assertSame(1, $first['woocommerce']['ambiguous']);
        $this->assertSame(1, $first['woocommerce']['failed']);
        $this->assertSame(array(730), $GLOBALS['digitalogic_test_wc_product_saves']);

        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $retry = Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot);
        $this->assertSame('recovered', $retry['status']);
        $this->assertFalse($retry['retryable']);
        $this->assertSame(0, $retry['pending_products']);
        $this->assertSame(2, $retry['deferred_products']);
        $this->assertSame(1, $retry['woocommerce']['attempted']);
        $this->assertSame(array(730, 733), $GLOBALS['digitalogic_test_wc_product_saves']);

        $replay = Digitalogic_Product_Sync_Receiver::instance()->receive($snapshot);
        $this->assertSame('replayed', $replay['status']);
        $this->assertSame(2, $replay['deferred_products']);
        $this->assertSame(array(730, 733), $GLOBALS['digitalogic_test_wc_product_saves']);

        $GLOBALS['digitalogic_test_posts'][734] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => $missing['product_code']),
        );
        $GLOBALS['digitalogic_test_posts'][731]['meta']['_sku'] = 'COLLISION-RESOLVED';
        $later = $this->envelope('update', array(), $products, '2026-07-16T11:16:00Z');
        $reconciled = Digitalogic_Product_Sync_Receiver::instance()->receive($later);

        $this->assertSame('already_current', $reconciled['status']);
        $this->assertFalse($reconciled['retryable']);
        $this->assertSame(0, $reconciled['pending_products']);
        $this->assertSame(0, $reconciled['deferred_products']);
        $this->assertSame(2, $reconciled['woocommerce']['attempted']);
        $this->assertSame(2, $reconciled['woocommerce']['updated']);
        $this->assertSame(array(730, 733, 734, 732), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_deferred_state_readback_failure_rolls_back_to_retryable_outbox(): void {
        $product = $this->productWithCode(0, 'DEFERRED-READBACK');
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:16:30Z');
        $writes = 0;
        $callback = null;
        $callback = static function($database, $option_name) use (&$callback, &$writes): void {
            $writes++;
            if (1 === $writes) {
                $database->after_option_write = $callback;
                return;
            }
            $GLOBALS['digitalogic_test_options'][$option_name]['tampered_after_write'] = true;
        };
        $GLOBALS['wpdb']->after_option_write = $callback;

        $failed = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertInstanceOf(WP_Error::class, $failed);
        $this->assertSame('digitalogic_product_sync_readback_failed', $failed->get_error_code());
        $this->assertContains('ROLLBACK', $GLOBALS['wpdb']->queries);

        $rolled_back = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');
        $this->assertCount(1, $rolled_back['pending_products']);
        $this->assertCount(0, $rolled_back['deferred_products']);

        $recovered = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('recovered', $recovered['status']);
        $this->assertFalse($recovered['retryable']);
        $this->assertSame(0, $recovered['pending_products']);
        $this->assertSame(1, $recovered['deferred_products']);
    }

    public function test_identifier_query_failure_stays_pending_until_an_identical_retry_can_resolve(): void {
        $product = $this->productWithCode(0, 'QUERY-FAILURE');
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:16:45Z');
        $GLOBALS['wpdb']->identifier_query_failure = true;

        $failed = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('partially_applied', $failed['status']);
        $this->assertTrue($failed['retryable']);
        $this->assertSame(1, $failed['pending_products']);
        $this->assertSame(0, $failed['deferred_products']);
        $this->assertSame(1, $failed['woocommerce']['failed']);
        $this->assertSame(
            'digitalogic_product_identifier_query_failed',
            $failed['woocommerce']['errors'][0]['code']
        );
        $this->assertTrue($failed['woocommerce']['errors'][0]['retryable']);

        $GLOBALS['wpdb']->identifier_query_failure = false;
        $recovered = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('recovered', $recovered['status']);
        $this->assertFalse($recovered['retryable']);
        $this->assertSame(0, $recovered['pending_products']);
        $this->assertSame(1, $recovered['deferred_products']);
        $this->assertSame(1, $recovered['woocommerce']['missing']);
    }

    public function test_v2_not_found_state_is_requeried_before_it_can_be_deferred(): void {
        $product = $this->productWithCode(0, 'MIGRATE-MISSING');
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:17:00Z');
        $this->assertSame('accepted', Digitalogic_Product_Sync_Receiver::instance()->receive($event)['status']);

        $state = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());
        $source_key = array_key_first($state['sources']);
        $state['version'] = 2;
        $entry = $state['sources'][$source_key]['deferred_products'][$product['product_code']];
        unset($entry['reason'], $state['sources'][$source_key]['deferred_products']);
        $state['sources'][$source_key]['pending_products'][$product['product_code']] = $entry;
        update_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false);

        $projected = Digitalogic_Product_Sync_Receiver::instance()->get_state();
        $this->assertSame(4, $projected['version']);
        $this->assertCount(1, $projected['sources'][$source_key]['pending_products']);
        $this->assertCount(0, $projected['sources'][$source_key]['deferred_products']);
        $this->assertSame(2, get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array())['version']);

        $GLOBALS['wpdb']->identifier_query_count = 0;
        $replay = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $this->assertSame('recovered', $replay['status']);
        $this->assertSame(0, $replay['pending_products']);
        $this->assertSame(1, $replay['deferred_products']);
        $this->assertSame(1, $GLOBALS['wpdb']->identifier_query_count);
        $this->assertSame(4, get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array())['version']);
        $this->assertEmpty($GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_v3_state_projects_and_persists_empty_v10_catalog_fields(): void {
        $product = $this->productWithCode(0, 'MIGRATE-V3');
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:17:30Z');
        $this->assertSame('accepted', Digitalogic_Product_Sync_Receiver::instance()->receive($event)['status']);

        $state = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());
        $source_key = array_key_first($state['sources']);
        $state['version'] = 3;
        unset(
            $state['sources'][$source_key]['schema_version'],
            $state['sources'][$source_key]['categories'],
            $state['sources'][$source_key]['excluded_codes']
        );
        update_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false);

        $projected = Digitalogic_Product_Sync_Receiver::instance()->get_state();
        $this->assertSame(4, $projected['version']);
        $this->assertSame('1.0', $projected['sources'][$source_key]['schema_version']);
        $this->assertSame(array(), $projected['sources'][$source_key]['categories']);
        $this->assertSame(array(), $projected['sources'][$source_key]['excluded_codes']);
        $this->assertSame(3, get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array())['version']);

        $this->assertSame('replayed', Digitalogic_Product_Sync_Receiver::instance()->receive($event)['status']);
        $persisted = get_option(Digitalogic_Product_Sync_Receiver::STATE_OPTION, array());
        $this->assertSame(4, $persisted['version']);
        $this->assertSame('1.0', $persisted['sources'][$source_key]['schema_version']);
        $this->assertSame(array(), $persisted['sources'][$source_key]['categories']);
        $this->assertSame(array(), $persisted['sources'][$source_key]['excluded_codes']);
    }

    public function test_deferred_response_details_and_cli_status_are_bounded_and_nonsecret(): void {
        $products = array();
        for ($index = 0; $index < 101; $index++) {
            $products[] = $this->productWithCode(0, sprintf('BOUND-%03d', $index));
        }
        $event = $this->envelope('snapshot', $products, $products, '2026-07-16T11:18:00Z');
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $status = Digitalogic_Product_Sync_Receiver::instance()->get_status();

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(101, $result['deferred_products']);
        $this->assertIsInt($result['deferred_products']);
        $this->assertLessThanOrEqual(2147483647, $result['deferred_products']);
        $this->assertCount(100, $result['deferred_reconciliation']['details']);
        $this->assertSame(1, $result['deferred_reconciliation']['details_truncated']);
        $this->assertSame(
            $result['deferred_products'],
            count($result['deferred_reconciliation']['details'])
                + $result['deferred_reconciliation']['details_truncated']
        );
        $this->assertSame(101, $status['totals']['deferred_products']);
        $this->assertSame(101, $status['sources'][0]['stored_products']);
        $this->assertArrayNotHasKey('products', $status['sources'][0]);

        WP_CLI::$logs = array();
        (new Digitalogic_CLI_Commands())->product_sync_status();
        $output = implode("\n", WP_CLI::$logs);
        $this->assertStringContainsString('"deferred_products":101', $output);
        $this->assertStringNotContainsString('BOUND-000', $output);
        $this->assertArrayHasKey('digitalogic product-sync status', WP_CLI::$commands);
        $this->assertArrayHasKey('digitalogic product-sync reconcile', WP_CLI::$commands);
    }

    public function test_reconciliation_cli_requires_admin_and_retries_only_durable_work(): void {
        $product = $this->productWithCode(0, 'CLI-MISSING');
        $event = $this->envelope('snapshot', array($product), array($product), '2026-07-16T11:19:00Z');
        Digitalogic_Product_Sync_Receiver::instance()->receive($event);
        $GLOBALS['digitalogic_test_posts'][735] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => $product['product_code']),
        );
        WP_CLI::$errors = array();
        WP_CLI::$logs = array();
        $command = new Digitalogic_CLI_Commands();

        $command->product_sync_reconcile(array(), array());
        $this->assertNotEmpty(WP_CLI::$errors);
        $this->assertEmpty($GLOBALS['digitalogic_test_wc_product_saves']);

        $GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
        WP_CLI::$errors = array();
        $command->product_sync_reconcile(array(), array(
            'source-id' => 'receiver-tests',
            'dataset' => 'kala.db',
        ));
        $this->assertEmpty(WP_CLI::$errors);
        $this->assertSame(array(735), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertStringContainsString('"status":"reconciled"', implode("\n", WP_CLI::$logs));
    }
    // phpcs:enable

    public function test_newer_same_content_occurrence_advances_watermark_before_older_change(): void {
        $first = $this->product(0);
        $second = $this->product(1);
        $jan2 = $this->envelope('snapshot', array($first), array($first), '2026-01-02T00:00:00Z');
        $jan4 = $this->envelope('snapshot', array($first), array($first), '2026-01-04T00:00:00Z');
        $this->assertNotSame($jan2['event_id'], $jan4['event_id']);
        Digitalogic_Product_Sync_Receiver::instance()->receive($jan2);
        $same_content = Digitalogic_Product_Sync_Receiver::instance()->receive($jan4);
        $this->assertFalse($same_content['replayed']);

        $jan3 = $this->envelope('update', array($second), array($first, $second), '2026-01-03T00:00:00Z');
        $stale = Digitalogic_Product_Sync_Receiver::instance()->receive($jan3);
        $this->assertSame('digitalogic_product_sync_stale_event', $stale->get_error_code());
        $source = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('receiver-tests', 'kala.db');
        $this->assertSame('2026-01-04T00:00:00Z', $source['generated_at']);
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

    // phpcs:disable -- Match the established PHPUnit fixture style in this baseline-managed test file.
    private function v11Product($index) {
        return self::$goldenV11['products'][$index];
    }

    private function v11Category($index) {
        return self::$goldenV11['categories'][$index];
    }

    private function productWithCode($index, $code) {
        $product = $this->product($index);
        $product['product_code'] = $code;

        return $this->rehashProduct($product);
    }
    private function rehashProduct($product) {
        unset($product['record_hash']);
        ksort($product['warehouse_stock'], SORT_STRING);
        $product['record_hash'] = 'sha256:' . hash(
            'sha256',
            json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $product;
    }

    private function rehashCategory($category) {
        unset($category['record_hash']);
        $category['record_hash'] = 'sha256:' . hash(
            'sha256',
            json_encode($category, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $category;
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

    private function v11Envelope($type, $event_products, $full_products, $generated_at, $categories, $excluded, $deleted = array(), $quarantined = array()) {
        usort($event_products, static function($left, $right) {
            return strcmp($left['product_code'], $right['product_code']);
        });
        usort($categories, static function($left, $right) {
            return strcmp($left['category_code'], $right['category_code']);
        });
        usort($deleted, static function($left, $right) {
            return strcmp($left['product_code'], $right['product_code']);
        });
        sort($excluded, SORT_STRING);
        sort($quarantined, SORT_STRING);
        $source = array(
            'id' => 'receiver-tests',
            'dataset' => 'kala.db',
            'revision' => $this->sourceRevision($full_products, $quarantined, $categories, $excluded),
        );
        $envelope = array(
            'schema' => 'digitalogic.product-sync',
            'schema_version' => '1.1',
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
            'categories' => array_values($categories),
            'excluded_codes' => array_values($excluded),
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

    private function sourceRevision($products, $quarantined, $categories = array(), $excluded = array()) {
        $lines = array();
        foreach ($products as $product) {
            if (in_array($product['product_code'], $quarantined, true)) {
                continue;
            }
            $lines[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        foreach ($categories as $category) {
            $lines[] = 'category:' . $category['category_code'] . '=' . $category['record_hash'];
        }
        foreach ($excluded as $code) {
            $lines[] = 'excluded=' . $code;
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
        $category_hashes = array();
        foreach ($envelope['categories'] ?? array() as $category) {
            $category_hashes[] = $category['category_code'] . '=' . $category['record_hash'];
        }
        sort($category_hashes, SORT_STRING);
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
            'generated_at' => $envelope['generated_at'],
            'products' => $hashes,
        );
        if (!empty($category_hashes)) {
            $identity['categories'] = $category_hashes;
        }
        if (!empty($envelope['excluded_codes'])) {
            $identity['excluded_codes'] = $envelope['excluded_codes'];
        }
        if (!empty($envelope['deleted_codes'])) {
            $identity['deleted_codes'] = $envelope['deleted_codes'];
        }
        if (!empty($envelope['quarantined_codes'])) {
            $identity['quarantined_codes'] = $envelope['quarantined_codes'];
        }

        return 'sha256:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    // phpcs:enable
}
