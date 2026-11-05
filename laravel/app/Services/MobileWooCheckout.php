<?php
/**
 * Native WooCheckout adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;

/** Executes the installed WooCommerce engine in an isolated, cookie-free native session. */
final class MobileWooCheckout {
	/**
	 * Old session.
	 *
	 * @var mixed
	 */
	private $old_session;
	/**
	 * Old cart.
	 *
	 * @var mixed
	 */
	private $old_cart;
	/**
	 * Old customer.
	 *
	 * @var mixed
	 */
	private $old_customer;
	/**
	 * Old user.
	 *
	 * @var int
	 */
	private int $old_user;
	/**
	 * Old post.
	 *
	 * @var array
	 */
	private array $old_post;
	/**
	 * Removed hooks.
	 *
	 * @var array
	 */
	private array $removed_hooks = array();
	/**
	 * Engine.
	 *
	 * @var mixed
	 */
	public $engine;
	/**
	 * Data.
	 *
	 * @var array
	 */
	public array $data = array();
	/**
	 * Issues.
	 *
	 * @var array
	 */
	public array $issues = array();
	/**
	 * Profile.
	 *
	 * @var array
	 */
	public array $profile = array();
	/**
	 * Input.
	 *
	 * @var array
	 */
	private array $input;
	/**
	 * Cart.
	 *
	 * @var mixed
	 */
	private $cart;

	/**
	 * Bind the adapter to its verified context while preserving the surrounding WordPress runtime.
	 *
	 * @param array $context Verified owner and canonical cart context.
	 * @param array $input Validated or pending native input.
	 * @param bool  $canonical Canonical.
	 * @return void Native adapter result.
	 */
	public function __construct( array $context, array $input, bool $canonical = true ) {
		$this->old_session  = WC()->session;
		$this->old_cart     = WC()->cart;
		$this->old_customer = WC()->customer;
		$this->old_user     = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve the outer global for restoration; native mutation was authenticated separately.
		$this->old_post = $_POST;
		$this->input    = $input;
		// Remove only Woo's browser-cart/session callbacks; keep all business/customization hooks.
		foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && is_object( $function[0] ) && ( $function[0] instanceof \WC_Cart || $function[0] instanceof \WC_Cart_Session ) ) {
						$this->removed_hooks[] = array( $tag, $function, $priority, $callback['accepted_args'] );
						remove_filter( $tag, $function, $priority );}
				}
			}
		}
		wp_set_current_user( (int) $context['session']['user_id'] );
		WC()->session  = $canonical ? new MobileWooSession( $context['cart'] ) : new class( (int) $context['session']['user_id']) extends \WC_Session {
			/**
			 * Bind the adapter to its verified context while preserving the surrounding WordPress runtime.
			 *
			 * @param int $id Exact scoped identifier.
			 * @return void Native adapter result.
			 */
			public function __construct( int $id ) {
				$this->_customer_id = (string) $id;}
			/**
			 * Report that this scoped native Woo session exists.
			 *
			 * @return mixed Native adapter result.
			 */
			public function has_session() {
				return true;}
			/**
			 * Suppress implicit cookie-session writes; explicit persistence owns this operation.
			 *
			 * @return mixed Native adapter result.
			 */
			public function save_data() {}
			/**
			 * Keep native bearer authorization isolated from browser session cookies.
			 *
			 * @param mixed $set Set.
			 * @return mixed Native adapter result.
			 */
			public function set_customer_session_cookie( $set ) {}
			/**
			 * Clear only this scoped session’s in-memory state.
			 *
			 * @return mixed Native adapter result.
			 */
			public function destroy_session() {
				$this->_data = array();}
			/**
			 * Avoid reading a browser cookie as native customer authority.
			 *
			 * @return mixed Native adapter result.
			 */
			public function get_session_cookie() {
				return false;}
		};
		WC()->customer = new \WC_Customer( (int) $context['session']['user_id'] );
		$this->profile = array(
			'billing'  => WC()->customer->get_billing(),
			'shipping' => WC()->customer->get_shipping(),
		);
		add_filter( 'woocommerce_cart_session_initialize', '__return_false', PHP_INT_MAX );
		try {
			$this->cart = new \WC_Cart();
		} finally {
			remove_filter( 'woocommerce_cart_session_initialize', '__return_false', PHP_INT_MAX );}
		WC()->cart    = $this->cart;
		$this->engine = new class() extends \WC_Checkout {
			/**
			 * Invoke the installed Woo checkout validator and its business hooks.
			 *
			 * @param array     $data Canonical or validated native data.
			 * @param \WP_Error $errors Errors.
			 * @return void Native adapter result.
			 */
			public function nativeValidate( array &$data, \WP_Error &$errors ): void {
				$this->validate_checkout( $data, $errors );}
			/**
			 * Persist customer fields through the installed Woo checkout workflow.
			 *
			 * @param array $data Canonical or validated native data.
			 * @return void Native adapter result.
			 */
			public function nativeCustomer( array $data ): void {
				$this->process_customer( $data );}
		};
	}
	/**
	 * Restore the surrounding Woo objects, hooks and current user after native processing.
	 *
	 * @return void Native adapter result.
	 */
	public function close(): void {
		if ( $this->cart ) {
			remove_action( 'woocommerce_add_to_cart', array( $this->cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_applied_coupon', array( $this->cart, 'calculate_totals' ), 20 );
			remove_action( 'woocommerce_removed_coupon', array( $this->cart, 'calculate_totals' ), 20 );}
		foreach ( $this->removed_hooks as [$tag, $function, $priority, $accepted] ) {
			add_filter( $tag, $function, $priority, $accepted );}
		WC()->session  = $this->old_session;
		WC()->cart     = $this->old_cart;
		WC()->customer = $this->old_customer;
		wp_set_current_user( $this->old_user );
		$_POST = $this->old_post;
	}
	/**
	 * Prepare current canonical state and validate input before persistence.
	 *
	 * @param array $context Verified owner and canonical cart context.
	 * @param bool  $validate Validate.
	 * @param bool  $submit Submit.
	 * @return void Native adapter result.
	 */
	public function prepare( array $context, bool $validate, bool $submit = false ): void {
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );}
		add_filter( 'woocommerce_persistent_cart_enabled', '__return_false', PHP_INT_MAX );
		try {
			( new \WC_Cart_Session( $this->cart ) )->get_cart_from_session();
		} finally {
			remove_filter( 'woocommerce_persistent_cart_enabled', '__return_false', PHP_INT_MAX );}
		if ( MobileWooCart::quantities( $this->cart->get_cart() ) !== json_decode( $context['cart']['items'], true ) ) {
			$this->issues[] = array(
				'code'    => 'cart_product_invalid',
				'message' => 'یکی از کالاهای سبد دیگر قابل سفارش نیست؛ سبد را بررسی کنید.',
			);}
		if ( $this->cart->is_empty() ) {
			$this->issues[] = array(
				'code'    => 'cart_empty',
				'message' => 'سبد خرید خالی است.',
			);}
		$posted = array(
			'payment_method'            => $this->input['payment_method'] ?? '',
			'shipping_method'           => $this->input['shipping_methods'] ?? WC()->session->get( 'chosen_shipping_methods', array() ),
			'ship_to_different_address' => ! empty( $this->input['ship_to_different_address'] ),
			'order_comments'            => $this->input['customer_note'] ?? '',
			'terms'                     => 1,
			'terms-field'               => wc_terms_and_conditions_checkbox_enabled() ? 1 : 0,
		);
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$address = $this->input[ $group ] ?? $this->profile[ $group ];
			foreach ( $address as $key => $value ) {
				$posted[ $group . '_' . $key ] = $value;}
		}
		if ( empty( $posted['billing_country'] ) ) {
			$posted['billing_country'] = WC()->countries->get_base_country();}
		if ( empty( $posted['shipping_country'] ) ) {
			$posted['shipping_country'] = $posted['billing_country'];}
		// Set customer location before country-specific fields are built. No customer save during quote.
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$address = ( 'shipping' === $group && empty( $posted['ship_to_different_address'] ) ) ? 'billing' : $group;
			foreach ( array( 'first_name', 'last_name', 'company', 'country', 'state', 'city', 'address_1', 'address_2', 'postcode', 'phone', 'email' ) as $key ) {
				$setter = 'set_' . $group . '_' . $key;
				if ( is_callable( array( WC()->customer, $setter ) ) && isset( $posted[ $address . '_' . $key ] ) ) {
					try {
								WC()->customer->$setter( $posted[ $address . '_' . $key ] );
					} catch ( \WC_Data_Exception $error ) {
						$this->issues[] = array(
							'code'    => 'invalid_field',
							'message' => wp_strip_all_tags( $error->getMessage() ),
							'field'   => $group . '_' . $key,
						);}
				}
			}
		}
		WC()->customer->set_calculated_shipping( true );
		$_POST = wp_slash( $posted );
		if ( $submit ) {
			do_action( 'woocommerce_before_checkout_process' );
			do_action( 'woocommerce_checkout_process' );
		}$this->data                 = $this->engine->get_posted_data();
		$this->data['createaccount'] = 0;
		WC()->session->set( 'chosen_shipping_methods', $posted['shipping_method'] );
		WC()->session->set( 'chosen_payment_method', $posted['payment_method'] );
		$coupons = $this->input['coupons'] ?? $this->cart->get_applied_coupons();
		$this->cart->set_applied_coupons( array() );
		foreach ( $coupons as $coupon ) {
			if ( ! $this->cart->apply_coupon( $coupon ) ) {
				$this->issues[] = array(
					'code'    => 'coupon_invalid',
					'message' => 'کد تخفیف قابل استفاده نیست.',
				);}
		}
		$this->cart->calculate_totals();
		if ( $validate ) {
			$errors = new \WP_Error();
			$this->engine->nativeValidate( $this->data, $errors );
			foreach ( $errors->errors as $code => $messages ) {
				foreach ( $messages as $message ) {
					$this->issues[] = array(
						'code'    => (string) $code,
						'message' => wp_strip_all_tags( $message ),
						'field'   => (string) ( $errors->get_error_data( $code )['id'] ?? '' ),
					);}
			}
		}
		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$this->issues[] = array(
				'code'    => 'woocommerce_validation',
				'message' => wp_strip_all_tags( $notice['notice'] ),
			);
		}wc_clear_notices();
		if ( $this->cart->needs_payment() && ! isset( $this->supportedGateways()[ $this->data['payment_method'] ] ) ) {
			$this->issues[] = array(
				'code'    => 'payment_method_required',
				'message' => 'روش پرداخت قابل استفاده در برنامه را انتخاب کنید.',
				'field'   => 'payment_method',
			);}
	}
	/**
	 * Explicit adapters, not arbitrary PHP gateway HTML. More adapters can be enabled after audit.
	 *
	 * @return array Native adapter result.
	 */
	public function supportedGateways(): array {
		$available = WC()->payment_gateways()->get_available_payment_gateways();
		$supported = array();
		foreach ( array( 'bacs' ) as $id ) {
			if ( isset( $available[ $id ] ) && ! $available[ $id ]->has_fields ) {
				$supported[ $id ] = $available[ $id ];}
		}
		return $supported;
	}
	/**
	 * Project canonical state and supported native controls.
	 *
	 * @param array $context Verified owner and canonical cart context.
	 * @return array Native adapter result.
	 */
	public function presentation( array $context ): array {
		$schema = array(
			'billing'  => array(),
			'shipping' => array(),
		);
		foreach ( $schema as $group => $_ ) {
			$getter           = 'get_' . $group . '_country';
			$schema[ $group ] = MobileAddresses::descriptors( $this->engine->get_checkout_fields( $group ), $group, WC()->customer->$getter() );
		}
		$packages = array();
		$selected = WC()->session->get( 'chosen_shipping_methods', array() );
		foreach ( WC()->shipping()->get_packages() as $id => $package ) {
			$rates = array();
			foreach ( $package['rates'] ?? array() as $rate ) {
				$rates[] = array(
					'id'    => $rate->get_id(),
					'label' => wp_strip_all_tags( $rate->get_label() ),
					'cost'  => self::money( $rate->get_cost() ),
					'taxes' => self::money( array_sum( $rate->get_taxes() ) ),
				);
			}$packages[] = array(
				'package_id'       => (string) $id,
				'rates'            => $rates,
				'selected_rate_id' => $selected[ $id ] ?? null,
			);}
		$payments = array();
		foreach ( $this->supportedGateways() as $gateway ) {
			$payments[] = array(
				'id'          => $gateway->id,
				'title'       => wp_strip_all_tags( $gateway->get_title() ),
				'description' => wp_strip_all_tags( $gateway->get_description() ),
			);}
		$totals   = $this->cart->get_totals();
		$terms_id = wc_terms_and_conditions_page_id();
		$terms    = $terms_id ? get_post( $terms_id ) : null;
		return array(
			'cart_revision'     => (int) $context['cart']['revision'],
			'schema'            => $schema,
			'profile'           => array(
				'billing'  => WC()->customer->get_billing(),
				'shipping' => WC()->customer->get_shipping(),
			),
			'address_locations' => $this->addressLocations(),
			'address_map'       => MobileAddresses::map_config(),
			'shipping_packages' => $packages,
			'payment_methods'   => $payments,
			'payment_method'    => $this->data['payment_method'] ?? '',
			'coupons'           => $this->cart->get_applied_coupons(),
			'totals'            => array(
				'subtotal' => self::money( $totals['subtotal'] ),
				'discount' => self::money( $totals['discount_total'] ),
				'shipping' => self::money( $totals['shipping_total'] ),
				'fees'     => self::money( $totals['fee_total'] ),
				'tax'      => self::money( $totals['total_tax'] ),
				'total'    => self::money( $totals['total'] ),
			),
			'currency'          => array(
				'code'       => get_woocommerce_currency(),
				'minor_unit' => wc_get_price_decimals(),
				'symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			),
			'terms'             => array(
				'required' => wc_terms_and_conditions_checkbox_enabled(),
				'label'    => wp_strip_all_tags( wc_get_terms_and_conditions_checkbox_text() ),
				'text'     => $terms ? wp_strip_all_tags( strip_shortcodes( $terms->post_content ) ) : '',
			),
			'issues'            => $this->issues,
			'quote_id'          => null,
			'expires_at'        => null,
		);
	}
	/**
	 * Quote/order locations follow the actual selected billing/shipping address.
	 *
	 * @return array Native adapter result.
	 */
	public function addressLocations(): array {
		$locations = array_replace( MobileAddresses::locations( WC()->customer ), $this->input['address_locations'] ?? array() );
		if ( empty( $this->input['ship_to_different_address'] ) ) {
			$locations['shipping'] = $locations['billing'];
		}
		return $locations;
	}
	/**
	 * Bind an immutable quote to current cart, address, shipping, payment and price data.
	 *
	 * @return string Native adapter result.
	 */
	public function fingerprint(): string {
		$lines = array();
		foreach ( $this->cart->get_cart() as $key => $line ) {
			$lines[] = array(
				'id'       => ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'],
				'quantity' => $line['quantity'],
				'subtotal' => $line['line_subtotal'],
				'total'    => $line['line_total'],
				'tax'      => $line['line_tax'],
				'tax_data' => $line['line_tax_data'],
			);}
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'data'              => $this->data,
					'address_locations' => $this->addressLocations(),
					'lines'             => $lines,
					'totals'            => $this->cart->get_totals(),
					'shipping'          => WC()->session->get( 'chosen_shipping_methods', array() ),
					'coupons'           => $this->cart->get_applied_coupons(),
					'currency'          => get_woocommerce_currency(),
				)
			)
		);
	}
	/**
	 * Format an amount using the current Woo currency precision.
	 *
	 * @param mixed $value Value.
	 * @return string Native adapter result.
	 */
	public static function money( $value ): string {
		return MobileCatalogController::minorUnits( (string) $value, wc_get_price_decimals() ) ?? '0';}
}
