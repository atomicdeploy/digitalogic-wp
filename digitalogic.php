<?php
/**
 * Plugin Name: Digitalogic WooCommerce Extension
 * Plugin URI: https://github.com/atomicdeploy/digitalogic-wp
 * Description: Custom dynamic pricing, stock manager, and POS integration for Digitalogic electronic components shop. Supports bulk operations, import/export, and external API integration.
 * Version: 1.6.5
 * Author: Digitalogic
 * Author URI: https://digitalogic.ir
 * Text Domain: digitalogic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define( 'DIGITALOGIC_VERSION', '1.6.5' );
define( 'DIGITALOGIC_PBX_SCHEMA_VERSION', '3' );
define('DIGITALOGIC_MIN_PHP_VERSION', '8.3');
define('DIGITALOGIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DIGITALOGIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DIGITALOGIC_PLUGIN_BASENAME', plugin_basename(__FILE__));

if (version_compare(PHP_VERSION, DIGITALOGIC_MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', 'digitalogic_php_version_notice');

    return;
}

function digitalogic_php_version_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <?php
            printf(
                esc_html__('Digitalogic requires PHP %1$s or newer. The current server is running PHP %2$s. Please upgrade PHP to a supported release before activating Digitalogic.', 'digitalogic'),
                esc_html(DIGITALOGIC_MIN_PHP_VERSION),
                esc_html(PHP_VERSION)
            );
            ?>
        </p>
    </div>
    <?php
}

// Load Composer autoloader
if (file_exists(DIGITALOGIC_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once DIGITALOGIC_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main Digitalogic Plugin Class
 */
final class Digitalogic {

    /**
     * The single instance of the class
     */
    private static $instance = null;

    /**
     * Main Digitalogic Instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->includes();
        $this->init_early_integrations();
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Declare HPOS compatibility before WooCommerce initializes
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        add_action('plugins_loaded', array($this, 'init'), 0);
        add_action('init', array($this, 'load_textdomain'));

        // Plugin action links
        add_filter('plugin_action_links_' . DIGITALOGIC_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
    }

    /**
     * Include required core files
     */
    private function includes() {
        // Core includes
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-number-formatter.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-currency-date-formatter.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-options.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-currency-shortcodes.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-woocommerce-currency-status.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-access-control.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/panel/class-digitalogic-panel-error-page.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-product-query.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-product-manager.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-pricing.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-import-export.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-unit-converter.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-product-identifier-resolver.php';
		require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-product-metadata-inspector.php';
		require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-product-write-lock.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-pricing-input-credential.php'; // phpcs:ignore
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-patris-feed.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-product-sync-receiver.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-shipping-method-service.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-patris-catalog-materializer.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-google-sheets-catalog.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-digitalogic-google-sheets-writeback.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-report-engine.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-command-dispatcher.php';

        // WebSocket support
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/websocket/class-websocket.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/websocket/class-websocket-auth.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/websocket/class-websocket-server.php';

        // External panel and migrated site integrations
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-laravel-bridge.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-smsir-integration.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-comment-guard.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-admin-branding.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-label-overrides.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-auth-page.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-digitalogic-sidebar-login.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-desktop-app.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-frontend-search.php';
		require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-pbx-phone.php';
		require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-call-verification.php';
		require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-voice-notifications.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-product-identity.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-storefront-catalog.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-homepage-showcase.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-storefront-product-table.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/class-storefront-order-forms.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/panel/class-panel.php';

        // Admin includes
        if (is_admin()) {
            require_once DIGITALOGIC_PLUGIN_DIR . 'includes/admin/class-admin.php';
			require_once DIGITALOGIC_PLUGIN_DIR . 'includes/admin/class-digitalogic-product-table.php';
        }

        // API includes
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/api/class-rest-api.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/api/class-webhooks.php';

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            require_once DIGITALOGIC_PLUGIN_DIR . 'includes/cli/class-cli-commands.php';
        }
    }

    /**
     * Register integrations that must hook before plugins_loaded.
     */
    private function init_early_integrations() {
        Digitalogic_Label_Overrides::init();
        Digitalogic_Plugin_Admin_Branding::init();
        Digitalogic_Plugin_Auth_Routes::init();
        Digitalogic_Sidebar_Login::init();
        Digitalogic_Desktop_App::init();
        Digitalogic_Frontend_Search::instance();
		Digitalogic_Call_Verification::instance();
        Digitalogic_Product_Identity::instance();
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

		if ( DIGITALOGIC_PBX_SCHEMA_VERSION !== (string) get_option( 'digitalogic_pbx_schema_version', '' ) ) {
			$this->install_pbx_schema();
		}

        // Initialize components
        Digitalogic_Options::instance();
        Digitalogic_Currency_Shortcodes::instance();
        Digitalogic_WooCommerce_Currency_Status::instance();
        Digitalogic_Logger::instance();
		Digitalogic_Product_Write_Lock::instance();
        Digitalogic_Product_Manager::instance();
        Digitalogic_Pricing::instance();
        Digitalogic_Import_Export::instance();
        Digitalogic_Patris_Feed::instance();
        Digitalogic_Shipping_Method_Service::instance();
        Digitalogic_Google_Sheets_Catalog::instance();
        Digitalogic_Google_Sheets_Writeback::instance();
        Digitalogic_Report_Engine::instance();
        Digitalogic_Command_Dispatcher::instance();
        Digitalogic_WebSocket::instance();
        Digitalogic_Laravel_Bridge::instance();
        Digitalogic_Panel::instance();
        Digitalogic_Comment_Guard::instance();
        Digitalogic_Storefront_Catalog::instance();
        Digitalogic_Homepage_Showcase::instance();
        Digitalogic_Storefront_Product_Table::instance();
        Digitalogic_Storefront_Order_Forms::instance();
		Digitalogic_Voice_Notifications::instance();

        if (is_admin()) {
            Digitalogic_Admin::instance();
        }

        Digitalogic_REST_API::instance();
        Digitalogic_Webhooks::instance();

        do_action('digitalogic_init');
    }

    /**
     * Declare HPOS compatibility
     *
     * This must be called on the 'before_woocommerce_init' hook to properly
     * declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
     *
     * @link https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            foreach (array('custom_order_tables', 'cart_checkout_blocks') as $feature_id) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility($feature_id, __FILE__, true);
            }

            $wp_parsidate_file = WP_PLUGIN_DIR . '/wp-parsidate/wp-parsidate.php';
            if (file_exists($wp_parsidate_file)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $wp_parsidate_file, true);
            }
        }
    }

    /**
     * Check HPOS compatibility status (for debugging)
     *
     * @return array Status information
     */
    public function get_hpos_status() {
        $status = array(
            'hpos_enabled' => false,
            'plugin_compatible' => false,
            'using_custom_tables' => false
        );

        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            $status['hpos_enabled'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $status['using_custom_tables'] = $status['hpos_enabled'];
        }

        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            $status['plugin_compatible'] = true;
        }

        return $status;
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain('digitalogic', false, dirname(DIGITALOGIC_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Plugin activation
     */
	public function activate() {
		// Create database tables
		$this->create_tables();
		$this->install_pbx_schema();

        // Set default options
        $this->set_default_options();

        // Flush rewrite rules
        flush_rewrite_rules();
	}

	/**
	 * Mark PBX storage ready only after both installers verify all invariants.
	 *
	 * @return bool
	 */
	private function install_pbx_schema(): bool {
		$call_ready = Digitalogic_Call_Verification::install();
		$voice_ready = Digitalogic_Voice_Notifications::install();
		if ( $call_ready && $voice_ready ) {
			update_option( 'digitalogic_pbx_schema_version', DIGITALOGIC_PBX_SCHEMA_VERSION, false );
			if ( DIGITALOGIC_PBX_SCHEMA_VERSION === (string) get_option( 'digitalogic_pbx_schema_version', '' ) ) {
				return true;
			}
		}

		Digitalogic_Call_Verification::mark_schema_unready();
		Digitalogic_Voice_Notifications::mark_schema_unready();
		delete_option( 'digitalogic_pbx_schema_version' );
		return false;
	}

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Create custom database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Activity log table
        $table_name = $wpdb->prefix . 'digitalogic_logs';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            action varchar(255) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) UNSIGNED DEFAULT NULL,
            old_value longtext DEFAULT NULL,
            new_value longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Set default options
     *
     * Note: Options are synchronized with ACF storage (options_ prefix)
     * Both get_option('dollar_price') and get_field('dollar_price', 'option') access the same data
     */
    private function set_default_options() {
        // Initialize ACF storage (options_ prefix) - this is where ACF stores option fields
        if (get_option('options_dollar_price') === false) {
            add_option('options_dollar_price', '0');
        }
        if (get_option('options_yuan_price') === false) {
            add_option('options_yuan_price', '0');
        }
        if (get_option('options_update_date') === false) {
            add_option('options_update_date', date('ymd'));
        }

        // Also initialize direct options for backward compatibility
        if (get_option('dollar_price') === false) {
            add_option('dollar_price', '0');
        }
        if (get_option('yuan_price') === false) {
            add_option('yuan_price', '0');
        }
        if (get_option('update_date') === false) {
            add_option('update_date', date('ymd'));
        }

        // Migration: Move old prefixed options to ACF storage
        $prefixed_dollar = get_option('digitalogic_dollar_price');
        if ($prefixed_dollar !== false) {
            update_option('options_dollar_price', $prefixed_dollar);
            update_option('dollar_price', $prefixed_dollar);
            delete_option('digitalogic_dollar_price');
        }

        $prefixed_yuan = get_option('digitalogic_yuan_price');
        if ($prefixed_yuan !== false) {
            update_option('options_yuan_price', $prefixed_yuan);
            update_option('yuan_price', $prefixed_yuan);
            delete_option('digitalogic_yuan_price');
        }

        $prefixed_date = get_option('digitalogic_update_date');
        if ($prefixed_date !== false) {
            update_option('options_update_date', $prefixed_date);
            update_option('update_date', $prefixed_date);
            delete_option('digitalogic_update_date');
        }

        // Sync: If direct options exist but ACF storage doesn't, copy to ACF storage
        if (get_option('options_dollar_price') === '0' && get_option('dollar_price') !== '0') {
            update_option('options_dollar_price', get_option('dollar_price'));
        }
        if (get_option('options_yuan_price') === '0' && get_option('yuan_price') !== '0') {
            update_option('options_yuan_price', get_option('yuan_price'));
        }
        if (get_option('update_date') !== false) {
            update_option('options_update_date', get_option('update_date'));
        }

        if (get_option('digitalogic_patris_feed_settings') === false) {
            add_option('digitalogic_patris_feed_settings', array(
                'api_url' => '',
                'api_token' => '',
                'selected_warehouses' => array(),
                'legacy_url_replacements' => array(),
                'image_quality_thresholds' => array(
                    'very_low' => 180,
                    'low' => 250,
                    'review' => 350,
                    'soft_review' => 450,
                ),
                'stale_after_hours' => 48,
                'sync_interval' => '',
            ), '', 'no');
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Digitalogic requires WooCommerce to be installed and activated.', 'digitalogic'); ?></p>
        </div>
        <?php
    }

    /**
     * Add plugin action links on Plugins page
     *
     * @param array $links Existing links
     * @return array Modified links
     */
    public function plugin_action_links($links) {
        $custom_links = array(
            'settings' => '<a href="' . esc_url(admin_url('admin.php?page=digitalogic')) . '">' . __('Dashboard', 'digitalogic') . '</a>',
            'currency' => '<a href="' . esc_url(admin_url('admin.php?page=price-settings')) . '">' . __('Currency', 'digitalogic') . '</a>',
            'products' => '<a href="' . esc_url(admin_url('admin.php?page=product-list')) . '">' . __('Products', 'digitalogic') . '</a>',
        );

        return array_merge($custom_links, $links);
    }

    /**
     * Add plugin row meta links on Plugins page
     *
     * @param array $links Existing row meta
     * @param string $file Plugin file
     * @return array Modified row meta
     */
    public function plugin_row_meta($links, $file) {
        if ($file === DIGITALOGIC_PLUGIN_BASENAME) {
            $row_meta = array(
                'docs' => '<a href="https://github.com/atomicdeploy/digitalogic-wp#readme" target="_blank">' . __('Documentation', 'digitalogic') . '</a>',
                'api' => '<a href="' . esc_url(admin_url('admin.php?page=digitalogic-status')) . '">' . __('API & Status', 'digitalogic') . '</a>',
                'support' => '<a href="https://github.com/atomicdeploy/digitalogic-wp/issues" target="_blank">' . __('Support', 'digitalogic') . '</a>',
            );

            $links = array_merge($links, $row_meta);
        }

        return $links;
    }
}

/**
 * Returns the main instance of Digitalogic
 */
function digitalogic() {
    return Digitalogic::instance();
}

// Initialize the plugin
digitalogic();
