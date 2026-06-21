<?php
/**
 * UI settings view.
 *
 * @var bool $custom_ui_enabled
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap digitalogic-panel-page">
    <h1><?php esc_html_e('Digitalogic UI Settings', 'digitalogic'); ?></h1>

    <form method="post">
        <?php wp_nonce_field('digitalogic_ui_settings'); ?>

        <div class="digitalogic-section">
            <h2><?php esc_html_e('Custom admin and login experience', 'digitalogic'); ?></h2>
            <p class="description">
                <?php esc_html_e('Controls the Digitalogic branded admin theme, wp-login.php styling, phone-aware login bridge, and legacy /login redirect.', 'digitalogic'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable custom UI layer', 'digitalogic'); ?></th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="digitalogic_custom_ui_enabled"
                                    value="1"
                                    <?php checked($custom_ui_enabled); ?>
                                />
                                <?php esc_html_e('Load Digitalogic custom admin/login styles and auth route improvements.', 'digitalogic'); ?>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php submit_button(__('Save UI settings', 'digitalogic'), 'primary', 'digitalogic_ui_settings_submit'); ?>
    </form>
</div>
