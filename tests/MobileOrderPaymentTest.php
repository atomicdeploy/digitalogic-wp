<?php
/**
 * Owner-safe canonical BACS readback, with no payment or order writes.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileOrderPayment;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileOrderPayment.php';

/** Regression checks for receipt/history consistency and payment privacy. */
final class MobileOrderPaymentTest extends TestCase {

	/**
	 * Make an immutable canonical order stand-in.
	 *
	 * @param string $status Canonical order state.
	 * @param string $method Canonical gateway identifier.
	 * @return object Read-only order fixture.
	 */
	private function order( string $status = 'on-hold', string $method = 'bacs' ): object {
		return new class( $status, $method ) {
			// phpcs:disable Squiz.Commenting -- Small immutable test fixture implements only the exercised Woo getters.
			public function __construct( private string $status, private string $method ) {}
			public function get_id(): int {
				return 71; }
			public function get_customer_id(): int {
				return 12; }
			public function get_total(): string {
				return '2400'; }
			public function get_payment_method(): string {
				return $this->method; }
			public function is_paid(): bool {
				return in_array( $this->status, array( 'processing', 'completed' ), true ); }
			public function has_status( $statuses ): bool {
				return in_array( $this->status, (array) $statuses, true ); }
			public function payment_complete(): never {
				throw new LogicException( 'A projection must not change payment state.' ); }
			// phpcs:enable Squiz.Commenting
		};
	}

	/** Return a public merchant configuration, never a provider credential. */
	private function gateway(): object {
		return new class() {
			// phpcs:disable Squiz.Commenting -- Small gateway fixture prohibits financial side effects.
			public string $id             = 'bacs';
			public array $account_details = array(
				array(
					'account_name'   => '<b>Fixture merchant</b>',
					'account_number' => '00123',
					'iban'           => 'TEST-ONLY',
					'api_secret'     => 'must-not-project',
				),
				array(
					'account_name'   => '',
					'account_number' => '',
				),
			);
			public function get_option( string $name, string $fallback ): string {
				return 'instructions' === $name ? '<p>Use the order number.</p>' : $fallback; }
			public function process_payment(): never {
				throw new LogicException( 'Recovery must not repeat a payment.' ); }
			// phpcs:enable Squiz.Commenting
		};
	}

	/** The same canonical projection remains readable after initial submission. */
	public function test_receipt_and_history_share_current_published_details(): void {
		$gateway = $this->gateway();
		$first   = MobileOrderPayment::forOrder( $this->order(), 12, $gateway );
		self::assertSame( 'awaiting_bank_transfer', $first['status'] );
		self::assertSame( 'Use the order number.', $first['instructions'] );
		self::assertCount( 1, $first['bank_accounts'] );
		self::assertSame( '00123', $first['bank_accounts'][0]['account_number'] );
		self::assertSame( 'Fixture merchant', $first['bank_accounts'][0]['account_name'] );
		self::assertArrayNotHasKey( 'api_secret', $first['bank_accounts'][0] );
		self::assertSame( $first, MobileOrderPayment::forOrder( $this->order(), 12, $gateway ) );
	}

	/** Canonical per-order bank filtering also applies to native receipts. */
	public function test_provider_filter_receives_the_exact_order_id(): void {
		$filter = static function ( $accounts, $order_id ) {
			self::assertSame( 71, $order_id );
			$accounts[0]['account_number'] = '00234';
			return $accounts;
		};
		add_filter( 'woocommerce_bacs_accounts', $filter, 10, 2 );
		try {
			$result = MobileOrderPayment::forOrder( $this->order(), 12, $this->gateway() );
			self::assertSame( '00234', $result['bank_accounts'][0]['account_number'] );
		} finally {
			remove_filter( 'woocommerce_bacs_accounts', $filter, 10 );
		}
	}

	/** Paid, cancelled and uncertain orders never request a second bank transfer. */
	public function test_terminal_or_unaccepted_state_does_not_expose_transfer_instructions(): void {
		self::assertSame( array( 'status' => 'complete' ), MobileOrderPayment::forOrder( $this->order( 'completed' ), 12, $this->gateway() ) );
		foreach ( array( 'cancelled', 'refunded', 'failed', 'pending' ) as $status ) {
			self::assertSame( array( 'status' => 'requires_review' ), MobileOrderPayment::forOrder( $this->order( $status ), 12, $this->gateway() ) );
		}
		self::assertSame( array( 'status' => 'requires_review' ), MobileOrderPayment::forOrder( $this->order( 'on-hold', 'another-provider' ), 12, $this->gateway() ) );
	}

	/** Another account cannot inspect this order's private payment presentation. */
	public function test_exact_owner_is_required_before_projection(): void {
		$this->expectException( UnexpectedValueException::class );
		$this->expectExceptionMessage( 'order_owner_mismatch' );
		MobileOrderPayment::forOrder( $this->order(), 13, $this->gateway() );
	}
}
