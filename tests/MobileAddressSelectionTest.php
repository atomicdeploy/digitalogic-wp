<?php
/**
 * Selected saved records must not compete with inline geography or contact authority.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileAddressSelection as Selection;
use Digitalogic\Laravel\Services\MobileAddressMutation as Mutation;
use PHPUnit\Framework\TestCase;

/** Tests immutable destination references and explicit mutation intent boundaries. */
final class MobileAddressSelectionTest extends TestCase {

	/** Selected geography and map pin have exactly one source. */
	public function test_selected_address_rejects_mixed_inline_sources(): void {
		$input = array(
			'address_selection' => array(
				'billing' => array(
					'id'       => str_repeat( 'a', 32 ),
					'revision' => 2,
				),
			),
			'billing'           => array(
				'first_name' => 'Fixture',
				'phone'      => 'Account contact',
			),
		);
		self::assertTrue( Selection::unambiguous( $input ) );
		foreach ( array( 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ) as $field ) {
			$conflict                      = $input;
			$conflict['billing'][ $field ] = '';
			self::assertFalse( Selection::unambiguous( $conflict ) );
		}
		self::assertFalse( Selection::unambiguous( $input + array( 'address_locations' => array( 'billing' => null ) ) ) );
		self::assertFalse( Selection::unambiguous( $input + array( 'address_locations' => null ) ) );
		self::assertFalse( Selection::unambiguous( array( 'address_selection' => null ) ) );
		self::assertFalse( Selection::unambiguous( array( 'address_selection' => array( 'shipping' => $input['address_selection']['billing'] ) ) ) );
		self::assertFalse(
			Selection::unambiguous(
				array(
					'address_selection' => array(
						'billing' => array(
							'id'       => str_repeat( 'a', 32 ),
							'revision' => '2',
						),
					),
				)
			)
		);
	}

	/** The exact reviewed snapshot is applied while account contact fields stay untouched. */
	public function test_snapshot_application_preserves_account_contact_and_revision(): void {
		$input    = array(
			'address_selection' => array(
				'billing' => array(
					'id'       => str_repeat( 'a', 32 ),
					'revision' => 2,
				),
			),
			'billing'           => array(
				'first_name' => 'Fixture',
				'email'      => 'fixture@example.invalid',
			),
		);
		$snapshot = array(
			'references' => $input['address_selection'],
			'addresses'  => array(
				'billing' => array(
					'country'   => 'IR',
					'address_1' => "Street\nUnit",
				),
			),
			'locations'  => array( 'billing' => null ),
		);
		$resolved = Selection::apply( $input, $snapshot );
		self::assertSame( 'Fixture', $resolved['billing']['first_name'] );
		self::assertSame( 'fixture@example.invalid', $resolved['billing']['email'] );
		self::assertSame( "Street\nUnit", $resolved['billing']['address_1'] );
		self::assertSame( $input['address_selection'], $resolved['address_selection'] );
		self::assertNull( $resolved['address_locations']['billing'] );
		$snapshot['references']['billing']['revision'] = 3;
		$this->expectException( InvalidArgumentException::class );
		Selection::apply( $input, $snapshot );
	}

	/** Owner/person mutations and duplicate default roles cannot enter a durable address intent. */
	public function test_mutation_shape_and_explicit_defaults(): void {
		$create = array(
			'operation_id'  => 'c35694a0-9898-4f38-b7d9-72bf191eb424',
			'base_revision' => 0,
			'address'       => array(
				'label'   => 'Home',
				'country' => 'IR',
			),
			'default_for'   => array(),
		);
		self::assertIsArray( Mutation::normalize( 'create', null, $create ) );
		self::assertInstanceOf( WP_Error::class, Mutation::normalize( 'create', null, $create + array( 'user_id' => 7 ) ) );
		$duplicate                = $create;
		$duplicate['default_for'] = array( 'billing', 'billing' );
		self::assertInstanceOf( WP_Error::class, Mutation::normalize( 'create', null, $duplicate ) );
		$duplicate['default_for'] = array( array( 'billing' ) );
		self::assertInstanceOf( WP_Error::class, Mutation::normalize( 'create', null, $duplicate ) );
		$duplicate['default_for'] = array( null );
		self::assertInstanceOf( WP_Error::class, Mutation::normalize( 'create', null, $duplicate ) );
		$identity                     = $create;
		$identity['address']['phone'] = 'separate identity';
		self::assertInstanceOf( WP_Error::class, Mutation::normalize( 'create', null, $identity ) );
		self::assertInstanceOf(
			WP_Error::class,
			Mutation::normalize(
				'defaults',
				null,
				array(
					'base_revision' => 1,
					'billing_id'    => null,
				)
			)
		);
		self::assertIsArray(
			Mutation::normalize(
				'defaults',
				null,
				array(
					'base_revision' => 1,
					'billing_id'    => null,
					'shipping_id'   => null,
				)
			)
		);
	}
}
