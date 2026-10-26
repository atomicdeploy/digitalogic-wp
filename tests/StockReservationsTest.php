<?php // phpcs:ignoreFile -- Small order doubles intentionally live with these business-rule tests.
/** @package Digitalogic */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-stock-reservations.php';

final class Digitalogic_Test_Reservation_Order {
	public array $meta = array();
	public string $status = 'pending';
	public bool $paid = false;
	public $paid_at = null;
	public string $transaction = '';
	public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function is_paid() { return $this->paid; }
	public function get_date_paid() { return $this->paid_at; }
	public function get_transaction_id() { return $this->transaction; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
}

final class StockReservationsTest extends TestCase {
	private function order(): Digitalogic_Test_Reservation_Order {
		$order = new Digitalogic_Test_Reservation_Order();
		$order->meta[ Digitalogic_Stock_Reservations::DEADLINE ] = 1000;
		return $order;
	}

	public function test_due_boundary_and_historical_orders(): void {
		$order = $this->order();
		self::assertFalse( Digitalogic_Stock_Reservations::should_expire( $order, 999 ) );
		self::assertTrue( Digitalogic_Stock_Reservations::should_expire( $order, 1000 ) );
		$order->status = 'on-hold';
		self::assertTrue( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ) );
		unset( $order->meta[ Digitalogic_Stock_Reservations::DEADLINE ] );
		self::assertFalse( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ) );
	}

	public function test_payment_or_deposit_prevents_expiry(): void {
		foreach ( array( 'paid', 'paid_at', 'transaction', 'deposit' ) as $proof ) {
			$order = $this->order();
			if ( 'deposit' === $proof ) { $order->meta['_wc_deposit_paid'] = 'yes'; }
			else { $order->$proof = 'paid' === $proof ? true : ( 'transaction' === $proof ? 'verified' : 999 ); }
			self::assertFalse( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ), $proof );
		}
	}

	public function test_only_complete_timely_receipt_preserves_allocation(): void {
		$receipt = tempnam( sys_get_temp_dir(), 'dgl-receipt-test-' );
		try {
			$order = $this->order();
			$order->meta += array(
				'_digitalogic_payment_receipt_status' => 'submitted',
				'_digitalogic_payment_receipt_reference' => 'synthetic',
				'_digitalogic_payment_receipt_file' => $receipt,
				'_digitalogic_receipt_intake_at' => 999,
			);
			self::assertFalse( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ) );
			self::assertFalse( Digitalogic_Stock_Reservations::protect_receipt( true, $order ) );
			$order->meta['_digitalogic_receipt_intake_at'] = 1001;
			self::assertTrue( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ) );
			$order->meta['_digitalogic_receipt_intake_at'] = 999;
			$order->meta['_digitalogic_payment_receipt_reference'] = '';
			self::assertTrue( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ) );
		} finally { unlink( $receipt ); }
	}

	public function test_rejected_receipt_does_not_extend_original_deadline(): void {
		$order = $this->order();
		$order->meta['_digitalogic_payment_receipt_status'] = 'rejected';
		self::assertTrue( Digitalogic_Stock_Reservations::should_expire( $order, 1001 ) );
		self::assertFalse( Digitalogic_Stock_Reservations::needs_payment( true, $order ) );
		self::assertSame( 1000, Digitalogic_Stock_Reservations::deadline( $order ) );
	}

	public function test_ordinary_status_date_checks_never_claim_a_payment(): void {
		$order = $this->order();
		self::assertSame( 'processing', Digitalogic_Stock_Reservations::payment_status( 'processing', 17, $order ) );
		self::assertArrayNotHasKey( Digitalogic_Stock_Reservations::UNASSIGNED, $order->meta );
	}

	public function test_unallocated_late_payment_cannot_reduce_stock(): void {
		$order = $this->order();
		$order->meta[ Digitalogic_Stock_Reservations::UNASSIGNED ] = 'yes';
		self::assertFalse( Digitalogic_Stock_Reservations::can_reduce_stock( true, $order ) );
		unset( $order->meta[ Digitalogic_Stock_Reservations::UNASSIGNED ] );
		self::assertTrue( Digitalogic_Stock_Reservations::can_reduce_stock( true, $order ) );
	}
}
