<?php

use PHPUnit\Framework\TestCase;

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        $cache_key = (string) $group . ':' . (string) $key;
        $found = array_key_exists($cache_key, $GLOBALS['digitalogic_test_object_cache']);

        return $found ? $GLOBALS['digitalogic_test_object_cache'][$cache_key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        if (isset($GLOBALS['digitalogic_test_cache_set_callback']) && is_callable($GLOBALS['digitalogic_test_cache_set_callback'])) {
            $callback = $GLOBALS['digitalogic_test_cache_set_callback'];
            $GLOBALS['digitalogic_test_cache_set_callback'] = null;
            $callback($key, $data, $group, $expire);
        }
        $cache_key = (string) $group . ':' . (string) $key;
        $GLOBALS['digitalogic_test_object_cache'][$cache_key] = $data;
        $GLOBALS['digitalogic_test_object_cache_sets'][] = array($key, $group, (int) $expire);

        return true;
    }
}

if (!function_exists('wp_cache_add')) {
    function wp_cache_add($key, $data, $group = '', $expire = 0) {
        $cache_key = (string) $group . ':' . (string) $key;
        if (isset($GLOBALS['digitalogic_test_cache_add_callback']) && is_callable($GLOBALS['digitalogic_test_cache_add_callback'])) {
            $GLOBALS['digitalogic_test_cache_add_callback']($key, $data, $group, $expire);
        }
        if (array_key_exists($cache_key, $GLOBALS['digitalogic_test_object_cache'])) {
            return false;
        }
        $GLOBALS['digitalogic_test_object_cache'][$cache_key] = $data;

        return true;
    }
}

final class ReportEngineTest extends TestCase {

    private Digitalogic_Report_Engine $engine;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array(
            'digitalogic_patris_feed_settings' => array(
                'selected_warehouses' => array(),
                'stale_after_hours' => 48,
            ),
            'digitalogic_patris_feed_products' => array(),
            'digitalogic_patris_feed_customers' => array(),
            'digitalogic_shipping_currency_migration_complete' => 'complete',
            'woocommerce_currency' => 'IRT',
        );
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_post_meta_cache'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_object_cache'] = array();
        $GLOBALS['digitalogic_test_object_cache_sets'] = array();
        $GLOBALS['digitalogic_test_cache_add_callback'] = null;
        $GLOBALS['digitalogic_test_cache_set_callback'] = null;
        $GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

        $this->resetSingleton(Digitalogic_Report_Engine::class);
        $this->resetSingleton(Digitalogic_Patris_Feed::class);
        $this->resetSingleton(Digitalogic_Product_Manager::class);
        $this->resetSingleton(Digitalogic_Shipping_Method_Service::class);
        $this->resetSingleton(Digitalogic_WooCommerce_Currency_Status::class);

        $this->engine = Digitalogic_Report_Engine::instance();
    }

    public function test_item_limit_bounds_each_category_and_reports_truncation_metadata(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
            'REPORT-2' => $this->feedProduct('REPORT-2'),
            'REPORT-3' => $this->feedProduct('REPORT-3'),
        );

        $report = $this->engine->get_report(array('item_limit' => '2'));
        $missing = $this->category($report, 'missing_in_woocommerce');

        $this->assertSame(2, $report['item_limit']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(3, $missing['count']);
        $this->assertSame(2, $missing['returned_count']);
        $this->assertTrue($missing['truncated']);
        $this->assertCount(2, $missing['items']);
        $this->assertGreaterThanOrEqual(2, $report['returned_count']);
    }

    public function test_unlimited_report_preserves_legacy_shape_and_bypasses_bounded_cache(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
            'REPORT-2' => $this->feedProduct('REPORT-2'),
        );

        $bounded = $this->engine->get_report(array('item_limit' => 1));
        $this->assertSame(2, $this->category($bounded, 'missing_in_woocommerce')['count']);

        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products']['REPORT-3'] = $this->feedProduct('REPORT-3');
        $unlimited = $this->engine->get_report();
        $missing = $this->category($unlimited, 'missing_in_woocommerce');

        $this->assertSame(3, $missing['count']);
        $this->assertCount(3, $missing['items']);
        $this->assertArrayNotHasKey('item_limit', $unlimited);
        $this->assertArrayNotHasKey('returned_count', $unlimited);
        $this->assertArrayNotHasKey('truncated', $unlimited);
        $this->assertArrayNotHasKey('returned_count', $missing);
        $this->assertArrayNotHasKey('truncated', $missing);
    }

    public function test_bounded_cache_respects_false_strings_and_force_refreshes_explicitly(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
        );

        $initial = $this->engine->get_report(array('item_limit' => 10));
        $this->assertSame(1, $this->category($initial, 'missing_in_woocommerce')['count']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_object_cache_sets']);
        $this->assertSame('digitalogic_reports', $GLOBALS['digitalogic_test_object_cache_sets'][0][1]);
        $this->assertSame(300, $GLOBALS['digitalogic_test_object_cache_sets'][0][2]);

        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products']['REPORT-2'] = $this->feedProduct('REPORT-2');
        $cached = $this->engine->get_report(array('item_limit' => 10, 'force_refresh' => 'false'));
        $this->assertSame(1, $this->category($cached, 'missing_in_woocommerce')['count']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_object_cache_sets']);

        $refreshed = $this->engine->get_report(array('item_limit' => 10, 'force_refresh' => 'true'));
        $this->assertSame(2, $this->category($refreshed, 'missing_in_woocommerce')['count']);
        $this->assertCount(2, $GLOBALS['digitalogic_test_object_cache_sets']);
    }

    public function test_item_limit_is_clamped_and_invalid_values_are_safe(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
        );

        $clamped = $this->engine->get_report(array('item_limit' => '999999', 'force_refresh' => true));
        $invalid = $this->engine->get_report(array('item_limit' => 'not-a-number'));

        $this->assertSame(250, $clamped['item_limit']);
        $this->assertSame(0, $invalid['item_limit']);
        $this->assertSame(0, $this->category($invalid, 'missing_in_woocommerce')['returned_count']);
        $this->assertTrue($this->category($invalid, 'missing_in_woocommerce')['truncated']);
    }

    public function test_category_offset_returns_a_reachable_page_without_other_category_rows(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
            'REPORT-2' => $this->feedProduct('REPORT-2'),
            'REPORT-3' => $this->feedProduct('REPORT-3'),
            'REPORT-4' => $this->feedProduct('REPORT-4'),
        );

        $report = $this->engine->get_report(array(
            'category' => 'missing_in_woocommerce',
            'item_limit' => 2,
            'item_offset' => 2,
        ));
        $requested = $this->category($report, 'missing_in_woocommerce');
        $other = $this->category($report, 'zero_price');

        $this->assertSame('missing_in_woocommerce', $report['category']);
        $this->assertSame(2, $report['item_offset']);
        $this->assertSame(2, $requested['item_offset']);
        $this->assertSame(array('REPORT-3', 'REPORT-4'), array_column($requested['items'], 'product_code'));
        $this->assertFalse($requested['has_more']);
        $this->assertSame(array(), $other['items']);
    }

    public function test_summary_and_each_page_expose_reachable_pagination_metadata(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
            'REPORT-2' => $this->feedProduct('REPORT-2'),
            'REPORT-3' => $this->feedProduct('REPORT-3'),
            'REPORT-4' => $this->feedProduct('REPORT-4'),
        );

        $summary = $this->engine->get_report(array('item_limit' => 0));
        $summary_category = $this->category($summary, 'missing_in_woocommerce');
        $first = $this->engine->get_report(array('category' => 'missing_in_woocommerce', 'item_limit' => 2));
        $final = $this->engine->get_report(array('category' => 'missing_in_woocommerce', 'item_limit' => 2, 'item_offset' => 2));

        $this->assertSame(4, $summary_category['count']);
        $this->assertSame(array(), $summary_category['items']);
        $this->assertSame(0, $summary_category['returned_count']);
        $this->assertTrue($summary_category['has_more']);
        $this->assertSame(array('REPORT-1', 'REPORT-2'), array_column($this->category($first, 'missing_in_woocommerce')['items'], 'product_code'));
        $this->assertTrue($this->category($first, 'missing_in_woocommerce')['has_more']);
        $this->assertSame(array('REPORT-3', 'REPORT-4'), array_column($this->category($final, 'missing_in_woocommerce')['items'], 'product_code'));
        $this->assertFalse($this->category($final, 'missing_in_woocommerce')['has_more']);
    }

    public function test_out_of_range_offset_is_stable_and_unknown_category_is_rejected(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
            'REPORT-2' => $this->feedProduct('REPORT-2'),
        );

        $out_of_range = $this->engine->get_report(array(
            'category' => 'missing_in_woocommerce',
            'item_limit' => 25,
            'item_offset' => 999,
        ));
        $category = $this->category($out_of_range, 'missing_in_woocommerce');
        $unknown = $this->engine->get_report(array('category' => 'not_a_report', 'item_limit' => 25));

        $this->assertSame(2, $category['item_offset']);
        $this->assertSame(array(), $category['items']);
        $this->assertFalse($category['has_more']);
        $this->assertInstanceOf(WP_Error::class, $unknown);
        $this->assertSame('digitalogic_unknown_report_category', $unknown->get_error_code());
    }

    public function test_lock_loser_never_builds_and_invalidation_removes_bounded_cache(): void {
        $lock_key = 'build-lock-v2-' . md5('full-v1-' . md5('en_US'));
        $GLOBALS['digitalogic_test_object_cache']['digitalogic_reports:' . $lock_key] = 'another-request';

        $locked = $this->engine->get_report(array('item_limit' => 0));
        $this->assertInstanceOf(WP_Error::class, $locked);
        $this->assertSame('digitalogic_report_build_in_progress', $locked->get_error_code());
        $this->assertCount(0, $GLOBALS['digitalogic_test_object_cache_sets']);

        unset($GLOBALS['digitalogic_test_object_cache']['digitalogic_reports:' . $lock_key]);
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
        );
        $initial = $this->engine->get_report(array('item_limit' => 10));
        $this->assertSame(1, $this->category($initial, 'missing_in_woocommerce')['count']);

        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products']['REPORT-2'] = $this->feedProduct('REPORT-2');
        $this->engine->invalidate_cache();
        $refreshed = $this->engine->get_report(array('item_limit' => 10));
        $this->assertSame(2, $this->category($refreshed, 'missing_in_woocommerce')['count']);
    }

    public function test_lock_owner_does_not_delete_a_replacement_lock(): void {
        $acquire = new ReflectionMethod(Digitalogic_Report_Engine::class, 'acquire_build_lock');
        $release = new ReflectionMethod(Digitalogic_Report_Engine::class, 'release_build_lock');
        $key_method = new ReflectionMethod(Digitalogic_Report_Engine::class, 'build_lock_key');

        $this->assertTrue($acquire->invoke($this->engine));
        $lock_key = $key_method->invoke($this->engine);
        $cache_key = 'digitalogic_reports:' . $lock_key;
        $GLOBALS['digitalogic_test_object_cache'][$cache_key] = 'replacement-owner';
        $release->invoke($this->engine);

        $this->assertSame('replacement-owner', $GLOBALS['digitalogic_test_object_cache'][$cache_key]);
    }

    public function test_cache_is_double_checked_after_lock_acquisition(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
        );
        $fresh = $this->engine->get_report();
        $report_cache_key = 'digitalogic_reports:full-v1-' . md5('en_US');
        $GLOBALS['digitalogic_test_cache_add_callback'] = static function($key, $data, $group) use ($fresh, $report_cache_key) {
            if ('digitalogic_reports' === $group && 0 === strpos((string) $key, 'build-lock-v2-')) {
                $fresh['_cache_generation'] = 'initial';
                $GLOBALS['digitalogic_test_object_cache'][$report_cache_key] = $fresh;
            }
        };

        $bounded = $this->engine->get_report(array('item_limit' => 10));

        $this->assertSame(1, $this->category($bounded, 'missing_in_woocommerce')['count']);
        $this->assertCount(0, $GLOBALS['digitalogic_test_object_cache_sets']);
    }

    public function test_invalidation_during_cache_publish_rejects_the_stale_report(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
            'REPORT-1' => $this->feedProduct('REPORT-1'),
        );
        $GLOBALS['digitalogic_test_cache_set_callback'] = function($key, $data, $group) {
            if ('digitalogic_reports' === $group && 0 === strpos((string) $key, 'full-v1-')) {
                $this->engine->invalidate_cache();
            }
        };

        $report = $this->engine->get_report(array('item_limit' => 10));
        $report_cache_key = 'digitalogic_reports:full-v1-' . md5('en_US');

        $this->assertInstanceOf(WP_Error::class, $report);
        $this->assertSame('digitalogic_report_source_changed', $report->get_error_code());
        $this->assertArrayNotHasKey($report_cache_key, $GLOBALS['digitalogic_test_object_cache']);
        $this->assertArrayHasKey('digitalogic_reports:generation-v1', $GLOBALS['digitalogic_test_object_cache']);
    }

    public function test_rest_reports_propagates_a_build_lock_error(): void {
        $lock_key = 'build-lock-v2-' . md5('full-v1-' . md5('en_US'));
        $GLOBALS['digitalogic_test_object_cache']['digitalogic_reports:' . $lock_key] = 'another-request';
        $this->resetSingleton(Digitalogic_REST_API::class);

        $response = Digitalogic_REST_API::instance()->get_reports(new WP_REST_Request(array('item_limit' => 0)));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('digitalogic_report_build_in_progress', $response->get_error_code());
        $this->assertSame(503, $response->get_error_data()['status']);
    }

    private function feedProduct(string $code): array {
        return array(
            'product_code' => $code,
            'name' => 'Report product ' . $code,
            'total_stock' => 5,
            'foreign_currency' => 'CNY',
            'foreign_price' => 10,
            'weight_grams' => 25,
            'minimum_stock' => 1,
            'final_price' => 100000,
            'updated_at' => '2026-07-16 12:00:00',
        );
    }

    private function category(array $report, string $key): array {
        foreach ($report['categories'] as $category) {
            if ($key === $category['key']) {
                return $category;
            }
        }

        $this->fail('Missing report category: ' . $key);
    }

    private function resetSingleton(string $class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
