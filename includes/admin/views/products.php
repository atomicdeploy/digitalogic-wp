<div class="wrap digitalogic-products">
	<h1><?php _e( 'Product Management', 'digitalogic' ); ?></h1>
	
	<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
	<div class="notice notice-info" id="digitalogic-debug-info">
		<p><strong>Debug Info:</strong></p>
		<ul>
			<li>Plugin Version: <?php echo esc_html( DIGITALOGIC_VERSION ); ?></li>
			<li>WordPress Version: <?php echo esc_html( get_bloginfo( 'version' ) ); ?></li>
			<li>WooCommerce Version: <?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : 'Not active' ); ?></li>
			<li>jQuery: <span id="jquery-version">Checking...</span></li>
			<li>DataTables: <span id="datatables-version">Checking...</span></li>
			<li>Digitalogic JS: <span id="digitalogic-js">Checking...</span></li>
		</ul>
	</div>
	<?php
	// Add inline script with nonce for debug info
	$inline_script = "
		jQuery(document).ready(function($) {
			$('#jquery-version').text(typeof $ !== 'undefined' ? 'Loaded (v' + $.fn.jquery + ')' : 'Not loaded');
			$('#datatables-version').text(typeof $.fn.DataTable !== 'undefined' ? 'Loaded (v' + $.fn.dataTable.version + ')' : 'Not loaded');
			$('#digitalogic-js').text(typeof digitalogic !== 'undefined' ? 'Loaded' : 'Not loaded');
		});
	";
	wp_add_inline_script( 'digitalogic-admin', $inline_script );
	?>
	<?php endif; ?>
	
	<div class="digitalogic-toolbar">
		<input type="text" id="product-search" placeholder="<?php _e( 'Search products...', 'digitalogic' ); ?>">
		<button type="button" id="toggle-filters" class="button"><?php _e( 'Show Filters', 'digitalogic' ); ?></button>
		<label class="mode-toggle">
			<input type="checkbox" id="edit-mode-toggle" checked>
			<span class="mode-toggle-label"><?php _e( 'Edit Mode', 'digitalogic' ); ?></span>
		</label>
		<label class="mode-toggle">
			<input type="checkbox" id="auto-save-toggle">
			<span class="mode-toggle-label"><?php _e( 'Auto-Save', 'digitalogic' ); ?></span>
		</label>
		<span class="auto-save-indicator" id="auto-save-status" style="display: none;">
			<span class="dashicons dashicons-saved"></span>
			<span id="auto-save-text">Saved</span>
		</span>
		<button type="button" id="refresh-products" class="button"><?php _e( 'Refresh', 'digitalogic' ); ?></button>
		<button type="button" id="bulk-update-btn" class="button button-primary"><?php _e( 'Save Changes', 'digitalogic' ); ?></button>
	</div>
	
	<div class="digitalogic-filters" id="product-filters" style="display: none;">
		<h3><?php _e( 'Filter Products', 'digitalogic' ); ?></h3>
		<div class="filter-grid">
			<div class="filter-field">
				<label for="filter-sku"><?php _e( 'SKU', 'digitalogic' ); ?></label>
				<input type="text" id="filter-sku" class="filter-input" placeholder="<?php _e( 'Enter SKU...', 'digitalogic' ); ?>">
			</div>
			<div class="filter-field">
				<label for="filter-type"><?php _e( 'Product Type', 'digitalogic' ); ?></label>
				<select id="filter-type" class="filter-input">
					<option value=""><?php _e( 'All Types', 'digitalogic' ); ?></option>
					<option value="simple"><?php _e( 'Simple', 'digitalogic' ); ?></option>
					<option value="variable"><?php _e( 'Variable', 'digitalogic' ); ?></option>
					<option value="grouped"><?php _e( 'Grouped', 'digitalogic' ); ?></option>
					<option value="external"><?php _e( 'External', 'digitalogic' ); ?></option>
				</select>
			</div>
			<div class="filter-field">
				<label for="filter-status"><?php _e( 'Status', 'digitalogic' ); ?></label>
				<select id="filter-status" class="filter-input">
					<option value=""><?php _e( 'All Statuses', 'digitalogic' ); ?></option>
					<option value="publish"><?php _e( 'Published', 'digitalogic' ); ?></option>
					<option value="draft"><?php _e( 'Draft', 'digitalogic' ); ?></option>
					<option value="private"><?php _e( 'Private', 'digitalogic' ); ?></option>
				</select>
			</div>
			<div class="filter-field">
				<label for="filter-stock-status"><?php _e( 'Stock Status', 'digitalogic' ); ?></label>
				<select id="filter-stock-status" class="filter-input">
					<option value=""><?php _e( 'All', 'digitalogic' ); ?></option>
					<option value="instock"><?php _e( 'In Stock', 'digitalogic' ); ?></option>
					<option value="outofstock"><?php _e( 'Out of Stock', 'digitalogic' ); ?></option>
					<option value="onbackorder"><?php _e( 'On Backorder', 'digitalogic' ); ?></option>
				</select>
			</div>
			<div class="filter-field">
				<label for="filter-price-min"><?php _e( 'Min Price', 'digitalogic' ); ?></label>
				<input type="number" id="filter-price-min" class="filter-input" placeholder="<?php _e( 'Minimum price', 'digitalogic' ); ?>" step="0.01" min="0">
			</div>
			<div class="filter-field">
				<label for="filter-price-max"><?php _e( 'Max Price', 'digitalogic' ); ?></label>
				<input type="number" id="filter-price-max" class="filter-input" placeholder="<?php _e( 'Maximum price', 'digitalogic' ); ?>" step="0.01" min="0">
			</div>
			<div class="filter-field">
				<label for="filter-stock-min"><?php _e( 'Min Stock', 'digitalogic' ); ?></label>
				<input type="number" id="filter-stock-min" class="filter-input" placeholder="<?php _e( 'Minimum stock', 'digitalogic' ); ?>" step="1" min="0">
			</div>
			<div class="filter-field">
				<label for="filter-stock-max"><?php _e( 'Max Stock', 'digitalogic' ); ?></label>
				<input type="number" id="filter-stock-max" class="filter-input" placeholder="<?php _e( 'Maximum stock', 'digitalogic' ); ?>" step="1" min="0">
			</div>
			<div class="filter-field">
				<label for="filter-weight-min"><?php _e( 'Min Weight', 'digitalogic' ); ?></label>
				<input type="number" id="filter-weight-min" class="filter-input" placeholder="<?php _e( 'Minimum weight', 'digitalogic' ); ?>" step="0.01" min="0">
			</div>
			<div class="filter-field">
				<label for="filter-weight-max"><?php _e( 'Max Weight', 'digitalogic' ); ?></label>
				<input type="number" id="filter-weight-max" class="filter-input" placeholder="<?php _e( 'Maximum weight', 'digitalogic' ); ?>" step="0.01" min="0">
			</div>
		</div>
		<div class="filter-actions">
			<button type="button" id="apply-filters" class="button button-primary"><?php _e( 'Apply Filters', 'digitalogic' ); ?></button>
			<button type="button" id="clear-filters" class="button"><?php _e( 'Clear Filters', 'digitalogic' ); ?></button>
		</div>
	</div>
	
	<table id="products-table" class="display" style="width:100%">
		<thead>
			<tr>
				<th><input type="checkbox" id="select-all"></th>
				<th><?php _e( 'ID', 'digitalogic' ); ?></th>
				<th><?php _e( 'Image', 'digitalogic' ); ?></th>
				<th><?php _e( 'Name', 'digitalogic' ); ?></th>
				<th><?php _e( 'SKU', 'digitalogic' ); ?></th>
				<th><?php _e( 'Regular Price', 'digitalogic' ); ?></th>
				<th><?php _e( 'Sale Price', 'digitalogic' ); ?></th>
				<th><?php _e( 'Stock', 'digitalogic' ); ?></th>
				<th><?php _e( 'Weight', 'digitalogic' ); ?></th>
				<th><?php _e( 'Actions', 'digitalogic' ); ?></th>
			</tr>
		</thead>
		<tfoot>
			<tr>
				<th></th>
				<th><?php _e( 'ID', 'digitalogic' ); ?></th>
				<th><?php _e( 'Image', 'digitalogic' ); ?></th>
				<th><?php _e( 'Name', 'digitalogic' ); ?></th>
				<th><?php _e( 'SKU', 'digitalogic' ); ?></th>
				<th><?php _e( 'Regular Price', 'digitalogic' ); ?></th>
				<th><?php _e( 'Sale Price', 'digitalogic' ); ?></th>
				<th><?php _e( 'Stock', 'digitalogic' ); ?></th>
				<th><?php _e( 'Weight', 'digitalogic' ); ?></th>
				<th><?php _e( 'Actions', 'digitalogic' ); ?></th>
			</tr>
		</tfoot>
		<tbody>
			<!-- Populated by DataTables -->
		</tbody>
	</table>
	
	<noscript>
		<div class="notice notice-error">
			<p><?php _e( 'JavaScript is required for this page to function properly. Please enable JavaScript in your browser.', 'digitalogic' ); ?></p>
		</div>
	</noscript>
</div>
