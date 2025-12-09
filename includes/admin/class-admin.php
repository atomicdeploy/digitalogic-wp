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
        
        add_menu_page(
            __('Reports', 'digitalogic'),
            __('Digitalogic', 'digitalogic'),
            'manage_woocommerce',
            'reports',
            array($this, 'render_dashboard'),
            $icon_svg,
            56
        );
        
        add_submenu_page(
            'reports',
            __('Product List', 'digitalogic'),
            __('Products', 'digitalogic'),
            'manage_woocommerce',
            'product-list',
            array($this, 'render_products_page')
        );
        
        add_submenu_page(
            'reports',
            __('Price Settings', 'digitalogic'),
            __('Currency', 'digitalogic'),
            'manage_woocommerce',
            'price-settings',
            array($this, 'render_currency_page')
        );
        
        add_submenu_page(
            'reports',
            __('Import/Export', 'digitalogic'),
            __('Import/Export', 'digitalogic'),
            'manage_woocommerce',
            'import-export',
            array($this, 'render_import_export_page')
        );
        
        add_submenu_page(
            'reports',
            __('Activity Logs', 'digitalogic'),
            __('Logs', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic-logs',
            array($this, 'render_logs_page')
        );
        
        add_submenu_page(
            'reports',
            __('Status & Diagnostics', 'digitalogic'),
            __('Status', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic-status',
            array($this, 'render_status_page')
        );
    }
    
    /**
     * Get menu icon as data URL
     * 
     * @return string Base64-encoded SVG data URL
     */
    private function get_menu_icon() {
        // Monochrome SVG for WordPress admin menu (optimized for 20x20px)
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 552.72 552.72"><g transform="translate(0, 38.65)"><path d="M115.2,235.05c1.84,9.53-6.46,17.83-15.99,15.99-5.35-1.03-9.63-5.31-10.66-10.66-1.84-9.53,6.46-17.83,15.99-15.99,5.35,1.03,9.63,5.31,10.66,10.66ZM133.9,226.4c-4.2-14.94-22.62-24.86-38.15-22.33-7.77,1.26-14.81,5.64-19.83,11.76s-8,14.01-8,21.89,2.98,15.76,8,21.89,12.06,10.5,19.83,11.76c15.53,2.53,33.95-7.39,38.15-22.33h113.2v-22.64h-113.2ZM65.98,135.84c-4.2-14.94-22.62-24.86-38.15-22.33-7.77,1.26-14.81,5.64-19.83,11.76-5.01,6.13-8,14.01-8,21.89s2.98,15.76,8,21.89c5.01,6.13,12.06,10.5,19.83,11.76,15.53,2.53,33.95-7.39,38.15-22.33h58.7l45.28,45.28h77.14v-22.64h-67.76l-45.28-45.28s-68.08,0-68.08,0ZM36.63,314.95c-9.53-1.84-17.83,6.46-15.99,15.99,1.03,5.35,5.31,9.63,10.66,10.66,9.53,1.84,17.83-6.46,15.99-15.99-1.03-5.35-5.31-9.63-10.66-10.66ZM36.63,133.83c-9.53-1.84-17.83,6.46-15.99,15.99,1.03,5.35,5.31,9.63,10.66,10.66,9.53,1.84,17.83-6.46,15.99-15.99-1.03-5.35-5.31-9.63-10.66-10.66ZM65.98,339.59h68.08l45.28-45.28h67.76v-22.64h-77.14l-45.28,45.28h-58.7c-4.2-14.94-22.62-24.86-38.15-22.33-7.77,1.26-14.81,5.64-19.83,11.76C2.99,312.51,0,320.39,0,328.27s2.98,15.76,8,21.89c5.01,6.13,12.06,10.5,19.83,11.76,15.53,2.53,33.95-7.39,38.15-22.33h0ZM308.22,244.51v38.49h13.58v-38.49h38.49v-13.58h-38.49v-38.49h-13.58v38.49h-38.49v13.58h38.49ZM201.81,419.97c-4.92,11.42-19.61,18.01-31.8,15.12-12.2-2.88-21.91-15.23-21.91-27.57s9.71-24.69,21.91-27.57,26.88,3.69,31.8,15.11h90.54c35.29.79,76.88-14.16,106.78-41.77,29.89-27.61,48.08-67.89,50.09-103.13h-55.74c-2.29,22.22-14.5,46.95-33.62,63.86-19.13,16.91-45.16,26.01-67.49,25.56h-45.28v-22.64h-58.38l-45.28,45.28h-54.82v113.2h226.4c28.02-.08,58.12-4.83,87.5-16.69,29.38-11.86,58.03-30.83,81.01-53.63,45.96-45.59,69.2-106.5,69.2-167.4s-23.25-121.81-69.2-167.4c-22.98-22.8-51.63-41.77-81.01-53.63C373.14,4.83,343.03.08,315.01,0H88.62v113.2h54.82l45.28,45.28h58.38v-22.64h45.28c22.33-.45,48.37,8.65,67.49,25.56,19.13,16.91,31.34,41.65,33.62,63.86h55.74c-2.01-35.24-20.2-75.52-50.08-103.13-29.89-27.61-71.48-42.55-106.77-41.77h-90.56c-4.92,11.42-19.61,18-31.8,15.12-12.2-2.88-21.91-15.23-21.91-27.57s9.71-24.69,21.91-27.57c12.2-2.88,26.88,3.69,31.8,15.12h90.56c47.24-.43,95.12,19.6,128.87,53.38,33.75,33.79,53.38,81.33,53.38,128.87s-19.63,95.09-53.39,128.87c-33.76,33.78-81.64,53.82-128.88,53.38h-90.55Z" fill="#a7aaad" fill-rule="evenodd"/></g></svg>';
        
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        // Check if we're on any of our admin pages
        if (strpos($hook, 'reports') === false && strpos($hook, 'digitalogic') === false) {
            return;
        }
        
        // DataTables
        wp_enqueue_style('datatables', 'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css', array(), '1.13.7');
        wp_enqueue_script('datatables', 'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', array('jquery'), '1.13.7', true);
        
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
        check_ajax_referer('digitalogic_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
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
        } else {
            $filepath = $import_export->export_csv($product_ids);
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
