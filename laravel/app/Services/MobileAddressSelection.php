<?php
/**
 * Merge an explicitly selected destination into a native quote without mixing authorities.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 adapter contracts.

/** Geography comes from a selected record; recipient/contact stays in canonical checkout fields. */
final class MobileAddressSelection {

	/**
	 * Reject ambiguous legacy geographic input before resolving private records.
	 *
	 * @param array $input Native quote data.
	 * @return bool Whether selected records are the only supplied geographic authority.
	 */
	public static function unambiguous( array $input ): bool {
		$selection = array_key_exists( 'address_selection', $input ) ? $input['address_selection'] : array();
		if ( ! is_array( $selection ) || array_diff( array_keys( $selection ), array( 'billing', 'shipping' ) ) || ( array_key_exists( 'address_locations', $input ) && ! is_array( $input['address_locations'] ) ) ) {
			return false;
		}
		if ( isset( $selection['shipping'] ) && empty( $input['ship_to_different_address'] ) ) {
			return false;
		}
		foreach ( $selection as $group => $reference ) {
			if ( ! is_array( $reference ) || array_diff( array_keys( $reference ), array( 'id', 'revision' ) ) || ! is_string( $reference['id'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $reference['id'] ) || ! is_int( $reference['revision'] ?? null ) || $reference['revision'] < 1 ) {
				return false;
			}
			if ( ! is_array( $input[ $group ] ?? array() ) || array_intersect( array_keys( $input[ $group ] ?? array() ), MobileSavedAddress::FIELDS ) || array_key_exists( $group, $input['address_locations'] ?? array() ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Apply a resolved immutable snapshot without silently replacing contact data or input.
	 *
	 * @param array $input Unambiguous native quote input.
	 * @param array $resolved Owned record snapshot from MobileAddressBookStore::resolve.
	 * @return array Quote data ready for canonical Woo validation.
	 * @throws \InvalidArgumentException If caller supplies competing geographic sources.
	 */
	public static function apply( array $input, array $resolved ): array {
		if ( ! self::unambiguous( $input ) || array_keys( $input['address_selection'] ?? array() ) !== array_keys( $resolved['references'] ?? array() ) ) {
			throw new \InvalidArgumentException( 'Ambiguous address selection.' );
		}
		foreach ( $resolved['references'] as $group => $reference ) {
			if ( $reference !== $input['address_selection'][ $group ] ) {
				throw new \InvalidArgumentException( 'Address revision changed.' );
			}
			$input[ $group ]                      = array_merge( $input[ $group ] ?? array(), $resolved['addresses'][ $group ] );
			$input['address_locations'][ $group ] = $resolved['locations'][ $group ];
		}
		return $input;
	}
}
