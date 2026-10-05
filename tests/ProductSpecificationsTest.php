<?php
/**
 * Product specification integration tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify product metadata, storefront rendering, and Product schema output. */
final class ProductSpecificationsTest extends TestCase {

	/**
	 * Integration under test.
	 *
	 * @var Digitalogic_Product_Specifications
	 */
	private $specifications;

	/** Reset product and taxonomy fixtures. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_posts']             = array();
		$GLOBALS['digitalogic_test_terms']             = array(
			10 => array(
				'term_id'  => 10,
				'name'     => 'SMD',
				'slug'     => 'smd',
				'parent'   => 0,
				'taxonomy' => 'product_cat',
			),
			11 => array(
				'term_id'  => 11,
				'name'     => 'SMD IC',
				'slug'     => 'smd-ic',
				'parent'   => 10,
				'taxonomy' => 'product_cat',
			),
			20 => array(
				'term_id'  => 20,
				'name'     => 'Modules',
				'slug'     => 'modules',
				'parent'   => 0,
				'taxonomy' => 'product_cat',
			),
		);
		$GLOBALS['digitalogic_test_enqueued_styles']   = array();
		$GLOBALS['digitalogic_test_enqueued_scripts']  = array();
		$GLOBALS['digitalogic_test_localized_scripts'] = array();
		$GLOBALS['digitalogic_test_is_admin']          = false;
		$GLOBALS['digitalogic_test_is_product']        = false;
		$GLOBALS['product']                            = null;
		$_POST = array();

		$this->specifications = ( new ReflectionClass( Digitalogic_Product_Specifications::class ) )->newInstanceWithoutConstructor();
	}

	/** Remove request data after every test. */
	protected function tearDown(): void {
		$_POST = array();
	}

	/** SMD descendants accept sanitized controlled values. */
	public function test_saves_controlled_authenticity_and_smd_fields_for_smd_descendant(): void {
		$product = $this->product( 100, array( 11 ) );
		$_POST   = array(
			Digitalogic_Product_Specifications::AUTHENTICITY_META => 'copy',
			Digitalogic_Product_Specifications::SMD_MARKING_META => ' A1<script> ',
			Digitalogic_Product_Specifications::SMD_PACKAGE_META => ' SOT-23 ',
			'tax_input' => array( 'product_cat' => array( '11' ) ),
		);

		$this->specifications->save_product_fields( $product );

		$this->assertSame( 'copy', $product->get_meta( Digitalogic_Product_Specifications::AUTHENTICITY_META, true ) );
		$this->assertSame( 'A1', $product->get_meta( Digitalogic_Product_Specifications::SMD_MARKING_META, true ) );
		$this->assertSame( 'SOT-23', $product->get_meta( Digitalogic_Product_Specifications::SMD_PACKAGE_META, true ) );
	}

	/** Products outside SMD cannot leak stale SMD-only metadata. */
	public function test_non_smd_product_cannot_retain_smd_only_metadata(): void {
		$product = $this->product(
			101,
			array( 20 ),
			array(
				Digitalogic_Product_Specifications::AUTHENTICITY_META => 'original',
				Digitalogic_Product_Specifications::SMD_MARKING_META  => 'OLD',
				Digitalogic_Product_Specifications::SMD_PACKAGE_META  => 'QFN-32',
			)
		);
		$_POST   = array(
			Digitalogic_Product_Specifications::AUTHENTICITY_META => 'not-reviewed',
			Digitalogic_Product_Specifications::SMD_MARKING_META => 'NEW',
			Digitalogic_Product_Specifications::SMD_PACKAGE_META => 'SOIC-8',
			'tax_input' => array( 'product_cat' => array( '20' ) ),
		);

		$this->specifications->save_product_fields( $product );

		$this->assertSame( '', $product->get_meta( Digitalogic_Product_Specifications::AUTHENTICITY_META, true ) );
		$this->assertSame( '', $product->get_meta( Digitalogic_Product_Specifications::SMD_MARKING_META, true ) );
		$this->assertSame( '', $product->get_meta( Digitalogic_Product_Specifications::SMD_PACKAGE_META, true ) );
	}

	/** Storefront output contains only escaped populated values. */
	public function test_renders_only_populated_applicable_values_with_escaped_output(): void {
		$GLOBALS['product'] = $this->product(
			102,
			array( 11 ),
			array(
				Digitalogic_Product_Specifications::AUTHENTICITY_META => 'original',
				Digitalogic_Product_Specifications::SMD_MARKING_META  => 'A1<mark>',
				Digitalogic_Product_Specifications::SMD_PACKAGE_META  => 'SOIC-8',
			)
		);

		ob_start();
		$this->specifications->render_single_product_specs();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'وضعیت اصالت', $html );
		$this->assertStringContainsString( '>Original<', $html );
		$this->assertStringContainsString( '>A1<', $html );
		$this->assertStringNotContainsString( '<mark>', $html );
		$this->assertStringContainsString( '>SOIC-8<', $html );
	}

	/** Marking remains an additional property and never becomes an MPN. */
	public function test_marking_and_package_are_product_schema_properties_not_mpn(): void {
		$product = $this->product(
			103,
			array( 11 ),
			array(
				Digitalogic_Product_Specifications::AUTHENTICITY_META => 'copy',
				Digitalogic_Product_Specifications::SMD_MARKING_META  => 'A7X',
				Digitalogic_Product_Specifications::SMD_PACKAGE_META  => 'QFN-32',
			)
		);

		$entity = $this->specifications->add_product_schema_properties(
			array(
				'@type'              => 'Product',
				'additionalProperty' => array(
					'@type' => 'PropertyValue',
					'name'  => 'Voltage',
					'value' => '3.3V',
				),
			),
			$product
		);

		$this->assertArrayNotHasKey( 'mpn', $entity );
		$this->assertCount( 4, $entity['additionalProperty'] );
		$properties = array_column( $entity['additionalProperty'], 'value', 'name' );
		$this->assertSame( 'Copy', $properties['Authenticity / وضعیت اصالت'] );
		$this->assertSame( 'A7X', $properties['SMD Marking / مارکینگ روی قطعه'] );
		$this->assertSame( 'QFN-32', $properties['SMD Package / پکیج قطعه'] );
	}

	/** Authenticity is global while SMD metadata remains category-scoped. */
	public function test_non_smd_schema_includes_authenticity_but_omits_smd_fields(): void {
		$product = $this->product(
			104,
			array( 20 ),
			array(
				Digitalogic_Product_Specifications::AUTHENTICITY_META => 'original',
				Digitalogic_Product_Specifications::SMD_MARKING_META  => 'SHOULD-NOT-LEAK',
				Digitalogic_Product_Specifications::SMD_PACKAGE_META  => 'SHOULD-NOT-LEAK',
			)
		);

		$entity     = $this->specifications->add_product_schema_properties( array( '@type' => 'Product' ), $product );
		$properties = array_column( $entity['additionalProperty'], 'value', 'name' );

		$this->assertSame( array( 'Authenticity / وضعیت اصالت' => 'Original' ), $properties );
	}

	/** Variations inherit their parent product-level specifications. */
	public function test_variation_uses_parent_level_specifications(): void {
		$this->product(
			105,
			array( 10 ),
			array(
				Digitalogic_Product_Specifications::AUTHENTICITY_META => 'original',
				Digitalogic_Product_Specifications::SMD_MARKING_META  => 'PARENT-MARK',
			)
		);
		$GLOBALS['digitalogic_test_posts'][106] = array(
			'post_type'    => 'product_variation',
			'product_type' => 'variation',
			'post_parent'  => 105,
			'post_status'  => 'publish',
			'post_title'   => 'Child',
			'meta'         => array(),
		);

		$entity     = $this->specifications->add_product_schema_properties( array( '@type' => 'Product' ), wc_get_product( 106 ) );
		$properties = array_column( $entity['additionalProperty'], 'value', 'name' );

		$this->assertSame( 'Original', $properties['Authenticity / وضعیت اصالت'] );
		$this->assertSame( 'PARENT-MARK', $properties['SMD Marking / مارکینگ روی قطعه'] );
	}

	/**
	 * Create one product fixture.
	 *
	 * @param int   $id Product ID.
	 * @param array $category_ids Category IDs.
	 * @param array $meta Product metadata.
	 * @return WC_Product
	 */
	private function product( $id, $category_ids, $meta = array() ) {
		$GLOBALS['digitalogic_test_posts'][ $id ] = array(
			'post_type'    => 'product',
			'product_type' => 'simple',
			'post_status'  => 'publish',
			'post_title'   => 'Product ' . $id,
			'category_ids' => $category_ids,
			'meta'         => $meta,
		);

		return wc_get_product( $id );
	}
}
