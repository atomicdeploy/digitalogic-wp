<?php
/**
 * Product Data Debug/Dump Page
 * 
 * Shows product metadata from both wp_postmeta and wp_wc_product_meta_lookup
 * and warns about inconsistencies
 */

if (!defined('ABSPATH')) {
    exit;
}

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$metadata = null;
$error = null;

if ($product_id > 0) {
    $manager = Digitalogic_Product_Manager::instance();
    $metadata = $manager->get_product_metadata($product_id);
    
    if (is_wp_error($metadata)) {
        $error = $metadata->get_error_message();
        $metadata = null;
    }
}

?>
<div class="wrap">
    <h1><?php _e('Product Data Debug', 'digitalogic'); ?></h1>
    
    <p><?php _e('View detailed product metadata and check for inconsistencies between wp_postmeta and wp_wc_product_meta_lookup tables.', 'digitalogic'); ?></p>
    
    <div class="digitalogic-section">
        <h2><?php _e('Select Product', 'digitalogic'); ?></h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="product-debug">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="product_id"><?php _e('Product ID or SKU', 'digitalogic'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="product_id" name="product_id" value="<?php echo esc_attr($product_id); ?>" class="regular-text">
                        <p class="description"><?php _e('Enter a product ID to view its metadata', 'digitalogic'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button button-primary" value="<?php _e('Load Product Data', 'digitalogic'); ?>">
            </p>
        </form>
    </div>
    
    <?php if ($error) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($error); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if ($metadata) : ?>
        
        <!-- Product Information -->
        <div class="digitalogic-section">
            <h2><?php _e('Product Information', 'digitalogic'); ?></h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td><strong><?php _e('Product ID', 'digitalogic'); ?></strong></td>
                        <td dir="ltr"><?php echo esc_html($metadata['product_id']); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('SKU', 'digitalogic'); ?></strong></td>
                        <td dir="ltr"><?php echo esc_html($metadata['sku']); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Name', 'digitalogic'); ?></strong></td>
                        <td><?php echo esc_html($metadata['name']); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Type', 'digitalogic'); ?></strong></td>
                        <td><?php echo esc_html($metadata['type']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- wp_wc_product_meta_lookup Data -->
        <?php if (!empty($metadata['lookup_table'])) : ?>
        <div class="digitalogic-section">
            <h2><?php _e('WooCommerce Product Meta Lookup', 'digitalogic'); ?></h2>
            <p class="description"><?php _e('Data from wp_wc_product_meta_lookup table (authoritative source for product data)', 'digitalogic'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Field', 'digitalogic'); ?></th>
                        <th><?php _e('Value', 'digitalogic'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metadata['lookup_table'] as $key => $value) : ?>
                    <tr>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td dir="ltr"><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else : ?>
        <div class="notice notice-warning">
            <p><?php _e('⚠ Warning: Product not found in wp_wc_product_meta_lookup table. This may indicate a data synchronization issue.', 'digitalogic'); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- wp_postmeta Data -->
        <?php if (!empty($metadata['postmeta'])) : ?>
        <div class="digitalogic-section">
            <h2><?php _e('Product Meta (wp_postmeta)', 'digitalogic'); ?></h2>
            <p class="description"><?php _e('Data from wp_postmeta table', 'digitalogic'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Meta Key', 'digitalogic'); ?></th>
                        <th><?php _e('Meta Value', 'digitalogic'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($metadata['postmeta'] as $key => $value) : ?>
                    <tr>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td dir="ltr"><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Inconsistencies -->
        <div class="digitalogic-section">
            <h2><?php _e('Data Consistency Check', 'digitalogic'); ?></h2>
            <?php if (!empty($metadata['inconsistencies'])) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('⚠ Inconsistencies Detected:', 'digitalogic'); ?></strong></p>
                    <ul style="margin-left: 20px; list-style: disc;">
                        <?php foreach ($metadata['inconsistencies'] as $inconsistency) : ?>
                        <li><?php echo esc_html($inconsistency); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="description">
                        <?php _e('Inconsistencies between wp_postmeta and wp_wc_product_meta_lookup can cause issues. Consider regenerating the WooCommerce lookup tables.', 'digitalogic'); ?>
                    </p>
                    <p>
                        <button type="button" class="button" onclick="regenerateLookupTable(<?php echo esc_js($metadata['product_id']); ?>)">
                            <?php _e('Regenerate Lookup Table for This Product', 'digitalogic'); ?>
                        </button>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p><?php _e('✓ No inconsistencies detected. Data is synchronized between tables.', 'digitalogic'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- JSON Export -->
        <div class="digitalogic-section">
            <h2><?php _e('Export Data', 'digitalogic'); ?></h2>
            <p><?php _e('Copy this data for debugging or support purposes:', 'digitalogic'); ?></p>
            <textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px;"><?php echo esc_textarea(json_encode($metadata, JSON_PRETTY_PRINT)); ?></textarea>
        </div>
        
    <?php elseif ($product_id > 0) : ?>
        <div class="notice notice-info">
            <p><?php _e('Enter a product ID above and click "Load Product Data" to view metadata.', 'digitalogic'); ?></p>
        </div>
    <?php endif; ?>
</div>

<script>
function regenerateLookupTable(productId) {
    if (!confirm('<?php _e('Regenerate lookup table data for this product?', 'digitalogic'); ?>')) {
        return;
    }
    
    jQuery.ajax({
        url: digitalogic.ajax_url,
        type: 'POST',
        data: {
            action: 'digitalogic_regenerate_lookup',
            nonce: digitalogic.nonce,
            product_id: productId
        },
        success: function(response) {
            if (response.success) {
                alert('<?php _e('Lookup table regenerated successfully', 'digitalogic'); ?>');
                location.reload();
            } else {
                alert('<?php _e('Error:', 'digitalogic'); ?> ' + response.data);
            }
        },
        error: function() {
            alert('<?php _e('An error occurred', 'digitalogic'); ?>');
        }
    });
}
</script>
