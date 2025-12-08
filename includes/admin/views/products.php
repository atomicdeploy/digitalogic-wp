<div class="wrap digitalogic-products">
    <h1><?php _e('Product Management', 'digitalogic'); ?></h1>
    
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
</div>
