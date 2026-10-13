<?php
/**
 * Private research grouping, decimal, timestamp and price comparison contracts.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-digitalogic-product-research.php';

/** Keep presentation changes independent of stored evidence and commercial writes. */
final class ProductResearchPresentationTest extends TestCase {

	/** Render complete source groups with working labels, money, dates and access guards. */
	public function test_complete_admin_table_renders_every_offer_and_rejects_anonymous_access(): void {
		$caps = $GLOBALS['digitalogic_test_capabilities'] ?? array();
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$GLOBALS['digitalogic_test_capabilities']['edit_post']      = true;
		$GLOBALS['digitalogic_test_posts'][9907]                    = array(
			'post_type' => 'product',
			'meta'      => array(
				'_price' => '994000',
				'_sku'   => 'SYNTHETIC-9907',
			),
		);
		$d      = array(
			'title'                => 'Synthetic offer',
			'kind'                 => 'seller',
			'match'                => 'candidate',
			'offer_units'          => 1,
			'variation_id'         => 0,
			'identity_evidence_fa' => 'Synthetic evidence',
			'summary_fa'           => 'Synthetic summary',
			'specifications_fa'    => '',
			'description_fa'       => 'Synthetic summary',
			'comparison_fa'        => '',
			'price_note_fa'        => '',
			'availability_note_fa' => '',
			'attachments'          => array(),
		);
		$row    = array(
			'id'               => 1,
			'source_id'        => 2,
			'url'              => 'https://example.com/a',
			'domain'           => 'example.com',
			'profile'          => array(
				'title'          => 'Synthetic storefront',
				'storefront_url' => 'https://example.com/',
			),
			'price'            => '966000.00000000',
			'currency'         => 'IRT',
			'observed_at'      => '2026-10-13 04:44:29',
			'identity_current' => true,
			'availability'     => 'in_stock',
			'details'          => $d,
		);
		$second = array_merge(
			$row,
			array(
				'id'    => 3,
				'url'   => 'https://example.com/b',
				'price' => '1019000.00000000',
			)
		);
		$data   = array(
			'coverage' => null,
			'listings' => Digitalogic_Product_Research_Presentation::decorate( 9907, array( $row, $second ) ),
		);
		try {
			ob_start();
			Digitalogic_Product_Research_Presentation::render( 9907, $data );
			$html = ob_get_clean();
			$this->assertSame( 2, substr_count( $html, 'data-listing-id=' ) );
			$this->assertSame( 1, substr_count( $html, 'data-source-id=' ) );
			$this->assertSame( 1, substr_count( $html, 'Synthetic storefront' ) );
			$this->assertSame( 2, substr_count( $html, 'Synthetic summary' ) );
			$this->assertStringContainsString( 'data-comparison="lower"', $html );
			$this->assertStringContainsString( 'data-comparison="higher"', $html );
			$this->assertStringContainsString( 'datetime="2026-10-13T04:44:29Z"', $html );
			$this->assertStringNotContainsString( '966000.00000000', $html );
			$this->assertStringEndsWith( '</table></div></div>', $html );
			$GLOBALS['digitalogic_test_capabilities']['manage_options'] = false;
			ob_start();
			Digitalogic_Product_Research_Presentation::render( 9907, $data );
			$this->assertSame( '', ob_get_clean() );
		} finally {
			$GLOBALS['digitalogic_test_capabilities'] = $caps;
			unset( $GLOBALS['digitalogic_test_posts'][9907], $GLOBALS['digitalogic_test_wc_products'][9907] );
		}
	}

	/** Reject invalid quantity types before touching shared sources or database rows. */
	public function test_offer_quantity_validation_precedes_database_writes(): void {
		$save = new ReflectionMethod( Digitalogic_Product_Research::class, 'save_result' );
		foreach ( array( '1', 0, -1, 1000001 ) as $invalid ) {
			try {
				$save->invoke( null, 10473, array( 'offer_units' => $invalid ) );
				$this->fail( 'Invalid quantity was accepted.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( 'Observed offer_units must be a positive integer or null.', $error->getMessage() );
			}
		}
	}
	/** Whole numbers trim padding; genuine fractions and large values stay exact. */
	public function test_decimal_display_preserves_precision_and_zero(): void {
		$this->assertSame( '۱٬۰۱۹٬۰۰۰', Digitalogic_Product_Research_Presentation::format_amount( '1019000.00000000' ) );
		$this->assertSame( '۲۹٫۳', Digitalogic_Product_Research_Presentation::format_amount( '29.30000000' ) );
		$this->assertSame( '۰', Digitalogic_Product_Research_Presentation::format_amount( '0.00000000' ) );
		$this->assertSame( '9999999999999999.12345678', Digitalogic_Product_Research_Presentation::amount( '9999999999999999.12345678' ) );
		$this->assertNull( Digitalogic_Product_Research_Presentation::amount( null ) );
	}

	/** Dates expose integer UTC time and semantic ISO markup, not guessed epoch zero. */
	public function test_timestamps_are_machine_readable_and_strict(): void {
		$t = Digitalogic_Product_Research_Presentation::time_value( '2026-10-13 04:44:29' );
		$this->assertSame( '2026-10-13T04:44:29Z', $t['iso8601'] );
		$this->assertIsInt( $t['unix'] );
		$this->assertStringContainsString( 'datetime="2026-10-13T04:44:29Z"', Digitalogic_Product_Research_Presentation::time_html( '2026-10-13 04:44:29' ) );
		$this->assertNull( Digitalogic_Product_Research_Presentation::time_value( '2026-02-30 00:00:00' ) );
		$this->assertNull( Digitalogic_Product_Research_Presentation::time_value( null ) );
	}

	/** Exact differences govern colors; nulls, foreign currency and ambiguous models stay neutral. */
	public function test_comparison_uses_structured_values_and_guards(): void {
		$own   = array(
			'product_id' => 10473,
			'amount'     => '994000',
			'currency'   => 'IRT',
		);
		$ctx   = array(
			'identity_current' => true,
			'match'            => 'candidate',
		);
		$offer = array(
			'amount'   => '966000.00000000',
			'currency' => 'IRT',
			'units'    => 1,
		);
		$c     = Digitalogic_Product_Research_Presentation::compare( $offer, $own, $ctx );
		$this->assertSame( 'lower', $c['state'] );
		$this->assertSame( '28000', $c['difference'] );
		$this->assertSame( 2.82, $c['percent'] );
		$offer['amount'] = '1019000';
		$this->assertSame( 'higher', Digitalogic_Product_Research_Presentation::compare( $offer, $own, $ctx )['state'] );
		$offer['amount'] = '994000.00000000';
		$this->assertSame( 'equal', Digitalogic_Product_Research_Presentation::compare( $offer, $own, $ctx )['state'] );
		foreach ( array( array( 'amount' => null ), array( 'currency' => 'IRR' ), array( 'units' => null ), array( 'units' => 10 ) ) as $patch ) {
			$this->assertSame( 'neutral', Digitalogic_Product_Research_Presentation::compare( array_merge( $offer, $patch ), $own, $ctx )['state'] );
		}
		$this->assertSame(
			'neutral',
			Digitalogic_Product_Research_Presentation::compare(
				$offer,
				$own,
				array(
					'identity_current' => false,
					'match'            => 'candidate',
				)
			)['state']
		);
		$this->assertSame(
			'neutral',
			Digitalogic_Product_Research_Presentation::compare(
				$offer,
				$own,
				array(
					'identity_current' => true,
					'match'            => 'different_variant',
				)
			)['state']
		);
		$own['amount']   = '9999999999999999.12345677';
		$offer['amount'] = '9999999999999999.12345678';
		$c               = Digitalogic_Product_Research_Presentation::compare( $offer, $own, $ctx );
		$this->assertSame( 'higher', $c['state'] );
		$this->assertSame( '0.00000001', $c['difference'] );
	}

	/** Multiple offers share one source profile, without losing or merging any offer. */
	public function test_source_grouping_preserves_independent_listings(): void {
		$rows   = array(
			array(
				'id'        => 17,
				'source_id' => 18,
			),
			array(
				'id'        => 18,
				'source_id' => 19,
			),
			array(
				'id'        => 85,
				'source_id' => 18,
			),
		);
		$groups = Digitalogic_Product_Research_Presentation::groups( $rows );
		$this->assertCount( 2, $groups );
		$this->assertSame( array( 17, 85 ), array_column( $groups[18], 'id' ) );
	}

	/** An unspecified variable parent cannot supply its aggregate minimum as a unit reference. */
	public function test_variable_parent_uses_only_an_exact_child(): void {
		$GLOBALS['digitalogic_test_posts'][9905] = array(
			'post_type'    => 'product',
			'product_type' => 'variable',
			'meta'         => array( '_price' => '100' ),
		);
		$GLOBALS['digitalogic_test_posts'][9906] = array(
			'post_type'   => 'product_variation',
			'post_parent' => 9905,
			'meta'        => array(
				'_price' => '200',
				'_sku'   => 'CHILD-9906',
			),
		);
		try {
			$this->assertNull( Digitalogic_Product_Research_Presentation::current_price( 9905 )['amount'] );
			$this->assertSame( '200', Digitalogic_Product_Research_Presentation::current_price( 9905, 9906 )['amount'] );
			$this->assertNull( Digitalogic_Product_Research_Presentation::current_price( 9906, 9905 )['amount'] );
		} finally {
			unset( $GLOBALS['digitalogic_test_posts'][9905], $GLOBALS['digitalogic_test_posts'][9906], $GLOBALS['digitalogic_test_wc_products'][9905], $GLOBALS['digitalogic_test_wc_products'][9906] );
		}
	}
}
