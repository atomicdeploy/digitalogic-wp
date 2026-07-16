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
    private const EVENT_SEQUENCE_OPTION = 'digitalogic_panel_event_sequence';
    private const EVENT_LOCK_NAME = 'digitalogic_panel_events_v1';
    private const EVENT_LIMIT = 200;

    private static $reported_delivery_failures = array();

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        self::$instance->register_import_freight_delivery_channel();

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
        add_filter('posts_search', array($this, 'extend_product_search'), 10, 2);
        add_action('wp_footer', array($this, 'hide_wp_armour_honeypot_notice'), 1000);
        add_action('admin_footer', array($this, 'hide_wp_armour_honeypot_notice'), 1000);
    }

    private function register_import_freight_delivery_channel() {
        Digitalogic_Import_Freight_Service::instance()->register_delivery_channel(
            'panel',
            array($this, 'deliver_import_freight_event')
        );
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
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/css/panel.css') ?: DIGITALOGIC_VERSION
        );

        wp_enqueue_script(
            'vue',
            DIGITALOGIC_PLUGIN_URL . 'assets/vendor/vue/vue.global.prod.js',
            array(),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/vendor/vue/vue.global.prod.js') ?: '3',
            true
        );

        wp_enqueue_script(
            'digitalogic-panel',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/panel-app.js',
            array('vue'),
            filemtime(DIGITALOGIC_PLUGIN_DIR . 'assets/js/panel-app.js') ?: DIGITALOGIC_VERSION,
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
            'logout_url' => wp_logout_url(home_url('/')),
            'legacy_panel_url' => home_url('/panell/'),
            'initial_path' => '/' . trim((string) get_query_var(self::PATH_VAR), '/'),
            'locale' => determine_locale(),
            'direction' => is_rtl() ? 'rtl' : 'ltr',
            'theme_mode' => $this->current_theme_mode(),
            'admin_color' => $this->current_admin_color(),
            'theme_storage_key' => 'digitalogic-admin-theme',
            'event_cursor' => self::get_latest_event_id(),
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
                'logo_icon_url' => DIGITALOGIC_PLUGIN_URL . 'assets/images/icon.svg',
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
        $commands['digitalogic_panel_create_user'] = array($this, 'create_user_command');
        $commands['digitalogic_panel_delete_user'] = array($this, 'delete_user_command');
        $commands['digitalogic_panel_user_orders'] = array($this, 'user_orders_command');
        $commands['digitalogic_panel_settings'] = array($this, 'settings_command');
        $commands['digitalogic_panel_events'] = array($this, 'events_command');
        $commands['digitalogic_panel_set_theme'] = array($this, 'set_theme_command');

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
            'panel_broadcast' => 'wp digitalogic panel broadcast --message="Hello panel" --level=success --allow-root',
            'patris_sync' => 'wp digitalogic patris sync --allow-root',
            'patris_report' => 'wp digitalogic patris report --format=table --allow-root',
            'patris_token' => 'wp digitalogic patris token --allow-root',
        ),
        'patris' => array(
            'project' => 'Digitalogic normalized Patris API',
            'mode' => 'Pull scheduled/manual feed or authenticated push payload into WooCommerce',
            'suggested_bridge' => 'POST /wp-json/digitalogic/v1/patris/push or configure a pull URL in Patris Reports',
        ),
            'logs' => Digitalogic_Logger::instance()->get_logs(array('limit' => 6)),
            'categories' => Digitalogic_Product_Manager::instance()->get_product_categories(),
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
                'theme_mode' => $this->current_theme_mode(),
                'admin_color' => $this->current_admin_color(),
                'patris_project' => 'Digitalogic normalized Patris API',
            ),
            'patris_feed' => class_exists('Digitalogic_Patris_Feed') ? array(
                'settings' => Digitalogic_Patris_Feed::instance()->get_settings(),
                'push_token' => Digitalogic_Patris_Feed::instance()->get_push_token(),
            ) : array(),
        );
    }

    public function set_theme_command($payload) {
        if (!is_user_logged_in()) {
            return new WP_Error('digitalogic_panel_theme_login_required', __('You must be logged in to change the theme.', 'digitalogic'), array('status' => 401));
        }

        $theme = isset($payload['theme']) ? sanitize_key(wp_unslash($payload['theme'])) : '';

        if (class_exists('Digitalogic_Plugin_Admin_Branding')) {
            $result = Digitalogic_Plugin_Admin_Branding::set_user_theme(get_current_user_id(), $theme);
            return is_wp_error($result) ? $result : $result;
        }

        if ($theme !== 'light' && $theme !== 'dark') {
            return new WP_Error('digitalogic_panel_theme_invalid', __('Choose either light or dark mode.', 'digitalogic'), array('status' => 400));
        }

        $admin_color = $theme === 'dark' ? 'digitalogic-dark' : 'digitalogic-light';
        update_user_option(get_current_user_id(), 'admin_color', $admin_color, true);

        return array(
            'theme' => $theme,
            'admin_color' => $admin_color,
        );
    }

    public function users_command() {
        if (!current_user_can('list_users')) {
            return new WP_Error('digitalogic_users_forbidden', __('You are not allowed to list users.', 'digitalogic'), array('status' => 403));
        }

        $users = get_users(array(
            'number' => 200,
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
        if (array_key_exists('login', $data) && empty($user->user_login)) {
            $update['user_login'] = sanitize_user(wp_unslash($data['login']), true);
        }
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

    public function create_user_command($payload) {
        if (!current_user_can('create_users')) {
            return new WP_Error('digitalogic_user_create_forbidden', __('You are not allowed to create users.', 'digitalogic'), array('status' => 403));
        }

        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $email = isset($data['email']) ? sanitize_email(wp_unslash($data['email'])) : '';
        $login = isset($data['login']) ? sanitize_user(wp_unslash($data['login']), true) : '';

        if (!$email || !is_email($email)) {
            return new WP_Error('digitalogic_invalid_email', __('Invalid email address.', 'digitalogic'), array('status' => 400));
        }

        if (!$login) {
            $login = sanitize_user(current(explode('@', $email)), true);
        }

        if (username_exists($login) || email_exists($email)) {
            return new WP_Error('digitalogic_user_exists', __('A user with this login or email already exists.', 'digitalogic'), array('status' => 409));
        }

        $role = isset($data['role']) ? sanitize_key(wp_unslash($data['role'])) : 'customer';
        $roles = wp_roles();
        if (!$role || !isset($roles->roles[$role]) || !current_user_can('promote_users')) {
            $role = 'customer';
        }

        $user_id = wp_insert_user(array(
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => isset($data['display_name']) ? sanitize_text_field(wp_unslash($data['display_name'])) : $login,
            'user_pass' => wp_generate_password(20, true, true),
            'role' => $role,
        ));

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        self::record_event('user.created', array('id' => $user_id));

        return array('user' => $this->format_user(get_userdata($user_id)));
    }

    public function delete_user_command($payload) {
        if (!current_user_can('delete_users')) {
            return new WP_Error('digitalogic_user_delete_forbidden', __('You are not allowed to delete users.', 'digitalogic'), array('status' => 403));
        }

        $user_id = isset($payload['user_id']) ? absint($payload['user_id']) : 0;
        if (!$user_id || $user_id === get_current_user_id()) {
            return new WP_Error('digitalogic_invalid_user_delete', __('This user cannot be deleted from the panel.', 'digitalogic'), array('status' => 400));
        }

        if (!get_userdata($user_id)) {
            return new WP_Error('digitalogic_user_not_found', __('User not found.', 'digitalogic'), array('status' => 404));
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        $deleted = wp_delete_user($user_id, get_current_user_id());
        if (!$deleted) {
            return new WP_Error('digitalogic_user_delete_failed', __('User deletion failed.', 'digitalogic'), array('status' => 500));
        }

        self::record_event('user.deleted', array('id' => $user_id));

        return array('deleted' => true);
    }

    public function user_orders_command($payload) {
        if (!current_user_can('list_users') || !function_exists('wc_get_orders')) {
            return array('orders' => array());
        }

        $user_id = isset($payload['user_id']) ? absint($payload['user_id']) : 0;
        if (!$user_id) {
            return new WP_Error('digitalogic_invalid_user', __('User ID is required.', 'digitalogic'), array('status' => 400));
        }

        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
        ));

        return array(
            'orders' => array_map(function($order) {
                return array(
                    'id' => $order->get_id(),
                    'status' => wc_get_order_status_name($order->get_status()),
                    'total' => $order->get_total(),
                    'currency' => $order->get_currency(),
                    'date_created' => $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i:s') : '',
                    'edit_url' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
                );
            }, is_array($orders) ? $orders : array()),
        );
    }

    public function events_command($payload) {
        $since = isset($payload['since']) ? absint($payload['since']) : 0;
        return array(
            'events' => self::get_events_since($since),
        );
    }

    public static function get_events_since($since = 0) {
        self::refresh_event_option_cache();
        $events = get_option(self::EVENT_OPTION, array());
        $events = is_array($events) ? $events : array();

        return array_values(array_filter($events, function($event) use ($since) {
                return isset($event['id']) && absint($event['id']) > $since;
        }));
    }

    /**
     * Return the newest stored panel event ID.
     *
     * @return int
     */
    public static function get_latest_event_id() {
        self::refresh_event_option_cache();
        $events = get_option(self::EVENT_OPTION, array());
        $latest_id = absint(get_option(self::EVENT_SEQUENCE_OPTION, 0));

        if (is_array($events)) {
            foreach ($events as $event) {
                if (!is_array($event) || !isset($event['id'])) {
                    continue;
                }

                $latest_id = max($latest_id, absint($event['id']));
            }
        }

        return $latest_id;
    }

    /**
     * Return normalized server-side Redis settings for panel event delivery.
     *
     * The password is intentionally limited to server-side consumers. Do not
     * pass this configuration to client settings or write it to logs.
     *
     * @return array{host:string,port:int,timeout:float,password:string,database:?int,channel:string}
     */
    public static function get_redis_config() {
        $defaults = array(
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 0.2,
            'password' => '',
            'database' => null,
            'channel' => 'digitalogic_panel_events',
        );
        $filtered = apply_filters('digitalogic_panel_redis_config', $defaults);
        $config = is_array($filtered) ? array_merge($defaults, $filtered) : $defaults;

        $host = is_scalar($config['host']) ? trim((string) $config['host']) : '';
        $port = (int) $config['port'];
        $timeout = (float) $config['timeout'];
        $password = is_scalar($config['password']) ? (string) $config['password'] : '';
        $database = $config['database'] === null || $config['database'] === ''
            ? null
            : max(0, (int) $config['database']);
        $channel = is_scalar($config['channel']) ? trim((string) $config['channel']) : '';

        return array(
            'host' => $host !== '' ? $host : $defaults['host'],
            'port' => $port > 0 && $port <= 65535 ? $port : $defaults['port'],
            'timeout' => $timeout > 0 ? $timeout : $defaults['timeout'],
            'password' => $password,
            'database' => $database,
            'channel' => $channel !== '' ? $channel : $defaults['channel'],
        );
    }

    public function record_product_event($product_id) {
        self::record_event('product.updated', array('id' => absint($product_id)));
    }

    public function record_user_event($user_id) {
        self::record_event('user.updated', array('id' => absint($user_id)));
    }

    public function record_option_event($option, $old_value, $value) {
        if (in_array($option, array('dollar_price', 'yuan_price', 'digitalogic_dollar_price', 'digitalogic_yuan_price', 'woocommerce_currency'), true)) {
            $data = array('option' => $option);
            if ('woocommerce_currency' === $option) {
                $data['woocommerce_base'] = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();
            }
            self::record_event('currency.updated', $data);
        }
    }

    public function record_import_freight_method_created($method) {
        return $this->record_import_freight_method_event('import_freight.method.created', $method);
    }

    public function record_import_freight_method_updated($method) {
        return $this->record_import_freight_method_event('import_freight.method.updated', $method);
    }

    public function record_import_freight_method_deleted($method) {
        return $this->record_import_freight_method_event('import_freight.method.deleted', $method);
    }

    public function record_import_freight_assignment_event($product_id, $method_id) {
        return self::record_event('import_freight.assignment.updated', array(
            'product_id' => absint($product_id),
            'import_freight_method_id' => sanitize_key((string) $method_id),
        ));
    }

    private function record_import_freight_method_event($event, $method) {
        $method = is_array($method) ? $method : array();
        return self::record_event($event, array(
            'id' => isset($method['id']) ? sanitize_key($method['id']) : '',
            'name' => isset($method['name']) ? sanitize_text_field($method['name']) : '',
            'enabled' => !empty($method['enabled']),
            'price_per_kg_cny' => isset($method['price_per_kg_cny']) ? (float) $method['price_per_kg_cny'] : null,
        ));
    }

    /**
     * Result-aware import-freight delivery channel used after service commits.
     */
    public function deliver_import_freight_event($hook, $args) {
        $args = is_array($args) ? $args : array();
        if ('digitalogic_import_freight_default_markup_updated' === $hook) {
            $event = 'import_freight.default_markup.updated';
            $markup = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
            $data = array(
                'configured' => !empty($markup['configured']),
                'profit_percent' => isset($markup['profit_percent']) ? (string) $markup['profit_percent'] : null,
                'source' => isset($markup['source']) ? sanitize_key($markup['source']) : '',
                'revision' => isset($markup['revision']) ? sanitize_text_field($markup['revision']) : '',
                'previous_revision' => isset($markup['previous_revision']) ? sanitize_text_field($markup['previous_revision']) : '',
                'updated_at' => isset($markup['updated_at']) ? sanitize_text_field($markup['updated_at']) : '',
                'updated_by' => isset($markup['updated_by']) ? absint($markup['updated_by']) : 0,
            );
        } elseif ('digitalogic_product_import_freight_method_updated' === $hook) {
            $event = 'import_freight.assignment.updated';
            $data = array(
                'product_id' => absint(isset($args[0]) ? $args[0] : 0),
                'import_freight_method_id' => sanitize_key((string) (isset($args[1]) ? $args[1] : '')),
            );
        } else {
            $events = array(
                'digitalogic_import_freight_method_created' => 'import_freight.method.created',
                'digitalogic_import_freight_method_updated' => 'import_freight.method.updated',
                'digitalogic_import_freight_method_deleted' => 'import_freight.method.deleted',
            );
            if (!isset($events[$hook])) {
                return new WP_Error(
                    'digitalogic_panel_delivery_event_unknown',
                    __('The panel does not recognize this import freight event.', 'digitalogic')
                );
            }

            $event = $events[$hook];
            $method = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
            $data = array(
                'id' => isset($method['id']) ? sanitize_key($method['id']) : '',
                'name' => isset($method['name']) ? sanitize_text_field($method['name']) : '',
                'enabled' => !empty($method['enabled']),
                'price_per_kg_cny' => isset($method['price_per_kg_cny']) ? (float) $method['price_per_kg_cny'] : null,
            );
        }

        $result = self::record_event_result($event, $data);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!empty($result['delivery_warnings'])) {
            return new WP_Error(
                'digitalogic_panel_delivery_failed',
                __('The panel event was stored, but one or more real-time deliveries failed.', 'digitalogic'),
                array('warnings' => $result['delivery_warnings'])
            );
        }

        return true;
    }

    public static function record_event($event, $data = array()) {
        $result = self::record_event_result($event, $data);
        return is_wp_error($result) ? null : $result['event'];
    }

    /**
     * Persist and publish an event while retaining per-channel outcome data.
     *
     * @return array{event:array,delivery_warnings:array}|WP_Error
     */
    public static function record_event_result($event, $data = array()) {
        $lock = self::acquire_event_lock();
        if ($lock === false) {
            self::report_event_delivery_failure('Could not acquire the database event lock; the event was not recorded.');
            return new WP_Error(
                'digitalogic_panel_queue_lock_failed',
                __('The panel event queue lock could not be acquired.', 'digitalogic')
            );
        }

        $stored = false;
        $event_envelope = null;
        $delivery_warnings = array();

        try {
            self::refresh_event_option_cache(true);
            $events = get_option(self::EVENT_OPTION, array());
            $events = is_array($events) ? $events : array();
            $latest_id = absint(get_option(self::EVENT_SEQUENCE_OPTION, 0));

            foreach ($events as $stored_event) {
                if (is_array($stored_event) && isset($stored_event['id'])) {
                    $latest_id = max($latest_id, absint($stored_event['id']));
                }
            }

            $event_id = max((int) round(microtime(true) * 1000), $latest_id + 1);
            $event_envelope = array(
                'id' => $event_id,
                'event' => sanitize_key(str_replace('.', '_', $event)),
                'name' => sanitize_text_field($event),
                'data' => is_array($data) ? $data : array(),
                'time' => current_time('mysql'),
            );
            $events[] = $event_envelope;

            if (count($events) > self::EVENT_LIMIT) {
                $events = array_slice($events, -self::EVENT_LIMIT);
            }

            if (!update_option(self::EVENT_SEQUENCE_OPTION, $event_id, false)) {
                self::report_event_delivery_failure('The durable event sequence could not be updated.');
                $delivery_warnings[] = 'panel_sequence_write_failed';
            }

            $stored = update_option(self::EVENT_OPTION, $events, false);
        } finally {
            self::release_event_lock($lock);
        }

        if (!$stored || !is_array($event_envelope)) {
            self::report_event_delivery_failure('The panel event queue could not be updated; Redis publication was skipped.');
            return new WP_Error(
                'digitalogic_panel_queue_write_failed',
                __('The panel event queue could not be updated.', 'digitalogic')
            );
        }

        if (!self::publish_event($event_envelope)) {
            $delivery_warnings[] = 'panel_redis_delivery_failed';
        }

        return array(
            'event' => $event_envelope,
            'delivery_warnings' => array_values(array_unique($delivery_warnings)),
        );
    }

    /**
     * Acquire a database-wide lock around sequence allocation and queue writes.
     *
     * MySQL advisory locks serialize all PHP workers that use this writer, so
     * IDs remain strictly increasing and concurrent option updates cannot drop
     * an event through a read-modify-write race.
     *
     * @return string|false
     */
    private static function acquire_event_lock() {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return 'process';
        }

        $query = $wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::EVENT_LOCK_NAME, 5);
        return (string) $wpdb->get_var($query) === '1' ? 'database' : false;
    }

    /**
     * Release an event lock acquired by acquire_event_lock().
     *
     * @param string $lock Lock provider.
     */
    private static function release_event_lock($lock) {
        global $wpdb;

        if ($lock !== 'database' || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::EVENT_LOCK_NAME));
    }

    /**
     * Avoid stale option values in the long-running WP-CLI WebSocket process.
     */
    private static function refresh_event_option_cache($force = false) {
        if (
            !function_exists('wp_cache_delete')
            || (!$force && (!defined('WP_CLI') || !WP_CLI))
        ) {
            return;
        }

        wp_cache_delete(self::EVENT_OPTION, 'options');
        wp_cache_delete(self::EVENT_SEQUENCE_OPTION, 'options');
    }

    /**
     * Publish the already-persisted envelope to Redis for immediate delivery.
     *
     * A zero subscriber count is a successful Redis PUBLISH reply. Any false
     * connection/auth/select/publish response is reported while the option
     * queue and the browser's polling loop remain the delivery fallback.
     *
     * @param array $event_envelope Stored event envelope.
     * @return bool
     */
    private static function publish_event($event_envelope) {
        $redis = apply_filters('digitalogic_panel_redis_client', null);
        if ($redis === null) {
            if (!class_exists('Redis')) {
                self::report_event_delivery_failure('The Redis extension is unavailable.');
                return false;
            }

            $redis = new Redis();
        }

        if (!is_object($redis)) {
            self::report_event_delivery_failure('The Redis publisher factory returned an invalid client.');
            return false;
        }

        $config = self::get_redis_config();

        try {
            if (!method_exists($redis, 'connect') || $redis->connect($config['host'], $config['port'], $config['timeout']) !== true) {
                throw new RuntimeException('Redis connection failed.');
            }

            if ($config['password'] !== '' && (!method_exists($redis, 'auth') || $redis->auth($config['password']) !== true)) {
                throw new RuntimeException('Redis authentication failed.');
            }

            if ($config['database'] !== null && (!method_exists($redis, 'select') || $redis->select($config['database']) !== true)) {
                throw new RuntimeException('Redis database selection failed.');
            }

            $payload = wp_json_encode($event_envelope);
            if (!is_string($payload)) {
                throw new RuntimeException('The panel event could not be JSON encoded.');
            }

            if (!method_exists($redis, 'publish') || $redis->publish($config['channel'], $payload) === false) {
                throw new RuntimeException('Redis publication failed.');
            }

            return true;
        } catch (Throwable $error) {
            self::report_event_delivery_failure($error->getMessage());
            return false;
        } finally {
            if (method_exists($redis, 'close')) {
                try {
                    $redis->close();
                } catch (Throwable $error) {
                    // Connection teardown does not change delivery outcome.
                }
            }
        }
    }

    /**
     * Report a transport/storage failure and coalesce repeats in this process.
     *
     * @param string $message Failure summary without credentials.
     */
    private static function report_event_delivery_failure($message) {
        $message = sanitize_text_field((string) $message);
        do_action('digitalogic_panel_event_delivery_failed', $message);

        $signature = md5($message);
        $now = time();
        if (!isset(self::$reported_delivery_failures[$signature]) || ($now - self::$reported_delivery_failures[$signature]) >= 60) {
            self::$reported_delivery_failures[$signature] = $now;
            error_log('[Digitalogic panel events] ' . $message);
        }
    }

    public static function broadcast_panel_message($data = array()) {
        $data = is_array($data) ? $data : array('message' => (string) $data);
        if (empty($data['message']) && empty($data['title'])) {
            $data['message'] = __('Panel notification', 'digitalogic');
        }

        return self::record_event('panel.toast', $data);
    }

    private function format_user($user) {
        return array(
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => array_values((array) $user->roles),
            'registered' => $user->user_registered,
            'edit_url' => get_edit_user_link($user->ID),
        );
    }

    public function extend_product_search($search, $query) {
        if (!$query instanceof WP_Query || !$query->is_search()) {
            return $search;
        }

        $term = trim((string) $query->get('s'));
        if ($term === '') {
            return $search;
        }

        $post_type = $query->get('post_type');
        $is_product_search = empty($post_type) || $post_type === 'product' || (is_array($post_type) && in_array('product', $post_type, true));
        if (!$is_product_search) {
            return $search;
        }

        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';
        $search_body = preg_replace('/^\s*AND\s*/', '', (string) $search);

        $meta_clause = $wpdb->prepare(
            "{$wpdb->posts}.ID IN (
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key IN ('_sku', '_product_attributes', 'attribute_pa_model')
                AND meta_value LIKE %s
            )",
            $like
        );

        $variation_clause = $wpdb->prepare(
            "{$wpdb->posts}.ID IN (
                SELECT DISTINCT variations.post_parent
                FROM {$wpdb->posts} variations
                INNER JOIN {$wpdb->postmeta} variation_meta ON variations.ID = variation_meta.post_id
                WHERE variations.post_type = 'product_variation'
                AND variations.post_parent > 0
                AND variation_meta.meta_key IN ('_sku', 'attribute_pa_model')
                AND variation_meta.meta_value LIKE %s
            )",
            $like
        );

        $taxonomy_clause = $wpdb->prepare(
            "{$wpdb->posts}.ID IN (
                SELECT object_id
                FROM {$wpdb->term_relationships} relationships
                INNER JOIN {$wpdb->term_taxonomy} taxonomy ON relationships.term_taxonomy_id = taxonomy.term_taxonomy_id
                INNER JOIN {$wpdb->terms} terms ON taxonomy.term_id = terms.term_id
                WHERE taxonomy.taxonomy IN ('pa_model', 'product_cat')
                AND (terms.name LIKE %s OR terms.slug LIKE %s)
            )",
            $like,
            $like
        );

        if ($search_body === '') {
            return ' AND (' . $meta_clause . ' OR ' . $variation_clause . ' OR ' . $taxonomy_clause . ')';
        }

        return ' AND (' . $search_body . ' OR ' . $meta_clause . ' OR ' . $variation_clause . ' OR ' . $taxonomy_clause . ')';
    }

    public function hide_wp_armour_honeypot_notice() {
        ?>
        <style>
        .wpa-test-msg,
        .wp-armour-honeypot-notice,
        .wp-armour-admin-visible,
        .wpae-test-msg {
            display: none !important;
        }
        </style>
        <script>
        (function() {
            var phrases = ['WP Armour', 'honeypot trap enabled'];
            function hideNoise() {
                document.querySelectorAll('body *').forEach(function(node) {
                    var text = node.textContent || '';
                    if (phrases.every(function(phrase) { return text.indexOf(phrase) !== -1; }) && node.children.length < 8) {
                        node.style.display = 'none';
                    }
                });
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', hideNoise);
            } else {
                hideNoise();
            }
        })();
        </script>
        <?php
    }

    private function logo_url() {
        $logo_id = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        return $logo_url ?: get_site_icon_url(192);
    }

    private function current_admin_color() {
        if (class_exists('Digitalogic_Plugin_Admin_Branding')) {
            return Digitalogic_Plugin_Admin_Branding::current_user_admin_color();
        }

        $color = get_user_option('admin_color', get_current_user_id());
        return is_string($color) && $color !== '' ? $color : 'digitalogic-light';
    }

    private function current_theme_mode() {
        if (class_exists('Digitalogic_Plugin_Admin_Branding')) {
            return Digitalogic_Plugin_Admin_Branding::current_user_theme();
        }

        return $this->current_admin_color() === 'digitalogic-dark' ? 'dark' : 'light';
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
            'editWooCommerce' => 'Edit in WooCommerce',
            'modalEdit' => 'Dialog edit',
            'reorder' => 'Reorder',
            'copied' => 'Copied',
            'copy' => 'Copy',
            'commandUsage' => 'Command usage',
            'patrisSync' => 'Patris sync',
            'panelStyle' => 'Panel style',
            'modernStyle' => 'Modern',
            'defaultStyle' => 'Default',
            'themeAppearance' => 'Theme',
            'persian' => 'Persian',
            'columns' => 'Columns',
            'filter' => 'Filter',
            'all' => 'All',
            'min' => 'Min',
            'max' => 'Max',
            'clear' => 'Clear',
            'hideColumn' => 'Hide column',
            'sortAsc' => 'Sort ascending',
            'sortDesc' => 'Sort descending',
            'resetColumns' => 'Reset columns',
            'selectAll' => 'Select all',
            'selectRow' => 'Select row',
            'logout' => 'Sign out',
            'productTitle' => 'Product title',
            'partNumber' => 'Part Number',
            'categories' => 'Categories',
            'totalSales' => 'Total sales',
            'revisions' => 'Revisions',
            'availability' => 'Availability',
            'publish' => 'Published',
            'draft' => 'Draft',
            'pending' => 'Pending review',
            'private' => 'Private',
            'instock' => 'In stock',
            'outofstock' => 'Out of stock',
            'onbackorder' => 'On backorder',
            'editShortcutTitle' => 'Edit. Shift: dialog, Ctrl: panel page, Alt: WordPress.',
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
            'problemRows' => 'Problem rows',
            'patrisProducts' => 'Patris/API products',
            'foreignPrice' => 'Foreign price',
            'weight' => 'Weight',
            'finalPrice' => 'Final price',
            'patrisCurrency' => 'Feed currency',
            'patrisForeignPrice' => 'Feed foreign price',
            'patrisWeight' => 'Feed weight',
            'patrisFinalPrice' => 'Feed final price',
            'patrisLocation' => 'Feed location',
            'patrisUpdatedAt' => 'Feed updated',
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
            'update_currency' => 'Currency update',
            'update_product' => 'Product update',
            'product' => 'Product',
            'currency' => 'Currency',
            'user' => 'User',
            'withImage' => 'Has image',
            'withoutImage' => 'Missing image',
            'bulkActions' => 'Bulk actions',
            'compactTableMode' => 'Compact table',
            'publishSelected' => 'Publish selected',
            'draftSelected' => 'Move selected to draft',
            'markInStock' => 'Mark in stock',
            'markOutOfStock' => 'Mark out of stock',
            'exportSelected' => 'Export selected',
            'pinEditor' => 'Pin editor',
            'unpinEditor' => 'Unpin editor',
            'openToolbox' => 'Open in toolbox',
            'createUser' => 'Create user',
            'deleteSelected' => 'Delete selected',
            'delete' => 'Delete',
            'username' => 'Username',
            'purchaseHistory' => 'Purchase history',
            'confirmDeleteUser' => 'Delete this user?',
            'customer' => 'Customer',
            'subscriber' => 'Subscriber',
            'shopManager' => 'Shop manager',
            'administrator' => 'Administrator',
        );
    }

    private function translations_fa() {
        return array(
            'dir' => 'rtl',
            'update_currency' => 'به روزرسانی ارز',
            'update_product' => 'به روزرسانی کالا',
            'product' => 'کالا',
            'currency' => 'ارز',
            'user' => 'کاربر',
            'withImage' => 'دارای تصویر',
            'withoutImage' => 'بدون تصویر',
            'bulkActions' => 'عملیات گروهی',
            'compactTableMode' => 'حالت فشرده جدول',
            'publishSelected' => 'انتشار انتخاب شده ها',
            'draftSelected' => 'انتقال به پیش نویس',
            'markInStock' => 'موجود کردن',
            'markOutOfStock' => 'ناموجود کردن',
            'exportSelected' => 'خروجی انتخاب شده ها',
            'pinEditor' => 'سنجاق کردن ویرایشگر',
            'unpinEditor' => 'برداشتن سنجاق',
            'openToolbox' => 'باز کردن در جعبه ابزار',
            'createUser' => 'ایجاد کاربر',
            'deleteSelected' => 'حذف انتخاب شده ها',
            'delete' => 'حذف',
            'username' => 'نام کاربری',
            'purchaseHistory' => 'سوابق خرید',
            'confirmDeleteUser' => 'این کاربر حذف شود؟',
            'customer' => 'مشتری',
            'subscriber' => 'مشترک',
            'shopManager' => 'مدیر فروشگاه',
            'administrator' => 'مدیرکل',
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
            'editWooCommerce' => 'ویرایش در ووکامرس',
            'modalEdit' => 'ویرایش پنجره ای',
            'reorder' => 'چینش',
            'copied' => 'کپی شد',
            'copy' => 'کپی',
            'commandUsage' => 'راهنمای دستورها',
            'patrisSync' => 'همگام سازی پاتریس',
            'panelStyle' => 'سبک پنل',
            'modernStyle' => 'مدرن',
            'defaultStyle' => 'پیش فرض',
            'themeAppearance' => 'ظاهر',
            'persian' => 'فارسی',
            'columns' => 'ستون ها',
            'filter' => 'فیلتر',
            'all' => 'همه',
            'min' => 'حداقل',
            'max' => 'حداکثر',
            'clear' => 'پاک کردن',
            'hideColumn' => 'مخفی کردن ستون',
            'sortAsc' => 'مرتب سازی صعودی',
            'sortDesc' => 'مرتب سازی نزولی',
            'resetColumns' => 'بازنشانی ستون ها',
            'selectAll' => 'انتخاب همه',
            'selectRow' => 'انتخاب ردیف',
            'logout' => 'خروج',
            'productTitle' => 'عنوان کالا',
            'partNumber' => 'Part Number',
            'categories' => 'دسته بندی ها',
            'totalSales' => 'فروش کل',
            'revisions' => 'بازبینی ها',
            'availability' => 'وضعیت موجودی',
            'publish' => 'منتشر شده',
            'draft' => 'پیش نویس',
            'pending' => 'در انتظار بررسی',
            'private' => 'خصوصی',
            'instock' => 'موجود',
            'outofstock' => 'ناموجود',
            'onbackorder' => 'پیش خرید',
            'editShortcutTitle' => 'ویرایش. Shift: پنجره، Ctrl: صفحه پنل، Alt: وردپرس.',
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
            'problemRows' => 'ردیف های مشکل دار',
            'patrisProducts' => 'محصولات Patris/API',
            'foreignPrice' => 'قیمت ارزی',
            'weight' => 'وزن',
            'finalPrice' => 'قیمت نهایی',
            'patrisCurrency' => 'ارز API',
            'patrisForeignPrice' => 'قیمت ارزی API',
            'patrisWeight' => 'وزن API',
            'patrisFinalPrice' => 'قیمت نهایی API',
            'patrisLocation' => 'محل API',
            'patrisUpdatedAt' => 'به روزرسانی API',
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
