<?php
/**
 * Product lookup refresh safety tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify row actions never silently trigger a catalog-wide rebuild. */
final class ProductLookupRefreshTest extends TestCase {
	/** Reset the shared table handler and data-store fixtures. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_wc_lookup_full_rebuilds'] = 0;
		$GLOBALS['digitalogic_test_wc_data_store']           = null;

		$instance = new ReflectionProperty( Digitalogic_Product_Table::class, 'instance' );
		$instance->setValue( null, null );
	}

	/** Verify supported WooCommerce versions refresh only normalized row IDs. */
	public function test_supported_data_store_refreshes_only_requested_rows(): void {
		$data_store                                = new Digitalogic_Test_WC_Product_Data_Store_With_Row_Refresh();
		$GLOBALS['digitalogic_test_wc_data_store'] = $data_store;
		$table                                     = Digitalogic_Product_Table::instance();

		$this->assertTrue( $table->supports_per_product_refresh() );
		$this->assertTrue( $table->regenerate_lookup_tables( array( 901, '901', 0 ) ) );
		$this->assertSame( array( 901 ), $data_store->refreshed_ids );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_wc_lookup_full_rebuilds'] );
	}

	/** Verify older WooCommerce versions refuse row refresh without rebuilding all rows. */
	public function test_unsupported_data_store_never_falls_back_to_global_rebuild(): void {
		$GLOBALS['digitalogic_test_wc_data_store'] = new Digitalogic_Test_WC_Product_Data_Store_Without_Row_Refresh();
		$table                                     = Digitalogic_Product_Table::instance();
		$proxy                                     = WC_Data_Store::load( 'product' );

		$this->assertTrue( is_callable( array( $proxy, 'refresh_product_lookup_table' ) ) );
		$this->assertFalse( $table->supports_per_product_refresh() );
		$result = $table->regenerate_lookup_tables( array( 901 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_lookup_row_refresh_unsupported', $result->get_error_code() );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_wc_lookup_full_rebuilds'] );
	}

	/** Verify an explicit maintenance request can still rebuild the whole catalog. */
	public function test_empty_id_list_is_an_explicit_global_maintenance_action(): void {
		$this->assertTrue( Digitalogic_Product_Table::instance()->regenerate_lookup_tables() );
		$this->assertSame( 1, $GLOBALS['digitalogic_test_wc_lookup_full_rebuilds'] );
	}
}
