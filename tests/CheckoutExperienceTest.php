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

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
	function wc_get_checkout_url() { return 'https://digitalogic.test/checkout/'; }
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() { return ! empty( $GLOBALS['digitalogic_test_checkout_ajax'] ); }
}
if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $endpoint = '' ) { return false; }
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
		$GLOBALS['digitalogic_test_current_user_id'] = 0;
		$GLOBALS['digitalogic_test_checkout_ajax'] = false;
		$GLOBALS['digitalogic_test_options']['woocommerce_enable_guest_checkout'] = 'no';
		$GLOBALS['digitalogic_test_options']['woocommerce_enable_signup_and_login_from_checkout'] = 'no';
		$GLOBALS['digitalogic_test_wc_runtime'] = (object) array(
			'cart' => new class() {
				public function needs_shipping(): bool {
					return true;
				}
			},
		);
	}

	public function test_guest_checkout_returns_to_checkout_after_login(): void {
		$this->assertSame(
			'https://digitalogic.test/wp-login.php?redirect_to=' . rawurlencode( wc_get_checkout_url() ),
			Digitalogic_Checkout_Experience::checkout_login_redirect()
		);
		$GLOBALS['digitalogic_test_is_checkout'] = false;
		$this->assertSame( '', Digitalogic_Checkout_Experience::checkout_login_redirect() );
	}

	public function test_signed_in_customer_can_continue_without_redirect_or_rejection(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 10;
		$this->assertSame( '', Digitalogic_Checkout_Experience::checkout_login_redirect() );
		Digitalogic_Checkout_Experience::require_checkout_login();
		$request = ( new WP_REST_Request() )->set_route( '/wc/store/v1/checkout' )->set_method( 'POST' );
		$this->assertNull( Digitalogic_Checkout_Experience::require_store_checkout_login( null, null, $request ) );
	}

	public function test_classic_guest_request_is_rejected_before_checkout_processing(): void {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'برای ثبت سفارش، ابتدا وارد حساب کاربری شوید یا ثبت‌نام کنید.' );
		Digitalogic_Checkout_Experience::require_checkout_login();
	}

	public function test_store_api_guest_checkout_is_rejected_without_creating_a_draft(): void {
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$request = ( new WP_REST_Request() )->set_route( '/wc/store/v1/checkout' )->set_method( $method );
			$result = Digitalogic_Checkout_Experience::require_store_checkout_login( null, null, $request );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'digitalogic_checkout_login_required', $result->get_error_code() );
			$this->assertSame( 401, $result->get_error_data()['status'] );
		}
	}

	public function test_cart_api_and_preflight_and_existing_responses_are_preserved(): void {
		$request = ( new WP_REST_Request() )->set_route( '/wc/store/v1/cart' )->set_method( 'POST' );
		$this->assertNull( Digitalogic_Checkout_Experience::require_store_checkout_login( null, null, $request ) );
		$request->set_route( '/wc/store/v1/checkout' )->set_method( 'OPTIONS' );
		$this->assertNull( Digitalogic_Checkout_Experience::require_store_checkout_login( null, null, $request ) );
		$request->set_method( 'POST' );
		$existing = new WP_Error( 'previous_error' );
		$this->assertSame( $existing, Digitalogic_Checkout_Experience::require_store_checkout_login( $existing, null, $request ) );
	}

	public function test_received_order_and_ajax_requests_are_not_redirected(): void {
		$GLOBALS['digitalogic_test_is_order_received_page'] = true;
		$this->assertSame( '', Digitalogic_Checkout_Experience::checkout_login_redirect() );
		$GLOBALS['digitalogic_test_is_order_received_page'] = false;
		$GLOBALS['digitalogic_test_checkout_ajax'] = true;
		$this->assertSame( '', Digitalogic_Checkout_Experience::checkout_login_redirect() );
	}

	public function test_native_guest_or_checkout_registration_settings_are_respected(): void {
		foreach ( array( 'woocommerce_enable_guest_checkout', 'woocommerce_enable_signup_and_login_from_checkout' ) as $option ) {
			$GLOBALS['digitalogic_test_options'][ $option ] = 'yes';
			$this->assertSame( '', Digitalogic_Checkout_Experience::checkout_login_redirect() );
			Digitalogic_Checkout_Experience::require_checkout_login();
			$GLOBALS['digitalogic_test_options'][ $option ] = 'no';
		}
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

		$this->assertSame( 'add_manually', $GLOBALS['iconic_wds']->settings['general_setup_position'] );
		$this->assertSame( array(), array_values( $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_order_review'] ) );
		$this->assertSame(
			array( Digitalogic_Checkout_Experience::class, 'render_delivery_fields' ),
			$GLOBALS['digitalogic_test_action_callbacks']['woocommerce_after_checkout_billing_form'][0]['callback']
		);
		$this->assertSame( 20, $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_after_checkout_billing_form'][0]['priority'] );
		$this->assertSame( 5, $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_before_order_review'][0]['priority'] );
	}

	public function test_explicit_vendor_position_is_replaced_with_manual_stable_rendering(): void {
		$dates = new Digitalogic_Test_Delivery_Dates();
		$GLOBALS['iconic_wds_dates'] = $dates;
		$GLOBALS['iconic_wds']       = (object) array(
			'settings' => array( 'general_setup_position' => 'woocommerce_checkout_billing' ),
		);
		$GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_billing'][] = array(
			'callback'      => array( $dates, 'display_checkout_fields' ),
			'priority'      => 10,
			'accepted_args' => 1,
		);

		Digitalogic_Checkout_Experience::stabilize_delivery_fields();

		$this->assertSame( array(), array_values( $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_checkout_billing'] ) );
		$this->assertSame( 'add_manually', $GLOBALS['iconic_wds']->settings['general_setup_position'] );
		$this->assertArrayHasKey( 'woocommerce_after_checkout_billing_form', $GLOBALS['digitalogic_test_action_callbacks'] );
	}

	public function test_checkout_assets_include_vendor_ordering_and_localized_validation(): void {
		$GLOBALS['iconic_wds'] = (object) array(
			'settings' => array(
				'datesettings_datesettings_setup_mandatory' => 0,
				'timesettings_timesettings_setup_mandatory' => '1',
			),
		);
		Digitalogic_Checkout_Experience::enqueue_assets();

		$this->assertArrayHasKey( 'digitalogic-checkout-experience', $GLOBALS['digitalogic_test_enqueued_styles'] );
		$this->assertSame(
			array( 'jquery', 'wc-checkout', 'jckwds-script' ),
			$GLOBALS['digitalogic_test_enqueued_scripts']['digitalogic-checkout-experience']['dependencies']
		);
		$config = $GLOBALS['digitalogic_test_localized_scripts']['digitalogic-checkout-experience']['DigitalogicCheckoutExperience'];
		$this->assertTrue( $config['needsShipping'] );
		$this->assertFalse( $config['deliveryDateRequired'] );
		$this->assertTrue( $config['deliveryTimeRequired'] );
		$this->assertSame( 'Please complete %s.', $config['messages']['completeField'] );
	}

	public function test_vendor_validation_uses_maintained_translation_strings(): void {
		$this->assertSame(
			'Please choose a shipping method.',
			Digitalogic_Checkout_Experience::translate_delivery_string( 'unchanged', 'Please select a shipping method.', 'jckwds' )
		);
		$this->assertSame(
			'Delivery date and time',
			Digitalogic_Checkout_Experience::translate_delivery_string( 'unchanged', 'Delivery Date & Time', 'jckwds' )
		);
		$this->assertSame(
			'Change',
			Digitalogic_Checkout_Experience::translate_delivery_string( 'unchanged', 'Change', 'jckwds' )
		);
		$this->assertSame(
			'unchanged',
			Digitalogic_Checkout_Experience::translate_delivery_string( 'unchanged', 'Unknown vendor text', 'jckwds' )
		);
	}
}
