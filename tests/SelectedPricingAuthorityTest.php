<?php
/**
 * Selected pricing authority integration regressions.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Exercise authority selection through real receiver and coordinator methods. */
final class SelectedPricingAuthorityTest extends TestCase {

	/** A slow full-product save cannot admit the next fallback after the deadline. */
	public function test_source_deadline_stops_before_second_full_product_save(): void {
		$payload  = $this->seed_ingress_batch();
		$before   = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$products = $payload['products'];
		foreach ( array( 0, 1 ) as $offset ) {
			$products[ $offset ]['name'] = 'Changed source name ' . $offset;
			$GLOBALS['digitalogic_test_posts'][ 20000 + $offset ]['post_status'] = 'draft';
			unset( $products[ $offset ]['record_hash'] );
			$products[ $offset ]['record_hash'] = $this->record_hash( $products[ $offset ] );
		}
		$now   = 100.0;
		$clock = new ReflectionProperty( Digitalogic_Pricing_Service::class, 'source_delivery_clock' );
		$clock->setValue(
			Digitalogic_Pricing_Service::instance(),
			static function () use ( &$now ) {
				return $now;
			}
		);
		$saved                                     = array();
		$GLOBALS['digitalogic_test_wc_after_save'] = static function ( $product ) use ( &$now, &$saved ) {
			$saved[] = $product->get_id();
			$now     = 161.0;
		};
		try {
			$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( $products, '2026-07-22T00:00:00Z' ) );
		} finally {
			$GLOBALS['digitalogic_test_wc_after_save'] = null;
			$clock->setValue( Digitalogic_Pricing_Service::instance(), null );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_source_delivery_deadline_exceeded', $result->get_error_code() );
		$this->assertNotContains( 20001, $saved );
		$this->assertContains( 20000, $saved );
		$this->assertSame( $before, Digitalogic_Product_Sync_Receiver::instance()->get_state() );
	}

	/** Site-owned product titles do not force otherwise identical rows into full saves. */
	public function test_source_batch_preserves_site_owned_titles_without_full_product_saves(): void {
		$payload = $this->seed_ingress_batch();
		foreach ( range( 20000, 20029 ) as $id ) {
			$GLOBALS['digitalogic_test_posts'][ $id ]['post_title'] = 'Site title ' . $id;
			$this->assertSame( '', (string) get_post_meta( $id, Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META, true ) );
		}
		$GLOBALS['digitalogic_test_options']['yuan_price']         = '29501';
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = '29501';
		$GLOBALS['digitalogic_test_wc_product_saves']              = array();
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $payload );
		$this->assert_success( $result );
		$this->assertSame( 30, $result['woocommerce']['updated'] );
		$this->assertSame( 1, $result['woocommerce']['batch_count'] );
		$this->assertSame( 'complete', $result['delivery']['status'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		foreach ( range( 20000, 20029 ) as $id ) {
			$this->assertSame( 'Site title ' . $id, $GLOBALS['digitalogic_test_posts'][ $id ]['post_title'] );
			$this->assertSame( '8437286', (string) get_post_meta( $id, '_price', true ) );
		}
	}
	/**
	 * A full source retry delivers every known leaf in one real SQL transaction.
	 */
	public function test_full_source_delivery_batches_more_than_twenty_five_known_products(): void {
		$payload = $this->seed_ingress_batch();
		$GLOBALS['digitalogic_test_options']['yuan_price']         = '29501';
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = '29501';
		$GLOBALS['digitalogic_test_wc_product_saves']              = array();
		$GLOBALS['wpdb']->queries                                  = array();
		$receipts = array();
		add_action(
			'digitalogic_product_sync_applied',
			function ( $receipt ) use ( &$receipts ) {
				$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
				$this->assertFalse( Digitalogic_Pricing_Service::coordination_lock_is_held() );
				$receipts[] = $receipt;
			}
		);
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $payload );
		$this->assert_success( $result );
		$this->assertSame( 30, $result['woocommerce']['updated'] );
		$this->assertSame( 1, $result['woocommerce']['batch_count'] );
		$this->assertSame( 0, $result['pending_products'] );
		$this->assertSame( 'complete', $result['delivery']['status'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertCount( 1, $receipts );
		$this->assertSame( 1, count( array_filter( $GLOBALS['wpdb']->queries, static fn( $query ) => 'START TRANSACTION' === $query ) ) );
		$this->assertSame( 1, count( array_filter( $GLOBALS['wpdb']->queries, static fn( $query ) => 'COMMIT' === $query ) ) );
		for ( $id = 20000; $id < 20030; ++$id ) {
			$this->assertSame( '8437286', (string) get_post_meta( $id, '_price', true ) );
		}
		$again = Digitalogic_Product_Sync_Receiver::instance()->receive( $payload );
		$this->assert_success( $again );
		$this->assertSame( 'replayed', $again['status'] );
		$this->assertCount( 2, $receipts );
	}

	/**
	 * Source operational changes use the existing batch and preserve site-owned titles.
	 */
	public function test_full_source_batch_preserves_changed_operational_fields(): void {
		$payload = $this->seed_ingress_batch();
		$GLOBALS['digitalogic_test_options']['yuan_price']         = '29501';
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = '29501';
		$products            = $payload['products'];
		$products[0]['name'] = 'Changed operational name';
		unset( $products[0]['record_hash'] );
		$products[0]['record_hash']                   = $this->record_hash( $products[0] );
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( $products, '2026-07-22T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( 1, $result['woocommerce']['batch_count'] );
		$this->assertSame( 30, $result['woocommerce']['updated'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( 'INGRESS-000', $GLOBALS['digitalogic_test_posts'][20000]['post_title'] );
		$this->assertSame( 'Changed operational name', get_post_meta( 20000, '_digitalogic_patris_name', true ) );
		$this->assertSame( '8437286', (string) get_post_meta( 20000, '_price', true ) );
	}

	/**
	 * Unavailable prices use the normal preservation policy alongside batched peers.
	 */
	public function test_full_source_batch_preserves_unavailable_price(): void {
		$payload = $this->seed_ingress_batch();
		$GLOBALS['digitalogic_test_options']['yuan_price']         = '29501';
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = '29501';
		$products = $payload['products'];
		unset( $products[29]['weight_grams'], $products[29]['final_price'], $products[29]['record_hash'] );
		$products[29]['warnings']                     = array( 'final_price_unavailable' );
		$products[29]['record_hash']                  = $this->record_hash( $products[29] );
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$result                                       = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( $products, '2026-07-22T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( 1, $result['woocommerce']['batch_count'] );
		$this->assertSame( 30, $result['woocommerce']['updated'] );
		$this->assertSame( 0, $result['pending_products'] );
		$this->assertSame( 'complete', $result['delivery']['status'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( 'canonical_missing_preserved', get_post_meta( 20029, Digitalogic_Patris_Price_Policy::STATUS_META, true ) );
		$this->assertNotSame( '', get_post_meta( 20029, Digitalogic_Patris_Price_Policy::WARNING_META, true ) );
		$this->assertSame( array(), get_post_meta( 20029, '_digitalogic_patris_final_price', false ) );
		$this->assertSame( '8437000', (string) get_post_meta( 20029, '_price', true ) );
		$this->assertSame( '8437286', (string) get_post_meta( 20000, '_price', true ) );
	}

	/**
	 * Batch failure restores source, prices and deferred events together.
	 */
	public function test_source_batch_writes_stock_facts_and_unpriced_transition_without_full_saves(): void {
		$payload = $this->seed_ingress_batch( 2 );

		$GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] = array();
		$GLOBALS['digitalogic_test_posts'][20000]['meta'][ Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META ] = '1';
		unset( $GLOBALS['digitalogic_test_post_meta_cache'][20000] );
		$products                             = $payload['products'];
		$products[0]['total_stock']           = 5;
		$products[0]['purchase_price_source'] = 123;
		$products[0]['source_updated_at']     = '2026-07-22T00:00:00Z';
		$products[0]['warehouse_stock']       = array( 'main' => 5 );
		$products[0]['name']                  = 'Updated source title';
		foreach ( array( 'foreign_price', 'price_source_kind', 'price_source_amount', 'price_source_currency', 'final_price' ) as $field ) {
			unset( $products[1][ $field ] );
		}
		foreach ( array( 0, 1 ) as $offset ) {
			unset( $products[ $offset ]['record_hash'] );
			$products[ $offset ]['record_hash'] = $this->record_hash( $products[ $offset ] );
		}
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$result                                       = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( $products, '2026-07-22T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( 'complete', $result['delivery']['status'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( '5', get_post_meta( 20000, '_stock', true ) );
		$this->assertSame( 'Updated source title', $GLOBALS['digitalogic_test_posts'][20000]['post_title'] );
		$this->assertContains( 20000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 20001, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertSame( '5', $GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['stock_quantity'] );
		$this->assertSame( '123', get_post_meta( 20000, '_digitalogic_patris_purchase_price_source', true ) );
		$this->assertSame( '2026-07-22T00:00:00Z', get_post_meta( 20000, '_digitalogic_patris_updated_at', true ) );
		$this->assertSame( '{"main":5}', get_post_meta( 20000, '_digitalogic_patris_warehouse_stock', true ) );
		$this->assertSame( '', get_post_meta( 20001, '_price', true ) );
		$this->assertSame( 'canonical_missing_unpriced', get_post_meta( 20001, Digitalogic_Patris_Price_Policy::STATUS_META, true ) );
		$this->assertSame( '2', get_post_meta( 20001, '_stock', true ) );
		$this->assertSame( 'outofstock', $GLOBALS['digitalogic_test_wc_lookup_rows'][20001]['stock_status'] );
		$this->assertNull( $GLOBALS['digitalogic_test_wc_lookup_rows'][20001]['min_price'] );
		$this->assertContains( 990001, $GLOBALS['digitalogic_test_object_terms'][20001]['product_visibility'] );
	}

	/** A real FX change does not restore an older source stock quantity after a sale. */
	public function test_owner_fx_reprice_preserves_current_stock_after_sale(): void {
		$this->seed_ingress_batch( 5 );
		$GLOBALS['digitalogic_test_posts'][20000]['meta'][ Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META ] = '1';
		$GLOBALS['digitalogic_test_posts'][20000]['meta']['_stock']          = '2';
		$GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['stock_quantity'] = '2';
		$GLOBALS['digitalogic_test_wc_products']                             = array();
		unset( $GLOBALS['digitalogic_test_post_meta_cache'][20000] );
		foreach ( array(
			'direct_db' => array( '29501', '8437286' ),
			'adapter'   => array( '29502', '8437572' ),
		) as $mode => $expected ) {
			$GLOBALS['digitalogic_test_wc_product_saves'] = array();
			Digitalogic_Pricing_Coordinator::instance()->set_write_mode( $mode );
			$result = Digitalogic_Pricing_Coordinator::instance()->update_currency( array( 'yuan_price' => $expected[0] ), 'stock_after_sale_' . $mode );
			$this->assert_success( $result );
			$this->assertSame( $expected[1], (string) get_post_meta( 20000, '_price', true ) );
			$this->assertSame( '2', (string) get_post_meta( 20000, '_stock', true ) );
			$this->assertSame( '2', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['stock_quantity'] );
			$this->assertSame( '5', (string) get_post_meta( 20000, '_digitalogic_patris_total_stock', true ) );
			if ( 'direct_db' === $mode ) {
				$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
			} else {
				$this->assertContains( 20000, $GLOBALS['digitalogic_test_wc_product_saves'] );
			}
		}
	}

	/** Raw metadata that should be absent must reject the entire delivery. */
	public function test_source_batch_rejects_undeleted_absent_metadata_and_rolls_back(): void {
		$payload = $this->seed_ingress_batch();
		$GLOBALS['digitalogic_test_options']['yuan_price']         = '29501';
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = '29501';
		$before = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$posts  = $GLOBALS['digitalogic_test_posts'];
		$events = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_applied'] ?? array() );
		$GLOBALS['digitalogic_test_before_pricing_batch_meta_readback'] = static function () {
			$GLOBALS['digitalogic_test_posts'][20000]['meta'][ Digitalogic_Patris_Price_Policy::WARNING_META ] = 'undeleted stale warning';
		};
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $payload );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, Digitalogic_Product_Sync_Receiver::instance()->get_state() );
		$this->assertSame( $posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $events, count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_applied'] ?? array() ) );
	}

	/** Stock/taxonomy writes are rolled back when their exact readback fails. */
	public function test_source_batch_stock_visibility_failure_rolls_back_without_receipt(): void {
		$payload                    = $this->seed_ingress_batch( 2 );
		$before                     = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$posts                      = $GLOBALS['digitalogic_test_posts'];
		$lookups                    = $GLOBALS['digitalogic_test_wc_lookup_rows'];
		$terms                      = $GLOBALS['digitalogic_test_object_terms'];
		$products                   = $payload['products'];
		$products[0]['total_stock'] = 0;
		unset( $products[0]['record_hash'] );
		$products[0]['record_hash'] = $this->record_hash( $products[0] );
		$events                     = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_applied'] ?? array() );
		$GLOBALS['digitalogic_test_pricing_batch_stock_readback_failure'] = true;
		try {
			$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( $products, '2026-07-22T00:00:00Z' ) );
		} finally {
			unset( $GLOBALS['digitalogic_test_pricing_batch_stock_readback_failure'] );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, Digitalogic_Product_Sync_Receiver::instance()->get_state() );
		$this->assertSame( $posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $lookups, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
		$this->assertSame( $terms, $GLOBALS['digitalogic_test_object_terms'] );
		$this->assertSame( $events, count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_applied'] ?? array() ) );
	}

	/** Batch failure restores source, prices and deferred events together. */
	public function test_full_source_batch_failure_rolls_back_without_receipt(): void {
		$payload  = $this->seed_ingress_batch();
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$before   = $receiver->get_state();
		$GLOBALS['digitalogic_test_options']['yuan_price']         = '29501';
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = '29501';
		$events = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_applied'] ?? array() );
		$GLOBALS['digitalogic_test_pricing_batch_lookup_readback_failure'] = true;
		try {
			$result = $receiver->receive( $payload );
		} finally {
			unset( $GLOBALS['digitalogic_test_pricing_batch_lookup_readback_failure'] );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $before, $receiver->get_state() );
		$this->assertSame( $events, count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_sync_applied'] ?? array() ) );
		for ( $id = 20000; $id < 20030; ++$id ) {
			$this->assertSame( '8437000', (string) get_post_meta( $id, '_price', true ) );
		}
	}

	/**
	 * Seed via the actual ingress writer so ownership and operational metadata are real.
	 *
	 * @param int|null $stock Optional source stock quantity.
	 */
	private function seed_ingress_batch( $stock = null ) {
		remove_all_filters( 'digitalogic_patris_auto_materialize_source_product' );
		$products = array();
		for ( $offset = 0; $offset < 30; ++$offset ) {
			$code = sprintf( 'INGRESS-%03d', $offset );
			$GLOBALS['digitalogic_test_posts'][ 20000 + $offset ] = array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $code,
				'meta'        => array(
					'_digitalogic_patris_product_code' => $code,
					'_sku'                             => $code,
					Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => 'air_express',
				),
			);
			$product = $this->priced_product( $code );
			if ( null !== $stock ) {
				$product['total_stock'] = $stock;
				unset( $product['record_hash'] );
				$product['record_hash'] = $this->record_hash( $product );
			}
			$products[] = $product;
		}
		$payload = $this->snapshot( $products, '2026-07-21T00:00:00Z' );
		$this->assert_success( Digitalogic_Product_Sync_Receiver::instance()->receive( $payload ) );
		return $payload;
	}

	/**
	 * A newer committed owner revision replaces prices without reviving old input.
	 */
	public function test_new_go_owner_delivery_supersedes_old_receipt(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$old      = $this->snapshot( array( $this->priced_product( 'PRICE-901' ), $this->priced_product( 'MISSING-902' ) ), '2026-07-21T00:00:00Z' );
		$this->assert_success( $receiver->receive( $old ) );
		$this->assertSame( 'pending', $receiver->get_delivery_receipt( 'pricing-tests', 'kala' )['status'] );
		$committed = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-22',
			),
			'owner-supersession-test'
		);
		$this->assert_success( $committed );
		$this->assertSame( 'awaiting_delivery', $committed['status'] );
		$stale = $receiver->receive( $old );
		$this->assertInstanceOf( WP_Error::class, $stale );
		$this->assertSame( 'digitalogic_pricing_owner_catalog_changed', $stale->get_error_code() );
		$product                            = $this->priced_product( 'PRICE-901' );
		$product['irt_per_cny']             = 31000;
		$product['currency_effective_date'] = '2026-07-22';
		$product['final_price']             = 8866000;
		unset( $product['record_hash'] );
		$product['record_hash']  = $this->record_hash( $product );
		$missing                 = $product;
		$missing['product_code'] = 'MISSING-902';
		unset( $missing['record_hash'] );
		$missing['record_hash'] = $this->record_hash( $missing );
		$this->assert_success( $receiver->receive( $this->snapshot( array( $product, $missing ), '2026-07-22T00:00:00Z' ) ) );
		$receipt = $receiver->get_delivery_receipt( 'pricing-tests', 'kala' );
		$this->assertSame( 'pending', $receipt['status'] );
		$this->assertSame( 1, $receipt['pending_products'] );
		$this->assertSame( $committed['pricing_results']['owner_catalog_revision'], $receipt['owner_catalog_revision'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$late = $receiver->receive( $old );
		$this->assert_success( $late );
		$this->assertSame( $receipt['owner_catalog_revision'], $late['delivery']['owner_catalog_revision'] );
		$this->assertSame( $receipt['event_id'], $late['delivery']['event_id'] );
		$this->assertNotSame( $old['event_id'], $late['delivery']['event_id'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Direct-only sources are unaffected; mixed sources still need the Go actuation receipt. */
	public function test_owner_dependent_sources_exclude_direct_only_but_include_mixed_inputs(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$catalog               = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		$direct                = array(
			'product_code'                   => 'PRICE-901',
			'sale_price_source'              => 1234500,
			'price_source_amount'            => 1234500,
			'price_source_currency'          => 'IRR',
			'price_source_kind'              => 'sale_price_direct',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => 0,
			'shipping_price_per_kg_currency' => 'IRR',
			'pricing_catalog_revision'       => $catalog['revision'],
			'final_price'                    => 123450,
			'warnings'                       => array(),
		);
		$direct['record_hash'] = $this->record_hash( $direct );
		$receiver              = Digitalogic_Product_Sync_Receiver::instance();
		$this->assert_success( $receiver->receive( $this->snapshot( array( $direct ), '2026-07-21T00:00:00Z', 'direct-only' ) ) );
		$this->assertSame( array( 'sources' => array() ), $receiver->get_owner_dependent_source_identities() );
		$this->assertCount( 1, $receiver->get_source_identities()['sources'] );
		$this->assertArrayNotHasKey( 'owner_catalog_revision', $receiver->get_delivery_receipt( 'direct-only', 'kala' ) );
		$priced = $this->priced_product( 'MIXED-902' );
		$this->assert_success( $receiver->receive( $this->snapshot( array( $direct, $priced ), '2026-07-21T00:00:00Z', 'mixed-source' ) ) );
		$affected = $receiver->get_owner_dependent_source_identities();
		$this->assertCount( 1, $affected['sources'] );
		$this->assertSame( 'mixed-source', array_values( $affected['sources'] )[0]['source']['id'] );
		$state = $receiver->get_state();
		unset( $state['sources'][ hash( 'sha256', "direct-only\nkala" ) ]['input_products'] );
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		$unknown = $receiver->get_owner_dependent_source_identities();
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'digitalogic_product_sync_input_baseline_required', $unknown->get_error_code() );
	}
	/** Initial delivery, recovery and replay each emit once after releasing the lock. */
	public function test_go_receipts_are_durable_and_emitted_once_outside_the_lock(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$product  = $this->priced_product( 'PRICE-901' );
		$payload  = $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' );
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertNull( $receiver->get_delivery_receipt( 'pricing-tests', 'kala' ) );
		$observed = array();
		add_action(
			'digitalogic_product_sync_applied',
			function ( $result, $metadata ) use ( &$observed, $product ) {
				$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
				$this->assertTrue( $result['persistence_verified'] );
				$this->assertSame( $product['pricing_catalog_revision'], $result['owner_catalog_revision'] );
				$this->assertSame( $result['owner_catalog_revision'], $metadata['owner_catalog_revision'] );
				$this->assertSame( $result['delivery'], $metadata['delivery'] );
				$observed[] = $result;
			},
			10,
			2
		);
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 901 );
		$this->assert_success( $receiver->receive( $payload ) );
		$this->assertSame( 'pending', $receiver->get_delivery_receipt( 'pricing-tests', 'kala' )['status'] );
		$GLOBALS['digitalogic_test_wc_save_failures'] = array();
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$this->assert_success( $receiver->receive( $payload ) );
		$this->assert_success( $receiver->receive( $payload ) );
		$this->assertSame( array( 'partially_applied', 'recovered', 'replayed' ), array_column( $observed, 'status' ) );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$receipt = Digitalogic_Product_Sync_Receiver::instance()->get_delivery_receipt( 'pricing-tests', 'kala' );
		$this->assertSame( $observed[2]['delivery'], $receipt );
		$this->assertSame( 'complete', $receipt['status'] );
		$this->assertSame( $payload['event_id'], $receipt['event_id'] );
		$this->assertSame( $payload['source'], $receipt['input_source'] );
		foreach ( array( 'pending_products', 'deferred_products', 'deferred_missing', 'deferred_ambiguous' ) as $field ) {
			$this->assertSame( 0, $receipt[ $field ] );
		}
		$this->assertSame( array( 'sources' => array( hash( 'sha256', "pricing-tests\nkala" ) => array( 'source' => $receipt['source'] ) ) ), Digitalogic_Product_Sync_Receiver::instance()->get_source_identities() );
	}

	/** Nested receivers cannot acquire an async listener's mutex under the outer source lock. */
	public function test_applied_receipt_waits_for_outer_lock_and_is_discarded_on_failure(): void {
		$product  = $this->priced_product( 'PRICE-901' );
		$payload  = $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' );
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$observed = array();
		add_action(
			'digitalogic_product_sync_applied',
			function ( $result ) use ( &$observed, $receiver ) {
				$this->assertFalse( $receiver->source_identity_lock_is_owned() );
				$observed[] = $result;
			}
		);
		$result = Digitalogic_Pricing_Service::instance()->with_source_delivery_lock(
			function () use ( $receiver, $payload, &$observed ) {
				$result = $receiver->receive( $payload );
				$this->assertSame( array(), $observed );
				return $result;
			}
		);
		$this->assert_success( $result );
		$this->assertCount( 1, $observed );
		$observed = array();
		$failed   = Digitalogic_Pricing_Service::instance()->with_source_delivery_lock(
			function () use ( $receiver, $payload, &$observed ) {
				$this->assert_success( $receiver->receive( $payload ) );
				$this->assertSame( array(), $observed );
				return new WP_Error( 'outer_transaction_failed', 'Injected rollback.' );
			}
		);
		$this->assertInstanceOf( WP_Error::class, $failed );
		$receiver->dispatch_materializer_product_committed();
		$this->assertSame( array(), $observed );
	}

	/** A post-commit listener failure is a warning, never a delivery rollback. */
	public function test_applied_listener_failure_preserves_verified_delivery_and_warning(): void {
		add_action(
			'digitalogic_product_sync_applied',
			static function () {
				throw new RuntimeException( 'Injected receipt listener failure.' );
			}
		);
		$product = $this->priced_product( 'PRICE-901' );
		$result  = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertTrue( $result['persistence_verified'] );
		$this->assertSame( 'digitalogic_product_sync_listener_failed', $result['delivery_warnings'][0]['code'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertArrayNotHasKey( 'owner_catalog_revision', $result );
		$this->assertArrayNotHasKey( 'owner_catalog_revision', $result['delivery'] );
	}

	/** Missing and ambiguous leaves both remain explicitly incomplete. */
	public function test_go_receipt_keeps_missing_and_ambiguous_delivery_incomplete(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$product = $this->priced_product( 'MISSING-902' );
		$result  = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( 'pending', $result['delivery']['status'] );
		$this->assertSame( 1, $result['delivery']['pending_products'] );
		$GLOBALS['digitalogic_test_posts'][902] = $GLOBALS['digitalogic_test_posts'][901];
		$product                                = $this->priced_product( 'PRICE-901' );
		$result                                 = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-22T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( 'deferred', $result['delivery']['status'] );
		$this->assertSame( 0, $result['delivery']['pending_products'] );
		$this->assertSame( 1, $result['delivery']['deferred_products'] );
		$this->assertSame( 0, $result['delivery']['deferred_missing'] );
		$this->assertSame( 1, $result['delivery']['deferred_ambiguous'] );
	}
	/** Direct callers cannot bypass the selected authority through the receiver. */
	public function test_receiver_local_reprice_cannot_bypass_go_authority(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$settings = Digitalogic_Pricing_Service::instance()->current_canonical_settings();
		$settings = array_intersect_key( $settings, array_flip( array( 'dollar_price', 'yuan_price', 'effective_date', 'profit_margin_percent', 'price_rounding_digits', 'price_rounding_mode' ) ) );
		$result   = Digitalogic_Product_Sync_Receiver::instance()->reprice_pricing_state( $settings );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_pricing_go_dispatch_required', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** A selected Go calculator must use the site's current owner inputs. */
	public function test_go_authority_rejects_stale_owner_catalog_before_any_write(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$product = $this->priced_product( 'PRICE-901' );
		$this->set_owner_rate( '31000' );
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_pricing_owner_catalog_changed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( '1', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Matching owner inputs permit Go's verified result without PHP repricing. */
	public function test_go_authority_accepts_current_owner_catalog(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$product = $this->priced_product( 'PRICE-901' );
		$result  = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** An old accepted event cannot replay after the owner inputs change. */
	public function test_go_replay_is_fenced_by_current_owner_catalog(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Pricing_Coordinator::AUTHORITY_OPTION ] = 'go';
		$product  = $this->priced_product( 'PRICE-901' );
		$payload  = $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' );
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assert_success( $receiver->receive( $payload ) );
		$this->set_owner_rate( '31000' );
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$result                                       = $receiver->receive( $payload );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_pricing_owner_catalog_changed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Owner reads stay constant for one bounded batch as its row count grows. */
	public function test_owner_projection_batches_identity_and_assignment_reads(): void {
		$prototype = $this->priced_product( 'PRICE-901' );
		$this->set_owner_rate( '31000' );
		$project = new ReflectionMethod( Digitalogic_Product_Sync_Receiver::class, 'project_authority_products' );
		$counts  = array();
		foreach ( array( 4, 8 ) as $count ) {
			$products = array();
			for ( $offset = 0; $offset < $count; ++$offset ) {
				$id                                       = 901 + $offset;
				$code                                     = 'PRICE-' . $id;
				$GLOBALS['digitalogic_test_posts'][ $id ] = $GLOBALS['digitalogic_test_posts'][901];
				$GLOBALS['digitalogic_test_posts'][ $id ]['meta']['_sku']                             = $code;
				$GLOBALS['digitalogic_test_posts'][ $id ]['meta']['_digitalogic_patris_product_code'] = $code;
				$product                 = $prototype;
				$product['product_code'] = $code;
				// Exercise raw-source bootstrap too: it must reuse the batch assignment.
				unset( $product['price_source_amount'], $product['price_source_currency'], $product['price_source_kind'], $product['final_price'], $product['record_hash'] );
				$product['record_hash'] = $this->record_hash( $product );
				$products[ $code ]      = $product;
			}
			Digitalogic_Product_Identifier_Resolver::instance()->clear_code_rows_cache();
			$GLOBALS['wpdb']->identifier_query_count = 0;
			$GLOBALS['wpdb']->option_read_counts     = array();
			$result                                  = $project->invoke( Digitalogic_Product_Sync_Receiver::instance(), $products, array( 'formula_id' => 'landed_price', 'input_mode' => 'patris_inputs' ), 'php' );
			$this->assert_success( $result );
			$this->assertCount( $count, $result );
			foreach ( $result as $product ) {
				$this->assertSame( '8866000', (string) $product['final_price'] );
			}
			$this->assertLessThanOrEqual( 2, $GLOBALS['wpdb']->identifier_query_count );
			$counts[] = array(
				'identities' => $GLOBALS['wpdb']->identifier_query_count,
				'margin'     => $GLOBALS['wpdb']->option_read_counts[ Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION ] ?? 0,
				'catalog'    => $GLOBALS['wpdb']->option_read_counts[ Digitalogic_Shipping_Method_Service::METHODS_OPTION ] ?? 0,
			);
		}
		$this->assertSame( $counts[0], $counts[1], 'Doubling one batch must not double owner catalog/default reads.' );
	}
	/** Only the owner-derived amount ever reaches the first Woo save. */
	public function test_unpriced_envelope_cannot_bypass_php_authority_with_a_final_price(): void {
		$product                = array(
			'product_code' => 'PRICE-901',
			'final_price'  => 12345,
			'warnings'     => array(),
		);
		$product['record_hash'] = $this->record_hash( $product );
		$payload                = $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' );
		unset( $payload['local_currency'], $payload['formula_id'] );
		$payload['products']  = array($product);
		$identity             = array_intersect_key( $payload, array_flip( array( 'schema', 'event_type', 'input_mode', 'source', 'generated_at', 'products', 'categories', 'excluded_codes', 'quarantined_codes' ) ) );
		$identity['products'] = array( $product['product_code'] . '=' . $product['record_hash'] );
		$payload['event_id']  = 'sha256:' . hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$result               = Digitalogic_Product_Sync_Receiver::instance()->receive( $payload );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_sync_owner_fields_forbidden', $result->get_error_code() );
		$this->assertSame( '1', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Only the owner-derived amount ever reaches the first Woo save. */
	public function test_php_authority_reprices_before_the_first_woo_write(): void {
		$product = $this->priced_product( 'PRICE-901' );
		$this->set_owner_rate( '31000' );
		$observed                                  = array();
		$GLOBALS['digitalogic_test_wc_after_save'] = static function ( $woo ) use ( &$observed ) {
			$observed[] = $woo->get_regular_price();
		};
		$result                                    = Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) );
		$this->assert_success( $result );
		$this->assertSame( array( '8866000' ), $observed );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_saves'] );
		$state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( 'pricing-tests', 'kala' );
		$this->assertArrayNotHasKey('final_price', $state['input_products']['PRICE-901']);
		$this->assertSame( '8866000', (string) $state['products']['PRICE-901']['final_price'] );
		$this->assertNotSame( $state['input_source']['revision'], $state['source']['revision'] );
	}

	/** Unchanged upstream rows must still validate after owner repricing. */
	public function test_materializer_metadata_saves_never_expose_the_upstream_price(): void {
		add_filter( 'digitalogic_patris_auto_materialize_source_product', static fn() => true, 20 );
		$product = $this->priced_product( 'PRICE-901' );
		$this->set_owner_rate( '31000' );
		$observed                                  = array();
		$observer                                  = static function ( $woo ) use ( &$observed, &$observer ) {
			$observed[]                                = $woo->get_regular_price();
			$GLOBALS['digitalogic_test_wc_after_save'] = $observer;
		};
		$GLOBALS['digitalogic_test_wc_after_save'] = $observer;
		try {
			$this->assert_success( Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) ) );
		} finally {
			$GLOBALS['digitalogic_test_wc_after_save'] = null;
		}
		$this->assertNotEmpty( $observed );
		$this->assertCount( count( $GLOBALS['digitalogic_test_wc_product_saves'] ), $observed );
		$this->assertSame( array( '8866000' ), array_values( array_unique( $observed ) ) );
	}

	/** Unchanged upstream rows must still validate after owner repricing. */
	public function test_local_reprice_then_go_delta_keeps_the_input_baseline(): void {
		$first                                  = $this->priced_product( 'PRICE-901' );
		$second                                 = $this->priced_product( 'PRICE-902' );
		$GLOBALS['digitalogic_test_posts'][902] = $GLOBALS['digitalogic_test_posts'][901];
		$GLOBALS['digitalogic_test_posts'][902]['meta']['_sku']                             = 'PRICE-902';
		$GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_product_code'] = 'PRICE-902';
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assert_success( $receiver->receive( $this->snapshot( array( $first, $second ), '2026-07-21T00:00:00Z' ) ) );
		$this->assert_success(
			Digitalogic_Pricing_Coordinator::instance()->update_currency(
				array(
					'yuan_price'     => '31000',
					'effective_date' => '2026-07-22',
				),
				'authority-test'
			)
		);
		$second['name'] = 'Changed upstream name';
		unset( $second['record_hash'] );
		$second['record_hash']                        = $this->record_hash( $second );
		$delta                                        = $this->delta( array( $second ), array( $first, $second ), '2026-07-23T00:00:00Z' );
		$observed                                     = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$GLOBALS['digitalogic_test_wc_after_save']    = static function ( $woo ) use ( &$observed ) {
			$observed[] = $woo->get_regular_price();
		};
		$this->assert_success( $receiver->receive( $delta ) );
		$this->assertSame( array( '8866000' ), $observed );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_saves'] );
		$state = $receiver->get_source_state( 'pricing-tests', 'kala' );
		$this->assertSame( $this->pure_input($first)['record_hash'], $state['input_products']['PRICE-901']['record_hash'] );
		$this->assertSame( $delta['source'], $state['input_source'] );
		$this->assertSame( '8866000', (string) $state['products']['PRICE-902']['final_price'] );
	}

	/** Existing state has one explicit full-snapshot cutover, never a delta alias. */
	public function test_old_combined_state_requires_a_complete_snapshot(): void {
		$product  = $this->priced_product( 'PRICE-901' );
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assert_success( $receiver->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) ) );
		$state = $receiver->get_state();
		foreach ( $state['sources'] as &$source ) {
			unset( $source['input_products'], $source['input_source'] );
		}
		unset( $source );
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		$result = $receiver->receive( $this->delta( array( $product ), array( $product ), '2026-07-22T00:00:00Z' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_sync_input_baseline_required', $result->get_error_code() );
		$this->assert_success( $receiver->receive( $this->snapshot( array( $product ), '2026-07-22T00:00:00Z' ) ) );
		$this->assertArrayHasKey( 'input_products', $receiver->get_source_state( 'pricing-tests', 'kala' ) );
	}

	/** Retried delivery resolves the latest owner inputs, not the pending amount. */
	public function test_replay_reprices_pending_delivery_before_retry(): void {
		$product                                      = $this->priced_product( 'PRICE-901' );
		$payload                                      = $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' );
		$receiver                                     = Digitalogic_Product_Sync_Receiver::instance();
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 901 );
		$first                                        = $receiver->receive( $payload );
		$this->assert_success( $first );
		$this->assertSame( 1, $first['pending_products'] );
		$GLOBALS['digitalogic_test_wc_save_failures'] = array();
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$this->set_owner_rate( '31000' );
		$this->assert_success( $receiver->receive( $payload ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** The product's owner-selected route overrides stale upstream selection. */
	public function test_php_projection_uses_the_existing_owner_shipping_assignment(): void {
		$product = $this->priced_product( 'PRICE-901' );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['freight']      = array(
			'id'           => 'freight',
			'name'         => 'Freight',
			'enabled'      => true,
			'currency'     => 'CNY',
			'price_per_kg' => '200',
		);
		$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] = 'freight';
		$GLOBALS['digitalogic_test_option_cache'] = array();
		$this->assert_success( Digitalogic_Product_Sync_Receiver::instance()->receive( $this->snapshot( array( $product ), '2026-07-21T00:00:00Z' ) ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( '11505000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( 'pricing-tests', 'kala' );
		$this->assertArrayNotHasKey('shipping_method_id', $state['input_products']['PRICE-901']);
		$this->assertSame( 'freight', $state['products']['PRICE-901']['shipping_method_id'] );
	}

	/**
	 * Assert a successful receiver result.
	 *
	 * @param mixed $result Operation result.
	 */
	private function assert_success( $result ): void {
		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' );
	}

	/**
	 * Seed a different persisted owner rate before ingest.
	 *
	 * @param string $rate CNY rate.
	 */
	private function set_owner_rate( $rate ): void {
		$GLOBALS['digitalogic_test_options']['yuan_price']         = $rate;
		$GLOBALS['digitalogic_test_options']['options_yuan_price'] = $rate;
		$GLOBALS['digitalogic_test_option_cache']                  = array();
	}

	/**
	 * Build a delta whose revision covers unchanged upstream records too.
	 *
	 * @param array  $changed Changed records.
	 * @param array  $all Complete upstream snapshot.
	 * @param string $time Event timestamp.
	 * @return array
	 */
	private function delta( $changed, $all, $time ): array {
		$payload = $this->snapshot( $all, $time );
		if ($payload['input_mode'] === 'patris_inputs') { $changed = array_map(array($this, 'pure_input'), $changed); }
		$payload['event_type'] = 'update';
		$payload['products']   = $changed;
		$identity              = array_intersect_key( $payload, array_flip( array( 'schema', 'event_type', 'input_mode', 'local_currency', 'formula_id', 'source', 'generated_at', 'products', 'categories', 'excluded_codes', 'quarantined_codes' ) ) );
		$identity['products']  = array_map( static fn( $product ) => $product['product_code'] . '=' . $product['record_hash'], $changed );
		sort( $identity['products'], SORT_STRING );
		$payload['event_id'] = 'sha256:' . hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		return $payload;
	}
	/** Seed canonical owner settings and one exact managed WooCommerce leaf. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_capabilities']                   = array();
		$GLOBALS['digitalogic_test_filters']                        = array();
		$GLOBALS['digitalogic_test_routes']                         = array();
		$GLOBALS['digitalogic_test_option_cache']                   = array();
		$GLOBALS['digitalogic_test_transients']                     = array();
		$GLOBALS['digitalogic_test_transient_deletes']              = array();
		$GLOBALS['digitalogic_test_actions']                        = array();
		$GLOBALS['digitalogic_test_action_callbacks']               = array();
		$GLOBALS['digitalogic_test_scheduled_events']               = array();
		$GLOBALS['digitalogic_test_schedule_failure']               = false;
		$GLOBALS['digitalogic_test_update_failures']                = array();
		$GLOBALS['digitalogic_test_transaction_failures']           = array();
		$GLOBALS['digitalogic_test_cache_deletes']                  = array();
		$GLOBALS['digitalogic_test_cache_delete_multiple']          = array();
		$GLOBALS['digitalogic_test_cache_invalidation_suspended']   = false;
		$GLOBALS['digitalogic_test_cache_invalidation_history']     = array();
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations']   = array();
		$GLOBALS['digitalogic_test_object_term_cache_cleans']       = array();
		$GLOBALS['digitalogic_test_post_meta_cache']                = array();
		$GLOBALS['digitalogic_test_meta_update_failures']           = array();
		$GLOBALS['digitalogic_test_meta_delete_failures']           = array();
		$GLOBALS['digitalogic_test_wc_products']                    = array();
		$GLOBALS['digitalogic_test_wc_product_saves']               = array();
		$GLOBALS['digitalogic_test_wc_transient_deletes']           = array();
		$GLOBALS['digitalogic_test_wc_save_failures']               = array();
		$GLOBALS['digitalogic_test_wc_save_fail_once']              = array();
		$GLOBALS['digitalogic_test_wc_enqueue_parent_sync_on_save'] = false;
		unset( $GLOBALS['wc_deferred_product_sync'] );
		$GLOBALS['digitalogic_test_wc_after_save'] = null;
		$GLOBALS['digitalogic_test_wc_currency']   = 'IRT';
		$GLOBALS['digitalogic_test_terms']         = array(
			990001 => array(
				'term_id'  => 990001,
				'taxonomy' => 'product_visibility',
				'slug'     => 'outofstock',
				'name'     => 'Out of stock',
			),
		);
		$GLOBALS['digitalogic_test_term_meta']     = array();
		$GLOBALS['digitalogic_test_object_terms']  = array();
		unset( $GLOBALS['digitalogic_test_pricing_batch_lookup_readback_failure'] );
		unset( $GLOBALS['digitalogic_test_pricing_batch_parent_meta_readback_failure'] );
		unset( $GLOBALS['digitalogic_test_before_pricing_batch_parent_inputs'] );
		unset( $GLOBALS['digitalogic_test_before_pricing_batch_leaf_identity'] );
		array_splice( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'], 0 );
		array_splice( $GLOBALS['digitalogic_test_wc_product_instance_cache_failure_ids'], 0 );
		array_splice( $GLOBALS['digitalogic_test_pricing_phase_events'], 0 );
		$GLOBALS['digitalogic_test_remote_posts']        = array();
		$GLOBALS['digitalogic_test_remote_post_results'] = array();
		$GLOBALS['digitalogic_test_spawn_cron_calls']    = array();
		$GLOBALS['digitalogic_test_current_user_id']     = 0;
		WP_CLI::$errors                                  = array();
		WP_CLI::$logs                                    = array();
		WP_CLI::$warnings                                = array();
		$GLOBALS['digitalogic_test_posts']               = array(
			901 => array(
				'post_type'    => 'product',
				'post_status'  => 'publish',
				'post_title'   => 'Atomic pricing product',
				'product_type' => 'simple',
				'meta'         => array(
					'_digitalogic_patris_product_code' => 'PRICE-901',
					Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => 'air_express',
					'_sku'                             => 'PRICE-901',
					'_regular_price'                   => '1',
					'_price'                           => '1',
					'_sale_price'                      => '',
				),
			),
		);
		$GLOBALS['digitalogic_test_options']             = array(
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION => 'receiver-secret',
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION => array(
				array(
					'id'      => 'pricing-tests',
					'dataset' => 'kala',
				),
			),
			'dollar_price'            => '187891',
			'options_dollar_price'    => '187891',
			'yuan_price'              => '29500',
			'options_yuan_price'      => '29500',
			'update_date'             => '260721',
			'options_update_date'     => '260721',
			'woocommerce_weight_unit' => 'kg',
			'digitalogic_shipping_currency_migration_complete' => 'complete',
			Digitalogic_Shipping_Method_Service::METHODS_OPTION => array(
				'air_express' => array(
					'id'           => 'air_express',
					'name'         => 'Air (Express)',
					'enabled'      => true,
					'currency'     => 'CNY',
					'price_per_kg' => '120',
				),
			),
			Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION => $this->default_markup_state( '30' ),
			Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION => 0,
		);
		$GLOBALS['wpdb']                                 = new Digitalogic_Test_WPDB();
		$_POST = array();
		unset( $GLOBALS['digitalogic_test_cache_delete_multiple_callback'] );

		foreach (
			array(
				Digitalogic_Product_Identifier_Resolver::class,
				Digitalogic_Product_Manager::class,
				Digitalogic_Product_Write_Lock::class,
				Digitalogic_Patris_Price_Write_Guard::class,
				Digitalogic_Product_Sync_Receiver::class,
				Digitalogic_Shipping_Method_Service::class,
				Digitalogic_Google_Sheets_Catalog::class,
				Digitalogic_Google_Sheets_Writeback::class,
				Digitalogic_Pricing_Service::class,
				Digitalogic_Pricing_Coordinator::class,
				Digitalogic_Currency_Admin_Async::class,
				Digitalogic_Pricing_Snapshot::class,
				Digitalogic_Report_Engine::class,
				Digitalogic_Logger::class,
				Digitalogic_Webhooks::class,
			) as $class_name
		) {
			$this->reset_singleton( $class_name );
		}
		// Existing managed leaf: exercise the sole canonical price save without
		// the separate optional catalog ownership-metadata save.
		add_filter( 'digitalogic_patris_auto_materialize_source_product', static fn() => false );
	}

	/**
	 * Build a canonical upstream product at the original owner rate.
	 *
	 * @param string $product_code Exact Product Code.
	 * @return array
	 */
	private function priced_product( $product_code ) {
		$catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		$this->assertFalse( is_wp_error( $catalog ) );
		$product                = array(
			'product_code'                   => $product_code,
			'foreign_currency'               => 'CNY',
			'foreign_price'                  => 100,
			'price_source_amount'            => 100,
			'price_source_currency'          => 'CNY',
			'price_source_kind'              => 'foreign_price',
			'weight_grams'                   => 1000,
			'shipping_method_id'             => 'air_express',
			'shipping_price_per_kg'          => 120,
			'shipping_price_per_kg_currency' => 'CNY',
			'markup_percent'                 => 30,
			'irt_per_cny'                    => 29500,
			'price_rounding_digits'          => 0,
			'price_rounding_mode'            => 'nearest_half_up',
			'pricing_catalog_revision'       => $catalog['revision'],
			'pricing_catalog_status'         => 'ready',
			'currency_effective_date'        => '2026-07-21',
			'final_price'                    => 8437000,
			'warnings'                       => array(),
		);
		$product['record_hash'] = $this->record_hash( $product );

		return $product;
	}

	/**
	 * Build one canonical product-sync snapshot.
	 *
	 * @param array  $products     Products.
	 * @param string $generated_at Event time.
	 * @param string $source_id    Source identity.
	 * @return array
	 */
	private function snapshot( $products, $generated_at, $source_id = 'pricing-tests' ) {
		$input_mode = 'php' === Digitalogic_Pricing_Coordinator::instance()->pricing_authority() ? 'patris_inputs' : 'go_projection';
		if ('patris_inputs' === $input_mode) {
			$products = array_map(array($this, 'pure_input'), $products);
		}
		$material = array();
		foreach ( $products as $product ) {
			$material[] = $product['product_code'] . '=' . $product['record_hash'];
		}
		sort( $material, SORT_STRING );
		$source   = array(
			'id'       => $source_id,
			'dataset'  => 'kala',
			'revision' => 'sha256:' . hash( 'sha256', implode( "\n", $material ) ),
		);
		$identity = array(
			'schema'            => 'patris.product-sync',
			'event_type'        => 'snapshot',
			'input_mode'        => $input_mode,
			'local_currency'    => 'IRT',
			'formula_id'        => 'landed_price',
			'source'            => $source,
			'generated_at'      => $generated_at,
			'products'          => array_map(
				static fn( $product ) => $product['product_code'] . '=' . $product['record_hash'],
				$products
			),
			'categories'        => array(),
			'excluded_codes'    => array(),
			'quarantined_codes' => array(),
		);
		sort( $identity['products'], SORT_STRING );

		return array(
			'schema'            => 'patris.product-sync',
			'event_type'        => 'snapshot',
			'input_mode'        => $input_mode,
			'event_id'          => 'sha256:' . hash(
				'sha256',
				wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			),
			'local_currency'    => 'IRT',
			'formula_id'        => 'landed_price',
			'source'            => $source,
			'generated_at'      => $generated_at,
			'products'          => $products,
			'categories'        => array(),
			'excluded_codes'    => array(),
			'quarantined_codes' => array(),
			'warnings'          => array(),
		);
	}

	private function pure_input($product) {
		foreach (array('shipping_method_id', 'shipping_price_per_kg', 'shipping_price_per_kg_currency', 'markup_percent', 'irt_per_cny', 'pricing_catalog_revision', 'pricing_catalog_status', 'currency_effective_date', 'price_source_amount', 'price_source_currency', 'price_source_kind', 'price_rounding_digits', 'price_rounding_mode', 'final_price', 'record_hash') as $field) {
			unset($product[$field]);
		}
		$product['record_hash'] = $this->record_hash($product);
		return $product;
	}

	/**
	 * Build the Go-compatible record hash for this numeric fixture.
	 *
	 * @param array $record Product.
	 * @return string
	 */
	private function record_hash( $record ) {
		ksort( $record, SORT_STRING );

		return 'sha256:' . hash(
			'sha256',
			wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Build a valid installed default-markup record.
	 *
	 * @param string $profit Exact percentage.
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
				'updated_at' => '2026-07-21 00:00:00',
				'updated_by' => 0,
			)
		);
	}

	/**
	 * Reset a singleton.
	 *
	 * @param string $class_name Class name.
	 * @return void
	 */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
