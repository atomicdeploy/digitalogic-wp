<?php
/**
 * In-site Vue panel served from /panell while the standalone Laravel panel is prepared.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Panel {

    private const QUERY_VAR = 'digitalogic_panel';
    private const PATH_VAR = 'digitalogic_panel_path';
    private const REWRITE_VERSION_OPTION = 'digitalogic_panel_rewrite_version';
    private const REWRITE_VERSION = '20260616-panell';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_route'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'render'));
        add_filter('digitalogic_command_handlers', array($this, 'register_commands'), 10, 2);
        add_action('wp_ajax_digitalogic_panel_command', array($this, 'ajax_command'));
    }

    public function register_route() {
        add_rewrite_rule('^panell/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^panell/(.+)/?$', 'index.php?' . self::QUERY_VAR . '=1&' . self::PATH_VAR . '=$matches[1]', 'top');

        if (get_option(self::REWRITE_VERSION_OPTION) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false);
        }
    }

    public function register_query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::PATH_VAR;

        return $vars;
    }

    public function render() {
        if (!get_query_var(self::QUERY_VAR)) {
            return;
        }

        if (!is_user_logged_in()) {
            auth_redirect();
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You are not allowed to open the Digitalogic panel.', 'digitalogic'),
                esc_html__('Forbidden', 'digitalogic'),
                array('response' => 403)
            );
        }

        $this->enqueue_assets();
        nocache_headers();
        status_header(200);

        $panel_path = trim((string) get_query_var(self::PATH_VAR), '/');
        include DIGITALOGIC_PLUGIN_DIR . 'includes/panel/views/app.php';
        exit;
    }

    public function enqueue_assets() {
        wp_enqueue_style(
            'digitalogic-panel',
            DIGITALOGIC_PLUGIN_URL . 'assets/css/panel.css',
            array(),
            DIGITALOGIC_VERSION
        );

        wp_enqueue_script(
            'vue',
            'https://unpkg.com/vue@3/dist/vue.global.prod.js',
            array(),
            '3',
            true
        );

        wp_enqueue_script(
            'digitalogic-panel',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/panel-app.js',
            array('vue'),
            DIGITALOGIC_VERSION,
            true
        );

        wp_localize_script('digitalogic-panel', 'digitalogicPanel', $this->client_config());
    }

    public function client_config() {
        $user = wp_get_current_user();

        return array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('digitalogic_nonce'),
            'panel_url' => trailingslashit(Digitalogic_Laravel_Bridge::instance()->get_panel_url()),
            'initial_path' => '/' . trim((string) get_query_var(self::PATH_VAR), '/'),
            'locale' => determine_locale(),
            'direction' => is_rtl() ? 'rtl' : 'ltr',
            'user' => array(
                'id' => $user->ID,
                'display_name' => $user->display_name,
                'email' => $user->user_email,
                'roles' => array_values((array) $user->roles),
            ),
            'theme' => apply_filters('digitalogic_laravel_panel_theme', array(
                'name' => 'Digitalogic',
                'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                'logo_url' => $this->logo_url(),
                'shared_ui_base_url' => home_url('/digitalogic-ui/'),
            )),
            'websocket' => Digitalogic_WebSocket::instance()->get_client_config(),
            'i18n' => array(
                'en' => $this->translations_en(),
                'fa' => $this->translations_fa(),
            ),
        );
    }

    public function register_commands($commands, $transport) {
        $commands['digitalogic_panel_summary'] = array($this, 'summary_command');
        $commands['digitalogic_panel_users'] = array($this, 'users_command');

        return $commands;
    }

    public function ajax_command() {
        check_ajax_referer('digitalogic_nonce', 'nonce');

        $command = isset($_POST['command']) ? Digitalogic_Command_Dispatcher::normalize_command_name(wp_unslash($_POST['command'])) : '';
        $data = array();

        if (isset($_POST['data'])) {
            $decoded = json_decode(wp_unslash((string) $_POST['data']), true);
            $data = is_array($decoded) ? $decoded : array();
        }

        $result = Digitalogic_Command_Dispatcher::instance()->execute($command, $data, 'ajax');
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    public function summary_command() {
        $options = Digitalogic_Options::instance();

        return array(
            'products' => Digitalogic_Product_Manager::instance()->get_product_count(),
            'currency' => array(
                'dollar_price' => $options->get_dollar_price(),
                'yuan_price' => $options->get_yuan_price(),
                'updated_at' => $options->get_update_date_formatted(),
            ),
            'logs' => Digitalogic_Logger::instance()->get_logs(array('limit' => 6)),
            'websocket' => array(
                'enabled' => true,
                'path' => '/wordpress-ws',
            ),
        );
    }

    public function users_command() {
        if (!current_user_can('list_users')) {
            return new WP_Error('digitalogic_users_forbidden', __('You are not allowed to list users.', 'digitalogic'), array('status' => 403));
        }

        $users = get_users(array(
            'number' => 50,
            'orderby' => 'registered',
            'order' => 'DESC',
        ));

        return array(
            'users' => array_map(function($user) {
                return array(
                    'id' => $user->ID,
                    'login' => $user->user_login,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name,
                    'roles' => array_values((array) $user->roles),
                );
            }, $users),
        );
    }

    private function logo_url() {
        $logo_id = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        return $logo_url ?: get_site_icon_url(192);
    }

    private function translations_en() {
        return array(
            'dir' => 'ltr',
            'dashboard' => 'Dashboard',
            'products' => 'Products',
            'users' => 'Users',
            'settings' => 'Settings',
            'openWordPress' => 'WordPress admin',
            'search' => 'Search products',
            'refresh' => 'Refresh',
            'save' => 'Save',
            'view' => 'View',
            'loading' => 'Loading',
            'connected' => 'WebSocket connected',
            'fallback' => 'AJAX fallback',
            'dark' => 'Dark',
            'light' => 'Light',
            'system' => 'System',
            'language' => 'Language',
            'totalProducts' => 'Total products',
            'currency' => 'Currency',
            'recentActivity' => 'Recent activity',
            'stock' => 'Stock',
            'regularPrice' => 'Regular price',
            'salePrice' => 'Sale price',
            'sku' => 'SKU',
            'status' => 'Status',
            'panelSettings' => 'Panel settings',
            'transport' => 'Transport',
            'signedInAs' => 'Signed in as',
            'noRows' => 'No records found',
            'error' => 'Something went wrong. Please try again.',
        );
    }

    private function translations_fa() {
        return array(
            'dir' => 'rtl',
            'dashboard' => 'پیشخوان',
            'products' => 'محصولات',
            'users' => 'کاربران',
            'settings' => 'تنظیمات',
            'openWordPress' => 'مدیریت وردپرس',
            'search' => 'جستجوی محصولات',
            'refresh' => 'تازه سازی',
            'save' => 'ذخیره',
            'view' => 'نمایش',
            'loading' => 'در حال بارگذاری',
            'connected' => 'وب سوکت متصل است',
            'fallback' => 'جایگزین AJAX',
            'dark' => 'تیره',
            'light' => 'روشن',
            'system' => 'خودکار',
            'language' => 'زبان',
            'totalProducts' => 'کل محصولات',
            'currency' => 'ارز',
            'recentActivity' => 'فعالیت های اخیر',
            'stock' => 'موجودی',
            'regularPrice' => 'قیمت عادی',
            'salePrice' => 'قیمت فروش',
            'sku' => 'شناسه کالا',
            'status' => 'وضعیت',
            'panelSettings' => 'تنظیمات پنل',
            'transport' => 'ارتباط',
            'signedInAs' => 'ورود با',
            'noRows' => 'رکوردی پیدا نشد',
            'error' => 'مشکلی پیش آمد. دوباره تلاش کنید.',
        );
    }
}
