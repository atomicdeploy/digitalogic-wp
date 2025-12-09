<div class="wrap digitalogic-products">
    <h1><?php _e('Product Management', 'digitalogic'); ?></h1>
    
    <?php if (WP_DEBUG): ?>
    <div class="notice notice-info" id="digitalogic-debug-info">
        <p><strong>Debug Info:</strong></p>
        <ul>
            <li>Plugin Version: <?php echo DIGITALOGIC_VERSION; ?></li>
            <li>WordPress Version: <?php echo get_bloginfo('version'); ?></li>
            <li>WooCommerce Version: <?php echo defined('WC_VERSION') ? WC_VERSION : 'Not active'; ?></li>
            <li>jQuery: <span id="jquery-version">Checking...</span></li>
            <li>DataTables: <span id="datatables-version">Checking...</span></li>
            <li>Digitalogic JS: <span id="digitalogic-js">Checking...</span></li>
        </ul>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#jquery-version').text(typeof $ !== 'undefined' ? 'Loaded (v' + $.fn.jquery + ')' : 'Not loaded');
            $('#datatables-version').text(typeof $.fn.DataTable !== 'undefined' ? 'Loaded (v' + $.fn.dataTable.version + ')' : 'Not loaded');
            $('#digitalogic-js').text(typeof digitalogic !== 'undefined' ? 'Loaded' : 'Not loaded');
        });
    </script>
    <?php endif; ?>
    
    <div class="digitalogic-toolbar">
        <input type="text" id="product-search" placeholder="<?php _e('Search products...', 'digitalogic'); ?>">
        <button type="button" id="refresh-products" class="button"><?php _e('Refresh', 'digitalogic'); ?></button>
        <button type="button" id="bulk-update-btn" class="button button-primary"><?php _e('Save Changes', 'digitalogic'); ?></button>
    </div>
    
    <table id="products-table" class="display" style="width:100%">
        <thead>
            <tr>
                <th><input type="checkbox" id="select-all"></th>
                <th><?php _e('ID', 'digitalogic'); ?></th>
                <th><?php _e('Image', 'digitalogic'); ?></th>
                <th><?php _e('Name', 'digitalogic'); ?></th>
                <th><?php _e('SKU', 'digitalogic'); ?></th>
                <th><?php _e('Regular Price', 'digitalogic'); ?></th>
                <th><?php _e('Sale Price', 'digitalogic'); ?></th>
                <th><?php _e('Stock', 'digitalogic'); ?></th>
                <th><?php _e('Weight', 'digitalogic'); ?></th>
                <th><?php _e('Actions', 'digitalogic'); ?></th>
            </tr>
        </thead>
        <tbody>
            <!-- Populated by DataTables -->
        </tbody>
    </table>
    
    <noscript>
        <div class="notice notice-error">
            <p><?php _e('JavaScript is required for this page to function properly. Please enable JavaScript in your browser.', 'digitalogic'); ?></p>
        </div>
    </noscript>
</div>
