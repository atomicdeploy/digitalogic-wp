<?php
/**
 * In-process bridge between WordPress and the bundled Laravel application.
 *
 * WordPress remains the only identity and authorization authority. The bridge
 * never creates a second session, issues a handoff code, or accepts a panel
 * token over HTTP.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Laravel_Bridge {

    private const LOCAL_APP_PATH_OPTION = 'digitalogic_laravel_app_path';

    private static $instance = null;

    private $laravel_app = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_digitalogic_laravel_panel_launch', array($this, 'handle_panel_launch'));
    }

    /**
     * Launch the integrated panel while preserving the current WP session.
     */
    public function handle_panel_launch() {
        check_admin_referer('digitalogic_laravel_panel_launch');

        if ( ! Digitalogic_Access_Control::can_access_panel() ) {
            Digitalogic_Panel_Error_Page::render( 403, 'panel-access-denied' );
            exit;
        }

        $return_to = isset($_GET['return_to']) ? esc_url_raw(wp_unslash($_GET['return_to'])) : '';
        wp_safe_redirect($this->get_panel_auth_url(array('return_to' => $return_to)));
        exit;
    }

    /**
     * Return a same-origin panel URL. An external panel origin is deliberately
     * unsupported because it would require a second authentication boundary.
     */
    public function get_panel_url($path = '', $args = array()) {
        $default = untrailingslashit(home_url('/panel/'));
        $base = untrailingslashit((string) apply_filters('digitalogic_integrated_panel_url', $default));

        if ($this->normalized_origin($base) !== $this->normalized_origin(home_url('/'))) {
            $base = $default;
        }

        $path = '/' . ltrim((string) $path, '/');
        $url = $base . ($path === '/' ? '' : $path);

        return $args ? add_query_arg(array_filter($args), $url) : $url;
    }

    /**
     * Compatibility method for callers that previously requested an auth URL.
     * No auth code or token is ever copied into the resulting URL.
     */
    public function get_panel_auth_url($args = array()) {
        $return_to = isset($args['return_to']) ? (string) $args['return_to'] : '';
        $query = $return_to !== '' ? array('return_to' => $return_to) : array();

        return add_query_arg($query, trailingslashit($this->get_panel_url()));
    }

    public function uses_integrated_panel() {
        return true;
    }

    /**
     * Bootstrap the bundled Laravel container as part of a WordPress panel
     * request. The panel remains available when the optional bundle has not yet
     * been installed, and the returned WP_Error exposes that exact state.
     */
    public function boot_for_panel() {
        if ( ! Digitalogic_Access_Control::can_access_panel() ) {
            return new WP_Error(
                'digitalogic_laravel_forbidden',
                __('You are not allowed to use the Digitalogic application.', 'digitalogic'),
                array('status' => 403)
            );
        }

        $app = $this->boot_local_laravel();
        if (is_wp_error($app)) {
            return $app;
        }

        do_action('digitalogic_laravel_booted', $app);

        return $app;
    }

    /**
     * Invoke the bundled Laravel HTTP kernel in the current PHP process.
     * Laravel can call WordPress/WooCommerce functions directly and observes
     * the already established WordPress user and capability state.
     */
    public function call_local_laravel($path, $method = 'GET', $payload = array()) {
        $app = $this->boot_for_panel();
        if (is_wp_error($app)) {
            return $app;
        }

        if (!class_exists('\\Illuminate\\Http\\Request')) {
            return new WP_Error('digitalogic_laravel_request_missing', __('Laravel HTTP request class is not available.', 'digitalogic'), array('status' => 503));
        }

        $path = '/' . ltrim((string) $path, '/');
        $method = strtoupper((string) $method);
        $request = \Illuminate\Http\Request::create($path, $method, $payload);

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

    public function get_laravel_status() {
        return $this->local_laravel_status();
    }

    private function local_laravel_status() {
        $path = $this->get_local_laravel_path();

        return array(
            'configured' => $path !== '',
            'path' => $path,
            'available' => $path !== '' && file_exists($path . '/bootstrap/app.php'),
            'mode' => 'in_process',
            'auth' => 'wordpress_session',
        );
    }

    private function boot_local_laravel() {
        if ($this->laravel_app !== null) {
            return $this->laravel_app;
        }

        $status = $this->local_laravel_status();
        if (!$status['available']) {
            return new WP_Error('digitalogic_laravel_unavailable', __('No bundled Laravel app is configured for direct loading.', 'digitalogic'), array('status' => 503));
        }

        $this->laravel_app = require $status['path'] . '/bootstrap/app.php';

        return $this->laravel_app;
    }

    private function get_local_laravel_path() {
        $default = defined('DIGITALOGIC_PLUGIN_DIR') ? DIGITALOGIC_PLUGIN_DIR . 'laravel' : '';
        $path = (string) get_option(self::LOCAL_APP_PATH_OPTION, $default);
        $path = (string) apply_filters('digitalogic_laravel_app_path', $path);
        $path = untrailingslashit($path);

        return $path !== '' ? $path : '';
    }

    private function normalized_origin($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . $host . ':' . $port;
    }
}
