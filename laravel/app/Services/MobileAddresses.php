<?php
/**
 * Native address presentation over existing WooCommerce customer and order objects.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

/** Canonical Woo addresses with explicitly selected, optional location metadata. */
final class MobileAddresses {
	/**
	 * Validate optional coordinates without accepting automatic or incomplete pins.
	 *
	 * @param mixed $input Billing/shipping location patches.
	 * @return array|null Valid normalized patch, or null for invalid input.
	 */
	public static function normalize_locations( $input ): ?array {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'billing', 'shipping' ) ) ) {
			return null;
		}
		$result = array();
		foreach ( $input as $group => $point ) {
			if ( null === $point ) {
				$result[ $group ] = null;
				continue;
			}
			if ( ! is_array( $point ) || array_diff( array_keys( $point ), array( 'lat', 'lon', 'accuracy' ) ) || ! isset( $point['lat'], $point['lon'] ) ) {
				return null;
			}
			foreach ( $point as $number ) {
				if ( ( ! is_int( $number ) && ! is_float( $number ) ) || ! is_finite( (float) $number ) ) {
					return null;
				}
			}
			if ( abs( $point['lat'] ) > 90 || abs( $point['lon'] ) > 180 || ( isset( $point['accuracy'] ) && ( $point['accuracy'] < 0 || $point['accuracy'] > 1000000 ) ) ) {
				return null;
			}
			$result[ $group ] = array( 'lat' => round( $point['lat'], 7 ), 'lon' => round( $point['lon'], 7 ) );
			if ( isset( $point['accuracy'] ) ) {
				$result[ $group ]['accuracy'] = round( $point['accuracy'], 1 );
			}
		}
		return $result;
	}

	/**
	 * Read optional location metadata from the existing customer/order ORM.
	 *
	 * @param \WC_Data $object Canonical owner object.
	 * @return array Billing and shipping pins; absent pins stay null.
	 */
	public static function locations( $object ): array {
		$result = array();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$value = $object->get_meta( '_digitalogic_' . $group . '_location', true );
			$valid = self::normalize_locations( array( $group => is_array( $value ) ? $value : null ) );
			$result[ $group ] = $valid[ $group ] ?? null;
		}
		return $result;
	}

	/**
	 * Attach only explicitly supplied pin changes; saving remains with the owning workflow.
	 *
	 * @param \WC_Data $object Canonical owner object.
	 * @param array    $locations Previously validated location patch.
	 * @return void
	 */
	public static function apply_locations( $object, array $locations ): void {
		foreach ( $locations as $group => $point ) {
			$key = '_digitalogic_' . $group . '_location';
			if ( null === $point ) {
				$object->delete_meta_data( $key );
			} else {
				$object->update_meta_data( $key, $point );
			}
		}
	}

	/**
	 * Project Woo field definitions and real country/state options.
	 *
	 * @param array  $fields Country-aware Woo field definitions.
	 * @param string $group Billing or shipping.
	 * @param string $country Current country.
	 * @return array Native field descriptors.
	 */
	public static function descriptors( array $fields, string $group, string $country ): array {
		$result = array();
		$countries = 'billing' === $group ? WC()->countries->get_allowed_countries() : WC()->countries->get_shipping_countries();
		foreach ( $fields as $key => $field ) {
			$type = $field['type'] ?? 'text';
			$options = $field['options'] ?? array();
			$by_country = array();
			if ( 'country' === $type ) {
				$type = 'select';
				$options = $countries;
			} elseif ( 'state' === $type ) {
				$options = WC()->countries->get_states( $country ) ?: array();
				$type = $options ? 'select' : 'text';
				foreach ( $countries as $code => $label ) {
					$by_country[ $code ] = self::options( WC()->countries->get_states( $code ) ?: array() );
				}
			}
			$descriptor = array(
				'key' => preg_replace( '/^' . preg_quote( $group, '/' ) . '_/', '', $key ),
				'label' => wp_strip_all_tags( $field['label'] ?? $key ),
				'type' => $type,
				'required' => ! empty( $field['required'] ),
				'options' => self::options( $options ),
				'max_length' => min( 300, (int) ( $field['maxlength'] ?? 300 ) ),
			);
			if ( $by_country ) {
				$descriptor['options_by_country'] = $by_country;
			}
			$result[] = $descriptor;
		}
		return $result;
	}

	/**
	 * Convert Woo's keyed options to stable native descriptors.
	 *
	 * @param array $options Canonical value/label pairs.
	 * @return array Native options.
	 */
	private static function options( array $options ): array {
		$result = array();
		foreach ( $options as $value => $label ) {
			$result[] = array( 'value' => (string) $value, 'label' => wp_strip_all_tags( $label ) );
		}
		return $result;
	}

	/**
	 * Build customer-owned address data and country-aware editor schema.
	 *
	 * @param \WC_Customer $customer Verified native account's Woo customer.
	 * @return array Canonical address presentation.
	 */
	public static function presentation( $customer ): array {
		$schema = array();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$getter = 'get_' . $group . '_country';
			$country = $customer->$getter() ?: WC()->countries->get_base_country();
			$schema[ $group ] = self::descriptors( WC()->countries->get_address_fields( $country, $group . '_' ), $group, $country );
		}
		return array( 'billing' => $customer->get_billing(), 'shipping' => $customer->get_shipping(), 'address_schema' => $schema, 'address_locations' => self::locations( $customer ), 'address_map' => self::map_config() );
	}

	/**
	 * Validate an address patch and prepare the canonical Woo customer without saving.
	 *
	 * @param int   $user_id Verified WordPress owner.
	 * @param array $input Native profile patch.
	 * @return \WC_Customer|\WP_Error Valid customer changes or validation failure.
	 */
	public static function prepare( int $user_id, array $input ) {
		$customer = new \WC_Customer( $user_id );
		$issues = array();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			if ( ! array_key_exists( $group, $input ) ) {
				continue;
			}
			$patch = $input[ $group ];
			if ( ! is_array( $patch ) || count( $patch ) > 30 ) {
				return new \WP_Error( 'invalid_address', 'اطلاعات نشانی معتبر نیست.' );
			}
			$getter = 'get_' . $group;
			$address = array_merge( $customer->$getter(), $patch );
			$country = $address['country'] ?? '';
			$allowed = 'billing' === $group ? WC()->countries->get_allowed_countries() : WC()->countries->get_shipping_countries();
			if ( ! is_string( $country ) || ! isset( $allowed[ $country ] ) ) {
				return new \WP_Error( 'invalid_country', 'کشور را از فهرست انتخاب کنید.' );
			}
			$fields = WC()->countries->get_address_fields( $country, $group . '_' );
			foreach ( $patch as $key => $value ) {
				$setter = 'set_' . $group . '_' . $key;
				if ( ! is_string( $key ) || ! isset( $fields[ $group . '_' . $key ] ) || ! is_callable( array( $customer, $setter ) ) || ! is_string( $value ) || mb_strlen( $value ) > 300 ) {
					return new \WP_Error( 'unsupported_address_field', 'یکی از فیلدهای نشانی پشتیبانی نمی‌شود.' );
				}
				$address[ $key ] = wc_clean( $value );
			}
			foreach ( $fields as $full_key => $field ) {
				$key = substr( $full_key, strlen( $group ) + 1 );
				$value = $address[ $key ] ?? '';
				$valid = empty( $field['required'] ) || '' !== trim( (string) $value );
				if ( '' !== $value ) {
					if ( in_array( 'postcode', $field['validate'] ?? array(), true ) ) {
						$valid = $valid && \WC_Validation::is_postcode( $value, $country );
					} elseif ( in_array( 'phone', $field['validate'] ?? array(), true ) ) {
						$valid = $valid && \WC_Validation::is_phone( $value );
					} elseif ( in_array( 'email', $field['validate'] ?? array(), true ) ) {
						$valid = $valid && is_email( $value );
					} elseif ( 'state' === ( $field['type'] ?? '' ) ) {
						$states = WC()->countries->get_states( $country );
						$valid = $valid && ( ! $states || isset( $states[ $value ] ) );
					}
				}
				if ( ! $valid ) {
					$issues[] = array( 'field' => $full_key, 'message' => sprintf( '«%s» را کامل و معتبر وارد کنید.', wp_strip_all_tags( $field['label'] ?? $key ) ) );
				}
			}
			if ( $issues ) {
				return new \WP_Error( 'invalid_address', 'اطلاعات نشانی را بررسی کنید.', array( 'issues' => $issues ) );
			}
			try {
				foreach ( $patch as $key => $_ ) {
					$setter = 'set_' . $group . '_' . $key;
					$customer->$setter( $address[ $key ] );
				}
			} catch ( \WC_Data_Exception $error ) {
				return new \WP_Error( 'invalid_address', wp_strip_all_tags( $error->getMessage() ) );
			}
		}
		if ( array_key_exists( 'address_locations', $input ) ) {
			$locations = self::normalize_locations( $input['address_locations'] );
			if ( null === $locations ) {
				return new \WP_Error( 'invalid_address_location', 'نقطه انتخاب‌شده روی نقشه معتبر نیست.' );
			}
			self::apply_locations( $customer, $locations );
		}
		return $customer;
	}

	/**
	 * Return provider settings and a viewport, never a preselected destination.
	 *
	 * @return array Public map configuration.
	 */
	public static function map_config(): array {
		$settings = self::sanitize_map( get_option( 'digitalogic_mobile_address_map', array() ) );
		$business = MobileStorefrontInfo::business();
		$point = $business['location'];
		return $settings + array( 'initial_lat' => $point['latitude'] ?? 0, 'initial_lon' => $point['longitude'] ?? 0, 'initial_zoom' => $point ? 12 : 3, 'min_zoom' => 3, 'max_zoom' => 19 );
	}

	/**
	 * Validate administrator-owned public tile configuration.
	 *
	 * @param mixed $input Stored/submitted provider settings.
	 * @return array Safe public settings.
	 */
	public static function sanitize_map( $input ): array {
		$defaults = array( 'enabled' => true, 'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'attribution_text' => '© OpenStreetMap contributors', 'attribution_url' => 'https://www.openstreetmap.org/copyright' );
		if ( ! is_array( $input ) || empty( $input['tile_url'] ) ) {
			return $defaults;
		}
		$url = $input['tile_url'];
		$attribution = $input['attribution_url'] ?? '';
		foreach ( array( $url, $attribution ) as $value ) {
			$parts = is_string( $value ) ? wp_parse_url( $value ) : false;
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $value ) > 1500 ) {
				return $defaults;
			}
		}
		if ( ! str_contains( $url, '{z}' ) || ! str_contains( $url, '{x}' ) || ! str_contains( $url, '{y}' ) || empty( $input['attribution_text'] ) ) {
			return $defaults;
		}
		return array( 'enabled' => ! empty( $input['enabled'] ), 'tile_url' => $url, 'attribution_text' => mb_substr( sanitize_text_field( $input['attribution_text'] ), 0, 200 ), 'attribution_url' => $attribution );
	}
}
