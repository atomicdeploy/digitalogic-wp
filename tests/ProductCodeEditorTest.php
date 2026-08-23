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
		$GLOBALS['digitalogic_test_actions']                      = array();
		$GLOBALS['digitalogic_test_action_callbacks']             = array();
		$GLOBALS['digitalogic_test_filters']                      = array();
		$GLOBALS['digitalogic_test_options']                      = array(
			Digitalogic_Product_Sync_Receiver::STATE_OPTION => array( 'sources' => array() ),
		);
		$GLOBALS['digitalogic_test_option_cache']                 = array();
		$GLOBALS['digitalogic_test_posts']                        = array(
			741 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'meta'        => array(
					'_digitalogic_patris_product_code' => '000741',
					'_sku'                             => 'SKU-741',
				),
			),
		);
		$GLOBALS['digitalogic_test_post_meta_cache']              = array();
		$GLOBALS['digitalogic_test_wc_products']                  = array();
		$GLOBALS['digitalogic_test_meta_update_failures']         = array();
		$GLOBALS['digitalogic_test_meta_delete_failures']         = array();
		$GLOBALS['digitalogic_test_update_failures']              = array();
		$GLOBALS['digitalogic_test_cache_deletes']                = array();
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		$GLOBALS['digitalogic_test_capabilities']                 = array( 'manage_woocommerce' => true );
		$GLOBALS['digitalogic_test_current_user_id']              = 17;
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

		$this->reset_singleton( Digitalogic_Product_Code_Editor::class );
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		Digitalogic_Logger::instance()->entries = array();
		$this->editor                           = Digitalogic_Product_Code_Editor::instance();
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
		$this->assertSame( array( 0, 0, 0 ), $GLOBALS['wpdb']->lock_timeouts );
		$this->assertSame( 3, $GLOBALS['wpdb']->acquire_count );
		$this->assertSame( 3, $GLOBALS['wpdb']->release_count );
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
		$this->assertSame( 5, $GLOBALS['wpdb']->acquire_count, 'Replay acquires only the shared source and short operation locks.' );
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

		$editability = $this->editor->editability_for( 741, '000741' );

		$this->assertFalse( $editability['editable'] );
		$this->assertSame( 'source_managed', $editability['reason'] );
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
	 * Build a complete valid interrupted-operation fixture.
	 *
	 * @param array $request Complete request.
	 * @return array
	 */
	private function interrupted_record( $request ) {
		$method    = new ReflectionMethod( Digitalogic_Product_Code_Editor::class, 'validate_request' );
		$validated = $method->invoke( $this->editor, $request );

		return array(
			'schema'              => Digitalogic_Product_Code_Editor::SCHEMA,
			'status'              => 'in_progress',
			'request_fingerprint' => $validated['fingerprint'],
			'product_id'          => 741,
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'backup_reference'    => 'sha256:' . str_repeat( 'd', 64 ),
			'rollback_data'       => array(
				'meta_exists'  => true,
				'product_code' => $request['expected_code'],
				'revision'     => $request['if_match'],
			),
			'actor_id'            => 17,
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
