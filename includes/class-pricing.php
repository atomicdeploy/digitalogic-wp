<?php
/**
 * Dynamic Pricing Class
 * 
 * Handles dynamic pricing calculations and display
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Pricing {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into WooCommerce pricing
        add_filter('woocommerce_product_get_price', array($this, 'calculate_dynamic_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'calculate_dynamic_price'), 10, 2);
    }
    
    /**
     * Calculate dynamic price based on currency rates
     * 
     * @param float $price
     * @param WC_Product $product
     * @return float
     */
    public function calculate_dynamic_price($price, $product) {
        // Check if dynamic pricing is enabled for this product
        $enable_dynamic = get_post_meta($product->get_id(), '_digitalogic_dynamic_pricing', true);
        
        if ($enable_dynamic !== 'yes') {
            return $price;
        }
        
        // Get currency type
        $currency_type = get_post_meta($product->get_id(), '_digitalogic_currency_type', true);
        
        if (empty($currency_type)) {
            return $price;
        }
        
        // Get base price in foreign currency
        $base_price = get_post_meta($product->get_id(), '_digitalogic_base_price', true);
        
        if (empty($base_price)) {
            return $price;
        }
        
        // Get currency rate
        $options = Digitalogic_Options::instance();
        
        switch ($currency_type) {
            case 'usd':
                $rate = $options->get_dollar_price();
                break;
            case 'cny':
                $rate = $options->get_yuan_price();
                break;
            default:
                return $price;
        }
        
        if ($rate <= 0) {
            return $price;
        }
        
        // Calculate new price
        $calculated_price = $base_price * $rate;
        
        // Apply markup if set
        $markup = get_post_meta($product->get_id(), '_digitalogic_markup', true);
        if (!empty($markup)) {
            $markup_type = get_post_meta($product->get_id(), '_digitalogic_markup_type', true);
            
            if ($markup_type === 'percentage') {
                $calculated_price = $calculated_price * (1 + ($markup / 100));
            } else {
                $calculated_price = $calculated_price + $markup;
            }
        }
        
        return round($calculated_price, 2);
    }
    
    /**
     * Update product price from foreign currency
     * 
     * @param int $product_id
     * @param string $currency_type 'usd' or 'cny'
     * @param float $base_price
     * @param float $markup
     * @param string $markup_type 'percentage' or 'fixed'
     * @return bool
     */
    public function set_dynamic_pricing($product_id, $currency_type, $base_price, $markup = 0, $markup_type = 'percentage') {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return false;
        }
        
        // Save dynamic pricing meta
        update_post_meta($product_id, '_digitalogic_dynamic_pricing', 'yes');
        update_post_meta($product_id, '_digitalogic_currency_type', $currency_type);
        update_post_meta($product_id, '_digitalogic_base_price', $base_price);
        update_post_meta($product_id, '_digitalogic_markup', $markup);
        update_post_meta($product_id, '_digitalogic_markup_type', $markup_type);
        
        // Calculate and update the actual price
        $options = Digitalogic_Options::instance();
        
        switch ($currency_type) {
            case 'usd':
                $rate = $options->get_dollar_price();
                break;
            case 'cny':
                $rate = $options->get_yuan_price();
                break;
            default:
                return false;
        }
        
        if ($rate <= 0) {
            return false;
        }
        
        $calculated_price = $base_price * $rate;
        
        if ($markup_type === 'percentage') {
            $calculated_price = $calculated_price * (1 + ($markup / 100));
        } else {
            $calculated_price = $calculated_price + $markup;
        }
        
        $product->set_regular_price($calculated_price);
        $product->save();
        
        // Update product lookup tables
        wc_update_product_lookup_tables($product_id);
        
        return true;
    }
    
    /**
     * Bulk update prices based on new currency rates
     * 
     * @return array Results
     */
    public function bulk_recalculate_prices() {
        global $wpdb;
        
        // Get all products with dynamic pricing enabled
        $product_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_digitalogic_dynamic_pricing' 
            AND meta_value = 'yes'"
        );
        
        $results = array(
            'success' => 0,
            'failed' => 0,
            'total' => count($product_ids)
        );
        
        foreach ($product_ids as $product_id) {
            $currency_type = get_post_meta($product_id, '_digitalogic_currency_type', true);
            $base_price = get_post_meta($product_id, '_digitalogic_base_price', true);
            $markup = get_post_meta($product_id, '_digitalogic_markup', true);
            $markup_type = get_post_meta($product_id, '_digitalogic_markup_type', true);
            
            if ($this->set_dynamic_pricing($product_id, $currency_type, $base_price, $markup, $markup_type)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }
        
        // Log the bulk update
        Digitalogic_Logger::instance()->log(
            'bulk_recalculate_prices',
            'product',
            null,
            null,
            json_encode($results),
            'Bulk recalculated prices for ' . $results['success'] . ' products'
        );
        
        return $results;
    }
}
