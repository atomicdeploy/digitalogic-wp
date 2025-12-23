<?php
/**
 * WP-CLI Commands
 * 
 * Command-line interface for Digitalogic plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Digitalogic WP-CLI Commands
 */
class Digitalogic_CLI_Commands {
    
    /**
     * Get currency rates
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic currency get
     * 
     * @when after_wp_load
     */
    public function currency_get($args, $assoc_args) {
        $options = Digitalogic_Options::instance();
        
        WP_CLI::line('Currency Rates:');
        WP_CLI::line('USD: ' . $options->get_dollar_price());
        WP_CLI::line('CNY: ' . $options->get_yuan_price());
        WP_CLI::line('Last Update: ' . $options->get_update_date());
    }
    
    /**
     * Update currency rates
     * 
     * ## OPTIONS
     * 
     * [--usd=<price>]
     * : USD price in local currency
     * 
     * [--cny=<price>]
     * : CNY price in local currency
     * 
     * [--recalculate]
     * : Recalculate all product prices
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic currency update --usd=42000 --cny=6000
     *     wp digitalogic currency update --usd=42000 --recalculate
     * 
     * @when after_wp_load
     */
    public function currency_update($args, $assoc_args) {
        $options = Digitalogic_Options::instance();
        
        $updated = false;
        
        if (isset($assoc_args['usd'])) {
            $options->set_dollar_price(floatval($assoc_args['usd']));
            WP_CLI::success('USD price updated to ' . $assoc_args['usd']);
            $updated = true;
        }
        
        if (isset($assoc_args['cny'])) {
            $options->set_yuan_price(floatval($assoc_args['cny']));
            WP_CLI::success('CNY price updated to ' . $assoc_args['cny']);
            $updated = true;
        }
        
        if (!$updated) {
            WP_CLI::error('No currency rates provided. Use --usd or --cny');
        }
        
        if (isset($assoc_args['recalculate'])) {
            WP_CLI::line('Recalculating product prices...');
            $pricing = Digitalogic_Pricing::instance();
            $results = $pricing->bulk_recalculate_prices();
            WP_CLI::success('Updated ' . $results['success'] . ' products, ' . $results['failed'] . ' failed');
        }
    }
    
    /**
     * List products
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : Number of products to list
     * ---
     * default: 10
     * ---
     * 
     * [--search=<term>]
     * : Search term
     * 
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic products list --limit=20
     *     wp digitalogic products list --search=arduino --format=json
     * 
     * @when after_wp_load
     */
    public function products_list($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 10;
        $search = isset($assoc_args['search']) ? $assoc_args['search'] : '';
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        
        $manager = Digitalogic_Product_Manager::instance();
        $products = $manager->get_products(array(
            'limit' => $limit,
            'search' => $search
        ));
        
        $items = array();
        foreach ($products as $product) {
            $items[] = array(
                'ID' => $product['id'],
                'Name' => $product['name'],
                'SKU' => $product['sku'],
                'Price' => $product['price'],
                'Stock' => $product['stock_quantity']
            );
        }
        
        WP_CLI\Utils\format_items($format, $items, array('ID', 'Name', 'SKU', 'Price', 'Stock'));
    }
    
    /**
     * Get product information
     * 
     * ## OPTIONS
     * 
     * [--id=<product_id>]
     * : Product ID
     * 
     * [--sku=<sku>]
     * : Product SKU
     * 
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic products get --id=123
     *     wp digitalogic products get --sku=113004012
     *     wp digitalogic products get --id=123 --format=json
     * 
     * @when after_wp_load
     */
    public function products_get($args, $assoc_args) {
        $manager = Digitalogic_Product_Manager::instance();
        $product = null;
        
        if (isset($assoc_args['id'])) {
            $product = $manager->get_product(intval($assoc_args['id']));
        } elseif (isset($assoc_args['sku'])) {
            $product = $manager->get_product_by_sku($assoc_args['sku']);
        } else {
            WP_CLI::error('Please specify either --id or --sku');
        }
        
        if (!$product) {
            WP_CLI::error('Product not found');
        }
        
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        
        if ($format === 'json') {
            WP_CLI::line(json_encode($product, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::line('Product Information:');
            WP_CLI::line('');
            WP_CLI::line('ID: ' . $product['id']);
            WP_CLI::line('Name: ' . $product['name']);
            WP_CLI::line('SKU: ' . $product['sku']);
            WP_CLI::line('Type: ' . $product['type']);
            WP_CLI::line('Status: ' . $product['status']);
            WP_CLI::line('Regular Price: ' . $product['regular_price']);
            WP_CLI::line('Sale Price: ' . $product['sale_price']);
            WP_CLI::line('Price: ' . $product['price']);
            WP_CLI::line('Stock Quantity: ' . $product['stock_quantity']);
            WP_CLI::line('Stock Status: ' . $product['stock_status']);
            WP_CLI::line('Manage Stock: ' . ($product['manage_stock'] ? 'Yes' : 'No'));
        }
    }
    
    /**
     * Get product metadata
     * 
     * ## OPTIONS
     * 
     * [--id=<product_id>]
     * : Product ID
     * 
     * [--sku=<sku>]
     * : Product SKU
     * 
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic products metadata --id=123
     *     wp digitalogic products metadata --sku=113004012
     *     wp digitalogic products metadata --id=123 --format=json
     * 
     * @when after_wp_load
     */
    public function products_metadata($args, $assoc_args) {
        $manager = Digitalogic_Product_Manager::instance();
        $metadata = null;
        
        if (isset($assoc_args['id'])) {
            $metadata = $manager->get_product_metadata(intval($assoc_args['id']));
        } elseif (isset($assoc_args['sku'])) {
            $metadata = $manager->get_product_metadata(null, $assoc_args['sku']);
        } else {
            WP_CLI::error('Please specify either --id or --sku');
        }
        
        if (is_wp_error($metadata)) {
            WP_CLI::error($metadata->get_error_message());
        }
        
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        
        if ($format === 'json') {
            WP_CLI::line(json_encode($metadata, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::line('Product Metadata for Product #' . $metadata['product_id'] . ' (SKU: ' . $metadata['sku'] . ')');
            WP_CLI::line('');
            
            // Display lookup table data
            if (!empty($metadata['lookup_table'])) {
                WP_CLI::line('=== WooCommerce Product Meta Lookup ===');
                foreach ($metadata['lookup_table'] as $key => $value) {
                    WP_CLI::line(str_pad($key, 25) . ' ' . $value);
                }
                WP_CLI::line('');
            }
            
            // Display postmeta data
            if (!empty($metadata['postmeta'])) {
                WP_CLI::line('=== Product Meta (wp_postmeta) ===');
                foreach ($metadata['postmeta'] as $key => $value) {
                    WP_CLI::line(str_pad($key, 25) . ' ' . $value);
                }
                WP_CLI::line('');
            }
            
            // Display inconsistencies
            if (!empty($metadata['inconsistencies'])) {
                WP_CLI::warning('Inconsistencies detected:');
                foreach ($metadata['inconsistencies'] as $inconsistency) {
                    WP_CLI::line('  - ' . $inconsistency);
                }
            } else {
                WP_CLI::success('No inconsistencies detected between tables');
            }
        }
    }
    
    /**
     * Update a product
     * 
     * ## OPTIONS
     * 
     * [--id=<product_id>]
     * : Product ID
     * 
     * [--sku=<sku>]
     * : Product SKU (for lookup)
     * 
     * [--price=<price>]
     * : Regular price
     * 
     * [--sale-price=<price>]
     * : Sale price
     * 
     * [--stock=<quantity>]
     * : Stock quantity
     * 
     * [--set-sku=<sku>]
     * : Set new SKU for the product
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic products update --id=123 --price=99.99 --stock=50
     *     wp digitalogic products update --sku=113004012 --price=250000
     *     wp digitalogic products update --id=123 --set-sku=NEW-SKU-123
     * 
     * @when after_wp_load
     */
    public function products_update($args, $assoc_args) {
        $manager = Digitalogic_Product_Manager::instance();
        
        $product_id = null;
        $lookup_sku = null;
        
        if (isset($assoc_args['id'])) {
            $product_id = intval($assoc_args['id']);
        } elseif (isset($assoc_args['sku'])) {
            $lookup_sku = $assoc_args['sku'];
        } else {
            WP_CLI::error('Please specify either --id or --sku');
        }
        
        $data = array();
        
        if (isset($assoc_args['price'])) {
            $data['regular_price'] = floatval($assoc_args['price']);
        }
        
        if (isset($assoc_args['sale-price'])) {
            $data['sale_price'] = floatval($assoc_args['sale-price']);
        }
        
        if (isset($assoc_args['stock'])) {
            $data['stock_quantity'] = intval($assoc_args['stock']);
        }
        
        if (isset($assoc_args['set-sku'])) {
            $data['sku'] = $assoc_args['set-sku'];
        }
        
        if (empty($data)) {
            WP_CLI::error('No update data provided');
        }
        
        $result = $manager->update_product($product_id, $data, $lookup_sku);
        
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        
        // Get the actual product ID for the success message
        if ($lookup_sku !== null) {
            $product_id = wc_get_product_id_by_sku($lookup_sku);
        }
        
        WP_CLI::success('Product #' . $product_id . ' updated');
    }
    
    /**
     * Export products
     * 
     * ## OPTIONS
     * 
     * [--format=<format>]
     * : Export format
     * ---
     * default: csv
     * options:
     *   - csv
     *   - json
     * ---
     * 
     * [--output=<file>]
     * : Output file path
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic export --format=csv
     *     wp digitalogic export --format=json --output=/path/to/products.json
     *     wp digitalogic export --format=excel --output=/path/to/products.xlsx
     * 
     * @when after_wp_load
     */
    public function export($args, $assoc_args) {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'csv';
        
        $import_export = Digitalogic_Import_Export::instance();
        
        if ($format === 'json') {
            $filepath = $import_export->export_json();
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel();
        } else {
            $filepath = $import_export->export_csv();
        }
        
        if (is_wp_error($filepath)) {
            WP_CLI::error($filepath->get_error_message());
        }
        
        if (isset($assoc_args['output'])) {
            $output = $assoc_args['output'];
            if (copy($filepath, $output)) {
                WP_CLI::success('Products exported to: ' . $output);
            } else {
                WP_CLI::error('Failed to copy export file');
            }
        } else {
            WP_CLI::success('Products exported to: ' . $filepath);
        }
    }
    
    /**
     * Import products
     * 
     * ## OPTIONS
     * 
     * <file>
     * : Input file path (CSV, JSON, or Excel)
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic import /path/to/products.csv
     *     wp digitalogic import /path/to/products.json
     *     wp digitalogic import /path/to/products.xlsx
     * 
     * @when after_wp_load
     */
    public function import($args, $assoc_args) {
        $filepath = $args[0];
        
        if (!file_exists($filepath)) {
            WP_CLI::error('File not found: ' . $filepath);
        }
        
        $import_export = Digitalogic_Import_Export::instance();
        
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        
        WP_CLI::line('Importing products...');
        
        if ($extension === 'json') {
            $results = $import_export->import_json($filepath);
        } elseif ($extension === 'xlsx' || $extension === 'xls') {
            $results = $import_export->import_excel($filepath);
        } else {
            $results = $import_export->import_csv($filepath);
        }
        
        if (is_wp_error($results)) {
            WP_CLI::error($results->get_error_message());
        }
        
        WP_CLI::success('Import completed: ' . $results['success'] . ' success, ' . $results['failed'] . ' failed');
        
        if (!empty($results['errors'])) {
            WP_CLI::warning('Errors occurred:');
            foreach (array_slice($results['errors'], 0, 10) as $error) {
                WP_CLI::line('  - ' . $error);
            }
        }
    }
    
    /**
     * View activity logs
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : Number of logs to display
     * ---
     * default: 20
     * ---
     * 
     * [--action=<action>]
     * : Filter by action
     * 
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     * 
     * ## EXAMPLES
     * 
     *     wp digitalogic logs --limit=50
     *     wp digitalogic logs --action=update_product --format=json
     * 
     * @when after_wp_load
     */
    public function logs($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 20;
        $action = isset($assoc_args['action']) ? $assoc_args['action'] : null;
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        
        $logger = Digitalogic_Logger::instance();
        $logs = $logger->get_logs(array(
            'limit' => $limit,
            'action' => $action
        ));
        
        $items = array();
        foreach ($logs as $log) {
            $items[] = array(
                'ID' => $log->id,
                'User' => $log->user_id,
                'Action' => $log->action,
                'Object' => $log->object_type . ' #' . $log->object_id,
                'Date' => $log->created_at
            );
        }
        
        WP_CLI\Utils\format_items($format, $items, array('ID', 'User', 'Action', 'Object', 'Date'));
    }
}

// Register commands
// Note: Don't register 'digitalogic currency' alone as it conflicts with subcommands
WP_CLI::add_command('digitalogic currency get', array('Digitalogic_CLI_Commands', 'currency_get'));
WP_CLI::add_command('digitalogic currency update', array('Digitalogic_CLI_Commands', 'currency_update'));
WP_CLI::add_command('digitalogic products list', array('Digitalogic_CLI_Commands', 'products_list'));
WP_CLI::add_command('digitalogic products get', array('Digitalogic_CLI_Commands', 'products_get'));
WP_CLI::add_command('digitalogic products metadata', array('Digitalogic_CLI_Commands', 'products_metadata'));
WP_CLI::add_command('digitalogic products update', array('Digitalogic_CLI_Commands', 'products_update'));
WP_CLI::add_command('digitalogic export', array('Digitalogic_CLI_Commands', 'export'));
WP_CLI::add_command('digitalogic import', array('Digitalogic_CLI_Commands', 'import'));
WP_CLI::add_command('digitalogic logs', array('Digitalogic_CLI_Commands', 'logs'));
