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
		$GLOBALS['digitalogic_test_posts']           = array(
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
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows']  = array(
			901 => array(
				'product_id'       => 901,
				'sku'              => '000901',
				'virtual'          => '0',
				'downloadable'     => '0',
				'min_price'        => '10',
				'max_price'        => '10',
				'onsale'           => '0',
				'stock_quantity'   => '1',
				'stock_status'     => 'instock',
				'rating_count'     => '0',
				'average_rating'   => '0',
				'total_sales'      => '0',
				'tax_status'       => '',
				'tax_class'        => '',
				'global_unique_id' => '',
			),
		);
		$GLOBALS['digitalogic_test_product_updates'] = array();
		$GLOBALS['wpdb']                             = new Digitalogic_Test_WPDB();

		foreach ( array( Digitalogic_Product_Identifier_Resolver::class, Digitalogic_Product_Metadata_Inspector::class, Digitalogic_REST_API::class ) as $class ) {
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
		$this->assertSame( 901, $GLOBALS['digitalogic_test_product_updates'][0]['product_id'] );
	}

	/** Verify ambiguous reads and writes return HTTP conflict. */
	public function test_ambiguous_sku_returns_conflict_for_read_and_write(): void {
		$get    = $this->api->get_product_by_sku( new WP_REST_Request( array( 'sku' => 'DUP-902' ) ) );
		$update = $this->api->update_product_by_sku( new WP_REST_Request( array( 'sku' => 'DUP-902' ), array( 'stock_quantity' => 2 ) ) );

		$this->assertSame( 409, $get->get_status() );
		$this->assertSame( 409, $update->get_status() );
		$this->assertSame( 'digitalogic_product_identifier_ambiguous', $get->get_data()['code'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_product_updates'] );
	}

	/** Verify all requested CLI commands are registered. */
	public function test_cli_commands_are_registered_on_the_shared_manager_contract(): void {
		$this->assertArrayHasKey( 'digitalogic products get', WP_CLI::$commands );
		$this->assertArrayHasKey( 'digitalogic products metadata', WP_CLI::$commands );
		$this->assertArrayHasKey( 'digitalogic products update', WP_CLI::$commands );
	}
}
