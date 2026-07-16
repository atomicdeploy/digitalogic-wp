<?php

use PHPUnit\Framework\TestCase;

final class ImportFreightServiceTest extends TestCase {
    /** @var Digitalogic_Import_Freight_Service */
    private $service;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['digitalogic_test_options'] = array(
            'options_express' => '85',
            'options_aerial' => '80',
            'options_marine' => '50',
            'options_yuan_price' => '25300',
            'options_update_date' => '260716',
            'digitalogic_patris_feed_settings' => array(
                'selected_warehouses' => array('tehran', 'shenzhen'),
                'shipping_methods' => array('deprecated' => 999),
                'image_quality_thresholds' => array('soft_review' => 450),
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
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_save_failures'] = array();
        $GLOBALS['digitalogic_test_current_user_can_calls'] = 0;
        $GLOBALS['digitalogic_test_rest_url_calls'] = 0;
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $_POST = array();

        $this->resetSingleton(Digitalogic_Import_Freight_Service::class);
        $this->resetSingleton(Digitalogic_Patris_Feed::class);
        $this->resetSingleton(Digitalogic_Command_Dispatcher::class);
        $this->resetSingleton(Digitalogic_REST_API::class);
        $this->resetSingleton(Digitalogic_Report_Engine::class);
        $this->resetSingleton(Digitalogic_Panel::class);
        $this->resetSingleton(Digitalogic_Webhooks::class);
        $this->service = Digitalogic_Import_Freight_Service::instance();
    }

    public function test_current_acf_migration_is_idempotent_and_preserves_reference_meta() {
        $GLOBALS['digitalogic_test_posts'][101] = array(
            'post_type' => 'product',
            'meta' => array(
                '_sku' => '113007045',
                'shipping_method' => 'express',
                '_shipping_method' => 'field_custom_existing',
            ),
        );

        $first = $this->service->migrate_legacy_data();
        $methods = $this->indexMethods($this->service->list_methods());

        $this->assertTrue($first['catalog_seeded']);
        $this->assertSame(1, $first['assignments_migrated']);
        $this->assertSame(85.0, $methods['air_express']['price_per_kg_cny']);
        $this->assertSame(80.0, $methods['air_freight']['price_per_kg_cny']);
        $this->assertSame(50.0, $methods['sea_freight']['price_per_kg_cny']);
        $this->assertSame('air_express', get_post_meta(101, '_digitalogic_import_freight_method_id', true));
        $this->assertSame('field_custom_existing', get_post_meta(101, '_shipping_method', true));

        $snapshot = $GLOBALS['digitalogic_test_options'][Digitalogic_Import_Freight_Service::METHODS_OPTION];
        $second = $this->service->migrate_legacy_data();

        $this->assertFalse($second['migrated']);
        $this->assertSame(0, $second['assignments_migrated']);
        $this->assertSame($snapshot, $GLOBALS['digitalogic_test_options'][Digitalogic_Import_Freight_Service::METHODS_OPTION]);
        $this->assertSame('field_custom_existing', get_post_meta(101, '_shipping_method', true));
    }

    public function test_legacy_acf_changes_remain_bidirectionally_compatible() {
        $GLOBALS['digitalogic_test_posts'][102] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'CODE-102', 'shipping_method' => 'aerial'),
        );
        $this->service->migrate_legacy_data();

        $this->assertSame('air_freight', get_post_meta(102, '_digitalogic_import_freight_method_id', true));
        $this->assertSame('field_694534693f9ba', get_post_meta(102, '_shipping_method', true));

        update_post_meta(102, 'shipping_method', 'marine');
        $this->assertSame('sea_freight', get_post_meta(102, '_digitalogic_import_freight_method_id', true));

        $assignment = $this->service->assign_product_by_code('CODE-102', 'air_express');
        $this->assertSame('air_express', $assignment['import_freight_method_id']);
        $this->assertSame('express', get_post_meta(102, 'shipping_method', true));
        $this->assertSame('field_694534693f9ba', get_post_meta(102, '_shipping_method', true));

        $this->service->assign_product_by_code('CODE-102', null);
        $this->assertSame('', get_post_meta(102, '_digitalogic_import_freight_method_id', true));
        $this->assertSame('', get_post_meta(102, 'shipping_method', true));
        $this->assertSame('field_694534693f9ba', get_post_meta(102, '_shipping_method', true));
    }

    public function test_catalog_revision_is_stable_and_covers_formula_rate_methods_and_warehouses() {
        $this->service->migrate_legacy_data();
        $first = $this->service->get_integration_catalog();
        $again = $this->service->get_integration_catalog();

        $this->assertSame('digitalogic.integration-catalog', $first['schema']);
        $this->assertSame('landed_price_v1', $first['pricing']['formula_id']);
        $this->assertSame(
            '((weight_g * freight_cny_per_kg / 1000) + foreign_price_cny) * (1 + profit_percent / 100) * cny_to_irt',
            $first['pricing']['expression']
        );
        $this->assertSame(25300.0, $first['currency']['cny_to_irt']);
        $this->assertSame(25300.0, $first['currency']['cny_to_local']);
        $this->assertSame('IRT', $first['currency']['local']);
        $this->assertSame('2026-07-16', $first['currency']['effective_date']);
        $this->assertSame('260716', $first['currency']['source_effective_date']);
        $this->assertSame(array('shenzhen', 'tehran'), $first['selected_warehouses']);
        $this->assertSame($first['revision'], $again['revision']);

        $updated = $this->service->update_method('air_express', array('price_per_kg_cny' => 86));
        $changed = $this->service->get_integration_catalog();

        $this->assertSame(86.0, $updated['price_per_kg_cny']);
        $this->assertSame(86.0, get_option('options_express'));
        $this->assertNotSame($first['revision'], $changed['revision']);
        $this->assertArrayHasKey('minimum_charge_cny', $updated);
        $this->assertArrayHasKey('billable_weight_rule', $updated);
        $this->assertArrayHasKey('volumetric_divisor_cm3_per_kg', $updated);
        $this->assertArrayHasKey('transit_days_min', $updated);
        $this->assertArrayHasKey('metadata', $updated);
        $this->assertArrayHasKey('tiered_rates', $updated);

        update_option('options_express', 87);
        do_action('updated_option', 'options_express', 86, 87);
        $this->assertSame(87.0, $this->service->get_method('air_express')['price_per_kg_cny']);
    }

    public function test_crud_enforces_immutable_ids_delete_conflict_and_disable_semantics() {
        $GLOBALS['digitalogic_test_posts'][201] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'P-201'),
        );
        $GLOBALS['digitalogic_test_posts'][202] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'P-202'),
        );
        $this->service->migrate_legacy_data();

        $created = $this->service->create_method(array(
            'id' => 'rail_freight',
            'name' => 'Rail Freight',
            'price_per_kg_cny' => 42,
            'minimum_charge_cny' => 10,
            'billable_weight_rule' => 'greater_of',
            'volumetric_divisor_cm3_per_kg' => 6000,
            'transit_days_min' => 12,
            'transit_days_max' => 20,
            'metadata' => array('provider' => 'example'),
            'tiered_rates' => array(
                array('min_weight_kg' => 10, 'max_weight_kg' => null, 'price_per_kg_cny' => 39),
            ),
        ));
        $this->assertSame('rail_freight', $created['id']);

        $this->service->assign_product_by_code('P-201', 'rail_freight');
        $conflict = $this->service->delete_method('rail_freight');
        $this->assertTrue(is_wp_error($conflict));
        $this->assertSame(409, $conflict->get_error_data()['status']);
        $this->assertSame(1, $conflict->get_error_data()['assigned_products']);

        $disabled = $this->service->update_method('rail_freight', array('enabled' => false));
        $this->assertFalse($disabled['enabled']);
        $this->assertSame('rail_freight', $this->service->get_product_assignment_by_code('P-201')['import_freight_method_id']);
        $disabled_assignment = $this->service->assign_product_by_code('P-202', 'rail_freight');
        $this->assertSame('digitalogic_import_freight_method_disabled', $disabled_assignment->get_error_code());

        $immutable = $this->service->update_method('rail_freight', array('id' => 'renamed_freight'));
        $this->assertSame('digitalogic_import_freight_method_id_immutable', $immutable->get_error_code());

        $unassigned = $this->service->create_method(array('id' => 'courier_freight', 'name' => 'Courier', 'price_per_kg_cny' => 90));
        $this->assertSame('courier_freight', $unassigned['id']);
        $this->assertSame(array('deleted' => true, 'id' => 'courier_freight'), $this->service->delete_method('courier_freight'));

        $legacy_delete = $this->service->delete_method('air_express');
        $this->assertSame('digitalogic_import_freight_legacy_method_delete_forbidden', $legacy_delete->get_error_code());
    }

    public function test_resolution_is_exact_detects_ambiguity_and_batch_preflight_is_atomic() {
        $GLOBALS['digitalogic_test_posts'] = array(
            301 => array('post_type' => 'product', 'meta' => array('_sku' => 'EXACT-1')),
            302 => array('post_type' => 'product', 'meta' => array('_digitalogic_patris_product_code' => 'EXACT-2')),
            303 => array('post_type' => 'product', 'meta' => array('_sku' => 'DUPLICATE')),
            304 => array('post_type' => 'product_variation', 'meta' => array('_sku' => 'DUPLICATE')),
        );
        $this->service->migrate_legacy_data();

        $this->assertSame(301, $this->service->assign_product_by_code('EXACT-1', 'air_express')['product_id']);
        $this->assertSame(302, $this->service->assign_product_by_code('EXACT-2', 'sea_freight')['product_id']);
        $this->assertSame('digitalogic_product_code_not_found', $this->service->assign_product_by_code('exact-1', 'air_express')->get_error_code());
        $this->assertSame('digitalogic_product_code_ambiguous', $this->service->assign_product_by_code('DUPLICATE', 'air_express')->get_error_code());

        $before = get_post_meta(301, '_digitalogic_import_freight_method_id', true);
        $batch = $this->service->batch_assign_products(array(
            array('code' => 'EXACT-1', 'import_freight_method_id' => 'sea_freight'),
            array('code' => 'MISSING', 'import_freight_method_id' => 'air_freight'),
        ));
        $this->assertSame('digitalogic_import_freight_batch_invalid', $batch->get_error_code());
        $this->assertSame($before, get_post_meta(301, '_digitalogic_import_freight_method_id', true));
    }

    public function test_rest_and_dispatcher_share_identical_service_results_and_scoped_permissions() {
        $this->service->migrate_legacy_data();
        $GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;
        $dispatcher = Digitalogic_Command_Dispatcher::instance();
        $api = Digitalogic_REST_API::instance();

        $dispatcher_catalog = $dispatcher->get_integration_catalog(array());
        $rest_response = $api->get_integration_catalog(new WP_REST_Request());
        $this->assertSame(200, $rest_response->get_status());
        $this->assertSame($dispatcher_catalog, $rest_response->get_data()['data']);

        $create_request = new WP_REST_Request(array(), array(
            'id' => 'road_freight',
            'name' => 'Road Freight',
            'price_per_kg_cny' => 35,
        ));
        $create_response = $api->create_import_freight_method($create_request);
        $this->assertSame(201, $create_response->get_status());
        $this->assertSame(
            $dispatcher->get_import_freight_method(array('id' => 'road_freight')),
            $create_response->get_data()['data']
        );

        $rename_response = $api->update_import_freight_method(new WP_REST_Request(
            array('id' => 'road_freight'),
            array('id' => 'renamed_road', 'name' => 'Renamed')
        ));
        $this->assertSame(400, $rename_response->get_status());
        $this->assertSame('digitalogic_import_freight_method_id_immutable', $rename_response->get_data()['code']);

        $GLOBALS['digitalogic_test_capabilities'] = array();
        $this->assertFalse($api->check_read_permission(new WP_REST_Request()));
        $this->assertFalse($api->check_write_permission(new WP_REST_Request()));
        add_filter('digitalogic_rest_api_permission', function($allowed, $scope) {
            return $scope === 'read';
        }, 10, 2);
        $this->assertTrue($api->check_read_permission(new WP_REST_Request()));
        $this->assertFalse($api->check_write_permission(new WP_REST_Request()));
    }

    public function test_lock_failure_is_retryable_and_performs_no_write_or_domain_action() {
        $before = $GLOBALS['digitalogic_test_options'];
        $GLOBALS['wpdb']->acquire_result = 0;

        $result = $this->service->migrate_legacy_data();

        $this->assertSame('digitalogic_import_freight_catalog_busy', $result->get_error_code());
        $this->assertSame(503, $result->get_error_data()['status']);
        $this->assertSame($before, $GLOBALS['digitalogic_test_options']);
        $this->assertArrayNotHasKey('digitalogic_import_freight_method_created', $GLOBALS['digitalogic_test_actions']);
        $this->assertSame(0, $GLOBALS['wpdb']->release_count);
    }

    public function test_lock_is_released_when_a_mutation_callback_throws() {
        $lock = new ReflectionMethod(Digitalogic_Import_Freight_Service::class, 'with_catalog_lock');

        $result = $lock->invoke($this->service, function() {
            throw new RuntimeException('injected callback failure');
        });

        $this->assertSame('digitalogic_import_freight_unexpected_write_failure', $result->get_error_code());
        $this->assertSame(1, $GLOBALS['wpdb']->acquire_count);
        $this->assertSame(1, $GLOBALS['wpdb']->release_count);

        $retry = $this->service->migrate_legacy_data();
        $this->assertFalse(is_wp_error($retry));
        $this->assertSame(2, $GLOBALS['wpdb']->release_count);
    }

    public function test_catalog_and_legacy_rate_failures_roll_back_without_events() {
        $this->service->migrate_legacy_data();
        $before_catalog = get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION);
        $before_rate = get_option('options_express');
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Import_Freight_Service::METHODS_OPTION;

        $catalog_failure = $this->service->update_method('air_express', array('price_per_kg_cny' => 91));

        $this->assertSame('digitalogic_import_freight_catalog_write_failed', $catalog_failure->get_error_code());
        $this->assertSame($before_catalog, get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION));
        $this->assertSame($before_rate, get_option('options_express'));
        $this->assertArrayNotHasKey('digitalogic_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
        $this->assertContains(
            array(Digitalogic_Import_Freight_Service::METHODS_OPTION, 'options'),
            $GLOBALS['digitalogic_test_cache_deletes']
        );

        $GLOBALS['digitalogic_test_update_failures'] = array('options_express');
        $legacy_failure = $this->service->update_method('air_express', array('price_per_kg_cny' => 92));

        $this->assertSame('digitalogic_import_freight_legacy_rate_write_failed', $legacy_failure->get_error_code());
        $this->assertSame($before_catalog, get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION));
        $this->assertSame($before_rate, get_option('options_express'));
        $this->assertArrayNotHasKey('digitalogic_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
        $this->assertSame($GLOBALS['wpdb']->acquire_count, $GLOBALS['wpdb']->release_count);
    }

    public function test_post_rollback_compensation_never_overwrites_a_newer_option_writer() {
        $this->service->migrate_legacy_data();
        $newer_catalog = get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION);
        $newer_catalog['air_express']['price_per_kg_cny'] = 123.0;
        $GLOBALS['digitalogic_test_update_failures'][] = 'options_express';
        $GLOBALS['wpdb']->after_rollback = static function() use ($newer_catalog) {
            $GLOBALS['digitalogic_test_options'][Digitalogic_Import_Freight_Service::METHODS_OPTION] = $newer_catalog;
            $GLOBALS['digitalogic_test_options']['options_express'] = '123';
        };

        $failed = $this->service->update_method('air_express', array('price_per_kg_cny' => 92));

        $this->assertSame('digitalogic_import_freight_legacy_rate_write_failed', $failed->get_error_code());
        $this->assertSame(123.0, get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION)['air_express']['price_per_kg_cny']);
        $this->assertSame('123', get_option('options_express'));
    }

    public function test_post_rollback_compensation_never_overwrites_a_newer_meta_writer() {
        $GLOBALS['digitalogic_test_posts'][408] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'INTERLEAVE-408'),
        );
        $this->service->migrate_legacy_data();
        $GLOBALS['digitalogic_test_meta_update_failures'][] = '408:shipping_method';
        $GLOBALS['wpdb']->after_rollback = static function() {
            $GLOBALS['digitalogic_test_posts'][408]['meta'][Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META] = 'sea_freight';
            $GLOBALS['digitalogic_test_posts'][408]['meta']['shipping_method'] = 'marine';
        };

        $failed = $this->service->assign_product_by_code('INTERLEAVE-408', 'air_express');

        $this->assertSame('digitalogic_import_freight_meta_write_failed', $failed->get_error_code());
        $this->assertSame('sea_freight', get_post_meta(408, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));
        $this->assertSame('marine', get_post_meta(408, 'shipping_method', true));
    }

    public function test_failed_rollback_query_is_reported_without_attempting_snapshot_rewrites() {
        $this->service->migrate_legacy_data();
        $GLOBALS['digitalogic_test_update_failures'][] = 'options_express';
        $GLOBALS['digitalogic_test_transaction_failures'][] = 'ROLLBACK';

        $failed = $this->service->update_method('air_express', array('price_per_kg_cny' => 92));

        $this->assertSame('digitalogic_import_freight_rollback_failed', $failed->get_error_code());
        $this->assertContains(
            array(Digitalogic_Import_Freight_Service::METHODS_OPTION, 'options'),
            $GLOBALS['digitalogic_test_cache_deletes']
        );
    }

    public function test_direct_legacy_rate_write_is_restored_when_catalog_mirroring_fails() {
        $this->service->migrate_legacy_data();
        $before_catalog = get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION);
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Import_Freight_Service::METHODS_OPTION;

        update_option('options_express', 99, false);

        $this->assertSame('85', get_option('options_express'));
        $this->assertSame($before_catalog, get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION));
        $this->assertArrayNotHasKey('digitalogic_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
        $this->assertSame($GLOBALS['wpdb']->acquire_count, $GLOBALS['wpdb']->release_count);
    }

    public function test_single_and_batch_meta_failures_restore_every_key_and_emit_no_domain_event() {
        $GLOBALS['digitalogic_test_posts'] = array(
            401 => array('post_type' => 'product', 'meta' => array('_sku' => 'ROLLBACK-1')),
            402 => array('post_type' => 'product', 'meta' => array('_sku' => 'ROLLBACK-2')),
        );
        $this->service->migrate_legacy_data();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_meta_update_failures'][] = '401:shipping_method';

        $single = $this->service->assign_product_by_code('ROLLBACK-1', 'air_express');

        $this->assertSame('digitalogic_import_freight_meta_write_failed', $single->get_error_code());
        $this->assertAssignmentMetaAbsent(401);
        $this->assertArrayNotHasKey('digitalogic_product_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
        $this->assertContains(array(401, 'post_meta'), $GLOBALS['digitalogic_test_cache_deletes']);

        $GLOBALS['digitalogic_test_meta_update_failures'] = array('402:shipping_method');
        $batch = $this->service->batch_assign_products(array(
            array('code' => 'ROLLBACK-1', 'method_id' => 'air_freight'),
            array('code' => 'ROLLBACK-2', 'method_id' => 'sea_freight'),
        ));

        $this->assertSame('digitalogic_import_freight_meta_write_failed', $batch->get_error_code());
        $this->assertAssignmentMetaAbsent(401);
        $this->assertAssignmentMetaAbsent(402);
        $this->assertArrayNotHasKey('digitalogic_product_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
    }

    public function test_assignment_retries_are_idempotent_and_do_not_emit_duplicate_events() {
        $GLOBALS['digitalogic_test_posts'][403] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'NOOP-403'),
        );
        $this->service->migrate_legacy_data();
        $first = $this->service->assign_product_by_code('NOOP-403', 'air_express');
        $this->assertTrue($first['changed']);
        $GLOBALS['digitalogic_test_actions'] = array();

        $retry = $this->service->assign_product_by_code('NOOP-403', 'air_express');
        $batch_retry = $this->service->batch_assign_products(array(
            array('code' => 'NOOP-403', 'method_id' => 'air_express'),
        ));

        $this->assertFalse($retry['changed']);
        $this->assertSame(0, $batch_retry['updated']);
        $this->assertSame(1, $batch_retry['unchanged']);
        $this->assertFalse($batch_retry['assignments'][0]['changed']);
        $this->assertArrayNotHasKey('digitalogic_product_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
    }

    public function test_failed_migration_is_unstamped_and_a_retry_completes() {
        $GLOBALS['digitalogic_test_posts'][404] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'MIGRATE-404', 'shipping_method' => 'marine'),
        );
        $GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Import_Freight_Service::MIGRATION_OPTION;

        $failed = $this->service->migrate_legacy_data();

        $this->assertSame('digitalogic_import_freight_migration_write_failed', $failed->get_error_code());
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::MIGRATION_OPTION, false));
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION, false));
        $this->assertSame('', get_post_meta(404, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));

        $GLOBALS['digitalogic_test_update_failures'] = array();
        $retry = $this->service->migrate_legacy_data();

        $this->assertSame(1, $retry['assignments_migrated']);
        $this->assertSame(1, get_option(Digitalogic_Import_Freight_Service::MIGRATION_OPTION));
        $this->assertSame('sea_freight', get_post_meta(404, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));
    }

    public function test_mysql_string_roundtrip_accepts_scalar_migration_marker_and_legacy_rate() {
        $GLOBALS['wpdb']->mysql_string_roundtrip = true;

        $migration = $this->service->migrate_legacy_data();

        $this->assertFalse(is_wp_error($migration));
        $this->assertSame('1', get_option(Digitalogic_Import_Freight_Service::MIGRATION_OPTION));
        $this->assertSame('85', get_option('options_express'));

        $updated = $this->service->update_method('air_express', array('price_per_kg_cny' => 92.5));

        $this->assertFalse(is_wp_error($updated));
        $this->assertSame(92.5, $updated['price_per_kg_cny']);
        $this->assertSame('92.5', get_option('options_express'));
    }

    public function test_migration_repairs_a_partial_catalog_even_when_the_old_marker_exists() {
        $GLOBALS['digitalogic_test_options'][Digitalogic_Import_Freight_Service::METHODS_OPTION] = array(
            'rail_freight' => array('id' => 'rail_freight', 'name' => 'Existing custom method'),
        );
        $GLOBALS['digitalogic_test_options'][Digitalogic_Import_Freight_Service::MIGRATION_OPTION] = 1;

        $result = $this->service->maybe_migrate();
        $stored = get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION);

        $this->assertTrue($result['catalog_seeded']);
        $this->assertArrayHasKey('rail_freight', $stored);
        $this->assertArrayHasKey('air_express', $stored);
        $this->assertArrayHasKey('air_freight', $stored);
        $this->assertArrayHasKey('sea_freight', $stored);
        $this->assertSame(1, get_option(Digitalogic_Import_Freight_Service::MIGRATION_OPTION));
    }

    public function test_acf_choices_support_custom_ids_and_reject_new_disabled_or_unknown_values() {
        $GLOBALS['digitalogic_test_posts'][405] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'ACF-405'),
        );
        $this->service->migrate_legacy_data();
        $this->service->create_method(array('id' => 'rail_freight', 'name' => 'Rail Freight', 'price_per_kg_cny' => 42));
        $this->service->update_method('rail_freight', array('enabled' => false));

        $field = $this->service->filter_acf_method_field(array('choices' => array('stale' => 'Stale')));
        $this->assertArrayHasKey('express', $field['choices']);
        $this->assertArrayHasKey('aerial', $field['choices']);
        $this->assertArrayHasKey('marine', $field['choices']);
        $this->assertArrayHasKey('rail_freight', $field['choices']);
        $this->assertStringContainsString('disabled', $field['choices']['rail_freight']);
        $this->assertTrue($this->service->validate_acf_method_value(true, 'express', array(), 'acf[field]'));
        $this->assertIsString($this->service->validate_acf_method_value(true, 'unknown_method', array(), 'acf[field]'));
        $this->assertIsString($this->service->validate_acf_method_value(true, 'rail_freight', array(), 'acf[field]'));

        update_post_meta(405, 'shipping_method', 'rail_freight');
        $this->assertSame('', get_post_meta(405, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));
        $this->assertSame('', get_post_meta(405, 'shipping_method', true));

        $this->service->update_method('rail_freight', array('enabled' => true));
        $this->service->assign_product_by_code('ACF-405', 'rail_freight');
        $this->service->update_method('rail_freight', array('enabled' => false));
        $_POST['post_ID'] = 405;
        $this->assertTrue($this->service->validate_acf_method_value(true, 'rail_freight', array(), 'acf[field]'));
    }

    public function test_assignment_markup_contract_is_explicit_for_percentage_fixed_and_missing_values() {
        $GLOBALS['digitalogic_test_posts'][406] = array(
            'post_type' => 'product',
            'meta' => array(
                '_sku' => 'MARKUP-406',
                '_digitalogic_markup_type' => 'percentage',
                '_digitalogic_markup' => '12.5',
            ),
        );
        $this->service->migrate_legacy_data();

        $percentage = $this->service->get_product_assignment_by_code('MARKUP-406');
        $this->assertSame('percentage', $percentage['markup']['type']);
        $this->assertSame(12.5, $percentage['markup']['value']);
        $this->assertSame(12.5, $percentage['markup']['profit_percent']);
        $this->assertSame(12.5, $percentage['profit_percent']);
        $this->assertSame(array(), $percentage['pricing_warnings']);

        update_post_meta(406, '_digitalogic_markup_type', 'fixed');
        update_post_meta(406, '_digitalogic_markup', '250000');
        $fixed = $this->service->get_product_assignment_by_code('MARKUP-406');
        $this->assertSame('fixed', $fixed['markup']['type']);
        $this->assertSame(250000.0, $fixed['markup']['value']);
        $this->assertNull($fixed['markup']['profit_percent']);
        $this->assertContains('fixed_markup_not_supported_by_landed_price_v1', $fixed['pricing_warnings']);

        delete_post_meta(406, '_digitalogic_markup_type');
        delete_post_meta(406, '_digitalogic_markup');
        $missing = $this->service->get_product_assignment_by_code('MARKUP-406');
        $this->assertNull($missing['markup']['type']);
        $this->assertNull($missing['markup']['value']);
        $this->assertContains('markup_missing', $missing['pricing_warnings']);
    }

    public function test_report_uses_the_canonical_catalog_and_tier_validation_rejects_bad_ranges() {
        $this->service->migrate_legacy_data();
        $report = Digitalogic_Report_Engine::instance()->get_report();
        $method_ids = array_column($report['settings']['import_freight_methods'], 'id');

        $this->assertSame($report['settings']['import_freight_methods'], $report['settings']['shipping_methods']);
        $this->assertContains('air_express', $method_ids);
        $this->assertNotContains('deprecated', $method_ids);
        $this->assertNotNull($report['settings']['integration_catalog_revision']);

        $invalid = $this->service->create_method(array(
            'id' => 'bad_tiers',
            'name' => 'Bad tiers',
            'price_per_kg_cny' => 10,
            'tiered_rates' => array(
                array('min_weight_kg' => 0, 'max_weight_kg' => 10, 'price_per_kg_cny' => 9),
                array('min_weight_kg' => 10, 'max_weight_kg' => 20, 'price_per_kg_cny' => 8),
            ),
        ));
        $this->assertSame('digitalogic_import_freight_tiers_overlap', $invalid->get_error_code());
    }

    public function test_assignment_commands_require_an_explicit_method_field() {
        $GLOBALS['digitalogic_test_posts'][501] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'REQUIRED-501'),
        );
        $this->service->migrate_legacy_data();
        $dispatcher = Digitalogic_Command_Dispatcher::instance();
        $api = Digitalogic_REST_API::instance();

        $command = $dispatcher->assign_product_import_freight(array('code' => 'REQUIRED-501'));
        $rest = $api->assign_product_import_freight(new WP_REST_Request(
            array('code' => 'REQUIRED-501'),
            array()
        ));
        $batch = $this->service->batch_assign_products(array(
            array('code' => 'REQUIRED-501'),
        ));

        $this->assertSame('digitalogic_import_freight_method_required', $command->get_error_code());
        $this->assertSame(400, $rest->get_status());
        $this->assertSame('digitalogic_import_freight_method_required', $rest->get_data()['code']);
        $this->assertSame('digitalogic_import_freight_batch_invalid', $batch->get_error_code());
        $this->assertSame(
            'digitalogic_import_freight_method_required',
            $batch->get_error_data()['errors'][0]['code']
        );
        $this->assertAssignmentMetaAbsent(501);

        $clear = $dispatcher->assign_product_import_freight(array(
            'code' => 'REQUIRED-501',
            'import_freight_method_id' => null,
        ));
        $this->assertFalse(is_wp_error($clear));
        $this->assertFalse($clear['changed']);
    }

    public function test_custom_method_ids_cannot_collide_with_legacy_acf_values() {
        $this->service->migrate_legacy_data();

        foreach (array('express', 'aerial', 'marine') as $reserved_id) {
            $result = $this->service->create_method(array(
                'id' => $reserved_id,
                'name' => 'Collision',
                'price_per_kg_cny' => 10,
            ));
            $this->assertSame('digitalogic_import_freight_method_id_reserved', $result->get_error_code());
        }

        $methods = $this->indexMethods($this->service->list_methods());
        $this->assertArrayNotHasKey('express', $methods);
        $this->assertArrayNotHasKey('aerial', $methods);
        $this->assertArrayNotHasKey('marine', $methods);
    }

    public function test_batch_rejects_two_codes_that_resolve_to_the_same_product() {
        $GLOBALS['digitalogic_test_posts'][502] = array(
            'post_type' => 'product',
            'meta' => array(
                '_sku' => 'SKU-502',
                '_digitalogic_patris_product_code' => 'PATRIS-502',
            ),
        );
        $this->service->migrate_legacy_data();

        $result = $this->service->batch_assign_products(array(
            array('code' => 'SKU-502', 'method_id' => 'air_express'),
            array('code' => 'PATRIS-502', 'method_id' => 'sea_freight'),
        ));

        $this->assertSame('digitalogic_import_freight_batch_invalid', $result->get_error_code());
        $errors = $result->get_error_data()['errors'];
        $this->assertSame('digitalogic_duplicate_product_target', $errors[1]['code']);
        $this->assertSame(502, $errors[1]['product_id']);
        $this->assertSame(0, $errors[1]['first_index']);
        $this->assertAssignmentMetaAbsent(502);
    }

    public function test_existing_disabled_assignment_can_be_replayed_as_an_idempotent_noop() {
        $GLOBALS['digitalogic_test_posts'][503] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'DISABLED-503'),
        );
        $this->service->migrate_legacy_data();
        $this->service->assign_product_by_code('DISABLED-503', 'air_express');
        $this->service->update_method('air_express', array('enabled' => false));
        $GLOBALS['digitalogic_test_actions'] = array();

        $result = $this->service->assign_product_by_code('DISABLED-503', 'air_express');

        $this->assertFalse(is_wp_error($result));
        $this->assertFalse($result['changed']);
        $this->assertSame('air_express', $result['import_freight_method_id']);
        $this->assertArrayNotHasKey('digitalogic_product_import_freight_method_updated', $GLOBALS['digitalogic_test_actions']);
    }

    public function test_committed_mutation_uses_shared_panel_queue_and_redis_once() {
        $GLOBALS['digitalogic_test_posts'][504] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'EVENT-504'),
        );
        $this->service->migrate_legacy_data();
        $redis = new Digitalogic_Test_Redis_Client();
        add_filter('digitalogic_panel_redis_client', static function() use ($redis) {
            return $redis;
        });
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls'] = array('https://n8n.test/webhook/freight');
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_secret'] = 'test-secret';
        Digitalogic_Panel::instance();
        Digitalogic_Webhooks::instance();

        $result = $this->service->assign_product_by_code('EVENT-504', 'air_express');

        $this->assertTrue($result['changed']);
        $events = get_option('digitalogic_panel_events', array());
        $this->assertCount(1, $events);
        $this->assertSame('import_freight.assignment.updated', $events[0]['name']);
        $this->assertSame(504, $events[0]['data']['product_id']);
        $this->assertSame('air_express', $events[0]['data']['import_freight_method_id']);
        $this->assertCount(1, $redis->published);
        $this->assertSame($events[0], json_decode($redis->published[0][1], true));
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $webhook_payload = json_decode($GLOBALS['digitalogic_test_remote_posts'][0]['args']['body'], true);
        $this->assertSame('import_freight.assignment.updated', $webhook_payload['event']);
        $this->assertSame(504, $webhook_payload['data']['product_id']);

        $retry = $this->service->assign_product_by_code('EVENT-504', 'air_express');
        $this->assertFalse($retry['changed']);
        $this->assertCount(1, get_option('digitalogic_panel_events', array()));
        $this->assertCount(1, $redis->published);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);

        $webhook_source = file_get_contents(dirname(__DIR__) . '/includes/api/class-webhooks.php');
        $panel_source = file_get_contents(dirname(__DIR__) . '/includes/panel/class-panel.php');
        $this->assertStringContainsString('digitalogic_product_import_freight_method_updated', $webhook_source);
        $this->assertStringContainsString('import_freight.assignment.updated', $webhook_source);
        $this->assertStringNotContainsString("add_action('digitalogic_import_freight", $webhook_source);
        $this->assertStringNotContainsString("add_action('digitalogic_product_import_freight", $webhook_source);
        $this->assertStringNotContainsString("add_action('digitalogic_import_freight", $panel_source);
        $this->assertStringNotContainsString("add_action('digitalogic_product_import_freight", $panel_source);
    }

    public function test_delivery_channels_attempt_all_transports_and_aggregate_redis_webhook_void_and_exception_failures() {
        $GLOBALS['digitalogic_test_posts'][509] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'DELIVERY-509'),
        );
        $this->service->migrate_legacy_data();

        $redis = new Digitalogic_Test_Redis_Client();
        $redis->publish_result = false;
        add_filter('digitalogic_panel_redis_client', static function() use ($redis) {
            return $redis;
        });
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls'] = array(
            'https://n8n.test/webhook/one',
            'https://n8n.test/webhook/two',
        );
        $GLOBALS['digitalogic_test_remote_post_results'] = array(
            new WP_Error('transport_down', 'first destination unavailable'),
            array('response' => array('code' => 503)),
        );
        Digitalogic_Panel::instance();
        Digitalogic_Webhooks::instance();

        $successful_channel_calls = 0;
        $this->service->register_delivery_channel('void_channel', static function() {
            // A legacy void callback must be observable as unconfirmed.
        });
        $this->service->register_delivery_channel('throwing_channel', static function() {
            throw new RuntimeException('injected delivery exception');
        });
        $this->service->register_delivery_channel('successful_channel', static function() use (&$successful_channel_calls) {
            $successful_channel_calls++;
            return true;
        });
        add_action('digitalogic_product_import_freight_method_updated', static function() {
            throw new RuntimeException('legacy action listener failed');
        }, 1, 2);

        $result = $this->service->assign_product_by_code('DELIVERY-509', 'air_freight');

        $this->assertTrue($result['changed']);
        $this->assertCount(1, get_option('digitalogic_panel_events', array()));
        $this->assertCount(1, $redis->published);
        $this->assertCount(2, $GLOBALS['digitalogic_test_remote_posts']);
        $this->assertSame(1, $successful_channel_calls);
        $this->assertContains('event_delivery_failed:panel:digitalogic_panel_delivery_failed', $result['delivery_warnings']);
        $this->assertContains('event_delivery_failed:webhook:digitalogic_webhook_delivery_failed', $result['delivery_warnings']);
        $this->assertContains('event_delivery_unconfirmed:void_channel', $result['delivery_warnings']);
        $this->assertContains('event_delivery_failed:throwing_channel:exception', $result['delivery_warnings']);
        $this->assertContains('event_delivery_failed:digitalogic_product_import_freight_method_updated', $result['delivery_warnings']);

        $retry = $this->service->assign_product_by_code('DELIVERY-509', 'air_freight');
        $this->assertFalse($retry['changed']);
        $this->assertArrayNotHasKey('delivery_warnings', $retry);
        $this->assertCount(1, get_option('digitalogic_panel_events', array()));
        $this->assertCount(1, $redis->published);
        $this->assertCount(2, $GLOBALS['digitalogic_test_remote_posts']);
        $this->assertSame(1, $successful_channel_calls);
    }

    public function test_panel_queue_failure_is_reported_while_webhook_and_later_channels_still_run() {
        $GLOBALS['digitalogic_test_posts'][510] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'QUEUE-510'),
        );
        $this->service->migrate_legacy_data();
        $redis = new Digitalogic_Test_Redis_Client();
        add_filter('digitalogic_panel_redis_client', static function() use ($redis) {
            return $redis;
        });
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls'] = array('https://n8n.test/webhook/queue');
        $GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';
        Digitalogic_Panel::instance();
        Digitalogic_Webhooks::instance();

        $result = $this->service->assign_product_by_code('QUEUE-510', 'sea_freight');

        $this->assertTrue($result['changed']);
        $this->assertContains('event_delivery_failed:panel:digitalogic_panel_queue_write_failed', $result['delivery_warnings']);
        $this->assertCount(0, $redis->published);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
    }

    public function test_delivery_channel_names_cannot_be_replaced_without_explicit_unregister() {
        $GLOBALS['digitalogic_test_posts'][511] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'CHANNEL-511'),
        );
        $this->service->migrate_legacy_data();
        $first_calls = 0;
        $replacement_calls = 0;
        $first = static function() use (&$first_calls) {
            $first_calls++;
            return true;
        };
        $replacement = static function() use (&$replacement_calls) {
            $replacement_calls++;
            return true;
        };

        $this->assertTrue($this->service->register_delivery_channel('stable_name', $first));
        $this->assertFalse($this->service->register_delivery_channel('stable_name', $replacement));
        $this->service->assign_product_by_code('CHANNEL-511', 'air_express');
        $this->assertSame(1, $first_calls);
        $this->assertSame(0, $replacement_calls);

        $this->assertTrue($this->service->unregister_delivery_channel('stable_name'));
        $this->assertTrue($this->service->register_delivery_channel('stable_name', $replacement));
        $this->service->assign_product_by_code('CHANNEL-511', 'sea_freight');
        $this->assertSame(1, $first_calls);
        $this->assertSame(1, $replacement_calls);
    }

    public function test_empty_webhook_configuration_is_a_confirmed_disabled_channel() {
        $GLOBALS['digitalogic_test_posts'][513] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'NO-WEBHOOK-513'),
        );
        $this->service->migrate_legacy_data();
        Digitalogic_Webhooks::instance();

        $result = $this->service->assign_product_by_code('NO-WEBHOOK-513', 'air_freight');

        $this->assertTrue($result['changed']);
        $this->assertArrayNotHasKey('delivery_warnings', $result);
        $this->assertCount(0, $GLOBALS['digitalogic_test_remote_posts']);
    }

    public function test_panel_and_webhook_singletons_reregister_after_service_reset_without_duplicate_delivery() {
        $GLOBALS['digitalogic_test_posts'][512] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'RESET-512'),
        );
        $this->service->migrate_legacy_data();
        $redis = new Digitalogic_Test_Redis_Client();
        add_filter('digitalogic_panel_redis_client', static function() use ($redis) {
            return $redis;
        });
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls'] = array('https://n8n.test/webhook/reset');
        Digitalogic_Panel::instance();
        Digitalogic_Webhooks::instance();

        $this->resetSingleton(Digitalogic_Import_Freight_Service::class);
        $this->service = Digitalogic_Import_Freight_Service::instance();
        Digitalogic_Panel::instance();
        Digitalogic_Webhooks::instance();

        $result = $this->service->assign_product_by_code('RESET-512', 'air_express');

        $this->assertTrue($result['changed']);
        $this->assertArrayNotHasKey('delivery_warnings', $result);
        $this->assertCount(1, get_option('digitalogic_panel_events', array()));
        $this->assertCount(1, $redis->published);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
    }

    public function test_throwing_post_commit_listener_returns_success_with_delivery_warning() {
        $GLOBALS['digitalogic_test_posts'][505] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'THROW-505'),
        );
        $this->service->migrate_legacy_data();
        add_action('digitalogic_product_import_freight_method_updated', static function() {
            throw new RuntimeException('injected listener failure');
        }, 1, 2);

        $result = $this->service->assign_product_by_code('THROW-505', 'sea_freight');

        $this->assertFalse(is_wp_error($result));
        $this->assertTrue($result['changed']);
        $this->assertSame('sea_freight', get_post_meta(505, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));
        $this->assertContains(
            'event_delivery_failed:digitalogic_product_import_freight_method_updated',
            $result['delivery_warnings']
        );
        $this->assertSame($GLOBALS['wpdb']->acquire_count, $GLOBALS['wpdb']->release_count);

        $retry = $this->service->assign_product_by_code('THROW-505', 'sea_freight');
        $this->assertFalse($retry['changed']);
        $this->assertArrayNotHasKey('delivery_warnings', $retry);
    }

    public function test_storage_bypasses_stale_shared_cache_and_invalidates_after_commit_before_hooks() {
        $GLOBALS['digitalogic_test_posts'][506] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'CACHE-506'),
        );
        $this->service->migrate_legacy_data();
        $stale_catalog = get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION);
        $stale_catalog['air_express']['price_per_kg_cny'] = 1.0;
        $GLOBALS['digitalogic_test_option_cache'][Digitalogic_Import_Freight_Service::METHODS_OPTION] = $stale_catalog;

        $updated = $this->service->update_method('air_express', array('price_per_kg_cny' => 88));
        $this->assertSame(88.0, $updated['price_per_kg_cny']);
        $this->assertSame(88.0, get_option(Digitalogic_Import_Freight_Service::METHODS_OPTION)['air_express']['price_per_kg_cny']);

        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['digitalogic_test_post_meta_cache'][506] = array(
            '_sku' => 'CACHE-506',
            Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META => 'stale_method',
        );
        $hook_observations = array();
        add_action('added_post_meta', static function() use (&$hook_observations) {
            $queries = $GLOBALS['wpdb']->queries;
            $hook_observations[] = array(
                'last_query' => end($queries),
                'cache_invalidated' => in_array(array(506, 'post_meta'), $GLOBALS['digitalogic_test_cache_deletes'], true),
            );
        }, 30, 4);

        $assignment = $this->service->assign_product_by_code('CACHE-506', 'air_freight');

        $this->assertTrue($assignment['changed']);
        $this->assertSame('air_freight', get_post_meta(506, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));
        $this->assertNotEmpty($hook_observations);
        foreach ($hook_observations as $observation) {
            $this->assertSame('COMMIT', $observation['last_query']);
            $this->assertTrue($observation['cache_invalidated']);
        }
    }

    public function test_stale_legacy_hooks_never_overwrite_a_newer_value() {
        $GLOBALS['digitalogic_test_posts'][507] = array(
            'post_type' => 'product',
            'meta' => array('_sku' => 'CAS-507'),
        );
        $this->service->migrate_legacy_data();
        $this->service->assign_product_by_code('CAS-507', 'air_express');
        update_post_meta(507, 'shipping_method', 'marine');
        $this->assertSame('sea_freight', get_post_meta(507, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));

        $this->service->sync_legacy_assignment(1, 507, 'shipping_method', 'express');
        $this->assertSame('marine', get_post_meta(507, 'shipping_method', true));
        $this->assertSame('sea_freight', get_post_meta(507, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true));

        update_option('options_express', 99, false);
        $this->assertSame(99.0, $this->service->get_method('air_express')['price_per_kg_cny']);
        $this->service->sync_updated_legacy_rate('options_express', 85, 90);
        $this->assertSame(99, get_option('options_express'));
        $this->assertSame(99.0, $this->service->get_method('air_express')['price_per_kg_cny']);
    }

    public function test_invalid_currency_rate_is_null_with_warning_and_transit_days_are_strict() {
        $this->service->migrate_legacy_data();
        foreach (array(null, 'not-a-rate', 0, -1) as $invalid_rate) {
            if (is_null($invalid_rate)) {
                unset($GLOBALS['digitalogic_test_options']['options_yuan_price']);
            } else {
                $GLOBALS['digitalogic_test_options']['options_yuan_price'] = $invalid_rate;
            }
            $GLOBALS['digitalogic_test_option_cache'] = array();
            $catalog = $this->service->get_integration_catalog();
            $this->assertNull($catalog['currency']['cny_to_local']);
            $this->assertNull($catalog['currency']['cny_to_irt']);
            $this->assertContains('cny_to_local_missing_or_invalid', $catalog['currency']['warnings']);
        }

        foreach (array(-1, '1.5', 2.5, 'two') as $index => $invalid_transit) {
            $method = $this->service->create_method(array(
                'id' => 'invalid_transit_' . $index,
                'name' => 'Invalid transit',
                'price_per_kg_cny' => 10,
                'transit_days_min' => $invalid_transit,
            ));
            $this->assertSame('digitalogic_import_freight_transit_invalid', $method->get_error_code());
        }
    }

    public function test_default_markup_lifecycle_is_exact_idempotent_and_catalog_versioned_without_price_writes() {
        $GLOBALS['digitalogic_test_posts'][601] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => 'NO-PRICE-WRITE-601', '_price' => '777'),
        );
        $before_posts = $GLOBALS['digitalogic_test_posts'];

        $unset = $this->service->get_default_percentage_markup();
        $catalog_before = $this->service->get_integration_catalog();
        $this->assertFalse($unset['configured']);
        $this->assertNull($unset['profit_percent']);
        $this->assertSame('unset', $unset['source']);
        $this->assertFalse($unset['storage_present']);
        $this->assertContains('default_markup_unset', $unset['warnings']);
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, false));

        $updated = $this->service->update_default_percentage_markup('۰۰۳۰٫۵۰۰۰');
        $this->assertFalse(is_wp_error($updated));
        $this->assertTrue($updated['changed']);
        $this->assertTrue($updated['configured']);
        $this->assertSame('30.5', $updated['profit_percent']);
        $this->assertSame('global_default', $updated['source']);
        $stored = get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION);
        $this->assertSame('30.5', $stored['profit_percent']);
        $this->assertSame($updated['revision'], $stored['revision']);

        $catalog_changed = $this->service->get_integration_catalog();
        $this->assertSame($updated, array_merge($catalog_changed['pricing']['default_percentage_markup'], array(
            'changed' => true,
            'previous_revision' => $updated['previous_revision'],
        )));
        $this->assertNotSame($catalog_before['revision'], $catalog_changed['revision']);
        $this->assertSame(array('product_percentage', 'global_default'), $catalog_changed['pricing']['product_markup_contract']['resolution_order']);

        $event_count = count($GLOBALS['digitalogic_test_actions']['digitalogic_import_freight_default_markup_updated'] ?? array());
        $same = $this->service->update_default_percentage_markup('30.500000');
        $this->assertFalse($same['changed']);
        $this->assertSame($event_count, count($GLOBALS['digitalogic_test_actions']['digitalogic_import_freight_default_markup_updated'] ?? array()));

        $cleared = $this->service->update_default_percentage_markup(null);
        $this->assertTrue($cleared['changed']);
        $this->assertFalse($cleared['configured']);
        $this->assertNull($cleared['profit_percent']);
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, false));
        $catalog_cleared = $this->service->get_integration_catalog();
        $this->assertSame($catalog_before['revision'], $catalog_cleared['revision']);
        $this->assertSame($before_posts, $GLOBALS['digitalogic_test_posts']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_default_markup_validation_bounds_and_mysql_string_readback_are_exact() {
        $this->service->get_default_percentage_markup();
        $invalid = array(
            '' => 'digitalogic_import_freight_default_markup_invalid',
            '   ' => 'digitalogic_import_freight_default_markup_invalid',
            '1e2' => 'digitalogic_import_freight_default_markup_invalid',
            '-0.1' => 'digitalogic_import_freight_default_markup_out_of_range',
            '1000.0000001' => 'digitalogic_import_freight_default_markup_out_of_range',
            '0.1234567890123' => 'digitalogic_import_freight_default_markup_scale_invalid',
        );
        foreach ($invalid as $value => $code) {
            $result = $this->service->update_default_percentage_markup($value);
            $this->assertSame($code, $result->get_error_code(), $value);
            $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, false));
        }
        foreach (array(true, false, array('30'), NAN, INF) as $value) {
            $result = $this->service->update_default_percentage_markup($value);
            $this->assertSame('digitalogic_import_freight_default_markup_invalid', $result->get_error_code());
        }
        $float_scale = $this->service->update_default_percentage_markup(0.1234567890123);
        $this->assertSame('digitalogic_import_freight_default_markup_scale_invalid', $float_scale->get_error_code());

        $this->assertSame('0', $this->service->update_default_percentage_markup('0')['profit_percent']);
        $this->assertSame('1000', $this->service->update_default_percentage_markup(1000)['profit_percent']);

        $GLOBALS['wpdb']->mysql_string_roundtrip = true;
        $exact = $this->service->update_default_percentage_markup('30.123456789012');
        $this->assertSame('30.123456789012', $exact['profit_percent']);
        $GLOBALS['digitalogic_test_option_cache'][Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION] = array(
            'profit_percent' => '999',
        );
        $read_back = $this->service->get_default_percentage_markup();
        $this->assertSame('30.123456789012', $read_back['profit_percent']);
        $this->assertContains(
            array(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, 'options'),
            $GLOBALS['digitalogic_test_cache_deletes']
        );

        $non_string_state = get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION);
        $non_string_state['profit_percent'] = 30;
        $GLOBALS['digitalogic_test_options'][Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION] = $non_string_state;
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $invalid_storage = $this->service->get_default_percentage_markup();
        $this->assertFalse($invalid_storage['configured']);
        $this->assertSame('invalid_storage', $invalid_storage['source']);
        $this->assertContains('default_markup_storage_invalid', $invalid_storage['warnings']);
        $repaired = $this->service->update_default_percentage_markup('30');
        $this->assertSame('30', $repaired['profit_percent']);
        $this->assertIsString(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION)['profit_percent']);
    }

    public function test_product_percentage_override_precedes_global_and_non_percentage_states_never_fallback() {
        $GLOBALS['digitalogic_test_posts'][602] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => 'MARKUP-602'),
        );
        $this->service->migrate_legacy_data();

        $unset = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($unset['profit_percent']);
        $this->assertSame('unset', $unset['profit_percent_source']);
        $this->assertSame(array('markup_missing'), $unset['pricing_warnings']);

        $default = $this->service->update_default_percentage_markup('30');
        $global = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertSame('30', $global['profit_percent']);
        $this->assertSame('global_default', $global['profit_percent_source']);
        $this->assertSame($default['revision'], $global['markup']['default_revision']);
        $this->assertSame(array(), $global['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup_type', '');
        update_post_meta(602, '_digitalogic_markup', '');
        $paired_empty = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertSame('30', $paired_empty['profit_percent']);
        $this->assertSame('global_default', $paired_empty['profit_percent_source']);
        $this->assertSame($default['revision'], $paired_empty['markup']['default_revision']);
        $this->assertSame(array(), $paired_empty['pricing_warnings']);

        delete_post_meta(602, '_digitalogic_markup');
        $type_only_empty = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($type_only_empty['profit_percent']);
        $this->assertNull($type_only_empty['profit_percent_source']);
        $this->assertSame(array('markup_metadata_value_absent'), $type_only_empty['pricing_warnings']);

        delete_post_meta(602, '_digitalogic_markup_type');
        update_post_meta(602, '_digitalogic_markup', '');
        $value_only_empty = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($value_only_empty['profit_percent']);
        $this->assertNull($value_only_empty['profit_percent_source']);
        $this->assertSame(array('markup_metadata_type_absent'), $value_only_empty['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup_type', '***');
        $malformed_type = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($malformed_type['profit_percent']);
        $this->assertNull($malformed_type['profit_percent_source']);
        $this->assertSame(array('markup_type_malformed'), $malformed_type['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup_type', 'percentage');
        update_post_meta(602, '_digitalogic_markup', '12.5');
        $override = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertSame(12.5, $override['profit_percent']);
        $this->assertSame('product_override', $override['profit_percent_source']);
        $this->assertNull($override['markup']['default_revision']);

        update_post_meta(602, '_digitalogic_markup_type', 'fixed');
        $fixed = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($fixed['profit_percent']);
        $this->assertNull($fixed['profit_percent_source']);
        $this->assertSame(array('fixed_markup_not_supported_by_landed_price_v1'), $fixed['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup_type', 'tiered');
        $unsupported = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($unsupported['profit_percent']);
        $this->assertSame(array('markup_type_unsupported'), $unsupported['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup_type', 'percentage');
        update_post_meta(602, '_digitalogic_markup', '');
        $missing_value = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($missing_value['profit_percent']);
        $this->assertSame(array('percentage_markup_value_missing'), $missing_value['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup', 'not-a-number');
        $invalid_value = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($invalid_value['profit_percent']);
        $this->assertSame(array('percentage_markup_value_invalid'), $invalid_value['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup', '1000.1');
        $out_of_range = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($out_of_range['profit_percent']);
        $this->assertSame(array('percentage_markup_value_invalid'), $out_of_range['pricing_warnings']);

        update_post_meta(602, '_digitalogic_markup_type', '');
        update_post_meta(602, '_digitalogic_markup', 'not-a-number');
        $missing_type = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertNull($missing_type['profit_percent']);
        $this->assertSame(array('markup_type_missing'), $missing_type['pricing_warnings']);

        delete_post_meta(602, '_digitalogic_markup_type');
        delete_post_meta(602, '_digitalogic_markup');
        $fallback = $this->service->get_product_assignment_by_code('MARKUP-602');
        $this->assertSame('30', $fallback['profit_percent']);
        $this->assertSame('global_default', $fallback['profit_percent_source']);
    }

    public function test_batch_assignment_reads_default_markup_once_for_all_response_rows() {
        $assignments = array();
        for ($index = 0; $index < 12; $index++) {
            $product_id = 620 + $index;
            $code = 'BATCH-MARKUP-' . $product_id;
            $GLOBALS['digitalogic_test_posts'][$product_id] = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'meta' => array('_sku' => $code),
            );
            $assignments[] = array(
                'code' => $code,
                'method_id' => 'air_express',
            );
        }

        $this->service->migrate_legacy_data();
        $this->service->update_default_percentage_markup('30');
        $GLOBALS['wpdb']->option_read_counts = array();

        $result = $this->service->batch_assign_products($assignments);

        $this->assertFalse(is_wp_error($result));
        $this->assertSame(12, $result['updated']);
        $this->assertCount(12, $result['assignments']);
        foreach ($result['assignments'] as $assignment) {
            $this->assertSame('30', $assignment['profit_percent']);
            $this->assertSame('global_default', $assignment['profit_percent_source']);
        }
        $this->assertSame(
            1,
            $GLOBALS['wpdb']->option_read_counts[Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION] ?? 0
        );
    }

    public function test_thirty_percent_global_fixture_produces_2009410_irt() {
        $GLOBALS['digitalogic_test_posts'][603] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => 'FIXTURE-603'),
        );
        $this->service->migrate_legacy_data();
        $this->service->update_default_percentage_markup('30');

        $assignment = $this->service->get_product_assignment_by_code('FIXTURE-603');
        $catalog = $this->service->get_integration_catalog();
        $final_price = (int) round(
            ((24.5 + (240 / 1000 * 120)) * (1 + ((float) $assignment['profit_percent'] / 100))) * 29000,
            0,
            PHP_ROUND_HALF_UP
        );

        $this->assertSame('30', $assignment['profit_percent']);
        $this->assertSame('global_default', $assignment['profit_percent_source']);
        $this->assertSame('30', $catalog['pricing']['default_percentage_markup']['profit_percent']);
        $this->assertSame(2009410, $final_price);
    }

    public function test_default_markup_rest_command_and_admin_surfaces_share_the_service_contract() {
        $this->service->migrate_legacy_data();
        $dispatcher = Digitalogic_Command_Dispatcher::instance();
        $missing = $dispatcher->update_default_percentage_markup(array());
        $this->assertSame('digitalogic_import_freight_default_markup_required', $missing->get_error_code());

        $GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;
        $command = $dispatcher->execute(
            'digitalogic_update_default_percentage_markup',
            array('profit_percent' => '24.5'),
            'websocket'
        );
        $this->assertSame('24.5', $command['profit_percent']);
        $this->assertSame(
            $command['revision'],
            $dispatcher->execute('digitalogic_get_default_percentage_markup', array(), 'websocket')['revision']
        );

        $api = Digitalogic_REST_API::instance();
        $put = $api->update_default_percentage_markup(new WP_REST_Request(array(), array('profit_percent' => '30')));
        $this->assertSame(200, $put->get_status());
        $this->assertSame('30', $put->get_data()['data']['profit_percent']);
        $get = $api->get_default_percentage_markup(new WP_REST_Request());
        $this->assertSame(200, $get->get_status());
        $this->assertSame($put->get_data()['data']['revision'], $get->get_data()['data']['revision']);

        foreach (array('', '   ') as $blank) {
            $invalid_rest = $api->update_default_percentage_markup(
                new WP_REST_Request(array(), array('profit_percent' => $blank))
            );
            $this->assertSame(400, $invalid_rest->get_status());
            $this->assertSame(
                'digitalogic_import_freight_default_markup_invalid',
                $invalid_rest->get_data()['code']
            );
            $this->assertSame('30', $this->service->get_default_percentage_markup()['profit_percent']);

            $invalid_command = $dispatcher->execute(
                'digitalogic_update_default_percentage_markup',
                array('profit_percent' => $blank),
                'websocket'
            );
            $this->assertSame(
                'digitalogic_import_freight_default_markup_invalid',
                $invalid_command->get_error_code()
            );
            $this->assertSame(400, $invalid_command->get_error_data()['status']);
            $this->assertSame('30', $this->service->get_default_percentage_markup()['profit_percent']);
        }

        $clear = $api->update_default_percentage_markup(new WP_REST_Request(array(), array('profit_percent' => null)));
        $this->assertSame(200, $clear->get_status());
        $this->assertFalse($clear->get_data()['data']['configured']);

        $admin = file_get_contents(dirname(__DIR__) . '/includes/admin/class-admin.php');
        $view = file_get_contents(dirname(__DIR__) . '/includes/admin/views/patris-reports.php');
        $this->assertStringContainsString("case 'update_default_markup'", $admin);
        $this->assertStringContainsString("case 'clear_default_markup'", $admin);
        $this->assertMatchesRegularExpression(
            "/case 'update_default_markup'.*posted_value\\('default_profit_percent'\\).*case 'clear_default_markup'.*update_default_percentage_markup\\(null\\)/s",
            $admin
        );
        $this->assertStringContainsString('WooCommerce prices were not changed.', $admin);
        $this->assertStringContainsString('digitalogic_import_freight_admin', $view);
        $this->assertStringContainsString('name="default_profit_percent"', $view);
        $this->assertStringContainsString('value="clear_default_markup"', $view);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_default_markup_event_is_result_aware_and_delivered_once_per_change() {
        $this->service->migrate_legacy_data();
        $GLOBALS['digitalogic_test_posts'][604] = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta' => array('_sku' => 'EVENT-604', '_price' => '888'),
        );
        $before_posts = $GLOBALS['digitalogic_test_posts'];
        $redis = new Digitalogic_Test_Redis_Client();
        add_filter('digitalogic_panel_redis_client', static function() use ($redis) {
            return $redis;
        });
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls'] = array('https://n8n.test/webhook/default-markup');
        $GLOBALS['digitalogic_test_options']['digitalogic_webhook_secret'] = 'test-secret';
        Digitalogic_Panel::instance();
        Digitalogic_Webhooks::instance();

        $updated = $this->service->update_default_percentage_markup('30');
        $this->assertTrue($updated['changed']);
        $events = get_option('digitalogic_panel_events', array());
        $this->assertCount(1, $events);
        $this->assertSame('import_freight.default_markup.updated', $events[0]['name']);
        $this->assertTrue($events[0]['data']['configured']);
        $this->assertSame('30', $events[0]['data']['profit_percent']);
        $this->assertSame($updated['revision'], $events[0]['data']['revision']);
        $this->assertSame($updated['previous_revision'], $events[0]['data']['previous_revision']);
        $this->assertSame($updated['updated_at'], $events[0]['data']['updated_at']);
        $this->assertCount(1, $redis->published);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);
        $webhook = json_decode($GLOBALS['digitalogic_test_remote_posts'][0]['args']['body'], true);
        $this->assertSame('import_freight.default_markup.updated', $webhook['event']);
        $this->assertSame('30', $webhook['data']['profit_percent']);
        $this->assertSame($updated['previous_revision'], $webhook['data']['previous_revision']);

        $same = $this->service->update_default_percentage_markup('30.0');
        $this->assertFalse($same['changed']);
        $this->assertCount(1, get_option('digitalogic_panel_events', array()));
        $this->assertCount(1, $redis->published);
        $this->assertCount(1, $GLOBALS['digitalogic_test_remote_posts']);

        $cleared = $this->service->update_default_percentage_markup(null);
        $this->assertTrue($cleared['changed']);
        $events = get_option('digitalogic_panel_events', array());
        $this->assertCount(2, $events);
        $this->assertFalse($events[1]['data']['configured']);
        $this->assertNull($events[1]['data']['profit_percent']);
        $this->assertCount(2, $redis->published);
        $this->assertCount(2, $GLOBALS['digitalogic_test_remote_posts']);
        $this->assertSame($before_posts, $GLOBALS['digitalogic_test_posts']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_default_markup_lock_write_and_commit_failures_are_atomic_and_silent() {
        $this->service->get_default_percentage_markup();
        $GLOBALS['digitalogic_test_actions'] = array();

        $GLOBALS['wpdb']->acquire_result = 0;
        $busy = $this->service->update_default_percentage_markup('30');
        $this->assertSame('digitalogic_import_freight_catalog_busy', $busy->get_error_code());
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, false));

        $GLOBALS['wpdb']->acquire_result = 1;
        $GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION;
        $write = $this->service->update_default_percentage_markup('30');
        $this->assertSame('digitalogic_import_freight_default_markup_write_failed', $write->get_error_code());
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, false));
        $this->assertContains('ROLLBACK', $GLOBALS['wpdb']->queries);

        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array('COMMIT');
        $commit = $this->service->update_default_percentage_markup('30');
        $this->assertSame('digitalogic_import_freight_commit_failed', $commit->get_error_code());
        $this->assertFalse(get_option(Digitalogic_Import_Freight_Service::DEFAULT_MARKUP_OPTION, false));
        $this->assertArrayNotHasKey('digitalogic_import_freight_default_markup_updated', $GLOBALS['digitalogic_test_actions']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
    }

    public function test_routes_and_implementation_do_not_register_customer_delivery_apis() {
        $api = Digitalogic_REST_API::instance();
        $api->register_routes();

        $freight_routes = array();
        foreach ($GLOBALS['digitalogic_test_routes'] as $registration) {
            if (strpos($registration['route'], 'import-freight') !== false || $registration['route'] === '/integration/catalog' || strpos($registration['route'], 'import-pricing') !== false) {
                $freight_routes[] = $registration['route'];
            }
        }
        $this->assertContains('/integration/catalog', $freight_routes);
        $this->assertContains('/import-freight-methods', $freight_routes);
        $this->assertContains('/products/import-pricing/batch', $freight_routes);

        $source = file_get_contents(dirname(__DIR__) . '/includes/class-import-freight-service.php')
            . file_get_contents(dirname(__DIR__) . '/includes/api/class-rest-api.php');
        $this->assertStringNotContainsString('WC_' . 'Shipping_Method', $source);
        $this->assertStringNotContainsString('woocommerce_' . 'shipping_method', $source);
        $this->assertStringNotContainsString('woocommerce_' . 'checkout', $source);
    }

    private function indexMethods($methods) {
        $indexed = array();
        foreach ($methods as $method) {
            $indexed[$method['id']] = $method;
        }
        return $indexed;
    }

    private function assertAssignmentMetaAbsent($product_id) {
        $this->assertFalse(metadata_exists('post', $product_id, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META));
        $this->assertFalse(metadata_exists('post', $product_id, Digitalogic_Import_Freight_Service::LEGACY_PRODUCT_METHOD_META));
        $this->assertFalse(metadata_exists('post', $product_id, Digitalogic_Import_Freight_Service::LEGACY_ACF_REFERENCE_META));
    }

    private function resetSingleton($class_name) {
        $property = new ReflectionProperty($class_name, 'instance');
        $property->setValue(null, null);
    }
}
