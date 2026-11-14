<?php
/**
 * Geographic address records reuse WooCommerce's country and validation authority.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and adapter contracts.

/** A saved destination never contains a second person or authentication identity. */
final class MobileSavedAddress {

	/** Geographic fields copied to canonical Woo customer/order snapshots. */
	public const FIELDS = array( 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' );

	/**
	 * Normalize only supplied fields, keeping street line breaks and rejecting hidden identity.
	 *
	 * @param mixed $input Proposed full address or patch.
	 * @return array|\WP_Error Bounded geographic patch.
	 */
	public static function normalize( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_merge( self::FIELDS, array( 'label', 'location' ) ) ) ) {
			return new \WP_Error( 'invalid_address', 'اطلاعات نشانی معتبر نیست.' );
		}
		$result = array();
		foreach ( $input as $key => $value ) {
			if ( 'location' === $key ) {
				$locations = MobileAddresses::normalize_locations( array( 'billing' => $value ) );
				if ( null === $locations ) {
					return new \WP_Error( 'invalid_address_location', 'نقطه انتخاب‌شده روی نقشه معتبر نیست.' );
				}
				$result[ $key ] = $locations['billing'];
				continue;
			}
			$limit = 'label' === $key ? 80 : 300;
			if ( ! is_string( $value ) || mb_strlen( $value ) > $limit ) {
				return new \WP_Error( 'invalid_address_field', 'یکی از فیلدهای نشانی معتبر نیست.', array( 'field' => $key ) );
			}
			$street = in_array( $key, array( 'address_1', 'address_2' ), true );
			if ( ! $street && preg_match( '/[\r\n]/', $value ) ) {
				return new \WP_Error( 'invalid_address_field', 'این فیلد باید در یک سطر وارد شود.', array( 'field' => $key ) );
			}
			$result[ $key ] = $street ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		return $result;
	}

	/**
	 * Validate a complete destination independently of account contact fields.
	 *
	 * @param array $address Previously normalized record.
	 * @return array|\WP_Error Complete canonical destination.
	 */
	public static function validate( array $address ) {
		if ( '' === trim( $address['label'] ?? '' ) ) {
			return new \WP_Error( 'address_label_required', 'عنوان نشانی را وارد کنید.' );
		}
		$country = $address['country'] ?? '';
		$allowed = WC()->countries->get_allowed_countries();
		if ( ! isset( $allowed[ $country ] ) ) {
			return new \WP_Error( 'invalid_country', 'کشور را از فهرست انتخاب کنید.' );
		}
		$fields = WC()->countries->get_address_fields( $country, 'billing_' );
		$issues = array();
		foreach ( self::FIELDS as $key ) {
			$value = $address[ $key ] ?? '';
			$field = $fields[ 'billing_' . $key ] ?? array();
			$valid = empty( $field['required'] ) || '' !== trim( $value );
			if ( '' !== $value && 'postcode' === $key ) {
				$valid = $valid && \WC_Validation::is_postcode( $value, $country );
			}
			if ( '' !== $value && 'state' === $key ) {
				$states = WC()->countries->get_states( $country );
				$valid  = $valid && ( ! $states || isset( $states[ $value ] ) );
			}
			if ( ! $valid ) {
				$issues[] = array(
					'field'   => $key,
					'message' => sprintf( '«%s» را کامل و معتبر وارد کنید.', wp_strip_all_tags( $field['label'] ?? $key ) ),
				);
			}
			$address[ $key ] = $value;
		}
		if ( $issues ) {
			return new \WP_Error( 'invalid_address', 'اطلاعات نشانی را بررسی کنید.', array( 'issues' => $issues ) );
		}
		$address['location'] = $address['location'] ?? null;
		return $address;
	}

	/**
	 * Country-aware native descriptors contain no duplicated recipient/contact fields.
	 *
	 * @param string $country Requested country, or empty for Woo's configured base.
	 * @return array|\WP_Error Schema and precise mutation bounds.
	 */
	public static function schema( string $country = '' ) {
		$country = '' === $country ? WC()->countries->get_base_country() : $country;
		if ( ! isset( WC()->countries->get_allowed_countries()[ $country ] ) ) {
			return new \WP_Error( 'invalid_country', 'کشور را از فهرست انتخاب کنید.' );
		}
		$fields = MobileAddresses::descriptors( WC()->countries->get_address_fields( $country, 'billing_' ), 'billing', $country );
		$fields = array_values( array_filter( $fields, static fn( array $field ): bool => in_array( $field['key'], self::FIELDS, true ) ) );
		foreach ( $fields as &$field ) {
			if ( in_array( $field['key'], array( 'address_1', 'address_2' ), true ) ) {
				$field['type'] = 'textarea';
			}
		}
		unset( $field );
		$result                    = array(
			'country'       => $country,
			'fields'        => $fields,
			'max_addresses' => max( 1, min( 100, (int) get_option( 'digitalogic_saved_address_limit', 20 ) ) ),
			'label'         => array(
				'label'       => 'عنوان نشانی',
				'required'    => true,
				'max_length'  => 80,
				'suggestions' => array(),
			),
		);
		$result['schema_revision'] = hash( 'sha256', wp_json_encode( $result ) );
		return $result;
	}

	/**
	 * Compare only geographic data, excluding Woo customer names, email and telephone.
	 *
	 * @param array $address Canonical address or saved record.
	 * @param mixed $location Optional explicitly selected point.
	 * @return string Deterministic projection revision.
	 */
	public static function geographicHash( array $address, $location = null ): string {
		$geographic = array();
		foreach ( self::FIELDS as $key ) {
			$geographic[ $key ] = (string) ( $address[ $key ] ?? '' );
		}
		$geographic['location'] = $location;
		return hash( 'sha256', wp_json_encode( $geographic ) );
	}
}
