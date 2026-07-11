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
        add_action('template_redirect', array($this, 'serve_runtime_files'), 0);
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

        $this->enqueue_runtime_assets();

        wp_enqueue_style(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/css/desktop-app.css',
            array(),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/css/desktop-app.css') ?: DIGITALOGIC_VERSION
        );

        wp_enqueue_script(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/desktop-app.js',
            array('digitalogic-runtime'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/desktop-app.js') ?: DIGITALOGIC_VERSION,
            true
        );
    }

    public function enqueue_panel_assets() {
        if (!$this->is_desktop_request() || !$this->is_panel_request()) {
            return;
        }

        $this->enqueue_runtime_assets();

        wp_enqueue_style(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/css/desktop-app.css',
            array('digitalogic-panel'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/css/desktop-app.css') ?: DIGITALOGIC_VERSION
        );

        wp_enqueue_script(
            'digitalogic-desktop-app',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/desktop-app.js',
            array('digitalogic-panel', 'digitalogic-runtime'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/desktop-app.js') ?: DIGITALOGIC_VERSION,
            true
        );
    }

    private function enqueue_runtime_assets() {
        wp_enqueue_script(
            'digitalogic-runtime',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/digitalogic-runtime.js',
            array(),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/digitalogic-runtime.js') ?: DIGITALOGIC_VERSION,
            true
        );

        wp_enqueue_script(
            'digitalogic-pwa',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/digitalogic-pwa.js',
            array('digitalogic-runtime'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/digitalogic-pwa.js') ?: DIGITALOGIC_VERSION,
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

        register_rest_route('digitalogic/v1', '/extension/manifest', array(
            'methods' => 'GET',
            'callback' => array($this, 'extension_manifest'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('digitalogic/v1', '/runtime/config', array(
            'methods' => 'GET',
            'callback' => array($this, 'runtime_config'),
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

    public function extension_manifest() {
        $manifest_file = ABSPATH . 'digitalogic-wp/latest/extension-manifest.json';
        $manifest = array(
            'version' => self::VERSION,
            'name' => 'Digitalogic Browser Extension',
            'api_url' => home_url('/wp-json/digitalogic/v1'),
            'automation_url' => 'https://automation.digitalogic.ir',
            'panel_url' => home_url('/panel/products/'),
            'icon_url' => 'https://meet.digitalogic.ir/images/watermark.svg?v=2026062715',
            'download_url' => home_url('/digitalogic-wp/latest/Digitalogic-Browser-Extension.zip'),
        );

        if (file_exists($manifest_file)) {
            $decoded = json_decode((string) file_get_contents($manifest_file), true);
            if (is_array($decoded)) {
                $manifest = array_merge($manifest, $decoded);
            }
        }

        return rest_ensure_response($manifest);
    }

    public function runtime_config() {
        return rest_ensure_response(array(
            'apiBase' => home_url('/wp-json/digitalogic/v1'),
            'automationBase' => 'https://automation.digitalogic.ir',
            'panelBase' => home_url('/panel'),
            'desktopManifest' => home_url('/wp-json/digitalogic/v1/desktop/manifest'),
            'extensionManifest' => home_url('/wp-json/digitalogic/v1/extension/manifest'),
            'serviceWorker' => home_url('/digitalogic-service-worker.js'),
            'webManifest' => home_url('/digitalogic.webmanifest'),
            'brand' => array(
                'name' => 'Digitalogic',
                'icon' => 'https://meet.digitalogic.ir/images/watermark.svg?v=2026062715',
            ),
        ));
    }

    public function serve_runtime_files() {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = strtok($path, '?');

        if ($path === '/digitalogic-service-worker.js') {
            $file = DIGITALOGIC_PLUGIN_DIR . 'assets/js/digitalogic-service-worker.js';
            if (!file_exists($file)) {
                status_header(404);
                exit;
            }
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /');
            header('Cache-Control: no-cache');
            readfile($file);
            exit;
        }

        if ($path === '/digitalogic.webmanifest') {
            header('Content-Type: application/manifest+json; charset=utf-8');
            echo wp_json_encode(array(
                'name' => 'Digitalogic',
                'short_name' => 'Digitalogic',
                'start_url' => home_url('/panel/products/?source=pwa'),
                'scope' => home_url('/'),
                'display' => 'standalone',
                'background_color' => '#f4f8fb',
                'theme_color' => '#0168cd',
                'icons' => array(
                    array(
                        'src' => 'https://meet.digitalogic.ir/images/watermark.svg?v=2026062715',
                        'sizes' => 'any',
                        'type' => 'image/svg+xml',
                    ),
                ),
            ));
            exit;
        }
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
