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

if (!class_exists('Digitalogic_Unit_Converter')) {
    require_once __DIR__ . '/class-unit-converter.php';
}
if (!class_exists('Digitalogic_Product_Identifier_Resolver')) {
    require_once __DIR__ . '/class-product-identifier-resolver.php';
}

class Digitalogic_Patris_Feed {

    private const SETTINGS_OPTION = 'digitalogic_patris_feed_settings';
    private const PRODUCTS_OPTION = 'digitalogic_patris_feed_products';
    private const CUSTOMERS_OPTION = 'digitalogic_patris_feed_customers';
    private const LAST_SYNC_OPTION = 'digitalogic_patris_feed_last_sync';
    private const TOKEN_OPTION = 'digitalogic_patris_feed_push_token';
    public const PRODUCT_SYNC_SECRET_OPTION = 'digitalogic_product_sync_v1_secret';
    public const PRODUCT_SYNC_SCOPES_OPTION = 'digitalogic_product_sync_v1_source_scopes';

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

        $settings = wp_parse_args($settings, array(
            'api_url' => '',
            'api_token' => '',
            'selected_warehouses' => array(),
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

        unset($settings['shipping_methods']);
        return $settings;
    }

    public function update_settings($settings) {
        $current = $this->get_settings();
        $next = is_array($settings) ? $settings : array();

        // Import freight is now managed by Digitalogic_Import_Freight_Service.
        // Never revive the former unvalidated, free-form shipping_methods blob.
        unset($current['shipping_methods'], $next['shipping_methods']);

        if (isset($next['selected_warehouses']) && is_string($next['selected_warehouses'])) {
            $next['selected_warehouses'] = array_filter(array_map('trim', explode(',', $next['selected_warehouses'])));
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
            // Keep the complete normalized upstream snapshot for reporting and
            // reconciliation even when no unique WooCommerce target exists.
            // Resolution failures below must never turn into product writes.
            $normalized_products[$product_data['product_code']] = $product_data;

            $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(array(
                'code' => $product_data['product_code'],
            ));
            if (is_wp_error($resolved)) {
                if ('digitalogic_product_identifier_not_found' === $resolved->get_error_code()) {
                    $results['missing_in_woocommerce']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = 'digitalogic_product_identifier_ambiguous' === $resolved->get_error_code()
                        ? __('Skipped product because its exact Code/SKU is ambiguous.', 'digitalogic')
                        : __('Skipped product because its Code/SKU could not be resolved.', 'digitalogic');
                }
                continue;
            }

            $product_id = (int) $resolved['woocommerce_id'];

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

    /**
     * Return the dedicated v1 receiver secret.
     *
     * This credential is intentionally independent of the legacy push token.
     */
    public function get_product_sync_secret() {
        $secret = (string) get_option(self::PRODUCT_SYNC_SECRET_OPTION, '');
        if ('' !== $secret) {
            return $secret;
        }

        $generated = wp_generate_password(64, false, false);
        if (add_option(self::PRODUCT_SYNC_SECRET_OPTION, $generated, '', 'no')) {
            return $generated;
        }

        return (string) get_option(self::PRODUCT_SYNC_SECRET_OPTION, '');
    }

    /**
     * Return normalized exact source scopes for the v1 secret.
     *
     * An empty list is deliberately unscoped for backwards-compatible setup;
     * once configured, every request must match one exact {id,dataset} pair.
     */
    public function get_product_sync_source_scopes() {
        $configured = get_option(self::PRODUCT_SYNC_SCOPES_OPTION, array());
        $scopes = array();
        foreach ((array) $configured as $scope) {
            if (!is_array($scope)) {
                continue;
            }
            $id = isset($scope['id']) && is_string($scope['id']) ? trim($scope['id']) : '';
            $dataset = isset($scope['dataset']) && is_string($scope['dataset']) ? trim($scope['dataset']) : '';
            if ('' === $id || '' === $dataset || strlen($id) > 191 || strlen($dataset) > 191) {
                continue;
            }
            $scopes[$id . "\n" . $dataset] = array('id' => $id, 'dataset' => $dataset);
        }

        ksort($scopes, SORT_STRING);
        return array_values($scopes);
    }

    /**
     * Authenticate the versioned receiver with its dedicated header-only
     * secret and, when configured, an exact source ID/dataset scope.
     *
     * @param WP_REST_Request $request Current request.
     * @return bool
     */
    public function verify_product_sync_request(WP_REST_Request $request) {
        $expected = $this->get_product_sync_secret();
        $provided = $request->get_header('x-digitalogic-product-sync-secret');

        if (!is_string($provided) || '' === $provided || '' === $expected || !hash_equals($expected, $provided)) {
            return false;
        }

        $configured_scopes = get_option(self::PRODUCT_SYNC_SCOPES_OPTION, array());
        $scopes = $this->get_product_sync_source_scopes();
        if (empty($configured_scopes)) {
            return true;
        }
        if (empty($scopes)) {
            return false;
        }

        $payload = $request->get_json_params();
        $source = is_array($payload) && isset($payload['source']) && is_array($payload['source'])
            ? $payload['source']
            : array();
        $source_id = isset($source['id']) && is_string($source['id']) ? $source['id'] : '';
        $dataset = isset($source['dataset']) && is_string($source['dataset']) ? $source['dataset'] : '';
        foreach ($scopes as $scope) {
            if (hash_equals($scope['id'], $source_id) && hash_equals($scope['dataset'], $dataset)) {
                return true;
            }
        }

        return false;
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

    /**
     * Apply a normalized Patris product through the shared WooCommerce writer.
     *
     * Both the legacy feed and the versioned transformed-only receiver use this
     * method so stock, weight, pricing, and Patris metadata cannot drift into
     * parallel implementations. Canonical callers do not pass a raw payload.
     *
     * @param WC_Product $product WooCommerce product.
     * @param array      $data    Validated normalized product.
     * @return void
     */
    public function apply_product_feed(WC_Product $product, $data) {
        $product->update_meta_data('_digitalogic_patris_product_code', $data['product_code']);
        $product->update_meta_data('_digitalogic_patris_name', $data['name']);
        $product->update_meta_data('_digitalogic_patris_serial', $data['serial']);
        $product->update_meta_data('_digitalogic_patris_unit', $data['unit']);
        $product->update_meta_data('_digitalogic_patris_unit_id', $data['unit_id'] ?? '');
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
        $product->update_meta_data(
            '_digitalogic_patris_updated_at',
            isset($data['source_updated_at']) ? $data['source_updated_at'] : ($data['updated_at'] ?? '')
        );
        if (array_key_exists('flags', $data)) {
            $product->update_meta_data('_digitalogic_patris_flags', wp_json_encode($data['flags']));
        }
        if (array_key_exists('raw', $data)) {
            $product->update_meta_data('_digitalogic_patris_last_feed', wp_json_encode($data['raw'], JSON_UNESCAPED_UNICODE));
        }

        $canonical_meta = array(
            'category_code' => '_digitalogic_patris_category_code',
            'import_freight_method_id' => '_digitalogic_patris_import_freight_method_id',
            'freight_cny_per_kg' => '_digitalogic_patris_freight_cny_per_kg',
            'markup_percent' => '_digitalogic_patris_markup_percent',
            'irt_per_cny' => '_digitalogic_patris_irt_per_cny',
            'pricing_catalog_revision' => '_digitalogic_patris_pricing_catalog_revision',
            'pricing_catalog_status' => '_digitalogic_patris_pricing_catalog_status',
            'currency_effective_date' => '_digitalogic_patris_currency_effective_date',
            'formula_version' => '_digitalogic_patris_formula_version',
            'record_hash' => '_digitalogic_patris_record_hash',
        );
        foreach ($canonical_meta as $field => $meta_key) {
            if (array_key_exists($field, $data)) {
                $product->update_meta_data($meta_key, $data[$field]);
            }
        }
        if (array_key_exists('warnings', $data)) {
            $product->update_meta_data('_digitalogic_patris_warnings', wp_json_encode($data['warnings']));
        }

        $store_weight = Digitalogic_Unit_Converter::grams_to_store_weight($data['weight_grams']);
        if (!is_null($store_weight)) {
            $product->set_weight((string) $store_weight);
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
