<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Plugin Name: Digitalogic HTTP Resilience
 * Description: Keeps outbound WordPress requests from blocking interactive requests.
 */

defined( 'ABSPATH' ) || exit;

function digitalogic_is_themepunch_update_url( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );

	return is_string( $host )
		&& preg_match( '/^updates\.themepunch(?:-ext-[a-z0-9-]+)?\.tools$/i', $host ) === 1;
}

add_filter(
	'http_request_args',
	function ( $args, $url ) {
		if ( ! digitalogic_is_themepunch_update_url( $url ) ) {
			return $args;
		}

		$timeout         = isset( $args['timeout'] ) ? (float) $args['timeout'] : 8.0;
		$args['timeout'] = min( $timeout, 8.0 );

		return $args;
	},
	PHP_INT_MAX,
	2
);

add_action(
	'http_api_curl',
	function ( $handle ) {
		if (
		function_exists( 'curl_setopt' )
		&& defined( 'CURLOPT_IPRESOLVE' )
		&& defined( 'CURL_IPRESOLVE_V4' )
		) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
	},
	PHP_INT_MAX,
	1
);
