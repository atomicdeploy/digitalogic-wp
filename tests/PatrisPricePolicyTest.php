<?php
/**
 * Tests for explicit Patris storefront pricing policy behavior.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies safe pricing writes, projections, and audit behavior.
 */
final class PatrisPricePolicyTest extends TestCase {

	/**
	 * Shared feed under test.
	 *
	 * @var Digitalogic_Patris_Feed
	 */
	private $feed;

	/** Prepare an isolated WooCommerce fixture. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']              = array( 'woocommerce_weight_unit' => 'kg' );
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_posts']                = array();
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_actions']              = array();
		$GLOBALS['digitalogic_test_action_callbacks']     = array();
		$GLOBALS['digitalogic_test_cache_deletes']        = array();
		$GLOBALS['digitalogic_test_wc_products']          = array();
		$GLOBALS['digitalogic_test_wc_product_saves']     = array();
		$GLOBALS['digitalogic_test_wc_set_price_calls']   = array();
		$GLOBALS['digitalogic_test_wc_transient_deletes'] = array();

		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		$GLOBALS['digitalogic_test_object_term_cache_cleans']     = array();

		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

		$this->resetSingleton( Digitalogic_Product_Identifier_Resolver::class );
		$this->resetSingleton( Digitalogic_Patris_Price_Policy::class );
		$this->resetSingleton( Digitalogic_Patris_Feed::class );
		$this->resetSingleton( Digitalogic_Product_Manager::class );
		$this->feed = Digitalogic_Patris_Feed::instance();
	}

	/** Verify canonical price becomes both regular and customer-effective price. */
	public function test_simple_product_uses_canonical_regular_and_effective_price(): void {
		$this->addProduct(
			801,
			'simple',
			array(
				'_regular_price' => '100',
				'_price'         => '100',
			)
		);

		$this->feed->apply_product_feed( wc_get_product( 801 ), $this->row( 'SIMPLE-801', 200 ) );

		$product = wc_get_product( 801 );
		$this->assertSame( '200', $product->get_regular_price() );
		$this->assertSame( '200', $product->get_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( 'priced', $product->get_meta( '_digitalogic_patris_price_status', true ) );
		$this->assertSame( array( array( 801, '200' ) ), $GLOBALS['digitalogic_test_wc_set_price_calls'] );
		$this->assertSame( array( 801 ), $GLOBALS['digitalogic_test_wc_transient_deletes'] );
		$this->assertContains( array( 801, 'post_meta' ), $GLOBALS['digitalogic_test_cache_deletes'] );
		$this->assertSame( array( 'product_801' ), $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertSame( array( array( 801, 'product' ) ), $GLOBALS['digitalogic_test_object_term_cache_cleans'] );
	}

	/** Verify a stale promotion is removed and cannot override the selling price. */
	public function test_active_sale_is_cleared_and_customer_price_matches_selling_price(): void {
		$this->addProduct(
			802,
			'simple',
			array(
				'_regular_price' => '500',
				'_sale_price'    => '250',
				'_price'         => '250',
			)
		);

		$this->feed->apply_product_feed( wc_get_product( 802 ), $this->row( 'SALE-802', 600 ) );

		$product    = wc_get_product( 802 );
		$projection = Digitalogic_Patris_Price_Policy::instance()->project( $product );
		$this->assertSame( '600', $product->get_regular_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( '600', $product->get_price() );
		$this->assertSame( 'priced', $projection['policy_status'] );
		$this->assertSame( Digitalogic_Patris_Price_Policy::CANONICAL_SALE, $projection['sale_policy'] );
		$this->assertFalse( $projection['sale_active'] );
		$this->assertSame( 'regular', $projection['price_source'] );
		$this->assertSame( array( array( 802, '600' ) ), $GLOBALS['digitalogic_test_wc_set_price_calls'] );
	}

	/** Verify a retired policy option cannot restore split pricing. */
	public function test_legacy_replacement_policy_cannot_change_canonical_behavior(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Price_Policy::OPTION_NAME ] = Digitalogic_Patris_Price_Policy::REPLACE_SALE;
		$this->addProduct(
			803,
			'simple',
			array(
				'_regular_price' => '500',
				'_sale_price'    => '250',
				'_price'         => '250',
			)
		);

		$this->feed->apply_product_feed( wc_get_product( 803 ), $this->row( 'REPLACE-803', 600 ) );

		$product = wc_get_product( 803 );
		$this->assertSame( '600', $product->get_regular_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( '600', $product->get_price() );
		$this->assertSame( 'priced', $product->get_meta( '_digitalogic_patris_price_status', true ) );
		$this->assertSame( Digitalogic_Patris_Price_Policy::CANONICAL_SALE, $product->get_meta( '_digitalogic_patris_sale_policy', true ) );
	}

	/** Verify variable containers remain canonical-only. */
	public function test_variable_parent_stays_canonical_only_without_storefront_price_writes(): void {
		$this->addProduct(
			804,
			'variable',
			array(
				'_regular_price' => '900',
				'_price'         => '850',
			)
		);

		$this->feed->apply_product_feed( wc_get_product( 804 ), $this->row( 'VARIABLE-804', 1200 ) );

		$product = wc_get_product( 804 );
		$this->assertSame( '1200', (string) $product->get_meta( '_digitalogic_patris_final_price', true ) );
		$this->assertSame( '900', $product->get_regular_price() );
		$this->assertSame( '850', $product->get_price() );
		$this->assertSame( 'canonical_only_variable', $product->get_meta( '_digitalogic_patris_price_status', true ) );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_set_price_calls'] );
	}

	/** Verify an exact-code variation may receive its own regular price. */
	public function test_exact_code_variation_receives_its_own_canonical_regular_price(): void {
		$this->addProduct( 805, 'simple', array(), 'product' );
		$this->addProduct(
			806,
			'variation',
			array(
				'_regular_price' => '100',
				'_price'         => '100',
			),
			'product_variation',
			805
		);

		$this->feed->apply_product_feed( wc_get_product( 806 ), $this->row( 'VARIATION-806', 345 ) );

		$product = wc_get_product( 806 );
		$this->assertSame( 'variation', $product->get_type() );
		$this->assertSame( '345', $product->get_regular_price() );
		$this->assertSame( '345', $product->get_price() );
		$this->assertSame( 'priced', $product->get_meta( '_digitalogic_patris_price_status', true ) );
	}

	/** Verify missing/nonpositive canonical values cannot leave a stale customer price. */
	public function test_missing_and_nonpositive_canonical_values_clear_all_customer_prices(): void {
		$this->addProduct(
			807,
			'simple',
			array(
				'_regular_price' => '700',
				'_sale_price'    => '650',
				'_price'         => '650',
			)
		);

		$this->feed->apply_product_feed( wc_get_product( 807 ), array( 'product_code' => 'MISSING-807' ) );
		$product = wc_get_product( 807 );
		$this->assertSame( '', $product->get_regular_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( '', $product->get_price() );
		$this->assertSame( 'canonical_missing_unpriced', $product->get_meta( '_digitalogic_patris_price_status', true ) );

		$this->feed->apply_product_feed( $product, $this->row( 'MISSING-807', 0 ) );
		$product = wc_get_product( 807 );
		$this->assertSame( '', $product->get_regular_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( '', $product->get_price() );
		$this->assertSame( 'canonical_nonpositive_unpriced', $product->get_meta( '_digitalogic_patris_price_status', true ) );
	}

	/** A missing weight preserves an already-consistent storefront price with a warning. */
	public function test_missing_weight_preserves_valid_existing_storefront_price(): void {
		$this->addProduct(
			812,
			'simple',
			array(
				'_regular_price' => '1150000',
				'_sale_price'    => '',
				'_price'         => '1150000',
			)
		);

		$this->feed->apply_product_feed(
			wc_get_product( 812 ),
			array(
				'product_code'  => 'MISSING-WEIGHT-812',
				'foreign_price' => 32,
				'total_stock'   => 1,
			)
		);

		$product    = wc_get_product( 812 );
		$projection = Digitalogic_Patris_Price_Policy::instance()->project( $product );
		$this->assertSame( '1150000', $product->get_regular_price() );
		$this->assertSame( '1150000', $product->get_price() );
		$this->assertSame( '', $product->get_sale_price() );
		$this->assertSame( 'canonical_missing_preserved', $projection['policy_status'] );
		$this->assertTrue( $projection['preserved_storefront_price'] );
		$this->assertSame( Digitalogic_Patris_Price_Policy::MISSING_WEIGHT_WARNING, $projection['policy_warning'] );
	}

	/** Sparse stock is conservatively unavailable while explicit quantities map deterministically. */
	public function test_sparse_stock_clears_stale_inventory_and_explicit_stock_is_floored(): void {
		$this->addProduct(
			810,
			'simple',
			array(
				'_regular_price' => '700',
				'_price'         => '700',
				'_manage_stock'  => 'yes',
				'_stock'         => 9,
				'_stock_status'  => 'instock',
			)
		);

		$this->feed->apply_product_feed( wc_get_product( 810 ), array( 'product_code' => 'STOCK-810' ) );
		$product = wc_get_product( 810 );
		$this->assertFalse( $product->get_manage_stock() );
		$this->assertNull( $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );

		$this->feed->apply_product_feed( $product, array( 'product_code' => 'STOCK-810', 'total_stock' => 0 ) );
		$product = wc_get_product( 810 );
		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 0, $product->get_stock_quantity() );
		$this->assertSame( 'outofstock', $product->get_stock_status() );

		$this->feed->apply_product_feed( $product, array( 'product_code' => 'STOCK-810', 'total_stock' => 1.9 ) );
		$product = wc_get_product( 810 );
		$this->assertSame( 1, $product->get_stock_quantity() );
		$this->assertSame( 'instock', $product->get_stock_status() );
	}

	/** Numeric source metadata is verified against MySQL's textual scalar readback. */
	public function test_feed_readback_normalizes_scalar_metadata_to_database_types(): void {
		$method = new ReflectionMethod( Digitalogic_Patris_Feed::class, 'normalize_meta_readback_value' );

		$this->assertSame( '0', $method->invoke( $this->feed, 0 ) );
		$this->assertSame( '4', $method->invoke( $this->feed, 4 ) );
		$this->assertSame( '2.5', $method->invoke( $this->feed, 2.5 ) );
		$this->assertSame( '1', $method->invoke( $this->feed, true ) );
		$this->assertSame( '', $method->invoke( $this->feed, false ) );
		$this->assertSame( array( 'warehouse' => 4 ), $method->invoke( $this->feed, array( 'warehouse' => 4 ) ) );
	}

	/** Verify the product projection names every distinct price value. */
	public function test_product_api_names_effective_price_and_policy_explicitly(): void {
		$this->addProduct(
			808,
			'simple',
			array(
				'_regular_price'                   => '500',
				'_sale_price'                      => '450',
				'_price'                           => '450',
				'_digitalogic_patris_final_price'  => '500',
				'_digitalogic_patris_price_status' => 'priced_sale_preserved',
				'_digitalogic_patris_sale_policy'  => 'preserve_sale',
			)
		);

		$data = Digitalogic_Product_Manager::instance()->get_product( 808 );

		$this->assertSame( '500', $data['patris_final_price'] );
		$this->assertSame( '500', $data['regular_price'] );
		$this->assertSame( '450', $data['sale_price'] );
		$this->assertSame( '450', $data['effective_price'] );
		$this->assertSame( 'sale', $data['price_source'] );
		$this->assertSame( 'canonical_sale', $data['patris_sale_policy'] );
	}

	/** Verify audit results are useful and strictly non-mutating. */
	public function test_audit_reports_differences_without_saving_products(): void {
		$this->addProduct(
			809,
			'simple',
			array(
				'_regular_price'                  => '100',
				'_price'                          => '100',
				'_digitalogic_patris_final_price' => '100',
			)
		);
		$this->addProduct(
			810,
			'simple',
			array(
				'_regular_price'                  => '100',
				'_price'                          => '100',
				'_digitalogic_patris_final_price' => '200',
			)
		);
		$this->addProduct(
			811,
			'variable',
			array(
				'_price'                          => '75',
				'_digitalogic_patris_final_price' => '300',
			)
		);
		$before = $GLOBALS['digitalogic_test_posts'];

		$rows = Digitalogic_Patris_Price_Policy::instance()->audit( 50, 1 );

		$this->assertSame( array( 'match', 'different', 'canonical_only_variable' ), array_column( $rows, 'audit_status' ) );
		$this->assertSame( array( 'no', 'yes', 'no' ), array_column( $rows, 'needs_review' ) );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( $before, $GLOBALS['digitalogic_test_posts'] );
	}

	/** Verify old configuration cannot change the fixed policy and CLI stays discoverable. */
	public function test_unknown_policy_cannot_change_canonical_behavior_and_cli_commands_are_registered(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Price_Policy::OPTION_NAME ] = 'unsafe_unknown_policy';

		$this->assertSame(
			Digitalogic_Patris_Price_Policy::CANONICAL_SALE,
			Digitalogic_Patris_Price_Policy::instance()->get_sale_policy()
		);
		$this->assertArrayHasKey( 'digitalogic pricing audit', WP_CLI::$commands );
		$this->assertArrayHasKey( 'digitalogic pricing policy', WP_CLI::$commands );
	}

	/**
	 * Build one normalized Patris row.
	 *
	 * @param string $code  Exact Patris product code.
	 * @param mixed  $price Canonical final price.
	 * @return array
	 */
	private function row( string $code, $price ): array {
		return array(
			'product_code' => $code,
			'final_price'  => $price,
			'total_stock'  => 5,
		);
	}

	/**
	 * Add one product test fixture.
	 *
	 * @param int    $id        Product ID.
	 * @param string $type      WooCommerce product type.
	 * @param array  $meta      Product metadata.
	 * @param string $post_type WordPress post type.
	 * @param int    $parent_id Parent product ID.
	 * @return void
	 */
	private function addProduct( int $id, string $type, array $meta, string $post_type = 'product', int $parent_id = 0 ): void {
		$meta['_digitalogic_patris_product_code'] = $meta['_digitalogic_patris_product_code'] ?? strtoupper( $type ) . '-' . $id;
		$GLOBALS['digitalogic_test_posts'][ $id ] = array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'product_type' => $type,
			'post_parent'  => $parent_id,
			'meta'         => $meta,
		);
	}

	/**
	 * Reset a singleton between tests.
	 *
	 * @param string $class_name Singleton class name.
	 * @return void
	 */
	private function resetSingleton( string $class_name ): void {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
