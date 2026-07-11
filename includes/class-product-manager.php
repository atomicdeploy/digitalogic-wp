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
            $public_product = $this->get_public_product($product);
            $public_product = $public_product ?: $product;
            $image_url = wp_get_attachment_url($product->get_image_id());
            if (!$image_url && $public_product->get_id() !== $product->get_id()) {
                $image_url = wp_get_attachment_url($public_product->get_image_id());
            }

            $data = array(
                'id' => $product->get_id(),
                'parent_id' => $product->get_parent_id(),
                'edit_product_id' => $this->get_edit_product_id($product),
                'name' => $product->get_name(),
                'part_number' => $this->get_part_number($product),
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
                'permalink' => $public_product->get_permalink(),
                'canonical_url' => $public_product->get_permalink(),
                'edit_url' => $this->get_edit_url($product),
                'image' => $image_url,
                'gallery_images' => $this->get_gallery_images($public_product),
                'category_ids' => $public_product->get_category_ids(),
                'categories' => $this->get_categories($public_product),
                'total_sales' => $public_product->get_total_sales(),
                'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->date_i18n('Y-m-d H:i') : '',
                'revision_count' => count(wp_get_post_revisions($public_product->get_id())),
                'patris_product_code' => $product->get_meta('_digitalogic_patris_product_code', true),
                'patris_name' => $product->get_meta('_digitalogic_patris_name', true),
                'patris_serial' => $product->get_meta('_digitalogic_patris_serial', true),
                'patris_unit' => $product->get_meta('_digitalogic_patris_unit', true),
                'patris_foreign_currency' => $product->get_meta('_digitalogic_patris_foreign_currency', true),
                'patris_foreign_price' => $product->get_meta('_digitalogic_patris_foreign_price', true),
                'patris_weight_grams' => $product->get_meta('_digitalogic_patris_weight_grams', true),
                'patris_total_stock' => $product->get_meta('_digitalogic_patris_total_stock', true),
                'patris_minimum_stock' => $product->get_meta('_digitalogic_patris_minimum_stock', true),
                'patris_warehouse_stock' => $this->decode_json_meta($product->get_meta('_digitalogic_patris_warehouse_stock', true)),
                'patris_location' => $product->get_meta('_digitalogic_patris_location', true),
                'patris_final_price' => $product->get_meta('_digitalogic_patris_final_price', true),
                'patris_price_status' => $product->get_meta('_digitalogic_patris_price_status', true),
                'patris_updated_at' => $product->get_meta('_digitalogic_patris_updated_at', true),
                'patris_flags' => $this->decode_json_meta($product->get_meta('_digitalogic_patris_flags', true)),
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

    private function get_edit_url($product) {
        return admin_url('post.php?post=' . $this->get_edit_product_id($product) . '&action=edit');
    }

    private function get_edit_product_id($product) {
        if ($this->is_variation_product($product) && $product->get_parent_id()) {
            return $product->get_parent_id();
        }

        return $product->get_id();
    }

    private function get_public_product($product) {
        if ($this->is_variation_product($product) && $product->get_parent_id()) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                return $parent;
            }
        }

        return $product;
    }

    private function is_variation_product($product) {
        return $product && ($product->is_type('variation') || get_post_type($product->get_id()) === 'product_variation');
    }

    private function get_part_number($product) {
        $part_number = '';

        if ($product->is_type('variation')) {
            $part_number = $product->get_attribute('pa_model');
            if (!$part_number) {
                $part_number = $product->get_meta('attribute_pa_model', true);
            }
        } else {
            $part_number = $product->get_attribute('pa_model');
        }

        if (is_string($part_number) && $part_number !== '') {
            return wc_clean(wp_strip_all_tags($part_number));
        }

        return '';
    }

    private function get_categories($product) {
        $categories = array();

        foreach ($product->get_category_ids() as $category_id) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = array(
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }

        return $categories;
    }

    private function get_gallery_images($product) {
        $images = array();

        foreach ($product->get_gallery_image_ids() as $image_id) {
            $url = wp_get_attachment_image_url($image_id, 'thumbnail');
            if ($url) {
                $images[] = array(
                    'id' => (int) $image_id,
                    'url' => $url,
                );
            }
        }

        return $images;
    }

    private function decode_json_meta($value) {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return array();
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public function get_product_categories() {
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 300,
        ));

        if (is_wp_error($terms) || !is_array($terms)) {
            return array();
        }

        return array_map(function($term) {
            return array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => (int) $term->parent,
            );
        }, $terms);
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

            if (isset($data['status'])) {
                $allowed_statuses = array('publish', 'draft', 'pending', 'private');
                if (in_array($data['status'], $allowed_statuses, true)) {
                    $product->set_status($data['status']);
                }
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

            if (isset($data['category_ids']) && is_array($data['category_ids'])) {
                $product->set_category_ids(array_values(array_filter(array_map('absint', $data['category_ids']))));
            }

            $this->update_patris_meta($product, $data);
            
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

            do_action('digitalogic_product_updated', $product_id, $data);
            
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
        
        // Add search filter if provided
        if (!empty($args['search'])) {
            $query_args['s'] = $args['search'];
        }

        if (!empty($args['sku'])) {
            $query_args['sku'] = $args['sku'];
        }
        
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

    private function update_patris_meta($product, $data) {
        $meta_map = array(
            'patris_foreign_currency' => '_digitalogic_patris_foreign_currency',
            'patris_foreign_price' => '_digitalogic_patris_foreign_price',
            'patris_weight_grams' => '_digitalogic_patris_weight_grams',
            'patris_total_stock' => '_digitalogic_patris_total_stock',
            'patris_minimum_stock' => '_digitalogic_patris_minimum_stock',
            'patris_location' => '_digitalogic_patris_location',
            'patris_final_price' => '_digitalogic_patris_final_price',
        );

        foreach ($meta_map as $field => $meta_key) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = is_string($data[$field]) ? sanitize_text_field(wp_unslash($data[$field])) : $data[$field];
            $product->update_meta_data($meta_key, $value);
        }
    }
}
