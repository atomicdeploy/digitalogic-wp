<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Shatel SIM primary SMS gateway with uncertainty-safe SMS.ir fallback.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

final class Digitalogic_Shatel_SMS_Primary {
	private const CONFIG_FILE = '/etc/digitalogic/shatel-sms.json';

	public static function bootstrap(): void {
		add_filter( 'pre_http_request', array( self::class, 'maybe_route_smsir' ), 5, 3 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'digitalogic-shatel-sms', array( self::class, 'cli' ) );
		}
	}

	public static function maybe_route_smsir( $preempt, array $args, string $url ) {
		if ( false !== $preempt || 'POST' !== strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
			return $preempt;
		}

		$parts = wp_parse_url( $url );
		if ( 'api.sms.ir' !== ( $parts['host'] ?? '' ) || '/v1/send/verify' !== rtrim( (string) ( $parts['path'] ?? '' ), '/' ) ) {
			return $preempt;
		}

		$body = self::decode_body( $args['body'] ?? null );
		if ( ! $body ) {
			return $preempt;
		}

		$digits              = get_option( 'digit_smsir2', array() );
		$configured_template = is_array( $digits ) ? (string) ( $digits['template'] ?? '' ) : '';
		if ( '' === $configured_template || (string) ( $body['templateId'] ?? '' ) !== $configured_template ) {
			return $preempt;
		}

		$mobile = self::normalize_mobile( (string) ( $body['mobile'] ?? '' ) );
		$otp    = self::otp_from_parameters( $body['parameters'] ?? array() );
		if ( '' === $mobile || '' === $otp ) {
			return $preempt;
		}

		$result = self::gateway_request(
			'POST',
			'/v1/send',
			array(
				'to'              => $mobile,
				'text'            => "کد ورود دیجیتالاجیک: {$otp}\nاین کد را در اختیار دیگران قرار ندهید.",
				'idempotency_key' => 'wp:' . substr( hash( 'sha256', $mobile . "\0" . $otp . "\0" . floor( time() / 300 ) ), 0, 48 ),
			)
		);

		if ( ! empty( $result['ok'] ) && ! empty( $result['accepted'] ) && ! empty( $result['outbox_id'] ) ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'status'  => 1,
						'message' => 'Accepted by primary gateway',
						'data'    => array( 'messageId' => (int) $result['outbox_id'] ),
					),
					JSON_UNESCAPED_UNICODE
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( array_key_exists( 'fallback_safe', $result ) && false === $result['fallback_safe'] ) {
			return new WP_Error( 'digitalogic_shatel_submission_uncertain', 'OTP delivery is still being confirmed. Please wait before requesting another code.' );
		}

		if ( empty( $result['ok'] ) || empty( $result['accepted'] ) || empty( $result['outbox_id'] ) ) {
			error_log( 'Digitalogic Shatel OTP primary unavailable; using configured fallback' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $preempt;
		}

		return $preempt;
	}

	private static function decode_body( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function otp_from_parameters( $parameters ): string {
		if ( ! is_array( $parameters ) ) {
			return '';
		}
		foreach ( $parameters as $parameter ) {
			if ( ! is_array( $parameter ) ) {
				continue;
			}
			$name  = strtoupper( (string) ( $parameter['name'] ?? '' ) );
			$value = trim( (string) ( $parameter['value'] ?? '' ) );
			if ( in_array( $name, array( 'CODE', 'OTP', 'VERIFICATION_CODE' ), true ) && 1 === preg_match( '/^[0-9]{4,8}$/D', $value ) ) {
				return $value;
			}
		}
		return '';
	}

	private static function normalize_mobile( string $value ): string {
		$number = preg_replace( '/[^0-9+]/', '', $value );
		if ( str_starts_with( $number, '0098' ) ) {
			$number = '+98' . substr( $number, 4 );
		} elseif ( str_starts_with( $number, '98' ) ) {
			$number = '+' . $number;
		} elseif ( str_starts_with( $number, '09' ) ) {
			$number = '+98' . substr( $number, 1 );
		}
		return 1 === preg_match( '/^\+989[0-9]{9}$/D', $number ) ? $number : '';
	}

	private static function configuration(): array {
		$path = defined( 'DIGITALOGIC_SHATEL_SMS_CONFIG' ) ? (string) DIGITALOGIC_SHATEL_SMS_CONFIG : self::CONFIG_FILE;
		$config = is_readable( $path )
			? json_decode( (string) file_get_contents( $path ), true ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Server-local protected configuration.
			: array();
		if ( ! is_array( $config ) || empty( $config ) ) {
			$stored = get_option( 'digitalogic_shatel_sms_runtime', array() );
			$config = is_array( $stored ) ? $stored : array();
		}

		$url        = rtrim( (string) ( $config['gateway_url'] ?? '' ), '/' );
		$token_file = (string) ( $config['token_file'] ?? '' );
		$parts      = wp_parse_url( $url );
		if ( 'http' !== ( $parts['scheme'] ?? '' ) || ! in_array( $parts['host'] ?? '', array( '127.0.0.1', '::1', 'localhost' ), true ) || '' === $token_file || '/' !== $token_file[0] ) {
			return array();
		}

		return array(
			'gateway_url' => $url,
			'token_file'  => $token_file,
		);
	}

	private static function gateway_request( string $method, string $path, array $payload = array() ): array {
		$config = self::configuration();
		if ( empty( $config ) || ! is_readable( $config['token_file'] ) || ! function_exists( 'curl_init' ) ) {
			return array();
		}

		$token = trim( (string) file_get_contents( $config['token_file'] ) );
		if ( strlen( $token ) < 32 ) {
			return array();
		}

		$handle  = curl_init( $config['gateway_url'] . $path );
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT        => 'POST' === $method ? 120 : 30,
			CURLOPT_NOSIGNAL       => true,
			CURLOPT_NOPROXY        => '127.0.0.1,localhost',
			CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . $token ),
		);
		if ( 'POST' === $method ) {
			$options[ CURLOPT_POST ]         = true;
			$options[ CURLOPT_POSTFIELDS ]   = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
			$options[ CURLOPT_HTTPHEADER ][] = 'Content-Type: application/json';
		}
		curl_setopt_array( $handle, $options );
		$raw        = curl_exec( $handle );
		$curl_errno = curl_errno( $handle );
		$status     = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		curl_close( $handle );

		if ( ! is_string( $raw ) ) {
			return in_array( $curl_errno, array( CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST ), true )
				? array(
					'ok'            => false,
					'fallback_safe' => true,
				)
				: array(
					'ok'            => false,
					'fallback_safe' => false,
					'uncertain'     => true,
				);
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'ok'            => false,
				'fallback_safe' => false,
				'uncertain'     => true,
			);
		}
		$decoded['_http_status'] = $status;
		return $decoded;
	}

	public static function cli( array $args ): void {
		if ( 'status' !== ( $args[0] ?? 'status' ) ) {
			WP_CLI::error( 'Only the status command is supported.' );
		}
		$body = self::gateway_request( 'GET', '/v1/status' );
		if ( empty( $body['ok'] ) ) {
			WP_CLI::error( 'Shatel SMS gateway is unavailable.' );
		}
		WP_CLI::success(
			sprintf(
				'Shatel primary is ready; registered=%s, SIM storage=%s/%s.',
				! empty( $body['modem']['registered'] ) ? 'yes' : 'no',
				(string) ( $body['modem']['storage_used'] ?? '?' ),
				(string) ( $body['modem']['storage_total'] ?? '?' )
			)
		);
	}
}
