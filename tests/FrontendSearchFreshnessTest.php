<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/integrations/class-frontend-search.php';

final class FrontendSearchFreshnessTest extends TestCase {

	public function test_daemon_rejects_search_before_reusing_woocommerce_request_state(): void {
		$search = Digitalogic_Frontend_Search::instance();
		$this->assertFalse( $search->allow_public_ajax_search_action( true, 'woodmart_ajax_search', array(), 'websocket' ) );
		$this->assertTrue( $search->allow_public_ajax_search_action( true, 'woodmart_ajax_search', array(), 'ajax' ) );
		$this->assertTrue( $search->allow_public_ajax_search_action( true, 'digitalogic_catalog_page', array(), 'websocket' ) );
		$this->assertFalse( $search->allow_public_ajax_search_action( false, 'unrelated', array(), 'websocket' ) );
	}
}
