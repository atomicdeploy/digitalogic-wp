<?php
/**
 * Stored feed comparisons must not execute storefront pricing filters.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify stored projections stay independent of presentation filters. */
final class PatrisRawFeedProjectionTest extends TestCase {

	/** Different view values cannot contaminate feed verification. */
	public function test_expected_feed_projection_uses_raw_prices_even_when_view_prices_differ(): void {
		$GLOBALS['digitalogic_test_posts'][98765] = array( 'meta' => array() );
		$product                                  = new class(98765) extends WC_Product {
			/** @var int Number of presentation reads. */
			public $view_reads = 0;
			/** Return context-specific fixture data. */
			public function get_regular_price( $context = 'view' ) {
				if ( 'view' === $context ) {
					++$this->view_reads;
					return '900'; }
				return '100';
			}
			/** Return context-specific fixture data. */
			public function get_sale_price( $context = 'view' ) {
				if ( 'view' === $context ) {
					++$this->view_reads;
					return '800'; }
				return '';
			}
			/** Return context-specific fixture data. */
			public function get_price( $context = 'view' ) {
				if ( 'view' === $context ) {
					++$this->view_reads;
					return '800'; }
				return '100';
			}
		};
		$method                                   = new ReflectionMethod( Digitalogic_Patris_Feed::class, 'capture_product_feed_expected' );
		$expected                                 = $method->invoke( Digitalogic_Patris_Feed::instance(), $product, array() );
		$this->assertSame( '100', $expected['props']['regular_price'] );
		$this->assertSame( '', $expected['props']['sale_price'] );
		$this->assertSame( '100', $expected['props']['price'] );
		$this->assertSame( 0, $product->view_reads );
		unset( $GLOBALS['digitalogic_test_posts'][98765] );
	}
}
