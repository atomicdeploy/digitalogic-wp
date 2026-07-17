<?php
/**
 * Tests for read-only WooCommerce base-currency monitoring.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies shared IRT/Toman status semantics and transport parity.
 */
final class WooCommerceCurrencyStatusTest extends TestCase {

	/**
	 * Currency status service under test.
	 *
	 * @var Digitalogic_WooCommerce_Currency_Status
	 */
	private $service;

	/**
	 * Reset isolated WordPress test state.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['digitalogic_test_options']             = array(
			'options_dollar_price' => '610000',
			'options_yuan_price'   => '25300',
			'options_update_date'  => '260716',
		);
		$GLOBALS['digitalogic_test_option_cache']        = array();
		$GLOBALS['digitalogic_test_actions']             = array();
		$GLOBALS['digitalogic_test_action_callbacks']    = array();
		$GLOBALS['digitalogic_test_filters']             = array();
		$GLOBALS['digitalogic_test_update_failures']     = array();
		$GLOBALS['digitalogic_test_remote_posts']        = array();
		$GLOBALS['digitalogic_test_remote_post_results'] = array();
		$GLOBALS['digitalogic_test_wc_currency']         = 'IRT';
		$GLOBALS['wpdb']                                 = new Digitalogic_Test_WPDB();

		WP_CLI::$errors   = array();
		WP_CLI::$warnings = array();
		WP_CLI::$logs     = array();

		$this->reset_singleton( Digitalogic_WooCommerce_Currency_Status::class );
		$this->reset_singleton( Digitalogic_Logger::class );
		$this->reset_singleton( Digitalogic_Import_Freight_Service::class );
		$this->reset_singleton( Digitalogic_Command_Dispatcher::class );
		$this->reset_singleton( Digitalogic_REST_API::class );
		$this->reset_singleton( Digitalogic_Webhooks::class );
		$this->reset_singleton( Digitalogic_Panel::class );
		$this->service = Digitalogic_WooCommerce_Currency_Status::instance();
	}

	/**
	 * IRT is exposed explicitly as Toman without any writes.
	 */
	public function test_irt_status_is_explicit_read_only_toman_metadata(): void {
		$before_options = $GLOBALS['digitalogic_test_options'];
		$before_queries = $GLOBALS['wpdb']->queries;
		$status         = $this->service->get_status();

		$this->assertSame( 'IRT', $status['code'] );
		$this->assertSame( 'toman', $status['unit'] );
		$this->assertSame( 10, $status['irr_per_unit'] );
		$this->assertSame( 'IRT', $status['pricing_output_currency'] );
		$this->assertSame( 'toman', $status['pricing_output_unit'] );
		$this->assertSame( 10, $status['pricing_output_irr_per_unit'] );
		$this->assertTrue( $status['compatible'] );
		$this->assertSame( 'ready', $status['status'] );
		$this->assertTrue( $status['read_only'] );
		$this->assertSame( array(), $status['warnings'] );
		$this->assertSame( $before_options, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( $before_queries, $GLOBALS['wpdb']->queries );
	}

	/**
	 * IRR remains distinct and blocks the IRT pricing contract.
	 */
	public function test_non_irt_catalog_is_blocked_versioned_and_never_relabels_irr(): void {
		$catalog_irt                             = Digitalogic_Import_Freight_Service::instance()->get_integration_catalog();
		$GLOBALS['digitalogic_test_wc_currency'] = 'IRR';
		$catalog_irr                             = Digitalogic_Import_Freight_Service::instance()->get_integration_catalog();

		$this->assertSame( '1.1.0', $catalog_irr['schema_version'] );
		$this->assertSame( 'IRR', $catalog_irr['currency']['local'] );
		$this->assertSame( 'IRR', $catalog_irr['currency']['woocommerce_base']['code'] );
		$this->assertNull( $catalog_irr['currency']['woocommerce_base']['unit'] );
		$this->assertNull( $catalog_irr['currency']['woocommerce_base']['irr_per_unit'] );
		$this->assertNull( $catalog_irr['currency']['cny_to_irt'] );
		$this->assertFalse( $catalog_irr['currency']['compatibility']['compatible'] );
		$this->assertSame( 'base_currency_mismatch', $catalog_irr['currency']['compatibility']['status'] );
		$this->assertContains( 'woocommerce_base_currency_must_be_irt', $catalog_irr['currency']['warnings'] );
		$this->assertNotSame( $catalog_irt['revision'], $catalog_irr['revision'] );
	}

	/**
	 * Monitoring is idempotent, auditable, and non-mutating.
	 */
	public function test_change_monitor_logs_and_emits_metadata_without_option_writes(): void {
		$this->assertTrue( has_action( 'updated_option_woocommerce_currency' ) );
		$before_options = $GLOBALS['digitalogic_test_options'];

		$this->service->handle_currency_change( 'irt', 'IRR', 'woocommerce_currency' );

		$entries = Digitalogic_Logger::instance()->entries;
		$this->assertCount( 1, $entries );
		$this->assertSame( 'woocommerce_currency_change', $entries[0][0] );
		$this->assertSame( 'IRT', $entries[0][3] );
		$this->assertSame( 'IRR', $entries[0][4] );
		$this->assertSame( $before_options, $GLOBALS['digitalogic_test_options'] );
		$this->assertSame( 'IRR', $GLOBALS['digitalogic_test_actions']['digitalogic_woocommerce_currency_changed'][0][1] );
		$this->assertSame(
			'base_currency_mismatch',
			$GLOBALS['digitalogic_test_actions']['digitalogic_woocommerce_currency_changed'][0][2]['status']
		);

		$this->service->handle_currency_change( 'irt', 'IRT', 'woocommerce_currency' );
		$this->assertCount( 1, Digitalogic_Logger::instance()->entries );
	}

	/**
	 * REST and CLI consume the shared command-dispatcher status.
	 */
	public function test_dispatcher_rest_and_cli_share_the_same_status_payload(): void {
		$GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
		$dispatcher                              = Digitalogic_Command_Dispatcher::instance()->get_currency();
		$response                                = Digitalogic_REST_API::instance()->get_currency( new WP_REST_Request() );

		$this->assertSame( $dispatcher, $response->get_data()['data'] );
		$this->assertSame( 'IRT', $dispatcher['woocommerce_base']['code'] );
		$this->assertTrue( $dispatcher['woocommerce_base']['compatible'] );

		( new Digitalogic_CLI_Commands() )->currency_get( array(), array() );
		$this->assertContains( 'WooCommerce Base: IRT (Toman)', WP_CLI::$logs );
		$this->assertContains( 'Patris IRT Pricing: Ready', WP_CLI::$logs );
		$this->assertSame( array(), WP_CLI::$warnings );

		WP_CLI::$logs                            = array();
		$GLOBALS['digitalogic_test_wc_currency'] = 'USD';
		( new Digitalogic_CLI_Commands() )->currency_get( array(), array() );
		$this->assertContains( 'Patris IRT Pricing: Base currency mismatch', WP_CLI::$logs );
		$this->assertCount( 1, WP_CLI::$warnings );
	}

	/**
	 * A committed setting change reaches webhook and panel observers once.
	 */
	public function test_committed_change_fans_out_shared_status_without_currency_mutation(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_webhook_urls']   = array( 'https://n8n.test/webhook/currency' );
		$GLOBALS['digitalogic_test_options']['digitalogic_webhook_secret'] = 'currency-observer-secret';
		$redis = new Digitalogic_Test_Redis_Client();
		$GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_client'] = static function () use ( $redis ) {
			return $redis;
		};

		Digitalogic_Webhooks::instance();
		Digitalogic_Panel::instance();
		$GLOBALS['digitalogic_test_wc_currency'] = 'USD';

		do_action( 'updated_option_woocommerce_currency', 'IRT', 'USD', 'woocommerce_currency' );
		do_action( 'updated_option', 'woocommerce_currency', 'IRT', 'USD' );

		$this->assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
		$payload = json_decode(
			$GLOBALS['digitalogic_test_remote_posts'][0]['args']['body'],
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->assertSame( 'currency.updated', $payload['event'] );
		$this->assertSame( 'USD', $payload['data']['woocommerce_base']['code'] );
		$this->assertSame( 'base_currency_mismatch', $payload['data']['woocommerce_base']['status'] );
		$this->assertSame( 'IRT', $payload['data']['previous_woocommerce_base']['code'] );

		$events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
		$this->assertCount( 1, $events );
		$this->assertSame( 'currency.updated', $events[0]['name'] );
		$this->assertSame( 'USD', $events[0]['data']['woocommerce_base']['code'] );
		$this->assertCount( 1, $redis->published );
		$this->assertArrayNotHasKey( 'woocommerce_currency', $GLOBALS['digitalogic_test_options'] );
	}

	/**
	 * Reset a test singleton.
	 *
	 * @param string $class_name Class with a static instance property.
	 */
	private function reset_singleton( $class_name ): void {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
