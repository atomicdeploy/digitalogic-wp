<?php

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Webhooks {

	private const GENERIC_URL_OPTION    = 'digitalogic_admin_n8n_url';
	private const GENERIC_SECRET_OPTION = 'digitalogic_admin_n8n_secret';
	private const AUTH_URL_OPTION       = 'digitalogic_admin_n8n_auth_url';
	private const AUTH_SECRET_OPTION    = 'digitalogic_admin_n8n_auth_secret';
	private const USER_URL_OPTION       = 'digitalogic_admin_n8n_user_url';
	private const USER_SECRET_OPTION    = 'digitalogic_admin_n8n_user_secret';
	private const SCHEMA                = 'digitalogic.admin.event.v1';
	private const MAX_PAYLOAD_BYTES     = 4096;
	private const MIN_SECRET_BYTES      = 32;

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'wp_login', array( self::class, 'login_succeeded' ), 10, 2 );
		add_action( 'wp_login_failed', array( self::class, 'login_failed' ), 10, 2 );
		add_action( 'user_register', array( self::class, 'user_created' ), 10, 2 );
		add_action( 'profile_update', array( self::class, 'user_updated' ), 10, 3 );
	}

	public static function install_options(): void {
		foreach ( self::option_names() as $option_name ) {
			add_option( $option_name, '', '', false );
		}
	}

	public static function login_succeeded( string $_user_login, \WP_User $user ): void {
		self::dispatch( 'auth.login.succeeded', 'auth', (int) $user->ID );
	}

	public static function login_failed( string $_attempted_login, mixed $error = null ): void {
		$codes = array();
		if ( is_wp_error( $error ) ) {
			foreach ( array_slice( $error->get_error_codes(), 0, 5 ) as $code ) {
				$codes[] = substr( sanitize_key( (string) $code ), 0, 48 );
			}
		}

		self::dispatch( 'auth.login.failed', 'auth', 0, array( 'failure_codes' => array_values( array_filter( $codes ) ) ) );
	}

	/** @param array<string,mixed> $_userdata */
	public static function user_created( int $user_id, array $_userdata = array() ): void {
		self::dispatch( 'user.created', 'user', $user_id );
	}

	/** @param array<string,mixed> $_userdata */
	public static function user_updated( int $user_id, \WP_User $_old_user_data, array $_userdata = array() ): void {
		self::dispatch( 'user.updated', 'user', $user_id );
	}

	/** @return array{configured:int,total:int} */
	public static function configuration_counts(): array {
		$configured = 0;
		foreach ( array( 'auth', 'user' ) as $group ) {
			if ( self::configuration( $group ) !== null ) {
				++$configured;
			}
		}

		return array(
			'configured' => $configured,
			'total'      => 2,
		);
	}

	/** @param array<string,mixed> $properties */
	private static function dispatch( string $event, string $group, int $user_id = 0, array $properties = array() ): void {
		$configuration = self::configuration( $group );
		if ( $configuration === null ) {
			return;
		}

		$event_id    = wp_generate_uuid4();
		$occurred_at = gmdate( 'c' );
		$payload     = array(
			'schema'      => self::SCHEMA,
			'event'       => $event,
			'event_id'    => $event_id,
			'occurred_at' => $occurred_at,
			'brand'       => Brand::KEY,
			'site_ref'    => substr( hash_hmac( 'sha256', strtolower( home_url( '/' ) ), $configuration['secret'] ), 0, 24 ),
		);

		if ( $user_id > 0 ) {
			$payload['subject_ref'] = substr( hash_hmac( 'sha256', 'user:' . $user_id, $configuration['secret'] ), 0, 24 );
		}

		if ( $properties !== array() ) {
			$payload['properties'] = $properties;
		}

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return;
		}

		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $json, $configuration['secret'] );

		wp_safe_remote_post(
			$configuration['url'],
			array(
				'timeout'             => 4,
				'redirection'         => 0,
				'blocking'            => false,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 1024,
				'headers'             => array(
					'Content-Type'                  => 'application/json; charset=utf-8',
					'X-Digitalogic-Admin-Event'     => $event,
					'X-Digitalogic-Admin-Event-Id'  => $event_id,
					'X-Digitalogic-Admin-Timestamp' => $timestamp,
					'X-Digitalogic-Admin-Signature' => 'sha256=' . $signature,
				),
				'body'                => $json,
				'data_format'         => 'body',
			)
		);
	}

	/** @return array{url:string,secret:string}|null */
	private static function configuration( string $group ): ?array {
		$prefix          = strtoupper( $group );
		$specific_url    = self::configuration_value(
			'DIGITALOGIC_ADMIN_N8N_' . $prefix . '_URL',
			$group === 'auth' ? self::AUTH_URL_OPTION : self::USER_URL_OPTION
		);
		$specific_secret = self::configuration_value(
			'DIGITALOGIC_ADMIN_N8N_' . $prefix . '_SECRET',
			$group === 'auth' ? self::AUTH_SECRET_OPTION : self::USER_SECRET_OPTION
		);

		$url    = $specific_url !== ''
			? $specific_url
			: self::configuration_value( 'DIGITALOGIC_ADMIN_N8N_URL', self::GENERIC_URL_OPTION );
		$secret = $specific_secret !== ''
			? $specific_secret
			: self::configuration_value( 'DIGITALOGIC_ADMIN_N8N_SECRET', self::GENERIC_SECRET_OPTION );

		if ( ! self::is_safe_https_url( $url ) || strlen( $secret ) < self::MIN_SECRET_BYTES ) {
			return null;
		}

		return array(
			'url'    => $url,
			'secret' => $secret,
		);
	}

	private static function configuration_value( string $constant_name, string $option_name ): string {
		$value = defined( $constant_name ) ? constant( $constant_name ) : get_option( $option_name, '' );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function is_safe_https_url( string $url ): bool {
		if ( $url === '' || strlen( $url ) > 2048 || wp_http_validate_url( $url ) === false ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 443;

		if (
			$scheme !== 'https'
			|| $host === ''
			|| $port !== 443
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] )
		) {
			return false;
		}

		if (
			$host === 'localhost'
			|| str_ends_with( $host, '.localhost' )
			|| str_ends_with( $host, '.local' )
			|| str_ends_with( $host, '.internal' )
		) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
			return filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			) !== false;
		}

		return true;
	}

	/** @return string[] */
	private static function option_names(): array {
		return array(
			self::GENERIC_URL_OPTION,
			self::GENERIC_SECRET_OPTION,
			self::AUTH_URL_OPTION,
			self::AUTH_SECRET_OPTION,
			self::USER_URL_OPTION,
			self::USER_SECRET_OPTION,
		);
	}
}
