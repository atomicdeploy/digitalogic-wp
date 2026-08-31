<?php
/**
 * Atomic pricing-coordinator tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies exact repricing, rollback, terminal-missing handling, and write guards.
 */
final class PricingCoordinatorTest extends TestCase {

	/**
	 * Prepare one fully Patris-managed WooCommerce product.
	 */
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
				Digitalogic_Excel_Pricing_Sync::class,
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

		$this->seed_snapshot();
	}

	/** A CNY change commits settings and the exact landed price together. */
	public function test_currency_change_reprices_and_reads_back_in_one_transaction(): void {
		$released_before_commit               = false;
		$before_receiver_state                = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$before_source                        = reset( $before_receiver_state['sources'] );
		$GLOBALS['wpdb']->before_release_lock = static function ( $database ) use ( &$released_before_commit ) {
			$released_before_commit = ! in_array( 'COMMIT', $database->queries, true );
		};
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_weight']       = '9';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_manage_stock'] = 'yes';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_stock']        = 7;
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_stock_status'] = 'instock';
		$GLOBALS['digitalogic_test_wc_products']                         = array();
		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_currency'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '31000', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_final_price'] );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_irt_per_cny'] );
		$this->assertSame( '2026-07-27', $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_currency_effective_date'] );
		$this->assertSame( '9', $GLOBALS['digitalogic_test_posts'][901]['meta']['_weight'] );
		$this->assertSame( 'yes', $GLOBALS['digitalogic_test_posts'][901]['meta']['_manage_stock'] );
		$this->assertSame( 7, $GLOBALS['digitalogic_test_posts'][901]['meta']['_stock'] );
		$this->assertSame( 1, $result['pricing_results']['updated_products'] );
		$this->assertSame( 0, $result['pricing_results']['pending_products'] );
		$state        = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$source_after = reset( $state['sources'] );
		$stored       = $source_after['products']['PRICE-901'];
		$this->assertSame( $before_source['generated_at'], $source_after['generated_at'] );
		$this->assertSame( $before_source['generated_at_order'], $source_after['generated_at_order'] );
		$this->assertSame( $before_source['last_event_id'], $source_after['last_event_id'] );
		$wire    = $stored;
		$numeric = array(
			'foreign_price',
			'price_source_amount',
			'weight_grams',
			'shipping_price_per_kg',
			'markup_percent',
			'irt_per_cny',
			'final_price',
		);
		foreach ( $numeric as $field ) {
			$wire[ $field ] = (int) $wire[ $field ];
		}
		unset( $wire['record_hash'] );
		$this->assertSame( $this->record_hash( $wire ), $stored['record_hash'] );
		$catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		$this->assertFalse( is_wp_error( $catalog ) );
		$this->assertSame( $catalog['revision'], $stored['pricing_catalog_revision'] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertFalse( $released_before_commit, 'Product-sync lock was released before the pricing transaction committed.' );
	}

	/** Raw update_option rate aliases are coordinated or rejected atomically. */
	public function test_legacy_rate_option_write_cannot_bypass_repricing(): void {
		Digitalogic_Pricing_Coordinator::instance();

		$updated = update_option( 'yuan_price', '31000' );

		$this->assertTrue( $updated );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['yuan_price'] );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );

		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 901 );
		$rejected                                     = update_option( 'options_yuan_price', '32000' );

		$this->assertFalse( $rejected );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['yuan_price'] );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_legacy_write_rejected'] );
	}

	/** The public legacy markup service delegates to shared-margin repricing. */
	public function test_legacy_markup_service_cannot_bypass_repricing(): void {
		$result = Digitalogic_Shipping_Method_Service::instance()->update_default_percentage_markup( '40' );

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertTrue( $result['changed'] );
		$this->assertSame( '40', $result['profit_percent'] );
		$this->assertSame( '9086000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '9086000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_final_price'] );
	}

	/** The public rounding service delegates to the shared atomic repricer. */
	public function test_rounding_service_updates_policy_and_product_provenance_atomically(): void {
		$result = Digitalogic_Shipping_Method_Service::instance()->update_price_rounding_digits( 2 );

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 2, $result['rounding_digits'] );
		$this->assertSame(
			'2',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION ]
		);
		$this->assertSame(
			'2',
			(string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_price_rounding_digits']
		);
		$this->assertSame(
			Digitalogic_Shipping_Method_Service::ROUNDING_MODE,
			$GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_price_rounding_mode']
		);
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( array( true, false ), $GLOBALS['digitalogic_test_cache_invalidation_history'] );
		$this->assertFalse( $GLOBALS['digitalogic_test_cache_invalidation_suspended'] );
		$this->assertContains( 'product_901', $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertContains( array( 901, 'product' ), $GLOBALS['digitalogic_test_object_term_cache_cleans'] );
	}

	/** Partner IRR repricing applies shared markup and rounds 123456 up to 123500. */
	public function test_partner_route_reprices_with_domestic_zero_shipping_and_half_up_boundary(): void {
		$GLOBALS['digitalogic_test_posts'][902] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Partner pricing product',
			'product_type' => 'simple',
			'meta'         => array(
				'_digitalogic_patris_product_code' => 'PARTNER-902',
				'_sku'                             => 'PARTNER-902',
				'_regular_price'                   => '1',
				'_price'                           => '1',
				'_sale_price'                      => '',
				Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => 'domestic',
			),
		);
		$catalog                                = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		$this->assertFalse( is_wp_error( $catalog ) );
		$partner                = array(
			'product_code'                   => 'PARTNER-902',
			'partner_price_source'           => 1234560,
			'price_source_amount'            => 1234560,
			'price_source_currency'          => 'IRR',
			'price_source_kind'              => 'partner_price',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => 0,
			'shipping_price_per_kg_currency' => 'IRR',
			'markup_percent'                 => 30,
			'price_rounding_digits'          => 0,
			'price_rounding_mode'            => 'nearest_half_up',
			'pricing_catalog_revision'       => $catalog['revision'],
			'pricing_catalog_status'         => 'ready',
			'final_price'                    => 160493,
			'warnings'                       => array(),
		);
		$partner['record_hash'] = $this->record_hash( $partner );
		$received               = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( array( $partner ), '2026-07-22T01:00:00Z' )
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received )
				? $received->get_error_code() . ': ' . $received->get_error_message() . ' ' . wp_json_encode( $received->get_error_data() )
				: ''
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$service                           = Digitalogic_Excel_Pricing_Sync::instance();
		$before                            = $service->current_canonical_state();
		$settings                          = $before['settings'];
		$settings['profit_margin_percent'] = 0;
		$settings['price_rounding_digits'] = 2;
		$applied                           = $service->apply_internal_settings(
			$settings,
			'test_partner_route',
			$before['state_revision']
		);

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( '123500', (string) $GLOBALS['digitalogic_test_posts'][902]['meta']['_regular_price'] );
		$this->assertSame( '123500', (string) $GLOBALS['digitalogic_test_posts'][902]['meta']['_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][902]['meta']['_sale_price'] );
		$this->assertSame( 'partner_price', $GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_price_source_kind'] );
		$this->assertSame( '1234560', (string) $GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_price_source_amount'] );
		$this->assertSame( 'domestic', $GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_shipping_method_id'] );
		$this->assertSame( '0', (string) $GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_shipping_price_per_kg'] );
		$this->assertSame( 'IRR', $GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_shipping_price_per_kg_currency'] );
		$this->assertSame( 'domestic', $GLOBALS['digitalogic_test_posts'][902]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] );
		$this->assertSame( '2', (string) $GLOBALS['digitalogic_test_posts'][902]['meta']['_digitalogic_patris_price_rounding_digits'] );

		$state  = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$stored = reset( $state['sources'] )['products']['PARTNER-902'];
		$this->assertSame( '123500', (string) $stored['final_price'] );
		$this->assertSame( '0', (string) $stored['markup_percent'] );
		$this->assertSame( 2, $stored['price_rounding_digits'] );
		$this->assertArrayNotHasKey( 'irt_per_cny', $stored );
		$this->assertArrayNotHasKey( 'currency_effective_date', $stored );
	}

	/** Direct sale pricing remains exact IRR/10 across every unrelated global change. */
	public function test_direct_sale_route_is_immune_to_fx_markup_rounding_and_shipping_changes(): void {
		$GLOBALS['digitalogic_test_posts'][903] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Direct sale pricing product',
			'product_type' => 'simple',
			'meta'         => array(
				'_digitalogic_patris_product_code' => 'DIRECT-903',
				'_sku'                             => 'DIRECT-903',
				'_regular_price'                   => '1',
				'_price'                           => '1',
				'_sale_price'                      => '',
				Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => 'domestic',
			),
		);
		$direct                                 = array(
			'product_code'                   => 'DIRECT-903',
			'sale_price_source'              => 1234560,
			'price_source_amount'            => 1234560,
			'price_source_currency'          => 'IRR',
			'price_source_kind'              => 'sale_price_direct',
			'shipping_method_id'             => 'domestic',
			'shipping_price_per_kg'          => 0,
			'shipping_price_per_kg_currency' => 'IRR',
			'final_price'                    => 123456,
			'warnings'                       => array(
				'freight_not_applied_for_sale_price_direct',
				'sale_price_direct_fallback_used',
			),
		);
		$direct['record_hash']                  = $this->record_hash( $direct );
		$received                               = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( array( $direct ), '2026-07-22T02:00:00Z' )
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$service                              = Digitalogic_Excel_Pricing_Sync::instance();
		$before                               = $service->current_canonical_state();
		$settings                             = $before['settings'];
		$settings['dollar_price']             = 190000;
		$settings['yuan_price']               = 31000;
		$settings['profit_margin_percent']    = 40;
		$settings['price_rounding_digits']    = 3;
		$settings['air_express_price_per_kg'] = 130;
		$settings['effective_date']           = '2026-07-27';
		$settings['usd_effective_date']       = '2026-07-27';
		$settings['cny_effective_date']       = '2026-07-27';
		$applied                              = $service->apply_internal_settings(
			$settings,
			'test_direct_route',
			$before['state_revision']
		);

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( '123456', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_regular_price'] );
		$this->assertSame( '123456', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_digitalogic_patris_final_price'] );
		$this->assertSame( 'sale_price_direct', $GLOBALS['digitalogic_test_posts'][903]['meta']['_digitalogic_patris_price_source_kind'] );
		$this->assertSame( 'domestic', $GLOBALS['digitalogic_test_posts'][903]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] );
		$this->assertArrayNotHasKey( '_digitalogic_patris_markup_percent', $GLOBALS['digitalogic_test_posts'][903]['meta'] );
		$this->assertArrayNotHasKey( '_digitalogic_patris_irt_per_cny', $GLOBALS['digitalogic_test_posts'][903]['meta'] );
		$this->assertArrayNotHasKey( '_digitalogic_patris_price_rounding_digits', $GLOBALS['digitalogic_test_posts'][903]['meta'] );

		$state  = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$stored = reset( $state['sources'] )['products']['DIRECT-903'];
		$this->assertSame( 123456, $stored['final_price'] );
		$this->assertArrayNotHasKey( 'markup_percent', $stored );
		$this->assertArrayNotHasKey( 'irt_per_cny', $stored );
		$this->assertArrayNotHasKey( 'price_rounding_digits', $stored );
	}

	/** A row without an upstream-selected source remains unpriced after reconciliation. */
	public function test_no_source_route_does_not_invent_price_or_provenance(): void {
		$GLOBALS['digitalogic_test_posts'][904] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'No source pricing product',
			'product_type' => 'simple',
			'meta'         => array(
				'_digitalogic_patris_product_code' => 'NO-SOURCE-904',
				'_sku'                             => 'NO-SOURCE-904',
				'_regular_price'                   => '',
				'_price'                           => '',
				'_sale_price'                      => '',
			),
		);
		$unpriced                               = array(
			'product_code'          => 'NO-SOURCE-904',
			'foreign_currency'      => 'CNY',
			'foreign_price'         => 0,
			'price_rounding_digits' => 0,
			'price_rounding_mode'   => 'nearest_half_up',
			'warnings'              => array(),
		);
		$unpriced['record_hash']                = $this->record_hash( $unpriced );
		$received                               = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( array( $unpriced ), '2026-07-22T03:00:00Z' )
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$service                           = Digitalogic_Excel_Pricing_Sync::instance();
		$before                            = $service->current_canonical_state();
		$settings                          = $before['settings'];
		$settings['yuan_price']            = 31000;
		$settings['profit_margin_percent'] = 0;
		$settings['price_rounding_digits'] = 2;
		$settings['effective_date']        = '2026-07-27';
		$settings['cny_effective_date']    = '2026-07-27';
		$applied                           = $service->apply_internal_settings(
			$settings,
			'test_no_source_route',
			$before['state_revision']
		);

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][904]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][904]['meta']['_price'] );
		$this->assertArrayNotHasKey( '_digitalogic_patris_final_price', $GLOBALS['digitalogic_test_posts'][904]['meta'] );

		$state  = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$stored = reset( $state['sources'] )['products']['NO-SOURCE-904'];
		$this->assertArrayNotHasKey( 'price_source_amount', $stored );
		$this->assertArrayNotHasKey( 'price_source_currency', $stored );
		$this->assertArrayNotHasKey( 'price_source_kind', $stored );
		$this->assertArrayNotHasKey( 'final_price', $stored );
		$this->assertArrayNotHasKey( 'markup_percent', $stored );
		$this->assertArrayNotHasKey( 'irt_per_cny', $stored );
	}

	/** A stale or product-specific incoming margin is rejected before writes. */
	public function test_product_sync_cannot_introduce_profit_margin_drift(): void {
		$before_state              = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$before_meta               = $GLOBALS['digitalogic_test_posts'][901]['meta'];
		$product                   = $this->priced_product( 'PRICE-901' );
		$product['markup_percent'] = 40;
		$product['final_price']    = 9086000;
		unset( $product['record_hash'] );
		$product['record_hash'] = $this->record_hash( $product );

		$result = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( array( $product ), '2026-07-22T00:00:00Z' )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_product_sync_profit_margin_mismatch', $result->get_error_code() );
		$this->assertSame( '30', $result->get_error_data()['profit_margin_percent'] );
		$this->assertSame( array( 'PRICE-901' ), $result->get_error_data()['product_codes'] );
		$this->assertSame(
			$before_state,
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]
		);
		$this->assertSame( $before_meta, $GLOBALS['digitalogic_test_posts'][901]['meta'] );
	}

	/** Google/Excel share one revisioned settings read and optimistic write contract. */
	public function test_revisioned_global_settings_contract_rejects_stale_google_write(): void {
		$service = Digitalogic_Excel_Pricing_Sync::instance();
		$state   = $service->current_canonical_state();

		$this->assertFalse(
			is_wp_error( $state ),
			is_wp_error( $state ) ? $state->get_error_code() . ': ' . $state->get_error_message() : ''
		);
		$this->assertSame( Digitalogic_Excel_Pricing_Sync::STATE_SCHEMA, $state['schema'] );
		$this->assertMatchesRegularExpression( '/\Asha256:[a-f0-9]{64}\z/D', $state['state_revision'] );
		$this->assertSame( '29500', (string) $state['settings']['yuan_price'] );
		$this->assertSame( '120', (string) $state['settings']['air_express_price_per_kg'] );
		$this->assertSame( '30', $state['settings']['profit_margin_percent'] );
		$this->assertSame( 0, $state['settings']['price_rounding_digits'] );
		$this->assertSame( 'nearest_half_up', $state['settings']['price_rounding_mode'] );
		$this->assertSame( 7, $state['freshness']['stale_after'] );

		$settings               = $state['settings'];
		$settings['yuan_price'] = '31000';
		$conflict               = $service->apply_internal_settings(
			$settings,
			'google_sheets_settings',
			'sha256:' . str_repeat( 'f', 64 )
		);

		$this->assertTrue( is_wp_error( $conflict ) );
		$this->assertSame( 'digitalogic_pricing_state_revision_conflict', $conflict->get_error_code() );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );

		$applied = $service->apply_internal_settings(
			$settings,
			'google_sheets_settings',
			$state['state_revision']
		);
		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( '31000', (string) $applied['settings']['yuan_price'] );
		$this->assertSame( '187891', (string) $applied['settings']['dollar_price'] );
		$this->assertSame( '30', (string) $applied['settings']['profit_margin_percent'] );
		$this->assertSame( $settings['effective_date'], $applied['settings']['effective_date'] );
		$this->assertNotSame( $state['state_revision'], $applied['state_revision'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** A legitimate zero profit margin survives validation, storage, repricing, and readback. */
	public function test_zero_profit_margin_reprices_and_preserves_canonical_woo_price_tuple(): void {
		$service                           = Digitalogic_Excel_Pricing_Sync::instance();
		$before                            = $service->current_canonical_state();
		$settings                          = $before['settings'];
		$settings['profit_margin_percent'] = 0;

		$applied = $service->apply_internal_settings(
			$settings,
			'test_zero_profit_margin',
			$before['state_revision']
		);

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( '0', (string) $applied['settings']['profit_margin_percent'] );
		$this->assertSame( '6490000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '6490000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );

		$receiver_state = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$source         = reset( $receiver_state['sources'] );
		$this->assertSame( '0', (string) $source['products']['PRICE-901']['markup_percent'] );
		$this->assertSame( '6490000', (string) $source['products']['PRICE-901']['final_price'] );

		$readback = $service->current_canonical_state();
		$this->assertSame( '0', (string) $readback['settings']['profit_margin_percent'] );
	}

	/** Shipping, FX, margin, source state, and Woo price share one commit. */
	public function test_air_express_rate_changes_atomically_with_repricing(): void {
		$service                              = Digitalogic_Excel_Pricing_Sync::instance();
		$before                               = $service->current_canonical_state();
		$settings                             = $before['settings'];
		$settings['air_express_price_per_kg'] = '130';

		$result = $service->apply_internal_settings(
			$settings,
			'test_atomic_shipping',
			$before['state_revision']
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '130', $result['settings']['air_express_price_per_kg'] );
		$this->assertNotSame(
			$settings['shipping_catalog_revision'],
			$result['settings']['shipping_catalog_revision']
		);
		$this->assertSame(
			'130',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['air_express']['price_per_kg']
		);
		$state   = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$product = reset( $state['sources'] )['products']['PRICE-901'];
		$this->assertSame( '130', (string) $product['shipping_price_per_kg'] );
		$this->assertSame( $result['settings']['shipping_catalog_revision'], $product['pricing_catalog_revision'] );
		$this->assertSame( '8820500', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame( '8820500', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_shipping_method_updated'] );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	/** A live Patris revision does not require a quiet window for a safe CNY reprice. */
	public function test_cny_change_rebases_product_from_newer_live_catalog_revision(): void {
		$state                               = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$source_key                          = array_key_first( $state['sources'] );
		$product                             = $state['sources'][ $source_key ]['products']['PRICE-901'];
		$product['pricing_catalog_revision'] = 'sha256:' . str_repeat( 'd', 64 );
		unset( $product['record_hash'] );
		$product['record_hash']                                   = $this->record_hash( $product );
		$state['sources'][ $source_key ]['products']['PRICE-901'] = $product;
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = $state;

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '31000' ),
			'test_live_patris_rebase'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '31000', (string) $result['settings']['yuan_price'] );
		$updated_state   = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$updated_product = $updated_state['sources'][ $source_key ]['products']['PRICE-901'];
		$this->assertSame( $result['settings']['shipping_catalog_revision'], $updated_product['pricing_catalog_revision'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** The public shipping service cannot write a live freight rate outside repricing. */
	public function test_legacy_air_express_method_update_cannot_bypass_repricing(): void {
		$service = Digitalogic_Shipping_Method_Service::instance();
		$result  = $service->update_method(
			'air_express',
			array(
				'price_per_kg' => '130',
				'currency'     => 'CNY',
			)
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertTrue( $result['changed'] );
		$this->assertSame( '130', (string) $result['price_per_kg'] );
		$this->assertSame( '8820500', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame(
			'130',
			(string) $GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['air_express']['price_per_kg']
		);

		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 901 );
		$rejected                                     = $service->update_method(
			'air_express',
			array(
				'price_per_kg' => '140',
				'currency'     => 'CNY',
			)
		);

		$this->assertTrue( is_wp_error( $rejected ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $rejected->get_error_code() );
		$this->assertSame(
			'130',
			(string) $GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['air_express']['price_per_kg']
		);
		$this->assertSame( '8820500', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );

		$disabled = $service->update_method( 'air_express', array( 'enabled' => false ) );
		$this->assertTrue( is_wp_error( $disabled ) );
		$this->assertSame( 'digitalogic_pricing_air_express_required', $disabled->get_error_code() );
		$this->assertTrue(
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['air_express']['enabled']
		);
	}

	/** Numeric-only Product Codes remain text identifiers during repricing. */
	public function test_rounding_reprices_numeric_only_product_code_atomically(): void {
		$product_code = '101001001';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_product_code'] = $product_code;
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_sku']                             = $product_code;
		$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META ] = $product_code;
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$received = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot(
				array( $this->priced_product( $product_code ) ),
				'2026-07-22T00:00:00Z'
			)
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
		);

		$result = Digitalogic_Pricing_Coordinator::instance()->update_price_rounding( 2, 'numeric_code_test' );

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 2, $result['settings']['price_rounding_digits'] );
		$this->assertSame(
			'2',
			(string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_price_rounding_digits']
		);
		$state  = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$source = reset( $state['sources'] );
		$this->assertArrayHasKey( $product_code, $source['products'] );
	}

	/** A product write failure rolls the shipping option back with all settings. */
	public function test_shipping_change_rolls_back_when_repricing_fails(): void {
		$service                                      = Digitalogic_Excel_Pricing_Sync::instance();
		$before                                       = $service->current_canonical_state();
		$settings                                     = $before['settings'];
		$settings['air_express_price_per_kg']         = '130';
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 901 );

		$result = $service->apply_internal_settings(
			$settings,
			'test_shipping_rollback',
			$before['state_revision']
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame(
			'120',
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ]['air_express']['price_per_kg']
		);
		$readback = $service->current_canonical_state();
		$this->assertSame( $before['state_revision'], $readback['state_revision'] );
		$this->assertSame( '120', $readback['settings']['air_express_price_per_kg'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Tiered/minimum/volumetric shipping never falls through to the flat-price formula. */
	public function test_variable_shipping_contract_fails_closed(): void {
		$methods                                = $GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ];
		$methods['air_express']['tiered_rates'] = array(
			array(
				'minimum_weight_kg' => '0',
				'maximum_weight_kg' => '1',
				'price_per_kg'      => '120',
			),
		);
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Shipping_Method_Service::METHODS_OPTION ] = $methods;
		$this->reset_singleton( Digitalogic_Shipping_Method_Service::class );
		$catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		$this->assertFalse( is_wp_error( $catalog ) );

		$state                               = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$source_key                          = array_key_first( $state['sources'] );
		$product                             = $state['sources'][ $source_key ]['products']['PRICE-901'];
		$product['pricing_catalog_revision'] = $catalog['revision'];
		unset( $product['record_hash'] );
		$product['record_hash']                                   = $this->record_hash( $product );
		$state['sources'][ $source_key ]['products']['PRICE-901'] = $product;
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] = $state;

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_variable_shipping'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_variable_shipping_unsupported', $result->get_error_code() );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A Woo write failure rolls back both global settings and receiver state. */
	public function test_delivery_failure_rolls_back_settings_state_and_price(): void {
		$before_state                                 = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 901 );

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_failure'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( '29500', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame(
			$before_state,
			$GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ]
		);
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A matching record hash cannot hide a drifted WooCommerce regular price. */
	public function test_unchanged_settings_repair_woocommerce_price_drift(): void {
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] = '1';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price']    = '2';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_price']         = '1';
		$GLOBALS['digitalogic_test_wc_products']                          = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '29500',
				'effective_date' => '2026-07-21',
			),
			'test_drift_repair'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 'reconciled', $result['status'] );
		$this->assertFalse( $result['settings_changed'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
		$this->assertSame( 1, $result['pricing_results']['updated_products'] );
		$this->assertSame( 'clear', $result['confirmation']['status'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );

		$again = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '29500',
				'effective_date' => '2026-07-21',
			),
			'test_drift_repair_replay'
		);
		$this->assertFalse( is_wp_error( $again ) );
		$this->assertSame( 0, $again['pricing_results']['updated_products'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );
	}

	/** Reconciliation leaves every managed simple/variation price unsplit. */
	public function test_no_managed_product_retains_sale_or_effective_price_drift(): void {
		$GLOBALS['digitalogic_test_posts'][903] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Variation container',
			'product_type' => 'variable',
			'meta'         => array(),
		);
		$GLOBALS['digitalogic_test_posts'][902] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => 903,
			'post_title'  => 'Managed variation',
			'meta'        => array(
				'_digitalogic_patris_product_code' => 'VAR-902',
				Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => 'air_express',
				'_sku'                             => 'VAR-902',
				'_regular_price'                   => '1',
				'_sale_price'                      => '2',
				'_price'                           => '2',
			),
		);
		$accepted                               = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot(
				array(
					$this->priced_product( 'PRICE-901' ),
					$this->priced_product( 'VAR-902' ),
				),
				'2026-07-22T00:00:00Z'
			)
		);
		$this->assertFalse(
			is_wp_error( $accepted ),
			is_wp_error( $accepted ) ? $accepted->get_error_code() . ': ' . $accepted->get_error_message() : ''
		);

		foreach ( array( 901, 902 ) as $product_id ) {
			$GLOBALS['digitalogic_test_posts'][ $product_id ]['meta']['_regular_price'] = '1';
			$GLOBALS['digitalogic_test_posts'][ $product_id ]['meta']['_sale_price']    = '2';
			$GLOBALS['digitalogic_test_posts'][ $product_id ]['meta']['_price']         = '2';
		}
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'test_price_invariant' );
		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);

		foreach ( array( 901, 902 ) as $product_id ) {
			$meta = $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'];
			$this->assertTrue(
				Digitalogic_Patris_Price_Write_Guard::instance()->is_managed_product( $product_id )
			);
			$this->assertSame( '', (string) $meta['_sale_price'] );
			$this->assertSame( (string) $meta['_regular_price'], (string) $meta['_price'] );
			$this->assertSame(
				(string) $meta['_digitalogic_patris_final_price'],
				(string) $meta['_regular_price']
			);
		}
	}

	/** A USD date/rate update cannot falsely refresh CNY product provenance. */
	public function test_partial_usd_change_preserves_date_and_product_identity(): void {
		$before_state   = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$before_product = reset( $before_state['sources'] )['products']['PRICE-901'];

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'dollar_price'       => '188891',
				'usd_effective_date' => '2026-07-27',
			),
			'test_usd_only'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '188891', $GLOBALS['digitalogic_test_options']['options_dollar_price'] );
		$this->assertSame( '260721', $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertSame( '2026-07-27', $settings['usd_effective_date'] );
		$this->assertSame( '2026-07-21', $settings['cny_effective_date'] );
		$this->assertSame( '2026-07-21', $settings['effective_date'] );
		$after_state   = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$after_product = reset( $after_state['sources'] )['products']['PRICE-901'];
		$this->assertSame( $before_product['pricing_catalog_revision'], $after_product['pricing_catalog_revision'] );
		$this->assertSame( $before_product['record_hash'], $after_product['record_hash'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( 0, $result['pricing_results']['changed_products'] );
	}

	/** A CNY date remains the legacy/storefront date and drives product pricing. */
	public function test_independent_cny_date_updates_legacy_and_product_state_only(): void {
		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'         => '31000',
				'cny_effective_date' => '2026-07-27',
			),
			'test_cny_only'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertSame( '2026-07-21', $settings['usd_effective_date'] );
		$this->assertSame( '2026-07-27', $settings['cny_effective_date'] );
		$this->assertSame( '2026-07-27', $settings['effective_date'] );
		$this->assertSame( '260727', $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame(
			'2026-07-27',
			$GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_currency_effective_date']
		);
	}

	/** A CNY-only legacy/API rate change receives a fresh CNY date automatically. */
	public function test_single_cny_rate_change_refreshes_only_cny_date(): void {
		$before = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertFalse( is_wp_error( $before ) );
		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '31000' ),
			'test_cny_rate_only'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$today    = gmdate( 'Y-m-d' );
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertSame( $before['usd_effective_date'], $settings['usd_effective_date'] );
		$this->assertSame( $today, $settings['cny_effective_date'] );
		$this->assertSame( $today, $settings['effective_date'] );
		$this->assertSame( substr( $today, 2, 2 ) . substr( $today, 5, 2 ) . substr( $today, 8, 2 ), $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** A USD-only legacy/API rate change receives a fresh USD date automatically. */
	public function test_single_usd_rate_change_refreshes_only_usd_date(): void {
		$before = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertFalse( is_wp_error( $before ) );
		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'dollar_price' => '188891' ),
			'test_usd_rate_only'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$today    = gmdate( 'Y-m-d' );
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertSame( $today, $settings['usd_effective_date'] );
		$this->assertSame( $before['cny_effective_date'], $settings['cny_effective_date'] );
		$this->assertSame( $before['effective_date'], $settings['effective_date'] );
		$this->assertSame( '260721', $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( 0, $result['pricing_results']['changed_products'] );
	}

	/** An unchanged admin-style two-rate submission never refreshes either date. */
	public function test_unchanged_two_rate_submission_does_not_refresh_dates(): void {
		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'dollar_price' => '0187891',
				'yuan_price'   => '029500',
			),
			'admin_currency'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 'reconciled', $result['status'] );
		$this->assertSame( '260721', $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertSame( '2026-07-21', $settings['usd_effective_date'] );
		$this->assertSame( '2026-07-21', $settings['cny_effective_date'] );
		$this->assertArrayNotHasKey(
			Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION,
			$GLOBALS['digitalogic_test_options']
		);
	}

	/** A one-thousand-row no-op reconcile is bounded and performs no Woo writes. */
	public function test_large_unchanged_reconcile_is_one_query_zero_write_and_under_five_seconds(): void {
		$this->seed_large_pricing_snapshot( 1000 );
		$GLOBALS['wpdb']->identifier_query_count                  = 0;
		$GLOBALS['wpdb']->queries                                 = array();
		$GLOBALS['digitalogic_test_wc_product_saves']             = array();
		$GLOBALS['digitalogic_test_cache_delete_multiple']        = array();
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		array_splice( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'], 0 );
		array_splice( $GLOBALS['digitalogic_test_pricing_phase_events'], 0 );

		$started = microtime( true );
		$result  = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'dollar_price' => '187891',
				'yuan_price'   => '29500',
			),
			'performance_noop'
		);
		$elapsed = microtime( true ) - $started;

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertLessThan( 5.0, $elapsed );
		$this->assertSame( 1, $GLOBALS['wpdb']->identifier_query_count );
		$this->assertSame( 0, $result['pricing_results']['changed_products'] );
		$this->assertSame( 0, $result['pricing_results']['updated_products'] );
		$this->assertSame( 1000, $result['pricing_results']['already_current_products'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_cache_delete_multiple'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertSame(
			array( 'pricing_batch_leaf_lookup_preflight' ),
			array_column(
				array_values(
					array_filter(
						$GLOBALS['digitalogic_test_pricing_phase_events'],
						static fn( $event ) => str_starts_with( (string) ( $event['name'] ?? '' ), 'pricing_topology_' )
							|| str_starts_with( (string) ( $event['name'] ?? '' ), 'pricing_batch_' )
					)
				),
				'name'
			)
		);
	}

	/** Same-rate reconciliation repairs an exact leaf lookup drift and then becomes a no-op. */
	public function test_unchanged_rate_repairs_leaf_lookup_drift_and_replay_is_noop(): void {
		$this->seed_large_pricing_snapshot( 4 );
		$GLOBALS['digitalogic_test_wc_transient_deletes']               = array();
		$GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['min_price'] = '1';
		$GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['max_price'] = '2';
		$GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['onsale']    = 1;
		$GLOBALS['digitalogic_test_wc_product_saves']                   = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'leaf_lookup_drift' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 0, $result['pricing_results']['changed_products'] );
		$this->assertSame( 1, $result['pricing_results']['updated_products'] );
		$this->assertSame( 3, $result['pricing_results']['already_current_products'] );
		$this->assertSame( 0, $result['pricing_results']['repaired_parent_products'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['min_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['max_price'] );
		$this->assertSame( 0, (int) $GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['onsale'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( array( 20000 ), array_values( array_unique( $GLOBALS['digitalogic_test_wc_transient_deletes'] ) ) );

		$GLOBALS['digitalogic_test_wc_transient_deletes'] = array();
		$replay = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'leaf_lookup_replay' );
		$this->assertFalse( is_wp_error( $replay ) );
		$this->assertSame( 0, $replay['pricing_results']['updated_products'] );
		$this->assertSame( 4, $replay['pricing_results']['already_current_products'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_transient_deletes'] );
	}

	/** Same-rate reconciliation atomically repairs variable-parent raw and lookup drift. */
	public function test_unchanged_rate_repairs_variable_parent_projection_once(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_wc_transient_deletes']               = array();
		$GLOBALS['digitalogic_test_posts'][30000]['meta_rows']          = array(
			'_price'         => array( '1', '2' ),
			'_regular_price' => array( '1' ),
			'_sale_price'    => array( '2' ),
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] = '1';
		$GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] = '2';
		$GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['onsale']    = 1;
		$GLOBALS['digitalogic_test_wc_product_saves']                   = array();
		$events_before = count(
			$GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array()
		);

		$result = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'parent_projection_drift' );

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 0, $result['pricing_results']['changed_products'] );
		$this->assertSame( 2, $result['pricing_results']['updated_products'] );
		$this->assertSame( 2, $result['pricing_results']['already_current_products'] );
		$this->assertSame( 1, $result['pricing_results']['repaired_parent_products'] );
		$this->assertSame( 1, $result['pricing_results']['sources'][0]['woocommerce']['batch_parent_count'] );
		$this->assertSame( array( '8437000' ), $GLOBALS['digitalogic_test_posts'][30000]['meta_rows']['_price'] );
		$this->assertArrayNotHasKey( '_regular_price', $GLOBALS['digitalogic_test_posts'][30000]['meta_rows'] );
		$this->assertArrayNotHasKey( '_sale_price', $GLOBALS['digitalogic_test_posts'][30000]['meta_rows'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] );
		$this->assertSame( 0, (int) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['onsale'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame(
			array( 20002, 20003, 30000 ),
			array_values( array_unique( $GLOBALS['digitalogic_test_wc_transient_deletes'] ) )
		);
		$this->assertSame(
			$events_before + 1,
			count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() )
		);

		$GLOBALS['digitalogic_test_wc_transient_deletes'] = array();
		$replay = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'parent_projection_replay' );
		$this->assertFalse( is_wp_error( $replay ) );
		$this->assertSame( 0, $replay['pricing_results']['updated_products'] );
		$this->assertSame( 4, $replay['pricing_results']['already_current_products'] );
		$this->assertSame( 0, $replay['pricing_results']['repaired_parent_products'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_transient_deletes'] );
	}

	/** Duplicate raw leaf price rows cannot hide behind a same-rate cache hit. */
	public function test_unchanged_rate_repairs_duplicate_leaf_price_rows(): void {
		$this->seed_large_pricing_snapshot( 4 );
		$GLOBALS['digitalogic_test_posts'][20000]['meta_rows']['_price'] = array( '8437000', '8437000' );

		$result = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'duplicate_leaf_price_rows' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, $result['pricing_results']['updated_products'] );
		$this->assertSame( 3, $result['pricing_results']['already_current_products'] );
		$this->assertSame( array( '8437000' ), get_post_meta( 20000, '_price', false ) );
	}

	/** A stale WC instance cannot hide authoritative raw price drift on same-rate reconcile. */
	public function test_unchanged_rate_repairs_raw_price_drift_hidden_by_stale_product_instance(): void {
		$this->seed_large_pricing_snapshot( 4 );
		$stale = wc_get_product( 20000 );
		$this->assertSame( '8437000', (string) $stale->get_price() );
		$GLOBALS['digitalogic_test_posts'][20000]['meta']['_regular_price'] = '1';
		$GLOBALS['digitalogic_test_posts'][20000]['meta']['_price']         = '1';
		unset( $GLOBALS['digitalogic_test_post_meta_cache'][20000] );

		$result = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'stale_instance_raw_drift' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][20000]['meta']['_regular_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][20000]['meta']['_price'] );
		$this->assertContains( 20000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
	}

	/** A live-shaped CNY change is four-chunked, query-bounded, and save-free. */
	public function test_large_changed_reconcile_batches_771_leaves_under_ten_seconds(): void {
		$this->seed_large_pricing_snapshot( 771, 14 );
		$GLOBALS['digitalogic_test_posts'][30000]['meta_rows'] = array(
			'_price'         => array( '8437000', '8437001' ),
			'_regular_price' => array( '8437000' ),
			'_sale_price'    => array( '1' ),
		);
		$GLOBALS['wpdb']->identifier_query_count           = 0;
		$GLOBALS['wpdb']->queries                          = array();
		$GLOBALS['digitalogic_test_wc_product_saves']      = array();
		$GLOBALS['digitalogic_test_cache_delete_multiple'] = array();
		$GLOBALS['digitalogic_test_primed_post_ids']       = array();
		array_splice( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'], 0 );
		$events_before = count(
			$GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array()
		);

		$started = microtime( true );
		$result  = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '29501',
				'effective_date' => '2026-07-27',
			),
			'performance_changed'
		);
		$elapsed = microtime( true ) - $started;

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertLessThan( 10.0, $elapsed );
		$this->assertSame( 1, $GLOBALS['wpdb']->identifier_query_count );
		$this->assertSame( 771, $result['pricing_results']['changed_products'] );
		$this->assertSame( 771, $result['pricing_results']['updated_products'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertCount( 3, $GLOBALS['digitalogic_test_primed_post_ids'] );
		$this->assertCount( 771, $GLOBALS['digitalogic_test_primed_post_ids'][0] );
		$this->assertCount( 771, $GLOBALS['digitalogic_test_primed_post_ids'][1] );
		$this->assertCount( 14, $GLOBALS['digitalogic_test_primed_post_ids'][2] );
		$this->assertContains( 30000, $GLOBALS['digitalogic_test_primed_post_ids'][2] );
		$source = $result['pricing_results']['sources'][0]['woocommerce'];
		$this->assertSame( 4, $source['batch_count'] );
		$this->assertSame( 14, $source['batch_parent_count'] );
		$this->assertLessThanOrEqual( 21, count( $GLOBALS['wpdb']->queries ) );
		$this->assertCount( 5, $GLOBALS['digitalogic_test_cache_delete_multiple'] );
		$this->assertSame(
			array( 'posts', 'post_meta', 'product_type_relationships', 'posts', 'post_meta' ),
			array_column( $GLOBALS['digitalogic_test_cache_delete_multiple'], 'group' )
		);
		$this->assertCount( 785, $GLOBALS['digitalogic_test_cache_delete_multiple'][0]['keys'] );
		$this->assertContains( 30000, $GLOBALS['digitalogic_test_cache_delete_multiple'][0]['keys'] );
		$this->assertContains( 'products', $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertCount( 1570, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 30000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertSame(
			array( 2 ),
			array_values(
				array_unique(
					array_values(
						array_count_values( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] )
					)
				)
			)
		);
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_posts'][20000]['meta']['_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][20000]['min_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_posts'][20770]['meta']['_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][20770]['min_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] );
		$this->assertSame( 0, (int) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['onsale'] );
		$this->assertSame( array( '8437286' ), $GLOBALS['digitalogic_test_posts'][30000]['meta_rows']['_price'] );
		$this->assertArrayNotHasKey( '_regular_price', $GLOBALS['digitalogic_test_posts'][30000]['meta_rows'] );
		$this->assertArrayNotHasKey( '_sale_price', $GLOBALS['digitalogic_test_posts'][30000]['meta_rows'] );
		$this->assertSame(
			$events_before + 1,
			count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() )
		);
	}

	/** One full-feed repair cannot force otherwise-safe priced leaves through Woo saves. */
	public function test_unsafe_priced_leaf_does_not_poison_safe_bulk_partition(): void {
		$this->seed_large_pricing_snapshot( 30 );
		$GLOBALS['digitalogic_test_posts'][20029]['meta'][ Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META ] = '1';
		unset( $GLOBALS['digitalogic_test_posts'][20029]['meta']['_digitalogic_patris_weight_grams'] );
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$GLOBALS['digitalogic_test_wc_product_saves'] = array();

		$GLOBALS['digitalogic_test_cache_delete_multiple'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '29501',
				'effective_date' => '2026-07-27',
			),
			'performance_partitioned_fallback'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 30, $result['pricing_results']['changed_products'] );
		$this->assertSame( 30, $result['pricing_results']['updated_products'] );
		$source = $result['pricing_results']['sources'][0]['woocommerce'];
		$this->assertSame( 1, $source['batch_count'] );
		$this->assertSame( 0, $source['batch_parent_count'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame(
			array( 20029 ),
			array_values( array_unique( $GLOBALS['digitalogic_test_wc_product_saves'] ) )
		);
		$this->assertSame( '1000', (string) $GLOBALS['digitalogic_test_posts'][20029]['meta']['_digitalogic_patris_weight_grams'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_posts'][20000]['meta']['_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_posts'][20029]['meta']['_price'] );
	}

	/** One missing legacy shipping assignment is CAS-bootstrapped in fallback without poisoning bulk survivors. */
	public function test_missing_shipping_assignment_partitions_to_fallback_and_commits_bulk_survivors(): void {
		$this->seed_large_pricing_snapshot( 4 );
		unset(
			$GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$events_before                                = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] ?? array() );
		$events_at_commit                             = null;
		$GLOBALS['wpdb']->after_commit                = static function () use ( &$events_at_commit ) {
			$events_at_commit = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] ?? array() );
		};

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'missing_assignment_partition'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 4, $result['pricing_results']['updated_products'] );
		$this->assertSame( 1, $result['pricing_results']['sources'][0]['woocommerce']['batch_count'] );
		$this->assertSame(
			array( 20003 ),
			array_values( array_unique( $GLOBALS['digitalogic_test_wc_product_saves'] ) )
		);
		$this->assertSame(
			'air_express',
			(string) $GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_posts'][20000]['meta']['_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_posts'][20003]['meta']['_price'] );
		$this->assertSame( $events_before, $events_at_commit );
		$this->assertSame(
			$events_before + 1,
			count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] ?? array() )
		);
		$this->assertSame(
			array( 20003, 'air_express' ),
			end( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] )
		);
	}

	/** A later fallback failure rolls assignment and prices back without publishing an assignment event. */
	public function test_missing_shipping_assignment_outer_rollback_restores_prestate_and_discards_event(): void {
		$this->seed_large_pricing_snapshot( 4 );
		unset(
			$GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 20003 );
		$before_posts                                 = $GLOBALS['digitalogic_test_posts'];
		$before_lookup                                = $GLOBALS['digitalogic_test_wc_lookup_rows'];
		$before_options                               = $GLOBALS['digitalogic_test_options'];
		$events_before                                = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] ?? array() );
		$events_at_rollback                           = null;
		$GLOBALS['wpdb']->queries                     = array();
		$GLOBALS['wpdb']->after_rollback              = static function () use ( &$events_at_rollback ) {
			$events_at_rollback = count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] ?? array() );
		};

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'missing_assignment_outer_rollback'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $before_lookup, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
		$this->assertSame( $before_options, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( $events_before, $events_at_rollback );
		$this->assertSame(
			$events_before,
			count( $GLOBALS['digitalogic_test_actions']['digitalogic_product_shipping_method_updated'] ?? array() )
		);
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	/** A fallback-only rollback evicts request-local WC objects for every touched leaf. */
	public function test_fallback_only_rollback_evicts_exact_product_instances(): void {
		$this->seed_large_pricing_snapshot( 2 );
		foreach ( array( 20000, 20001 ) as $product_id ) {
			unset( $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] );
		}
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 20001 );
		array_splice( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'], 0 );

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'fallback_only_cache_rollback'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertContains( 20000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 20001, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A disabled materializer variation stays fallback and its parent is refreshed after both lanes. */
	public function test_mixed_variation_bulk_and_fallback_refreshes_parent_after_fallback(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['wc_deferred_product_sync']                        = array( 999 );
		$GLOBALS['digitalogic_test_wc_enqueue_parent_sync_on_save'] = true;
		add_filter(
			'digitalogic_patris_auto_materialize_source_product',
			static function ( $enabled, $product ) {
				return 'PERF-0003' === (string) ( $product['product_code'] ?? '' ) ? false : $enabled;
			},
			10,
			2
		);
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '29501',
				'effective_date' => '2026-07-27',
			),
			'mixed_variation_parent_refresh'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 4, $result['pricing_results']['updated_products'] );
		$this->assertSame( 1, $result['pricing_results']['sources'][0]['woocommerce']['batch_count'] );
		$this->assertSame( 1, $result['pricing_results']['sources'][0]['woocommerce']['fallback_parent_count'] );
		$this->assertSame(
			array( 20003 ),
			array_values( array_unique( $GLOBALS['digitalogic_test_wc_product_saves'] ) )
		);
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] );
		$this->assertSame( 999, (int) reset( $GLOBALS['wc_deferred_product_sync'] ) );
		$this->assertContains( 30000, $GLOBALS['wc_deferred_product_sync'] );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	/** A fallback variation rollback restores the exact preexisting Woo shutdown queue. */
	public function test_variation_fallback_rollback_restores_exact_deferred_parent_sync_queue(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['wc_deferred_product_sync']                        = array( 999 );
		$GLOBALS['digitalogic_test_wc_enqueue_parent_sync_on_save'] = true;
		unset( $GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] );
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 20003 );

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'variation_deferred_sync_rollback'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array( 999 ), $GLOBALS['wc_deferred_product_sync'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A rolled-back variation fallback leaves no new shutdown queue behind. */
	public function test_variation_fallback_rollback_discards_new_deferred_parent_sync_queue(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_wc_enqueue_parent_sync_on_save'] = true;
		unset( $GLOBALS['wc_deferred_product_sync'] );
		unset( $GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] );
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 20003 );

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'variation_deferred_sync_discard'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertArrayNotHasKey( 'wc_deferred_product_sync', $GLOBALS );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A literal zero sibling follows Woo min rules, while a blank-price zero lookup sentinel does not. */
	public function test_variable_parent_zero_sibling_distinguishes_literal_price_from_lookup_sentinel(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_posts'][40000]          = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 30000,
			'meta'         => array(
				'_regular_price' => '0',
				'_sale_price'    => '',
				'_price'         => '0',
			),
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows'][40000] = array(
			'product_id' => 40000,
			'min_price'  => '0',
			'max_price'  => '0',
			'onsale'     => 0,
		);

		$literal = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'literal_zero_sibling'
		);
		$this->assertFalse( is_wp_error( $literal ) );
		$this->assertSame( '0', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] );

		$GLOBALS['digitalogic_test_posts'][40000]['meta']['_regular_price'] = '';
		$GLOBALS['digitalogic_test_posts'][40000]['meta']['_price']         = '';
		$GLOBALS['digitalogic_test_wc_products']                            = array();
		$sentinel = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29502' ),
			'blank_price_lookup_sentinel'
		);
		$this->assertFalse( is_wp_error( $sentinel ) );
		$this->assertSame( '8437572', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] );
		$this->assertSame( '8437572', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][40000]['meta']['_price'] );
	}

	/** Hidden out-of-stock variations are excluded from parent rows and lookup bounds. */
	public function test_variable_parent_price_rows_follow_visible_out_of_stock_membership(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_terms'][900]                                     = array(
			'term_id'  => 900,
			'slug'     => 'outofstock',
			'taxonomy' => 'product_visibility',
		);
		$GLOBALS['digitalogic_test_posts'][40000]                                   = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 30000,
			'meta'         => array( '_price' => '1' ),
		);
		$GLOBALS['digitalogic_test_object_terms'][40000]['product_visibility']      = array( 900 );
		$GLOBALS['digitalogic_test_wc_lookup_rows'][40000]                          = array(
			'product_id'   => 40000,
			'min_price'    => '1',
			'max_price'    => '1',
			'onsale'       => 0,
			'stock_status' => 'outofstock',
		);
		$GLOBALS['digitalogic_test_options']['woocommerce_hide_out_of_stock_items'] = 'yes';

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'visible_children_stock_filter'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( '8437286' ), $GLOBALS['digitalogic_test_posts'][30000]['meta_rows']['_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['min_price'] );
		$this->assertSame( '8437286', (string) $GLOBALS['digitalogic_test_wc_lookup_rows'][30000]['max_price'] );
	}

	/** Incomplete identity/materialization markers isolate to fallback instead of poisoning the safe batch. */
	public function test_missing_owner_sku_and_noncanonical_marker_partition_to_fallback(): void {
		$this->seed_large_pricing_snapshot( 6 );
		foreach (
			array(
				Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META,
				Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META,
				Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META,
			) as $owner_key
		) {
			unset( $GLOBALS['digitalogic_test_posts'][20003]['meta'][ $owner_key ] );
		}
		unset( $GLOBALS['digitalogic_test_posts'][20004]['meta']['_sku'] );
		$GLOBALS['digitalogic_test_posts'][20005]['meta'][ Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META ] = '0';
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'incomplete_bulk_identity_partition'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 6, $result['pricing_results']['updated_products'] );
		$this->assertSame(
			array( 20003, 20004, 20005 ),
			array_values( array_unique( $GLOBALS['digitalogic_test_wc_product_saves'] ) )
		);
		$this->assertSame( 'pricing-tests', (string) $GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META ] );
		$this->assertSame( 'kala', (string) $GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META ] );
		$this->assertSame( 'PERF-0003', (string) $GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META ] );
		$this->assertSame( 'PERF-0004', (string) $GLOBALS['digitalogic_test_posts'][20004]['meta']['_sku'] );
	}

	/** Missing lookup rows never enter the partial bulk lookup writer. */
	public function test_missing_leaf_lookup_partitions_to_full_writer(): void {
		$this->seed_large_pricing_snapshot( 4 );
		unset( $GLOBALS['digitalogic_test_wc_lookup_rows'][20003] );
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'missing_lookup_partition'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( array( 20003 ), array_values( array_unique( $GLOBALS['digitalogic_test_wc_product_saves'] ) ) );
		$this->assertArrayHasKey( 20003, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
		$this->assertArrayHasKey( 'stock_status', $GLOBALS['digitalogic_test_wc_lookup_rows'][20003] );
	}

	/** Coordinated fallback drains beyond the normal request cursor without rollback replay. */
	public function test_coordinated_fallback_drains_thirty_rows_atomically(): void {
		$this->seed_large_pricing_snapshot( 30 );
		for ( $product_id = 20000; $product_id < 20030; ++$product_id ) {
			unset( $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] );
		}
		$GLOBALS['digitalogic_test_wc_products']      = array();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'fallback_thirty_atomic'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 30, $result['pricing_results']['updated_products'] );
		$this->assertCount( 30, array_unique( $GLOBALS['digitalogic_test_wc_product_saves'] ) );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Reparenting, parent status, or parent ownership drift rolls the exact transaction back. */
	public function test_variation_parent_reparent_and_status_drift_roll_back_exact_prestate(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		foreach ( array( 'reparent', 'status', 'owner' ) as $drift ) {
			$before_posts  = $GLOBALS['digitalogic_test_posts'];
			$before_lookup = $GLOBALS['digitalogic_test_wc_lookup_rows'];
			$GLOBALS['digitalogic_test_before_pricing_batch_parent_inputs'] = static function () use ( $drift ) {
				if ( 'reparent' === $drift ) {
					$GLOBALS['digitalogic_test_posts'][20003]['post_parent'] = 39999;
				} elseif ( 'status' === $drift ) {
					$GLOBALS['digitalogic_test_posts'][30000]['post_status'] = 'draft';
				} else {
					$GLOBALS['digitalogic_test_posts'][30000]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META ] = 'foreign-source';
				}
			};

			$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
				array( 'yuan_price' => '29501' ),
				'parent_' . $drift . '_drift'
			);

			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
			$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
			$this->assertSame( $before_lookup, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
			$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
			$GLOBALS['digitalogic_test_wc_products'] = array();
		}
	}

	/** A post-save fallback reparent is locked, rejected, and rolled back before parent refresh. */
	public function test_fallback_post_save_reparent_rolls_back_without_event_or_queue_leak(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_posts'][30001]          = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Adversarial variable parent',
			'product_type' => 'variable',
			'meta'         => array(),
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows'][30001] = array(
			'product_id' => 30001,
			'min_price'  => '0',
			'max_price'  => '0',
			'onsale'     => 0,
		);
		unset(
			$GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$GLOBALS['wc_deferred_product_sync']                            = array( 999 );
		$GLOBALS['digitalogic_test_wc_enqueue_parent_sync_on_save']     = true;
		$GLOBALS['digitalogic_test_wc_products']                        = array();
		$GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] = array();
		$GLOBALS['wpdb']->queries                                       = array();
		$events_before                             = count(
			$GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array()
		);
		$before_posts                              = $GLOBALS['digitalogic_test_posts'];
		$before_lookup                             = $GLOBALS['digitalogic_test_wc_lookup_rows'];
		$GLOBALS['digitalogic_test_wc_after_save'] = static function ( $product ) {
			if ( 20003 === (int) $product->get_id() ) {
				$GLOBALS['digitalogic_test_posts'][20003]['post_parent'] = 30001;
			}
		};

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'fallback_post_save_reparent'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_readback_failed', $result->get_error_code() );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $before_lookup, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
		$this->assertSame( array( 999 ), $GLOBALS['wc_deferred_product_sync'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertSame(
			$events_before,
			count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() )
		);
		$removed = array_values( array_unique( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] ) );
		$this->assertContains( 20003, $removed );
		$this->assertContains( 30000, $removed );
		$this->assertContains( 30001, $removed );
	}

	/** A stale request-local simple object cannot misclassify an authoritative variation. */
	public function test_pricing_preclassification_evicts_stale_product_instance_type_and_parent(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_wc_products'][20003] = new class( 20003 ) extends WC_Product {
			/** Return a deliberately stale type projection. */
			public function get_type() {
				return 'simple';
			}

			/**
			 * Match the deliberately stale type projection.
			 *
			 * @param mixed $type Requested type.
			 * @return bool
			 */
			public function is_type( $type ) {
				return 'simple' === $type;
			}

			/** Return a deliberately stale parent projection. */
			public function get_parent_id() {
				return 0;
			}
		};
		$GLOBALS['digitalogic_test_wc_product_saves']   = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'stale_product_instance_type'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 4, $result['pricing_results']['updated_products'] );
		$this->assertSame( 1, $result['pricing_results']['sources'][0]['woocommerce']['batch_count'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame( 'variation', wc_get_product( 20003 )->get_type() );
		$this->assertSame( 30000, wc_get_product( 20003 )->get_parent_id() );
		$this->assertSame( 2, array_count_values( $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] )[20003] ?? 0 );
	}

	/** Locked current reads reject leaf, assignment, owner, and cross-namespace identity races. */
	public function test_pricing_leaf_identity_toc_tou_races_roll_back_without_event_or_cache_contamination(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		foreach ( array( 'code', 'shipping', 'owner', 'collision' ) as $drift ) {
			$before_posts   = $GLOBALS['digitalogic_test_posts'];
			$before_lookup  = $GLOBALS['digitalogic_test_wc_lookup_rows'];
			$before_options = $GLOBALS['digitalogic_test_options'];
			$events_before  = count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
			$GLOBALS['digitalogic_test_before_pricing_batch_leaf_identity'] = static function () use ( $drift ) {
				if ( 'code' === $drift ) {
					$GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META ] = 'FOREIGN-CODE';
				} elseif ( 'shipping' === $drift ) {
					$GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] = 'sea';
				} elseif ( 'owner' === $drift ) {
					$GLOBALS['digitalogic_test_posts'][20003]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META ] = 'foreign-dataset';
				} else {
					$GLOBALS['digitalogic_test_posts'][49999] = array(
						'post_type'    => 'product',
						'post_status'  => 'publish',
						'product_type' => 'simple',
						'post_parent'  => 0,
						'meta'         => array( '_sku' => 'PERF-0003' ),
					);
				}
			};

			$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
				array( 'yuan_price' => '29501' ),
				'leaf_identity_' . $drift
			);

			$this->assertTrue( is_wp_error( $result ), $drift );
			$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code(), $drift );
			$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'], $drift );
			$this->assertSame( $before_lookup, $GLOBALS['digitalogic_test_wc_lookup_rows'], $drift );
			$this->assertSame( $before_options, $GLOBALS['digitalogic_test_options'], $drift );
			$this->assertSame(
				$events_before,
				count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() ),
				$drift
			);
			$this->assertSame( 'PERF-0003', (string) wc_get_product( 20003 )->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ), $drift );
			$this->assertSame( 'air_express', (string) wc_get_product( 20003 )->get_meta( Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ), $drift );
			$this->assertSame( 'variation', wc_get_product( 20003 )->get_type(), $drift );
			$this->assertSame( 30000, wc_get_product( 20003 )->get_parent_id(), $drift );
			$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries, $drift );
			$GLOBALS['digitalogic_test_wc_products'] = array();
		}
	}

	/** One malformed negative sibling fails closed instead of disappearing from the parent aggregate. */
	public function test_negative_variable_sibling_price_fails_closed_and_rolls_back(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_posts'][40000] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'product_type' => 'variation',
			'post_parent'  => 30000,
			'meta'         => array( '_price' => '-1' ),
		);
		$before_posts                             = $GLOBALS['digitalogic_test_posts'];

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'negative_sibling'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A ProductCache exception drains later removals but fails closed before writes. */
	public function test_product_instance_cache_eviction_failure_fails_closed_after_draining_targets(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );
		$GLOBALS['digitalogic_test_wc_product_instance_cache_failure_ids'] = array( 20000 );
		$before_posts             = $GLOBALS['digitalogic_test_posts'];
		$before_lookup            = $GLOBALS['digitalogic_test_wc_lookup_rows'];
		$GLOBALS['wpdb']->queries = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'cache_evict_continue'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_product_cache_evict_failed', $result->get_error_code() );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $before_lookup, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
		$this->assertNotContains( 20000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 20001, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 20003, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 30000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	/** A post-write parent readback failure restores leaf, parent, source, and rate exactly. */
	public function test_variation_parent_bulk_readback_failure_rolls_back_exact_prestate(): void {
		$this->seed_large_pricing_snapshot( 4, 1 );

		$before_posts   = $GLOBALS['digitalogic_test_posts'];
		$before_lookup  = $GLOBALS['digitalogic_test_wc_lookup_rows'];
		$before_state   = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$before_options = array(
			'yuan_price'         => $GLOBALS['digitalogic_test_options']['yuan_price'],
			'options_yuan_price' => $GLOBALS['digitalogic_test_options']['options_yuan_price'],
		);

		$GLOBALS['wpdb']->queries = array();

		$GLOBALS['digitalogic_test_pricing_batch_lookup_readback_failure'] = true;

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '29501',
				'effective_date' => '2026-07-27',
			),
			'variation_parent_readback_rollback'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( $before_options['yuan_price'], $GLOBALS['digitalogic_test_options']['yuan_price'] );
		$this->assertSame( $before_options['options_yuan_price'], $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( $before_state, $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ] );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $before_lookup, $GLOBALS['digitalogic_test_wc_lookup_rows'] );
		$this->assertContains( 20003, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 30000, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertSame( '8437000', (string) wc_get_product( 20003 )->get_price() );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	/** The ACF page queues a changed CNY rate without mutating the confirmed option inline. */
	public function test_currency_admin_async_queue_keeps_confirmed_rate_until_background_job(): void {
		$before = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$job    = Digitalogic_Currency_Admin_Async::instance()->enqueue( '29501' );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( array( 'yuan_price' => 29501 ), $job['desired_currency'] );
		$this->assertSame( 29500, $job['confirmed_currency']['yuan_price'] );
		$after = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		$this->assertSame( $before['yuan_price'], $after['yuan_price'] );
		$this->assertNotFalse(
			wp_next_scheduled(
				'digitalogic_currency_admin_async_apply',
				array( $job['job_id'], $job['generation'] )
			)
		);
		$this->assertArrayHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( 1, $job['dispatch_attempts'] );
		$this->assertNotEmpty( $GLOBALS['digitalogic_test_remote_posts'] );
		$dispatch = end( $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertStringStartsWith( 'https://digitalogic.test/wp-cron.php?doing_wp_cron=', $dispatch['url'] );
		$this->assertStringNotContainsString( '127.0.0.1', $dispatch['url'] );
		$this->assertFalse( $dispatch['args']['blocking'] );
		$this->assertSame( 0.01, $dispatch['args']['timeout'] );
		$this->assertFalse( $dispatch['args']['sslverify'] );
		$this->assertArrayNotHasKey( 'headers', $dispatch['args'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
		$spawn = $GLOBALS['digitalogic_test_spawn_cron_calls'][0];
		$this->assertSame( $spawn['token'], (string) get_transient( 'doing_cron' ) );
		$this->assertSame( 0, $spawn['job_lock_balance'] );
	}

	/** Action Scheduler and WP-Cron carry one exact effect identity, so either runner may wake safely. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_currency_admin_async_dual_schedules_one_fenced_identity(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Isolated test process injects the production scheduler path.
				'function as_schedule_single_action($timestamp, $hook, $args, $group, $unique) {'
				. 'foreach ($GLOBALS["digitalogic_test_as_actions"] as $index => $action) {'
				. 'if ($unique && $action["hook"] === $hook && $action["args"] === $args && $action["group"] === $group) { return $index + 1; }}'
				. '$GLOBALS["digitalogic_test_as_actions"][] = array("timestamp" => $timestamp, "hook" => $hook, "args" => $args, "group" => $group, "unique" => $unique);'
				. 'return count($GLOBALS["digitalogic_test_as_actions"]);}'
			);
		}
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Isolated test process records exact provider cleanup.
				'function as_unschedule_all_actions($hook, $args, $group) {'
				. '$before = count($GLOBALS["digitalogic_test_as_actions"]);'
				. '$GLOBALS["digitalogic_test_as_actions"] = array_values(array_filter($GLOBALS["digitalogic_test_as_actions"], '
				. 'static fn($action) => $action["hook"] !== $hook || $action["args"] !== $args || $action["group"] !== $group));'
				. 'return $before - count($GLOBALS["digitalogic_test_as_actions"]);}'
			);
		}
		$GLOBALS['digitalogic_test_as_actions'] = array();

		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501' );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_spawn_cron_calls'][0]['job_lock_balance'] );
		$as_apply = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_as_actions'],
				static fn( $action ) => 'digitalogic_currency_admin_async_apply' === $action['hook']
			)
		);
		$wp_apply = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static fn( $event ) => 'digitalogic_currency_admin_async_apply' === $event['hook']
			)
		);
		$this->assertCount( 1, $as_apply );
		$this->assertCount( 1, $wp_apply );
		$this->assertSame( $as_apply[0]['args'], $wp_apply[0]['args'] );
		$this->assertSame( array( $job['job_id'], $job['generation'] ), $wp_apply[0]['args'] );
		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$this->assertTrue( $async->recover_queued_job() );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_as_actions'] );

		$this->assertSame( 1, digitalogic_test_run_spawned_cron() );
		$terminal = $async->status( $job['job_id'], $job['generation'] );
		$async->run_job( $job['job_id'], $job['generation'] );
		$this->assertSame( 'confirmed', $terminal['status'] );
		$this->assertSame( 1, $terminal['apply_attempts'] );
		$this->assertSame( $terminal, $async->status( $job['job_id'], $job['generation'] ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$remaining_async_actions = array_filter(
			$GLOBALS['digitalogic_test_as_actions'],
			static fn( $action ) => str_starts_with( (string) $action['hook'], 'digitalogic_currency_admin_async_' )
		);
		$remaining_cron_events   = array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static fn( $event ) => str_starts_with( (string) $event['hook'], 'digitalogic_currency_admin_async_' )
		);
		$this->assertSame( array(), array_values( $remaining_async_actions ) );
		$this->assertSame( array(), array_values( $remaining_cron_events ) );
	}

	/** A provider dispatcher or HTTP transport failure cannot undo the durable queued job. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_currency_admin_async_wake_failures_are_bounded_and_best_effort(): void {
		$GLOBALS['digitalogic_test_remote_post_results'][] = new RuntimeException( 'HTTP transport unavailable' );

		$started = microtime( true );
		$job     = Digitalogic_Currency_Admin_Async::instance()->enqueue( '29501' );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 1, $job['dispatch_attempts'] );
		$this->assertLessThan( 0.5, microtime( true ) - $started );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertNotFalse(
			wp_next_scheduled(
				'digitalogic_currency_admin_async_apply',
				array( $job['job_id'], $job['generation'] )
			)
		);
	}

	/** A failed cron transport keeps a post-lock retry instead of exhausting attempts under the core lock. */
	public function test_currency_admin_async_retries_after_core_cron_lock_expires(): void {
		$GLOBALS['digitalogic_test_remote_post_results'][] = new WP_Error( 'transport_failed', 'transport failed' );
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501' );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 1, $job['dispatch_attempts'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
		for ( $poll = 0; $poll < 6; $poll++ ) {
			$this->assertFalse( $async->recover_queued_job() );
		}
		$stored = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 1, $stored['dispatch_attempts'] );

		delete_transient( 'doing_cron' );
		$stored['last_dispatch_at'] = time() - 66;
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );
		$GLOBALS['digitalogic_test_option_cache'] = array();

		$this->assertTrue( $async->recover_queued_job() );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
		$retried = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 2, $retried['dispatch_attempts'] );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_spawn_cron_calls'][1]['job_lock_balance'] );
		$this->assertSame( 1, digitalogic_test_run_spawned_cron() );

		$terminal = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'confirmed', $terminal['status'] );
		$this->assertSame( 1, $terminal['apply_attempts'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** A persistently unreachable runner makes an overdue queued job durably terminal, never indefinitely active. */
	public function test_currency_admin_async_overdue_queue_fails_closed_without_cron(): void {
		$GLOBALS['digitalogic_test_remote_post_results'][] = new WP_Error( 'transport_failed', 'transport failed' );
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501' );
		$this->assertFalse( is_wp_error( $job ) );

		$stored                      = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['deadline_at']       = time() - 1;
		$stored['dispatch_attempts'] = 6;
		$stored['last_dispatch_at']  = time();
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );
		$GLOBALS['digitalogic_test_option_cache'] = array();

		$this->assertTrue( $async->recover_queued_job() );
		$terminal = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'failed', $terminal['status'] );
		$this->assertSame( 'digitalogic_currency_async_deadline_exceeded', $terminal['error_code'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
	}

	/** An Action Scheduler-only action is not accepted when the prompt WP-Cron safety identity cannot be stored. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_currency_admin_async_fails_fast_when_prompt_safety_schedule_is_unavailable(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Isolated test process injects a provider that cannot guarantee prompt wake alone.
				'function as_schedule_single_action($timestamp, $hook, $args, $group, $unique) {'
				. '$GLOBALS["digitalogic_test_as_actions"][] = array("hook" => $hook, "args" => $args, "group" => $group);'
				. 'return count($GLOBALS["digitalogic_test_as_actions"]);}'
			);
		}
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Isolated test process verifies rollback of the optional provider copy.
				'function as_unschedule_all_actions($hook, $args, $group) {'
				. '$GLOBALS["digitalogic_test_as_actions"] = array_values(array_filter($GLOBALS["digitalogic_test_as_actions"], '
				. 'static fn($action) => $action["hook"] !== $hook || $action["args"] !== $args || $action["group"] !== $group));'
				. 'return 1;}'
			);
		}
		$GLOBALS['digitalogic_test_as_actions']       = array();
		$GLOBALS['digitalogic_test_schedule_failure'] = true;

		$result = Digitalogic_Currency_Admin_Async::instance()->enqueue( '29501' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_schedule_failed', $result->get_error_code() );
		$this->assertSame( 'failed', Digitalogic_Currency_Admin_Async::instance()->status()['status'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_as_actions'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
	}

	/** The native two-rate form queues one atomic job and never reprices in the POST request. */
	public function test_currency_admin_async_two_rate_submission_is_atomic_and_background_only(): void {
		$async        = Digitalogic_Currency_Admin_Async::instance();
		$before       = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$before_price = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$job          = $async->enqueue_currency(
			array(
				'dollar_price' => '188000',
				'yuan_price'   => '31000',
			),
			false,
			false,
			$before['state_revision'],
			'native_admin'
		);

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame(
			array(
				'dollar_price' => 188000,
				'yuan_price'   => 31000,
			),
			$job['desired_currency']
		);
		$this->assertSame( '187891', (string) $GLOBALS['digitalogic_test_options']['options_dollar_price'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( $before_price, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 188000, $status['confirmed_currency']['dollar_price'] );
		$this->assertSame( 31000, $status['confirmed_currency']['yuan_price'] );
		$this->assertSame( '188000', (string) $GLOBALS['digitalogic_test_options']['dollar_price'] );
		$this->assertSame( '188000', (string) $GLOBALS['digitalogic_test_options']['options_dollar_price'] );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['yuan_price'] );
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertNotSame( $before_price, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );
	}

	/** A changed CLI rate plus --recalculate is one synchronous apply, not a rejected reconcile or web-worker queue. */
	public function test_currency_cli_changed_rate_recalculate_is_synchronous_and_terminal(): void {
		$state        = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$before_price = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$result       = Digitalogic_Currency_Admin_Async::instance()->execute_cli_currency(
			array( 'yuan_price' => '31500' ),
			true,
			(string) $state['state_revision'],
			'cli-sync-changed-0001'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'confirmed', $result['status'] );
		$this->assertSame( 'apply', $result['mode'] );
		$this->assertSame( 'wp_cli_sync', $result['execution_mode'] );
		$this->assertSame( 1, $result['apply_attempts'] );
		$this->assertSame( 31500, $result['confirmed_currency']['yuan_price'] );
		$this->assertSame( '31500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertNotSame( $before_price, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$currency_worker_events = array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static fn( $event ) => in_array(
				(string) $event['hook'],
				array( 'digitalogic_currency_admin_async_apply', 'digitalogic_currency_admin_async_finalize' ),
				true
			)
		);
		$this->assertSame( array(), array_values( $currency_worker_events ) );
	}

	/** The public command blocks until the exact changed-rate transaction is confirmed. */
	public function test_currency_cli_command_changed_rate_recalculate_prints_terminal_result(): void {
		$command = new Digitalogic_CLI_Commands();

		try {
			$command->currency_update(
				array(),
				array(
					'cny'         => '31500',
					'recalculate' => true,
					'request-id'  => 'cli-command-changed-0001',
				)
			);
			$this->fail( 'WP_CLI::success should terminate the command.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'transaction confirmed', $exception->getMessage() );
		}

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 1, WP_CLI::$logs );
		$output = json_decode( WP_CLI::$logs[0], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'confirmed', $output['status'] );
		$this->assertSame( 'apply', $output['mode'] );
		$this->assertSame( 'wp_cli_sync', $output['execution_mode'] );
		$this->assertSame( '31500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** An expired async lease cannot be re-fenced while its original pricing connection still owns the lock. */
	public function test_currency_async_expired_lease_waits_for_original_pricing_lock_owner(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );

		$stored                   = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['status']         = 'running';
		$stored['owner_token']    = str_repeat( 'a', 32 );
		$stored['fence_token']    = str_repeat( 'b', 32 );
		$stored['fence']          = 1;
		$stored['apply_attempts'] = 1;
		$stored['lease_until']    = time() - 2;
		$stored['deadline_at']    = time() - 1;
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );
		$GLOBALS['digitalogic_test_option_cache']     = array();
		$GLOBALS['digitalogic_test_scheduled_events'] = array();

		$lock_name = Digitalogic_Excel_Pricing_Sync::coordination_lock_name( 'wp_' );
		$GLOBALS['wpdb']->used_locks[ $lock_name ] = 9999;
		$async->run_job( $job['job_id'], $job['generation'] );
		$renewed = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];

		$this->assertSame( 'running', $renewed['status'] );
		$this->assertSame( 1, $renewed['fence'] );
		$this->assertSame( 1, $renewed['apply_attempts'] );
		$this->assertGreaterThan( time(), $renewed['lease_until'] );
		$this->assertGreaterThan( $renewed['lease_until'], $renewed['deadline_at'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( 'digitalogic_currency_admin_async_watchdog', $GLOBALS['digitalogic_test_scheduled_events'][0]['hook'] );

		unset( $GLOBALS['wpdb']->used_locks[ $lock_name ] );
		$renewed['lease_until'] = time() - 1;
		update_option( 'digitalogic_currency_admin_async_job', $renewed, false );
		$GLOBALS['digitalogic_test_option_cache']     = array();
		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$async->run_watchdog( $job['job_id'], $job['generation'], 1 );
		$released = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];

		$this->assertSame( 'queued', $released['status'] );
		$this->assertSame( 1, $released['fence'] );
		$this->assertSame( 1, $released['apply_attempts'] );
		$this->assertNotEmpty(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static fn( $event ) => 'digitalogic_currency_admin_async_apply' === $event['hook']
			)
		);
	}

	/** A fully published terminal marker does not block a no-op successor or cause another actuation. */
	public function test_currency_admin_async_confirmed_job_allows_noop_successor_without_repricing(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$first = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $first ) );
		$async->run_job( $first['job_id'], $first['generation'] );
		$first_terminal = $async->status( $first['job_id'], $first['generation'] );
		$this->assertSame( 'confirmed', $first_terminal['status'] );
		$this->assertNotSame( '', $first_terminal['committed_state_revision'] );
		$price_before = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];

		$successor = $async->enqueue( '29501', false );

		$this->assertFalse( is_wp_error( $successor ) );
		$this->assertSame( (int) $first['generation'] + 1, $successor['generation'] );
		$this->assertNotSame( $first['job_id'], $successor['job_id'] );
		$this->assertSame( 'confirmed', $successor['status'] );
		$this->assertSame( 0, $successor['apply_attempts'] );
		$this->assertSame( $price_before, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertArrayNotHasKey(
			'effect_state_revision',
			$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job']
		);
		$currency_events = array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static fn( $event ) => str_starts_with( (string) $event['hook'], 'digitalogic_currency_admin_async_' )
		);
		$this->assertSame( array(), array_values( $currency_events ) );
	}

	/** A fully published terminal marker belongs to its generation and cannot block the next exact apply. */
	public function test_currency_admin_async_confirmed_job_allows_exact_apply_successor_generation(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$first = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $first ) );
		$async->run_job( $first['job_id'], $first['generation'] );
		$first_terminal = $async->status( $first['job_id'], $first['generation'] );
		$this->assertSame( 'confirmed', $first_terminal['status'] );
		$this->assertNotSame( '', $first_terminal['committed_state_revision'] );

		$state       = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$price_before = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$successor   = $async->enqueue_currency(
			array(
				'dollar_price' => (string) $state['settings']['dollar_price'],
				'yuan_price'   => '29502',
			),
			false,
			false,
			(string) $state['state_revision'],
			'sequential_acceptance'
		);

		$this->assertFalse( is_wp_error( $successor ) );
		$this->assertSame( (int) $first['generation'] + 1, $successor['generation'] );
		$this->assertNotSame( $first['job_id'], $successor['job_id'] );
		$this->assertSame( 'queued', $successor['status'] );
		$this->assertSame( 'apply', $successor['mode'] );
		$this->assertArrayNotHasKey(
			'effect_state_revision',
			$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job']
		);

		$async->run_job( $successor['job_id'], $successor['generation'] );
		$successor_terminal = $async->status( $successor['job_id'], $successor['generation'] );
		$this->assertSame( 'confirmed', $successor_terminal['status'] );
		$this->assertSame( 1, $successor_terminal['apply_attempts'] );
		$this->assertNotSame( $price_before, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '29502', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** A stale transition within one generation still cannot clear its committed effect marker. */
	public function test_currency_admin_async_same_generation_cannot_clear_commit_marker(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$async->run_job( $job['job_id'], $job['generation'] );
		$expected = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$marker   = (string) $expected['effect_state_revision'];
		$desired  = $expected;
		unset( $desired['effect_state_revision'], $desired['effect_committed_at'], $desired['committed_state_revision'] );
		$desired['status'] = 'queued';
		$method            = new ReflectionMethod( $async, 'store_job_open_lock' );

		$result = $method->invoke( $async, $desired, $expected );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_job_cas_conflict', $result->get_error_code() );
		$this->assertSame(
			$marker,
			$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job']['effect_state_revision']
		);
		$this->assertSame( 'confirmed', $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job']['status'] );
	}

	/** Only the exact next generation may replace a fully published terminal job. */
	public function test_currency_admin_async_terminal_successor_guard_rejects_unsafe_variants(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$async->run_job( $job['job_id'], $job['generation'] );
		$terminal = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$marker   = (string) $terminal['effect_state_revision'];
		$method   = new ReflectionMethod( $async, 'store_job_open_lock' );

		$successor               = $terminal;
		$successor['job_id']     = 'exact-successor-job';
		$successor['generation'] = (int) $terminal['generation'] + 1;
		$successor['status']     = 'queued';
		unset( $successor['effect_state_revision'], $successor['effect_committed_at'], $successor['committed_state_revision'] );

		$active_current           = $terminal;
		$active_current['status'] = 'running';

		$generation_jump               = $successor;
		$generation_jump['generation'] = (int) $terminal['generation'] + 2;

		$different_marker                          = $successor;
		$different_marker['effect_state_revision'] = $marker . '-different';

		$disallowed_status           = $successor;
		$disallowed_status['status'] = 'running';

		$cases = array(
			'active current job'        => array(
				'current' => $active_current,
				'desired' => $successor,
			),
			'generation jump'           => array(
				'current' => $terminal,
				'desired' => $generation_jump,
			),
			'different desired marker'  => array(
				'current' => $terminal,
				'desired' => $different_marker,
			),
			'disallowed desired status' => array(
				'current' => $terminal,
				'desired' => $disallowed_status,
			),
		);

		foreach ( $cases as $label => $case ) {
			$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $case['current'];
			$result = $method->invoke( $async, $case['desired'], $case['current'] );

			$this->assertTrue( is_wp_error( $result ), $label );
			$this->assertSame( 'digitalogic_currency_async_job_cas_conflict', $result->get_error_code(), $label );
			$this->assertSame(
				$case['current'],
				$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'],
				$label
			);
		}
	}

	/** REST currency writes are revision-bound, return quickly, and never reprice inline. */
	public function test_currency_rest_write_returns_async_job_without_inline_mutation(): void {
		$state        = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$before_price = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$response     = Digitalogic_REST_API::instance()->update_currency(
			new WP_REST_Request(
				array(),
				array(
					'yuan_price'              => '29501',
					'expected_state_revision' => $state['state_revision'],
					'request_id'              => 'rest-currency-0001',
				),
				array(
					'If-Match'       => '"' . $state['state_revision'] . '"',
					'Idempotency-Key' => 'rest-currency-0001',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['data']['status'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( $before_price, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );

		$job    = $response->get_data()['data'];
		$status = Digitalogic_REST_API::instance()->get_currency_job(
			new WP_REST_Request(
				array(
					'job_id'     => $job['job_id'],
					'generation' => $job['generation'],
				)
			)
		);
		$this->assertSame( 200, $status->get_status() );
		$this->assertSame( $job['job_id'], $status->get_data()['data']['job_id'] );
		$this->assertSame( $job['generation'], $status->get_data()['data']['generation'] );
		$this->assertSame( 'no-store', $status->get_headers()['Cache-Control'] );
	}

	/** Remote currency writes fail before enqueue unless body revision and If-Match agree. */
	public function test_currency_rest_write_requires_exact_if_match(): void {
		$state    = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$response = Digitalogic_REST_API::instance()->update_currency(
			new WP_REST_Request(
				array(),
				array(
					'yuan_price'              => '29501',
					'expected_state_revision' => $state['state_revision'],
				)
			)
		);

		$this->assertSame( 428, $response->get_status() );
		$this->assertSame( 'digitalogic_currency_if_match_required', $response->get_data()['code'] );
		$this->assertTrue( $response->get_data()['blocking'] );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
	}

	/** Explicit REST reconciliation also runs only through the fenced worker. */
	public function test_currency_rest_recalculate_returns_background_reconcile_job(): void {
		$state                                        = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$response                                     = Digitalogic_REST_API::instance()->recalculate_prices(
			new WP_REST_Request(
				array(),
				array(
					'expected_state_revision' => $state['state_revision'],
					'request_id'              => 'rest-reconcile-0001',
				),
				array(
					'If-Match'        => '"' . $state['state_revision'] . '"',
					'Idempotency-Key' => 'rest-reconcile-0001',
				)
			)
		);

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['data']['status'] );
		$this->assertSame( 'reconcile', $response->get_data()['data']['mode'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** An exact request identity replays its terminal before stale-state checks. */
	public function test_currency_admin_async_request_id_replays_terminal_without_second_effect(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$first = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ),
			false,
			false,
			(string) $state['state_revision'],
			'test',
			'currency-replay-0001'
		);
		$this->assertFalse( is_wp_error( $first ) );
		$async->run_job( $first['job_id'], $first['generation'] );
		$terminal = $async->status_by_request( 'currency-replay-0001' );
		$this->assertSame( 'confirmed', $terminal['status'] );
		$price_after = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];

		$replay = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ),
			false,
			false,
			(string) $state['state_revision'],
			'retry_transport',
			'currency-replay-0001'
		);

		$this->assertFalse( is_wp_error( $replay ) );
		$this->assertTrue( $replay['replayed'] );
		$this->assertSame( $first['job_id'], $replay['job_id'] );
		$this->assertSame( 1, $replay['apply_attempts'] );
		$this->assertSame( $price_after, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Reusing an idempotency identity for another intent fails closed. */
	public function test_currency_admin_async_request_id_conflict_is_blocking(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$first = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ), false, false, (string) $state['state_revision'], 'test', 'currency-conflict-0001'
		);
		$this->assertFalse( is_wp_error( $first ) );

		$conflict = $async->enqueue_currency(
			array( 'yuan_price' => '29502' ), false, false, (string) $state['state_revision'], 'test', 'currency-conflict-0001'
		);

		$this->assertTrue( is_wp_error( $conflict ) );
		$this->assertSame( 'digitalogic_currency_async_request_id_conflict', $conflict->get_error_code() );
		$this->assertTrue( $conflict->get_error_data()['blocking'] );
	}

	/** Queued cancellation is immediate, durable, idempotent, and effect-free. */
	public function test_currency_admin_async_queued_cancel_is_durable_and_idempotent(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$job   = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ), false, false, (string) $state['state_revision'], 'test', 'currency-cancel-0001'
		);
		$this->assertSame( 'queued', $job['status'] );

		$cancelled = $async->cancel( '', 0, 'currency-cancel-0001' );
		$replayed  = $async->cancel( '', 0, 'currency-cancel-0001' );

		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 'digitalogic_currency_async_cancelled', $cancelled['error_code'] );
		$this->assertFalse( $cancelled['cancellable'] );
		$this->assertSame( 'cancelled', $replayed['status'] );
		$this->assertTrue( $replayed['replayed'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_scheduled_events'] );
	}

	/** A running cancellation is observed by the transactional fence and rolls back. */
	public function test_currency_admin_async_running_cancel_rolls_back_before_effect(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$job   = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ), false, false, (string) $state['state_revision'], 'test', 'currency-cancel-running-0001'
		);
		add_action(
			'digitalogic_currency_async_worker_claimed',
			static function () use ( $async ) {
				$result = $async->cancel( '', 0, 'currency-cancel-running-0001' );
				$GLOBALS['digitalogic_test_running_cancel'] = $result;
			}
		);

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status_by_request( 'currency-cancel-running-0001' );

		$this->assertSame( 'cancelling', $GLOBALS['digitalogic_test_running_cancel']['status'] );
		$this->assertSame( 'cancelled', $status['status'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Historical request aliases remain immutable after a successor is admitted. */
	public function test_currency_admin_async_historical_request_replays_after_successor(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$first = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ), false, false, (string) $state['state_revision'], 'test', 'currency-history-0001'
		);
		$async->run_job( $first['job_id'], $first['generation'] );
		$next_state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$second     = $async->enqueue_currency(
			array( 'yuan_price' => '29502' ), false, false, (string) $next_state['state_revision'], 'test', 'currency-history-0002'
		);
		$this->assertSame( 'queued', $second['status'] );

		$historical = $async->status_by_request( 'currency-history-0001' );
		$replay     = $async->enqueue_currency(
			array( 'yuan_price' => '29501' ), false, false, (string) $state['state_revision'], 'retry', 'currency-history-0001'
		);

		$this->assertSame( 'confirmed', $historical['status'] );
		$this->assertTrue( $historical['replayed'] );
		$this->assertSame( $first['job_id'], $replay['job_id'] );
		$this->assertSame( $second['job_id'], $async->status()['job_id'] );
	}

	/** A stale native page cannot overwrite a newer canonical pricing revision. */
	public function test_currency_admin_async_two_rate_submission_requires_current_page_revision(): void {
		$async   = Digitalogic_Currency_Admin_Async::instance();
		$state   = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$changed = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'concurrent_writer'
		);
		$this->assertFalse( is_wp_error( $changed ) );

		$result = $async->enqueue_currency(
			array(
				'dollar_price' => '188000',
				'yuan_price'   => '31000',
			),
			false,
			false,
			$state['state_revision'],
			'native_admin'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_state_revision_conflict', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** No mutation surface can bypass the canonical optimistic revision. */
	public function test_currency_admin_async_core_requires_exact_state_revision(): void {
		$result = Digitalogic_Currency_Admin_Async::instance()->enqueue_currency(
			array( 'yuan_price' => '29501' ),
			false,
			false
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_expected_revision_required', $result->get_error_code() );
		$this->assertSame( 428, $result->get_error_data()['status'] );
		$this->assertTrue( $result->get_error_data()['blocking'] );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
	}

	/** The authenticated ACF request can claim its job directly without WP-Cron latency. */
	public function test_currency_admin_async_direct_enqueue_does_not_depend_on_cron_dispatch(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 0, $job['dispatch_attempts'] );
		$this->assertEmpty( $GLOBALS['digitalogic_test_remote_posts'] );

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status();
		$this->assertContains( $status['status'], array( 'awaiting_excel', 'confirmed' ) );
		$this->assertSame( 1, $status['apply_attempts'] );
	}

	/** The background ACF job flushes its revision event after releasing the pricing lock. */
	public function test_currency_admin_async_job_wakes_excel_without_waiting_for_cron(): void {
		$redis = new Digitalogic_Test_Redis_Client();
		add_filter(
			'digitalogic_panel_redis_client',
			static function () use ( $redis ) {
				return $redis;
			}
		);
		Digitalogic_Pricing_Snapshot::instance();
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501' );

		$this->assertFalse( is_wp_error( $job ) );
		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status();
		$this->assertContains( $status['status'], array( 'awaiting_excel', 'confirmed' ) );

		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 2, $events );
		$this->assertSame( array( 'pricing.source.changed', 'pricing.state.changed' ), array_column( $events, 'name' ) );
		$this->assertCount( 2, $redis->published );
	}

	/** A transient product lock yields one bounded retry action instead of sleeping in one worker. */
	public function test_currency_admin_async_job_retries_transient_product_sync_lock(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501' );
		$this->assertFalse( is_wp_error( $job ) );

		// Job mutex, pricing mutex, then a contended product-sync mutex.
		$GLOBALS['wpdb']->acquire_results = array( 1, 1, 0 );
		$async->run_job( $job['job_id'], $job['generation'] );
		$retry = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'queued', $retry['status'] );
		$this->assertSame( 1, $retry['apply_attempts'] );
		$this->assertSame( 'digitalogic_product_sync_busy', $retry['error_code'] );
		$this->assertGreaterThan( time(), $retry['next_attempt_at'] );

		$stored                    = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['next_attempt_at'] = time();
		$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $stored;

		$GLOBALS['digitalogic_test_option_cache'] = array();

		$GLOBALS['wpdb']->acquire_results = array( 1, 1, 1 );
		$async->run_job( $job['job_id'], $job['generation'] );

		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 2, $status['apply_attempts'] );
		$this->assertStringNotContainsString( 'ثبت نرخ کامل نشد', $status['message_fa'] );
	}

	/** Missing JavaScript and a rotated ACF key still queue by semantic option identity. */
	public function test_acf_yuan_semantic_fallback_queues_and_returns_confirmed_value(): void {
		$async                                       = Digitalogic_Currency_Admin_Async::instance();
		$state                                       = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$_POST['acf']                                = array( 'field_rotated_without_javascript' => '29501' );
		$_POST['digitalogic_pricing_state_revision'] = $state['state_revision'];
		$value                                       = $async->route_acf_currency_update(
			'29501',
			'options',
			array(
				'name' => 'options_yuan_price',
				'key'  => 'field_rotated_without_javascript',
			)
		);
		do_action( 'acf/save_post', 'options' );

		$this->assertSame( '29500', $value );
		$job = $async->status();
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( array( 'yuan_price' => 29501 ), $job['desired_currency'] );
		$this->assertSame( 29500, Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings()['yuan_price'] );

		// Even if the ACF name variation no longer fires, the canonical option
		// write is intercepted before the legacy synchronous coordinator guard.
		$filtered = apply_filters(
			'pre_update_option_options_yuan_price',
			'29501',
			'29500',
			'options_yuan_price'
		);
		$this->assertSame( '29500', $filtered );
		$this->assertSame( $job['job_id'], $async->status()['job_id'] );
		$this->assertSame( $job['generation'], $async->status()['generation'] );

		$unrelated = $async->route_acf_currency_update(
			'changed elsewhere',
			'options',
			array(
				'name' => 'unrelated_field',
				'key'  => 'field_unrelated',
			)
		);
		$this->assertSame( 'changed elsewhere', $unrelated );
		$this->assertSame( $job['generation'], $async->status()['generation'] );
	}

	/** ACF cannot round-trip a legacy YYMMDD option through an epoch-era UI date. */
	public function test_acf_epoch_date_projection_is_repaired_and_never_enqueued(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$_GET['page'] = 'currency-settings';
		$this->assertSame(
			'20260721',
			$async->load_acf_effective_date(
				'19700104',
				'options',
				array( 'name' => 'update_date' )
			)
		);
		$late_contamination = static function ( $field ) {
			if ( is_array( $field ) && 'update_date' === (string) ( $field['_name'] ?? '' ) ) {
				$field['value'] = '19700104';
			}

			return $field;
		};
		$prepared = apply_filters(
			'acf/pre_render_field',
			array(
				'_name' => 'update_date',
				'name'  => 'acf[field_date_rotated]',
				'value' => '19700104',
			),
			'options'
		);
		add_filter( 'acf/prepare_field', $late_contamination, 100 );
		$prepared = apply_filters( 'acf/prepare_field', $prepared );
		remove_filter( 'acf/prepare_field', $late_contamination );
		$this->assertSame( '20260721', $prepared['value'] );
		$options_alias = apply_filters(
			'acf/pre_render_field',
			array(
				'_name' => 'options_update_date',
				'name'  => 'acf[field_date_rotated]',
				'value' => '19700104',
			),
			'options'
		);
		$this->assertSame(
			'20260721',
			apply_filters( 'acf/prepare_field', $options_alias )['value']
		);
		$this->assertSame(
			'20260721',
			apply_filters(
				'acf/pre_render_field',
				array(
					'_name'    => 'update_date',
					'_prepare' => true,
					'name'     => 'acf[field_date_rotated]',
					'value'    => '19700104',
				),
				'options'
			)['value']
		);
		$unrelated_post = apply_filters(
			'acf/pre_render_field',
			array(
				'_name' => 'update_date',
				'name'  => 'acf[field_unrelated]',
				'value' => '19700104',
			),
			901
		);
		$unrelated_post = apply_filters( 'acf/prepare_field', $unrelated_post );
		$this->assertSame( '19700104', $unrelated_post['value'] );
		$_GET['page'] = 'post.php';
		$this->assertSame(
			'19700104',
			apply_filters(
				'acf/prepare_field',
				array(
					'_name' => 'update_date',
					'name'  => 'acf[field_unrelated]',
					'value' => '19700104',
				)
			)['value']
		);
		$_GET['page'] = 'currency-settings';
		$GLOBALS['digitalogic_test_option_cache']['options_update_date'] = '260720';
		$this->assertSame(
			'20260721',
			$async->load_acf_effective_date( '19700104', 'options', array( 'name' => 'update_date' ) )
		);
		$GLOBALS['digitalogic_test_option_cache'] = array();
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION ] = array(
			'effective_date'     => '2026-07-21',
			'usd_effective_date' => '2026-07-21',
			'cny_effective_date' => '2026-07-21',
		);
		$GLOBALS['digitalogic_test_options']['options_update_date'] = 'invalid';
		$GLOBALS['digitalogic_test_option_cache']                   = array();
		$this->assertSame(
			'20260721',
			$async->load_acf_effective_date( '19700104', 'options', array( 'name' => 'update_date' ) )
		);
		unset( $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION ] );
		$GLOBALS['digitalogic_test_options']['options_update_date'] = '260721';
		$GLOBALS['digitalogic_test_option_cache']                   = array();

		$_POST['acf']                                = array(
			'field_cny_rotated'  => '29501',
			'field_date_rotated' => '19700104',
		);
		$_POST['digitalogic_pricing_state_revision'] = $state['state_revision'];
		$async->route_acf_currency_update(
			'29501',
			'options',
			array( 'name' => 'yuan_price' )
		);
		$async->route_acf_currency_update(
			'19700104',
			'options',
			array( 'name' => 'update_date' )
		);
		do_action( 'acf/save_post', 'options' );

		$job = $async->status();
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( '2026-07-21', $job['desired_currency']['effective_date'] );
		$this->assertSame( '260721', (string) $GLOBALS['digitalogic_test_options']['options_update_date'] );
	}

	/** A normal browserless ACF Ymd submission survives rotated field keys and queues an ISO effective date. */
	public function test_acf_strict_ymd_submission_queues_canonical_iso_without_javascript(): void {
		$async                                       = Digitalogic_Currency_Admin_Async::instance();
		$state                                       = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$_POST['acf']                                = array(
			'field_cny_rotated'  => '31500',
			'field_date_rotated' => '20260831',
		);
		$_POST['digitalogic_pricing_state_revision'] = $state['state_revision'];

		$this->assertSame(
			'29500',
			$async->route_acf_currency_update( '31500', 'options', array( 'name' => 'yuan_price' ) )
		);
		$this->assertSame(
			'260721',
			$async->route_acf_currency_update( '20260831', 'options', array( 'name' => 'update_date' ) )
		);
		do_action( 'acf/save_post', 'options' );

		$job = $async->status();
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 31500, $job['desired_currency']['yuan_price'] );
		$this->assertSame( '2026-08-31', $job['desired_currency']['effective_date'] );
		$this->assertSame( '260721', (string) $GLOBALS['digitalogic_test_options']['options_update_date'] );
	}

	/** Malformed ACF Ymd remains fail-closed and leaves the confirmed rate/date untouched. */
	public function test_acf_invalid_ymd_submission_reports_issue_without_job_or_write(): void {
		$async                                              = Digitalogic_Currency_Admin_Async::instance();
		$state                                              = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$GLOBALS['digitalogic_test_current_user_id']         = 1;
		$_POST['acf']                                       = array(
			'field_cny_rotated'  => '31500',
			'field_date_rotated' => '20260230',
		);
		$_POST['digitalogic_pricing_state_revision']        = $state['state_revision'];

		$async->route_acf_currency_update( '31500', 'options', array( 'name' => 'yuan_price' ) );
		$async->route_acf_currency_update( '20260230', 'options', array( 'name' => 'update_date' ) );
		do_action( 'acf/save_post', 'options' );

		$this->assertSame( 'idle', $async->status()['status'] );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '260721', (string) $GLOBALS['digitalogic_test_options']['options_update_date'] );
		$issue = get_transient( 'digitalogic_currency_async_issue_1' );
		$this->assertSame( 'digitalogic_currency_async_effective_date_invalid', $issue['code'] );
		$this->assertSame( 'تاریخ مؤثر نرخ معتبر نیست.', $issue['message_fa'] );
	}

	/** One ACF request collects USD, CNY, and date semantically and queues exactly once. */
	public function test_acf_complete_settings_form_queues_one_atomic_job_after_all_fields(): void {
		$async                                       = Digitalogic_Currency_Admin_Async::instance();
		$state                                       = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$_POST['acf']                                = array(
			'field_usd_rotated'  => '188000',
			'field_cny_rotated'  => '31000',
			'field_date_rotated' => '260727',
		);
		$_POST['digitalogic_pricing_state_revision'] = $state['state_revision'];

		$this->assertSame(
			'187891',
			$async->route_acf_currency_update(
				'188000',
				'options',
				array(
					'name' => 'dollar_price',
					'key'  => 'field_usd_rotated',
				)
			)
		);
		$this->assertSame(
			'29500',
			$async->route_acf_currency_update(
				'31000',
				'options',
				array(
					'name' => 'yuan_price',
					'key'  => 'field_cny_rotated',
				)
			)
		);
		$this->assertSame(
			'260721',
			$async->route_acf_currency_update(
				'260727',
				'options',
				array(
					'name' => 'update_date',
					'key'  => 'field_date_rotated',
				)
			)
		);
		$this->assertSame( 'idle', $async->status()['status'] );

		do_action( 'acf/save_post', 'options' );
		$job = $async->status();
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame(
			array(
				'dollar_price'   => 188000,
				'effective_date' => '2026-07-27',
				'yuan_price'     => 31000,
			),
			$job['desired_currency']
		);
		$apply_actions = array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static function ( $event ) {
				return 'digitalogic_currency_admin_async_apply' === $event['hook'];
			}
		);
		$this->assertCount( 1, $apply_actions );
		$this->assertSame( '187891', (string) $GLOBALS['digitalogic_test_options']['options_dollar_price'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '260721', (string) $GLOBALS['digitalogic_test_options']['options_update_date'] );
	}

	/** A stale ACF form cannot restore older rates after a newer writer commits. */
	public function test_acf_stale_form_is_rejected_before_enqueue(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 42;
		$async                                       = Digitalogic_Currency_Admin_Async::instance();
		$stale                                       = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$updated                                     = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'concurrent_writer'
		);
		$this->assertFalse( is_wp_error( $updated ) );
		$_POST['acf']                                = array( 'field_cny_rotated' => '29500' );
		$_POST['digitalogic_pricing_state_revision'] = $stale['state_revision'];

		$async->route_acf_currency_update(
			'29500',
			'options',
			array(
				'name' => 'yuan_price',
				'key'  => 'field_cny_rotated',
			)
		);
		do_action( 'acf/save_post', 'options' );

		$this->assertSame( 'idle', $async->status()['status'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$issue = get_transient( 'digitalogic_currency_async_issue_42' );
		$this->assertSame( 'digitalogic_currency_async_state_revision_conflict', $issue['code'] );
	}

	/** A server-side enqueue failure survives the ACF redirect as an operator notice. */
	public function test_acf_missing_javascript_enqueue_failure_is_not_silent(): void {
		$GLOBALS['digitalogic_test_current_user_id']  = 42;
		$GLOBALS['digitalogic_test_schedule_failure'] = true;
		$async                                        = Digitalogic_Currency_Admin_Async::instance();
		$state                                        = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		$_POST['acf']                                 = array( 'field_changed_identity' => '29501' );
		$_POST['digitalogic_pricing_state_revision']  = $state['state_revision'];

		$value = $async->route_acf_currency_update(
			'29501',
			'options',
			array(
				'name' => 'yuan_price',
				'key'  => 'field_changed_identity',
			)
		);
		do_action( 'acf/save_post', 'options' );

		$this->assertSame( '29500', $value );
		$issue = get_transient( 'digitalogic_currency_async_issue_42' );
		$this->assertIsArray( $issue );
		$this->assertSame( 'digitalogic_currency_async_schedule_failed', $issue['code'] );
		$this->assertNotSame( '', $issue['message_fa'] );
		$this->assertSame( 'failed', $async->status()['status'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** Raw managed USD option writes also queue before the legacy synchronous guard. */
	public function test_currency_option_fail_safe_covers_both_currency_aliases(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$value = apply_filters(
			'pre_update_option_options_dollar_price',
			'188000',
			'187891',
			'options_dollar_price'
		);

		$this->assertSame( '187891', $value );
		$job = $async->status();
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( array( 'dollar_price' => 188000 ), $job['desired_currency'] );
		$this->assertSame( '187891', (string) $GLOBALS['digitalogic_test_options']['options_dollar_price'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** A same-value submission is terminal, idempotent, and has no worker side effects. */
	public function test_acf_same_confirmed_value_is_terminal_and_side_effect_free(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29500' );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'confirmed', $job['status'] );
		$this->assertSame( 0, $job['apply_attempts'] );
		$this->assertSame( 100, $job['progress'] );
		$this->assertEmpty( $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertEmpty( $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertArrayNotHasKey( 'owner_token', $job );
		$this->assertArrayNotHasKey( 'fence_token', $job );

		$before = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$async->run_job( $job['job_id'], $job['generation'] );
		$this->assertSame( $before, $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** An explicit same-rate reconcile exercises the worker without changing the business rate. */
	public function test_acf_same_rate_reconcile_repairs_prices_without_rate_change(): void {
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] = '1';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price']    = '2';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_price']         = '1';
		$GLOBALS['digitalogic_test_wc_products']                          = array();
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29500', false, true );

		$this->assertFalse( is_wp_error( $job ) );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 'reconcile', $job['mode'] );
		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 'reconcile', $status['mode'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertEmpty( $GLOBALS['digitalogic_test_actions']['digitalogic_pricing_confirmation_event'] ?? array() );
	}

	/** Reconcile mode cannot be used to smuggle a different business rate. */
	public function test_acf_reconcile_rejects_rate_mismatch_without_job(): void {
		$result = Digitalogic_Currency_Admin_Async::instance()->enqueue( '29501', false, true );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_reconcile_rate_mismatch', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** Status polling observes durable state without writes, locks, schedules, or wake-ups. */
	public function test_currency_admin_async_status_is_read_only(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501' );
		$this->assertFalse( is_wp_error( $job ) );

		$options_before  = $GLOBALS['digitalogic_test_options'];
		$events_before   = $GLOBALS['digitalogic_test_scheduled_events'];
		$remote_before   = $GLOBALS['digitalogic_test_remote_posts'];
		$acquires_before = $GLOBALS['wpdb']->acquire_count;
		$releases_before = $GLOBALS['wpdb']->release_count;
		$first           = $async->status( $job['job_id'], $job['generation'] );
		$second          = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( $first, $second );
		$this->assertSame( $options_before, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( $events_before, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( $remote_before, $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertSame( $acquires_before, $GLOBALS['wpdb']->acquire_count );
		$this->assertSame( $releases_before, $GLOBALS['wpdb']->release_count );
	}

	/** Init/status repair a crash-gap queue with exact, non-duplicated action identities. */
	public function test_currency_admin_async_repairs_orphaned_queue_schedule_idempotently(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );

		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$GLOBALS['digitalogic_test_remote_posts']     = array();
		$stored                                       = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['dispatch_attempts']                  = 0;
		$stored['last_dispatch_at']                   = 0;
		$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $stored;
		$GLOBALS['digitalogic_test_option_cache']                                    = array();

		$this->assertTrue( $async->recover_queued_job() );
		$events = $GLOBALS['digitalogic_test_scheduled_events'];
		$this->assertCount( 2, $events );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertCount(
			1,
			array_filter(
				$events,
				static fn( $event ) => 'digitalogic_currency_admin_async_apply' === $event['hook']
					&& array( $job['job_id'], $job['generation'] ) === $event['args']
			)
		);
		$this->assertCount(
			1,
			array_filter(
				$events,
				static fn( $event ) => 'digitalogic_currency_admin_async_watchdog' === $event['hook']
					&& array( $job['job_id'], $job['generation'], 0 ) === $event['args']
			)
		);

		$GLOBALS['digitalogic_test_scheduled_events'] = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static fn( $event ) => 'digitalogic_currency_admin_async_watchdog' !== $event['hook']
			)
		);
		$events_before                                = count( $GLOBALS['digitalogic_test_scheduled_events'] );
		$remote_before                                = count( $GLOBALS['digitalogic_test_remote_posts'] );
		$status                                       = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'queued', $status['status'] );
		$this->assertCount( $events_before + 1, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( $remote_before, count( $GLOBALS['digitalogic_test_remote_posts'] ) );
		$this->assertCount(
			1,
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static fn( $event ) => 'digitalogic_currency_admin_async_apply' === $event['hook']
					&& array( $job['job_id'], $job['generation'] ) === $event['args']
			)
		);

		$events_after = $GLOBALS['digitalogic_test_scheduled_events'];
		$async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( $events_after, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( $remote_before, count( $GLOBALS['digitalogic_test_remote_posts'] ) );
	}

	/** Anonymous bootstrap hides historical terminal jobs; exact clients can still read them. */
	public function test_currency_admin_async_active_status_hides_historical_terminal_job(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29500' );

		$this->assertSame( 'confirmed', $job['status'] );
		$this->assertSame( 'idle', $async->status( '', 0, true )['status'] );
		$this->assertSame( 'confirmed', $async->status( $job['job_id'], $job['generation'] )['status'] );
		$this->assertSame( 'invalid_identity', $async->status( $job['job_id'], 0 )['status'] );
	}

	/** Missing generations are safety no-ops for both worker and watchdog. */
	public function test_currency_admin_async_generation_is_mandatory_for_actuation(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$before_price = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];

		$before = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$async->run_job( $job['job_id'] );
		$async->run_watchdog( $job['job_id'] );

		$this->assertSame( $before, $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( $before_price, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** A transaction that outlives its UI deadline makes new saves fail fast, never block on its row lock. */
	public function test_currency_admin_async_overdue_running_job_rejects_new_save_without_overwrite(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$stored                = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['status']      = 'running';
		$stored['owner_token'] = str_repeat( 'a', 32 );
		$stored['fence_token'] = str_repeat( 'b', 32 );
		$stored['fence']       = 3;
		$stored['lease_until'] = time() - 1;
		$stored['deadline_at'] = time() - 1;
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );

		$started = microtime( true );
		$result  = $async->enqueue( '29502', false );
		$elapsed = microtime( true ) - $started;

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_worker_still_running', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['retry_after'] );
		$this->assertLessThan( 0.5, $elapsed );
		$this->assertSame( $stored, $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] );
		$observed = $async->status( '', 0, true );
		$this->assertSame( 'failed', $observed['status'] );
		$this->assertSame( 'digitalogic_currency_async_observed_deadline_exceeded', $observed['error_code'] );
	}

	/** Action Scheduler store failures fall back to exact WP-Cron actions and cannot orphan a job. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_currency_admin_async_action_scheduler_exceptions_fall_back_and_cleanup(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Isolated test process injects the provider exception path.
				'function as_schedule_single_action($timestamp, $hook, $args, $group, $unique) {'
				. 'throw new RuntimeException("scheduler store unavailable");}'
			);
		}
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Isolated test process injects the provider exception path.
				'function as_unschedule_all_actions($hook, $args, $group) {'
				. 'throw new RuntimeException("scheduler cleanup unavailable");}'
			);
		}

		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$this->assertNotFalse(
			wp_next_scheduled(
				'digitalogic_currency_admin_async_apply',
				array( $job['job_id'], $job['generation'] )
			)
		);

		$async->run_job( $job['job_id'], $job['generation'] );
		$this->assertSame( 'confirmed', $async->status( $job['job_id'], $job['generation'] )['status'] );
		$hooks = array_column( $GLOBALS['digitalogic_test_scheduled_events'], 'hook' );
		$this->assertNotContains( 'digitalogic_currency_admin_async_apply', $hooks );
		$this->assertNotContains( 'digitalogic_currency_admin_async_watchdog', $hooks );
	}

	/** An unrelated explicit Excel ACK cannot make an admin-origin job wait or roll back. */
	public function test_currency_admin_async_ignores_unrelated_excel_confirmation(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$transaction_id = 'ptx_' . str_repeat( 'a', 32 );
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::CONFIRMATIONS_OPTION ] = array(
			'active'       => $transaction_id,
			'transactions' => array(
				$transaction_id => array(
					'transaction_id' => $transaction_id,
					'status'         => 'awaiting_ack',
					'ack_deadline'   => time() + 90,
				),
			),
		);
		$GLOBALS['digitalogic_test_option_cache'] = array();

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 29501, $status['confirmed_currency']['yuan_price'] );
		$ledger = $GLOBALS['digitalogic_test_options'][ Digitalogic_Excel_Pricing_Sync::CONFIRMATIONS_OPTION ];
		$this->assertNull( $ledger['active'] );
		$this->assertSame( 'superseded', $ledger['transactions'][ $transaction_id ]['status'] );
		$this->assertSame( 'admin_async', $ledger['transactions'][ $transaction_id ]['superseded_by_source'] );
		Digitalogic_Excel_Pricing_Sync::instance()->run_confirmation_timeout( $transaction_id );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** A contended job mutex fails quickly and cannot create a partial job. */
	public function test_currency_admin_async_claim_lock_contention_is_side_effect_free(): void {
		$GLOBALS['wpdb']->acquire_result = 0;
		$result                          = Digitalogic_Currency_Admin_Async::instance()->enqueue( '29501' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_currency_async_job_lock_busy', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['retry_after'] );
		$this->assertArrayNotHasKey( 'digitalogic_currency_admin_async_job', $GLOBALS['digitalogic_test_options'] );
		$this->assertEmpty( $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertEmpty( $GLOBALS['digitalogic_test_remote_posts'] );
	}

	/** The watchdog makes an overdue job terminal and removes every async action. */
	public function test_currency_admin_async_watchdog_terminalizes_and_clears_schedules(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$stored                = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['deadline_at'] = time() - 1;
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );

		$async->run_watchdog( $job['job_id'], $job['generation'], 0 );
		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'digitalogic_currency_async_deadline_exceeded', $status['error_code'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$hooks = array_column( $GLOBALS['digitalogic_test_scheduled_events'], 'hook' );
		$this->assertNotContains( 'digitalogic_currency_admin_async_apply', $hooks );
		$this->assertNotContains( 'digitalogic_currency_admin_async_watchdog', $hooks );
	}

	/** An active lease rejects a duplicate worker before pricing actuation. */
	public function test_currency_admin_async_active_lease_rejects_duplicate_worker(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );

		$stored                   = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['status']         = 'running';
		$stored['owner_token']    = str_repeat( 'a', 32 );
		$stored['fence_token']    = str_repeat( 'b', 32 );
		$stored['fence']          = 4;
		$stored['lease_until']    = time() + 60;
		$stored['apply_attempts'] = 1;
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 4, $status['fence'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** An expired lease is reclaimed with a strictly newer fence. */
	public function test_currency_admin_async_expired_lease_is_reclaimed_with_new_fence(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );

		$stored                   = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['status']         = 'running';
		$stored['owner_token']    = str_repeat( 'a', 32 );
		$stored['fence_token']    = str_repeat( 'b', 32 );
		$stored['fence']          = 4;
		$stored['lease_until']    = time() - 1;
		$stored['apply_attempts'] = 1;
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );
		$claimed = array();
		add_action(
			'digitalogic_currency_async_worker_claimed',
			static function ( $projection ) use ( &$claimed ) {
				$claimed = $projection;
			},
			10,
			1
		);

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 5, $claimed['fence'] );
		$this->assertSame( 2, $claimed['apply_attempts'] );
		$this->assertArrayNotHasKey( 'owner_token', $claimed );
		$this->assertArrayNotHasKey( 'fence_token', $claimed );
		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 5, $status['fence'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** An exact fenced worker may commit after its lease clock passes if no takeover occurred. */
	public function test_currency_admin_async_long_worker_keeps_exact_fence_through_commit(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		add_action(
			'digitalogic_currency_async_worker_claimed',
			static function () {
				$GLOBALS['wpdb']->after_option_write = static function ( $database, $option_name ) {
					unset( $database, $option_name );
					$current                = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
					$current['lease_until'] = time() - 1;
					$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $current;
				};
			},
			10,
			1
		);

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertNotContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A stale watchdog CAS can never erase a commit marker written while it waited. */
	public function test_currency_admin_async_watchdog_cas_preserves_concurrent_commit_marker(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$stored                = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$stored['status']      = 'running';
		$stored['owner_token'] = str_repeat( 'a', 32 );
		$stored['fence_token'] = str_repeat( 'b', 32 );
		$stored['fence']       = 4;
		$stored['lease_until'] = time() - 1;
		$stored['deadline_at'] = time() + 60;
		$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $stored;
		$GLOBALS['wpdb']->before_currency_job_cas                                    = static function () {
			$current                          = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
			$current['effect_state_revision'] = 'sha256:' . str_repeat( 'c', 64 );
			$current['effect_committed_at']   = time();
			$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $current;
		};

		$async->run_watchdog( $job['job_id'], $job['generation'], 4 );
		$after = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];

		$this->assertSame( 'running', $after['status'] );
		$this->assertSame( 'sha256:' . str_repeat( 'c', 64 ), $after['effect_state_revision'] );
		$this->assertSame( str_repeat( 'a', 32 ), $after['owner_token'] );
	}

	/** Fence loss after claim prevents the stale worker from entering the coordinator. */
	public function test_currency_admin_async_fence_loss_before_actuation_is_rejected(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$job_lock_released = false;
		add_action(
			'digitalogic_currency_async_worker_claimed',
			static function () use ( &$job_lock_released ) {
				$job_lock_released      = $GLOBALS['wpdb']->release_count >= 2;
				$current                = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
				$current['fence_token'] = str_repeat( 'f', 32 );
				// Bypass the WordPress option cache deliberately: the transactional
				// guard must read the authoritative DB row, not its stale cached claim.
				$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $current;
			}
		);

		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertTrue( $job_lock_released );
		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A committed effect marker survives a worker crash and finalizes without a second reprice. */
	public function test_currency_admin_async_commit_marker_closes_completion_crash_window(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		add_action(
			'digitalogic_currency_async_worker_before_complete',
			static function () {
				$GLOBALS['wpdb']->acquire_result = 0;
			}
		);

		$async->run_job( $job['job_id'], $job['generation'] );
		$stored = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 'running', $stored['status'] );
		$this->assertMatchesRegularExpression( '/\Asha256:[a-f0-9]{64}\z/D', $stored['effect_state_revision'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$price_after_commit  = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$events_after_commit = count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );

		$GLOBALS['wpdb']->acquire_result = 1;
		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( $stored['effect_state_revision'], $status['committed_state_revision'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( $events_after_commit + 1, count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() ) );
		$stored_after = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 'published', $stored_after['effect_publication']['status'] );
		$this->assertSame( $stored_after['effect_id'], $stored_after['effect_publication']['payload']['effect_id'] );
	}

	/** A crash immediately after SQL COMMIT recovers publication without repricing again. */
	public function test_currency_admin_async_recovers_crash_before_post_commit_publication(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$GLOBALS['wpdb']->after_commit = static function ( $database ) {
			$database->acquire_result = 0;
			throw new RuntimeException( 'simulated process loss after COMMIT' );
		};

		$async->run_job( $job['job_id'], $job['generation'] );
		$stored = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 'running', $stored['status'] );
		$this->assertSame( 'pending', $stored['effect_publication']['status'] );
		$this->assertSame( array( 901 ), $stored['effect_publication']['payload']['cache_plan']['product_ids'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertCount( 0, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$price_after_commit = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];

		$GLOBALS['wpdb']->acquire_result                   = 1;
		$GLOBALS['digitalogic_test_cache_deletes']         = array();
		$GLOBALS['digitalogic_test_cache_delete_multiple'] = array();
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
		$this->assertSame( 'published', $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job']['effect_publication']['status'] );
		$this->assertContains( array( 'options_yuan_price', 'options' ), $GLOBALS['digitalogic_test_cache_deletes'] );
		$this->assertContains(
			array(
				'keys'  => array( 901 ),
				'group' => 'post_meta',
			),
			$GLOBALS['digitalogic_test_cache_delete_multiple']
		);
	}

	/** A due committed finalizer retries after the core cron lock and completes without repricing. */
	public function test_currency_admin_async_status_kicks_post_commit_recovery_without_repricing(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$GLOBALS['wpdb']->after_commit = static function ( $database ) {
			$database->acquire_result = 0;
			throw new RuntimeException( 'simulated process loss after COMMIT' );
		};
		$async->run_job( $job['job_id'], $job['generation'] );
		$stored = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 1, $stored['apply_attempts'] );
		$this->assertSame( 'pending', $stored['effect_publication']['status'] );
		$price_after_commit = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];

		$GLOBALS['wpdb']->acquire_result              = 1;
		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$GLOBALS['digitalogic_test_remote_posts']     = array();
		$GLOBALS['digitalogic_test_spawn_cron_calls'] = array();
		$GLOBALS['digitalogic_test_remote_post_results'][] = new WP_Error( 'transport_failed', 'transport failed' );
		$observed                                     = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'publishing', $observed['status'] );
		$this->assertSame( 90, $observed['progress'] );
		$this->assertSame( 1, $observed['apply_attempts'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
		$finalizers = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static fn( $event ) => 'digitalogic_currency_admin_async_finalize' === (string) ( $event['hook'] ?? '' )
			)
		);
		$this->assertCount( 1, $finalizers );
		$this->assertLessThanOrEqual( time() + 2, (int) $finalizers[0]['timestamp'] );
		for ( $poll = 0; $poll < 6; $poll++ ) {
			$this->assertTrue( $async->recover_committed_publication() );
		}
		$pending = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 1, $pending['effect_publication']['dispatch_attempts'] );

		delete_transient( 'doing_cron' );
		$pending['effect_publication']['last_dispatch_at'] = time() - 66;
		update_option( 'digitalogic_currency_admin_async_job', $pending, false );
		$GLOBALS['digitalogic_test_option_cache'] = array();
		$this->assertTrue( $async->recover_committed_publication() );
		$this->assertCount( 2, $GLOBALS['digitalogic_test_spawn_cron_calls'] );
		$this->assertSame( 1, digitalogic_test_run_spawned_cron() );
		$this->assertSame( 'confirmed', $async->status( $job['job_id'], $job['generation'] )['status'] );
		$this->assertSame( 1, $async->status( $job['job_id'], $job['generation'] )['apply_attempts'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** A persistently unreachable post-commit finalizer becomes an actionable terminal state. */
	public function test_currency_admin_async_publication_dispatch_is_bounded_without_repricing(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$GLOBALS['wpdb']->after_commit = static function ( $database ) {
			$database->acquire_result = 0;
			throw new RuntimeException( 'simulated process loss after COMMIT' );
		};
		$async->run_job( $job['job_id'], $job['generation'] );
		$GLOBALS['wpdb']->acquire_result = 1;
		$stored                           = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$price_after_commit               = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$stored['effect_publication']['dispatch_attempts'] = 6;
		$stored['effect_publication']['last_dispatch_at']  = time() - 66;
		$stored['effect_publication']['next_attempt_at']   = time();
		$GLOBALS['digitalogic_test_scheduled_events']       = array();
		$GLOBALS['digitalogic_test_remote_posts']           = array();
		$GLOBALS['digitalogic_test_spawn_cron_calls']       = array();
		update_option( 'digitalogic_currency_admin_async_job', $stored, false );
		$GLOBALS['digitalogic_test_option_cache'] = array();

		$this->assertTrue( $async->recover_committed_publication() );
		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'publication_failed', $status['status'] );
		$this->assertTrue( $status['operator_action_required'] );
		$this->assertSame( 'digitalogic_currency_async_publication_dispatch_exhausted', $status['error_code'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_spawn_cron_calls'] );
	}

	/** A persistently unavailable finalizer schedule becomes terminal without repeating the committed price effect. */
	public function test_currency_admin_async_publication_schedule_failure_is_bounded_without_repricing(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$GLOBALS['wpdb']->after_commit = static function ( $database ) {
			$database->acquire_result = 0;
			throw new RuntimeException( 'simulated process loss after COMMIT' );
		};
		$async->run_job( $job['job_id'], $job['generation'] );
		$GLOBALS['wpdb']->acquire_result              = 1;
		$stored                                      = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$price_after_commit                          = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$GLOBALS['digitalogic_test_scheduled_events'] = array();
		$GLOBALS['digitalogic_test_remote_posts']     = array();
		$GLOBALS['digitalogic_test_spawn_cron_calls'] = array();
		$GLOBALS['digitalogic_test_schedule_failure'] = true;

		for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
			$this->assertTrue( $async->recover_committed_publication() );
			$stored = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
			$this->assertSame( $attempt, $stored['effect_publication']['schedule_failures'] );
			if ( $attempt < 6 ) {
				$stored['effect_publication']['last_schedule_failure_at'] = time() - 3;
				update_option( 'digitalogic_currency_admin_async_job', $stored, false );
				$GLOBALS['digitalogic_test_option_cache'] = array();
			}
		}

		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'publication_failed', $status['status'] );
		$this->assertTrue( $status['operator_action_required'] );
		$this->assertSame( 'digitalogic_currency_async_publication_schedule_exhausted', $status['error_code'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_remote_posts'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_spawn_cron_calls'] );
	}

	/** A durable report-stage failure cannot make a committed job look terminal. */
	public function test_currency_admin_async_publication_failure_retries_without_repricing(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'] = 'before-publication';
		$GLOBALS['digitalogic_test_update_failures'][]                                 = 'digitalogic_report_cache_generation_v1';
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );

		$async->run_job( $job['job_id'], $job['generation'] );
		$pending            = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$price_after_commit = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];

		$this->assertSame( 'running', $pending['status'] );
		$this->assertSame( 'pending', $pending['effect_publication']['status'] );
		$this->assertSame( 'digitalogic_report_effect_generation_store_failed', $pending['effect_publication']['last_error'] );
		$this->assertSame( 'publishing', $async->status( $job['job_id'], $job['generation'] )['status'] );
		$this->assertSame( 1, $pending['apply_attempts'] );

		$GLOBALS['digitalogic_test_update_failures']      = array();
		$pending['effect_publication']['next_attempt_at'] = time();
		$pending['next_attempt_at']                       = time();
		$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $pending;
		$GLOBALS['digitalogic_test_option_cache']                                    = array();
		$async->finalize_job(
			(string) $pending['job_id'],
			(int) $pending['generation'],
			(int) $pending['fence'],
			(string) $pending['effect_state_revision']
		);
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 1, $status['apply_attempts'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Post-commit publication backoff terminalizes visibly and never re-actuates pricing. */
	public function test_currency_admin_async_publication_retries_are_bounded_and_terminal(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_report_cache_generation_v1'] = 'before-publication';
		$GLOBALS['digitalogic_test_update_failures'][]                                 = 'digitalogic_report_cache_generation_v1';
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );

		$async->run_job( $job['job_id'], $job['generation'] );
		$stored             = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$price_after_commit = (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'];
		$this->assertSame( 1, $stored['effect_publication']['attempts'] );
		$this->assertGreaterThanOrEqual( 2, $stored['effect_publication']['next_attempt_at'] - time() );
		$this->assertLessThanOrEqual( 3, $stored['effect_publication']['next_attempt_at'] - time() );

		$finalize_args = array(
			(string) $stored['job_id'],
			(int) $stored['generation'],
			(int) $stored['fence'],
			(string) $stored['effect_state_revision'],
		);
		for ( $attempt = 2; $attempt <= 6; $attempt++ ) {
			wp_clear_scheduled_hook( 'digitalogic_currency_admin_async_finalize', $finalize_args );
			$stored['effect_publication']['next_attempt_at'] = time();
			$stored['next_attempt_at']                       = time();
			$GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'] = $stored;
			$GLOBALS['digitalogic_test_option_cache']                                    = array();

			$async->finalize_job( ...$finalize_args );
			$stored = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
			$this->assertSame( $attempt, $stored['effect_publication']['attempts'] );
			$this->assertSame( 1, $stored['apply_attempts'] );
			$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		}

		$status = $async->status( $job['job_id'], $job['generation'] );
		$this->assertSame( 'publication_failed', $status['status'] );
		$this->assertSame( 100, $status['progress'] );
		$this->assertSame( 6, $status['publication_attempts'] );
		$this->assertTrue( $status['operator_action_required'] );
		$this->assertSame( 'digitalogic_currency_async_publication_exhausted', $status['error_code'] );
		$this->assertSame( 'publication_failed', $async->status( '', 0, true )['status'] );
		$this->assertSame( 0, $stored['effect_publication']['next_attempt_at'] );
		$this->assertFalse( wp_next_scheduled( 'digitalogic_currency_admin_async_finalize', $finalize_args ) );

		$events_before = $GLOBALS['digitalogic_test_scheduled_events'];
		$async->run_job( $job['job_id'], $job['generation'] );
		$this->assertSame( $events_before, $GLOBALS['digitalogic_test_scheduled_events'] );
		$this->assertSame( 1, $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job']['apply_attempts'] );
		$this->assertSame( $price_after_commit, (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Marker-owned pricing emits no raw per-option compatibility event. */
	public function test_currency_admin_async_publishes_only_the_canonical_currency_effect(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		$before = count( $GLOBALS['digitalogic_test_actions']['updated_option_yuan_price'] ?? array() );

		$async->run_job( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $async->status( $job['job_id'], $job['generation'] )['status'] );
		$this->assertSame( $before, count( $GLOBALS['digitalogic_test_actions']['updated_option_yuan_price'] ?? array() ) );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_settings_updated'] ?? array() );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_apply_committed'] ?? array() );
	}

	/** A later valid revision supersedes history without trapping or replaying the old rate. */
	public function test_currency_admin_async_committed_job_finalizes_after_newer_revision_without_snapback(): void {
		$async = Digitalogic_Currency_Admin_Async::instance();
		$job   = $async->enqueue( '29501', false );
		$this->assertFalse( is_wp_error( $job ) );
		add_action(
			'digitalogic_currency_async_worker_before_complete',
			static function () {
				$GLOBALS['wpdb']->acquire_result = 0;
			}
		);
		$async->run_job( $job['job_id'], $job['generation'] );
		$committed = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
		$this->assertSame( 'running', $committed['status'] );
		$this->assertSame( '29501', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );

		$GLOBALS['wpdb']->acquire_result = 1;
		$newer                           = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29502' ),
			'newer_writer'
		);
		$this->assertFalse( is_wp_error( $newer ) );
		$settings_events_before = count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_settings_updated'] ?? array() );
		$async->run_job( $job['job_id'], $job['generation'] );
		$status = $async->status( $job['job_id'], $job['generation'] );

		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( $newer['state_revision'], $status['superseded_by_state_revision'] );
		$this->assertSame( '29502', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( $settings_events_before, count( $GLOBALS['digitalogic_test_actions']['digitalogic_excel_pricing_settings_updated'] ?? array() ) );
		$this->assertSame( 1, $status['apply_attempts'] );
	}

	/** A late completion cannot overwrite a newer generation and the newer job wins. */
	public function test_currency_admin_async_stale_completion_cannot_overwrite_newer_job(): void {
		$async    = Digitalogic_Currency_Admin_Async::instance();
		$old_job  = $async->enqueue( '29501', false );
		$replaced = false;
		$new_job  = array();
		$this->assertFalse( is_wp_error( $old_job ) );
		add_action(
			'digitalogic_currency_async_worker_before_complete',
			static function () use ( &$replaced, &$new_job ) {
				if ( $replaced ) {
					return;
				}
				$replaced                           = true;
				$new_job                            = $GLOBALS['digitalogic_test_options']['digitalogic_currency_admin_async_job'];
				$new_job['job_id']                  = str_repeat( 'c', 32 );
				$new_job['generation']              = (int) $new_job['generation'] + 1;
				$new_job['status']                  = 'queued';
				$new_job['desired_currency']        = array( 'yuan_price' => 29502 );
				$state                              = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
				$new_job['confirmed_currency']      = array(
					'dollar_price' => (int) $state['settings']['dollar_price'],
					'yuan_price'   => (int) $state['settings']['yuan_price'],
				);
				$new_job['expected_state_revision'] = $state['state_revision'];
				$new_job['created_at']              = time();
				$new_job['updated_at']              = time();
				$new_job['deadline_at']             = time() + 300;
				$new_job['completed_at']            = 0;
				$new_job['next_attempt_at']         = time();
				$new_job['transaction_id']          = '';
				$new_job['error_code']              = '';
				$new_job['message_fa']              = 'درخواست تازه';
				$new_job['owner_token']             = '';
				$new_job['fence_token']             = '';
				$new_job['fence']                   = 0;
				$new_job['lease_until']             = 0;
				$new_job['apply_attempts']          = 0;
				unset( $new_job['effect_state_revision'], $new_job['effect_committed_at'], $new_job['committed_state_revision'] );
				update_option( 'digitalogic_currency_admin_async_job', $new_job, false );
			},
			10,
			1
		);

		$async->run_job( $old_job['job_id'], $old_job['generation'] );
		$this->assertSame( 'superseded', $async->status( $old_job['job_id'], $old_job['generation'] )['status'] );
		$this->assertSame( $new_job['job_id'], $async->status()['job_id'] );
		$this->assertSame( 'queued', $async->status()['status'] );

		$async->run_job( $new_job['job_id'], $new_job['generation'] );
		$status = $async->status( $new_job['job_id'], $new_job['generation'] );
		$this->assertSame( 'confirmed', $status['status'] );
		$this->assertSame( 29502, $status['confirmed_currency']['yuan_price'] );
		$this->assertSame( '29502', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
	}

	/** ACF retries only safe pending drift and still blocks ambiguous/readback failures. */
	public function test_currency_admin_async_retry_classifier_is_safety_bounded(): void {
		$async  = Digitalogic_Currency_Admin_Async::instance();
		$method = new ReflectionMethod( $async, 'is_retryable_apply_error' );

		$this->assertTrue(
			$method->invoke(
				$async,
				new WP_Error( 'digitalogic_product_sync_busy', 'busy' )
			)
		);
		$this->assertTrue(
			$method->invoke(
				$async,
				new WP_Error(
					'digitalogic_pricing_delivery_incomplete',
					'pending',
					array(
						'pending_products'   => 25,
						'deferred_ambiguous' => 0,
					)
				)
			)
		);
		$this->assertFalse(
			$method->invoke(
				$async,
				new WP_Error(
					'digitalogic_pricing_delivery_incomplete',
					'ambiguous',
					array(
						'pending_products'   => 25,
						'deferred_ambiguous' => 1,
					)
				)
			)
		);
		$this->assertFalse(
			$method->invoke(
				$async,
				new WP_Error( 'digitalogic_pricing_delivery_readback_failed', 'unsafe mismatch' )
			)
		);
	}

	/** Failed persistent-cache multi-delete keys fall back to exact cache cleanup. */
	public function test_large_changed_reconcile_repairs_partial_persistent_cache_delete(): void {
		$this->seed_large_pricing_snapshot( 3 );
		$GLOBALS['digitalogic_test_cache_deletes']                  = array();
		$GLOBALS['digitalogic_test_cache_delete_multiple_callback'] = static function ( $keys, $group ) {
			$results = array_fill_keys( (array) $keys, true );
			$failed  = (int) end( $keys );
			foreach ( (array) $keys as $key ) {
				if ( (int) $key !== $failed ) {
					wp_cache_delete( $key, $group );
				}
			}
			$results[ $failed ] = false;
			return $results;
		};

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array( 'yuan_price' => '29501' ),
			'cache_fallback'
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertContains(
			array( 20002, 'post_meta' ),
			$GLOBALS['digitalogic_test_cache_deletes']
		);
	}

	/** An identity-safe source row gets a Woo page and joins later repricing. */
	public function test_identity_safe_missing_product_is_materialized_and_repriced(): void {
		$this->seed_snapshot( true );
		$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
			array( 'patris_code' => 'MISSING-902' )
		);
		$this->assertFalse( is_wp_error( $resolved ) );
		$materialized_id = (int) $resolved['woocommerce_id'];
		$this->assertGreaterThan( 0, $materialized_id );
		$this->assertSame(
			'air_express',
			(string) $GLOBALS['digitalogic_test_posts'][ $materialized_id ]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_missing'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 0, $result['pricing_results']['deferred_missing'] );
		$this->assertSame( 0, $result['pricing_results']['deferred_ambiguous'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][ $materialized_id ]['meta']['_regular_price'] );
	}

	/** A raw CNY legacy row gains a price only after exact site-route readback. */
	public function test_raw_cny_legacy_row_bootstraps_shipping_then_prices_on_same_value_reconcile(): void {
		$initial = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'prepare_same_value'
		);
		$this->assertFalse( is_wp_error( $initial ) );

		unset(
			$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ],
			$GLOBALS['digitalogic_test_post_meta_cache'][901]
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();
		$product = array(
			'product_code'          => 'PRICE-901',
			'foreign_currency'      => 'CNY',
			'foreign_price'         => 115,
			'weight_grams'          => 370,
			'price_rounding_digits' => 0,
			'price_rounding_mode'   => 'nearest_half_up',
			'warnings'              => array(),
		);
		$product['record_hash'] = $this->record_hash( $product );
		$received               = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot( array( $product ), '2026-07-28T00:00:00Z' )
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received )
				? $received->get_error_code() . ': ' . $received->get_error_message() . ' ' . wp_json_encode( $received->get_error_data() )
				: ''
		);
		$this->assertSame(
			'air_express',
			(string) $GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$this->assertArrayNotHasKey( '_digitalogic_patris_price_source_kind', $GLOBALS['digitalogic_test_posts'][901]['meta'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );

		$reconciled = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'same_value_raw_cny' );
		$this->assertFalse(
			is_wp_error( $reconciled ),
			is_wp_error( $reconciled ) ? $reconciled->get_error_code() . ': ' . $reconciled->get_error_message() : ''
		);
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( 'foreign_price', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_price_source_kind'] );
		$this->assertSame( 'CNY', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_price_source_currency'] );
		$this->assertSame( '115', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_digitalogic_patris_price_source_amount'] );
		$this->assertSame( '6423820', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '6423820', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );

		$state  = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$source = reset( $state['sources'] );
		$stored = $source['products']['PRICE-901'];
		$this->assertSame( 'air_express', (string) $stored['shipping_method_id'] );
		$this->assertSame( 'foreign_price', (string) $stored['price_source_kind'] );
		$this->assertSame( '6423820', (string) $stored['final_price'] );
		$matching = array_filter(
			$GLOBALS['digitalogic_test_posts'],
			static function ( $post ) {
				return 'PRICE-901' === (string) ( $post['meta']['_digitalogic_patris_product_code'] ?? '' );
			}
		);
		$this->assertCount( 1, $matching );
	}

	/** A target deleted after snapshot ingestion is recreated inside coordinated pricing. */
	public function test_coordinated_reprice_recreates_a_deleted_identity_safe_target(): void {
		$this->seed_snapshot( true );
		$before = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
			array( 'patris_code' => 'MISSING-902' )
		);
		$this->assertFalse( is_wp_error( $before ) );
		$deleted_id = (int) $before['woocommerce_id'];
		unset(
			$GLOBALS['digitalogic_test_posts'][ $deleted_id ],
			$GLOBALS['digitalogic_test_wc_products'][ $deleted_id ],
			$GLOBALS['digitalogic_test_post_meta_cache'][ $deleted_id ]
		);
		$this->reset_singleton( Digitalogic_Product_Identifier_Resolver::class );
		$lock_observations = array();
		add_action(
			'digitalogic_patris_materializer_product_committed',
			static function ( $snapshot ) use ( &$lock_observations ) {
				if ( 'MISSING-902' === (string) ( $snapshot['product_code'] ?? '' ) ) {
					$lock_observations[] = Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned();
				}
			}
		);

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_recreate_missing'
		);
		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( 0, $result['pricing_results']['pending_products'] );
		$this->assertSame( 0, $result['pricing_results']['deferred_missing'] );
		$this->assertSame( array( false ), $lock_observations );

		$this->reset_singleton( Digitalogic_Product_Identifier_Resolver::class );
		$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
			array( 'patris_code' => 'MISSING-902' )
		);
		$this->assertFalse( is_wp_error( $resolved ) );
		$product_id = (int) $resolved['woocommerce_id'];
		$meta       = $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'];
		$state      = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$source     = reset( $state['sources'] );
		$revision   = (string) $source['source']['revision'];
		$this->assertSame( 'publish', $GLOBALS['digitalogic_test_posts'][ $product_id ]['post_status'] );
		$this->assertSame( '1', (string) $meta[ Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META ] );
		$this->assertSame( $revision, (string) $meta[ Digitalogic_Patris_Catalog_Materializer::SOURCE_REVISION_META ] );
		$this->assertSame( '["image","seo","stock"]', (string) $meta[ Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META ] );
		$this->assertSame( 'MISSING-902', (string) $meta['_digitalogic_patris_product_code'] );
		$this->assertSame( '8866000', (string) $meta['_regular_price'] );
		$this->assertSame( '1000', (string) $meta['_digitalogic_patris_weight_grams'] );

		$matching = array_filter(
			$GLOBALS['digitalogic_test_posts'],
			static function ( $post ) {
				return 'MISSING-902' === (string) ( $post['meta']['_digitalogic_patris_product_code'] ?? '' );
			}
		);
		$this->assertCount( 1, $matching );
	}

	/** Auto-materialized leaves use the canonical full feed during later repricing. */
	public function test_coordinated_reprice_repairs_auto_materialized_feed_drift(): void {
		$this->seed_snapshot( true );
		$resolved   = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
			array( 'patris_code' => 'MISSING-902' )
		);
		$product_id = (int) $resolved['woocommerce_id'];
		unset( $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta']['_digitalogic_patris_weight_grams'] );
		unset( $GLOBALS['digitalogic_test_post_meta_cache'][ $product_id ] );
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_repair_auto_feed'
		);
		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame(
			'1000',
			(string) $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta']['_digitalogic_patris_weight_grams']
		);
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][ $product_id ]['meta']['_regular_price'] );
	}

	/** The pricing batch cannot bypass exact source ownership validation. */
	public function test_coordinated_reprice_rejects_conflicting_source_ownership(): void {
		$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META ] = 'other-source';
		$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META ] = 'kala';
		$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META ] = 'PRICE-901';
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_conflicting_owner'
		);
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( 1, (int) $result->get_error_data()['deferred_identity_hazard'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A rolled-back coordinated write cannot emit product commit snapshots. */
	public function test_coordinated_reprice_discards_commit_snapshots_on_rollback(): void {
		$this->seed_snapshot( true );
		$resolved   = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
			array( 'patris_code' => 'MISSING-902' )
		);
		$product_id = (int) $resolved['woocommerce_id'];
		$GLOBALS['digitalogic_test_posts'][ $product_id ]['meta'][ Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META ] = 'other-source';
		$GLOBALS['digitalogic_test_wc_products'] = array();
		$committed = array();
		add_action(
			'digitalogic_patris_materializer_product_committed',
			static function ( $snapshot ) use ( &$committed ) {
				$committed[] = $snapshot;
			}
		);

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_rollback_drops_commit_snapshots'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( 1, (int) $result->get_error_data()['deferred_identity_hazard'] );
		$this->assertSame( array(), $committed );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Ambiguous Woo identities fail closed and roll back the settings change. */
	public function test_ambiguous_product_identity_blocks_commit(): void {
		$GLOBALS['digitalogic_test_posts'][902] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Duplicate Product Code',
			'product_type' => 'simple',
			'meta'         => array(
				'_digitalogic_patris_product_code' => 'PRICE-901',
				'_sku'                             => 'DUPLICATE-PRICE-901',
			),
		);
		$result                                 = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_ambiguous'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_product_identity_ambiguous', $result->get_error_code() );
		$this->assertSame( '29500', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** The same Product Code cannot be owned by two active pricing sources. */
	public function test_duplicate_product_code_across_sources_blocks_commit(): void {
		$second = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot(
				array( $this->priced_product( 'PRICE-901' ) ),
				'2026-07-22T00:00:00Z',
				'pricing-tests-duplicate'
			)
		);
		$this->assertFalse(
			is_wp_error( $second ),
			is_wp_error( $second ) ? $second->get_error_code() . ': ' . $second->get_error_message() : ''
		);

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_duplicate_source'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_product_source_ambiguous', $result->get_error_code() );
		$this->assertSame( '29500', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** An unavailable formula clears every old customer price instead of preserving drift. */
	public function test_missing_final_price_clears_old_woo_price(): void {
		$unpriced = $this->priced_product( 'PRICE-901' );
		unset(
			$unpriced['foreign_price'],
			$unpriced['price_source_amount'],
			$unpriced['price_source_currency'],
			$unpriced['price_source_kind'],
			$unpriced['final_price'],
			$unpriced['record_hash']
		);
		$unpriced['record_hash'] = $this->record_hash( $unpriced );
		$received                = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot(
				array( $unpriced ),
				'2026-07-22T00:00:00Z'
			)
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
		);
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_unpriced'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '31000', $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );

		$state  = $GLOBALS['digitalogic_test_options'][ Digitalogic_Product_Sync_Receiver::STATE_OPTION ];
		$stored = reset( $state['sources'] )['products']['PRICE-901'];
		unset(
			$GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'],
			$GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'],
			$GLOBALS['digitalogic_test_posts'][901]['meta']['_price']
		);
		$GLOBALS['digitalogic_test_wc_products']     = array();
		$GLOBALS['digitalogic_test_post_meta_cache'] = array();

		$readback = new ReflectionMethod( Digitalogic_Product_Sync_Receiver::class, 'coordinated_price_readback_matches' );
		$this->assertTrue(
			$readback->invoke( Digitalogic_Product_Sync_Receiver::instance(), 901, $stored ),
			'Woo may delete empty price rows; that canonical absence must not roll back a CNY update.'
		);
	}

	/** Missing weight preserves a valid prior storefront price and reports why. */
	public function test_missing_weight_preserves_consistent_storefront_price_with_warning(): void {
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] = '1150000';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price']    = '';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_price']         = '1150000';
		$GLOBALS['digitalogic_test_wc_products']                          = array();

		$unpriced = $this->priced_product( 'PRICE-901' );
		unset( $unpriced['weight_grams'], $unpriced['final_price'], $unpriced['record_hash'] );
		$unpriced['record_hash'] = $this->record_hash( $unpriced );
		$received                = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot(
				array( $unpriced ),
				'2026-07-22T00:00:00Z'
			)
		);
		$this->assertFalse(
			is_wp_error( $received ),
			is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
		);
		$this->assertSame( '1150000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '1150000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame(
			'canonical_missing_preserved',
			(string) $GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Patris_Price_Policy::STATUS_META ]
		);

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_missing_weight'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '1150000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '1150000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame(
			Digitalogic_Patris_Price_Policy::MISSING_WEIGHT_WARNING,
			(string) $GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Patris_Price_Policy::WARNING_META ]
		);
		$this->assertSame( 1, $result['pricing_results']['warning_count'] );
		$this->assertSame(
			'canonical_missing_preserved',
			$result['pricing_results']['warnings'][0]['code']
		);

		$GLOBALS['digitalogic_test_wc_product_saves'] = array();
		$replay                                       = Digitalogic_Pricing_Coordinator::instance()->reconcile_current( 'test_missing_weight_replay' );
		$this->assertFalse( is_wp_error( $replay ) );
		$this->assertSame( 0, $replay['pricing_results']['updated_products'] );
		$this->assertSame( 1, $replay['pricing_results']['already_current_products'] );
		$this->assertSame( 1, $replay['pricing_results']['warning_count'] );
		$this->assertSame( 'canonical_missing_preserved', $replay['pricing_results']['warnings'][0]['code'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Reconciliation removes a promotion and makes visible and selling prices exact. */
	public function test_active_sale_is_removed_by_global_pricing_commit(): void {
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] = '3000000';
		$GLOBALS['digitalogic_test_posts'][901]['meta']['_price']      = '3000000';
		$GLOBALS['digitalogic_test_wc_products']                       = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_sale_mismatch'
		);

		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$this->assertSame( '31000', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	/** Variable containers fail closed until their variations are reconciled. */
	public function test_variable_product_blocks_global_pricing_commit(): void {
		$GLOBALS['digitalogic_test_posts'][901]['product_type'] = 'variable';
		$GLOBALS['digitalogic_test_wc_products']                = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_variable_mismatch'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( 1, (int) $result->get_error_data()['deferred_identity_hazard'] );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** A conflicting live assignment is isolated to fenced fallback and fails closed. */
	public function test_shipping_assignment_drift_blocks_global_pricing_commit(): void {
		$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] = 'domestic';
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
			array(
				'yuan_price'     => '31000',
				'effective_date' => '2026-07-27',
			),
			'test_shipping_assignment_mismatch'
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'digitalogic_pricing_delivery_incomplete', $result->get_error_code() );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertSame(
			'domestic',
			$GLOBALS['digitalogic_test_posts'][901]['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ]
		);
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Per-product Sheet profit edits are rejected because profit is shared. */
	public function test_google_sheet_product_profit_change_is_rejected(): void {
		$product    = Digitalogic_Product_Manager::instance()->get_product( 901 );
		$projection = Digitalogic_Google_Sheets_Catalog::instance()->transform_products( array( $product ) );
		$this->assertFalse( is_wp_error( $projection ) );
		$revision = $projection['rows'][0]['record_revision'];
		$payload  = array(
			'idempotency_key' => 'pricing-profit-preview-901',
			'changes'         => array(
				array(
					'sync_key'                 => 'PRICE-901',
					'patris_code'              => 'PRICE-901',
					'expected_record_revision' => $revision,
					'fields'                   => array( 'profit_percent' => '40' ),
				),
			),
		);
		$service  = Digitalogic_Google_Sheets_Writeback::instance();
		$preview  = $service->preview( $payload );
		$this->assertSame( 'invalid', $preview['results'][0]['status'] );
		$this->assertSame( 'digitalogic_sheets_writeback_source_owned_field_forbidden', $preview['results'][0]['code'] );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Pricing domain events are emitted only after the database commit succeeds. */
	public function test_pricing_domain_events_are_post_commit_only(): void {
		$observed = array();
		add_action(
			'digitalogic_pricing_reconciled',
			static function () use ( &$observed ) {
				$observed[] = in_array( 'COMMIT', $GLOBALS['wpdb']->queries, true );
			}
		);
		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );

		$failed = Digitalogic_Pricing_Coordinator::instance()->update_profit_margin( '40', 'test_event_failure' );

		$this->assertTrue( is_wp_error( $failed ) );
		$this->assertSame( 'digitalogic_excel_sync_commit_failed', $failed->get_error_code() );
		$this->assertSame( array(), $observed );

		$GLOBALS['digitalogic_test_transaction_failures'] = array();
		$GLOBALS['digitalogic_test_wc_products']          = array();
		$applied = Digitalogic_Pricing_Coordinator::instance()->update_profit_margin( '40', 'test_event_success' );

		$this->assertFalse(
			is_wp_error( $applied ),
			is_wp_error( $applied ) ? $applied->get_error_code() . ': ' . $applied->get_error_message() : ''
		);
		$this->assertSame( array( true ), $observed );
		$this->assertSame( '9086000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_sale_price'] );
		$this->assertSame( '9086000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_price'] );
	}

	/** Every direct Woo customer-price write is rejected for managed products. */
	public function test_managed_customer_price_writes_are_blocked(): void {
		$manager_result = Digitalogic_Product_Manager::instance()->update_product(
			901,
			array( 'regular_price' => '1' )
		);
		$this->assertTrue( is_wp_error( $manager_result ) );
		$this->assertSame( 'digitalogic_patris_regular_price_managed', $manager_result->get_error_code() );
		$input_result = Digitalogic_Product_Manager::instance()->update_product(
			901,
			array( 'patris_foreign_price' => '200' )
		);
		$this->assertTrue( is_wp_error( $input_result ) );
		$this->assertSame( 'digitalogic_patris_pricing_inputs_managed', $input_result->get_error_code() );

		$guard_result = Digitalogic_Patris_Price_Write_Guard::instance()->guard_price_metadata(
			null,
			901,
			'_regular_price',
			'1'
		);
		$this->assertFalse( $guard_result );
		$this->assertFalse(
			Digitalogic_Patris_Price_Write_Guard::instance()->guard_price_metadata( null, 901, '_sale_price', '1' )
		);
		$this->assertFalse(
			Digitalogic_Patris_Price_Write_Guard::instance()->guard_price_metadata( null, 901, '_price', '1' )
		);
		$sale_result = Digitalogic_Product_Manager::instance()->update_product( 901, array( 'sale_price' => '1' ) );
		$this->assertTrue( is_wp_error( $sale_result ) );
		$this->assertSame( '8437000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
	}

	/** Unmanaged Woo rows still cannot persist or display a separate sale price. */
	public function test_ecosystem_price_invariant_covers_unmanaged_products(): void {
		$GLOBALS['digitalogic_test_posts'][903]  = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'post_title'   => 'Manual Digitalogic product',
			'product_type' => 'simple',
			'meta'         => array(
				'_sku'           => 'MANUAL-903',
				'_regular_price' => '100',
				'_sale_price'    => '90',
				'_price'         => '90',
			),
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();

		$updated = Digitalogic_Product_Manager::instance()->update_product(
			903,
			array( 'regular_price' => '200' )
		);
		$this->assertTrue( $updated );
		$this->assertSame( '200', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_sale_price'] );
		$this->assertSame( '200', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_price'] );

		$rejected = Digitalogic_Product_Manager::instance()->update_product(
			903,
			array( 'sale_price' => '150' )
		);
		$this->assertTrue( is_wp_error( $rejected ) );
		$this->assertSame( 'digitalogic_sale_price_forbidden', $rejected->get_error_code() );

		$guard   = Digitalogic_Patris_Price_Write_Guard::instance();
		$product = wc_get_product( 903 );
		$this->assertFalse( $guard->guard_price_metadata( null, 903, '_sale_price', '150' ) );
		$this->assertFalse( $guard->guard_price_metadata( null, 903, '_price', '199' ) );
		$this->assertNull( $guard->guard_price_metadata( null, 903, '_price', '200' ) );
		$this->assertSame( '200', $guard->canonical_visible_price( '150', $product ) );
		$this->assertSame( '', $guard->canonical_sale_price( '150', $product ) );

		$GLOBALS['digitalogic_test_wc_products'] = array();
		$this->assertSame( 1, update_post_meta( 903, '_regular_price', '250' ) );
		$this->assertSame( '250', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_regular_price'] );
		$this->assertSame( '', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_sale_price'] );
		$this->assertSame( '250', (string) $GLOBALS['digitalogic_test_posts'][903]['meta']['_price'] );
	}

	/**
	 * Seed a priced snapshot, optionally with one product lacking a Woo page.
	 *
	 * @param bool $include_missing Include a terminal-missing Product Code.
	 * @return void
	 */
	private function seed_snapshot( $include_missing = false ) {
		$products = array( $this->priced_product( 'PRICE-901' ) );
		if ( $include_missing ) {
			$products[] = $this->priced_product( 'MISSING-902' );
		}
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive(
			$this->snapshot(
				$products,
				$include_missing ? '2026-07-22T00:00:00Z' : '2026-07-21T00:00:00Z'
			)
		);
		$this->assertFalse(
			is_wp_error( $result ),
			is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : ''
		);
		$GLOBALS['digitalogic_test_wc_products'] = array();
	}

	/**
	 * Seed a deterministic production-sized pricing source and Woo projection.
	 *
	 * @param int $count Product count.
	 * @param int $variable_parent_count Number of two-child variable parents.
	 * @return void
	 */
	private function seed_large_pricing_snapshot( $count, $variable_parent_count = 0 ) {
		$count                 = (int) $count;
		$variable_parent_count = max( 0, (int) $variable_parent_count );
		$variation_count       = 2 * $variable_parent_count;
		$this->assertLessThanOrEqual( $count, $variation_count );
		$simple_count = $count - $variation_count;
		$prototype    = $this->priced_product( 'PERF-0000' );
		$products     = array();
		for ( $offset = 0; $offset < $count; ++$offset ) {
			$product_id = 20000 + $offset;

			$product_code = 'PERF-' . str_pad( (string) $offset, 4, '0', STR_PAD_LEFT );

			$is_variation = $offset >= $simple_count;

			$parent_id = $is_variation
				? 30000 + intdiv( $offset - $simple_count, 2 )
				: 0;
			if ( $is_variation && ! isset( $GLOBALS['digitalogic_test_posts'][ $parent_id ] ) ) {
				$GLOBALS['digitalogic_test_posts'][ $parent_id ] = array(
					'post_type'    => 'product',
					'post_status'  => 'publish',
					'post_title'   => 'Performance variable parent ' . ( $parent_id - 30000 ),
					'product_type' => 'variable',
					'meta'         => array(),
				);

				$GLOBALS['digitalogic_test_wc_lookup_rows'][ $parent_id ] = array(
					'product_id' => $parent_id,
					'min_price'  => '0',
					'max_price'  => '0',
					'onsale'     => 0,
				);
			}
			$GLOBALS['digitalogic_test_posts'][ $product_id ] = array(
				'post_type'    => $is_variation ? 'product_variation' : 'product',
				'post_status'  => 'publish',
				'post_title'   => 'Performance product ' . $offset,
				'product_type' => $is_variation ? 'variation' : 'simple',
				'post_parent'  => $parent_id,
				'meta'         => array(
					'_digitalogic_patris_product_code' => $product_code,
					Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => 'air_express',
					'_sku'                             => $product_code,
					'_regular_price'                   => '8437000',
					'_price'                           => '8437000',
					'_sale_price'                      => '',
				),
			);
			$GLOBALS['digitalogic_test_wc_lookup_rows'][ $product_id ] = array(
				'product_id' => $product_id,
				'min_price'  => '8437000',
				'max_price'  => '8437000',
				'onsale'     => 0,
			);
			$product                 = $prototype;
			$product['product_code'] = $product_code;
			unset( $product['record_hash'] );
			$product['record_hash'] = $this->record_hash( $product );
			$products[]             = $product;
		}

		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$snapshot = $this->snapshot( $products, '2026-07-23T00:00:00Z' );
		$received = null;
		for ( $attempt = 0; $attempt <= (int) ceil( $count / 25 ); ++$attempt ) {
			$received = $receiver->receive( $snapshot );
			$this->assertFalse(
				is_wp_error( $received ),
				is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
			);
			if ( 0 === (int) ( $received['pending_products'] ?? 0 ) ) {
				break;
			}
			$this->assertTrue( (bool) ( $received['retryable'] ?? false ) );
		}
		$this->assertSame( 0, (int) ( $received['pending_products'] ?? -1 ) );
		$GLOBALS['digitalogic_test_wc_products'] = array();
	}

	/**
	 * Build one optimistic managed-profit writeback request.
	 *
	 * @param string $idempotency_key Request identity.
	 * @param string $profit          Desired profit percentage.
	 * @return array
	 */
	private function sheet_profit_payload( $idempotency_key, $profit ) {
		$product    = Digitalogic_Product_Manager::instance()->get_product( 901 );
		$projection = Digitalogic_Google_Sheets_Catalog::instance()->transform_products( array( $product ) );
		$this->assertFalse( is_wp_error( $projection ) );

		return array(
			'idempotency_key' => $idempotency_key,
			'changes'         => array(
				array(
					'sync_key'                 => 'PRICE-901',
					'patris_code'              => 'PRICE-901',
					'expected_record_revision' => $projection['rows'][0]['record_revision'],
					'fields'                   => array( 'profit_percent' => $profit ),
				),
			),
		);
	}

	/**
	 * Build one exact landed-price product.
	 *
	 * @param string $product_code Product Code.
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
