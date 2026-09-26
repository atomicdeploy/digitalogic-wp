<?php
/**
 * Branded order and invoice documents.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Render one stable Digitalogic document from WooCommerce or trusted custom data. */
final class Digitalogic_Order_Documents {

	private const BRANDING_OPTION = 'digitalogic_order_document_branding';

	/** Register the WebToffee final-template integration. */
	public static function init(): void {
		add_filter( 'wt_pklist_alter_order_template_html', array( self::class, 'replace_invoice_html' ), 99, 3 );
		add_filter( 'wt_pklist_alter_active_pdf_library', array( self::class, 'select_rtl_pdf_library' ), 20, 3 );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
		add_action( 'admin_post_digitalogic_order_document', array( self::class, 'stream_customer_document' ) );
		add_action( 'admin_post_nopriv_digitalogic_order_document', array( self::class, 'stream_customer_document' ) );
	}

	/**
	 * Build an expiring, customer-authorized invoice URL.
	 *
	 * @param WC_Order $order Authorized order.
	 * @param string   $mode  Download or print presentation mode.
	 * @return string
	 */
	public static function customer_document_url( $order, string $mode = 'download' ): string {
		$order_id  = (int) $order->get_id();
		$order_key = (string) $order->get_order_key();
		$mode      = 'print' === $mode ? 'print' : 'download';
		return add_query_arg(
			array(
				'action'    => 'digitalogic_order_document',
				'order_id'  => $order_id,
				'order_key' => $order_key,
				'mode'      => $mode,
				'_wpnonce'  => wp_create_nonce( 'digitalogic_order_document_' . $order_id . '|' . $order_key ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/** Stream one branded PDF to the authorized customer or order manager. */
	public static function stream_customer_document(): void {
		$order_id   = absint( $_GET['order_id'] ?? 0 );
		$order_key  = sanitize_text_field( wp_unslash( $_GET['order_key'] ?? '' ) );
		$nonce      = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		$order      = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$authorized = false;
		if ( $order ) {
			$authorized = current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce.
			$authorized = $authorized || ( get_current_user_id() && (int) $order->get_user_id() === get_current_user_id() );
			$authorized = $authorized || ( '' !== $order_key && hash_equals( (string) $order->get_order_key(), $order_key ) );
		}
		if ( ! $authorized || ! wp_verify_nonce( $nonce, 'digitalogic_order_document_' . $order_id . '|' . $order_key ) ) {
			wp_die( esc_html__( 'This invoice link is invalid or has expired.', 'digitalogic' ), '', array( 'response' => 403 ) );
		}

		$output = wp_normalize_path( trailingslashit( get_temp_dir() ) . 'digitalogic-customer-invoice-' . wp_generate_uuid4() . '.pdf' );
		$result = self::generate_custom_pdf( self::payload_from_order( $order ), $output );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$filename = 'Digitalogic-Invoice-' . sanitize_file_name( (string) $order->get_order_number() ) . '.pdf';
		$mode     = sanitize_key( wp_unslash( $_GET['mode'] ?? 'download' ) );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( 'print' === $mode ? 'inline' : 'attachment' ) . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $output ) );
		readfile( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Protected PDF streaming cannot use WP_Filesystem.
		wp_delete_file( $output );
		exit;
	}

	/** Register protected PDF generation routes for automation and Patris. */
	public static function register_rest_routes(): void {
		register_rest_route(
			'digitalogic/v1',
			'/order-documents/render',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_render_custom_document' ),
				'permission_callback' => array( self::class, 'rest_permission' ),
			)
		);
		register_rest_route(
			'digitalogic/v1',
			'/order-documents/order/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_render_order_document' ),
				'permission_callback' => array( self::class, 'rest_permission' ),
			)
		);
	}

	/**
	 * Permit WooCommerce managers or an explicitly authenticated automation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public static function rest_permission( $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce.
			return true;
		}

		$provided = trim( (string) $request->get_header( 'x-digitalogic-secret' ) );
		$expected = trim( (string) get_option( 'digitalogic_webhook_secret', '' ) );
		if ( '' !== $provided && '' !== $expected && hash_equals( $expected, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'digitalogic_document_forbidden',
			__( 'Document generation requires an authorized WooCommerce session or automation secret.', 'digitalogic' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Render a document from a trusted Patris or automation payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_render_custom_document( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload['items'] ) ) {
			return new WP_Error(
				'digitalogic_document_payload_invalid',
				__( 'The document payload must include at least one order item.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}

		return self::rest_pdf_response( $payload, (string) ( $payload['order_number'] ?? 'custom' ) );
	}

	/**
	 * Render a document from a live WooCommerce order.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_render_order_document( $request ) {
		$order = wc_get_order( absint( $request['id'] ) );
		if ( ! $order ) {
			return new WP_Error(
				'digitalogic_document_order_not_found',
				__( 'The requested WooCommerce order was not found.', 'digitalogic' ),
				array( 'status' => 404 )
			);
		}

		return self::rest_pdf_response( self::payload_from_order( $order ), (string) $order->get_order_number() );
	}

	/**
	 * Use mPDF for invoices because it provides proper Persian shaping and RTL.
	 *
	 * @param string              $active Current library.
	 * @param array<string,mixed> $libraries Available libraries.
	 * @param string              $document_type Document type.
	 */
	public static function select_rtl_pdf_library( string $active, array $libraries, string $document_type ): string {
		return 'invoice' === $document_type && isset( $libraries['mpdf'] ) ? 'mpdf' : $active;
	}

	/**
	 * Replace only the invoice document; preserve other document types.
	 *
	 * @param string $html          Existing generated HTML.
	 * @param string $template_type Document type.
	 * @param mixed  $order         WooCommerce order.
	 * @return string
	 */
	public static function replace_invoice_html( string $html, string $template_type, $order ): string {
		if ( 'invoice' !== $template_type || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return $html;
		}

		return self::render_payload( self::payload_from_order( $order ) );
	}

	/**
	 * Convert a WooCommerce order to the shared document contract.
	 *
	 * @param object $order WooCommerce order.
	 * @return array<string,mixed>
	 */
	public static function payload_from_order( $order ): array {
		$currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'IRT';
		$items    = array();

		foreach ( $order->get_items() as $item ) {
			$product  = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$quantity = max( 1, (int) $item->get_quantity() );
			$total    = (float) $item->get_total();
			$items[]  = array(
				'name'         => (string) $item->get_name(),
				'product_code' => $product && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
				'quantity'     => $quantity,
				'unit_price'   => self::plain_price( $total / $quantity, $currency ),
				'total'        => self::plain_price( $total, $currency ),
			);
		}

		$created         = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
		$status          = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		$billing_address = method_exists( $order, 'get_formatted_billing_address' ) ? (string) $order->get_formatted_billing_address() : '';
		$billing_address = (string) preg_replace( '/<br\s*\/?\s*>/i', "\n", $billing_address );

		return array(
			'document_title'  => 'صورتحساب سفارش',
			'order_number'    => (string) $order->get_order_number(),
			'order_status'    => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : $status,
			'order_date'      => $created && method_exists( $created, 'getTimestamp' ) ? date_i18n( get_option( 'date_format' ), $created->getTimestamp() ) : '',
			'payment_method'  => method_exists( $order, 'get_payment_method_title' ) ? (string) $order->get_payment_method_title() : '',
			'shipping_method' => method_exists( $order, 'get_shipping_method' ) ? (string) $order->get_shipping_method() : '',
			'delivery_date'   => (string) $order->get_meta( 'jckwds_date', true ),
			'delivery_time'   => (string) $order->get_meta( 'jckwds_timeslot', true ),
			'customer'        => array(
				'name'    => trim( (string) $order->get_formatted_billing_full_name() ),
				'address' => trim( wp_strip_all_tags( $billing_address ) ),
				'phone'   => (string) $order->get_billing_phone(),
				'email'   => (string) $order->get_billing_email(),
			),
			'items'           => $items,
			'subtotal'        => self::plain_html( (string) $order->get_subtotal_to_display() ),
			'shipping_total'  => self::plain_price( (float) $order->get_shipping_total(), $currency ),
			'total'           => self::plain_html( (string) $order->get_formatted_order_total() ),
			'customer_note'   => method_exists( $order, 'get_customer_note' ) ? (string) $order->get_customer_note() : '',
		);
	}

	/**
	 * Render trusted, normalized order data as RTL HTML suitable for mPDF.
	 *
	 * @param array<string,mixed> $payload Order or Patris-fed data.
	 * @return string
	 */
	public static function render_payload( array $payload ): string {
		$data     = self::normalize_payload( $payload );
		$branding = self::branding();
		$logo     = self::logo_data_uri();
		$font     = self::font_css();
		$rows     = '';
		$index    = 0;

		foreach ( $data['items'] as $item ) {
			++$index;
			$rows .= '<tr>'
				. '<td class="center">' . esc_html( (string) $index ) . '</td>'
				. '<td><strong>' . esc_html( $item['name'] ) . '</strong><br><span class="muted">کد کالا: ' . esc_html( self::value_or_dash( $item['product_code'] ) ) . '</span></td>'
				. '<td class="center">' . esc_html( (string) $item['quantity'] ) . '</td>'
				. '<td>' . esc_html( $item['unit_price'] ) . '</td>'
				. '<td>' . esc_html( $item['total'] ) . '</td>'
				. '</tr>';
		}

		$logo_html        = $logo ? '<img class="brand-logo" src="' . esc_attr( $logo ) . '" alt="دیجیتالاجیک">' : '<div class="brand-name">دیجیتالاجیک</div>';
		$note_html        = '' !== $data['customer_note']
			? '<div class="note"><strong>یادداشت مشتری:</strong> ' . esc_html( $data['customer_note'] ) . '</div>'
			: '';
		$customer_contact = implode(
			"\n",
			array_filter(
				array(
					$data['customer']['phone'],
					$data['customer']['email'],
				)
			)
		);
		$contact_parts    = array_filter(
			array(
				'' !== $branding['phone'] ? 'تلفن: ' . $branding['phone'] : '',
				'' !== $branding['mobile'] ? 'موبایل: ' . $branding['mobile'] : '',
			)
		);
		$contact_html = array() !== $contact_parts
			? '<br>' . implode( '<br>', array_map( 'esc_html', $contact_parts ) )
			: '';

		return '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><style>'
			. $font
			. 'html,body{direction:rtl;font-family:YekanBakh,sans-serif;color:#17324a;font-size:10.5pt;line-height:1.65;margin:0;padding:0}'
			. '*{box-sizing:border-box}.page{padding:10mm 11mm 9mm}.header{width:100%;border-collapse:separate;background:#eef6ff;border:1px solid #cfe4ff;border-radius:16px;margin-bottom:4mm;padding:5mm}.header td{vertical-align:middle}'
			. '.brand-logo{width:47mm;max-height:18mm}.brand-name{font-size:22pt;font-weight:700;color:#153a5b}.doc-kicker{color:#1769e8;font-size:8.5pt;font-weight:700;text-align:left}.doc-title{font-size:21pt;font-weight:700;color:#17324a;text-align:left}'
			. '.accent{height:2.2mm;background:#0bb8df;border-radius:2mm;margin:0 0 5mm}.status{display:inline-block;background:#e4f7ef;color:#087653;border:1px solid #9bd9c2;border-radius:14px;padding:2mm 4mm;font-weight:700}'
			. '.cards{width:100%;border-collapse:separate;border-spacing:3mm;margin:0 -3mm 4mm}.card{width:50%;background:#f7fbff;border:1px solid #d7e8f6;border-radius:12px;padding:4mm;vertical-align:top}.cards .card:first-child{border-top:2px solid #1769e8}.cards .card:last-child{border-top:2px solid #7759ef}'
			. '.label{font-size:8.5pt;color:#647b8d}.value{font-weight:700;color:#17324a}.section{font-size:13pt;font-weight:700;color:#17324a;margin:5mm 0 2mm;border-right:3px solid #0bb8df;padding-right:3mm}'
			. 'table.items{width:100%;border-collapse:collapse;border:1px solid #cfe0eb;border-radius:9px;overflow:hidden}table.items th{background:#1769e8;color:#fff;padding:2.6mm;text-align:right}table.items td{padding:2.7mm;border-bottom:1px solid #dbe7ee;vertical-align:top}table.items tr:nth-child(even) td{background:#f3f8ff}'
			. '.center{text-align:center}.muted{color:#6b8190;font-size:8.5pt}.totals{width:76%;margin-right:auto;margin-top:4mm;border-collapse:collapse}.totals td{padding:2.2mm 3mm;border-bottom:1px solid #dbe7ee;vertical-align:top}.totals td:first-child{width:28%;font-weight:700;padding-left:7mm}.totals td:last-child{text-align:left}.totals .grand td{font-size:13pt;font-weight:700;color:#1769e8;border-top:2px solid #0bb8df}'
			. '.note{margin-top:5mm;padding:3mm 4mm;background:#fff2f6;border-right:3px solid #ef4d78}.footer{margin-top:8mm;padding:4mm 5mm;background:#f3f8ff;border:1px solid #d8e7f4;border-radius:12px;text-align:center;color:#536b7c;font-size:8.8pt}.footer strong{color:#1769e8}'
			. '</style></head><body><div class="page">'
			. '<table class="header"><tr><td>' . $logo_html . '</td><td><div class="doc-kicker">DIGITALOGIC · ORDER DOCUMENT</div><div class="doc-title">' . esc_html( $data['document_title'] ) . '</div></td></tr></table><div class="accent"></div>'
			. '<table class="cards"><tr><td class="card"><div class="label">شماره سفارش</div><div class="value">' . esc_html( $data['order_number'] ) . '</div><div class="label">تاریخ سفارش</div><div class="value">' . esc_html( self::value_or_dash( $data['order_date'] ) ) . '</div><div class="label">وضعیت</div><div class="status">' . esc_html( self::value_or_dash( $data['order_status'] ) ) . '</div></td>'
			. '<td class="card"><div class="label">نام مشتری</div><div class="value">' . esc_html( self::value_or_dash( $data['customer']['name'] ) ) . '</div><div class="label">نشانی</div><div>' . nl2br( esc_html( self::value_or_dash( $data['customer']['address'] ) ) ) . '</div><div class="label">تماس</div><div>' . nl2br( esc_html( self::value_or_dash( $customer_contact ) ) ) . '</div></td></tr></table>'
			. '<table class="cards"><tr><td class="card"><div class="label">روش تحویل</div><div class="value">' . esc_html( self::value_or_dash( $data['shipping_method'] ) ) . '</div><div class="label">تاریخ تحویل</div><div class="value">' . esc_html( self::value_or_dash( $data['delivery_date'] ) ) . '</div><div class="label">بازه زمانی تحویل</div><div class="value">' . esc_html( self::value_or_dash( $data['delivery_time'] ) ) . '</div></td>'
			. '<td class="card"><div class="label">روش پرداخت</div><div class="value">' . esc_html( self::value_or_dash( $data['payment_method'] ) ) . '</div><div class="label">توضیح</div><div>این فایل، خلاصه رسمی سفارش ثبت‌شده در دیجیتالاجیک است.</div></td></tr></table>'
			. '<div class="section">اقلام سفارش</div><table class="items"><thead><tr><th class="center">ردیف</th><th>شرح کالا</th><th class="center">تعداد</th><th>قیمت واحد</th><th>مبلغ</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<table class="totals"><tr><td>جمع کالاها</td><td>' . esc_html( self::value_or_dash( $data['subtotal'] ) ) . '</td></tr><tr><td>هزینه ارسال</td><td>' . esc_html( self::value_or_dash( $data['shipping_total'] ) ) . '</td></tr><tr class="grand"><td>مبلغ نهایی</td><td>' . esc_html( self::value_or_dash( $data['total'] ) ) . '</td></tr></table>'
			. $note_html
			. '<div class="footer"><strong>' . esc_html( $branding['company'] ) . '</strong><br>' . esc_html( $branding['address'] ) . $contact_html . '<br>' . esc_html( $branding['email'] ) . ' | ' . esc_html( $branding['website'] ) . '</div>'
			. '</div></body></html>';
	}

	/**
	 * Generate a branded PDF from a normalized custom payload.
	 *
	 * @param array<string,mixed> $payload Order or Patris data.
	 * @param string              $output  Absolute output path.
	 * @return bool|WP_Error
	 */
	public static function generate_custom_pdf( array $payload, string $output ) {
		$output = wp_normalize_path( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- A CLI output directory must be validated before mPDF writes it.
		if ( '' === $output || ! str_ends_with( strtolower( $output ), '.pdf' ) || ! is_dir( dirname( $output ) ) || ! is_writable( dirname( $output ) ) ) {
			return new WP_Error( 'digitalogic_document_output_invalid', __( 'The PDF output directory is not writable.', 'digitalogic' ) );
		}

		$library = WP_PLUGIN_DIR . '/mpdf-addon-for-pdf-invoices/classes/class-mpdf.php';
		if ( ! class_exists( 'Wt_Pklist_Mpdf' ) && file_exists( $library ) ) {
			require_once $library;
		}
		if ( ! class_exists( 'Wt_Pklist_Mpdf' ) ) {
			return new WP_Error( 'digitalogic_document_pdf_library_unavailable', __( 'The mPDF document engine is unavailable.', 'digitalogic' ) );
		}

		$font_path = self::font_path();
		$config    = array();
		if ( '' !== $font_path ) {
			$config = array(
				'fontDir'      => array( dirname( $font_path ) ),
				'fontdata'     => array(
					'yekanbakh' => array(
						'R'          => basename( $font_path ),
						'B'          => basename( $font_path ),
						'useOTL'     => 0xFF,
						'useKashida' => 75,
					),
				),
				'default_font' => 'yekanbakh',
			);
		}

		$generator = new Wt_Pklist_Mpdf( $config );
		$mpdf      = $generator->mpdf;
		$mpdf->tempDir          = dirname( $output );
		$mpdf->autoScriptToLang = false;
		$mpdf->autoLangToFont   = false;
		$mpdf->SetDirectionality( 'rtl' );
		$mpdf->WriteHTML( self::render_payload( $payload ) );
		$mpdf->Output( $output, 'F' );
		$ok = true;

		return $ok && file_exists( $output ) && filesize( $output ) > 0
			? true
			: new WP_Error( 'digitalogic_document_generation_failed', __( 'The PDF could not be generated.', 'digitalogic' ) );
	}

	/**
	 * Generate one no-store base64 PDF response without retaining a public file.
	 *
	 * @param array<string,mixed> $payload Order or Patris data.
	 * @param string              $reference Safe filename reference.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function rest_pdf_response( array $payload, string $reference ) {
		$filename = 'Digitalogic-Invoice-' . sanitize_file_name( $reference ) . '.pdf';
		$output   = wp_normalize_path( trailingslashit( get_temp_dir() ) . 'digitalogic-order-' . wp_generate_uuid4() . '.pdf' );
		$result   = self::generate_custom_pdf( $payload, $output );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the private temporary PDF immediately before deletion.
		$pdf = file_get_contents( $output );
		wp_delete_file( $output );
		if ( false === $pdf ) {
			return new WP_Error( 'digitalogic_document_read_failed', __( 'The generated PDF could not be read.', 'digitalogic' ) );
		}

		$response = new WP_REST_Response(
			array(
				'filename'       => $filename,
				'mime_type'      => 'application/pdf',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The authenticated JSON transport carries binary PDF data as base64.
				'content_base64' => base64_encode( $pdf ),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}

	/**
	 * Normalize a trusted custom document payload.
	 *
	 * @param array<string,mixed> $payload Input payload.
	 * @return array<string,mixed>
	 */
	private static function normalize_payload( array $payload ): array {
		$customer = is_array( $payload['customer'] ?? null ) ? $payload['customer'] : array();
		$items    = array();
		foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || '' === trim( (string) ( $item['name'] ?? '' ) ) ) {
				continue;
			}
			$items[] = array(
				'name'         => sanitize_text_field( (string) $item['name'] ),
				'product_code' => sanitize_text_field( (string) ( $item['product_code'] ?? $item['sku'] ?? '' ) ),
				'quantity'     => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
				'unit_price'   => sanitize_text_field( (string) ( $item['unit_price'] ?? '' ) ),
				'total'        => sanitize_text_field( (string) ( $item['total'] ?? '' ) ),
			);
		}

		return array(
			'document_title'  => sanitize_text_field( (string) ( $payload['document_title'] ?? 'صورتحساب سفارش' ) ),
			'order_number'    => sanitize_text_field( (string) ( $payload['order_number'] ?? '' ) ),
			'order_status'    => sanitize_text_field( (string) ( $payload['order_status'] ?? '' ) ),
			'order_date'      => sanitize_text_field( (string) ( $payload['order_date'] ?? '' ) ),
			'payment_method'  => sanitize_text_field( (string) ( $payload['payment_method'] ?? '' ) ),
			'shipping_method' => sanitize_text_field( (string) ( $payload['shipping_method'] ?? '' ) ),
			'delivery_date'   => sanitize_text_field( (string) ( $payload['delivery_date'] ?? '' ) ),
			'delivery_time'   => sanitize_text_field( (string) ( $payload['delivery_time'] ?? '' ) ),
			'customer'        => array(
				'name'    => sanitize_text_field( (string) ( $customer['name'] ?? '' ) ),
				'address' => sanitize_textarea_field( (string) ( $customer['address'] ?? '' ) ),
				'phone'   => sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ),
				'email'   => sanitize_email( (string) ( $customer['email'] ?? '' ) ),
			),
			'items'           => $items,
			'subtotal'        => sanitize_text_field( (string) ( $payload['subtotal'] ?? '' ) ),
			'shipping_total'  => sanitize_text_field( (string) ( $payload['shipping_total'] ?? '' ) ),
			'total'           => sanitize_text_field( (string) ( $payload['total'] ?? '' ) ),
			'customer_note'   => sanitize_textarea_field( (string) ( $payload['customer_note'] ?? '' ) ),
		);
	}

	/**
	 * Return configured document-branding contact details.
	 *
	 * @return array<string,string>
	 */
	private static function branding(): array {
		$defaults = array(
			'company' => 'دیجیتالاجیک',
			'address' => 'تهران، خیابان جمهوری اسلامی، بعد از خیابان حافظ، پاساژ فرشته، پلاک ۲۶۹',
			'phone'   => '۰۲۱-۶۶۷۵۴۱۲۳',
			'mobile'  => '',
			'email'   => 'info@digitalogic.ir',
			'website' => 'digitalogic.ir',
		);
		$value    = get_option( self::BRANDING_OPTION, array() );
		return array_map( 'sanitize_text_field', wp_parse_args( is_array( $value ) ? $value : array(), $defaults ) );
	}

	/** Return a local YekanBakh font-face rule when the configured asset exists. */
	private static function font_css(): string {
		$path = self::font_path();
		if ( '' === $path ) {
			return '';
		}
		return "@font-face{font-family:'YekanBakh';src:url('file://" . esc_url( $path ) . "') format('truetype');font-weight:400;font-style:normal}";
	}

	/** Return the verified local YekanBakh TrueType asset used by mPDF. */
	private static function font_path(): string {
		if ( ! function_exists( 'wp_get_upload_dir' ) ) {
			return '';
		}
		$uploads = wp_get_upload_dir();
		$path    = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) . '/2025/09/YekanBakh-Regular.ttf' );
		return is_readable( $path ) ? $path : '';
	}

	/** Return the current WordPress logo as an embedded document image. */
	private static function logo_data_uri(): string {
		if ( ! function_exists( 'get_theme_mod' ) || ! function_exists( 'get_attached_file' ) || ! function_exists( 'get_post_mime_type' ) ) {
			return '';
		}
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$path    = $logo_id > 0 ? get_attached_file( $logo_id ) : '';
		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$mime = (string) get_post_mime_type( $logo_id );
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local configured logo asset.
		if ( false === $data ) {
			return '';
		}
		$mime    = '' !== $mime ? $mime : 'image/svg+xml';
		$encoded = base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Embeds a verified local logo in a PDF data URI.
		return 'data:' . $mime . ';base64,' . $encoded;
	}

	/**
	 * Format a numeric WooCommerce amount without storefront markup.
	 *
	 * @param float  $amount   Numeric amount.
	 * @param string $currency WooCommerce currency code.
	 */
	private static function plain_price( float $amount, string $currency ): string {
		return function_exists( 'wc_price' ) ? self::plain_html( (string) wc_price( $amount, array( 'currency' => $currency ) ) ) : number_format_i18n( $amount );
	}

	/**
	 * Convert trusted WooCommerce price HTML to plain Unicode text.
	 *
	 * @param string $value Price HTML.
	 */
	private static function plain_html( string $value ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Return a printable value or an em dash for a missing field.
	 *
	 * @param string $value Printable value.
	 */
	private static function value_or_dash( string $value ): string {
		return '' !== trim( $value ) ? $value : '—';
	}
}
