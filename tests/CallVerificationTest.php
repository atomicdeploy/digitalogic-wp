<?php
// phpcs:ignoreFile

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/integrations/class-pbx-phone.php';
require_once dirname(__DIR__) . '/includes/integrations/class-call-verification.php';
require_once dirname(__DIR__) . '/includes/integrations/class-voice-notifications.php';
require_once __DIR__ . '/fixtures/ZeroSpamLogin.php';

final class CallVerificationTest extends TestCase {
    public function test_public_phone_normalizer_accepts_ir_mobile_and_full_landline_but_not_local_ani(): void {
        $this->assertSame('+989123456789', Digitalogic_PBX_Phone::normalize('09123456789'));
        $this->assertSame('+989123456789', Digitalogic_PBX_Phone::normalize('۹۸۹۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('+982112345678', Digitalogic_PBX_Phone::normalize('021-12345678'));
        $this->assertSame('', Digitalogic_PBX_Phone::normalize('12345678'));
        $this->assertSame('', Digitalogic_PBX_Phone::normalize('+982166754123'));
        $this->assertSame('909123456789', Digitalogic_PBX_Phone::to_pbx_target('+989123456789'));
        $this->assertSame('902112345678', Digitalogic_PBX_Phone::to_pbx_target('+982112345678'));
    }

    public function test_trusted_ani_supports_observed_tci_forms_without_accepting_routing_prefixes(): void {
        $this->assertSame('+982166754124', Digitalogic_PBX_Phone::normalize_trusted_ani('66754124'));
        $this->assertSame('+989123456789', Digitalogic_PBX_Phone::normalize_trusted_ani('09123456789'));
        $this->assertSame('+989123456789', Digitalogic_PBX_Phone::normalize_trusted_ani('989123456789'));
        $this->assertSame('+989123456789', Digitalogic_PBX_Phone::normalize_trusted_ani('0989123456789'));
        $this->assertSame('', Digitalogic_PBX_Phone::normalize_trusted_ani('909123456789'));
        $this->assertSame('', Digitalogic_PBX_Phone::normalize_trusted_ani('9989123456789'));
        $this->assertSame('', Digitalogic_PBX_Phone::normalize_trusted_ani('966754124'));
    }

    public function test_canonical_signature_input_is_byte_exact_and_stable(): void {
        $body = '{"schema":"phone-verification.v1","code":"381624"}';
        $canonical = Digitalogic_Call_Verification::pbx_canonical_string(
            '1784567890',
            'mF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM',
            $body
        );

        $this->assertSame(
            "v1\nPOST\n/wp-json/digitalogic/v1/call-verification/pbx-confirm\n1784567890\nmF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM\nd0b213b3b504adbc647efc51a154b4f0c5d8e6be13e05c73f79a488ef109f44c",
            $canonical
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', hash_hmac('sha256', $canonical, str_repeat("\x5a", 32)));
    }

    public function test_pending_probe_uses_a_distinct_path_bound_signature(): void {
        $body = '{"schema":"phone-verification-pending.v1"}';
        $canonical = Digitalogic_Call_Verification::pbx_canonical_string(
            '1784567890',
            'mF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM',
            $body,
            '/wp-json/digitalogic/v1/call-verification/pbx-pending'
        );

        $this->assertSame(
            "v1\nPOST\n/wp-json/digitalogic/v1/call-verification/pbx-pending\n1784567890\nmF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM\n" . hash('sha256', $body),
            $canonical
        );
        $this->assertNotSame(
            $canonical,
            Digitalogic_Call_Verification::pbx_canonical_string('1784567890', 'mF5QGmkQ9T0L8YzAF1QJHf1S2W8ERuHM', $body)
        );
    }

    public function test_browser_challenge_expires_after_exactly_120_seconds(): void {
        $ttl = new ReflectionClassConstant(Digitalogic_Call_Verification::class, 'CHALLENGE_TTL');
        $this->assertSame(120, $ttl->getValue());
    }

    public function test_verification_secret_requires_canonical_strict_base64_and_32_decoded_bytes(): void {
        $raw = str_repeat("\xff", 32);
        $encoded = base64_encode($raw);

        $this->assertSame($raw, Digitalogic_Call_Verification::decode_pbx_secret($encoded));
        $this->assertSame('', Digitalogic_Call_Verification::decode_pbx_secret(base64_encode('too short')));
        $this->assertSame('', Digitalogic_Call_Verification::decode_pbx_secret($encoded . "\n"));
        $this->assertSame('', Digitalogic_Call_Verification::decode_pbx_secret(strtr($encoded, '+/', '-_')));
        $this->assertSame('', Digitalogic_Call_Verification::decode_pbx_secret('%%%%'));
    }

    public function test_callback_success_is_true_only_for_an_actual_verified_match(): void {
        $this->assertSame(array('success' => true, 'verified' => true), Digitalogic_Call_Verification::pbx_result(true));
        $this->assertSame(array('success' => false, 'verified' => false), Digitalogic_Call_Verification::pbx_result(false));
    }

    public function test_callback_payload_contract_rejects_extra_keys_stale_events_and_wrong_did_or_ani(): void {
        $timestamp = 1784567890;
        $payload = $this->validPayload($timestamp);

        $valid = Digitalogic_Call_Verification::validate_pbx_payload($payload, $timestamp);
        $this->assertFalse(is_wp_error($valid));
        $this->assertSame('+989123456789', $valid['caller']);

        $extra = $payload;
        $extra['unexpected'] = true;
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_payload($extra, $timestamp)));

        $stale = $payload;
        $stale['occurred_at'] = gmdate('Y-m-d\TH:i:s.000\Z', $timestamp - 181);
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_payload($stale, $timestamp)));

        $wrongDid = $payload;
        $wrongDid['called_number'] = '+982191002369';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_payload($wrongDid, $timestamp)));

        $routedAni = $payload;
        $routedAni['caller_number'] = '909123456789';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_payload($routedAni, $timestamp)));

		$numericDid = $payload;
		$numericDid['called_number'] = 982166754123;
		$this->assertTrue( is_wp_error( Digitalogic_Call_Verification::validate_pbx_payload( $numericDid, $timestamp ) ) );

		$numericAni = $payload;
		$numericAni['caller_number'] = 989123456789;
		$this->assertTrue( is_wp_error( Digitalogic_Call_Verification::validate_pbx_payload( $numericAni, $timestamp ) ) );

        $badEvent = $payload;
        $badEvent['event_id'] = 'not-a-uuid';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_payload($badEvent, $timestamp)));
    }

    public function test_callback_accepts_normalized_did_and_tci_local_landline_ani(): void {
        $timestamp = 1784567890;
        $payload = $this->validPayload($timestamp);
        $payload['called_number'] = '02166754123';
        $payload['caller_number'] = '66754124';

        $valid = Digitalogic_Call_Verification::validate_pbx_payload($payload, $timestamp);
        $this->assertFalse(is_wp_error($valid));
        $this->assertSame('+982166754124', $valid['caller']);
    }

    public function test_pending_probe_envelope_is_exact_code_free_and_did_ani_bound(): void {
        $timestamp = 1784567890;
        $payload = $this->validPendingPayload($timestamp);
        $valid = Digitalogic_Call_Verification::validate_pbx_pending_payload($payload, $timestamp);

        $this->assertFalse(is_wp_error($valid));
        $this->assertSame('+989123456789', $valid['caller']);

        $withCode = $payload;
        $withCode['code'] = '381624';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_pending_payload($withCode, $timestamp)));

        $wrongSchema = $payload;
        $wrongSchema['schema'] = 'phone-verification.v1';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_pending_payload($wrongSchema, $timestamp)));

        $wrongDid = $payload;
        $wrongDid['called_number'] = '+982191002369';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_pending_payload($wrongDid, $timestamp)));

        $routedAni = $payload;
        $routedAni['caller_number'] = '909123456789';
        $this->assertTrue(is_wp_error(Digitalogic_Call_Verification::validate_pbx_pending_payload($routedAni, $timestamp)));
    }

    public function test_pending_probe_returns_only_a_boolean_without_pii(): void {
        $oldWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public function prepare($query, ...$args) { return $query; }
			public function query($query) { return 1; }
            public function get_var($query) { return 1; }
        };
        $timestamp = 1784567890;
        $request = new WP_REST_Request(
            array(),
            array(),
            array('x-pbx-timestamp' => (string) $timestamp),
            json_encode($this->validPendingPayload($timestamp))
        );

        try {
            $response = Digitalogic_Call_Verification::instance()->pbx_pending($request);
            $this->assertSame(200, $response->get_status());
            $this->assertSame(array('pending' => true), $response->get_data());

            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'wp_';
				public string $last_error = '';
                public function prepare($query, ...$args) { return $query; }
				public function query($query) { return 1; }
				public function get_var($query) {
					if ( false !== strpos( $query, 'verification_rates' ) ) {
						$this->last_error = '';
						return 1;
					}
					$this->last_error = 'simulated pending lookup failure';
					return null;
				}
            };
            $failure = Digitalogic_Call_Verification::instance()->pbx_pending($request);
            $this->assertSame(503, $failure->get_status());
            $this->assertSame(array('pending' => false), $failure->get_data());
        } finally {
            if (null === $oldWpdb) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $oldWpdb;
            }
        }
    }

	public function test_pending_probe_rate_limits_each_ani_and_fails_closed_when_rate_storage_fails(): void {
		$oldWpdb = $GLOBALS['wpdb'] ?? null;
		$fakeWpdb = new class {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public bool $fail_rate_write = false;
			public int $challenge_lookups = 0;
			public array $counters = array();
			public array $bucket_names = array();
			public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
			public function query( $prepared ) {
				$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
				if ( false === strpos( $query, 'verification_rates' ) ) {
					return 1;
				}
				if ( $this->fail_rate_write ) {
					$this->last_error = 'injected pending-probe rate write failure';
					return false;
				}
				$key = (string) $prepared['args'][0];
				$this->bucket_names[] = (string) $prepared['args'][1];
				$this->counters[ $key ] = ( $this->counters[ $key ] ?? 0 ) + 1;
				$this->last_error = '';
				return 1;
			}
			public function get_var( $prepared ) {
				$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
				if ( false !== strpos( $query, 'verification_rates' ) ) {
					$key = (string) $prepared['args'][0];
					return $this->counters[ $key ] ?? 0;
				}
				++$this->challenge_lookups;
				$this->last_error = '';
				return 1;
			}
		};
		$timestamp = 1784567890;
		$requestFor = function ( string $caller ) use ( $timestamp ): WP_REST_Request {
			$payload = $this->validPendingPayload( $timestamp );
			$payload['caller_number'] = $caller;
			return new WP_REST_Request(
				array(),
				array(),
				array( 'x-pbx-timestamp' => (string) $timestamp ),
				json_encode( $payload )
			);
		};

		try {
			$GLOBALS['wpdb'] = $fakeWpdb;
			$firstCaller = $requestFor( '+989123456789' );
			for ( $attempt = 0; $attempt < 20; ++$attempt ) {
				$response = Digitalogic_Call_Verification::instance()->pbx_pending( $firstCaller );
				$this->assertSame( 200, $response->get_status() );
				$this->assertSame( array( 'pending' => true ), $response->get_data() );
			}

			$limited = Digitalogic_Call_Verification::instance()->pbx_pending( $firstCaller );
			$this->assertSame( 429, $limited->get_status() );
			$this->assertSame( array( 'pending' => false ), $limited->get_data() );
			$this->assertGreaterThanOrEqual( 1, (int) $limited->get_headers()['Retry-After'] );
			$this->assertSame( 20, $fakeWpdb->challenge_lookups );

			$secondCaller = Digitalogic_Call_Verification::instance()->pbx_pending( $requestFor( '+989351234567' ) );
			$this->assertSame( 200, $secondCaller->get_status() );
			$this->assertSame( array( 'pending' => true ), $secondCaller->get_data() );
			$this->assertSame( 21, $fakeWpdb->challenge_lookups );

			$fakeWpdb->fail_rate_write = true;
			$storageFailure = Digitalogic_Call_Verification::instance()->pbx_pending( $requestFor( '+989197103488' ) );
			$this->assertSame( 503, $storageFailure->get_status() );
			$this->assertSame( array( 'pending' => false ), $storageFailure->get_data() );
			$this->assertSame( 21, $fakeWpdb->challenge_lookups );
			$this->assertSame( array( 'pbx-pending-60s' ), array_values( array_unique( $fakeWpdb->bucket_names ) ) );
		} finally {
			if ( null === $oldWpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $oldWpdb;
			}
		}
	}

    public function test_security_responses_receive_no_store_headers(): void {
        $request = (new WP_REST_Request())->set_route('/digitalogic/v1/call-verification/123');
        $response = new WP_REST_Response(array('code' => '123456'), 201);
        $instance = Digitalogic_Call_Verification::instance();

        $instance->add_no_store_headers($response, null, $request);

        $this->assertSame('no-store, no-cache, must-revalidate, private', $response->get_headers()['Cache-Control']);
        $this->assertSame('no-cache', $response->get_headers()['Pragma']);
    }

	public function test_status_polling_is_bound_rate_limited_at_eight_per_three_seconds_and_storage_failures_close(): void {
		$oldWpdb       = $GLOBALS['wpdb'] ?? null;
		$oldOptions    = $GLOBALS['digitalogic_test_options'];
		$oldCache      = $GLOBALS['digitalogic_test_option_cache'];
		$hadCookie     = array_key_exists( 'digitalogic_call_binding', $_COOKIE );
		$oldCookie     = $_COOKIE['digitalogic_call_binding'] ?? null;
		$binding       = str_repeat( 'b', 43 );
		$csrf          = str_repeat( 'c', 43 );
		$challengeId   = '123e4567-e89b-42d3-a456-426614174111';
		$lookupHash    = new ReflectionMethod( Digitalogic_Call_Verification::class, 'lookup_hash' );
		$row            = (object) array(
			'id'               => 81,
			'public_id'        => $challengeId,
			'binding_mac'      => $lookupHash->invoke( null, 'binding', $binding ),
			'csrf_mac'         => $lookupHash->invoke( null, 'csrf', $csrf ),
			'status'           => 'pending',
			'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + 120 ),
			'consume_deadline' => null,
		);
		$fakeWpdb = new class( $row ) {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public bool $fail_rate_write = false;
			public array $counters = array();
			public array $rate_keys = array();
			private object $row;
			public function __construct( object $row ) { $this->row = $row; }
			public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
			public function get_row( $prepared ) { return clone $this->row; }
			public function query( $prepared ) {
				$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
				if ( false === strpos( $query, 'verification_rates' ) ) {
					return 1;
				}
				if ( $this->fail_rate_write ) {
					$this->last_error = 'injected status rate write failure';
					return false;
				}
				$key = (string) $prepared['args'][0];
				$this->rate_keys[] = $key;
				$this->counters[ $key ] = ( $this->counters[ $key ] ?? 0 ) + 1;
				return 1;
			}
			public function get_var( $prepared ) {
				$key = (string) $prepared['args'][0];
				return $this->counters[ $key ] ?? 0;
			}
		};

		try {
			$GLOBALS['wpdb'] = $fakeWpdb;
			$GLOBALS['digitalogic_test_options']['digitalogic_pbx_schema_version'] = '3';
			$GLOBALS['digitalogic_test_option_cache']['digitalogic_pbx_schema_version'] = '3';
			$_COOKIE['digitalogic_call_binding'] = $binding;
			$request = new WP_REST_Request(
				array( 'id' => $challengeId ),
				array(),
				array( 'x-digitalogic-csrf' => $csrf )
			);

			for ( $attempt = 0; $attempt < 8; ++$attempt ) {
				$response = Digitalogic_Call_Verification::instance()->challenge_status( $request );
				$this->assertInstanceOf( WP_REST_Response::class, $response );
				$this->assertSame( 200, $response->get_status() );
			}
			$limited = Digitalogic_Call_Verification::instance()->challenge_status( $request );
			$this->assertTrue( is_wp_error( $limited ) );
			$this->assertSame( 'digitalogic_call_rate', $limited->get_error_code() );
			$this->assertSame( 429, $limited->get_error_data()['status'] );
			$this->assertCount( 1, array_unique( $fakeWpdb->rate_keys ) );

			$fakeWpdb->fail_rate_write = true;
			$storageFailure = Digitalogic_Call_Verification::instance()->challenge_status( $request );
			$this->assertTrue( is_wp_error( $storageFailure ) );
			$this->assertSame( 'digitalogic_call_rate_storage', $storageFailure->get_error_code() );
			$this->assertSame( 503, $storageFailure->get_error_data()['status'] );

			$source = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-call-verification.php' );
			$this->assertStringContainsString( "self::lookup_hash( 'status', (string) \$row->binding_mac . '|' . (string) \$row->public_id )", $source );
			$this->assertStringContainsString( "'status-3s'", $source );
		} finally {
			$GLOBALS['digitalogic_test_options'] = $oldOptions;
			$GLOBALS['digitalogic_test_option_cache'] = $oldCache;
			if ( $hadCookie ) {
				$_COOKIE['digitalogic_call_binding'] = $oldCookie;
			} else {
				unset( $_COOKIE['digitalogic_call_binding'] );
			}
			if ( null === $oldWpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $oldWpdb;
			}
		}
	}

    public function test_exact_signed_pbx_callback_route_is_registered(): void {
        $GLOBALS['digitalogic_test_routes'] = array();
        Digitalogic_Call_Verification::instance()->register_routes();
        $matches = array_values(array_filter(
            $GLOBALS['digitalogic_test_routes'],
            static fn(array $route): bool => $route['namespace'] === 'digitalogic/v1'
                && $route['route'] === '/call-verification/pbx-confirm'
        ));

        $this->assertCount(1, $matches);
        $this->assertSame('POST', $matches[0]['args']['methods']);
        $this->assertSame('authorize_pbx', $matches[0]['args']['permission_callback'][1]);
    }

    public function test_exact_signed_pending_probe_route_is_registered(): void {
        $GLOBALS['digitalogic_test_routes'] = array();
        Digitalogic_Call_Verification::instance()->register_routes();
        $matches = array_values(array_filter(
            $GLOBALS['digitalogic_test_routes'],
            static fn(array $route): bool => $route['namespace'] === 'digitalogic/v1'
                && $route['route'] === '/call-verification/pbx-pending'
        ));

        $this->assertCount(1, $matches);
        $this->assertSame('POST', $matches[0]['args']['methods']);
        $this->assertSame('authorize_pbx_pending', $matches[0]['args']['permission_callback'][1]);
    }

	public function test_login_identity_queries_fail_closed_and_short_circuit_on_database_errors(): void {
		$oldWpdb = $GLOBALS['wpdb'] ?? null;
		$resolve = new ReflectionMethod( Digitalogic_Call_Verification::class, 'resolve_login_user' );
		$firstSourceFailure = new class {
			public string $prefix = 'wp_';
			public string $usermeta = 'wp_usermeta';
			public string $last_error = 'stale';
			public int $queries = 0;
			public function prepare( $query, ...$args ) { return $query; }
			public function get_col( $query ) {
				++$this->queries;
				$this->last_error = 'injected contact lookup failure';
				return array();
			}
		};
		$secondSourceFailure = new class {
			public string $prefix = 'wp_';
			public string $usermeta = 'wp_usermeta';
			public string $last_error = 'stale';
			public int $queries = 0;
			public function prepare( $query, ...$args ) { return $query; }
			public function get_col( $query ) {
				++$this->queries;
				$this->last_error = '';
				return array( 101 );
			}
			public function get_results( $query, $output = null ) {
				++$this->queries;
				$this->last_error = 'injected Digits lookup failure';
				return array();
			}
		};

		try {
			$GLOBALS['wpdb'] = $firstSourceFailure;
			$firstResult = $resolve->invoke( Digitalogic_Call_Verification::instance(), '+989123456789', str_repeat( 'a', 64 ) );
			$this->assertTrue( is_wp_error( $firstResult ) );
			$this->assertSame( 'digitalogic_call_storage', $firstResult->get_error_code() );
			$this->assertSame( 503, $firstResult->get_error_data()['status'] );
			$this->assertSame( 1, $firstSourceFailure->queries );

			$GLOBALS['wpdb'] = $secondSourceFailure;
			$secondResult = $resolve->invoke( Digitalogic_Call_Verification::instance(), '+989123456789', str_repeat( 'b', 64 ) );
			$this->assertTrue( is_wp_error( $secondResult ) );
			$this->assertSame( 'digitalogic_call_storage', $secondResult->get_error_code() );
			$this->assertSame( 503, $secondResult->get_error_data()['status'] );
			$this->assertSame( 2, $secondSourceFailure->queries );
		} finally {
			if ( null === $oldWpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $oldWpdb;
			}
		}
	}

	public function test_digits_login_identity_requires_a_complete_consistent_pair_matching_the_challenge(): void {
		$consistent = new ReflectionMethod( Digitalogic_Call_Verification::class, 'consistent_digits_user_ids' );
		$rows = array(
			array( 'user_id' => 11, 'meta_key' => 'digits_phone', 'meta_value' => '+989123456789' ),
			array( 'user_id' => 11, 'meta_key' => 'digits_phone_no', 'meta_value' => '09123456789' ),
			array( 'user_id' => 12, 'meta_key' => 'digits_phone', 'meta_value' => '+989123456789' ),
			array( 'user_id' => 13, 'meta_key' => 'digits_phone', 'meta_value' => '+989123456789' ),
			array( 'user_id' => 13, 'meta_key' => 'digits_phone_no', 'meta_value' => '09351234567' ),
			array( 'user_id' => 14, 'meta_key' => 'digits_phone', 'meta_value' => '+989123456789' ),
			array( 'user_id' => 14, 'meta_key' => 'digits_phone_no', 'meta_value' => '09123456789' ),
			array( 'user_id' => 14, 'meta_key' => 'digits_phone_no', 'meta_value' => 'not-a-phone' ),
			array( 'user_id' => 15, 'meta_key' => 'digits_phone', 'meta_value' => '9123456789' ),
			array( 'user_id' => 15, 'meta_key' => 'digits_phone_no', 'meta_value' => '9123456789' ),
			array( 'user_id' => 16, 'meta_key' => 'billing_phone', 'meta_value' => '09123456789' ),
		);

		$this->assertSame(
			array( 11, 15 ),
			$consistent->invoke( null, $rows, '+989123456789' )
		);
	}

	public function test_verified_login_suspends_only_zerospam_form_filter_and_restores_it(): void {
		$oldFilters = $GLOBALS['digitalogic_test_filters'] ?? array();
		$oldWpFilter = $GLOBALS['wp_filter'] ?? array();
		$zeroSpam = new \ZeroSpam\Modules\Login\Login();
		$accountPolicy = new class {
			public int $calls = 0;
			public function validate( $user, $password ) {
				++$this->calls;
				return $user;
			}
		};
		$unrelatedFormFilter = new class {
			public int $calls = 0;
			public function process_form( $user, $password ) {
				++$this->calls;
				return $user;
			}
		};

		try {
			remove_all_filters( 'wp_authenticate_user' );
			add_filter( 'wp_authenticate_user', array( $zeroSpam, 'process_form' ), 10, 2 );
			add_filter( 'wp_authenticate_user', array( $unrelatedFormFilter, 'process_form' ), 15, 2 );
			add_filter( 'wp_authenticate_user', array( $accountPolicy, 'validate' ), 20, 2 );
			$hook = new WP_Hook();
			$hook->callbacks = array(
				10 => array(
					'zerospam' => array(
						'function'      => array( $zeroSpam, 'process_form' ),
						'accepted_args' => 2,
					),
				),
				15 => array(
					'unrelated-form-filter' => array(
						'function'      => array( $unrelatedFormFilter, 'process_form' ),
						'accepted_args' => 2,
					),
				),
				20 => array(
					'account-policy' => array(
						'function'      => array( $accountPolicy, 'validate' ),
						'accepted_args' => 2,
					),
				),
			);
			$GLOBALS['wp_filter']['wp_authenticate_user'] = $hook;

			$authenticate = new ReflectionMethod( Digitalogic_Call_Verification::class, 'authenticate_verified_user' );
			$user = new WP_User( 52, 'verified-user' );
			$this->assertSame( $user, $authenticate->invoke( Digitalogic_Call_Verification::instance(), $user ) );
			$this->assertSame( 0, $zeroSpam->calls );
			$this->assertSame( 1, $unrelatedFormFilter->calls );
			$this->assertSame( 1, $accountPolicy->calls );

			$normalLogin = apply_filters( 'wp_authenticate_user', $user, '' );
			$this->assertTrue( is_wp_error( $normalLogin ) );
			$this->assertSame( 'failed_zerospam', $normalLogin->get_error_code() );
			$this->assertSame( 1, $zeroSpam->calls );
			$this->assertSame( 2, $unrelatedFormFilter->calls );
			$this->assertSame( 2, $accountPolicy->calls );
		} finally {
			$GLOBALS['digitalogic_test_filters'] = $oldFilters;
			$GLOBALS['wp_filter'] = $oldWpFilter;
		}
	}

	public function test_verified_login_propagates_other_policy_failures_and_restores_zerospam_on_throw(): void {
		$oldFilters = $GLOBALS['digitalogic_test_filters'] ?? array();
		$oldWpFilter = $GLOBALS['wp_filter'] ?? array();
		$zeroSpam = new \ZeroSpam\Modules\Login\Login();
		$authenticate = new ReflectionMethod( Digitalogic_Call_Verification::class, 'authenticate_verified_user' );
		$user = new WP_User( 53, 'policy-user' );
		$policyError = new class {
			public function validate( $user, $password ) {
				return new WP_Error( 'account_suspended', 'account policy denied access' );
			}
		};
		$throwingPolicy = new class {
			public function validate( $user, $password ) {
				throw new RuntimeException( 'injected account policy failure' );
			}
		};

		try {
			remove_all_filters( 'wp_authenticate_user' );
			add_filter( 'wp_authenticate_user', array( $zeroSpam, 'process_form' ), 10, 2 );
			add_filter( 'wp_authenticate_user', array( $policyError, 'validate' ), 20, 2 );
			$hook = new WP_Hook();
			$hook->callbacks = array(
				10 => array( 'zerospam' => array( 'function' => array( $zeroSpam, 'process_form' ), 'accepted_args' => 2 ) ),
				20 => array( 'policy' => array( 'function' => array( $policyError, 'validate' ), 'accepted_args' => 2 ) ),
			);
			$GLOBALS['wp_filter']['wp_authenticate_user'] = $hook;

			$result = $authenticate->invoke( Digitalogic_Call_Verification::instance(), $user );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'account_suspended', $result->get_error_code() );
			$this->assertSame( 0, $zeroSpam->calls );

			remove_all_filters( 'wp_authenticate_user' );
			add_filter( 'wp_authenticate_user', array( $zeroSpam, 'process_form' ), 10, 2 );
			add_filter( 'wp_authenticate_user', array( $throwingPolicy, 'validate' ), 20, 2 );
			$hook = new WP_Hook();
			$hook->callbacks = array(
				10 => array( 'zerospam' => array( 'function' => array( $zeroSpam, 'process_form' ), 'accepted_args' => 2 ) ),
				20 => array( 'policy' => array( 'function' => array( $throwingPolicy, 'validate' ), 'accepted_args' => 2 ) ),
			);
			$GLOBALS['wp_filter']['wp_authenticate_user'] = $hook;

			try {
				$authenticate->invoke( Digitalogic_Call_Verification::instance(), $user );
				$this->fail( 'Expected the account policy exception.' );
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'injected account policy failure', $exception->getMessage() );
			}
			$callbacks = array_column( $GLOBALS['digitalogic_test_filters']['wp_authenticate_user'], 'callback' );
			$this->assertContains( array( $zeroSpam, 'process_form' ), $callbacks );
		} finally {
			$GLOBALS['digitalogic_test_filters'] = $oldFilters;
			$GLOBALS['wp_filter'] = $oldWpFilter;
		}
	}

	public function test_callback_and_contact_range_locks_fail_closed_on_database_errors(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-call-verification.php' );
		$callbackStart = strpos( $source, 'public function pbx_confirm' );
		$recoveryRead = strpos( $source, '$recovery_rows', $callbackStart );
		$recoveryCheck = strpos( $source, '! is_array( $recovery_rows )', $recoveryRead );
		$pendingRead = strpos( $source, '$pending_rows', $recoveryCheck );
		$pendingCheck = strpos( $source, '! is_array( $pending_rows )', $pendingRead );

		$this->assertIsInt( $callbackStart );
		$this->assertIsInt( $recoveryRead );
		$this->assertIsInt( $recoveryCheck );
		$this->assertIsInt( $pendingRead );
		$this->assertIsInt( $pendingCheck );
		$this->assertLessThan( $recoveryCheck, $recoveryRead );
		$this->assertLessThan( $pendingRead, $recoveryCheck );
		$this->assertLessThan( $pendingCheck, $pendingRead );
		$recoveryReadSource = substr( $source, $recoveryRead - 100, $recoveryCheck - $recoveryRead + 300 );
		$pendingReadSource = substr( $source, $recoveryCheck, $pendingCheck - $recoveryCheck + 200 );
		$callbackReads = $recoveryReadSource . $pendingReadSource;
		$this->assertStringContainsString( "\$wpdb->last_error = '';", $recoveryReadSource );
		$this->assertStringContainsString( "\$wpdb->last_error = '';", $pendingReadSource );
		$this->assertStringContainsString( "array( 'status' => 503 )", $callbackReads );

		foreach ( array(
			'public function rest_add_email',
			'public function save_admin_contacts',
			'private function upsert_verified_phone',
		) as $functionMarker ) {
			$start = strpos( $source, $functionMarker );
			$this->assertIsInt( $start );
			$functionSource = substr( $source, $start, 2500 );
			$this->assertStringContainsString( "'' !== (string) \$wpdb->last_error || ! is_array( \$locked", str_replace( '$contacts', '$locked', $functionSource ) );
		}
	}

    public function test_template_allowlist_and_explicit_local_callout_acknowledgement(): void {
        $this->assertSame(
            'سفارش {order_number} برای {first_name}',
            Digitalogic_Voice_Notifications::sanitize_template('سفارش {order_number} برای {first_name}')
        );
        $this->assertTrue(is_wp_error(Digitalogic_Voice_Notifications::sanitize_template('Order {customer_phone}')));
        $this->assertTrue(is_wp_error(Digitalogic_Voice_Notifications::sanitize_template('<b>Order</b>')));
        $this->assertTrue(Digitalogic_Voice_Notifications::callout_acknowledged(200, 'application/json; charset=utf-8', '{"ok":true}'));
        $this->assertFalse(Digitalogic_Voice_Notifications::callout_acknowledged(200, 'application/json', '{"ok":1}'));
        $this->assertFalse(Digitalogic_Voice_Notifications::callout_acknowledged(200, 'text/plain', '{"ok":true}'));
        $this->assertFalse(Digitalogic_Voice_Notifications::callout_acknowledged(202, 'application/json', '{"success":true}'));
        $this->assertFalse(Digitalogic_Voice_Notifications::callout_acknowledged(500, 'application/json', '{"ok":true}'));
        $this->assertFalse(Digitalogic_Voice_Notifications::callout_acknowledged(200, 'application/json', str_repeat('x', 16385)));

        $oversizedOrder = new class {
            public function get_billing_first_name(): string { return str_repeat('آ', 800); }
            public function get_order_number(): string { return '42'; }
        };
        $this->assertSame('', Digitalogic_Voice_Notifications::render_message('{first_name}', $oversizedOrder, 'completed'));
    }

    public function test_voice_consent_requires_the_exact_event_and_empty_preferences_deny_all(): void {
        $contact = (object) array(
            'kind' => 'phone',
            'status' => 'verified',
            'voice_opt_in' => 1,
            'admin_suppressed' => 0,
            'voice_events' => '[]',
        );
        $this->assertFalse(Digitalogic_Call_Verification::contact_allows_voice_event($contact, 'completed'));

        $contact->voice_events = '{"completed":1}';
        $this->assertTrue(Digitalogic_Call_Verification::contact_allows_voice_event($contact, 'completed'));
        $this->assertFalse(Digitalogic_Call_Verification::contact_allows_voice_event($contact, 'processing'));

		unset( $contact->admin_suppressed );
		$this->assertFalse( Digitalogic_Call_Verification::contact_allows_voice_event( $contact, 'completed' ) );
		$contact->admin_suppressed = 0;
		$contact->voice_opt_in = true;
		$this->assertFalse( Digitalogic_Call_Verification::contact_allows_voice_event( $contact, 'completed' ) );
		$contact->voice_opt_in = 1;

        $contact->admin_suppressed = 1;
        $this->assertFalse(Digitalogic_Call_Verification::contact_allows_voice_event($contact, 'completed'));
    }

    public function test_outbound_contract_has_zero_retries_and_configuration_is_admin_gated(): void {
        $payload = Digitalogic_Voice_Notifications::callout_payload('909123456789', 'پیام سفارش', 42);
        $this->assertSame(0, $payload['max_retries']);
        $this->assertSame('909123456789', $payload['to']);
        $this->assertSame('fa', $payload['language']);
        $this->assertFalse(Digitalogic_Voice_Notifications::callout_configuration_ready());
    }

    public function test_outbound_worker_claims_job_and_atomically_reserves_rate_before_network(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-voice-notifications.php');
        $claim = strpos($source, '$claimed = $wpdb->query');
        $reserve = strpos($source, '$rate_reservation = $this->reserve_rate');
		$attempt = strpos( $source, '$attempted = $wpdb->query' );
        $finalCheck = strpos($source, '$final_context = $this->live_job_context');
		$finalWindow = strpos( $source, '$final_next_allowed = self::next_allowed_time', $finalCheck );
        $network = strpos($source, '$response = wp_remote_post');

        $this->assertIsInt($claim);
        $this->assertIsInt($reserve);
		$this->assertIsInt( $attempt );
        $this->assertIsInt($finalCheck);
		$this->assertIsInt( $finalWindow );
        $this->assertIsInt($network);
        $this->assertLessThan($reserve, $claim);
		$this->assertLessThan( $finalCheck, $reserve );
		$this->assertLessThan( $finalCheck, $attempt );
		$this->assertLessThan( $finalWindow, $finalCheck );
		$this->assertLessThan( $network, $finalWindow );
		$this->assertLessThan( $network, $finalCheck );
        $this->assertStringContainsString( '$wpdb->query( \'START TRANSACTION\' )', $source );
        $this->assertStringContainsString('FOR UPDATE', $source);
		$this->assertStringContainsString( 'get_userdata( (int) $job->user_id )', $source );
        $this->assertStringContainsString("'limit_response_size' => 16384", $source);
		$this->assertMatchesRegularExpression( "/private const CALLOUT_URL\\s+= 'http:\/\/127\\.0\\.0\\.1:8789\/call';/", $source );
		$this->assertStringContainsString( '$settings = self::settings_from_database();', $source );
		$this->assertStringContainsString( "status_settings['version']", $source );
		$this->assertStringContainsString( 'wp_remote_retrieve_header', $source );
		$this->assertMatchesRegularExpression( '/MAX_RENDERED_BYTES\\s+= 1400/', $source );
		$this->assertStringContainsString( 'if ( $final_next_allowed > time() )', $source );
    }

    public function test_deleted_users_have_contact_and_job_cleanup_hooks(): void {
        Digitalogic_Call_Verification::instance();
        Digitalogic_Voice_Notifications::instance();
        $callbacks = $GLOBALS['digitalogic_test_action_callbacks']['deleted_user'] ?? array();
        $methods = array_map(static fn(array $hook) => $hook['callback'][1] ?? '', $callbacks);

        $this->assertContains('cleanup_deleted_user', $methods);
        $this->assertContains('cancel_deleted_user_jobs', $methods);
    }

    public function test_voice_scheduler_accepts_multiple_jobs_and_postpones_with_job_specific_args(): void {
        $schedule = new ReflectionMethod(Digitalogic_Voice_Notifications::class, 'schedule');
        $postpone = new ReflectionMethod(Digitalogic_Voice_Notifications::class, 'postpone_job');
        $oldWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['digitalogic_test_scheduled_events'] = array();
        $GLOBALS['digitalogic_test_schedule_failure'] = false;

        try {
            $this->assertTrue($schedule->invoke(null, 101, 1784567900));
            $this->assertTrue($schedule->invoke(null, 102, 1784567901));
            $this->assertTrue($schedule->invoke(null, 101, 1784567902));
            $events = array_values(array_filter(
                $GLOBALS['digitalogic_test_scheduled_events'],
                static fn(array $event): bool => $event['hook'] === 'digitalogic_voice_dispatch_job'
            ));
            $this->assertCount(2, $events);
            $this->assertSame(array(101), $events[0]['args']);
            $this->assertSame(array(102), $events[1]['args']);

            $fakeWpdb = new class {
                public string $prefix = 'wp_';
                public array $updates = array();
                public function update($table, $data, $where, $formats, $whereFormats) {
                    $this->updates[] = compact('table', 'data', 'where', 'formats', 'whereFormats');
                    return 1;
                }
            };
            $GLOBALS['wpdb'] = $fakeWpdb;
            $GLOBALS['digitalogic_test_scheduled_events'] = array();
            $postpone->invoke(Digitalogic_Voice_Notifications::instance(), 303, 1784567999, 'processing');
            $this->assertSame('pending', $fakeWpdb->updates[0]['data']['status']);
            $this->assertSame('processing', $fakeWpdb->updates[0]['where']['status']);
            $this->assertSame(array(303), $GLOBALS['digitalogic_test_scheduled_events'][0]['args']);

            $GLOBALS['digitalogic_test_scheduled_events'] = array();
            $GLOBALS['digitalogic_test_schedule_failure'] = true;
            $this->assertFalse($schedule->invoke(null, 404, 1784568000));
        } finally {
            $GLOBALS['digitalogic_test_schedule_failure'] = false;
            $GLOBALS['digitalogic_test_scheduled_events'] = array();
            if (null === $oldWpdb) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $oldWpdb;
            }
        }

        $source = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-voice-notifications.php');
        $this->assertStringContainsString('self::ACTION_GROUP, false', $source);
        $this->assertStringContainsString('reconcile_pending_jobs', $source);
        $this->assertStringContainsString('scheduler deferred to reconciliation', $source);
    }

    public function test_schema_and_recovery_schedules_are_hard_feature_gates(): void {
        $callSource = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-call-verification.php');
        $voiceSource = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-voice-notifications.php');
        $entrySource = file_get_contents(dirname(__DIR__) . '/digitalogic.php');

        $this->assertStringContainsString('ENGINE=InnoDB', $callSource);
        $this->assertStringContainsString('SHOW TABLE STATUS', $callSource);
        $this->assertStringContainsString('SHOW COLUMNS FROM', $callSource);
        $this->assertStringContainsString('SHOW INDEX FROM', $callSource);
        $this->assertStringContainsString('null === $active_value', $callSource);
        $this->assertStringContainsString('self::is_schema_ready() &&', $callSource);
        $this->assertStringContainsString('ENGINE=InnoDB', $voiceSource);
        $this->assertStringContainsString('ensure_reconciliation_schedules', $voiceSource);
        $this->assertStringContainsString('RECONCILE_WATCHDOG_ACTION', $voiceSource);
        $this->assertStringContainsString('if ( ! self::schema_ready() )', $voiceSource);
        $this->assertStringContainsString('$call_ready && $voice_ready', $entrySource);
        $this->assertStringContainsString('mark_schema_unready', $entrySource);
    }

    public function test_failed_policy_write_leaves_direct_database_kill_switch_off_even_if_cache_is_stale(): void {
        $oldWpdb = $GLOBALS['wpdb'] ?? null;
        $oldOptions = $GLOBALS['digitalogic_test_options'];
        $oldCache = $GLOBALS['digitalogic_test_option_cache'];
        $oldFailures = $GLOBALS['digitalogic_test_update_failures'];
        $defaults = (new ReflectionMethod(Digitalogic_Voice_Notifications::class, 'defaults'))->invoke(null);
        $desired = $defaults;
        $desired['hourly_limit'] = 3;
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $GLOBALS['digitalogic_test_options'] = array(
            'digitalogic_voice_notifications_enabled' => '1',
            'digitalogic_voice_notification_settings' => $defaults,
        );
        $GLOBALS['digitalogic_test_option_cache'] = $GLOBALS['digitalogic_test_options'];
        $GLOBALS['digitalogic_test_update_failures'] = array('digitalogic_voice_notification_settings');

        try {
            $persist = new ReflectionMethod(Digitalogic_Voice_Notifications::class, 'persist_voice_policy');
            $this->assertFalse($persist->invoke(null, $desired, false));
            $this->assertSame('0', $GLOBALS['digitalogic_test_options']['digitalogic_voice_notifications_enabled']);

            $GLOBALS['digitalogic_test_option_cache']['digitalogic_voice_notifications_enabled'] = '1';
            $readGlobal = new ReflectionMethod(Digitalogic_Voice_Notifications::class, 'global_enabled_from_database');
            $this->assertFalse($readGlobal->invoke(null));
            $this->assertSame('1', get_option('digitalogic_voice_notifications_enabled'));
        } finally {
            $GLOBALS['digitalogic_test_options'] = $oldOptions;
            $GLOBALS['digitalogic_test_option_cache'] = $oldCache;
            $GLOBALS['digitalogic_test_update_failures'] = $oldFailures;
            if (null === $oldWpdb) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $oldWpdb;
            }
        }
    }

    public function test_consent_writes_and_pbx_attempt_limits_use_transactions_and_audit(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-call-verification.php');
        $reserve = strpos($source, 'reserve_pbx_attempt_capacity( $phone_hash )');
        $eventLock = strpos($source, "SELECT call_hash,result_json FROM ' . self::table( 'pbx_events' ) . ' WHERE event_id = %s FOR UPDATE");
        $matchQuery = strpos($source, "SELECT id, public_id, phone_encrypted, code_mac, status, verified_call_hash, version");

        $this->assertIsInt($reserve);
        $this->assertIsInt($eventLock);
        $this->assertIsInt($matchQuery);
		$this->assertLessThan( $reserve, $eventLock );
        $this->assertLessThan($matchQuery, $reserve);
        $this->assertStringContainsString('SELECT counter FROM', $source);
        $this->assertStringContainsString('FOR UPDATE', $source);
        $this->assertStringContainsString("self::table( 'contact_consent_audit' )", $source);
        $this->assertStringContainsString('customer self-service', $source);
        $this->assertStringContainsString('A reason is required when an administrator enables or expands voice-call consent.', $source);
        $this->assertStringContainsString('consent_expands', $source);
        $this->assertStringContainsString('MAX_CONTACTS_PER_KIND = 10', $source);
		$this->assertStringNotContainsString("'digits_phone_no','billing_phone'", $source);
		$this->assertStringContainsString("meta_key IN ('digits_phone','digits_phone_no')", $source);
		$this->assertStringContainsString( 'wp_set_auth_cookie( $user->ID, false, is_ssl() )', $source );
		$this->assertStringContainsString("'digitalogic_call_rate' === \$reservation->get_error_code()", $source);
		$challengeUpdate = strpos( $source, '$updated = $wpdb->query' );
		$challengeWriteError = strpos( $source, 'if ( false === $updated )', $challengeUpdate );
		$this->assertIsInt( $challengeUpdate );
		$this->assertIsInt( $challengeWriteError );
		$this->assertLessThan( $challengeWriteError, $challengeUpdate );
		$challengeErrorSource = substr( $source, $challengeWriteError, 400 );
		$this->assertStringContainsString( '$wpdb->query( \'ROLLBACK\' )', $challengeErrorSource );
		$this->assertStringContainsString( "array( 'status' => 503 )", $challengeErrorSource );
		$nonceInsert = strpos( $source, "'INSERT IGNORE INTO ' . self::table( 'pbx_nonces' )" );
		$nonceStorageError = strpos( $source, 'if ( false === $inserted )', $nonceInsert );
		$nonceReplay = strpos( $source, 'if ( 0 === (int) $inserted )', $nonceStorageError );
		$this->assertIsInt( $nonceInsert );
		$this->assertIsInt( $nonceStorageError );
		$this->assertIsInt( $nonceReplay );
		$this->assertLessThan( $nonceStorageError, $nonceInsert );
		$this->assertLessThan( $nonceReplay, $nonceStorageError );
		$nonceResultSource = substr( $source, $nonceStorageError, 800 );
		$this->assertStringContainsString( "array( 'status' => 503 )", $nonceResultSource );
		$this->assertStringContainsString( "array( 'status' => 409 )", $nonceResultSource );

		$reserveStart = strpos( $source, 'private function reserve_pbx_attempt_capacity' );
		$reserveEnd = strpos( $source, 'private function rate_hit', $reserveStart );
		$this->assertIsInt( $reserveStart );
		$this->assertIsInt( $reserveEnd );
		$reserveSource = substr( $source, $reserveStart, $reserveEnd - $reserveStart );
		$this->assertStringNotContainsString( "START TRANSACTION", $reserveSource );
		$this->assertStringNotContainsString( "COMMIT", $reserveSource );
		$this->assertStringNotContainsString( "ROLLBACK", $reserveSource );
    }

    public function test_event_result_write_failure_is_retryable_and_completed_retry_is_idempotent(): void {
        $oldWpdb = $GLOBALS['wpdb'] ?? null;
        $fakeWpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';
            public bool $failWrite = true;
            public string $stored = '{"processing":true}';
            public function prepare($query, ...$args) { return array('query' => $query, 'args' => $args); }
            public function query($prepared) {
                if ($this->failWrite) { return false; }
                if ($this->stored !== '{"processing":true}') { return 0; }
                $this->stored = (string) $prepared['args'][0];
                return 1;
            }
            public function get_var($prepared) { return $this->stored; }
        };
        $GLOBALS['wpdb'] = $fakeWpdb;
        $complete = new ReflectionMethod(Digitalogic_Call_Verification::class, 'complete_pbx_event');
        $result = array('success' => true, 'verified' => true);

        try {
            $this->assertFalse($complete->invoke(Digitalogic_Call_Verification::instance(), 'event-1', $result));
            $this->assertSame('{"processing":true}', $fakeWpdb->stored);
            $fakeWpdb->failWrite = false;
            $this->assertTrue($complete->invoke(Digitalogic_Call_Verification::instance(), 'event-1', $result));
            $this->assertSame('{"success":true,"verified":true}', $fakeWpdb->stored);
            $this->assertTrue($complete->invoke(Digitalogic_Call_Verification::instance(), 'event-1', $result));
        } finally {
            if (null === $oldWpdb) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $oldWpdb;
            }
        }

        $source = file_get_contents(dirname(__DIR__) . '/includes/integrations/class-call-verification.php');
        $this->assertStringContainsString("verified_call_hash = %s AND status IN ('verified','consumed')", $source);
        $this->assertStringContainsString('complete_pbx_event( $event_id, $result )', $source);
        $this->assertStringContainsString("'call-event'", $source);
    }

	public function test_guest_sidebar_reuses_one_configured_login_widget_without_exposing_secrets(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-call-verification.php' );
		$enqueue_start = strpos( $source, 'public function enqueue_assets' );
		$enqueue_end = strpos( $source, 'public function maybe_enqueue_account_assets', $enqueue_start );
		$this->assertIsInt( $enqueue_start );
		$this->assertIsInt( $enqueue_end );
		$localized_source = substr( $source, $enqueue_start, $enqueue_end - $enqueue_start );

		$this->assertStringContainsString(
			"add_action( 'wp_footer', array( \$this, 'render_sidebar_login_verification' ), 5 )",
			$source
		);
		$this->assertStringContainsString( '$is_guest_page   = ! is_admin() && ! is_user_logged_in();', $source );
		$this->assertStringContainsString( 'data-digitalogic-sidebar-call-parking hidden', $source );
		$this->assertStringContainsString( 'data-digitalogic-sidebar-call-widget hidden', $source );
		$this->assertStringContainsString( 'data-purpose="login"', $source );
		$this->assertStringContainsString( 'aria-expanded="false"', $source );
		$this->assertStringContainsString( 'aria-describedby="<?php echo esc_attr( $phone_help_id ); ?>"', $source );
		$this->assertStringContainsString( 'Place the call from the same phone number you entered so caller ID can confirm ownership.', $source );
		$this->assertStringContainsString( 'data-call-dial', $source );
		$this->assertStringContainsString( 'When the caller-ID shortcut says «دوست عزیزم»', $source );
		$this->assertStringNotContainsString( 'data-call-ivr', $source );
		$this->assertStringNotContainsString( 'self::IVR_OPTION', $source );
		$this->assertStringNotContainsString( 'DIGITALOGIC_PBX_VERIFY_SECRET', $localized_source );
	}

    private function validPayload(int $timestamp): array {
        return array(
            'schema' => 'phone-verification.v1',
            'site_id' => 'digitalogic.ir',
            'event_id' => '123e4567-e89b-42d3-a456-426614174000',
            'call_id' => '1721491200.42',
            'called_number' => '+982166754123',
            'caller_number' => '+989123456789',
            'code' => '381624',
            'occurred_at' => gmdate('Y-m-d\TH:i:s.000\Z', $timestamp),
        );
    }

    private function validPendingPayload(int $timestamp): array {
        return array(
            'schema' => 'phone-verification-pending.v1',
            'site_id' => 'digitalogic.ir',
            'event_id' => '123e4567-e89b-42d3-a456-426614174000',
            'call_id' => '1721491200.42',
            'called_number' => '+982166754123',
            'caller_number' => '+989123456789',
            'occurred_at' => gmdate('Y-m-d\TH:i:s.000\Z', $timestamp),
        );
    }
}
