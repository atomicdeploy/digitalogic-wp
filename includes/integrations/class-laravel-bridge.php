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

    private static $instance = null;

    private $laravel_app = null;

    private $laravel_booting = false;

    private $laravel_boot_error = null;

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
     * Return the authenticated WordPress panel route without a handoff token.
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
     * request. An incomplete package produces a bounded WP_Error.
     */
    public function boot_for_panel() {
        if ( ! Digitalogic_Access_Control::can_access_panel() ) {
            return new WP_Error(
                'digitalogic_laravel_forbidden',
                __('You are not allowed to use the Digitalogic application.', 'digitalogic'),
                array('status' => 403)
            );
        }

        return $this->boot_laravel();
    }

    /** Lazily boot Laravel for trusted server-side WordPress callers. */
    public function boot_laravel() {
        if ($this->laravel_app !== null) {
            return $this->laravel_app;
        }

        if (is_wp_error($this->laravel_boot_error)) {
            return $this->laravel_boot_error;
        }

        if ($this->laravel_booting) {
            return new WP_Error(
                'digitalogic_laravel_recursive_boot',
                __('Laravel is already being booted by this PHP request.', 'digitalogic'),
                array('status' => 503)
            );
        }

        $this->laravel_booting = true;
        try {
            $app = $this->boot_local_laravel();
            if (is_wp_error($app)) {
                $this->laravel_boot_error = $app;
                return $app;
            }

            if (!$this->is_laravel_application($app)) {
                $this->laravel_boot_error = new WP_Error(
                    'digitalogic_laravel_invalid_application',
                    __('The bundled Laravel bootstrap did not return an application container.', 'digitalogic'),
                    array('status' => 503)
                );
                return $this->laravel_boot_error;
            }

            if (method_exists($app, 'bootstrapForIntegration')) {
                $app->bootstrapForIntegration();
            }

            $this->laravel_app = $app;
        } catch (Throwable $error) {
            $this->laravel_boot_error = new WP_Error(
                'digitalogic_laravel_boot_failed',
                __('The bundled Laravel application could not be booted.', 'digitalogic'),
                array(
                    'status' => 503,
                    'exception' => get_class($error),
                )
            );
            return $this->laravel_boot_error;
        } finally {
            $this->laravel_booting = false;
        }

        do_action('digitalogic_laravel_booted', $this->laravel_app);

        return $this->laravel_app;
    }

    /** Resolve and invoke Laravel-side code directly through its container. */
    public function call($callable, $parameters = array()) {
        $app = $this->boot_laravel();
        if (is_wp_error($app)) {
            return $app;
        }

        if (!method_exists($app, 'call')) {
            return new WP_Error(
                'digitalogic_laravel_container_call_missing',
                __('Laravel container calls are unavailable.', 'digitalogic'),
                array('status' => 503)
            );
        }

        try {
            return $app->call($callable, is_array($parameters) ? $parameters : array());
        } catch (Throwable $error) {
            return new WP_Error(
                'digitalogic_laravel_call_failed',
                __('The Laravel-side callable failed.', 'digitalogic'),
                array(
                    'status' => 500,
                    'exception' => get_class($error),
                )
            );
        }
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
        $request->headers->set('Accept', 'application/json');

        $kernel_class = \Illuminate\Contracts\Http\Kernel::class;
        if (!method_exists($app, 'make') || !interface_exists($kernel_class)) {
            return new WP_Error('digitalogic_laravel_kernel_missing', __('Laravel HTTP kernel is not available.', 'digitalogic'), array('status' => 503));
        }

        try {
            $kernel = $app->make($kernel_class);
            $response = $kernel->handle($request);
            $content = method_exists($response, 'getContent') ? $response->getContent() : '';
            $decoded = json_decode((string) $content, true);

            if (method_exists($kernel, 'terminate')) {
                $kernel->terminate($request, $response);
            }
        } catch (Throwable $error) {
            return new WP_Error(
                'digitalogic_laravel_request_failed',
                __('The in-process Laravel request failed.', 'digitalogic'),
                array(
                    'status' => 500,
                    'exception' => get_class($error),
                )
            );
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
            'autoload' => class_exists('\\Illuminate\\Foundation\\Application'),
            'booted' => $this->laravel_app !== null,
            'lazy' => true,
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

        return require $status['path'] . '/bootstrap/app.php';
    }

    private function is_laravel_application($app) {
        return is_object($app)
            && is_a($app, '\\Digitalogic\\Laravel\\Application');
    }

    private function get_local_laravel_path() {
        return dirname(__DIR__, 2) . '/laravel';
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
