<?php
/**
 * REST API Class
 * 
 * Provides REST API endpoints for external integrations
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_REST_API {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter(
            'woocommerce_rest_is_request_to_rest_api',
            array($this, 'allow_woocommerce_rest_authentication')
        );
    }

    /**
     * Let WooCommerce authenticate API keys for this plugin's REST namespace.
     *
     * WooCommerce only recognizes its own wc/* and wc-* namespaces by default.
     * Preserve requests already recognized by WooCommerce and opt in only the
     * exact digitalogic/v1 namespace (including installations in a subdirectory
     * or using the rest_route query parameter).
     *
     * @param bool $is_request Whether WooCommerce already recognizes the request.
     * @return bool
     */
    public function allow_woocommerce_rest_authentication($is_request) {
        if ($is_request) {
            return true;
        }

        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
        $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
        $api_url = rest_url('digitalogic/v1');
        $api_path = wp_parse_url($api_url, PHP_URL_PATH);
        $api_query = wp_parse_url($api_url, PHP_URL_QUERY);

        if (is_string($api_query) && '' !== $api_query) {
            parse_str($api_query, $api_query_params);
            if (isset($api_query_params['rest_route'])) {
                if (!is_string($request_path) || !is_string($api_path)) {
                    return false;
                }

                if (rtrim($request_path, '/') !== rtrim($api_path, '/')) {
                    return false;
                }

                return $this->query_targets_digitalogic_namespace($request_uri);
            }
        }

        if (is_string($request_path) && is_string($api_path) && '/' !== $api_path) {
            $api_path = rtrim($api_path, '/');
            if ($request_path === $api_path || str_starts_with($request_path, $api_path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check a rest_route query parameter against the Digitalogic namespace.
     *
     * @param string $request_uri Current request URI.
     * @return bool
     */
    private function query_targets_digitalogic_namespace($request_uri) {

        $request_query = wp_parse_url($request_uri, PHP_URL_QUERY);
        if (!is_string($request_query) || '' === $request_query) {
            return false;
        }

        parse_str($request_query, $query_params);
        if (!isset($query_params['rest_route']) || !is_string($query_params['rest_route'])) {
            return false;
        }

        $route = '/' . trim($query_params['rest_route'], '/');

        return '/digitalogic/v1' === $route || str_starts_with($route, '/digitalogic/v1/');
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Products endpoints
        register_rest_route('digitalogic/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_read_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product'),
            'permission_callback' => array($this, 'check_read_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_product'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/batch', array(
            'methods' => 'POST',
            'callback' => array($this, 'batch_update_products'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Currency endpoints
        register_rest_route('digitalogic/v1', '/currency', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_currency'),
            'permission_callback' => array($this, 'check_read_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/currency', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_currency'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Pricing endpoints
        register_rest_route('digitalogic/v1', '/pricing/recalculate', array(
            'methods' => 'POST',
            'callback' => array($this, 'recalculate_prices'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Export endpoint
        register_rest_route('digitalogic/v1', '/export', array(
            'methods' => 'GET',
            'callback' => array($this, 'export_products'),
            'permission_callback' => array($this, 'check_diagnostic_permission')
        ));

        register_rest_route('digitalogic/v1', '/reports', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_reports'),
            'permission_callback' => array($this, 'check_diagnostic_permission')
        ));

        register_rest_route('digitalogic/v1', '/patris/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_patris'),
            'permission_callback' => array($this, 'check_write_permission')
        ));

        register_rest_route('digitalogic/v1', '/patris/push', array(
            'methods' => 'POST',
            'callback' => array($this, 'push_patris'),
            'permission_callback' => array($this, 'check_patris_push_permission')
        ));
    }
    
    /**
     * Backward-compatible permission check for existing integrations.
     *
     * This alias deliberately uses the write scope, matching the broad access
     * historically associated with this method without restoring read-level
     * authorization for management routes.
     *
     * @deprecated Use check_read_permission(), check_write_permission(), or
     *             check_diagnostic_permission() for an explicit route scope.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_permission($request = null) {
        return $this->check_write_permission($request);
    }

    /**
     * Check access to read-only catalog and currency routes.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_read_permission($request = null) {
        return $this->check_scoped_permission('read', $request);
    }

    /**
     * Check access to routes that mutate products, settings, or sync state.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_write_permission($request = null) {
        return $this->check_scoped_permission('write', $request);
    }

    /**
     * Check access to reports and exports that expose operational data.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_diagnostic_permission($request = null) {
        return $this->check_scoped_permission('diagnostic', $request);
    }

    /**
     * Resolve a REST permission scope.
     *
     * The legacy filter remains available for explicit integrations. Its
     * default is deliberately false; callbacks must return the boolean true.
     * The second argument lets integrations grant only the required scope.
     *
     * @param string               $scope   One of read, write, or diagnostic.
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    private function check_scoped_permission($scope, $request = null) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        /**
         * Filter access to a Digitalogic REST API scope.
         *
         * @param bool                 $allowed Denied by default.
         * @param string               $scope   Permission scope.
         * @param WP_REST_Request|null $request Current REST request.
         */
        $allowed = apply_filters('digitalogic_rest_api_permission', false, $scope, $request);

        return true === $allowed;
    }

    public function check_patris_push_permission(WP_REST_Request $request) {
        return Digitalogic_Patris_Feed::instance()->verify_push_request($request);
    }
    
    /**
     * GET /products
     */
    public function get_products(WP_REST_Request $request) {
        $params = $request->get_params();
        
        $args = array(
            'page' => isset($params['page']) ? intval($params['page']) : 1,
            'limit' => isset($params['limit']) ? intval($params['limit']) : 50,
            'search' => isset($params['search']) ? sanitize_text_field($params['search']) : '',
            'sku' => isset($params['sku']) ? sanitize_text_field($params['sku']) : '',
        );
        
        $manager = Digitalogic_Product_Manager::instance();
        $products = $manager->get_products($args);
        $total = $manager->get_product_count();
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $products,
            'total' => $total,
            'page' => $args['page'],
            'limit' => $args['limit']
        ), 200);
    }
    
    /**
     * GET /products/{id}
     */
    public function get_product(WP_REST_Request $request) {
        $product_id = $request['id'];
        
        $manager = Digitalogic_Product_Manager::instance();
        $product = $manager->get_product($product_id);
        
        if (!$product) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Product not found'
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $product
        ), 200);
    }
    
    /**
     * PUT /products/{id}
     */
    public function update_product(WP_REST_Request $request) {
        $product_id = $request['id'];
        $data = $request->get_json_params();
        
        $manager = Digitalogic_Product_Manager::instance();
        $result = $manager->update_product($product_id, $data);
        
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ), 400);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Product updated successfully'
        ), 200);
    }
    
    /**
     * POST /products/batch
     */
    public function batch_update_products(WP_REST_Request $request) {
        $updates = $request->get_json_params();
        
        if (empty($updates)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No updates provided'
            ), 400);
        }
        
        $manager = Digitalogic_Product_Manager::instance();
        $results = $manager->bulk_update($updates);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $results
        ), 200);
    }
    
    /**
     * GET /currency
     */
    public function get_currency(WP_REST_Request $request) {
        $options = Digitalogic_Options::instance();
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'dollar_price' => $options->get_dollar_price(),
                'yuan_price' => $options->get_yuan_price(),
                'update_date' => $options->get_update_date()
            )
        ), 200);
    }
    
    /**
     * POST /currency
     */
    public function update_currency(WP_REST_Request $request) {
        $data = $request->get_json_params();
        
        $options = Digitalogic_Options::instance();
        
        if (isset($data['dollar_price'])) {
            $options->set_dollar_price($data['dollar_price']);
        }
        
        if (isset($data['yuan_price'])) {
            $options->set_yuan_price($data['yuan_price']);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Currency rates updated'
        ), 200);
    }
    
    /**
     * POST /pricing/recalculate
     */
    public function recalculate_prices(WP_REST_Request $request) {
        $pricing = Digitalogic_Pricing::instance();
        $results = $pricing->bulk_recalculate_prices();
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $results
        ), 200);
    }
    
    /**
     * GET /export
     */
    public function export_products(WP_REST_Request $request) {
        $format = $request->get_param('format') ?: 'json';
        $product_ids = $request->get_param('product_ids') ?: array();
        
        $import_export = Digitalogic_Import_Export::instance();
        
        if ($format === 'csv') {
            $filepath = $import_export->export_csv($product_ids);
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel($product_ids);
        } else {
            $filepath = $import_export->export_json($product_ids);
        }
        
        if (is_wp_error($filepath)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $filepath->get_error_message()
            ), 500);
        }
        
        $upload_dir = wp_upload_dir();
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $filepath);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'url' => $file_url,
                'format' => $format
            )
        ), 200);
    }

    public function get_reports(WP_REST_Request $request) {
        return new WP_REST_Response(array(
            'success' => true,
            'data' => Digitalogic_Report_Engine::instance()->get_report($request->get_params())
        ), 200);
    }

    public function sync_patris(WP_REST_Request $request) {
        $result = Digitalogic_Patris_Feed::instance()->pull_sync();
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ), 400);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result
        ), 200);
    }

    public function push_patris(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $result = Digitalogic_Patris_Feed::instance()->import_payload(is_array($payload) ? $payload : array(), 'push');
        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message()
            ), 400);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result
        ), 200);
    }
}
