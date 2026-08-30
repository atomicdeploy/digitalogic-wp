<?php
/**
 * Excel pricing-sync contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies scoped authentication, optimistic concurrency, preview binding,
 * atomic settings writes, readback, rollback, and Persian catalog state.
 */
final class ExcelPricingSyncTest extends TestCase {

	/**
	 * Exact source used by the local Patris companion.
	 *
	 * @var array
	 */
	private $source;

	/**
	 * Prepare isolated global pricing and source state.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->source = array(
			'id'       => 'patris-export',
			'dataset'  => 'kala',
			'revision' => 'sha256:' . str_repeat( 'a', 64 ),
		);
		$source_key   = hash( 'sha256', $this->source['id'] . "\n" . $this->source['dataset'] );
		$markup       = $this->default_markup_state( '30' );

		$GLOBALS['digitalogic_test_capabilities']         = array();
		$GLOBALS['digitalogic_test_filters']              = array();
		$GLOBALS['digitalogic_test_routes']               = array();
		$GLOBALS['digitalogic_test_options']              = array(
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION => 'receiver-secret',
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION => array(
				array(
					'id'      => $this->source['id'],
					'dataset' => $this->source['dataset'],
				),
			),
			Digitalogic_Product_Sync_Receiver::STATE_OPTION => array(
				'sources' => array(
					$source_key => array(
						'source'            => $this->source,
						'products'          => array(),
						'categories'        => array(),
						'excluded_codes'    => array(),
						'quarantined_codes' => array(),
						'applied_products'  => array(),
						'pending_products'  => array(),
						'deferred_products' => array(),
					),
				),
			),
			'dollar_price'            => '187891',
			'options_dollar_price'    => '187891',
			'yuan_price'              => '29500',
			'options_yuan_price'      => '29500',
			'update_date'             => '260629',
			'options_update_date'     => '260629',
			Digitalogic_Shipping_Method_Service::METHODS_OPTION => array(
				'air_express' => array(
					'id'           => 'air_express',
					'name'         => 'Air (Express)',
					'enabled'      => true,
					'currency'     => 'CNY',
					'price_per_kg' => '120',
				),
			),
			Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION => $markup,
			Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION => 0,
			'digitalogic_shipping_currency_migration_complete' => 'complete',
			'woocommerce_weight_unit' => 'kg',
		);
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_actions']              = array();
		$GLOBALS['digitalogic_test_action_callbacks']     = array();
		$GLOBALS['digitalogic_test_update_failures']      = array();
		$GLOBALS['digitalogic_test_transaction_failures'] = array();
		$GLOBALS['digitalogic_test_cache_deletes']        = array();
		$GLOBALS['digitalogic_test_transients']           = array();
		$GLOBALS['digitalogic_test_transient_deletes']    = array();
		$GLOBALS['digitalogic_test_scheduled_events']     = array();
		$GLOBALS['digitalogic_test_action_scheduler_actions'] = array();
		$GLOBALS['digitalogic_test_action_scheduler_next_id'] = 1;
		$GLOBALS['digitalogic_test_action_scheduler_available'] = true;
		$GLOBALS['digitalogic_test_posts']                = array();
		$GLOBALS['digitalogic_test_wc_products']          = array();
		$GLOBALS['digitalogic_test_wp_query_results']     = array();
		$GLOBALS['digitalogic_test_wp_query_args']        = array();
		$GLOBALS['digitalogic_test_wc_currency']          = 'IRT';
		$GLOBALS['digitalogic_test_current_user_id']      = 0;
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();

		foreach (
			array(
				Digitalogic_Excel_Pricing_Sync::class,
				Digitalogic_REST_API::class,
				Digitalogic_Patris_Feed::class,
				Digitalogic_Product_Sync_Receiver::class,
				Digitalogic_Shipping_Method_Service::class,
				Digitalogic_Google_Sheets_Catalog::class,
				Digitalogic_Product_Manager::class,
			) as $class_name
		) {
			$this->reset_singleton( $class_name );
		}
	}

	/** Restore Action Scheduler availability for unrelated test classes. */
	protected function tearDown(): void {
		$GLOBALS['digitalogic_test_action_scheduler_available'] = false;
		parent::tearDown();
	}

	/**
	 * Verify exact POST routes and fail-closed scoped-secret authentication.
	 */
	public function test_routes_and_machine_scope_are_exact(): void {
		$api = Digitalogic_REST_API::instance();
		$api->register_routes();
		$routes = array();
		foreach ( $GLOBALS['digitalogic_test_routes'] as $route ) {
			$routes[ $route['namespace'] . $route['route'] ] = $route['args'];
		}

		foreach ( array( 'state', 'preview', 'apply' ) as $mode ) {
			$universal_key = 'digitalogic/pricing/sync/' . $mode;
			$this->assertArrayHasKey( $universal_key, $routes );
			$this->assertSame( 'POST', $routes[ $universal_key ]['methods'] );
			$this->assertSame( array( $api, 'pricing_sync_' . $mode ), $routes[ $universal_key ]['callback'] );
			$this->assertSame( array( $api, 'check_pricing_sync_permission' ), $routes[ $universal_key ]['permission_callback'] );
			$this->assertArrayNotHasKey( 'digitalogic/excel/pricing-sync/' . $mode, $routes );
		}
		$this->assertSame( 'POST', $routes['digitalogic/pricing/sync/ack']['methods'] );
		$this->assertSame( array( $api, 'pricing_sync_ack' ), $routes['digitalogic/pricing/sync/ack']['callback'] );
		$this->assertSame( array( $api, 'check_pricing_sync_permission' ), $routes['digitalogic/pricing/sync/ack']['permission_callback'] );
		$this->assertArrayNotHasKey( 'digitalogic/excel/pricing-sync/ack', $routes );
		$this->assertSame(
			array( $api, 'get_profit_margin' ),
			$routes['digitalogic/pricing/profit-margin'][0]['callback']
		);
		$this->assertSame(
			array( $api, 'update_profit_margin' ),
			$routes['digitalogic/pricing/profit-margin'][1]['callback']
		);

		$request = $this->request(
			'state',
			array(),
			array( 'X-Patris-Product-Sync-Secret' => 'receiver-secret' )
		);
		$this->assertTrue( $api->check_pricing_sync_permission( $request ) );

		$missing_secret = $this->request( 'state' );
		$denied         = $api->check_pricing_sync_permission( $missing_secret );
		$this->assertSame( 'digitalogic_excel_sync_unauthorized', $denied->get_error_code() );

		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array();
		$GLOBALS['digitalogic_test_option_cache'] = array();
		$unscoped                                 = $api->check_pricing_sync_permission( $request );
		$this->assertSame( 'digitalogic_excel_sync_scope_required', $unscoped->get_error_code() );
	}

	/**
	 * Verify the requested stable state shape and Persian paged catalog.
	 */
	public function test_state_is_living_persian_and_reports_staleness(): void {
		$request  = $this->request(
			'state',
			array(
				'page'   => 1,
				'limit'  => 500,
				'locale' => 'fa',
			)
		);
		$response = Digitalogic_REST_API::instance()->pricing_sync_state( $request );

		$this->assertSame( 200, $response->get_status() );
		$state = $response->get_data();
		$this->assertSame(
			array( 'schema', 'state_revision', 'generated_at', 'source', 'client_id', 'channel', 'request_id', 'warnings', 'confirmation', 'settings', 'currency', 'profit_margin', 'price_rounding', 'shipping', 'attribute_owners', 'catalog' ),
			array_keys( $state )
		);
		$this->assertSame( Digitalogic_Excel_Pricing_Sync::STATE_SCHEMA, $state['schema'] );
		$this->assertStringStartsWith( 'sha256:', $state['state_revision'] );
		$this->assertSame(
			array(
				'id'                       => $this->source['id'],
				'dataset'                  => $this->source['dataset'],
				'submitted_revision'       => $this->source['revision'],
				'current_revision'         => $this->source['revision'],
				'revision_matches_current' => true,
				'revision_capability'      => true,
			),
			$state['source']
		);
		$this->assertSame( array(), $state['warnings'] );
		$this->assertSame( 'unidentified-client', $state['client_id'] );
		$this->assertSame( 'api', $state['channel'] );
		$this->assertSame( 'state-not-provided', $state['request_id'] );
		$this->assertSame( '120', $state['settings']['air_express_price_per_kg'] );
		$this->assertSame( 'CNY', $state['settings']['air_express_currency'] );
		$this->assertSame(
			$state['shipping']['catalog_revision'],
			$state['settings']['shipping_catalog_revision']
		);
		$this->assertSame( '30', $state['profit_margin']['profit_margin_percent'] );
		$this->assertSame( 0, $state['settings']['price_rounding_digits'] );
		$this->assertSame( 'nearest_half_up', $state['settings']['price_rounding_mode'] );
		$this->assertSame( 0, $state['price_rounding']['rounding_digits'] );
		$this->assertSame( 'digitalogic_pricing_coordinator', $state['attribute_owners']['selling_price'] );
		$this->assertSame( 'digitalogic_pricing_coordinator', $state['attribute_owners']['air_express_shipping'] );
		$this->assertSame( 'digitalogic_pricing_coordinator', $state['attribute_owners']['price_rounding'] );
		$this->assertSame(
			array(
				'dollar_price',
				'yuan_price',
				'effective_date',
				'usd_effective_date',
				'cny_effective_date',
				'revision',
				'age_days',
				'stale',
				'stale_currencies',
				'freshness',
				'rate_provenance',
			),
			array_keys( $state['currency'] )
		);
		$this->assertSame( 187891, $state['currency']['dollar_price'] );
		$this->assertSame( 29500, $state['currency']['yuan_price'] );
		$this->assertSame( '2026-06-29', $state['currency']['effective_date'] );
		$this->assertSame( '2026-06-29', $state['currency']['usd_effective_date'] );
		$this->assertSame( '2026-06-29', $state['currency']['cny_effective_date'] );
		$this->assertSame( 'legacy_shared', $state['currency']['rate_provenance']['usd']['date_basis'] );
		$this->assertSame( 'legacy_shared', $state['currency']['rate_provenance']['cny']['date_basis'] );
		$this->assertTrue( $state['currency']['stale'] );
		$this->assertArrayNotHasKey( 'default_markup', $state );
		$this->assertArrayNotHasKey( 'deprecated_aliases', $state );
		$this->assertSame( 'reconciled_products', $state['catalog']['dataset'] );
		$this->assertSame( 'fa', $state['catalog']['locale'] );
		$this->assertMatchesRegularExpression( '/^sha256:[a-f0-9]{64}$/', $state['catalog']['dataset_revision'] );
		$this->assertSame( 250, $state['catalog']['pagination']['limit'] );
		$this->assertNotEmpty( $state['catalog']['columns'] );
		$this->assertMatchesRegularExpression( '/[^\x00-\x7F]/', $state['catalog']['columns'][0]['header'] );
	}

	/** Pricing writeback can read only current settings without building the product catalog. */
	public function test_settings_projection_omits_catalog_but_keeps_confirmation_and_revision(): void {
		$response = Digitalogic_REST_API::instance()->pricing_sync_state(
			$this->request(
				'state',
				array(
					'projection' => 'settings',
					'locale'     => 'fa',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$state = $response->get_data();
		$this->assertArrayNotHasKey( 'catalog', $state );
		$this->assertSame( '29500', (string) $state['settings']['yuan_price'] );
		$this->assertStringStartsWith( 'sha256:', $state['state_revision'] );
		$this->assertArrayHasKey( 'confirmation', $state );
	}
	/**
	 * A legacy alias-only settings document is no longer a valid contract.
	 */
	public function test_legacy_four_field_document_is_rejected_without_mutation(): void {
		$service = Digitalogic_Excel_Pricing_Sync::instance();
		$before  = $service->current_canonical_state();
		$result  = $service->apply_internal_settings(
			array(
				'dollar_price'           => '190000',
				'yuan_price'             => '29500',
				'effective_date'         => '2026-07-27',
				'default_profit_percent' => '30',
			),
			'legacy_excel'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_excel_sync_settings_shape_invalid', $result->get_error_code() );
		$this->assertSame( '260629', $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$this->assertSame( $before['state_revision'], $service->current_canonical_state()['state_revision'] );
	}

	/** Legacy aliases cannot replace required fields and are ignored beside canonical data. */
	public function test_profit_alias_cannot_replace_canonical_field(): void {
		$primary = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$legacy  = $primary;
		unset( $legacy['profit_margin_percent'] );
		$legacy['default_profit_percent'] = '30';
		$primary_result                   = Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings( $primary, 'primary_alias_test' );
		$legacy_result                    = Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings( $legacy, 'legacy_alias_test' );

		$this->assertFalse( is_wp_error( $primary_result ) );
		$this->assertTrue( is_wp_error( $legacy_result ) );
		$this->assertSame( 'digitalogic_excel_sync_settings_shape_invalid', $legacy_result->get_error_code() );

		$conflict                           = $primary;
		$conflict['default_profit_percent'] = '31';
		$ignored                            = Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings(
			$conflict,
			'alias_conflict_test'
		);
		$this->assertFalse( is_wp_error( $ignored ) );
		$this->assertSame( $primary_result['settings'], $ignored['settings'] );
	}

	/**
	 * New documents require both independent dates and keep the CNY alias exact.
	 */
	public function test_independent_date_shape_is_strict_and_cny_alias_matches(): void {
		$service  = Digitalogic_Excel_Pricing_Sync::instance();
		$settings = $service->current_canonical_settings();
		unset( $settings['cny_effective_date'] );
		$result = $service->apply_internal_settings( $settings, 'invalid_dates' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_excel_sync_currency_dates_incomplete', $result->get_error_code() );

		$settings                       = $service->current_canonical_settings();
		$settings['usd_effective_date'] = '2026-07-27';
		$settings['cny_effective_date'] = '2026-07-26';
		$settings['effective_date']     = '2026-07-27';
		$result                         = $service->apply_internal_settings( $settings, 'invalid_alias' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_excel_sync_effective_date_conflict', $result->get_error_code() );
	}

	/**
	 * Independent CNY metadata cannot disagree with the legacy storefront date.
	 */
	public function test_cny_metadata_mismatch_with_legacy_option_fails_closed(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION ] = array(
			'effective_date'     => '2026-06-28',
			'usd_effective_date' => '2026-06-28',
			'cny_effective_date' => '2026-06-28',
		);

		$result = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame(
			'digitalogic_pricing_currency_date_metadata_invalid',
			$result->get_error_code()
		);
	}

	/**
	 * A newer valid local revision remains visible and non-blocking throughout
	 * state, preview, apply, settings metadata, and the bounded audit record.
	 */
	public function test_source_revision_drift_is_visible_but_non_blocking_for_full_flow(): void {
		$submitted_source             = $this->source;
		$submitted_source['revision'] = 'sha256:' . str_repeat( 'b', 64 );

		$state = Digitalogic_Excel_Pricing_Sync::instance()->state(
			$this->request(
				'state',
				array( 'source' => $submitted_source )
			)
		);
		$this->assertFalse( is_wp_error( $state ) );
		$this->assert_source_revision_drift( $state, $submitted_source );

		$settings = $this->proposed_settings();
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-drift-0001',
				$state['state_revision'],
				$settings,
				array( 'source' => $submitted_source )
			)
		);
		$this->assertFalse( is_wp_error( $preview ) );
		$this->assert_source_revision_drift( $preview, $submitted_source );

		$applied = Digitalogic_Excel_Pricing_Sync::instance()->apply(
			$this->mutation_request(
				'apply',
				'excel-apply-drift-000001',
				$state['state_revision'],
				$settings,
				array(
					'source'         => $submitted_source,
					'preview_digest' => $preview['preview_digest'],
					'confirmation'   => 'APPLY',
				)
			)
		);
		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( 'applied', $applied['status'] );
		$this->assert_source_revision_drift( $applied, $submitted_source );
		$this->assertSame(
			$submitted_source,
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION ]['source']
		);

		$audit = $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION ];
		$this->assertCount( 1, $audit );
		$this->assertSame( $submitted_source, $audit[0]['source'] );
		$this->assertSame( $applied['source'], $audit[0]['source_revision_context'] );
	}

	/**
	 * Revision tolerance must never widen the configured source ID/dataset.
	 */
	public function test_wrong_source_id_or_dataset_remains_rejected(): void {
		$wrong_sources = array(
			array_merge( $this->source, array( 'id' => 'wrong-source' ) ),
			array_merge( $this->source, array( 'dataset' => 'wrong-dataset' ) ),
		);

		foreach ( $wrong_sources as $wrong_source ) {
			$result = Digitalogic_Excel_Pricing_Sync::instance()->state(
				$this->request(
					'state',
					array( 'source' => $wrong_source )
				)
			);

			$this->assertSame( 'digitalogic_excel_sync_source_scope_conflict', $result->get_error_code() );
			$this->assertSame( 409, $result->get_error_data()['status'] );
		}
	}

	/**
	 * Revision tolerance does not weaken transport, idempotency, or preview
	 * binding: every submitted revision remains part of those identities.
	 */
	public function test_revision_drift_keeps_mutation_guards_strict(): void {
		$state    = $this->state_data();
		$settings = $this->proposed_settings();
		$source_b = array_merge(
			$this->source,
			array( 'revision' => 'sha256:' . str_repeat( 'b', 64 ) )
		);
		$source_c = array_merge(
			$this->source,
			array( 'revision' => 'sha256:' . str_repeat( 'c', 64 ) )
		);

		$if_match = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->request(
				'preview',
				array(
					'source'                  => $source_b,
					'idempotency_key'         => 'excel-preview-guard-0001',
					'expected_state_revision' => $state['state_revision'],
					'settings'                => $settings,
					'product_changes'         => array(),
				),
				array(
					'Idempotency-Key' => 'excel-preview-guard-0001',
					'If-Match'        => '"sha256:' . str_repeat( 'f', 64 ) . '"',
				)
			)
		);
		$this->assertSame( 'digitalogic_excel_sync_if_match_mismatch', $if_match->get_error_code() );

		$preview = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-guard-0002',
				$state['state_revision'],
				$settings,
				array( 'source' => $source_b )
			)
		);
		$this->assertFalse( is_wp_error( $preview ) );

		$reused = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-guard-0002',
				$state['state_revision'],
				$settings,
				array( 'source' => $source_c )
			)
		);
		$this->assertSame( 'digitalogic_excel_sync_idempotency_reused', $reused->get_error_code() );

		$mismatched_preview = Digitalogic_Excel_Pricing_Sync::instance()->apply(
			$this->mutation_request(
				'apply',
				'excel-apply-guard-000001',
				$state['state_revision'],
				$settings,
				array(
					'source'         => $source_c,
					'preview_digest' => $preview['preview_digest'],
					'confirmation'   => 'APPLY',
				)
			)
		);
		$this->assertSame( 'digitalogic_excel_sync_preview_mismatch', $mismatched_preview->get_error_code() );
	}

	/**
	 * Verify the 7-day and 7-percent warnings and a bound preview digest.
	 */
	public function test_preview_warns_for_stale_and_large_drift(): void {
		$state                  = $this->state_data();
		$settings               = $this->proposed_settings();
		$settings['yuan_price'] = 33000;
		$preview                = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-0001',
				$state['state_revision'],
				$settings
			)
		);

		$this->assertFalse( is_wp_error( $preview ) );
		$this->assertSame( 'preview', $preview['mode'] );
		$this->assertSame( 'confirmation_required', $preview['status'] );
		$this->assertStringStartsWith( 'sha256:', $preview['preview_digest'] );
		$codes = array_column( $preview['warnings'], 'code' );
		$this->assertContains( 'current_currency_stale', $codes );
		$this->assertContains( 'currency_drift_over_7_percent', $codes );
		$this->assertContains( 'shipping_drift_over_7_percent', $codes );
		$this->assertContains( 'price_rounding_changed', $codes );
		$this->assertContains( 'effective_date_changed', $codes );
		$this->assertSame( array(), $preview['product_results'] );
	}

	/**
	 * Verify stale If-Match state is rejected before preview creation.
	 */
	public function test_preview_rejects_stale_state_revision(): void {
		$result = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-0002',
				'sha256:' . str_repeat( 'f', 64 ),
				$this->proposed_settings()
			)
		);

		$this->assertSame( 'digitalogic_excel_sync_state_revision_conflict', $result->get_error_code() );
		$this->assertSame( 412, $result->get_error_data()['status'] );
	}

	/**
	 * Verify apply requires exact confirmation and an unmodified preview.
	 */
	public function test_apply_requires_exact_confirmation(): void {
		$state    = $this->state_data();
		$settings = $this->proposed_settings();
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-0003',
				$state['state_revision'],
				$settings
			)
		);
		$request  = $this->mutation_request(
			'apply',
			'excel-apply-000001',
			$state['state_revision'],
			$settings,
			array(
				'preview_digest' => $preview['preview_digest'],
				'confirmation'   => 'YES',
			)
		);
		$result   = Digitalogic_Excel_Pricing_Sync::instance()->apply( $request );

		$this->assertSame( 'digitalogic_excel_sync_confirmation_required', $result->get_error_code() );
		$this->assertSame( '187891', $GLOBALS['digitalogic_test_options']['dollar_price'] );
	}

	/**
	 * The companion skips duplicate catalog work when settings are unchanged.
	 */
	public function test_unchanged_companion_apply_defers_to_required_product_sync(): void {
		$state    = $this->state_data();
		$settings = $state['settings'];
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-current-0001',
				$state['state_revision'],
				$settings,
				array(
					'client_id'  => 'digitalogic-price-calculator',
					'channel'    => 'excel-workbook',
					'request_id' => 'excel-preview-current-0001',
				)
			)
		);
		$this->assertFalse( is_wp_error( $preview ) );

		$GLOBALS['wpdb']->queries                      = array();
		$GLOBALS['digitalogic_test_cache_deletes']     = array();
		$GLOBALS['digitalogic_test_transient_deletes'] = array();

		$applied = Digitalogic_Excel_Pricing_Sync::instance()->apply(
			$this->mutation_request(
				'apply',
				'excel-apply-current-000001',
				$state['state_revision'],
				$settings,
				array(
					'preview_digest' => $preview['preview_digest'],
					'confirmation'   => 'APPLY',
					'client_id'      => 'digitalogic-price-calculator',
					'channel'        => 'excel-workbook',
					'request_id'     => 'excel-apply-current-000001',
				)
			)
		);

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( 'reconciled', $applied['status'] );
		$this->assertSame( $state['state_revision'], $applied['state_revision'] );
		$this->assertSame( array(), $applied['product_results'] );
		$this->assertNotContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertContains(
			array( 'generation', 'digitalogic_reports' ),
			$GLOBALS['digitalogic_test_cache_deletes']
		);
		$this->assertSame( array(), $GLOBALS['digitalogic_test_transient_deletes'] );
		$this->assertArrayNotHasKey(
			Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION,
			$GLOBALS['digitalogic_test_options']
		);
		$warning_codes = array_column( $applied['warnings'], 'code' );
		$this->assertContains( 'settings_already_current', $warning_codes );
		$this->assertNotContains( 'pricing_reconciled', $warning_codes );
	}

	/**
	 * Other API clients retain direct unchanged-settings drift repair.
	 */
	public function test_unchanged_non_companion_apply_still_reconciles_catalog(): void {
		$state    = $this->state_data();
		$settings = $state['settings'];
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-generic-0001',
				$state['state_revision'],
				$settings
			)
		);
		$this->assertFalse( is_wp_error( $preview ) );

		$GLOBALS['wpdb']->queries = array();
		$applied                  = Digitalogic_Excel_Pricing_Sync::instance()->apply(
			$this->mutation_request(
				'apply',
				'excel-apply-generic-000001',
				$state['state_revision'],
				$settings,
				array(
					'preview_digest' => $preview['preview_digest'],
					'confirmation'   => 'APPLY',
				)
			)
		);

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( 'reconciled', $applied['status'] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$warning_codes = array_column( $applied['warnings'], 'code' );
		$this->assertContains( 'pricing_reconciled', $warning_codes );
		$this->assertNotContains( 'settings_already_current', $warning_codes );
	}

	/**
	 * Verify atomic apply, exact readback, audit, and idempotent replay.
	 */
	public function test_apply_is_atomic_audited_and_idempotent(): void {
		$state    = $this->state_data();
		$settings = $this->proposed_settings();
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-0004',
				$state['state_revision'],
				$settings,
				array(
					'client_id'  => 'desktop-price-calculator',
					'channel'    => 'excel-workbook',
					'request_id' => 'workbook-preview-0004',
				)
			)
		);
		$request  = $this->mutation_request(
			'apply',
			'excel-apply-000002',
			$state['state_revision'],
			$settings,
			array(
				'preview_digest' => $preview['preview_digest'],
				'confirmation'   => 'APPLY',
				'client_id'      => 'desktop-price-calculator',
				'channel'        => 'excel-workbook',
				'request_id'     => 'workbook-apply-000002',
			)
		);
		$service  = Digitalogic_Excel_Pricing_Sync::instance();
		$applied  = $service->apply( $request );

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertSame( 'desktop-price-calculator', $applied['client_id'] );
		$this->assertSame( 'excel-workbook', $applied['channel'] );
		$this->assertSame( 'workbook-apply-000002', $applied['request_id'] );
		$this->assertNotSame( $state['state_revision'], $applied['state_revision'] );
		$this->assertSame( '132', $applied['settings']['air_express_price_per_kg'] );
		$this->assertNotSame(
			$settings['shipping_catalog_revision'],
			$applied['settings']['shipping_catalog_revision']
		);
		$this->assertSame(
			$applied['settings']['shipping_catalog_revision'],
			Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog()['revision']
		);
		$this->assertSame( '190000', $GLOBALS['digitalogic_test_options']['dollar_price'] );
		$this->assertSame( '31000', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame(
			'132',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['air_express']['price_per_kg']
		);
		$this->assertSame( gmdate( 'ymd' ), $GLOBALS['digitalogic_test_options']['update_date'] );
		$this->assertSame(
			'35',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION ]['profit_percent']
		);
		$this->assertSame(
			'2',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION ]
		);
		$this->assertSame(
			$applied['state_revision'],
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION ]['revision']
		);
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION ] );
		$audit = $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION ][0];
		$this->assertSame( 'desktop-price-calculator', $audit['client_id'] );
		$this->assertSame( 'excel-workbook', $audit['channel'] );
		$this->assertSame( 'workbook-apply-000002', $audit['request_id'] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertSame( 'awaiting_ack', $applied['confirmation']['status'] );
		$this->assertGreaterThanOrEqual( 90, $applied['confirmation']['ack_deadline'] - time() );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );
		$scheduled_before_replay = $GLOBALS['digitalogic_test_scheduled_events'];
		$events_before_replay    = $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'];

		$replayed = $service->apply( $request );
		$this->assertFalse( is_wp_error( $replayed ) );
		$this->assertSame( 'replayed', $replayed['status'] );
		$this->assertSame( $applied['state_revision'], $replayed['state_revision'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION ] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertSame( $scheduled_before_replay, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( $events_before_replay, $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] );
	}

	/**
	 * Verify an intermediate storage failure rolls back every global setting.
	 */
	public function test_apply_rolls_back_all_settings_on_failed_readback_write(): void {
		$state    = $this->state_data();
		$settings = $this->proposed_settings();
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-0005',
				$state['state_revision'],
				$settings
			)
		);
		$GLOBALS['digitalogic_test_update_failures'][] = 'options_yuan_price';
		$result                                        = Digitalogic_Excel_Pricing_Sync::instance()->apply(
			$this->mutation_request(
				'apply',
				'excel-apply-000003',
				$state['state_revision'],
				$settings,
				array(
					'preview_digest' => $preview['preview_digest'],
					'confirmation'   => 'APPLY',
				)
			)
		);

		$this->assertSame( 'digitalogic_excel_sync_option_write_failed', $result->get_error_code() );
		$this->assertSame( '187891', $GLOBALS['digitalogic_test_options']['dollar_price'] );
		$this->assertSame( '29500', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '30', $GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION ]['profit_percent'] );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION ] );
		$this->assertArrayNotHasKey( Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Internal admin commits are terminal and never depend on a workbook ACK. */
	public function test_internal_admin_commit_is_terminal_and_never_stages_excel_ack(): void {
		$service                = Digitalogic_Excel_Pricing_Sync::instance();
		$previous               = $service->current_canonical_state();
		$settings               = $previous['settings'];
		$settings['yuan_price'] = 29501;
		$committed              = $service->apply_internal_settings( $settings, 'admin_async', $previous['state_revision'] );

		$this->assertFalse( is_wp_error( $committed ) );
		$this->assertSame( 'applied', $committed['status'] );
		$this->assertSame( 29501, $committed['settings']['yuan_price'] );
		$this->assertSame( 'clear', $committed['confirmation']['status'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertArrayNotHasKey( Digitalogic_Excel_Pricing_Sync::CONFIRMATIONS_OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame(
			array( 'digitalogic_pricing_state_event_delivery' ),
			array_values( array_unique( array_column( $GLOBALS['digitalogic_test_scheduled_events'], 'hook' ) ) )
		);
		$this->assertEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );
		$this->assertSame( 29501, $service->current_canonical_settings()['yuan_price'] );
		$this->assertNull( $service->recover_pending_confirmation() );
	}

	/** Repeating the same semantic A-to-B transition is a new effect, not a dedupe replay. */
	public function test_repeated_internal_rate_cycle_uses_distinct_effect_ids(): void {
		$service          = Digitalogic_Excel_Pricing_Sync::instance();
		$state            = $service->current_canonical_state();
		$up               = $state['settings'];
		$up['yuan_price'] = 29501;
		$first            = $service->apply_internal_settings( $up, 'admin_async', $state['state_revision'] );
		$this->assertFalse( is_wp_error( $first ) );

		$state              = $service->current_canonical_state();
		$down               = $state['settings'];
		$down['yuan_price'] = 29500;
		$second             = $service->apply_internal_settings( $down, 'admin_async', $state['state_revision'] );
		$this->assertFalse( is_wp_error( $second ) );

		$state            = $service->current_canonical_state();
		$up               = $state['settings'];
		$up['yuan_price'] = 29501;
		$third            = $service->apply_internal_settings( $up, 'admin_async', $state['state_revision'] );
		$this->assertFalse( is_wp_error( $third ) );

		$this->assertNotSame( $first['effect_id'], $third['effect_id'] );
		$this->assertSame( $first['state_revision'], $third['state_revision'] );
	}

	/**
	 * The transaction may still see the pre-write shipping catalog through the
	 * WordPress option cache even after every SQL option write was verified.
	 */
	public function test_transaction_readback_normalizes_only_exact_stale_shipping_cache(): void {
		$service    = Digitalogic_Excel_Pricing_Sync::instance();
		$reflection = new ReflectionClass( $service );
		$read       = $reflection->getMethod( 'read_globals' );
		$desired_m  = $reflection->getMethod( 'globals_from_settings' );
		$normalize  = $reflection->getMethod( 'transaction_consistent_readback' );
		$read->setAccessible( true );
		$desired_m->setAccessible( true );
		$normalize->setAccessible( true );

		$current                 = $read->invoke( $service );
		$settings                = $service->current_canonical_settings();
		$settings['yuan_price']  = (int) $settings['yuan_price'] + 1;
		$desired                 = $desired_m->invoke( $service, $settings );
		$stale                   = $desired;
		$stale['shipping']       = $current['shipping'];
		$stale['state_revision'] = 'sha256:' . str_repeat( 'a', 64 );

		$resolved = $normalize->invoke( $service, $stale, $desired, $current );
		$this->assertSame( $desired['state_revision'], $resolved['state_revision'] );
		$this->assertSame( $desired['shipping'], $resolved['shipping'] );

		$unsafe             = $stale;
		$unsafe['currency'] = $current['currency'];
		$rejected           = $normalize->invoke( $service, $unsafe, $desired, $current );
		$this->assertSame( $stale['state_revision'], $rejected['state_revision'] );
		$this->assertSame( $current['shipping'], $rejected['shipping'] );
	}

	/** Missing ACK rolls back only an explicit Excel apply, exactly once. */
	public function test_ack_timeout_rolls_back_explicit_excel_apply_and_is_restart_idempotent(): void {
		$service                = Digitalogic_Excel_Pricing_Sync::instance();
		$previous               = $service->current_canonical_state();
		$settings               = $previous['settings'];
		$settings['yuan_price'] = 29501;
		$preview                = $service->preview(
			$this->mutation_request(
				'preview',
				'excel-preview-timeout-0001',
				$previous['state_revision'],
				$settings
			)
		);
		$this->assertFalse( is_wp_error( $preview ) );
		$committed = $service->apply(
			$this->mutation_request(
				'apply',
				'excel-apply-timeout-000001',
				$previous['state_revision'],
				$settings,
				array(
					'preview_digest' => $preview['preview_digest'],
					'confirmation'   => 'APPLY',
				)
			)
		);
		$this->assertFalse( is_wp_error( $committed ) );
		$this->assertSame( 'awaiting_ack', $committed['confirmation']['status'] );
		$id = $committed['confirmation']['transaction_id'];

		$ledger                                        = $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::CONFIRMATIONS_OPTION ];
		$ledger['transactions'][ $id ]['ack_deadline'] = time() - 1;
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::CONFIRMATIONS_OPTION ] = $ledger;
		$GLOBALS['digitalogic_test_option_cache'] = array();

		$rolled_back = $service->run_confirmation_timeout( $id );
		$this->assertFalse(
			is_wp_error( $rolled_back ),
			is_wp_error( $rolled_back ) ? $rolled_back->get_error_code() . ': ' . $rolled_back->get_error_message() : ''
		);
		$this->assertSame( 'rolled_back', $rolled_back['status'] );
		$this->assertSame( 29500, $service->current_canonical_settings()['yuan_price'] );
		$this->assertSame( $previous['state_revision'], $service->current_canonical_state()['state_revision'] );
		$this->assertSame(
			array( 'digitalogic_pricing_state_event_delivery' ),
			array_values( array_unique( array_column( $GLOBALS['digitalogic_test_scheduled_events'], 'hook' ) ) )
		);
		$events = $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array();
		$this->assertCount( 2, $events );
		$this->assertSame( 'pricing.settings.rolled_back', $events[1][0]['event_type'] );
		$this->assertSame( 29500, $events[1][0]['confirmed_settings']['yuan_price'] );

		$again = $service->run_confirmation_timeout( $id );
		$this->assertFalse( is_wp_error( $again ) );
		$this->assertSame( 'rolled_back', $again['status'] );
		$this->assertSame( 29500, $service->current_canonical_settings()['yuan_price'] );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );
	}

	/**
	 * Return one exact state result.
	 *
	 * @return array
	 */
	private function state_data() {
		$result = Digitalogic_Excel_Pricing_Sync::instance()->state( $this->request( 'state' ) );
		$this->assertFalse( is_wp_error( $result ) );

		return $result;
	}

	/**
	 * Build fresh settings with material rate and profit differences.
	 *
	 * @return array
	 */
	private function proposed_settings() {
		$current = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertFalse(
			is_wp_error( $current ),
			is_wp_error( $current ) ? $current->get_error_code() . ': ' . $current->get_error_message() : ''
		);

		return array_merge(
			$current,
			array(
				'dollar_price'             => 190000,
				'yuan_price'               => 31000,
				'effective_date'           => gmdate( 'Y-m-d' ),
				'usd_effective_date'       => gmdate( 'Y-m-d' ),
				'cny_effective_date'       => gmdate( 'Y-m-d' ),
				'profit_margin_percent'    => '35',
				'price_rounding_digits'    => 2,
				'price_rounding_mode'      => 'nearest_half_up',
				'air_express_price_per_kg' => '132',
				'air_express_currency'     => 'CNY',
			)
		);
	}

	/**
	 * Build one request with the common contract envelope.
	 *
	 * @param string $operation Route operation.
	 * @param array  $extra     Additional body fields.
	 * @param array  $headers   Additional headers.
	 * @return WP_REST_Request
	 */
	private function request( $operation, $extra = array(), $headers = array() ) {
		$payload = array_merge(
			array(
				'schema'    => Digitalogic_Excel_Pricing_Sync::REQUEST_SCHEMA,
				'source'    => $this->source,
				'operation' => $operation,
			),
			$extra
		);

		return new WP_REST_Request(
			array(),
			$payload,
			$headers,
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Build one preview/apply request with matching transport headers.
	 *
	 * @param string $operation       preview or apply.
	 * @param string $idempotency_key Request key.
	 * @param string $revision        Expected settings revision.
	 * @param array  $settings        Complete settings.
	 * @param array  $extra           Additional body fields.
	 * @return WP_REST_Request
	 */
	private function mutation_request( $operation, $idempotency_key, $revision, $settings, $extra = array() ) {
		return $this->request(
			$operation,
			array_merge(
				array(
					'idempotency_key'         => $idempotency_key,
					'expected_state_revision' => $revision,
					'settings'                => $settings,
					'product_changes'         => array(),
				),
				$extra
			),
			array(
				'Idempotency-Key' => $idempotency_key,
				'If-Match'        => '"' . $revision . '"',
			)
		);
	}

	/**
	 * Build an acknowledgement bound to the exact committed website value.
	 *
	 * @param array $committed Committed coordinator response.
	 * @param array $overrides Optional acknowledgement overrides.
	 * @return WP_REST_Request
	 */
	private function ack_request( $committed, $overrides = array() ) {
		$settings = $committed['settings'];
		$key      = 'excel-ack-00000001';
		$payload  = array_merge(
			array(
				'schema'                    => Digitalogic_Excel_Pricing_Sync::ACK_SCHEMA,
				'schema_version'            => 1,
				'operation'                 => 'ack',
				'transaction_id'            => $committed['confirmation']['transaction_id'],
				'consumer_id'               => 'digitalogic-price-calculator',
				'channel'                   => 'excel-workbook',
				'source'                    => $this->source,
				'committed_state_revision'  => $committed['state_revision'],
				'confirmed_settings'        => $settings,
				'confirmed_settings_digest' => $committed['confirmation']['committed_settings_digest'],
				'idempotency_key'           => $key,
			),
			$overrides
		);

		return new WP_REST_Request(
			array(),
			$payload,
			array( 'Idempotency-Key' => $key ),
			wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Assert additive source metadata and its Persian non-blocking warning.
	 *
	 * @param array $response         Successful sync response.
	 * @param array $submitted_source Submitted source identity.
	 * @return void
	 */
	private function assert_source_revision_drift( $response, $submitted_source ) {
		$this->assertSame( $submitted_source['id'], $response['source']['id'] );
		$this->assertSame( $submitted_source['dataset'], $response['source']['dataset'] );
		$this->assertSame( $submitted_source['revision'], $response['source']['submitted_revision'] );
		$this->assertSame( $this->source['revision'], $response['source']['current_revision'] );
		$this->assertFalse( $response['source']['revision_matches_current'] );

		$warnings = array_values(
			array_filter(
				$response['warnings'],
				static function ( $warning ) {
					return is_array( $warning )
						&& 'source_revision_out_of_sync' === ( $warning['code'] ?? '' );
				}
			)
		);
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'warning', $warnings[0]['severity'] );
		$this->assertMatchesRegularExpression( '/[^\x00-\x7F]/', $warnings[0]['message_fa'] );
		$this->assertSame( $submitted_source['revision'], $warnings[0]['details']['submitted_revision'] );
		$this->assertSame( $this->source['revision'], $warnings[0]['details']['current_revision'] );
	}

	/**
	 * Build an installed default-markup record using its established revision.
	 *
	 * @param string $profit Canonical profit.
	 * @return array
	 */
	private function default_markup_state( $profit ) {
		$identity = array(
			'schema'         => Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_SCHEMA,
			'configured'     => true,
			'type'           => 'percentage',
			'source'         => 'global_default',
			'profit_percent' => $profit,
		);

		return array_merge(
			$identity,
			array(
				'revision'   => 'sha256:' . hash(
					'sha256',
					wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				),
				'updated_at' => '2026-06-29 00:00:00',
				'updated_by' => 0,
			)
		);
	}

	/**
	 * Reset one singleton between isolated tests.
	 *
	 * @param string $class_name Singleton class.
	 * @return void
	 */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
