<div class="wrap digitalogic-dashboard">
    <h1><?php _e('Reports', 'digitalogic'); ?></h1>
    
    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <div id="post-body-content">
                <!-- Statistics Postbox -->
                <div id="dashboard-stats" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Statistics Overview', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Statistics Overview', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <div class="digitalogic-stats">
                            <a href="<?php echo admin_url('admin.php?page=product-list'); ?>" class="stat-box clickable">
                                <h3><?php _e('Total Products', 'digitalogic'); ?></h3>
                                <p class="stat-number"><?php echo number_format($product_count); ?></p>
                            </a>
                            
                            <a href="<?php echo admin_url('admin.php?page=price-settings'); ?>" class="stat-box clickable">
                                <h3><?php _e('USD Price', 'digitalogic'); ?></h3>
                                <p class="stat-number"><?php echo number_format($dollar_price, 2); ?></p>
                            </a>
                            
                            <a href="<?php echo admin_url('admin.php?page=price-settings'); ?>" class="stat-box clickable">
                                <h3><?php _e('CNY Price', 'digitalogic'); ?></h3>
                                <p class="stat-number"><?php echo number_format($yuan_price, 2); ?></p>
                            </a>
                            
                            <a href="<?php echo admin_url('admin.php?page=price-settings'); ?>" class="stat-box clickable">
                                <h3><?php _e('Last Update', 'digitalogic'); ?></h3>
                                <p class="stat-number"><?php echo esc_html($update_date); ?></p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="postbox-container-1" class="postbox-container">
                <!-- Quick Links Postbox -->
                <div id="quick-links" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Quick Links', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Quick Links', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
                        <div class="digitalogic-quick-links">
                            <ul>
                                <li><a href="<?php echo admin_url('admin.php?page=product-list'); ?>" class="button button-primary button-large"><?php _e('Manage Products', 'digitalogic'); ?></a></li>
                                <li><a href="<?php echo admin_url('admin.php?page=price-settings'); ?>" class="button button-large"><?php _e('Update Currency Rates', 'digitalogic'); ?></a></li>
                                <li><a href="<?php echo admin_url('admin.php?page=import-export'); ?>" class="button button-large"><?php _e('Import/Export', 'digitalogic'); ?></a></li>
                                <li><a href="<?php echo admin_url('admin.php?page=digitalogic-logs'); ?>" class="button button-large"><?php _e('View Logs', 'digitalogic'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize postboxes with unique page identifier
    postboxes.add_postbox_toggles('digitalogic_dashboard');
});
</script>
