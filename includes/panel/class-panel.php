<?php
/**
 * In-site Vue panel served from /panel while the standalone Laravel panel is prepared.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Panel {

    private const QUERY_VAR = 'digitalogic_panel';
    private const PATH_VAR = 'digitalogic_panel_path';
    private const LEGACY_VAR = 'digitalogic_panel_legacy';
    private const REWRITE_VERSION_OPTION = 'digitalogic_panel_rewrite_version';
    private const REWRITE_VERSION = '20260617-panel';
    private const EVENT_OPTION = 'digitalogic_panel_events';

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
        add_filter('redirect_canonical', array($this, 'redirect_legacy_canonical'), 10, 2);
        add_action('template_redirect', array($this, 'render'));
        add_filter('digitalogic_command_handlers', array($this, 'register_commands'), 10, 2);
        add_action('wp_ajax_digitalogic_panel_command', array($this, 'ajax_command'));
        add_action('digitalogic_product_updated', array($this, 'record_product_event'), 20, 1);
        add_action('woocommerce_update_product', array($this, 'record_product_event'), 20, 1);
        add_action('woocommerce_update_product_variation', array($this, 'record_product_event'), 20, 1);
        add_action('updated_option', array($this, 'record_option_event'), 20, 3);
        add_action('user_register', array($this, 'record_user_event'), 20, 1);
        add_action('profile_update', array($this, 'record_user_event'), 20, 1);
    }

    public function register_route() {
        add_rewrite_rule('^panel/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_rewrite_rule('^panel/(.+)/?$', 'index.php?' . self::QUERY_VAR . '=1&' . self::PATH_VAR . '=$matches[1]', 'top');
        add_rewrite_rule('^panell/?$', 'index.php?' . self::QUERY_VAR . '=1&' . self::LEGACY_VAR . '=1', 'top');
        add_rewrite_rule('^panell/(.+)/?$', 'index.php?' . self::QUERY_VAR . '=1&' . self::LEGACY_VAR . '=1&' . self::PATH_VAR . '=$matches[1]', 'top');

        if (get_option(self::REWRITE_VERSION_OPTION) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_VERSION_OPTION, self::REWRITE_VERSION, false);
        }
    }

    public function register_query_vars($vars) {
        $vars[] = self::QUERY_VAR;
        $vars[] = self::PATH_VAR;
        $vars[] = self::LEGACY_VAR;

        return $vars;
    }

    public function redirect_legacy_canonical($redirect_url, $requested_url) {
        $path = wp_parse_url($requested_url, PHP_URL_PATH);

        if (!is_string($path) || !preg_match('#^/panell(?:/|$)#', $path)) {
            return $redirect_url;
        }

        $legacy_path = trim(substr($path, strlen('/panell')), '/');
        $target = home_url('/panel/' . ($legacy_path ? $legacy_path . '/' : ''));
        $query = wp_parse_url($requested_url, PHP_URL_QUERY);

        if ($query) {
            $target .= '?' . $query;
        }

        return $target;
    }

    public function render() {
        if (!get_query_var(self::QUERY_VAR)) {
            return;
        }

        $panel_path = trim((string) get_query_var(self::PATH_VAR), '/');
        if (get_query_var(self::LEGACY_VAR)) {
            wp_safe_redirect(home_url('/panel/' . ($panel_path ? $panel_path . '/' : '')), 301);
            exit;
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

        include DIGITALOGIC_PLUGIN_DIR . 'includes/panel/views/app.php';
        exit;
    }

    public function enqueue_assets() {
        wp_enqueue_style(
            'dashicons'
        );

        wp_enqueue_style(
            'digitalogic-panel',
            DIGITALOGIC_PLUGIN_URL . 'assets/css/panel.css',
            array('dashicons'),
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
            'legacy_panel_url' => home_url('/panell/'),
            'initial_path' => '/' . trim((string) get_query_var(self::PATH_VAR), '/'),
            'locale' => determine_locale(),
            'direction' => is_rtl() ? 'rtl' : 'ltr',
            'user' => array(
                'id' => $user->ID,
                'login' => $user->user_login,
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
        $commands['digitalogic_panel_update_user'] = array($this, 'update_user_command');
        $commands['digitalogic_panel_settings'] = array($this, 'settings_command');
        $commands['digitalogic_panel_events'] = array($this, 'events_command');

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
            'cli' => array(
                'websocket' => 'wp digitalogic websocket serve --host=127.0.0.1 --port=8090 --allow-root',
                'websocket_token' => 'wp digitalogic websocket token --allow-root',
                'panel_token' => 'wp digitalogic panel token --allow-root',
                'panel_rotate' => 'wp digitalogic panel token --rotate --allow-root',
            ),
            'patris' => array(
                'project' => 'atomicdeploy/patris-export',
                'mode' => 'REST and WebSocket watcher for Patris Paradox DB exports',
                'suggested_bridge' => 'patris-export serve kala.db -a 127.0.0.1:8080 --debounce 0s',
            ),
            'logs' => Digitalogic_Logger::instance()->get_logs(array('limit' => 6)),
            'websocket' => array(
                'enabled' => true,
                'path' => '/wordpress-ws',
            ),
            'bridge' => array(
                'panel_url' => Digitalogic_Laravel_Bridge::instance()->get_panel_url(),
                'rest_url' => rest_url('digitalogic-panel/v1/'),
                'wordpress_loaded' => true,
                'laravel_bootstrap' => file_exists(DIGITALOGIC_PLUGIN_DIR . 'laravel/bootstrap/app.php'),
            ),
        );
    }

    public function settings_command() {
        $options = Digitalogic_Options::instance();
        $ws = Digitalogic_WebSocket::instance()->get_client_config();

        return array(
            'currency' => array(
                'dollar_price' => $options->get_dollar_price(),
                'yuan_price' => $options->get_yuan_price(),
                'updated_at' => $options->get_update_date_formatted(),
            ),
            'urls' => array(
                'panel' => Digitalogic_Laravel_Bridge::instance()->get_panel_url(),
                'legacy_panel' => home_url('/panell/'),
                'admin' => admin_url(),
                'ajax' => admin_url('admin-ajax.php'),
                'rest' => rest_url('digitalogic/v1/'),
                'bridge_rest' => rest_url('digitalogic-panel/v1/'),
            ),
            'websocket' => array(
                'enabled' => !empty($ws['enabled']),
                'url' => isset($ws['url']) ? $ws['url'] : '',
                'path' => '/wordpress-ws',
                'ajax_proxy_enabled' => !empty($ws['ajax_proxy_enabled']),
            ),
            'bridge' => array(
                'wordpress_bootstrap' => ABSPATH,
                'laravel_bootstrap' => file_exists(DIGITALOGIC_PLUGIN_DIR . 'laravel/bootstrap/app.php'),
                'theme_shared' => true,
                'patris_project' => 'atomicdeploy/patris-export',
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
                return $this->format_user($user);
            }, $users),
        );
    }

    public function update_user_command($payload) {
        if (!current_user_can('edit_users')) {
            return new WP_Error('digitalogic_user_update_forbidden', __('You are not allowed to edit users.', 'digitalogic'), array('status' => 403));
        }

        $user_id = isset($payload['user_id']) ? absint($payload['user_id']) : 0;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $user = $user_id ? get_userdata($user_id) : false;

        if (!$user) {
            return new WP_Error('digitalogic_user_not_found', __('User not found.', 'digitalogic'), array('status' => 404));
        }

        $update = array('ID' => $user_id);
        if (array_key_exists('display_name', $data)) {
            $update['display_name'] = sanitize_text_field(wp_unslash($data['display_name']));
        }
        if (array_key_exists('email', $data)) {
            $email = sanitize_email(wp_unslash($data['email']));
            if (!$email || !is_email($email)) {
                return new WP_Error('digitalogic_invalid_email', __('Invalid email address.', 'digitalogic'), array('status' => 400));
            }
            $update['user_email'] = $email;
        }

        if (count($update) > 1) {
            $result = wp_update_user($update);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (isset($data['role']) && current_user_can('promote_users')) {
            $role = sanitize_key(wp_unslash($data['role']));
            $roles = wp_roles();
            if ($role && isset($roles->roles[$role])) {
                $editable_user = new WP_User($user_id);
                $editable_user->set_role($role);
            }
        }

        self::record_event('user.updated', array('id' => $user_id));

        return array('user' => $this->format_user(get_userdata($user_id)));
    }

    public function events_command($payload) {
        $since = isset($payload['since']) ? absint($payload['since']) : 0;
        $events = get_option(self::EVENT_OPTION, array());
        $events = is_array($events) ? $events : array();

        return array(
            'events' => array_values(array_filter($events, function($event) use ($since) {
                return isset($event['id']) && absint($event['id']) > $since;
            })),
        );
    }

    public function record_product_event($product_id) {
        self::record_event('product.updated', array('id' => absint($product_id)));
    }

    public function record_user_event($user_id) {
        self::record_event('user.updated', array('id' => absint($user_id)));
    }

    public function record_option_event($option, $old_value, $value) {
        if (in_array($option, array('dollar_price', 'yuan_price', 'digitalogic_dollar_price', 'digitalogic_yuan_price'), true)) {
            self::record_event('currency.updated', array('option' => $option));
        }
    }

    public static function record_event($event, $data = array()) {
        $events = get_option(self::EVENT_OPTION, array());
        $events = is_array($events) ? $events : array();
        $events[] = array(
            'id' => (int) round(microtime(true) * 1000),
            'event' => sanitize_key(str_replace('.', '_', $event)),
            'name' => sanitize_text_field($event),
            'data' => is_array($data) ? $data : array(),
            'time' => current_time('mysql'),
        );

        if (count($events) > 200) {
            $events = array_slice($events, -200);
        }

        update_option(self::EVENT_OPTION, $events, false);
    }

    private function format_user($user) {
        return array(
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => array_values((array) $user->roles),
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
            'reports' => 'Reports',
            'settings' => 'Settings',
            'cli' => 'WP-CLI',
            'sync' => 'Sync',
            'openWordPress' => 'WordPress admin',
            'search' => 'Search products',
            'refresh' => 'Refresh',
            'save' => 'Save',
            'view' => 'View',
            'loading' => 'Loading',
            'connected' => 'Connected',
            'fallback' => 'AJAX fallback',
            'actions' => 'Actions',
            'edit' => 'Edit',
            'reorder' => 'Reorder',
            'copied' => 'Copied',
            'copy' => 'Copy',
            'commandUsage' => 'Command usage',
            'patrisSync' => 'Patris sync',
            'panelStyle' => 'Panel style',
            'modernStyle' => 'Modern',
            'classicStyle' => 'Classic',
            'persian' => 'Persian',
            'columns' => 'Columns',
            'resetColumns' => 'Reset columns',
            'selectAll' => 'Select all',
            'selectRow' => 'Select row',
            'searchUsers' => 'Search users',
            'displayName' => 'Display name',
            'role' => 'Role',
            'interfaceSettings' => 'Interface',
            'tableSettings' => 'Tables',
            'productTable' => 'Product table',
            'userTable' => 'User table',
            'bridgeSettings' => 'WordPress / Laravel bridge',
            'autosave' => 'Autosave',
            'priceReports' => 'Product price reports',
            'priceReportsText' => 'Migrated from the old products report: highlights missing prices, dual currency/local prices, and site/catalog differences.',
            'priceSync' => 'Price synchronization',
            'priceSyncText' => 'Patris-style price calculation flow for currency, shipping, weight, profit, and final rounded price review.',
            'imageAudit' => 'Image audit',
            'imageAuditText' => 'Image quality checks from the legacy image report, ready to connect to WooCommerce product media.',
            'customerReports' => 'Customer reports',
            'customerReportsText' => 'Customer/catalog comparison workflow from the legacy customer report.',
            'currencyShipping' => 'Currency and shipping',
            'currencyShippingText' => 'Inline currency editing is active; shipping method pricing is mapped for the next bridge command.',
            'excelExports' => 'Excel exports',
            'excelExportsText' => 'Existing Digitalogic CSV, JSON, and Excel export commands are surfaced through the panel and WP-CLI.',
            'missingFeatures' => 'Missing features',
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
            'sku' => 'Product code',
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
            'reports' => 'گزارش ها',
            'settings' => 'تنظیمات',
            'cli' => 'WP-CLI',
            'sync' => 'همگام سازی',
            'openWordPress' => 'مدیریت وردپرس',
            'search' => 'جستجوی محصولات',
            'refresh' => 'تازه سازی',
            'save' => 'ذخیره',
            'view' => 'نمایش',
            'loading' => 'در حال بارگذاری',
            'connected' => 'متصل',
            'fallback' => 'جایگزین AJAX',
            'actions' => 'عملیات',
            'edit' => 'ویرایش',
            'reorder' => 'چینش',
            'copied' => 'کپی شد',
            'copy' => 'کپی',
            'commandUsage' => 'راهنمای دستورها',
            'patrisSync' => 'همگام سازی پاتریس',
            'panelStyle' => 'سبک پنل',
            'modernStyle' => 'مدرن',
            'classicStyle' => 'کلاسیک',
            'persian' => 'فارسی',
            'columns' => 'ستون ها',
            'resetColumns' => 'بازنشانی ستون ها',
            'selectAll' => 'انتخاب همه',
            'selectRow' => 'انتخاب ردیف',
            'searchUsers' => 'جستجوی کاربران',
            'displayName' => 'نام نمایشی',
            'role' => 'نقش',
            'interfaceSettings' => 'ظاهر و تجربه کاربری',
            'tableSettings' => 'تنظیمات جدول ها',
            'productTable' => 'جدول محصولات',
            'userTable' => 'جدول کاربران',
            'bridgeSettings' => 'پل وردپرس / لاراول',
            'autosave' => 'ذخیره خودکار',
            'priceReports' => 'گزارش قیمت محصولات',
            'priceReportsText' => 'مهاجرت از گزارش محصولات قدیمی: قیمت های خالی، قیمت همزمان ارزی/ریالی، و اختلاف سایت و کاتالوگ را نشان می دهد.',
            'priceSync' => 'همگام سازی قیمت',
            'priceSyncText' => 'جریان محاسبه قیمت به سبک پاتریس برای ارز، حمل، وزن، سود، و قیمت نهایی گرد شده.',
            'imageAudit' => 'بررسی تصاویر',
            'imageAuditText' => 'کنترل کیفیت تصویر از گزارش قدیمی تصاویر و آماده اتصال به رسانه های ووکامرس.',
            'customerReports' => 'گزارش مشتریان',
            'customerReportsText' => 'جریان مقایسه مشتری و کاتالوگ از گزارش قدیمی مشتریان.',
            'currencyShipping' => 'ارز و حمل',
            'currencyShippingText' => 'ویرایش ارز داخل پنل فعال است؛ قیمت روش های حمل برای دستور بعدی پل آماده شده است.',
            'excelExports' => 'خروجی اکسل',
            'excelExportsText' => 'دستورهای موجود CSV، JSON و Excel دیجیتالاجیک در پنل و WP-CLI در دسترس هستند.',
            'missingFeatures' => 'بخش های باقی مانده',
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
            'sku' => 'کد کالا',
            'status' => 'وضعیت',
            'panelSettings' => 'تنظیمات پنل',
            'transport' => 'ارتباط',
            'signedInAs' => 'ورود با',
            'noRows' => 'رکوردی پیدا نشد',
            'error' => 'مشکلی پیش آمد. دوباره تلاش کنید.',
        );
    }
}
