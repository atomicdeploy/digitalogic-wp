<?php

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Test-only local socket fixtures must exercise native WebSocket and Redis stream behavior.

final class WebSocketLifecycleTest extends TestCase {

    /** @var Digitalogic_Test_Redis_Client */
    private $redis;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['digitalogic_test_option_cache']         = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_meta_update_failures'] = array();
        $GLOBALS['digitalogic_test_meta_delete_failures'] = array();
        $GLOBALS['digitalogic_test_transaction_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
		$GLOBALS['digitalogic_test_scheduled_events']     = array();
		$GLOBALS['digitalogic_test_schedule_failure']     = false;
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        $_POST = array();
        WP_CLI::$errors = array();
        WP_CLI::$warnings = array();
        WP_CLI::$logs = array();

        $this->redis = new Digitalogic_Test_Redis_Client();
        $redis = $this->redis;
        $GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_client'] = static function() use ($redis) {
            return $redis;
        };
    }

    public function test_acf_validation_stays_on_native_admin_ajax(): void {
        $config = file_get_contents(dirname(__DIR__) . '/includes/websocket/class-websocket.php');
        $proxy = file_get_contents(dirname(__DIR__) . '/assets/js/ajax-proxy.js');

        $this->assertStringContainsString("'acf/validate_save_post'", $config);
        $this->assertStringContainsString("payload.action.indexOf('acf/') === 0", $proxy);
        $async = file_get_contents(dirname(__DIR__) . '/assets/js/currency-admin-async.js');
        $this->assertStringContainsString("digitalogic_currency_async_status", $async);
		$this->assertStringContainsString("active_only: '1'", $async);
		$this->assertStringContainsString('Number(config.terminalTimeoutMs) || 180000', $async);
		$this->assertStringContainsString("controller.abort()", $async);
		$this->assertStringContainsString("document.readyState === 'loading'", $async);
		$this->assertStringContainsString("document.addEventListener('DOMContentLoaded', initialize, {once: true})", $async);
		$this->assertStringContainsString('initialize();', $async);
		$this->assertStringNotContainsString('preventDefault', $async);
		$this->assertStringNotContainsString('stopImmediatePropagation', $async);
		$this->assertStringNotContainsString('.value =', $async);
    }

    public function test_latest_event_id_uses_queue_and_durable_sequence(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] = array(
            array('id' => 120),
            array('id' => '450'),
            array('name' => 'missing.id'),
            'invalid event',
            array('id' => 310),
        );
        $GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 700;

        $this->assertSame(700, Digitalogic_Panel::get_latest_event_id());
        $this->assertNotEmpty($GLOBALS['digitalogic_test_cache_deletes']);
    }

    public function test_latest_event_id_defaults_to_zero_for_invalid_storage(): void {
        $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] = 'invalid';

        $this->assertSame(0, Digitalogic_Panel::get_latest_event_id());
    }

    public function test_redis_config_uses_existing_delivery_defaults(): void {
        $this->assertSame(array(
            'host' => '127.0.0.1',
            'port' => 6379,
            'timeout' => 0.2,
            'password' => '',
            'database' => null,
            'channel' => 'digitalogic_panel_events',
        ), Digitalogic_Panel::get_redis_config());
    }

    public function test_redis_config_normalizes_filtered_server_settings(): void {
        $GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_config'] = static function() {
            return array(
                'host' => ' redis.internal ',
                'port' => '6380',
                'timeout' => '1.5',
                'password' => 'server-secret',
                'database' => '4',
                'channel' => ' panel.events ',
            );
        };

        $this->assertSame(array(
            'host' => 'redis.internal',
            'port' => 6380,
            'timeout' => 1.5,
            'password' => 'server-secret',
            'database' => 4,
            'channel' => 'panel.events',
        ), Digitalogic_Panel::get_redis_config());
    }

	public function test_pricing_service_auth_requires_exact_configured_scope_and_never_generates_a_secret(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ] = 'receiver-secret';
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array(
			array(
				'id'      => 'patris-office',
				'dataset' => 'kala.db',
			),
		);
		$headers = array(
			'X-Patris-Product-Sync-Secret' => 'receiver-secret',
			'X-Patris-Source-Id'           => 'patris-office',
			'X-Patris-Source-Dataset'      => 'kala.db',
		);

		$context = Digitalogic_WebSocket_Auth::authenticate_context($headers, array());

		$this->assertTrue($context['authenticated']);
		$this->assertSame(0, $context['user_id']);
		$this->assertSame('patris_pricing', $context['principal']);
		$this->assertSame(
			array( 'id' => 'patris-office', 'dataset' => 'kala.db' ),
			$context['source']
		);
		$this->assertTrue(Digitalogic_WebSocket_Auth::pricing_service_context_is_current($context));

		$wrong_source                            = $headers;
		$wrong_source['X-Patris-Source-Dataset'] = 'other.db';
		$this->assertFalse(
			Digitalogic_WebSocket_Auth::authenticate_context($wrong_source, array())['authenticated']
		);

		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ] = 'rotated-secret';
		unset($GLOBALS['digitalogic_test_option_cache'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ]);
		$this->assertFalse(Digitalogic_WebSocket_Auth::pricing_service_context_is_current($context));
		$headers['X-Patris-Product-Sync-Secret'] = 'rotated-secret';
		$this->assertTrue(Digitalogic_WebSocket_Auth::authenticate_context($headers, array())['authenticated']);

		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array();
		$this->assertFalse(Digitalogic_WebSocket_Auth::authenticate_context($headers, array())['authenticated']);

		unset($GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ]);
		$this->assertFalse(Digitalogic_WebSocket_Auth::authenticate_context($headers, array())['authenticated']);
		$this->assertArrayNotHasKey(
			Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION,
			$GLOBALS['digitalogic_test_options']
		);
	}

	public function test_pricing_service_handshake_requires_subprotocol_and_reports_cursor_gap(): void {
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ( false === $pair ) {
			$this->markTestSkipped('Unix socket pairs are unavailable on this platform.');
		}
		$this->assertIsArray($pair);
		stream_set_timeout($pair[1], 1);
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ] = 'receiver-secret';
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array(
			array( 'id' => 'patris-office', 'dataset' => 'kala.db' ),
		);
		$source        = array( 'id' => 'patris-office', 'dataset' => 'kala.db' );
		$pricing_event = $this->pricing_state_event(101, $source);
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events']         = array(
			$pricing_event,
			array(
				'id'    => 102,
				'event' => 'product_updated',
				'name'  => 'product.updated',
				'data'  => array( 'id' => 55 ),
				'time'  => '2026-08-23 01:00:01',
			),
		);
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 102;

		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'        => $pair[0],
				'handshake'     => false,
				'headers'       => '',
				'buffer'        => '',
				'user_id'       => 0,
				'principal'     => '',
				'source'        => array(),
				'device_id'     => '',
				'last_event_id' => 102,
			),
		));
		$request = "GET /wordpress-ws HTTP/1.1\r\n"
			. "Host: digitalogic.test\r\n"
			. "Upgrade: websocket\r\n"
			. "Connection: Upgrade\r\n"
			. "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
			. "Sec-WebSocket-Protocol: digitalogic.pricing.v1\r\n"
			. "Last-Event-ID: 1\r\n"
			. "X-Patris-Product-Sync-Secret: receiver-secret\r\n"
			. "X-Patris-Source-Id: patris-office\r\n"
			. "X-Patris-Source-Dataset: kala.db\r\n\r\n";
		fwrite($pair[1], $request);

		$this->invoke_private($server, 'handshake', array( 42 ));
		$raw   = fread($pair[1], 16384);
		$parts = explode("\r\n\r\n", $raw, 2);
		$this->assertStringContainsString('101 Switching Protocols', $parts[0]);
		$this->assertStringContainsString('Sec-WebSocket-Protocol: digitalogic.pricing.v1', $parts[0]);
		$connected = $this->decode_websocket_frame($parts[1]);
		$this->assertSame('patris_pricing', $connected['data']['principal']);
		$this->assertSame(100, $connected['data']['cursor']);
		$this->assertSame(101, $connected['data']['oldest_event_id']);
		$this->assertSame(102, $connected['data']['latest_event_id']);
		$this->assertTrue($connected['data']['cursor_reset_required']);
		$this->assertTrue($connected['data']['revision_validation_required']);
		$client = $this->read_private($server, 'clients')[42];
		$this->assertSame('patris_pricing', $client['principal']);
		$this->assertSame($source, $client['source']);
		$this->assertSame('', $client['headers']);
		$this->assertSame(102, $client['last_event_id']);

		fclose($pair[0]);
		fclose($pair[1]);
	}

	public function test_pricing_service_handshake_rejects_missing_versioned_subprotocol(): void {
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ( false === $pair ) {
			$this->markTestSkipped('Unix socket pairs are unavailable on this platform.');
		}
		$this->assertIsArray($pair);
		stream_set_timeout($pair[1], 1);
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ] = 'receiver-secret';
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array(
			array( 'id' => 'patris-office', 'dataset' => 'kala.db' ),
		);
		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'        => $pair[0],
				'handshake'     => false,
				'headers'       => '',
				'buffer'        => '',
				'last_event_id' => 0,
			),
		));
		fwrite(
			$pair[1],
			"GET /wordpress-ws HTTP/1.1\r\n"
			. "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
			. "X-Patris-Product-Sync-Secret: receiver-secret\r\n"
			. "X-Patris-Source-Id: patris-office\r\n"
			. "X-Patris-Source-Dataset: kala.db\r\n\r\n"
		);

		$this->invoke_private($server, 'handshake', array( 42 ));

		$this->assertStringContainsString('403 Forbidden', fread($pair[1], 4096));
		$this->assertArrayNotHasKey(42, $this->read_private($server, 'clients'));
		fclose($pair[1]);
	}

	public function test_pricing_service_handshake_rejects_ambiguous_headers_and_invalid_resume_cursors(): void {
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ] = 'receiver-secret';
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array(
			array( 'id' => 'patris-office', 'dataset' => 'kala.db' ),
		);
		$scenarios = array(
			"X-Patris-Product-Sync-Secret: receiver-secret\r\nX-Patris-Product-Sync-Secret: receiver-secret\r\n",
			"X-Patris-Product-Sync-Secret: receiver-secret\r\nLast-Event-ID: not-a-number\r\n",
			"X-Patris-Product-Sync-Secret: receiver-secret\r\nLast-Event-ID: 99999999999999999999\r\n",
		);

		foreach ( $scenarios as $scenario ) {
			$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
			if ( false === $pair ) {
				$this->markTestSkipped('Unix socket pairs are unavailable on this platform.');
			}
			$this->assertIsArray($pair);
			stream_set_timeout($pair[1], 1);
			$server = new Digitalogic_WebSocket_Server();
			$this->write_private($server, 'clients', array(
				42 => array(
					'socket'        => $pair[0],
					'handshake'     => false,
					'headers'       => '',
					'buffer'        => '',
					'last_event_id' => 0,
				),
			));
			fwrite(
				$pair[1],
				"GET /wordpress-ws HTTP/1.1\r\n"
				. "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
				. "Sec-WebSocket-Protocol: digitalogic.pricing.v1\r\n"
				. $scenario
				. "X-Patris-Source-Id: patris-office\r\n"
				. "X-Patris-Source-Dataset: kala.db\r\n\r\n"
			);

			$this->invoke_private($server, 'handshake', array( 42 ));

			$this->assertStringContainsString('403 Forbidden', fread($pair[1], 4096));
			$this->assertArrayNotHasKey(42, $this->read_private($server, 'clients'));
			fclose($pair[1]);
		}
	}

	public function test_pricing_header_parser_marks_duplicates_and_cursor_validation_is_exact(): void {
		$server        = new Digitalogic_WebSocket_Server();
		list($headers) = $this->invoke_private(
			$server,
			'parse_request',
			array(
				"GET /wordpress-ws HTTP/1.1\r\n"
				. "X-Patris-Product-Sync-Secret: first\r\n"
				. "X-Patris-Product-Sync-Secret: second\r\n\r\n",
			)
		);

		$this->assertSame('second', $headers['x-patris-product-sync-secret']);
		$this->assertSame(
			array( 'x-patris-product-sync-secret' ),
			$headers['__digitalogic_duplicate_headers']
		);
		$this->assertTrue($this->invoke_private($server, 'valid_event_cursor', array( '0' )));
		$this->assertTrue($this->invoke_private($server, 'valid_event_cursor', array( '00042' )));
		$this->assertTrue($this->invoke_private($server, 'valid_event_cursor', array( (string) PHP_INT_MAX )));
		$this->assertFalse($this->invoke_private($server, 'valid_event_cursor', array( '-1' )));
		$this->assertFalse($this->invoke_private($server, 'valid_event_cursor', array( '42.0' )));
		$this->assertFalse($this->invoke_private($server, 'valid_event_cursor', array( '99999999999999999999' )));
	}

	public function test_pricing_service_json_ping_is_a_read_only_keepalive(): void {
		$socket = fopen('php://temp', 'w+b');
		$this->assertIsResource($socket);
		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'        => $socket,
				'handshake'     => true,
				'principal'     => 'patris_pricing',
				'last_event_id' => 0,
			),
		));

		$this->invoke_private(
			$server,
			'handle_message',
			array( 42, wp_json_encode(array( 'id' => 'keepalive-1', 'command' => 'ping' )) )
		);
		rewind($socket);
		$pong = $this->decode_websocket_frame(stream_get_contents($socket));

		$this->assertSame('keepalive-1', $pong['id']);
		$this->assertSame('pong', $pong['event']);
		$this->assertTrue($pong['success']);
		$this->assertArrayHasKey(42, $this->read_private($server, 'clients'));
		fclose($socket);
	}

    public function test_record_event_persists_and_publishes_the_exact_same_envelope(): void {
        $event = Digitalogic_Panel::record_event('product.updated', array('id' => 42));

        $this->assertIsArray($event);
        $this->assertSame($event, $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'][0]);
        $this->assertSame($event['id'], $GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence']);
        $this->assertCount(1, $this->redis->published);
        $this->assertSame('digitalogic_panel_events', $this->redis->published[0][0]);
        $this->assertSame($event, json_decode($this->redis->published[0][1], true));
        $this->assertSame(1, $GLOBALS['wpdb']->acquire_count);
        $this->assertSame(1, $GLOBALS['wpdb']->release_count);
    }

    public function test_every_panel_event_producer_uses_the_shared_durable_publisher(): void {
        $panel = (new ReflectionClass(Digitalogic_Panel::class))->newInstanceWithoutConstructor();

        $panel->record_product_event(101);
        $panel->record_user_event(202);
        $panel->record_option_event('yuan_price', '10', '11');
        Digitalogic_Panel::broadcast_panel_message(array('message' => 'Finished', 'level' => 'success'));

        $events = $GLOBALS['digitalogic_test_options']['digitalogic_panel_events'];
        $this->assertSame(
            array('product.updated', 'user.updated', 'currency.updated', 'panel.toast'),
            array_column($events, 'name')
        );
        $this->assertCount(4, $this->redis->published);

        foreach ($events as $index => $event) {
            $this->assertSame($event, json_decode($this->redis->published[$index][1], true));
        }
    }

    public function test_event_ids_are_strictly_monotonic_even_when_clock_is_behind(): void {
        $seed = (int) round(microtime(true) * 1000) + 10000;
        $GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = $seed;

        $first = Digitalogic_Panel::record_event('first.event');
        $second = Digitalogic_Panel::record_event('second.event');

        $this->assertSame($seed + 1, $first['id']);
        $this->assertSame($seed + 2, $second['id']);
    }

    public function test_event_is_not_written_without_the_database_lock(): void {
        $GLOBALS['wpdb']->acquire_result = 0;

        $event = Digitalogic_Panel::record_event('product.updated', array('id' => 1));

        $this->assertNull($event);
        $this->assertArrayNotHasKey('digitalogic_panel_events', $GLOBALS['digitalogic_test_options']);
        $this->assertCount(0, $this->redis->published);
        $this->assertStringContainsString('database event lock', $this->delivery_failure_messages()[0]);
    }

    public function test_queue_write_failure_skips_redis_but_reports_the_fallback_failure(): void {
        $GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_panel_events';

        $event = Digitalogic_Panel::record_event('product.updated', array('id' => 1));

        $this->assertNull($event);
        $this->assertCount(0, $this->redis->published);
        $messages = $this->delivery_failure_messages();
        $this->assertStringContainsString('queue could not be updated', end($messages));
    }

    public function test_publisher_reports_connection_auth_select_and_publish_failures(): void {
        $scenarios = array(
            array('connect_result', false, array(), 'connection failed'),
            array('auth_result', false, array('password' => 'secret'), 'authentication failed'),
            array('select_result', false, array('database' => 2), 'database selection failed'),
            array('publish_result', false, array(), 'publication failed'),
        );

        foreach ($scenarios as $index => $scenario) {
            $GLOBALS['digitalogic_test_actions'] = array();
            $client = new Digitalogic_Test_Redis_Client();
            $client->{$scenario[0]} = $scenario[1];
            $GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_client'] = static function() use ($client) {
                return $client;
            };
            $config_override = $scenario[2];
            $GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_config'] = static function() use ($config_override) {
                return $config_override;
            };

            $event = Digitalogic_Panel::record_event('test.failure.' . $index);

			$this->assertIsArray($event, 'The durable queue must survive Redis failure.');
            $messages = $this->delivery_failure_messages();
            $this->assertStringContainsString($scenario[3], strtolower(end($messages)));
        }
    }

	public function test_failed_redis_wakes_coalesce_into_one_durable_one_shot_retry(): void {
		$this->redis->publish_result = false;
		$first                       = Digitalogic_Panel::record_event_result( 'pricing.state.changed', array( 'revision' => 'first' ) );
		$second                      = Digitalogic_Panel::record_event_result( 'pricing.state.changed', array( 'revision' => 'second' ) );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertContains( 'panel_redis_delivery_failed', $first['delivery_warnings'] );
		$wake = $GLOBALS['digitalogic_test_options']['digitalogic_panel_event_wake_outbox_v1'];
		$this->assertSame( $second['event'], $wake['event'] );
		$scheduled = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_scheduled_events'],
				static function ( $event ) {
					return 'digitalogic_panel_event_wake_retry_v1' === $event['hook'];
				}
			)
		);
		$this->assertCount( 1, $scheduled );
		$this->assertSame( '', $scheduled[0]['recurrence'] );
		Digitalogic_Panel::deactivate_event_wake_retry();
		$this->assertArrayHasKey( 'digitalogic_panel_event_wake_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 0, array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static function ( $event ) {
				return 'digitalogic_panel_event_wake_retry_v1' === $event['hook'];
			}
		) );
		$this->assertTrue( Digitalogic_Panel::install_event_wake_retry() );
		$this->assertCount( 1, array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static function ( $event ) {
				return 'digitalogic_panel_event_wake_retry_v1' === $event['hook'];
			}
		) );

		$this->redis->publish_result = 0;
		Digitalogic_Panel::retry_event_wake_delivery();
		$this->assertArrayNotHasKey( 'digitalogic_panel_event_wake_outbox_v1', $GLOBALS['digitalogic_test_options'] );
		$this->assertCount( 0, array_filter(
			$GLOBALS['digitalogic_test_scheduled_events'],
			static function ( $event ) {
				return 'digitalogic_panel_event_wake_retry_v1' === $event['hook'];
			}
		) );
		$this->assertSame( $second['event'], json_decode( end( $this->redis->published )[1], true ) );
	}

	public function test_pricing_stream_has_no_recurring_state_or_queue_poll(): void {
		$server_source   = file_get_contents( dirname( __DIR__ ) . '/includes/websocket/class-websocket-server.php' );
		$panel_source    = file_get_contents( dirname( __DIR__ ) . '/includes/panel/class-panel.php' );
		$snapshot_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-digitalogic-pricing-snapshot.php' );
		$this->assertIsString( $server_source );
		$this->assertIsString( $panel_source );
		$this->assertIsString( $snapshot_source );
		$this->assertStringNotContainsString( 'maybe_send_missed_panel_events', $server_source );
		$this->assertStringNotContainsString( 'durable_replay_at', $server_source );
		$this->assertDoesNotMatchRegularExpression( '/wp_schedule_event\s*\(/', $panel_source . $snapshot_source );
		$this->assertMatchesRegularExpression( '/wp_schedule_single_event\s*\(/', $panel_source );
		$this->assertMatchesRegularExpression( '/wp_schedule_single_event\s*\(/', $snapshot_source );
		$run = array();
		$this->assertSame( 1, preg_match( '/public function run\([^\{]+\) \{([\s\S]+?)\n    private function accept/', $server_source, $run ) );
		$this->assertStringNotContainsString( 'send_missed_panel_events', $run[1] );
		$this->assertStringNotContainsString( 'get_events_since', $run[1] );
	}

    public function test_zero_subscribers_is_a_successful_publish_reply(): void {
        $this->redis->publish_result = 0;

        $event = Digitalogic_Panel::record_event('panel.toast', array('message' => 'No listeners yet'));

        $this->assertIsArray($event);
        $this->assertSame(array(), $this->delivery_failure_messages());
        $this->assertCount(1, $this->redis->published);
    }

    public function test_subscriber_validates_auth_select_and_subscribe_before_becoming_healthy(): void {
        $channel = 'panel.integration.events';
        list($port, $pid) = $this->start_redis_server(array(
            "+OK\r\n",
            "+OK\r\n",
            $this->subscribe_reply($channel),
        ));
        $this->set_subscriber_config($port, $channel, 'secret', 3);
        $server = new Digitalogic_WebSocket_Server();

        $this->invoke_private($server, 'connect_redis_subscriber');

        $this->assertIsResource($this->read_private($server, 'redis_socket'));
        $this->assertSame(0, $this->read_private($server, 'redis_next_connect_at'));
        $this->assertSame(array(), WP_CLI::$warnings);
        $logs = WP_CLI::$logs;
        $this->assertStringContainsString($channel, end($logs));

        $this->invoke_private($server, 'close_redis_subscriber');
        $this->wait_for_child($pid);
    }

    public function test_subscriber_rejects_bad_setup_and_retries_with_a_new_connection(): void {
        list($bad_port, $bad_pid) = $this->start_redis_server(array("-ERR invalid password\r\n"));
        $this->set_subscriber_config($bad_port, 'panel.retry.events', 'bad-secret', null);
        $server = new Digitalogic_WebSocket_Server();

        $this->invoke_private($server, 'connect_redis_subscriber');

        $this->assertNull($this->read_private($server, 'redis_socket'));
        $this->assertGreaterThan(microtime(true), $this->read_private($server, 'redis_next_connect_at'));
        $warnings = WP_CLI::$warnings;
        $this->assertStringContainsString('AUTH was rejected', end($warnings));
        $this->wait_for_child($bad_pid);

        $channel = 'panel.retry.events';
        list($good_port, $good_pid) = $this->start_redis_server(array($this->subscribe_reply($channel)));
        $this->set_subscriber_config($good_port, $channel, '', null);
        $this->write_private($server, 'redis_next_connect_at', 0);

        $this->invoke_private($server, 'maybe_connect_redis_subscriber');

        $this->assertIsResource($this->read_private($server, 'redis_socket'));
        $this->invoke_private($server, 'close_redis_subscriber');
        $this->wait_for_child($good_pid);
    }

    public function test_subscriber_rejects_an_invalid_subscribe_acknowledgement(): void {
        list($port, $pid) = $this->start_redis_server(array($this->subscribe_reply('wrong.channel')));
        $this->set_subscriber_config($port, 'expected.channel', '', null);
        $server = new Digitalogic_WebSocket_Server();

        $this->invoke_private($server, 'connect_redis_subscriber');

        $this->assertNull($this->read_private($server, 'redis_socket'));
        $warnings = WP_CLI::$warnings;
        $this->assertStringContainsString('SUBSCRIBE acknowledgement was invalid', end($warnings));
        $this->wait_for_child($pid);
    }

    public function test_subscriber_broadcasts_valid_durable_envelopes_and_suppresses_duplicates(): void {
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (false === $pair) {
            $this->markTestSkipped('Unix socket pairs are unavailable on this platform.');
        }
        $this->assertIsArray($pair);
        stream_set_timeout($pair[1], 1);

        $server = new Digitalogic_WebSocket_Server();
        $this->write_private($server, 'clients', array(
            42 => array(
                'socket' => $pair[0],
                'handshake' => true,
                'last_event_id' => 0,
            ),
        ));
        $event = array(
            'id' => 1700000000001,
            'event' => 'product_updated',
            'name' => 'product.updated',
            'data' => array('id' => 55),
            'time' => '2026-07-16 12:00:00',
        );
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] = array( $event );
        $reply = array('message', 'digitalogic_panel_events', wp_json_encode($event));

        $this->invoke_private($server, 'handle_redis_reply', array($reply));
        $payload = $this->decode_websocket_frame(fread($pair[1], 8192));

        $this->assertSame($event['id'], $payload['id']);
        $this->assertSame($event['name'], $payload['name']);
        $this->assertSame($event['data'], $payload['data']);
        $this->assertSame($event['time'], $payload['time']);

        stream_set_blocking($pair[1], false);
        $this->invoke_private($server, 'handle_redis_reply', array($reply));
        usleep(10000);
        $this->assertSame('', fread($pair[1], 8192));

        fclose($pair[0]);
        fclose($pair[1]);
    }

	public function test_pricing_service_redis_wakeup_drains_durable_events_in_id_order_and_rejects_commands(): void {
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ( false === $pair ) {
			$pair = @stream_socket_pair( STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		}
		if ( false === $pair ) {
			$this->markTestSkipped( 'Stream socket pairs are unavailable on this platform.' );
		}
		$this->assertIsArray($pair);
		stream_set_timeout($pair[1], 1);

		$source                = array( 'id' => 'patris-office', 'dataset' => 'kala.db' );
		$pricing_event         = $this->pricing_state_event(101, $source);
		$later_unrelated_event = array(
			'id'    => 102,
			'event' => 'product_updated',
			'name'  => 'product.updated',
			'data'  => array( 'id' => 55 ),
			'time'  => '2026-08-23 01:00:01',
		);
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SECRET_OPTION ] = 'receiver-secret';
		$GLOBALS['digitalogic_test_options'][ Digitalogic_Patris_Feed::PRODUCT_SYNC_SCOPES_OPTION ] = array( $source );
		$auth = Digitalogic_WebSocket_Auth::authenticate_context(
			array(
				'X-Patris-Product-Sync-Secret' => 'receiver-secret',
				'X-Patris-Source-Id'           => $source['id'],
				'X-Patris-Source-Dataset'      => $source['dataset'],
			),
			array()
		);
		$this->assertTrue( $auth['authenticated'] );

		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events'] = array(
			$pricing_event,
			$later_unrelated_event,
		);

		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'                 => $pair[0],
				'handshake'              => true,
				'last_event_id'          => 100,
				'user_id'                => 0,
				'device_id'              => '',
				'principal'              => $auth['principal'],
				'source'                 => $auth['source'],
				'credential_fingerprint' => $auth['credential_fingerprint'],
			),
		));

		$reply = array( 'message', 'digitalogic_panel_events', wp_json_encode($later_unrelated_event) );
		$this->invoke_private($server, 'handle_redis_reply', array( $reply ));
		$payload = $this->decode_websocket_frame(fread($pair[1], 8192));
		$this->assertSame(101, $payload['id']);
		$this->assertSame('pricing.state.changed', $payload['name']);
		$this->assertSame(102, $this->read_private($server, 'clients')[42]['last_event_id']);

		$this->invoke_private(
			$server,
			'handle_message',
			array( 42, wp_json_encode(array( 'id' => 'request-1', 'command' => 'digitalogic_product_update' )) )
		);
		$denied = $this->decode_websocket_frame(fread($pair[1], 8192));
		$this->assertFalse($denied['success']);
		$this->assertSame('digitalogic_pricing_stream_read_only', $denied['error']['code']);

		fclose($pair[0]);
		fclose($pair[1]);
	}


	public function test_pricing_terminal_replay_is_dotted_and_exact_source_only(): void {
		$pairs = array();
		for ( $index = 0; $index < 3; ++$index ) {
			$pair = @stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			if ( false === $pair ) {
				$pair = @stream_socket_pair( STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			}
			if ( false === $pair ) {
				$this->markTestSkipped( 'Stream socket pairs are unavailable on this platform.' );
			}
			stream_set_timeout( $pair[1], 1 );
			$pairs[] = $pair;
		}

		$source = array( 'id' => 'fixture-source', 'dataset' => 'fixture.db' );
		$event  = $this->pricing_terminal_event( 301, $source );
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events']         = array( $event );
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 301;
		$server = new Digitalogic_WebSocket_Server();
		$this->write_private(
			$server,
			'clients',
			array(
				41 => array(
					'socket'        => $pairs[0][0],
					'handshake'     => true,
					'last_event_id' => 300,
					'user_id'       => 0,
					'device_id'     => '',
					'principal'     => 'patris_pricing',
					'source'        => $source,
				),
				42 => array(
					'socket'        => $pairs[1][0],
					'handshake'     => true,
					'last_event_id' => 300,
					'user_id'       => 0,
					'device_id'     => '',
					'principal'     => 'patris_pricing',
					'source'        => array( 'id' => $source['id'], 'dataset' => 'other.db' ),
				),
				43 => array(
					'socket'        => $pairs[2][0],
					'handshake'     => true,
					'last_event_id' => 300,
					'user_id'       => 1,
					'device_id'     => '',
					'principal'     => 'wordpress_user',
					'source'        => array(),
				),
			)
		);

		$this->invoke_private( $server, 'send_missed_panel_events' );
		$payload = $this->decode_websocket_frame( fread( $pairs[0][1], 8192 ) );
		$this->assertSame( 'pricing.snapshot.build.terminal', $payload['event'] );
		$this->assertSame( 'pricing.snapshot.build.terminal', $payload['name'] );
		$this->assertSame( 301, $payload['id'] );
		$this->assertSame( $event['data'], $payload['data'] );

		stream_set_blocking( $pairs[1][1], false );
		stream_set_blocking( $pairs[2][1], false );
		$this->assertSame( '', fread( $pairs[1][1], 8192 ) );
		$this->assertSame( '', fread( $pairs[2][1], 8192 ) );
		$clients = $this->read_private( $server, 'clients' );
		$this->assertSame( 301, $clients[42]['last_event_id'] );
		$this->assertSame( 301, $clients[43]['last_event_id'] );

		foreach ( $pairs as $pair ) {
			fclose( $pair[0] );
			fclose( $pair[1] );
		}
	}

	public function test_connected_pricing_service_receives_explicit_reset_when_cursor_falls_outside_retention(): void {
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ( false === $pair ) {
			$this->markTestSkipped('Unix socket pairs are unavailable on this platform.');
		}
		$this->assertIsArray($pair);
		stream_set_timeout($pair[1], 1);
		$source = array( 'id' => 'patris-office', 'dataset' => 'kala.db' );
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events']         = array(
			$this->pricing_state_event(201, $source),
			array(
				'id'    => 202,
				'event' => 'product_updated',
				'name'  => 'product.updated',
				'data'  => array( 'id' => 55 ),
				'time'  => '2026-08-23 01:00:01',
			),
		);
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 202;
		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'        => $pair[0],
				'handshake'     => true,
				'last_event_id' => 100,
				'user_id'       => 0,
				'device_id'     => '',
				'principal'     => 'patris_pricing',
				'source'        => $source,
			),
		));

		$this->invoke_private($server, 'send_missed_panel_events');
		$reset = $this->decode_websocket_frame(fread($pair[1], 8192));

		$this->assertSame('pricing.stream.reset', $reset['event']);
		$this->assertSame('digitalogic.pricing-stream-reset/v1', $reset['data']['schema']);
		$this->assertSame(202, $reset['data']['cursor']);
		$this->assertSame(201, $reset['data']['oldest_event_id']);
		$this->assertTrue($reset['data']['revision_validation_required']);
		$this->assertSame(202, $this->read_private($server, 'clients')[42]['last_event_id']);

		fclose($pair[0]);
		fclose($pair[1]);
	}

	public function test_connected_pricing_service_resets_when_sequence_survives_but_retained_queue_is_empty(): void {
		$socket = fopen('php://temp', 'w+b');
		$this->assertIsResource($socket);
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_events']         = array();
		$GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 202;
		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'        => $socket,
				'handshake'     => true,
				'last_event_id' => 100,
				'user_id'       => 0,
				'device_id'     => '',
				'principal'     => 'patris_pricing',
				'source'        => array( 'id' => 'patris-office', 'dataset' => 'kala.db' ),
			),
		));

		$this->invoke_private($server, 'send_missed_panel_events');
		rewind($socket);
		$reset = $this->decode_websocket_frame(stream_get_contents($socket));

		$this->assertSame('pricing.stream.reset', $reset['event']);
		$this->assertSame('cursor_gap', $reset['data']['reason']);
		$this->assertSame(0, $reset['data']['oldest_event_id']);
		$this->assertSame(202, $reset['data']['latest_event_id']);
		$this->assertSame(202, $reset['data']['cursor']);
		$this->assertTrue($reset['data']['revision_validation_required']);
		$this->assertSame(202, $this->read_private($server, 'clients')[42]['last_event_id']);
		fclose($socket);
	}

	public function test_pricing_event_cursor_does_not_advance_when_nonblocking_frame_write_fails(): void {
		$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		if ( false === $pair ) {
			$this->markTestSkipped('Unix socket pairs are unavailable on this platform.');
		}
		$this->assertIsArray($pair);
		stream_set_blocking($pair[0], false);
		$chunk   = str_repeat('x', 65536);
		$blocked = false;
		for ( $attempt = 0; $attempt < 512; ++$attempt ) {
			$written = @fwrite($pair[0], $chunk);
			if ( false === $written || $written < strlen($chunk) ) {
				$blocked = true;
				break;
			}
		}
		if ( ! $blocked ) {
			fclose($pair[0]);
			fclose($pair[1]);
			$this->markTestSkipped('The socket buffer could not be saturated deterministically.');
		}

		$source = array( 'id' => 'patris-office', 'dataset' => 'kala.db' );
		$server = new Digitalogic_WebSocket_Server();
		$this->write_private($server, 'clients', array(
			42 => array(
				'socket'        => $pair[0],
				'handshake'     => true,
				'last_event_id' => 100,
				'user_id'       => 0,
				'device_id'     => '',
				'principal'     => 'patris_pricing',
				'source'        => $source,
			),
		));
		$event = $this->pricing_state_event(101, $source);

		$this->invoke_private($server, 'send_panel_event', array( 42, $event ));

		$this->assertArrayNotHasKey(42, $this->read_private($server, 'clients'));
		fclose($pair[1]);
	}

    public function test_websocket_cli_catches_engine_errors_without_terminating_phpunit(): void {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('The occupied-port server probe is not reliable on Windows.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($listener);
        $address = stream_socket_get_name($listener, false);
        $port = (int) substr(strrchr($address, ':'), 1);

        $commands = new Digitalogic_CLI_Commands();
        $commands->websocket_serve(array(), array('host' => '127.0.0.1', 'port' => $port));

        $this->assertNotEmpty(WP_CLI::$errors);
        fclose($listener);
    }

    public function test_browser_advances_polling_cursor_for_websocket_events(): void {
        $source = file_get_contents(dirname(__DIR__) . '/assets/js/panel-app.js');

        $this->assertStringContainsString(
            'lastEventId: Number(config.event_cursor || 0)',
            $source
        );
        $this->assertStringContainsString(
            'this.lastEventId = Math.max(this.lastEventId, Number(event.id || 0));',
            $source
        );
    }

    private function delivery_failure_messages(): array {
        $calls = $GLOBALS['digitalogic_test_actions']['digitalogic_panel_event_delivery_failed'] ?? array();
        return array_map(static function($args) {
            return (string) ($args[0] ?? '');
        }, $calls);
    }

    private function set_subscriber_config($port, $channel, $password, $database): void {
        $GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_config'] = static function() use ($port, $channel, $password, $database) {
            return array(
                'host' => '127.0.0.1',
                'port' => $port,
                'timeout' => 1.0,
                'password' => $password,
                'database' => $database,
                'channel' => $channel,
            );
        };
    }

    private function start_redis_server(array $replies): array {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the TCP Redis protocol test.');
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($listener, $errstr);
        $address = stream_socket_get_name($listener, false);
        $port = (int) substr(strrchr($address, ':'), 1);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $client = @stream_socket_accept($listener, 5);
            if (!is_resource($client)) {
                exit(2);
            }

            foreach ($replies as $reply) {
                self::read_redis_command($client);
                self::write_all($client, $reply);
            }

            usleep(100000);
            fclose($client);
            fclose($listener);
            exit(0);
        }

        fclose($listener);
        return array($port, $pid);
    }

    private static function read_redis_command($socket): void {
        $header = self::read_line($socket);
        if ($header === '' || $header[0] !== '*') {
            exit(3);
        }

        $parts = (int) substr($header, 1);
        for ($index = 0; $index < $parts; $index++) {
            $length_line = self::read_line($socket);
            if ($length_line === '' || $length_line[0] !== '$') {
                exit(4);
            }

            self::read_exact($socket, (int) substr($length_line, 1) + 2);
        }
    }

    private static function read_line($socket): string {
        $line = stream_get_line($socket, 8192, "\r\n");
        return is_string($line) ? $line : '';
    }

    private static function read_exact($socket, $length): string {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                exit(5);
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private static function write_all($socket, $payload): void {
        $written = 0;
        while ($written < strlen($payload)) {
            $result = fwrite($socket, substr($payload, $written));
            if ($result === false || $result === 0) {
                exit(6);
            }
            $written += $result;
        }
    }

    private function subscribe_reply($channel): string {
        return '*3' . "\r\n" . '$9' . "\r\nsubscribe\r\n" . '$' . strlen($channel) . "\r\n" . $channel . "\r\n:1\r\n";
    }

    private function wait_for_child($pid): void {
        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function invoke_private($object, $method, array $arguments = array()) {
        $reflection = new ReflectionMethod($object, $method);
        return $reflection->invokeArgs($object, $arguments);
    }

    private function read_private($object, $property) {
        $reflection = new ReflectionProperty($object, $property);
        return $reflection->getValue($object);
    }

    private function write_private($object, $property, $value): void {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    private function decode_websocket_frame($frame): array {
        $this->assertNotSame('', $frame);
        $length = ord($frame[1]) & 127;
        $offset = 2;

        if ($length === 126) {
            $length = unpack('n', substr($frame, $offset, 2))[1];
            $offset += 2;
        } elseif ($length === 127) {
            $parts = unpack('N2', substr($frame, $offset, 8));
            $length = ($parts[1] * 4294967296) + $parts[2];
            $offset += 8;
        }

        return json_decode(substr($frame, $offset, $length), true);
    }

	private function pricing_state_event( $id, array $source ): array {
		$state_revision = 'sha256:' . str_repeat('b', 64);

		return array(
			'id'    => (int) $id,
			'event' => 'pricing_state_changed',
			'name'  => 'pricing.state.changed',
			'data'  => array(
				'schema'                  => Digitalogic_Pricing_Snapshot::STATE_EVENT_SCHEMA,
				'schema_version'          => Digitalogic_Pricing_Snapshot::SCHEMA_VERSION,
				'projection'              => Digitalogic_Pricing_Snapshot::PROJECTION,
				'source'                  => $source + array( 'revision' => 'sha256:' . str_repeat('a', 64) ),
				'state_revision'          => $state_revision,
				'etag'                    => '"' . $state_revision . '"',
				'catalog_revision'        => 'sha256:' . str_repeat('c', 64),
				'pricing_state_revision'  => 'sha256:' . str_repeat('d', 64),
				'pricing_policy_revision' => 'sha256:' . str_repeat('e', 64),
				'cause'                   => 'projection-invalidated',
				'idempotency_key'         => 'sha256:' . str_repeat('f', 64),
				'revision_path'           => '/wp-json/digitalogic/pricing/sync/revision',
				'audience'                => array( 'services' => array( 'patris_pricing' ) ),
			),
			'time'  => '2026-08-23 01:00:00',
		);
	}

	private function pricing_terminal_event( $id, array $source ): array {
		$source['revision'] = 'sha256:' . str_repeat( 'a', 64 );
		$token              = 'snap_' . str_repeat( '1', 32 );
		$revision           = 'sha256:' . str_repeat( 'b', 64 );

		return array(
			'id'    => (int) $id,
			'event' => 'pricing_snapshot_build_terminal',
			'name'  => 'pricing.snapshot.build.terminal',
			'data'  => array(
				'schema'                 => Digitalogic_Pricing_Snapshot::TERMINAL_EVENT_SCHEMA,
				'schema_version'         => Digitalogic_Pricing_Snapshot::SCHEMA_VERSION,
				'projection'             => Digitalogic_Pricing_Snapshot::PROJECTION,
				'build_id'               => 'build_' . str_repeat( '2', 32 ),
				'request_id'             => 'sha256:' . str_repeat( '3', 64 ),
				'status'                 => 'ready',
				'source'                 => $source,
				'state_revision'         => 'sha256:' . str_repeat( '4', 64 ),
				'pricing_state_revision' => 'sha256:' . str_repeat( '5', 64 ),
				'catalog_revision'       => 'sha256:' . str_repeat( '6', 64 ),
				'snapshot_token'         => $token,
				'snapshot_revision'      => $revision,
				'digest'                 => $revision,
				'snapshot_path'          => '/wp-json/digitalogic/pricing/sync/snapshots/' . $token
					. '?source_id=' . rawurlencode( $source['id'] )
					. '&source_dataset=' . rawurlencode( $source['dataset'] )
					. '&source_revision=' . rawurlencode( $source['revision'] ),
				'retryable'              => false,
				'idempotency_key'        => 'sha256:' . str_repeat( '7', 64 ),
				'audience'               => array( 'services' => array( 'patris_pricing' ) ),
			),
			'time'  => '2026-08-23 01:00:00',
		);
	}
}
