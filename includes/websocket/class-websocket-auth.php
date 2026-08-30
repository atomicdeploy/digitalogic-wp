<?php
/**
 * WebSocket authentication helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_WebSocket_Auth {

	/**
     * Authenticate a WebSocket handshake and retain its least-privilege role.
     *
     * Patris uses its existing exact-source credential in headers only. The
     * resulting principal can receive pricing events but cannot dispatch any
     * generic WordPress command.
     */
	public static function authenticate_context( $headers, $query ) {
		$headers          = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : array();
		$secret           = isset($headers['x-patris-product-sync-secret'])
			? (string) $headers['x-patris-product-sync-secret']
			: '';
		$source           = array(
			'id'      => isset($headers['x-patris-source-id']) ? trim( (string) $headers['x-patris-source-id']) : '',
			'dataset' => isset($headers['x-patris-source-dataset']) ? trim( (string) $headers['x-patris-source-dataset']) : '',
		);
		$patris_attempted = '' !== $secret || '' !== $source['id'] || '' !== $source['dataset'];
		if ( $patris_attempted ) {
			$feed = class_exists( 'Digitalogic_Patris_Feed' ) ? Digitalogic_Patris_Feed::instance() : null;
			self::refresh_pricing_auth_options( $feed );
			$scopes  = $feed ? $feed->get_product_sync_source_scopes() : array();
			$allowed = '' !== $source['id']
				&& '' !== $source['dataset']
				&& ! empty( $scopes )
				&& $feed
				&& $feed->verify_product_sync_credential_for_source(
					$secret,
					$source,
					false
				);

			return array(
				'authenticated'          => (bool) $allowed,
				'user_id'                => 0,
				'principal'              => $allowed ? 'patris_pricing' : '',
				'source'                 => $allowed ? $source : array(),
				'credential_fingerprint' => $allowed ? $feed->product_sync_credential_fingerprint_for_source( $source ) : '',
			);
		}

		$user_id = self::authenticate($headers, $query);

		return array(
			'authenticated'          => (bool) $user_id,
			'user_id'                => (int) $user_id,
			'principal'              => -1 === (int) $user_id ? 'public' : ( (int) $user_id > 0 ? 'user' : '' ),
			'source'                 => array(),
			'credential_fingerprint' => '',
		);
	}

	/** Revalidate one already-connected pricing principal without retaining its secret. */
	public static function pricing_service_context_is_current( $context ) {
		$context = is_array( $context ) ? $context : array();
		$source  = isset( $context['source'] ) && is_array( $context['source'] ) ? $context['source'] : array();
		$stored  = isset( $context['credential_fingerprint'] ) ? (string) $context['credential_fingerprint'] : '';
		$feed    = class_exists( 'Digitalogic_Patris_Feed' ) ? Digitalogic_Patris_Feed::instance() : null;
		if ( ! $feed || '' === $stored ) {
			return false;
		}
		self::refresh_pricing_auth_options( $feed );
		$current = $feed->product_sync_credential_fingerprint_for_source( $source );

		return '' !== $current && hash_equals( $stored, $current );
	}

	/** Purge only authentication options cached by this long-running daemon. */
	private static function refresh_pricing_auth_options( $feed ) {
		if ( ! $feed || ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION, 'options' );
		wp_cache_delete( Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION, 'options' );
	}

    public static function authenticate($headers, $query) {
        $token = isset($query['token']) ? (string) $query['token'] : '';
        if ($token !== '' && hash_equals(Digitalogic_WebSocket::get_server_token(), $token)) {
            return self::system_user_id();
        }

        if ($token !== '') {
            $user_id = Digitalogic_WebSocket::validate_client_token($token);
            if ($user_id) {
                return $user_id;
            }

            if (Digitalogic_WebSocket::validate_public_token($token)) {
                wp_set_current_user(0);
                return -1;
            }
        }

        $nonce = isset($query['nonce']) ? (string) $query['nonce'] : '';
        wp_set_current_user(0);
        if ($nonce && wp_verify_nonce($nonce, 'digitalogic_ws_public')) {
            return -1;
        }

        $cookie_header = isset($headers['cookie']) ? $headers['cookie'] : '';
        if ($cookie_header === '') {
            return 0;
        }

        $cookies = self::parse_cookie_header($cookie_header);
        foreach ($cookies as $name => $value) {
            $_COOKIE[$name] = $value;
        }

        $user_id = wp_validate_auth_cookie('', 'logged_in');
        if (!$user_id) {
            return 0;
        }

        wp_set_current_user($user_id);
        if (!$nonce || !wp_verify_nonce($nonce, 'digitalogic_ws')) {
            return 0;
        }

        return Digitalogic_Access_Control::can_access_panel() ? $user_id : 0;
    }

    private static function parse_cookie_header($header) {
        $cookies = array();
        foreach (explode(';', $header) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2) {
                $cookies[$parts[0]] = urldecode($parts[1]);
            }
        }

        return $cookies;
    }

    private static function system_user_id() {
        $admins = get_users(array(
            'role' => 'administrator',
            'number' => 1,
            'fields' => 'ID',
        ));

        $user_id = !empty($admins) ? intval($admins[0]) : 0;
        if ($user_id) {
            wp_set_current_user($user_id);
        }

        return $user_id;
    }
}
