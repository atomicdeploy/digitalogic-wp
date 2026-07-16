<?php

use PHPUnit\Framework\TestCase;

final class RestApiPermissionsTest extends TestCase {
    /** @var Digitalogic_REST_API */
    private $api;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_rest_prefix'] = 'wp-json';
        $GLOBALS['digitalogic_test_rest_url_calls'] = 0;
        $GLOBALS['digitalogic_test_current_user_can_calls'] = 0;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        unset($_SERVER['REQUEST_URI']);

        $this->resetApi();
    }

    private function resetApi() {
        remove_all_filters('woocommerce_rest_is_request_to_rest_api');

        $instance = new ReflectionProperty(Digitalogic_REST_API::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        $this->api = Digitalogic_REST_API::instance();
    }

    public function test_woocommerce_route_matcher_never_generates_a_rest_url_or_resolves_a_user() {
        $GLOBALS['digitalogic_test_rest_url_calls'] = 0;
        $GLOBALS['digitalogic_test_current_user_can_calls'] = 0;

        $this->resetApi();

        $_SERVER['REQUEST_URI'] = '/wp-json/digitalogic/v1/products';

        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));
        $this->assertSame(0, $GLOBALS['digitalogic_test_rest_url_calls']);
        $this->assertSame(0, $GLOBALS['digitalogic_test_current_user_can_calls']);
    }

    /**
     * @dataProvider rolePolicyProvider
     */
    public function test_role_capability_policy($role, $capabilities, $expected) {
        $GLOBALS['digitalogic_test_capabilities'] = $capabilities;
        $request = new WP_REST_Request();

        $this->assertSame($expected, $this->api->check_read_permission($request), $role . ' read access');
        $this->assertSame($expected, $this->api->check_write_permission($request), $role . ' write access');
        $this->assertSame($expected, $this->api->check_diagnostic_permission($request), $role . ' diagnostic access');
    }

    public static function rolePolicyProvider() {
        return array(
            'subscriber' => array('subscriber', array('read' => true), false),
            'customer' => array('customer', array('read' => true), false),
            'shop manager' => array('shop_manager', array('read' => true, 'manage_woocommerce' => true), true),
            'administrator' => array('administrator', array('read' => true, 'manage_woocommerce' => true), true),
        );
    }

    /**
     * @dataProvider restRequestProvider
     */
    public function test_woocommerce_authentication_recognizes_only_digitalogic_routes($request_uri, $already_recognized, $expected) {
        $_SERVER['REQUEST_URI'] = $request_uri;

        $this->assertSame(
            $expected,
            apply_filters('woocommerce_rest_is_request_to_rest_api', $already_recognized),
            $request_uri
        );
    }

    public static function restRequestProvider() {
        return array(
            'namespace root' => array('/wp-json/digitalogic/v1', false, true),
            'namespace route and query' => array('/wp-json/digitalogic/v1/products?consumer_key=ck_example', false, true),
            'namespace lookalike' => array('/wp-json/digitalogic/v10/products', false, false),
            'namespace suffix lookalike' => array('/wp-json/digitalogic/v1-unsafe/products', false, false),
            'unrelated route' => array('/wp-json/example/v1/products', false, false),
            'existing WooCommerce route remains recognized' => array('/wp-json/wc/v3/products', true, true),
        );
    }

    public function test_woocommerce_authentication_recognizes_subdirectory_install() {
        $_SERVER['SCRIPT_NAME'] = '/store/index.php';
        $_SERVER['REQUEST_URI'] = '/store/wp-json/digitalogic/v1/products';

        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $_SERVER['REQUEST_URI'] = '/elsewhere/wp-json/digitalogic/v1/products';
        $this->assertFalse(apply_filters('woocommerce_rest_is_request_to_rest_api', false));
    }

    public function test_woocommerce_authentication_recognizes_rest_route_query() {
        $_SERVER['REQUEST_URI'] = '/?rest_route=%2Fdigitalogic%2Fv1%2Fproducts';

        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $_SERVER['REQUEST_URI'] = '/?rest_route=%2Fdigitalogic%2Fv10%2Fproducts';
        $this->assertFalse(apply_filters('woocommerce_rest_is_request_to_rest_api', false));
    }

    public function test_rest_route_query_does_not_authenticate_unrelated_subdirectory_request() {
        $_SERVER['SCRIPT_NAME'] = '/store/index.php';
        $_SERVER['REQUEST_URI'] = '/store/?unrelated=1';

        $this->assertFalse(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $_SERVER['REQUEST_URI'] = '/store/?rest_route=%2Fdigitalogic%2Fv1%2Fproducts';
        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $_SERVER['REQUEST_URI'] = '/store/index.php?rest_route=%2Fdigitalogic%2Fv1%2Fproducts';
        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $_SERVER['REQUEST_URI'] = '/elsewhere/?rest_route=%2Fdigitalogic%2Fv1%2Fproducts';
        $this->assertFalse(apply_filters('woocommerce_rest_is_request_to_rest_api', false));
    }

    public function test_woocommerce_authentication_uses_the_configured_rest_prefix() {
        $GLOBALS['digitalogic_test_rest_prefix'] = 'api';
        $_SERVER['REQUEST_URI'] = '/api/digitalogic/v1/products';

        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $_SERVER['REQUEST_URI'] = '/wp-json/digitalogic/v1/products';
        $this->assertFalse(apply_filters('woocommerce_rest_is_request_to_rest_api', false));
    }

    public function test_woocommerce_route_recognition_does_not_bypass_capability_policy() {
        $_SERVER['REQUEST_URI'] = '/wp-json/digitalogic/v1/products';
        $request = new WP_REST_Request();

        $this->assertTrue(apply_filters('woocommerce_rest_is_request_to_rest_api', false));

        $GLOBALS['digitalogic_test_capabilities'] = array('read' => true);
        $this->assertFalse($this->api->check_read_permission($request));
        $this->assertFalse($this->api->check_write_permission($request));

        $GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;

        $this->assertTrue($this->api->check_read_permission($request));
        $this->assertTrue($this->api->check_write_permission($request));
    }

    public function test_legacy_filter_is_denied_by_default_and_receives_scope_and_request() {
        $calls = array();
        $request = new WP_REST_Request();

        add_filter(
            'digitalogic_rest_api_permission',
            function($allowed, $scope, $filtered_request) use (&$calls) {
                $calls[] = array($allowed, $scope, $filtered_request);

                return 'read' === $scope;
            },
            10,
            3
        );

        $this->assertTrue($this->api->check_read_permission($request));
        $this->assertFalse($this->api->check_write_permission($request));
        $this->assertFalse($this->api->check_diagnostic_permission($request));
        $this->assertCount(3, $calls);

        foreach ($calls as $call) {
            $this->assertFalse($call[0]);
            $this->assertSame($request, $call[2]);
        }

        $this->assertSame(array('read', 'write', 'diagnostic'), array_column($calls, 1));
    }

    public function test_legacy_one_argument_filter_remains_compatible_for_all_scopes() {
        add_filter(
            'digitalogic_rest_api_permission',
            function($allowed) {
                $this->assertFalse($allowed);

                return true;
            }
        );

        $this->assertTrue($this->api->check_read_permission(new WP_REST_Request()));
        $this->assertTrue($this->api->check_write_permission(new WP_REST_Request()));
        $this->assertTrue($this->api->check_diagnostic_permission(new WP_REST_Request()));
    }

    public function test_legacy_filter_must_return_boolean_true() {
        add_filter(
            'digitalogic_rest_api_permission',
            function() {
                return 'true';
            }
        );

        $this->assertFalse($this->api->check_read_permission(new WP_REST_Request()));
    }

    public function test_legacy_public_check_permission_alias_uses_write_scope() {
        $scope_seen = null;
        $request = new WP_REST_Request();

        $this->assertFalse($this->api->check_permission($request));

        add_filter(
            'digitalogic_rest_api_permission',
            function($allowed, $scope) use (&$scope_seen) {
                $scope_seen = $scope;

                return 'write' === $scope;
            },
            10,
            2
        );

        $this->assertTrue($this->api->check_permission($request));
        $this->assertSame('write', $scope_seen);
    }

    public function test_every_route_has_the_expected_permission_policy() {
        $this->api->register_routes();

        $actual = array();
        foreach ($GLOBALS['digitalogic_test_routes'] as $registration) {
            $definitions = isset($registration['args']['callback'])
                ? array($registration['args'])
                : $registration['args'];
            foreach ($definitions as $definition) {
                $permission_callback = $definition['permission_callback'];
                $actual[$definition['methods'] . ' ' . $registration['route']] = $permission_callback[1];
            }
        }

        $expected = array(
            'GET /products' => 'check_read_permission',
            'GET /products/(?P<id>\d+)' => 'check_read_permission',
            'PUT /products/(?P<id>\d+)' => 'check_write_permission',
            'POST /products/batch' => 'check_write_permission',
            'GET /currency' => 'check_read_permission',
            'POST /currency' => 'check_write_permission',
            'POST /pricing/recalculate' => 'check_write_permission',
            'GET /export' => 'check_diagnostic_permission',
            'GET /reports' => 'check_diagnostic_permission',
            'POST /patris/sync' => 'check_write_permission',
            'POST /patris/push' => 'check_patris_push_permission',
            'GET /integration/catalog' => 'check_read_permission',
            'GET /import-freight-methods' => 'check_read_permission',
            'POST /import-freight-methods' => 'check_write_permission',
            'GET /import-freight-methods/(?P<id>[a-z][a-z0-9_]{1,63})' => 'check_read_permission',
            'PUT /import-freight-methods/(?P<id>[a-z][a-z0-9_]{1,63})' => 'check_write_permission',
            'DELETE /import-freight-methods/(?P<id>[a-z][a-z0-9_]{1,63})' => 'check_write_permission',
            'GET /products/by-code/(?P<code>[^/]+)/import-pricing' => 'check_read_permission',
            'PUT /products/by-code/(?P<code>[^/]+)/import-pricing' => 'check_write_permission',
            'POST /products/import-pricing/batch' => 'check_write_permission',
        );

        $this->assertSame($expected, $actual);
    }

    public function test_patris_push_delegates_to_its_scoped_verifier() {
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_push_token'] = 'scoped-patris-token';
        unset($GLOBALS['digitalogic_test_option_cache']['digitalogic_patris_feed_push_token']);
        $authorized = new WP_REST_Request(array(), array(), array(
            'x-digitalogic-token' => 'scoped-patris-token',
        ));
        $unauthorized = new WP_REST_Request(array(), array(), array(
            'x-digitalogic-token' => 'wrong-token',
        ));

        $this->assertTrue($this->api->check_patris_push_permission($authorized));
        $this->assertFalse($this->api->check_patris_push_permission($unauthorized));
    }
}
