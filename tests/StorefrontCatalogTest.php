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
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy = 'wp_term_taxonomy';
			public $terms = 'wp_terms';
			public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
			public $last_catalog_query = '';

			public function get_col( $query ) {
				$this->last_catalog_query = (string) $query;

				return array( 31 );
			}
		};
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$terms   = array(
			(object) array( 'term_id' => 10, 'name' => 'Parent' ),
			(object) array( 'term_id' => 20, 'name' => 'Child' ),
			(object) array( 'term_id' => 31, 'name' => 'Visible leaf' ),
			(object) array( 'term_id' => 40, 'name' => 'Empty' ),
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
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy = 'wp_term_taxonomy';
			public $terms = 'wp_terms';
			public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
			public $last_catalog_query = '';

			public function get_col( $query ) {
				$this->last_catalog_query = (string) $query;

				return array();
			}
		};
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();

		$catalog->get_visible_category_ids();

		$this->assertStringContainsString( 'wp_wc_product_meta_lookup', $GLOBALS['wpdb']->last_catalog_query );
		$this->assertStringContainsString( "product_lookup.stock_status = 'instock'", $GLOBALS['wpdb']->last_catalog_query );
	}

	public function test_filters_empty_product_categories_from_manual_storefront_menus(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy = 'wp_term_taxonomy';
			public $terms = 'wp_terms';

			public function get_col( $query ) {
				unset( $query );

				return array( 10 );
			}
		};
		$catalog = ( new ReflectionClass( Digitalogic_Storefront_Catalog::class ) )->newInstanceWithoutConstructor();
		$items   = array(
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
}
