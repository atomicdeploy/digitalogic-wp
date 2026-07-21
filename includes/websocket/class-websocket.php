<?php
/**
 * WebSocket client configuration and REST token route.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_WebSocket {

    private const CLIENT_TOKEN_PREFIX = 'digitalogic_ws_client_';
    private const CLIENT_TOKEN_TTL = 3600;
    private const PUBLIC_TOKEN_PREFIX = 'digitalogic_ws_public_';
    private const PUBLIC_TOKEN_TTL = 900;

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_proxy'));
    }

    public function register_routes() {
        register_rest_route('digitalogic/v1', '/websocket/config', array(
            'methods' => 'GET',
            'permission_callback' => array($this, 'check_permission'),
            'callback' => array($this, 'get_config_response'),
        ));
    }

    public function check_permission() {
        return Digitalogic_Access_Control::can_access_panel();
    }

    public function get_config_response() {
        return rest_ensure_response($this->get_client_config());
    }

    public function get_client_config() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : wp_parse_url(home_url(), PHP_URL_HOST);
        $scheme = is_ssl() ? 'wss' : 'ws';
        $default_url = $scheme . '://' . $host . '/wordpress-ws';

        return apply_filters('digitalogic_websocket_client_config', array(
            'enabled' => (bool) apply_filters('digitalogic_websocket_enabled', true),
            'url' => $default_url,
            'nonce' => wp_create_nonce('digitalogic_ws'),
            'token' => Digitalogic_Access_Control::can_access_panel() ? self::create_client_token(get_current_user_id()) : '',
            'reconnect_interval' => 3000,
            'request_timeout' => 15000,
            'ajax_proxy_enabled' => (bool) apply_filters('digitalogic_websocket_ajax_proxy_enabled', true),
            'ajax_proxy_excluded_actions' => apply_filters('digitalogic_websocket_ajax_proxy_excluded_actions', array(
                'heartbeat',
                'wp-auth-check',
                'upload-attachment',
            )),
        ));
    }

    public function get_public_client_config() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : wp_parse_url(home_url(), PHP_URL_HOST);
        $scheme = is_ssl() ? 'wss' : 'ws';
        $default_url = $scheme . '://' . $host . '/wordpress-ws';

        return apply_filters('digitalogic_websocket_public_client_config', array(
            'enabled' => (bool) apply_filters('digitalogic_websocket_public_enabled', true),
            'url' => $default_url,
            'nonce' => wp_create_nonce('digitalogic_ws_public'),
            'token' => self::create_public_token(),
            'reconnect_interval' => 3000,
            'request_timeout' => 8000,
        ));
    }

    public function enqueue_admin_proxy() {
        if ( ! Digitalogic_Access_Control::can_access_panel() ) {
            return;
        }

        wp_enqueue_script(
            'digitalogic-websocket-ajax-proxy',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/ajax-proxy.js',
            array('jquery'),
            DIGITALOGIC_VERSION,
            true
        );

        wp_localize_script('digitalogic-websocket-ajax-proxy', 'digitalogicWs', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'websocket' => $this->get_client_config(),
        ));
    }

    public static function get_server_token() {
        $token = (string) get_option('digitalogic_websocket_server_token', '');
        if ($token === '') {
            $token = wp_generate_password(64, false, false);
            add_option('digitalogic_websocket_server_token', $token, '', 'no');
        }

        return $token;
    }

    public static function create_client_token($user_id) {
        $token = wp_generate_password(48, false, false);
        set_transient(self::CLIENT_TOKEN_PREFIX . md5($token), array(
            'user_id' => (int) $user_id,
            'expires_at' => time() + self::CLIENT_TOKEN_TTL,
        ), self::CLIENT_TOKEN_TTL);

        return $token;
    }

    public static function validate_client_token($token) {
        $data = get_transient(self::CLIENT_TOKEN_PREFIX . md5((string) $token));
        if (!is_array($data) || empty($data['user_id'])) {
            return 0;
        }

        $user_id = (int) $data['user_id'];
        wp_set_current_user($user_id);

        return Digitalogic_Access_Control::can_access_panel() ? $user_id : 0;
    }

    public static function create_public_token() {
        $token = wp_generate_password(40, false, false);
        set_transient(self::PUBLIC_TOKEN_PREFIX . md5($token), array(
            'expires_at' => time() + self::PUBLIC_TOKEN_TTL,
        ), self::PUBLIC_TOKEN_TTL);

        return $token;
    }

    public static function validate_public_token($token) {
        $data = get_transient(self::PUBLIC_TOKEN_PREFIX . md5((string) $token));

        return is_array($data) && !empty($data['expires_at']) && (int) $data['expires_at'] >= time();
    }
}
