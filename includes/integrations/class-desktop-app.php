<?php
/**
 * Desktop app integration for the frameless Digitalogic shell.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Desktop_App {

    private const COOKIE = 'digitalogic_desktop';
    private const VERSION = '1.0.0';

    public static function init() {
        static $instance = null;

        if ($instance === null) {
            $instance = new self();
        }

        return $instance;
    }

    private function __construct() {
        add_action('init', array($this, 'remember_desktop_context'), 1);
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_assets'));
        add_filter('login_body_class', array($this, 'login_body_class'));
        add_filter('login_headertext', array($this, 'login_headertext'));
        add_filter('login_message', array($this, 'login_message'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_panel_assets'), 100);
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('send_headers', array($this, 'send_desktop_headers'));
    }

    public function remember_desktop_context() {
        if (!$this->is_desktop_request()) {
            return;
        }

        if (!headers_sent()) {
            setcookie(self::COOKIE, '1', time() + MONTH_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    public function enqueue_login_assets() {
        if (!$this->is_desktop_request()) {
            return;
        }

        wp_enqueue_style(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/css/desktop-app.css',
            array(),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/css/desktop-app.css') ?: DIGITALOGIC_VERSION
        );

        wp_enqueue_script(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/desktop-app.js',
            array(),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/desktop-app.js') ?: DIGITALOGIC_VERSION,
            true
        );
    }

    public function enqueue_panel_assets() {
        if (!$this->is_desktop_request() || !$this->is_panel_request()) {
            return;
        }

        wp_enqueue_style(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/css/desktop-app.css',
            array('digitalogic-panel'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/css/desktop-app.css') ?: DIGITALOGIC_VERSION
        );

        wp_enqueue_script(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/desktop-app.js',
            array('digitalogic-panel'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/desktop-app.js') ?: DIGITALOGIC_VERSION,
            true
        );
    }

    public function login_body_class($classes) {
        if ($this->is_desktop_request()) {
            $classes[] = 'digitalogic-desktop-login';
        }

        return $classes;
    }

    public function login_headertext($text) {
        return $this->is_desktop_request() ? __('Digitalogic Desktop', 'digitalogic') : $text;
    }

    public function login_message($message) {
        if (!$this->is_desktop_request()) {
            return $message;
        }

        return '<div class="digitalogic-desktop-login-message">' .
            esc_html__('Sign in with your normal Digitalogic account to continue in the desktop app.', 'digitalogic') .
            '</div>' . $message;
    }

    public function register_rest_routes() {
        register_rest_route('digitalogic/v1', '/desktop/manifest', array(
            'methods' => 'GET',
            'callback' => array($this, 'manifest'),
            'permission_callback' => '__return_true',
        ));
    }

    public function manifest() {
        $manifest_file = ABSPATH . 'digitalogic-wp/latest/desktop-manifest.json';
        $manifest = array(
            'version' => self::VERSION,
            'name' => 'Digitalogic Desktop',
            'panel_url' => home_url('/panel/products/?digitalogic_desktop=1'),
            'icon_url' => 'https://meet.digitalogic.ir/images/watermark.svg?v=2026062715',
            'download_url' => home_url('/digitalogic-wp/latest/Digitalogic-Desktop.exe'),
        );

        if (file_exists($manifest_file)) {
            $decoded = json_decode((string) file_get_contents($manifest_file), true);
            if (is_array($decoded)) {
                $manifest = array_merge($manifest, $decoded);
            }
        }

        return rest_ensure_response($manifest);
    }

    public function send_desktop_headers() {
        if (!$this->is_desktop_request()) {
            return;
        }

        header('X-Digitalogic-Desktop-Mode: 1');
    }

    private function is_panel_request() {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';

        return (bool) preg_match('#/panel(?:/|$)#', $path);
    }

    private function is_desktop_request() {
        if (isset($_GET['digitalogic_desktop'])) {
            return true;
        }

        if (!empty($_COOKIE[self::COOKIE])) {
            return true;
        }

        $header = isset($_SERVER['HTTP_X_DIGITALOGIC_DESKTOP'])
            ? (string) wp_unslash($_SERVER['HTTP_X_DIGITALOGIC_DESKTOP'])
            : '';
        if ($header !== '') {
            return true;
        }

        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';

        return stripos($agent, 'DigitalogicDesktop/') !== false || stripos($agent, 'Electron') !== false;
    }
}
