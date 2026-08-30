<?php

use PHPUnit\Framework\TestCase;

final class ProductSyncReceiverTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities']         = array();
        $GLOBALS['digitalogic_test_filters']              = array();
        $GLOBALS['digitalogic_test_routes']               = array();
        $GLOBALS['digitalogic_test_options']              = array();
        $GLOBALS['digitalogic_test_option_cache']         = array();
        $GLOBALS['digitalogic_test_actions']              = array();
        $GLOBALS['digitalogic_test_action_callbacks']     = array();
        $GLOBALS['digitalogic_test_posts']                = array();
        $GLOBALS['digitalogic_test_post_meta_cache']      = array();
        $GLOBALS['digitalogic_test_update_failures']      = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes']        = array();
        $GLOBALS['digitalogic_test_wc_products']          = array();
        $GLOBALS['digitalogic_test_wc_product_saves']     = array();
        $GLOBALS['digitalogic_test_wc_save_failures']     = array();
        $GLOBALS['digitalogic_test_wc_currency']          = 'IRT';
        $GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();
        $this->resetSingleton(Digitalogic_Product_Sync_Receiver::class);
    }

    public function test_accepts_current_golden_fixture_and_requires_catalog_arrays(): void {
        $path   = __DIR__ . '/fixtures/patris-product-sync-golden.json';
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json(file_get_contents($path));

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertSame('accepted', $result['status']);
        $this->assertSame(0, $result['stored_products']);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('patris-export', 'ALLANBAR');
        $this->assertSame(array(
            'source',
            'generated_at',
            'generated_at_order',
            'last_event_id',
            'last_event_type',
            'products',
            'categories',
            'excluded_codes',
            'quarantined_codes',
            'recent_events',
            'applied_products',
            'pending_products',
            'deferred_products',
            'received_at',
        ), array_keys($state));

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($payload['categories']);
        $invalid = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
        $this->assertInstanceOf(WP_Error::class, $invalid);
        $this->assertSame('digitalogic_product_sync_missing_field', $invalid->get_error_code());
    }

	/** A 101001001-like incomplete row is public, exact, idempotent, and later promotable. */
	public function test_incomplete_source_product_is_materialized_once_then_promoted_without_a_duplicate(): void {
		$observed  = array();
		$completed = 0;
		add_action(
			'digitalogic_patris_materializer_product_committed',
			function ( $snapshot ) use ( &$observed ) {
				$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
				$observed[] = $snapshot;
			}
		);
		add_action(
			'digitalogic_patris_materializer_product_committed',
			static function () {
				throw new RuntimeException( 'Injected post-commit alert listener failure.' );
			},
			20
		);
		add_action(
			'digitalogic_patris_materializer_product_commits_complete',
			function () use ( &$completed ) {
				$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
				++$completed;
			}
		);
		$product                = array(
			'product_code' => '101001001',
			'name'         => 'Patris incomplete source product',
			// Positive physical stock must not make an unpriced product
			// purchasable or leave the receiver retrying forever.
			'total_stock'  => 4,
			'weight_grams' => null,
			'warnings'     => array(
				'final_price_unavailable',
				'foreign_price_missing',
				'markup_percent_missing',
				'shipping_method_missing',
				'weight_missing',
			),
		);
		$product['record_hash'] = $this->recordHash( $product, true );
		$receiver               = Digitalogic_Product_Sync_Receiver::instance();

		$first = $receiver->receive( $this->snapshot( array( $product ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertSame( 1, $first['woocommerce']['attempted'] );
		$this->assertSame( 1, $first['woocommerce']['created'] );
		$this->assertSame( 1, $first['woocommerce']['updated'] );
		$this->assertSame( 0, $first['woocommerce']['missing'] );
		$this->assertSame( 0, $first['pending_products'] );
		$this->assertSame( 0, $first['deferred_products'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		$woo        = wc_get_product( $product_id );
		$this->assertSame( 'publish', $woo->get_status() );
		$this->assertSame( 'visible', $woo->get_catalog_visibility() );
		$this->assertSame( '101001001', $woo->get_sku() );
		$this->assertSame( '101001001', $woo->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		$this->assertSame( 'tests', $woo->get_meta( Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META, true ) );
		$this->assertSame( 'ALLANBAR', $woo->get_meta( Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META, true ) );
		$this->assertSame( '101001001', $woo->get_meta( Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META, true ) );
		$this->assertSame( $product['record_hash'], $woo->get_meta( '_digitalogic_patris_record_hash', true ) );
		$this->assertSame( '', $woo->get_regular_price() );
		$this->assertSame( '', $woo->get_price() );
		$this->assertSame( '', $woo->get_sale_price() );
		$this->assertSame( 0, $woo->get_stock_quantity() );
		$this->assertSame( 'outofstock', $woo->get_stock_status() );
		$this->assertSame( '4', (string) $woo->get_meta( '_digitalogic_patris_total_stock', true ) );
		$this->assertSame( '', $woo->get_meta( Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) );
		$this->assertSame( 'canonical_missing_unpriced', $woo->get_meta( '_digitalogic_patris_price_status', true ) );
		$this->assertSame(
			array( 'freight', 'image', 'markup', 'price', 'seo', 'weight' ),
			json_decode( $woo->get_meta( Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META, true ), true, 512, JSON_THROW_ON_ERROR )
		);
		$this->resetSingleton( Digitalogic_Report_Engine::class );
		$report = Digitalogic_Report_Engine::instance()->get_report( array( 'view' => 'price_list' ) );
		$this->assertNotInstanceOf( WP_Error::class, $report );
		$this->assertSame( 1, $report['counts']['matched_products'] );
		$this->assertSame( 0, $report['counts']['source_only_products'] );
		$this->assertCount( 1, $observed );
		$this->assertSame( 1, $completed );
		$this->assertSame( $product_id, $observed[0]['product_id'] );
		$this->assertFalse( $observed[0]['purchasable'] );

		$unchanged = $receiver->receive(
			$this->snapshot( array( $product ), array(), false, '2026-07-20T00:01:00Z' )
		);
		$this->assertNotInstanceOf( WP_Error::class, $unchanged );
		$this->assertSame( 'already_current', $unchanged['status'] );
		$this->assertSame( 0, $unchanged['woocommerce']['attempted'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
		$this->assertCount( 1, $observed );
		$this->assertSame( 1, $completed );

		$complete                = array(
			'product_code'                   => '101001001',
			'name'                           => 'Patris complete source product',
			'sale_price_source'              => 1234500,
			'price_source_amount'            => 1234500,
			'price_source_currency'          => 'IRR',
			'price_source_kind'              => 'sale_price_direct',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => 0,
			'shipping_price_per_kg_currency' => 'IRR',
			'weight_grams'                   => 100,
			'total_stock'                    => 4,
			'final_price'                    => 123450,
			'warnings'                       => array(
				'freight_not_applied_for_sale_price_direct',
				'sale_price_direct_fallback_used',
			),
		);
		$complete['record_hash'] = $this->recordHash( $complete, true );
		$promoted                = $receiver->receive(
			$this->snapshot( array( $complete ), array(), true, '2026-07-20T00:02:00Z' )
		);
		$this->assertNotInstanceOf( WP_Error::class, $promoted );
		$this->assertSame( 0, $promoted['woocommerce']['created'] );
		$this->assertSame( 1, $promoted['woocommerce']['updated'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
		$woo = wc_get_product( $product_id );
		$this->assertSame( '123450', $woo->get_regular_price() );
		$this->assertSame( '123450', $woo->get_price() );
		$this->assertSame( 4, $woo->get_stock_quantity() );
		$this->assertSame( 'instock', $woo->get_stock_status() );
		$this->assertSame( 'domestic', $woo->get_meta( Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) );
		$this->assertSame( 'priced', $woo->get_meta( '_digitalogic_patris_price_status', true ) );
		$this->assertSame( array( 'image', 'markup', 'seo' ), json_decode( $woo->get_meta( Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META, true ), true, 512, JSON_THROW_ON_ERROR ) );
		$this->assertCount( 2, $observed );
		$this->assertSame( 2, $completed );
		$this->assertTrue( $observed[1]['purchasable'] );
	}

	/** Bounded reconciliation materializes every safe missing Code without starvation. */
	public function test_reconciliation_materializes_a_bounded_backlog_without_deferral(): void {
		$products = array();
		for ( $index = 1; $index <= 30; $index++ ) {
			$product                = array(
				'product_code' => sprintf( 'MISSING-%02d', $index ),
				'warnings'     => array(),
			);
			$product['record_hash'] = $this->recordHash( $product, true );
			$products[]             = $product;
		}
		$receiver = Digitalogic_Product_Sync_Receiver::instance();

		$first = $receiver->receive( $this->snapshot( $products ) );
		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertSame( 25, $first['woocommerce']['attempted'] );
		$this->assertSame( 5, $first['pending_products'] );
		$this->assertSame( 25, $first['woocommerce']['created'] );
		$this->assertSame( 0, $first['deferred_products'] );

		$retry = $receiver->reconcile( 'tests', 'ALLANBAR' );
		$this->assertNotInstanceOf( WP_Error::class, $retry );
		$this->assertSame( 5, $retry['sources'][0]['woocommerce']['attempted'] );
		$this->assertSame( 0, $retry['pending_products'] );
		$this->assertSame( 5, $retry['sources'][0]['woocommerce']['created'] );
		$this->assertSame( 0, $retry['deferred_products'] );
		$this->assertCount( 30, $GLOBALS['digitalogic_test_posts'] );
	}

	/** A temporarily unavailable materializer remains retryable, never deferred as missing. */
	public function test_unmaterialized_not_found_record_remains_pending_instead_of_deferred(): void {
		add_filter(
			'digitalogic_patris_auto_materialize_source_product',
			static function () {
				return false;
			}
		);
		$product                = array(
			'product_code' => 'SAFE-PENDING',
			'name'         => 'Safe pending source leaf',
			'warnings'     => array(),
		);
		$product['record_hash'] = $this->recordHash( $product, true );

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $result['woocommerce']['missing'] );
		$this->assertSame( 1, $result['pending_products'] );
		$this->assertSame( 0, $result['deferred_products'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_posts'] );
	}

	/** An exact metadata read failure is transient, not an identity-hazard deferral. */
	public function test_identity_readback_failure_remains_pending_and_retryable(): void {
		$GLOBALS['digitalogic_test_posts'][902]    = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Existing exact product',
			'meta'         => array(
				'_sku'                             => 'READBACK-RETRY',
				'_digitalogic_patris_product_code' => 'READBACK-RETRY',
			),
		);
		$GLOBALS['wpdb']->exact_meta_query_failure = true;

		$product = array(
			'product_code' => 'READBACK-RETRY',
			'name'         => 'Existing exact product',
			'warnings'     => array(),
		);

		$product['record_hash'] = $this->recordHash( $product, true );

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $result['woocommerce']['failed'] );
		$this->assertSame( 0, $result['woocommerce']['identity_hazard'] );
		$this->assertSame( 1, $result['pending_products'] );
		$this->assertSame( 0, $result['deferred_products'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( '', get_post_meta( 902, '_digitalogic_patris_record_hash', true ) );
	}

	/** An outer catalog lock delays the warning action until its final release. */
	public function test_materializer_commit_hook_waits_for_the_outer_source_lock_release(): void {
		$observed = array();
		add_action(
			'digitalogic_patris_materializer_product_committed',
			function ( $snapshot ) use ( &$observed ) {
				$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
				$observed[] = $snapshot;
			}
		);
		$product                = array(
			'product_code' => 'OUTER-LOCK-COMMIT',
			'name'         => 'Outer lock source leaf',
			'warnings'     => array(),
		);
		$product['record_hash'] = $this->recordHash( $product, true );
		$receiver               = Digitalogic_Product_Sync_Receiver::instance();

		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );
		$result = $receiver->receive( $this->snapshot( array( $product ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $observed );

		$receiver->release_source_identity_lock();
		$this->assertCount( 1, $observed );
		$this->assertSame( 'OUTER-LOCK-COMMIT', $observed[0]['product_code'] );
	}

	/** A unique SKU-only owner is a concrete identity hazard, never a duplicate-create hint. */
	public function test_sku_only_identity_collision_stays_deferred_without_a_duplicate(): void {
		$GLOBALS['digitalogic_test_posts'][900] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'simple',
			'post_title'   => 'Existing SKU-only product',
			'meta'         => array( '_sku' => '101001001' ),
		);

		$product = array(
			'product_code' => '101001001',
			'name'         => 'Conflicting source product',
			'total_stock'  => 0,
			'warnings'     => array(),
		);

		$product['record_hash'] = $this->recordHash( $product, true );

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $result['woocommerce']['attempted'] );
		$this->assertSame( 0, $result['woocommerce']['created'] );
		$this->assertSame( 1, $result['woocommerce']['identity_hazard'] );
		$this->assertSame( 1, $result['deferred_products'] );
		$this->assertSame( 1, $result['deferred_reconciliation']['identity_hazard'] );
		$this->assertSame( 'identity_hazard', $result['deferred_reconciliation']['details'][0]['reason'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( '', get_post_meta( 900, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );

		$retry = Digitalogic_Product_Sync_Receiver::instance()->reconcile( 'tests', 'ALLANBAR' );
		$this->assertNotInstanceOf( WP_Error::class, $retry );
		$this->assertSame( 1, $retry['deferred_products'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
	}

	/** A variable container can never become the exact owner of one source leaf. */
	public function test_variable_parent_leaf_identity_is_deferred_as_a_hazard(): void {
		$GLOBALS['digitalogic_test_posts'][901] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => 'variable',
			'post_title'   => 'Unsafe variable owner',
			'meta'         => array(
				'_sku'                             => 'VARIABLE-LEAF',
				'_digitalogic_patris_product_code' => 'VARIABLE-LEAF',
			),
		);

		$product = array(
			'product_code' => 'VARIABLE-LEAF',
			'name'         => 'Source leaf',
			'warnings'     => array(),
		);

		$product['record_hash'] = $this->recordHash( $product, true );

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $result['woocommerce']['identity_hazard'] );
		$this->assertSame( 1, $result['deferred_products'] );
		$this->assertSame( 0, $result['woocommerce']['updated'] );
		$this->assertSame( '', get_post_meta( 901, '_digitalogic_patris_record_hash', true ) );
	}

	/** Automatic creation leaves the established 757 priced-leaf cohort byte-for-byte intact. */
	public function test_incomplete_materialization_does_not_regress_the_757_priced_leaf_parity_cohort(): void {
		$before = array();
		for ( $index = 1; $index <= 757; ++$index ) {
			$product_id   = 1000 + $index;
			$product_code = sprintf( 'PRICED-%03d', $index );
			$price        = (string) ( 100000 + $index );
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'product_type' => 'simple',
				'post_title'   => $product_code,
				'meta'         => array(
					'_sku'                             => $product_code,
					'_regular_price'                   => $price,
					'_price'                           => $price,
					'_digitalogic_patris_product_code' => $product_code,
					'_digitalogic_patris_price_status' => 'priced',
				),
			);
			// phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Loop capture is independent of the fixture below.
			$before[ $product_id ] = $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'];
		}
		// phpcs:ignore Generic.Formatting.MultipleStatementAlignment -- Fixture assignment is independent of the loop capture above.
		$product = array(
			'product_code' => '101001001',
			'name'         => 'New incomplete leaf',
			'total_stock'  => 0,
			'warnings'     => array(),
		);

		$product['record_hash'] = $this->recordHash( $product, true );

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 1, $result['woocommerce']['created'] );
		$this->assertCount( 758, $GLOBALS['digitalogic_test_posts'] );
		$after = array();
		foreach ( array_keys( $before ) as $product_id ) {
			$after[ $product_id ] = $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'];
		}
		$this->assertCount( 757, $after );
		$this->assertSame( $before, $after );
	}

	/** Receiver state listeners run only after a verified owning transaction commits. */
	public function test_source_state_commit_hook_runs_after_commit_and_not_after_commit_failure(): void {
		$observed = array();
		add_action(
			'digitalogic_product_sync_state_committed',
			static function ( $before, $after ) use ( &$observed ) {
				$observed[] = array(
					'commit_visible' => in_array( 'COMMIT', $GLOBALS['wpdb']->queries, true ),
					'before'         => $before,
					'after'          => $after,
				);
			},
			10,
			2
		);

		$accepted = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot() );
		$this->assertNotInstanceOf( WP_Error::class, $accepted );
		$this->assertCount( 1, $observed );
		$this->assertTrue( $observed[0]['commit_visible'] );
		$this->assertSame( array(), $observed[0]['before']['sources'] );
		$this->assertCount( 1, $observed[0]['after']['sources'] );

		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );
		$failed = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( array(), array(), false, '2026-07-20T00:01:00Z' )
		);
		$this->assertInstanceOf( WP_Error::class, $failed );
		$this->assertSame( 'digitalogic_product_sync_commit_failed', $failed->get_error_code() );
		$this->assertCount( 1, $observed );
	}

	/** Large deliveries yield the global receiver lock and resume from durable pending work. */
	public function test_large_delivery_is_bounded_and_same_event_replay_drains_pending_products(): void {
		$products = array();
		for ( $index = 1; $index <= 30; $index++ ) {
			$product_code = sprintf( 'BATCH-%03d', $index );
			$product_id   = 8000 + $index;
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'meta'        => array( '_digitalogic_patris_product_code' => $product_code ),
			);
			$product                = array(
				'product_code' => $product_code,
				'name'         => 'Batch product ' . $index,
				'warnings'     => array(),
			);
			$product['record_hash'] = $this->recordHash( $product, true );
			$products[]             = $product;
		}

		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$payload  = $this->snapshot( $products );
		$first    = $receiver->receive( $payload );

		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'partially_applied', $first['status'] );
		$this->assertTrue( $first['retryable'] );
		$this->assertSame( 25, $first['woocommerce']['attempted'] );
		$this->assertSame( 5, $first['pending_products'] );

		$replay = $receiver->receive( $payload );

		$this->assertNotInstanceOf( WP_Error::class, $replay );
		$this->assertSame( 'recovered', $replay['status'] );
		$this->assertFalse( $replay['retryable'] );
		$this->assertSame( 5, $replay['woocommerce']['attempted'] );
		$this->assertSame( 0, $replay['pending_products'] );
	}

    public function test_sparse_null_empty_and_missing_values_remain_distinct(): void {
        $GLOBALS['digitalogic_test_posts'][701] = array(
            'post_type'   => 'product',
            'post_status' => 'publish',
            'meta'        => array('_digitalogic_patris_product_code' => 'SPARSE-701'),
        );
        $product                                = array(
            'product_code'    => 'SPARSE-701',
            'name'            => null,
            'unit'            => '',
            'warehouse_stock' => array(),
            'warnings'        => array(),
        );
        $product['record_hash']                 = $this->recordHash($product, true);
        $category                               = array(
            'category_code' => 'ROOT',
            'name'          => null,
            'parent_code'   => '',
            'depth'         => 1,
            'warnings'      => array(),
        );
        $category['record_hash']                = $this->recordHash($category);
        $payload                                = $this->snapshot(array($product), array($category));

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $woo           = wc_get_product(701);
        $nullFields    = json_decode($woo->meta['_digitalogic_patris_null_fields'], true, 512, JSON_THROW_ON_ERROR);
        $missingFields = json_decode($woo->meta['_digitalogic_patris_missing_fields'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertContains('name', $nullFields);
        $this->assertContains('serial', $missingFields);
        $this->assertSame('', $woo->meta['_digitalogic_patris_unit']);
        $this->assertSame('[]', $woo->meta['_digitalogic_patris_warehouse_stock']);

        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertNull($state['products']['SPARSE-701']['name']);
        $this->assertSame('', $state['products']['SPARSE-701']['unit']);
        $this->assertSame(array(), $state['products']['SPARSE-701']['warehouse_stock']);
        $this->assertNull($state['categories']['ROOT']['name']);
    }

	/** Same-hash snapshots repair canonical metadata without inventing an unpriced canonical value. */
	public function test_same_hash_snapshot_repairs_priced_canonical_meta_without_erasing_unpriced_fallback(): void {
		$GLOBALS['digitalogic_test_posts'][711] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array(
				'_digitalogic_patris_product_code' => '101002006',
				'_digitalogic_shipping_method_id'  => 'domestic',
				'_regular_price'                   => '1',
				'_sale_price'                      => '',
				'_price'                           => '1',
			),
		);
		$GLOBALS['digitalogic_test_posts'][712] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array(
				'_digitalogic_patris_product_code' => 'UNPRICED-712',
				'_regular_price'                   => '777',
				'_sale_price'                      => '',
				'_price'                           => '777',
			),
		);

		$priced                  = array(
			'product_code'                   => '101002006',
			'partner_price_source'           => 949661,
			'price_source_amount'            => 949661,
			'price_source_currency'          => 'IRR',
			'price_source_kind'              => 'partner_price',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => 0,
			'shipping_price_per_kg_currency' => 'IRR',
			'markup_percent'                 => 30,
			'price_rounding_digits'          => 2,
			'price_rounding_mode'            => 'nearest_half_up',
			'final_price'                    => 123500,
			'warnings'                       => array(),
		);
		$priced['record_hash']   = $this->recordHash( $priced, true );
		$unpriced                = array(
			'product_code'          => 'UNPRICED-712',
			'price_rounding_digits' => 2,
			'price_rounding_mode'   => 'nearest_half_up',
			'warnings'              => array( 'missing_weight' ),
		);
		$unpriced['record_hash'] = $this->recordHash( $unpriced, true );
		$products                = array( $priced, $unpriced );

		$first = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( $products, array(), true )
		);
		$this->assertNotInstanceOf( WP_Error::class, $first );
		$this->assertSame( '123500', (string) get_post_meta( 711, '_digitalogic_patris_final_price', true ) );
		$this->assertSame( 'canonical_missing_preserved', get_post_meta( 712, '_digitalogic_patris_price_status', true ) );

		unset(
			$GLOBALS['digitalogic_test_posts'][711]['meta']['_digitalogic_patris_final_price'],
			$GLOBALS['digitalogic_test_posts'][711]['meta']['_digitalogic_patris_price_source_amount'],
			$GLOBALS['digitalogic_test_wc_products'][711]->meta['_digitalogic_patris_final_price'],
			$GLOBALS['digitalogic_test_wc_products'][711]->meta['_digitalogic_patris_price_source_amount']
		);

		$second = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( $products, array(), true, '2026-07-20T00:01:00Z' )
		);

		$this->assertNotInstanceOf( WP_Error::class, $second );
		$this->assertSame( 1, $second['woocommerce']['updated'] );
		$this->assertSame( '123500', (string) get_post_meta( 711, '_digitalogic_patris_final_price', true ) );
		$this->assertSame( '949661', (string) get_post_meta( 711, '_digitalogic_patris_price_source_amount', true ) );
		$this->assertSame( '123500', wc_get_product( 711 )->get_regular_price() );
		$this->assertSame( '123500', wc_get_product( 711 )->get_price() );
		$this->assertSame( '', wc_get_product( 711 )->get_sale_price() );
		$this->assertFalse( metadata_exists( 'post', 712, '_digitalogic_patris_final_price' ) );
		$this->assertSame( '777', wc_get_product( 712 )->get_regular_price() );
		$this->assertSame( '777', wc_get_product( 712 )->get_price() );
		$this->assertSame( '', wc_get_product( 712 )->get_sale_price() );
		$this->assertSame( 'canonical_missing_preserved', get_post_meta( 712, '_digitalogic_patris_price_status', true ) );

		unset(
			$GLOBALS['digitalogic_test_posts'][711]['meta']['_digitalogic_patris_final_price'],
			$GLOBALS['digitalogic_test_posts'][711]['meta']['_digitalogic_patris_price_source_amount'],
			$GLOBALS['digitalogic_test_wc_products'][711]->meta['_digitalogic_patris_final_price'],
			$GLOBALS['digitalogic_test_wc_products'][711]->meta['_digitalogic_patris_price_source_amount']
		);
		$replay = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( $products, array(), true, '2026-07-20T00:01:00Z' )
		);

		$this->assertNotInstanceOf( WP_Error::class, $replay );
		$this->assertSame( 'recovered', $replay['status'] );
		$this->assertSame( 1, $replay['woocommerce']['updated'] );
		$this->assertSame( '123500', (string) get_post_meta( 711, '_digitalogic_patris_final_price', true ) );
	}

    public function test_rejects_removed_contract_fields_and_null_final_price(): void {
        $payload                   = $this->snapshot();
        $payload['obsolete_field'] = 'anything';
        $result                    = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
        $this->assertSame('digitalogic_product_sync_unknown_field', $result->get_error_code());

        $product                = array('product_code' => 'NULL-PRICE', 'final_price' => null, 'warnings' => array());
        $product['record_hash'] = $this->recordHash($product, true);
        $payload                = $this->snapshot(array($product), array(), true);
        $result                 = Digitalogic_Product_Sync_Receiver::instance()->receive($payload);
        $this->assertSame('digitalogic_product_sync_field_invalid', $result->get_error_code());
    }

    public function test_cny_and_irr_freight_produce_the_same_final_irt_price(): void {
        $base                        = array(
            'foreign_currency'      => 'CNY',
            'foreign_price'         => 100,
            'price_source_amount'   => 100,
            'price_source_currency' => 'CNY',
            'price_source_kind'     => 'foreign_price',
            'weight_grams'          => 1000,
            'shipping_method_id'    => 'air_express',
            'markup_percent'        => 30,
            'irt_per_cny'           => 30000,
            'price_rounding_digits' => 2,
            'price_rounding_mode'   => 'nearest_half_up',
            'final_price'           => 4680000,
            'warnings'              => array(),
        );
        $cny                         = array_merge($base, array(
            'product_code'                   => 'CNY-FREIGHT',
            'shipping_price_per_kg'          => 20,
            'shipping_price_per_kg_currency' => 'CNY',
        ));
        $cny['record_hash']          = $this->recordHash($cny, true);
        $irr                         = array_merge($base, array(
            'product_code'                   => 'IRR-FREIGHT',
            'shipping_price_per_kg'          => 6000000,
            'shipping_price_per_kg_currency' => 'IRR',
        ));
        $irr['record_hash']          = $this->recordHash($irr, true);
        $explicitNull                = array(
            'product_code'                   => 'NULL-FREIGHT',
            'shipping_price_per_kg'          => null,
            'shipping_price_per_kg_currency' => null,
            'price_rounding_digits'          => 2,
            'price_rounding_mode'            => 'nearest_half_up',
            'warnings'                       => array(),
        );
        $explicitNull['record_hash'] = $this->recordHash($explicitNull, true);
		$nullCurrency                = array(
			'product_code'                   => 'NULL-CURRENCY',
			'shipping_price_per_kg'          => 20,
			'shipping_price_per_kg_currency' => null,
			'price_rounding_digits'          => 2,
			'price_rounding_mode'            => 'nearest_half_up',
			'warnings'                       => array(),
		);
		$nullCurrency['record_hash'] = $this->recordHash($nullCurrency, true);
		$nullAmount                  = array(
			'product_code'                   => 'NULL-AMOUNT',
			'shipping_price_per_kg'          => null,
			'shipping_price_per_kg_currency' => 'CNY',
			'price_rounding_digits'          => 2,
			'price_rounding_mode'            => 'nearest_half_up',
			'warnings'                       => array(),
		);
		$nullAmount['record_hash']   = $this->recordHash($nullAmount, true);

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($cny, $irr, $explicitNull, $nullCurrency, $nullAmount), array(), true)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $this->assertSame('accepted', $result['status']);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertSame(4680000, $state['products']['CNY-FREIGHT']['final_price']);
        $this->assertSame(4680000, $state['products']['IRR-FREIGHT']['final_price']);
        $this->assertNull($state['products']['NULL-FREIGHT']['shipping_price_per_kg']);
        $this->assertNull($state['products']['NULL-FREIGHT']['shipping_price_per_kg_currency']);
        $this->assertArrayNotHasKey('final_price', $state['products']['NULL-FREIGHT']);
		$this->assertSame('20', $state['products']['NULL-CURRENCY']['shipping_price_per_kg']);
		$this->assertNull($state['products']['NULL-CURRENCY']['shipping_price_per_kg_currency']);
		$this->assertArrayNotHasKey('final_price', $state['products']['NULL-CURRENCY']);
		$this->assertNull($state['products']['NULL-AMOUNT']['shipping_price_per_kg']);
		$this->assertSame('CNY', $state['products']['NULL-AMOUNT']['shipping_price_per_kg_currency']);
		$this->assertArrayNotHasKey('final_price', $state['products']['NULL-AMOUNT']);
    }

    public function test_partner_price_fallback_uses_irr_without_freight_and_rounds_nearest(): void {
        $products = array(
            array(
                'product_code'                   => 'PARTNER-UP',
                'partner_price_source'           => 949661,
                'price_source_amount'            => 949661,
                'price_source_currency'          => 'IRR',
                'price_source_kind'              => 'partner_price',
                'shipping_method_id'             => 'domestic',
                'shipping_price_per_kg'          => 0,
                'shipping_price_per_kg_currency' => 'IRR',
                'markup_percent'                 => 30,
                'price_rounding_digits'          => 2,
                'price_rounding_mode'            => 'nearest_half_up',
                'final_price'                    => 123500,
                'warnings'                       => array(),
            ),
            array(
                'product_code'                   => 'PARTNER-DOWN',
                'partner_price_source'           => 949600,
                'price_source_amount'            => 949600,
                'price_source_currency'          => 'IRR',
                'price_source_kind'              => 'partner_price',
                'shipping_method_id'             => 'domestic',
                'shipping_price_per_kg'          => 0,
                'shipping_price_per_kg_currency' => 'IRR',
                'markup_percent'                 => 30,
                'price_rounding_digits'          => 2,
                'price_rounding_mode'            => 'nearest_half_up',
                'final_price'                    => 123400,
                'warnings'                       => array(),
            ),
            array(
                'product_code'                   => 'PARTNER-HALF',
                'partner_price_source'           => 1234500,
                'price_source_amount'            => 1234500,
                'price_source_currency'          => 'IRR',
                'price_source_kind'              => 'partner_price',
                'shipping_method_id'             => 'domestic',
                'shipping_price_per_kg'          => 0,
                'shipping_price_per_kg_currency' => 'IRR',
                'markup_percent'                 => 0,
                'price_rounding_digits'          => 2,
                'price_rounding_mode'            => 'nearest_half_up',
                'final_price'                    => 123500,
                'warnings'                       => array(),
            ),
            array(
                'product_code'          => 'EXPLICIT-ZERO',
                'foreign_currency'      => 'CNY',
                'foreign_price'         => 0,
                'sale_price_source'     => 0,
                'price_rounding_digits' => 2,
                'price_rounding_mode'   => 'nearest_half_up',
                'warnings'              => array(),
            ),
        );
        foreach ($products as &$product) {
            $product['record_hash'] = $this->recordHash($product, true);
        }
        unset($product);

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot($products, array(), true)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertSame(123500, $state['products']['PARTNER-UP']['final_price']);
        $this->assertSame(123400, $state['products']['PARTNER-DOWN']['final_price']);
        $this->assertSame(123500, $state['products']['PARTNER-HALF']['final_price']);
        $this->assertSame('partner_price', $state['products']['PARTNER-UP']['price_source_kind']);
        $this->assertSame('949661', $state['products']['PARTNER-UP']['price_source_amount']);
        $this->assertSame('0', $state['products']['EXPLICIT-ZERO']['foreign_price']);
        $this->assertArrayNotHasKey('price_source_amount', $state['products']['EXPLICIT-ZERO']);
        $this->assertArrayNotHasKey('final_price', $state['products']['EXPLICIT-ZERO']);
    }

    public function test_opt_in_direct_sale_fallback_uses_distinct_forosh_without_markup_or_rounding(): void {
        $direct                = array(
            'product_code'                   => 'DIRECT-SALE',
            'sale_price_source'              => 1234500,
            'price_source_amount'            => 1234500,
            'price_source_currency'          => 'IRR',
            'price_source_kind'              => 'sale_price_direct',
            'shipping_method_id'             => 'domestic',
            'shipping_price_per_kg'          => 0,
            'shipping_price_per_kg_currency' => 'IRR',
            'final_price'                    => 123450,
            'warnings'                       => array(
                'freight_not_applied_for_sale_price_direct',
                'sale_price_direct_fallback_used',
            ),
        );
        $direct['record_hash'] = $this->recordHash($direct, true);

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($direct), array(), true)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertSame('sale_price_direct', $state['products']['DIRECT-SALE']['price_source_kind']);
        $this->assertSame('1234500', (string) $state['products']['DIRECT-SALE']['sale_price_source']);
        $this->assertSame(123450, $state['products']['DIRECT-SALE']['final_price']);
        $this->assertArrayNotHasKey('markup_percent', $state['products']['DIRECT-SALE']);
        $this->assertArrayNotHasKey('price_rounding_digits', $state['products']['DIRECT-SALE']);

        $wrong                 = $direct;
        $wrong['product_code'] = 'DIRECT-WRONG';
        $wrong['final_price']  = 160485;
        unset($wrong['record_hash']);
        $wrong['record_hash'] = $this->recordHash($wrong, true);
        $result               = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($wrong), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_final_price_mismatch', $result->get_error_code());

        foreach (
            array(
                'markup_percent'        => 30,
                'price_rounding_digits' => 0,
                'price_rounding_mode'   => 'nearest_half_up',
                'irt_per_cny'           => 30000,
            ) as $field => $value
        ) {
            $forbidden                 = $direct;
            $forbidden['product_code'] = 'DIRECT-FORBIDDEN-' . strtoupper($field);
            $forbidden[$field]         = $value;
            unset($forbidden['record_hash']);
            $forbidden['record_hash'] = $this->recordHash($forbidden, true);

            $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
                $this->snapshot(array($forbidden), array(), true)
            );

            $this->assertSame('digitalogic_product_sync_direct_sale_inputs_forbidden', $result->get_error_code());
            $this->assertSame(array($field), $result->get_error_data()['fields']);
        }
    }

    public function test_partner_price_never_reuses_patris_sale_price_or_non_domestic_shipping(): void {
        $product                = array(
            'product_code'                   => 'PARTNER-SEPARATION',
            'sale_price_source'              => 12000,
            'partner_price_source'           => 7000,
            'price_source_amount'            => 12000,
            'price_source_currency'          => 'IRR',
            'price_source_kind'              => 'partner_price',
            'shipping_method_id'             => 'domestic',
            'shipping_price_per_kg'          => 0,
            'shipping_price_per_kg_currency' => 'IRR',
            'markup_percent'                 => 30,
            'price_rounding_digits'          => 0,
            'price_rounding_mode'            => 'nearest_half_up',
            'final_price'                    => 1560,
            'warnings'                       => array(),
        );
        $product['record_hash'] = $this->recordHash($product, true);
        $result                 = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($product), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_price_source_mismatch', $result->get_error_code());

        $product['price_source_amount']            = 7000;
        $product['final_price']                    = 910;
        $product['shipping_method_id']             = 'air_express';
        $product['shipping_price_per_kg']          = 20;
        $product['shipping_price_per_kg_currency'] = 'CNY';
        unset($product['record_hash']);
        $product['record_hash'] = $this->recordHash($product, true);
        $result                 = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($product), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_final_price_mismatch', $result->get_error_code());
    }

    public function test_selected_price_source_is_atomic_and_complete_cny_route_is_first(): void {
        $partial                = array(
            'product_code'          => 'PARTIAL-SOURCE',
            'price_source_amount'   => 100,
            'price_source_currency' => 'IRR',
            'price_rounding_digits' => 0,
            'price_rounding_mode'   => 'nearest_half_up',
            'warnings'              => array(),
        );
        $partial['record_hash'] = $this->recordHash($partial, true);
        $result                 = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($partial), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_price_source_incomplete', $result->get_error_code());

        $fallback                = array(
            'product_code'                   => 'CNY-INCOMPLETE',
            'foreign_currency'               => 'CNY',
            'foreign_price'                  => 10,
            'weight_grams'                   => 0,
            'partner_price_source'           => 100000,
            'price_source_amount'            => 100000,
            'price_source_currency'          => 'IRR',
            'price_source_kind'              => 'partner_price',
            'shipping_method_id'             => 'domestic',
            'shipping_price_per_kg'          => 0,
            'shipping_price_per_kg_currency' => 'IRR',
            'markup_percent'                 => 30,
            'price_rounding_digits'          => 0,
            'price_rounding_mode'            => 'nearest_half_up',
            'final_price'                    => 13000,
            'warnings'                       => array(),
        );
        $fallback['record_hash'] = $this->recordHash($fallback, true);
        $result                  = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($fallback), array(), true)
        );
        $this->assertNotInstanceOf(WP_Error::class, $result);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertSame('0', (string) $state['products']['CNY-INCOMPLETE']['weight_grams']);
        $this->assertSame('partner_price', $state['products']['CNY-INCOMPLETE']['price_source_kind']);

        $priority = array_merge(
            $fallback,
            array(
                'product_code'                   => 'CNY-PRIORITY',
                'weight_grams'                   => 100,
                'shipping_method_id'             => 'air_express',
                'shipping_price_per_kg'          => 20,
                'shipping_price_per_kg_currency' => 'CNY',
                'irt_per_cny'                    => 30000,
            )
        );
        unset($priority['record_hash']);
        $priority['record_hash'] = $this->recordHash($priority, true);
        $result                  = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($priority), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_price_source_priority', $result->get_error_code());

        $zeroWeight = array_merge(
            $priority,
            array(
                'product_code'          => 'CNY-ZERO-WEIGHT',
                'price_source_amount'   => 10,
                'price_source_currency' => 'CNY',
                'price_source_kind'     => 'foreign_price',
                'weight_grams'          => 0,
                'final_price'           => 390000,
            )
        );
        unset($zeroWeight['record_hash']);
        $zeroWeight['record_hash'] = $this->recordHash($zeroWeight, true);
        $result                    = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($zeroWeight), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_field_invalid', $result->get_error_code());
        $this->assertSame('products[0].weight_grams', $result->get_error_data()['field']);
    }

    public function test_raw_price_facts_require_complete_policy_before_source_selection(): void {
        $incompleteCny                         = array(
            'product_code'                   => 'RAW-CNY-INCOMPLETE',
            'foreign_currency'               => 'CNY',
            'foreign_price'                  => 10,
            'partner_price_source'           => 100000,
            'weight_grams'                   => 100,
            'shipping_method_id'             => 'air_express',
            'shipping_price_per_kg'          => 20,
            'shipping_price_per_kg_currency' => 'CNY',
            'irt_per_cny'                    => 30000,
            'price_rounding_digits'          => 0,
            'price_rounding_mode'            => 'nearest_half_up',
            'warnings'                       => array(),
        );
        $incompleteCny['record_hash']          = $this->recordHash($incompleteCny, true);
        $incompletePartner                     = array(
            'product_code'          => 'RAW-PARTNER-INCOMPLETE',
            'partner_price_source'  => 100000,
            'price_rounding_digits' => 0,
            'price_rounding_mode'   => 'nearest_half_up',
            'warnings'              => array(),
        );
        $incompletePartner['record_hash']      = $this->recordHash($incompletePartner, true);
        $nullRounding                          = $incompleteCny;
        $nullRounding['product_code']          = 'RAW-ROUNDING-NULL';
        $nullRounding['markup_percent']        = 30;
        $nullRounding['price_rounding_digits'] = null;
        $nullRounding['warnings']              = array('price_rounding_digits_explicit_null');
        unset($nullRounding['price_rounding_mode'], $nullRounding['record_hash']);
        $nullRounding['record_hash'] = $this->recordHash($nullRounding, true);

        $result = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($incompleteCny, $incompletePartner, $nullRounding), array(), true)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        foreach (array('RAW-CNY-INCOMPLETE', 'RAW-PARTNER-INCOMPLETE', 'RAW-ROUNDING-NULL') as $code) {
            $this->assertArrayNotHasKey('price_source_amount', $state['products'][$code]);
            $this->assertArrayNotHasKey('final_price', $state['products'][$code]);
        }

        $completeCny                   = $incompleteCny;
        $completeCny['product_code']   = 'RAW-CNY-COMPLETE';
        $completeCny['markup_percent'] = 30;
        unset($completeCny['record_hash']);
        $completeCny['record_hash'] = $this->recordHash($completeCny, true);
        $result                     = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($completeCny), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_price_source_missing', $result->get_error_code());

        $completePartner                   = $incompletePartner;
        $completePartner['product_code']   = 'RAW-PARTNER-COMPLETE';
        $completePartner['markup_percent'] = 30;
        unset($completePartner['record_hash']);
        $completePartner['record_hash'] = $this->recordHash($completePartner, true);
        $result                         = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($completePartner), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_price_source_missing', $result->get_error_code());
    }

    public function test_explicit_null_rounding_is_preserved_and_withholds_final_price(): void {
        $product                = array(
            'product_code'                   => 'NULL-ROUNDING',
            'partner_price_source'           => 100000,
            'price_source_amount'            => 100000,
            'price_source_currency'          => 'IRR',
            'price_source_kind'              => 'partner_price',
            'shipping_method_id'             => 'domestic',
            'shipping_price_per_kg'          => 0,
            'shipping_price_per_kg_currency' => 'IRR',
            'markup_percent'                 => 30,
            'price_rounding_digits'          => null,
            'warnings'                       => array('price_rounding_digits_explicit_null'),
        );
        $product['record_hash'] = $this->recordHash($product, true);
        $result                 = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($product), array(), true)
        );

        $this->assertNotInstanceOf(WP_Error::class, $result);
        $state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state('tests', 'ALLANBAR');
        $this->assertNull($state['products']['NULL-ROUNDING']['price_rounding_digits']);
        $this->assertArrayNotHasKey('price_rounding_mode', $state['products']['NULL-ROUNDING']);
        $this->assertArrayNotHasKey('final_price', $state['products']['NULL-ROUNDING']);

        $product['price_rounding_mode'] = 'nearest_half_up';
        $product['record_hash']         = $this->recordHash($product, true);
        $result                         = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($product), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_field_invalid', $result->get_error_code());
    }

    public function test_shipping_price_and_currency_are_an_explicit_pair(): void {
        $priceOnly                = array(
            'product_code'          => 'PRICE-ONLY',
            'shipping_price_per_kg' => 20,
            'warnings'              => array(),
        );
        $priceOnly['record_hash'] = $this->recordHash($priceOnly, true);
        $result                   = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($priceOnly), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_shipping_currency_required', $result->get_error_code());

        $currencyOnly                = array(
            'product_code'                   => 'CURRENCY-ONLY',
            'shipping_price_per_kg_currency' => 'CNY',
            'warnings'                       => array(),
        );
        $currencyOnly['record_hash'] = $this->recordHash($currencyOnly, true);
        $result                      = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($currencyOnly), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_shipping_currency_required', $result->get_error_code());

        $unsupported                = array(
            'product_code'                   => 'UNSUPPORTED-CURRENCY',
            'shipping_price_per_kg'          => 20,
            'shipping_price_per_kg_currency' => 'USD',
            'warnings'                       => array(),
        );
        $unsupported['record_hash'] = $this->recordHash($unsupported, true);
        $result                     = Digitalogic_Product_Sync_Receiver::instance()->receive(
            $this->snapshot(array($unsupported), array(), true)
        );
        $this->assertSame('digitalogic_product_sync_field_invalid', $result->get_error_code());
    }

	/**
	 * Build a deterministic source snapshot.
	 *
	 * @param array  $products     Product records.
	 * @param array  $categories   Category records.
	 * @param bool   $pricing      Whether pricing context is active.
	 * @param string $generated_at Event generation timestamp.
	 * @return array
	 */
	private function snapshot(
		$products = array(),
		$categories = array(),
		$pricing = false,
		$generated_at = '2026-07-20T00:00:00Z'
	): array {
        $material = array();
        foreach ($products as $product) {
            $material[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        foreach ($categories as $category) {
            $material[] = 'category:' . $category['category_code'] . '=' . $category['record_hash'];
        }
        sort($material, SORT_STRING);
        $source   = array(
            'id'       => 'tests',
            'dataset'  => 'ALLANBAR',
            'revision' => 'sha256:' . hash('sha256', implode("\n", $material)),
        );
        $identity = array(
            'schema'     => 'patris.product-sync',
            'event_type' => 'snapshot',
        );
        if ($pricing) {
            $identity['local_currency'] = 'IRT';
            $identity['formula_id']     = 'landed_price';
        }
        $identity['source']            = $source;
        $identity['generated_at']      = $generated_at;
        $identity['products']          = array_map(static fn($product) => $product['product_code'] . '=' . $product['record_hash'], $products);
        $identity['categories']        = array_map(static fn($category) => $category['category_code'] . '=' . $category['record_hash'], $categories);
        $identity['excluded_codes']    = array();
        $identity['quarantined_codes'] = array();
        sort($identity['products'], SORT_STRING);
        sort($identity['categories'], SORT_STRING);

        $envelope = array(
            'schema'            => 'patris.product-sync',
            'event_type'        => 'snapshot',
            'event_id'          => 'sha256:' . hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'source'            => $source,
            'generated_at'      => $generated_at,
            'products'          => $products,
            'categories'        => $categories,
            'excluded_codes'    => array(),
            'quarantined_codes' => array(),
            'warnings'          => array(),
        );
        if ($pricing) {
            $envelope['local_currency'] = 'IRT';
            $envelope['formula_id']     = 'landed_price';
        }

        return $envelope;
    }

    private function recordHash($record, $warehouseMap = false): string {
        if ($warehouseMap && isset($record['warehouse_stock'])) {
            ksort($record['warehouse_stock'], SORT_STRING);
        }
        ksort($record, SORT_STRING);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($warehouseMap && array_key_exists('warehouse_stock', $record) && array() === $record['warehouse_stock']) {
            $json = str_replace('"warehouse_stock":[]', '"warehouse_stock":{}', $json);
        }
        return 'sha256:' . hash('sha256', $json);
    }

    private function resetSingleton($class): void {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
