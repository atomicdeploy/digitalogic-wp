<?php
/**
 * Patris/API report page.
 *
 * @var array  $settings
 * @var string $push_token
 * @var array  $report
 * @var string $notice
 */

if (!defined('ABSPATH')) {
    exit;
}

$push_url = rest_url('digitalogic/v1/patris/push');
?>
<div class="wrap digitalogic-patris-reports">
    <h1><?php echo esc_html__('Digitalogic Patris Reports', 'digitalogic'); ?></h1>
    <p class="description"><?php echo esc_html__('Normalized Patris/API data is compared against WooCommerce to recreate and extend the old reportFinal workflows.', 'digitalogic'); ?></p>

    <?php if (!empty($notice)) : ?>
        <div class="notice notice-info is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <div class="digitalogic-report-layout">
        <section class="digitalogic-section digitalogic-report-settings">
            <h2><?php echo esc_html__('Feed Settings', 'digitalogic'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('digitalogic_patris_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-api-url"><?php echo esc_html__('Pull API URL', 'digitalogic'); ?></label></th>
                        <td><input class="regular-text code" id="digitalogic-patris-api-url" name="api_url" value="<?php echo esc_attr($settings['api_url']); ?>" dir="ltr"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-api-token"><?php echo esc_html__('Pull API Token', 'digitalogic'); ?></label></th>
                        <td><input class="regular-text code" id="digitalogic-patris-api-token" name="api_token" value="<?php echo esc_attr($settings['api_token']); ?>" dir="ltr" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-warehouses"><?php echo esc_html__('Selected Warehouses', 'digitalogic'); ?></label></th>
                        <td><input class="regular-text code" id="digitalogic-patris-warehouses" name="selected_warehouses" value="<?php echo esc_attr(implode(',', (array) $settings['selected_warehouses'])); ?>" dir="ltr"><p class="description"><?php echo esc_html__('Comma-separated dynamic warehouse keys from the normalized API.', 'digitalogic'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-shipping"><?php echo esc_html__('Shipping Methods JSON', 'digitalogic'); ?></label></th>
                        <td><textarea class="large-text code" rows="4" id="digitalogic-patris-shipping" name="shipping_methods" dir="ltr"><?php echo esc_textarea(wp_json_encode($settings['shipping_methods'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-stale"><?php echo esc_html__('Stale After Hours', 'digitalogic'); ?></label></th>
                        <td><input type="number" min="1" id="digitalogic-patris-stale" name="stale_after_hours" value="<?php echo esc_attr($settings['stale_after_hours']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-interval"><?php echo esc_html__('Pull Schedule', 'digitalogic'); ?></label></th>
                        <td>
                            <select id="digitalogic-patris-interval" name="sync_interval">
                                <?php foreach (array('' => __('Manual only', 'digitalogic'), 'hourly' => __('Hourly', 'digitalogic'), 'twicedaily' => __('Twice daily', 'digitalogic'), 'daily' => __('Daily', 'digitalogic')) as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['sync_interval'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary" name="digitalogic_patris_settings_submit" value="1"><?php echo esc_html__('Save Settings', 'digitalogic'); ?></button></p>
            </form>
            <form method="post" class="digitalogic-inline-form">
                <?php wp_nonce_field('digitalogic_patris_sync'); ?>
                <button class="button" name="digitalogic_patris_sync_submit" value="1"><?php echo esc_html__('Pull Sync Now', 'digitalogic'); ?></button>
            </form>
            <div class="digitalogic-push-box">
                <strong><?php echo esc_html__('Push endpoint', 'digitalogic'); ?></strong>
                <code><?php echo esc_html($push_url); ?></code>
                <strong><?php echo esc_html__('X-Digitalogic-Token', 'digitalogic'); ?></strong>
                <code><?php echo esc_html($push_token); ?></code>
            </div>
        </section>

        <section class="digitalogic-section digitalogic-report-summary">
            <h2><?php echo esc_html__('Summary', 'digitalogic'); ?></h2>
            <div class="digitalogic-report-stats">
                <span><strong><?php echo esc_html(number_format_i18n($report['counts']['woocommerce_products'])); ?></strong><?php echo esc_html__('WooCommerce products', 'digitalogic'); ?></span>
                <span><strong><?php echo esc_html(number_format_i18n($report['counts']['patris_products'])); ?></strong><?php echo esc_html__('Patris/API products', 'digitalogic'); ?></span>
                <span><strong><?php echo esc_html(number_format_i18n($report['counts']['patris_customers'])); ?></strong><?php echo esc_html__('Patris/API customers', 'digitalogic'); ?></span>
            </div>
            <?php if (!empty($report['last_sync'])) : ?>
                <p><strong><?php echo esc_html__('Last sync:', 'digitalogic'); ?></strong> <?php echo esc_html($report['last_sync']['synced_at'] ?? ''); ?></p>
            <?php endif; ?>
        </section>
    </div>

    <section class="digitalogic-section digitalogic-report-results">
        <h2><?php echo esc_html__('Problem Rows', 'digitalogic'); ?></h2>
        <?php foreach ($report['categories'] as $category) : ?>
            <details class="digitalogic-report-category is-<?php echo esc_attr($category['severity']); ?>" <?php echo $category['count'] ? 'open' : ''; ?>>
                <summary>
                    <span class="digitalogic-report-dot"></span>
                    <strong><?php echo esc_html($category['title']); ?></strong>
                    <span><?php echo esc_html(number_format_i18n($category['count'])); ?></span>
                </summary>
                <?php if (empty($category['items'])) : ?>
                    <p class="digitalogic-report-empty"><?php echo esc_html__('No rows in this category.', 'digitalogic'); ?></p>
                <?php else : ?>
                    <div class="digitalogic-report-table-wrap">
                        <table class="widefat striped digitalogic-report-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Code', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('WooCommerce', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Patris/API', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Stock', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Foreign', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Weight', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Final Price', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Updated', 'digitalogic'); ?></th>
                                    <th><?php echo esc_html__('Action', 'digitalogic'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($category['items'] as $item) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html($item['product_code'] ?? $item['customer_code'] ?? ''); ?></code></td>
                                        <td><?php echo esc_html($item['woo_name'] ?? ''); ?></td>
                                        <td><?php echo esc_html($item['name'] ?? ''); ?></td>
                                        <td class="digitalogic-num"><?php echo esc_html(isset($item['stock']) ? $item['stock'] : ''); ?></td>
                                        <td class="digitalogic-num"><?php echo esc_html(trim(($item['foreign_currency'] ?? '') . ' ' . ($item['foreign_price'] ?? ''))); ?></td>
                                        <td class="digitalogic-num"><?php echo esc_html(isset($item['weight_grams']) ? $item['weight_grams'] : ''); ?></td>
                                        <td class="digitalogic-num"><?php echo esc_html(isset($item['final_price']) ? $item['final_price'] : ''); ?></td>
                                        <td><?php echo esc_html($item['updated_at'] ?? ''); ?></td>
                                        <td><?php if (!empty($item['edit_url'])) : ?><a class="button button-small" href="<?php echo esc_url($item['edit_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Edit', 'digitalogic'); ?></a><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    </section>
</div>
