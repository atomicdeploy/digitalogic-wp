<?php
/**
 * Shared Digitalogic report engine.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Report_Engine {

    private const CACHE_GROUP = 'digitalogic_reports';
    private const CACHE_TTL = 300;
    private const CACHE_GENERATION_KEY = 'generation-v1';
    private const BUILD_LOCK_TTL = 180;
    private const MAX_ITEM_LIMIT = 250;

    private static $instance = null;
    private $build_lock_token = '';

    private function __construct() {
        add_action('save_post_product', array($this, 'invalidate_cache'));
        add_action('woocommerce_update_product', array($this, 'invalidate_cache'));
        add_action('digitalogic_product_updated', array($this, 'invalidate_cache'));
        add_action('digitalogic_product_sync_applied', array($this, 'invalidate_cache'));
        add_action('digitalogic_patris_feed_synced', array($this, 'invalidate_cache'));
        add_action('digitalogic_woocommerce_currency_changed', array($this, 'invalidate_cache'));
        add_action('updated_option', array($this, 'invalidate_cache_for_option'), 10, 3);
        add_action('added_option', array($this, 'invalidate_cache_for_added_option'), 10, 2);
        add_action('deleted_option', array($this, 'invalidate_cache_for_deleted_option'));
    }

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get_report($args = array()) {
        $args = is_array($args) ? $args : array();

        // Preserve the existing unlimited, always-fresh report contract used
        // by CLI and REST callers unless they explicitly request a bound.
        if (!array_key_exists('item_limit', $args)) {
            return $this->build_report();
        }

        $force_refresh = $this->is_truthy($args['force_refresh'] ?? false);
        $cached_report = $this->get_cached_report();
        $report = $force_refresh ? null : $cached_report;

        if (!is_array($report)) {
            $lock_acquired = $this->acquire_build_lock();
            if (!$lock_acquired && is_array($cached_report)) {
                $report = $cached_report;
                $report['refresh_deferred'] = true;
            } elseif (!$lock_acquired) {
                return new WP_Error(
                    'digitalogic_report_build_in_progress',
                    __('Another report build is already running. Please retry shortly.', 'digitalogic'),
                    array('status' => 503, 'retry_after' => 2)
                );
            } else {
                try {
                    // Close the small gap between the first cache read and the
                    // atomic lock acquisition before starting the expensive audit.
                    $report = $force_refresh ? null : $this->get_cached_report();
                    if (!is_array($report)) {
                        $build_generation = $this->cache_generation();
                        $report = $this->build_report();
                        if (!$this->set_cached_report($report, $build_generation)) {
                            return new WP_Error(
                                'digitalogic_report_source_changed',
                                __('Report source data changed while the report was being built. Please retry.', 'digitalogic'),
                                array('status' => 503, 'retry_after' => 1)
                            );
                        }
                    }
                } finally {
                    $this->release_build_lock();
                }
            }
        }

        return $this->limit_report_items(
            $report,
            $this->normalize_item_limit($args['item_limit']),
            $this->normalize_item_offset($args['item_offset'] ?? 0),
            $this->normalize_category_key($args['category'] ?? '')
        );
    }

    private function build_report() {
        $feed = Digitalogic_Patris_Feed::instance();
        $feed_products = $feed->get_products();
        $feed_customers = $feed->get_customers();
        $settings = $feed->get_settings();
		$catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
        $catalog_error = is_wp_error($catalog) ? $catalog : null;
		$shipping_methods = $catalog_error ? array() : $catalog['shipping_methods'];
        $products = $this->get_woocommerce_products();
        $by_sku = $this->index_products_by_sku($products);

        $categories = array(
            'missing_in_woocommerce' => $this->category('missing_in_woocommerce', __('In Patris/API but missing in WooCommerce', 'digitalogic'), 'danger'),
            'missing_in_patris' => $this->category('missing_in_patris', __('In WooCommerce but missing in Patris/API', 'digitalogic'), 'warning'),
            'duplicate_sku' => $this->category('duplicate_sku', __('Duplicate product code / SKU', 'digitalogic'), 'danger'),
            'zero_stock' => $this->category('zero_stock', __('Zero or negative stock', 'digitalogic'), 'danger'),
            'zero_price' => $this->category('zero_price', __('Zero or invalid price', 'digitalogic'), 'danger'),
            'missing_foreign_price' => $this->category('missing_foreign_price', __('Missing foreign price', 'digitalogic'), 'warning'),
            'bad_weight' => $this->category('bad_weight', __('Missing, bad, or ambiguous weight', 'digitalogic'), 'warning'),
            'missing_minimum_stock' => $this->category('missing_minimum_stock', __('Missing minimum / reorder point', 'digitalogic'), 'warning'),
            'stale_price' => $this->category('stale_price', __('Stale Patris/API price data', 'digitalogic'), 'warning'),
            'missing_image' => $this->category('missing_image', __('Missing product image', 'digitalogic'), 'warning'),
            'missing_description' => $this->category('missing_description', __('Missing product description', 'digitalogic'), 'warning'),
            'mismatched_name' => $this->category('mismatched_name', __('Name differs between WooCommerce and Patris/API', 'digitalogic'), 'info'),
            'image_duplicate' => $this->category('image_duplicate', __('Duplicate product image files', 'digitalogic'), 'warning'),
            'image_corrupt' => $this->category('image_corrupt', __('Corrupt product image files', 'digitalogic'), 'danger'),
            'image_quality' => $this->category('image_quality', __('Low quality product images', 'digitalogic'), 'warning'),
            'customer_missing_mobile' => $this->category('customer_missing_mobile', __('Customers missing mobile/phone number', 'digitalogic'), 'warning'),
        );

        foreach ($feed_products as $code => $item) {
            $code = (string) ($item['product_code'] ?? $code);
            if ($code === '') {
                continue;
            }

            if (empty($by_sku[$code])) {
                $categories['missing_in_woocommerce']['items'][] = $this->feed_item($item);
            }

            if ($this->is_zero_or_negative($item['total_stock'] ?? null)) {
                $categories['zero_stock']['items'][] = $this->feed_item($item);
            }

            if ($this->is_zero_or_negative($item['final_price'] ?? null)) {
                $categories['zero_price']['items'][] = $this->feed_item($item);
            }

            if ($this->is_empty_number($item['foreign_price'] ?? null) && !$this->is_zero_or_negative($item['total_stock'] ?? null)) {
                $categories['missing_foreign_price']['items'][] = $this->feed_item($item);
            }

            if ($this->is_empty_number($item['weight_grams'] ?? null) || in_array('bad_weight', (array) ($item['flags'] ?? array()), true) || in_array('ambiguous_weight', (array) ($item['flags'] ?? array()), true)) {
                $categories['bad_weight']['items'][] = $this->feed_item($item);
            }

            if ($this->is_empty_number($item['minimum_stock'] ?? null)) {
                $categories['missing_minimum_stock']['items'][] = $this->feed_item($item);
            }

            if ($this->is_stale($item['updated_at'] ?? '', $settings['stale_after_hours'])) {
                $categories['stale_price']['items'][] = $this->feed_item($item);
            }

            if (!empty($by_sku[$code])) {
                foreach ($by_sku[$code] as $product) {
                    if ($this->names_mismatch($product['name'], $item['name'] ?? '')) {
                        $categories['mismatched_name']['items'][] = $this->product_item($product, $item);
                    }
                }
            }
        }

        $product_index = 0;
        foreach ($products as $product) {
            $sku = (string) $product['sku'];
            if ($sku === '' || empty($feed_products[$sku])) {
                $categories['missing_in_patris']['items'][] = $this->product_item($product);
            }

            if (empty($product['image'])) {
                $categories['missing_image']['items'][] = $this->product_item($product);
            }

            if ($this->product_has_empty_description($product['id'])) {
                $categories['missing_description']['items'][] = $this->product_item($product);
            }

            if ($this->is_zero_or_negative($product['stock_quantity'])) {
                $categories['zero_stock']['items'][] = $this->product_item($product, $feed_products[$sku] ?? array());
            }

            if ($this->is_zero_or_negative($product['regular_price'])) {
                $categories['zero_price']['items'][] = $this->product_item($product, $feed_products[$sku] ?? array());
            }

            $this->maybe_flush_runtime_cache(++$product_index);
        }

        foreach ($by_sku as $sku => $items) {
            if ($sku !== '' && count($items) > 1) {
                foreach ($items as $product) {
                    $categories['duplicate_sku']['items'][] = $this->product_item($product);
                }
            }
        }

        $this->append_image_audit($products, $categories, $settings);
        $this->append_customer_audit($feed_customers, $categories);

        foreach ($categories as &$category) {
            $category['count'] = count($category['items']);
        }

        return array(
            'generated_at' => current_time('mysql'),
            'brand' => array(
                'en' => 'Digitalogic',
                'fa' => 'دیجیتالاجیک',
            ),
            'counts' => array(
                'woocommerce_products' => count($products),
                'patris_products' => count($feed_products),
                'patris_customers' => count($feed_customers),
            ),
            'settings' => array(
                'selected_warehouses' => $settings['selected_warehouses'],
				'shipping_methods' => $shipping_methods,
                'integration_catalog_revision' => $catalog_error ? null : $catalog['revision'],
                'integration_catalog_error' => $catalog_error ? $catalog_error->get_error_code() : null,
                'stale_after_hours' => $settings['stale_after_hours'],
            ),
            'last_sync' => $feed->get_last_sync(),
            'categories' => array_values($categories),
        );
    }

    private function get_cached_report() {
        if (!function_exists('wp_cache_get')) {
            return null;
        }

        $found = false;
        $report = wp_cache_get($this->cache_key(), self::CACHE_GROUP, false, $found);
        if (!$found || !is_array($report) || !isset($report['_cache_generation'])) {
            return null;
        }

        $cached_generation = (string) $report['_cache_generation'];
        unset($report['_cache_generation']);
        if (!hash_equals($this->cache_generation(), $cached_generation)) {
            $this->delete_cached_report();
            return null;
        }

        return $report;
    }

    public function invalidate_cache() {
        if (function_exists('wp_cache_set')) {
            wp_cache_set(self::CACHE_GENERATION_KEY, $this->new_cache_token(), self::CACHE_GROUP, 0);
        }

        if (!function_exists('wp_cache_delete')) {
            return;
        }

        $locales = array('en_US', 'fa_IR');
        if (function_exists('determine_locale')) {
            $locales[] = determine_locale();
        }
        if (function_exists('get_locale')) {
            $locales[] = get_locale();
        }

        foreach (array_unique(array_filter($locales)) as $locale) {
            wp_cache_delete($this->cache_key_for_locale($locale), self::CACHE_GROUP);
        }
    }

    public function invalidate_cache_for_option($option, $old_value = null, $value = null) {
        if ($this->is_report_option($option)) {
            $this->invalidate_cache();
        }
    }

    public function invalidate_cache_for_added_option($option, $value = null) {
        $this->invalidate_cache_for_option($option);
    }

    public function invalidate_cache_for_deleted_option($option) {
        $this->invalidate_cache_for_option($option);
    }

    private function is_report_option($option) {
        return in_array((string) $option, array(
            'digitalogic_patris_feed_settings',
            'digitalogic_patris_feed_products',
            'digitalogic_patris_feed_customers',
            'digitalogic_shipping_methods',
            'digitalogic_pricing_default_percentage_markup',
            'dollar_price',
            'yuan_price',
            'options_dollar_price',
            'options_yuan_price',
            'woocommerce_currency',
        ), true);
    }

    private function acquire_build_lock() {
        if (!function_exists('wp_cache_add')) {
            $this->build_lock_token = 'request-local';
            return true;
        }

        $token = $this->new_cache_token();
        $acquired = wp_cache_add($this->build_lock_key(), $token, self::CACHE_GROUP, self::BUILD_LOCK_TTL);
        $this->build_lock_token = $acquired ? $token : '';

        return (bool) $acquired;
    }

    private function release_build_lock() {
        if ('request-local' === $this->build_lock_token) {
            $this->build_lock_token = '';
            return;
        }
        if (!$this->build_lock_token || !function_exists('wp_cache_get') || !function_exists('wp_cache_delete')) {
            return;
        }

        $found = false;
        $current = wp_cache_get($this->build_lock_key(), self::CACHE_GROUP, false, $found);
        if ($found && is_string($current) && hash_equals($this->build_lock_token, $current)) {
            wp_cache_delete($this->build_lock_key(), self::CACHE_GROUP);
        }
        $this->build_lock_token = '';
    }

    private function build_lock_key() {
        return 'build-lock-v2-' . md5($this->cache_key());
    }

    private function set_cached_report($report, $build_generation) {
        if (!function_exists('wp_cache_set') || !is_array($report)) {
            return true;
        }

        $build_generation = (string) $build_generation;
        if (!hash_equals($build_generation, $this->cache_generation())) {
            return false;
        }

        $cached_report = $report;
        $cached_report['_cache_generation'] = $build_generation;
        wp_cache_set($this->cache_key(), $cached_report, self::CACHE_GROUP, self::CACHE_TTL);

        if (!hash_equals($build_generation, $this->cache_generation())) {
            $this->delete_cached_report();
            return false;
        }

        return true;
    }

    private function cache_generation() {
        if (!function_exists('wp_cache_get')) {
            return 'initial';
        }

        $found = false;
        $generation = wp_cache_get(self::CACHE_GENERATION_KEY, self::CACHE_GROUP, false, $found);

        return $found && is_string($generation) && '' !== $generation ? $generation : 'initial';
    }

    private function new_cache_token() {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('report-', true);
    }

    private function delete_cached_report() {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($this->cache_key(), self::CACHE_GROUP);
        }
    }

    private function cache_key() {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();

        return $this->cache_key_for_locale($locale);
    }

    private function cache_key_for_locale($locale) {
        return 'full-v1-' . md5((string) $locale);
    }

    private function normalize_item_limit($value) {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            return 0;
        }

        return min(self::MAX_ITEM_LIMIT, max(0, (int) $value));
    }

    private function normalize_item_offset($value) {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            return 0;
        }

        return max(0, (int) $value);
    }

    private function normalize_category_key($value) {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $value)));
    }

    private function limit_report_items($report, $item_limit, $item_offset = 0, $category_key = '') {
        $available_categories = array_column((array) ($report['categories'] ?? array()), 'key');
        if ('' !== $category_key && !in_array($category_key, $available_categories, true)) {
            return new WP_Error(
                'digitalogic_unknown_report_category',
                __('Unknown report category.', 'digitalogic'),
                array('status' => 400)
            );
        }

        $returned_count = 0;
        $truncated = false;
        $categories = array();

        foreach ((array) ($report['categories'] ?? array()) as $category) {
            $items = isset($category['items']) && is_array($category['items'])
                ? array_values($category['items'])
                : array();
            $total_count = isset($category['count']) ? max(0, (int) $category['count']) : count($items);
            $is_requested_category = '' === $category_key || $category_key === (string) ($category['key'] ?? '');
            $category_offset = $is_requested_category ? min($item_offset, $total_count) : 0;
            $category['items'] = $is_requested_category
                ? array_slice($items, $category_offset, $item_limit)
                : array();
            $category['item_offset'] = $category_offset;
            $category['returned_count'] = count($category['items']);
            $category['has_more'] = $is_requested_category
                && ($category_offset + $category['returned_count']) < $total_count;
            $category['truncated'] = $category['returned_count'] < $total_count;
            $returned_count += $category['returned_count'];
            $truncated = $truncated || $category['truncated'];
            $categories[] = $category;
        }

        $report['categories'] = $categories;
        $report['category'] = $category_key;
        $report['item_limit'] = $item_limit;
        $report['item_offset'] = $item_offset;
        $report['returned_count'] = $returned_count;
        $report['truncated'] = $truncated;

        return $report;
    }

    private function is_truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return 1.0 === (float) $value;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true);
    }

    private function get_woocommerce_products() {
        $manager = Digitalogic_Product_Manager::instance();
        $products = array();
        $page = 1;

        do {
            $result = $manager->query_products(array(
                'limit' => 100,
                'page' => $page,
                'status' => 'any',
                'type' => array('simple', 'variable', 'variation'),
                'orderby' => 'ID',
                'order' => 'ASC',
            ));
            foreach ((array) ($result['products'] ?? array()) as $product) {
                $products[] = $product;
            }
            $pages = max(0, (int) ($result['pages'] ?? 0));
            unset($result);
            $this->flush_runtime_cache();
            ++$page;
        } while ($page <= $pages);

        return $products;
    }

    private function maybe_flush_runtime_cache($index, $batch_size = 100) {
        if ($index > 0 && 0 === $index % max(1, (int) $batch_size)) {
            $this->flush_runtime_cache();
        }
    }

    private function flush_runtime_cache() {
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    private function index_products_by_sku($products) {
        $indexed = array();
        foreach ($products as $product) {
            $sku = (string) ($product['sku'] ?? '');
            if ($sku === '') {
                continue;
            }
            if (!isset($indexed[$sku])) {
                $indexed[$sku] = array();
            }
            $indexed[$sku][] = $product;
        }

        return $indexed;
    }

    private function category($key, $title, $severity) {
        return array(
            'key' => $key,
            'title' => $title,
            'severity' => $severity,
            'count' => 0,
            'items' => array(),
        );
    }

    private function feed_item($feed) {
        return array(
            'source' => 'patris_api',
            'product_code' => (string) ($feed['product_code'] ?? ''),
            'name' => (string) ($feed['name'] ?? ''),
            'woo_id' => null,
            'woo_name' => '',
            'foreign_currency' => (string) ($feed['foreign_currency'] ?? ''),
            'foreign_price' => $feed['foreign_price'] ?? null,
            'weight_grams' => $feed['weight_grams'] ?? null,
            'stock' => $feed['total_stock'] ?? null,
            'minimum_stock' => $feed['minimum_stock'] ?? null,
            'final_price' => $feed['final_price'] ?? null,
            'updated_at' => (string) ($feed['updated_at'] ?? ''),
            'location' => (string) ($feed['location'] ?? ''),
            'edit_url' => '',
        );
    }

    private function product_item($product, $feed = array()) {
        return array(
            'source' => 'woocommerce',
            'product_code' => (string) ($product['sku'] ?? ''),
            'name' => (string) ($feed['name'] ?? ''),
            'woo_id' => (int) ($product['id'] ?? 0),
            'woo_name' => (string) ($product['name'] ?? ''),
            'foreign_currency' => (string) ($product['patris_foreign_currency'] ?? $feed['foreign_currency'] ?? ''),
            'foreign_price' => $product['patris_foreign_price'] ?? $feed['foreign_price'] ?? null,
            'weight_grams' => $product['patris_weight_grams'] ?? $feed['weight_grams'] ?? null,
            'stock' => $product['stock_quantity'] ?? $feed['total_stock'] ?? null,
            'minimum_stock' => $product['patris_minimum_stock'] ?? $feed['minimum_stock'] ?? null,
            'final_price' => $product['patris_final_price'] ?? $feed['final_price'] ?? $product['regular_price'] ?? null,
            'updated_at' => (string) ($product['patris_updated_at'] ?? $feed['updated_at'] ?? ''),
            'location' => (string) ($product['patris_location'] ?? $feed['location'] ?? ''),
            'edit_url' => (string) ($product['edit_url'] ?? ''),
        );
    }

    private function append_image_audit($products, &$categories, $settings) {
        $hashes = array();
        $thresholds = $settings['image_quality_thresholds'];
        $review_threshold = isset($thresholds['soft_review']) ? (int) $thresholds['soft_review'] : 450;

        $product_index = 0;
        foreach ($products as $product) {
            $this->maybe_flush_runtime_cache(++$product_index);
            $image_id = get_post_thumbnail_id($product['edit_product_id'] ?? $product['id']);
            if (!$image_id) {
                continue;
            }

            $file = get_attached_file($image_id);
            if (!$file || !is_file($file)) {
                $categories['image_corrupt']['items'][] = $this->product_item($product);
                continue;
            }

            $hash = md5_file($file);
            if ($hash) {
                if (!empty($hashes[$hash])) {
                    $categories['image_duplicate']['items'][] = $this->product_item($product);
                }
                $hashes[$hash] = true;
            }

            $size = @getimagesize($file);
            if (!$size || empty($size[0]) || empty($size[1])) {
                $categories['image_corrupt']['items'][] = $this->product_item($product);
                continue;
            }

            if ($size[0] <= $review_threshold || $size[1] <= $review_threshold) {
                $item = $this->product_item($product);
                $item['image_width'] = $size[0];
                $item['image_height'] = $size[1];
                $categories['image_quality']['items'][] = $item;
            }
        }
    }

    private function append_customer_audit($customers, &$categories) {
        foreach ($customers as $customer) {
            $haystack = implode(' ', array_filter(array(
                $customer['tel'] ?? '',
                $customer['phone'] ?? '',
                $customer['mobile'] ?? '',
                $customer['address'] ?? '',
            )));

            if (!preg_match('/(?<!\d)0?9\d{9}(?!\d)/', $haystack)) {
                $categories['customer_missing_mobile']['items'][] = array(
                    'source' => 'patris_api',
                    'customer_code' => $customer['customer_code'] ?? '',
                    'name' => $customer['name'] ?? '',
                    'tel' => $customer['tel'] ?? '',
                    'phone' => $customer['phone'] ?? '',
                    'mobile' => $customer['mobile'] ?? '',
                    'email' => $customer['email'] ?? '',
                    'address' => $customer['address'] ?? '',
                );
            }
        }
    }

    private function is_zero_or_negative($value) {
        return $value !== null && is_numeric($value) && (float) $value <= 0;
    }

    private function is_empty_number($value) {
        return $value === null || $value === '' || !is_numeric($value) || (float) $value <= 0;
    }

    private function is_stale($updated_at, $hours) {
        if (!$updated_at) {
            return true;
        }

        $timestamp = strtotime($updated_at);
        if (!$timestamp) {
            return true;
        }

        return $timestamp < (time() - (max(1, (int) $hours) * HOUR_IN_SECONDS));
    }

    private function names_mismatch($woo_name, $feed_name) {
        $woo = $this->normalize_name($woo_name);
        $feed = $this->normalize_name($feed_name);

        return $woo !== '' && $feed !== '' && $woo !== $feed;
    }

    private function normalize_name($name) {
        $name = strtolower(wp_strip_all_tags((string) $name));
        return preg_replace('/[^a-z0-9\p{Arabic}]+/u', '', $name);
    }

    private function product_has_empty_description($product_id) {
        $post = get_post((int) $product_id);
        if (!$post) {
            return true;
        }

        return trim(wp_strip_all_tags($post->post_content . ' ' . $post->post_excerpt)) === '';
    }
}
