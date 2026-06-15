<?php
/**
 * Token-authenticated bridge for a Laravel panel.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Laravel_Bridge {

    private const TOKEN_OPTION = 'digitalogic_laravel_panel_token';

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

    public function register_routes() {
        register_rest_route('digitalogic-panel/v1', '/products', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'get_products'),
        ));

        register_rest_route('digitalogic-panel/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'get_product'),
        ));

        register_rest_route('digitalogic-panel/v1', '/products/(?P<id>\d+)', array(
            'methods' => array('POST', 'PUT', 'PATCH'),
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'update_product'),
        ));

        register_rest_route('digitalogic-panel/v1', '/commands', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'run_command'),
        ));
    }

    public function authorize_request(WP_REST_Request $request) {
        $expected = self::get_token();
        $provided = (string) $request->get_header('x-digitalogic-panel-token');
        if ($provided === '') {
            $provided = (string) $request->get_param('token');
        }

        if ($expected === '' || !hash_equals($expected, $provided)) {
            return false;
        }

        return $this->set_integration_user();
    }

    public function get_products(WP_REST_Request $request) {
        return $this->rest_response(Digitalogic_Command_Dispatcher::instance()->execute(
            'digitalogic_get_products',
            $request->get_params(),
            'laravel'
        ));
    }

    public function get_product(WP_REST_Request $request) {
        return $this->rest_response(Digitalogic_Command_Dispatcher::instance()->execute(
            'digitalogic_get_product',
            array('product_id' => (int) $request['id']),
            'laravel'
        ));
    }

    public function update_product(WP_REST_Request $request) {
        return $this->rest_response(Digitalogic_Command_Dispatcher::instance()->execute(
            'digitalogic_update_product',
            array(
                'product_id' => (int) $request['id'],
                'data' => (array) $request->get_json_params(),
            ),
            'laravel'
        ));
    }

    public function run_command(WP_REST_Request $request) {
        $body = (array) $request->get_json_params();
        $command = isset($body['command']) ? Digitalogic_Command_Dispatcher::normalize_command_name($body['command']) : '';
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : array();

        return $this->rest_response(Digitalogic_Command_Dispatcher::instance()->execute($command, $data, 'laravel'));
    }

    private function rest_response($result) {
        if (is_wp_error($result)) {
            $status = 400;
            $data = $result->get_error_data();
            if (is_array($data) && isset($data['status'])) {
                $status = (int) $data['status'];
            }

            return new WP_REST_Response(array(
                'success' => false,
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ), $status);
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => $result,
        ));
    }

    private function set_integration_user() {
        $users = get_users(array(
            'role' => 'administrator',
            'fields' => 'ID',
        ));

        foreach ($users as $user_id) {
            if (user_can((int) $user_id, 'manage_woocommerce')) {
                wp_set_current_user((int) $user_id);
                return true;
            }
        }

        return false;
    }

    public static function get_token() {
        $token = (string) get_option(self::TOKEN_OPTION, '');
        if ($token === '') {
            $token = wp_generate_password(64, false, false);
            add_option(self::TOKEN_OPTION, $token, '', 'no');
        }

        return $token;
    }

    public static function rotate_token() {
        $token = wp_generate_password(64, false, false);
        update_option(self::TOKEN_OPTION, $token, false);

        return $token;
    }
}
