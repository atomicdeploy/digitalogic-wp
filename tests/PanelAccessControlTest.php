<?php
/**
 * Panel access policy tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies the shared WordPress-native panel access predicate.
 */
final class PanelAccessControlTest extends TestCase {

	/**
	 * Reset current-user capability fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_capabilities']           = array();
		$GLOBALS['digitalogic_test_filters']                = array();
		$GLOBALS['digitalogic_test_current_user_can_calls'] = 0;
		$GLOBALS['digitalogic_test_current_user_id']        = 42;
	}

	/**
	 * Prevent an extensibility filter from leaking into unrelated tests.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'digitalogic_panel_access_capabilities' );
		remove_all_filters( 'digitalogic_panel_access_allowed' );
	}

	/**
	 * WooCommerce managers retain panel access.
	 */
	public function test_woocommerce_manager_can_access_panel(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;

		$this->assertTrue( Digitalogic_Access_Control::can_access_panel() );
	}

	/**
	 * WordPress administrators have a safe management fallback.
	 */
	public function test_administrator_has_safe_fallback_access(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;

		$this->assertTrue( Digitalogic_Access_Control::can_access_panel() );
	}

	/**
	 * Storefront-only roles never receive implicit panel access.
	 */
	public function test_customer_and_subscriber_capabilities_do_not_grant_access(): void {
		$GLOBALS['digitalogic_test_capabilities']['read']     = true;
		$GLOBALS['digitalogic_test_capabilities']['customer'] = true;

		$this->assertFalse( Digitalogic_Access_Control::can_access_panel() );
	}

	/**
	 * Integrators can add an explicit WordPress capability through the filter.
	 */
	public function test_integrators_can_extend_the_capability_list_explicitly(): void {
		$GLOBALS['digitalogic_test_capabilities']['operate_digitalogic'] = true;
		add_filter(
			'digitalogic_panel_access_capabilities',
			static function ( $capabilities ) {
				$capabilities[] = 'operate_digitalogic';
				return $capabilities;
			}
		);

		$this->assertTrue( Digitalogic_Access_Control::can_access_panel() );
	}
}
