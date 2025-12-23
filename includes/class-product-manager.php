<?php
/**
 * Product Manager Class
 * 
 * Handles bulk product operations and management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Product_Manager {
    
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
     * Get products with filters
     * 
     * @param array $args Query arguments
     * @return array
     */
    public function get_products($args = array()) {
        $defaults = array(
            'limit' => 50,
            'page' => 1,
            'status' => 'any',
            'type' => array('simple', 'variable', 'variation'),
            'orderby' => 'date',
            'order' => 'DESC',
            'search' => '',
            'sku' => '',
            'category' => array(),
            'stock_status' => '',
            'price_min' => null,
            'price_max' => null,
            'stock_min' => null,
            'stock_max' => null,
            'weight_min' => null,
            'weight_max' => null,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $query_args = array(
            'limit' => $args['limit'],
            'page' => $args['page'],
            'orderby' => $args['orderby'],
            'order' => $args['order'],
            'return' => 'objects',
        );
        
        // Status filter
        if (!empty($args['status'])) {
            $query_args['status'] = $args['status'];
        }
        
        // Type filter
        if (!empty($args['type'])) {
            $query_args['type'] = $args['type'];
        } else {
            $query_args['type'] = array('simple', 'variable');
        }
        
        // Search
        if (!empty($args['search'])) {
            $query_args['s'] = $args['search'];
        }
        
        // SKU filter
        if (!empty($args['sku'])) {
            $query_args['sku'] = $args['sku'];
        }
        
        // Category filter
        if (!empty($args['category'])) {
            $query_args['category'] = $args['category'];
        }
        
        // Stock status filter
        if (!empty($args['stock_status'])) {
            $query_args['stock_status'] = $args['stock_status'];
        }
        
        try {
            $products = wc_get_products($query_args);
            
            if (!is_array($products)) {
                error_log('Digitalogic: wc_get_products returned non-array value');
                return array();
            }
            
            $results = array();
            
            foreach ($products as $product) {
                if ($product && is_a($product, 'WC_Product')) {
                    $product_data = $this->format_product_data($product);
                    
                    // Apply additional filters that WooCommerce query doesn't support natively
                    if ($this->matches_filters($product_data, $args)) {
                        $results[] = $product_data;
                    }
                }
            }
            
            return $results;
        } catch (Exception $e) {
            error_log('Digitalogic: Error in get_products - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Check if product matches additional filters
     * 
     * @param array $product_data Product data array
     * @param array $filters Filter arguments
     * @return bool
     */
    private function matches_filters($product_data, $filters) {
        // Price filters
        if ($filters['price_min'] !== null && $filters['price_min'] !== '') {
            $price = floatval($product_data['regular_price']);
            if ($price < floatval($filters['price_min'])) {
                return false;
            }
        }
        
        if ($filters['price_max'] !== null && $filters['price_max'] !== '') {
            $price = floatval($product_data['regular_price']);
            if ($price > floatval($filters['price_max'])) {
                return false;
            }
        }
        
        // Stock quantity filters
        if ($filters['stock_min'] !== null && $filters['stock_min'] !== '') {
            $stock = intval($product_data['stock_quantity']);
            if ($stock < intval($filters['stock_min'])) {
                return false;
            }
        }
        
        if ($filters['stock_max'] !== null && $filters['stock_max'] !== '') {
            $stock = intval($product_data['stock_quantity']);
            if ($stock > intval($filters['stock_max'])) {
                return false;
            }
        }
        
        // Weight filters
        if ($filters['weight_min'] !== null && $filters['weight_min'] !== '') {
            $weight = floatval($product_data['weight']);
            if ($weight < floatval($filters['weight_min'])) {
                return false;
            }
        }
        
        if ($filters['weight_max'] !== null && $filters['weight_max'] !== '') {
            $weight = floatval($product_data['weight']);
            if ($weight > floatval($filters['weight_max'])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get single product by ID
     * 
     * @param int $product_id
     * @return array|null
     */
    public function get_product($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return null;
        }
        
        return $this->format_product_data($product);
    }
    
    /**
     * Format product data for output
     * 
     * @param WC_Product $product
     * @param int $depth Current recursion depth
     * @return array
     */
    private function format_product_data($product, $depth = 0) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return array();
        }
        
        // Prevent deep recursion (max 2 levels)
        if ($depth > 2) {
            error_log('Digitalogic: Maximum recursion depth reached for product #' . $product->get_id());
            return array();
        }
        
        try {
            $data = array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'type' => $product->get_type(),
                'status' => $product->get_status(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'price' => $product->get_price(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'manage_stock' => $product->get_manage_stock(),
                'weight' => $product->get_weight(),
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
                'permalink' => $product->get_permalink(),
                'image' => wp_get_attachment_url($product->get_image_id()),
            );
            
            // Add variation data if variable product (only at first level)
            if ($depth === 0 && $product->is_type('variable')) {
                $data['variations'] = array();
                $children = $product->get_children();
                
                // Limit to 100 variations to prevent performance issues
                if (count($children) > 100) {
                    error_log('Digitalogic: Product #' . $product->get_id() . ' has more than 100 variations, limiting output');
                    $children = array_slice($children, 0, 100);
                }
                
                foreach ($children as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $data['variations'][] = $this->format_product_data($variation, $depth + 1);
                    }
                }
            }
            
            return $data;
        } catch (Exception $e) {
            error_log('Digitalogic: Error formatting product data for product #' . $product->get_id() . ' - ' . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Update single product
     * 
     * @param int $product_id
     * @param array $data
     * @return bool|WP_Error
     */
    public function update_product($product_id, $data) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return new WP_Error('invalid_product', __('Product not found', 'digitalogic'));
        }
        
        $old_data = $this->format_product_data($product);
        
        try {
            // Update basic fields
            if (isset($data['name'])) {
                $product->set_name($data['name']);
            }
            
            if (isset($data['sku'])) {
                $product->set_sku($data['sku']);
            }
            
            // Update pricing
            if (isset($data['regular_price'])) {
                $product->set_regular_price($data['regular_price']);
            }
            
            if (isset($data['sale_price'])) {
                $product->set_sale_price($data['sale_price']);
            }
            
            // Update stock
            if (isset($data['stock_quantity'])) {
                $product->set_stock_quantity($data['stock_quantity']);
            }
            
            if (isset($data['stock_status'])) {
                $product->set_stock_status($data['stock_status']);
            }
            
            if (isset($data['manage_stock'])) {
                $product->set_manage_stock($data['manage_stock']);
            }
            
            // Update dimensions
            if (isset($data['weight'])) {
                $product->set_weight($data['weight']);
            }
            
            if (isset($data['length'])) {
                $product->set_length($data['length']);
            }
            
            if (isset($data['width'])) {
                $product->set_width($data['width']);
            }
            
            if (isset($data['height'])) {
                $product->set_height($data['height']);
            }
            
            // Save product
            $product->save();
            
            // Update product lookup tables
            wc_update_product_lookup_tables($product_id);
            
            // Log the change
            Digitalogic_Logger::instance()->log(
                'update_product',
                'product',
                $product_id,
                json_encode($old_data),
                json_encode($data),
                'Updated product: ' . $product->get_name()
            );
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error('update_failed', $e->getMessage());
        }
    }
    
    /**
     * Bulk update products
     * 
     * @param array $updates Array of product updates [product_id => data]
     * @return array Results
     */
    public function bulk_update($updates) {
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($updates as $product_id => $data) {
            $result = $this->update_product($product_id, $data);
            
            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][$product_id] = $result->get_error_message();
            } else {
                $results['success']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Get product count
     * 
     * @param array $args Query arguments
     * @return int
     */
    public function get_product_count($args = array()) {
        $defaults = array(
            'status' => 'any',
            'type' => array('simple', 'variable', 'variation'),
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $query_args = array(
            'status' => $args['status'],
            'type' => $args['type'],
            'return' => 'ids',
            'limit' => -1,
        );
        
        try {
            $products = wc_get_products($query_args);
            
            if (!is_array($products)) {
                error_log('Digitalogic: wc_get_products returned non-array value in get_product_count');
                return 0;
            }
            
            return count($products);
        } catch (Exception $e) {
            error_log('Digitalogic: Error in get_product_count - ' . $e->getMessage());
            return 0;
        }
    }
}
