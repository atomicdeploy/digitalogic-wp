<?php
/**
 * Product metadata REST and CLI access tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify shared exact-selector behavior across REST and CLI. */
final class ProductMetadataAccessTest extends TestCase {
	/**
	 * API under test.
	 *
	 * @var Digitalogic_REST_API
	 */
	private $api;

	/** Reset deterministic route and product fixtures. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_posts']            = array(
			901 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Leading zero SKU',
				'meta'        => array(
					'_sku'          => '000901',
					'_price'        => '10',
					'_stock'        => '1',
					'_stock_status' => 'instock',
				),
			),
			902 => array(
				'post_type' => 'product',
				'meta'      => array( '_sku' => 'DUP-902' ),
			),
			903 => array(
				'post_type' => 'product_variation',
				'meta'      => array( '_sku' => 'DUP-902' ),
			),
			904 => array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'meta'        => array( '_sku' => 'NOT-A-PRODUCT' ),
			),
			905 => array(
				'post_type'   => 'product',
				'post_status' => 'trash',
				'meta'        => array( '_sku' => 'TRASHED-PRODUCT' ),
			),
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows']   = array(
			901 => array(
				'product_id'     => 901,
				'sku'            => '000901',
				'virtual'        => '0',
				'downloadable'   => '0',
				'min_price'      => '10',
				'max_price'      => '10',
				'onsale'         => '0',
				'stock_quantity' => '1',
				'stock_status'   => 'instock',
				'rating_count'   => '0',
				'average_rating' => '0',
				'total_sales'    => '0',
				'tax_status'     => '',
				'tax_class'      => '',
			),
		);
		$GLOBALS['digitalogic_test_product_updates']  = array();
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$GLOBALS['digitalogic_test_routes']           = array();
		WP_CLI::$errors                               = array();
		WP_CLI::$warnings                             = array();
		WP_CLI::$logs                                 = array();
		$GLOBALS['wpdb']                              = new Digitalogic_Test_WPDB();

		foreach ( array( Digitalogic_Product_Identifier_Resolver::class, Digitalogic_Product_Metadata_Inspector::class, Digitalogic_Product_Manager::class, Digitalogic_REST_API::class ) as $class ) {
			$property = new ReflectionProperty( $class, 'instance' );
			$property->setValue( null, null );
		}
		$this->api = Digitalogic_REST_API::instance();
	}

	/** Verify REST reads and writes preserve leading-zero SKUs. */
	public function test_rest_reads_and_updates_by_exact_leading_zero_sku(): void {
		$get = $this->api->get_product_by_sku( new WP_REST_Request( array( 'sku' => '000901' ) ) );
		$this->assertSame( 200, $get->get_status() );
		$this->assertSame( '000901', $get->get_data()['data']['sku'] );

		$metadata = $this->api->get_product_metadata_by_sku( new WP_REST_Request( array( 'sku' => '000901' ) ) );
		$this->assertSame( 200, $metadata->get_status() );
		$this->assertSame( '901', $metadata->get_data()['data']['woocommerce_id'] );

		$update = $this->api->update_product_by_sku(
			new WP_REST_Request(
				array( 'sku' => '000901' ),
				array( 'regular_price' => '12' )
			)
		);
		$this->assertSame( 200, $update->get_status() );
		$this->assertSame( array( 901 ), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( '12', $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Verify ambiguous reads and writes return HTTP conflict. */
	public function test_ambiguous_sku_returns_conflict_for_read_and_write(): void {
		$get    = $this->api->get_product_by_sku( new WP_REST_Request( array( 'sku' => 'DUP-902' ) ) );
		$update = $this->api->update_product_by_sku( new WP_REST_Request( array( 'sku' => 'DUP-902' ), array( 'stock_quantity' => 2 ) ) );

		$this->assertSame( 409, $get->get_status() );
		$this->assertSame( 409, $update->get_status() );
		$this->assertSame( 'digitalogic_product_identifier_ambiguous', $get->get_data()['code'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Verify registered ID routes reject noncanonical and unavailable IDs. */
	public function test_registered_id_routes_use_the_shared_exact_resolver(): void {
		$leading_zero = $this->dispatchRegisteredIdRoute( 'GET', '0901' );
		$zero         = $this->dispatchRegisteredIdRoute( 'PUT', '0', array( 'regular_price' => '99' ) );
		$nonproduct   = $this->dispatchRegisteredIdRoute( 'GET', '904' );
		$trashed      = $this->dispatchRegisteredIdRoute( 'GET', '905' );

		$this->assertSame( 400, $leading_zero->get_status() );
		$this->assertSame( 'digitalogic_invalid_product_identifier', $leading_zero->get_data()['code'] );
		$this->assertSame( 400, $zero->get_status() );
		$this->assertSame( 'digitalogic_invalid_product_identifier', $zero->get_data()['code'] );
		$this->assertSame( 404, $nonproduct->get_status() );
		$this->assertSame( 404, $trashed->get_status() );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Verify all requested CLI commands are registered. */
	public function test_cli_commands_are_registered_on_the_shared_manager_contract(): void {
		$this->assertArrayHasKey( 'digitalogic products get', WP_CLI::$commands );
		$this->assertArrayHasKey( 'digitalogic products metadata', WP_CLI::$commands );
		$this->assertArrayHasKey( 'digitalogic products update', WP_CLI::$commands );
	}

	/** Verify the historical positional-ID --sku setter remains functional. */
	public function test_cli_positional_id_sku_setter_remains_backward_compatible(): void {
		$command = new Digitalogic_CLI_Commands();

		try {
			$command->products_update( array( '901' ), array( 'sku' => '000902' ) );
			$this->fail( 'WP_CLI::success should terminate the command.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'Product updated.', $exception->getMessage() );
		}

		$this->assertSame( '000902', $GLOBALS['digitalogic_test_posts'][901]['meta']['_sku'] );
		$this->assertSame( array( 901 ), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertCount( 1, WP_CLI::$warnings );
		$this->assertStringContainsString( 'deprecated', WP_CLI::$warnings[0] );
	}

	/** Verify the historical positional setter can still clear an SKU. */
	public function test_cli_positional_id_sku_setter_can_clear_the_sku(): void {
		$command = new Digitalogic_CLI_Commands();

		try {
			$command->products_update( array( '901' ), array( 'sku' => '' ) );
			$this->fail( 'WP_CLI::success should terminate the command.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'Product updated.', $exception->getMessage() );
		}

		$this->assertSame( '', $GLOBALS['digitalogic_test_posts'][901]['meta']['_sku'] );
		$this->assertSame( array( 901 ), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertCount( 1, WP_CLI::$warnings );
	}

	/** Verify exact SKU selection and the explicit replacement option. */
	public function test_cli_sku_selector_with_set_sku_updates_the_resolved_product(): void {
		$command = new Digitalogic_CLI_Commands();

		try {
			$command->products_update(
				array(),
				array(
					'sku'     => '000901',
					'set-sku' => '000902',
				)
			);
			$this->fail( 'WP_CLI::success should terminate the command.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'Product updated.', $exception->getMessage() );
		}

		$this->assertSame( '000902', $GLOBALS['digitalogic_test_posts'][901]['meta']['_sku'] );
		$this->assertSame( array( 901 ), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( array(), WP_CLI::$warnings );
	}

	/**
	 * Dispatch the callback registered for one ID route and HTTP method.
	 *
	 * @param string $method HTTP method.
	 * @param string $id Raw route ID.
	 * @param array  $json Optional request body.
	 * @return WP_REST_Response
	 */
	private function dispatchRegisteredIdRoute( $method, $id, $json = array() ): WP_REST_Response {
		$GLOBALS['digitalogic_test_routes'] = array();
		$this->api->register_routes();

		foreach ( $GLOBALS['digitalogic_test_routes'] as $route ) {
			if (
				'digitalogic/v1' === $route['namespace']
				&& '/products/(?P<id>\d+)' === $route['route']
				&& strtoupper( $method ) === strtoupper( (string) $route['args']['methods'] )
			) {
				$request = new WP_REST_Request( array( 'id' => $id ), $json );
				$request->set_method( $method );
				$request->set_route( '/digitalogic/v1/products/' . $id );

				return call_user_func( $route['args']['callback'], $request );
			}
		}

		$this->fail( 'The expected product ID route was not registered.' );
	}
}
