<?php
/**
 * Front-end search acceleration over the Digitalogic WebSocket transport.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Frontend_Search {

    private static $instance = null;

    private $public_actions = array(
        'woodmart_ajax_search',
    );

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 100);
        add_filter('digitalogic_command_requires_auth', array($this, 'allow_public_search_command'), 10, 4);
        add_filter('digitalogic_websocket_ajax_action_allowed', array($this, 'allow_public_ajax_search_action'), 10, 4);
    }

    public function enqueue_scripts() {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        wp_enqueue_script(
            'digitalogic-frontend-search-ws',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/frontend-search-ws.js',
            array('jquery'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/frontend-search-ws.js') ?: DIGITALOGIC_VERSION,
            true
        );

        wp_localize_script('digitalogic-frontend-search-ws', 'digitalogicFrontendSearchWs', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'websocket' => Digitalogic_WebSocket::instance()->get_public_client_config(),
            'actions' => apply_filters('digitalogic_frontend_search_websocket_actions', $this->public_actions),
            'fallback_delay' => (int) apply_filters('digitalogic_frontend_search_websocket_fallback_delay', 1200),
        ));
    }

    public function allow_public_search_command($requires_auth, $command, $payload, $transport) {
        if ($transport === 'websocket' && in_array($command, $this->public_actions, true)) {
            return false;
        }

        return $requires_auth;
    }

    public function allow_public_ajax_search_action($allowed, $command, $payload, $transport) {
        if ($transport === 'websocket' && in_array($command, $this->public_actions, true)) {
            return true;
        }

        return $allowed;
    }
}
