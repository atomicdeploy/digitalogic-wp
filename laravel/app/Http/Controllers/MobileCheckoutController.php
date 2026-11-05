<?php
/**
 * Native CheckoutController adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Security, lock and operation metadata must read current SQL state rather than stale cache; canonical commerce writes use Woo/provider APIs.

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Digitalogic\Laravel\Services\MobileCheckoutStore as Store;
use Digitalogic\Laravel\Services\MobileCartStore;
use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Digitalogic\Laravel\Services\MobileRequestStore;
use Digitalogic\Laravel\Services\MobileWooCart;
use Digitalogic\Laravel\Services\MobileAddresses;
use Digitalogic\Laravel\Services\MobileWooCheckout as Woo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Native presentation and idempotency around the installed Woo checkout engine. */
final class MobileCheckoutController {
	/**
	 * Report whether the deployed adapter and its required provider are available.
	 *
	 * @return bool Native adapter result.
	 */
	public static function enabled(): bool {
		return MobileCartController::enabled() && get_option( 'digitalogic_mobile_checkout_schema' ) === '2' && (bool) get_option( 'digitalogic_mobile_native_checkout', false );}
	/**
	 * Resolve the current native owner and canonical Woo cart without browser cookie handoff.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return mixed Native adapter result.
	 */
	private function context( Request $request ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'checkout_unavailable', 'تکمیل خرید موقتاً در دسترس نیست.', array( 'status' => 503 ) );}
		$context = ( new MobileCartController() )->context( $request );
		if ( is_wp_error( $context ) ) {
			return $context;}
		if ( ! $context['session'] ) {
			return new \WP_Error( 'unauthorized', 'ابتدا وارد حساب شوید.', array( 'status' => 401 ) );}
		if ( (int) $context['cart']['user_id'] !== (int) $context['session']['user_id'] ) {
			return new \WP_Error( 'cart_claim_required', 'ابتدا سبد خرید را با حساب همگام کنید.', array( 'status' => 409 ) );}
		return $context;
	}
	/**
	 * Present the canonical Woo checkout state without creating an order.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function show( Request $request ): Response {
		$context = $this->context( $request );
		if ( is_wp_error( $context ) ) {
			return $this->wpError( $context );}
		$woo = new Woo( $context, array() );
		try {
			$woo->prepare( $context, false );
			return $this->json( $woo->presentation( $context ) );
		} finally {
			$woo->close();}
	}
	/**
	 * Bound checkout input and reject client-owned prices or owner identifiers.
	 *
	 * @param mixed $input Validated or pending native input.
	 * @return array|null Native adapter result.
	 */
	public static function normalizeInput( $input ): ?array {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'cart_revision', 'billing', 'shipping', 'ship_to_different_address', 'shipping_methods', 'payment_method', 'coupons', 'customer_note', 'address_locations' ) ) || ! is_int( $input['cart_revision'] ?? null ) || $input['cart_revision'] < 0 ) {
			return null;}
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( isset( $input[ $group ] ) ) {
				if ( ! is_array( $input[ $group ] ) || count( $input[ $group ] ) > 30 ) {
					return null;
				}foreach ( $input[ $group ] as $key => $value ) {
					if ( ! is_string( $key ) || ! preg_match( '/\A[a-z][a-z0-9_]{0,79}\z/D', $key ) || ! is_string( $value ) || mb_strlen( $value ) > 300 ) {
						return null;}
				}
			}
		}
		if ( isset( $input['ship_to_different_address'] ) && ! is_bool( $input['ship_to_different_address'] ) ) {
			return null;}
		if ( isset( $input['payment_method'] ) && ( ! is_string( $input['payment_method'] ) || ! preg_match( '/\A[a-zA-Z0-9_-]{0,80}\z/D', $input['payment_method'] ) ) ) {
			return null;}
		if ( isset( $input['shipping_methods'] ) ) {
			if ( ! is_array( $input['shipping_methods'] ) || count( $input['shipping_methods'] ) > 20 ) {
				return null;
			}foreach ( $input['shipping_methods'] as $key => $value ) {
				if ( ! preg_match( '/\A[0-9]{1,5}\z/D', (string) $key ) || ! is_string( $value ) || strlen( $value ) > 150 ) {
						return null;}
			}
		}
		if ( isset( $input['coupons'] ) ) {
			if ( ! is_array( $input['coupons'] ) || ! array_is_list( $input['coupons'] ) || count( $input['coupons'] ) > 10 ) {
				return null;
			}foreach ( $input['coupons'] as $code ) {
				if ( ! is_string( $code ) || strlen( $code ) > 100 ) {
						return null;}
			}
		}
		if ( isset( $input['customer_note'] ) && ( ! is_string( $input['customer_note'] ) || mb_strlen( $input['customer_note'] ) > 2000 ) ) {
			return null;}
		if ( array_key_exists( 'address_locations', $input ) ) {
			$input['address_locations'] = MobileAddresses::normalize_locations( $input['address_locations'] );
			if ( null === $input['address_locations'] ) {
				return null; }
		}
		return $input;
	}
	/**
	 * Reserve a short-lived quote bound to current canonical checkout state.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 */
	public function quote( Request $request ): Response {
		$context = $this->context( $request );
		if ( is_wp_error( $context ) ) {
			return $this->wpError( $context );}
		if ( ! Identity::admit( 'checkout-quote:' . $context['session']['user_id'], 40, 60 ) ) {
			return $this->error( 'rate_limited', 'کمی بعد دوباره بررسی کنید.', 429 );}
		$input = self::normalizeInput( $request->json()->all() );
		if ( null === $input ) {
			return $this->error( 'invalid_checkout', 'اطلاعات خرید معتبر نیست.', 400 );}
		if ( $input['cart_revision'] !== (int) $context['cart']['revision'] ) {
			return $this->error( 'cart_changed', 'سبد تغییر کرده است؛ دوباره آن را بررسی کنید.', 409 );}
		$woo = new Woo( $context, $input );
		try {
			$woo->prepare( $context, true );
			$result = $woo->presentation( $context );
			if ( ! $result['issues'] ) {
				$quote                = Store::quote( $context, $input, $woo->fingerprint() );
				$result['quote_id']   = $quote['id'];
				$result['expires_at'] = gmdate( 'c', $quote['expires_at'] );
			}return $this->json( $result );
		} finally {
			$woo->close();}
	}
	/**
	 * Submit an explicitly confirmed immutable operation with durable recovery state.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @return Response Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public function submit( Request $request ): Response {
		$context = $this->context( $request );
		if ( is_wp_error( $context ) ) {
			return $this->wpError( $context );}
		$input    = $request->json()->all();
		$id       = $input['operation_id'] ?? '';
		$quote_id = $input['quote_id'] ?? '';
		if ( ! MobileRequestStore::validRequestId( $id ) || ! is_string( $quote_id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $quote_id ) || array_diff( array_keys( $input ), array( 'operation_id', 'quote_id', 'terms_accepted' ) ) ) {
			return $this->error( 'invalid_checkout_operation', 'شناسه تأیید خرید معتبر نیست.', 400 );}
		global $wpdb;
		$user = (int) $context['session']['user_id'];
		$key  = Store::key( $user, $id );
		$lock = 'dgl-checkout-' . substr( $key, 0, 40 );
		$hash = hash( 'sha256', wp_json_encode( array( $quote_id, $input['terms_accepted'] ?? false ) ) );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== 1 ) {
			return $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'processing',
					'status'           => 'processing',
				),
				202
			);}
		try {
			$prior = Store::operation( $user, $id );
			if ( $prior ) {
				if ( ! hash_equals( $prior['payload_hash'], $hash ) ) {
					return $this->json(
						array(
							'operation_id'     => $id,
							'code'             => 'operation_id_conflict',
							'message'          => 'این شناسه با تأیید دیگری استفاده شده است.',
							'operation_status' => 'conflict',
						),
						409
					);
				}return $this->operationResponse( $prior );}
			if ( wc_terms_and_conditions_checkbox_enabled() && ( $input['terms_accepted'] ?? false ) !== true ) {
				return $this->reject( $context, $id, $hash, 'terms_required', 'برای ثبت سفارش، شرایط را تأیید کنید.', 422 );}
			$quotes     = Store::table( 'checkout_quotes' );
			$operations = Store::table( 'checkout_operations' );
			$carts      = MobileCartStore::table( 'carts' );
			$quote      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d AND cart_id=%s', $quotes, $quote_id, $user, $context['cart']['id'] ), ARRAY_A );
			if ( ! $quote || $quote['expires_at'] <= time() ) {
				return $this->reject( $context, $id, $hash, 'quote_expired', 'قیمت و شرایط خرید را دوباره بررسی کنید.', 409 );}
			$wpdb->query( 'START TRANSACTION' );
			$cart = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s FOR UPDATE', $carts, $context['cart']['id'] ), ARRAY_A );
			$cart = $cart ? MobileWooCart::reconcile( $cart ) : null;
			if ( ! $cart || (int) $cart['revision'] !== (int) $quote['cart_revision'] ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->reject( $context, $id, $hash, 'cart_changed', 'سبد تغییر کرده است؛ دوباره آن را بررسی کنید.', 409 );}
			if ( Store::busy( $cart['id'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->reject( $context, $id, $hash, 'checkout_in_progress', 'ابتدا نتیجه تأیید قبلی را دریافت کنید.', 409 );}
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT operation_key FROM %i WHERE quote_id=%s', $operations, $quote_id ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $this->reject( $context, $id, $hash, 'quote_used', 'این تأیید قبلاً استفاده شده است؛ سفارش‌ها را بررسی کنید.', 409 );}
			$row = array(
				'operation_key' => $key,
				'user_id'       => $user,
				'request_id'    => $id,
				'quote_id'      => $quote_id,
				'cart_id'       => $cart['id'],
				'payload_hash'  => $hash,
				'cart_snapshot' => $cart['items'],
				'state'         => 'validating',
				'created_at'    => time(),
				'updated_at'    => time(),
			);
			if ( $wpdb->insert( $operations, $row ) !== 1 || $wpdb->query( 'COMMIT' ) === false ) {
				throw new \RuntimeException( 'operation_storage_failed' );}
			$context['cart'] = $cart;
			$woo             = new Woo( $context, json_decode( $quote['payload'], true ) );
			try {
				if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
					define( 'WOOCOMMERCE_CHECKOUT', true );}
				$woo->prepare( $context, true, true );
				if ( $woo->issues ) {
					return $this->reject( $context, $id, $hash, 'checkout_validation', 'اطلاعات یا شرایط خرید تغییر کرده است؛ دوباره بررسی کنید.', 422, $woo->issues );}
				if ( ! hash_equals( $quote['fingerprint'], $woo->fingerprint() ) ) {
					return $this->reject( $context, $id, $hash, 'quote_changed', 'مبلغ یا روش ارسال تغییر کرده است؛ قیمت تازه را بررسی کنید.', 409 );}
				// Persist the point of no automatic retry BEFORE invoking Woo's order engine.
				if ( $wpdb->update(
					$operations,
					array(
						'state'      => 'creating',
						'updated_at' => time(),
					),
					array( 'operation_key' => $key )
				) !== 1 ) {
					throw new \RuntimeException( 'operation_storage_failed' );}
				$locations = $woo->addressLocations();
				$annotate  = static function ( $order ) use ( $key, $locations ) {
					$order->update_meta_data( '_digitalogic_mobile_operation', $key );
					MobileAddresses::apply_locations( $order, $locations );
				};
				add_action( 'woocommerce_checkout_create_order', $annotate, PHP_INT_MAX, 1 );
				try {
					$woo->engine->nativeCustomer( $woo->data );
					$customer = new \WC_Customer( $user );
					MobileAddresses::apply_locations( $customer, $locations );
					$customer->save();
					$order_id = $woo->engine->create_order( $woo->data );
				} finally {
					remove_action( 'woocommerce_checkout_create_order', $annotate, PHP_INT_MAX );}
				$order = is_wp_error( $order_id ) ? false : wc_get_order( $order_id );
				if ( ! $order ) {
					throw new \RuntimeException( 'order_creation_uncertain' );}
				if ( (int) $order->get_customer_id() !== $user ) {
					throw new \RuntimeException( 'order_owner_mismatch' );}
				if ( $wpdb->update(
					$operations,
					array(
						'order_id'   => $order_id,
						'state'      => 'payment_pending',
						'updated_at' => time(),
					),
					array( 'operation_key' => $key )
				) !== 1 ) {
					throw new \RuntimeException( 'order_mapping_uncertain' );}
				do_action( 'woocommerce_checkout_order_processed', $order_id, $woo->data, $order );
				$payment = array( 'status' => 'not_required' );
				if ( apply_filters( 'woocommerce_cart_needs_payment', $order->needs_payment(), WC()->cart ) ) {
					$gateways = $woo->supportedGateways();
					$gateway  = $gateways[ $woo->data['payment_method'] ] ?? null;
					if ( ! $gateway ) {
						throw new \RuntimeException( 'payment_method_changed' );}
					$result = $gateway->process_payment( $order_id );
					if ( ( $result['result'] ?? '' ) !== 'success' ) {
						throw new \RuntimeException( 'payment_result_uncertain' );}
					$payment = array(
						'status'       => 'awaiting_bank_transfer',
						'instructions' => wp_strip_all_tags( $gateway->get_option( 'instructions', '' ) ),
					);
					// BACS account details are the shop's published transfer instructions, never API credentials.
					$payment['bank_accounts'] = array_map( static fn( $account )=>array_intersect_key( $account, array_flip( array( 'account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic' ) ) ), (array) get_option( 'woocommerce_bacs_accounts', array() ) );
				} else {
					$order->payment_complete();
					WC()->cart->empty_cart();}
				$row['order_id'] = $order_id;
				return $this->finalizeAccepted( $row, wc_get_order( $order_id ), $payment, 201 );
			} finally {
				$woo->close();}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$safe = Store::operation( $user, $id );
			if ( $safe && 'validating' === $safe['state'] ) {
				return $this->reject( $context, $id, $hash, 'checkout_validation', 'امکان تأیید شرایط سفارش نبود؛ دوباره بررسی کنید.', 422 );
			}$operations = Store::table( 'checkout_operations' );
			$wpdb->update(
				$operations,
				array(
					'state'      => 'uncertain',
					'updated_at' => time(),
				),
				array( 'operation_key' => $key )
			);
			$prior = Store::operation( $user, $id );
			return $prior ? $this->operationResponse( $prior ) : $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'processing',
					'status'           => 'processing',
					'code'             => 'checkout_uncertain',
				),
				202
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}
	/**
	 * Persist a terminal precreation rejection so a late replay cannot create an order.
	 *
	 * @param array  $context Verified owner and canonical cart context.
	 * @param string $id Exact scoped identifier.
	 * @param string $hash Hash.
	 * @param string $code Bounded error or challenge code.
	 * @param string $message Message.
	 * @param int    $status HTTP or durable operation status.
	 * @param array  $issues Issues.
	 * @return Response Native adapter result.
	 */
	private function reject( array $context, string $id, string $hash, string $code, string $message, int $status, array $issues = array() ): Response {
		global $wpdb;
		$table    = Store::table( 'checkout_operations' );
		$user     = (int) $context['session']['user_id'];
		$key      = Store::key( $user, $id );
		$body     = array(
			'operation_id'     => $id,
			'operation_status' => 'rejected',
			'status'           => 'rejected',
			'code'             => $code,
			'message'          => $message,
			'issues'           => $issues,
		);
		$existing = Store::operation( $user, $id );
		if ( $existing && ! in_array( $existing['state'], array( 'validating', 'rejected' ), true ) ) {
			return $this->operationResponse( $existing );}
		$values = array(
			'state'      => 'rejected',
			'quote_id'   => null,
			'response'   => wp_json_encode( $body ),
			'updated_at' => time(),
		);
		if ( $existing ) {
			$saved = $wpdb->update( $table, $values, array( 'operation_key' => $key ) );
		} else {
			$saved = $wpdb->insert(
				$table,
				$values + array(
					'operation_key' => $key,
					'user_id'       => $user,
					'request_id'    => $id,
					'cart_id'       => $context['cart']['id'],
					'payload_hash'  => $hash,
					'created_at'    => time(),
				)
			);}
		if ( false === $saved ) {
			return $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'processing',
					'status'           => 'processing',
				),
				202
			);
		}return $this->json( $body, $status );
	}
	/**
	 * Recover an owner-scoped operation without automatically repeating its side effects.
	 *
	 * @param Request $request Bounded native HTTP request.
	 * @param string  $id Exact scoped identifier.
	 * @return Response Native adapter result.
	 */
	public function lookup( Request $request, string $id ): Response {
		$session = Identity::authenticate( $request->headers->get( 'Authorization', '' ) );
		if ( ! $session ) {
			return $this->error( 'unauthorized', 'ابتدا وارد حساب شوید.', 401 );}
		if ( ! MobileRequestStore::validRequestId( $id ) ) {
			return $this->error( 'invalid_operation', 'شناسه معتبر نیست.', 400 );}
		$row = Store::operation( (int) $session['user_id'], $id );
		if ( ! $row ) {
			return $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'unknown',
					'status'           => 'unknown',
					'code'             => 'operation_not_found',
				),
				404
			);}
		global $wpdb;
		$lock = 'dgl-checkout-' . substr( $row['operation_key'], 0, 40 );
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) !== 1 ) {
			return $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'processing',
					'status'           => 'processing',
				),
				202
			);}
		try {
			$row = Store::operation( (int) $session['user_id'], $id );
			return $this->operationResponse( $row );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );}
	}
	/**
	 * Project durable operation state and recover only provable outcomes.
	 *
	 * @param array $row Supporting durable metadata row.
	 * @return Response Native adapter result.
	 */
	private function operationResponse( array $row ): Response {
		// Caller holds the per-operation lock. A dead validating worker never entered create_order.
		if ( 'validating' === $row['state'] ) {
			return $this->reject(
				array(
					'session' => array( 'user_id' => (int) $row['user_id'] ),
					'cart'    => array( 'id' => $row['cart_id'] ),
				),
				$row['request_id'],
				$row['payload_hash'],
				'checkout_interrupted',
				'ثبت سفارش انجام نشده است؛ شرایط خرید را دوباره بررسی کنید.',
				409
			);}
		if ( in_array( $row['state'], array( 'accepted', 'rejected' ), true ) && $row['response'] ) {
			$body = json_decode( $row['response'], true );
			if ( ! empty( $row['order_id'] ) ) {
				$order = wc_get_order( (int) $row['order_id'] );
				if ( $order && (int) $order->get_customer_id() === (int) $row['user_id'] ) {
					$body['order'] = $this->order( $order );
				}
			}return $this->json( $body + array( 'replayed' => true ) );}
		$order = ! empty( $row['order_id'] ) ? wc_get_order( (int) $row['order_id'] ) : null;
		if ( ! $order ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => (int) $row['user_id'],
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Owner-bounded recovery uses the canonical Woo order metadata API.
					'meta_key'    => '_digitalogic_mobile_operation',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact immutable operation key, limit two, scoped to the verified customer.
					'meta_value'  => $row['operation_key'],
					'limit'       => 2,
				)
			);
			if ( count( $orders ) === 1 ) {
				$order = $orders[0];}
		}
		if ( $order && (int) $order->get_customer_id() === (int) $row['user_id'] && $order->get_payment_method() === 'bacs' && $order->has_status( array( 'on-hold', 'processing', 'completed' ) ) ) {
			try {
				return $this->finalizeAccepted( $row, $order, array( 'status' => $order->is_paid() ? 'complete' : 'awaiting_bank_transfer' ), 200 );} catch ( \Throwable $error ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure signal contains no token, phone, customer data or exception details.
				error_log( 'Digitalogic native checkout awaits canonical cart finalization.' );}
		}
		$body = array(
			'operation_id'     => $row['request_id'],
			'operation_status' => 'processing',
			'status'           => 'processing',
			'code'             => 'checkout_uncertain',
		);
		if ( $order && (int) $order->get_customer_id() === (int) $row['user_id'] ) {
			$body['order']   = $this->order( $order );
			$body['payment'] = array( 'status' => 'requires_review' );}
		return $this->json( $body, 202 );
	}
	/**
	 * Finalize the canonical cart once while preserving later website changes.
	 *
	 * @param array $row Supporting durable metadata row.
	 * @param mixed $order Order.
	 * @param array $payment Payment.
	 * @param int   $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 * @throws \Throwable After rollback when the underlying authoritative operation fails.
	 */
	private function finalizeAccepted( array $row, $order, array $payment, int $status ): Response {
		global $wpdb;
		$operations = Store::table( 'checkout_operations' );
		$carts      = MobileCartStore::table( 'carts' );
		$wpdb->query( 'START TRANSACTION' );
		$cart = null;
		try {
			$cart   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s FOR UPDATE', $carts, $row['cart_id'] ), ARRAY_A );
			$latest = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s FOR UPDATE', $operations, $row['operation_key'] ), ARRAY_A );
			if ( $latest && 'accepted' === $latest['state'] && $latest['response'] ) {
				$wpdb->query( 'COMMIT' );
				return $this->json( json_decode( $latest['response'], true ) + array( 'replayed' => true ) );}
			$review   = false;
			$snapshot = json_decode( $row['cart_snapshot'] ?? 'null', true );
			if ( ! $cart || ! is_array( $snapshot ) ) {
				$review = true;} else {
				$cart    = MobileWooCart::reconcile( $cart );
				$current = array_column( json_decode( $cart['items'], true ), 'quantity', 'id' );
				$changes = array();
				foreach ( $snapshot as $line ) {
					$quantity = (int) ( $current[ $line['id'] ] ?? 0 );
					if ( $quantity === (int) $line['quantity'] ) {
						$changes[] = array(
							'id'       => (int) $line['id'],
							'quantity' => 0,
						);
					} elseif ( 0 !== $quantity ) {
						$review = true;}
				}
				if ( $changes ) {
					MobileWooCart::mutate( $cart, $changes );
					$cart = MobileWooCart::reconcile( $cart );}
				}
				$body = array(
					'operation_id'         => $row['request_id'],
					'operation_status'     => 'accepted',
					'status'               => 'accepted',
					'order'                => $this->order( $order ),
					'payment'              => $payment,
					'cart_review_required' => $review,
				);
				if ( $wpdb->update(
					$operations,
					array(
						'state'      => 'accepted',
						'order_id'   => $order->get_id(),
						'response'   => wp_json_encode( $body ),
						'updated_at' => time(),
					),
					array( 'operation_key' => $row['operation_key'] )
				) === false || $wpdb->query( 'COMMIT' ) === false ) {
					throw new \RuntimeException( 'cart_finalization_uncertain' );}
				return $this->json( $body, $status );
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			if ( $cart ) {
				MobileWooCart::invalidate( $cart );
			}throw $error;}
	}
	/**
	 * Project the verified customer-owned order for native confirmation.
	 *
	 * @param mixed $order Order.
	 * @return array Native adapter result.
	 */
	private function order( $order ): array {
		return array(
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'total'        => Woo::money( $order->get_total() ),
			'currency'     => $order->get_currency(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
		);}
	/**
	 * Translate an existing WordPress validation failure into the native error contract.
	 *
	 * @param \WP_Error $error Error.
	 * @return Response Native adapter result.
	 */
	private function wpError( \WP_Error $error ): Response {
		return $this->error( $error->get_error_code(), $error->get_error_message(), (int) ( $error->get_error_data()['status'] ?? 400 ) );}
	/**
	 * Return a bounded error code and customer-facing message.
	 *
	 * @param string $code Bounded error or challenge code.
	 * @param string $message Message.
	 * @param int    $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 */
	private function error( string $code, string $message, int $status ): Response {
		return $this->json(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);}
	/**
	 * Return a native JSON response with the appropriate privacy headers.
	 *
	 * @param array $data Canonical or validated native data.
	 * @param int   $status HTTP or durable operation status.
	 * @return Response Native adapter result.
	 */
	private function json( array $data, int $status = 200 ): Response {
		return response()->json(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);}
}
