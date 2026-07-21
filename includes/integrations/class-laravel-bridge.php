<?php
/**
 * Token-authenticated bridge for a Laravel panel.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Laravel_Bridge {

    private const TOKEN_OPTION = 'digitalogic_laravel_panel_token';
    private const PANEL_URL_OPTION = 'digitalogic_laravel_panel_url';
    private const LOCAL_APP_PATH_OPTION = 'digitalogic_laravel_app_path';
    private const HANDOFF_PREFIX = 'digitalogic_panel_handoff_';
    private const HANDOFF_TTL = 120;

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_post_digitalogic_laravel_panel_launch', array($this, 'handle_panel_launch'));
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

        register_rest_route('digitalogic-panel/v1', '/session/consume', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'consume_session_handoff'),
            'args' => array(
                'code' => array('required' => true),
            ),
        ));

        register_rest_route('digitalogic-panel/v1', '/theme', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'get_theme'),
        ));

        register_rest_route('digitalogic-panel/v1', '/laravel/status', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'get_laravel_status'),
        ));

        register_rest_route('digitalogic-panel/v1', '/laravel/request', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'authorize_request'),
            'callback' => array($this, 'run_laravel_request'),
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

    public function handle_panel_launch() {
        check_admin_referer('digitalogic_laravel_panel_launch');

        if ( ! Digitalogic_Access_Control::can_access_panel() ) {
            Digitalogic_Panel_Error_Page::render( 403, 'panel-access-denied' );
            exit;
        }

        $return_to = isset($_GET['return_to']) ? esc_url_raw(wp_unslash($_GET['return_to'])) : '';
        $code = $this->create_session_handoff(get_current_user_id(), $return_to);

        wp_redirect($this->get_panel_auth_url(array(
            'code' => $code,
            'return_to' => $return_to,
        )));
        exit;
    }

    public function consume_session_handoff(WP_REST_Request $request) {
        $code = (string) $request->get_param('code');
        $key = $this->handoff_key($code);
        $handoff = get_transient($key);

        if (!is_array($handoff)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid or expired WordPress session handoff.',
            ), 401);
        }

        delete_transient($key);

        $user = get_user_by('id', (int) $handoff['user_id']);
        if (!$user) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'WordPress user no longer exists.',
            ), 404);
        }

        wp_set_current_user($user->ID);

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'user' => $this->format_user($user),
                'return_to' => $handoff['return_to'],
                'issued_at' => $handoff['issued_at'],
                'expires_at' => $handoff['expires_at'],
                'wordpress' => array(
                    'site_url' => site_url(),
                    'home_url' => home_url(),
                    'cookie_domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                    'logged_in_cookie' => defined('LOGGED_IN_COOKIE') ? LOGGED_IN_COOKIE : '',
                    'secure_auth_cookie' => defined('SECURE_AUTH_COOKIE') ? SECURE_AUTH_COOKIE : '',
                ),
                'theme' => $this->theme_payload(),
            ),
        ));
    }

    public function get_theme() {
        return rest_ensure_response(array(
            'success' => true,
            'data' => $this->theme_payload(),
        ));
    }

    public function get_laravel_status() {
        return rest_ensure_response(array(
            'success' => true,
            'data' => $this->local_laravel_status(),
        ));
    }

    public function run_laravel_request(WP_REST_Request $request) {
        $body = (array) $request->get_json_params();
        $path = isset($body['path']) ? (string) $body['path'] : '/';
        $method = isset($body['method']) ? (string) $body['method'] : 'GET';
        $payload = isset($body['data']) && is_array($body['data']) ? $body['data'] : array();

        $result = $this->call_local_laravel($path, $method, $payload);
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();
            $status = is_array($error_data) && isset($error_data['status']) ? (int) $error_data['status'] : 503;

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

    private function create_session_handoff($user_id, $return_to = '') {
        $code = wp_generate_password(48, false, false);
        $now = time();
        $user = get_user_by('id', (int) $user_id);

        set_transient($this->handoff_key($code), array(
            'user_id' => (int) $user_id,
            'user_login' => $user ? $user->user_login : '',
            'return_to' => $return_to,
            'issued_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + self::HANDOFF_TTL),
        ), self::HANDOFF_TTL);

        return $code;
    }

    private function handoff_key($code) {
        return self::HANDOFF_PREFIX . md5((string) $code);
    }

    private function format_user(WP_User $user) {
        return array(
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => array_values((array) $user->roles),
            'capabilities' => array(
                'manage_woocommerce' => user_can($user, 'manage_woocommerce'),
                'manage_options' => user_can($user, 'manage_options'),
                'edit_users' => user_can($user, 'edit_users'),
                'list_users' => user_can($user, 'list_users'),
            ),
        );
    }

    private function theme_payload() {
        $site_icon = get_site_icon_url(192);
        $logo_id = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        return apply_filters('digitalogic_laravel_panel_theme', array(
            'name' => 'Digitalogic',
            'direction' => is_rtl() ? 'rtl' : 'ltr',
            'locale' => get_locale(),
            'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'logo_url' => $logo_url ?: $site_icon,
            'site_icon_url' => $site_icon,
            'shared_ui_base_url' => home_url('/digitalogic-ui/'),
            'colors' => array(
                'primary' => '#2271b1',
                'surface' => '#ffffff',
                'surface_muted' => '#f6f7f7',
                'border' => '#c3c4c7',
                'text' => '#1d2327',
                'success' => '#46b450',
                'warning' => '#f0b849',
                'danger' => '#dc3232',
            ),
        ));
    }

    public function get_panel_url($path = '', $args = array()) {
        $base = (string) get_option(self::PANEL_URL_OPTION, home_url('/panel/'));
        $base = untrailingslashit(apply_filters('digitalogic_laravel_panel_url', $base));
        $path = '/' . ltrim((string) $path, '/');
        $url = $base . ($path === '/' ? '' : $path);

        return $args ? add_query_arg(array_filter($args), $url) : $url;
    }

    public function get_panel_auth_url($args = array()) {
        $panel_url = $this->get_panel_url();
        $panel_host = wp_parse_url($panel_url, PHP_URL_HOST);
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        if ($panel_host && $home_host && strtolower($panel_host) === strtolower($home_host)) {
            return add_query_arg(array_filter($args), trailingslashit($panel_url));
        }

        return $this->get_panel_url('/auth/wordpress', $args);
    }

    public function call_local_laravel($path, $method = 'GET', $payload = array()) {
        $app = $this->boot_local_laravel();
        if (is_wp_error($app)) {
            return $app;
        }

        if (!class_exists('\\Illuminate\\Http\\Request')) {
            return new WP_Error('digitalogic_laravel_request_missing', __('Laravel HTTP request class is not available.', 'digitalogic'), array('status' => 503));
        }

        $path = '/' . ltrim((string) $path, '/');
        $method = strtoupper((string) $method);
        $request = \Illuminate\Http\Request::create($path, $method, $payload);
        $request->headers->set('X-WordPress-User-ID', (string) get_current_user_id());
        $request->headers->set('X-WordPress-User-Login', (string) wp_get_current_user()->user_login);

        $kernel_class = '\\Illuminate\\Contracts\\Http\\Kernel';
        if (!method_exists($app, 'make') || !interface_exists($kernel_class)) {
            return new WP_Error('digitalogic_laravel_kernel_missing', __('Laravel HTTP kernel is not available.', 'digitalogic'), array('status' => 503));
        }

        $kernel = $app->make($kernel_class);
        $response = $kernel->handle($request);
        $content = method_exists($response, 'getContent') ? $response->getContent() : '';
        $decoded = json_decode((string) $content, true);

        if (method_exists($kernel, 'terminate')) {
            $kernel->terminate($request, $response);
        }

        return array(
            'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
            'body' => json_last_error() === JSON_ERROR_NONE ? $decoded : $content,
        );
    }

    public function get_launch_url($return_to = '') {
        return wp_nonce_url(add_query_arg(array(
            'action' => 'digitalogic_laravel_panel_launch',
            'return_to' => $return_to,
        ), admin_url('admin-post.php')), 'digitalogic_laravel_panel_launch');
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

    private function local_laravel_status() {
        $path = $this->get_local_laravel_path();

        return array(
            'configured' => $path !== '',
            'path' => $path,
            'available' => $path !== '' && file_exists($path . '/bootstrap/app.php'),
        );
    }

    private function boot_local_laravel() {
        $status = $this->local_laravel_status();
        if (!$status['available']) {
            return new WP_Error('digitalogic_laravel_unavailable', __('No local Laravel app is configured for direct loading.', 'digitalogic'), array('status' => 503));
        }

        return require $status['path'] . '/bootstrap/app.php';
    }

    private function get_local_laravel_path() {
        $path = (string) get_option(self::LOCAL_APP_PATH_OPTION, '');
        $path = (string) apply_filters('digitalogic_laravel_app_path', $path);
        $path = untrailingslashit($path);

        return $path !== '' ? $path : '';
    }
}
