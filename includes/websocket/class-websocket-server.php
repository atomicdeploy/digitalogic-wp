<?php
/**
 * Small WP-CLI WebSocket server for Digitalogic commands.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_WebSocket_Server {

    private $clients = array();
    private $redis_socket = null;
    private $redis_buffer = '';
    private $redis_next_connect_at = 0;
    private $redis_channel = 'digitalogic_panel_events';

    public function run($host = '127.0.0.1', $port = 8090) {
        $server = stream_socket_server('tcp://' . $host . ':' . $port, $errno, $errstr);
        if (!$server) {
            throw new RuntimeException($errstr, $errno);
        }

        stream_set_blocking($server, false);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::success('Digitalogic WebSocket server listening on ' . $host . ':' . $port);
        }

        $this->connect_redis_subscriber();

        while (true) {
            $this->maybe_connect_redis_subscriber();

            $read = array($server);
            if (is_resource($this->redis_socket)) {
                $read[] = $this->redis_socket;
            }
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }

            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $socket) {
                if (is_resource($this->redis_socket) && $socket === $this->redis_socket) {
                    $this->read_redis_events();
                    continue;
                }

                if ($socket === $server) {
                    $this->accept($server);
                    continue;
                }

                $id = intval($socket);
                if (!isset($this->clients[$id])) {
                    continue;
                }

                if (!$this->clients[$id]['handshake']) {
                    $this->handshake($id);
                } else {
                    $this->read($id);
                }
            }
        }
    }

    private function accept($server) {
        $socket = @stream_socket_accept($server, 0);
        if (!$socket) {
            return;
        }

        stream_set_blocking($socket, false);
        $this->clients[intval($socket)] = array(
            'socket' => $socket,
            'handshake' => false,
            'headers' => '',
            'buffer' => '',
            'user_id' => 0,
            'last_event_id' => class_exists('Digitalogic_Panel') ? Digitalogic_Panel::get_latest_event_id() : (int) round(microtime(true) * 1000),
        );
    }

    private function handshake($id) {
        $chunk = @fread($this->clients[$id]['socket'], 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->clients[$id]['socket'])) {
                $this->close($id);
            }
            return;
        }

        $this->clients[$id]['headers'] .= $chunk;
        if (strpos($this->clients[$id]['headers'], "\r\n\r\n") === false) {
            return;
        }

        list($headers, $query) = $this->parse_request($this->clients[$id]['headers']);
        $user_id = Digitalogic_WebSocket_Auth::authenticate($headers, $query);
        if (!$user_id || empty($headers['sec-websocket-key'])) {
            @fwrite($this->clients[$id]['socket'], "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
            $this->close($id);
            return;
        }

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . $accept . "\r\n\r\n";

        @fwrite($this->clients[$id]['socket'], $response);
        $this->clients[$id]['handshake'] = true;
        $this->clients[$id]['user_id'] = $user_id;
        $this->send_json($id, array(
            'event' => 'connected',
            'success' => true,
            'data' => array('user_id' => max(0, $user_id)),
        ));
        $this->send_missed_panel_events($id);
    }

    private function read($id) {
        $chunk = @fread($this->clients[$id]['socket'], 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->clients[$id]['socket'])) {
                $this->close($id);
            }
            return;
        }

        $this->clients[$id]['buffer'] .= $chunk;
        $frames = $this->decode_frames($this->clients[$id]['buffer']);
        $this->clients[$id]['buffer'] = $frames['buffer'];

        foreach ($frames['messages'] as $frame) {
            if ($frame['opcode'] === 8) {
                $this->close($id);
                return;
            }

            if ($frame['opcode'] === 9) {
                $this->send_frame($id, $frame['payload'], 10);
                continue;
            }

            if ($frame['opcode'] !== 1) {
                continue;
            }

            $this->handle_message($id, $frame['payload']);
        }
    }

    private function handle_message($id, $payload) {
        $request = json_decode($payload, true);
        if (!is_array($request)) {
            $this->send_error($id, null, 'invalid_json', __('Invalid JSON payload.', 'digitalogic'));
            return;
        }

        $request_id = isset($request['id']) ? sanitize_text_field((string) $request['id']) : null;
        $command = isset($request['command'])
            ? Digitalogic_Command_Dispatcher::normalize_command_name($request['command'])
            : (isset($request['action']) ? Digitalogic_Command_Dispatcher::normalize_command_name($request['action']) : '');
        $data = isset($request['data']) && is_array($request['data']) ? $request['data'] : array();

        if ($command === 'ping') {
            $this->send_json($id, array('id' => $request_id, 'event' => 'pong', 'success' => true));
            return;
        }

        wp_set_current_user(max(0, (int) $this->clients[$id]['user_id']));
        if ($command === 'digitalogic_panel_events' && class_exists('Digitalogic_Panel')) {
            if (!current_user_can('manage_woocommerce')) {
                $this->send_error($id, $request_id, 'digitalogic_unauthorized', __('Unauthorized', 'digitalogic'));
                return;
            }

            $since = isset($data['since']) ? absint($data['since']) : 0;
            $this->send_json($id, array(
                'id' => $request_id,
                'event' => 'response',
                'command' => $command,
                'success' => true,
                'data' => array(
                    'events' => Digitalogic_Panel::get_events_since($since),
                ),
            ));
            return;
        }

        $result = Digitalogic_Command_Dispatcher::instance()->execute($command, $data, 'websocket');
        if (is_wp_error($result)) {
            $this->send_error($id, $request_id, $result->get_error_code(), $result->get_error_message());
            return;
        }

        $this->send_json($id, array(
            'id' => $request_id,
            'event' => 'response',
            'command' => $command,
            'success' => true,
            'data' => $result,
        ));
    }

    private function send_error($id, $request_id, $code, $message) {
        $this->send_json($id, array(
            'id' => $request_id,
            'event' => 'response',
            'success' => false,
            'error' => array(
                'code' => $code,
                'message' => $message,
            ),
        ));
    }

    private function send_json($id, $payload) {
        $this->send_frame($id, wp_json_encode($payload), 1);
    }

    private function send_missed_panel_events($client_id = null) {
        if (!class_exists('Digitalogic_Panel')) {
            return;
        }

        foreach ($this->clients as $id => $client) {
            if ($client_id !== null && (int) $client_id !== (int) $id) {
                continue;
            }

            if (empty($client['handshake'])) {
                continue;
            }

            $last_id = isset($client['last_event_id']) ? absint($client['last_event_id']) : 0;
            $events = Digitalogic_Panel::get_events_since($last_id);
            foreach ($events as $event) {
                $this->send_panel_event($id, $event);
            }
        }
    }

    private function broadcast_panel_event($event) {
        if (!is_array($event)) {
            return;
        }

        foreach ($this->clients as $id => $client) {
            if (empty($client['handshake'])) {
                continue;
            }

            $this->send_panel_event($id, $event);
        }
    }

    private function send_panel_event($id, $event) {
        if (!isset($this->clients[$id]) || !is_array($event)) {
            return;
        }

        $event_id = isset($event['id']) ? absint($event['id']) : 0;
        if ($event_id && isset($this->clients[$id]['last_event_id']) && $event_id <= absint($this->clients[$id]['last_event_id'])) {
            return;
        }

        if ($event_id) {
            $this->clients[$id]['last_event_id'] = max(absint($this->clients[$id]['last_event_id']), $event_id);
        }

        $this->send_json($id, array(
            'event' => isset($event['name']) ? $event['name'] : (isset($event['event']) ? $event['event'] : 'panel.event'),
            'name' => isset($event['name']) ? $event['name'] : '',
            'success' => true,
            'data' => isset($event['data']) && is_array($event['data']) ? $event['data'] : array(),
            'time' => isset($event['time']) ? $event['time'] : '',
            'id' => $event_id,
        ));
    }

    private function maybe_connect_redis_subscriber() {
        if (is_resource($this->redis_socket) || microtime(true) < $this->redis_next_connect_at) {
            return;
        }

        $this->connect_redis_subscriber();
    }

    private function connect_redis_subscriber() {
        $this->close_redis_subscriber();

        $config = class_exists('Digitalogic_Panel') ? Digitalogic_Panel::get_redis_config() : array();
        $host = isset($config['host']) ? (string) $config['host'] : '127.0.0.1';
        $port = isset($config['port']) ? (int) $config['port'] : 6379;
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 0.2;
        $password = isset($config['password']) ? (string) $config['password'] : '';
        $database = array_key_exists('database', $config) && $config['database'] !== null ? (int) $config['database'] : null;
        $this->redis_channel = !empty($config['channel']) ? (string) $config['channel'] : 'digitalogic_panel_events';

        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if (!is_resource($socket)) {
            $this->redis_next_connect_at = microtime(true) + 5;
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::warning('Digitalogic WebSocket Redis subscriber unavailable: ' . $errstr);
            }
            return;
        }

        stream_set_blocking($socket, false);
        $this->redis_socket = $socket;
        $this->redis_buffer = '';
        $this->redis_next_connect_at = 0;

        if ($password !== '') {
            @fwrite($this->redis_socket, $this->redis_encode_command(array('AUTH', $password)));
        }

        if ($database !== null) {
            @fwrite($this->redis_socket, $this->redis_encode_command(array('SELECT', (string) $database)));
        }

        @fwrite($this->redis_socket, $this->redis_encode_command(array('SUBSCRIBE', $this->redis_channel)));
        $this->send_missed_panel_events();

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::log('Digitalogic WebSocket subscribed to Redis channel ' . $this->redis_channel . '.');
        }
    }

    private function close_redis_subscriber() {
        if (is_resource($this->redis_socket)) {
            @fclose($this->redis_socket);
        }

        $this->redis_socket = null;
        $this->redis_buffer = '';
    }

    private function read_redis_events() {
        if (!is_resource($this->redis_socket)) {
            return;
        }

        $chunk = @fread($this->redis_socket, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->redis_socket)) {
                $this->close_redis_subscriber();
                $this->redis_next_connect_at = microtime(true) + 1;
            }
            return;
        }

        $this->redis_buffer .= $chunk;
        while ($this->redis_buffer !== '') {
            list($complete, $reply) = $this->pop_redis_reply();
            if (!$complete) {
                break;
            }

            $this->handle_redis_reply($reply);
        }
    }

    private function handle_redis_reply($reply) {
        if (!is_array($reply) || empty($reply[0])) {
            return;
        }

        $type = strtolower((string) $reply[0]);
        if ($type !== 'message' || count($reply) < 3 || (string) $reply[1] !== $this->redis_channel) {
            return;
        }

        $event = json_decode((string) $reply[2], true);
        if (!is_array($event)) {
            return;
        }

        $this->broadcast_panel_event($event);
    }

    private function pop_redis_reply() {
        $offset = 0;
        $complete = true;
        $reply = $this->parse_redis_value($this->redis_buffer, $offset, $complete);

        if (!$complete) {
            return array(false, null);
        }

        $this->redis_buffer = substr($this->redis_buffer, $offset);

        return array(true, $reply);
    }

    private function parse_redis_value($buffer, &$offset, &$complete) {
        if ($offset >= strlen($buffer)) {
            $complete = false;
            return null;
        }

        $type = $buffer[$offset];
        $offset++;
        $line_end = strpos($buffer, "\r\n", $offset);
        if ($line_end === false) {
            $complete = false;
            return null;
        }

        $line = substr($buffer, $offset, $line_end - $offset);
        $offset = $line_end + 2;

        if ($type === '+' || $type === '-' || $type === ':') {
            return $line;
        }

        if ($type === '$') {
            $length = (int) $line;
            if ($length < 0) {
                return null;
            }

            if (strlen($buffer) < $offset + $length + 2) {
                $complete = false;
                return null;
            }

            $value = substr($buffer, $offset, $length);
            $offset += $length + 2;

            return $value;
        }

        if ($type === '*') {
            $count = (int) $line;
            $items = array();
            for ($i = 0; $i < $count; $i++) {
                $items[] = $this->parse_redis_value($buffer, $offset, $complete);
                if (!$complete) {
                    return null;
                }
            }

            return $items;
        }

        return null;
    }

    private function redis_encode_command($parts) {
        $command = '*' . count($parts) . "\r\n";

        foreach ($parts as $part) {
            $part = (string) $part;
            $command .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        return $command;
    }

    private function send_frame($id, $payload, $opcode = 1) {
        if (!isset($this->clients[$id])) {
            return;
        }

        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . $this->pack_uint64($length);
        }

        @fwrite($this->clients[$id]['socket'], $header . $payload);
    }

    private function decode_frames($buffer) {
        $messages = array();

        while (strlen($buffer) >= 2) {
            $first = ord($buffer[0]);
            $second = ord($buffer[1]);
            $opcode = $first & 0x0f;
            $masked = ($second & 0x80) === 0x80;
            $length = $second & 0x7f;
            $offset = 2;

            if ($length === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $length = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $length = $this->unpack_uint64(substr($buffer, 2, 8));
                $offset = 10;
            }

            $mask_offset = $offset;
            if ($masked) {
                if (strlen($buffer) < $offset + 4) {
                    break;
                }
                $mask = substr($buffer, $offset, 4);
                $offset += 4;
            } else {
                $mask = '';
            }

            if (strlen($buffer) < $offset + $length) {
                break;
            }

            $payload = substr($buffer, $offset, $length);
            if ($masked) {
                for ($i = 0; $i < $length; $i++) {
                    $payload[$i] = $payload[$i] ^ $mask[$i % 4];
                }
            }

            $messages[] = array('opcode' => $opcode, 'payload' => $payload);
            $buffer = substr($buffer, $offset + $length);
        }

        return array('messages' => $messages, 'buffer' => $buffer);
    }

    private function parse_request($request) {
        $lines = preg_split('/\r\n/', trim($request));
        $request_line = array_shift($lines);
        $headers = array();
        $query = array();

        if (preg_match('#^GET\s+([^\s]+)#', $request_line, $matches)) {
            $parts = wp_parse_url($matches[1]);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
            }
        }

        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            list($name, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return array($headers, $query);
    }

    private function pack_uint64($value) {
        $high = intdiv($value, 4294967296);
        $low = $value % 4294967296;

        return pack('NN', $high, $low);
    }

    private function unpack_uint64($bytes) {
        $parts = unpack('Nhigh/Nlow', $bytes);

        return ($parts['high'] * 4294967296) + $parts['low'];
    }

    private function close($id) {
        if (!isset($this->clients[$id])) {
            return;
        }

        @fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
    }
}
