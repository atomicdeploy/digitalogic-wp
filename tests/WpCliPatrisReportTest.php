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
			$GLOBALS['wpdb']                              = new Digitalogic_Test_WPDB();
			WP_CLI::$errors                               = array();
			WP_CLI::$logs                                 = array();

			$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
			$this->reset_singleton( Digitalogic_Report_Engine::class );
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
