<div class="wrap digitalogic-currency">
    <h1><?php _e('Currency Settings', 'digitalogic'); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('digitalogic_currency_update'); ?>
        
        <div id="poststuff" class="poststuff">
            <div id="post-body" class="metabox-holder columns-2">
                <!-- Sidebar -->
                <div id="postbox-container-1" class="postbox-container">
                    <div id="side-sortables" class="meta-box-sortables ui-sortable">
                        <div id="submitdiv" class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle ui-sortable-handle"><?php _e('Update', 'digitalogic'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel: Update', 'digitalogic'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
                                <div id="major-publishing-actions">
                                    <div id="publishing-action">
                                        <span class="spinner"></span>
                                        <input type="submit" accesskey="p" value="<?php esc_attr_e('Update Currency Rates', 'digitalogic'); ?>" class="button button-primary button-large" id="publish" name="publish">
                                    </div>
                                    <div class="clear"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="last-update-div" class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle ui-sortable-handle"><?php _e('Last Update', 'digitalogic'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel: Last Update', 'digitalogic'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
                                <div class="misc-pub-section">
                                    <strong><?php echo esc_html($update_date); ?></strong>
                                    <br>
                                    <small style="color: #666;"><?php echo esc_html($update_date_relative); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Content -->
                <div id="postbox-container-2" class="postbox-container">
                    <div id="normal-sortables" class="meta-box-sortables ui-sortable">
                        <div id="currency-rates" class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle ui-sortable-handle"><?php _e('Exchange Rates', 'digitalogic'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel: Exchange Rates', 'digitalogic'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
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
                                </table>
                            </div>
                        </div>
                        
                        <div id="additional-options" class="postbox">
                            <div class="postbox-header">
                                <h2 class="hndle ui-sortable-handle"><?php _e('Additional Options', 'digitalogic'); ?></h2>
                                <div class="handle-actions hide-if-no-js">
                                    <button type="button" class="handlediv" aria-expanded="true">
                                        <span class="screen-reader-text"><?php _e('Toggle panel: Additional Options', 'digitalogic'); ?></span>
                                        <span class="toggle-indicator" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="inside">
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="recalculate_prices"><?php _e('Recalculate Prices', 'digitalogic'); ?></label>
                                        </th>
                                        <td>
                                            <label for="recalculate_prices">
                                                <input type="checkbox" name="recalculate_prices" id="recalculate_prices" value="1">
                                                <?php _e('Update all products with dynamic pricing after saving', 'digitalogic'); ?>
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <br class="clear">
        </div>
    </form>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Initialize postboxes with unique page identifier
    postboxes.add_postbox_toggles('digitalogic_currency');
});
</script>
