<?php

use PHPUnit\Framework\TestCase;

final class WebSocketLifecycleTest extends TestCase {

    /** @var Digitalogic_Test_Redis_Client */
    private $redis;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_cache_deletes'] = array();
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
        WP_CLI::$errors = array();
        WP_CLI::$warnings = array();
        WP_CLI::$logs = array();

        $this->redis = new Digitalogic_Test_Redis_Client();
        $redis = $this->redis;
        $GLOBALS['digitalogic_test_filters']['digitalogic_panel_redis_client'] = static function() use ($redis) {
            return $redis;
        };
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

            $this->assertIsArray($event, 'The polling queue must survive Redis failure.');
            $messages = $this->delivery_failure_messages();
            $this->assertStringContainsString($scenario[3], strtolower(end($messages)));
        }
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
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
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

    public function test_websocket_cli_catches_engine_errors_without_terminating_phpunit(): void {
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
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    private function read_private($object, $property) {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }

    private function write_private($object, $property, $value): void {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
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
}
