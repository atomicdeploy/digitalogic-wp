<?php

use Digitalogic\Integrations\Paradox\CanonicalProductReport;
use Digitalogic\Integrations\Paradox\ReportArithmetic;
use Digitalogic\Pricing\Calculator;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/paradox/CanonicalProductReport.php';

final class ParadoxCanonicalProductReportTest extends TestCase {

	private function foreign( array $changes = array() ): array {
		return array_replace(
			array(
				'product_code'                   => 'EXACT-001',
				'name'                           => 'کالای نمونه',
				'unit'                           => 'عدد',
				'record_hash'                    => 'sha256:' . str_repeat( 'a', 64 ),
				'total_stock'                    => 5,
				'price_source_kind'              => 'foreign_price',
				'price_source_currency'          => 'CNY',
				'price_source_amount'            => '24.5',
				'foreign_price'                  => '24.5',
				'foreign_currency'               => 'CNY',
				'weight_grams'                   => '240',
				'shipping_method_id'             => 'air_express',
				'shipping_price_per_kg'          => '120',
				'shipping_price_per_kg_currency' => 'CNY',
				'markup_percent'                 => '30',
				'irt_per_cny'                    => '29000',
				'price_rounding_digits'          => 0,
				'price_rounding_mode'            => 'nearest_half_up',
			),
			$changes
		);
	}

	private function domestic( string $kind, string $amount, array $changes = array() ): array {
		return array_replace(
			array(
				'product_code'                   => 'EXACT-001',
				'name'                           => 'کالای داخلی',
				'unit'                           => 'عدد',
				'record_hash'                    => 'sha256:' . str_repeat( 'b', 64 ),
				'total_stock'                    => 5,
				'price_source_kind'              => $kind,
				'price_source_currency'          => 'IRR',
				'price_source_amount'            => $amount,
				'weight_grams'                   => '100',
				'shipping_method_id'             => 'domestic',
				'shipping_price_per_kg'          => '0',
				'shipping_price_per_kg_currency' => 'IRR',
			),
			$changes
		);
	}

	private function snapshot( array $products ): array {
		return array(
			'schema'         => 'patris.product-sync',
			'event_type'     => 'snapshot',
			'event_id'       => 'sha256:' . str_repeat( '1', 64 ),
			'local_currency' => 'IRT',
			'formula_id'     => 'landed_price',
			'source'         => array(
				'id'       => 'synthetic',
				'dataset'  => 'kala.db',
				'revision' => 'sha256:' . str_repeat( '2', 64 ),
			),
			'generated_at'   => '2026-09-07T00:00:00Z',
			'products'       => $products,
		);
	}

	private function report( array $row, array $woo = array() ): array {
		return CanonicalProductReport::analyze( $this->snapshot( array( $row ) ), $woo );
	}

	public function test_foreign_cny_and_irr_freight_match_reference_price(): void {
		foreach ( array(
			$this->foreign(),
			$this->foreign(
				array(
					'shipping_price_per_kg'          => '34800000',
					'shipping_price_per_kg_currency' => 'IRR',
				)
			),
		) as $row ) {
			$row['final_price'] = 2009410;
			self::assertSame( 2009410, $this->report( $row )['price_list'][0]['expected_final_price'] );
			self::assertSame( ( new Calculator() )->evaluate( $row ), CanonicalProductReport::evaluatePrice( $row ) );
		}
	}

	public function test_report_uses_selected_source_and_per_row_rounding(): void {
		$row                = $this->foreign(
			array(
				'irt_per_cny'           => '34000',
				'foreign_price'         => '999',
				'price_rounding_digits' => 2,
			)
		);
		$row['final_price'] = 2355900;
		$report             = $this->report( $row );
		self::assertSame( 2355900, $report['price_list'][0]['expected_final_price'] );
		self::assertNotContains( 'source_formula_price_drift', array_column( $report['warnings'], 'issue_code' ) );
		$row['final_price'] = 2355860;
		self::assertContains( 'source_formula_price_drift', array_column( $this->report( $row )['warnings'], 'issue_code' ) );
	}

	public function test_partner_price_needs_no_foreign_fields_and_rounds_half_up(): void {
		$row    = $this->domestic(
			'partner_price',
			'1234500',
			array(
				'markup_percent'        => '0',
				'price_rounding_digits' => 2,
				'price_rounding_mode'   => 'nearest_half_up',
				'final_price'           => 123500,
			)
		);
		$report = $this->report( $row );
		self::assertSame( 123500, $report['price_list'][0]['expected_final_price'] );
		self::assertSame( array( 'source_only_positive' ), array_column( $report['warnings'], 'issue_code' ) );
	}

	public function test_direct_sale_is_exact_and_does_not_use_markup_or_rounding(): void {
		$row = $this->domestic( 'sale_price_direct', '12340', array( 'final_price' => 1234 ) );
		self::assertSame( 1234, $this->report( $row )['price_list'][0]['expected_final_price'] );
		$row['price_source_amount'] = '12345';
		self::assertArrayNotHasKey( 'expected_final_price', $this->report( $row )['price_list'][0] );
		self::assertFalse( CanonicalProductReport::evaluatePrice( $row )['available'] );
	}

	public function test_missing_selected_source_does_not_reconstruct_a_foreign_price(): void {
		$row = $this->foreign();
		unset( $row['price_source_kind'], $row['price_source_amount'], $row['price_source_currency'] );
		$report = $this->report( $row );
		self::assertArrayNotHasKey( 'expected_final_price', $report['price_list'][0] );
		self::assertContains( 'source_missing_price_source_kind', array_column( $report['warnings'], 'issue_code' ) );
		self::assertFalse( method_exists( CanonicalProductReport::class, 'calculateFinalPrice' ) );
	}

	public function test_invalid_pricing_is_reported_without_trusting_supplied_final_price(): void {
		foreach ( array(
			array( 'price_source_currency' => 'USD' ),
			array( 'price_source_amount' => '1000000000000000' ),
			array( 'markup_percent' => '1001' ),
			array( 'weight_grams' => '1e2' ),
		) as $invalid ) {
			$row    = $this->foreign( $invalid + array( 'final_price' => 99 ) );
			$report = $this->report( $row );
			self::assertArrayNotHasKey( 'expected_final_price', $report['price_list'][0] );
			self::assertContains( 'source_invalid_pricing_input', array_column( $report['warnings'], 'issue_code' ) );
		}
	}

	public function test_missing_null_zero_are_preserved_without_mutating_snapshot(): void {
		$row      = $this->foreign(
			array(
				'weight_grams' => null,
				'total_stock'  => 0,
			)
		);
		$snapshot = $this->snapshot( array( $row ) );
		$before   = $snapshot;
		$report   = CanonicalProductReport::analyze( $snapshot, array() );
		self::assertSame( $before, $snapshot );
		self::assertSame( array( 'kind' => 'missing' ), CanonicalProductReport::fieldState( array(), 'weight_grams' ) );
		self::assertSame(
			array(
				'kind'  => 'null',
				'value' => null,
			),
			$report['price_list'][0]['weight_grams']
		);
		self::assertSame(
			array(
				'kind'  => 'value',
				'value' => 0,
			),
			CanonicalProductReport::fieldState( $row, 'total_stock' )
		);
		self::assertArrayNotHasKey( 'expected_final_price', $report['price_list'][0] );
	}

	public function test_duplicate_identity_and_variable_parent_handling_remain_explicit(): void {
		$row    = $this->foreign();
		$report = $this->report(
			$row,
			array(
				array(
					'woo_id'             => 10,
					'product_code'       => 'EXACT-001',
					'is_variable_parent' => true,
				),
				array(
					'woo_id'       => 11,
					'product_code' => 'EXACT-001',
				),
				array(
					'woo_id'       => 12,
					'product_code' => 'EXACT-001',
					'post_type'    => 'product_variation',
				),
			)
		);
		self::assertSame( 1, $report['summary']['excluded_variable_parents'] );
		self::assertSame( 2, $report['summary']['woocommerce_products'] );
		self::assertSame( 1, $report['summary']['duplicate_match_codes'] );
		$this->expectException( InvalidArgumentException::class );
		CanonicalProductReport::analyze( $this->snapshot( array( $row, $row ) ), array() );
	}

	public function test_report_arithmetic_retains_exact_large_difference_and_scale(): void {
		$math = new ReportArithmetic();
		self::assertSame( '999999999999999999999999', $math->subtract( '1000000000000000000000000', '1' ) );
		self::assertSame(
			0,
			$math->compare(
				array(
					'digits' => '123400',
					'scale'  => 2,
				),
				array(
					'digits' => '1234',
					'scale'  => 0,
				)
			)
		);
		$row    = $this->domestic( 'sale_price_direct', '12340', array( 'final_price' => 1234 ) );
		$report = $this->report(
			$row,
			array(
				array(
					'woo_id'           => 11,
					'product_code'     => 'EXACT-001',
					'price'            => '12345',
					'price_present'    => 1,
					'store_currency'   => 'IRR',
					'actual_price_irt' => '1234.5',
				),
			)
		);
		self::assertSame( '0.5', $report['price_list'][0]['woo_prices'][0]['difference_irt'] );
	}

	public function test_persian_html_escapes_source_content_and_shows_selected_route(): void {
		$row  = $this->domestic(
			'sale_price_direct',
			'12340',
			array(
				'name'        => '<script>alert(1)</script>',
				'final_price' => 1234,
			)
		);
		$html = CanonicalProductReport::renderHtml( $this->report( $row ) );
		self::assertStringContainsString( 'sale_price_direct', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringContainsString( 'data:font/woff2;base64,', $html );
	}

	public function test_private_snapshot_verification_and_report_output_remain_bound_to_exact_bytes(): void {
		$runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'digitalogic-paradox-test-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $runtime, 0700 ) );
		$input  = $runtime . DIRECTORY_SEPARATOR . 'snapshot.json';
		$output = $runtime . DIRECTORY_SEPARATOR . 'report.html';
		$raw    = json_encode( $this->snapshot( array( $this->foreign() ) ), JSON_THROW_ON_ERROR );
		file_put_contents( $input, $raw );
		$seen     = null;
		$verifier = static function ( string $bytes ) use ( &$seen ): array {
			$seen = $bytes;
			return array( 'identity_verifier' => 'synthetic exact-byte verifier' );
		};
		try {
			$loaded = CanonicalProductReport::loadSnapshotFile(
				$input,
				dirname( __DIR__ ),
				$runtime,
				'synthetic',
				'kala.db',
				$verifier
			);
			self::assertSame( $raw, $seen );
			self::assertSame( 'sha256:' . hash( 'sha256', $raw ), $loaded['provenance']['snapshot_sha256'] );
			$html = CanonicalProductReport::renderHtml( CanonicalProductReport::analyze( $loaded['document'], array() ) );
			self::assertSame( $output, CanonicalProductReport::writeHtml( $output, $html, dirname( __DIR__ ), $runtime ) );
			self::assertSame( $html, file_get_contents( $output ) );
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( 'exists' );
			CanonicalProductReport::writeHtml( $output, $html, dirname( __DIR__ ), $runtime );
		} finally {
			if ( is_file( $output ) ) {
				unlink( $output );
			}
			if ( is_file( $input ) ) {
				unlink( $input );
			}
			rmdir( $runtime );
		}
	}

	public function test_report_rejects_webroot_input_and_wrong_source_scope(): void {
		try {
			CanonicalProductReport::assertSnapshotScope( $this->snapshot( array() ), 'other-source', 'kala.db' );
			self::fail( 'Wrong source scope was accepted.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertStringContainsString( 'scope', $error->getMessage() );
		}
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'outside' );
		CanonicalProductReport::loadSnapshotFile(
			__FILE__,
			dirname( __DIR__ ),
			sys_get_temp_dir(),
			'synthetic',
			'kala.db',
			static fn ( string $raw ): array => array( 'identity_verifier' => 'unused' )
		);
	}
}
