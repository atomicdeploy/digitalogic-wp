<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/integrations/class-frontend-search.php';

final class FrontendSearchFreshnessTest extends TestCase {

	/** Exercise real response/cache code in an isolated WordPress fixture. */
	public function test_cache_revision_and_fresh_price_boundaries(): void {
		$output = array();
		$status = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/search-cache-regression.php' ), $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertContains( 'Search cache regression: 7 boundaries passed', $output );
	}

	public function test_daemon_rejects_search_before_reusing_woocommerce_request_state(): void {
		$search = Digitalogic_Frontend_Search::instance();
		$this->assertFalse( $search->allow_public_ajax_search_action( true, 'woodmart_ajax_search', array(), 'websocket' ) );
		$this->assertTrue( $search->allow_public_ajax_search_action( true, 'woodmart_ajax_search', array(), 'ajax' ) );
		$this->assertTrue( $search->allow_public_ajax_search_action( true, 'digitalogic_catalog_page', array(), 'websocket' ) );
		$this->assertFalse( $search->allow_public_ajax_search_action( false, 'unrelated', array(), 'websocket' ) );
	}
}
