<?php
/**
 * Small WP-CLI WebSocket server for Digitalogic commands.
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- A nonblocking WebSocket/Redis daemon requires native stream sockets and result-aware suppressed probes.

class Digitalogic_WebSocket_Server {

    private $clients = array();
    private $redis_socket = null;
    private $redis_buffer = '';
    private $redis_next_connect_at = 0;
    private $redis_channel = 'digitalogic_panel_events';

    public function run($host = '127.0.0.1', $port = 8090) {
        $server = @stream_socket_server('tcp://' . $host . ':' . $port, $errno, $errstr);
        if (!$server) {
            throw new RuntimeException($errstr, $errno);
        }

        stream_set_blocking($server, false);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::log('Digitalogic WebSocket server listening on ' . $host . ':' . $port);
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
			'principal'              => '',
			'source'                 => array(),
			'credential_fingerprint' => '',
            'device_id'     => '',
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
		$provider_header_names     = Digitalogic_WebSocket_Auth::pricing_protected_headers();
		$pricing_header_names      = array_merge(
			$provider_header_names,
			array( 'last-event-id', 'sec-websocket-key', 'sec-websocket-protocol' )
		);
		$pricing_header_attempted  = (bool) array_intersect( $provider_header_names, array_keys( $headers ) );
		$duplicate_headers         = isset($headers['__digitalogic_duplicate_headers'])
			&& is_array($headers['__digitalogic_duplicate_headers'])
			? $headers['__digitalogic_duplicate_headers']
			: array();
		$ambiguous_pricing_headers = $pricing_header_attempted
			&& ! empty(array_intersect($pricing_header_names, $duplicate_headers));
		$auth                      = $ambiguous_pricing_headers
			? array( 'authenticated' => false, 'principal' => '' )
			: Digitalogic_WebSocket_Auth::authenticate_context($headers, $query);
		$protocols                 = isset($headers['sec-websocket-protocol'])
			? array_map('trim', explode(',', (string) $headers['sec-websocket-protocol']))
			: array();
		$pricing_service           = Digitalogic_WebSocket_Auth::is_pricing_context( $auth );
		$pricing_protocol          = '';
		if ( $pricing_service ) {
			foreach ( $protocols as $protocol ) {
				if ( 'digitalogic.pricing' === $protocol ) {
					$pricing_protocol = $protocol;
					break;
				}
			}
		}
		$invalid_pricing_cursor    = $pricing_service
			&& isset($headers['last-event-id'])
			&& ! $this->valid_event_cursor($headers['last-event-id']);
		if (
			empty($auth['authenticated'])
			|| empty($headers['sec-websocket-key'])
			|| $invalid_pricing_cursor
			|| ( $pricing_service && '' === $pricing_protocol )
		) {
            @fwrite($this->clients[$id]['socket'], "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
            $this->close($id);
            return;
        }
		$user_id = (int) ( $auth['user_id'] ?? 0 );

        $accept = base64_encode(sha1($headers['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
			. "Sec-WebSocket-Accept: " . $accept . "\r\n"
			. ( $pricing_service ? "Sec-WebSocket-Protocol: " . $pricing_protocol . "\r\n" : '' )
			. "\r\n";

		$written = @fwrite($this->clients[ $id ]['socket'], $response);
		if ( $written !== strlen($response) ) {
			$this->close($id);
			return;
		}
        $this->clients[$id]['handshake'] = true;
        $this->clients[$id]['user_id'] = $user_id;
		$this->clients[ $id ]['principal']              = (string) ( $auth['principal'] ?? '' );
		$this->clients[ $id ]['source']                 = isset($auth['source']) && is_array($auth['source']) ? $auth['source'] : array();
		$this->clients[ $id ]['credential_fingerprint'] = (string) ( $auth['credential_fingerprint'] ?? '' );
		$cursor_reset_required                        = false;
		$oldest_event_id                              = 0;
		$latest_event_id                              = isset($this->clients[ $id ]['last_event_id'])
			? absint($this->clients[ $id ]['last_event_id'])
			: 0;
		if (
			Digitalogic_WebSocket_Auth::is_pricing_context( $this->clients[ $id ] )
			&& isset($headers['last-event-id'])
			&& preg_match('/\A[0-9]{1,20}\z/D', (string) $headers['last-event-id'])
		) {
			$requested_cursor = absint($headers['last-event-id']);
			$window           = $this->durable_event_window();
			$oldest_event_id  = $window['oldest_event_id'];
			$latest_event_id  = $window['latest_event_id'];
			if ( $requested_cursor > $latest_event_id ) {
				$requested_cursor      = $latest_event_id;
				$cursor_reset_required = true;
			} elseif ( $oldest_event_id > 0 && $requested_cursor < ( $oldest_event_id - 1 ) ) {
				$requested_cursor      = $oldest_event_id - 1;
				$cursor_reset_required = true;
			} elseif ( 0 === $oldest_event_id && $requested_cursor < $latest_event_id ) {
				$requested_cursor      = $latest_event_id;
				$cursor_reset_required = true;
			}
			$this->clients[ $id ]['last_event_id'] = $requested_cursor;
		} elseif ( $pricing_service ) {
			$window                              = $this->durable_event_window();
			$oldest_event_id                     = $window['oldest_event_id'];
			$latest_event_id                     = $window['latest_event_id'];
			$this->clients[ $id ]['last_event_id'] = $latest_event_id;
		}
		$this->clients[ $id ]['headers'] = '';
		$connected                     = array(
            'event' => 'connected',
            'success' => true,
			'data'    => array(
				'user_id'   => max(0, $user_id),
				'principal' => $this->clients[ $id ]['principal'],
			),
		);
		if ( $pricing_service ) {
			$connected['data']['cursor']                       = absint($this->clients[ $id ]['last_event_id']);
			$connected['data']['oldest_event_id']              = $oldest_event_id;
			$connected['data']['latest_event_id']              = $latest_event_id;
			$connected['data']['cursor_reset_required']        = $cursor_reset_required;
			$connected['data']['revision_validation_required'] = true;
			$connected['data']['revision_path']                = '/wp-json/digitalogic/pricing/sync/revision';
			$connected['data']['projection']                   = Digitalogic_Pricing_Snapshot::PROJECTION;
			if ( $cursor_reset_required ) {
				$delivery                         = $this->pricing_cursor_gap_delivery();
				$connected['data']['diagnostics'] = $delivery['diagnostics'];
				$connected['data']['recovery']    = $delivery['recovery'];
			}
		}
		if ( ! $this->send_json($id, $connected) ) {
			return;
		}
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

		if ( Digitalogic_WebSocket_Auth::is_pricing_context( $this->clients[ $id ] ) ) {
			$this->send_error($id, $request_id, 'digitalogic_pricing_stream_read_only', __('The pricing event stream does not accept commands.', 'digitalogic'));
			return;
		}

        wp_set_current_user(max(0, (int) $this->clients[$id]['user_id']));
        if ($command === 'digitalogic_panel_events' && class_exists('Digitalogic_Panel')) {
            if ( ! Digitalogic_Access_Control::can_access_panel() ) {
                $this->send_error($id, $request_id, 'digitalogic_unauthorized', __('Unauthorized', 'digitalogic'));
                return;
            }

            $since  = isset($data['since']) ? absint($data['since']) : 0;
            $events = Digitalogic_Panel::get_events_since($since);
            if (class_exists('Digitalogic_Event_Mesh')) {
                $client_user_id   = max(0, (int) ($this->clients[$id]['user_id'] ?? 0));
                $client_device_id = (string) ($this->clients[$id]['device_id'] ?? '');
                $events           = array_values(array_filter($events, static function($event) use ($client_user_id, $client_device_id) {
                    return is_array($event) && Digitalogic_Event_Mesh::event_visible_to($event, $client_user_id, $client_device_id);
                }));
            }
            $this->send_json($id, array(
                'id' => $request_id,
                'event' => 'response',
                'command' => $command,
                'success' => true,
                'data' => array(
                    'events' => $events,
                ),
            ));
            return;
        }

        $result = Digitalogic_Command_Dispatcher::instance()->execute($command, $data, 'websocket');
        if (is_wp_error($result)) {
            $this->send_error($id, $request_id, $result->get_error_code(), $result->get_error_message());
            return;
        }

        if (
            $command === 'digitalogic_workstation_register'
            && is_array($result)
            && !empty($result['device_id'])
        ) {
            $this->clients[$id]['device_id'] = sanitize_text_field((string) $result['device_id']);
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
		return $this->send_frame($id, wp_json_encode($payload), 1);
    }

	private function send_missed_panel_events( $client_id = null, $revalidate_service = false ) {
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

			$pricing_service = Digitalogic_WebSocket_Auth::is_pricing_context( $client );
			if (
				$pricing_service
				&& $revalidate_service
				&& ! Digitalogic_WebSocket_Auth::pricing_service_context_is_current($client)
			) {
				$this->close($id);
				continue;
			}

            $last_id = isset($client['last_event_id']) ? absint($client['last_event_id']) : 0;
			if ( $pricing_service ) {
				$window = $this->durable_event_window();
				$gap    = $last_id > $window['latest_event_id']
					|| ( $window['oldest_event_id'] > 0 && $last_id < ( $window['oldest_event_id'] - 1 ) )
					|| ( 0 === $window['oldest_event_id'] && $last_id < $window['latest_event_id'] );
				if ( $gap ) {
					$reset_cursor = $window['latest_event_id'];
					$delivery     = $this->pricing_cursor_gap_delivery();
					$sent         = $this->send_json($id, array(
						'event'   => 'pricing.stream.diagnostic',
						'success' => true,
						'data'    => array_merge(
							array(
							'reason'                       => 'cursor_gap',
							'cursor'                       => $reset_cursor,
							'oldest_event_id'              => $window['oldest_event_id'],
							'latest_event_id'              => $window['latest_event_id'],
							'revision_validation_required' => true,
							'revision_path'                => '/wp-json/digitalogic/pricing/sync/revision',
							),
							$delivery
						),
					));
					if ( $sent && isset($this->clients[ $id ]) ) {
						$this->clients[ $id ]['last_event_id'] = $reset_cursor;
					}
					continue;
				}
			}
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

		$pricing_service = Digitalogic_WebSocket_Auth::is_pricing_context( $this->clients[ $id ] );
		$visible         = true;
		$delivery        = null;
		if ( $pricing_service ) {
			if ( class_exists('Digitalogic_Event_Mesh') ) {
				$delivery = Digitalogic_Event_Mesh::pricing_event_delivery_decision(
					$event,
					Digitalogic_WebSocket_Auth::pricing_principal(),
					isset($this->clients[ $id ]['source']) && is_array($this->clients[ $id ]['source'])
						? $this->clients[ $id ]['source']
						: array()
				);
				$visible  = ! empty($delivery['visible']);
			} else {
				$visible = false;
			}
		} elseif ( class_exists('Digitalogic_Event_Mesh') ) {
			$visible = Digitalogic_Event_Mesh::event_visible_to(
				$event,
				max(0, (int) ( $this->clients[ $id ]['user_id'] ?? 0 )),
				(string) ( $this->clients[ $id ]['device_id'] ?? '' )
			);
		}
		if ( ! $visible ) {
			if ( $pricing_service && is_array($delivery) && ! empty($delivery['blocking']) ) {
				$this->send_pricing_blocking_reset($id, $event_id, $delivery);
				return;
			}
			if ( $event_id ) {
				$this->clients[ $id ]['last_event_id'] = $event_id;
			}
			return;
        }

		$payload = array(
            'event' => isset($event['name']) ? $event['name'] : (isset($event['event']) ? $event['event'] : 'panel.event'),
            'name' => isset($event['name']) ? $event['name'] : '',
            'success' => true,
            'data' => $pricing_service && is_array($delivery) && isset($delivery['data']) && is_array($delivery['data'])
				? $delivery['data']
				: (isset($event['data']) && is_array($event['data']) ? $event['data'] : array()),
            'time' => isset($event['time']) ? $event['time'] : '',
            'id' => $event_id,
		);
		if ( $pricing_service && is_array($delivery) && ! empty($delivery['diagnostics']) ) {
			$payload['delivery'] = array(
				'diagnostics' => $delivery['diagnostics'],
				'recovery'    => $delivery['recovery'],
			);
		}
		$sent = $this->send_json($id, $payload);
		if ( $sent && $event_id && isset($this->clients[ $id ]) ) {
			$this->clients[ $id ]['last_event_id'] = max(absint($this->clients[ $id ]['last_event_id']), $event_id);
		}
    }

	/**
	 * Send one secret-free blocking reset and close only the unsafe stream.
	 *
	 * @param int   $id       Client socket ID.
	 * @param int   $event_id Durable event ID.
	 * @param array $delivery Structured blocking delivery decision.
	 * @return void
	 */
	private function send_pricing_blocking_reset($id, $event_id, array $delivery) {
		$diagnostics = isset($delivery['diagnostics']) && is_array($delivery['diagnostics'])
			? $delivery['diagnostics']
			: array();
		$reason      = isset($diagnostics[0]['code']) ? (string) $diagnostics[0]['code'] : 'unsafe_event_identity';
		$this->send_json($id, array(
			'event'   => 'pricing.stream.reset',
			'success' => false,
			'id'      => absint($event_id),
			'data'    => array(
				'reason'      => $reason,
				'diagnostics' => $diagnostics,
				'recovery'    => isset($delivery['recovery']) && is_array($delivery['recovery'])
					? $delivery['recovery']
					: array(),
			),
		));
		if ( isset($this->clients[ $id ]) ) {
			$this->close($id);
		}
	}

	/**
	 * Return finite non-blocking recovery for a durable cursor gap.
	 *
	 * @return array
	 */
	private function pricing_cursor_gap_delivery() {
		return array(
			'diagnostics' => array(
				array(
					'code'            => 'cursor_gap',
					'severity'        => 'WARNING',
					'blocking'        => false,
					'reason'          => 'The durable event cursor is outside the retained window.',
					'retryable'       => true,
					'recovery_action' => 'conditional_refresh',
				),
			),
			'recovery'    => array(
				'action'                => 'conditional_refresh',
				'retryable'             => true,
				'max_attempts'          => 3,
				'timeout_seconds'       => 30,
				'revision_path'         => '/wp-json/digitalogic/pricing/sync/revision',
				'fallback_action'       => 'controlled_polling',
				'poll_interval_seconds' => 5,
			),
		);
	}

    private function maybe_connect_redis_subscriber() {
        if (is_resource($this->redis_socket) || microtime(true) < $this->redis_next_connect_at) {
            return;
        }

        $this->connect_redis_subscriber();
    }

    private function connect_redis_subscriber() {
        $this->close_redis_subscriber();

        $config = Digitalogic_Panel::get_redis_config();
        $host = $config['host'];
        $port = $config['port'];
        $timeout = $config['timeout'];
        $password = $config['password'];
        $database = $config['database'];
        $this->redis_channel = $config['channel'];

        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if (!is_resource($socket)) {
            $this->schedule_redis_retry('Redis connection failed: ' . $errstr, 5);
            return;
        }

        stream_set_blocking($socket, true);
        $timeout_seconds = (int) floor($timeout);
        $timeout_microseconds = (int) (($timeout - $timeout_seconds) * 1000000);
        stream_set_timeout($socket, $timeout_seconds, $timeout_microseconds);
        $this->redis_socket = $socket;
        $this->redis_buffer = '';

        try {
            if ($password !== '') {
                $auth_reply = $this->send_redis_setup_command(array('AUTH', $password), $timeout);
                if ((string) $auth_reply !== 'OK') {
                    throw new RuntimeException('Redis AUTH was rejected.');
                }
            }

            if ($database !== null) {
                $select_reply = $this->send_redis_setup_command(array('SELECT', (string) $database), $timeout);
                if ((string) $select_reply !== 'OK') {
                    throw new RuntimeException('Redis SELECT was rejected.');
                }
            }

            $subscribe_reply = $this->send_redis_setup_command(array('SUBSCRIBE', $this->redis_channel), $timeout);
            if (
                !is_array($subscribe_reply)
                || count($subscribe_reply) < 3
                || strtolower((string) $subscribe_reply[0]) !== 'subscribe'
                || (string) $subscribe_reply[1] !== $this->redis_channel
                || (int) $subscribe_reply[2] < 1
            ) {
                throw new RuntimeException('Redis SUBSCRIBE acknowledgement was invalid.');
            }
        } catch (Throwable $error) {
            $this->schedule_redis_retry($error->getMessage(), 5);
            return;
        }

        stream_set_blocking($this->redis_socket, false);
        stream_set_timeout($this->redis_socket, 0);
        $this->redis_next_connect_at = 0;
		$this->send_missed_panel_events(null, true);
        $this->process_redis_buffer();

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::log('Digitalogic WebSocket subscribed to Redis channel ' . $this->redis_channel . '.');
        }
    }

    /**
     * Schedule a clean Redis reconnect after setup or I/O failure.
     *
     * @param string $message Failure summary without credentials.
     * @param int    $delay Retry delay in seconds.
     */
    private function schedule_redis_retry($message, $delay = 5) {
        $this->close_redis_subscriber();
        $this->redis_next_connect_at = microtime(true) + max(1, (int) $delay);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::warning('Digitalogic WebSocket Redis subscriber unavailable: ' . sanitize_text_field($message));
        }
    }

    /**
     * Write a complete Redis command and synchronously read its setup reply.
     *
     * @param array $parts Redis command parts.
     * @param float $timeout Read timeout in seconds.
     * @return mixed
     */
    private function send_redis_setup_command($parts, $timeout) {
        $payload = $this->redis_encode_command($parts);
        $length = strlen($payload);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($this->redis_socket, substr($payload, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Redis command write failed.');
            }

            $written += $result;
        }

        return $this->read_redis_setup_reply($timeout);
    }

    /**
     * Read exactly one complete RESP reply during subscriber setup.
     *
     * @param float $timeout Read timeout in seconds.
     * @return mixed
     */
    private function read_redis_setup_reply($timeout) {
        $deadline = microtime(true) + max(0.1, (float) $timeout);

        while (true) {
            if ($this->redis_buffer !== '') {
                list($complete, $reply) = $this->pop_redis_reply();
                if ($complete) {
                    return $reply;
                }
            }

            $chunk = @fread($this->redis_socket, 8192);
            if (is_string($chunk) && $chunk !== '') {
                $this->redis_buffer .= $chunk;
                continue;
            }

            $metadata = stream_get_meta_data($this->redis_socket);
            if (!empty($metadata['timed_out']) || microtime(true) >= $deadline) {
                throw new RuntimeException('Redis setup reply timed out.');
            }

            if (!empty($metadata['eof'])) {
                throw new RuntimeException('Redis closed the setup connection.');
            }

            usleep(10000);
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
                $this->schedule_redis_retry('Redis closed the subscription connection.', 1);
            }
            return;
        }

        $this->redis_buffer .= $chunk;
        $this->process_redis_buffer();
    }

    /**
     * Process all complete Redis replies already buffered in userspace.
     */
    private function process_redis_buffer() {
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
        if (
            !is_array($event)
            || empty($event['id'])
            || empty($event['name'])
            || !isset($event['data'])
            || !is_array($event['data'])
            || !isset($event['time'])
            || !is_string($event['time'])
        ) {
            return;
        }

		// Redis is only the low-latency wake-up. The durable queue owns global
		// ordering, so every client drains from its exact cursor instead of
		// trusting publish order across concurrent PHP requests.
		$this->send_missed_panel_events(null, true);
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
			return false;
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

		$frame   = $header . $payload;
		$written = @fwrite($this->clients[ $id ]['socket'], $frame);
		if ( $written !== strlen($frame) ) {
			$this->close($id);
			return false;
		}

		return true;
	}

	/** Return the bounded durable cursor window without exposing event payloads. */
	private function durable_event_window() {
		if ( ! class_exists('Digitalogic_Panel') ) {
			return array( 'oldest_event_id' => 0, 'latest_event_id' => 0 );
		}
		$events = Digitalogic_Panel::get_events_since(0);
		$oldest = 0;
		foreach ( $events as $event ) {
			$event_id = is_array($event) ? absint($event['id'] ?? 0) : 0;
			if ( $event_id > 0 && ( 0 === $oldest || $event_id < $oldest ) ) {
				$oldest = $event_id;
			}
		}

		return array(
			'oldest_event_id' => $oldest,
			'latest_event_id' => Digitalogic_Panel::get_latest_event_id(),
		);
	}

	/** Validate one nonnegative decimal cursor without integer overflow. */
	private function valid_event_cursor( $cursor ) {
		$cursor = is_string($cursor) ? $cursor : '';
		if ( ! preg_match('/\A[0-9]{1,20}\z/D', $cursor) ) {
			return false;
		}

		$normalized = ltrim($cursor, '0');
		$normalized = '' === $normalized ? '0' : $normalized;
		$maximum    = (string) PHP_INT_MAX;

		return strlen($normalized) < strlen($maximum)
			|| ( strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) <= 0 );
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
		$duplicates   = array();
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
			$name               = strtolower(trim($name));
			if ( array_key_exists($name, $headers) ) {
				$duplicates[ $name ] = true;
			}
			$headers[ $name ] = trim($value);
		}
		if ( $duplicates ) {
			$headers['__digitalogic_duplicate_headers'] = array_keys($duplicates);
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
