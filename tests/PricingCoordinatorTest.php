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
		$GLOBALS['digitalogic_test_capabilities']                 = array();
		$GLOBALS['digitalogic_test_filters']                      = array();
		$GLOBALS['digitalogic_test_routes']                       = array();
		$GLOBALS['digitalogic_test_option_cache']                 = array();
		$GLOBALS['digitalogic_test_transients']                   = array();
		$GLOBALS['digitalogic_test_transient_deletes']            = array();
		$GLOBALS['digitalogic_test_actions']                      = array();
		$GLOBALS['digitalogic_test_action_callbacks']             = array();
		$GLOBALS['digitalogic_test_update_failures']              = array();
		$GLOBALS['digitalogic_test_transaction_failures']         = array();
		$GLOBALS['digitalogic_test_cache_deletes']                = array();
		$GLOBALS['digitalogic_test_cache_invalidation_suspended'] = false;
		$GLOBALS['digitalogic_test_cache_invalidation_history']   = array();
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		$GLOBALS['digitalogic_test_object_term_cache_cleans']     = array();
		$GLOBALS['digitalogic_test_post_meta_cache']              = array();
		$GLOBALS['digitalogic_test_meta_update_failures']         = array();
		$GLOBALS['digitalogic_test_meta_delete_failures']         = array();
		$GLOBALS['digitalogic_test_wc_products']                  = array();
		$GLOBALS['digitalogic_test_wc_product_saves']             = array();
		$GLOBALS['digitalogic_test_wc_save_failures']             = array();
		$GLOBALS['digitalogic_test_wc_save_fail_once']            = array();
		$GLOBALS['digitalogic_test_wc_after_save']                = null;
		$GLOBALS['digitalogic_test_wc_currency']                  = 'IRT';
		$GLOBALS['digitalogic_test_remote_posts']                 = array();
		$GLOBALS['digitalogic_test_remote_post_results']          = array();
		$GLOBALS['digitalogic_test_current_user_id']              = 0;
		$GLOBALS['digitalogic_test_posts']                        = array(
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
		$GLOBALS['digitalogic_test_options']                      = array(
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
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

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
			is_wp_error( $received ) ? $received->get_error_code() . ': ' . $received->get_error_message() : ''
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
	}

	/** Reconciliation leaves every managed simple/variation price unsplit. */
	public function test_no_managed_product_retains_sale_or_effective_price_drift(): void {
		$GLOBALS['digitalogic_test_posts'][902] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'publish',
			'post_parent' => 901,
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

	/** Missing Woo pages are terminal-safe; they do not block existing prices. */
	public function test_terminal_missing_product_is_reported_but_does_not_fail_commit(): void {
		$this->seed_snapshot( true );
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
		$this->assertSame( 1, $result['pricing_results']['deferred_missing'] );
		$this->assertSame( 0, $result['pricing_results']['deferred_ambiguous'] );
		$this->assertSame( '8866000', (string) $GLOBALS['digitalogic_test_posts'][901]['meta']['_regular_price'] );
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
		$this->assertSame( 'digitalogic_pricing_delivery_readback_failed', $result->get_error_code() );
		$this->assertSame( '29500', (string) $GLOBALS['digitalogic_test_options']['options_yuan_price'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
	}

	/** Readback fails closed when the live site-owned shipping assignment drifts. */
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
		$this->assertSame( 'digitalogic_pricing_delivery_readback_failed', $result->get_error_code() );
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
