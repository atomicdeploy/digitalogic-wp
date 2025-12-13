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
            'status' => 'publish',
            'type' => array('simple', 'variable'),
            'orderby' => 'date',
            'order' => 'DESC',
            'search' => '',
            'sku' => '',
            'category' => array(),
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $query_args = array(
            'limit' => $args['limit'],
            'page' => $args['page'],
            'status' => $args['status'],
            'type' => $args['type'],
            'orderby' => $args['orderby'],
            'order' => $args['order'],
            'return' => 'objects',
        );
        
        if (!empty($args['search'])) {
            $query_args['s'] = $args['search'];
        }
        
        if (!empty($args['sku'])) {
            $query_args['sku'] = $args['sku'];
        }
        
        if (!empty($args['category'])) {
            $query_args['category'] = $args['category'];
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
                    $results[] = $this->format_product_data($product);
                }
            }
            
            return $results;
        } catch (Exception $e) {
            error_log('Digitalogic: Error in get_products - ' . $e->getMessage());
            return array();
        }
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
     * Get single product by SKU
     * 
     * @param string $sku Product SKU
     * @return array|null
     */
    public function get_product_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            return null;
        }
        
        return $this->get_product($product_id);
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
            global $wpdb;
            $product_id = $product->get_id();
            
            // Get data from wp_wc_product_meta_lookup for accurate pricing and stock info
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            $lookup_data = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT min_price, max_price, stock_quantity, stock_status FROM {$lookup_table} WHERE product_id = %d",
                    $product_id
                ),
                ARRAY_A
            );
            
            $data = array(
                'id' => $product_id,
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
            
            // Add min_price and max_price from lookup table if available
            if ($lookup_data) {
                $data['min_price'] = $lookup_data['min_price'];
                $data['max_price'] = $lookup_data['max_price'];
                // Use lookup table stock data as it's the authoritative source
                if (isset($lookup_data['stock_quantity'])) {
                    $data['stock_quantity'] = (int) $lookup_data['stock_quantity'];
                }
                if (isset($lookup_data['stock_status'])) {
                    $data['stock_status'] = $lookup_data['stock_status'];
                }
            } else {
                // Fallback to calculated values
                $data['min_price'] = $product->get_price();
                $data['max_price'] = $product->get_price();
            }
            
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
     * @param int $product_id Product ID
     * @param array $data
     * @param string $sku Product SKU (optional, for lookups by SKU)
     * @return bool|WP_Error
     */
    public function update_product($product_id, $data, $sku = null) {
        // If SKU is provided, get product ID from SKU
        if ($sku !== null) {
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                return new WP_Error('product_not_found', __('Product not found', 'digitalogic'));
            }
        }
        
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
            'status' => 'publish',
            'type' => array('simple', 'variable'),
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
    
    /**
     * Get product metadata from both wp_postmeta and wp_wc_product_meta_lookup
     * 
     * @param int $product_id Product ID
     * @param string $sku Product SKU (optional, for lookups by SKU)
     * @return array|WP_Error
     */
    public function get_product_metadata($product_id, $sku = null) {
        global $wpdb;
        
        // If SKU is provided, get product ID from SKU
        if ($sku !== null) {
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                return new WP_Error('product_not_found', 'Product not found');
            }
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found');
        }
        
        $metadata = array(
            'product_id' => $product_id,
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'type' => $product->get_type(),
        );
        
        // Get data from wp_wc_product_meta_lookup
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        $lookup_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$lookup_table} WHERE product_id = %d",
                $product_id
            ),
            ARRAY_A
        );
        
        $metadata['lookup_table'] = $lookup_data ?: array();
        
        // Get relevant meta from wp_postmeta
        $meta_keys = array(
            '_sku',
            '_regular_price',
            '_sale_price',
            '_price',
            '_stock',
            '_stock_status',
            '_manage_stock',
            '_backorders',
            '_sold_individually',
            'total_sales',
            '_tax_status',
            '_tax_class',
        );
        
        $postmeta = array();
        foreach ($meta_keys as $key) {
            $value = get_post_meta($product_id, $key, true);
            if ($value !== '') {
                $postmeta[$key] = $value;
            }
        }
        
        $metadata['postmeta'] = $postmeta;
        
        // Check for inconsistencies
        $metadata['inconsistencies'] = $this->check_metadata_inconsistencies($product_id, $lookup_data, $postmeta);
        
        return $metadata;
    }
    
    /**
     * Check for inconsistencies between wp_postmeta and wp_wc_product_meta_lookup
     * 
     * @param int $product_id Product ID
     * @param array $lookup_data Data from wp_wc_product_meta_lookup
     * @param array $postmeta Data from wp_postmeta
     * @return array List of inconsistencies
     */
    private function check_metadata_inconsistencies($product_id, $lookup_data, $postmeta) {
        $inconsistencies = array();
        
        if (!$lookup_data) {
            $inconsistencies[] = 'Product not found in wp_wc_product_meta_lookup table';
            return $inconsistencies;
        }
        
        // Check SKU consistency
        if (isset($postmeta['_sku']) && isset($lookup_data['sku'])) {
            if ($postmeta['_sku'] !== $lookup_data['sku']) {
                $inconsistencies[] = sprintf(
                    'SKU mismatch: postmeta="%s", lookup="%s"',
                    $postmeta['_sku'],
                    $lookup_data['sku']
                );
            }
        }
        
        // Check price consistency
        if (isset($postmeta['_price']) && isset($lookup_data['min_price'])) {
            $price_meta = floatval($postmeta['_price']);
            $price_lookup = floatval($lookup_data['min_price']);
            if (abs($price_meta - $price_lookup) > 0.01) {
                $inconsistencies[] = sprintf(
                    'Price mismatch: postmeta="%s", lookup min_price="%s"',
                    $price_meta,
                    $price_lookup
                );
            }
        }
        
        // Check stock quantity consistency
        if (isset($postmeta['_stock']) && isset($lookup_data['stock_quantity'])) {
            if ($postmeta['_stock'] !== $lookup_data['stock_quantity']) {
                $inconsistencies[] = sprintf(
                    'Stock quantity mismatch: postmeta="%s", lookup="%s"',
                    $postmeta['_stock'],
                    $lookup_data['stock_quantity']
                );
            }
        }
        
        // Check stock status consistency
        if (isset($postmeta['_stock_status']) && isset($lookup_data['stock_status'])) {
            if ($postmeta['_stock_status'] !== $lookup_data['stock_status']) {
                $inconsistencies[] = sprintf(
                    'Stock status mismatch: postmeta="%s", lookup="%s"',
                    $postmeta['_stock_status'],
                    $lookup_data['stock_status']
                );
            }
        }
        
        // Check tax status consistency
        if (isset($postmeta['_tax_status']) && isset($lookup_data['tax_status'])) {
            if ($postmeta['_tax_status'] !== $lookup_data['tax_status']) {
                $inconsistencies[] = sprintf(
                    'Tax status mismatch: postmeta="%s", lookup="%s"',
                    $postmeta['_tax_status'],
                    $lookup_data['tax_status']
                );
            }
        }
        
        return $inconsistencies;
    }
}
