<?php
/**
 * Product-list query contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers request normalization, pre-pagination filters, and dispatcher reuse.
 */
final class ProductQueryTest extends TestCase {

	/**
	 * Reset the shared test manager before each assertion group.
	 */
	protected function setUp(): void {
		$manager = new ReflectionProperty( Digitalogic_Product_Manager::class, 'instance' );
		$manager->setValue( null, null );
		unset( $GLOBALS['digitalogic_test_product_query_result'] );
	}

	/**
	 * Untrusted filter keys cannot escape the canonical allowlist.
	 */
	public function test_normalizes_comprehensive_filters_and_rejects_untrusted_query_keys(): void {
		$normalized = Digitalogic_Product_Query::normalize_args(
			array(
				'page'    => '-8',
				'limit'   => 1000,
				'search'  => '  board  ',
				'filters' => array(
					'id'                  => '0012',
					'sku'                 => '  SKU-42  ',
					'type'                => 'variable',
					'status'              => 'publish',
					'stock_status'        => 'instock',
					'regular_price'       => array(
						'min' => '۱۲٬۵۰۰٫۵',
						'max' => 'bad',
					),
					'patris_weight_grams' => array( 'max' => '250.25' ),
					'arbitrary_meta_key'  => 'must-not-pass',
				),
				'image'   => 'with',
				'sorts'   => array(
					array(
						'field'     => 'regular_price',
						'direction' => 'asc',
					),
					array(
						'field'     => 'arbitrary_meta_key',
						'direction' => 'asc',
					),
				),
			)
		);

		$this->assertSame( 1, $normalized['page'] );
		$this->assertSame( 100, $normalized['limit'] );
		$this->assertSame( 'board', $normalized['search'] );
		$this->assertArrayNotHasKey( 'id', $normalized['filters'] );
		$this->assertSame( 'SKU-42', $normalized['filters']['sku'] );
		$this->assertSame( 'variable', $normalized['filters']['type'] );
		$this->assertSame( 'publish', $normalized['filters']['status'] );
		$this->assertSame( 'instock', $normalized['filters']['stock_status'] );
		$this->assertSame( array( 'min' => '12500.5' ), $normalized['filters']['regular_price'] );
		$this->assertSame( array( 'max' => '250.25' ), $normalized['filters']['patris_weight_grams'] );
		$this->assertArrayNotHasKey( 'arbitrary_meta_key', $normalized['filters'] );
		$this->assertSame(
			array(
				array(
					'field'     => 'regular_price',
					'direction' => 'asc',
				),
			),
			$normalized['sorts']
		);
	}

	/**
	 * Range, image, type, and status filters are part of the SQL plan.
	 */
	public function test_builds_filters_into_the_database_query_before_pagination(): void {
		$query = Digitalogic_Product_Query::build_wp_query_args(
			array(
				'page'    => 3,
				'limit'   => 25,
				'search'  => 'Raspberry',
				'filters' => array(
					'type'               => 'simple',
					'status'             => 'draft',
					'sku'                => 'PI-',
					'part_number'        => 'CM4',
					'stock_status'       => 'outofstock',
					'weight'             => array(
						'min' => '10',
						'max' => '500',
					),
					'patris_final_price' => array( 'max' => '9000000' ),
				),
				'image'   => 'without',
				'sorts'   => array(
					array(
						'field'     => 'weight',
						'direction' => 'desc',
					),
				),
			)
		);

		$this->assertSame( 25, $query['posts_per_page'] );
		$this->assertSame( 3, $query['paged'] );
		$this->assertSame( array( 'product' ), $query['post_type'] );
		$this->assertSame( array( 'draft' ), $query['post_status'] );
		$this->assertSame( 'Raspberry', $query['s'] );
		$this->assertFalse( $query['no_found_rows'] );
		$this->assertSame( '_weight', $query['digitalogic_product_sort_meta'] );
		$this->assertSame( 1, $query['digitalogic_product_sort_numeric'] );
		$this->assertSame( 'none', $query['orderby'] );
		$this->assertSame( 'DESC', $query['order'] );
		$this->assertSame( array( 'simple' ), $query['tax_query'][0]['terms'] );
		$this->assertSame( 'CM4', $query['digitalogic_product_part_number_filter'] );

		$meta_keys = $this->collectMetaKeys( $query['meta_query'] );
		$this->assertContains( '_sku', $meta_keys );
		$this->assertContains( '_stock_status', $meta_keys );
		$this->assertContains( '_weight', $meta_keys );
		$this->assertContains( '_digitalogic_patris_final_price', $meta_keys );
		$this->assertSame( 'without', $query['digitalogic_product_image_filter'] );

		$count_query = Digitalogic_Product_Query::build_wp_query_args( array( 'filters' => array( 'sku' => 'PI-' ) ), true );
		$this->assertSame( 1, $count_query['posts_per_page'] );
		$this->assertSame( 'ids', $count_query['fields'] );
		$this->assertFalse( $count_query['no_found_rows'] );
	}

	/**
	 * Every command transport delegates to one manager query operation.
	 */
	public function test_dispatcher_uses_one_shared_manager_query_contract(): void {
		$GLOBALS['digitalogic_test_product_query_result'] = array(
			'products'        => array( array( 'id' => 42 ) ),
			'total'           => 120,
			'recordsTotal'    => 120,
			'recordsFiltered' => 1,
			'page'            => 2,
			'limit'           => 25,
			'pages'           => 1,
		);
		$payload = array(
			'page'    => 2,
			'limit'   => 25,
			'filters' => array( 'sku' => 'SKU-42' ),
		);

		$result  = Digitalogic_Command_Dispatcher::instance()->get_products( $payload );
		$manager = Digitalogic_Product_Manager::instance();

		$this->assertSame( $GLOBALS['digitalogic_test_product_query_result'], $result );
		$this->assertCount( 1, $manager->query_requests );
		$this->assertSame( $payload, $manager->query_requests[0] );
	}

	/**
	 * Recursively collect meta keys from a nested meta query.
	 *
	 * @param array $query Meta query.
	 * @return array
	 */
	private function collectMetaKeys( $query ): array {
		$keys = array();
		foreach ( (array) $query as $key => $value ) {
			if ( 'key' === $key ) {
				$keys[] = $value;
			} elseif ( is_array( $value ) ) {
				$keys = array_merge( $keys, $this->collectMetaKeys( $value ) );
			}
		}

		return $keys;
	}
}
