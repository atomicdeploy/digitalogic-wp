<?php
/**
 * Product metadata inspector contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify exact identifiers and derived lookup diagnostics. */
final class ProductMetadataInspectorTest extends TestCase {
	/**
	 * Inspector under test.
	 *
	 * @var Digitalogic_Product_Metadata_Inspector
	 */
	private $inspector;

	/** Reset deterministic product and lookup fixtures. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_posts']          = array(
			801 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'meta'        => array(
					'_sku'           => '00123',
					'_regular_price' => '100',
					'_price'         => '100.00',
					'_stock'         => '5.0000',
					'_stock_status'  => 'instock',
					'_tax_status'    => 'taxable',
					'_tax_class'     => '',
					'total_sales'    => '2',
				),
			),
			802 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'meta'        => array(
					'_sku'          => 'MISMATCH',
					'_price'        => '125',
					'_stock'        => '3',
					'_stock_status' => 'instock',
					'_tax_status'   => 'taxable',
					'_tax_class'    => '',
					'total_sales'   => '0',
				),
			),
			803 => array(
				'post_type' => 'product',
				'meta'      => array( '_sku' => 'DUPLICATE' ),
			),
			804 => array(
				'post_type' => 'product_variation',
				'meta'      => array( '_sku' => 'DUPLICATE' ),
			),
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows'] = array(
			801 => $this->lookupRow( 801, '00123', '100.0000', '100.0000', '5.0', 'instock', 'taxable', '', '2' ),
			802 => $this->lookupRow( 802, 'OTHER', '124', '124', '4', 'outofstock', 'none', 'reduced-rate', '1' ),
		);
		$GLOBALS['wpdb']                            = new Digitalogic_Test_WPDB();

		$resolver = new ReflectionProperty( Digitalogic_Product_Identifier_Resolver::class, 'instance' );
		$resolver->setValue( null, null );
		$inspector = new ReflectionProperty( Digitalogic_Product_Metadata_Inspector::class, 'instance' );
		$inspector->setValue( null, null );
		$this->inspector = Digitalogic_Product_Metadata_Inspector::instance();
	}

	/** Verify leading-zero SKUs and bounded query counts. */
	public function test_exact_leading_zero_sku_uses_one_resolver_and_one_lookup_query(): void {
		$result = $this->inspector->inspect( array( 'sku' => '00123' ) );

		$this->assertSame( '801', $result['woocommerce_id'] );
		$this->assertSame( '00123', $result['sku'] );
		$this->assertSame( 'sku', $result['resolved_by'] );
		$this->assertSame( 'derived_diagnostic_only', $result['lookup_table_role'] );
		$this->assertTrue( $result['is_consistent'] );
		$this->assertSame( 0, $result['inconsistency_count'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->identifier_query_count );
		$this->assertSame( 1, $GLOBALS['wpdb']->metadata_lookup_query_count );
	}

	/** Verify structured mismatches never promote derived data to source of truth. */
	public function test_mismatches_are_structured_and_lookup_data_is_not_authoritative(): void {
		$result = $this->inspector->inspect( array( 'woocommerce_id' => '802' ) );

		$this->assertFalse( $result['is_consistent'] );
		$this->assertSame( 'woocommerce_crud_and_current_postmeta', $result['source_of_truth'] );
		$fields = array_column( $result['inconsistencies'], 'field' );
		foreach ( array( 'sku', 'stock_quantity', 'stock_status', 'tax_status', 'tax_class', 'total_sales', 'price' ) as $field ) {
			$this->assertContains( $field, $fields );
		}
		foreach ( $result['inconsistencies'] as $inconsistency ) {
			$this->assertContains( $inconsistency['code'], array( 'derived_lookup_mismatch', 'lookup_row_missing' ) );
		}
	}

	/** Verify ambiguous exact SKUs fail before diagnostics run. */
	public function test_duplicate_sku_fails_before_any_lookup_read(): void {
		$result = $this->inspector->inspect( array( 'sku' => 'DUPLICATE' ) );

		$this->assertSame( 'digitalogic_product_identifier_ambiguous', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->metadata_lookup_query_count );
	}

	/** Verify database failures remain retryable and redacted. */
	public function test_lookup_database_failure_is_retryable_and_redacted(): void {
		$GLOBALS['wpdb']->metadata_lookup_query_failure = true;

		$result = $this->inspector->inspect( array( 'sku' => '00123' ) );

		$this->assertSame( 'digitalogic_product_metadata_query_failed', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertStringNotContainsString( 'Injected', $result->get_error_message() );
	}

	/**
	 * Build one deterministic lookup row.
	 *
	 * @param int    $id Product ID.
	 * @param string $sku SKU.
	 * @param string $min Minimum price.
	 * @param string $max Maximum price.
	 * @param string $stock Stock quantity.
	 * @param string $stock_status Stock status.
	 * @param string $tax_status Tax status.
	 * @param string $tax_class Tax class.
	 * @param string $sales Total sales.
	 * @return array
	 */
	private function lookupRow( $id, $sku, $min, $max, $stock, $stock_status, $tax_status, $tax_class, $sales ): array {
		return array(
			'product_id'       => $id,
			'sku'              => $sku,
			'virtual'          => '0',
			'downloadable'     => '0',
			'min_price'        => $min,
			'max_price'        => $max,
			'onsale'           => '0',
			'stock_quantity'   => $stock,
			'stock_status'     => $stock_status,
			'rating_count'     => '0',
			'average_rating'   => '0',
			'total_sales'      => $sales,
			'tax_status'       => $tax_status,
			'tax_class'        => $tax_class,
			'global_unique_id' => '',
		);
	}
}
