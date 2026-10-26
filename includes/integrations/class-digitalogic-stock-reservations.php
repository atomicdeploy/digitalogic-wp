<?php
/**
 * Bounded unpaid-order reservations and current cart availability.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Keep reservation expiry, receipts, and delayed payments consistent. */
final class Digitalogic_Stock_Reservations {
	public const ENABLED_AT = 'digitalogic_stock_reservations_enabled_at';
	public const DEADLINE   = '_digitalogic_stock_deadline';
	public const EXPIRED    = '_digitalogic_stock_expired_at';
	public const UNASSIGNED = '_digitalogic_payment_stock_review';
	public const EXPIRE_JOB = 'digitalogic_expire_unpaid_stock';
	private const GROUP     = 'digitalogic-stock';
	/**
	 * Locks held by this PHP request.
	 *
	 * @var array<int,bool>
	 */
	private static $locks = array();
	/**
	 * Payments that could not serialize with expiry.
	 *
	 * @var array<int,bool>
	 */
	private static $unlocked_payments = array();
	/**
	 * Verified payment_complete calls, excluding ordinary status/date checks.
	 *
	 * @var array<int,bool>
	 */
	private static $completing_payments = array();

	/** Register with the existing plugin and Action Scheduler. */
	public static function init(): void {
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'track_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'track_order' ), 20 );
		add_action( self::EXPIRE_JOB, array( self::class, 'expire_order' ) );
		add_action( 'woocommerce_update_order', array( self::class, 'reschedule_order' ), 40 );
		add_action( 'woocommerce_before_checkout_process', array( self::class, 'forget_expired_order' ), 5 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'protect_receipt' ), 100, 2 );
		add_filter( 'woocommerce_order_needs_payment', array( self::class, 'needs_payment' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_pre_payment_complete', array( self::class, 'begin_payment' ), 0 );
		add_filter( 'woocommerce_payment_complete_order_status', array( self::class, 'payment_status' ), PHP_INT_MAX, 3 );
		add_filter( 'woocommerce_can_reduce_order_stock', array( self::class, 'can_reduce_stock' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', array( self::class, 'should_reduce_stock' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'release_lock' ), PHP_INT_MAX );
		add_action( 'digitalogic_before_payment_receipt', array( self::class, 'before_receipt' ) );
		add_action( 'digitalogic_payment_receipt_submitted', array( self::class, 'after_receipt' ) );
		add_action( 'woocommerce_thankyou', array( self::class, 'render_deadline' ), 3 );
		add_action( 'woocommerce_view_order', array( self::class, 'render_deadline' ), 3 );
		add_action( 'wc_ajax_digitalogic_cart_stock', array( self::class, 'cart_stock' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ), 120 );
		add_filter( 'woocommerce_cart_item_name', array( self::class, 'cart_item_marker' ), 100, 3 );
		add_filter( 'woocommerce_order_actions', array( self::class, 'admin_actions' ), 20, 2 );
		add_action( 'woocommerce_order_action_digitalogic_allocate_late_payment', array( self::class, 'allocate_late_payment' ) );
	}

	/**
	 * Return a fixed deadline; viewing or editing an order does not extend it.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return int
	 */
	public static function deadline( $order ): int {
		return (int) $order->get_meta( self::DEADLINE, true );
	}

	/**
	 * Never retrospectively enroll historical orders or scheduled installments.
	 *
	 * @param WC_Order $order Candidate checkout order.
	 * @return bool
	 */
	public static function is_new_checkout_order( $order ): bool {
		$enabled = (int) get_option( self::ENABLED_AT, 0 );
		$created = $order->get_date_created();
		return $enabled > 0 && $created && $created->getTimestamp() >= $enabled
			&& 0 === (int) $order->get_parent_id()
			&& in_array( $order->get_created_via(), array( 'checkout', 'store-api' ), true )
			&& ! ( class_exists( 'WC_Pre_Orders_Order' ) && WC_Pre_Orders_Order::order_contains_pre_order( $order ) );
	}

	/**
	 * Distinguish an unverified unpaid order from received payment evidence.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return bool
	 */
	public static function has_payment_evidence( $order ): bool {
		if ( $order->is_paid() || $order->get_date_paid() || '' !== $order->get_transaction_id()
			|| in_array( $order->get_meta( '_wc_deposit_paid', true ), array( 'yes', '1', 1, true ), true ) ) {
			return true;
		}
		$status = (string) $order->get_meta( '_digitalogic_payment_receipt_status', true );
		if ( 'approved' === $status ) {
			return true;
		}
		$submitted = (int) $order->get_meta( '_digitalogic_receipt_intake_at', true );
		if ( ! $submitted ) {
			$submitted = (int) strtotime( (string) $order->get_meta( '_digitalogic_payment_receipt_submitted_at', true ) );
		}
		return 'submitted' === $status && $submitted > 0 && $submitted <= self::deadline( $order )
			&& '' !== (string) $order->get_meta( '_digitalogic_payment_receipt_reference', true )
			&& is_file( (string) $order->get_meta( '_digitalogic_payment_receipt_file', true ) );
	}

	/**
	 * Pure expiry decision, also used by the payment and receipt paths.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @param int|null $now UTC timestamp, default current time.
	 * @return bool
	 */
	public static function should_expire( $order, ?int $now = null ): bool {
		return self::deadline( $order ) > 0 && ( $now ?? time() ) >= self::deadline( $order )
			&& $order->has_status( array( 'pending', 'on-hold' ) ) && ! self::has_payment_evidence( $order );
	}

	/**
	 * Start the one-hour clock only when the customer creates an order.
	 *
	 * @param WC_Order $order Newly created checkout order.
	 */
	public static function track_order( $order ): void {
		if ( ! self::is_new_checkout_order( $order ) ) {
			return;
		}
		if ( ! self::deadline( $order ) ) {
			$order->update_meta_data( self::DEADLINE, $order->get_date_created()->getTimestamp() + HOUR_IN_SECONDS );
			$order->save();
		}
		self::schedule( $order );
		self::publish_stock( $order );
	}

	/**
	 * Schedule an exact per-order action rather than wait for an hourly sweep.
	 *
	 * @param WC_Order $order Order with a fixed deadline.
	 */
	private static function schedule( $order ): void {
		if ( self::deadline( $order ) && $order->has_status( array( 'pending', 'on-hold' ) )
			&& ! self::has_payment_evidence( $order ) && function_exists( 'as_has_scheduled_action' )
			&& ! as_has_scheduled_action( self::EXPIRE_JOB, array( $order->get_id() ), self::GROUP ) ) {
			as_schedule_single_action( max( time() + 1, self::deadline( $order ) ), self::EXPIRE_JOB, array( $order->get_id() ), self::GROUP, true );
		}
	}

	/**
	 * Rejected receipts resume the original deadline, never a new hour.
	 *
	 * @param int $order_id Updated order ID.
	 */
	public static function reschedule_order( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && self::deadline( $order ) ) {
			self::schedule( $order );
		}
	}

	/** Start a fresh order rather than resume an expired checkout session. */
	public static function forget_expired_order(): void {
		if ( ! WC()->session ) {
			return;
		}
		$id    = absint( WC()->session->get( 'order_awaiting_payment' ) );
		$order = $id ? wc_get_order( $id ) : false;
		if ( $order && self::deadline( $order ) && time() >= self::deadline( $order ) && ! self::has_payment_evidence( $order ) ) {
			self::expire_order( $id );
			WC()->session->set( 'order_awaiting_payment', false );
		}
	}

	/**
	 * Serialize expiry with receipt intake and verified gateway callbacks.
	 *
	 * @param int $order_id Order to lock.
	 * @return bool
	 */
	public static function lock_order( int $order_id ): bool {
		global $wpdb;
		if ( isset( self::$locks[ $order_id ] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-scoped advisory lock, shared by all order writers in this integration.
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'digitalogic-stock-' . $order_id ) );
		if ( $locked ) {
			self::$locks[ $order_id ] = true;
			register_shutdown_function( array( self::class, 'release_lock' ), $order_id );
		}
		return $locked;
	}

	/**
	 * Release only a lock owned by this request.
	 *
	 * @param int $order_id Order whose lock is held.
	 */
	public static function release_lock( $order_id ): void {
		global $wpdb;
		$order_id = (int) $order_id;
		unset( self::$completing_payments[ $order_id ], self::$unlocked_payments[ $order_id ] );
		if ( isset( self::$locks[ $order_id ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the paired advisory lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'digitalogic-stock-' . $order_id ) );
			unset( self::$locks[ $order_id ] );
		}
	}

	/**
	 * Cancel once through WooCommerce so stock restoration remains idempotent.
	 *
	 * @param int $order_id Order whose deadline is due.
	 */
	public static function expire_order( $order_id ): void {
		$order_id = (int) $order_id;
		if ( ! self::lock_order( $order_id ) ) {
			as_schedule_single_action( time() + 15, self::EXPIRE_JOB, array( $order_id ), self::GROUP );
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! self::should_expire( $order ) ) {
				return;
			}
			$order->update_meta_data( self::EXPIRED, time() );
			$order->save();
			$order->update_status( 'cancelled', 'مهلت ۶۰ دقیقه پرداخت پایان یافت؛ سفارش پرداخت‌نشده لغو و موجودی آن آزاد شد.' );
			wc_release_stock_for_order( $order );
			self::publish_stock( $order );
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * Preserve timely submitted receipts from the native cancellation job too.
	 *
	 * @param bool     $cancel Native cancellation decision.
	 * @param WC_Order $order Candidate unpaid order.
	 * @return bool
	 */
	public static function protect_receipt( $cancel, $order ) {
		return self::deadline( $order ) && self::has_payment_evidence( $order ) ? false : $cancel;
	}

	/**
	 * Expired orders require a fresh cart/stock check before another payment.
	 *
	 * @param bool     $needs_payment Native payment decision.
	 * @param WC_Order $order Order awaiting payment.
	 * @return bool
	 */
	public static function needs_payment( $needs_payment, $order ) {
		return ( self::deadline( $order ) && time() >= self::deadline( $order ) && ! self::has_payment_evidence( $order ) )
			|| $order->get_meta( self::EXPIRED, true ) ? false : $needs_payment;
	}

	/**
	 * Lock before WooCommerce records a gateway's verified payment.
	 *
	 * @param int $order_id Order with a verified payment callback.
	 */
	public static function begin_payment( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && self::deadline( $order ) ) {
			self::$completing_payments[ (int) $order_id ] = true;
			if ( ! self::lock_order( (int) $order_id ) ) {
				self::$unlocked_payments[ (int) $order_id ] = true;
			}
		}
	}

	/**
	 * Record late money safely, without silently fulfilling resold inventory.
	 *
	 * @param string   $status Native next status.
	 * @param int      $order_id Paid order ID.
	 * @param WC_Order $order Core payment object being saved.
	 * @return string
	 */
	public static function payment_status( $status, $order_id, $order ) {
		if ( ! isset( self::$completing_payments[ (int) $order_id ] ) ) {
			return $status;
		}
		$fresh = wc_get_order( $order_id );
		if ( ! $fresh || ! self::deadline( $fresh ) ) {
			return $status;
		}
		$late = $fresh->get_meta( self::EXPIRED, true ) || $fresh->has_status( 'cancelled' )
			|| self::should_expire( $fresh ) || isset( self::$unlocked_payments[ (int) $order_id ] );
		if ( $late || $fresh->get_meta( self::UNASSIGNED, true ) ) {
			$order->update_meta_data( self::UNASSIGNED, 'yes' );
			if ( ! $fresh->get_meta( self::UNASSIGNED, true ) ) {
				$order->add_order_note( 'پرداخت پس از پایان رزرو دریافت شد؛ پرداخت ثبت شده اما موجودی کسر و سفارش تأیید نشده است. تأمین مجدد یا بازگشت وجه نیازمند بررسی مدیر است.' );
			}
			return 'on-hold';
		}
		return $status;
	}

	/**
	 * Do not reduce stock again for an unallocated late payment.
	 *
	 * @param bool     $reduce Native stock decision.
	 * @param WC_Order $order Order being allocated.
	 * @return bool
	 */
	public static function can_reduce_stock( $reduce, $order ) {
		return $order->get_meta( self::UNASSIGNED, true ) ? false : $reduce;
	}

	/**
	 * Stop the native wrapper from marking unreduced late stock as reduced.
	 *
	 * @param bool $reduce Native stock decision.
	 * @param int  $order_id Order being allocated.
	 * @return bool
	 */
	public static function should_reduce_stock( $reduce, $order_id ) {
		$order = wc_get_order( $order_id );
		return $order ? self::can_reduce_stock( $reduce, $order ) : $reduce;
	}

	/**
	 * Offer an explicit manager action after verifying a delayed payment.
	 *
	 * @param array    $actions Existing order actions.
	 * @param WC_Order $order Current admin order.
	 * @return array
	 */
	public static function admin_actions( $actions, $order ) {
		if ( $order && $order->get_meta( self::UNASSIGNED, true ) ) {
			$actions['digitalogic_allocate_late_payment'] = 'بازبررسی موجودی و تخصیص به پرداخت تأییدشده';
		}
		return $actions;
	}

	/**
	 * Allocate only after a fresh atomic stock hold; never force a shortage.
	 *
	 * @param WC_Order $order Order selected by a manager.
	 */
	public static function allocate_late_payment( $order ): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this management capability.
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::lock_order( (int) $order->get_id() ) ) {
			return;
		}
		try {
			$order     = wc_get_order( $order->get_id() );
			$confirmed = $order->get_date_paid() || 'approved' === $order->get_meta( '_digitalogic_payment_receipt_status', true );
			if ( ! $order->get_meta( self::UNASSIGNED, true ) || ! $confirmed ) {
				return;
			}
			$order->update_status( 'pending', 'بازبررسی تخصیص موجودی به پرداخت دیرهنگام.' );
			try {
				wc_reserve_stock_for_order( $order );
			} catch ( Exception $error ) {
				$order->update_status( 'on-hold', 'موجودی کافی نیست؛ تخصیص انجام نشد. تأمین کالا یا بازگشت وجه باید بررسی شود.' );
				return;
			}
			$order->delete_meta_data( self::UNASSIGNED );
			$order->save();
			$order->update_status( $order->needs_processing() ? 'processing' : 'completed', 'پرداخت تأییدشده با کنترل مجدد موجودی تخصیص یافت.' );
			self::publish_stock( $order );
		} finally {
			self::release_lock( $order->get_id() );
		}
	}

	/**
	 * Lock receipt intake, recording its server arrival time before upload work.
	 *
	 * @param WC_Order $order Authorized receipt order.
	 */
	public static function before_receipt( $order ): void {
		if ( ! self::deadline( $order ) ) {
			return;
		}
		if ( ! self::lock_order( (int) $order->get_id() ) ) {
			wp_die( esc_html( 'سفارش در حال بررسی است؛ چند لحظه بعد دوباره تلاش کنید.' ), '', array( 'response' => 409 ) );
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( self::should_expire( $fresh ) ) {
			self::expire_order( $order->get_id() );
			// expire_order releases the shared lock; reacquire before receipt writes.
			if ( ! self::lock_order( (int) $order->get_id() ) ) {
				wp_die( esc_html( 'سفارش در حال بررسی است؛ دوباره تلاش کنید.' ), '', array( 'response' => 409 ) );
			}
		}
		if ( ! self::has_payment_evidence( $fresh ) ) {
			$order->update_meta_data( '_digitalogic_receipt_intake_at', time() );
		}
	}

	/**
	 * A timely receipt keeps its allocation; a late receipt goes to review only.
	 *
	 * @param WC_Order $order Order with a newly stored receipt.
	 */
	public static function after_receipt( $order ): void {
		try {
			$order = wc_get_order( $order->get_id() );
			if ( ! self::deadline( $order ) ) {
				return;
			}
			if ( self::has_payment_evidence( $order ) && ! $order->get_meta( self::EXPIRED, true ) ) {
				if ( $order->has_status( 'pending' ) ) {
					try {
						wc_reserve_stock_for_order( $order );
					} catch ( Exception $error ) {
						$order->update_meta_data( self::UNASSIGNED, 'yes' );
						$order->add_order_note( 'رسید دریافت شد اما موجودی قابل تخصیص نیست؛ بررسی مدیر لازم است.' );
					}
					$order->save();
					$order->update_status( 'on-hold', 'رسید در مهلت مقرر دریافت شد؛ موجودی تا بررسی رسید محفوظ است.' );
				}
			} else {
				$order->update_meta_data( self::UNASSIGNED, 'yes' );
				$order->save();
				$order->add_order_note( 'رسید پس از پایان رزرو دریافت شد؛ موجودی رزرو نیست و تأمین کالا یا بازگشت وجه باید بررسی شود.' );
			}
		} finally {
			self::release_lock( $order->get_id() );
		}
	}

	/**
	 * Publish availability invalidation through the existing shared SSE transport.
	 *
	 * @param WC_Order $order Order whose availability changed.
	 */
	private static function publish_stock( $order ): void {
		if ( ! class_exists( 'Digitalogic_Panel' ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				Digitalogic_Panel::record_event(
					'product.stock.changed',
					array(
						'id'         => $product->get_id(),
						'product_id' => $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(),
					)
				);
			}
		}
	}

	/**
	 * Return current availability for the session's own cart, never another cart.
	 *
	 * @param array $cart Current session cart lines.
	 * @param int   $exclude_order The same shopper's pending order.
	 * @return array
	 */
	public static function snapshot( $cart, int $exclude_order = 0 ): array {
		$items  = array();
		$totals = array();
		foreach ( $cart as $key => $line ) {
			$product = wc_get_product( ! empty( $line['variation_id'] ) ? $line['variation_id'] : $line['product_id'] );
			if ( ! $product ) {
				$items[ $key ] = array(
					'available' => 0,
					'blocked'   => true,
					'message'   => 'این کالا دیگر قابل خرید نیست.',
				);
				continue;
			}
			$managed            = $product->get_stock_managed_by_id();
			$totals[ $managed ] = ( $totals[ $managed ] ?? 0 ) + (float) $line['quantity'];
			$available          = $product->managing_stock() ? max( 0, (float) $product->get_stock_quantity() - wc_get_held_stock_quantity( $product, $exclude_order ) ) : null;
			$blocked            = ! $product->is_in_stock() || ! $product->is_purchasable();
			$items[ $key ]      = array(
				'product_id' => $product->get_id(),
				'managed_id' => $managed,
				'available'  => $available,
				'backorders' => $product->backorders_allowed(),
				'blocked'    => $blocked,
				'message'    => $blocked ? 'این کالا اکنون ناموجود یا غیرقابل خرید است.' : '',
			);
		}
		foreach ( $items as &$item ) {
			if ( isset( $item['managed_id'] ) && null !== $item['available'] && ! $item['backorders'] && $totals[ $item['managed_id'] ] > $item['available'] ) {
				$item['blocked'] = true;
				$item['message'] = sprintf( 'موجودی قابل خرید اکنون %s عدد است؛ تعداد سبد را اصلاح کنید.', wc_format_localized_decimal( $item['available'] ) );
			} elseif ( ! $item['blocked'] && null !== $item['available'] ) {
				$item['message'] = sprintf( 'موجودی قابل خرید: %s عدد', wc_format_localized_decimal( $item['available'] ) );
			}
		}
		unset( $item );
		return array(
			'items'      => $items,
			'blocked'    => (bool) array_filter( $items, static fn( $item ) => $item['blocked'] ),
			'checked_at' => time(),
		);
	}

	/**
	 * Associate checkout summary rows with the same cart keys as the cart page.
	 *
	 * @param string $name Existing item name markup.
	 * @param array  $item Cart item data.
	 * @param string $key Session cart item key.
	 * @return string
	 */
	public static function cart_item_marker( $name, $item, $key ) {
		unset( $item );
		return '<span data-dg-stock-key="' . esc_attr( $key ) . '">' . $name . '</span>';
	}

	/** WooCommerce AJAX callback is read-only and session-scoped. */
	public static function cart_stock(): void {
		nocache_headers();
		$session  = WC()->session;
		$awaiting = $session ? absint( $session->get( 'order_awaiting_payment' ) ) : 0;
		$exclude  = $awaiting ? $awaiting : ( $session ? absint( $session->get( 'store_api_draft_order', 0 ) ) : 0 );
		wp_send_json( self::snapshot( WC()->cart ? WC()->cart->get_cart() : array(), $exclude ) );
	}

	/** Load the cart watcher; reuse the existing realtime product invalidations. */
	public static function enqueue_assets(): void {
		if ( ! get_option( self::ENABLED_AT ) || ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) || is_order_received_page() ) {
			return;
		}
		$file = DIGITALOGIC_PLUGIN_DIR . 'assets/js/stock-reservations.js';
		wp_enqueue_script( 'digitalogic-stock-reservations', DIGITALOGIC_PLUGIN_URL . 'assets/js/stock-reservations.js', array( 'jquery', 'digitalogic-storefront-realtime' ), (string) filemtime( $file ), true );
		wp_localize_script(
			'digitalogic-stock-reservations',
			'DigitalogicCartStock',
			array(
				'url'      => WC_AJAX::get_endpoint( 'digitalogic_cart_stock' ),
				'interval' => 15000,
			)
		);
	}

	/**
	 * Explain the reservation deadline only to the order's authenticated owner.
	 *
	 * @param int $order_id Order visible to its owner.
	 */
	public static function render_deadline( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::deadline( $order ) || ! is_user_logged_in()
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this management capability.
			|| ( get_current_user_id() !== (int) $order->get_customer_id() && ! current_user_can( 'manage_woocommerce' ) ) ) {
			return;
		}
		if ( $order->get_meta( self::UNASSIGNED, true ) ) {
			$message = 'رزرو قبلی پایان یافته است؛ پرداخت یا رسید ارسالی شما باید توسط پشتیبانی بررسی شود و کالا هنوز به این سفارش تخصیص نیافته است.';
		} elseif ( $order->get_meta( self::EXPIRED, true ) || self::should_expire( $order ) ) {
			$message = 'مهلت رزرو این سفارش پایان یافته و موجودی آزاد شده است. برای خرید، موجودی سبد را دوباره بررسی و سفارش جدید ثبت کنید. اگر وجهی واریز کرده‌اید، رسید را برای بررسی ارسال کنید.';
		} elseif ( self::has_payment_evidence( $order ) || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		} else {
			$message = 'رزرو کالا تا ' . wp_date( 'Y/m/d H:i', self::deadline( $order ) ) . ' معتبر است. پرداخت و در روش کارت‌به‌کارت ارسال رسید را در همین مهلت انجام دهید؛ رسید به‌موقع تا بررسی محفوظ می‌ماند.';
		}
		echo '<div class="woocommerce-info digitalogic-stock-deadline" role="status">' . esc_html( $message ) . '</div>';
	}
}
