<?php
/**
 * Admin Class
 * 
 * Handles admin interface and pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Admin {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private $page_hooks = array();
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_digitalogic_get_products', array($this, 'ajax_get_products'));
        add_action('wp_ajax_digitalogic_update_product', array($this, 'ajax_update_product'));
        add_action('wp_ajax_digitalogic_bulk_update', array($this, 'ajax_bulk_update'));
        add_action('wp_ajax_digitalogic_update_currency', array($this, 'ajax_update_currency'));
        add_action('wp_ajax_digitalogic_export', array($this, 'ajax_export'));
        add_action('wp_ajax_digitalogic_import', array($this, 'ajax_import'));
        add_action('wp_ajax_digitalogic_get_logs', array($this, 'ajax_get_logs'));
    }
    
    /**
     * Add admin menu
     */
    public function add_menu() {
        // Get the custom icon
        $icon_svg = $this->get_menu_icon();
        
        $this->page_hooks[] = add_menu_page(
            __('Dashboard', 'digitalogic'),
            __('Digitalogic', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic',
            array($this, 'render_dashboard'),
            $icon_svg,
            56
        );
        
        // Add explicit Dashboard submenu to override the auto-generated one
        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('Dashboard', 'digitalogic'),
            __('Dashboard', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic',
            array($this, 'render_dashboard')
        );
        
        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('Product List', 'digitalogic'),
            __('Products', 'digitalogic'),
            'manage_woocommerce',
            'product-list',
            array($this, 'render_products_page')
        );
        
        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('Price Settings', 'digitalogic'),
            __('Currency', 'digitalogic'),
            'manage_woocommerce',
            'price-settings',
            array($this, 'render_currency_page')
        );
        
        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('Import/Export', 'digitalogic'),
            __('Import/Export', 'digitalogic'),
            'manage_woocommerce',
            'import-export',
            array($this, 'render_import_export_page')
        );
        
        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('Activity Logs', 'digitalogic'),
            __('Logs', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic-logs',
            array($this, 'render_logs_page')
        );
        
        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('Status & Diagnostics', 'digitalogic'),
            __('Status', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic-status',
            array($this, 'render_status_page')
        );
    }
    
    /**
     * Fallback SVG icon
     */
    private function get_fallback_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"></svg>';
    }
    
    /**
     * Get menu icon as data URL
     * 
     * @return string Base64-encoded SVG data URL
     */
    private function get_menu_icon() {
        // Read SVG file instead of hardcoding it
        $svg_file = DIGITALOGIC_PLUGIN_DIR . 'assets/images/icon-mono.svg';
        
        // Validate that the file path is within the plugin directory
        $real_svg_file = realpath($svg_file);
        $real_plugin_dir = realpath(DIGITALOGIC_PLUGIN_DIR);
        
        if ($real_svg_file && $real_plugin_dir && strpos($real_svg_file, $real_plugin_dir) === 0) {
            $svg = file_get_contents($real_svg_file);
            
            // Check if file was successfully read
            if ($svg === false) {
                // Log error and use fallback SVG
                error_log('Digitalogic: Unable to read menu icon file: ' . $svg_file);
                $svg = $this->get_fallback_svg();
            }
        } else {
            // Log error and use fallback SVG
            error_log('Digitalogic: Menu icon file not found or invalid path: ' . $svg_file);
            $svg = $this->get_fallback_svg();
        }
        
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Check if we're on any of our admin pages
        // Use the registered page hooks from add_menu() and add_submenu_page()
        if (!in_array($hook, $this->page_hooks) && strpos($hook, 'digitalogic') === false) {
            return;
        }
        
        // DataTables - Local files
        wp_enqueue_style('datatables', DIGITALOGIC_PLUGIN_URL . 'assets/vendor/datatables/jquery.dataTables.min.css', array(), '1.13.7');
        wp_enqueue_script('datatables', DIGITALOGIC_PLUGIN_URL . 'assets/vendor/datatables/jquery.dataTables.min.js', array('jquery'), '1.13.7', true);
        
        // Plugin styles
        wp_enqueue_style('digitalogic-admin', DIGITALOGIC_PLUGIN_URL . 'assets/css/admin.css', array(), DIGITALOGIC_VERSION);
        
        // Plugin scripts
        wp_enqueue_script('digitalogic-admin', DIGITALOGIC_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'datatables'), DIGITALOGIC_VERSION, true);
        
        // Localize script
        wp_localize_script('digitalogic-admin', 'digitalogic', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('digitalogic_nonce'),
            'i18n' => array(
                'confirm_bulk_update' => __('Are you sure you want to update these products?', 'digitalogic'),
                'success' => __('Success', 'digitalogic'),
                'error' => __('Error', 'digitalogic'),
                'loading' => __('Loading...', 'digitalogic'),
            )
        ));
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard() {
        $options = Digitalogic_Options::instance();
        $manager = Digitalogic_Product_Manager::instance();
        
        $dollar_price = $options->get_dollar_price();
        $yuan_price = $options->get_yuan_price();
        $update_date = $options->get_update_date_formatted();
        $product_count = $manager->get_product_count();
        
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/dashboard.php';
    }
    
    /**
     * Render products page
     */
    public function render_products_page() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            ?>
            <div class="wrap">
                <h1><?php _e('Product Management', 'digitalogic'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('WooCommerce must be installed and activated to use this feature.', 'digitalogic'); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/products.php';
    }
    
    /**
     * Render currency page
     */
    public function render_currency_page() {
        $options = Digitalogic_Options::instance();
        
        if (isset($_POST['submit']) && check_admin_referer('digitalogic_currency_update')) {
            $dollar_price = floatval($_POST['dollar_price']);
            $yuan_price = floatval($_POST['yuan_price']);
            
            $options->set_dollar_price($dollar_price);
            $options->set_yuan_price($yuan_price);
            
            // Recalculate dynamic prices
            if (isset($_POST['recalculate_prices'])) {
                $pricing = Digitalogic_Pricing::instance();
                $results = $pricing->bulk_recalculate_prices();
                
                echo '<div class="notice notice-success"><p>' . 
                    sprintf(__('Updated %d products successfully', 'digitalogic'), $results['success']) . 
                    '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>' . __('Currency rates updated', 'digitalogic') . '</p></div>';
            }
        }
        
        $dollar_price = $options->get_dollar_price();
        $yuan_price = $options->get_yuan_price();
        $update_date = $options->get_update_date_formatted();
        $update_date_relative = $options->get_update_date_relative();
        $currency_status = $options->get_currency_status();
        
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/currency.php';
    }
    
    /**
     * Render import/export page
     */
    public function render_import_export_page() {
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/import-export.php';
    }
    
    /**
     * Render logs page
     */
    public function render_logs_page() {
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/logs.php';
    }
    
    /**
     * Render status & diagnostics page
     */
    public function render_status_page() {
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/status.php';
    }
    
    /**
     * AJAX: Get products
     */
    public function ajax_get_products() {
        try {
            check_ajax_referer('digitalogic_nonce', 'nonce');
            
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Unauthorized');
                return;
            }
            
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
            $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
            
            $manager = Digitalogic_Product_Manager::instance();
            $products = $manager->get_products(array(
                'page' => $page,
                'limit' => $limit,
                'search' => $search
            ));
            
            $total = $manager->get_product_count();
            
            wp_send_json_success(array(
                'products' => $products,
                'total' => $total
            ));
        } catch (Exception $e) {
            error_log('Digitalogic: Error fetching products - ' . $e->getMessage());
            wp_send_json_error('Error loading products: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Update product
     */
    public function ajax_update_product() {
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $data = isset($_POST['data']) ? $_POST['data'] : array();
        
        $manager = Digitalogic_Product_Manager::instance();
        $result = $manager->update_product($product_id, $data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Product updated');
    }
    
    /**
     * AJAX: Bulk update
     */
    public function ajax_bulk_update() {
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $updates = isset($_POST['updates']) ? $_POST['updates'] : array();
        
        $manager = Digitalogic_Product_Manager::instance();
        $results = $manager->bulk_update($updates);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Update currency
     */
    public function ajax_update_currency() {
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $dollar_price = isset($_POST['dollar_price']) ? floatval($_POST['dollar_price']) : 0;
        $yuan_price = isset($_POST['yuan_price']) ? floatval($_POST['yuan_price']) : 0;
        
        $options = Digitalogic_Options::instance();
        $options->set_dollar_price($dollar_price);
        $options->set_yuan_price($yuan_price);
        
        wp_send_json_success('Currency updated');
    }
    
    /**
     * AJAX: Export
     */
    public function ajax_export() {
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
        $product_ids = isset($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
        
        $import_export = Digitalogic_Import_Export::instance();
        
        if ($format === 'json') {
            $filepath = $import_export->export_json($product_ids);
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel($product_ids);
        } else {
            $filepath = $import_export->export_csv($product_ids);
        }
        
        if (is_wp_error($filepath)) {
            wp_send_json_error($filepath->get_error_message());
        }
        
        $upload_dir = wp_upload_dir();
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $filepath);
        
        wp_send_json_success(array(
            'url' => $file_url,
            'filepath' => $filepath
        ));
    }
    
    /**
     * AJAX: Import
     */
    public function ajax_import() {
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        if (!isset($_FILES['file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        $file = $_FILES['file'];
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_send_json_error($upload['error']);
        }
        
        $filepath = $upload['file'];
        $import_export = Digitalogic_Import_Export::instance();
        
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        
        if ($extension === 'json') {
            $results = $import_export->import_json($filepath);
        } elseif ($extension === 'xlsx' || $extension === 'xls') {
            $results = $import_export->import_excel($filepath);
        } else {
            $results = $import_export->import_csv($filepath);
        }
        
        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
        }
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $offset = ($page - 1) * $limit;
        
        $logger = Digitalogic_Logger::instance();
        $logs = $logger->get_logs(array(
            'limit' => $limit,
            'offset' => $offset
        ));
        
        wp_send_json_success(array(
            'logs' => $logs
        ));
    }
}
