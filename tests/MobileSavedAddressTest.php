<?php
/**
 * Geographic records keep account identity separate and preserve explicit multiline streets.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileSavedAddress as Address;
use PHPUnit\Framework\TestCase;

/** Tests bounded input and independent identity authority. */
final class MobileSavedAddressTest extends TestCase {

	/** Street text retains line structure; labels and identifiers must remain single-line. */
	public function test_multiline_street_and_identity_exclusion(): void {
		$value = Address::normalize(
			array(
				'label'     => 'خانه',
				'address_1' => "خیابان نخست\nپلاک ۲",
				'address_2' => "طبقه ۱\nواحد ۲",
				'country'   => 'IR',
				'location'  => null,
			)
		);
		self::assertIsArray( $value );
		self::assertSame( "خیابان نخست\nپلاک ۲", $value['address_1'] );
		self::assertSame( "طبقه ۱\nواحد ۲", $value['address_2'] );
		self::assertNull( $value['location'] );
		foreach ( array( 'first_name', 'last_name', 'phone', 'email', 'company', 'user_id', 'owner_id', 'id', 'revision', 'default_for' ) as $field ) {
			self::assertInstanceOf( WP_Error::class, Address::normalize( array( $field => 'untrusted' ) ), $field );
		}
		foreach ( array( 'label', 'country', 'state', 'city', 'postcode' ) as $field ) {
			self::assertInstanceOf( WP_Error::class, Address::normalize( array( $field => "one\ntwo" ) ), $field );
		}
	}

	/** Invalid coordinates, oversized values and typed nulls cannot enter a saved record. */
	public function test_bounds_and_explicit_location_clear(): void {
		foreach ( array(
			array( 'label' => str_repeat( 'a', 81 ) ),
			array( 'address_1' => str_repeat( 'a', 301 ) ),
			array( 'postcode' => null ),
			array( 'country' => array( 'IR' ) ),
			array(
				'location' => array(
					'lat' => 99,
					'lon' => 1,
				),
			),
			array(
				'location' => array(
					'lat' => '35',
					'lon' => 51,
				),
			),
		) as $input ) {
			self::assertInstanceOf( WP_Error::class, Address::normalize( $input ) );
		}
		self::assertSame( array(), Address::normalize( array() ) );
		self::assertSame( array( 'location' => null ), Address::normalize( array( 'location' => null ) ) );
	}

	/** Contact-only edits never change the address projection's compare-and-set fingerprint. */
	public function test_geographic_hash_excludes_person_fields_but_includes_pin_and_street(): void {
		$address = array(
			'country'   => 'IR',
			'state'     => 'THR',
			'city'      => 'تهران',
			'address_1' => 'خیابان',
			'postcode'  => '1111111111',
		);
		$hash    = Address::geographicHash( $address );
		self::assertSame(
			$hash,
			Address::geographicHash(
				$address + array(
					'first_name' => 'Fixture',
					'phone'      => 'private',
					'email'      => 'fixture.invalid',
				)
			)
		);
		self::assertNotSame( $hash, Address::geographicHash( array_replace( $address, array( 'address_1' => 'خیابان دیگر' ) ) ) );
		self::assertNotSame(
			$hash,
			Address::geographicHash(
				$address,
				array(
					'lat' => 35,
					'lon' => 51,
				)
			)
		);
	}
}
