<?php // phpcs:ignoreFile -- Opt-in CLI integration harness, isolated synthetic data and explicit cleanup.
/**
 * Opt-in integration scenarios against actual WooCommerce CRUD and reservations.
 * Run with wp eval-file FILE [candidate-class-path]. All fixtures are deleted.
 * Never sends mail, SMS, calls, gateway requests, or outbound webhooks.
 *
 * @package Digitalogic
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WC_Order' ) ) {
	return;
}
// Disable outbound transports and notification producers only in this process.
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'pre_http_request', static fn() => new WP_Error( 'fixture_no_network' ), PHP_INT_MAX );
foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
	foreach ( $hook->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$callback = $entry['function'];
			$owner = is_array( $callback ) ? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) : '';
			if ( preg_match( '/Webhooks|SMSIR|Voice.Notifications|Viewer.*Events|WC_Emails/i', $owner ) ) {
				remove_filter( $tag, $callback, $priority );
			}
		}
	}
}
if ( ! class_exists( 'Digitalogic_Stock_Reservations' ) ) {
	require $args[0];
	Digitalogic_Stock_Reservations::init();
}
$started = time();
add_filter( 'pre_option_digitalogic_stock_reservations_enabled_at', static fn() => $started - 5 );
$managers = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
wp_set_current_user( (int) $managers[0] );
$orders = array();
$products = array();
$checks = array();
remove_action( 'woocommerce_before_product_object_save', array( 'Digitalogic_Patris_Mapping_Guard', 'before_save' ), 100 );
add_action( 'woocommerce_before_product_object_save', static function ( $product ) {
	if ( 'yes' !== $product->get_meta( '_digitalogic_stock_test_fixture' ) ) { Digitalogic_Patris_Mapping_Guard::before_save( $product ); }
}, 100 );
// Only synthetic product IDs receive display-price eligibility in this request.
foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
	add_filter( 'woocommerce_product_get_' . $field, static function ( $value, $product ) use ( &$products, $field ) {
		return in_array( $product->get_id(), $products, true ) ? $product->{'get_' . $field}( 'edit' ) : $value;
	}, PHP_INT_MAX, 2 );
}
add_filter( 'woocommerce_is_purchasable', static function ( $value, $product ) use ( &$products ) {
	return in_array( $product->get_id(), $products, true ) ? true : $value;
}, PHP_INT_MAX, 2 );
$receipt = tempnam( sys_get_temp_dir(), 'dgl-fixture-' );
file_put_contents( $receipt, 'Synthetic receipt: no financial transaction.' );
$assert = static function ( $ok, $name ) use ( &$checks ) {
	if ( ! $ok ) { throw new RuntimeException( $name ); }
	$checks[] = $name;
};
$make_product = static function ( $quantity = 1 ) use ( &$products ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'آزمایش موقت رزرو موجودی' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( '1000' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( $quantity );
	$product->set_backorders( 'no' );
	$product->update_meta_data( '_digitalogic_stock_test_fixture', 'yes' );
	Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write( static fn() => $product->save() );
	$products[] = $product->get_id();
	return $product;
};
$make_order = static function ( $product, $tracked = true ) use ( &$orders ) {
	$order = wc_create_order( array( 'created_via' => 'checkout' ) );
	$orders[] = $order->get_id();
	$order->add_product( $product, 1 );
	$order->set_payment_method( 'bacs' );
	$order->calculate_totals();
	$order->save();
	if ( $tracked ) { Digitalogic_Stock_Reservations::track_order( $order ); }
	return wc_get_order( $order->get_id() );
};
$due = static function ( $order ) {
	$order->update_meta_data( Digitalogic_Stock_Reservations::DEADLINE, time() - 2 );
	$order->save();
};
try {
	$p = $make_product();
	$first = $make_order( $p );
	$deadline = Digitalogic_Stock_Reservations::deadline( $first );
	$assert( $deadline === $first->get_date_created()->getTimestamp() + 3600, 'fixed_60_minutes' );
	Digitalogic_Stock_Reservations::track_order( $first );
	$assert( $deadline === Digitalogic_Stock_Reservations::deadline( $first ), 'revisit_does_not_extend_deadline' );
	wc_reserve_stock_for_order( $first );
	$cart = array( 'first' => array( 'product_id' => $p->get_id(), 'quantity' => 1 ) );
	$assert( Digitalogic_Stock_Reservations::snapshot( $cart )['blocked'], 'other_cart_sees_reserved_stock' );
	$assert( ! Digitalogic_Stock_Reservations::snapshot( $cart, $first->get_id() )['blocked'], 'own_reservation_not_subtracted_twice' );
	$second = $make_order( $p );
	$assert( as_has_scheduled_action( Digitalogic_Stock_Reservations::EXPIRE_JOB, array( $first->get_id() ), 'digitalogic-stock' ) && as_has_scheduled_action( Digitalogic_Stock_Reservations::EXPIRE_JOB, array( $second->get_id() ), 'digitalogic-stock' ), 'independent_per_order_jobs' );
	$rejected = false;
	try { wc_reserve_stock_for_order( $second ); } catch ( Exception $e ) { $rejected = true; }
	$assert( $rejected, 'atomic_reservation_rejects_competing_buyer' );
	$due( $first );
	Digitalogic_Stock_Reservations::expire_order( $first->get_id() );
	$assert( ! wc_get_order( $first->get_id() )->get_meta( Digitalogic_Stock_Reservations::UNASSIGNED ), 'unpaid_cancellation_never_claims_money_received' );
	$assert( wc_get_order( $first->get_id() )->has_status( 'cancelled' ) && 0 === (int) wc_get_held_stock_quantity( wc_get_product( $p->get_id() ) ), 'unpaid_pending_released' );
	wc_reserve_stock_for_order( $second );
	$second->update_status( 'processing' );
	$assert( 0 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'second_buyer_allocated_last_unit' );
	$first = wc_get_order( $first->get_id() );
	$first->payment_complete( 'SYNTHETIC-NO-GATEWAY' );
	$first = wc_get_order( $first->get_id() );
	$assert( $first->has_status( 'on-hold' ) && $first->get_date_paid() && $first->get_meta( Digitalogic_Stock_Reservations::UNASSIGNED ), 'late_money_recorded_for_manual_review' );
	$assert( 0 === (int) wc_get_product( $p->get_id() )->get_stock_quantity() && ! $first->get_data_store()->get_stock_reduced( $first->get_id() ), 'late_payment_does_not_oversell_or_mark_allocation' );
	Digitalogic_Stock_Reservations::allocate_late_payment( $first );
	$assert( wc_get_order( $first->get_id() )->get_meta( Digitalogic_Stock_Reservations::UNASSIGNED ) && 0 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'manager_cannot_allocate_shortage' );
	Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write( static fn() => wc_update_product_stock( $p->get_id(), 1, 'set' ) );
	Digitalogic_Stock_Reservations::allocate_late_payment( wc_get_order( $first->get_id() ) );
	$assert( wc_get_order( $first->get_id() )->has_status( 'processing' ) && ! wc_get_order( $first->get_id() )->get_meta( Digitalogic_Stock_Reservations::UNASSIGNED ) && 0 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'manager_allocates_only_after_restock' );
	$p = $make_product();
	$bacs = $make_order( $p );
	$bacs->update_status( 'on-hold' );
	$assert( 0 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'bacs_initially_allocates_stock' );
	$due( $bacs );
	$action = as_schedule_single_action( time() - 1, Digitalogic_Stock_Reservations::EXPIRE_JOB, array( $bacs->get_id() ), 'digitalogic-stock' );
	ActionScheduler_QueueRunner::instance()->process_action( $action, 'Digitalogic isolated integration' );
	$assert( 'complete' === ActionScheduler::store()->get_status( $action ), 'real_action_scheduler_executes_expiry' );
	Digitalogic_Stock_Reservations::expire_order( $bacs->get_id() );
	$assert( 1 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'bacs_expiry_restores_exactly_once' );
	$receipt_order = $make_order( $p );
	$receipt_order->update_status( 'on-hold' );
	$due( $receipt_order );
	$receipt_order->update_meta_data( '_digitalogic_payment_receipt_file', $receipt );
	$receipt_order->update_meta_data( '_digitalogic_payment_receipt_reference', 'SYNTHETIC' );
	$receipt_order->update_meta_data( '_digitalogic_payment_receipt_status', 'submitted' );
	$receipt_order->update_meta_data( '_digitalogic_receipt_intake_at', time() - 10 );
	$receipt_order->save();
	Digitalogic_Stock_Reservations::expire_order( $receipt_order->get_id() );
	$assert( wc_get_order( $receipt_order->get_id() )->has_status( 'on-hold' ) && 0 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'timely_receipt_keeps_stock_until_review' );
	$assert( ! apply_filters( 'woocommerce_cancel_unpaid_order', true, $receipt_order ), 'native_sweep_also_protects_receipt' );
	$receipt_order->update_meta_data( '_digitalogic_payment_receipt_status', 'rejected' );
	$receipt_order->save();
	Digitalogic_Stock_Reservations::expire_order( $receipt_order->get_id() );
	$assert( wc_get_order( $receipt_order->get_id() )->has_status( 'cancelled' ) && 1 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'rejected_receipt_resumes_original_deadline' );
	$late_receipt = $make_order( $p );
	$due( $late_receipt );
	Digitalogic_Stock_Reservations::before_receipt( $late_receipt );
	$late_receipt->update_meta_data( '_digitalogic_payment_receipt_file', $receipt );
	$late_receipt->update_meta_data( '_digitalogic_payment_receipt_reference', 'SYNTHETIC-LATE' );
	$late_receipt->update_meta_data( '_digitalogic_payment_receipt_status', 'submitted' );
	$late_receipt->save();
	Digitalogic_Stock_Reservations::after_receipt( $late_receipt );
	$assert( wc_get_order( $late_receipt->get_id() )->has_status( 'cancelled' ) && wc_get_order( $late_receipt->get_id() )->get_meta( Digitalogic_Stock_Reservations::UNASSIGNED ) && 1 === (int) wc_get_product( $p->get_id() )->get_stock_quantity(), 'late_receipt_never_resurrects_expired_reservation' );
	$old = $make_order( $p, false );
	$old->set_date_created( $started - 86400 );
	$old->update_status( 'on-hold' );
	Digitalogic_Stock_Reservations::track_order( $old );
	Digitalogic_Stock_Reservations::expire_order( $old->get_id() );
	$assert( ! Digitalogic_Stock_Reservations::deadline( wc_get_order( $old->get_id() ) ) && wc_get_order( $old->get_id() )->has_status( 'on-hold' ), 'historical_order_untouched' );
	$p = $make_product( 2 );
	$cart = array( 'a' => array( 'product_id' => $p->get_id(), 'quantity' => 2 ), 'b' => array( 'product_id' => $p->get_id(), 'quantity' => 1 ) );
	$state = Digitalogic_Stock_Reservations::snapshot( $cart );
	$assert( $state['items']['a']['blocked'] && $state['items']['b']['blocked'], 'shared_stock_quantities_aggregated' );
	echo wp_json_encode( array( 'passed' => count( $checks ), 'checks' => $checks ), JSON_UNESCAPED_UNICODE ) . "\n";
} catch ( Throwable $error ) {
	echo 'SCENARIO_FAILED: ' . $error->getMessage() . ' after ' . implode( ',', $checks ) . "\n";
	throw $error;
} finally {
	foreach ( $orders as $id ) {
		as_unschedule_all_actions( Digitalogic_Stock_Reservations::EXPIRE_JOB, array( $id ), 'digitalogic-stock' );
		$order = wc_get_order( $id );
		if ( $order ) { wc_release_stock_for_order( $order ); $order->delete( true ); }
	}
	foreach ( $products as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			$receiver = Digitalogic_Product_Sync_Receiver::instance();
			$locked = $receiver->acquire_source_identity_lock( 15 );
			if ( is_wp_error( $locked ) ) { throw new RuntimeException( 'Fixture cleanup source lock unavailable.' ); }
			try {
				$scope = Digitalogic_Product_Code_Write_Guard::instance()->acquire_for_post_deletion( null, get_post( $id ), true );
				if ( false === $scope ) { throw new RuntimeException( 'Fixture cleanup product lock unavailable.' ); }
				$product->delete( true );
			} finally { $receiver->release_source_identity_lock(); }
		}
	}
	unlink( $receipt );
}

