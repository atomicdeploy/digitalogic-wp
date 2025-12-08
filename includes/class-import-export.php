<?php
/**
 * Import/Export Class
 * 
 * Handles CSV, JSON, and Excel import/export
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Import_Export {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor
    }
    
    /**
     * Export products to CSV
     * 
     * @param array $product_ids
     * @return string File path
     */
    public function export_csv($product_ids = array()) {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/digitalogic-exports';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename = 'products-export-' . date('Y-m-d-His') . '.csv';
        $filepath = $export_dir . '/' . $filename;
        
        $file = fopen($filepath, 'w');
        
        // Headers
        $headers = array(
            'ID',
            'Name',
            'SKU',
            'Type',
            'Regular Price',
            'Sale Price',
            'Stock Quantity',
            'Stock Status',
            'Weight',
            'Length',
            'Width',
            'Height',
            'Dynamic Pricing',
            'Currency Type',
            'Base Price',
            'Markup',
            'Markup Type'
        );
        
        fputcsv($file, $headers);
        
        // Get products
        $manager = Digitalogic_Product_Manager::instance();
        
        if (empty($product_ids)) {
            $products = $manager->get_products(array('limit' => -1));
        } else {
            $products = array();
            foreach ($product_ids as $id) {
                $product = $manager->get_product($id);
                if ($product) {
                    $products[] = $product;
                }
            }
        }
        
        // Write data
        foreach ($products as $product_data) {
            $product = wc_get_product($product_data['id']);
            if (!$product) {
                continue;
            }
            
            $row = array(
                $product_data['id'],
                $product_data['name'],
                $product_data['sku'],
                $product_data['type'],
                $product_data['regular_price'],
                $product_data['sale_price'],
                $product_data['stock_quantity'],
                $product_data['stock_status'],
                $product_data['weight'],
                $product_data['length'],
                $product_data['width'],
                $product_data['height'],
                $product->get_meta('_digitalogic_dynamic_pricing', true),
                $product->get_meta('_digitalogic_currency_type', true),
                $product->get_meta('_digitalogic_base_price', true),
                $product->get_meta('_digitalogic_markup', true),
                $product->get_meta('_digitalogic_markup_type', true),
            );
            
            fputcsv($file, $row);
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Export products to JSON
     * 
     * @param array $product_ids
     * @return string File path
     */
    public function export_json($product_ids = array()) {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/digitalogic-exports';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename = 'products-export-' . date('Y-m-d-His') . '.json';
        $filepath = $export_dir . '/' . $filename;
        
        // Get products
        $manager = Digitalogic_Product_Manager::instance();
        
        if (empty($product_ids)) {
            $products = $manager->get_products(array('limit' => -1));
        } else {
            $products = array();
            foreach ($product_ids as $id) {
                $product = $manager->get_product($id);
                if ($product) {
                    $products[] = $product;
                }
            }
        }
        
        // Add dynamic pricing metadata
        foreach ($products as &$product_data) {
            $product = wc_get_product($product_data['id']);
            if ($product) {
                $product_data['dynamic_pricing'] = array(
                    'enabled' => $product->get_meta('_digitalogic_dynamic_pricing', true),
                    'currency_type' => $product->get_meta('_digitalogic_currency_type', true),
                    'base_price' => $product->get_meta('_digitalogic_base_price', true),
                    'markup' => $product->get_meta('_digitalogic_markup', true),
                    'markup_type' => $product->get_meta('_digitalogic_markup_type', true),
                );
            }
        }
        
        file_put_contents($filepath, json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $filepath;
    }
    
    /**
     * Import products from CSV
     * 
     * @param string $filepath
     * @return array Results
     */
    public function import_csv($filepath) {
        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }
        
        $file = fopen($filepath, 'r');
        $headers = fgetcsv($file);
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        $manager = Digitalogic_Product_Manager::instance();
        
        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($headers, $row);
            
            if (empty($data['ID'])) {
                $results['failed']++;
                $results['errors'][] = 'Missing product ID';
                continue;
            }
            
            $product_id = intval($data['ID']);
            
            $update_data = array(
                'name' => $data['Name'],
                'sku' => $data['SKU'],
                'regular_price' => $data['Regular Price'],
                'sale_price' => $data['Sale Price'],
                'stock_quantity' => $data['Stock Quantity'],
                'stock_status' => $data['Stock Status'],
                'weight' => $data['Weight'],
                'length' => $data['Length'],
                'width' => $data['Width'],
                'height' => $data['Height'],
            );
            
            // Remove empty values
            $update_data = array_filter($update_data, function($value) {
                return $value !== '';
            });
            
            $result = $manager->update_product($product_id, $update_data);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = $result->get_error_message();
            } else {
                $results['success']++;
                
                // Update dynamic pricing if set using WooCommerce methods (HPOS compatible)
                if (!empty($data['Dynamic Pricing']) && $data['Dynamic Pricing'] === 'yes') {
                    $product = wc_get_product($product_id);
                    if ($product) {
                        $product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
                        $product->update_meta_data('_digitalogic_currency_type', $data['Currency Type']);
                        $product->update_meta_data('_digitalogic_base_price', $data['Base Price']);
                        $product->update_meta_data('_digitalogic_markup', $data['Markup']);
                        $product->update_meta_data('_digitalogic_markup_type', $data['Markup Type']);
                        $product->save();
                    }
                }
            }
        }
        
        fclose($file);
        
        return $results;
    }
    
    /**
     * Import products from JSON
     * 
     * @param string $filepath
     * @return array Results
     */
    public function import_json($filepath) {
        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', __('File not found', 'digitalogic'));
        }
        
        $json = file_get_contents($filepath);
        $products = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', __('Invalid JSON file', 'digitalogic'));
        }
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        $manager = Digitalogic_Product_Manager::instance();
        
        foreach ($products as $product_data) {
            if (empty($product_data['id'])) {
                $results['failed']++;
                $results['errors'][] = 'Missing product ID';
                continue;
            }
            
            $product_id = intval($product_data['id']);
            
            $result = $manager->update_product($product_id, $product_data);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = $result->get_error_message();
            } else {
                $results['success']++;
                
                // Update dynamic pricing if present using WooCommerce methods (HPOS compatible)
                if (isset($product_data['dynamic_pricing'])) {
                    $dp = $product_data['dynamic_pricing'];
                    if (!empty($dp['enabled']) && $dp['enabled'] === 'yes') {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $product->update_meta_data('_digitalogic_dynamic_pricing', 'yes');
                            $product->update_meta_data('_digitalogic_currency_type', $dp['currency_type']);
                            $product->update_meta_data('_digitalogic_base_price', $dp['base_price']);
                            $product->update_meta_data('_digitalogic_markup', $dp['markup']);
                            $product->update_meta_data('_digitalogic_markup_type', $dp['markup_type']);
                            $product->save();
                        }
                    }
                }
            }
        }
        
        return $results;
    }
}
