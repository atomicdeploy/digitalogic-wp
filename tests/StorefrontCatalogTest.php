<?php

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $object_id, $object_type = '', $resource_type = '' ) {
		unset( $object_type, $resource_type );

		return $GLOBALS['digitalogic_test_category_ancestors'][ (int) $object_id ] ?? array();
	}
}

require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-catalog.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-product-table.php';

if ( ! function_exists( 'wc_get_product_category_list' ) ) {
	/**
	 * Return an empty category list for focused table-row tests.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $separator Category separator.
	 * @return string
	 */
	function wc_get_product_category_list( $product_id, $separator = ', ' ) {
		unset( $product_id, $separator );

		return '';
	}
}

final class StorefrontCatalogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_filters']            = array();
		$GLOBALS['digitalogic_test_options']            = array();
		$GLOBALS['digitalogic_test_option_cache']       = array();
		$GLOBALS['digitalogic_test_category_ancestors'] = array();
		add_filter( 'digitalogic_is_public_storefront_request', static fn() => true );
	}

	public function test_forces_empty_category_hiding_only_for_product_categories(): void {
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		$product_args = $catalog->force_hide_empty_product_categories(
			array( 'hide_empty' => false ),
			array( 'product_cat' )
		);
		$post_args    = $catalog->force_hide_empty_product_categories(
			array( 'hide_empty' => false ),
			array( 'category' )
		);

		$this->assertTrue( $product_args['hide_empty'] );
		$this->assertFalse( $post_args['hide_empty'] );
	}

	public function test_filters_non_catalog_terms_and_keeps_visible_ancestors(): void {
		$GLOBALS['digitalogic_test_category_ancestors'][31] = array( 20, 10 );
		$GLOBALS['wpdb']                                    = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships     = 'wp_term_relationships';
			public $term_taxonomy          = 'wp_term_taxonomy';
			public $terms                  = 'wp_terms';
			public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
			public $last_catalog_query     = '';

			public function get_col( $query ) {
				$this->last_catalog_query = (string) $query;

				return array( 31 );
			}
		};
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$terms   = array(
			(object) array(
				'term_id' => 10,
				'name'    => 'Parent',
			),
			(object) array(
				'term_id' => 20,
				'name'    => 'Child',
			),
			(object) array(
				'term_id' => 31,
				'name'    => 'Visible leaf',
			),
			(object) array(
				'term_id' => 40,
				'name'    => 'Empty',
			),
		);

		$filtered = $catalog->filter_invisible_product_categories(
			$terms,
			array( 'product_cat' ),
			array( 'fields' => 'all' )
		);

		$this->assertSame( array( 10, 20, 31 ), array_map( static fn( $term ) => $term->term_id, $filtered ) );
		$this->assertStringContainsString( "products.post_status = 'publish'", $GLOBALS['wpdb']->last_catalog_query );
		$this->assertStringContainsString( "visibility_term.slug = 'exclude-from-catalog'", $GLOBALS['wpdb']->last_catalog_query );
	}

	public function test_honors_hide_out_of_stock_store_setting(): void {
		$GLOBALS['digitalogic_test_options']['woocommerce_hide_out_of_stock_items'] = 'yes';
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships     = 'wp_term_relationships';
			public $term_taxonomy          = 'wp_term_taxonomy';
			public $terms                  = 'wp_terms';
			public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
			public $last_catalog_query     = '';

			public function get_col( $query ) {
				$this->last_catalog_query = (string) $query;

				return array();
			}
		};
		$catalog         = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		$catalog->get_visible_category_ids();

		$this->assertStringContainsString( 'wp_wc_product_meta_lookup', $GLOBALS['wpdb']->last_catalog_query );
		$this->assertStringContainsString( "product_lookup.stock_status = 'instock'", $GLOBALS['wpdb']->last_catalog_query );
	}

	public function test_filters_empty_product_categories_from_manual_storefront_menus(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy      = 'wp_term_taxonomy';
			public $terms              = 'wp_terms';

			public function get_col( $query ) {
				unset( $query );

				return array( 10 );
			}
		};
		$catalog         = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$items           = array(
			(object) array(
				'ID'        => 1,
				'object'    => 'product_cat',
				'object_id' => 10,
			),
			(object) array(
				'ID'        => 2,
				'object'    => 'product_cat',
				'object_id' => 20,
			),
			(object) array(
				'ID'        => 3,
				'object'    => 'custom',
				'object_id' => 0,
			),
		);

		$filtered = $catalog->filter_invisible_category_menu_items( $items );

		$this->assertSame( array( 1, 3 ), array_map( static fn( $item ) => $item->ID, $filtered ) );
	}

	/** Ensure a leaf Code search resolves to its published parent row. */
	public function test_table_search_maps_published_child_codes_back_to_parent_products(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $catalog_search_query = '';
			public $catalog_search_args  = array();

			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				$this->catalog_search_query = (string) $query;
				$this->catalog_search_args  = $args;

				return (string) $query;
			}

			public function get_col( $query ) {
				unset( $query );

				return array( 10600 );
			}
		};
		$table           = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method          = new ReflectionMethod( $table, 'search_product_ids' );
		$ids             = $method->invoke( $table, '113004011' );

		$this->assertSame( array( 10600 ), $ids );
		$this->assertStringContainsString( 'variations.post_parent = products.ID', $GLOBALS['wpdb']->catalog_search_query );
		$this->assertStringContainsString( "variations.post_status = 'publish'", $GLOBALS['wpdb']->catalog_search_query );
		$this->assertStringContainsString( 'variation_identifiers.meta_value LIKE %s', $GLOBALS['wpdb']->catalog_search_query );
		$this->assertSame( array_fill( 0, 4, '%113004011%' ), $GLOBALS['wpdb']->catalog_search_args );
	}

	/** Ensure legacy child references cannot masquerade as quick-add choices. */
	public function test_legacy_parent_child_codes_are_labeled_as_registered_and_disable_quick_add(): void {
		$GLOBALS['digitalogic_test_posts'][40] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Legacy parent',
			'meta'         => array( '_price' => '1000' ),
		);
		$GLOBALS['digitalogic_test_posts'][41] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 40,
			'post_title'   => 'Registered child',
			'meta'         => array( '_digitalogic_patris_product_code' => 'CHILD-41' ),
		);
		$product                               = new class( 40 ) extends WC_Product {
			public function get_price_html() {
				return '۱۰۰۰ تومان'; }
			public function managing_stock() {
				return false; }
			public function is_purchasable() {
				return true; }
			public function is_in_stock() {
				return true; }
			public function is_sold_individually() {
				return false; }
			public function add_to_cart_url() {
				return 'https://digitalogic.test/?add-to-cart=40'; }
		};
		$table                                 = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method                                = new ReflectionMethod( $table, 'product_row' );
		$html                                  = $method->invoke( $table, $product );

		$this->assertStringContainsString( 'کدهای ثبت‌شده برای مدل‌ها', $html );
		$this->assertStringContainsString( 'CHILD-41', $html );
		$this->assertStringContainsString( 'دیدن کد مدل‌ها', $html );
		$this->assertStringNotContainsString( 'افزودن سریع', $html );
	}

	/** Ensure source product codes use the canonical generic customer label. */
	public function test_table_uses_generic_product_code_label(): void {
		$GLOBALS['digitalogic_test_posts'][49] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Coded product',
			'meta'         => array(
				'_digitalogic_patris_product_code' => 'CODE-49',
				'_price'                           => '1000',
			),
		);
		$product                               = new class( 49 ) extends WC_Product {
			public function get_price_html() {
				return '۱۰۰۰ تومان'; }
			public function managing_stock() {
				return false; }
			public function is_purchasable() {
				return true; }
			public function is_in_stock() {
				return true; }
			public function is_sold_individually() {
				return false; }
			public function add_to_cart_url() {
				return 'https://digitalogic.test/?add-to-cart=49'; }
		};
		$table                                 = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method                                = new ReflectionMethod( $table, 'product_row' );
		$html                                  = $method->invoke( $table, $product );

		$this->assertStringContainsString( '>کد کالا</span>', $html );
		$this->assertStringContainsString( 'CODE-49', $html );
		$this->assertStringNotContainsString( 'کد پاتریس', $html );
	}

	/** Ensure an unrelated WooCommerce SKU keeps its own label. */
	public function test_table_calls_an_unrelated_code_sku_not_patris_code(): void {
		$GLOBALS['digitalogic_test_posts'][50] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Raspberry Pi',
			'meta'         => array(
				'_sku'   => 'RPI5-8GB',
				'_price' => '1000',
			),
		);
		$product                               = new class( 50 ) extends WC_Product {
			public function get_price_html() {
				return '۱۰۰۰ تومان'; }
			public function managing_stock() {
				return false; }
			public function is_purchasable() {
				return true; }
			public function is_in_stock() {
				return true; }
			public function is_sold_individually() {
				return false; }
			public function add_to_cart_url() {
				return 'https://digitalogic.test/?add-to-cart=50'; }
		};
		$table                                 = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$method                                = new ReflectionMethod( $table, 'product_row' );
		$html                                  = $method->invoke( $table, $product );

		$this->assertStringContainsString( '>SKU</span>', $html );
		$this->assertStringContainsString( 'RPI5-8GB', $html );
		$this->assertStringNotContainsString( 'کد پاتریس', $html );
	}
}
