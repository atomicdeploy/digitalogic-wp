<?php
/**
 * Existing-account discovery must not create or claim a normalized duplicate.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;
use Digitalogic\Laravel\Services\MobileIdentityStore;
use Digitalogic\Laravel\Services\MobileIdentityResolver;

// phpcs:disable WordPress.DB.SlowDBQuery -- Literal in-memory identity fixtures, not WordPress metadata queries.

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityStore.php';
require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileIdentityResolver.php';

/** Pure comparison fixtures never read or modify real identities. */
final class MobileIdentityResolverTest extends TestCase {
	/** Iranian stored Digits formats and presentation marks preserve exact identity. */
	public function test_stored_phone_formats_share_one_key_without_accepting_labels(): void {
		foreach ( array( '+989123456789', '989123456789', '00989123456789', '09123456789', '9123456789', '۰۹۱۲ ۳۴۵ ۶۷۸۹', '\u{200F}0912-345-6789\u{200E}' ) as $value ) {
			$value = str_replace( array( '\u{200F}', '\u{200E}' ), array( "\u{200F}", "\u{200E}" ), $value );
			self::assertSame( '+989123456789', MobileIdentityResolver::storedPhone( $value ) );
		}
		self::assertSame( '+989123456789', MobileIdentityStore::phone( "\u{2066}+98 (912) 345-6789\u{2069}" ) );
		foreach ( array( '09123456789 ext1', '+441234567890', '++989123456789', '0912345678', '9e10', array() ) as $value ) {
			self::assertNull( MobileIdentityResolver::storedPhone( $value ) );
		}
	}

	/** A matching value on another account or contradictory Digits pair blocks enrollment. */
	public function test_all_authoritative_phone_rows_participate_in_collision_detection(): void {
		$phone = '+989123456789';
		$rows  = array(
			array(
				'user_id'    => 7,
				'meta_key'   => 'digits_phone',
				'meta_value' => '۰۹۱۲۳۴۵۶۷۸۹',
			),
			array(
				'user_id'    => 7,
				'meta_key'   => 'digits_phone_no',
				'meta_value' => '9123456789',
			),
			array(
				'user_id'    => 7,
				'meta_key'   => 'digt_countrycode',
				'meta_value' => '+98',
			),
		);
		self::assertSame( 7, MobileIdentityResolver::phoneOwnerFromRows( $rows, $phone ) );
		self::assertSame( 0, MobileIdentityResolver::phoneOwnerFromRows( $rows, '+989120000001' ) );
		self::assertSame(
			-1,
			MobileIdentityResolver::phoneOwnerFromRows(
				array_merge(
					$rows,
					array(
						array(
							'user_id'    => 8,
							'meta_key'   => 'digits_phone',
							'meta_value' => '00989123456789',
						),
					)
				),
				$phone
			)
		);
		self::assertSame(
			-1,
			MobileIdentityResolver::phoneOwnerFromRows(
				array_merge(
					$rows,
					array(
						array(
							'user_id'    => 7,
							'meta_key'   => 'digits_phone_no',
							'meta_value' => '9120000001',
						),
					)
				),
				$phone
			)
		);
		self::assertSame(
			-1,
			MobileIdentityResolver::phoneOwnerFromRows(
				array_merge(
					$rows,
					array(
						array(
							'user_id'    => 7,
							'meta_key'   => 'digits_phone',
							'meta_value' => 'unknown',
						),
					)
				),
				$phone
			)
		);
		self::assertSame(
			-1,
			MobileIdentityResolver::phoneOwnerFromRows(
				array_merge(
					$rows,
					array(
						array(
							'user_id'    => 7,
							'meta_key'   => 'digt_countrycode',
							'meta_value' => '+44',
						),
					)
				),
				$phone
			)
		);
		self::assertSame(
			0,
			MobileIdentityResolver::phoneOwnerFromRows(
				array(
					array(
						'user_id'    => 8,
						'meta_key'   => 'billing_phone',
						'meta_value' => $phone,
					),
				),
				$phone
			)
		);
	}

	/** Gmail aliases are discovery equivalents; custom Workspace local parts remain intact. */
	public function test_email_keys_preserve_provider_specific_identity_boundaries(): void {
		self::assertSame( 'firstlast@gmail.com', MobileIdentityResolver::emailKey( ' First.Last+shop@GMAIL.COM ' ) );
		self::assertSame( 'firstlast@gmail.com', MobileIdentityResolver::emailKey( 'firstlast@gmail.com' ) );
		self::assertSame( 'firstlast@gmail.com', MobileIdentityResolver::emailKey( 'First.Last+shop@googlemail.com' ) );
		self::assertSame( 'first.last+shop@example.com', MobileIdentityResolver::emailKey( 'First.Last+shop@EXAMPLE.COM' ) );
		self::assertNotSame( MobileIdentityResolver::emailKey( 'first.last@example.com' ), MobileIdentityResolver::emailKey( 'firstlast@example.com' ) );
		foreach ( array( 'name', 'name@@gmail.com', 'first last@gmail.com', '', array() ) as $value ) {
			self::assertNull( MobileIdentityResolver::emailKey( $value ) );
		}
	}
}
