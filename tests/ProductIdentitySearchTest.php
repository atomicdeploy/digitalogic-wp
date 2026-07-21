<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'DIGITALOGIC_PLUGIN_URL' ) ) {
	define( 'DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/' );
}

if ( ! defined( 'DIGITALOGIC_VERSION' ) ) {
	define( 'DIGITALOGIC_VERSION', 'test' );
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'is_product' ) ) {
	function is_product() {
		return ! empty( $GLOBALS['digitalogic_test_is_product'] );
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return isset( $GLOBALS['digitalogic_test_queried_object_id'] ) ? (int) $GLOBALS['digitalogic_test_queried_object_id'] : 0;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $dependencies = array(), $version = false ) {
		$GLOBALS['digitalogic_test_enqueued_styles'][ $handle ] = compact( 'src', 'dependencies', 'version' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $dependencies = array(), $version = false, $in_footer = false ) {
		$GLOBALS['digitalogic_test_enqueued_scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $data ) {
		$GLOBALS['digitalogic_test_localized_scripts'][ $handle ][ $object_name ] = $data;

		return true;
	}
}

final class ProductIdentitySearchTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_posts']            = array();
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_filters']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['product']                           = null;
		$GLOBALS['digitalogic_test_is_product']       = false;
		$GLOBALS['digitalogic_test_queried_object_id'] = 0;
		$GLOBALS['digitalogic_test_enqueued_styles']  = array();
		$GLOBALS['digitalogic_test_enqueued_scripts'] = array();
		$GLOBALS['digitalogic_test_localized_scripts'] = array();
	}

	public function test_localizes_single_product_patris_name_for_woodmart_fallback(): void {
		$GLOBALS['digitalogic_test_posts'][12] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Reviewed Persian product title',
			'meta'        => array( '_digitalogic_patris_family_name' => 'ATmega <Core>' ),
		);
		$GLOBALS['digitalogic_test_is_product']        = true;
		$GLOBALS['digitalogic_test_queried_object_id'] = 12;
		$identity                                      = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		$identity->enqueue_assets();

		$this->assertSame(
			'ATmega <Core>',
			$GLOBALS['digitalogic_test_localized_scripts']['digitalogic-product-identity']['digitalogicProductIdentity']['singleProductPatrisName']
		);
	}

	public function test_renders_escaped_patris_name_as_a_second_line(): void {
		$GLOBALS['digitalogic_test_posts'][10] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'ماژول فارسی',
			'meta'        => array( '_digitalogic_patris_name' => 'English <Module>' ),
		);
		$GLOBALS['product']                    = wc_get_product( 10 );
		$identity                              = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$identity->render_single_patris_name();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'digitalogic-patris-name', $html );
		$this->assertStringContainsString( 'English &lt;Module&gt;', $html );
		$this->assertStringNotContainsString( 'English <Module>', $html );
	}

	public function test_exposes_child_identity_and_adds_sku_mpn_without_replacing_offers(): void {
		$GLOBALS['digitalogic_test_posts'][11] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 10,
			'meta'         => array(
				'_sku'                             => 'PAT-11',
				'_digitalogic_patris_product_code' => 'PAT-11',
				'_digitalogic_patris_name'         => 'Patris child',
				'_digitalogic_persian_name'        => 'فرزند فارسی',
				'_digitalogic_part_number'         => 'HC-06-DIP',
			),
		);
		$variation                             = wc_get_product( 11 );
		$identity                              = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		$data   = $identity->add_variation_identity_data( array(), null, $variation );
		$entity = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => array( 'price' => '100' ),
			),
			$variation
		);

		$this->assertSame( 'Patris child', $data['digitalogic_patris_name'] );
		$this->assertSame( 'PAT-11', $data['digitalogic_patris_code'] );
		$this->assertSame( 'فرزند فارسی', $data['digitalogic_persian_name'] );
		$this->assertSame( 'PAT-11', $entity['sku'] );
		$this->assertSame( 'HC-06-DIP', $entity['mpn'] );
		$this->assertSame( array( 'price' => '100' ), $entity['offers'] );

		ob_start();
		$identity->render_variation_identity_slot();
		$slot = ob_get_clean();
		$this->assertStringContainsString( 'dir="ltr"', $slot );
		$this->assertStringContainsString( 'lang="en"', $slot );
	}

	public function test_product_search_includes_both_names_code_serial_part_model_and_variations(): void {
		$GLOBALS['wpdb'] = new class() extends Digitalogic_Test_WPDB {
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy      = 'wp_term_taxonomy';
			public $terms              = 'wp_terms';

			public function esc_like( $value ) {
				return addcslashes( (string) $value, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				return (string) $query;
			}
		};
		$query           = new class() extends WP_Query {
			public function __construct() {}
			public function is_search() {
				return true; }
			public function get( $key ) {
				return 's' === $key ? 'HC-06' : ( 'post_type' === $key ? 'product' : null );
			}
		};
		$panel           = ( new ReflectionClass( Digitalogic_Panel::class ) )->newInstanceWithoutConstructor();

		$core_search = ' AND (((wp_posts.post_title LIKE "%HC-06%") OR (wp_posts.post_excerpt LIKE "%HC-06%")) AND ((wp_posts.post_title NOT LIKE "%private%") AND (wp_posts.post_excerpt NOT LIKE "%private%"))) AND (wp_posts.post_password = "")';
		$sql         = $panel->extend_product_search( $core_search, $query );

		foreach (
			array(
				'_digitalogic_patris_name',
				'_digitalogic_persian_name',
				'_digitalogic_patris_product_code',
				'_digitalogic_patris_serial',
				'_digitalogic_part_number',
				'_digitalogic_model',
				'product_variation',
				'attribute_pa_model',
			) as $needle
		) {
			$this->assertStringContainsString( $needle, $sql );
		}
		$this->assertStringContainsString( '(wp_posts.post_title NOT LIKE "%private%")', $sql );
		$this->assertStringEndsWith( ' AND (wp_posts.post_password = "")', $sql );
		$this->assertLessThan( strpos( $sql, 'NOT LIKE' ), strpos( $sql, '_digitalogic_patris_name' ) );
		$this->assertStringContainsString( "variations.post_status = 'publish'", $sql );
		$this->assertStringNotContainsString( "variations.post_status IN ('publish', 'draft', 'trash')", $sql );
	}
}
