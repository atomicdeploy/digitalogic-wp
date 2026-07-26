<?php
/**
 * WP-CLI report transport tests.
 *
 * @package Digitalogic
 */

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		/** Capture table/CSV rows in the lightweight test bootstrap. */
		function format_items( $format, $items, $fields ) {
			$GLOBALS['digitalogic_test_cli_format_items'][] = array(
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			);
		}
	}
}

namespace {
	use PHPUnit\Framework\TestCase;

	/** Ensure price-list formats emit rows rather than category summaries. */
	final class WpCliPatrisReportTest extends TestCase {

		/** Reset receiver and output state. */
		protected function setUp(): void {
			$GLOBALS['digitalogic_test_options']          = array();
			$GLOBALS['digitalogic_test_option_cache']     = array();
			$GLOBALS['digitalogic_test_posts']            = array();
			$GLOBALS['digitalogic_test_cli_format_items'] = array();
			$GLOBALS['digitalogic_test_wp_query_args']    = array();
			$GLOBALS['digitalogic_test_wp_query_results'] = array();
			$GLOBALS['digitalogic_test_primed_post_ids']  = array();
			$GLOBALS['digitalogic_test_wc_products']      = array();
			$GLOBALS['digitalogic_test_wc_lookup_rows']   = array();
			$GLOBALS['wpdb']                              = new Digitalogic_Test_WPDB();
			WP_CLI::$errors                               = array();
			WP_CLI::$logs                                 = array();

			$this->reset_singleton( Digitalogic_Product_Manager::class );
			$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
			$this->reset_singleton( Digitalogic_Report_Engine::class );
		}

		/** Product list keeps the exact integration Code separate from SKU. */
		public function test_product_list_outputs_exact_product_code_and_separate_sku(): void {
			$GLOBALS['digitalogic_test_posts']            = array(
				1601 => array(
					'post_type'    => 'product',
					'post_status'  => 'publish',
					'post_title'   => 'Exact Code Product',
					'product_type' => 'simple',
					'meta'         => array(
						'_sku'                             => 'SKU-001',
						'_digitalogic_patris_product_code' => '000123',
						'_price'                           => '125000',
						'_regular_price'                   => '125000',
						'_stock'                           => '4',
						'_manage_stock'                    => 'yes',
						'_stock_status'                    => 'instock',
					),
				),
				1602 => array(
					'post_type'    => 'product',
					'post_status'  => 'publish',
					'post_title'   => 'SKU-only Product',
					'product_type' => 'simple',
					'meta'         => array(
						'_sku'           => 'SKU-ONLY',
						'_price'         => '',
						'_regular_price' => '',
						'_stock_status'  => 'instock',
					),
				),
			);
			$GLOBALS['digitalogic_test_wp_query_results'] = array(
				array(
					'posts'       => array( 1601, 1602 ),
					'found_posts' => 2,
				),
			);

			( new Digitalogic_CLI_Commands() )->products_list(
				array(),
				array(
					'limit'  => 20,
					'format' => 'json',
				)
			);

			$this->assertSame( array(), WP_CLI::$errors );
			$this->assertCount( 1, $GLOBALS['digitalogic_test_cli_format_items'] );
			$formatted = $GLOBALS['digitalogic_test_cli_format_items'][0];
			$this->assertSame( array( 'ID', 'Name', 'Product Code', 'SKU', 'Price', 'Stock' ), $formatted['fields'] );
			$this->assertSame( '000123', $formatted['items'][0]['Product Code'] );
			$this->assertSame( 'SKU-001', $formatted['items'][0]['SKU'] );
			$this->assertSame( '', $formatted['items'][1]['Product Code'] );
			$this->assertSame( 'SKU-ONLY', $formatted['items'][1]['SKU'] );
		}

		/** Table output contains the selected price-list row fields. */
		public function test_price_list_table_outputs_product_rows(): void {
			update_option(
				Digitalogic_Product_Sync_Receiver::STATE_OPTION,
				array(
					'sources' => array(
						'cli-source' => array(
							'source'       => array(
								'id'      => 'patris-export',
								'dataset' => 'ALLANBAR',
							),
							'generated_at' => gmdate( 'c' ),
							'products'     => array(
								'CLI-1' => array(
									'product_code'  => 'CLI-1',
									'foreign_price' => '25',
									'weight_grams'  => '500',
									'total_stock'   => 3,
									'final_price'   => 900000,
									'warnings'      => array(),
								),
							),
						),
					),
				),
				false
			);

			$command = new Digitalogic_CLI_Commands();
			$command->patris_report(
				array(),
				array(
					'view'   => 'price_list',
					'format' => 'table',
				)
			);

			$this->assertSame( array(), WP_CLI::$errors );
			$this->assertCount( 1, $GLOBALS['digitalogic_test_cli_format_items'] );
			$formatted = $GLOBALS['digitalogic_test_cli_format_items'][0];
			$this->assertSame( 'table', $formatted['format'] );
			$this->assertSame( 'CLI-1', $formatted['items'][0]['Code'] );
			$this->assertSame( '900000', $formatted['items'][0]['Source price'] );
			$this->assertSame( '[missing]', $formatted['items'][0]['Woo active price'] );
			$this->assertArrayNotHasKey( 'Key', $formatted['items'][0] );
		}

		/** Reset one singleton with a private static instance property. */
		private function reset_singleton( $class_name ): void {
			$property = new \ReflectionProperty( $class_name, 'instance' );
			$property->setValue( null, null );
		}
	}
}
