<?php
/**
 * Native message privacy, payload bounds, and idempotency contracts.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;
use Digitalogic\Laravel\Services\MobileMessages;
use Digitalogic\Laravel\Services\MobileMessageStore;

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileMessages.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileMessageStore.php';
require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';

/** Prove safe boundaries before any canonical provider write is attempted. */
final class MobileMessagesContractTest extends TestCase {
	/** Replies preserve literal user text and reject unsupported ownership or upload fields. */
	public function test_reply_payload_is_plain_bounded_and_cannot_select_another_author(): void {
		$data = MobileMessages::prepare( 'reply', array( 'body' => "First\n<script>literal</script>" ) );
		self::assertIsArray( $data );
		self::assertStringNotContainsString( '<script>', $data['content'] );
		self::assertStringContainsString( '&lt;script&gt;', $data['content'] );
		foreach ( array(
			array( 'body' => '' ),
			array( 'body' => array() ),
			array( 'body' => str_repeat( 'x', 10001 ) ),
			array(
				'body'    => 'Hello',
				'user_id' => 7,
			),
			array(
				'body'        => 'Hello',
				'attachments' => array( 'foreign-upload' ),
			),
		) as $input ) {
			self::assertTrue( is_wp_error( MobileMessages::prepare( 'reply', $input ) ) );
		}
	}

	/** Plain paragraphs retain reading order without leaking private HTML attributes. */
	public function test_visible_text_preserves_paragraphs_without_attachment_locations(): void {
		$text = MobileMessages::text( '<p>First &amp; second</p><p><a href="https://private.example/file?token=secret">Attachment</a></p>' );
		self::assertSame( "First & second\nAttachment", $text );
		self::assertStringNotContainsString( 'secret', $text );
	}

	/** Same UUID on another account or kind cannot claim an existing native result. */
	public function test_operation_key_is_owner_bound_and_provider_marker_is_exact(): void {
		$id    = '11111111-1111-4111-8111-111111111111';
		$first = MobileMessageStore::key( 7, $id );
		self::assertNotSame( $first, MobileMessageStore::key( 8, $id ) );
		self::assertSame( $first, MobileMessageStore::key( 7, strtoupper( $id ) ) );
		self::assertNull( MobileMessageStore::key( 7, '../other' ) );
		self::assertSame( 'digitalogic-native-' . $first . '@digitalogic.ir', MobileMessageStore::providerId( $first ) );
		self::assertSame( MobileMessageStore::providerId( $first ), sanitize_text_field( MobileMessageStore::providerId( $first ) ) );
	}

	/** Routes cannot escape into admin providers or turn schema/recovery reads into writes. */
	public function test_message_route_methods_are_narrow(): void {
		$root = Digitalogic_Mobile_API::route( '/app-api/account/messages' );
		self::assertSame( array( 'GET', 'HEAD', 'POST' ), Digitalogic_Mobile_API::methods( $root ) );
		foreach ( array( '/schema', '/12', '/operations/11111111-1111-4111-8111-111111111111' ) as $suffix ) {
			$route = Digitalogic_Mobile_API::route( '/app-api/account/messages' . $suffix );
			self::assertNotNull( $route );
			self::assertSame( array( 'GET', 'HEAD' ), Digitalogic_Mobile_API::methods( $route ) );
		}
		foreach ( array( '/12/messages', '/12/close', '/12/reopen' ) as $suffix ) {
			$route = Digitalogic_Mobile_API::route( '/app-api/account/messages' . $suffix );
			self::assertSame( array( 'POST' ), Digitalogic_Mobile_API::methods( $route ) );
		}
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/account/messages/12/internal-notes' ) );
		self::assertNull( Digitalogic_Mobile_API::route( '/app-api/account/messages/12/delete' ) );
	}
}
