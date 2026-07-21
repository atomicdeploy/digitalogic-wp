<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncReceiverTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities']         = array();
        $GLOBALS['digitalogic_test_filters']              = array();
        $GLOBALS['digitalogic_test_routes']               = array();
        $GLOBALS['digitalogic_test_options']              = array();
        $GLOBALS['digitalogic_test_option_cache']         = array();
        $GLOBALS['digitalogic_test_actions']              = array();
        $GLOBALS['digitalogic_test_action_callbacks']     = array();
        $GLOBALS['digitalogic_test_posts']                = array();
        $GLOBALS['digitalogic_test_post_meta_cache']      = array();
        $GLOBALS['digitalogic_test_update_failures']      = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes']        = array();
        $GLOBALS['digitalogic_test_wc_products']          = array();
        $GLOBALS['digitalogic_test_wc_product_saves']     = array();
        $GLOBALS['digitalogic_test_wc_save_failures']     = array();
        $GLOBALS['digitalogic_test_wc_currency']          = 'IRT';
        $GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();
        $this->resetSingleton(Digitalogic_Product_Sync_Receiver::class);
    }

    public function test_accepts_current_golden_fixture_and_requires_catalog_arrays(): void {
        $path   = __DIR__ . '/fixtures/patris-product-sync-golden.json';
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(file_get_contents($path));

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame(0, $result['stored_products']);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('patris-export', 'ALLANBAR');
        $this->assertSame(array(
            'source',
            'generated_at',
            'generated_at_order',
            'last_event_id',
            'last_event_type',
            'products',
            'categories',
            'excluded_codes',
            'quarantined_codes',
            'recent_events',
            'applied_products',
            'pending_products',
            'deferred_products',
            'received_at',
        ), array_keys($state));

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($payload['categories']);
        $invalid = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
        $this->assertInstanceOf(WP_Error::class, $invalid);
        $this->assertSame('digitalogic_product_sync_missing_field', $invalid->get_error_code());
    }

    public function test_sparse_null_empty_and_missing_values_remain_distinct(): void {
        $GLOBALS['digitalogic_test_posts'][701] = array(
            'post_type'   => 'product',
            'post_status' => 'publish',
            'meta'        => array('_digitalogic_patris_product_code' => 'SPARSE-701'),
        );
        $product                                = array(
            'product_code'    => 'SPARSE-701',
            'name'            => null,
            'unit'            => '',
            'warehouse_stock' => array(),
            'warnings'        => array(),
        );
        $product['record_hash']                 = $this->recordHash($product, true);
        $category                               = array(
            'category_code' => 'ROOT',
            'name'          => null,
            'parent_code'   => '',
            'depth'         => 1,
            'warnings'      => array(),
        );
        $category['record_hash']                = $this->recordHash($category);
        $payload                                = $this->snapshot(array($product), array($category));

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $woo           = wc_get_product(701);
        $nullFields    = json_decode($woo->meta['_digitalogic_patris_null_fields'], true, 512, JSON_THROW_ON_ERROR);
        $missingFields = json_decode($woo->meta['_digitalogic_patris_missing_fields'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('name', $nullFields);
        $this->assertContains('serial', $missingFields);
        $this->assertSame('', $woo->meta['_digitalogic_patris_unit']);
        $this->assertSame('[]', $woo->meta['_digitalogic_patris_warehouse_stock']);

        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertNull($state['products']['SPARSE-701']['name']);
        $this->assertSame('', $state['products']['SPARSE-701']['unit']);
        $this->assertSame(array(), $state['products']['SPARSE-701']['warehouse_stock']);
        $this->assertNull($state['categories']['ROOT']['name']);
    }

    public function test_rejects_removed_contract_fields_and_null_final_price(): void {
        $payload                   = $this->snapshot();
        $payload['obsolete_field'] = 'anything';
        $result                    = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
        $this->assertSame('digitalogic_product_sync_unknown_field', $result->get_error_code());

        $product                = array('product_code' => 'NULL-PRICE', 'final_price' => null, 'warnings' => array());
        $product['record_hash'] = $this->recordHash($product, true);
        $payload                = $this->snapshot(array($product), array(), true);
        $result                 = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
        $this->assertSame('digitalogic_product_sync_field_invalid', $result->get_error_code());
    }

    public function test_cny_and_irr_freight_produce_the_same_final_irt_price(): void {
        $base                        = array(
            'foreign_currency'   => 'CNY',
            'foreign_price'      => 100,
            'weight_grams'       => 1000,
            'shipping_method_id' => 'air_express',
            'markup_percent'     => 30,
            'irt_per_cny'        => 30000,
            'final_price'        => 4680000,
            'warnings'           => array(),
        );
        $cny                         = array_merge($base, array(
            'product_code'                   => 'CNY-FREIGHT',
            'shipping_price_per_kg'          => 20,
            'shipping_price_per_kg_currency' => 'CNY',
        ));
        $cny['record_hash']          = $this->recordHash($cny, true);
        $irr                         = array_merge($base, array(
            'product_code'                   => 'IRR-FREIGHT',
            'shipping_price_per_kg'          => 6000000,
            'shipping_price_per_kg_currency' => 'IRR',
        ));
        $irr['record_hash']          = $this->recordHash($irr, true);
        $explicitNull                = array(
            'product_code'                   => 'NULL-FREIGHT',
            'shipping_price_per_kg'          => null,
            'shipping_price_per_kg_currency' => null,
            'warnings'                       => array(),
        );
        $explicitNull['record_hash'] = $this->recordHash($explicitNull, true);
		$nullCurrency                = array(
			'product_code'                   => 'NULL-CURRENCY',
			'shipping_price_per_kg'          => 20,
			'shipping_price_per_kg_currency' => null,
			'warnings'                       => array(),
		);
		$nullCurrency['record_hash'] = $this->recordHash($nullCurrency, true);
		$nullAmount                  = array(
			'product_code'                   => 'NULL-AMOUNT',
			'shipping_price_per_kg'          => null,
			'shipping_price_per_kg_currency' => 'CNY',
			'warnings'                       => array(),
		);
		$nullAmount['record_hash']   = $this->recordHash($nullAmount, true);

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($cny, $irr, $explicitNull, $nullCurrency, $nullAmount), array(), true)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertSame('accepted', $result['status']);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertSame(4680000, $state['products']['CNY-FREIGHT']['final_price']);
        $this->assertSame(4680000, $state['products']['IRR-FREIGHT']['final_price']);
        $this->assertNull($state['products']['NULL-FREIGHT']['shipping_price_per_kg']);
        $this->assertNull($state['products']['NULL-FREIGHT']['shipping_price_per_kg_currency']);
        $this->assertArrayNotHasKey('final_price', $state['products']['NULL-FREIGHT']);
		$this->assertSame('20', $state['products']['NULL-CURRENCY']['shipping_price_per_kg']);
		$this->assertNull($state['products']['NULL-CURRENCY']['shipping_price_per_kg_currency']);
		$this->assertArrayNotHasKey('final_price', $state['products']['NULL-CURRENCY']);
		$this->assertNull($state['products']['NULL-AMOUNT']['shipping_price_per_kg']);
		$this->assertSame('CNY', $state['products']['NULL-AMOUNT']['shipping_price_per_kg_currency']);
		$this->assertArrayNotHasKey('final_price', $state['products']['NULL-AMOUNT']);
    }

    public function test_shipping_price_and_currency_are_an_explicit_pair(): void {
        $priceOnly                = array(
            'product_code'          => 'PRICE-ONLY',
            'shipping_price_per_kg' => 20,
            'warnings'              => array(),
        );
        $priceOnly['record_hash'] = $this->recordHash($priceOnly, true);
        $result                   = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($priceOnly), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_shipping_currency_required', $result->get_error_code());

        $currencyOnly                = array(
            'product_code'                   => 'CURRENCY-ONLY',
            'shipping_price_per_kg_currency' => 'CNY',
            'warnings'                       => array(),
        );
        $currencyOnly['record_hash'] = $this->recordHash($currencyOnly, true);
        $result                      = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($currencyOnly), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_shipping_currency_required', $result->get_error_code());

        $unsupported                = array(
            'product_code'                   => 'UNSUPPORTED-CURRENCY',
            'shipping_price_per_kg'          => 20,
            'shipping_price_per_kg_currency' => 'USD',
            'warnings'                       => array(),
        );
        $unsupported['record_hash'] = $this->recordHash($unsupported, true);
        $result                     = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($unsupported), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_field_invalid', $result->get_error_code());
    }

    private function snapshot($products = array(), $categories = array(), $pricing = false): array {
        $material = array();
        foreach ($products as $product) {
            $material[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        foreach ($categories as $category) {
            $material[] = 'category:' . $category['category_code'] . '=' . $category['record_hash'];
        }
        sort($material, SORT_STRING);
        $source   = array(
            'id'       => 'tests',
            'dataset'  => 'ALLANBAR',
            'revision' => 'sha256:' . hash('sha256', implode("\n", $material)),
        );
        $identity = array(
            'schema'            => 'patris.product-sync',
            'event_type'        => 'snapshot',
        );
        if ($pricing) {
            $identity['local_currency'] = 'IRT';
            $identity['formula_id']     = 'landed_price';
        }
        $identity['source']            = $source;
        $identity['generated_at']      = '2026-07-20T00:00:00Z';
        $identity['products']          = array_map(static fn($product) => $product['product_code'] . '=' . $product['record_hash'], $products);
        $identity['categories']        = array_map(static fn($category) => $category['category_code'] . '=' . $category['record_hash'], $categories);
        $identity['excluded_codes']    = array();
        $identity['quarantined_codes'] = array();
        sort($identity['products'], SORT_STRING);
        sort($identity['categories'], SORT_STRING);

        $envelope = array(
            'schema'            => 'patris.product-sync',
            'event_type'        => 'snapshot',
            'event_id'          => 'sha256:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'source'            => $source,
            'generated_at'      => '2026-07-20T00:00:00Z',
            'products'          => $products,
            'categories'        => $categories,
            'excluded_codes'    => array(),
            'quarantined_codes' => array(),
            'warnings'          => array(),
        );
        if ($pricing) {
            $envelope['local_currency'] = 'IRT';
            $envelope['formula_id']     = 'landed_price';
        }

        return $envelope;
    }

    private function recordHash($record, $warehouseMap = false): string {
        if ($warehouseMap && isset($record['warehouse_stock'])) {
            ksort($record['warehouse_stock'], SORT_STRING);
        }
        ksort($record, SORT_STRING);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($warehouseMap && array_key_exists('warehouse_stock', $record) && array() === $record['warehouse_stock']) {
            $json = str_replace('"warehouse_stock":[]', '"warehouse_stock":{}', $json);
        }
        return 'sha256:' . hash('sha256', $json);
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
