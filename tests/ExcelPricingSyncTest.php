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
			'dollar_price'            => '170000',
			'options_dollar_price'    => '170000',
			'yuan_price'              => '25300',
			'options_yuan_price'      => '25300',
			'update_date'             => '260629',
			'options_update_date'     => '260629',
			Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION => $markup,
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
			$key = 'digitalogic/excel/pricing-sync/' . $mode;
			$this->assertArrayHasKey( $key, $routes );
			$this->assertSame( 'POST', $routes[ $key ]['methods'] );
			$this->assertSame( array( $api, 'check_excel_pricing_sync_permission' ), $routes[ $key ]['permission_callback'] );
		}

		$request = $this->request(
			'state',
			array(),
			array( 'X-Patris-Product-Sync-Secret' => 'receiver-secret' )
		);
		$this->assertTrue( $api->check_excel_pricing_sync_permission( $request ) );

		$missing_secret = $this->request( 'state' );
		$denied         = $api->check_excel_pricing_sync_permission( $missing_secret );
		$this->assertSame( 'digitalogic_excel_sync_unauthorized', $denied->get_error_code() );

		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array();
		$GLOBALS['digitalogic_test_option_cache'] = array();
		$unscoped                                 = $api->check_excel_pricing_sync_permission( $request );
		$this->assertSame( 'digitalogic_excel_sync_scope_required', $unscoped->get_error_code() );
	}

	/**
	 * Verify the requested stable state shape and Persian paged catalog.
	 */
	public function test_state_is_versioned_persian_and_reports_staleness(): void {
		$request  = $this->request(
			'state',
			array(
				'page'   => 1,
				'limit'  => 500,
				'locale' => 'fa',
			)
		);
		$response = Digitalogic_REST_API::instance()->excel_pricing_sync_state( $request );

		$this->assertSame( 200, $response->get_status() );
		$state = $response->get_data();
		$this->assertSame(
			array( 'schema', 'state_revision', 'generated_at', 'source', 'warnings', 'currency', 'default_markup', 'catalog' ),
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
			),
			$state['source']
		);
		$this->assertSame( array(), $state['warnings'] );
		$this->assertSame(
			array( 'dollar_price', 'yuan_price', 'effective_date', 'revision', 'age_days', 'stale' ),
			array_keys( $state['currency'] )
		);
		$this->assertSame( 170000, $state['currency']['dollar_price'] );
		$this->assertSame( 25300, $state['currency']['yuan_price'] );
		$this->assertSame( '2026-06-29', $state['currency']['effective_date'] );
		$this->assertTrue( $state['currency']['stale'] );
		$this->assertTrue( $state['default_markup']['configured'] );
		$this->assertSame( '30', $state['default_markup']['profit_percent'] );
		$this->assertSame( 'products', $state['catalog']['dataset'] );
		$this->assertSame( 'fa', $state['catalog']['locale'] );
		$this->assertSame( 500, $state['catalog']['pagination']['limit'] );
		$this->assertNotEmpty( $state['catalog']['columns'] );
		$this->assertMatchesRegularExpression( '/[^\x00-\x7F]/', $state['catalog']['columns'][0]['header'] );
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
		$this->assertFalse( is_wp_error( $applied ) );
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
		$state    = $this->state_data();
		$settings = $this->proposed_settings();
		$preview  = Digitalogic_Excel_Pricing_Sync::instance()->preview(
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
		$this->assertSame( '170000', $GLOBALS['digitalogic_test_options']['dollar_price'] );
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
				$settings
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
			)
		);
		$service  = Digitalogic_Excel_Pricing_Sync::instance();
		$applied  = $service->apply( $request );

		$this->assertFalse( is_wp_error( $applied ) );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertNotSame( $state['state_revision'], $applied['state_revision'] );
		$this->assertSame( '175000', $GLOBALS['digitalogic_test_options']['dollar_price'] );
		$this->assertSame( '29500', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( gmdate( 'ymd' ), $GLOBALS['digitalogic_test_options']['update_date'] );
		$this->assertSame(
			'35',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION ]['profit_percent']
		);
		$this->assertSame(
			$applied['state_revision'],
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION ]['revision']
		);
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION ] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );

		$replayed = $service->apply( $request );
		$this->assertFalse( is_wp_error( $replayed ) );
		$this->assertSame( 'replayed', $replayed['status'] );
		$this->assertSame( $applied['state_revision'], $replayed['state_revision'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION ] );
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
		$this->assertSame( '170000', $GLOBALS['digitalogic_test_options']['dollar_price'] );
		$this->assertSame( '25300', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '30', $GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION ]['profit_percent'] );
		$this->assertArrayNotHasKey( Digitalogic_Excel_Pricing_Sync::AUDIT_OPTION, $GLOBALS['digitalogic_test_options'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
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
		return array(
			'dollar_price'           => 175000,
			'yuan_price'             => 29500,
			'effective_date'         => gmdate( 'Y-m-d' ),
			'default_profit_percent' => '35',
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
				'schema'         => Digitalogic_Excel_Pricing_Sync::REQUEST_SCHEMA,
				'schema_version' => 1,
				'source'         => $this->source,
				'operation'      => $operation,
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
