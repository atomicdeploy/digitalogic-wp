<?php
/** Standalone search cache boundary regression harness. @package Digitalogic */
// phpcs:disable -- Deliberately minimal WordPress/theme test doubles in an isolated process.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function nocache_headers() {}
function get_option($key, $default = false) { return $default; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return (string) $value; }
function wp_json_encode($value) { return json_encode($value); }
function get_current_user_id() { return 0; }
function get_locale() { return 'fa_IR'; }
function get_woocommerce_currency() { return 'IRT'; }
function url_to_postid($url) { return 11904; }
function wp_cache_get($key, $group) { return $GLOBALS['cache'][$key] ?? false; }
function wp_cache_set($key, $data, $group, $ttl) { $GLOBALS['cache'][$key] = $data; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {}
class SearchJson extends Exception { public $result; public $status; }
function wp_send_json($data, $status = 200) {
    $error = new SearchJson(); $error->result = $data; $error->status = $status; throw $error;
}
class Digitalogic_Report_Engine {
    public static $generation = 'one';
    public static function instance() { return new self(); }
    public function current_projection_generation() { return self::$generation; }
}
class SearchProduct {
    public static $price = '505900';
    public function get_price_html() { return self::$price; }
}
function wc_get_product($id) { return new SearchProduct(); }
class SearchTheme {
    public static $queries = 0;
    public static $race = false;
    public static function get_instance() { return new self(); }
    public function get_main_suggestions() {
        self::$queries++;
        if (self::$race) Digitalogic_Report_Engine::$generation = 'raced';
        return array(array('value' => 'TEC1-12704', 'permalink' => '/product/tec1-12704/', 'price' => 'old-theme-price'));
    }
    public function get_product_categories_suggestions() { return array(); }
    public function get_blog_suggestions() { return array(); }
    public function build_suggestions($main, $categories, $blog) { return $main; }
}
class_alias(SearchTheme::class, 'XTS\\Modules\\Search\\Ajax_Search');
require __DIR__ . '/../includes/integrations/class-frontend-search.php';
$_COOKIE = array();
$_REQUEST = array('query' => 'TEC1-12704');
function search_response() {
    try { Digitalogic_Frontend_Search::instance()->serve_search(); }
    catch (SearchJson $result) { return $result; }
    throw new RuntimeException('No response');
}
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$first = search_response()->result;
verify($first['suggestions'][0]['price'] === '505900', 'fresh price');
$second = search_response()->result;
verify($second['dg_cache']['hit'] && SearchTheme::$queries === 1, 'query cache reused');
$_REQUEST['dg_search_signature'] = $second['dg_cache']['signature'];
verify(search_response()->result['unchanged'] === true, 'browser cache validated');
SearchProduct::$price = '506000'; // Simulate missed invalidation: same generation, new canonical price.
$changed = search_response()->result;
verify($changed['suggestions'][0]['price'] === '506000', 'missed event cannot return old cached price');
verify($changed['dg_cache']['signature'] !== $second['dg_cache']['signature'], 'old signature rejected');
Digitalogic_Report_Engine::$generation = 'two';
verify(!search_response()->result['dg_cache']['hit'] && SearchTheme::$queries === 2, 'event generation invalidates query cache');
Digitalogic_Report_Engine::$generation = 'three'; SearchTheme::$race = true;
verify(search_response()->status === 409, 'concurrent change fails closed');
Digitalogic_Report_Engine::$generation = new WP_Error();
verify(search_response()->status === 503, 'unavailable generation fails closed');
echo "Search cache regression: 7 boundaries passed\n";
