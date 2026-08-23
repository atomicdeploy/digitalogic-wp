<?php
/**
 * Canonical Product Code editor tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify bounded, audited, source-safe Product Code edits. */
final class ProductCodeEditorTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Digitalogic_Product_Code_Editor
	 */
	private $editor;

	/** Reset all mutable service and WordPress test state. */
	protected function setUp(): void {
		parent::setUp();
		$source                                       = array(
			'id'       => 'product-code-tests',
			'dataset'  => 'catalog',
			'revision' => 'sha256:' . str_repeat( 'c', 64 ),
		);
		$source_key                                   = hash( 'sha256', $source['id'] . "\n" . $source['dataset'] );
		$generation_option                            = ( new ReflectionClass( Digitalogic_Report_Engine::class ) )
			->getReflectionConstant( 'CACHE_GENERATION_OPTION' )
			->getValue();
		$GLOBALS['digitalogic_test_actions']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['digitalogic_test_filters']          = array();
		$GLOBALS['digitalogic_test_options']          = array(
			$generation_option => 'product-code-test-generation',
			Digitalogic_Product_Sync_Receiver::STATE_OPTION => array(
				'sources' => array(
					$source_key => array(
						'source'            => $source,
						'products'          => array(),
						'applied_products'  => array(),
						'pending_products'  => array(),
						'deferred_products' => array(),
					),
				),
			),
		);
		$GLOBALS['digitalogic_test_option_cache']     = array();
		$GLOBALS['digitalogic_test_posts']            = array(
			741 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'meta'        => array(
					'_digitalogic_patris_product_code' => '000741',
					'_sku'                             => 'SKU-741',
				),
			),
		);
		$GLOBALS['digitalogic_test_post_meta_cache']  = array();
		$GLOBALS['digitalogic_test_meta_by_mid']      = array();
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_meta_update_failures']         = array();
		$GLOBALS['digitalogic_test_meta_delete_failures']         = array();
		$GLOBALS['digitalogic_test_option_delete_failures']       = array();
		$GLOBALS['digitalogic_test_update_failures']              = array();
		$GLOBALS['digitalogic_test_cache_deletes']                = array();
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		$GLOBALS['digitalogic_test_capabilities']                 = array(
			'manage_woocommerce' => true,
			'edit_post'          => true,
		);
		$GLOBALS['digitalogic_test_current_user_id']              = 17;
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

		$this->reset_singleton( Digitalogic_Product_Code_Editor::class );
		$this->reset_singleton( Digitalogic_Product_Code_Write_Guard::class );
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$this->reset_singleton( Digitalogic_Report_Engine::class );
		$this->reset_singleton( Digitalogic_Pricing_Snapshot::class );
		Digitalogic_Logger::instance()->entries = array();
		Digitalogic_Product_Code_Write_Guard::instance();
		$this->editor = Digitalogic_Product_Code_Editor::instance();
	}

	/** Leading zeroes survive the dedicated write, audit, and exact readback. */
	public function test_applies_leading_zero_code_with_fail_fast_locks_and_narrow_invalidation(): void {
		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:leading-zero' ) );

		$this->assertIsArray( $result );
		$this->assertSame( Digitalogic_Product_Code_Editor::SCHEMA, $result['schema'] );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertTrue( $result['changed'] );
		$this->assertFalse( $result['replayed'] );
		$this->assertSame( '000741', $result['previous_product_code'] );
		$this->assertSame( '000742', $result['product_code'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$this->assertSame( array( 0, 0, 0, 1, 1 ), $GLOBALS['wpdb']->lock_timeouts );
		$this->assertSame( 5, $GLOBALS['wpdb']->acquire_count );
		$this->assertSame( 5, $GLOBALS['wpdb']->release_count );
		$this->assertSame(
			Digitalogic_Product_Sync_Receiver::source_identity_lock_name( 'wp_' ),
			$GLOBALS['wpdb']->lock_names[0]
		);
		$this->assertContains( 'product_741', $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertArrayNotHasKey( 'digitalogic_product_code_updated', $GLOBALS['digitalogic_test_actions'] );
		$this->assertArrayNotHasKey( 'digitalogic_product_sync_applied', $GLOBALS['digitalogic_test_actions'] );
		$this->assertArrayNotHasKey( 'digitalogic_pricing_settings_changed', $GLOBALS['digitalogic_test_actions'] );

		$ledger = $GLOBALS['digitalogic_test_options']['digitalogic_product_code_edit_operations'];
		$this->assertSame( Digitalogic_Product_Code_Editor::SCHEMA, $ledger['schema'] );
		$this->assertCount( 1, $ledger['operations'] );
		$record = reset( $ledger['operations'] );
		$this->assertSame( 'completed', $record['status'] );
		$this->assertSame( '000741', $record['rollback_data']['product_code'] );
		$this->assertTrue( $record['rollback_data']['meta_exists'] );
		$this->assertSame( 17, $record['actor_id'] );
		$this->assertSame(
			$record,
			$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( 'product-code:741:leading-zero' ) ]
		);
	}

	/** A completed request replays before stale expected-state checks. */
	public function test_completed_request_replays_without_a_second_product_write(): void {
		$request = $this->request( '000742', 'product-code:741:replay' );
		$first   = $this->editor->edit( $request );
		$actions = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() );
		$GLOBALS['digitalogic_test_option_cache'][ $this->operation_option_name( $request['request_id'] ) ] = array();
		$replay = $this->editor->edit( $request );

		$this->assertSame( $first['request_fingerprint'], $replay['request_fingerprint'] );
		$this->assertSame( $first['product_code'], $replay['product_code'] );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $actions, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
		$this->assertSame( 7, $GLOBALS['wpdb']->acquire_count, 'Replay adds only the shared source and short operation locks.' );
	}

	/** Historical replay returns a separate DB-fresh current row projection. */
	public function test_historical_replay_cannot_replace_a_newer_current_row_state(): void {
		$first_request = $this->request( '000742', 'product-code:741:historical-a' );
		$first         = $this->editor->edit( $first_request );
		$second        = $this->editor->edit(
			array(
				'product_id'    => 741,
				'expected_code' => '000742',
				'product_code'  => '000743',
				'if_match'      => $this->editor->revision_for( 741, '000742' ),
				'request_id'    => 'product-code:741:historical-b',
			)
		);
		$write_count   = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() );

		$replay = $this->editor->edit( $first_request );

		$this->assertSame( '000742', $first['product_code'] );
		$this->assertSame( '000743', $second['product_code'] );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( '000742', $replay['product_code'], 'The immutable audit result remains historical.' );
		$this->assertSame( '000743', $replay['current_product_code'] );
		$this->assertSame( $this->editor->revision_for( 741, '000743' ), $replay['current_revision'] );
		$this->assertSame(
			array(
				'database_readback' => true,
				'cache_bypassed'    => true,
			),
			$replay['current_readback']
		);
		$this->assertSame( $write_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
	}

	/** One edit advances the Living projection once; replay has no new effect. */
	public function test_success_invalidates_projection_once_and_replay_is_effect_free(): void {
		$request           = $this->request( '000742', 'product-code:741:living-invalidation' );
		$first             = $this->editor->edit( $request );
		$count             = count( $GLOBALS['digitalogic_test_actions']['digitalogic_report_projection_invalidated'] ?? array() );
		$generation_option = ( new ReflectionClass( Digitalogic_Report_Engine::class ) )
			->getReflectionConstant( 'CACHE_GENERATION_OPTION' )
			->getValue();
		$generation        = $GLOBALS['digitalogic_test_options'][ $generation_option ];

		$replay = $this->editor->edit( $request );

		$this->assertSame( 'applied', $first['status'] );
		$this->assertTrue( $first['projection']['state_revision_event_durable'] );
		$this->assertSame( 1, $count );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $count, count( $GLOBALS['digitalogic_test_actions']['digitalogic_report_projection_invalidated'] ?? array() ) );
		$this->assertSame( $generation, $GLOBALS['digitalogic_test_options'][ $generation_option ] );
	}

	/** A throwable after the write preserves the same key for exact recovery. */
	public function test_throwable_after_write_returns_retryable_same_key_and_recovers(): void {
		$request = $this->request( '000742', 'product-code:741:throwable-recovery' );
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $product_id, $meta_key, $meta_value ) {
				unset( $meta_id );
				if ( 741 === (int) $product_id && '_digitalogic_patris_product_code' === $meta_key && '000742' === $meta_value ) {
					throw new RuntimeException( 'Injected post-write failure.' );
				}
			},
			10,
			4
		);

		$uncertain = $this->editor->edit( $request );

		$this->assertInstanceOf( WP_Error::class, $uncertain );
		$this->assertSame( 'digitalogic_product_code_retry_required', $uncertain->get_error_code() );
		$this->assertSame( 503, $uncertain->get_error_data()['status'] );
		$this->assertTrue( $uncertain->get_error_data()['retryable'] );
		$this->assertSame( $request['request_id'], $uncertain->get_error_data()['request_id'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$this->assertSame(
			'in_progress',
			$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status']
		);

		$GLOBALS['digitalogic_test_action_callbacks']['updated_post_meta'] = array();
		$write_count = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] );
		$recovered   = $this->editor->edit( $request );

		$this->assertIsArray( $recovered );
		$this->assertTrue( $recovered['recovered'] );
		$this->assertSame( $request['request_id'], $recovered['request_id'] );
		$this->assertSame( $write_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ) );
		$this->assertSame(
			'completed',
			$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status']
		);
	}

	/** A request ID cannot be rebound to a different desired code. */
	public function test_request_id_reuse_with_different_payload_is_rejected(): void {
		$request = $this->request( '000742', 'product-code:741:reuse-check' );
		$this->assertIsArray( $this->editor->edit( $request ) );
		$request['product_code'] = '000743';

		$result = $this->editor->edit( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_request_id_reused', $result->get_error_code() );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
	}

	/** A malformed terminal ledger record is never trusted or re-executed. */
	public function test_malformed_terminal_audit_record_fails_closed(): void {
		$request   = $this->request( '000742', 'product-code:741:bad-terminal' );
		$method    = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$validated = $method->invoke( $this->editor, $request );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = array(
			'status'              => 'completed',
			'request_fingerprint' => $validated['fingerprint'],
			'result'              => array( 'request_id' => 'different-request' ),
		);

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $result->get_error_code() );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Summary eviction never deletes the durable per-request replay record. */
	public function test_audit_summary_eviction_cannot_make_an_old_request_executable_again(): void {
		$store          = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'store_operation' );
		$old_request_id = 'product-code:retention:0';
		$old_record     = array( 'sequence' => 0 );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $old_request_id ) ] = $old_record;
		$seed = array();
		for ( $index = 1023; $index >= 0; --$index ) {
			$seed[ hash( 'sha256', 'product-code:retention:' . $index ) ] = array( 'sequence' => $index );
		}
		$GLOBALS['digitalogic_test_options']['digitalogic_product_code_edit_operations'] = array(
			'schema'     => Digitalogic_Product_Code_Editor::SCHEMA,
			'operations' => $seed,
		);

		$result = $store->invoke(
			$this->editor,
			'product-code:retention:1024',
			array( 'sequence' => 1024 )
		);

		$operations = $GLOBALS['digitalogic_test_options']['digitalogic_product_code_edit_operations']['operations'];
		$this->assertTrue( $result );
		$this->assertCount( 1024, $operations );
		$this->assertArrayHasKey( hash( 'sha256', 'product-code:retention:1024' ), $operations );
		$this->assertArrayNotHasKey( hash( 'sha256', 'product-code:retention:0' ), $operations );
		$this->assertSame( $old_record, $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $old_request_id ) ] );
		$lookup = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'operation_record' );
		$this->assertSame( $old_record, $lookup->invoke( $this->editor, $old_request_id ) );
	}

	/** A broken navigation summary never contradicts the authoritative operation record. */
	public function test_broken_audit_summary_cannot_rollback_a_verified_terminal_operation(): void {
		$request = $this->request( '000742', 'product-code:741:broken-summary' );
		$GLOBALS['digitalogic_test_options']['digitalogic_product_code_edit_operations'] = array(
			'schema'     => 'corrupted-summary',
			'operations' => 'not-an-array',
		);

		$result = $this->editor->edit( $request );
		$replay = $this->editor->edit( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$this->assertSame(
			'completed',
			$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status']
		);
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( '000742', $replay['product_code'] );
	}

	/** An uncertain terminal-record readback never rolls back a verified product write. */
	public function test_terminal_audit_readback_failure_preserves_after_state_for_same_key_recovery(): void {
		$request        = $this->request( '000742', 'product-code:741:terminal-readback' );
		$operation_name = $this->operation_option_name( $request['request_id'] );
		add_action(
			'updated_option_' . $operation_name,
			static function ( $old_value, $value ) use ( $operation_name ) {
				unset( $old_value );
				if ( 'completed' === (string) ( $value['status'] ?? '' ) ) {
					unset( $GLOBALS['digitalogic_test_options'][ $operation_name ] );
					$GLOBALS['wpdb']->last_error = 'Injected terminal readback failure.';
				}
			},
			10,
			2
		);

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_completion_pending', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['updated_post_meta'] );
	}

	/** Both exact expected-old and If-Match state are mandatory preconditions. */
	public function test_stale_expected_code_and_if_match_fail_without_writing(): void {
		$stale_code                  = $this->request( '000742', 'product-code:741:stale-code' );
		$stale_code['expected_code'] = '000740';
		$result                      = $this->editor->edit( $stale_code );
		$this->assertSame( 'digitalogic_product_code_precondition_failed', $result->get_error_code() );
		$this->assertSame( 'expected_code', $result->get_error_data()['failed_field'] );

		$stale_revision             = $this->request( '000742', 'product-code:741:stale-revision' );
		$stale_revision['if_match'] = 'sha256:' . str_repeat( 'a', 64 );
		$result                     = $this->editor->edit( $stale_revision );
		$this->assertSame( 'digitalogic_product_code_precondition_failed', $result->get_error_code() );
		$this->assertSame( 'if_match', $result->get_error_data()['failed_field'] );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Exact uniqueness covers both products and variations. */
	public function test_duplicate_code_on_variation_is_rejected(): void {
		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => '000842' ),
		);

		$result = $this->editor->edit( $this->request( '000842', 'product-code:741:duplicate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_not_unique', $result->get_error_code() );
		$this->assertSame( array( '842' ), $result->get_error_data()['woocommerce_ids'] );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
	}

	/** Uniqueness includes canonical-equivalent meta keys even on a binary-collated table. */
	public function test_duplicate_case_variant_key_is_rejected_independent_of_database_collation(): void {
		$GLOBALS['wpdb']->product_code_meta_key_binary_collation = true;

		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'trash',
			'meta'        => array( '_DIGITALOGIC_PATRIS_PRODUCT_CODE' => '000842' ),
		);

		$result = $this->editor->edit( $this->request( '000842', 'product-code:741:binary-collation-duplicate' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_not_unique', $result->get_error_code() );
		$this->assertSame( array( '842' ), $result->get_error_data()['woocommerce_ids'] );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
	}

	/** A supported source writer cannot interleave after the editor uniqueness check. */
	public function test_concurrent_feed_writer_fails_fast_while_editor_holds_shared_identity_lock(): void {
		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => 'OTHER-842' ),
		);
		$writer_result                          = null;
		$interleaved                            = false;
		add_action(
			'updated_post_meta',
			function ( $meta_id, $product_id, $meta_key ) use ( &$writer_result, &$interleaved ) {
				unset( $meta_id );
				if ( $interleaved || 741 !== (int) $product_id || Digitalogic_Product_Code_Editor::META_KEY !== $meta_key ) {
					return;
				}
				$interleaved                = true;
				$editor_db                  = $GLOBALS['wpdb'];
				$receiver_property          = new ReflectionProperty( Digitalogic_Product_Sync_Receiver::class, 'instance' );
				$editor_receiver            = $receiver_property->getValue();
				$writer_db                  = new Digitalogic_Test_WPDB();
				$writer_db->acquire_results = array( 0 );
				$GLOBALS['wpdb']            = $writer_db;
				$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
				try {
					$writer_result = Digitalogic_Patris_Feed::instance()->apply_product_feed(
						wc_get_product( 842 ),
						array( 'product_code' => '000742' )
					);
				} finally {
					$GLOBALS['wpdb'] = $editor_db;
					$receiver_property->setValue( null, $editor_receiver );
				}
			},
			10,
			3
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:concurrent-writer' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['changed'] );
		$this->assertInstanceOf( WP_Error::class, $writer_result );
		$this->assertSame( 'digitalogic_product_sync_busy', $writer_result->get_error_code() );
		$this->assertSame( 'OTHER-842', $GLOBALS['digitalogic_test_posts'][842]['meta']['_digitalogic_patris_product_code'] );
	}

	/** A source record hash makes the identity source-governed and immutable here. */
	public function test_managed_record_hash_is_a_hard_source_guard(): void {
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_record_hash'] = 'sha256:' . str_repeat( 'b', 64 );

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:managed-hash' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'managed_record_hash', $result->get_error_data()['reasons'] );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
	}

	/** Empty provenance rows are still source-ownership evidence. */
	public function test_empty_record_hash_row_fails_closed(): void {
		$GLOBALS['digitalogic_test_posts'][741]['meta_rows']['_digitalogic_patris_record_hash'] = array( '' );

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:empty-hash' ) );

		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'empty_record_hash_provenance', $result->get_error_data()['reasons'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Duplicate provenance rows are ambiguous even when each hash is valid. */
	public function test_duplicate_record_hash_rows_fail_closed(): void {
		$GLOBALS['digitalogic_test_posts'][741]['meta_rows']['_digitalogic_patris_record_hash'] = array(
			'sha256:' . str_repeat( 'a', 64 ),
			'sha256:' . str_repeat( 'b', 64 ),
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:duplicate-hash' ) );

		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'duplicate_record_hash_provenance', $result->get_error_data()['reasons'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Durable materializer ownership survives source rotation and remains immutable here. */
	public function test_materializer_owner_trio_without_record_hash_is_source_managed(): void {
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_owner_source_id']    = 'retired-source';
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_owner_dataset']      = 'retired-dataset';
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_owner_product_code'] = '000741';

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:materializer-owner' ) );

		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'managed_materializer_owner', $result->get_error_data()['reasons'] );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Partial, duplicate, and case-variant materializer provenance all fail closed. */
	public function test_malformed_materializer_owner_provenance_is_rejected_before_effect(): void {
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_owner_source_id'] = 'partial-source';
		$partial = $this->editor->edit( $this->request( '000742', 'product-code:741:owner-partial' ) );
		$this->assertSame( 'digitalogic_product_code_source_managed', $partial->get_error_code() );
		$this->assertContains( 'partial_materializer_owner_provenance', $partial->get_error_data()['reasons'] );

		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_owner_dataset']      = 'dataset';
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_owner_product_code'] = '000741';
		$GLOBALS['digitalogic_test_posts'][741]['meta_rows']['_digitalogic_patris_owner_dataset'] = array( 'dataset', 'dataset' );
		$duplicate = $this->editor->edit( $this->request( '000742', 'product-code:741:owner-duplicate' ) );
		$this->assertSame( 'digitalogic_product_code_source_managed', $duplicate->get_error_code() );
		$this->assertContains( 'duplicate_materializer_owner_provenance', $duplicate->get_error_data()['reasons'] );

		unset( $GLOBALS['digitalogic_test_posts'][741]['meta_rows']['_digitalogic_patris_owner_dataset'] );
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_DIGITALOGIC_PATRIS_OWNER_DATASET'] = 'variant';
		$variant = $this->editor->edit( $this->request( '000742', 'product-code:741:owner-variant' ) );
		$this->assertSame( 'digitalogic_product_code_meta_conflict', $variant->get_error_code() );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** An unbound Woo row still cannot claim a code present in the live source. */
	public function test_desired_code_in_product_sync_snapshot_is_blocked(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'fixture' => array(
					'source'            => array(
						'id'       => 'patris-office',
						'dataset'  => 'kala.db',
						'revision' => 'sha256:' . str_repeat( 'c', 64 ),
					),
					'products'          => array(
						'000742' => array(
							'product_code' => '000742',
							'record_hash'  => 'sha256:' . str_repeat( 'd', 64 ),
						),
					),
					'applied_products'  => array(),
					'pending_products'  => array(),
					'deferred_products' => array(),
				),
			),
		);

		$GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array( 'sources' => array() );

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:source-conflict' ) );

		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'desired_code_in_source', $result->get_error_data()['reasons'] );
		$this->assertSame( 'patris-office', $result->get_error_data()['sources'][0]['id'] );
	}

	/** A delivery-only row still proves source ownership of the current code. */
	public function test_current_code_in_delivery_only_state_is_source_governed(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'fixture' => array(
					'source'            => array(
						'id'       => 'patris-office',
						'dataset'  => 'kala.db',
						'revision' => 'sha256:' . str_repeat( 'c', 64 ),
					),
					'products'          => array(),
					'applied_products'  => array(),
					'pending_products'  => array(
						'000741' => array(
							'product_code' => '000741',
							'record_hash'  => 'sha256:' . str_repeat( 'd', 64 ),
						),
					),
					'deferred_products' => array(),
				),
			),
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:delivery-current' ) );

		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'current_code_in_delivery_state', $result->get_error_data()['reasons'] );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Legacy feed rows govern both their bound product and an unbound desired code. */
	public function test_legacy_feed_snapshot_without_record_hash_is_source_governed(): void {
		$GLOBALS['digitalogic_test_posts'][742]       = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => 'WOO-742' ),
		);
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$this->reset_singleton( Digitalogic_Product_Identifier_Resolver::class );
		$this->reset_singleton( Digitalogic_Patris_Feed::class );
		$import = Digitalogic_Patris_Feed::instance()->import_payload(
			array(
				'products' => array(
					array(
						'product_code' => '000741',
						'name'         => 'Legacy bound row',
					),
					array(
						'product_code' => 'LEGACY-ONLY-742',
						'name'         => 'Legacy source-only row',
					),
				),
			),
			'test'
		);

		$this->assertSame( 1, $import['updated'] );
		$this->assertSame( 1, $import['missing_in_woocommerce'] );
		$this->assertArrayNotHasKey( '_digitalogic_patris_record_hash', $GLOBALS['digitalogic_test_posts'][741]['meta'] );
		$editability = $this->admin_editability( 741, '000741' );
		$this->assertFalse( $editability['editable'] );
		$this->assertSame( 'source_managed', $editability['reason'] );

		$bound = $this->editor->edit( $this->request( 'OWNER-741', 'product-code:741:legacy-bound' ) );
		$this->assertSame( 'digitalogic_product_code_source_managed', $bound->get_error_code() );
		$this->assertContains( 'current_code_in_legacy_feed', $bound->get_error_data()['reasons'] );

		$claim = $this->editor->edit(
			array(
				'product_id'    => 742,
				'expected_code' => 'WOO-742',
				'product_code'  => 'LEGACY-ONLY-742',
				'if_match'      => $this->editor->revision_for( 742, 'WOO-742' ),
				'request_id'    => 'product-code:742:legacy-desired',
			)
		);
		$this->assertSame( 'digitalogic_product_code_source_managed', $claim->get_error_code() );
		$this->assertContains( 'desired_code_in_legacy_feed', $claim->get_error_data()['reasons'] );
		$this->assertSame( 'WOO-742', $GLOBALS['digitalogic_test_posts'][742]['meta']['_digitalogic_patris_product_code'] );
	}

	/** Admin rows expose source governance before offering an edit control. */
	public function test_editability_hint_fails_closed_for_a_current_source_code(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'fixture' => array(
					'source'            => array(
						'id'       => 'patris-office',
						'dataset'  => 'kala.db',
						'revision' => 'sha256:' . str_repeat( 'c', 64 ),
					),
					'products'          => array(
						'000741' => array(
							'product_code' => '000741',
							'record_hash'  => 'sha256:' . str_repeat( 'd', 64 ),
						),
					),
					'applied_products'  => array(),
					'pending_products'  => array(),
					'deferred_products' => array(),
				),
			),
		);

		$editability = $this->admin_editability( 741, '000741' );

		$this->assertFalse( $editability['editable'] );
		$this->assertSame( 'source_managed', $editability['reason'] );
	}

	/** Admin lookup cost is constant for one or one hundred prepared rows. */
	public function test_admin_read_batch_has_constant_query_count_and_o1_row_lookups(): void {
		for ( $product_id = 742; $product_id <= 840; ++$product_id ) {
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = array(
				'post_type'   => 0 === $product_id % 2 ? 'product' : 'product_variation',
				'post_status' => 'publish',
				'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => 'BATCH-' . $product_id ),
			);
		}
		$ids = range( 741, 840 );

		$this->editor->reset_editability_cache();
		$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 741 ) ) );
		$this->assertTrue( $this->editor->editability_for( 741, '000741' )['editable'] );
		$this->assertSame( array(), $this->editor->recovery_intent_for( 741 ) );
		$one_readback_queries = $GLOBALS['wpdb']->product_code_readback_batch_query_count;
		$one_option_queries   = $GLOBALS['wpdb']->product_code_options_batch_query_count;

		$this->editor->reset_editability_cache();
		$before_readbacks = $GLOBALS['wpdb']->product_code_readback_batch_query_count;
		$before_options   = $GLOBALS['wpdb']->product_code_options_batch_query_count;
		$this->assertTrue( $this->editor->prepare_admin_read_batch( $ids ) );
		foreach ( $ids as $product_id ) {
			$cached_code = 741 === $product_id ? '000741' : 'BATCH-' . $product_id;
			$this->assertTrue( $this->editor->editability_for( $product_id, $cached_code )['editable'] );
			$this->assertSame( array(), $this->editor->recovery_intent_for( $product_id ) );
		}
		$this->assertSame( $one_readback_queries, $GLOBALS['wpdb']->product_code_readback_batch_query_count - $before_readbacks );
		$this->assertSame( $one_option_queries, $GLOBALS['wpdb']->product_code_options_batch_query_count - $before_options );
		$builds = new ReflectionProperty( Digitalogic_Product_Code_Editor::class, 'editability_source_index_builds' );
		$this->assertSame( 1, $builds->getValue( $this->editor ) );
	}

	/** UI source hints are never trusted by the locked mutation path. */
	public function test_mutation_rereads_source_governance_after_a_stale_admin_batch(): void {
		$this->editor->reset_editability_cache();
		$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 741 ) ) );
		$this->assertTrue( $this->editor->editability_for( 741, '000741' )['editable'] );
		$source_key = array_key_first( $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]['sources'] );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]['sources'][ $source_key ]['products']['000742'] = array(
			'product_code' => '000742',
			'record_hash'  => 'sha256:' . str_repeat( 'f', 64 ),
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:stale-admin-index' ) );

		$this->assertSame( 'digitalogic_product_code_source_managed', $result->get_error_code() );
		$this->assertContains( 'desired_code_in_source', $result->get_error_data()['reasons'] );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Unprepared or actor-mismatched admin reads fail closed without fallback queries. */
	public function test_admin_read_requires_the_exact_prepared_actor_and_product_set(): void {
		$unprepared = $this->editor->editability_for( 741, '000741' );
		$this->assertFalse( $unprepared['editable'] );
		$this->assertSame( 'state_unavailable', $unprepared['reason'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->product_code_readback_batch_query_count );

		$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 741 ) ) );
		$GLOBALS['digitalogic_test_current_user_id'] = 23;

		$mismatched = $this->editor->recovery_intent_for( 741 );
		$this->assertSame( 'digitalogic_product_code_admin_read_unprepared', $mismatched->get_error_code() );
	}

	/** A source outage keeps editability closed without hiding a durable retry key. */
	public function test_source_state_failure_does_not_hide_a_valid_recovery_handoff(): void {
		$request = $this->request( '000742', 'product-code:741:source-outage-recovery' );
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $product_id, $meta_key ) {
				unset( $meta_id );
				if ( 741 === (int) $product_id && Digitalogic_Product_Code_Editor::META_KEY === $meta_key ) {
					throw new RuntimeException( 'Injected ambiguous result.' );
				}
			},
			10,
			3
		);
		$this->assertSame( 'digitalogic_product_code_retry_required', $this->editor->edit( $request )->get_error_code() );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array( 'sources' => 'unavailable' );

		$this->editor->reset_editability_cache();
		$prepared    = $this->editor->prepare_admin_read_batch( array( 741 ) );
		$editability = $this->editor->editability_for( 741, '000742' );
		$recovery    = $this->editor->recovery_intent_for( 741 );

		$this->assertInstanceOf( WP_Error::class, $prepared );
		$this->assertFalse( $editability['editable'] );
		$this->assertSame( 'state_unavailable', $editability['reason'] );
		$this->assertIsArray( $recovery );
		$this->assertSame( $request['request_id'], $recovery['request_id'] );
		$this->assertSame( '000742', $recovery['product_code'] );
	}

	/** A valid pointer stored under another product is rejected before hydration. */
	public function test_recovery_batch_rejects_a_cross_product_pointer(): void {
		$request   = $this->request( '000742', 'product-code:741:cross-product-pointer' );
		$validate  = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$validated = $validate->invoke( $this->editor, $request );
		$pointer   = array(
			'schema'              => Digitalogic_Product_Code_Editor::SCHEMA,
			'kind'                => 'recovery-index',
			'status'              => 'in_progress',
			'actor_id'            => 17,
			'product_id'          => 741,
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'request_id'          => $request['request_id'],
			'request_fingerprint' => $validated['fingerprint'],
			'updated_at'          => gmdate( 'c' ),
		);

		// Store the foreign row separately from the recovery-pointer fixture below.
		$GLOBALS['digitalogic_test_posts'][742]                                    = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => 'WOO-742' ),
		);
		$GLOBALS['digitalogic_test_options'][ $this->recovery_option_name( 742 ) ] = $pointer;

		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );

		$this->editor->reset_editability_cache();
		$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 742 ) ) );
		$recovery = $this->editor->recovery_intent_for( 742 );

		$this->assertInstanceOf( WP_Error::class, $recovery );
		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $recovery->get_error_code() );
		$fresh = $this->editor->edit(
			array(
				'product_id'    => 742,
				'expected_code' => 'WOO-742',
				'product_code'  => 'WOO-743',
				'if_match'      => $this->editor->revision_for( 742, 'WOO-742' ),
				'request_id'    => 'product-code:742:blocked-cross-product-pointer',
			)
		);
		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $fresh->get_error_code() );
		$this->assertArrayNotHasKey( 'recovery', $fresh->get_error_data() );
		$this->assertSame( 'WOO-742', get_post_meta( 742, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Missing operation records are safe only for an explicit pre-claim reservation. */
	public function test_missing_operation_records_fail_closed_for_every_effect_capable_pointer_status(): void {
		$validate = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		foreach ( array( 'in_progress', 'failed_retryable', 'outcome_unknown' ) as $status ) {
			$request   = $this->request( '000742', 'product-code:741:missing-operation:' . $status );
			$validated = $validate->invoke( $this->editor, $request );
			$GLOBALS['digitalogic_test_options'][ $this->recovery_option_name( 741 ) ] = array(
				'schema'              => Digitalogic_Product_Code_Editor::SCHEMA,
				'kind'                => 'recovery-index',
				'status'              => $status,
				'actor_id'            => 17,
				'product_id'          => 741,
				'expected_code'       => $request['expected_code'],
				'product_code'        => $request['product_code'],
				'if_match'            => $request['if_match'],
				'request_id'          => $request['request_id'],
				'request_fingerprint' => $validated['fingerprint'],
				'updated_at'          => gmdate( 'c' ),
			);
			$this->editor->reset_editability_cache();
			$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 741 ) ) );
			$this->assertSame( 'digitalogic_product_code_audit_unavailable', $this->editor->recovery_intent_for( 741 )->get_error_code() );
			$result = $this->editor->edit( $request );
			$this->assertSame( 'digitalogic_product_code_audit_unavailable', $result->get_error_code() );
			$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		}

		$request   = $this->request( '000742', 'product-code:741:missing-operation:reservation' );
		$validated = $validate->invoke( $this->editor, $request );
		$GLOBALS['digitalogic_test_options'][ $this->recovery_option_name( 741 ) ] = array(
			'schema'              => Digitalogic_Product_Code_Editor::SCHEMA,
			'kind'                => 'recovery-index',
			'status'              => 'reservation_pending',
			'actor_id'            => 17,
			'product_id'          => 741,
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'request_id'          => $request['request_id'],
			'request_fingerprint' => $validated['fingerprint'],
			'updated_at'          => gmdate( 'c' ),
		);
		$this->editor->reset_editability_cache();
		$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 741 ) ) );
		$this->assertSame( 'reservation_pending', $this->editor->recovery_intent_for( 741 )['status'] );
	}

	/** A durable claim is promoted before effect and cannot masquerade as a reservation. */
	public function test_real_claim_pointer_is_in_progress_and_missing_operation_never_reapplies(): void {
		$request = $this->request( '000742', 'product-code:741:claimed-operation-loss' );
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $product_id, $meta_key ) {
				unset( $meta_id );
				if ( 741 === (int) $product_id && Digitalogic_Product_Code_Editor::META_KEY === $meta_key ) {
					throw new RuntimeException( 'Injected ambiguous result after effect.' );
				}
			},
			10,
			3
		);

		$this->assertSame( 'digitalogic_product_code_retry_required', $this->editor->edit( $request )->get_error_code() );
		$pointer_name = $this->recovery_option_name( 741 );
		$this->assertSame( 'in_progress', $GLOBALS['digitalogic_test_options'][ $pointer_name ]['status'] );
		unset( $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] );
		$effect_count = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() );

		$this->editor->reset_editability_cache();
		$this->assertTrue( $this->editor->prepare_admin_read_batch( array( 741 ) ) );
		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $this->editor->recovery_intent_for( 741 )->get_error_code() );
		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $this->editor->edit( $request )->get_error_code() );
		$this->assertSame( '000742', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertSame( $effect_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
	}

	/** Failure to promote a reservation stops before the canonical effect. */
	public function test_claim_pointer_promotion_failure_has_no_product_code_effect(): void {
		$request      = $this->request( '000742', 'product-code:741:pointer-promotion-failure' );
		$pointer_name = $this->recovery_option_name( 741 );
		add_action(
			'updated_option_' . $pointer_name,
			static function () use ( $pointer_name ) {
				$GLOBALS['digitalogic_test_update_failures'][] = $pointer_name;
			},
			10,
			0
		);

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $result->get_error_code() );
		$this->assertSame( 'reservation_pending', $GLOBALS['digitalogic_test_options'][ $pointer_name ]['status'] );
		$this->assertSame( 'in_progress', $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status'] );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Admin output uses exact DB state even when the WC product object is stale. */
	public function test_product_manager_renders_exact_code_and_revision_after_cache_drift(): void {
		$stale_product = wc_get_product( 741 );
		$this->assertSame( '000741', $stale_product->get_meta( Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000744';
		$this->reset_singleton( Digitalogic_Product_Manager::class );

		$product = Digitalogic_Product_Manager::instance()->get_product( 741 );

		$this->assertSame( '000744', $product['patris_product_code'] );
		$this->assertSame( $this->editor->revision_for( 741, '000744' ), $product['patris_product_code_revision'] );
		$this->assertTrue( $product['patris_product_code_editable'] );
		$this->assertSame( '', $product['patris_product_code_edit_reason'] );
		$this->assertTrue( $product['patris_product_code_cache_mismatch'] );
		$this->assertContains( 'product_741', $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
	}

	/** Malformed nested source evidence is never skipped as if it were absent. */
	public function test_malformed_nested_source_state_fails_closed(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'fixture' => array(
					'source'            => array(
						'id'       => 'patris-office',
						'dataset'  => 'kala.db',
						'revision' => 'sha256:' . str_repeat( 'c', 64 ),
					),
					'products'          => array(
						'000900' => array(
							'product_code' => '000900',
							'record_hash'  => 'sha256:' . str_repeat( 'd', 64 ),
						),
					),
					'applied_products'  => 'malformed',
					'pending_products'  => array(),
					'deferred_products' => array(),
				),
			),
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:malformed-source' ) );

		$this->assertSame( 'digitalogic_product_code_source_state_malformed', $result->get_error_code() );
		$this->assertSame( 'sources[0].applied_products', $result->get_error_data()['location'] );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Source revision provenance must retain its exact sha256 identity. */
	public function test_malformed_source_revision_fails_closed(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'fixture' => array(
					'source'            => array(
						'id'       => 'patris-office',
						'dataset'  => 'kala.db',
						'revision' => 'not-a-source-revision',
					),
					'products'          => array(),
					'applied_products'  => array(),
					'pending_products'  => array(),
					'deferred_products' => array(),
				),
			),
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:bad-source-revision' ) );

		$this->assertSame( 'digitalogic_product_code_source_state_malformed', $result->get_error_code() );
		$this->assertSame( 'sources[0].source.revision', $result->get_error_data()['location'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Delivery ownership entries without exact record hashes are never ignored. */
	public function test_malformed_delivery_provenance_fails_closed(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = array(
			'sources' => array(
				'fixture' => array(
					'source'            => array(
						'id'       => 'patris-office',
						'dataset'  => 'kala.db',
						'revision' => 'sha256:' . str_repeat( 'c', 64 ),
					),
					'products'          => array(),
					'applied_products'  => array(),
					'pending_products'  => array(
						'000900' => array( 'product_code' => '000900' ),
					),
					'deferred_products' => array(),
				),
			),
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:bad-delivery-record' ) );

		$this->assertSame( 'digitalogic_product_code_source_state_malformed', $result->get_error_code() );
		$this->assertSame( 'sources[0].pending_products[000900]', $result->get_error_data()['location'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Duplicate raw metadata on the target must be reconciled, never overwritten. */
	public function test_duplicate_target_meta_rows_fail_closed(): void {
		$GLOBALS['digitalogic_test_posts'][741]['meta_rows']['_digitalogic_patris_product_code'] = array( 'STALE', '000741' );

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:target-conflict' ) );

		$this->assertSame( 'digitalogic_product_code_meta_conflict', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['row_count'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Case-variant legacy identity/provenance keys are malformed, never absent. */
	public function test_case_variant_identity_and_provenance_rows_fail_closed_before_effect(): void {
		$fixtures = array(
			'variant-only'       => array(
				'meta'      => array( '_DIGITALOGIC_PATRIS_PRODUCT_CODE' => '000741' ),
				'meta_rows' => array(),
			),
			'exact-plus-variant' => array(
				'meta'      => array( '_digitalogic_patris_product_code' => '000741' ),
				'meta_rows' => array( '_DIGITALOGIC_PATRIS_PRODUCT_CODE' => array( '000741' ) ),
			),
			'provenance-variant' => array(
				'meta'      => array(
					'_digitalogic_patris_product_code' => '000741',
					'_DIGITALOGIC_PATRIS_RECORD_HASH'  => 'sha256:' . str_repeat( 'a', 64 ),
				),
				'meta_rows' => array(),
			),
		);
		foreach ( $fixtures as $name => $fixture ) {
			$GLOBALS['digitalogic_test_posts'][741]['meta']      = $fixture['meta'];
			$GLOBALS['digitalogic_test_posts'][741]['meta_rows'] = $fixture['meta_rows'];
			$result = $this->editor->edit( $this->request( '000742', 'product-code:741:case-' . $name ) );

			$this->assertInstanceOf( WP_Error::class, $result, $name );
			$this->assertSame( 'digitalogic_product_code_meta_conflict', $result->get_error_code(), $name );
			$this->assertGreaterThanOrEqual( 1, $result->get_error_data()['invalid_key_rows'], $name );
		}
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Coordinator, source, and product locks fail immediately instead of waiting. */
	public function test_busy_locks_return_typed_retryable_errors_without_waiting(): void {
		$GLOBALS['wpdb']->acquire_results = array( 0 );
		$result                           = $this->editor->edit( $this->request( '000742', 'product-code:741:busy-source' ) );
		$this->assertSame( 'digitalogic_product_code_source_busy', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( array( 0 ), $GLOBALS['wpdb']->lock_timeouts );

		$GLOBALS['wpdb']                  = new Digitalogic_Test_WPDB();
		$GLOBALS['wpdb']->acquire_results = array( 1, 0 );
		$result                           = $this->editor->edit( $this->request( '000742', 'product-code:741:busy-global' ) );
		$this->assertSame( 'digitalogic_product_code_busy', $result->get_error_code() );
		$this->assertSame( array( 0, 0 ), $GLOBALS['wpdb']->lock_timeouts );
		$this->assertSame( 1, $GLOBALS['wpdb']->release_count, 'Only the acquired source lock is released.' );

		$GLOBALS['wpdb']                  = new Digitalogic_Test_WPDB();
		$GLOBALS['wpdb']->acquire_results = array( 1, 1, 0 );
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:busy-product' ) );
		$this->assertSame( 'product_write_lock_busy', $result->get_error_code() );
		$this->assertSame( array( 0, 0, 0 ), $GLOBALS['wpdb']->lock_timeouts );
		$this->assertSame( 2, $GLOBALS['wpdb']->release_count, 'The acquired source and operation locks are released.' );
	}

	/** The coordinator detects a reconnect even when its callback returns normally. */
	public function test_operation_lock_is_bound_to_the_acquiring_database_connection(): void {
		$method = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'with_operation_lock' );
		$result = $method->invoke(
			$this->editor,
			static function () {
				$GLOBALS['wpdb']->connection_id = 2002;
				return 'must-not-be-terminal';
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_operation_lock_lost', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
	}

	/** A reconnect after the SQL effect never produces a false terminal record. */
	public function test_connection_loss_after_meta_effect_requires_same_key_recovery(): void {
		$request = $this->request( '000742', 'product-code:741:effect-lock-loss' );
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $product_id, $meta_key ) {
				unset( $meta_id );
				if ( 741 === (int) $product_id && Digitalogic_Product_Code_Editor::META_KEY === $meta_key ) {
					$GLOBALS['wpdb']->connection_id = 2002;
				}
			},
			10,
			3
		);

		$result = $this->editor->edit( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_recovery_pending', $result->get_error_code() );
		$this->assertSame( $request['request_id'], $result->get_error_data()['request_id'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'in_progress', $record['status'] );
		$this->assertArrayNotHasKey( 'result', $record );
		$this->assertSame( $request['request_id'], $this->admin_recovery_intent( 741 )['request_id'] );

		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$GLOBALS['digitalogic_test_action_callbacks']['updated_post_meta'] = array();

		$recovered = $this->editor->edit( $request );
		$this->assertIsArray( $recovered );
		$this->assertTrue( $recovered['recovered'] );
		$this->assertSame( '000742', $recovered['product_code'] );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'completed', $record['status'] );
	}

	/** A terminal record proven after reconnect remains a valid successful effect. */
	public function test_reconnect_during_terminal_operation_write_returns_proven_completion(): void {
		$request                             = $this->request( '000742', 'product-code:741:terminal-write-reconnect' );
		$operation_name                      = $this->operation_option_name( $request['request_id'] );
		$callback                            = null;
		$callback                            = static function ( $database, $option_name ) use ( &$callback, $operation_name ) {
			$record = $GLOBALS['digitalogic_test_options'][ $option_name ] ?? array();
			if ( $operation_name === $option_name && 'completed' === (string) ( $record['status'] ?? '' ) ) {
				$database->connection_id = 2002;
				return;
			}
			$database->after_option_write = $callback;
		};
		$GLOBALS['wpdb']->after_option_write = $callback;

		$result = $this->editor->edit( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( '000742', $result['product_code'] );
		$record = $GLOBALS['digitalogic_test_options'][ $operation_name ];
		$this->assertSame( 'completed', $record['status'] );
		$this->assertSame( array(), $this->admin_recovery_intent( 741 ) );

		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$replay = $this->editor->edit( $request );
		$this->assertIsArray( $replay );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( '000742', $replay['current_product_code'] );
	}

	/** Reconnect during physical pointer cleanup cannot hide a terminal request. */
	public function test_reconnect_during_terminal_pointer_clear_remains_reload_safe(): void {
		$request                              = $this->request( '000742', 'product-code:741:pointer-clear-reconnect' );
		$recovery_name                        = $this->recovery_option_name( 741 );
		$GLOBALS['wpdb']->after_option_delete = static function ( $database, $option_name ) use ( $recovery_name ) {
			if ( $recovery_name === $option_name ) {
				$database->connection_id = 2002;
			}
		};

		$result = $this->editor->edit( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertArrayNotHasKey( $recovery_name, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( 'completed', $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status'] );

		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$this->assertSame( array(), $this->admin_recovery_intent( 741 ) );
		$replay = $this->editor->edit( $request );
		$this->assertTrue( $replay['replayed'] );
	}

	/** A readback race triggers exact rollback and a resumable failure record. */
	public function test_failed_readback_rolls_back_to_exact_backup(): void {
		$interfered = false;
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $product_id, $meta_key ) use ( &$interfered ) {
				unset( $meta_id );
				if ( ! $interfered && 741 === (int) $product_id && Digitalogic_Product_Code_Editor::META_KEY === $meta_key ) {
					$interfered = true;
					$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = 'RACED';
				}
			},
			10,
			3
		);

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:rollback' ) );

		$this->assertSame( 'digitalogic_product_code_readback_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['rollback_verified'] );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( 'product-code:741:rollback' ) ];
		$this->assertSame( 'failed_retryable', $record['status'] );
		$this->assertTrue( $record['rollback']['verified'] );
	}

	/** An interrupted request whose after-state is present finalizes as recovered. */
	public function test_interrupted_after_state_is_recovered_idempotently(): void {
		$request = $this->request( '000742', 'product-code:741:recover' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code']            = '000742';

		$result = $this->editor->edit( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['recovered'] );
		$this->assertSame( '000742', $result['product_code'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'completed', $record['status'] );
	}

	/** Recovery never finalizes an after-state that became source-owned. */
	public function test_interrupted_after_state_with_new_source_ownership_is_unknown(): void {
		$request = $this->request( '000742', 'product-code:741:recover-source-conflict' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code']            = '000742';
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_record_hash']             = 'sha256:' . str_repeat( 'e', 64 );

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_outcome_unknown', $result->get_error_code() );
		$this->assertSame( 'recovery_source_conflict', $result->get_error_data()['reason'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'outcome_unknown', $record['status'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Recovery never finalizes an after-state that is no longer globally unique. */
	public function test_interrupted_after_state_with_duplicate_is_unknown(): void {
		$request = $this->request( '000742', 'product-code:741:recover-duplicate' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code']            = '000742';
		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => '000742' ),
		);

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_outcome_unknown', $result->get_error_code() );
		$this->assertSame( 'recovery_uniqueness_conflict', $result->get_error_data()['reason'] );
		$this->assertSame( '000742', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'outcome_unknown', $record['status'] );
		$this->assertArrayNotHasKey( 'updated_post_meta', $GLOBALS['digitalogic_test_actions'] );
	}

	/** Numeric inputs are rejected so a client cannot erase leading zeroes. */
	public function test_product_codes_must_arrive_as_strings(): void {
		$request                 = $this->request( '000742', 'product-code:741:numeric-input' );
		$request['product_code'] = 742;

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_string_required', $result->get_error_code() );
		$this->assertSame( '000741', $GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_product_code'] );
	}

	/** Malformed UTF-8 never reaches revision or idempotency hashing. */
	public function test_malformed_utf8_is_rejected_without_hash_collapse(): void {
		$first         = $this->request( "\xC3\x28", 'product-code:741:bad-utf8-a' );
		$second        = $this->request( "\xA0\xA1", 'product-code:741:bad-utf8-b' );
		$first_result  = $this->editor->edit( $first );
		$second_result = $this->editor->edit( $second );

		$this->assertSame( 'digitalogic_product_code_encoding_invalid', $first_result->get_error_code() );
		$this->assertSame( 'digitalogic_product_code_encoding_invalid', $second_result->get_error_code() );
		$this->assertSame( '', $this->editor->revision_for( 741, "\xC3\x28" ) );
		$validate = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$valid_a  = $validate->invoke( $this->editor, $this->request( '۰۱۲۳', 'product-code:741:utf8-valid-a' ) );
		$valid_b  = $validate->invoke( $this->editor, $this->request( '۰۱۲۴', 'product-code:741:utf8-valid-b' ) );
		$this->assertNotSame( $valid_a['fingerprint'], $valid_b['fingerprint'] );
	}

	/** An unchanged request still proves source governance and uniqueness. */
	public function test_unchanged_request_does_not_claim_unchecked_invariants(): void {
		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => '000741' ),
		);
		$duplicate                              = $this->editor->edit( $this->request( '000741', 'product-code:741:unchanged-duplicate' ) );
		$this->assertSame( 'digitalogic_product_code_not_unique', $duplicate->get_error_code() );

		unset( $GLOBALS['digitalogic_test_posts'][842] );
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_record_hash'] = 'sha256:' . str_repeat( 'a', 64 );
		$managed = $this->editor->edit( $this->request( '000741', 'product-code:741:unchanged-managed' ) );
		$this->assertSame( 'digitalogic_product_code_source_managed', $managed->get_error_code() );
	}

	/** Trashed products retain canonical ownership until permanent deletion. */
	public function test_trash_code_cannot_be_reused_and_restore_stays_unique(): void {
		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product',
			'post_status' => 'trash',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => '000742' ),
		);
		$blocked                                = $this->editor->edit( $this->request( '000742', 'product-code:741:trash-owner' ) );
		$this->assertSame( 'digitalogic_product_code_not_unique', $blocked->get_error_code() );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );

		$GLOBALS['digitalogic_test_posts'][842]['post_status'] = 'publish';
		$still_blocked = $this->editor->edit( $this->request( '000742', 'product-code:741:restored-owner' ) );
		$this->assertSame( 'digitalogic_product_code_not_unique', $still_blocked->get_error_code() );
		$this->assertSame( '000742', get_post_meta( 842, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Exact conflicts ignore DB collation for key casing, object type, and status. */
	public function test_uniqueness_matrix_folds_meta_key_but_keeps_values_byte_exact(): void {
		$GLOBALS['wpdb']->product_code_meta_key_binary_collation = true;

		$GLOBALS['wpdb']->product_code_meta_value_case_insensitive_collation = true;
		$matrix = array(
			842 => array( 'product', 'publish', 'MATRIX-PRODUCT-PUBLISH' ),
			843 => array( 'product', 'trash', 'MATRIX-PRODUCT-TRASH' ),
			844 => array( 'product_variation', 'publish', 'MATRIX-VARIATION-PUBLISH' ),
			845 => array( 'product_variation', 'trash', 'MATRIX-VARIATION-TRASH' ),
		);
		foreach ( $matrix as $owner_id => $fixture ) {
			$GLOBALS['digitalogic_test_posts'][ $owner_id ] = array(
				'post_type'   => $fixture[0],
				'post_status' => $fixture[1],
				'meta'        => array( strtoupper( Digitalogic_Product_Code_Editor::META_KEY ) => $fixture[2] ),
			);
			$result = $this->editor->edit( $this->request( $fixture[2], 'product-code:741:matrix:' . $owner_id ) );
			$this->assertSame( 'digitalogic_product_code_not_unique', $result->get_error_code(), $fixture[0] . ':' . $fixture[1] );
			$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		}

		$GLOBALS['digitalogic_test_posts'][846] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'trash',
			'meta'        => array( strtoupper( Digitalogic_Product_Code_Editor::META_KEY ) => 'CaseSensitiveCode' ),
		);

		$case_variant = $this->editor->edit( $this->request( 'casesensitivecode', 'product-code:741:value-case-exact' ) );
		$this->assertSame( 'applied', $case_variant['status'] );
		$this->assertSame( 'casesensitivecode', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Permanent deletion releases exact ownership for products and variations. */
	public function test_permanent_delete_releases_product_and_variation_codes_for_reuse(): void {
		$GLOBALS['digitalogic_test_posts'][742] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => 'WOO-742' ),
		);
		$GLOBALS['digitalogic_test_posts'][842] = array(
			'post_type'   => 'product',
			'post_status' => 'trash',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => 'REUSE-PRODUCT' ),
		);
		$GLOBALS['digitalogic_test_posts'][843] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'trash',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => 'REUSE-VARIATION' ),
		);

		$this->assertSame(
			'digitalogic_product_code_not_unique',
			$this->editor->edit( $this->request( 'REUSE-PRODUCT', 'product-code:741:reuse-product-blocked' ) )->get_error_code()
		);
		$this->assertNotFalse( wp_delete_post( 842, true ) );
		$this->assertSame( 'applied', $this->editor->edit( $this->request( 'REUSE-PRODUCT', 'product-code:741:reuse-product-applied' ) )['status'] );

		$variation_request = array(
			'product_id'    => 742,
			'expected_code' => 'WOO-742',
			'product_code'  => 'REUSE-VARIATION',
			'if_match'      => $this->editor->revision_for( 742, 'WOO-742' ),
			'request_id'    => 'product-code:742:reuse-variation-blocked',
		);
		$this->assertSame( 'digitalogic_product_code_not_unique', $this->editor->edit( $variation_request )->get_error_code() );
		$this->assertNotFalse( wp_delete_post( 843, true ) );
		$variation_request['request_id'] = 'product-code:742:reuse-variation-applied';
		$this->assertSame( 'applied', $this->editor->edit( $variation_request )['status'] );
	}

	/** A timeout survives a page/service reload and forces the original key. */
	public function test_server_recovery_index_survives_reload_and_blocks_a_fresh_key(): void {
		$request = $this->request( '000742', 'product-code:741:reload-recovery' );
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $product_id, $meta_key, $meta_value ) {
				unset( $meta_id );
				if ( 741 === (int) $product_id && Digitalogic_Product_Code_Editor::META_KEY === $meta_key && '000742' === $meta_value ) {
					throw new RuntimeException( 'Injected client-ambiguous failure.' );
				}
			},
			20,
			4
		);
		$uncertain = $this->editor->edit( $request );
		$this->assertSame( 'digitalogic_product_code_retry_required', $uncertain->get_error_code() );

		$this->reset_singleton( Digitalogic_Product_Code_Editor::class );
		$this->editor = Digitalogic_Product_Code_Editor::instance();
		$recovery     = $this->admin_recovery_intent( 741 );
		$this->assertSame( $request['request_id'], $recovery['request_id'] );
		$this->assertSame( '000742', $recovery['product_code'] );
		$GLOBALS['digitalogic_test_current_user_id'] = 23;
		$private_recovery                            = $this->admin_recovery_intent( 741 );
		$this->assertIsArray( $private_recovery );
		$this->assertTrue( $private_recovery['takeover_required'] );
		$this->assertSame( $request['request_id'], $private_recovery['request_id'] );
		$other_actor = $this->editor->edit( $this->request( '000743', 'product-code:741:other-actor' ) );
		$this->assertSame( 'digitalogic_product_code_busy', $other_actor->get_error_code() );
		$this->assertArrayNotHasKey( 'recovery', $other_actor->get_error_data() );
		$GLOBALS['digitalogic_test_current_user_id'] = 17;

		$fresh_key = $this->request( '000743', 'product-code:741:fresh-after-timeout' );
		$blocked   = $this->editor->edit( $fresh_key );
		$this->assertSame( 'digitalogic_product_code_recovery_required', $blocked->get_error_code() );
		$this->assertSame( $request['request_id'], $blocked->get_error_data()['recovery']['request_id'] );

		$GLOBALS['digitalogic_test_action_callbacks']['updated_post_meta'] = array_filter(
			$GLOBALS['digitalogic_test_action_callbacks']['updated_post_meta'],
			static function ( $item ) {
				return 20 !== (int) $item['priority'];
			}
		);
		$recovered = $this->editor->edit( $request );
		$this->assertIsArray( $recovered );
		$this->assertTrue( $recovered['recovered'] );
		$this->assertSame( array(), $this->admin_recovery_intent( 741 ) );
	}

	/** A pointer failure occurs before any claim, so no undiscoverable operation remains. */
	public function test_recovery_pointer_failure_cannot_orphan_a_claim(): void {
		$request                                       = $this->request( '000742', 'product-code:741:pointer-failure' );
		$pointer                                       = $this->recovery_option_name( 741 );
		$GLOBALS['digitalogic_test_update_failures'][] = $pointer;

		$failed = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $failed->get_error_code() );
		$this->assertArrayNotHasKey( $this->operation_option_name( $request['request_id'] ), $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( $pointer, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->assertSame( 'applied', $this->editor->edit( $request )['status'] );
	}

	/** A claim failure leaves a reload-visible reservation that retries the same key. */
	public function test_claim_failure_remains_discoverable_through_product_pointer(): void {
		$request = $this->request( '000742', 'product-code:741:claim-failure' );
		$claim   = $this->operation_option_name( $request['request_id'] );
		$GLOBALS['digitalogic_test_update_failures'][] = $claim;

		$failed = $this->editor->edit( $request );
		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $failed->get_error_code() );
		$this->assertArrayHasKey( $this->recovery_option_name( 741 ), $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( $claim, $GLOBALS['digitalogic_test_options'] );
		$this->reset_singleton( Digitalogic_Product_Code_Editor::class );
		$this->editor = Digitalogic_Product_Code_Editor::instance();
		$recovery     = $this->admin_recovery_intent( 741 );
		$this->assertSame( 'reservation_pending', $recovery['status'] );
		$this->assertSame( $request['request_id'], $recovery['request_id'] );

		$fresh = $this->editor->edit( $this->request( '000743', 'product-code:741:blocked-by-reservation' ) );
		$this->assertSame( 'digitalogic_product_code_recovery_required', $fresh->get_error_code() );
		$GLOBALS['digitalogic_test_update_failures'] = array();
		$this->assertSame( 'applied', $this->editor->edit( $request )['status'] );
	}

	/** A stale completed pointer never blocks a later request when delete_option fails. */
	public function test_completed_pointer_cleanup_failure_does_not_block_next_edit(): void {
		$pointer = $this->recovery_option_name( 741 );
		$GLOBALS['digitalogic_test_option_delete_failures'][] = $pointer;
		$first = $this->editor->edit( $this->request( '000742', 'product-code:741:stale-terminal-pointer' ) );
		$this->assertSame( 'applied', $first['status'] );
		$this->assertArrayHasKey( $pointer, $GLOBALS['digitalogic_test_options'] );
		$GLOBALS['digitalogic_test_current_user_id'] = 23;
		$this->assertSame( array(), $this->admin_recovery_intent( 741 ) );

		$second = array(
			'product_id'    => 741,
			'expected_code' => '000742',
			'product_code'  => '000743',
			'if_match'      => $this->editor->revision_for( 741, '000742' ),
			'request_id'    => 'product-code:741:after-stale-terminal',
		);
		$this->assertSame( 'applied', $this->editor->edit( $second )['status'] );
		$this->assertSame( '000743', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Recovery preserves the original effect actor and attributes the helper. */
	public function test_cross_actor_recovery_preserves_effect_attribution(): void {
		$request = $this->request( '000742', 'product-code:741:cross-actor' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = '000742';
		$GLOBALS['digitalogic_test_current_user_id'] = 23;

		$result = $this->editor->edit( $request );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];

		$this->assertIsArray( $result );
		$this->assertSame( 17, $record['actor_id'] );
		$this->assertSame( 23, $record['recovered_by'] );
	}

	/** Dry-run and exact apply clear a restored-before outcome without a code write. */
	public function test_outcome_unknown_before_state_has_audited_no_effect_reconciliation(): void {
		$request = $this->request( '000742', 'product-code:741:reconcile-before' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$unknown = $this->editor->edit( $request );
		$this->assertSame( 'digitalogic_product_code_outcome_unknown', $unknown->get_error_code() );

		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000741';
		$preview = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);
		$this->assertIsArray( $preview );
		$this->assertSame( 'digitalogic.product-code-reconciliation', $preview['schema'] );
		$this->assertSame( 'dry_run', $preview['status'] );
		$this->assertSame( 'before', $preview['resolution'] );
		$this->assertSame( '000741', $preview['observed_product_code'] );
		$this->assertMatchesRegularExpression( '/\Asha256:[a-f0-9]{64}\z/', $preview['record_fingerprint'] );
		$this->assertMatchesRegularExpression( '/\Asha256:[a-f0-9]{64}\z/', $preview['preview_digest'] );

		$action_count   = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() );
		$apply          = $preview;
		$apply['apply'] = true;
		$result         = $this->editor->reconcile_outcome( $apply );
		$this->assertIsArray( $result );
		$this->assertSame( 'reconciled_no_effect', $result['status'] );
		$this->assertFalse( $result['changed'] );
		$this->assertTrue( $result['verification']['no_code_write'] );
		$this->assertSame( $action_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertSame( array(), $this->admin_recovery_intent( 741 ) );

		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'reconciled_no_effect', $record['status'] );
		$this->assertSame( 17, $record['reconciliation']['reconciled_by'] );
		$replay = $this->editor->reconcile_outcome( $apply );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( '000741', $replay['current_product_code'] );

		$new_request = $this->request( '000743', 'product-code:741:after-reconcile-before' );
		$this->assertSame( 'applied', $this->editor->edit( $new_request )['status'] );
	}

	/** A genuinely absent before-state can be reconciled without inventing a metadata row. */
	public function test_outcome_unknown_absent_before_state_has_audited_no_effect_reconciliation(): void {
		unset(
			$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ],
			$GLOBALS['digitalogic_test_posts'][741]['meta_rows'][ Digitalogic_Product_Code_Editor::META_KEY ]
		);
		$request = array(
			'product_id'    => 741,
			'expected_code' => '',
			'product_code'  => '000742',
			'if_match'      => $this->editor->revision_for( 741, '' ),
			'request_id'    => 'product-code:741:reconcile-absent-before',
		);
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$unknown = $this->editor->edit( $request );
		$this->assertSame( 'digitalogic_product_code_outcome_unknown', $unknown->get_error_code() );

		unset(
			$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ],
			$GLOBALS['digitalogic_test_posts'][741]['meta_rows'][ Digitalogic_Product_Code_Editor::META_KEY ]
		);
		$preview = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);
		$this->assertIsArray( $preview );
		$this->assertSame( 'before', $preview['resolution'] );
		$this->assertSame( '', $preview['observed_product_code'] );
		$action_count   = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() );
		$apply          = $preview;
		$apply['apply'] = true;
		$result         = $this->editor->reconcile_outcome( $apply );
		$this->assertSame( 'reconciled_no_effect', $result['status'] );
		$this->assertTrue( $result['verification']['no_code_write'] );
		$this->assertSame( $action_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
		$this->assertArrayNotHasKey( Digitalogic_Product_Code_Editor::META_KEY, $GLOBALS['digitalogic_test_posts'][741]['meta'] );
		$this->assertSame( array(), $this->admin_recovery_intent( 741 ) );

		$replay = $this->editor->reconcile_outcome( $apply );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( '', $replay['current_product_code'] );
	}

	/** Exact after-state reconciliation finalizes once and never rewrites the code. */
	public function test_outcome_unknown_after_state_reconciliation_is_idempotent(): void {
		$request = $this->request( '000742', 'product-code:741:reconcile-after' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$this->assertSame( 'digitalogic_product_code_outcome_unknown', $this->editor->edit( $request )->get_error_code() );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000742';

		$preview = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);
		$this->assertSame( 'after', $preview['resolution'] );
		$this->assertSame( 'unmanaged', $preview['source_status'] );
		$action_count   = count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() );
		$apply          = $preview;
		$apply['apply'] = true;
		$result         = $this->editor->reconcile_outcome( $apply );

		$this->assertIsArray( $result );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertTrue( $result['recovered'] );
		$this->assertSame( '000742', $result['product_code'] );
		$this->assertSame( $action_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'completed', $record['status'] );
		$this->assertSame( $result['reconciliation_evidence_fingerprint'], $record['reconciliation']['evidence_fingerprint'] );

		$replay = $this->editor->reconcile_outcome( $apply );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( '000742', $replay['current_product_code'] );
		$this->assertSame( $action_count, count( $GLOBALS['digitalogic_test_actions']['updated_post_meta'] ?? array() ) );
	}

	/** Apply fails before any terminalization when a dry-run assertion is stale. */
	public function test_outcome_reconciliation_requires_exact_preview_manifest(): void {
		$request = $this->request( '000742', 'product-code:741:reconcile-stale' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$this->editor->edit( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000741';
		$preview                      = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);
		$preview['apply']             = true;
		$preview['observed_revision'] = 'sha256:' . str_repeat( 'f', 64 );

		$result = $this->editor->reconcile_outcome( $preview );

		$this->assertSame( 'digitalogic_product_code_reconciliation_manifest_stale', $result->get_error_code() );
		$this->assertSame( 'observed_revision', $result->get_error_data()['failed_field'] );
		$this->assertSame( 'outcome_unknown', $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status'] );
		$this->assertNotSame( array(), $this->admin_recovery_intent( 741 ) );
	}

	/** A source revision change invalidates the dry-run even when the code is unchanged. */
	public function test_outcome_reconciliation_binds_the_exact_source_state_revision(): void {
		$request = $this->request( '000742', 'product-code:741:reconcile-source-revision' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$this->editor->edit( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000741';
		$preview = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);

		$source_key = array_key_first( $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]['sources'] );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]['sources'][ $source_key ]['source']['revision'] = 'sha256:' . str_repeat( 'd', 64 );
		$preview['apply'] = true;
		$result           = $this->editor->reconcile_outcome( $preview );

		$this->assertSame( 'digitalogic_product_code_reconciliation_manifest_stale', $result->get_error_code() );
		$this->assertSame( 'preview_digest', $result->get_error_data()['failed_field'] );
		$this->assertSame( 'outcome_unknown', $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status'] );
	}

	/** An after-state that has become source-owned is never terminalized as an owner edit. */
	public function test_outcome_reconciliation_rejects_a_source_managed_after_state(): void {
		$request = $this->request( '000742', 'product-code:741:reconcile-after-managed' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$this->editor->edit( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000742';
		$GLOBALS['digitalogic_test_posts'][741]['meta']['_digitalogic_patris_record_hash']           = 'sha256:' . str_repeat( 'e', 64 );

		$result = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);

		$this->assertSame( 'digitalogic_product_code_reconciliation_source_managed', $result->get_error_code() );
		$this->assertMatchesRegularExpression( '/\Asha256:[a-f0-9]{64}\z/', $result->get_error_data()['source_evidence_fingerprint'] );
		$this->assertSame( 'outcome_unknown', $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ]['status'] );
	}

	/** Reconciliation evidence is bound to its exact terminal audit record. */
	public function test_reconciliation_evidence_tamper_is_not_replayable(): void {
		$request = $this->request( '000742', 'product-code:741:reconcile-evidence-tamper' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = 'UNRESOLVED';
		$this->editor->edit( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ] = '000741';
		$preview          = $this->editor->reconcile_outcome(
			array(
				'product_id' => 741,
				'request_id' => $request['request_id'],
			)
		);
		$preview['apply'] = true;
		$this->assertSame( 'reconciled_no_effect', $this->editor->reconcile_outcome( $preview )['status'] );

		$name = $this->operation_option_name( $request['request_id'] );
		$GLOBALS['digitalogic_test_options'][ $name ]['reconciliation']['source_evidence_fingerprint'] = 'sha256:' . str_repeat( 'f', 64 );
		$result = $this->editor->reconcile_outcome( $preview );

		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $result->get_error_code() );
	}

	/** A tampered governance proof makes completed replay fail closed. */
	public function test_governance_proof_tamper_is_not_replayable(): void {
		$request = $this->request( '000742', 'product-code:741:governance-tamper' );
		$this->assertIsArray( $this->editor->edit( $request ) );
		$name = $this->operation_option_name( $request['request_id'] );
		$GLOBALS['digitalogic_test_options'][ $name ]['governance_proof']['legacy_feed']['row_count'] = 99;

		$result = $this->editor->edit( $request );

		$this->assertSame( 'digitalogic_product_code_audit_unavailable', $result->get_error_code() );
	}

	/** Terminal actor, backup, and projection evidence are recomputed, not trusted. */
	public function test_terminal_attribution_backup_and_projection_tamper_fail_validation(): void {
		$request = $this->request( '000742', 'product-code:741:terminal-evidence-tamper' );
		$this->assertIsArray( $this->editor->edit( $request ) );
		$record           = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$validate_request = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$validated        = $validate_request->invoke( $this->editor, $request );
		$validate_record  = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'operation_record_is_valid' );
		$this->assertTrue( $validate_record->invoke( $this->editor, $record, $validated, 'completed' ) );

		$without_actor = $record;
		unset( $without_actor['actor_id'] );
		$this->assertFalse( $validate_record->invoke( $this->editor, $without_actor, $validated, 'completed' ) );

		$empty_backup                               = $record;
		$empty_backup['backup_reference']           = '';
		$empty_backup['result']['backup_reference'] = '';
		$this->assertFalse( $validate_record->invoke( $this->editor, $empty_backup, $validated, 'completed' ) );

		$arbitrary_backup                               = $record;
		$arbitrary_backup['backup_reference']           = 'sha256:' . str_repeat( 'f', 64 );
		$arbitrary_backup['result']['backup_reference'] = $arbitrary_backup['backup_reference'];
		$this->assertFalse( $validate_record->invoke( $this->editor, $arbitrary_backup, $validated, 'completed' ) );

		$arbitrary_projection                                        = $record;
		$arbitrary_projection['projection']['generation_after_hash'] = 'sha256:' . str_repeat( '9', 64 );
		$arbitrary_projection['result']['projection']['generation_after_hash'] = $arbitrary_projection['projection']['generation_after_hash'];
		$this->assertFalse( $validate_record->invoke( $this->editor, $arbitrary_projection, $validated, 'completed' ) );
	}

	/** Recovered terminal records bind fresh governance and helper attribution. */
	public function test_recovery_governance_and_actor_tamper_fail_validation(): void {
		$request = $this->request( '000742', 'product-code:741:recovery-evidence-tamper' );
		$GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ] = $this->interrupted_record( $request );
		$GLOBALS['digitalogic_test_posts'][741]['meta'][ Digitalogic_Product_Code_Editor::META_KEY ]   = '000742';
		$GLOBALS['digitalogic_test_current_user_id'] = 23;
		$this->assertIsArray( $this->editor->edit( $request ) );

		$record           = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$validate_request = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$validated        = $validate_request->invoke( $this->editor, $request );
		$validate_record  = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'operation_record_is_valid' );
		$this->assertTrue( $validate_record->invoke( $this->editor, $record, $validated, 'completed' ) );

		$missing_proof = $record;
		unset( $missing_proof['recovery_governance_proof'] );
		$this->assertFalse( $validate_record->invoke( $this->editor, $missing_proof, $validated, 'completed' ) );

		$wrong_fingerprint = $record;
		$wrong_fingerprint['result']['recovery_governance_evidence_fingerprint'] = 'sha256:' . str_repeat( '1', 64 );
		$this->assertFalse( $validate_record->invoke( $this->editor, $wrong_fingerprint, $validated, 'completed' ) );

		$same_actor                 = $record;
		$same_actor['recovered_by'] = $same_actor['actor_id'];
		$this->assertFalse( $validate_record->invoke( $this->editor, $same_actor, $validated, 'completed' ) );
	}

	/** Generation persistence failure rolls back and same-key retry repairs it. */
	public function test_projection_failure_never_becomes_false_terminal_success(): void {
		$generation_option                             = ( new ReflectionClass( Digitalogic_Report_Engine::class ) )
			->getReflectionConstant( 'CACHE_GENERATION_OPTION' )
			->getValue();
		$GLOBALS['digitalogic_test_update_failures'][] = $generation_option;
		$request                                       = $this->request( '000742', 'product-code:741:projection-retry' );

		$failed = $this->editor->edit( $request );
		$this->assertSame( 'digitalogic_product_code_projection_pending', $failed->get_error_code() );
		$this->assertSame( '000741', get_post_meta( 741, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$record = $GLOBALS['digitalogic_test_options'][ $this->operation_option_name( $request['request_id'] ) ];
		$this->assertSame( 'failed_retryable', $record['status'] );
		$this->assertFalse( $record['rollback']['projection_verified'] );

		$GLOBALS['digitalogic_test_update_failures'] = array();
		$applied                                     = $this->editor->edit( $request );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertTrue( $applied['verification']['projection_current'] );
		$this->assertTrue( $applied['projection']['state_revision_event_durable'] );
	}

	/** Object-level permission is required in addition to commerce management. */
	public function test_object_capability_is_enforced_inside_the_service(): void {
		$GLOBALS['digitalogic_test_capabilities']['edit_post'] = false;

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:object-forbidden' ) );

		$this->assertSame( 'digitalogic_product_code_object_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $GLOBALS['wpdb']->acquire_count );
	}

	/** The explicit operation enforces WooCommerce management capability. */
	public function test_capability_is_enforced_inside_the_service(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = false;

		$result = $this->editor->edit( $this->request( '000742', 'product-code:741:forbidden' ) );

		$this->assertSame( 'digitalogic_product_code_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $GLOBALS['wpdb']->acquire_count );
	}

	/**
	 * Build a complete exact-state request.
	 *
	 * @param string $product_code Desired code.
	 * @param string $request_id Request idempotency key.
	 * @return array
	 */
	private function request( $product_code, $request_id ) {
		return array(
			'product_id'    => 741,
			'expected_code' => '000741',
			'product_code'  => $product_code,
			'if_match'      => $this->editor->revision_for( 741, '000741' ),
			'request_id'    => $request_id,
		);
	}

	/**
	 * Prepare and return one exact request-scoped editability hint.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $cached_code Cached Product Code.
	 * @return array
	 */
	private function admin_editability( $product_id, $cached_code ) {
		$this->editor->reset_editability_cache();
		$this->editor->prepare_admin_read_batch( array( $product_id ) );

		return $this->editor->editability_for( $product_id, $cached_code );
	}

	/**
	 * Prepare and return one exact request-scoped recovery handoff.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|WP_Error
	 */
	private function admin_recovery_intent( $product_id ) {
		$this->editor->reset_editability_cache();
		$this->editor->prepare_admin_read_batch( array( $product_id ) );

		return $this->editor->recovery_intent_for( $product_id );
	}

	/**
	 * Build a complete valid interrupted-operation fixture.
	 *
	 * @param array $request Complete request.
	 * @return array
	 */
	private function interrupted_record( $request ) {
		$method            = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$validated         = $method->invoke( $this->editor, $request );
		$readback_method   = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'read_exact_product_code' );
		$before            = $readback_method->invoke( $this->editor, 741 );
		$guard_method      = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'source_guard' );
		$governance        = $guard_method->invoke( $this->editor, 741, $request['expected_code'], $request['product_code'], $before );
		$projection_method = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'projection_checkpoint' );
		$projection        = $projection_method->invoke( $this->editor, 741 );
		$backup_method     = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'backup_reference' );
		$backup_reference  = $backup_method->invoke( $this->editor, $validated, $before, $governance['proof'] );

		return array(
			'schema'              => Digitalogic_Product_Code_Editor::SCHEMA,
			'status'              => 'in_progress',
			'request_fingerprint' => $validated['fingerprint'],
			'product_id'          => 741,
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'backup_reference'    => $backup_reference,
			'rollback_data'       => array(
				'meta_exists'  => (bool) $before['meta_exists'],
				'product_code' => (string) $before['product_code'],
				'revision'     => $this->editor->revision_for( 741, (string) $before['product_code'] ),
			),
			'actor_id'            => 17,
			'governance_proof'    => $governance['proof'],
			'projection'          => $projection,
			'attempts'            => 1,
			'updated_at'          => gmdate( 'c' ),
		);
	}

	/**
	 * Return the durable hashed option name used for one request.
	 *
	 * @param string $request_id Exact request identifier.
	 * @return string
	 */
	private function operation_option_name( $request_id ) {
		return 'digitalogic_product_code_edit_' . hash( 'sha256', (string) $request_id );
	}

	/**
	 * Return the durable per-product recovery pointer name.
	 *
	 * @param int $product_id Product or variation identifier.
	 * @return string
	 */
	private function recovery_option_name( $product_id ) {
		return 'digitalogic_product_code_recovery_' . hash(
			'sha256',
			Digitalogic_Product_Code_Editor::SCHEMA . "\n" . (string) $product_id
		);
	}

	/**
	 * Reset a private static singleton between tests.
	 *
	 * @param string $class_name Class name.
	 * @return void
	 */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
