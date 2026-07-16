<?php

/**
 * Lightweight WordPress, Redis, and WP-CLI stubs shared by plugin unit tests.
 */

define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['digitalogic_test_capabilities'] = array();
$GLOBALS['digitalogic_test_filters'] = array();
$GLOBALS['digitalogic_test_routes'] = array();
$GLOBALS['digitalogic_test_rest_prefix'] = 'wp-json';
$GLOBALS['digitalogic_test_rest_url_calls'] = 0;
$GLOBALS['digitalogic_test_current_user_can_calls'] = 0;
$GLOBALS['digitalogic_test_options'] = array();
$GLOBALS['digitalogic_test_option_cache'] = array();
$GLOBALS['digitalogic_test_actions'] = array();
$GLOBALS['digitalogic_test_action_callbacks'] = array();
$GLOBALS['digitalogic_test_posts'] = array();
$GLOBALS['digitalogic_test_post_meta_cache'] = array();
$GLOBALS['digitalogic_test_update_failures'] = array();
$GLOBALS['digitalogic_test_meta_update_failures'] = array();
$GLOBALS['digitalogic_test_meta_delete_failures'] = array();
$GLOBALS['digitalogic_test_transaction_failures'] = array();
$GLOBALS['digitalogic_test_cache_deletes'] = array();
$GLOBALS['digitalogic_test_remote_posts'] = array();
$GLOBALS['digitalogic_test_remote_post_results'] = array();
$GLOBALS['digitalogic_test_wc_products'] = array();
$GLOBALS['digitalogic_test_wc_product_saves'] = array();
$GLOBALS['digitalogic_test_wc_currency'] = 'IRT';

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

class WP_REST_Request implements ArrayAccess {
    private $params;
    private $json;
    private $headers;
    private $body;

    public function __construct($params = array(), $json = array(), $headers = array(), $body = null) {
        $this->params = is_array($params) ? $params : array();
        $this->json = is_array($json) ? $json : array();
        $this->headers = array_change_key_case(is_array($headers) ? $headers : array(), CASE_LOWER);
        $this->body = is_string($body) ? $body : json_encode($this->json);
    }

    public function get_params() {
        return array_merge($this->json, $this->params);
    }

    public function get_json_params() {
        return $this->json;
    }

    public function get_param($key) {
        $params = $this->get_params();
        return array_key_exists($key, $params) ? $params[$key] : null;
    }

    public function get_header($key) {
        $key = strtolower((string) $key);
        return isset($this->headers[$key]) ? $this->headers[$key] : '';
    }

    public function get_body() {
        return $this->body;
    }

    public function offsetExists($offset): bool {
        return array_key_exists($offset, $this->params);
    }

    public function offsetGet($offset): mixed {
        return array_key_exists($offset, $this->params) ? $this->params[$offset] : null;
    }

    public function offsetSet($offset, $value): void {
        $this->params[$offset] = $value;
    }

    public function offsetUnset($offset): void {
        unset($this->params[$offset]);
    }
}

class WP_REST_Response {
    private $data;
    private $status;

    public function __construct($data = null, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_data() {
        return $this->data;
    }

    public function get_status() {
        return $this->status;
    }
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['digitalogic_test_action_callbacks'][$hook_name][] = array(
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $accepted_args,
    );

    return true;
}

function has_action($hook_name) {
    return !empty($GLOBALS['digitalogic_test_action_callbacks'][$hook_name]);
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
        'priority' => $priority,
        'accepted_args' => $accepted_args,
    );

    return true;
}

function remove_all_filters($hook_name) {
    unset($GLOBALS['digitalogic_test_filters'][$hook_name]);

    return true;
}

function remove_filter($hook_name, $callback = null) {
    if (is_null($callback)) {
        unset($GLOBALS['digitalogic_test_filters'][$hook_name]);
        return true;
    }

    if (empty($GLOBALS['digitalogic_test_filters'][$hook_name])) {
        return false;
    }

    $removed = false;
    $GLOBALS['digitalogic_test_filters'][$hook_name] = array_values(array_filter(
        $GLOBALS['digitalogic_test_filters'][$hook_name],
        function($filter) use ($callback, &$removed) {
            if (isset($filter['callback']) && $filter['callback'] === $callback) {
                $removed = true;
                return false;
            }

            return true;
        }
    ));

    return $removed;
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

    usort($registered, function($left, $right) {
        return (isset($left['priority']) ? $left['priority'] : 10)
            <=> (isset($right['priority']) ? $right['priority'] : 10);
    });

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

    $callbacks = isset($GLOBALS['digitalogic_test_action_callbacks'][$hook_name])
        ? $GLOBALS['digitalogic_test_action_callbacks'][$hook_name]
        : array();
    usort($callbacks, function($left, $right) {
        return $left['priority'] <=> $right['priority'];
    });
    foreach ($callbacks as $item) {
        call_user_func_array($item['callback'], array_slice($args, 0, $item['accepted_args']));
    }
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
    if (array_key_exists($name, $GLOBALS['digitalogic_test_option_cache'])) {
        return $GLOBALS['digitalogic_test_option_cache'][$name];
    }

    return array_key_exists($name, $GLOBALS['digitalogic_test_options'])
        ? $GLOBALS['digitalogic_test_options'][$name]
        : $default;
}

function update_option($name, $value, $autoload = null) {
    if (in_array($name, $GLOBALS['digitalogic_test_update_failures'], true)) {
        return false;
    }

    $exists = array_key_exists($name, $GLOBALS['digitalogic_test_options']);
    $old_value = $exists ? $GLOBALS['digitalogic_test_options'][$name] : null;
    $changed = !$exists || $old_value !== $value;
    $GLOBALS['digitalogic_test_options'][$name] = $value;
    $GLOBALS['digitalogic_test_option_cache'][$name] = $value;

    if ($changed) {
        do_action('updated_option', $name, $old_value, $value);
        do_action('update_option_' . $name, $old_value, $value, $name);
    }

    return $changed;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($name, $GLOBALS['digitalogic_test_options'])) {
        return false;
    }

    $GLOBALS['digitalogic_test_options'][$name] = $value;
    $GLOBALS['digitalogic_test_option_cache'][$name] = $value;
    do_action('added_option', $name, $value);

    return true;
}

function delete_option($name) {
    if (!array_key_exists($name, $GLOBALS['digitalogic_test_options'])) {
        return false;
    }

    unset($GLOBALS['digitalogic_test_options'][$name]);
    unset($GLOBALS['digitalogic_test_option_cache'][$name]);
    return true;
}

function wp_cache_delete($key, $group = '') {
    $GLOBALS['digitalogic_test_cache_deletes'][] = array($key, $group);

    if ('options' === $group) {
        if ('alloptions' === $key || 'notoptions' === $key) {
            $GLOBALS['digitalogic_test_option_cache'] = array();
        } else {
            unset($GLOBALS['digitalogic_test_option_cache'][$key]);
        }
    } elseif ('post_meta' === $group) {
        unset($GLOBALS['digitalogic_test_post_meta_cache'][(int) $key]);
    }

    return true;
}

function clean_post_cache($post_id) {
    return wp_cache_delete((int) $post_id, 'post_meta');
}

function is_wp_error($value) {
    return $value instanceof WP_Error;
}

function wp_parse_args($args, $defaults = array()) {
    return array_merge($defaults, is_array($args) ? $args : array());
}

function wp_parse_url($url, $component = -1) {
    return parse_url($url, $component);
}

function wp_unslash($value) {
    return $value;
}

function maybe_serialize($value) {
    return is_array($value) || is_object($value) ? serialize($value) : $value;
}

function maybe_unserialize($value) {
    if (!is_string($value) || !preg_match('/^(?:a|O|s|i|b|d|N):/', $value)) {
        return $value;
    }

    $unserialized = @unserialize($value);
    return false === $unserialized && 'b:0;' !== $value ? $value : $unserialized;
}

function get_post_type($post_id) {
    return isset($GLOBALS['digitalogic_test_posts'][$post_id])
        ? $GLOBALS['digitalogic_test_posts'][$post_id]['post_type']
        : null;
}

function get_post_meta($post_id, $key = '', $single = false) {
    if (!isset($GLOBALS['digitalogic_test_posts'][$post_id])) {
        return $single ? '' : array();
    }

    $meta = isset($GLOBALS['digitalogic_test_post_meta_cache'][$post_id])
        ? $GLOBALS['digitalogic_test_post_meta_cache'][$post_id]
        : $GLOBALS['digitalogic_test_posts'][$post_id]['meta'];
    if ($key === '') {
        return $meta;
    }
    if (!array_key_exists($key, $meta)) {
        return $single ? '' : array();
    }

    return $single ? $meta[$key] : array($meta[$key]);
}

function metadata_exists($meta_type, $object_id, $meta_key) {
    return 'post' === $meta_type
        && isset($GLOBALS['digitalogic_test_posts'][$object_id]['meta'])
        && array_key_exists($meta_key, $GLOBALS['digitalogic_test_posts'][$object_id]['meta']);
}

function update_post_meta($post_id, $key, $value) {
    $failure_key = (int) $post_id . ':' . $key;
    if (in_array($failure_key, $GLOBALS['digitalogic_test_meta_update_failures'], true)) {
        return false;
    }

    if (!isset($GLOBALS['digitalogic_test_posts'][$post_id])) {
        $GLOBALS['digitalogic_test_posts'][$post_id] = array('post_type' => 'product', 'meta' => array());
    }

    $exists = array_key_exists($key, $GLOBALS['digitalogic_test_posts'][$post_id]['meta']);
    if ($exists && $GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key] === $value) {
        return false;
    }

    $GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key] = $value;
    if (!isset($GLOBALS['digitalogic_test_post_meta_cache'][$post_id])) {
        $GLOBALS['digitalogic_test_post_meta_cache'][$post_id] = $GLOBALS['digitalogic_test_posts'][$post_id]['meta'];
    }
    $GLOBALS['digitalogic_test_post_meta_cache'][$post_id][$key] = $value;
    do_action($exists ? 'updated_post_meta' : 'added_post_meta', 1, $post_id, $key, $value);

    return 1;
}

function delete_post_meta($post_id, $key, $value = '') {
    $failure_key = (int) $post_id . ':' . $key;
    if (in_array($failure_key, $GLOBALS['digitalogic_test_meta_delete_failures'], true)) {
        return false;
    }
    if (!isset($GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key])) {
        return false;
    }

    $old = $GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key];
    if (!isset($GLOBALS['digitalogic_test_post_meta_cache'][$post_id])) {
        $GLOBALS['digitalogic_test_post_meta_cache'][$post_id] = $GLOBALS['digitalogic_test_posts'][$post_id]['meta'];
    }
    unset($GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key]);
    unset($GLOBALS['digitalogic_test_post_meta_cache'][$post_id][$key]);
    do_action('deleted_post_meta', array(1), $post_id, $key, $old);

    return true;
}

function get_posts($args = array()) {
    $result = array();
    foreach ($GLOBALS['digitalogic_test_posts'] as $id => $post) {
        $types = isset($args['post_type']) ? (array) $args['post_type'] : array();
        if (!empty($types) && !in_array($post['post_type'], $types, true)) {
            continue;
        }

        $matches = true;
        if (isset($args['meta_key'])) {
            $matches = array_key_exists($args['meta_key'], $post['meta']);
            if ($matches && array_key_exists('meta_value', $args)) {
                $matches = (string) $post['meta'][$args['meta_key']] === (string) $args['meta_value'];
            }
        } elseif (!empty($args['meta_query'])) {
            $query = $args['meta_query'];
            $relation = isset($query['relation']) ? strtoupper($query['relation']) : 'AND';
            unset($query['relation']);
            $clause_results = array();
            foreach ($query as $clause) {
                if (!is_array($clause) || !isset($clause['key'])) {
                    continue;
                }
                $exists = array_key_exists($clause['key'], $post['meta']);
                if (isset($clause['compare']) && strtoupper($clause['compare']) === 'EXISTS') {
                    $clause_results[] = $exists;
                } else {
                    $clause_results[] = $exists && (string) $post['meta'][$clause['key']] === (string) $clause['value'];
                }
            }
            $matches = $relation === 'OR' ? in_array(true, $clause_results, true) : !in_array(false, $clause_results, true);
        }

        if ($matches) {
            $result[] = (int) $id;
        }
    }

    return $result;
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

function sanitize_email($value) {
    $email = filter_var((string) $value, FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function wp_kses_post($value) {
    return (string) $value;
}

function esc_url_raw($value) {
    return trim((string) $value);
}

function wp_http_validate_url($value) {
    return filter_var($value, FILTER_VALIDATE_URL) !== false;
}

function wp_generate_uuid4() {
    return '00000000-0000-4000-8000-000000000001';
}

function get_bloginfo($field = '') {
    return 'Digitalogic Test';
}

function home_url($path = '') {
    return 'https://digitalogic.test' . $path;
}

function wp_remote_post($url, $args = array()) {
    $GLOBALS['digitalogic_test_remote_posts'][] = array('url' => $url, 'args' => $args);

    if (!empty($GLOBALS['digitalogic_test_remote_post_results'])) {
        $result = array_shift($GLOBALS['digitalogic_test_remote_post_results']);
        if ($result instanceof Throwable) {
            throw $result;
        }
        return $result;
    }

    return array('response' => array('code' => 202));
}

function wp_remote_retrieve_response_code($response) {
    return is_array($response) && isset($response['response']['code'])
        ? (int) $response['response']['code']
        : 0;
}

function current_time($type, $gmt = 0) {
    return $type === 'mysql' ? '2026-07-16 12:00:00' : time();
}

function wp_json_encode($value, $flags = 0, $depth = 512) {
    return json_encode($value, $flags, $depth);
}

function __($message, $domain = null) {
    return $message;
}

class Digitalogic_Test_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $options = 'wp_options';
    public $postmeta = 'wp_postmeta';
    public $insert_id = 0;
    public $acquire_result = 1;
    public $acquire_count = 0;
    public $release_count = 0;
    public $queries = array();
    public $mysql_string_roundtrip = false;
    public $after_rollback = null;
    public $identifier_query_count = 0;
    private $transaction_snapshot = null;
    private $meta_ids = array();
    private $next_meta_id = 1;

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

    public function get_row($prepared, $output = ARRAY_A) {
        $query = is_array($prepared) && isset($prepared['query']) ? $prepared['query'] : (string) $prepared;
        $args = is_array($prepared) && isset($prepared['args']) ? $prepared['args'] : array();

        if (strpos($query, $this->options) !== false) {
            $name = isset($args[0]) ? (string) $args[0] : '';
            return array_key_exists($name, $GLOBALS['digitalogic_test_options'])
                ? array('option_value' => $this->database_raw_value($GLOBALS['digitalogic_test_options'][$name]))
                : null;
        }

        if (strpos($query, $this->postmeta) !== false) {
            $post_id = isset($args[0]) ? (int) $args[0] : 0;
            $key = isset($args[1]) ? (string) $args[1] : '';
            if (!isset($GLOBALS['digitalogic_test_posts'][$post_id]['meta']) || !array_key_exists($key, $GLOBALS['digitalogic_test_posts'][$post_id]['meta'])) {
                return null;
            }
            $meta_id = $this->ensure_meta_id($post_id, $key);
            return array(
                'meta_id' => $meta_id,
                'meta_value' => $this->database_raw_value($GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key]),
            );
        }

        return null;
    }

    public function get_results($prepared, $output = ARRAY_A) {
        $query = is_array($prepared) && isset($prepared['query']) ? $prepared['query'] : (string) $prepared;
        $args = is_array($prepared) && isset($prepared['args']) ? $prepared['args'] : array();
        if (strpos($query, 'digitalogic_identifier:') === false) {
            return array();
        }

        $this->identifier_query_count++;
        $rows = array();
        foreach ($GLOBALS['digitalogic_test_posts'] as $post_id => $post) {
            if (!in_array($post['post_type'], array('product', 'product_variation'), true)) {
                continue;
            }
            $post_status = isset($post['post_status']) ? (string) $post['post_status'] : 'publish';
            if (in_array($post_status, array('trash', 'auto-draft'), true)) {
                continue;
            }
            $sku = $this->current_test_meta_value($post, '_sku');
            $patris_code = $this->current_test_meta_value($post, '_digitalogic_patris_product_code');

            $matches = false;
            if (strpos($query, 'digitalogic_identifier:woocommerce_id') !== false) {
                $matches = (int) $post_id === (int) $args[0];
            } elseif (strpos($query, 'digitalogic_identifier:sku') !== false) {
                $matches = isset($args[1]) && $sku === (string) $args[1];
            } elseif (strpos($query, 'digitalogic_identifier:patris_code') !== false) {
                $matches = isset($args[1]) && $patris_code === (string) $args[1];
            } elseif (strpos($query, 'digitalogic_identifier:code') !== false) {
                $matches = isset($args[0]) && ($sku === (string) $args[0] || $patris_code === (string) $args[0]);
            }

            if ($matches) {
                $rows[] = array(
                    'ID' => (int) $post_id,
                    'post_type' => $post['post_type'],
                    'sku' => $sku,
                    'patris_code' => $patris_code,
                );
            }
        }

        usort($rows, static function($left, $right) {
            return (int) $left['ID'] <=> (int) $right['ID'];
        });
        return $rows;
    }

    private function current_test_meta_value($post, $key) {
        if (isset($post['meta_rows'][$key]) && is_array($post['meta_rows'][$key]) && !empty($post['meta_rows'][$key])) {
            $values = array_values($post['meta_rows'][$key]);
            return (string) end($values);
        }

        return isset($post['meta'][$key]) ? (string) $post['meta'][$key] : '';
    }

    public function insert($table, $data, $formats = null) {
        if ($table === $this->options) {
            $name = (string) $data['option_name'];
            if (in_array($name, $GLOBALS['digitalogic_test_update_failures'], true)) {
                return false;
            }
            $GLOBALS['digitalogic_test_options'][$name] = $this->stored_database_value($data['option_value']);
            $this->insert_id++;
            return 1;
        }

        if ($table === $this->postmeta) {
            $post_id = (int) $data['post_id'];
            $key = (string) $data['meta_key'];
            if (in_array($post_id . ':' . $key, $GLOBALS['digitalogic_test_meta_update_failures'], true)) {
                return false;
            }
            if (!isset($GLOBALS['digitalogic_test_posts'][$post_id])) {
                $GLOBALS['digitalogic_test_posts'][$post_id] = array('post_type' => 'product', 'meta' => array());
            }
            $GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key] = $this->stored_database_value($data['meta_value']);
            $this->insert_id = $this->next_meta_id++;
            $this->meta_ids[$post_id][$key] = $this->insert_id;
            return 1;
        }

        return false;
    }

    public function update($table, $data, $where, $formats = null, $where_formats = null) {
        if ($table === $this->options) {
            $name = (string) $where['option_name'];
            if (in_array($name, $GLOBALS['digitalogic_test_update_failures'], true)) {
                return false;
            }
            if (!array_key_exists($name, $GLOBALS['digitalogic_test_options'])) {
                return 0;
            }
            $GLOBALS['digitalogic_test_options'][$name] = $this->stored_database_value($data['option_value']);
            return 1;
        }

        if ($table === $this->postmeta && isset($where['meta_id'])) {
            foreach ($GLOBALS['digitalogic_test_posts'] as $post_id => &$post) {
                foreach ($post['meta'] as $key => &$value) {
                    if ($this->ensure_meta_id($post_id, $key) !== (int) $where['meta_id']) {
                        continue;
                    }
                    if (in_array($post_id . ':' . $key, $GLOBALS['digitalogic_test_meta_update_failures'], true)) {
                        return false;
                    }
                    $value = $this->stored_database_value($data['meta_value']);
                    return 1;
                }
                unset($value);
            }
            unset($post);
            return 0;
        }

        return false;
    }

    public function delete($table, $where, $formats = null) {
        if ($table === $this->options) {
            $name = (string) $where['option_name'];
            if (in_array($name, $GLOBALS['digitalogic_test_update_failures'], true)) {
                return false;
            }
            if (!array_key_exists($name, $GLOBALS['digitalogic_test_options'])) {
                return 0;
            }
            unset($GLOBALS['digitalogic_test_options'][$name]);
            return 1;
        }

        if ($table === $this->postmeta) {
            $post_id = (int) $where['post_id'];
            $key = (string) $where['meta_key'];
            if (in_array($post_id . ':' . $key, $GLOBALS['digitalogic_test_meta_delete_failures'], true)) {
                return false;
            }
            if (!isset($GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key])) {
                return 0;
            }
            unset($GLOBALS['digitalogic_test_posts'][$post_id]['meta'][$key], $this->meta_ids[$post_id][$key]);
            return 1;
        }

        return false;
    }

    private function ensure_meta_id($post_id, $key) {
        if (!isset($this->meta_ids[$post_id][$key])) {
            $this->meta_ids[$post_id][$key] = $this->next_meta_id++;
        }
        return $this->meta_ids[$post_id][$key];
    }

    private function database_raw_value($value) {
        $serialized = maybe_serialize($value);
        return $this->mysql_string_roundtrip ? (string) $serialized : $serialized;
    }

    private function stored_database_value($value) {
        $stored = maybe_unserialize($value);
        if ($this->mysql_string_roundtrip && !is_array($stored) && !is_object($stored)) {
            return (string) $stored;
        }

        return $stored;
    }

    public function query($query) {
        $normalized = strtoupper(trim((string) $query));
        $this->queries[] = $normalized;
        if (in_array($normalized, $GLOBALS['digitalogic_test_transaction_failures'], true)) {
            return false;
        }

        if ('START TRANSACTION' === $normalized) {
            $this->transaction_snapshot = array(
                'options' => $GLOBALS['digitalogic_test_options'],
                'posts' => $GLOBALS['digitalogic_test_posts'],
                'meta_ids' => $this->meta_ids,
                'next_meta_id' => $this->next_meta_id,
            );
            return 1;
        }
        if ('ROLLBACK' === $normalized) {
            if (is_array($this->transaction_snapshot)) {
                $GLOBALS['digitalogic_test_options'] = $this->transaction_snapshot['options'];
                $GLOBALS['digitalogic_test_posts'] = $this->transaction_snapshot['posts'];
                $this->meta_ids = $this->transaction_snapshot['meta_ids'];
                $this->next_meta_id = $this->transaction_snapshot['next_meta_id'];
            }
            $this->transaction_snapshot = null;
            $after_rollback = $this->after_rollback;
            $this->after_rollback = null;
            if (is_callable($after_rollback)) {
                call_user_func($after_rollback, $this);
            }
            return 1;
        }
        if ('COMMIT' === $normalized) {
            $this->transaction_snapshot = null;
            return 1;
        }

        return 1;
    }
}

class Digitalogic_Options {
    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_yuan_price() {
        return (float) get_option('options_yuan_price', get_option('yuan_price', 0));
    }

    public function get_update_date() {
        return (string) get_option('options_update_date', get_option('update_date', ''));
    }
}

class Digitalogic_Product_Manager {
    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_products($args = array()) {
        return array();
    }
}

class Digitalogic_Logger {
    private static $instance;
    public $entries = array();

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function log(...$args) {
        $this->entries[] = $args;
        return true;
    }
}

class WC_Product {
    public $id;
    public $meta = array();
    public $weight = null;
    public $manage_stock = false;
    public $stock_quantity = null;
    public $stock_status = null;
    public $regular_price = null;
    public $price = null;
    public $save_count = 0;

    public function __construct($id) {
        $this->id = (int) $id;
        $this->meta = isset($GLOBALS['digitalogic_test_posts'][$this->id]['meta'])
            ? $GLOBALS['digitalogic_test_posts'][$this->id]['meta']
            : array();
    }

    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }

    public function set_weight($value) {
        $this->weight = (string) $value;
    }

    public function set_manage_stock($value) {
        $this->manage_stock = (bool) $value;
    }

    public function set_stock_quantity($value) {
        $this->stock_quantity = (int) $value;
    }

    public function set_stock_status($value) {
        $this->stock_status = (string) $value;
    }

    public function set_regular_price($value) {
        $this->regular_price = (string) $value;
    }

    public function set_price($value) {
        $this->price = (string) $value;
    }

    public function save() {
        $this->save_count++;
        $GLOBALS['digitalogic_test_posts'][$this->id]['meta'] = $this->meta;
        $GLOBALS['digitalogic_test_wc_product_saves'][] = $this->id;
        return $this->id;
    }
}

function wc_get_product($product_id) {
    $product_id = (int) $product_id;
    if (
        !isset($GLOBALS['digitalogic_test_posts'][$product_id])
        || !in_array($GLOBALS['digitalogic_test_posts'][$product_id]['post_type'], array('product', 'product_variation'), true)
    ) {
        return false;
    }

    if (!isset($GLOBALS['digitalogic_test_wc_products'][$product_id])) {
        $GLOBALS['digitalogic_test_wc_products'][$product_id] = new WC_Product($product_id);
    }

    return $GLOBALS['digitalogic_test_wc_products'][$product_id];
}

function get_woocommerce_currency() {
    return isset($GLOBALS['digitalogic_test_wc_currency'])
        ? (string) $GLOBALS['digitalogic_test_wc_currency']
        : 'IRT';
}

function wc_get_weight($weight, $to_unit, $from_unit = '') {
    $weight = (float) $weight;
    $from_unit = $from_unit !== '' ? $from_unit : 'kg';
    $grams = array(
        'g' => $weight,
        'kg' => $weight * 1000,
        'lbs' => $weight * 453.59237,
        'oz' => $weight * 28.349523125,
    );

    if (!isset($grams[$from_unit])) {
        return 0;
    }

    $divisors = array(
        'g' => 1,
        'kg' => 1000,
        'lbs' => 453.59237,
        'oz' => 28.349523125,
    );

    return isset($divisors[$to_unit]) ? $grams[$from_unit] / $divisors[$to_unit] : 0;
}

function wc_get_price_decimals() {
    return 0;
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

require_once dirname(__DIR__) . '/includes/class-unit-converter.php';
require_once dirname(__DIR__) . '/includes/class-product-identifier-resolver.php';
require_once dirname(__DIR__) . '/includes/class-patris-feed.php';
require_once dirname(__DIR__) . '/includes/class-product-sync-receiver.php';
require_once dirname(__DIR__) . '/includes/class-import-freight-service.php';
require_once dirname(__DIR__) . '/includes/class-command-dispatcher.php';
require_once dirname(__DIR__) . '/includes/api/class-rest-api.php';
require_once dirname(__DIR__) . '/includes/api/class-webhooks.php';
require_once dirname(__DIR__) . '/includes/class-report-engine.php';
require_once dirname(__DIR__) . '/includes/panel/class-panel.php';
require_once dirname(__DIR__) . '/includes/websocket/class-websocket-server.php';
require_once dirname(__DIR__) . '/includes/cli/class-cli-commands.php';
