<?php
/**
 * Shared WooCommerce product-write lock tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify shared product-write locking and WooCommerce hook reentrancy. */
final class ProductWriteLockTest extends TestCase {

	/** Reset the shared lock service and action registry. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_actions']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['wpdb']                              = new Digitalogic_Test_WPDB();
		$property                                     = new ReflectionProperty( Digitalogic_Product_Write_Lock::class, 'instance' );
		$property->setValue( null, null );
	}

	/** Explicit, direct-stock, and product-save writes reuse one physical lock. */
	public function test_explicit_write_and_woocommerce_hooks_share_one_reentrant_lock(): void {
		$service = Digitalogic_Product_Write_Lock::instance();
		$product = new WC_Product( 741 );
		$result  = $service->with_product_lock(
			741,
			function () use ( $product ) {
				do_action( 'woocommerce_product_before_set_stock', $product );
				do_action( 'woocommerce_before_product_object_save', $product );
				$this->assertSame( 1, $GLOBALS['wpdb']->acquire_count );
				do_action( 'woocommerce_after_product_object_save', $product );
				$this->assertSame( 0, $GLOBALS['wpdb']->release_count );
				do_action( 'woocommerce_product_set_stock', $product );
				return 'guarded';
			},
			5
		);

		$this->assertSame( 'guarded', $result );
		$this->assertSame( 1, $GLOBALS['wpdb']->acquire_count );
		$this->assertSame( 1, $GLOBALS['wpdb']->release_count );
		$this->assertContains( 'digitalogic_product_' . md5( 'wp_' ) . '_741', $GLOBALS['wpdb']->lock_names );
		$this->assertTrue( has_action( 'woocommerce_variation_before_set_stock' ) );
		$this->assertTrue( has_action( 'woocommerce_variation_set_stock' ) );
	}

	/** A busy physical lock must fail before the callback runs. */
	public function test_busy_product_lock_is_typed_and_does_not_run_callback(): void {
		$GLOBALS['wpdb']->acquire_result = 0;
		$called                          = false;
		$result                          = Digitalogic_Product_Write_Lock::instance()->with_product_lock(
			741,
			function () use ( &$called ) {
				$called = true;
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'product_write_lock_busy', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertFalse( $called );
	}

	/** A busy direct-stock lock aborts before the data-store mutation. */
	public function test_busy_stock_hook_prevents_direct_mutation(): void {
		$GLOBALS['wpdb']->acquire_result = 0;
		Digitalogic_Product_Write_Lock::instance();
		$product = new WC_Product( 741 );
		$mutated = false;

		try {
			do_action( 'woocommerce_product_before_set_stock', $product );
			$mutated = true;
			do_action( 'woocommerce_product_set_stock', $product );
			$this->fail( 'The direct stock mutation should have been blocked.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'WooCommerce product write lock is busy.', $exception->getMessage() );
		}

		$this->assertFalse( $mutated );
		$this->assertSame( 0, $GLOBALS['wpdb']->release_count );
	}
}
