<?php
/**
 * Shared WordPress-native authorization policy for the Digitalogic panel.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central access predicate shared by every panel transport.
 */
final class Digitalogic_Access_Control {

	/**
	 * Check whether the current WordPress user can operate the Digitalogic panel.
	 *
	 * WooCommerce managers remain the primary operator role. The manage_options
	 * fallback keeps WordPress administrators in control when a custom role or
	 * another plugin has altered WooCommerce's role capabilities.
	 *
	 * @return bool
	 */
	public static function can_access_panel() {
		$capabilities = (array) apply_filters(
			'digitalogic_panel_access_capabilities',
			array( 'manage_woocommerce', 'manage_options' )
		);

		$allowed = false;
		foreach ( array_unique( array_filter( array_map( 'sanitize_key', $capabilities ) ) ) as $capability ) {
			if ( current_user_can( $capability ) ) {
				$allowed = true;
				break;
			}
		}

		return (bool) apply_filters(
			'digitalogic_panel_access_allowed',
			$allowed,
			get_current_user_id(),
			$capabilities
		);
	}
}
