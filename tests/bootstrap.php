<?php

/**
 * Lightweight WordPress, Redis, and WP-CLI stubs shared by plugin unit tests.
 */

define('ABSPATH', __DIR__ . '/');
define('WP_CLI', true);
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

// phpcs:disable Generic.Formatting.MultipleStatementAlignment -- Preserve the intentionally simple test-global registry.
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
$GLOBALS['digitalogic_test_next_post_id'] = 1;
$GLOBALS['digitalogic_test_post_meta_cache'] = array();
$GLOBALS['digitalogic_test_terms'] = array();
$GLOBALS['digitalogic_test_term_meta'] = array();
$GLOBALS['digitalogic_test_next_term_id'] = 1;
$GLOBALS['digitalogic_test_update_failures'] = array();
$GLOBALS['digitalogic_test_meta_update_failures'] = array();
$GLOBALS['digitalogic_test_meta_delete_failures'] = array();
$GLOBALS['digitalogic_test_transaction_failures'] = array();
$GLOBALS['digitalogic_test_cache_deletes'] = array();
$GLOBALS['digitalogic_test_remote_posts'] = array();
$GLOBALS['digitalogic_test_remote_post_results'] = array();
$GLOBALS['digitalogic_test_wc_products'] = array();
$GLOBALS['digitalogic_test_wc_product_saves'] = array();
$GLOBALS['digitalogic_test_wc_save_failures'] = array();
$GLOBALS['digitalogic_test_wc_set_price_calls'] = array();
$GLOBALS['digitalogic_test_wc_after_save'] = null;
$GLOBALS['digitalogic_test_wc_save_fail_once'] = array();
$GLOBALS['digitalogic_test_wc_lookup_rows'] = array();
$GLOBALS['digitalogic_test_wc_data_store'] = null;
$GLOBALS['digitalogic_test_wc_lookup_full_rebuilds'] = 0;
$GLOBALS['digitalogic_test_product_updates'] = array();
$GLOBALS['digitalogic_test_wp_query_args'] = array();
$GLOBALS['digitalogic_test_wp_query_results'] = array();
$GLOBALS['digitalogic_test_primed_post_ids'] = array();
$GLOBALS['digitalogic_test_wc_currency'] = 'IRT';
$GLOBALS['digitalogic_test_terms'] = array();
$GLOBALS['digitalogic_test_transients'] = array(); // phpcs:ignore
$GLOBALS['digitalogic_test_transient_deletes'] = array(); // phpcs:ignore
$GLOBALS['digitalogic_test_rewrite_rules'] = array(); // phpcs:ignore
$GLOBALS['digitalogic_test_rewrite_flushes'] = array(); // phpcs:ignore
$GLOBALS['digitalogic_test_locale'] = 'en_US';
$GLOBALS['digitalogic_test_shortcodes'] = array();
$GLOBALS['digitalogic_test_scheduled_events'] = array();
$GLOBALS['digitalogic_test_schedule_failure'] = false;
$GLOBALS['digitalogic_test_enqueued_styles'] = array();
$GLOBALS['digitalogic_test_enqueued_scripts'] = array();
// phpcs:enable Generic.Formatting.MultipleStatementAlignment

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
    private $route = ''; // phpcs:ignore
    private $method = ''; // phpcs:ignore

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
        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    public function get_body() {
        return $this->body;
    }

// phpcs:disable -- Test-only REST request compatibility methods follow the legacy bootstrap style.
    public function set_route($route) {
        $this->route = (string) $route;
        return $this;
    }

    public function get_route() {
        return $this->route;
    }

    public function set_method($method) {
        $this->method = strtoupper((string) $method);
        return $this;
    }

    public function get_method() {
        return $this->method;
    }
// phpcs:enable

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
	private $headers = array();

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

	public function header($key, $value, $replace = true) {
		if (!$replace && isset($this->headers[$key])) {
			$this->headers[$key] .= ', ' . $value;
			return;
		}
		$this->headers[$key] = $value;
	}

	public function get_headers() {
		return $this->headers;
	}
}

// phpcs:disable -- Test-only WordPress query stubs intentionally follow the legacy bootstrap style.
class WP_Query {
    public $posts = array();
    public $found_posts = 0;

    public function __construct($args = array()) {
        $GLOBALS['digitalogic_test_wp_query_args'][] = $args;
        $result = !empty($GLOBALS['digitalogic_test_wp_query_results'])
            ? array_shift($GLOBALS['digitalogic_test_wp_query_results'])
            : array();
        $this->posts = isset($result['posts']) ? array_values($result['posts']) : array();
        $this->found_posts = isset($result['found_posts']) ? (int) $result['found_posts'] : count($this->posts);
    }
}

function _prime_post_caches($post_ids, $update_term_cache = true, $update_meta_cache = true) {
    $GLOBALS['digitalogic_test_primed_post_ids'][] = array_values(array_map('absint', (array) $post_ids));
}
// phpcs:enable

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['digitalogic_test_action_callbacks'][$hook_name][] = array(
        'callback' => $callback,
        'priority' => $priority,
        'accepted_args' => $accepted_args,
    );

    return true;
}

function add_shortcode($tag, $callback) {
    $GLOBALS['digitalogic_test_shortcodes'][$tag] = $callback;

    return true;
}

function has_action($hook_name) {
    return !empty($GLOBALS['digitalogic_test_action_callbacks'][$hook_name]);
}

function wp_next_scheduled($hook, $args = array()) {
    foreach ($GLOBALS['digitalogic_test_scheduled_events'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['timestamp'];
        }
    }
    return false;
}

function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false) {
    if (!empty($GLOBALS['digitalogic_test_schedule_failure'])) {
        return $wp_error ? new WP_Error('schedule_failed', 'schedule failed') : false;
    }
    $GLOBALS['digitalogic_test_scheduled_events'][] = array(
        'timestamp' => (int) $timestamp,
        'hook' => (string) $hook,
        'args' => array_values((array) $args),
        'recurrence' => '',
    );
    return true;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {
    if (!empty($GLOBALS['digitalogic_test_schedule_failure'])) {
        return $wp_error ? new WP_Error('schedule_failed', 'schedule failed') : false;
    }
    $GLOBALS['digitalogic_test_scheduled_events'][] = array(
        'timestamp' => (int) $timestamp,
        'hook' => (string) $hook,
        'args' => array_values((array) $args),
        'recurrence' => (string) $recurrence,
    );
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
        'priority' => $priority,
        'accepted_args' => $accepted_args,
    );

    return true;
}

function add_rewrite_rule($regex, $query, $after = 'bottom') {
    $GLOBALS['digitalogic_test_rewrite_rules'][$regex] = array(
        'query' => $query,
        'after' => $after,
    );
}

function flush_rewrite_rules($hard = true) {
    $GLOBALS['digitalogic_test_rewrite_flushes'][] = (bool) $hard;
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
        do_action('update_option_' . $name, $old_value, $value, $name);
        do_action('updated_option_' . $name, $old_value, $value, $name);
        do_action('updated_option', $name, $old_value, $value);
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

// phpcs:disable -- Test-only transient stubs follow the legacy bootstrap style.
function get_transient($name) {
    if (!isset($GLOBALS['digitalogic_test_transients'][$name])) {
        return false;
    }

    $transient = $GLOBALS['digitalogic_test_transients'][$name];
    if ($transient['expires'] > 0 && $transient['expires'] <= time()) {
        unset($GLOBALS['digitalogic_test_transients'][$name]);
        return false;
    }

    return $transient['value'];
}

function set_transient($name, $value, $expiration = 0) {
    $GLOBALS['digitalogic_test_transients'][$name] = array(
        'value' => $value,
        'expires' => $expiration > 0 ? time() + (int) $expiration : 0,
    );

    return true;
}

function delete_transient($name) {
    $GLOBALS['digitalogic_test_transient_deletes'][] = $name;
    $exists = isset($GLOBALS['digitalogic_test_transients'][$name]);
    unset($GLOBALS['digitalogic_test_transients'][$name]);

    return $exists;
}
// phpcs:enable

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

function trailingslashit($value) {
    return rtrim((string) $value, '/\\') . '/';
}

function untrailingslashit($value) {
    return rtrim((string) $value, '/\\');
}

function add_query_arg($args, $url) {
    if (!is_array($args) || empty($args)) {
        return $url;
    }

    $separator = strpos($url, '?') === false ? '?' : '&';

    return $url . $separator . http_build_query($args);
}

function wp_unslash($value) {
    return $value;
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
    return substr(str_repeat('test-generated-secret-', 8), 0, (int) $length);
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

function get_post($post_id) {
    $post_id = (int) $post_id;
    if (!isset($GLOBALS['digitalogic_test_posts'][$post_id])) {
        return null;
    }

    return (object) array_merge(
        array(
            'ID' => $post_id,
            'post_content' => '',
            'post_excerpt' => '',
        ),
        $GLOBALS['digitalogic_test_posts'][$post_id]
    );
}

function get_post_thumbnail_id($post_id) {
    return 0;
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

    // phpcs:disable -- Test fixture supports duplicate raw metadata rows.
    $meta_rows = isset($GLOBALS['digitalogic_test_posts'][$post_id]['meta_rows'][$key])
        ? array_values($GLOBALS['digitalogic_test_posts'][$post_id]['meta_rows'][$key])
        : array();
    if (!$single && !empty($meta_rows)) {
        return $meta_rows;
    }
    if ($single && !empty($meta_rows) && !array_key_exists($key, $meta)) {
        return end($meta_rows);
    }
    // phpcs:enable

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

function sanitize_title($value) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9\-_]+/', '-', $value);
    return trim((string) $value, '-');
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

function esc_url($value) {
    return trim((string) $value);
}

function esc_attr($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_html($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function content_url($path = '') {
    return 'https://digitalogic.test/wp-content' . $path;
}

function determine_locale() {
    return $GLOBALS['digitalogic_test_locale'];
}

function get_locale() {
    return $GLOBALS['digitalogic_test_locale'];
}

function wp_timezone() {
    return new DateTimeZone('Asia/Tehran');
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

function wp_remote_retrieve_body($response) {
    return is_array($response) && isset($response['body']) ? (string) $response['body'] : '';
}

function wp_remote_retrieve_header($response, $header) {
    $headers = is_array($response) && isset($response['headers']) && is_array($response['headers'])
        ? array_change_key_case($response['headers'], CASE_LOWER)
        : array();
    return isset($headers[strtolower((string) $header)]) ? $headers[strtolower((string) $header)] : '';
}

function current_time($type, $gmt = 0) {
    return $type === 'mysql' ? '2026-07-16 12:00:00' : time();
}

function wp_json_encode($value, $flags = 0, $depth = 512) {
    return json_encode($value, $flags, $depth);
}

function wp_specialchars_decode($string, $quote_style = ENT_NOQUOTES) {
    return htmlspecialchars_decode((string) $string, $quote_style);
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
    public $acquire_results = array();
    public $acquire_count = 0;
    public $release_count = 0;
    public $lock_names = array();
    public $queries = array();
    public $mysql_string_roundtrip = false;
    public $after_rollback = null;
    public $identifier_query_count = 0;
    public $identifier_prepare_failure = false;
    public $identifier_query_failure = false;
    public $identifier_query_last_error = '';
    // phpcs:disable -- Product metadata lookup controls are test-only database hooks.
    public $metadata_lookup_query_count = 0;
    public $metadata_lookup_query_failure = false;
    public $price_range_query_count = 0;
    // phpcs:enable
    public $last_error = '';
    public $option_read_counts = array();
    // phpcs:disable -- Deterministic lifecycle-interleaving hooks for focused tests.
    public $before_get_lock = null;
    public $after_option_write = null;
    public $lock_timeouts = array();
    // phpcs:enable
    private $transaction_snapshot = null;
    private $meta_ids = array();
    private $next_meta_id = 1;

    public function prepare($query, ...$args) {
        if ($this->identifier_prepare_failure && strpos((string) $query, 'digitalogic_identifier:') !== false) {
            $this->last_error = 'Injected identifier prepare failure.';
            return false;
        }

        return array(
            'query' => $query,
            'args' => $args,
        );
    }

    public function get_var($prepared) {
        $query = is_array($prepared) && isset($prepared['query']) ? $prepared['query'] : (string) $prepared;
        if (strpos($query, 'GET_LOCK') !== false) {
            $this->acquire_count++;
            // phpcs:disable -- Test-only interleaving hook follows the legacy bootstrap style.
            $args = is_array($prepared) && isset($prepared['args']) ? $prepared['args'] : array();
            $this->lock_names[] = isset($args[0]) ? (string) $args[0] : '';
            $this->lock_timeouts[] = isset($args[1]) ? (int) $args[1] : null;
            $callback = $this->before_get_lock;
            $this->before_get_lock = null;
            if (is_callable($callback)) {
                call_user_func($callback, $this);
            }
            // phpcs:enable
            return !empty($this->acquire_results) ? array_shift($this->acquire_results) : $this->acquire_result;
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

        // phpcs:disable -- Test-only metadata lookup branch follows the legacy bootstrap style.
        if (strpos($query, 'digitalogic_product_metadata_lookup') !== false) {
            $this->metadata_lookup_query_count++;
            if ($this->metadata_lookup_query_failure) {
                $this->last_error = 'Injected product metadata lookup failure.';
                return null;
            }
            $this->last_error = '';
            $product_id = isset($args[0]) ? (int) $args[0] : 0;
            return $GLOBALS['digitalogic_test_wc_lookup_rows'][$product_id] ?? null;
        }
        // phpcs:enable

        if (strpos($query, $this->options) !== false) {
            $name = isset($args[0]) ? (string) $args[0] : '';
            if (!isset($this->option_read_counts[$name])) {
                $this->option_read_counts[$name] = 0;
            }
            $this->option_read_counts[$name]++;
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
        if (strpos($query, 'digitalogic_product_price_range_lookup') !== false) {
            $this->price_range_query_count++;
            $rows = array();
            foreach ($args as $product_id) {
                $product_id = (int) $product_id;
                if (!isset($GLOBALS['digitalogic_test_wc_lookup_rows'][$product_id])) {
                    continue;
                }
                $lookup_row = $GLOBALS['digitalogic_test_wc_lookup_rows'][$product_id];
                $rows[] = array(
                    'product_id' => $product_id,
                    'min_price' => $lookup_row['min_price'] ?? null,
                    'max_price' => $lookup_row['max_price'] ?? null,
                );
            }
            return $rows;
        }
        if (strpos($query, 'digitalogic_identifier:') === false) {
            return array();
        }

        $this->identifier_query_count++;
        if ($this->identifier_query_failure) {
            $this->last_error = '' !== $this->identifier_query_last_error
                ? $this->identifier_query_last_error
                : 'Injected identifier query failure.';
            return null;
        }
        if ('' !== $this->identifier_query_last_error) {
            $this->last_error = $this->identifier_query_last_error;
            return array();
        }
        $this->last_error = '';
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
            // phpcs:disable -- Test-only interleaving hook follows the legacy bootstrap style.
            $callback = $this->after_option_write;
            $this->after_option_write = null;
            if (is_callable($callback)) {
                call_user_func($callback, $this, $name);
            }
            // phpcs:enable
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
            // phpcs:disable -- Test-only interleaving hook follows the legacy bootstrap style.
            $callback = $this->after_option_write;
            $this->after_option_write = null;
            if (is_callable($callback)) {
                call_user_func($callback, $this, $name);
            }
            // phpcs:enable
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

    public function get_dollar_price() {
        return (float) get_option('options_dollar_price', get_option('dollar_price', 0));
    }

    public function get_update_date() {
        return Digitalogic_Currency_Date_Formatter::instance()->get_raw_update_date();
    }

    public function get_update_date_formatted($format = 'Y/m/d') {
        return Digitalogic_Currency_Date_Formatter::instance()->format($this->get_update_date(), $format);
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

    // phpcs:disable -- WooCommerce product test doubles intentionally follow the legacy bootstrap style.
    public function get_id() {
        return $this->id;
    }

    public function get_parent_id() {
        $post = $GLOBALS['digitalogic_test_posts'][$this->id] ?? array();
        return (int) ($post['post_parent'] ?? $post['parent_id'] ?? 0);
    }

    public function get_name() {
        return (string) ($GLOBALS['digitalogic_test_posts'][$this->id]['post_title'] ?? '');
    }

    public function get_sku() {
        $sku = (string) ($this->meta['_sku'] ?? '');
        $parent = $this->get_parent_id();
        return '' === $sku && $parent ? (string) wc_get_product($parent)->get_sku() : $sku;
    }

    public function get_type() {
        $post = $GLOBALS['digitalogic_test_posts'][$this->id] ?? array();
        if (isset($post['product_type'])) {
            return (string) $post['product_type'];
        }
        return ($post['post_type'] ?? '') === 'product_variation' ? 'variation' : 'simple';
    }

    public function get_status() {
        return (string) ($GLOBALS['digitalogic_test_posts'][$this->id]['post_status'] ?? 'publish');
    }

    public function get_regular_price() {
        return (string) ($this->meta['_regular_price'] ?? '');
    }

    public function get_sale_price() {
        return (string) ($this->meta['_sale_price'] ?? '');
    }

    public function get_price() {
        if (null !== $this->price) {
            return $this->price;
        }
        return (string) ($this->meta['_price'] ?? $this->meta['_regular_price'] ?? '');
    }

    public function get_stock_quantity() {
        if (null !== $this->stock_quantity) {
            return $this->stock_quantity;
        }
        if ('parent' === $this->get_manage_stock() && $this->get_parent_id()) {
            return wc_get_product($this->get_parent_id())->get_stock_quantity();
        }
        return array_key_exists('_stock', $this->meta) ? $this->meta['_stock'] : null;
    }

    public function get_stock_status() {
        return null !== $this->stock_status
            ? $this->stock_status
            : (string) ($this->meta['_stock_status'] ?? 'instock');
    }

    public function get_manage_stock() {
        if ($this->manage_stock || 'yes' === ($this->meta['_manage_stock'] ?? 'no')) {
            return true;
        }
        if ('parent' === ($this->meta['_manage_stock'] ?? '')) {
            return 'parent';
        }
        $parent = $this->get_parent_id();
        return $parent && true === wc_get_product($parent)->get_manage_stock() ? 'parent' : false;
    }

    public function get_weight() {
        return null !== $this->weight ? $this->weight : (string) ($this->meta['_weight'] ?? '');
    }

    public function get_length() {
        return (string) ($this->meta['_length'] ?? '');
    }

    public function get_width() {
        return (string) ($this->meta['_width'] ?? '');
    }

    public function get_height() {
        return (string) ($this->meta['_height'] ?? '');
    }

    public function get_image_id() {
        return (int) ($this->meta['_thumbnail_id'] ?? 0);
    }

    public function get_permalink() {
        return 'https://digitalogic.test/product/' . $this->id;
    }

    public function get_gallery_image_ids() {
        return array();
    }

    public function get_category_ids() {
        return (array) ($GLOBALS['digitalogic_test_posts'][$this->id]['category_ids'] ?? array());
    }

    public function get_short_description() {
        return (string) ($GLOBALS['digitalogic_test_posts'][$this->id]['post_excerpt'] ?? '');
    }

    public function get_catalog_visibility() {
        return (string) ($GLOBALS['digitalogic_test_posts'][$this->id]['catalog_visibility'] ?? 'visible');
    }

    public function get_attributes() {
        return (array) ($GLOBALS['digitalogic_test_posts'][$this->id]['attributes'] ?? array());
    }

    public function get_variation_attributes() {
        $attributes = array();
        foreach ($this->meta as $key => $value) {
            if (0 === strpos((string) $key, 'attribute_')) {
                $attributes[(string) $key] = (string) $value;
            }
        }
        return $attributes;
    }

    public function get_default_attributes() {
        return (array) ($GLOBALS['digitalogic_test_posts'][$this->id]['default_attributes'] ?? array());
    }

    public function get_total_sales() {
        return (int) ($this->meta['total_sales'] ?? 0);
    }

    public function get_date_modified() {
        return null;
    }

    public function get_meta($key, $single = true) {
        if (0 === strpos((string) $key, 'attribute_')) {
            return '';
        }
        return $this->meta[$key] ?? '';
    }

    public function get_attribute($name) {
        return (string) ($this->meta['attribute_' . $name] ?? $this->meta[$name] ?? '');
    }

    public function get_children() {
        $children = array();
        foreach ($GLOBALS['digitalogic_test_posts'] as $post_id => $post) {
            $parent_id = (int) ($post['post_parent'] ?? $post['parent_id'] ?? 0);
            if ($parent_id === $this->id) {
                $children[] = (int) $post_id;
            }
        }
        return $children;
    }

    public function get_tax_status() {
        if (array_key_exists('_tax_status', $this->meta)) {
            return (string) $this->meta['_tax_status'];
        }
        $parent = $this->get_parent_id();
        return $parent ? (string) wc_get_product($parent)->get_tax_status() : 'taxable';
    }

    public function get_tax_class() {
        if (array_key_exists('_tax_class', $this->meta)) {
            $tax_class = (string) $this->meta['_tax_class'];
            if ('parent' !== $tax_class) {
                return $tax_class;
            }
        }
        $parent = $this->get_parent_id();
        return $parent ? (string) wc_get_product($parent)->get_tax_class() : '';
    }

    public function is_type($type) {
        return $this->get_type() === $type;
    }

    public function set_name($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['post_title'] = (string) $value;
    }

    public function set_sku($value) {
        $this->meta['_sku'] = (string) $value;
    }

    public function set_status($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['post_status'] = (string) $value;
    }

    public function set_short_description($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['post_excerpt'] = (string) $value;
    }

    public function set_catalog_visibility($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['catalog_visibility'] = (string) $value;
    }

    public function set_parent_id($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['post_parent'] = (int) $value;
    }

    public function set_attributes($value) {
        $value = (array) $value;
        $contains_objects = false;
        foreach ($value as $attribute) {
            if ($attribute instanceof WC_Product_Attribute) {
                $contains_objects = true;
                break;
            }
        }
        if ($contains_objects) {
            $GLOBALS['digitalogic_test_posts'][$this->id]['attributes'] = $value;
            return;
        }
        foreach ($value as $taxonomy => $option) {
            $this->meta['attribute_' . $taxonomy] = (string) $option;
        }
    }

    public function set_default_attributes($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['default_attributes'] = (array) $value;
    }
    // phpcs:enable

    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }

    public function delete_meta_data($key) {
        unset($this->meta[$key]);
    }

    public function set_weight($value) {
        $this->weight = (string) $value;
        $this->meta['_weight'] = (string) $value; // phpcs:ignore -- Keep test product metadata synchronized.
    }

    public function set_manage_stock($value) {
        $this->manage_stock = (bool) $value;
        $this->meta['_manage_stock'] = $value ? 'yes' : 'no'; // phpcs:ignore -- Keep test product metadata synchronized.
    }

    public function set_stock_quantity($value) {
        $this->stock_quantity = null === $value ? null : (int) $value;
        if (null === $value) {
            unset($this->meta['_stock']);
        } else {
            $this->meta['_stock'] = (int) $value; // phpcs:ignore -- Keep test product metadata synchronized.
        }
    }

    public function set_stock_status($value) {
        $this->stock_status = (string) $value;
        $this->meta['_stock_status'] = (string) $value; // phpcs:ignore -- Keep test product metadata synchronized.
    }

    public function set_regular_price($value) {
        $this->regular_price = (string) $value;
        $this->meta['_regular_price'] = (string) $value; // phpcs:ignore -- Keep test product metadata synchronized.
    }

    public function set_price($value) {
        $GLOBALS['digitalogic_test_wc_set_price_calls'][] = array($this->id, $value);
        $this->price = (string) $value;
        $this->meta['_price'] = (string) $value; // phpcs:ignore -- Keep test product metadata synchronized.
    }

    // phpcs:disable -- WooCommerce product test doubles intentionally follow the legacy bootstrap style.
    public function set_sale_price($value) {
        $this->meta['_sale_price'] = (string) $value;
    }

    public function set_length($value) {
        $this->meta['_length'] = (string) $value;
    }

    public function set_width($value) {
        $this->meta['_width'] = (string) $value;
    }

    public function set_height($value) {
        $this->meta['_height'] = (string) $value;
    }

    public function set_category_ids($value) {
        $GLOBALS['digitalogic_test_posts'][$this->id]['category_ids'] = array_values($value);
    }
    // phpcs:enable

    public function save() {
        if (in_array($this->id, $GLOBALS['digitalogic_test_wc_save_failures'] ?? array(), true)) {
            throw new RuntimeException('Injected WooCommerce save failure.');
        }
        $fail_once_at = (int) ($GLOBALS['digitalogic_test_wc_save_fail_once'][$this->id] ?? 0);
        if ($fail_once_at > 0 && $fail_once_at === $this->save_count + 1) {
            unset($GLOBALS['digitalogic_test_wc_save_fail_once'][$this->id]);
            throw new RuntimeException('Injected one-time WooCommerce save failure.');
        }
        $this->save_count++;
        $GLOBALS['digitalogic_test_posts'][$this->id]['meta'] = $this->meta;
        $GLOBALS['digitalogic_test_wc_product_saves'][] = $this->id;
        $after_save = $GLOBALS['digitalogic_test_wc_after_save'] ?? null;
        $GLOBALS['digitalogic_test_wc_after_save'] = null;
        if (is_callable($after_save)) {
            call_user_func($after_save, $this);
        }
        return $this->id;
    }
}

class WC_Product_Simple extends WC_Product {
    public function __construct($id = 0) {
        if ((int) $id <= 0) {
            $id = max(1, (int) ($GLOBALS['digitalogic_test_next_post_id'] ?? 1));
            while (isset($GLOBALS['digitalogic_test_posts'][$id])) {
                $id++;
            }
            $GLOBALS['digitalogic_test_next_post_id'] = $id + 1;
            $GLOBALS['digitalogic_test_posts'][$id] = array(
                'post_type' => 'product',
                'post_status' => 'draft',
                'product_type' => 'simple',
                'post_title' => '',
                'post_excerpt' => '',
                'meta' => array(),
            );
        }
        parent::__construct($id);
        $GLOBALS['digitalogic_test_posts'][$this->id]['product_type'] = 'simple';
    }
}

class WC_Product_Variation extends WC_Product {
    public function __construct($id = 0) {
        if ((int) $id <= 0) {
            $id = max(1, (int) ($GLOBALS['digitalogic_test_next_post_id'] ?? 1));
            while (isset($GLOBALS['digitalogic_test_posts'][$id])) {
                $id++;
            }
            $GLOBALS['digitalogic_test_next_post_id'] = $id + 1;
            $GLOBALS['digitalogic_test_posts'][$id] = array(
                'post_type' => 'product_variation',
                'post_status' => 'draft',
                'product_type' => 'variation',
                'post_parent' => 0,
                'post_title' => '',
                'meta' => array(),
            );
        }
        parent::__construct($id);
        $GLOBALS['digitalogic_test_posts'][$this->id]['post_type'] = 'product_variation';
        $GLOBALS['digitalogic_test_posts'][$this->id]['product_type'] = 'variation';
    }
}

class WC_Product_Variable extends WC_Product {
    public static $synced_ids = array();

    public static function sync($product_id) {
        self::$synced_ids[] = (int) $product_id;
        return true;
    }
}

class WC_Product_Attribute {
    private $id = 0;
    private $name = '';
    private $options = array();
    private $position = 0;
    private $visible = false;
    private $variation = false;

    public function set_id($value) { $this->id = (int) $value; }
    public function set_name($value) { $this->name = (string) $value; }
    public function set_options($value) { $this->options = array_values((array) $value); }
    public function set_position($value) { $this->position = (int) $value; }
    public function set_visible($value) { $this->visible = (bool) $value; }
    public function set_variation($value) { $this->variation = (bool) $value; }
    public function get_options() { return $this->options; }
    public function get_visible() { return $this->visible; }
    public function get_variation() { return $this->variation; }
}

// phpcs:disable -- Test-only WordPress and WooCommerce stubs intentionally follow the legacy bootstrap style.
function wp_get_attachment_url($attachment_id) {
    return $attachment_id ? 'https://digitalogic.test/media/' . (int) $attachment_id : false;
}

function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail') {
    return wp_get_attachment_url($attachment_id);
}

function wp_get_post_revisions($post_id) {
    return array();
}

function get_term($term_id, $taxonomy = '') {
	$term_id = (int) $term_id;
	foreach (($GLOBALS['digitalogic_test_terms'] ?? array()) as $stored) {
		$term = is_object($stored) ? $stored : (object) $stored;
		if (
			(int) ($term->term_id ?? 0) === $term_id
			&& ('' === $taxonomy || !isset($term->taxonomy) || (string) $term->taxonomy === (string) $taxonomy)
		) {
			return $term;
		}
	}

	return new WP_Error('term_not_found', 'Term not found.');
}

function get_terms($args = array()) {
	$terms = array();
	foreach (($GLOBALS['digitalogic_test_terms'] ?? array()) as $term_id => $term) {
		$term = is_object($term) ? $term : (object) $term;
		if (isset($args['taxonomy']) && isset($term->taxonomy) && (string) $args['taxonomy'] !== (string) $term->taxonomy) {
			continue;
		}
		if (isset($args['parent']) && (int) $args['parent'] !== (int) ($term->parent ?? 0)) {
			continue;
		}
		if (isset($args['name']) && (string) $args['name'] !== (string) ($term->name ?? '')) {
			continue;
		}
		if (isset($args['meta_key'])) {
			$resolved_id = (int) ($term->term_id ?? $term_id);
			$meta = $GLOBALS['digitalogic_test_term_meta'][$resolved_id][$args['meta_key']] ?? '';
			if ((string) $meta !== (string) ($args['meta_value'] ?? '')) {
				continue;
			}
		}
		if (!empty($args['include']) && !in_array((int) ($term->term_id ?? 0), array_map('intval', (array) $args['include']), true)) {
			continue;
		}
		$terms[] = $term;
	}
	usort($terms, static fn($left, $right) => (int) ($left->term_id ?? 0) <=> (int) ($right->term_id ?? 0));
	if ('DESC' === strtoupper((string) ($args['order'] ?? 'ASC'))) {
		$terms = array_reverse($terms);
	}
	$offset = max(0, (int) ($args['offset'] ?? 0));
	if (isset($args['number']) && (int) $args['number'] > 0) {
		return array_slice($terms, $offset, (int) $args['number']);
	}

	return array_slice($terms, $offset);
}

function term_exists($term, $taxonomy = '', $parent_term = null) {
    foreach (($GLOBALS['digitalogic_test_terms'] ?? array()) as $term_id => $stored) {
        if (
            (string) ($stored['name'] ?? '') === (string) $term
            && ('' === $taxonomy || (string) ($stored['taxonomy'] ?? '') === (string) $taxonomy)
            && (null === $parent_term || (int) ($stored['parent'] ?? 0) === (int) $parent_term)
        ) {
            return array('term_id' => (string) $term_id, 'term_taxonomy_id' => (string) $term_id);
        }
    }

    return null;
}

function wp_insert_term($term, $taxonomy, $args = array()) {
    $parent = (int) ($args['parent'] ?? 0);
    $existing = term_exists($term, $taxonomy, $parent);
    if (is_array($existing)) {
        return new WP_Error('term_exists', 'Term already exists.', (int) $existing['term_id']);
    }
    $next = max(1, (int) ($GLOBALS['digitalogic_test_next_term_id'] ?? 1));
    while (isset($GLOBALS['digitalogic_test_terms'][$next])) {
        $next++;
    }
    $GLOBALS['digitalogic_test_next_term_id'] = $next + 1;
    $GLOBALS['digitalogic_test_terms'][$next] = array(
        'term_id' => $next,
        'name' => (string) $term,
        'slug' => isset($args['slug']) ? (string) $args['slug'] : sanitize_title($term),
        'parent' => $parent,
        'taxonomy' => (string) $taxonomy,
    );

    return array('term_id' => $next, 'term_taxonomy_id' => $next);
}

function wp_update_term($term_id, $taxonomy, $args = array()) {
    $term_id = (int) $term_id;
    if (!isset($GLOBALS['digitalogic_test_terms'][$term_id])) {
        return new WP_Error('term_not_found', 'Term not found.');
    }
    if (isset($args['name'])) {
        $GLOBALS['digitalogic_test_terms'][$term_id]['name'] = (string) $args['name'];
    }
    if (isset($args['parent'])) {
        $GLOBALS['digitalogic_test_terms'][$term_id]['parent'] = (int) $args['parent'];
    }
    $GLOBALS['digitalogic_test_terms'][$term_id]['taxonomy'] = (string) $taxonomy;

    return array('term_id' => $term_id, 'term_taxonomy_id' => $term_id);
}

function get_term_meta($term_id, $key = '', $single = false) {
    $value = $GLOBALS['digitalogic_test_term_meta'][(int) $term_id][(string) $key] ?? '';
    return $single ? $value : ('' === $value ? array() : array($value));
}

function update_term_meta($term_id, $meta_key, $meta_value, $prev_value = '') {
    $term_id = (int) $term_id;
    if (!isset($GLOBALS['digitalogic_test_terms'][$term_id])) {
        return false;
    }
    $GLOBALS['digitalogic_test_term_meta'][$term_id][(string) $meta_key] = $meta_value;

    return true;
}

function clean_term_cache($ids, $taxonomy = '') {
    return true;
}

function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) {
    if ('product_type' === $taxonomy) {
        $GLOBALS['digitalogic_test_posts'][(int) $object_id]['product_type'] = (string) $terms;
    }
    return array((int) $object_id);
}

function wc_delete_product_transients($product_id = 0) {
    if ((int) $product_id > 0) {
        unset($GLOBALS['digitalogic_test_wc_products'][(int) $product_id]);
    }
    return true;
}

function wc_attribute_taxonomy_id_by_name($name) {
	return str_starts_with((string) $name, 'pa_') ? 1 : 0;
}

function wp_count_terms($args = array()) {
	return count($GLOBALS['digitalogic_test_terms']);
}

function get_term_link($term, $taxonomy = '') {
    if (!is_object($term) || empty($term->term_id)) {
        return new WP_Error('term_not_found', 'Term not found.');
    }

	return 'https://digitalogic.test/product-category/' . (string) ($term->slug ?? $term->term_id);
}

function admin_url($path = '') {
    return 'https://digitalogic.test/wp-admin/' . ltrim((string) $path, '/');
}

function wc_clean($value) {
    return sanitize_text_field($value);
}

function wp_strip_all_tags($value) {
    return strip_tags((string) $value);
}

function wc_get_products($args = array()) {
    $products = array();
    foreach ($GLOBALS['digitalogic_test_posts'] as $post_id => $post) {
        if (!in_array($post['post_type'], array('product', 'product_variation'), true)) {
            continue;
        }
        $product = wc_get_product($post_id);
        if (isset($args['sku']) && (string) $args['sku'] !== $product->get_sku()) {
            continue;
        }
        if (isset($args['status']) && 'any' !== $args['status'] && !in_array($product->get_status(), (array) $args['status'], true)) {
            continue;
        }
        if (isset($args['type']) && !in_array($product->get_type(), (array) $args['type'], true)) {
            continue;
        }
        $products[] = 'ids' === ($args['return'] ?? '') ? (int) $post_id : $product;
    }

    $limit = isset($args['limit']) ? (int) $args['limit'] : -1;
    $page = max(1, (int) ($args['page'] ?? 1));
    if ($limit > -1) {
        $products = array_slice($products, ($page - 1) * $limit, $limit);
    }

    return $products;
}

class WC_Data_Store {
    public static function load($object_type) {
        if ('product' !== $object_type) {
            throw new RuntimeException('Unexpected WooCommerce data-store type.');
        }

        $instance = $GLOBALS['digitalogic_test_wc_data_store'] ?: new Digitalogic_Test_WC_Product_Data_Store_Without_Row_Refresh();
        return new Digitalogic_Test_WC_Data_Store_Proxy($instance);
    }
}

class Digitalogic_Test_WC_Data_Store_Proxy {
    private $instance;

    public function __construct($instance) {
        $this->instance = $instance;
    }

    public function get_current_class_name() {
        return get_class($this->instance);
    }

    public function __call($method, $parameters) {
        if (is_callable(array($this->instance, $method))) {
            return $this->instance->{$method}(...$parameters);
        }

        return null;
    }
}

class Digitalogic_Test_WC_Product_Data_Store_With_Row_Refresh {
    public $refreshed_ids = array();

    public function refresh_product_lookup_table($product_id) {
        $this->refreshed_ids[] = (int) $product_id;
    }
}

class Digitalogic_Test_WC_Product_Data_Store_Without_Row_Refresh {
}

function wc_update_product_lookup_tables() {
    $GLOBALS['digitalogic_test_wc_lookup_full_rebuilds']++;
}

function wc_stock_amount($amount) {
    return '' === $amount || null === $amount ? null : (float) $amount;
}
// phpcs:enable

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

function get_woocommerce_currency_symbol($currency = '') {
    $currency = strtoupper((string) $currency);

    return 'IRT' === $currency ? 'Toman' : $currency;
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

require_once dirname(__DIR__) . '/includes/class-digitalogic-currency-date-formatter.php';
require_once dirname(__DIR__) . '/includes/class-digitalogic-currency-shortcodes.php';
require_once dirname(__DIR__) . '/includes/class-unit-converter.php';
require_once dirname(__DIR__) . '/includes/class-digitalogic-woocommerce-currency-status.php';
require_once dirname(__DIR__) . '/includes/class-product-identifier-resolver.php';
require_once dirname(__DIR__) . '/includes/class-digitalogic-product-query.php';
require_once dirname(__DIR__) . '/includes/class-digitalogic-product-metadata-inspector.php'; // phpcs:ignore
require_once dirname(__DIR__) . '/includes/class-product-manager.php'; // phpcs:ignore
require_once dirname(__DIR__) . '/includes/admin/class-digitalogic-product-table.php'; // phpcs:ignore
require_once dirname(__DIR__) . '/includes/class-digitalogic-pricing-input-credential.php'; // phpcs:ignore
require_once dirname(__DIR__) . '/includes/class-patris-feed.php';
require_once dirname(__DIR__) . '/includes/class-product-sync-receiver.php';
require_once dirname(__DIR__) . '/includes/class-shipping-method-service.php';
require_once dirname(__DIR__) . '/includes/class-patris-catalog-materializer.php';
require_once dirname(__DIR__) . '/includes/class-digitalogic-google-sheets-catalog.php';
require_once dirname(__DIR__) . '/includes/class-digitalogic-google-sheets-writeback.php';
require_once dirname(__DIR__) . '/includes/class-command-dispatcher.php';
require_once dirname(__DIR__) . '/includes/api/class-rest-api.php';
require_once dirname(__DIR__) . '/includes/api/class-webhooks.php';
require_once dirname(__DIR__) . '/includes/class-report-engine.php';
require_once dirname(__DIR__) . '/includes/integrations/class-laravel-bridge.php';
require_once dirname(__DIR__) . '/includes/panel/class-panel.php';
require_once dirname(__DIR__) . '/includes/integrations/class-product-identity.php';
require_once dirname(__DIR__) . '/includes/websocket/class-websocket-server.php';
require_once dirname(__DIR__) . '/includes/cli/class-cli-commands.php';
