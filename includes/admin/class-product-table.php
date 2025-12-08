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
            // Update all products
            wc_update_product_lookup_tables();
        } else {
            // Update specific products
            foreach ($product_ids as $product_id) {
                wc_update_product_lookup_tables($product_id);
            }
        }
        
        return true;
    }
}
