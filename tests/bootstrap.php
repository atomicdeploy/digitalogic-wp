<?php

/**
 * Lightweight WordPress, Redis, and WP-CLI stubs shared by plugin unit tests.
 */

define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);

$GLOBALS['digitalogic_test_capabilities'] = array();
$GLOBALS['digitalogic_test_filters'] = array();
$GLOBALS['digitalogic_test_routes'] = array();
$GLOBALS['digitalogic_test_rest_prefix'] = 'wp-json';
$GLOBALS['digitalogic_test_rest_url_calls'] = 0;
$GLOBALS['digitalogic_test_current_user_can_calls'] = 0;
$GLOBALS['digitalogic_test_options'] = array();
$GLOBALS['digitalogic_test_actions'] = array();
$GLOBALS['digitalogic_test_update_failures'] = array();
$GLOBALS['digitalogic_test_cache_deletes'] = array();

class WP_REST_Request {
    public function __construct($method = '', $route = '') {
    }
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
    return true;
}

function current_user_can($capability) {
    $GLOBALS['digitalogic_test_current_user_can_calls']++;

    return !empty($GLOBALS['digitalogic_test_capabilities'][$capability]);
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    if (!isset($GLOBALS['digitalogic_test_filters'][$hook_name]) || !is_array($GLOBALS['digitalogic_test_filters'][$hook_name])) {
        $GLOBALS['digitalogic_test_filters'][$hook_name] = array();
    }

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
    if (!isset($GLOBALS['digitalogic_test_filters'][$hook_name])) {
        return $value;
    }

    $registered = $GLOBALS['digitalogic_test_filters'][$hook_name];
    if (is_callable($registered)) {
        return call_user_func($registered, $value, ...$args);
    }

    if (!is_array($registered)) {
        return $value;
    }

    foreach ($registered as $filter) {
        if (!is_array($filter) || !isset($filter['callback']) || !is_callable($filter['callback'])) {
            continue;
        }

        $parameters = array_merge(array($value), $args);
        $parameters = array_slice($parameters, 0, isset($filter['accepted_args']) ? $filter['accepted_args'] : 1);
        $value = call_user_func_array($filter['callback'], $parameters);
    }

    return $value;
}

function do_action($hook_name, ...$args) {
    if (!isset($GLOBALS['digitalogic_test_actions'][$hook_name])) {
        $GLOBALS['digitalogic_test_actions'][$hook_name] = array();
    }

    $GLOBALS['digitalogic_test_actions'][$hook_name][] = $args;
}

function register_rest_route($namespace, $route, $args = array(), $override = false) {
    $GLOBALS['digitalogic_test_routes'][] = array(
        'namespace' => $namespace,
        'route' => $route,
        'args' => $args,
    );

    return true;
}

function rest_get_url_prefix() {
    return $GLOBALS['digitalogic_test_rest_prefix'];
}

function rest_url($path = '') {
    $GLOBALS['digitalogic_test_rest_url_calls']++;

    throw new RuntimeException('The WooCommerce route matcher must not call rest_url().');
}

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['digitalogic_test_options'])
        ? $GLOBALS['digitalogic_test_options'][$name]
        : $default;
}

function update_option($name, $value, $autoload = null) {
    if (in_array($name, $GLOBALS['digitalogic_test_update_failures'], true)) {
        return false;
    }

    $changed = !array_key_exists($name, $GLOBALS['digitalogic_test_options'])
        || $GLOBALS['digitalogic_test_options'][$name] !== $value;
    $GLOBALS['digitalogic_test_options'][$name] = $value;

    return $changed;
}

function wp_cache_delete($key, $group = '') {
    $GLOBALS['digitalogic_test_cache_deletes'][] = array($key, $group);

    return true;
}

function absint($value) {
    return abs((int) $value);
}

function sanitize_key($value) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function current_time($type) {
    return '2026-07-16 12:00:00';
}

function wp_json_encode($value) {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function __($message, $domain = null) {
    return $message;
}

class Digitalogic_Test_WPDB {
    public $acquire_result = 1;
    public $acquire_count = 0;
    public $release_count = 0;

    public function prepare($query, ...$args) {
        return array(
            'query' => $query,
            'args' => $args,
        );
    }

    public function get_var($prepared) {
        $query = is_array($prepared) && isset($prepared['query']) ? $prepared['query'] : (string) $prepared;
        if (strpos($query, 'GET_LOCK') !== false) {
            $this->acquire_count++;
            return $this->acquire_result;
        }

        if (strpos($query, 'RELEASE_LOCK') !== false) {
            $this->release_count++;
            return 1;
        }

        return null;
    }
}

class Digitalogic_Test_Redis_Client {
    public $connect_result = true;
    public $auth_result = true;
    public $select_result = true;
    public $publish_result = 0;
    public $calls = array();
    public $published = array();

    public function connect($host, $port, $timeout) {
        $this->calls[] = array('connect', $host, $port, $timeout);
        return $this->connect_result;
    }

    public function auth($password) {
        $this->calls[] = array('auth', $password);
        return $this->auth_result;
    }

    public function select($database) {
        $this->calls[] = array('select', $database);
        return $this->select_result;
    }

    public function publish($channel, $payload) {
        $this->calls[] = array('publish', $channel);
        $this->published[] = array($channel, $payload);
        return $this->publish_result;
    }

    public function close() {
        $this->calls[] = array('close');
        return true;
    }
}

class WP_CLI {
    public static $commands = array();
    public static $errors = array();
    public static $warnings = array();
    public static $logs = array();

    public static function add_command($name, $callable) {
        self::$commands[$name] = $callable;
    }

    public static function error($message) {
        self::$errors[] = (string) $message;
    }

    public static function warning($message) {
        self::$warnings[] = (string) $message;
    }

    public static function log($message) {
        self::$logs[] = (string) $message;
    }

    public static function success($message) {
        throw new RuntimeException('WP_CLI::success must not be used by the long-running server: ' . $message);
    }

    public static function line($message) {
        self::$logs[] = (string) $message;
    }
}

$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

require_once dirname(__DIR__) . '/includes/api/class-rest-api.php';
require_once dirname(__DIR__) . '/includes/panel/class-panel.php';
require_once dirname(__DIR__) . '/includes/websocket/class-websocket-server.php';
require_once dirname(__DIR__) . '/includes/cli/class-cli-commands.php';
