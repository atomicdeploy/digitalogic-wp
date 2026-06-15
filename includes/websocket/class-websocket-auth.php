<?php
/**
 * WebSocket authentication helpers.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_WebSocket_Auth {

    public static function authenticate($headers, $query) {
        $token = isset($query['token']) ? (string) $query['token'] : '';
        if ($token !== '' && hash_equals(Digitalogic_WebSocket::get_server_token(), $token)) {
            return self::system_user_id();
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
        $nonce = isset($query['nonce']) ? (string) $query['nonce'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'digitalogic_ws')) {
            return 0;
        }

        return current_user_can('manage_woocommerce') ? $user_id : 0;
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
