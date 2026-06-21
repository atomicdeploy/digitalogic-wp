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
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_admin_bar_styles'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_bar_styles'));
        add_action('admin_footer', array($this, 'remove_unwanted_admin_notices'), 1000);
        add_action('wp_ajax_digitalogic_get_products', array($this, 'ajax_get_products'));
        add_action('wp_ajax_digitalogic_update_product', array($this, 'ajax_update_product'));
        add_action('wp_ajax_digitalogic_bulk_update', array($this, 'ajax_bulk_update'));
        add_action('wp_ajax_digitalogic_update_currency', array($this, 'ajax_update_currency'));
        add_action('wp_ajax_digitalogic_export', array($this, 'ajax_export'));
        add_action('wp_ajax_digitalogic_import', array($this, 'ajax_import'));
        add_action('wp_ajax_digitalogic_get_logs', array($this, 'ajax_get_logs'));
    }

    /**
     * Remove noisy third-party notices that are not actionable for site editors.
     */
    public function remove_unwanted_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.notice, .updated, .error, .woocommerce-message, .woocommerce-warning').forEach(function(notice) {
                var text = notice.textContent || '';
                if (
                    text.indexOf('Zero Spam Enhanced Protection') !== -1 &&
                    text.indexOf('missing a valid license key') !== -1
                ) {
                    notice.remove();
                }
            });
        });
        </script>
        <?php
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

        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            __('UI Settings', 'digitalogic'),
            __('UI Settings', 'digitalogic'),
            'manage_options',
            'digitalogic-ui-settings',
            array($this, 'render_ui_settings_page')
        );

        $this->page_hooks[] = add_submenu_page(
            'digitalogic',
            $this->panel_label(),
            $this->panel_label(),
            'manage_woocommerce',
            'digitalogic-panel',
            array($this, 'render_panel_page')
        );
    }
    
    /**
     * Add admin bar menu
     * 
     * @param WP_Admin_Bar $wp_admin_bar WordPress admin bar object
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Add parent menu item
        $wp_admin_bar->add_node(array(
            'id'    => 'digitalogic',
            'title' => '<span class="ab-icon dashicons dashicons-cart"></span><span class="ab-label">' . __('Digitalogic', 'digitalogic') . '</span>',
            'href'  => admin_url('admin.php?page=digitalogic'),
            'meta'  => array(
                'title' => __('Digitalogic', 'digitalogic'),
            ),
        ));
        
        // Add Dashboard submenu
        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-dashboard',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-dashboard"></span> ' . __('Dashboard', 'digitalogic'),
            'href'   => admin_url('admin.php?page=digitalogic'),
            'meta'   => array(
                'title' => __('Dashboard', 'digitalogic'),
            ),
        ));
        
        // Add Products submenu
        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-products',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-products"></span> ' . __('Products', 'digitalogic'),
            'href'   => admin_url('admin.php?page=product-list'),
            'meta'   => array(
                'title' => __('Product List', 'digitalogic'),
            ),
        ));
        
        // Add Currency submenu
        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-currency',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-money-alt"></span> ' . __('Currency', 'digitalogic'),
            'href'   => admin_url('admin.php?page=price-settings'),
            'meta'   => array(
                'title' => __('Price Settings', 'digitalogic'),
            ),
        ));
        
        // Add Import/Export submenu
        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-import-export',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-database-import"></span> ' . __('Import/Export', 'digitalogic'),
            'href'   => admin_url('admin.php?page=import-export'),
            'meta'   => array(
                'title' => __('Import/Export', 'digitalogic'),
            ),
        ));
        
        // Add Logs submenu
        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-logs',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-list-view"></span> ' . __('Logs', 'digitalogic'),
            'href'   => admin_url('admin.php?page=digitalogic-logs'),
            'meta'   => array(
                'title' => __('Activity Logs', 'digitalogic'),
            ),
        ));
        
        // Add Status submenu
        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-status',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-info"></span> ' . __('Status', 'digitalogic'),
            'href'   => admin_url('admin.php?page=digitalogic-status'),
            'meta'   => array(
                'title' => __('Status & Diagnostics', 'digitalogic'),
            ),
        ));

        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-panel',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-external"></span> ' . $this->panel_label(),
            'href'   => Digitalogic_Laravel_Bridge::instance()->get_launch_url(),
            'meta'   => array(
                'title'  => $this->panel_label(),
                'target' => '_blank',
                'rel'    => 'noopener',
            ),
        ));
    }
    
    /**
     * Fallback SVG icon
     */
    private function get_fallback_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"></svg>';
    }

    private function panel_label() {
        return is_rtl() ? 'پنل' : __('Panel', 'digitalogic');
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
            'panel_url' => Digitalogic_Laravel_Bridge::instance()->get_panel_url(),
            'websocket' => Digitalogic_WebSocket::instance()->get_client_config(),
            'i18n' => array(
                'confirm_bulk_update' => __('Are you sure you want to update these products?', 'digitalogic'),
                'success' => __('Success', 'digitalogic'),
                'error' => __('Error', 'digitalogic'),
                'loading' => __('Loading...', 'digitalogic'),
                'view_product' => is_rtl() ? 'نمایش' : __('View', 'digitalogic'),
                'edit_product' => is_rtl() ? 'ویرایش' : __('Edit', 'digitalogic'),
                'search_products' => is_rtl() ? 'جستجوی محصولات...' : __('Search products...', 'digitalogic'),
                'show' => __('Show', 'digitalogic'),
                'entries' => __('entries', 'digitalogic'),
                'search' => __('Search:', 'digitalogic'),
                'no_data' => __('No data available in table', 'digitalogic'),
                'showing' => __('Showing', 'digitalogic'),
                'to' => __('to', 'digitalogic'),
                'of' => __('of', 'digitalogic'),
                'entries_text' => __('entries', 'digitalogic'),
                'no_records' => __('No matching records found', 'digitalogic'),
                'filtered' => __('(filtered from _MAX_ total entries)', 'digitalogic'),
            )
        ));
    }
    
    /**
     * Enqueue admin bar styles
     * 
     * Enqueues styles for the admin bar menu on both front-end and back-end
     */
    public function enqueue_admin_bar_styles() {
        // Only enqueue if admin bar is showing and user has permission
        if (!is_admin_bar_showing() || !current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Enqueue separate admin bar styles (lighter than full admin.css)
        wp_enqueue_style('digitalogic-admin-bar', DIGITALOGIC_PLUGIN_URL . 'assets/css/admin-bar.css', array(), DIGITALOGIC_VERSION);
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
     * Render custom UI settings page.
     */
    public function render_ui_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'digitalogic'));
        }

        if (isset($_POST['digitalogic_ui_settings_submit']) && check_admin_referer('digitalogic_ui_settings')) {
            $enabled = isset($_POST['digitalogic_custom_ui_enabled']) ? 'yes' : 'no';
            update_option(Digitalogic_Plugin_Admin_Branding::OPTION_ENABLED, $enabled);

            echo '<div class="notice notice-success"><p>' . esc_html__('UI settings saved.', 'digitalogic') . '</p></div>';
        }

        $custom_ui_enabled = Digitalogic_Plugin_Admin_Branding::is_enabled();
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/ui-settings.php';
    }

    /**
     * Render panel launch page.
     */
    public function render_panel_page() {
        $bridge = Digitalogic_Laravel_Bridge::instance();
        $panel_url = $bridge->get_panel_url();
        $launch_url = $bridge->get_launch_url();

        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/panel.php';
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
            
            // Get products for current page
            $products = $manager->get_products(array(
                'page' => $page,
                'limit' => $limit,
                'search' => $search
            ));
            
            // Get total count (without filters)
            $total = $manager->get_product_count();
            
            // Get filtered count (with search filter if applicable)
            $filtered_count = $total;
            if (!empty($search)) {
                $filtered_count = $manager->get_product_count(array(
                    'search' => $search
                ));
            }
            
            // Return DataTables server-side format
            wp_send_json_success(array(
                'products' => $products,
                'total' => $total,
                'recordsTotal' => $total,
                'recordsFiltered' => $filtered_count
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
        $this->send_command_response('digitalogic_update_product', $_POST);
    }
    
    /**
     * AJAX: Bulk update
     */
    public function ajax_bulk_update() {
        $this->send_command_response('digitalogic_bulk_update', $_POST);
    }
    
    /**
     * AJAX: Update currency
     */
    public function ajax_update_currency() {
        $this->send_command_response('digitalogic_update_currency', $_POST);
    }
    
    /**
     * AJAX: Export
     */
    public function ajax_export() {
        $this->send_command_response('digitalogic_export', $_POST);
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
        $this->send_command_response('digitalogic_get_logs', $_POST);
    }

    /**
     * Send an AJAX response from the shared command dispatcher.
     */
    private function send_command_response($command, $payload) {
        check_ajax_referer('digitalogic_nonce', 'nonce');

        $payload = is_array($payload) ? wp_unslash($payload) : array();
        unset($payload['action'], $payload['nonce']);

        $result = Digitalogic_Command_Dispatcher::instance()->execute($command, $payload, 'ajax');
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }
}
