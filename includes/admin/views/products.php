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
	
	<div id="poststuff">
		<div id="post-body" class="metabox-holder columns-1">
			<div id="post-body-content">
				<!-- Product Table Postbox -->
				<div id="product-table" class="postbox">
					<div class="postbox-header">
						<h2 class="hndle"><?php _e('Products', 'digitalogic'); ?></h2>
						<div class="handle-actions hide-if-no-js">
							<button type="button" class="handlediv" aria-expanded="true">
								<span class="screen-reader-text"><?php _e('Toggle panel: Products', 'digitalogic'); ?></span>
								<span class="toggle-indicator" aria-hidden="true"></span>
							</button>
						</div>
					</div>
					<div class="inside">
						<div class="digitalogic-toolbar">
							<input type="text" id="product-search" placeholder="<?php _e( 'Search products...', 'digitalogic' ); ?>">
							<button type="button" id="refresh-products" class="button"><?php _e( 'Refresh', 'digitalogic' ); ?></button>
							<button type="button" id="bulk-update-btn" class="button button-primary"><?php _e( 'Save Changes', 'digitalogic' ); ?></button>
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
				</div>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Initialize postboxes with unique page identifier
	postboxes.add_postbox_toggles('digitalogic_products');
});
</script>
