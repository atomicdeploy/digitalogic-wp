<?php
/**
 * Status & Diagnostics Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get system information
global $wpdb;
$digitalogic = digitalogic();
$hpos_status = $digitalogic->get_hpos_status();
$options = Digitalogic_Options::instance();

// WordPress info
$wp_version = get_bloginfo('version');
$php_version = phpversion();
$mysql_version = $wpdb->db_version();
$wc_version = defined('WC_VERSION') ? WC_VERSION : 'N/A';

// Plugin status
$dollar_price = $options->get_dollar_price();
$yuan_price = $options->get_yuan_price();
$update_date = $options->get_update_date_formatted();
$update_date_raw = $options->get_update_date(); // Keep raw format for debug info


// Server info
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$max_upload = ini_get('upload_max_filesize');
$max_post = ini_get('post_max_size');
$memory_limit = ini_get('memory_limit');
$max_execution = ini_get('max_execution_time');

// Plugin info
$plugin_version = DIGITALOGIC_VERSION;
$plugin_path = DIGITALOGIC_PLUGIN_DIR;

// Database tables
$logs_table = $wpdb->prefix . 'digitalogic_logs';
$logs_count = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table");

// Product count
$manager = Digitalogic_Product_Manager::instance();
$product_count = $manager->get_product_count();

// WooCommerce features
$wc_features = array();
if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
    $wc_features['HPOS Available'] = 'Yes';
} else {
    $wc_features['HPOS Available'] = 'No';
}
$wc_features['HPOS Enabled'] = $hpos_status['hpos_enabled'] ? 'Yes' : 'No';
$wc_features['Using Custom Tables'] = $hpos_status['using_custom_tables'] ? 'Yes' : 'No';
$wc_features['Plugin Compatible'] = $hpos_status['plugin_compatible'] ? 'Yes' : 'No';

?>
<div class="wrap">
    <h1><?php _e('Status & Diagnostics', 'digitalogic'); ?></h1>
    
    <p><?php _e('System information and plugin diagnostics for troubleshooting and support.', 'digitalogic'); ?></p>
    
    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <div id="post-body-content">
                <!-- WordPress Environment Postbox -->
                <div id="wordpress-environment" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('WordPress Environment', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: WordPress Environment', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <td><strong><?php _e('WordPress Version', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($wp_version); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('WooCommerce Version', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($wc_version); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Site URL', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(site_url()); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Home URL', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(home_url()); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Language', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(get_locale()); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Timezone', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(wp_timezone_string()); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Server Environment Postbox -->
                <div id="server-environment" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Server Environment', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Server Environment', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <td><strong><?php _e('Server Software', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($server_software); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('PHP Version', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($php_version); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('MySQL Version', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($mysql_version); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Max Upload Size', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($max_upload); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Max Post Size', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($max_post); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Memory Limit', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($memory_limit); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Max Execution Time', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($max_execution); ?>s</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Plugin Information Postbox -->
                <div id="plugin-information" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Plugin Information', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Plugin Information', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <td><strong><?php _e('Plugin Version', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($plugin_version); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Plugin Path', 'digitalogic'); ?></strong></td>
                                    <td><code><?php echo esc_html($plugin_path); ?></code></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Total Products', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($product_count)); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Activity Logs', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($logs_count)); ?> <?php _e('entries', 'digitalogic'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Currency Settings Postbox -->
                <div id="currency-settings" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Currency Settings', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Currency Settings', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <td><strong><?php _e('USD Price (dollar_price)', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($dollar_price, 2)); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('CNY Price (yuan_price)', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($yuan_price, 2)); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php _e('Last Update Date (update_date)', 'digitalogic'); ?></strong></td>
                                    <td><?php echo esc_html($update_date); ?> <small>(<?php echo esc_html($update_date_raw); ?>)</small></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- WooCommerce HPOS Status Postbox -->
                <div id="hpos-status" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('WooCommerce HPOS Status', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: WooCommerce HPOS Status', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <table class="widefat striped">
                            <tbody>
                                <?php foreach ($wc_features as $feature => $value) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($feature); ?></strong></td>
                                    <td>
                                        <?php if ($value === 'Yes') : ?>
                                            <span style="color: green;">✓ <?php echo esc_html($value); ?></span>
                                        <?php else : ?>
                                            <span style="color: orange;">⚠ <?php echo esc_html($value); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <?php if ($hpos_status['plugin_compatible'] && $hpos_status['hpos_enabled']) : ?>
                            <p class="description" style="color: green;">
                                ✓ <?php _e('This plugin is fully compatible with WooCommerce High-Performance Order Storage (HPOS). All operations use WooCommerce CRUD methods for maximum compatibility.', 'digitalogic'); ?>
                            </p>
                        <?php elseif (!$hpos_status['hpos_enabled']) : ?>
                            <p class="description">
                                <?php _e('HPOS is available but not currently enabled. The plugin works with both traditional and HPOS storage modes.', 'digitalogic'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div id="postbox-container-1" class="postbox-container">
                <!-- REST API Endpoints Postbox -->
                <div id="rest-api" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('REST API Endpoints', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: REST API Endpoints', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <p><?php _e('Available REST API endpoints:', 'digitalogic'); ?></p>
                        <ul style="margin-left: 1.5em; font-size: 12px;">
                            <li><code>GET <?php echo esc_html(rest_url('digitalogic/v1/products')); ?></code></li>
                            <li><code>POST <?php echo esc_html(rest_url('digitalogic/v1/products/batch')); ?></code></li>
                            <li><code>GET <?php echo esc_html(rest_url('digitalogic/v1/currency')); ?></code></li>
                            <li><code>POST <?php echo esc_html(rest_url('digitalogic/v1/currency')); ?></code></li>
                            <li><code>POST <?php echo esc_html(rest_url('digitalogic/v1/pricing/recalculate')); ?></code></li>
                            <li><code>GET <?php echo esc_html(rest_url('digitalogic/v1/export')); ?></code></li>
                        </ul>
                    </div>
                </div>
                
                <!-- WP-CLI Commands Postbox -->
                <div id="wp-cli" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('WP-CLI Commands', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: WP-CLI Commands', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <p><?php _e('Available WP-CLI commands:', 'digitalogic'); ?></p>
                        <ul style="margin-left: 1.5em; font-size: 12px;">
                            <li><code>wp digitalogic currency get</code></li>
                            <li><code>wp digitalogic currency update --usd=42000 --cny=6000</code></li>
                            <li><code>wp digitalogic products list --limit=20</code></li>
                            <li><code>wp digitalogic products update 123 --price=99.99</code></li>
                            <li><code>wp digitalogic export --format=csv</code></li>
                            <li><code>wp digitalogic import /path/to/products.csv</code></li>
                            <li><code>wp digitalogic logs --limit=50</code></li>
                        </ul>
                    </div>
                </div>
                
                <!-- Debug Information Postbox -->
                <div id="debug-info" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Debug Information', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Debug Information', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <p><?php _e('Copy this information when requesting support:', 'digitalogic'); ?></p>
                        <textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;">
=== Digitalogic Status Report ===

Plugin Version: <?php echo $plugin_version; ?>

WordPress Version: <?php echo $wp_version; ?>

WooCommerce Version: <?php echo $wc_version; ?>

PHP Version: <?php echo $php_version; ?>

MySQL Version: <?php echo $mysql_version; ?>

Server: <?php echo $server_software; ?>

HPOS Enabled: <?php echo $hpos_status['hpos_enabled'] ? 'Yes' : 'No'; ?>

HPOS Compatible: <?php echo $hpos_status['plugin_compatible'] ? 'Yes' : 'No'; ?>

Products: <?php echo $product_count; ?>

Logs: <?php echo $logs_count; ?>

USD Price: <?php echo $dollar_price; ?>

CNY Price: <?php echo $yuan_price; ?>

Last Update: <?php echo $update_date; ?>

Memory Limit: <?php echo $memory_limit; ?>

Max Execution Time: <?php echo $max_execution; ?>s
                        </textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize postboxes with unique page identifier
    postboxes.add_postbox_toggles('digitalogic_status');
});
</script>
