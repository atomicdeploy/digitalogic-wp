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
     * The provider adapter owns its exact-source credential headers. The
     * resulting principal can receive pricing events but cannot dispatch any
     * generic WordPress command.
     */
	public static function authenticate_context( $headers, $query ) {
		$headers   = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : array();
		$protected = self::pricing_protected_headers();
		if ( array_intersect( $protected, array_keys( $headers ) ) ) {
			return Digitalogic_Pricing_Adapter_Registry::instance()->provider()->authenticate_websocket( $headers );
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
		return Digitalogic_Pricing_Adapter_Registry::instance()->provider()->websocket_context_is_current( $context );
	}

	/** Return the selected provider's exact pricing principal. */
	public static function pricing_principal() {
		return (string) Digitalogic_Pricing_Adapter_Registry::instance()->provider()->event_principal();
	}

	/** Return the selected provider's protected handshake header names. */
	public static function pricing_protected_headers() {
		return array_values(
			array_unique(
				array_map(
					static function ( $header ) {
						return strtolower( trim( (string) $header ) );
					},
					(array) Digitalogic_Pricing_Adapter_Registry::instance()->provider()->websocket_protected_headers()
				)
			)
		);
	}

	/** Return whether one context belongs to the selected pricing provider. */
	public static function is_pricing_context( $context ) {
		$context = is_array( $context ) ? $context : array();
		$principal = self::pricing_principal();

		return '' !== $principal && hash_equals( $principal, (string) ( $context['principal'] ?? '' ) );
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
