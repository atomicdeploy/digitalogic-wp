<?php
/**
 * Google Sheets controlled writeback tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies preview/apply safety, replay, concurrency, and REST wrapping.
 */
final class GoogleSheetsWritebackTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Digitalogic_Google_Sheets_Writeback
	 */
	private $service;

	/** Build one exact, editable product fixture. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_options']              = array(
			'woocommerce_weight_unit'          => 'kg',
			'options_yuan_price'               => '30000',
			'options_update_date'              => '260721',
			'digitalogic_patris_feed_settings' => array( 'selected_warehouses' => array() ),
		);
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_transients']           = array();
		$GLOBALS['digitalogic_test_transient_deletes']    = array();
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_meta_update_failures'] = array();
		$GLOBALS['digitalogic_test_meta_delete_failures'] = array();
		$GLOBALS['digitalogic_test_transaction_failures'] = array();
		$GLOBALS['digitalogic_test_wc_products']          = array();
		$GLOBALS['digitalogic_test_wc_product_saves']     = array();
		$GLOBALS['digitalogic_test_wc_save_failures']     = array();
		$GLOBALS['digitalogic_test_wc_set_price_calls']   = array();
		$GLOBALS['digitalogic_test_wc_after_save']        = null;
		$GLOBALS['digitalogic_test_actions']              = array();
		$GLOBALS['digitalogic_test_action_callbacks']     = array();
		$GLOBALS['digitalogic_test_update_failures']      = array();
		$GLOBALS['digitalogic_test_terms']                = array();
		$GLOBALS['digitalogic_test_wc_currency']          = 'IRT';
		$GLOBALS['digitalogic_test_posts']                = array(
			741 => array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => 'Controlled Product',
				'product_type' => 'simple',
				'meta'         => array(
					'_digitalogic_patris_product_code' => '000741',
					'_sku'                             => 'SKU-741',
					'_regular_price'                   => '100',
					'_sale_price'                      => '',
					'_price'                           => '100',
					'_manage_stock'                    => 'yes',
					'_stock'                           => 4,
					'_stock_status'                    => 'instock',
					'_digitalogic_markup'              => '25',
					'_digitalogic_markup_type'         => 'percentage',
				),
			),
		);
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'test-source' => array(
					'source'       => array(
						'id'       => 'patris-export',
						'dataset'  => 'ALLANBAR',
						'revision' => 'sha256:test-source',
					),
					'generated_at' => gmdate( 'c' ),
					'products'     => array(
						'000741' => array(
							'product_code' => '000741',
							'name'         => 'Controlled Product',
							'warnings'     => array(),
						),
					),
				),
			),
		);
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

		foreach (
			array(
				Digitalogic_Product_Identifier_Resolver::class,
				Digitalogic_Product_Manager::class,
				Digitalogic_Product_Write_Lock::class,
				Digitalogic_Shipping_Method_Service::class,
				Digitalogic_WooCommerce_Currency_Status::class,
				Digitalogic_Google_Sheets_Catalog::class,
				Digitalogic_Google_Sheets_Writeback::class,
				Digitalogic_Product_Sync_Receiver::class,
				Digitalogic_Report_Engine::class,
				Digitalogic_Logger::class,
			) as $class_name
		) {
			$this->reset_singleton( $class_name );
		}
		$this->service = Digitalogic_Google_Sheets_Writeback::instance();
	}

	/** Preview must be non-mutating; apply must audit and replay exactly once. */
	public function test_preview_apply_and_idempotent_replay() {
		$revision = $this->current_row()['record_revision'];
		$changes  = array(
			array(
				'sync_key'                 => 'woo:741',
				'patris_code'              => '000741',
				'expected_record_revision' => $revision,
				'fields'                   => array(
					'shipping_method_id' => 'air_express',
				),
			),
		);

		$preview = $this->service->preview(
			array(
				'idempotency_key' => 'preview-000741-01',
				'changes'         => $changes,
			)
		);
		$this->assertSame( 'preview', $preview['mode'] );
		$this->assertSame( 'ready', $preview['results'][0]['status'] );
		$this->assertSame( 0, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
		$this->assertSame( '100', wc_get_product( 741 )->get_regular_price() );

		$payload = array(
			'idempotency_key' => 'apply-000741-01',
			'changes'         => $changes,
		);
		$applied = $this->service->apply( $payload );
		$this->assertSame( 'applied', $applied['results'][0]['status'] );
		$this->assertSame( 1, $applied['summary']['applied'] );
		$this->assertSame( '100', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( '', wc_get_product( 741 )->get_sale_price() );
		$this->assertSame( '100', wc_get_product( 741 )->get_price() );
		$this->assertSame(
			'air_express',
			get_post_meta( 741, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true )
		);
		$this->assertCount( 1, Digitalogic_Logger::instance()->entries );
		$this->assertNotSame( $revision, $applied['results'][0]['record_revision'] );
		$this->assertTrue( $applied['results'][0]['rollback']['available'] );

		$save_count = count( $GLOBALS['digitalogic_test_wc_product_saves'] );
		$replay     = $this->service->apply( $payload );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $save_count, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
		$this->assertCount( 1, Digitalogic_Logger::instance()->entries );

		$payload['changes'][0]['fields']['shipping_method_id'] = 'sea_freight';
		$reused = $this->service->apply( $payload );
		$this->assertInstanceOf( WP_Error::class, $reused );
		$this->assertSame( 'idempotency_key_reused', $reused->get_error_code() );
		$this->assertSame( 409, $reused->get_error_data()['status'] );
	}

	/** A stale revision must return a typed conflict and write nothing. */
	public function test_stale_revision_is_a_non_mutating_conflict() {
		$revision = $this->current_row()['record_revision'];
		$product  = wc_get_product( 741 );
		$product->set_regular_price( '101' );
		$product->set_price( '101' );
		$product->save();
		$save_count = count( $GLOBALS['digitalogic_test_wc_product_saves'] );

		$result = $this->service->apply(
			$this->payload( 'apply-conflict-000741', $revision, array( 'shipping_method_id' => 'air_express' ) )
		);
		$this->assertSame( 'conflict', $result['results'][0]['status'] );
		$this->assertSame( 'record_revision_conflict', $result['results'][0]['code'] );
		$this->assertSame( 1, $result['summary']['conflicts'] );
		$this->assertSame( '101', $product->get_regular_price() );
		$this->assertSame( $save_count, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
		$this->assertCount( 0, Digitalogic_Logger::instance()->entries );
	}

	/** Shipping assignment remains the only row-level editable integration field. */
	public function test_shipping_assignment_apply() {
		$revision = $this->current_row()['record_revision'];
		$result   = $this->service->apply(
			$this->payload(
				'apply-clear-fields-000741',
				$revision,
				array(
					'shipping_method_id' => 'air_express',
				)
			)
		);

		$this->assertSame( 'applied', $result['results'][0]['status'] );
		$this->assertSame( '', wc_get_product( 741 )->get_sale_price() );
		$this->assertSame( '25', wc_get_product( 741 )->get_meta( '_digitalogic_markup', true ) );
		$this->assertSame(
			'air_express',
			get_post_meta( 741, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true )
		);
		$this->assertSame( 'air_express', $result['results'][0]['after']['shipping_method_id'] );
	}

	/** A downstream shipping failure compensates an earlier product save. */
	public function test_partial_apply_failure_attempts_compensation() {
		$revision = $this->current_row()['record_revision'];
		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );
		$result = $this->service->apply(
			$this->payload(
				'apply-compensate-000741',
				$revision,
				array(
					'shipping_method_id' => 'air_express',
				)
			)
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertSame( 'digitalogic_shipping_commit_failed', $result['results'][0]['code'] );
		$this->assertTrue( $result['results'][0]['rollback']['attempted'] );
		$this->assertSame( '100', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( '', get_post_meta( 741, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) );
		$this->assertCount( 0, Digitalogic_Logger::instance()->entries );
	}

	/** Variable parents are never writable reconciliation rows. */
	public function test_variable_parent_is_rejected_as_non_leaf() {
		$GLOBALS['digitalogic_test_posts'][743] = $this->product_fixture( 'VAR-PARENT', 'variable' );
		$source_products                        = $this->source_products();
		$source_products['VAR-PARENT']          = array(
			'product_code' => 'VAR-PARENT',
			'name'         => 'Variable parent',
			'warnings'     => array(),
		);
		$this->set_source_products( $source_products );
		$revision = $this->current_row( 743 )['record_revision'];
		$result   = $this->service->preview(
			array(
				'idempotency_key' => 'preview-variable-parent',
				'changes'         => array(
					array(
						'sync_key'                 => 'woo:743',
						'patris_code'              => 'VAR-PARENT',
						'expected_record_revision' => $revision,
						'fields'                   => array( 'shipping_method_id' => 'air_express' ),
					),
				),
			)
		);

		$this->assertSame( 'conflict', $result['results'][0]['status'] );
		$this->assertSame( 'reconciliation_not_leaf', $result['results'][0]['code'] );
		$this->assertSame( 0, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
	}

	/** Compensation uses shipping CAS and never overwrites a later assignment. */
	public function test_shipping_compensation_preserves_later_assignment() {
		$revision = $this->current_row()['record_revision'];
		$once     = true;
		add_action(
			'digitalogic_product_shipping_method_updated',
			static function () use ( &$once ) {
				if ( ! $once ) {
					return;
				}
				$once = false;
				Digitalogic_Shipping_Method_Service::instance()->assign_product_by_code( '000741', 'sea_freight' );
			},
			10,
			2
		);
		$result = $this->service->apply(
			$this->payload(
				'apply-shipping-compensation-cas-000741',
				$revision,
				array( 'shipping_method_id' => 'air_express' )
			)
		);

		$this->assertSame( 'conflict', $result['results'][0]['status'] );
		$this->assertSame( 'post_apply_value_conflict', $result['results'][0]['code'] );
		$this->assertSame(
			'sea_freight',
			get_post_meta( 741, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true )
		);
		$this->assertSame( 'current_value_changed', $result['results'][0]['rollback']['skipped_fields']['shipping_method_id'] );
	}

	/** Shared profit margin cannot be overridden from an individual catalog row. */
	public function test_row_profit_override_is_rejected() {
		$product = wc_get_product( 741 );
		$product->update_meta_data( '_digitalogic_markup', '25' );
		$product->update_meta_data( '_digitalogic_markup_type', 'fixed' );
		$product->save();
		$revision = $this->current_row()['record_revision'];
		$payload  = $this->payload( 'preview-fixed-profit-000741', $revision, array( 'profit_percent' => null ) );
		$preview  = $this->service->preview( $payload );

		$this->assertSame( 'invalid', $preview['results'][0]['status'] );
		$this->assertSame( 'digitalogic_sheets_writeback_source_owned_field_forbidden', $preview['results'][0]['code'] );
		$this->assertSame( array( 'profit_percent' ), $preview['results'][0]['forbidden_fields'] );
		$this->assertSame( '25', $product->get_meta( '_digitalogic_markup', true ) );
		$this->assertSame( 'fixed', $product->get_meta( '_digitalogic_markup_type', true ) );
	}

	/** Compensation must preserve a concurrent change to a field this request did not own. */
	public function test_compensation_preserves_unrelated_concurrent_stock_change() {
		$revision                        = $this->current_row()['record_revision'];
		$GLOBALS['wpdb']->after_rollback = static function () {
			$product = wc_get_product( 741 );
			$product->set_stock_quantity( 99 );
			$product->save();
		};
		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );
		$result = $this->service->apply(
			$this->payload(
				'apply-preserve-stock-000741',
				$revision,
				array(
					'shipping_method_id' => 'air_express',
				)
			)
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertSame( '100', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( 99, wc_get_product( 741 )->get_stock_quantity() );
		$this->assertNotContains( 'stock_quantity', $result['results'][0]['rollback']['restored_fields'] );
	}

	/** The deprecated exact-code key remains a bounded compatibility path. */
	public function test_legacy_exact_code_key_remains_compatible() {
		$revision                          = $this->current_row()['record_revision'];
		$payload                           = $this->payload( 'preview-legacy-key-000741', $revision, array( 'shipping_method_id' => 'air_express' ) );
		$payload['changes'][0]['sync_key'] = '000741';
		$result                            = $this->service->preview( $payload );

		$this->assertSame( 'ready', $result['results'][0]['status'] );
		$this->assertSame( 741, $result['results'][0]['woocommerce_id'] );
		$this->assertSame( '000741', $result['results'][0]['sync_key'] );
	}

	/** A legitimate exact Product Code resembling a new key is disambiguated by current Woo identity. */
	public function test_legacy_code_that_looks_like_woo_key_is_not_misrouted() {
		$GLOBALS['digitalogic_test_posts'][744] = $this->product_fixture( 'woo:999' );
		$source_products                        = $this->source_products();
		$source_products['woo:999']             = array(
			'product_code' => 'woo:999',
			'name'         => 'Prefixed exact code',
			'warnings'     => array(),
		);
		$this->set_source_products( $source_products );
		$revision = $this->current_row( 744 )['record_revision'];
		$result   = $this->service->preview(
			array(
				'idempotency_key' => 'preview-prefixed-exact-code',
				'changes'         => array(
					array(
						'sync_key'                 => 'woo:999',
						'patris_code'              => 'woo:999',
						'expected_record_revision' => $revision,
						'fields'                   => array( 'shipping_method_id' => 'air_express' ),
					),
				),
			)
		);

		$this->assertSame( 'ready', $result['results'][0]['status'] );
		$this->assertSame( 744, $result['results'][0]['woocommerce_id'] );
		$this->assertSame( 'woo:999', $result['results'][0]['patris_code'] );
	}

	/** Patris-only and ambiguous identities remain visible but cannot accept writes. */
	public function test_unmatched_and_ambiguous_reconciliation_rows_fail_closed() {
		$source_products                        = $this->source_products();
		$source_products['PATRIS-ONLY']         = array(
			'product_code' => 'PATRIS-ONLY',
			'name'         => 'Patris only',
			'warnings'     => array(),
		);
		$source_products['DUPLICATE']           = array(
			'product_code' => 'DUPLICATE',
			'name'         => 'Ambiguous',
			'warnings'     => array(),
		);
		$GLOBALS['digitalogic_test_posts'][745] = $this->product_fixture( 'DUPLICATE' );
		$GLOBALS['digitalogic_test_posts'][746] = $this->product_fixture( 'DUPLICATE' );
		$this->set_source_products( $source_products );

		$patris_only        = $this->service->preview(
			array(
				'idempotency_key' => 'preview-patris-only-row',
				'changes'         => array(
					array(
						'sync_key'                 => 'PATRIS-ONLY',
						'patris_code'              => 'PATRIS-ONLY',
						'expected_record_revision' => 'sha256:' . str_repeat( 'a', 64 ),
						'fields'                   => array( 'shipping_method_id' => 'air_express' ),
					),
				),
			)
		);
		$ambiguous_revision = $this->current_row( 745 )['record_revision'];
		$ambiguous          = $this->service->preview(
			array(
				'idempotency_key' => 'preview-ambiguous-row',
				'changes'         => array(
					array(
						'sync_key'                 => 'woo:745',
						'patris_code'              => 'DUPLICATE',
						'expected_record_revision' => $ambiguous_revision,
						'fields'                   => array( 'shipping_method_id' => 'air_express' ),
					),
				),
			)
		);

		$this->assertSame( 'invalid', $patris_only['results'][0]['status'] );
		$this->assertSame( 'reconciliation_not_matched', $patris_only['results'][0]['code'] );
		$this->assertSame( 'invalid', $ambiguous['results'][0]['status'] );
		$this->assertSame( 'reconciliation_not_matched', $ambiguous['results'][0]['code'] );
		$this->assertSame( 0, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
	}

	/** Sale price is always blank because customer-visible and canonical price are identical. */
	public function test_sale_write_is_rejected_and_price_tuple_is_unchanged() {
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_sale_price_dates_from'] = time() + 86400;
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_sale_price_dates_to']   = time() + 172800;
		unset( $GLOBALS['digitalogic_test_wc_products'][741] );
		$revision = $this->current_row()['record_revision'];
		$result   = $this->service->apply(
			$this->payload( 'apply-scheduled-sale-000741', $revision, array( 'sale_price' => 80 ) )
		);

		$this->assertSame( 'invalid', $result['results'][0]['status'] );
		$this->assertSame( 'sale_price_forbidden', $result['results'][0]['code'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_set_price_calls'] );
		$this->assertSame( '', $GLOBALS['digitalogic_test_posts'][741]['meta']['_sale_price'] );
		$this->assertSame( '100', $GLOBALS['digitalogic_test_posts'][741]['meta']['_price'] );
	}

	/** All matched source-owned values fail closed instead of creating ecosystem drift. */
	public function test_all_source_owned_row_fields_are_rejected() {
		$revision = $this->current_row()['record_revision'];
		$result   = $this->service->preview(
			$this->payload(
				'preview-source-owned-000741',
				$revision,
				array(
					'regular_price'  => '120',
					'sale_price'     => null,
					'stock_quantity' => 6,
					'stock_status'   => 'instock',
					'profit_percent' => '30',
				)
			)
		);

		$this->assertSame( 'invalid', $result['results'][0]['status'] );
		$this->assertSame( 'digitalogic_sheets_writeback_source_owned_field_forbidden', $result['results'][0]['code'] );
		$this->assertSame(
			array( 'profit_percent', 'regular_price', 'sale_price', 'stock_quantity', 'stock_status' ),
			$result['results'][0]['forbidden_fields']
		);
		$this->assertSame( '100', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( '', wc_get_product( 741 )->get_sale_price() );
		$this->assertSame( '100', wc_get_product( 741 )->get_price() );
	}

	/** Distinct exact prices must invalidate an older optimistic revision. */
	public function test_exact_decimal_revision_change_is_detected_as_stale() {
		$product = wc_get_product( 741 );
		$product->set_regular_price( '999999999999998.000001' );
		$product->save();
		$revision = $this->current_row()['record_revision'];
		$product->set_regular_price( '999999999999998.000002' );
		$product->save();

		$result = $this->service->apply(
			$this->payload(
				'apply-stale-exact-decimal-000741',
				$revision,
				array( 'shipping_method_id' => 'air_express' )
			)
		);

		$this->assertSame( 'conflict', $result['results'][0]['status'] );
		$this->assertSame( 'record_revision_conflict', $result['results'][0]['code'] );
		$this->assertSame( '999999999999998.000002', $product->get_regular_price() );
	}

	/** Internal DB and exception messages never cross the row-result boundary. */
	public function test_internal_failure_messages_are_sanitized_and_server_errors_are_failed() {
		$revision                                     = $this->current_row()['record_revision'];
		$GLOBALS['wpdb']->identifier_query_failure    = true;
		$GLOBALS['wpdb']->identifier_query_last_error = 'SECRET DSN /var/private/mysql.sock';
		$result                                       = $this->service->preview(
			$this->payload( 'preview-db-failure-000741', $revision, array( 'shipping_method_id' => 'air_express' ) )
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $result['results'][0] ) );

		$GLOBALS['wpdb']->identifier_query_failure        = false;
		$GLOBALS['wpdb']->identifier_query_last_error     = '';
		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );
		$failed = $this->service->apply(
			$this->payload( 'apply-save-failure-000741', $revision, array( 'shipping_method_id' => 'air_express' ) )
		);
		$this->assertSame( 'failed', $failed['results'][0]['status'] );
		$this->assertSame( 'digitalogic_shipping_commit_failed', $failed['results'][0]['code'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $failed['results'][0] ) );
	}

	/** Canonical nullable shipping values replay under one request key. */
	public function test_idempotency_hash_uses_canonical_field_values() {
		$revision = $this->current_row()['record_revision'];
		$first    = $this->service->preview(
			$this->payload(
				'preview-canonical-000741',
				$revision,
				array( 'shipping_method_id' => '' )
			)
		);
		$second   = $this->service->preview(
			$this->payload(
				'preview-canonical-000741',
				$revision,
				array( 'shipping_method_id' => null )
			)
		);

		$this->assertFalse( $first['replayed'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( $first['results'], $second['results'] );
	}

	/** A stale idempotency owner cannot delete or replace its successor's lock. */
	public function test_stale_idempotency_owner_cannot_complete_newer_reservation() {
		$claim_method         = new ReflectionMethod( $this->service, 'claim_idempotency' );
		$claim                = $claim_method->invoke( $this->service, 'preview', 'preview-owner-000741', 'sha256:' . str_repeat( 'a', 64 ) );
		$newer                = get_option( $claim['lock_key'] );
		$newer['owner_token'] = 'newer-owner-token';
		update_option( $claim['lock_key'], $newer, false );
		$complete_method = new ReflectionMethod( $this->service, 'complete_idempotency' );
		$completed       = $complete_method->invoke( $this->service, $claim, 'sha256:' . str_repeat( 'a', 64 ), array( 'results' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $completed );
		$this->assertSame( 'idempotency_reservation_lost', $completed->get_error_code() );
		$this->assertSame( 'newer-owner-token', get_option( $claim['lock_key'] )['owner_token'] );
	}

	/** Sheets apply coordinates with the existing Patris product-sync lock. */
	public function test_apply_fails_retryably_when_patris_sync_lock_is_busy() {
		$revision                         = $this->current_row()['record_revision'];
		$GLOBALS['wpdb']->acquire_results = array( 1, 1, 1, 1, 0 );
		$result                           = $this->service->apply(
			$this->payload( 'apply-sync-lock-000741', $revision, array( 'shipping_method_id' => 'air_express' ) )
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertSame( 'product_sync_lock_busy', $result['results'][0]['code'] );
		$this->assertTrue( $result['results'][0]['retryable'] );
		$this->assertContains( 'digitalogic_product_sync_' . md5( 'wp_' ), $GLOBALS['wpdb']->lock_names );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Woo-only rows are visible in the union but cannot be forged into matched writeback rows. */
	public function test_woo_only_row_is_rejected_by_current_reconciliation() {
		$GLOBALS['digitalogic_test_posts'][742] = $this->product_fixture( 'WOO-ONLY' );
		$revision                               = $this->current_row( 742 )['record_revision'];
		$payload                                = array(
			'idempotency_key' => 'preview-woo-only-row',
			'changes'         => array(
				array(
					'sync_key'                 => 'woo:742',
					'patris_code'              => 'WOO-ONLY',
					'expected_record_revision' => $revision,
					'fields'                   => array( 'shipping_method_id' => 'air_express' ),
				),
			),
		);
		$result                                 = $this->service->preview( $payload );

		$this->assertSame( 'invalid', $result['results'][0]['status'] );
		$this->assertSame( 'reconciliation_not_matched', $result['results'][0]['code'] );
		$this->assertSame( 0, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
	}

	/** A maximum-size preview refreshes its owner heartbeat throughout the batch. */
	public function test_maximum_batch_heartbeats_reservation_per_row() {
		$changes         = array();
		$source_products = $this->source_products();
		for ( $offset = 0; $offset < Digitalogic_Google_Sheets_Writeback::MAX_CHANGES; $offset++ ) {
			$product_id                                       = 800 + $offset;
			$patris_code                                      = sprintf( 'P%04d', $product_id );
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = $this->product_fixture( $patris_code );
			$source_products[ $patris_code ]                  = array(
				'product_code' => $patris_code,
				'name'         => 'Heartbeat Product ' . $patris_code,
				'warnings'     => array(),
			);
			$changes[]                                        = array(
				'sync_key'                 => 'woo:' . $product_id,
				'patris_code'              => $patris_code,
				'expected_record_revision' => $this->current_row( $product_id )['record_revision'],
				'fields'                   => array( 'shipping_method_id' => 'air_express' ),
			);
		}
		$this->set_source_products( $source_products );
		$result = $this->service->preview(
			array(
				'idempotency_key' => 'preview-heartbeat-fifty',
				'changes'         => $changes,
			)
		);

		$this->assertSame( Digitalogic_Google_Sheets_Writeback::MAX_CHANGES, $result['summary']['ready'] );
		$sequences = array();
		foreach ( $GLOBALS['digitalogic_test_actions']['updated_option'] ?? array() as $action ) {
			if ( str_starts_with( (string) $action[0], 'digitalogic_gswb_lock_' ) ) {
				$sequences[] = (int) ( $action[2]['heartbeat_sequence'] ?? 0 );
			}
		}
		$this->assertNotEmpty( $sequences );
		$this->assertGreaterThanOrEqual( 4 * Digitalogic_Google_Sheets_Writeback::MAX_CHANGES, max( $sequences ) );
	}

	/** Unknown fields and non-exact identities fail as row-level typed results. */
	public function test_allowlist_and_exact_identity_fail_closed() {
		$revision  = $this->current_row()['record_revision'];
		$forbidden = $this->service->preview(
			$this->payload( 'preview-forbidden-000741', $revision, array( 'name' => 'Unsafe rename' ) )
		);
		$this->assertSame( 'invalid', $forbidden['results'][0]['status'] );
		$this->assertSame( 'digitalogic_sheets_writeback_field_forbidden', $forbidden['results'][0]['code'] );

		$mismatch_payload                           = $this->payload( 'preview-mismatch-000741', $revision, array( 'regular_price' => 120 ) );
		$mismatch_payload['changes'][0]['sync_key'] = 'SKU-741';
		$mismatch                                   = $this->service->preview( $mismatch_payload );
		$this->assertSame( 'identity_mismatch', $mismatch['results'][0]['code'] );

		$unknown_row_payload                         = $this->payload( 'preview-row-key-000741', $revision, array( 'regular_price' => 120 ) );
		$unknown_row_payload['changes'][0]['action'] = 'apply';
		$unknown_row                                 = $this->service->preview( $unknown_row_payload );
		$this->assertSame( 'row_field_forbidden', $unknown_row['results'][0]['code'] );

		$unknown_envelope_payload            = $this->payload( 'preview-envelope-key-000741', $revision, array( 'regular_price' => 120 ) );
		$unknown_envelope_payload['dry_run'] = false;
		$unknown_envelope                    = $this->service->preview( $unknown_envelope_payload );
		$this->assertInstanceOf( WP_Error::class, $unknown_envelope );
		$this->assertSame( 'digitalogic_sheets_writeback_envelope_field_forbidden', $unknown_envelope->get_error_code() );
		$this->assertSame( 0, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
	}

	/** Requests are bounded before any product resolution or mutation. */
	public function test_batch_limit_is_enforced_at_the_envelope() {
		$change                             = array(
			'sync_key'                 => 'woo:741',
			'patris_code'              => '000741',
			'expected_record_revision' => str_repeat( 'a', 64 ),
			'fields'                   => array( 'regular_price' => 120 ),
		);
		$change['expected_record_revision'] = 'sha256:' . $change['expected_record_revision'];
		$result                             = $this->service->preview(
			array(
				'idempotency_key' => 'preview-too-many-000741',
				'changes'         => array_fill( 0, Digitalogic_Google_Sheets_Writeback::MAX_CHANGES + 1, $change ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_sheets_writeback_too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
	}

	/** REST adapter exposes service statuses without leaking auth material. */
	public function test_rest_adapter_wraps_structural_errors_and_success() {
		$api     = Digitalogic_REST_API::instance();
		$invalid = $api->preview_google_sheets_writeback(
			new WP_REST_Request( array(), array( 'changes' => array() ) )
		);
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertFalse( $invalid->get_data()['success'] );
		$this->assertSame( 'digitalogic_sheets_writeback_idempotency_required', $invalid->get_data()['code'] );
		$this->assertArrayNotHasKey( 'credentials', $invalid->get_data() );

		$revision = $this->current_row()['record_revision'];
		$request  = new WP_REST_Request(
			array(),
			$this->payload( 'preview-rest-000741', $revision, array( 'shipping_method_id' => 'air_express' ) )
		);
		$response = $api->preview_google_sheets_writeback( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'ready', $response->get_data()['data']['results'][0]['status'] );
	}

	/**
	 * Return the current managed Products-row projection.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function current_row( $product_id = 741 ) {
		$product    = Digitalogic_Product_Manager::instance()->get_product( $product_id );
		$projection = Digitalogic_Google_Sheets_Catalog::instance()->transform_products( array( $product ) );
		$this->assertFalse( is_wp_error( $projection ) );

		return $projection['rows'][0];
	}

	/**
	 * Build another simple product for bounded-batch tests.
	 *
	 * @param string $patris_code Exact Patris Code.
	 * @param string $product_type WooCommerce product type.
	 * @return array
	 */
	private function product_fixture( $patris_code, $product_type = 'simple' ) {
		return array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Heartbeat Product ' . $patris_code,
			'product_type' => $product_type,
			'meta'         => array(
				'_digitalogic_patris_product_code' => $patris_code,
				'_sku'                             => 'SKU-' . $patris_code,
				'_regular_price'                   => '100',
				'_sale_price'                      => '',
				'_price'                           => '100',
				'_manage_stock'                    => 'yes',
				'_stock'                           => 4,
				'_stock_status'                    => 'instock',
			),
		);
	}

	/**
	 * Return the current exact-source product map.
	 *
	 * @return array
	 */
	private function source_products() {
		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] ?? array() );

		return null === $source_key ? array() : (array) ( $state['sources'][ $source_key ]['products'] ?? array() );
	}

	/**
	 * Replace exact-source fixtures and invalidate stateful readers.
	 *
	 * @param array $products Product map keyed by exact Product Code.
	 * @return void
	 */
	private function set_source_products( $products ) {
		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] ?? array() );
		$this->assertNotNull( $source_key );
		$state['sources'][ $source_key ]['products'] = $products;
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$this->reset_singleton( Digitalogic_Report_Engine::class );
	}

	/**
	 * Build one request envelope.
	 *
	 * @param string $idempotency_key Client request key.
	 * @param string $revision        Expected catalog record revision.
	 * @param array  $fields          Requested editable fields.
	 * @return array
	 */
	private function payload( $idempotency_key, $revision, $fields ) {
		return array(
			'idempotency_key' => $idempotency_key,
			'changes'         => array(
				array(
					'sync_key'                 => 'woo:741',
					'patris_code'              => '000741',
					'expected_record_revision' => $revision,
					'fields'                   => $fields,
				),
			),
		);
	}

	/**
	 * Reset one singleton between tests.
	 *
	 * @param string $class_name Singleton class name.
	 * @return void
	 */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
