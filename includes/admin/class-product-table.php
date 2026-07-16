<?php
/**
 * Product Table Handler Class
 * 
 * Additional handler for product table operations (placeholder for future expansion)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Product_Table {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor - can be used for additional table operations
    }
    
    /**
     * Regenerate WooCommerce product lookup tables
     * 
     * @param array $product_ids Product IDs to update
     * @return bool
     */
    public function regenerate_lookup_tables($product_ids = array()) {
        if (empty($product_ids)) {
            // This is an explicit maintenance action. The WooCommerce helper
            // rebuilds the entire catalog and does not accept a product ID.
            wc_update_product_lookup_tables();

            return true;
        }

        $product_ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
        if (empty($product_ids)) {
            return true;
        }

        $data_store = WC_Data_Store::load('product');
        if (is_callable(array($data_store, 'refresh_product_lookup_table'))) {
            foreach ($product_ids as $product_id) {
                $data_store->refresh_product_lookup_table($product_id);
            }

            return true;
        }

        // WooCommerce versions before the per-product refresh API can only
        // schedule a full lookup rebuild. Run it once, never once per ID.
        wc_update_product_lookup_tables();
        
        return true;
    }
}
