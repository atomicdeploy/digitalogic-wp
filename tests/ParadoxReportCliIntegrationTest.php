<?php

use PHPUnit\Framework\TestCase;

final class ParadoxReportCliIntegrationTest extends TestCase {

	protected function setUp(): void {
		foreach ( array(
			'digitalogic_test_options',
			'digitalogic_test_option_cache',
			'digitalogic_test_posts',
			'digitalogic_test_wc_products',
			'digitalogic_test_wc_lookup_rows',
			'digitalogic_test_wc_product_query_args',
		) as $key ) {
			$GLOBALS[ $key ] = array();
		}
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		WP_CLI::$errors  = array();
		WP_CLI::$logs    = array();
		foreach ( array( Digitalogic_Product_Sync_Receiver::class, Digitalogic_Report_Engine::class ) as $class ) {
			( new ReflectionProperty( $class, 'instance' ) )->setValue( null, null );
		}
	}

	public function test_existing_report_command_renders_all_selected_rows_with_shared_prices(): void {
		$base     = array(
			'unit'                           => 'عدد',
			'total_stock'                    => 1,
			'price_source_currency'          => 'IRR',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => '0',
			'shipping_price_per_kg_currency' => 'IRR',
			'warnings'                       => array(),
		);
		$products = array(
			'DIRECT-1'  => $base + array(
				'product_code'        => 'DIRECT-1',
				'name'                => '<script>unsafe</script>',
				'price_source_kind'   => 'sale_price_direct',
				'price_source_amount' => '12340',
				'final_price'         => 999,
			),
			'PARTNER-1' => $base + array(
				'product_code'          => 'PARTNER-1',
				'name'                  => 'همکار',
				'price_source_kind'     => 'partner_price',
				'price_source_amount'   => '1234500',
				'final_price'           => 123500,
				'markup_percent'        => 0,
				'price_rounding_digits' => 2,
				'price_rounding_mode'   => 'nearest_half_up',
			),
		);
		$state    = array(
			'sources' => array(
				'selected-source' => array(
					'source'          => array(
						'id'       => 'cli-canonical',
						'dataset'  => 'kala.db',
						'revision' => 'sha256:' . str_repeat( 'a', 64 ),
					),
					'generated_at'    => gmdate( 'c' ),
					'last_event_id'   => 'sha256:' . str_repeat( 'b', 64 ),
					'last_event_type' => 'delta',
					'products'        => $products,
				),
			),
		);
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		( new Digitalogic_CLI_Commands() )->patris_report(
			array(),
			array(
				'view'      => 'price_list',
				'format'    => 'html',
				'per-page'  => 1,
				'source-id' => 'cli-canonical',
				'dataset'   => 'kala.db',
			)
		);
		self::assertSame( array(), WP_CLI::$errors );
		self::assertCount( 1, WP_CLI::$logs );
		$html = WP_CLI::$logs[0];
		self::assertStringContainsString( '<!doctype html>', $html );
		self::assertStringContainsString( 'DIRECT-1', $html );
		self::assertStringContainsString( 'PARTNER-1', $html );
		self::assertStringContainsString( '1,234', $html );
		self::assertStringContainsString( '123,500', $html );
		self::assertStringContainsString( '999', $html );
		self::assertStringContainsString( 'delta', $html );
		self::assertStringContainsString( 'cli-canonical', $html );
		self::assertStringContainsString( '&lt;script&gt;unsafe&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script>unsafe</script>', $html );
		self::assertSame( $state, get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION ) );
		self::assertSame( array(), $GLOBALS['digitalogic_test_posts'] );
	}

	public function test_html_uses_existing_report_argument_validation(): void {
		( new Digitalogic_CLI_Commands() )->patris_report(
			array(),
			array(
				'format'   => 'html',
				'category' => 'not-a-report-category',
			)
		);
		self::assertNotEmpty( WP_CLI::$errors );
		self::assertSame( array(), WP_CLI::$logs );
	}
}
