<?php
/**
 * Provider-neutral pricing adapter contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify adapter isolation, semantic normalization, and bounded fallback. */
final class PricingAdapterContractTest extends TestCase {

	/** Restore the default adapter registry after every contract test. */
	protected function tearDown(): void {
		Digitalogic_Pricing_Adapter_Registry::reset();
		parent::tearDown();
	}

	/** Provider extensions and optional metadata do not change canonical identity. */
	public function test_source_normalization_tolerates_extensions_and_optional_revision(): void {
		$revision = 'sha256:' . str_repeat( 'a', 64 );
		$source   = Digitalogic_Pricing_Canonical_Model::source(
			array(
				'dataset'      => 'kala',
				'id'           => 'office-provider',
				'revision'     => $revision,
				'page_size'    => 73,
				'provider'     => array( 'cursor' => 'opaque' ),
				'capabilities' => array( 'events' => false ),
			)
		);

		$this->assertSame(
			array(
				'id'       => 'office-provider',
				'dataset'  => 'kala',
				'revision' => $revision,
			),
			$source
		);
		$this->assertSame(
			array(
				'id'       => 'office-provider',
				'dataset'  => 'kala',
				'revision' => '',
			),
			Digitalogic_Pricing_Canonical_Model::source(
				array(
					'id'       => 'office-provider',
					'dataset'  => 'kala',
					'metadata' => array( 'schema' => 'provider.descriptive-label' ),
				)
			)
		);
	}

	/** Unsafe identity remains a blocking error with a specific recovery action. */
	public function test_unsafe_source_identity_is_blocking(): void {
		$result = Digitalogic_Pricing_Canonical_Model::source(
			array(
				'id'      => array( 'ambiguous', 'identity' ),
				'dataset' => 'kala',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 'error', $data['severity'] );
		$this->assertTrue( $data['blocking'] );
		$this->assertFalse( $data['retryable'] );
		$this->assertSame( 'correct_source_id', $data['recovery_action'] );
		$this->assertNotSame( '', $data['reason'] );
	}

	/** Semantic hashing ignores representation order, numeric form, date offset, and Unicode form. */
	public function test_semantic_digest_is_deterministic_across_safe_representations(): void {
		$name_nfc = "\u{0622}\u{06CC}\u{200C}\u{0633}\u{06CC}";
		$name_nfd = class_exists( 'Normalizer' )
			? Normalizer::normalize( $name_nfc, Normalizer::FORM_D )
			: $name_nfc;
		$left     = array(
			'dollar_price'   => 187891,
			'effective_date' => '2026-08-30T03:30:00+03:30',
			'name'           => $name_nfc,
			'nested'         => array(
				'profit_margin_percent' => '30.00',
				'enabled'               => true,
			),
		);
		$right    = array(
			'nested'         => array(
				'enabled'               => true,
				'profit_margin_percent' => 30,
			),
			'name'           => $name_nfd,
			'effective_date' => '2026-08-30T00:00:00Z',
			'dollar_price'   => '187891.0',
		);

		$this->assertSame(
			Digitalogic_Pricing_Canonical_Model::digest( $left ),
			Digitalogic_Pricing_Canonical_Model::digest( $right )
		);
	}

	/** Missing capabilities degrade to finite polling rather than negotiation failure. */
	public function test_capability_intersection_builds_finite_fallback(): void {
		$capabilities = Digitalogic_Pricing_Capabilities::negotiate(
			array(
				'revision'            => true,
				'conditional_request' => true,
				'events'              => true,
				'digest_algorithms'   => array( 'sha256' ),
			),
			array(
				'revision'            => true,
				'conditional_request' => true,
				'digest_algorithms'   => array( 'other' ),
			)
		);

		$this->assertTrue( $capabilities['revision'] );
		$this->assertTrue( $capabilities['conditional_request'] );
		$this->assertFalse( $capabilities['events'] );
		$this->assertFalse( $capabilities['etag'] );
		$this->assertSame( array(), $capabilities['digest_algorithms'] );
		$this->assertSame( array( 'conditional_request', 'polling' ), $capabilities['recovery_order'] );
		$this->assertSame( 5, $capabilities['polling']['max_attempts'] );
		$this->assertLessThanOrEqual( 30000, $capabilities['polling']['max_elapsed_ms'] );
	}

	/** The consumer owns column selection and safely ignores provider/store extensions. */
	public function test_consumer_projection_is_stable_across_order_and_extra_fields(): void {
		$fields      = array(
			'sync_key',
			'reconciliation_status',
			'patris_code',
			'woocommerce_id',
			'sku',
			'weight_grams',
			'foreign_price',
			'patris_location',
			'categories',
			'foreign_currency',
			'shipping_price_per_kg',
			'shipping_price_per_kg_currency',
			'profit_margin_percent',
			'price_source_amount',
			'price_source_currency',
			'price_source_kind',
			'effective_price',
			'patris_total_stock',
			'stock_quantity',
			'name',
			'updated_at',
			'record_revision',
			'permalink',
			'patris_final_price',
			'sale_price',
			'publication_status',
		);
		$column_keys = array_reverse( array_merge( $fields, array( 'provider_extension' ) ) );
		$columns     = array_map(
			static fn( $field ) => array(
				'key'   => $field,
				'label' => $field,
			),
			$column_keys
		);
		$row         = array_fill_keys( $column_keys, 'value' );
		$projected   = ( new Digitalogic_Excel_Pricing_Consumer_Adapter() )->project_catalog(
			array(
				'columns'           => $columns,
				'rows'              => array( $row ),
				'provider_metadata' => true,
			)
		);

		$this->assertSame( $fields, array_column( $projected['columns'], 'key' ) );
		$this->assertSame( $fields, array_keys( $projected['rows'][0] ) );
		$this->assertArrayNotHasKey( 'provider_extension', $projected['rows'][0] );
		$this->assertTrue( $projected['provider_metadata'] );
	}

	/** Core orchestration has no concrete provider, report, store, or consumer mapping calls. */
	public function test_core_orchestration_depends_only_on_adapter_registry(): void {
		$core = file_get_contents( dirname( __DIR__ ) . '/includes/class-digitalogic-pricing-snapshot.php' )
			. file_get_contents( dirname( __DIR__ ) . '/includes/class-digitalogic-excel-pricing-sync.php' );
		foreach (
			array(
				'Digitalogic_Patris_Feed',
				'Digitalogic_Product_Sync_Receiver',
				'Digitalogic_Report_Engine',
				'Digitalogic_Google_Sheets_Catalog',
				'Digitalogic_Pricing_Coordinator',
				'PROJECTION_FIELDS',
			) as $concrete_dependency
		) {
			$this->assertStringNotContainsString( $concrete_dependency, $core );
		}
		$this->assertStringContainsString( 'Digitalogic_Pricing_Adapter_Registry', $core );
	}
}
