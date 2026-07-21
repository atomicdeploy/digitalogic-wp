<?php

use PHPUnit\Framework\TestCase;

final class ShippingMethodServiceTest extends TestCase {
    private $service;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array(
            'options_yuan_price' => '25300',
            'options_update_date' => '260716',
            'digitalogic_patris_feed_settings' => array(
                'selected_warehouses' => array('tehran', 'shenzhen'),
            ),
        );
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_post_meta_cache'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_meta_update_failures'] = array();
        $GLOBALS['digitalogic_test_meta_delete_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_remote_posts'] = array();
        $GLOBALS['digitalogic_test_remote_post_results'] = array();
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $this->resetSingleton(Digitalogic_Shipping_Method_Service::class);
        $this->resetSingleton(Digitalogic_WooCommerce_Currency_Status::class);
        $this->service = Digitalogic_Shipping_Method_Service::instance();
    }

    public function test_catalog_has_exact_sparse_living_shape(): void {
        $catalog = $this->service->get_integration_catalog();

        $this->assertSame(
            array('schema', 'revision', 'currency', 'pricing', 'selected_warehouses', 'shipping_methods'),
            array_keys($catalog)
        );
        $this->assertSame('digitalogic.integration-catalog', $catalog['schema']);
        $this->assertSame(array('formula_id' => 'landed_price'), $catalog['pricing']);
        $this->assertSame(array('shenzhen', 'tehran'), $catalog['selected_warehouses']);
        $this->assertSame(
            array('id', 'name', 'enabled', 'shipping_price_per_kg_cny'),
            array_keys($catalog['shipping_methods'][0])
        );
        $this->assertStringNotContainsString('null', json_encode($catalog));
        $this->assertArrayNotHasKey(Digitalogic_Shipping_Method_Service::METHODS_OPTION, $GLOBALS['digitalogic_test_options']);
    }

    public function test_only_canonical_shipping_field_is_accepted(): void {
        $created = $this->service->create_method(array(
            'id' => 'rail',
            'name' => 'Rail',
            'shipping_price_per_kg_cny' => 42,
        ));
        $this->assertNotInstanceOf(WP_Error::class, $created);
        $this->assertSame(42.0, $created['shipping_price_per_kg_cny']);

        $alias = $this->service->create_method(array(
            'id' => 'road',
            'name' => 'Road',
            'unsupported_rate' => 40,
        ));
        $this->assertSame('digitalogic_shipping_method_unknown_field', $alias->get_error_code());
    }

    public function test_assignment_writes_one_meta_key_and_batch_is_sparse(): void {
        $GLOBALS['digitalogic_test_posts'][501] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => 'CODE-501'),
        );
        $assigned = $this->service->assign_product_by_code('CODE-501', 'air_express');

        $this->assertNotInstanceOf(WP_Error::class, $assigned);
        $this->assertSame('air_express', get_post_meta(501, '_digitalogic_shipping_method_id', true));
        $this->assertSame('', get_post_meta(501, 'shipping_method', true));

        $batch = $this->service->get_product_assignments_by_codes(array('CODE-501'));
        $this->assertSame(
            array('schema', 'requested_count', 'resolved_count', 'error_count', 'maximum_codes', 'default_percentage_markup', 'results'),
            array_keys($batch)
        );
        $assignment = $batch['results'][0]['assignment'];
        $this->assertSame(
            array('profit_percent_source', 'pricing_warnings', 'shipping_method_id'),
            array_keys($assignment)
        );
        $this->assertSame('air_express', $assignment['shipping_method_id']);
        $this->assertArrayNotHasKey('profit_percent', $assignment);
        $this->assertStringNotContainsString('null', json_encode($batch));
    }

    public function test_exact_patris_code_matching_never_falls_back_to_sku(): void {
        $GLOBALS['digitalogic_test_posts'][510] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => 'SKU-ONLY-510'),
        );
        $GLOBALS['digitalogic_test_posts'][511] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => 'PATRIS-511'),
        );

        $sku_only = $this->service->assign_product_by_code('SKU-ONLY-510', 'air_express');
        $this->assertSame('digitalogic_product_code_not_found', $sku_only->get_error_code());
        $this->assertSame('', get_post_meta(510, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true));

        $matched = $this->service->assign_product_by_code('PATRIS-511', 'air_express');
        $this->assertNotInstanceOf(WP_Error::class, $matched);
        $this->assertSame(511, $matched['product_id']);
    }

    public function test_crud_immutable_ids_disable_and_delete_conflict(): void {
        $GLOBALS['digitalogic_test_posts'][520] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => 'PATRIS-520'),
        );
        $GLOBALS['digitalogic_test_posts'][521] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_digitalogic_patris_product_code' => 'PATRIS-521'),
        );

        $created = $this->service->create_method(array(
            'id' => 'rail',
            'name' => 'Rail',
            'shipping_price_per_kg_cny' => 42,
        ));
        $this->assertSame('rail', $created['id']);
        $this->assertNotInstanceOf(WP_Error::class, $this->service->assign_product_by_code('PATRIS-520', 'rail'));

        $conflict = $this->service->delete_method('rail');
        $this->assertSame('digitalogic_shipping_method_assigned', $conflict->get_error_code());
        $this->assertSame(1, $conflict->get_error_data()['assigned_products']);

        $disabled = $this->service->update_method('rail', array('enabled' => false));
        $this->assertFalse($disabled['enabled']);
        $replay = $this->service->assign_product_by_code('PATRIS-520', 'rail');
        $this->assertFalse($replay['changed']);
        $new_assignment = $this->service->assign_product_by_code('PATRIS-521', 'rail');
        $this->assertSame('digitalogic_shipping_method_disabled', $new_assignment->get_error_code());

        $immutable = $this->service->update_method('rail', array('id' => 'renamed'));
        $this->assertSame('digitalogic_shipping_method_id_immutable', $immutable->get_error_code());

        $this->assertNotInstanceOf(WP_Error::class, $this->service->create_method(array(
            'id' => 'courier',
            'name' => 'Courier',
            'shipping_price_per_kg_cny' => 90,
        )));
        $this->assertSame(array('deleted' => true, 'id' => 'courier'), $this->service->delete_method('courier'));
    }

    public function test_batch_preflight_is_atomic_and_validates_shape(): void {
        foreach (array(530, 531) as $product_id) {
            $GLOBALS['digitalogic_test_posts'][$product_id] = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'meta' => array('_digitalogic_patris_product_code' => 'PATRIS-' . $product_id),
            );
        }

        $invalid = $this->service->batch_assign_products(array(
            array('code' => 'PATRIS-530', 'shipping_method_id' => 'air_express'),
            array('code' => 'PATRIS-531', 'shipping_method_id' => 'does_not_exist'),
        ));
        $this->assertSame('digitalogic_shipping_batch_invalid', $invalid->get_error_code());
        $this->assertSame('', get_post_meta(530, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true));
        $this->assertSame('', get_post_meta(531, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true));

        $valid = $this->service->batch_assign_products(array(
            array('code' => 'PATRIS-530', 'shipping_method_id' => 'air_express'),
            array('code' => 'PATRIS-531', 'shipping_method_id' => 'sea_freight'),
        ));
        $this->assertSame(2, $valid['updated']);

        $this->assertSame(
            'digitalogic_pricing_assignment_batch_empty',
            $this->service->get_product_assignments_by_codes(array())->get_error_code()
        );
        $this->assertSame(
            'digitalogic_pricing_assignment_batch_code_duplicate',
            $this->service->get_product_assignments_by_codes(array('PATRIS-530', 'PATRIS-530'))->get_error_code()
        );
        $this->assertSame(
            'digitalogic_pricing_assignment_batch_too_large',
            $this->service->get_product_assignments_by_codes(array_fill(0, 501, 'X'))->get_error_code()
        );
    }

    public function test_lock_failure_is_retryable_and_write_free(): void {
        $before = $GLOBALS['digitalogic_test_options'];
        $GLOBALS['wpdb']->acquire_result = 0;

        $result = $this->service->create_method(array(
            'id' => 'blocked',
            'name' => 'Blocked',
            'shipping_price_per_kg_cny' => 10,
        ));

        $this->assertSame('digitalogic_shipping_catalog_busy', $result->get_error_code());
        $this->assertTrue($result->get_error_data()['retryable']);
        $this->assertSame($before, $GLOBALS['digitalogic_test_options']);
        $this->assertSame(0, $GLOBALS['wpdb']->release_count);
    }

    public function test_invalid_currency_is_omitted_and_input_ranges_are_strict(): void {
        unset($GLOBALS['digitalogic_test_options']['options_yuan_price']);
        $catalog = $this->service->get_integration_catalog();
        $this->assertArrayNotHasKey('cny_to_local', $catalog['currency']);
        $this->assertArrayNotHasKey('cny_to_irt', $catalog['currency']);
        $this->assertContains('cny_to_local_missing_or_invalid', $catalog['currency']['warnings']);
        $this->assertStringNotContainsString('null', json_encode($catalog));

        $transit = $this->service->create_method(array(
            'id' => 'bad_transit',
            'name' => 'Bad transit',
            'shipping_price_per_kg_cny' => 10,
            'transit_days_min' => 3,
            'transit_days_max' => 2,
        ));
        $this->assertSame('digitalogic_shipping_transit_invalid', $transit->get_error_code());

        $overlap = $this->service->create_method(array(
            'id' => 'bad_tiers',
            'name' => 'Bad tiers',
            'shipping_price_per_kg_cny' => 10,
            'tiered_rates' => array(
                array('min_weight_kg' => 0, 'max_weight_kg' => 5, 'shipping_price_per_kg_cny' => 10),
                array('min_weight_kg' => 4, 'max_weight_kg' => 8, 'shipping_price_per_kg_cny' => 9),
            ),
        ));
        $this->assertSame('digitalogic_shipping_tiers_overlap', $overlap->get_error_code());
    }

    public function test_default_markup_is_exact_sparse_and_idempotent(): void {
        $unset = $this->service->get_product_assignments_by_codes(array('MISSING'));
        $this->assertArrayNotHasKey('profit_percent', $unset['default_percentage_markup']);
        $this->assertStringNotContainsString('null', json_encode($unset));

        $updated = $this->service->update_default_percentage_markup('30.5000');
        $this->assertNotInstanceOf(WP_Error::class, $updated);
        $this->assertTrue($updated['changed']);
        $this->assertSame('30.5', $updated['profit_percent']);

        $same = $this->service->update_default_percentage_markup('30.500000');
        $this->assertFalse($same['changed']);
        $batch = $this->service->get_product_assignments_by_codes(array('MISSING'));
        $this->assertSame('30.5', $batch['default_percentage_markup']['profit_percent']);

        $cleared = $this->service->update_default_percentage_markup(null);
        $this->assertTrue($cleared['changed']);
        $this->assertFalse($cleared['configured']);
        $after = $this->service->get_product_assignments_by_codes(array('MISSING'));
        $this->assertArrayNotHasKey('profit_percent', $after['default_percentage_markup']);
        $this->assertStringNotContainsString('null', json_encode($after));
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
