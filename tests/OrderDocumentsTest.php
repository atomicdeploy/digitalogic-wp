<?php
/**
 * Tests for branded order and invoice documents.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-order-documents.php';

/** Verify the custom-data contract and invoice integration boundary. */
final class OrderDocumentsTest extends TestCase {

	/** Reset document configuration. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']      = array();
		$GLOBALS['digitalogic_test_capabilities'] = array();
	}

	/** Custom payloads use the same branded RTL renderer as live orders. */
	public function test_custom_payload_renders_branded_rtl_document(): void {
		$html = Digitalogic_Order_Documents::render_payload(
			array(
				'order_number'    => 'P-14085',
				'order_status'    => 'در انتظار پرداخت',
				'payment_method'  => 'کارت به کارت',
				'shipping_method' => 'دریافت حضوری',
				'delivery_date'   => '1405/07/06',
				'delivery_time'   => '07:00 تا 07:30',
				'customer'        => array( 'name' => 'مشتری آزمایشی' ),
				'items'           => array(
					array(
						'name'         => 'سنسور فاصله',
						'product_code' => '102007003',
						'quantity'     => 1,
						'unit_price'   => '178,700 تومان',
						'total'        => '178,700 تومان',
					),
				),
				'total'           => '178,700 تومان',
			)
		);

		$this->assertStringContainsString( 'dir="rtl"', $html );
		$this->assertStringContainsString( 'font-family:YekanBakh', $html );
		$this->assertStringContainsString( '#0d4f86', $html );
		$this->assertStringContainsString( 'P-14085', $html );
		$this->assertStringContainsString( '102007003', $html );
		$this->assertStringContainsString( 'تلفن:', $html );
		$this->assertStringContainsString( 'info@digitalogic.ir', $html );
		$this->assertStringNotContainsString( 'orange', strtolower( $html ) );
	}

	/** Packing slips and labels remain owned by their existing templates. */
	public function test_non_invoice_document_is_left_unchanged(): void {
		$this->assertSame(
			'original',
			Digitalogic_Order_Documents::replace_invoice_html( 'original', 'packinglist', new stdClass() )
		);
	}

	/** Invoice generation selects mPDF while other documents preserve their engine. */
	public function test_invoice_uses_rtl_capable_pdf_engine(): void {
		$libraries = array(
			'dompdf' => array(),
			'mpdf'   => array(),
		);
		$this->assertSame( 'mpdf', Digitalogic_Order_Documents::select_rtl_pdf_library( 'dompdf', $libraries, 'invoice' ) );
		$this->assertSame( 'dompdf', Digitalogic_Order_Documents::select_rtl_pdf_library( 'dompdf', $libraries, 'packinglist' ) );
	}

	/** Automation access requires either WooCommerce capability or the configured shared secret. */
	public function test_rest_permission_rejects_missing_secret_and_accepts_exact_secret(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_webhook_secret'] = 'reviewed-secret';

		$denied = Digitalogic_Order_Documents::rest_permission( new WP_REST_Request() );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'digitalogic_document_forbidden', $denied->get_error_code() );

		$allowed = Digitalogic_Order_Documents::rest_permission(
			new WP_REST_Request( array(), array(), array( 'X-Digitalogic-Secret' => 'reviewed-secret' ) )
		);
		$this->assertTrue( $allowed );
	}

	/** WooCommerce managers can render without exposing the automation secret. */
	public function test_rest_permission_accepts_woocommerce_manager(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;
		$this->assertTrue( Digitalogic_Order_Documents::rest_permission( new WP_REST_Request() ) );
	}

	/** Patris/custom route refuses an empty order contract before generation. */
	public function test_rest_custom_document_requires_items(): void {
		$result = Digitalogic_Order_Documents::rest_render_custom_document( new WP_REST_Request( array(), array( 'order_number' => 'P-1' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_document_payload_invalid', $result->get_error_code() );
	}
}
