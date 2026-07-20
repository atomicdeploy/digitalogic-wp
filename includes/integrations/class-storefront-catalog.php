<?php
/**
 * Keep empty or non-catalog product categories off the public storefront.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Storefront_Catalog {

	private static $instance = null;

	/**
	 * Visible category IDs for the current request.
	 *
	 * @var array<int, true>|null
	 */
	private $visible_category_ids = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'get_terms_args', array( $this, 'force_hide_empty_product_categories' ), 20, 2 );
		add_filter( 'get_terms', array( $this, 'filter_invisible_product_categories' ), 20, 4 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_invisible_category_menu_items' ), 20, 2 );
		add_filter( 'woocommerce_product_subcategories_args', array( $this, 'force_hide_empty_product_categories' ), 20, 1 );
		add_filter( 'woocommerce_product_categories_widget_args', array( $this, 'force_hide_empty_product_categories' ), 20, 1 );
	}

	/**
	 * Make WooCommerce and page-builder category queries omit empty terms.
	 *
	 * @param array $args Query arguments.
	 * @param array $taxonomies Requested taxonomies.
	 * @return array
	 */
	public function force_hide_empty_product_categories( $args, $taxonomies = array( 'product_cat' ) ) {
		if ( ! is_array( $args ) || ! $this->is_public_storefront_request() ) {
			return $args;
		}

		$taxonomies = array_filter( (array) $taxonomies, 'is_string' );
		if ( in_array( 'product_cat', $taxonomies, true ) || 'product_cat' === ( $args['taxonomy'] ?? '' ) ) {
			$args['hide_empty'] = true;
		}

		return $args;
	}

	/**
	 * Remove terms whose products are not visible in the shop catalog.
	 *
	 * WordPress term counts include products hidden from the catalog. A single
	 * SQL projection is used here so an Elementor category carousel does not
	 * create an N+1 product query for every term.
	 *
	 * @param mixed $terms Term-query result.
	 * @param array $taxonomies Requested taxonomies.
	 * @param array $args Query arguments.
	 * @param mixed $term_query Term query object.
	 * @return mixed
	 */
	public function filter_invisible_product_categories( $terms, $taxonomies, $args, $term_query = null ) {
		unset( $term_query );

		if ( ! is_array( $terms ) || ! $this->is_public_storefront_request() ) {
			return $terms;
		}
		if ( ! in_array( 'product_cat', (array) $taxonomies, true ) ) {
			return $terms;
		}
		if ( in_array( $args['fields'] ?? 'all', array( 'count', 'names', 'slugs', 'id=>name', 'id=>slug' ), true ) ) {
			return $terms;
		}

		$visible_ids = $this->get_visible_category_ids();
		if ( empty( $visible_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static function ( $term ) use ( $visible_ids ) {
					$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;

					return $term_id > 0 && isset( $visible_ids[ $term_id ] );
				}
			)
		);
	}

	/**
	 * Whether a product category has a visible product in its subtree.
	 *
	 * @param int $term_id Product category term ID.
	 * @return bool
	 */
	public function is_category_visible( $term_id ) {
		return isset( $this->get_visible_category_ids()[ (int) $term_id ] );
	}

	/**
	 * Remove manually configured product-category links from public menus when
	 * their category has no visible product. Other custom links are preserved.
	 *
	 * @param array $items Menu item objects.
	 * @param mixed $args Menu arguments.
	 * @return array
	 */
	public function filter_invisible_category_menu_items( $items, $args = null ) {
		unset( $args );

		if ( ! is_array( $items ) || ! $this->is_public_storefront_request() ) {
			return $items;
		}

		$visible_ids = $this->get_visible_category_ids();

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $visible_ids ) {
					if ( ! is_object( $item ) || 'product_cat' !== ( $item->object ?? '' ) ) {
						return true;
					}

					return isset( $visible_ids[ (int) ( $item->object_id ?? 0 ) ] );
				}
			)
		);
	}

	/**
	 * Resolve direct product categories plus all of their ancestors.
	 *
	 * @return array<int, true>
	 */
	public function get_visible_category_ids() {
		if ( null !== $this->visible_category_ids ) {
			return $this->visible_category_ids;
		}

		global $wpdb;

		$this->visible_category_ids = array();
		if ( ! isset( $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms ) ) {
			return $this->visible_category_ids;
		}

		$stock_join  = '';
		$stock_where = '';
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' ) && isset( $wpdb->wc_product_meta_lookup ) ) {
			$stock_join  = " INNER JOIN {$wpdb->wc_product_meta_lookup} product_lookup ON product_lookup.product_id = products.ID";
			$stock_where = " AND product_lookup.stock_status = 'instock'";
		}

		$sql = "SELECT DISTINCT category_taxonomy.term_id
			FROM {$wpdb->posts} products
			INNER JOIN {$wpdb->term_relationships} category_relationship
				ON category_relationship.object_id = products.ID
			INNER JOIN {$wpdb->term_taxonomy} category_taxonomy
				ON category_taxonomy.term_taxonomy_id = category_relationship.term_taxonomy_id
				AND category_taxonomy.taxonomy = 'product_cat'
			{$stock_join}
			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				{$stock_where}
				AND NOT EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} visibility_relationship
					INNER JOIN {$wpdb->term_taxonomy} visibility_taxonomy
						ON visibility_taxonomy.term_taxonomy_id = visibility_relationship.term_taxonomy_id
						AND visibility_taxonomy.taxonomy = 'product_visibility'
					INNER JOIN {$wpdb->terms} visibility_term
						ON visibility_term.term_id = visibility_taxonomy.term_id
					WHERE visibility_relationship.object_id = products.ID
						AND visibility_term.slug = 'exclude-from-catalog'
				)";

		$direct_ids = array_map( 'absint', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static table names and literals only.
		foreach ( $direct_ids as $term_id ) {
			if ( $term_id <= 0 ) {
				continue;
			}
			$this->visible_category_ids[ $term_id ] = true;
			foreach ( (array) get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$this->visible_category_ids[ (int) $ancestor_id ] = true;
			}
		}

		/**
		 * Filter the request-local set of storefront-visible product categories.
		 *
		 * @param array<int, true> $visible_category_ids Visible IDs keyed by term ID.
		 */
		$this->visible_category_ids = (array) apply_filters( 'digitalogic_visible_product_category_ids', $this->visible_category_ids );

		return $this->visible_category_ids;
	}

	/**
	 * Keep admin, CLI, cron and integration REST operations authoritative.
	 *
	 * @return bool
	 */
	private function is_public_storefront_request() {
		$is_public = true;
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$is_public = false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$is_public = false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$is_public = false;
		}
		if ( is_admin() ) {
			$is_public = false;
		}

		return (bool) apply_filters( 'digitalogic_is_public_storefront_request', $is_public );
	}
}
