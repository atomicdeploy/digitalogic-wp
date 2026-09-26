<?php // phpcs:ignoreFile -- Focused unit stubs intentionally live with the test case.
/**
 * Checkout experience integration tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout() {
		return ! empty( $GLOBALS['digitalogic_test_is_checkout'] );
	}
}

if ( ! function_exists( 'is_order_received_page' ) ) {
	function is_order_received_page() {
		return ! empty( $GLOBALS['digitalogic_test_is_order_received_page'] );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $data ) {
		$GLOBALS['digitalogic_test_localized_scripts'][ $handle ][ $object_name ] = $data;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		return 'jckwds-script' === $handle && 'registered' === $status;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return $GLOBALS['digitalogic_test_wc_runtime'];
	}
}

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-checkout-experience.php';

/** Fake Delivery Slots date manager. */
final class Digitalogic_Test_Delivery_Dates {
	public function display_checkout_fields(): void {}
}

/** Verify the stable placement and checkout-only assets. */
final class CheckoutExperienceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_action_callbacks']  = array();
		$GLOBALS['digitalogic_test_filters']           = array();
		$GLOBALS['digitalogic_test_enqueued_styles']   = array();
		$GLOBALS['digitalogic_test_enqueued_scripts']  = array();
		$GLOBALS['digitalogic_test_localized_scripts'] = array();
		$GLOBALS['digitalogic_test_is_checkout']       = true;
		$GLOBALS['digitalogic_test_is_order_received_page'] = false;
		$GLOBALS['digitalogic_test_wc_runtime'] = (object) array(
			'cart' => new class() {
				public function needs_shipping(): bool {
					return true;
				}
			},
		);
	}

	public function test_relocates_vendor_fields_outside_ajax_fragment(): void {
		$dates = new Digitalogic_Test_Delivery_Dates();
		$GLOBALS['iconic_wds_dates'] = $dates;
		$GLOBALS['iconic_wds']       = (object) array(
			'settings' => array(
				'general_setup_position'          => 'woocommerce_checkout_order_review',
				'general_setup_position_priority' => '10',
			),
		);
		$GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_order_review'][] = array(
			'callback'      => array( $dates, 'display_checkout_fields' ),
			'priority'      => 10,
			'accepted_args' => 1,
		);

		Digitalogic_Checkout_Experience::stabilize_delivery_fields();

		$this->assertSame( 'woocommerce_checkout_billing', $GLOBALS['iconic_wds']->settings['general_setup_position'] );
		$this->assertSame( array(), array_values( $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_order_review'] ) );
		$this->assertSame(
			array( $dates, 'display_checkout_fields' ),
			$GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_billing'][0]['callback']
		);
		$this->assertSame( 20, $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_billing'][0]['priority'] );
	}

	public function test_explicit_stable_vendor_position_is_preserved(): void {
		$dates = new Digitalogic_Test_Delivery_Dates();
		$GLOBALS['iconic_wds_dates'] = $dates;
		$GLOBALS['iconic_wds']       = (object) array(
			'settings' => array( 'general_setup_position' => 'woocommerce_checkout_billing' ),
		);

		Digitalogic_Checkout_Experience::stabilize_delivery_fields();

		$this->assertArrayNotHasKey( 'woocommerce_checkout_billing', $GLOBALS['digitalogic_test_action_callbacks'] );
		$this->assertSame( 'woocommerce_checkout_billing', $GLOBALS['iconic_wds']->settings['general_setup_position'] );
	}

	public function test_checkout_assets_include_vendor_ordering_and_localized_validation(): void {
		Digitalogic_Checkout_Experience::enqueue_assets();

		$this->assertArrayHasKey( 'digitalogic-checkout-experience', $GLOBALS['digitalogic_test_enqueued_styles'] );
		$this->assertSame(
			array( 'jquery', 'wc-checkout', 'jckwds-script' ),
			$GLOBALS['digitalogic_test_enqueued_scripts']['digitalogic-checkout-experience']['dependencies']
		);
		$config = $GLOBALS['digitalogic_test_localized_scripts']['digitalogic-checkout-experience']['DigitalogicCheckoutExperience'];
		$this->assertTrue( $config['needsShipping'] );
		$this->assertSame( 'Please complete %s.', $config['messages']['completeField'] );
	}

	public function test_vendor_validation_uses_maintained_translation_strings(): void {
		$this->assertSame(
			'Please choose a shipping method.',
			Digitalogic_Checkout_Experience::translate_delivery_string( 'unchanged', 'Please select a shipping method.', 'jckwds' )
		);
		$this->assertSame(
			'unchanged',
			Digitalogic_Checkout_Experience::translate_delivery_string( 'unchanged', 'Unknown vendor text', 'jckwds' )
		);
	}
}
