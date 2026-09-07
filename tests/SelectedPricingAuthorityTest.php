<?php
/**
 * Selected pricing authority integration regressions.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Exercise authority selection through real receiver and coordinator methods. */
final class SelectedPricingAuthorityTest extends TestCase {
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
		$identity             = array_intersect_key( $payload, array_flip( array( 'schema', 'event_type', 'source', 'generated_at', 'products', 'categories', 'excluded_codes', 'quarantined_codes' ) ) );
		$identity['products'] = array( $product['product_code'] . '=' . $product['record_hash'] );
		$payload['event_id']  = 'sha256:' . hash( 'sha256', wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$result               = Digitalogic_Product_Sync_Receiver::instance()->receive( $payload );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_sync_pricing_context_missing', $result->get_error_code() );
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
		$this->assertSame( '8437000', (string) $state['input_products']['PRICE-901']['final_price'] );
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
		$this->assertSame( $first['record_hash'], $state['input_products']['PRICE-901']['record_hash'] );
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
		$this->assertSame( 'air_express', $state['input_products']['PRICE-901']['shipping_method_id'] );
		$this->assertSame( 'freight', $state['products']['PRICE-901']['shipping_method_id'] );
	}

	/**
	 * Assert a successful receiver result.
	 *
	 * @param mixed $result Operation result.
	 */
	private function assert_success( $result ): void {
		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
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
		$payload               = $this->snapshot( $all, $time );
		$payload['event_type'] = 'update';
		$payload['products']   = $changed;
		$identity              = array_intersect_key( $payload, array_flip( array( 'schema', 'event_type', 'local_currency', 'formula_id', 'source', 'generated_at', 'products', 'categories', 'excluded_codes', 'quarantined_codes' ) ) );
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
		$GLOBALS['digitalogic_test_terms']         = array();
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
