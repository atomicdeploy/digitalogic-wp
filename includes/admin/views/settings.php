<div class="wrap digitalogic-settings">
    <h1><?php _e('Settings', 'digitalogic'); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('digitalogic_settings_update'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="use_toman"><?php _e('Use Toman Currency Symbol', 'digitalogic'); ?></label>
                </th>
                <td>
                    <input type="checkbox" name="use_toman" id="use_toman" value="1" <?php checked($use_toman, true); ?>>
                    <label for="use_toman"><?php _e('Display "تومان" instead of "ریال" for Iranian Rial (IRR) currency', 'digitalogic'); ?></label>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Save Settings', 'digitalogic')); ?>
    </form>
</div>
