<?php
/**
 * Authenticated login-proxy client identity normalization.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a trusted local login proxy without coupling the mechanism to one
 * consumer. Organizer can keep using the legacy configuration file during the
 * migration; new consumers use the generic external configuration.
 */
final class Digitalogic_Login_Proxy {
	/** Apply a verified forwarded client address before authentication runs. */
	public static function normalize(): void {
		$config = self::configuration();
		if ( ! $config ) {
			return;
		}

		$remote  = self::server_value( 'REMOTE_ADDR' );
		$trusted = isset( $config['trusted_proxies'] ) && is_array( $config['trusted_proxies'] )
			? array_values( array_filter( array_map( 'strval', $config['trusted_proxies'] ) ) )
			: array( '127.0.0.1', '::1' );
		if ( ! in_array( $remote, $trusted, true ) ) {
			return;
		}

		$proof_header = isset( $config['proof_header'] ) && is_string( $config['proof_header'] )
			? $config['proof_header']
			: 'X-Digitalogic-Auth-Key';
		$proof        = self::server_value( self::header_server_key( $proof_header ) );
		if ( strlen( $proof ) < 32 || ! self::valid_proof( $proof, $config ) ) {
			return;
		}

		$client_header = isset( $config['client_ip_header'] ) && is_string( $config['client_ip_header'] )
			? $config['client_ip_header']
			: 'X-Forwarded-For';
		$client_key    = self::header_server_key( $client_header );
		$client        = self::server_value( $client_key );
		if ( false !== filter_var( $client, FILTER_VALIDATE_IP ) ) {
			$_SERVER['REMOTE_ADDR'] = $client;
			unset( $_SERVER[ $client_key ] );
		}
	}

	/** Read generic configuration with a bounded legacy Organizer fallback. */
	private static function configuration(): array {
		$candidates = array();
		if ( defined( 'DIGITALOGIC_LOGIN_PROXY_CONFIG' ) ) {
			$candidates[] = (string) DIGITALOGIC_LOGIN_PROXY_CONFIG;
		}
		$candidates[] = '/etc/digitalogic/login-proxy.json';
		if ( defined( 'DIGITALOGIC_ORGANIZER_GATEWAY_CONFIG' ) ) {
			$candidates[] = (string) DIGITALOGIC_ORGANIZER_GATEWAY_CONFIG;
		}
		$candidates[] = '/etc/digitalogic/organizer-gateway.json';

		foreach ( array_unique( array_filter( $candidates ) ) as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$config = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Server-local protected configuration.
			if ( is_array( $config ) ) {
				return $config;
			}
		}

		return array();
	}

	/**
	 * Accept either one legacy secret or a generic list of consumer secrets.
	 *
	 * @param string $proof  Submitted authentication proof.
	 * @param array  $config Protected runtime configuration.
	 */
	private static function valid_proof( string $proof, array $config ): bool {
		$secrets = array();
		if ( isset( $config['secret'] ) && is_string( $config['secret'] ) ) {
			$secrets[] = $config['secret'];
		}
		if ( isset( $config['secrets'] ) && is_array( $config['secrets'] ) ) {
			foreach ( $config['secrets'] as $secret ) {
				if ( is_string( $secret ) ) {
					$secrets[] = $secret;
				}
			}
		}

		$valid = false;
		foreach ( array_unique( $secrets ) as $secret ) {
			if ( strlen( $secret ) >= 32 ) {
				$valid = hash_equals( $secret, $proof ) || $valid;
			}
		}
		return $valid;
	}

	/**
	 * Convert a configured HTTP header name to its PHP server-array key.
	 *
	 * @param string $header Configured HTTP header name.
	 */
	private static function header_server_key( string $header ): string {
		$header = strtoupper( str_replace( '-', '_', preg_replace( '/[^A-Za-z0-9-]/', '', $header ) ) );
		return str_starts_with( $header, 'HTTP_' ) ? $header : 'HTTP_' . $header;
	}

	/**
	 * Return one unslashed scalar server value.
	 *
	 * @param string $key Server-array key.
	 */
	private static function server_value( string $key ): string {
		$value = $_SERVER[ $key ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The bounded scalar is unslashed immediately below and validated by its caller.
		return is_string( $value ) ? wp_unslash( $value ) : '';
	}
}
