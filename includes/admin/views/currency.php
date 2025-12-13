<div class="wrap digitalogic-currency">
    <h1><?php _e('Currency Settings', 'digitalogic'); ?></h1>
    
    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-1">
            <div id="post-body-content">
                <!-- Currency Rates Postbox -->
                <div id="currency-rates" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle"><?php _e('Exchange Rates', 'digitalogic'); ?></h2>
                        <div class="handle-actions hide-if-no-js">
                            <button type="button" class="handlediv" aria-expanded="true">
                                <span class="screen-reader-text"><?php _e('Toggle panel: Exchange Rates', 'digitalogic'); ?></span>
                                <span class="toggle-indicator" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                    <div class="inside">
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
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize postboxes with unique page identifier
    postboxes.add_postbox_toggles('digitalogic_currency');
});
</script>
