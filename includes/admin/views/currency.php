<div class="wrap digitalogic-currency">
    <h1><?php _e('Currency Settings', 'digitalogic'); ?></h1>
    
    <!-- WooCommerce Currency Status -->
    <div class="notice notice-info" style="margin: 20px 0; padding: 12px;">
        <h3 style="margin-top: 0;"><?php _e('WooCommerce Currency Status', 'digitalogic'); ?></h3>
        <p>
            <strong><?php _e('Base Currency:', 'digitalogic'); ?></strong> 
            <?php echo esc_html($currency_status['woocommerce_currency']); ?> 
            (<?php echo esc_html($currency_status['woocommerce_symbol']); ?>)
            <br>
            <small style="color: #666;">
                <?php 
                if ($currency_status['is_usd']) {
                    _e('Your WooCommerce store is using USD as the base currency. The USD exchange rate below may not be needed for pricing.', 'digitalogic');
                } elseif ($currency_status['is_cny']) {
                    _e('Your WooCommerce store is using CNY as the base currency. The CNY exchange rate below may not be needed for pricing.', 'digitalogic');
                } else {
                    printf(
                        __('Your WooCommerce store uses %s. The exchange rates below are used to convert USD and CNY prices to your base currency.', 'digitalogic'),
                        esc_html($currency_status['woocommerce_currency'])
                    );
                }
                ?>
            </small>
        </p>
        <p style="margin-bottom: 0;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings')); ?>" class="button button-small">
                <?php _e('WooCommerce Settings', 'digitalogic'); ?>
            </a>
        </p>
    </div>
    
    <form method="post" action="">
        <?php wp_nonce_field('digitalogic_currency_update'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="dollar_price"><?php _e('USD Price (in local currency)', 'digitalogic'); ?></label>
                </th>
                <td>
                    <input type="number" step="0.01" name="dollar_price" id="dollar_price" value="<?php echo esc_attr($dollar_price); ?>" class="regular-text">
                    <p class="description"><?php _e('The exchange rate for 1 USD in your local currency', 'digitalogic'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="yuan_price"><?php _e('CNY/Yuan Price (in local currency)', 'digitalogic'); ?></label>
                </th>
                <td>
                    <input type="number" step="0.01" name="yuan_price" id="yuan_price" value="<?php echo esc_attr($yuan_price); ?>" class="regular-text">
                    <p class="description"><?php _e('The exchange rate for 1 CNY in your local currency', 'digitalogic'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="recalculate_prices"><?php _e('Recalculate Prices', 'digitalogic'); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="recalculate_prices" id="recalculate_prices" value="1">
                    <label for="recalculate_prices"><?php _e('Update all products with dynamic pricing after saving', 'digitalogic'); ?></label>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <?php _e('Last Update', 'digitalogic'); ?>
                </th>
                <td>
                    <strong id="update_date">
                        <?php echo esc_html($update_date); ?>
                        <br>
                        <small style="color: #666; font-weight: normal;">(<?php echo esc_html($update_date_relative); ?>)</small>
                    </strong>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Update Currency Rates', 'digitalogic')); ?>
    </form>
</div>
