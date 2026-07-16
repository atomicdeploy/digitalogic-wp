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

    private $currency_page_hook = '';

    private $currency_page_data = array();
    
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
        add_action('wp_ajax_digitalogic_get_reports', array($this, 'ajax_get_reports'));
        add_action('wp_ajax_digitalogic_sync_patris', array($this, 'ajax_sync_patris'));
        add_action('wp_ajax_digitalogic_update_patris_settings', array($this, 'ajax_update_patris_settings'));
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
        
        $this->currency_page_hook = add_submenu_page(
            'digitalogic',
            __('Price Settings', 'digitalogic'),
            __('Currency', 'digitalogic'),
            'manage_woocommerce',
            'price-settings',
            array($this, 'render_currency_page')
        );
        $this->page_hooks[] = $this->currency_page_hook;
        add_action('load-' . $this->currency_page_hook, array($this, 'register_currency_meta_boxes'));
        
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
            __('Patris Reports', 'digitalogic'),
            __('Patris Reports', 'digitalogic'),
            'manage_woocommerce',
            'digitalogic-patris-reports',
            array($this, 'render_patris_reports_page')
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

        $wp_admin_bar->add_node(array(
            'id'     => 'digitalogic-patris-reports',
            'parent' => 'digitalogic',
            'title'  => '<span class="dashicons dashicons-chart-bar"></span> ' . __('Patris Reports', 'digitalogic'),
            'href'   => admin_url('admin.php?page=digitalogic-patris-reports'),
            'meta'   => array(
                'title' => __('Patris Reports', 'digitalogic'),
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

        $this->enqueue_currency_postbox_assets($hook);
        
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
     * Enqueue the native postbox behavior only for the currency screen.
     *
     * @param string $hook Current admin page hook.
     */
    private function enqueue_currency_postbox_assets($hook) {
        if ($hook !== $this->currency_page_hook) {
            return;
        }

        wp_enqueue_script(
            'digitalogic-currency-postboxes',
            DIGITALOGIC_PLUGIN_URL . 'assets/js/currency-postboxes.js',
            array('jquery', 'postbox'),
            DIGITALOGIC_VERSION,
            true
        );
        wp_localize_script(
            'digitalogic-currency-postboxes',
            'digitalogicCurrencyPostboxes',
            array('screenId' => $hook)
        );
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
        $currency_status = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();

        $this->currency_page_data = array(
            'dollar_price' => $dollar_price,
            'yuan_price' => $yuan_price,
            'update_date' => $update_date,
            'update_date_relative' => $update_date_relative,
            'currency_status' => $currency_status,
        );

        $current_screen = get_current_screen();
        
        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/currency.php';
    }

    /**
     * Register the native WordPress meta boxes used by the currency page.
     */
    public function register_currency_meta_boxes() {
        $screen = get_current_screen();

        if (!$screen) {
            return;
        }

        add_meta_box(
            'digitalogic-currency-update',
            __('Update', 'digitalogic'),
            array($this, 'render_currency_update_meta_box'),
            $screen,
            'side',
            'high'
        );
        add_meta_box(
            'digitalogic-currency-last-update',
            __('Last Update', 'digitalogic'),
            array($this, 'render_currency_last_update_meta_box'),
            $screen,
            'side',
            'default'
        );
        add_meta_box(
            'digitalogic-currency-status',
            __('WooCommerce Base Currency', 'digitalogic'),
            array($this, 'render_currency_status_meta_box'),
            $screen,
            'side',
            'high'
        );
        add_meta_box(
            'digitalogic-currency-rates',
            __('Exchange Rates', 'digitalogic'),
            array($this, 'render_currency_rates_meta_box'),
            $screen,
            'normal',
            'high'
        );
        add_meta_box(
            'digitalogic-currency-options',
            __('Additional Options', 'digitalogic'),
            array($this, 'render_currency_options_meta_box'),
            $screen,
            'normal',
            'default'
        );
    }

    /**
     * Render the currency update action meta box.
     */
    public function render_currency_update_meta_box() {
        ?>
        <div class="submitbox" id="submitpost">
            <div id="major-publishing-actions">
                <div id="publishing-action">
                    <?php submit_button(__('Update Currency Rates', 'digitalogic'), 'primary large', 'submit', false); ?>
                </div>
                <div class="clear"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the last-update summary meta box.
     */
    public function render_currency_last_update_meta_box() {
        ?>
        <p>
            <strong id="update_date"><?php echo esc_html($this->currency_page_value('update_date')); ?></strong>
        </p>
        <p class="description"><?php echo esc_html($this->currency_page_value('update_date_relative')); ?></p>
        <?php
    }

    /**
     * Render the read-only WooCommerce/Patris currency compatibility box.
     */
    public function render_currency_status_meta_box() {
        $status = $this->currency_page_value('currency_status');
        if (!is_array($status)) {
            $status = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();
        }
        $compatible = !empty($status['compatible']);
        ?>
        <div class="digitalogic-currency-postbox-status">
            <p class="digitalogic-status-label <?php echo $compatible ? 'is-ready' : 'is-warning'; ?>">
                <span class="dashicons <?php echo $compatible ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                <?php echo $compatible ? esc_html__('Ready', 'digitalogic') : esc_html__('Base currency mismatch', 'digitalogic'); ?>
            </p>
            <p>
                <strong><code><?php echo esc_html($status['code']); ?></code></strong>
                <?php if ($compatible) : ?>
                    &mdash; <?php echo esc_html__('Toman (10 IRR per unit)', 'digitalogic'); ?>
                <?php endif; ?>
            </p>
            <?php if ($compatible) : ?>
                <p><?php echo esc_html__('Ready for the Patris CNY-to-IRT pricing contract.', 'digitalogic'); ?></p>
            <?php else : ?>
                <p><?php echo esc_html__('Patris produces IRT prices. WooCommerce must use IRT before transformed prices can be applied.', 'digitalogic'); ?></p>
            <?php endif; ?>
            <p class="description"><?php echo esc_html__('Read-only monitoring; Digitalogic never changes this setting automatically.', 'digitalogic'); ?></p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings')); ?>"><?php echo esc_html__('Open WooCommerce settings', 'digitalogic'); ?></a></p>
        </div>
        <?php
    }

    /**
     * Render the exchange-rate fields meta box.
     */
    public function render_currency_rates_meta_box() {
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="dollar_price"><?php esc_html_e('USD Price (in local currency)', 'digitalogic'); ?></label>
                </th>
                <td>
                    <input type="number" min="0" step="0.01" name="dollar_price" id="dollar_price" value="<?php echo esc_attr($this->currency_page_value('dollar_price')); ?>" class="regular-text" inputmode="decimal">
                    <p class="description"><?php esc_html_e('The exchange rate for 1 USD in your local currency', 'digitalogic'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="yuan_price"><?php esc_html_e('CNY/Yuan Price (in local currency)', 'digitalogic'); ?></label>
                </th>
                <td>
                    <input type="number" min="0" step="0.01" name="yuan_price" id="yuan_price" value="<?php echo esc_attr($this->currency_page_value('yuan_price')); ?>" class="regular-text" inputmode="decimal">
                    <p class="description"><?php esc_html_e('The exchange rate for 1 CNY in your local currency', 'digitalogic'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render the optional recalculation controls meta box.
     */
    public function render_currency_options_meta_box() {
        ?>
        <fieldset>
            <legend class="screen-reader-text"><?php esc_html_e('Recalculate Prices', 'digitalogic'); ?></legend>
            <label for="recalculate_prices">
                <input type="checkbox" name="recalculate_prices" id="recalculate_prices" value="1">
                <?php esc_html_e('Recalculate Prices', 'digitalogic'); ?>
            </label>
            <p class="description"><?php esc_html_e('Update all products with dynamic pricing after saving', 'digitalogic'); ?></p>
        </fieldset>
        <?php
    }

    /**
     * Read a prepared value for a currency-page meta box.
     *
     * @param string $key Value key.
     * @return mixed
     */
    private function currency_page_value($key) {
        return isset($this->currency_page_data[$key]) ? $this->currency_page_data[$key] : '';
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

    public function render_patris_reports_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage Patris reports.', 'digitalogic'));
        }

        $feed = Digitalogic_Patris_Feed::instance();
        $freight_service = Digitalogic_Import_Freight_Service::instance();
        $notice = '';
        $notice_type = 'info';
        $freight_assignment = null;
        $posted_value = static function ($key) {
            if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
                return '';
            }

            return wp_unslash((string) $_POST[$key]);
        };

        $freight_action = sanitize_key($posted_value('digitalogic_import_freight_action'));

        if ($freight_action !== '') {
            check_admin_referer('digitalogic_import_freight_admin');

            switch ($freight_action) {
                case 'create_method':
                    $result = $freight_service->create_method(array(
                        'id' => $posted_value('method_id'),
                        'name' => sanitize_text_field($posted_value('method_name')),
                        'price_per_kg_cny' => $posted_value('price_per_kg_cny'),
                        'enabled' => isset($_POST['method_enabled']),
                    ));
                    $notice = is_wp_error($result)
                        ? $result->get_error_message()
                        : sprintf(__('Import freight method "%s" created.', 'digitalogic'), $result['name']);
                    $notice_type = is_wp_error($result) ? 'error' : 'success';
                    break;

                case 'update_method':
                    $method_id = $posted_value('method_id');
                    $result = $freight_service->update_method($method_id, array(
                        'name' => sanitize_text_field($posted_value('method_name')),
                        'price_per_kg_cny' => $posted_value('price_per_kg_cny'),
                        'enabled' => isset($_POST['method_enabled']),
                    ));
                    $notice = is_wp_error($result)
                        ? $result->get_error_message()
                        : sprintf(__('Import freight method "%s" updated.', 'digitalogic'), $result['name']);
                    $notice_type = is_wp_error($result) ? 'error' : 'success';
                    break;

                case 'delete_method':
                    $method_id = $posted_value('method_id');
                    $result = $freight_service->delete_method($method_id);
                    $notice = is_wp_error($result)
                        ? $result->get_error_message()
                        : __('Import freight method deleted.', 'digitalogic');
                    $notice_type = is_wp_error($result) ? 'error' : 'success';
                    break;

                case 'assign_product':
                    $code = sanitize_text_field($posted_value('product_code'));
                    $method_id = $posted_value('assignment_method_id');
                    $result = $freight_service->assign_product_by_code($code, $method_id);
                    if (is_wp_error($result)) {
                        $notice = $result->get_error_message();
                        $notice_type = 'error';
                    } else {
                        $freight_assignment = $result;
                        $notice = $method_id === ''
                            ? __('The import freight assignment was cleared.', 'digitalogic')
                            : __('The import freight method was assigned.', 'digitalogic');
                        $notice_type = 'success';
                    }
                    break;

                case 'update_default_markup':
                    $result = $freight_service->update_default_percentage_markup(
                        $posted_value('default_profit_percent')
                    );
                    $notice = is_wp_error($result)
                        ? $result->get_error_message()
                        : __('The global default percentage markup was saved. WooCommerce prices were not changed.', 'digitalogic');
                    $notice_type = is_wp_error($result) ? 'error' : 'success';
                    break;

                case 'clear_default_markup':
                    $result = $freight_service->update_default_percentage_markup(null);
                    $notice = is_wp_error($result)
                        ? $result->get_error_message()
                        : __('The global default percentage markup was cleared. WooCommerce prices were not changed.', 'digitalogic');
                    $notice_type = is_wp_error($result) ? 'error' : 'success';
                    break;

                default:
                    $notice = __('Unknown import freight action.', 'digitalogic');
                    $notice_type = 'error';
                    break;
            }
        }

        if (isset($_POST['digitalogic_patris_settings_submit']) && check_admin_referer('digitalogic_patris_settings')) {
            $feed->update_settings(array(
                'api_url' => $posted_value('api_url'),
                'api_token' => $posted_value('api_token'),
                'selected_warehouses' => $posted_value('selected_warehouses'),
                'stale_after_hours' => $posted_value('stale_after_hours') !== '' ? absint($posted_value('stale_after_hours')) : 48,
                'sync_interval' => sanitize_key($posted_value('sync_interval')),
            ));
            $notice = __('Patris report settings saved.', 'digitalogic');
            $notice_type = 'success';
        }

        if (isset($_POST['digitalogic_patris_sync_submit']) && check_admin_referer('digitalogic_patris_sync')) {
            $result = $feed->pull_sync();
            $notice = is_wp_error($result) ? $result->get_error_message() : __('Patris feed synchronized.', 'digitalogic');
            $notice_type = is_wp_error($result) ? 'error' : 'success';
        }

        $settings = $feed->get_settings();
        $push_token = $feed->get_push_token();
        $report = Digitalogic_Report_Engine::instance()->get_report();
        $freight_methods = $freight_service->list_methods(true);
        if (is_wp_error($freight_methods)) {
            $notice = $freight_methods->get_error_message();
            $notice_type = 'error';
            $freight_methods = array();
        }
        $default_markup = $freight_service->get_default_percentage_markup();
        if (is_wp_error($default_markup)) {
            $notice = $default_markup->get_error_message();
            $notice_type = 'error';
            $default_markup = array(
                'configured' => false,
                'profit_percent' => null,
                'revision' => '',
                'source' => 'unavailable',
                'bounds' => array('minimum' => '0', 'maximum' => '1000', 'maximum_fraction_digits' => 12),
            );
        }
        $currency_status = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();

        include DIGITALOGIC_PLUGIN_DIR . 'includes/admin/views/patris-reports.php';
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
            
            $request = wp_unslash($_POST);
            unset($request['action'], $request['nonce']);

            wp_send_json_success(Digitalogic_Product_Manager::instance()->query_products($request));
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

    public function ajax_get_reports() {
        $this->send_command_response('digitalogic_get_reports', $_POST);
    }

    public function ajax_sync_patris() {
        $this->send_command_response('digitalogic_sync_patris', $_POST);
    }

    public function ajax_update_patris_settings() {
        $this->send_command_response('digitalogic_update_patris_settings', $_POST);
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
