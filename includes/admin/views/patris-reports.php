<?php
/**
 * Patris/API report page.
 *
 * @var array  $settings
 * @var string $product_sync_secret
 * @var array  $report
 * @var string $notice
 * @var string $notice_type
 * @var array  $shipping_methods
 * @var array  $default_markup
 * @var array  $currency_status
 * @var array|null $shipping_assignment
 */

if (!defined('ABSPATH')) {
    exit;
}

$product_sync_url = rest_url('digitalogic/patris/product-sync');
$notice_type = in_array($notice_type, array('success', 'error', 'warning', 'info'), true) ? $notice_type : 'info';
?>
<div class="wrap digitalogic-patris-reports">
    <h1><?php echo esc_html__('Digitalogic Patris Reports', 'digitalogic'); ?></h1>
    <p class="description"><?php echo esc_html__('Sparse transformed Patris data is compared against WooCommerce and applied through the living product-sync contract.', 'digitalogic'); ?></p>

    <?php if (!empty($notice)) : ?>
        <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <div class="digitalogic-report-layout">
        <section class="digitalogic-section digitalogic-report-settings">
            <h2><?php echo esc_html__('Integration Settings', 'digitalogic'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('digitalogic_patris_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-warehouses"><?php echo esc_html__('Selected Warehouses', 'digitalogic'); ?></label></th>
                        <td><input class="regular-text code" id="digitalogic-patris-warehouses" name="selected_warehouses" value="<?php echo esc_attr(implode(',', (array) $settings['selected_warehouses'])); ?>" dir="ltr"><p class="description"><?php echo esc_html__('Comma-separated dynamic warehouse keys from the normalized API.', 'digitalogic'); ?></p></td>
                    </tr>
                    <tr>
						<th scope="row"><?php echo esc_html__('Shipping Method Catalog', 'digitalogic'); ?></th>
                        <td>
							<code><?php echo esc_html(rest_url('digitalogic/v1/shipping-methods')); ?></code>
							<p class="description"><?php echo esc_html__('Supplier shipping methods are managed as validated records. They are separate from WooCommerce customer delivery methods.', 'digitalogic'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="digitalogic-patris-stale"><?php echo esc_html__('Stale After Hours', 'digitalogic'); ?></label></th>
                        <td><input type="number" min="1" id="digitalogic-patris-stale" name="stale_after_hours" value="<?php echo esc_attr($settings['stale_after_hours']); ?>"></td>
                    </tr>
                </table>
                <p class="submit"><button class="button button-primary" name="digitalogic_patris_settings_submit" value="1"><?php echo esc_html__('Save Settings', 'digitalogic'); ?></button></p>
            </form>
            <div class="digitalogic-push-box">
                <strong><?php echo esc_html__('Product-sync endpoint', 'digitalogic'); ?></strong>
                <code><?php echo esc_html($product_sync_url); ?></code>
                <strong><?php echo esc_html__('X-Digitalogic-Product-Sync-Secret', 'digitalogic'); ?></strong>
                <code><?php echo esc_html($product_sync_secret); ?></code>
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

    <section class="digitalogic-section digitalogic-shipping-methods">
		<h2><?php echo esc_html__('Supplier Shipping Methods', 'digitalogic'); ?></h2>
        <p class="description">
			<?php echo esc_html__('Manage how products are shipped from suppliers through Patris. These records do not change WooCommerce customer delivery methods.', 'digitalogic'); ?>
        </p>

        <div class="digitalogic-currency-status <?php echo $currency_status['compatible'] ? 'is-ready' : 'is-warning'; ?>">
            <span class="dashicons <?php echo $currency_status['compatible'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
            <div>
                <h3><?php echo esc_html__('Pricing catalog currency', 'digitalogic'); ?></h3>
                <p>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: WooCommerce currency code, 2: compatibility status. */
                        __('WooCommerce base: %1$s. Integration status: %2$s.', 'digitalogic'),
                        $currency_status['code'],
						$currency_status['compatible']
							? __( 'Ready', 'digitalogic' )
							: __( 'Base currency mismatch', 'digitalogic' )
                    ));
                    ?>
                </p>
                <p class="description"><?php echo esc_html__('The catalog exposes IRT as Toman (10 IRR per unit) and blocks IRT pricing when this base-currency check fails.', 'digitalogic'); ?></p>
            </div>
        </div>

        <h3><?php echo esc_html__('Default percentage markup', 'digitalogic'); ?></h3>
        <p class="description">
            <?php echo esc_html__('Used only when a product has no markup configuration. A product percentage overrides it; fixed or unsupported product markup never falls back. Saving or clearing this value does not update WooCommerce prices.', 'digitalogic'); ?>
        </p>
        <div class="digitalogic-report-table-wrap">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="digitalogic-default-profit-percent"><?php echo esc_html__('Global profit percent', 'digitalogic'); ?></label></th>
                    <td>
                        <form method="post" class="digitalogic-inline-form">
                            <?php wp_nonce_field('digitalogic_shipping_admin'); ?>
                            <input type="hidden" name="digitalogic_shipping_action" value="update_default_markup">
                            <input
                                id="digitalogic-default-profit-percent"
                                class="small-text code"
                                name="default_profit_percent"
                                value="<?php echo esc_attr(!empty($default_markup['configured']) ? $default_markup['profit_percent'] : ''); ?>"
                                inputmode="decimal"
                                pattern="[0-9۰-۹٠-٩]+(?:[.٫][0-9۰-۹٠-٩]{1,12})?"
                                required
                                dir="ltr"
                            >
                            <span>%</span>
                            <button type="submit" class="button button-primary"><?php echo esc_html__('Save default', 'digitalogic'); ?></button>
                        </form>
                        <?php if (!empty($default_markup['configured'])) : ?>
                            <form method="post" class="digitalogic-inline-form">
                                <?php wp_nonce_field('digitalogic_shipping_admin'); ?>
                                <input type="hidden" name="digitalogic_shipping_action" value="clear_default_markup">
                                <button type="submit" class="button button-secondary"><?php echo esc_html__('Clear default', 'digitalogic'); ?></button>
                            </form>
                        <?php endif; ?>
                        <p class="description">
                            <?php
                            printf(
                                esc_html__('Allowed range: %1$s–%2$s%%, up to %3$d fractional digits. The recovered workbook value 30%% is proposed for a reviewed production action and is not seeded automatically.', 'digitalogic'),
                                esc_html($default_markup['bounds']['minimum']),
                                esc_html($default_markup['bounds']['maximum']),
                                absint($default_markup['bounds']['maximum_fraction_digits'])
                            );
                            ?>
                        </p>
                        <p class="description"><code><?php echo esc_html($default_markup['revision']); ?></code></p>
                    </td>
                </tr>
            </table>
        </div>

		<h3><?php echo esc_html__('Shipping methods', 'digitalogic'); ?></h3>
        <div class="digitalogic-report-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html__('Method ID', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Name', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('CNY per kg', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Enabled', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Assigned products', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Actions', 'digitalogic'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($shipping_methods)) : ?>
						<tr><td colspan="6"><?php echo esc_html__('No shipping methods are available.', 'digitalogic'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($shipping_methods as $method) : ?>
                            <?php
                            $method_id = (string) $method['id'];
                            $form_id = 'digitalogic-shipping-update-' . sanitize_html_class($method_id);
                            $assigned_products = isset($method['assigned_products']) ? absint($method['assigned_products']) : 0;
                            $can_delete = $assigned_products === 0;
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($method_id); ?></code></td>
                                <td>
                                    <label class="screen-reader-text" for="<?php echo esc_attr($form_id); ?>-name"><?php echo esc_html__('Method name', 'digitalogic'); ?></label>
                                    <input id="<?php echo esc_attr($form_id); ?>-name" class="regular-text" form="<?php echo esc_attr($form_id); ?>" name="method_name" value="<?php echo esc_attr($method['name']); ?>" required>
                                </td>
                                <td>
                                    <label class="screen-reader-text" for="<?php echo esc_attr($form_id); ?>-rate"><?php echo esc_html__('CNY price per kilogram', 'digitalogic'); ?></label>
                                    <input id="<?php echo esc_attr($form_id); ?>-rate" class="small-text" form="<?php echo esc_attr($form_id); ?>" type="number" min="0" step="any" name="shipping_price_per_kg_cny" value="<?php echo esc_attr($method['shipping_price_per_kg_cny']); ?>" required dir="ltr">
                                </td>
                                <td>
                                    <label>
                                        <input form="<?php echo esc_attr($form_id); ?>" type="checkbox" name="method_enabled" value="1" <?php checked(!empty($method['enabled'])); ?>>
                                        <?php echo esc_html__('Active', 'digitalogic'); ?>
                                    </label>
                                </td>
                                <td><?php echo esc_html(number_format_i18n($assigned_products)); ?></td>
                                <td>
                                    <form method="post" id="<?php echo esc_attr($form_id); ?>" class="digitalogic-inline-form">
                                        <?php wp_nonce_field('digitalogic_shipping_admin'); ?>
                                        <input type="hidden" name="digitalogic_shipping_action" value="update_method">
                                        <input type="hidden" name="method_id" value="<?php echo esc_attr($method_id); ?>">
                                        <button type="submit" class="button button-small"><?php echo esc_html__('Save', 'digitalogic'); ?></button>
                                    </form>
                                    <?php if ($can_delete) : ?>
                                        <form method="post" class="digitalogic-inline-form">
                                            <?php wp_nonce_field('digitalogic_shipping_admin'); ?>
                                            <input type="hidden" name="digitalogic_shipping_action" value="delete_method">
                                            <input type="hidden" name="method_id" value="<?php echo esc_attr($method_id); ?>">
                                            <button type="submit" class="button button-small button-link-delete"><?php echo esc_html__('Delete', 'digitalogic'); ?></button>
                                        </form>
                                    <?php else : ?>
                                        <span class="description"><?php echo esc_html__('Delete unavailable', 'digitalogic'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

		<h3><?php echo esc_html__('Add a shipping method', 'digitalogic'); ?></h3>
        <form method="post">
            <?php wp_nonce_field('digitalogic_shipping_admin'); ?>
            <input type="hidden" name="digitalogic_shipping_action" value="create_method">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="digitalogic-shipping-new-id"><?php echo esc_html__('Method ID', 'digitalogic'); ?></label></th>
                    <td>
                        <input id="digitalogic-shipping-new-id" class="regular-text code" name="method_id" required pattern="[a-z][a-z0-9_]{1,63}" dir="ltr">
						<p class="description"><?php echo esc_html__('A stable 2-64 character lowercase ID, such as rail. It cannot be renamed later.', 'digitalogic'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="digitalogic-shipping-new-name"><?php echo esc_html__('Name', 'digitalogic'); ?></label></th>
                    <td><input id="digitalogic-shipping-new-name" class="regular-text" name="method_name" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="digitalogic-shipping-new-rate"><?php echo esc_html__('CNY per kg', 'digitalogic'); ?></label></th>
                    <td><input id="digitalogic-shipping-new-rate" type="number" min="0" step="any" name="shipping_price_per_kg_cny" required dir="ltr"></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Status', 'digitalogic'); ?></th>
                    <td><label><input type="checkbox" name="method_enabled" value="1" checked> <?php echo esc_html__('Enabled', 'digitalogic'); ?></label></td>
                </tr>
            </table>
			<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__('Add shipping method', 'digitalogic'); ?></button></p>
        </form>

        <h3><?php echo esc_html__('Assign a product', 'digitalogic'); ?></h3>
        <p class="description"><?php echo esc_html__('Enter one exact Patris Code or WooCommerce SKU. An empty method selection clears the current assignment.', 'digitalogic'); ?></p>
        <form method="post">
            <?php wp_nonce_field('digitalogic_shipping_admin'); ?>
            <input type="hidden" name="digitalogic_shipping_action" value="assign_product">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="digitalogic-shipping-product-code"><?php echo esc_html__('Exact Code / SKU', 'digitalogic'); ?></label></th>
                    <td><input id="digitalogic-shipping-product-code" class="regular-text code" name="product_code" required dir="ltr"></td>
                </tr>
                <tr>
					<th scope="row"><label for="digitalogic-shipping-assignment-method"><?php echo esc_html__('Shipping method', 'digitalogic'); ?></label></th>
                    <td>
                        <select id="digitalogic-shipping-assignment-method" name="assignment_method_id">
                            <option value=""><?php echo esc_html__('Clear assignment', 'digitalogic'); ?></option>
                            <?php foreach ($shipping_methods as $method) : ?>
                                <option value="<?php echo esc_attr($method['id']); ?>" <?php disabled(empty($method['enabled'])); ?>>
                                    <?php
                                    echo esc_html(sprintf(
                                        '%1$s - %2$s CNY/kg%3$s',
                                        $method['name'],
                                        $method['shipping_price_per_kg_cny'],
                                        empty($method['enabled']) ? ' (' . __('disabled', 'digitalogic') . ')' : ''
                                    ));
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html__('Apply assignment', 'digitalogic'); ?></button></p>
        </form>

        <?php if (is_array($shipping_assignment)) : ?>
            <p>
                <strong><?php echo esc_html__('Resolved product:', 'digitalogic'); ?></strong>
                <?php echo esc_html(sprintf('#%1$d (%2$s)', absint($shipping_assignment['product_id']), $shipping_assignment['resolved_by'])); ?>
                &mdash;
                <strong><?php echo esc_html__('Current method:', 'digitalogic'); ?></strong>
                <?php
				echo empty($shipping_assignment['shipping_method'])
                    ? esc_html__('None', 'digitalogic')
					: esc_html($shipping_assignment['shipping_method']['name']);
                ?>
            </p>
        <?php endif; ?>
    </section>

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
