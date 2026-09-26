<?php
/**
 * Tests for bounded, idempotent order webhooks.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify routine order notifications do not fan out on every metadata save. */
final class OrderWebhookTest extends TestCase {

	/** Reset webhook state. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']          = array(
			'digitalogic_webhook_urls'   => array( 'https://automation.digitalogic.test/events' ),
			'digitalogic_webhook_secret' => 'observer-secret',
		);
		$GLOBALS['digitalogic_test_actions']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['digitalogic_test_remote_posts']     = array();

		$reflection = new ReflectionClass( Digitalogic_Webhooks::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setValue( null, null );
	}

	/** Invoice and metadata writes must not emit a generic order.updated event. */
	public function test_metadata_update_hook_is_not_registered(): void {
		Digitalogic_Webhooks::instance();
		$callbacks = $GLOBALS['digitalogic_test_action_callbacks']['woocommerce_update_order'] ?? array();
		$this->assertSame( array(), $callbacks );
	}

	/** One order-created event is stable, privacy-minimized, and explicitly routed. */
	public function test_order_created_uses_stable_event_and_safe_notification_contract(): void {
		$order = $this->order();
		Digitalogic_Webhooks::instance()->order_created( 14085, $order );

		$this->assertCount( 1, $GLOBALS['digitalogic_test_remote_posts'] );
		$payload = json_decode( $GLOBALS['digitalogic_test_remote_posts'][0]['args']['body'], true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( 'order.created', $payload['event'] );
		$this->assertTrue( $GLOBALS['digitalogic_test_remote_posts'][0]['args']['blocking'] );
		$this->assertSame( hash( 'sha256', 'order.created|14085' ), $payload['event_id'] );
		$this->assertSame( array( 'telegram', 'ntfy' ), $payload['data']['notify_channels'] );
		$this->assertSame( array( 'shokri' ), $payload['data']['audience'] );
		$this->assertTrue( $payload['data']['document_available'] );
		$this->assertSame( 'کارت به کارت / انتقال بانکی', $payload['data']['payment_method'] );
		$this->assertSame( 'دریافت حضوری', $payload['data']['shipping_method'] );
		$this->assertSame( '۱۴۰۵/۰۷/۰۶', $payload['data']['delivery_date'] );
		$this->assertArrayNotHasKey( 'billing_email', $payload['data'] );
		$this->assertArrayNotHasKey( 'billing_phone', $payload['data'] );
		$this->assertArrayNotHasKey( 'request', $payload['data'] );
	}

	/** Notification titles derive from the event key and order context. */
	public function test_n8n_titles_are_contextual_for_every_commerce_event(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/assets/integrations/n8n/digitalogic-wordpress-events.code.js' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'function eventTitle(key, payload)', $source );
		$this->assertStringContainsString( "'order.receipt.submitted'", $source );
		$this->assertStringContainsString( 'const title = eventTitle(eventKey, data);', $source );
		$this->assertStringNotContainsString( 'سفارش جدید دیجیتالاجیک', $source );
		$this->assertStringNotContainsString( 'به‌روزرسانی نرخ ارز دیجیتالاجیک', $source );
	}

	/** Build the minimum WooCommerce order surface used by the formatter. */
	private function order(): object {
		// phpcs:disable -- Compact anonymous test doubles keep this focused fixture readable.
		$product = new class() {
			public function get_sku(): string { return '102007003'; }
		};
		$item    = new class( $product ) {
			private object $product;
			public function __construct( object $product ) { $this->product = $product; }
			public function get_product(): object { return $this->product; }
			public function get_product_id(): int { return 101; }
			public function get_variation_id(): int { return 0; }
			public function get_name(): string { return 'سنسور فاصله'; }
			public function get_quantity(): int { return 1; }
			public function get_total(): string { return '178700'; }
		};
		$date    = new class() {
			public function date( string $format ): string { return '2026-09-26T15:00:00+03:30'; }
		};

		$order = new class( $item, $date ) {
			private object $item;
			private object $date;
			public function __construct( object $item, object $date ) { $this->item = $item; $this->date = $date; }
			public function get_id(): int { return 14085; }
			public function get_order_number(): string { return '14085'; }
			public function get_status(): string { return 'on-hold'; }
			public function get_total(): string { return '178700'; }
			public function get_currency(): string { return 'IRT'; }
			public function get_payment_method_title(): string { return 'کارت به کارت / انتقال بانکی'; }
			public function get_shipping_method(): string { return 'دریافت حضوری'; }
			public function get_date_created(): object { return $this->date; }
			public function get_date_modified(): object { return $this->date; }
			public function get_items(): array { return array( $this->item ); }
			public function get_meta( string $key, bool $single ): string {
				unset( $single );
				return 'jckwds_date' === $key ? '28/09/2026' : '07:00 - 07:30';
			}
		};
		// phpcs:enable

		return $order;
	}
}
