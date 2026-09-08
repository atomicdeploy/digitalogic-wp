<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authenticated server-to-server REST surface for trusted viewer proxies.
 */
final class Rest
{
    private const NAMESPACE = 'digitalogic-viewer/v1';
    private const PRODUCTION_ORIGINS = array(
        'https://digitalogic-tree-of-life.atomicdeploy.chatgpt.site',
        'https://visual.digitalogic.ir',
    );

    private static int $application_password_user_id = 0;

    public static function register(): void
    {
        add_filter(
            'wp_is_application_passwords_available',
            array(self::class, 'application_passwords_available'),
            PHP_INT_MAX,
            1
        );
        add_action(
            'application_password_did_authenticate',
            array(self::class, 'application_password_authenticated'),
            10,
            2
        );
        add_action('rest_api_init', array(self::class, 'routes'));
        add_filter('rest_post_dispatch', array(self::class, 'response_headers'), 20, 3);
        add_filter('rest_pre_serve_request', array(self::class, 'cors_headers'), 20, 4);
    }

    /**
     * Wordfence disables Application Passwords globally on this installation.
     * Preserve that policy everywhere except the exact bridge namespace,
     * whose permission callbacks additionally require the dedicated service
     * role and custom viewer capability.
     */
    public static function application_passwords_available(bool $available): bool
    {
        if ($available) {
            return true;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        $host = strtolower(
            preg_replace('/:\d+$/', '', trim((string) ($_SERVER['HTTP_HOST'] ?? '')))
        );
        $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (
            !in_array($method, array('GET', 'POST'), true)
            || !in_array($host, array('digitalogic.ir', 'www.digitalogic.ir'), true)
            || !is_string($path)
        ) {
            return false;
        }
        $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/' . self::NAMESPACE . '/';
        return str_starts_with(rawurldecode($path), $prefix);
    }

    /**
     * @param mixed $item
     */
    public static function application_password_authenticated(WP_User $user, $item): void
    {
        unset($item);
        self::$application_password_user_id = (int) $user->ID;
    }

    public static function routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/state',
            array(
                'methods' => 'GET',
                'callback' => array(self::class, 'state'),
                'permission_callback' => array(self::class, 'can_read'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/realtime-token',
            array(
                'methods' => array('GET', 'POST'),
                'callback' => array(self::class, 'realtime_token'),
                'permission_callback' => array(self::class, 'can_read'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/action',
            array(
                'methods' => 'POST',
                'callback' => array(self::class, 'action'),
                'permission_callback' => array(self::class, 'can_manage'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/products/(?P<id>product:(?:woo:[0-9]+|patris:[A-Za-z0-9._:%-]+))/orders',
            array(
                'methods' => 'GET',
                'callback' => array(self::class, 'product_orders'),
                'permission_callback' => array(self::class, 'can_read'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/patris-commerce',
            array(
                'methods' => 'POST',
                'callback' => array(self::class, 'patris_commerce'),
                'permission_callback' => array(Patris_Commerce::class, 'permission'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/patris-commerce/heartbeat',
            array(
                'methods' => 'POST',
                'callback' => array(self::class, 'patris_commerce_heartbeat'),
                'permission_callback' => array(Patris_Commerce::class, 'permission'),
            )
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function state(WP_REST_Request $request)
    {
        return self::safe_response(Live_State::build($request));
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function product_orders(WP_REST_Request $request)
    {
        return self::safe_response(
            Live_State::product_orders(
                rawurldecode((string) $request['id']),
                max(1, (int) ($request->get_param('page') ?: 1)),
                max(1, min(100, (int) ($request->get_param('limit') ?: 50)))
            )
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function patris_commerce(WP_REST_Request $request)
    {
        $result = Patris_Commerce::ingest($request);
        return $result instanceof WP_Error
            ? $result
            : self::safe_response($result);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function patris_commerce_heartbeat(WP_REST_Request $request)
    {
        $result = Patris_Commerce::heartbeat($request);
        return $result instanceof WP_Error
            ? $result
            : self::safe_response($result);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function realtime_token(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        $origin = trim(
            (string) (
                $request->get_header('X-Digitalogic-Viewer-Origin')
                ?: $request->get_header('Origin')
                ?: (is_array($json) ? ($json['origin'] ?? '') : '')
                ?: $request->get_param('origin')
            )
        );
        if (!self::origin_allowed($origin)) {
            return new WP_Error(
                'digitalogic_viewer_origin_not_allowed',
                __('The requested viewer Origin is not allowed.', 'digitalogic-viewer-bridge'),
                array('status' => 403)
            );
        }

        $scopes = array('realtime:read', 'drafts:read', 'layouts:read');
        if (current_user_can('digitalogic_viewer_manage')) {
            $scopes[] = 'drafts:write';
            $scopes[] = 'layouts:write';
        }
        $issued = Redis::issue_websocket_token(
            array(
                'subject' => 'wp-user:' . get_current_user_id(),
                'origin' => $origin,
                'scopes' => $scopes,
                'expiresAt' => '',
                'expiresUnix' => 0,
            ),
            90
        );
        if ($issued instanceof WP_Error) {
            return $issued;
        }
        return self::safe_response(
            array(
                'url' => Live_State::websocket_url(),
                'token' => $issued['token'],
                'expiresAt' => $issued['expiresAt'],
                'protocol' => 'digitalogic-viewer-v1',
            )
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function action(WP_REST_Request $request)
    {
        $result = Actions::handle($request);
        return $result instanceof WP_Error ? $result : self::safe_response($result);
    }

    /**
     * @return true|WP_Error
     */
    public static function can_read()
    {
        return self::permission('digitalogic_viewer_read');
    }

    /**
     * @return true|WP_Error
     */
    public static function can_manage()
    {
        return self::permission('digitalogic_viewer_manage');
    }

    /**
     * @param mixed $response
     * @param mixed $server
     * @return mixed
     */
    public static function response_headers($response, $server, WP_REST_Request $request)
    {
        unset($server);
        if (!str_starts_with($request->get_route(), '/' . self::NAMESPACE . '/')) {
            return $response;
        }
        if ($response instanceof WP_REST_Response) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Vary', 'Authorization, Origin');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }

    /**
     * Remove the host's broad CORS headers for this namespace. Direct browser
     * use is still unsupported because credentials belong only in Sites.
     *
     * @param mixed $served
     * @param mixed $result
     * @param mixed $server
     * @return mixed
     */
    public static function cors_headers(
        $served,
        $result,
        WP_REST_Request $request,
        $server
    ) {
        unset($result, $server);
        if (!str_starts_with($request->get_route(), '/' . self::NAMESPACE . '/')) {
            return $served;
        }
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
        $origin = trim((string) $request->get_header('Origin'));
        if (self::origin_allowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Headers: Authorization, Content-Type, If-Match, Idempotency-Key, X-Digitalogic-Viewer-Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        }
        return $served;
    }

    /**
     * @return true|WP_Error
     */
    private static function permission(string $capability)
    {
        $user = wp_get_current_user();
        $application_password_uuid = function_exists(
            'rest_get_authenticated_app_password'
        )
            ? rest_get_authenticated_app_password()
            : null;
        $application_password_authenticated =
            self::$application_password_user_id === (int) $user->ID
            || (
                is_string($application_password_uuid)
                && $application_password_uuid !== ''
            );
        if (
            !$user->exists()
            || !$application_password_authenticated
            || !in_array('digitalogic_viewer_service', (array) $user->roles, true)
            || !current_user_can($capability)
        ) {
            return new WP_Error(
                'digitalogic_viewer_service_auth_required',
                __(
                    'A least-privilege Viewer Service Application Password is required.',
                    'digitalogic-viewer-bridge'
                ),
                array('status' => 401)
            );
        }
        return true;
    }

    private static function origin_allowed(string $origin): bool
    {
        foreach (self::PRODUCTION_ORIGINS as $production_origin) {
            if (hash_equals($production_origin, $origin)) {
                return true;
            }
        }
        if (
            defined('DIGITALOGIC_VIEWER_ALLOW_LOCALHOST')
            && DIGITALOGIC_VIEWER_ALLOW_LOCALHOST === true
        ) {
            return (bool) preg_match(
                '#^https?://(?:localhost|127\.0\.0\.1)(?::[0-9]{1,5})?$#D',
                $origin
            );
        }
        return false;
    }

    /**
     * @return true|WP_Error
     */
    private static function permission_value_is_safe($value, string $key = '')
    {
        $forbidden = array(
            'email',
            'phone',
            'billing',
            'shipping',
            'address',
            'paymentmethod',
            'paymenttoken',
            'ipaddress',
            'useragent',
            'userlogin',
        );
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');
        $private_fragment = $normalized !== ''
            && (
                str_contains($normalized, 'email')
                || str_contains($normalized, 'phone')
                || str_contains($normalized, 'address')
                || str_starts_with($normalized, 'billing')
                || str_starts_with($normalized, 'shipping')
                || str_starts_with($normalized, 'payment')
            );
        if (
            $normalized !== ''
            && ($private_fragment || in_array($normalized, $forbidden, true))
        ) {
            return new WP_Error(
                'digitalogic_viewer_privacy_guard',
                __('A forbidden private field reached the viewer response.', 'digitalogic-viewer-bridge'),
                array('status' => 500)
            );
        }
        if (!is_array($value)) {
            return true;
        }
        foreach ($value as $child_key => $child) {
            $safe = self::permission_value_is_safe(
                $child,
                is_string($child_key) ? $child_key : ''
            );
            if ($safe instanceof WP_Error) {
                return $safe;
            }
        }
        return true;
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    private static function safe_response(array $data)
    {
        $safe = self::permission_value_is_safe($data);
        return $safe instanceof WP_Error ? $safe : new WP_REST_Response($data, 200);
    }
}
