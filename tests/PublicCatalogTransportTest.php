<?php
/**
 * Public catalog transport regression tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-frontend-search.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-product-table.php';

/** Verify the read-only catalog command across public transports. */
final class PublicCatalogTransportTest extends TestCase {

	/** Reset command and capability state between transport tests. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_capabilities']           = array();
		$GLOBALS['digitalogic_test_current_user_can_calls'] = 0;
		remove_all_filters( 'digitalogic_command_handlers' );
		remove_all_filters( 'digitalogic_command_requires_auth' );
	}

	/** Ensure the bounded read-only catalog command is public over WebSocket. */
	public function test_public_catalog_command_can_run_over_websocket_without_admin_capability(): void {
		$search = ( new ReflectionClass( Digitalogic_Frontend_Search::class ) )->newInstanceWithoutConstructor();

		add_filter(
			'digitalogic_command_handlers',
			static function ( $commands ) {
				$commands['digitalogic_catalog_page'] = static fn () => array( 'page' => 2 );

				return $commands;
			}
		);
		add_filter( 'digitalogic_command_requires_auth', array( $search, 'allow_public_search_command' ), 10, 4 );

		$result = Digitalogic_Command_Dispatcher::instance()->execute(
			'digitalogic_catalog_page',
			array( 'dgl_page' => 2 ),
			'websocket'
		);

		$this->assertSame( array( 'page' => 2 ), $result );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_current_user_can_calls'] );
	}

	/** Ensure the authorization exception cannot leak into normal AJAX commands. */
	public function test_public_catalog_exception_is_limited_to_websocket_transport(): void {
		$search = ( new ReflectionClass( Digitalogic_Frontend_Search::class ) )->newInstanceWithoutConstructor();
		add_filter( 'digitalogic_command_requires_auth', array( $search, 'allow_public_search_command' ), 10, 4 );

		$result = Digitalogic_Command_Dispatcher::instance()->execute(
			'digitalogic_catalog_page',
			array(),
			'ajax'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_unauthorized', $result->get_error_code() );
		$this->assertSame( 1, $GLOBALS['digitalogic_test_current_user_can_calls'] );
	}

	/** Ensure malformed payloads fail before any public-command exception. */
	public function test_invalid_payload_is_rejected_before_public_auth_filter(): void {
		$auth_filter_called = false;
		add_filter(
			'digitalogic_command_requires_auth',
			static function ( $requires_auth ) use ( &$auth_filter_called ) {
				$auth_filter_called = true;

				return $requires_auth;
			},
			10,
			4
		);

		$result = Digitalogic_Command_Dispatcher::instance()->execute(
			'digitalogic_catalog_page',
			'not-an-object',
			'websocket'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_invalid_payload', $result->get_error_code() );
		$this->assertFalse( $auth_filter_called );
	}

	/** Ensure handler registration and untrusted filter bounds stay deterministic. */
	public function test_catalog_handler_registration_and_filter_normalization(): void {
		$table    = ( new ReflectionClass( Digitalogic_Storefront_Product_Table::class ) )->newInstanceWithoutConstructor();
		$commands = $table->register_commands( array(), 'websocket' );

		$this->assertArrayHasKey( 'digitalogic_catalog_page', $commands );
		$this->assertIsCallable( $commands['digitalogic_catalog_page'] );

		$method  = new ReflectionMethod( Digitalogic_Storefront_Product_Table::class, 'request_filters' );
		$filters = $method->invoke(
			$table,
			array(
				'dgl_search'   => '  ESP32  ',
				'dgl_category' => '131',
				'dgl_sort'     => 'not-valid',
				'dgl_page'     => '5000',
			)
		);

		$this->assertSame( 'ESP32', $filters['search'] );
		$this->assertSame( 131, $filters['category'] );
		$this->assertSame( 'recommended', $filters['sort'] );
		$this->assertSame( 1000, $filters['page'] );
	}
}
