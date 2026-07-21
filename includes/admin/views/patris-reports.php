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
$report_base_url = admin_url('admin.php?page=digitalogic-patris-reports');
$report_scope = array();
if (!empty($report['source']['id']) && !empty($report['source']['dataset'])) {
    $report_scope = array(
        'report_source_id' => $report['source']['id'],
        'report_dataset' => $report['source']['dataset'],
    );
}
$report_url = static function ($values = array()) use ($report_base_url, $report_scope) {
    return add_query_arg(array_merge($report_scope, $values), $report_base_url);
};
$sparse_value = static function ($record, $key) {
    if (!is_array($record) || !array_key_exists($key, $record)) {
        return __('Missing', 'digitalogic');
    }
    if (null === $record[$key]) {
        return 'null';
    }
    if (is_array($record[$key])) {
        return wp_json_encode($record[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return (string) $record[$key];
};
$category_titles = array();
foreach ($report['categories'] as $report_category) {
    $category_titles[$report_category['key']] = $report_category['title'];
}
$row_status_titles = array(
	'matched'          => __('Matched', 'digitalogic'),
	'source_only'      => __('Source only', 'digitalogic'),
	'woocommerce_only' => __('WooCommerce only', 'digitalogic'),
	'ambiguous'        => __('Ambiguous', 'digitalogic'),
);
$report_status_titles = array(
	'current'                 => __('Current receiver state', 'digitalogic'),
	'static'                  => __('Validated static snapshot (read-only)', 'digitalogic'),
	'source_state_empty'      => __('No current source snapshot is available; reconciliation findings are withheld.', 'digitalogic'),
	'source_not_found'        => __('The selected source snapshot was not found; reconciliation findings are withheld.', 'digitalogic'),
	'source_scope_incomplete' => __('Both source ID and dataset are required; reconciliation findings are withheld.', 'digitalogic'),
);
?>
<div class="wrap digitalogic-patris-reports">
    <h1><?php echo esc_html__('Digitalogic Patris Reports', 'digitalogic'); ?></h1>
    <p class="description"><?php echo esc_html__('Sparse transformed Patris data is compared against WooCommerce and applied through the living product-sync flow.', 'digitalogic'); ?></p>

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
                <strong><?php echo esc_html__('X-Patris-Product-Sync-Secret', 'digitalogic'); ?></strong>
                <code><?php echo esc_html($product_sync_secret); ?></code>
            </div>
        </section>

        <section class="digitalogic-section digitalogic-report-summary">
            <h2><?php echo esc_html__('Summary', 'digitalogic'); ?></h2>
            <div class="digitalogic-report-stats">
                <span><strong><?php echo esc_html(number_format_i18n($report['counts']['woocommerce_products'])); ?></strong><?php echo esc_html__('WooCommerce products', 'digitalogic'); ?></span>
				<span><strong><?php echo esc_html(number_format_i18n($report['counts']['patris_products'])); ?></strong><?php echo esc_html__('Current Patris products', 'digitalogic'); ?></span>
				<span><strong><?php echo esc_html(number_format_i18n($report['counts']['matched_products'])); ?></strong><?php echo esc_html__('Exact Code matches', 'digitalogic'); ?></span>
				<span><strong><?php echo esc_html(number_format_i18n($report['counts']['source_only_products'])); ?></strong><?php echo esc_html__('Patris-only products', 'digitalogic'); ?></span>
				<span><strong><?php echo esc_html(number_format_i18n($report['counts']['positive_source_only_products'])); ?></strong><?php echo esc_html__('Positive-stock Patris-only products', 'digitalogic'); ?></span>
				<span><strong><?php echo esc_html(number_format_i18n($report['counts']['woocommerce_only_products'])); ?></strong><?php echo esc_html__('WooCommerce-only products', 'digitalogic'); ?></span>
				<span><strong><?php echo esc_html(number_format_i18n($report['counts']['drift_products'])); ?></strong><?php echo esc_html__('Products with drift', 'digitalogic'); ?></span>
            </div>
			<?php if (!empty($report['source'])) : ?>
				<p><strong><?php echo esc_html__('Current source:', 'digitalogic'); ?></strong> <code><?php echo esc_html($report['source']['id'] . ' / ' . $report['source']['dataset']); ?></code></p>
				<p><strong><?php echo esc_html__('Source generated:', 'digitalogic'); ?></strong> <?php echo esc_html($report['source']['generated_at'] ?? ''); ?></p>
            <?php endif; ?>
			<p><strong><?php echo esc_html__('Variable parents excluded:', 'digitalogic'); ?></strong> <?php echo esc_html(number_format_i18n($report['counts']['variable_parents_excluded'])); ?></p>
			<p><strong><?php echo esc_html__('Report state:', 'digitalogic'); ?></strong> <?php echo esc_html($report_status_titles[$report['status']] ?? $report['status']); ?></p>
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
                        <th scope="col"><?php echo esc_html__('Price per kg', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Currency', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Enabled', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Assigned products', 'digitalogic'); ?></th>
                        <th scope="col"><?php echo esc_html__('Actions', 'digitalogic'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($shipping_methods)) : ?>
						<tr><td colspan="7"><?php echo esc_html__('No shipping methods are available.', 'digitalogic'); ?></td></tr>
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
                                    <label class="screen-reader-text" for="<?php echo esc_attr($form_id); ?>-rate"><?php echo esc_html__('Shipping price per kilogram', 'digitalogic'); ?></label>
                                    <input id="<?php echo esc_attr($form_id); ?>-rate" class="small-text" form="<?php echo esc_attr($form_id); ?>" type="number" min="0" step="any" name="shipping_price_per_kg" value="<?php echo esc_attr($method['price_per_kg']); ?>" required dir="ltr">
                                </td>
                                <td>
                                    <label class="screen-reader-text" for="<?php echo esc_attr($form_id); ?>-currency"><?php echo esc_html__('Shipping price currency', 'digitalogic'); ?></label>
                                    <select id="<?php echo esc_attr($form_id); ?>-currency" form="<?php echo esc_attr($form_id); ?>" name="shipping_price_per_kg_currency" required dir="ltr">
                                        <option value="CNY" <?php selected('CNY', $method['currency']); ?>>CNY</option>
                                        <option value="IRR" <?php selected('IRR', $method['currency']); ?>>IRR</option>
                                    </select>
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
                    <th scope="row"><label for="digitalogic-shipping-new-rate"><?php echo esc_html__('Price per kg', 'digitalogic'); ?></label></th>
                    <td><input id="digitalogic-shipping-new-rate" type="number" min="0" step="any" name="shipping_price_per_kg" required dir="ltr"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="digitalogic-shipping-new-currency"><?php echo esc_html__('Currency', 'digitalogic'); ?></label></th>
                    <td>
                        <select id="digitalogic-shipping-new-currency" name="shipping_price_per_kg_currency" required dir="ltr">
                            <option value="CNY">CNY</option>
                            <option value="IRR">IRR</option>
                        </select>
                    </td>
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
                                        '%1$s - %2$s %3$s/kg%4$s',
                                        $method['name'],
                                        $method['price_per_kg'],
                                        $method['currency'],
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
		<h2><?php echo esc_html__('Current Product Report', 'digitalogic'); ?></h2>
		<?php if (!in_array($report['status'], array('current', 'static'), true)) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html($report_status_titles[$report['status']] ?? __('The report source is unavailable.', 'digitalogic')); ?></p></div>
		<?php endif; ?>
		<p class="description"><?php echo esc_html__('The report matches only exact product Code metadata. WooCommerce SKU is never used as a fallback.', 'digitalogic'); ?></p>
		<p class="nav-tab-wrapper">
			<a class="nav-tab <?php echo 'warnings' === $report['view'] ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($report_url(array('report_view' => 'warnings'))); ?>"><?php echo esc_html__('Warnings', 'digitalogic'); ?></a>
			<a class="nav-tab <?php echo 'price_list' === $report['view'] ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($report_url(array('report_view' => 'price_list'))); ?>"><?php echo esc_html__('Price List', 'digitalogic'); ?></a>
		</p>

		<?php if ('warnings' === $report['view']) : ?>
			<div class="digitalogic-report-stats">
				<a class="button <?php echo empty($report['filters']['category']) ? 'button-primary' : ''; ?>" href="<?php echo esc_url($report_url(array('report_view' => 'warnings'))); ?>"><?php echo esc_html__('All warnings', 'digitalogic'); ?></a>
				<?php foreach ($report['categories'] as $category) : ?>
					<?php if (empty($category['count'])) : continue; endif; ?>
					<a class="button <?php echo $report['filters']['category'] === $category['key'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url($report_url(array('report_view' => 'warnings', 'report_category' => $category['key']))); ?>">
						<?php echo esc_html($category['title'] . ' (' . number_format_i18n($category['count']) . ')'); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if (empty($report['rows'])) : ?>
			<p class="digitalogic-report-empty"><?php echo esc_html__('No rows on this report page.', 'digitalogic'); ?></p>
		<?php else : ?>
			<div class="digitalogic-report-table-wrap">
				<table class="widefat striped digitalogic-report-table">
					<thead>
						<tr>
							<th><?php echo esc_html__('Product Code', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('State', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('WooCommerce', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Patris', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Stock: source / WooCommerce', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('CNY price', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Weight (g)', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Price: source / WooCommerce', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Source updated', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Findings', 'digitalogic'); ?></th>
							<th><?php echo esc_html__('Action', 'digitalogic'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($report['rows'] as $item) : ?>
							<?php $source_row = $item['source'] ?? array(); $woo_row = $item['woocommerce'] ?? array(); ?>
							<tr>
								<td><code><?php echo esc_html($item['product_code']); ?></code></td>
								<td><?php echo esc_html($row_status_titles[$item['status']] ?? $item['status']); ?></td>
								<td><?php echo esc_html($woo_row['name'] ?? '—'); ?></td>
								<td><?php echo esc_html($sparse_value($source_row, 'name')); ?></td>
								<td class="digitalogic-num"><span dir="ltr"><?php echo esc_html($sparse_value($source_row, 'total_stock') . ' / ' . (array_key_exists('stock_quantity', $woo_row) ? (null === $woo_row['stock_quantity'] ? 'null' : $woo_row['stock_quantity']) : '—')); ?></span></td>
								<td class="digitalogic-num"><span dir="ltr"><?php echo esc_html($sparse_value($source_row, 'foreign_price')); ?></span></td>
								<td class="digitalogic-num"><span dir="ltr"><?php echo esc_html($sparse_value($source_row, 'weight_grams')); ?></span></td>
								<td class="digitalogic-num"><span dir="ltr"><?php echo esc_html($sparse_value($source_row, 'final_price') . ' / ' . ($woo_row['active_price'] ?? '—')); ?></span></td>
								<td><span dir="ltr"><?php echo esc_html($sparse_value($source_row, 'source_updated_at')); ?></span></td>
								<td>
									<?php if (empty($item['issues'])) : ?>
										<span class="description"><?php echo esc_html__('Current', 'digitalogic'); ?></span>
									<?php else : ?>
										<?php foreach ($item['issues'] as $issue) : ?><span class="digitalogic-report-badge"><?php echo esc_html($category_titles[$issue] ?? $issue); ?></span> <?php endforeach; ?>
									<?php endif; ?>
								</td>
								<td><?php if (!empty($item['edit_url'])) : ?><a class="button button-small" href="<?php echo esc_url($item['edit_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Edit', 'digitalogic'); ?></a><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php if ($report['pagination']['pages'] > 1) : ?>
			<p class="tablenav-pages">
				<?php if ($report['pagination']['page'] > 1) : ?><a class="button" href="<?php echo esc_url($report_url(array('report_view' => $report['view'], 'report_category' => $report['filters']['category'], 'report_page' => $report['pagination']['page'] - 1))); ?>"><?php echo esc_html__('Previous', 'digitalogic'); ?></a><?php endif; ?>
				<span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'digitalogic'), $report['pagination']['page'], $report['pagination']['pages'])); ?></span>
				<?php if ($report['pagination']['page'] < $report['pagination']['pages']) : ?><a class="button" href="<?php echo esc_url($report_url(array('report_view' => $report['view'], 'report_category' => $report['filters']['category'], 'report_page' => $report['pagination']['page'] + 1))); ?>"><?php echo esc_html__('Next', 'digitalogic'); ?></a><?php endif; ?>
			</p>
		<?php endif; ?>
    </section>
</div>
