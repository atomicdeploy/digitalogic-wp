<div class="wrap digitalogic-dashboard">
    <h1><?php _e('Reports', 'digitalogic'); ?></h1>
    
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
    
    <div class="digitalogic-quick-links">
        <h2><?php _e('Quick Links', 'digitalogic'); ?></h2>
        <ul>
            <li><a href="<?php echo admin_url('admin.php?page=product-list'); ?>" class="button button-primary"><?php _e('Manage Products', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo admin_url('admin.php?page=price-settings'); ?>" class="button"><?php _e('Update Currency Rates', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo admin_url('admin.php?page=import-export'); ?>" class="button"><?php _e('Import/Export', 'digitalogic'); ?></a></li>
            <li><a href="<?php echo admin_url('admin.php?page=digitalogic-logs'); ?>" class="button"><?php _e('View Logs', 'digitalogic'); ?></a></li>
        </ul>
    </div>
</div>
