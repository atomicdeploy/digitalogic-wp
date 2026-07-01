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
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Products endpoints
        register_rest_route('digitalogic/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/sku/(?P<sku>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_by_sku'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_product'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/sku/(?P<sku>[^/]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_product_by_sku'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)/metadata', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_metadata'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/sku/(?P<sku>[^/]+)/metadata', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product_metadata_by_sku'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/batch', array(
            'methods' => 'POST',
            'callback' => array($this, 'batch_update_products'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // Currency endpoints
        register_rest_route('digitalogic/v1', '/currency', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_currency'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/currency', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_currency'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // Pricing endpoints
        register_rest_route('digitalogic/v1', '/pricing/recalculate', array(
            'methods' => 'POST',
            'callback' => array($this, 'recalculate_prices'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // Export endpoint
        register_rest_route('digitalogic/v1', '/export', array(
            'methods' => 'GET',
            'callback' => array($this, 'export_products'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }
    
    /**
     * Check API permission
     */
    public function check_permission() {
        // Check if user is logged in with proper capabilities
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        
        // Check for WooCommerce REST API authentication
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // WooCommerce handles its own authentication
            return apply_filters('digitalogic_rest_api_permission', current_user_can('read'));
        }
        
        return false;
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
     * GET /products/sku/{sku}
     */
    public function get_product_by_sku(WP_REST_Request $request) {
        $sku = $request['sku'];
        
        $manager = Digitalogic_Product_Manager::instance();
        $product = $manager->get_product_by_sku($sku);
        
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
     * GET /products/{id}/metadata
     */
    public function get_product_metadata(WP_REST_Request $request) {
        $product_id = $request['id'];
        
        $manager = Digitalogic_Product_Manager::instance();
        $metadata = $manager->get_product_metadata($product_id);
        
        if (is_wp_error($metadata)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $metadata->get_error_message()
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $metadata
        ), 200);
    }
    
    /**
     * GET /products/sku/{sku}/metadata
     */
    public function get_product_metadata_by_sku(WP_REST_Request $request) {
        $sku = $request['sku'];
        
        $manager = Digitalogic_Product_Manager::instance();
        $metadata = $manager->get_product_metadata(null, $sku);
        
        if (is_wp_error($metadata)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $metadata->get_error_message()
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $metadata
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
     * PUT /products/sku/{sku}
     */
    public function update_product_by_sku(WP_REST_Request $request) {
        $sku = $request['sku'];
        $data = $request->get_json_params();
        
        $manager = Digitalogic_Product_Manager::instance();
        $result = $manager->update_product(null, $data, $sku);
        
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
}
