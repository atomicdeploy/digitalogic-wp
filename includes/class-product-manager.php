<?php
/**
 * Product Manager Class
 *
 * Handles bulk product operations and management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digitalogic_Product_Manager {
    
    private static $instance = null;

    private $unfiltered_count = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('posts_clauses', array($this, 'apply_product_query_clauses'), 20, 2);
    }

    /**
	 * Apply scoped image and meta-sort clauses to canonical list queries.
	 *
	 * @param array    $clauses WP_Query SQL clauses.
	 * @param WP_Query $query Current query.
	 * @return array
	 */
	public function apply_product_query_clauses( $clauses, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $clauses;
		}

		global $wpdb;
		$part_number = (string) $query->get( 'digitalogic_product_part_number_filter' );
		if ( $part_number !== '' ) {
			$like              = '%' . $wpdb->esc_like( $part_number ) . '%';
			$clauses['where'] .= $wpdb->prepare(
				" AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} digitalogic_part_number_meta
                        WHERE digitalogic_part_number_meta.post_id = {$wpdb->posts}.ID
                        AND digitalogic_part_number_meta.meta_key = 'attribute_pa_model'
                        AND digitalogic_part_number_meta.meta_value LIKE %s
                    )
                    OR EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} digitalogic_attribute_meta
                        WHERE digitalogic_attribute_meta.post_id = {$wpdb->posts}.ID
                        AND digitalogic_attribute_meta.meta_key = '_product_attributes'
                        AND digitalogic_attribute_meta.meta_value LIKE %s
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM {$wpdb->term_relationships} digitalogic_part_number_relationship
                        INNER JOIN {$wpdb->term_taxonomy} digitalogic_part_number_taxonomy
                            ON digitalogic_part_number_taxonomy.term_taxonomy_id = digitalogic_part_number_relationship.term_taxonomy_id
                        INNER JOIN {$wpdb->terms} digitalogic_part_number_term
                            ON digitalogic_part_number_term.term_id = digitalogic_part_number_taxonomy.term_id
                        WHERE digitalogic_part_number_relationship.object_id = {$wpdb->posts}.ID
                        AND digitalogic_part_number_taxonomy.taxonomy = 'pa_model'
                        AND (
                            digitalogic_part_number_term.name LIKE %s
                            OR digitalogic_part_number_term.slug LIKE %s
                        )
                    )
                )",
				$like,
				$like,
				$like,
				$like
			);
		}

		$image_filter = (string) $query->get( 'digitalogic_product_image_filter' );
		if ( $image_filter === 'with' || $image_filter === 'without' ) {
			$thumbnail_exists  = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} digitalogic_image_meta
                WHERE digitalogic_image_meta.post_id = {$wpdb->posts}.ID
                AND digitalogic_image_meta.meta_key = '_thumbnail_id'
                AND digitalogic_image_meta.meta_value NOT IN ('', '0')
            ) OR (
                {$wpdb->posts}.post_type = 'product_variation'
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} digitalogic_parent_image_meta
                    WHERE digitalogic_parent_image_meta.post_id = {$wpdb->posts}.post_parent
                    AND digitalogic_parent_image_meta.meta_key = '_thumbnail_id'
                    AND digitalogic_parent_image_meta.meta_value NOT IN ('', '0')
                )
            )";
			$clauses['where'] .= $image_filter === 'with'
				? " AND ({$thumbnail_exists})"
				: " AND NOT ({$thumbnail_exists})";
		}

		$meta_key = (string) $query->get( 'digitalogic_product_sort_meta' );
		$allowed  = array(
			'_sku',
			'_regular_price',
			'_sale_price',
			'_stock',
			'_weight',
			'_digitalogic_patris_foreign_price',
			'_digitalogic_patris_weight_grams',
			'_digitalogic_patris_final_price',
			'_digitalogic_patris_updated_at',
		);
		if ( ! in_array( $meta_key, $allowed, true ) ) {
			return $clauses;
		}

		$alias            = 'digitalogic_product_sort_meta';
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} {$alias} ON ({$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s)",
			$meta_key
		);

		$post_group = "{$wpdb->posts}.ID";
		if ( trim( (string) $clauses['groupby'] ) === '' ) {
			$clauses['groupby'] = $post_group;
		} elseif ( strpos( $clauses['groupby'], $post_group ) === false ) {
			$clauses['groupby'] .= ', ' . $post_group;
		}

		$direction          = strtoupper( (string) $query->get( 'digitalogic_product_sort_direction' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$value              = $query->get( 'digitalogic_product_sort_numeric' )
			? "CAST({$alias}.meta_value AS DECIMAL(24,8))"
			: "{$alias}.meta_value";
		$clauses['orderby'] = "CASE WHEN MAX({$alias}.meta_id) IS NULL THEN 1 ELSE 0 END ASC, MAX({$value}) {$direction}, {$post_group} {$direction}";

		return $clauses;
	}

	/**
	 * Get products with filters
	 *
	 * @param array $args Query arguments
	 * @return array
	 */
	public function get_products( $args = array() ) {
		if ( empty( $args['sorts'] ) && isset( $args['orderby'] ) ) {
			$legacy_orderby = strtolower( (string) $args['orderby'] );
			$legacy_field   = in_array( $legacy_orderby, array( 'id', 'name', 'title' ), true )
				? ( $legacy_orderby === 'title' ? 'name' : $legacy_orderby )
				: '';
			if ( $legacy_field !== '' ) {
				$args['sorts'] = array(
					array(
						'field'     => $legacy_field,
						'direction' => isset( $args['order'] ) && strtoupper( (string) $args['order'] ) === 'ASC' ? 'asc' : 'desc',
					),
				);
			}
		}

		if ( isset( $args['limit'] ) && -1 === intval( $args['limit'] ) ) {
			$products            = array();
			$batch_args          = $args;
			$batch_args['limit'] = 100;
			$batch_args['page']  = 1;

			do {
				$result   = $this->query_products( $batch_args );
				$products = array_merge( $products, $result['products'] );
				++$batch_args['page'];
			} while (
				! empty( $result['products'] )
				&& $batch_args['page'] <= $result['pages']
			);

			return $products;
		}

		$result = $this->query_products( $args );

		return $result['products'];
	}

	/**
	 * Execute the canonical, pre-pagination product-list query.
	 *
	 * The ID query and the optional unfiltered count are constant in number.
	 * Product, meta, taxonomy, parent, and image caches are primed in bulk before
	 * rows are formatted, so the result does not issue a lookup per table row.
	 *
	 * @param array $args Query arguments from any supported transport.
	 * @return array
	 */
	public function query_products( $args = array() ) {
		$normalized = Digitalogic_Product_Query::normalize_args( $args );

		try {
			$query       = new WP_Query( Digitalogic_Product_Query::build_wp_query_args( $normalized ) );
			$product_ids = array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
			$filtered    = max( 0, (int) $query->found_posts );
			$products    = $this->format_product_list( $product_ids );
			$total       = Digitalogic_Product_Query::has_active_filters( $normalized )
				? $this->get_unfiltered_product_count()
				: $filtered;

			return array(
				'products'        => $products,
				'total'           => $total,
				'recordsTotal'    => $total,
				'recordsFiltered' => $filtered,
				'page'            => $normalized['page'],
				'limit'           => $normalized['limit'],
				'pages'           => $normalized['limit'] > 0 ? (int) ceil( $filtered / $normalized['limit'] ) : 0,
			);
		} catch ( Throwable $e ) {
			error_log( 'Digitalogic: Error in query_products - ' . $e->getMessage() );

			return array(
				'products'        => array(),
				'total'           => 0,
				'recordsTotal'    => 0,
				'recordsFiltered' => 0,
				'page'            => $normalized['page'],
				'limit'           => $normalized['limit'],
				'pages'           => 0,
			);
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
     * Resolve and return one product through the shared exact identifier policy.
     *
     * @param array $identifiers WooCommerce ID, exact SKU, Patris Code, or code.
     * @return array|WP_Error
     */
    public function get_product_by_identifiers($identifiers) {
        $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve($identifiers);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $product = $this->get_product((int) $resolved['woocommerce_id']);
        if (!$product) {
            return new WP_Error(
                'digitalogic_product_unavailable',
                __('The resolved product is no longer available.', 'digitalogic'),
                array('status' => 404)
            );
        }

        $product['resolved_by'] = (string) $resolved['resolved_by'];
        $product['resolved_identifier'] = (string) $resolved['identifier'];

        return $product;
    }

    /**
     * Backward-compatible exact SKU lookup.
     *
     * @param string $sku Exact SKU, preserved as a string.
     * @return array|WP_Error
     */
    public function get_product_by_sku($sku) {
        return $this->get_product_by_identifiers(array('sku' => (string) $sku));
    }

    /**
     * Inspect current post meta against the derived WooCommerce lookup row.
     *
     * @param array $identifiers Exact identifier object.
     * @return array|WP_Error
     */
    public function get_product_metadata($identifiers) {
        return Digitalogic_Product_Metadata_Inspector::instance()->inspect($identifiers);
    }

    /**
     * Resolve one exact identifier and update through the normal CRUD path.
     *
     * @param array $identifiers Exact identifier object.
     * @param array $data Product fields accepted by update_product().
     * @return bool|WP_Error
     */
    public function update_product_by_identifiers($identifiers, $data) {
        $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve($identifiers);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        return $this->update_product((int) $resolved['woocommerce_id'], $data);
    }
    
    /**
     * Format product data for output
     * 
     * @param WC_Product $product
     * @param int  $depth Current recursion depth.
     * @param bool $list_context Whether this row is for the paginated list.
     * @return array
     */
    private function format_product_data($product, $depth = 0, $list_context = false) {
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
                'gallery_images' => $list_context ? array() : $this->get_gallery_images($public_product),
                'category_ids' => $public_product->get_category_ids(),
                'categories' => $this->get_categories($public_product),
                'total_sales' => $public_product->get_total_sales(),
                'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->date_i18n('Y-m-d H:i') : '',
                'revision_count' => $list_context ? null : count(wp_get_post_revisions($public_product->get_id())),
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
            if (!$list_context && $depth === 0 && $product->is_type('variable')) {
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

    private function format_product_list($product_ids) {
        if (!$product_ids) {
            return array();
        }

        $this->prime_post_caches($product_ids, true);
        $products = array();
        $related_ids = array();

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !is_a($product, 'WC_Product')) {
                continue;
            }

            $products[] = $product;
            $parent_id = absint($product->get_parent_id());
            $image_id = absint($product->get_image_id());
            if ($parent_id) {
                $related_ids[] = $parent_id;
            }
            if ($image_id) {
                $related_ids[] = $image_id;
            }
        }

        if ($related_ids) {
            $this->prime_post_caches(array_values(array_unique($related_ids)), true);
        }

        return array_values(array_map(function($product) {
            return $this->format_product_data($product, 0, true);
        }, $products));
    }

    private function prime_post_caches($post_ids, $terms) {
        $post_ids = array_values(array_filter(array_map('absint', (array) $post_ids)));
        if (!$post_ids) {
            return;
        }

        if (function_exists('_prime_post_caches')) {
            _prime_post_caches($post_ids, (bool) $terms, true);
            return;
        }

        update_meta_cache('post', $post_ids);
        if ($terms) {
            update_object_term_cache($post_ids, array('product', 'product_variation'));
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
        try {
            $query = new WP_Query(Digitalogic_Product_Query::build_wp_query_args($args, true));
            return max(0, (int) $query->found_posts);
        } catch (Throwable $e) {
            error_log('Digitalogic: Error in get_product_count - ' . $e->getMessage());
            return 0;
        }
    }

    private function get_unfiltered_product_count() {
        if ($this->unfiltered_count === null) {
            $this->unfiltered_count = $this->get_product_count();
        }

        return $this->unfiltered_count;
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
