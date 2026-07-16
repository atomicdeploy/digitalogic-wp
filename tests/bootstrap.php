<?php
/**
 * Lightweight WordPress stubs for unit-testing REST permission policy.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['digitalogic_test_capabilities'] = array();
$GLOBALS['digitalogic_test_filters'] = array();
$GLOBALS['digitalogic_test_routes'] = array();
$GLOBALS['digitalogic_test_rest_url'] = 'https://example.test/wp-json/';

class WP_REST_Request {
}

class Digitalogic_Patris_Feed {
    private static $instance;

    public $verified_request;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function verify_push_request($request) {
        $this->verified_request = $request;

        return 'patris-verifier-result';
    }
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
}

function current_user_can($capability) {
    return !empty($GLOBALS['digitalogic_test_capabilities'][$capability]);
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['digitalogic_test_filters'][$hook_name][] = array(
        'callback' => $callback,
        'accepted_args' => $accepted_args,
    );

    return true;
}

function remove_all_filters($hook_name) {
    unset($GLOBALS['digitalogic_test_filters'][$hook_name]);

    return true;
}

function apply_filters($hook_name, $value, ...$args) {
    if (empty($GLOBALS['digitalogic_test_filters'][$hook_name])) {
        return $value;
    }

    foreach ($GLOBALS['digitalogic_test_filters'][$hook_name] as $filter) {
        $parameters = array_merge(array($value), $args);
        $parameters = array_slice($parameters, 0, $filter['accepted_args']);
        $value = call_user_func_array($filter['callback'], $parameters);
    }

    return $value;
}

function register_rest_route($namespace, $route, $args = array(), $override = false) {
    $GLOBALS['digitalogic_test_routes'][] = array(
        'namespace' => $namespace,
        'route' => $route,
        'args' => $args,
    );

    return true;
}

function wp_unslash($value) {
    return $value;
}

function wp_parse_url($url, $component = -1) {
    return parse_url($url, $component);
}

function rest_url($path = '') {
    $base = $GLOBALS['digitalogic_test_rest_url'];

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

require_once dirname(__DIR__) . '/includes/api/class-rest-api.php';
