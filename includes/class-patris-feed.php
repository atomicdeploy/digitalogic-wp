<?php
/**
 * Normalized Patris feed ingestion.
 *
 * The external Patris service is responsible for Paradox reads, Patris text
 * conversion, and final price calculation. WordPress consumes a normalized API
 * payload and applies/report it against WooCommerce products.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Patris_Feed {

    private const SETTINGS_OPTION = 'digitalogic_patris_feed_settings';
    private const PRODUCTS_OPTION = 'digitalogic_patris_feed_products';
    private const CUSTOMERS_OPTION = 'digitalogic_patris_feed_customers';
    private const LAST_SYNC_OPTION = 'digitalogic_patris_feed_last_sync';
    private const TOKEN_OPTION = 'digitalogic_patris_feed_push_token';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('digitalogic_patris_feed_sync', array($this, 'pull_sync'));
    }

    public function get_settings() {
        $settings = get_option(self::SETTINGS_OPTION, array());
        $settings = is_array($settings) ? $settings : array();

        return wp_parse_args($settings, array(
            'api_url' => '',
            'api_token' => '',
            'selected_warehouses' => array(),
            'shipping_methods' => array(),
            'legacy_url_replacements' => array(),
            'image_quality_thresholds' => array(
                'very_low' => 180,
                'low' => 250,
                'review' => 350,
                'soft_review' => 450,
            ),
            'stale_after_hours' => 48,
            'sync_interval' => '',
        ));
    }

    public function update_settings($settings) {
        $current = $this->get_settings();
        $next = is_array($settings) ? $settings : array();

        if (isset($next['selected_warehouses']) && is_string($next['selected_warehouses'])) {
            $next['selected_warehouses'] = array_filter(array_map('trim', explode(',', $next['selected_warehouses'])));
        }

        if (isset($next['shipping_methods']) && is_string($next['shipping_methods'])) {
            $decoded = json_decode($next['shipping_methods'], true);
            $next['shipping_methods'] = is_array($decoded) ? $decoded : array();
        }

        if (isset($next['legacy_url_replacements']) && is_string($next['legacy_url_replacements'])) {
            $decoded = json_decode($next['legacy_url_replacements'], true);
            $next['legacy_url_replacements'] = is_array($decoded) ? $decoded : array();
        }

        $settings = array_merge($current, $this->sanitize_settings($next));
        update_option(self::SETTINGS_OPTION, $settings, false);

        if (isset($next['sync_interval'])) {
            $this->schedule_sync($settings['sync_interval']);
        }

        return $settings;
    }

    private function sanitize_settings($settings) {
        $clean = array();

        if (isset($settings['api_url'])) {
            $clean['api_url'] = esc_url_raw($settings['api_url']);
        }
        if (isset($settings['api_token'])) {
            $clean['api_token'] = sanitize_text_field(wp_unslash($settings['api_token']));
        }
        if (isset($settings['selected_warehouses'])) {
            $clean['selected_warehouses'] = array_values(array_filter(array_map('sanitize_text_field', (array) $settings['selected_warehouses'])));
        }
        if (isset($settings['shipping_methods'])) {
            $clean['shipping_methods'] = $this->sanitize_assoc_array($settings['shipping_methods']);
        }
        if (isset($settings['legacy_url_replacements'])) {
            $clean['legacy_url_replacements'] = $this->sanitize_assoc_array($settings['legacy_url_replacements']);
        }
        if (isset($settings['image_quality_thresholds']) && is_array($settings['image_quality_thresholds'])) {
            $clean['image_quality_thresholds'] = array_map('absint', $settings['image_quality_thresholds']);
        }
        if (isset($settings['stale_after_hours'])) {
            $clean['stale_after_hours'] = max(1, absint($settings['stale_after_hours']));
        }
        if (isset($settings['sync_interval'])) {
            $clean['sync_interval'] = sanitize_key($settings['sync_interval']);
        }

        return $clean;
    }

    private function sanitize_assoc_array($items) {
        $clean = array();
        foreach ((array) $items as $key => $value) {
            $key = sanitize_text_field((string) $key);
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize_assoc_array($value);
            } else {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }

    public function get_push_token() {
        $token = (string) get_option(self::TOKEN_OPTION, '');
        if ($token === '') {
            $token = wp_generate_password(48, false, false);
            add_option(self::TOKEN_OPTION, $token, '', 'no');
        }

        return $token;
    }

    public function schedule_sync($interval) {
        $timestamp = wp_next_scheduled('digitalogic_patris_feed_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'digitalogic_patris_feed_sync');
        }

        if (in_array($interval, array('hourly', 'twicedaily', 'daily'), true)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $interval, 'digitalogic_patris_feed_sync');
        }
    }

    public function pull_sync() {
        $settings = $this->get_settings();
        if (empty($settings['api_url'])) {
            return new WP_Error('digitalogic_patris_missing_url', __('Patris API URL is not configured.', 'digitalogic'));
        }

        $headers = array('Accept' => 'application/json');
        if (!empty($settings['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['api_token'];
        }

        $response = wp_remote_get($settings['api_url'], array(
            'timeout' => 45,
            'headers' => $headers,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('digitalogic_patris_http_error', sprintf(__('Patris API returned HTTP %d.', 'digitalogic'), $code));
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            return new WP_Error('digitalogic_patris_invalid_json', __('Patris API did not return valid JSON.', 'digitalogic'));
        }

        return $this->import_payload($payload, 'pull');
    }

    public function import_payload($payload, $source = 'push') {
        if (!is_array($payload)) {
            return new WP_Error('digitalogic_patris_invalid_payload', __('Patris payload must be an object.', 'digitalogic'));
        }

        $products = $this->extract_list($payload, 'products');
        $customers = $this->extract_list($payload, 'customers');

        if (empty($products) && empty($customers)) {
            return new WP_Error('digitalogic_patris_empty_payload', __('Patris payload did not contain products or customers.', 'digitalogic'));
        }

        $normalized_products = array();
        $results = array(
            'source' => $source,
            'total' => 0,
            'updated' => 0,
            'missing_in_woocommerce' => 0,
            'customers_imported' => 0,
            'failed' => 0,
            'errors' => array(),
            'synced_at' => current_time('mysql'),
        );

        foreach ($products as $row) {
            $product_data = $this->normalize_product($row);
            if (empty($product_data['product_code'])) {
                $results['failed']++;
                $results['errors'][] = __('Skipped product without product_code.', 'digitalogic');
                continue;
            }

            $results['total']++;
            $normalized_products[$product_data['product_code']] = $product_data;

            $product_id = wc_get_product_id_by_sku($product_data['product_code']);
            if (!$product_id) {
                $results['missing_in_woocommerce']++;
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                $results['failed']++;
                $results['errors'][] = sprintf(__('WooCommerce product for %s could not be loaded.', 'digitalogic'), $product_data['product_code']);
                continue;
            }

            $this->apply_product_feed($product, $product_data);
            $results['updated']++;
        }

        $normalized_customers = $this->normalize_customers($customers);
        $results['customers_imported'] = count($normalized_customers);

        if (!empty($products)) {
            update_option(self::PRODUCTS_OPTION, $normalized_products, false);
        }
        if (!empty($customers)) {
            update_option(self::CUSTOMERS_OPTION, $normalized_customers, false);
        }
        update_option(self::LAST_SYNC_OPTION, $results, false);

        Digitalogic_Logger::instance()->log(
            'patris_feed_sync',
            'patris_feed',
            null,
            null,
            wp_json_encode($results),
            'Patris feed synchronized'
        );

        do_action('digitalogic_patris_feed_synced', $results);

        return $results;
    }

    private function extract_list($payload, $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values($payload[$key]);
        }

        if ($key === 'products' && array_is_list($payload)) {
            return $payload;
        }

        return array();
    }

    public function get_products() {
        $products = get_option(self::PRODUCTS_OPTION, array());
        return is_array($products) ? $products : array();
    }

    public function get_customers() {
        $customers = get_option(self::CUSTOMERS_OPTION, array());
        return is_array($customers) ? $customers : array();
    }

    public function get_last_sync() {
        $sync = get_option(self::LAST_SYNC_OPTION, array());
        return is_array($sync) ? $sync : array();
    }

    public function verify_push_request(WP_REST_Request $request) {
        $expected = $this->get_push_token();
        $provided = $request->get_header('x-digitalogic-token');

        if (!$provided) {
            $provided = $request->get_param('token');
        }

        return is_string($provided) && hash_equals($expected, $provided);
    }

    private function normalize_product($row) {
        $row = is_array($row) ? $row : array();
        $warehouse_stock = isset($row['warehouse_stock']) && is_array($row['warehouse_stock']) ? $row['warehouse_stock'] : array();

        return array(
            'product_code' => $this->clean_string($row['product_code'] ?? $row['code'] ?? ''),
            'name' => $this->clean_string($row['name'] ?? ''),
            'serial' => $this->clean_string($row['serial'] ?? ''),
            'unit' => $this->clean_string($row['unit'] ?? ''),
            'unit_id' => $this->clean_string($row['unit_id'] ?? ''),
            'sale_price_source' => $this->clean_number($row['sale_price_source'] ?? null),
            'purchase_price_source' => $this->clean_number($row['purchase_price_source'] ?? null),
            'warehouse_stock' => array_map(array($this, 'clean_number'), $warehouse_stock),
            'total_stock' => $this->clean_number($row['total_stock'] ?? $row['stock'] ?? null),
            'minimum_stock' => $this->clean_number($row['minimum_stock'] ?? null),
            'foreign_currency' => strtoupper($this->clean_string($row['foreign_currency'] ?? '')),
            'foreign_price' => $this->clean_number($row['foreign_price'] ?? null),
            'weight_grams' => $this->clean_number($row['weight_grams'] ?? null),
            'location' => $this->clean_string($row['location'] ?? ''),
            'final_price' => $this->clean_number($row['final_price'] ?? null),
            'description' => wp_kses_post((string) ($row['description'] ?? '')),
            'updated_at' => $this->clean_string($row['updated_at'] ?? ''),
            'flags' => isset($row['flags']) && is_array($row['flags']) ? array_values(array_map('sanitize_key', $row['flags'])) : array(),
            'raw' => $row,
        );
    }

    private function normalize_customers($customers) {
        $normalized = array();

        foreach ((array) $customers as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = $this->clean_string($row['customer_code'] ?? $row['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $normalized[$code] = array(
                'customer_code' => $code,
                'name' => $this->clean_string($row['name'] ?? ''),
                'tel' => $this->clean_string($row['tel'] ?? ''),
                'phone' => $this->clean_string($row['phone'] ?? ''),
                'mobile' => $this->clean_string($row['mobile'] ?? ''),
                'email' => sanitize_email($row['email'] ?? ''),
                'address' => $this->clean_string($row['address'] ?? ''),
                'national_code' => $this->clean_string($row['national_code'] ?? ''),
                'postal_code' => $this->clean_string($row['postal_code'] ?? ''),
                'updated_at' => $this->clean_string($row['updated_at'] ?? ''),
                'raw' => $row,
            );
        }

        return $normalized;
    }

    private function apply_product_feed(WC_Product $product, $data) {
        $product->update_meta_data('_digitalogic_patris_product_code', $data['product_code']);
        $product->update_meta_data('_digitalogic_patris_name', $data['name']);
        $product->update_meta_data('_digitalogic_patris_serial', $data['serial']);
        $product->update_meta_data('_digitalogic_patris_unit', $data['unit']);
        $product->update_meta_data('_digitalogic_patris_unit_id', $data['unit_id']);
        $product->update_meta_data('_digitalogic_patris_sale_price_source', $data['sale_price_source']);
        $product->update_meta_data('_digitalogic_patris_purchase_price_source', $data['purchase_price_source']);
        $product->update_meta_data('_digitalogic_patris_warehouse_stock', wp_json_encode($data['warehouse_stock']));
        $product->update_meta_data('_digitalogic_patris_total_stock', $data['total_stock']);
        $product->update_meta_data('_digitalogic_patris_minimum_stock', $data['minimum_stock']);
        $product->update_meta_data('_digitalogic_patris_foreign_currency', $data['foreign_currency']);
        $product->update_meta_data('_digitalogic_patris_foreign_price', $data['foreign_price']);
        $product->update_meta_data('_digitalogic_patris_weight_grams', $data['weight_grams']);
        $product->update_meta_data('_digitalogic_patris_location', $data['location']);
        $product->update_meta_data('_digitalogic_patris_final_price', $data['final_price']);
        $product->update_meta_data('_digitalogic_patris_updated_at', $data['updated_at']);
        $product->update_meta_data('_digitalogic_patris_flags', wp_json_encode($data['flags']));
        $product->update_meta_data('_digitalogic_patris_last_feed', wp_json_encode($data['raw'], JSON_UNESCAPED_UNICODE));

        if ($data['weight_grams'] !== null && $data['weight_grams'] > 0) {
            $product->set_weight((string) ($data['weight_grams'] / 1000));
        }

        if ($data['total_stock'] !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity((int) round($data['total_stock']));
            $product->set_stock_status($data['total_stock'] > 0 ? 'instock' : 'outofstock');
        }

        $final_price = $data['final_price'];
        if ($final_price !== null) {
            $should_zero = $final_price <= 0 || ($data['total_stock'] !== null && $data['total_stock'] <= 0);
            $product->set_regular_price($should_zero ? '0' : (string) $final_price);
            $product->set_price($should_zero ? '0' : (string) $final_price);
            $product->update_meta_data('_digitalogic_patris_price_status', $should_zero ? 'zeroed' : 'priced');
        }

        $product->save();
    }

    private function clean_string($value) {
        return sanitize_text_field(wp_unslash((string) $value));
    }

    private function clean_number($value) {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(array(',', '٬', '،', ' '), '', wp_unslash($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
