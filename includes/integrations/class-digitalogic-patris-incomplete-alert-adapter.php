<?php
/**
 * Root-owned synchronous provider-receipt adapter for incomplete catalog alerts.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Patris_Incomplete_Alert_Private_Adapter {
	private const FILTER = 'digitalogic_patris_incomplete_product_alert_delivery_adapter';

	/** Register the private delivery callable without exposing configuration. */
	public static function register(): void {
		add_filter( self::FILTER, array( self::class, 'select_adapter' ), 10, 2 );
		add_filter( 'pre_http_send_through_proxy', array( self::class, 'bypass_wordpress_proxy' ), 10, 4 );
		add_action( 'http_api_curl', array( self::class, 'force_direct_loopback_curl' ), 10, 3 );
	}

	/** Keep the one private loopback URL out of WordPress proxy selection. */
	public static function bypass_wordpress_proxy( $override, string $uri, $check, $home ) {
		unset( $check, $home );
		return self::endpoint() === $uri ? false : $override;
	}

	/**
	 * libcurl also honors process proxy variables, so explicitly bypass them for
	 * only this exact loopback endpoint.
	 *
	 * @param mixed  $handle      cURL handle.
	 * @param array  $parsed_args WordPress HTTP arguments.
	 * @param string $url         Request URL.
	 */
	public static function force_direct_loopback_curl( $handle, array $parsed_args, string $url ): void {
		unset( $parsed_args );
		if ( self::endpoint() !== $url || ! function_exists( 'curl_setopt' ) ) {
			return;
		}
		curl_setopt( $handle, CURLOPT_PROXY, '' );
		if ( defined( 'CURLOPT_NOPROXY' ) ) {
			curl_setopt( $handle, CURLOPT_NOPROXY, '*' );
		}
	}

	/**
	 * Select this fail-closed adapter for the notifier contract.
	 *
	 * @param mixed $adapter Existing adapter.
	 * @param mixed $event   Canonical event.
	 * @return callable
	 */
	public static function select_adapter( $adapter, $event ): callable {
		unset( $adapter, $event );
		return array( self::class, 'deliver' );
	}

	/**
	 * Deliver one exact event and return only a confirmed provider receipt.
	 *
	 * @param mixed $event Canonical alert event.
	 * @return array|WP_Error
	 */
	public static function deliver( $event ) {
		if ( ! self::valid_event( $event ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_event_invalid', 'The private alert event is invalid.' );
		}

		$secret = self::shared_secret();
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}
		$body = wp_json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > 65536 ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_event_invalid', 'The private alert event is invalid.' );
		}

		$timestamp = (string) time();
		$event_id  = (string) $event['event_id'];
		$material  = $timestamp . "\n" . $event_id . "\n" . hash( 'sha256', $body );
		$signature = hash_hmac( 'sha256', $material, $secret );
		$response  = wp_remote_post(
			self::endpoint(),
			array(
				'timeout'     => 18,
				'redirection' => 0,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type'                  => 'application/json',
					'X-Digitalogic-Alert-Timestamp' => $timestamp,
					'X-Digitalogic-Alert-Event-Id'  => $event_id,
					'X-Digitalogic-Alert-Signature' => 'sha256=' . $signature,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_unavailable', 'The private operator route is unavailable.' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_pending', 'The private operator route has no provider receipt yet.' );
		}

		$receipt = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! self::valid_receipt( $receipt, $event_id ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_receipt_invalid', 'The private operator route returned an invalid provider receipt.' );
		}

		return $receipt;
	}

	/** Return the server-local loopback endpoint without committing private routing. */
	private static function endpoint(): string {
		if ( defined( 'DIGITALOGIC_PATRIS_ALERT_ADAPTER_URL' ) ) {
			$endpoint = (string) DIGITALOGIC_PATRIS_ALERT_ADAPTER_URL;
		} else {
			$config   = json_decode( (string) @file_get_contents( '/etc/digitalogic/patris-incomplete-alert-adapter.json' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$endpoint = is_array( $config ) ? (string) ( $config['endpoint'] ?? '' ) : '';
		}

		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || 'http' !== ( $parts['scheme'] ?? '' ) || ! in_array( $parts['host'] ?? '', array( '127.0.0.1', '::1', 'localhost' ), true ) ) {
			return '';
		}

		return $endpoint;
	}

	/** Read the root-owned shared key without logging its value. */
	private static function shared_secret() {
		$file = defined( 'DIGITALOGIC_PATRIS_ALERT_ADAPTER_KEY_FILE' )
			? (string) DIGITALOGIC_PATRIS_ALERT_ADAPTER_KEY_FILE
			: '/etc/digitalogic-patris-incomplete-alert-adapter.key';
		if ( '' === $file || "\0" === substr( $file, 0, 1 ) || ! is_readable( $file ) || is_link( $file ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_auth_unavailable', 'The private operator route is unavailable.' );
		}
		$stat = @stat( $file );
		if ( ! is_array( $stat ) || 0 !== (int) ( $stat['uid'] ?? -1 ) || 0 !== ( (int) ( $stat['mode'] ?? 0 ) & 0007 ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_auth_unavailable', 'The private operator route is unavailable.' );
		}
		$secret = trim( (string) @file_get_contents( $file ) );
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 512 || 1 !== preg_match( '/\A[A-Za-z0-9._~+\/=\-]+\z/D', $secret ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_adapter_auth_unavailable', 'The private operator route is unavailable.' );
		}
		return $secret;
	}

	/** Validate the exact notifier contract before signing anything. */
	private static function valid_event( $event ): bool {
		return is_array( $event )
			&& ! array_is_list( $event )
			&& 'digitalogic.alert-event' === ( $event['schema'] ?? null )
			&& 'catalog.product_incomplete' === ( $event['event_type'] ?? null )
			&& 'digitalogic-patris-materializer' === ( $event['source'] ?? null )
			&& is_string( $event['event_id'] ?? null )
			&& 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $event['event_id'] )
			&& hash_equals( $event['event_id'], (string) ( $event['idempotency_key'] ?? '' ) )
			&& array( 'telegram' ) === ( $event['notify_channels'] ?? null )
			&& array( 'shokri' ) === ( $event['audience']['operators'] ?? null );
	}

	/** Validate enough of the response to keep 202/pending out of the notifier. */
	private static function valid_receipt( $receipt, string $event_id ): bool {
		$provider = is_array( $receipt['provider_receipt'] ?? null ) ? $receipt['provider_receipt'] : array();
		$message  = trim( (string) ( $provider['message_id'] ?? '' ) );
		$time     = trim( (string) ( $provider['delivered_at'] ?? '' ) );
		return is_array( $receipt )
			&& ! array_is_list( $receipt )
			&& true === ( $receipt['ok'] ?? null )
			&& 'delivered' === ( $receipt['status'] ?? null )
			&& 'telegram' === ( $receipt['channel'] ?? null )
			&& 'shokri' === ( $receipt['audience'] ?? null )
			&& hash_equals( $event_id, (string) ( $receipt['event_id'] ?? '' ) )
			&& hash_equals( $event_id, (string) ( $receipt['idempotency_key'] ?? '' ) )
			&& 'telegram' === ( $provider['provider'] ?? null )
			&& 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/D', $message )
			&& false !== strtotime( $time );
	}
}
