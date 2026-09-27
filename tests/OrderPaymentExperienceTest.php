<?php
/**
 * Tests for order actions, bank ingress settings, and receipt authorization.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-order-payment-experience.php';

/** Verify bank account validation and customer authorization boundaries. */
final class OrderPaymentExperienceTest extends TestCase {
	/** Reset customer identity and capabilities. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 0;
		$GLOBALS['digitalogic_test_capabilities']    = array();
		$GLOBALS['digitalogic_test_deleted_files']   = array();
		$_GET                                        = array();
		remove_all_filters( 'digitalogic_receipt_directory' );
	}

	/** Persian digits and separators normalize into a stored 16-digit number. */
	public function test_sanitize_accounts_normalizes_card_and_iban(): void {
		$result = Digitalogic_Order_Payment_Experience::sanitize_accounts(
			array(
				array(
					'enabled'        => '1',
					'bank_name'      => 'بانک آزمایشی',
					'account_holder' => 'دیجیتالاجیک',
					'card_number'    => '۶۰۳۷ ۹۹۱۲ ۳۴۵۶ ۷۸۹۳',
					'iban'           => 'IR49 0000 0000 0000 0000 0000 00',
					'accent'         => '#14A9DF',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '6037991234567893', $result[0]['card_number'] );
		$this->assertSame( 'IR490000000000000000000000', $result[0]['iban'] );
		$this->assertSame( '#14a9df', $result[0]['accent'] );
	}

	/** Invalid financial identifiers are rejected rather than partially stored. */
	public function test_sanitize_accounts_rejects_invalid_card_or_iban(): void {
		$invalid_card = Digitalogic_Order_Payment_Experience::sanitize_accounts(
			array(
				array(
					'bank_name'      => 'بانک',
					'account_holder' => 'دارنده',
					'card_number'    => '1234',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid_card );
		$this->assertSame( 'digitalogic_bank_account_invalid', $invalid_card->get_error_code() );

		$invalid_card_checksum = Digitalogic_Order_Payment_Experience::sanitize_accounts(
			array(
				array(
					'bank_name'      => 'بانک',
					'account_holder' => 'دارنده',
					'card_number'    => '6037991234567890',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid_card_checksum );

		$invalid_iban = Digitalogic_Order_Payment_Experience::sanitize_accounts(
			array(
				array(
					'bank_name'      => 'بانک',
					'account_holder' => 'دارنده',
					'card_number'    => '6037991234567893',
					'iban'           => 'IR123',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid_iban );
		$this->assertSame( 'digitalogic_bank_iban_invalid', $invalid_iban->get_error_code() );

		$invalid_iban_checksum = Digitalogic_Order_Payment_Experience::sanitize_accounts(
			array(
				array(
					'bank_name'      => 'بانک',
					'account_holder' => 'دارنده',
					'card_number'    => '6037991234567893',
					'iban'           => 'IR000000000000000000000000',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $invalid_iban_checksum );
	}

	/** Customer display groups the stored number without changing its digits. */
	public function test_group_card_number_is_readable(): void {
		$this->assertSame( '6037 9912 3456 7893', Digitalogic_Order_Payment_Experience::group_card_number( '6037991234567893' ) );
	}

	/** The real payment card retains a distinct surface and aligned customer actions. */
	public function test_payment_surface_and_order_actions_keep_the_visual_contract(): void {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/order-payment-experience.css' );
		$this->assertStringContainsString( 'radial-gradient(circle at 88% 12%', $css );
		$this->assertStringContainsString( 'linear-gradient(135deg', $css );
		$this->assertStringContainsString( 'border: 1px solid color-mix', $css );
		$this->assertStringContainsString( '.dg-order-menu { display: flex; height: 52px;', $css );
		$this->assertStringContainsString( '.dg-order-action { border-radius: 14px; height: 52px;', $css );
	}

	/** A guest must present the exact WooCommerce order key. */
	public function test_guest_access_requires_exact_order_key(): void {
		$order = new class() {
			/** Guest order owner ID. */
			public function get_user_id(): int {
				return 0; }
			/** Exact order access key. */
			public function get_order_key(): string {
				return 'wc_order_exact'; }
		};
		$this->assertTrue( Digitalogic_Order_Payment_Experience::can_access_order( $order, 'wc_order_exact' ) );
		$this->assertFalse( Digitalogic_Order_Payment_Experience::can_access_order( $order, 'wc_order_other' ) );
	}

	/** Permanent order deletion removes only receipt files inside private storage. */
	public function test_order_deletion_removes_protected_receipt_file(): void {
		$directory = sys_get_temp_dir() . '/digitalogic-receipt-cleanup-' . uniqid( '', true );
		mkdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated test fixture.
		$receipt = $directory . '/receipt.pdf';
		file_put_contents( $receipt, '%PDF-test' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated test fixture.
		add_filter( 'digitalogic_receipt_directory', static fn() => $directory );

		$order = new class( $receipt ) {
			/** Protected receipt path. */
			private string $receipt;
			/** Store the fixture path. */
			public function __construct( string $receipt ) {
				$this->receipt = $receipt;
			}
			/** Return the requested metadata. */
			public function get_meta( string $key ) {
				return '_digitalogic_payment_receipt_file' === $key ? $this->receipt : '';
			}
		};

		Digitalogic_Order_Payment_Experience::delete_receipt_for_order( 42, $order );

		$this->assertFileDoesNotExist( $receipt );
		$this->assertSame( array( $receipt ), $GLOBALS['digitalogic_test_deleted_files'] );
		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated test fixture cleanup.
	}
}
