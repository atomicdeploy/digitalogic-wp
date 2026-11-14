<?php
/**
 * Isolated native merge contract regressions.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;
use Digitalogic\Laravel\Services\MobileMergeEffects;
use Digitalogic\Laravel\Services\MobileRegistrationStore;
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileRegistrationStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileMergeEffects.php';

/** No production account or provider transport is used. */
final class MobileMergeEffectsTest extends TestCase {
	/** Exercise isolated contract state. */
	protected function tearDown(): void {
		MobileMergeEffects::stop(); }
	/**
	 * Verify queue is bounded private and does not deliver.
	 */
	public function test_queue_is_bounded_private_and_does_not_deliver(): void {
		$before = count( $GLOBALS['digitalogic_test_remote_posts'] );
		self::assertFalse( MobileMergeEffects::defer( 'panel', array( 'mobile.cart.updated', array() ) ) );
		MobileMergeEffects::begin( str_repeat( 'a', 64 ) );
		self::assertTrue( MobileMergeEffects::defer( 'panel', array( 'mobile.cart.updated', array( 'scope' => 'cart' ) ) ) );
		self::assertTrue( MobileMergeEffects::defer( 'panel', array( 'mobile.cart.updated', array( 'scope' => 'cart' ) ) ) );
		self::assertSame( 1, MobileMergeEffects::count() );
		$sealed = MobileMergeEffects::seal();
		self::assertStringNotContainsString( 'mobile.cart.updated', $sealed );
		$events = json_decode( MobileRegistrationStore::open( $sealed, 'account-merge-effects' ), true );
		self::assertSame( 'panel', $events[0]['channel'] );
		self::assertSame( $before, count( $GLOBALS['digitalogic_test_remote_posts'] ) );
		MobileMergeEffects::stop();
		self::assertSame( 0, MobileMergeEffects::count() );
		self::assertFalse( MobileMergeEffects::active() );
	}
	/**
	 * Verify unreviewed http transport prevents commit receipt.
	 */
	public function test_unreviewed_http_transport_prevents_commit_receipt(): void {
		MobileMergeEffects::begin( str_repeat( 'b', 64 ) );
		self::assertInstanceOf( WP_Error::class, MobileMergeEffects::blockHttp( false, array(), 'https://synthetic.invalid' ) );
		$this->expectException( RuntimeException::class );
		MobileMergeEffects::seal();
	}
	/**
	 * Verify unreviewed mail prevents commit receipt.
	 */
	public function test_unreviewed_mail_prevents_commit_receipt(): void {
		MobileMergeEffects::begin( str_repeat( 'c', 64 ) );
		self::assertFalse( MobileMergeEffects::blockMail( null, array() ) );
		$this->expectException( RuntimeException::class );
		MobileMergeEffects::seal();
	}
	/**
	 * Verify unknown transport is not implicitly approved.
	 */
	public function test_unknown_transport_is_not_implicitly_approved(): void {
		MobileMergeEffects::begin( str_repeat( 'd', 64 ) );
		$this->expectException( RuntimeException::class );
		MobileMergeEffects::defer( 'unreviewed', array() );
	}

	/** Oversized events cannot create an accepted but undecryptable delivery receipt. */
	public function test_delivery_receipt_respects_its_decoder_bound(): void {
		MobileMergeEffects::begin( str_repeat( 'e', 64 ) );
		$this->expectException( RuntimeException::class );
		MobileMergeEffects::defer( 'webhook', array( 'user.updated', array( 'description' => str_repeat( 'x', 15000 ) ) ) );
	}
}
