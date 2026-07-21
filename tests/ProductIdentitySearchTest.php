<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'DIGITALOGIC_PLUGIN_URL' ) ) {
	define( 'DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/' );
}

if ( ! defined( 'DIGITALOGIC_PLUGIN_DIR' ) ) {
	define( 'DIGITALOGIC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
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
		$GLOBALS['digitalogic_test_posts']             = array();
		$GLOBALS['digitalogic_test_wc_products']       = array();
		$GLOBALS['digitalogic_test_filters']           = array();
		$GLOBALS['digitalogic_test_action_callbacks']  = array();
		$GLOBALS['product']                            = null;
		$GLOBALS['digitalogic_test_is_product']        = false;
		$GLOBALS['digitalogic_test_queried_object_id'] = 0;
		$GLOBALS['digitalogic_test_enqueued_styles']   = array();
		$GLOBALS['digitalogic_test_enqueued_scripts']  = array();
		$GLOBALS['digitalogic_test_localized_scripts'] = array();
	}

	public function test_localizes_single_product_patris_name_for_woodmart_fallback(): void {
		$GLOBALS['digitalogic_test_posts'][12]         = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Reviewed Persian product title',
			'meta'        => array(
				'_digitalogic_patris_family_name'  => 'ATmega <Core>',
				'_digitalogic_patris_product_code' => 'PAT-12',
			),
		);
		$GLOBALS['digitalogic_test_is_product']        = true;
		$GLOBALS['digitalogic_test_queried_object_id'] = 12;
		$identity                                      = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		$identity->enqueue_assets();

		$config = $GLOBALS['digitalogic_test_localized_scripts']['digitalogic-product-identity']['digitalogicProductIdentity'];
		$this->assertSame( 'ATmega <Core>', $config['singleProductPatrisName'] );
		$this->assertSame( 'PAT-12', $config['singleProductPatrisCode'] );
		$this->assertSame( 'کد کالا', $config['codeLabel'] );
		$this->assertFalse( $config['singleProductIsVariable'] );
		$this->assertFalse( $config['singleProductLegacyChildReferences'] );
		$this->assertSame( array(), $config['singleProductChildCodes'] );
	}

	public function test_renders_escaped_patris_name_and_code_as_customer_facing_identity(): void {
		$GLOBALS['digitalogic_test_posts'][10] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'ماژول فارسی',
			'meta'        => array(
				'_digitalogic_patris_name'         => 'English <Module>',
				'_digitalogic_patris_product_code' => 'PAT-<10>',
			),
		);
		$GLOBALS['product']                    = wc_get_product( 10 );
		$identity                              = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$identity->render_single_patris_name();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'digitalogic-patris-name', $html );
		$this->assertStringContainsString( 'English &lt;Module&gt;', $html );
		$this->assertStringNotContainsString( 'English <Module>', $html );
		$this->assertStringContainsString( 'کد کالا', $html );
		$this->assertStringContainsString( 'PAT-&lt;10&gt;', $html );
		$this->assertStringNotContainsString( 'PAT-<10>', $html );
	}

	/** Ensure an exact Code remains visible without mislabeling an unrelated SKU. */
	public function test_renders_code_when_patris_name_matches_title_and_never_labels_sku_as_patris(): void {
		$GLOBALS['digitalogic_test_posts'][13] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Identical name',
			'meta'        => array(
				'_digitalogic_patris_name'         => 'Identical name',
				'_digitalogic_patris_product_code' => 'PAT-13',
			),
		);
		$GLOBALS['product']                    = wc_get_product( 13 );
		$identity                              = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$identity->render_single_patris_name();
		$code_html = ob_get_clean();

		$this->assertStringNotContainsString( 'digitalogic-patris-name', $code_html );
		$this->assertStringContainsString( 'PAT-13', $code_html );

		$GLOBALS['digitalogic_test_posts'][14] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'SKU only',
			'meta'        => array( '_sku' => 'RPI5-8GB' ),
		);
		$GLOBALS['product']                    = wc_get_product( 14 );
		ob_start();
		$identity->render_single_patris_name();
		$sku_html = ob_get_clean();

		$this->assertSame( '', $sku_html );
	}

	/** Ensure legacy child Codes stay reference-only and block ambiguous purchase. */
	public function test_lists_registered_child_codes_for_legacy_code_less_parent(): void {
		$GLOBALS['digitalogic_test_posts'][20] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Legacy parent',
			'meta'         => array(),
		);
		$GLOBALS['digitalogic_test_posts'][21] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 20,
			'post_title'   => 'Model <A>',
			'meta'         => array( '_digitalogic_patris_product_code' => 'CHILD-21' ),
		);
		$GLOBALS['digitalogic_test_posts'][22] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'draft',
			'product_type' => 'variation',
			'post_parent'  => 20,
			'post_title'   => 'Draft model',
			'meta'         => array( '_digitalogic_patris_product_code' => 'DRAFT-22' ),
		);
		$GLOBALS['product']                    = wc_get_product( 20 );
		$identity                              = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$identity->render_single_patris_name();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'کدهای ثبت‌شده برای مدل‌ها', $html );
		$this->assertStringContainsString( 'Model &lt;A&gt;', $html );
		$this->assertStringContainsString( 'CHILD-21', $html );
		$this->assertStringNotContainsString( 'DRAFT-22', $html );
		$this->assertStringContainsString( 'برای انتخاب کد دقیق با پشتیبانی هماهنگ کن', $html );

		$GLOBALS['digitalogic_test_is_product']        = true;
		$GLOBALS['digitalogic_test_queried_object_id'] = 20;
		$identity->enqueue_assets();
		$config = $GLOBALS['digitalogic_test_localized_scripts']['digitalogic-product-identity']['digitalogicProductIdentity'];
		$this->assertTrue( $config['singleProductLegacyChildReferences'] );
		$this->assertStringContainsString( 'برای انتخاب کد دقیق', $config['legacyChildNote'] );
	}

	public function test_exposes_child_identity_and_adds_sku_mpn_without_replacing_offers(): void {
		$GLOBALS['digitalogic_test_posts'][11] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 10,
			'meta'         => array(
				'_sku'                             => 'PAT-11',
				'_price'                           => '100',
				'_regular_price'                   => '100',
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
		$this->assertSame( 'Patris child', $entity['alternateName'] );
		$this->assertSame( array( 'price' => '100' ), $entity['offers'] );

		ob_start();
		$identity->render_variation_identity_slot();
		$slot = ob_get_clean();
		$this->assertStringContainsString( 'data-digitalogic-variation-identity', $slot );
		$this->assertStringContainsString( 'aria-live="polite"', $slot );
	}

	/** Ensure the selected Code follows a customer line through the order flow. */
	public function test_adds_exact_patris_code_to_cart_checkout_and_order_details(): void {
		$GLOBALS['digitalogic_test_posts'][30] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'Cart product',
			'meta'        => array(
				'_sku'                             => 'PAT-<30>',
				'_digitalogic_patris_product_code' => 'PAT-<30>',
			),
		);
		$product                               = wc_get_product( 30 );
		$identity                              = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();

		$item_data = $identity->add_cart_item_patris_code(
			array(),
			array(
				'data'       => $product,
				'product_id' => 30,
			)
		);
		$this->assertSame( 'کد کالا', $item_data[0]['key'] );
		$this->assertSame( 'PAT-<30>', $item_data[0]['value'] );
		$this->assertStringContainsString( 'PAT-&lt;30&gt;', $item_data[0]['display'] );

		$order_item = new class() {
			/**
			 * Captured order metadata.
			 *
			 * @var array
			 */
			public $meta = array();

			/**
			 * Capture one metadata write.
			 *
			 * @param string $key Metadata label.
			 * @param string $value Metadata value.
			 * @param bool   $unique Whether the key is unique.
			 */
			public function add_meta_data( $key, $value, $unique ) {
				$this->meta[] = compact( 'key', 'value', 'unique' );
			}
		};
		$identity->add_order_item_patris_code( $order_item, 'cart-key', array( 'data' => $product ), null );
		$this->assertSame(
			array(
				'key'    => 'کد کالا',
				'value'  => 'PAT-<30>',
				'unique' => true,
			),
			$order_item->meta[0]
		);
	}

	public function test_unpriced_product_keeps_honest_identity_without_a_fabricated_offer(): void {
		$GLOBALS['digitalogic_test_posts'][12] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'محصول بدون قیمت',
			'meta'         => array(
				'_sku'                             => 'PAT-12',
				'_digitalogic_patris_product_code' => 'PAT-12',
				'_digitalogic_patris_name'         => 'Unpriced Patris product',
			),
		);

		$product  = wc_get_product( 12 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'name'   => 'محصول بدون قیمت',
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => '',
					'priceCurrency' => 'IRT',
				),
			),
			$product
		);

		$this->assertSame( 'PAT-12', $entity['sku'] );
		$this->assertSame( 'Unpriced Patris product', $entity['alternateName'] );
		$this->assertArrayNotHasKey( 'offers', $entity );
	}

	public function test_zero_priced_product_does_not_emit_an_offer(): void {
		$GLOBALS['digitalogic_test_posts'][16] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Zero priced product',
			'meta'         => array(
				'_sku'                             => 'PAT-16',
				'_price'                           => '000.000',
				'_regular_price'                   => '000.000',
				'_digitalogic_patris_product_code' => 'PAT-16',
			),
		);

		$product  = wc_get_product( 16 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => '0',
					'priceCurrency' => 'IRT',
				),
			),
			$product
		);

		$this->assertArrayNotHasKey( 'offers', $entity );
	}

	public function test_toman_offer_uses_equivalent_iso_rial_price_without_float_drift(): void {
		$GLOBALS['digitalogic_test_posts'][13] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'محصول قیمت‌دار',
			'meta'         => array(
				'_sku'                             => 'PAT-13',
				'_price'                           => '123.45',
				'_regular_price'                   => '123.45',
				'_digitalogic_patris_product_code' => 'PAT-13',
				'_digitalogic_patris_name'         => 'Priced Patris product',
			),
		);

		$product  = wc_get_product( 13 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => '123.45',
					'priceCurrency' => 'IRT',
				),
			),
			$product
		);

		$this->assertSame( '1234.5', $entity['offers']['price'] );
		$this->assertSame( 'IRR', $entity['offers']['priceCurrency'] );
	}

	public function test_noncanonical_toman_offer_is_not_relabelled_as_rial(): void {
		$GLOBALS['digitalogic_test_posts'][15] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'محصول قیمت‌دار',
			'meta'         => array(
				'_price'                           => '123.45',
				'_regular_price'                   => '123.45',
				'_digitalogic_patris_product_code' => 'PAT-15',
			),
		);

		$product  = wc_get_product( 15 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$offer    = array(
			'@type'         => 'Offer',
			'price'         => '1,234',
			'priceCurrency' => 'IRT',
		);
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => $offer,
			),
			$product
		);

		$this->assertSame( $offer, $entity['offers'] );
	}

	public function test_nested_noncanonical_toman_price_preserves_the_entire_offer_subtree(): void {
		$GLOBALS['digitalogic_test_posts'][17] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'variable',
			'post_title'   => 'Atomic offer conversion',
			'meta'         => array(
				'_price'         => '10',
				'_regular_price' => '10',
			),
		);

		$product  = wc_get_product( 17 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$offer    = array(
			'@type'         => 'AggregateOffer',
			'lowPrice'      => '10',
			'highPrice'     => '20',
			'priceCurrency' => 'IRT',
			'offers'        => array(
				array(
					'@type' => 'Offer',
					'price' => '1,234',
				),
			),
		);
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => $offer,
			),
			$product
		);

		$this->assertSame( $offer, $entity['offers'] );
	}

	public function test_declared_toman_container_with_only_nested_prices_is_relabelled_atomically(): void {
		$GLOBALS['digitalogic_test_posts'][18] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'variable',
			'post_title'   => 'Nested offers',
			'meta'         => array(
				'_price'         => '12.34',
				'_regular_price' => '12.34',
			),
		);

		$product  = wc_get_product( 18 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => array(
					'@type'         => 'AggregateOffer',
					'priceCurrency' => 'IRT',
					'offers'        => array(
						array(
							'@type' => 'Offer',
							'price' => '12.34',
						),
					),
				),
			),
			$product
		);

		$this->assertSame( 'IRR', $entity['offers']['priceCurrency'] );
		$this->assertSame( '123.4', $entity['offers']['offers'][0]['price'] );
		$this->assertSame( 'IRR', $entity['offers']['offers'][0]['priceCurrency'] );
	}

	public function test_code_less_variable_parent_normalizes_nested_offers_and_exposes_family_name(): void {
		$GLOBALS['digitalogic_test_posts'][14] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'variable',
			'post_title'   => 'خانواده حسگر گاز',
			'meta'         => array(
				'_price'                          => '100',
				'_digitalogic_patris_family_name' => 'MQ gas sensor family',
			),
		);

		$product  = wc_get_product( 14 );
		$identity = ( new ReflectionClass( Digitalogic_Product_Identity::class ) )->newInstanceWithoutConstructor();
		$entity   = $identity->add_product_schema_identity(
			array(
				'@type'  => 'Product',
				'offers' => array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => '10.01',
					'highPrice'     => '20',
					'priceCurrency' => 'IRT',
					'offers'        => array(
						array(
							'@type'              => 'Offer',
							'priceSpecification' => array(
								'@type' => 'UnitPriceSpecification',
								'price' => '10.01',
							),
						),
					),
				),
			),
			$product
		);

		$this->assertSame( 'MQ gas sensor family', $entity['alternateName'] );
		$this->assertArrayNotHasKey( 'sku', $entity );
		$this->assertSame( '100.1', $entity['offers']['lowPrice'] );
		$this->assertSame( '200', $entity['offers']['highPrice'] );
		$this->assertSame( 'IRR', $entity['offers']['priceCurrency'] );
		$this->assertSame( '100.1', $entity['offers']['offers'][0]['priceSpecification']['price'] );
		$this->assertSame( 'IRR', $entity['offers']['offers'][0]['priceSpecification']['priceCurrency'] );
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
