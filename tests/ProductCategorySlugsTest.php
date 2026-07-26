<?php
/**
 * Source-neutral managed product-category slug tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies lookup, redirect, and homepage compatibility.
 */
final class ProductCategorySlugsTest extends TestCase {

	/** Reset every shared registry used by the category service. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_terms']                 = array();
		$GLOBALS['digitalogic_test_term_meta']             = array();
		$GLOBALS['digitalogic_test_posts']                 = array();
		$GLOBALS['digitalogic_test_wc_products']           = array();
		$GLOBALS['digitalogic_test_wc_product_query_args'] = array();
		$GLOBALS['digitalogic_test_action_callbacks']      = array();
		$this->reset_singleton( Digitalogic_Product_Category_Slugs::class );
		$this->reset_singleton( Digitalogic_Homepage_Showcase::class );
	}

	/** Ambiguous category-code ownership must fail closed. */
	public function test_source_category_lookup_fails_closed_when_the_code_is_ambiguous(): void {
		$this->add_term( 10, 'product-category-113007', '113007', true );
		$this->add_term( 11, 'duplicate-category', '113007', true );

		$this->assertFalse( Digitalogic_Product_Category_Slugs::instance()->find_by_category_code( '113007' ) );
	}

	/** Redirects require an exact recorded slug and a managed neutral owner. */
	public function test_legacy_redirect_resolves_only_an_exact_managed_neutral_term(): void {
		$this->add_term( 20, 'product-category-113007', '113007', true );
		update_term_meta(
			20,
			Digitalogic_Product_Category_Slugs::LEGACY_SLUGS_META,
			array( 'patris-custom-113007' )
		);
		$service = Digitalogic_Product_Category_Slugs::instance();

		$this->assertSame(
			'https://digitalogic.test/product-category/product-category-113007',
			$service->resolve_legacy_category_redirect( 'parent/patris-113007' )
		);
		$this->assertSame(
			'https://digitalogic.test/product-category/product-category-113007',
			$service->resolve_legacy_category_redirect( 'patris-custom-113007' )
		);

		$this->add_term( 21, 'product-category-113008', '113008', false );
		$this->assertSame( '', $service->resolve_legacy_category_redirect( 'patris-113008' ) );
	}

	/** Homepage source category selection follows category-code metadata. */
	public function test_homepage_resolves_source_categories_by_code_meta_after_migration(): void {
		$this->add_term( 30, 'product-category-113007', '113007', true );
		$GLOBALS['digitalogic_test_posts'][41] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Neutral category product',
			'meta'         => array(),
		);

		$method = ( new ReflectionClass( Digitalogic_Homepage_Showcase::class ) )->getMethod( 'get_more_products' );
		$result = $method->invoke( Digitalogic_Homepage_Showcase::instance(), 1, array() );

		$this->assertCount( 1, $result );
		$this->assertSame(
			array( 'product-category-113007' ),
			$GLOBALS['digitalogic_test_wc_product_query_args'][0]['category']
		);
	}

	/** The public compatibility redirect is registered at initialization. */
	public function test_service_registers_the_permanent_redirect_hook(): void {
		Digitalogic_Product_Category_Slugs::instance();

		$this->assertSame( 301, Digitalogic_Product_Category_Slugs::LEGACY_REDIRECT_STATUS );
		$this->assertTrue( has_action( 'template_redirect' ) );
		$callback = $GLOBALS['digitalogic_test_action_callbacks']['template_redirect'][0]['callback'];
		$this->assertSame( 'maybe_redirect_legacy_category', $callback[1] );
	}

	/**
	 * Add one exact product category to the test registry.
	 *
	 * @param int    $term_id Exact term ID.
	 * @param string $slug Public slug.
	 * @param string $category_code Authoritative source category Code.
	 * @param bool   $managed Whether the integration owns this term.
	 */
	private function add_term( int $term_id, string $slug, string $category_code, bool $managed ): void {
		$GLOBALS['digitalogic_test_terms'][ $term_id ] = array(
			'term_id'  => $term_id,
			'name'     => 'Category ' . $category_code,
			'slug'     => $slug,
			'parent'   => 0,
			'taxonomy' => 'product_cat',
		);
		update_term_meta( $term_id, Digitalogic_Product_Category_Slugs::CATEGORY_CODE_META, $category_code );
		if ( $managed ) {
			update_term_meta( $term_id, Digitalogic_Product_Category_Slugs::CATEGORY_MANAGED_META, '1' );
		}
	}

	/**
	 * Reset one singleton between tests.
	 *
	 * @param string $class_name Exact class name.
	 */
	private function reset_singleton( string $class_name ): void {
		$property = ( new ReflectionClass( $class_name ) )->getProperty( 'instance' );
		$property->setValue( null, null );
	}
}
