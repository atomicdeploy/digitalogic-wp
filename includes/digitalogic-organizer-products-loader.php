<?php
/**
 * Bootstrap adapter for the organizer product catalog endpoint.
 *
 * A current Digitalogic plugin bootstrap needs to require only this file. The
 * adapter owns both the class include and singleton initialization so rebasing
 * does not depend on a particular core-includes or component-init layout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-digitalogic-organizer-products-endpoint.php';

add_action(
	'init',
	static function () {
		$service_role = get_role( 'digitalogic_viewer_service' );
		$capability   = Digitalogic_Organizer_Products_Endpoint::CAPABILITY;
		if ( $service_role && ! $service_role->has_cap( $capability ) ) {
			$service_role->add_cap( $capability );
		}
	},
	5
);

Digitalogic_Organizer_Products_Endpoint::instance();
