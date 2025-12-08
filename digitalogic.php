<?php
/**
 * Plugin Name: Digitalogic WooCommerce Extension
 * Plugin URI: https://github.com/atomicdeploy/digitalogic-wp
 * Description: Custom dynamic pricing, stock manager, and POS integration for Digitalogic electronic components shop. Supports bulk operations, import/export, and external API integration.
 * Version: 1.0.0
 * Author: Digitalogic
 * Author URI: https://digitalogic.ir
 * Text Domain: digitalogic
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
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
define('DIGITALOGIC_VERSION', '1.0.0');
define('DIGITALOGIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DIGITALOGIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DIGITALOGIC_PLUGIN_BASENAME', plugin_basename(__FILE__));

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
    }
    
    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        add_action('plugins_loaded', array($this, 'init'), 0);
        add_action('init', array($this, 'load_textdomain'));
    }
    
    /**
     * Include required core files
     */
    private function includes() {
        // Core includes
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-options.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-product-manager.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-pricing.php';
        require_once DIGITALOGIC_PLUGIN_DIR . 'includes/class-import-export.php';
        
        // Admin includes
        if (is_admin()) {
            require_once DIGITALOGIC_PLUGIN_DIR . 'includes/admin/class-admin.php';
            require_once DIGITALOGIC_PLUGIN_DIR . 'includes/admin/class-product-table.php';
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
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        // Declare HPOS compatibility
        $this->declare_hpos_compatibility();
        
        // Initialize components
        Digitalogic_Options::instance();
        Digitalogic_Logger::instance();
        Digitalogic_Product_Manager::instance();
        Digitalogic_Pricing::instance();
        Digitalogic_Import_Export::instance();
        
        if (is_admin()) {
            Digitalogic_Admin::instance();
        }
        
        Digitalogic_REST_API::instance();
        Digitalogic_Webhooks::instance();
        
        do_action('digitalogic_init');
    }
    
    /**
     * Declare HPOS compatibility
     */
    private function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
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
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
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
     */
    private function set_default_options() {
        if (get_option('digitalogic_dollar_price') === false) {
            add_option('digitalogic_dollar_price', '0');
        }
        if (get_option('digitalogic_yuan_price') === false) {
            add_option('digitalogic_yuan_price', '0');
        }
        if (get_option('digitalogic_update_date') === false) {
            add_option('digitalogic_update_date', date('ymd'));
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
}

/**
 * Returns the main instance of Digitalogic
 */
function digitalogic() {
    return Digitalogic::instance();
}

// Initialize the plugin
digitalogic();
