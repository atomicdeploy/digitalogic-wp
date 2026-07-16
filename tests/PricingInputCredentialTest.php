<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

final class PricingInputCredentialTest extends TestCase {
    /** @var Digitalogic_Pricing_Input_Credential */
    private $credential;

    /** @var Digitalogic_REST_API */
    private $api;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_capabilities'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_routes'] = array();
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_transients'] = array();
        $GLOBALS['digitalogic_test_transient_deletes'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        WP_CLI::$errors = array();
        WP_CLI::$warnings = array();
        WP_CLI::$logs = array();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.60';
        $_COOKIE = array();

        $credential_instance = new ReflectionProperty(Digitalogic_Pricing_Input_Credential::class, 'instance');
        $credential_instance->setValue(null, null);
        $api_instance = new ReflectionProperty(Digitalogic_REST_API::class, 'instance');
        $api_instance->setValue(null, null);

        $this->credential = Digitalogic_Pricing_Input_Credential::instance();
        $this->api = Digitalogic_REST_API::instance();
    }

    protected function tearDown(): void {
        $_COOKIE = array();
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_bearer_authorizes_only_both_exact_pricing_input_contracts_and_humans_remain_supported(): void {
        $secret = $this->createSecret();
        $catalog = $this->request('GET', '/digitalogic/v1/integration/catalog', $secret);
        $batch = $this->request('POST', '/digitalogic/v1/pricing-assignments/batch', $secret);

        $this->assertTrue($this->api->check_pricing_input_permission($catalog));
        $this->assertTrue($this->api->check_pricing_input_permission($batch));

        $this->assertSame(
            'digitalogic_pricing_input_scope_denied',
            $this->api->check_pricing_input_permission(
                $this->request('POST', '/digitalogic/v1/integration/catalog', $secret)
            )->get_error_code()
        );
        $this->assertSame(
            'digitalogic_pricing_input_scope_denied',
            $this->api->check_pricing_input_permission(
                $this->request('GET', '/digitalogic/v1/pricing-assignments/batch', $secret)
            )->get_error_code()
        );

        $GLOBALS['digitalogic_test_capabilities']['manage_woocommerce'] = true;
        $this->assertTrue(
            $this->api->check_pricing_input_permission(
                $this->request('GET', '/digitalogic/v1/integration/catalog')
            )
        );
        $this->assertTrue(
            $this->api->check_pricing_input_permission(
                $this->request('POST', '/digitalogic/v1/pricing-assignments/batch')
            )
        );
    }

    public function test_machine_bearer_cannot_authorize_any_other_registered_route_or_method(): void {
        $secret = $this->createSecret();
        $this->api->register_routes();
        $allowed = array(
            'GET /integration/catalog',
            'POST /pricing-assignments/batch',
        );
        $authorized = array();

        foreach ($GLOBALS['digitalogic_test_routes'] as $registration) {
            $definitions = isset($registration['args']['callback'])
                ? array($registration['args'])
                : $registration['args'];
            foreach ($definitions as $definition) {
                $key = $definition['methods'] . ' ' . $registration['route'];
                $request = $this->request(
                    $definition['methods'],
                    '/digitalogic/v1' . $registration['route'],
                    $secret
                );
                $result = call_user_func($definition['permission_callback'], $request);

                if (in_array($key, $allowed, true)) {
                    $this->assertTrue($result, $key);
                    $authorized[] = $key;
                } else {
                    $this->assertNotTrue($result, $key);
                }
            }
        }

        $this->assertSame($allowed, $authorized);
        $this->assertFalse($this->api->check_read_permission($this->request('GET', '/digitalogic/v1/products', $secret)));
        $this->assertFalse($this->api->check_write_permission($this->request('POST', '/digitalogic/v1/patris/sync', $secret)));
        $this->assertFalse($this->api->check_diagnostic_permission($this->request('GET', '/digitalogic/v1/reports', $secret)));
    }

    public function test_absent_wrong_rotated_and_revoked_credentials_fail_closed(): void {
        $absent = $this->api->check_pricing_input_permission(
            $this->request('GET', '/digitalogic/v1/integration/catalog')
        );
        $this->assertSame('digitalogic_pricing_input_unauthorized', $absent->get_error_code());
        $this->assertSame(401, $absent->get_error_data()['status']);

        $issued = $this->credential->create();
        $old = $issued['secret'];
        $wrong = substr($old, 0, -1) . ($old[-1] === 'A' ? 'B' : 'A');
        $this->assertSame(
            'digitalogic_pricing_input_unauthorized',
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $wrong)
            )->get_error_code()
        );
        $this->assertTrue(
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $old)
            )
        );

        $rotated = $this->credential->rotate();
        $new = $rotated['secret'];
        $this->assertNotSame($old, $new);
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $old)
            )
        );
        $this->assertTrue(
            $this->credential->authorize(
                $this->request('POST', '/digitalogic/v1/pricing-assignments/batch', $new)
            )
        );

        $revoked = $this->credential->revoke();
        $this->assertFalse($revoked['active']);
        $this->assertSame('revoked', $revoked['state']);
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('POST', '/digitalogic/v1/pricing-assignments/batch', $new)
            )
        );
    }

    public function test_query_cookie_basic_legacy_and_product_sync_credentials_are_rejected(): void {
        $secret = $this->createSecret();
        $GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_push_token'] = 'legacy-push-secret';
        $GLOBALS['digitalogic_test_options'][Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION] = 'product-sync-secret';

        $_COOKIE['Authorization'] = $secret;
        $requests = array(
            'query' => new WP_REST_Request(array('access_token' => $secret)),
            'cookie' => new WP_REST_Request(),
            'basic' => new WP_REST_Request(array(), array(), array(
                'Authorization' => 'Basic ' . base64_encode($secret . ':ignored'),
            )),
            'userinfo' => new WP_REST_Request(array('url' => 'https://' . rawurlencode($secret) . '@digitalogic.test/')),
            'legacy header' => new WP_REST_Request(array(), array(), array(
                'X-Digitalogic-Token' => 'legacy-push-secret',
            )),
            'product sync header' => new WP_REST_Request(array(), array(), array(
                'X-Digitalogic-Product-Sync-Secret' => 'product-sync-secret',
            )),
            'legacy bearer' => new WP_REST_Request(array(), array(), array(
                'Authorization' => 'Bearer legacy-push-secret',
            )),
        );

        foreach ($requests as $name => $request) {
            $GLOBALS['digitalogic_test_transients'] = array();
            $request->set_route('/digitalogic/v1/integration/catalog');
            $request->set_method('GET');
            $result = $this->api->check_pricing_input_permission($request);

            $this->assertInstanceOf(WP_Error::class, $result, $name);
            $this->assertSame('digitalogic_pricing_input_unauthorized', $result->get_error_code(), $name);
        }
    }

    public function test_overlapping_creates_are_linearized_and_only_one_secret_is_revealed(): void {
        $nested = null;
        $GLOBALS['wpdb']->before_get_lock = function() use (&$nested): void {
            $nested = $this->credential->create();
        };

        $outer = $this->credential->create();

        $this->assertIsArray($nested);
        $this->assertArrayHasKey('secret', $nested);
        $this->assertInstanceOf(WP_Error::class, $outer);
        $this->assertSame('digitalogic_pricing_input_lifecycle_conflict', $outer->get_error_code());
        $this->assertStringNotContainsString($nested['secret'], serialize($outer));
        $this->assertSame(1, $this->credential->status()['generation']);
        $this->assertTrue(
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $nested['secret'])
            )
        );
        $this->assertSame(array(3, 3), $GLOBALS['wpdb']->lock_timeouts);
        $this->assertSame(2, $GLOBALS['wpdb']->acquire_count);
        $this->assertSame(2, $GLOBALS['wpdb']->release_count);
    }

    public function test_overlapping_rotates_share_one_observation_and_only_one_can_issue(): void {
        $initial = $this->createSecret();
        $GLOBALS['wpdb']->acquire_count = 0;
        $GLOBALS['wpdb']->release_count = 0;
        $GLOBALS['wpdb']->lock_timeouts = array();
        $nested = null;
        $GLOBALS['wpdb']->before_get_lock = function() use (&$nested): void {
            $nested = $this->credential->rotate();
        };

        $outer = $this->credential->rotate();

        $this->assertIsArray($nested);
        $this->assertArrayHasKey('secret', $nested);
        $this->assertSame(2, $nested['metadata']['generation']);
        $this->assertInstanceOf(WP_Error::class, $outer);
        $this->assertSame('digitalogic_pricing_input_lifecycle_conflict', $outer->get_error_code());
        $this->assertStringNotContainsString($nested['secret'], serialize($outer));
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $initial)
            )
        );
        $this->assertTrue(
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $nested['secret'])
            )
        );

        $later = $this->credential->rotate();
        $this->assertIsArray($later);
        $this->assertSame(3, $later['metadata']['generation']);
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $nested['secret'])
            )
        );
        $this->assertTrue(
            $this->credential->authorize(
                $this->request('POST', '/digitalogic/v1/pricing-assignments/batch', $later['secret'])
            )
        );
        $this->assertSame(array(3, 3, 3), $GLOBALS['wpdb']->lock_timeouts);
        $this->assertSame(3, $GLOBALS['wpdb']->acquire_count);
        $this->assertSame(3, $GLOBALS['wpdb']->release_count);
    }

    public function test_revoke_wins_both_lock_orders_against_an_overlapping_rotate(): void {
        $initial = $this->createSecret();
        $nested_revoke = null;
        $GLOBALS['wpdb']->before_get_lock = function() use (&$nested_revoke): void {
            $nested_revoke = $this->credential->revoke();
        };

        $outer_rotate = $this->credential->rotate();

        $this->assertIsArray($nested_revoke);
        $this->assertSame('revoked', $nested_revoke['state']);
        $this->assertInstanceOf(WP_Error::class, $outer_rotate);
        $this->assertSame('digitalogic_pricing_input_lifecycle_conflict', $outer_rotate->get_error_code());
        $this->assertSame('revoked', $this->credential->status()['state']);
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $initial)
            )
        );

        $active = $this->credential->create();
        $nested_rotate = null;
        $GLOBALS['wpdb']->before_get_lock = function() use (&$nested_rotate): void {
            $nested_rotate = $this->credential->rotate();
        };

        $outer_revoke = $this->credential->revoke();

        $this->assertIsArray($nested_rotate);
        $this->assertArrayHasKey('secret', $nested_rotate);
        $this->assertIsArray($outer_revoke);
        $this->assertSame('revoked', $outer_revoke['state']);
        $this->assertSame($nested_rotate['metadata']['generation'], $outer_revoke['generation']);
        $this->assertSame('revoked', $this->credential->status()['state']);
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $active['secret'])
            )
        );
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $nested_rotate['secret'])
            )
        );
    }

    public function test_authoritative_verification_ignores_stale_cache_after_rotate_and_revoke(): void {
        $issued = $this->credential->create();
        $old_record = $GLOBALS['digitalogic_test_options'][Digitalogic_Pricing_Input_Credential::OPTION_NAME];
        $GLOBALS['digitalogic_test_option_cache'][Digitalogic_Pricing_Input_Credential::OPTION_NAME] = $old_record;

        $rotated = $this->credential->rotate();
        $active_record = $GLOBALS['digitalogic_test_options'][Digitalogic_Pricing_Input_Credential::OPTION_NAME];
        $GLOBALS['digitalogic_test_option_cache'][Digitalogic_Pricing_Input_Credential::OPTION_NAME] = $old_record;

        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $issued['secret'])
            )
        );
        $this->assertTrue(
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $rotated['secret'])
            )
        );

        $this->credential->revoke();
        $GLOBALS['digitalogic_test_option_cache'][Digitalogic_Pricing_Input_Credential::OPTION_NAME] = $active_record;

        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('POST', '/digitalogic/v1/pricing-assignments/batch', $rotated['secret'])
            )
        );
        $this->assertSame('revoked', $this->credential->status()['state']);
    }

    public function test_lifecycle_lock_timeout_and_failed_readback_reveal_no_secret_and_release_safely(): void {
        $GLOBALS['wpdb']->acquire_result = 0;
        $busy = $this->credential->create();

        $this->assertInstanceOf(WP_Error::class, $busy);
        $this->assertSame('digitalogic_pricing_input_lifecycle_busy', $busy->get_error_code());
        $this->assertTrue($busy->get_error_data()['retryable']);
        $this->assertArrayNotHasKey(Digitalogic_Pricing_Input_Credential::OPTION_NAME, $GLOBALS['digitalogic_test_options']);
        $this->assertSame(array(3), $GLOBALS['wpdb']->lock_timeouts);
        $this->assertSame(0, $GLOBALS['wpdb']->release_count);

        $GLOBALS['wpdb']->acquire_result = 1;
        $GLOBALS['wpdb']->after_option_write = function($wpdb, $name): void {
            $GLOBALS['digitalogic_test_options'][$name]['generation'] = 999;
        };
        $failed = $this->credential->create();

        $this->assertInstanceOf(WP_Error::class, $failed);
        $this->assertSame('digitalogic_pricing_input_write_failed', $failed->get_error_code());
        $this->assertArrayNotHasKey(Digitalogic_Pricing_Input_Credential::OPTION_NAME, $GLOBALS['digitalogic_test_options']);
        $this->assertSame(1, $GLOBALS['wpdb']->release_count);
        $this->assertStringNotContainsString('dgp1.', serialize($failed));
    }

    public function test_storage_status_errors_and_runtime_sources_never_leak_a_secret(): void {
        $issued = $this->credential->create();
        $secret = $issued['secret'];
        $stored = $GLOBALS['digitalogic_test_options'][Digitalogic_Pricing_Input_Credential::OPTION_NAME];
        $status = $this->credential->status();

        $this->assertMatchesRegularExpression('/\Adgp1\.[a-f0-9]{16}\.[A-Za-z0-9_-]{43}\z/D', $secret);
        $this->assertSame(hash('sha256', $secret), $stored['verifier']);
        $this->assertStringNotContainsString($secret, serialize($stored));
        $this->assertArrayNotHasKey('verifier', $status);
        $this->assertArrayNotHasKey('secret', $status);
        $this->assertStringNotContainsString($secret, wp_json_encode($status));

        $wrong = substr($secret, 0, -1) . ($secret[-1] === 'A' ? 'B' : 'A');
        $error = $this->credential->authorize(
            $this->request('GET', '/digitalogic/v1/integration/catalog', $wrong)
        );
        $this->assertStringNotContainsString($wrong, $error->get_error_message());
        $this->assertStringNotContainsString($secret, serialize($error->get_error_data()));

        $source = file_get_contents(dirname(__DIR__) . '/includes/class-digitalogic-pricing-input-credential.php');
        $this->assertStringContainsString('hash_equals(', $source);
        $this->assertStringNotContainsString('error_log(', $source);
        $this->assertStringNotContainsString('Digitalogic_Logger', $source);
    }

    public function test_malformed_or_unavailable_storage_fails_closed_without_revealing_generated_material(): void {
        $GLOBALS['digitalogic_test_options'][Digitalogic_Pricing_Input_Credential::OPTION_NAME] = array(
            'secret' => 'must-never-be-trusted',
        );
        $status = $this->credential->status();
        $result = $this->credential->authorize(
            $this->request(
                'GET',
                '/digitalogic/v1/integration/catalog',
                'dgp1.' . str_repeat('0', 16) . '.' . str_repeat('A', 43)
            )
        );

        $this->assertSame('invalid', $status['state']);
        $this->assertArrayNotHasKey('secret', $status);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringNotContainsString('must-never-be-trusted', $result->get_error_message());

        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $issued = $this->credential->create();
        $malformed = $GLOBALS['digitalogic_test_options'][Digitalogic_Pricing_Input_Credential::OPTION_NAME];
        unset($malformed['rotated_at']);
        $GLOBALS['digitalogic_test_options'][Digitalogic_Pricing_Input_Credential::OPTION_NAME] = $malformed;
        $GLOBALS['digitalogic_test_option_cache'][Digitalogic_Pricing_Input_Credential::OPTION_NAME] = $malformed;
        $this->assertSame('invalid', $this->credential->status()['state']);
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $issued['secret'])
            )
        );

        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_update_failures'][] = Digitalogic_Pricing_Input_Credential::OPTION_NAME;
        $failed = $this->credential->create();
        $this->assertSame('digitalogic_pricing_input_write_failed', $failed->get_error_code());
        $this->assertArrayNotHasKey(Digitalogic_Pricing_Input_Credential::OPTION_NAME, $GLOBALS['digitalogic_test_options']);
    }

    public function test_repeated_failures_are_throttled_without_retaining_or_logging_tokens(): void {
        $secret = $this->createSecret();
        $wrong = substr($secret, 0, -1) . ($secret[-1] === 'A' ? 'B' : 'A');

        for ($attempt = 0; $attempt < Digitalogic_Pricing_Input_Credential::FAILURE_LIMIT; $attempt++) {
            $result = $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $wrong)
            );
            $this->assertSame(401, $result->get_error_data()['status']);
        }

        $this->assertTrue(
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $secret)
            )
        );
        $this->assertSame(array(), $GLOBALS['digitalogic_test_transients']);
        $this->assertCount(1, $GLOBALS['digitalogic_test_transient_deletes']);

        for ($attempt = 0; $attempt < Digitalogic_Pricing_Input_Credential::FAILURE_LIMIT; $attempt++) {
            $result = $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $wrong)
            );
            $this->assertSame(401, $result->get_error_data()['status']);
        }

        $blocked = $this->credential->authorize(
            $this->request('GET', '/digitalogic/v1/integration/catalog', $secret)
        );
        $this->assertTrue($blocked);

        for ($attempt = 0; $attempt < Digitalogic_Pricing_Input_Credential::FAILURE_LIMIT; $attempt++) {
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $wrong)
            );
        }
        $throttled_invalid = $this->credential->authorize(
            $this->request('GET', '/digitalogic/v1/integration/catalog', $wrong)
        );
        $this->assertSame('digitalogic_pricing_input_throttled', $throttled_invalid->get_error_code());
        $this->assertSame(429, $throttled_invalid->get_error_data()['status']);
        $this->assertStringNotContainsString($wrong, serialize($GLOBALS['digitalogic_test_transients']));
        $this->assertStringNotContainsString($secret, serialize($GLOBALS['digitalogic_test_transients']));
    }

    public function test_first_correct_authentication_is_strictly_write_free(): void {
        $secret = $this->createSecret();
        $GLOBALS['digitalogic_test_transient_deletes'] = array();
        $before = array(
            'options' => $GLOBALS['digitalogic_test_options'],
            'option_cache' => $GLOBALS['digitalogic_test_option_cache'],
            'queries' => $GLOBALS['wpdb']->queries,
            'transients' => $GLOBALS['digitalogic_test_transients'],
        );

        $result = $this->credential->authorize(
            $this->request('GET', '/digitalogic/v1/integration/catalog', $secret)
        );

        $this->assertTrue($result);
        $this->assertSame($before['options'], $GLOBALS['digitalogic_test_options']);
        $this->assertSame($before['option_cache'], $GLOBALS['digitalogic_test_option_cache']);
        $this->assertSame($before['queries'], $GLOBALS['wpdb']->queries);
        $this->assertSame($before['transients'], $GLOBALS['digitalogic_test_transients']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_transient_deletes']);
    }

    public function test_administrator_only_cli_reveals_new_secrets_once_and_status_never_does(): void {
        $commands = new Digitalogic_CLI_Commands();

        $GLOBALS['digitalogic_test_capabilities'] = array('manage_woocommerce' => true);
        $commands->pricing_input_credential_create();
        $this->assertNotEmpty(WP_CLI::$errors);
        $this->assertArrayNotHasKey(Digitalogic_Pricing_Input_Credential::OPTION_NAME, $GLOBALS['digitalogic_test_options']);

        $GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
        WP_CLI::$errors = array();
        WP_CLI::$logs = array();
        $commands->pricing_input_credential_create();
        $secret = WP_CLI::$logs[0];
        $this->assertMatchesRegularExpression('/\Adgp1\./', $secret);
        $this->assertSame(1, substr_count(implode("\n", WP_CLI::$logs), $secret));
        $this->assertStringNotContainsString('verifier', implode("\n", WP_CLI::$logs));

        WP_CLI::$logs = array();
        $commands->pricing_input_credential_status();
        $status_output = implode("\n", WP_CLI::$logs);
        $this->assertStringNotContainsString($secret, $status_output);
        $this->assertStringNotContainsString('verifier', $status_output);

        WP_CLI::$logs = array();
        $commands->pricing_input_credential_rotate();
        $rotated = WP_CLI::$logs[0];
        $this->assertNotSame($secret, $rotated);
        $this->assertSame(1, substr_count(implode("\n", WP_CLI::$logs), $rotated));
        $this->assertInstanceOf(
            WP_Error::class,
            $this->credential->authorize(
                $this->request('GET', '/digitalogic/v1/integration/catalog', $secret)
            )
        );

        WP_CLI::$logs = array();
        $commands->pricing_input_credential_revoke();
        $this->assertStringNotContainsString($rotated, implode("\n", WP_CLI::$logs));
        $this->assertStringNotContainsString('verifier', implode("\n", WP_CLI::$logs));

        $this->assertArrayHasKey('digitalogic pricing-input-credential create', WP_CLI::$commands);
        $this->assertArrayHasKey('digitalogic pricing-input-credential rotate', WP_CLI::$commands);
        $this->assertArrayHasKey('digitalogic pricing-input-credential revoke', WP_CLI::$commands);
        $this->assertArrayHasKey('digitalogic pricing-input-credential status', WP_CLI::$commands);
    }

    private function createSecret(): string {
        $result = $this->credential->create();
        $this->assertFalse(is_wp_error($result));

        return $result['secret'];
    }

    private function request(string $method, string $route, ?string $secret = null): WP_REST_Request {
        $headers = array();
        if (null !== $secret) {
            $headers['Authorization'] = 'Bearer ' . $secret;
        }

        $request = new WP_REST_Request(array(), array(), $headers);
        $request->set_method($method);
        $request->set_route($route);

        return $request;
    }
}
