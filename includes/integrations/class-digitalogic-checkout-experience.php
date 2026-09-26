<?php
/**
 * Checkout compatibility, localization, and progressive validation.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps checkout controls visible and understandable across vendor refreshes.
 */
final class Digitalogic_Checkout_Experience {
	private const SCRIPT_HANDLE = 'digitalogic-checkout-experience';
	private const SCRIPT_FILE   = 'assets/js/checkout-experience.js';
	private const STYLE_HANDLE  = 'digitalogic-checkout-experience';
	private const STYLE_FILE    = 'assets/css/checkout-experience.css';
	private const STABLE_HOOK   = 'woocommerce_after_checkout_billing_form';
	private const FALLBACK_HOOK = 'woocommerce_checkout_before_order_review';
	private const MANUAL_HOOK   = 'add_manually';

	/**
	 * Register the checkout integration once.
	 */
	public static function init(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;
		add_action( 'wp_loaded', array( self::class, 'stabilize_delivery_fields' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ), 90 );

		add_filter( 'iconic_wds_delivery_details_text', array( self::class, 'delivery_details_label' ) );
		add_filter( 'iconic_wds_delivery_date_text', array( self::class, 'delivery_date_label' ) );
		add_filter( 'iconic_wds_select_delivery_date_text', array( self::class, 'select_delivery_date_label' ) );
		add_filter( 'iconic_wds_choose_delivery_date_text', array( self::class, 'choose_delivery_date_label' ) );
		add_filter( 'iconic_wds_select_date_first_text', array( self::class, 'select_date_first_label' ) );
		add_filter( 'iconic_wds_time_slot_text', array( self::class, 'delivery_time_label' ) );
		add_filter( 'iconic_wds_choose_time_slot_text', array( self::class, 'choose_delivery_time_label' ) );
		add_filter( 'iconic_wds_select_time_slot_text', array( self::class, 'select_delivery_time_label' ) );
		add_filter( 'iconic_wds_no_slots_available_text', array( self::class, 'no_slots_label' ) );
		add_filter( 'gettext_jckwds', array( self::class, 'translate_delivery_string' ), 10, 3 );
	}

	/**
	 * Move Delivery Slots outside the AJAX-replaced order-review fragment.
	 *
	 * Elementor and WooCommerce replace #order_review after the initial page
	 * render. Vendor fields attached inside that fragment disappear, while the
	 * vendor's server-side required-field validation remains registered.
	 */
	public static function stabilize_delivery_fields(): void {
		global $iconic_wds, $iconic_wds_dates;

		if ( ! is_object( $iconic_wds ) || ! is_object( $iconic_wds_dates ) ) {
			return;
		}

		$settings = isset( $iconic_wds->settings ) && is_array( $iconic_wds->settings ) ? $iconic_wds->settings : array();
		$position = isset( $settings['general_setup_position'] ) ? (string) $settings['general_setup_position'] : '';
		if ( ! is_callable( array( $iconic_wds_dates, 'display_checkout_fields' ) ) ) {
			return;
		}

		$priority = isset( $settings['general_setup_position_priority'] ) ? (int) $settings['general_setup_position_priority'] : 10;
		$callback = array( $iconic_wds_dates, 'display_checkout_fields' );

		if ( self::MANUAL_HOOK !== $position && 0 === strpos( $position, 'woocommerce_' ) ) {
			remove_action( $position, $callback, $priority );
		}

		$iconic_wds->settings['general_setup_position'] = self::MANUAL_HOOK;
		add_action( self::STABLE_HOOK, array( self::class, 'render_delivery_fields' ), 20 );
		add_action( self::FALLBACK_HOOK, array( self::class, 'render_delivery_fields' ), 5 );
	}

	/**
	 * Render vendor delivery controls once in the first stable hook available.
	 */
	public static function render_delivery_fields(): void {
		global $iconic_wds_dates;

		static $rendered = false;

		if ( $rendered || ! is_object( $iconic_wds_dates ) || ! is_callable( array( $iconic_wds_dates, 'display_checkout_fields' ) ) ) {
			return;
		}

		$rendered = true;
		$iconic_wds_dates->display_checkout_fields();
	}

	/**
	 * Load the checkout-only progressive enhancement layer.
	 */
	public static function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}

		$style_path  = DIGITALOGIC_PLUGIN_DIR . self::STYLE_FILE;
		$script_path = DIGITALOGIC_PLUGIN_DIR . self::SCRIPT_FILE;
		if ( ! is_readable( $style_path ) || ! is_readable( $script_path ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			DIGITALOGIC_PLUGIN_URL . self::STYLE_FILE,
			array(),
			(string) filemtime( $style_path )
		);

		$dependencies = array( 'jquery', 'wc-checkout' );
		if ( function_exists( 'wp_script_is' ) && wp_script_is( 'jckwds-script', 'registered' ) ) {
			$dependencies[] = 'jckwds-script';
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			DIGITALOGIC_PLUGIN_URL . self::SCRIPT_FILE,
			$dependencies,
			(string) filemtime( $script_path ),
			true
		);

		$needs_shipping = false;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$needs_shipping = (bool) WC()->cart->needs_shipping();
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'DigitalogicCheckoutExperience',
			array(
				'needsShipping' => $needs_shipping,
				'messages'      => array(
					/* translators: %s: Visible checkout field label. */
					'completeField'       => __( 'Please complete %s.', 'digitalogic' ),
					/* translators: %s: Visible checkout field label. */
					'invalidField'        => __( 'Please enter a valid value for %s.', 'digitalogic' ),
					'chooseShipping'      => __( 'Please choose a shipping method.', 'digitalogic' ),
					'choosePayment'       => __( 'Please choose a payment method.', 'digitalogic' ),
					'acceptTerms'         => __( 'Please accept the terms and conditions.', 'digitalogic' ),
					'shippingUnavailable' => __( 'No shipping method is available for this address. Please review the address or contact support.', 'digitalogic' ),
					'reviewFields'        => __( 'Please review the highlighted fields before placing the order.', 'digitalogic' ),
					'ready'               => __( 'The order information is ready to submit.', 'digitalogic' ),
					'incomplete'          => __( 'Complete the required information to place the order.', 'digitalogic' ),
					'fieldFallback'       => __( 'this field', 'digitalogic' ),
				),
			)
		);
	}

	/** Return the localized delivery-details heading. */
	public static function delivery_details_label(): string {
		return __( 'Delivery details', 'digitalogic' );
	}

	/** Return the localized delivery-date label. */
	public static function delivery_date_label(): string {
		return __( 'Delivery date', 'digitalogic' );
	}

	/** Return the localized date placeholder. */
	public static function select_delivery_date_label(): string {
		return __( 'Choose a delivery date', 'digitalogic' );
	}

	/** Return the localized date help. */
	public static function choose_delivery_date_label(): string {
		return __( 'Choose the date on which you want to receive the order.', 'digitalogic' );
	}

	/** Return the localized time placeholder shown before a date is selected. */
	public static function select_date_first_label(): string {
		return __( 'Choose the delivery date first', 'digitalogic' );
	}

	/** Return the localized time-window label. */
	public static function delivery_time_label(): string {
		return __( 'Delivery time window', 'digitalogic' );
	}

	/** Return the localized time-window help. */
	public static function choose_delivery_time_label(): string {
		return __( 'Choose the preferred delivery time window.', 'digitalogic' );
	}

	/** Return the localized time-window placeholder. */
	public static function select_delivery_time_label(): string {
		return __( 'Choose a delivery time window', 'digitalogic' );
	}

	/** Return the localized no-slots label. */
	public static function no_slots_label(): string {
		return __( 'No delivery time is available for this date.', 'digitalogic' );
	}

	/**
	 * Supply missing vendor translations through the maintained text domain.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 */
	public static function translate_delivery_string( string $translation, string $text, string $domain ): string {
		if ( 'jckwds' !== $domain ) {
			return $translation;
		}

		switch ( $text ) {
			case 'Delivery Details':
			case 'Delivery details':
				return self::delivery_details_label();
			case 'Delivery Date & Time':
				return __( 'Delivery date and time', 'digitalogic' );
			case 'Change':
				return __( 'Change', 'digitalogic' );
			case 'Change your delivery slot':
			case 'Change Delivery Slot':
				return __( 'Change delivery time', 'digitalogic' );
			case '+ Add Time Slot':
				return __( 'Add delivery time', 'digitalogic' );
			case 'Please select a shipping method.':
				return __( 'Please choose a shipping method.', 'digitalogic' );
			case 'Please select a delivery date.':
				return __( 'Please choose a delivery date.', 'digitalogic' );
			case 'Please select a time slot.':
				return __( 'Please choose a delivery time window.', 'digitalogic' );
			case 'Enter your address to view available time slots.':
				return __( 'Complete the delivery address to view available delivery times.', 'digitalogic' );
			case 'Sorry, there are no dates available for that shipping method. Please select another method or try again later.':
				return __( 'No delivery date is available for this shipping method. Choose another method or try again later.', 'digitalogic' );
			case 'Loading...':
				return __( 'Loading delivery times...', 'digitalogic' );
			case 'Available':
				return __( 'Available', 'digitalogic' );
			case 'Unavailable':
				return __( 'Unavailable', 'digitalogic' );
			default:
				return $translation;
		}
	}
}
