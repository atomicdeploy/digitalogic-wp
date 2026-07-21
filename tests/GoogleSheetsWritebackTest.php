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
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();

		foreach (
			array(
				Digitalogic_Product_Identifier_Resolver::class,
				Digitalogic_Product_Manager::class,
				Digitalogic_Shipping_Method_Service::class,
				Digitalogic_WooCommerce_Currency_Status::class,
				Digitalogic_Google_Sheets_Catalog::class,
				Digitalogic_Google_Sheets_Writeback::class,
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
				'sync_key'                 => '000741',
				'patris_code'              => '000741',
				'expected_record_revision' => $revision,
				'fields'                   => array(
					'regular_price'  => '120.00',
					'sale_price'     => '110',
					'stock_quantity' => 6,
					'profit_percent' => '30',
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
		$this->assertSame( '120', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( '110', wc_get_product( 741 )->get_sale_price() );
		$this->assertSame( 6, wc_get_product( 741 )->get_stock_quantity() );
		$this->assertSame( '30', (string) wc_get_product( 741 )->get_meta( '_digitalogic_markup', true ) );
		$this->assertCount( 1, Digitalogic_Logger::instance()->entries );
		$this->assertNotSame( $revision, $applied['results'][0]['record_revision'] );
		$this->assertTrue( $applied['results'][0]['rollback']['available'] );

		$save_count = count( $GLOBALS['digitalogic_test_wc_product_saves'] );
		$replay     = $this->service->apply( $payload );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $save_count, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
		$this->assertCount( 1, Digitalogic_Logger::instance()->entries );

		$payload['changes'][0]['fields']['regular_price'] = '130';
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
			$this->payload( 'apply-conflict-000741', $revision, array( 'regular_price' => '120' ) )
		);
		$this->assertSame( 'conflict', $result['results'][0]['status'] );
		$this->assertSame( 'record_revision_conflict', $result['results'][0]['code'] );
		$this->assertSame( 1, $result['summary']['conflicts'] );
		$this->assertSame( '101', $product->get_regular_price() );
		$this->assertSame( $save_count, count( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
		$this->assertCount( 0, Digitalogic_Logger::instance()->entries );
	}

	/** Nullable fields clear overrides and shipping uses its canonical service. */
	public function test_nullable_fields_clear_and_shipping_assignment_apply() {
		$revision = $this->current_row()['record_revision'];
		$result   = $this->service->apply(
			$this->payload(
				'apply-clear-fields-000741',
				$revision,
				array(
					'sale_price'         => null,
					'shipping_method_id' => 'air_express',
					'profit_percent'     => null,
				)
			)
		);

		$this->assertSame( 'applied', $result['results'][0]['status'] );
		$this->assertSame( '', wc_get_product( 741 )->get_sale_price() );
		$this->assertSame( '', wc_get_product( 741 )->get_meta( '_digitalogic_markup', true ) );
		$this->assertSame(
			'air_express',
			get_post_meta( 741, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true )
		);
		$this->assertNull( $result['results'][0]['after']['profit_percent'] );
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
					'regular_price'      => 120,
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

	/** Clearing a legacy fixed markup is a real change with truthful recovery metadata. */
	public function test_null_profit_clears_fixed_markup_and_marks_manual_rollback_unavailable() {
		$product = wc_get_product( 741 );
		$product->update_meta_data( '_digitalogic_markup', '25' );
		$product->update_meta_data( '_digitalogic_markup_type', 'fixed' );
		$product->save();
		$revision = $this->current_row()['record_revision'];
		$payload  = $this->payload( 'preview-fixed-profit-000741', $revision, array( 'profit_percent' => null ) );
		$preview  = $this->service->preview( $payload );

		$this->assertSame( 'ready', $preview['results'][0]['status'] );
		$this->assertSame( 'fixed', $preview['results'][0]['before']['profit_percent_state']['markup_type'] );
		$payload['idempotency_key'] = 'apply-fixed-profit-000741';
		$applied                    = $this->service->apply( $payload );
		$this->assertSame( 'applied', $applied['results'][0]['status'] );
		$this->assertSame( '', $product->get_meta( '_digitalogic_markup', true ) );
		$this->assertSame( '', $product->get_meta( '_digitalogic_markup_type', true ) );
		$this->assertFalse( $applied['results'][0]['rollback']['available'] );
		$this->assertSame( 'legacy_profit_state_not_representable', $applied['results'][0]['rollback']['unavailable_reason'] );
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
					'regular_price'      => 120,
					'shipping_method_id' => 'air_express',
				)
			)
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertSame( '100', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( 99, wc_get_product( 741 )->get_stock_quantity() );
		$this->assertNotContains( 'stock_quantity', $result['results'][0]['rollback']['restored_fields'] );
	}

	/** Compensation must not overwrite a concurrent change to the same requested field. */
	public function test_compensation_skips_requested_field_changed_by_another_writer() {
		$revision                        = $this->current_row()['record_revision'];
		$GLOBALS['wpdb']->after_rollback = static function () {
			$product = wc_get_product( 741 );
			$product->set_regular_price( '150' );
			$product->save();
		};
		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );
		$result = $this->service->apply(
			$this->payload(
				'apply-preserve-price-000741',
				$revision,
				array(
					'regular_price'      => 120,
					'shipping_method_id' => 'air_express',
				)
			)
		);

		$this->assertSame( '150', wc_get_product( 741 )->get_regular_price() );
		$this->assertFalse( $result['results'][0]['rollback']['success'] );
		$this->assertSame( 'current_value_changed', $result['results'][0]['rollback']['skipped_fields']['regular_price'] );
	}

	/** WooCommerce, not this bridge, owns effective _price and sale scheduling. */
	public function test_sale_write_never_manually_sets_effective_price() {
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_sale_price_dates_from'] = time() + 86400;
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_sale_price_dates_to']   = time() + 172800;
		unset( $GLOBALS['digitalogic_test_wc_products'][741] );
		$revision = $this->current_row()['record_revision'];
		$result   = $this->service->apply(
			$this->payload( 'apply-scheduled-sale-000741', $revision, array( 'sale_price' => 80 ) )
		);

		$this->assertSame( 'applied', $result['results'][0]['status'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_set_price_calls'] );
		$this->assertSame( '100', $GLOBALS['digitalogic_test_posts'][741]['meta']['_price'] );
	}

	/** Decimal bounds and price ordering remain exact beyond IEEE-754 precision. */
	public function test_decimal_boundaries_and_sale_order_do_not_use_float_comparisons() {
		$product = wc_get_product( 741 );
		$product->set_regular_price( '999999999999998.000001' );
		$product->save();
		$revision = $this->current_row()['record_revision'];
		$sale     = $this->service->preview(
			$this->payload( 'preview-exact-sale-000741', $revision, array( 'sale_price' => '999999999999998.000002' ) )
		);
		$this->assertSame( 'conflict', $sale['results'][0]['status'] );
		$this->assertSame( 'sale_price_exceeds_regular_price', $sale['results'][0]['code'] );
		$this->assertNull( $sale['results'][0]['before']['sale_price'] );

		$maximum = $this->service->preview(
			$this->payload( 'preview-exact-maximum-000741', $revision, array( 'regular_price' => '999999999999999.000001' ) )
		);
		$this->assertSame( 'invalid', $maximum['results'][0]['status'] );
		$this->assertSame( 'regular_price_out_of_range', $maximum['results'][0]['code'] );

		$exact = $this->service->preview(
			$this->payload( 'preview-exact-output-000741', $revision, array( 'regular_price' => '999999999999998.000002' ) )
		);
		$this->assertSame( '999999999999998.000001', $exact['results'][0]['before']['regular_price'] );
		$this->assertSame( '999999999999998.000002', $exact['results'][0]['after']['regular_price'] );
	}

	/** Internal DB and exception messages never cross the row-result boundary. */
	public function test_internal_failure_messages_are_sanitized_and_server_errors_are_failed() {
		$revision                                     = $this->current_row()['record_revision'];
		$GLOBALS['wpdb']->identifier_query_failure    = true;
		$GLOBALS['wpdb']->identifier_query_last_error = 'SECRET DSN /var/private/mysql.sock';
		$result                                       = $this->service->preview(
			$this->payload( 'preview-db-failure-000741', $revision, array( 'regular_price' => 120 ) )
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $result['results'][0] ) );

		$GLOBALS['wpdb']->identifier_query_failure    = false;
		$GLOBALS['wpdb']->identifier_query_last_error = '';
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 741 );
		$failed                                       = $this->service->apply(
			$this->payload( 'apply-save-failure-000741', $revision, array( 'regular_price' => 120 ) )
		);
		$this->assertSame( 'failed', $failed['results'][0]['status'] );
		$this->assertStringNotContainsString( 'Injected', wp_json_encode( $failed['results'][0] ) );
		$this->assertContains( 'product_restore_failed', $failed['results'][0]['rollback']['errors'] );
	}

	/** Canonical field order and numeric spellings replay under one request key. */
	public function test_idempotency_hash_uses_canonical_field_values_and_key_order() {
		$revision = $this->current_row()['record_revision'];
		$first    = $this->service->preview(
			$this->payload(
				'preview-canonical-000741',
				$revision,
				array(
					'regular_price'  => '120.00',
					'profit_percent' => '30.0',
				)
			)
		);
		$second   = $this->service->preview(
			$this->payload(
				'preview-canonical-000741',
				$revision,
				array(
					'profit_percent' => 30,
					'regular_price'  => 120,
				)
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
			$this->payload( 'apply-sync-lock-000741', $revision, array( 'regular_price' => 120 ) )
		);

		$this->assertSame( 'failed', $result['results'][0]['status'] );
		$this->assertSame( 'product_sync_lock_busy', $result['results'][0]['code'] );
		$this->assertTrue( $result['results'][0]['retryable'] );
		$this->assertContains( 'digitalogic_product_sync_' . md5( 'wp_' ), $GLOBALS['wpdb']->lock_names );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** A save hook overwrite is detected and preserved instead of reported applied. */
	public function test_post_write_verification_detects_and_preserves_hook_overwrite() {
		$revision                                  = $this->current_row()['record_revision'];
		$GLOBALS['digitalogic_test_wc_after_save'] = static function ( $product ) {
			$product->set_regular_price( '130' );
			$product->save();
		};
		$result                                    = $this->service->apply(
			$this->payload( 'apply-hook-overwrite-000741', $revision, array( 'regular_price' => 120 ) )
		);

		$this->assertSame( 'conflict', $result['results'][0]['status'] );
		$this->assertSame( 'post_apply_value_conflict', $result['results'][0]['code'] );
		$this->assertSame( '130', wc_get_product( 741 )->get_regular_price() );
		$this->assertSame( 'current_value_changed', $result['results'][0]['rollback']['skipped_fields']['regular_price'] );
		$this->assertCount( 0, Digitalogic_Logger::instance()->entries );
	}

	/** A maximum-size preview refreshes its owner heartbeat throughout the batch. */
	public function test_maximum_batch_heartbeats_reservation_per_row() {
		$changes = array();
		for ( $offset = 0; $offset < Digitalogic_Google_Sheets_Writeback::MAX_CHANGES; $offset++ ) {
			$product_id                                       = 800 + $offset;
			$patris_code                                      = sprintf( 'P%04d', $product_id );
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = $this->product_fixture( $patris_code );
			$changes[]                                        = array(
				'sync_key'                 => $patris_code,
				'patris_code'              => $patris_code,
				'expected_record_revision' => $this->current_row( $product_id )['record_revision'],
				'fields'                   => array( 'regular_price' => 120 ),
			);
		}
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
			'sync_key'                 => '000741',
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
			$this->payload( 'preview-rest-000741', $revision, array( 'regular_price' => 120 ) )
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
	 * @return array
	 */
	private function product_fixture( $patris_code ) {
		return array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Heartbeat Product ' . $patris_code,
			'product_type' => 'simple',
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
					'sync_key'                 => '000741',
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
