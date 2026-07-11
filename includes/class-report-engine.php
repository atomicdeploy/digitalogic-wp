<?php
/**
 * Shared Digitalogic report engine.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Report_Engine {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get_report($args = array()) {
        $feed = Digitalogic_Patris_Feed::instance();
        $feed_products = $feed->get_products();
        $feed_customers = $feed->get_customers();
        $settings = $feed->get_settings();
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
                'shipping_methods' => $settings['shipping_methods'],
                'stale_after_hours' => $settings['stale_after_hours'],
            ),
            'last_sync' => $feed->get_last_sync(),
            'categories' => array_values($categories),
        );
    }

    private function get_woocommerce_products() {
        $manager = Digitalogic_Product_Manager::instance();
        return $manager->get_products(array(
            'limit' => -1,
            'status' => 'any',
            'type' => array('simple', 'variable', 'variation'),
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
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

        foreach ($products as $product) {
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
