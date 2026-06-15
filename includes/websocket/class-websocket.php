<?php
/**
 * WebSocket client configuration and REST token route.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_WebSocket {

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
        return current_user_can('manage_woocommerce');
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

    public function enqueue_admin_proxy() {
        if (!current_user_can('manage_woocommerce')) {
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
}
