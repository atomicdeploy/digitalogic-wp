<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use Redis as PhpRedis;
use Throwable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Narrow Redis adapter. Configuration is server-side only and is never
 * serialized into REST responses or logs.
 */
final class Redis {

	public const PREFIX                   = 'digitalogic:viewer:';
	public const EVENT_STREAM             = self::PREFIX . 'events';
	public const EVENT_CHANNEL            = self::PREFIX . 'events:live';
	public const TOKEN_PREFIX             = self::PREFIX . 'ws-token:';
	public const EVENT_IDEMPOTENCY_PREFIX = self::PREFIX . 'event-idempotency:';
	public const RELAY_HEARTBEAT_KEY      = self::PREFIX . 'relay:heartbeat';

	private const EVENT_STREAM_MAXLEN     = 50000;
	private const EVENT_ID_TTL            = 86400;
	private const TOKEN_TTL_MIN           = 30;
	private const TOKEN_TTL_MAX           = 180;
	private const MAX_EVENT_BYTES         = 65536;
	private const MAX_EVENT_DEPTH         = 6;
	private const MAX_EVENT_KEYS          = 64;
	private const MAX_EVENT_LIST          = 1000;
	private const MAX_EVENT_STRING_LENGTH = 2048;

	/** @var PhpRedis|null */
	private static ?PhpRedis $client = null;

	/** @var WP_Error|null */
	private static ?WP_Error $connection_error = null;

	/**
	 * @return PhpRedis|WP_Error
	 */
	public static function client() {
		if ( self::$client instanceof PhpRedis ) {
			return self::$client;
		}
		if ( self::$connection_error instanceof WP_Error ) {
			return self::$connection_error;
		}
		if ( ! class_exists( PhpRedis::class ) ) {
			self::$connection_error = new WP_Error(
				'digitalogic_viewer_redis_extension_missing',
				__( 'The Redis PHP extension is unavailable.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
			return self::$connection_error;
		}

		$config = self::config();
		try {
			$redis = new PhpRedis();
			if (
				! $redis->connect(
					$config['host'],
					$config['port'],
					$config['timeout']
				)
			) {
				throw new \RuntimeException( 'Redis connection was rejected.' );
			}
			if ( $config['password'] !== '' && ! $redis->auth( $config['password'] ) ) {
				throw new \RuntimeException( 'Redis authentication was rejected.' );
			}
			if ( $config['database'] !== null && ! $redis->select( $config['database'] ) ) {
				throw new \RuntimeException( 'Redis database selection was rejected.' );
			}
			$redis->setOption( PhpRedis::OPT_READ_TIMEOUT, 1.0 );
			self::$client = $redis;
			return self::$client;
		} catch ( Throwable $error ) {
			// Deliberately omit host/password details.
			self::$connection_error = new WP_Error(
				'digitalogic_viewer_redis_unavailable',
				__( 'The realtime store is unavailable.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
			return self::$connection_error;
		}
	}

	/**
	 * @return array{connected:bool,relayConnected:bool,relayObservedAt:?string,channel:string,stream:string}
	 */
	public static function health(): array {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return array(
				'connected'       => false,
				'relayConnected'  => false,
				'relayObservedAt' => null,
				'channel'         => self::EVENT_CHANNEL,
				'stream'          => self::EVENT_STREAM,
			);
		}
		try {
			$connected       = (bool) $client->ping();
			$heartbeat       = $connected ? $client->get( self::RELAY_HEARTBEAT_KEY ) : false;
			$heartbeat_time  = is_string( $heartbeat ) ? strtotime( $heartbeat ) : false;
			$relay_connected = $heartbeat_time !== false
				&& $heartbeat_time >= time() - 35;
			return array(
				'connected'       => $connected,
				'relayConnected'  => $relay_connected,
				'relayObservedAt' => $heartbeat_time
					? gmdate( 'c', $heartbeat_time )
					: null,
				'channel'         => self::EVENT_CHANNEL,
				'stream'          => self::EVENT_STREAM,
			);
		} catch ( Throwable $error ) {
			return array(
				'connected'       => false,
				'relayConnected'  => false,
				'relayObservedAt' => null,
				'channel'         => self::EVENT_CHANNEL,
				'stream'          => self::EVENT_STREAM,
			);
		}
	}

	/**
	 * Store a one-use opaque WebSocket token by hash only.
	 *
	 * @param array{subject:string,origin:string,scopes:string[],expiresAt:string,expiresUnix:int} $claims
	 * @return array{token:string,expiresAt:string}|WP_Error
	 */
	public static function issue_websocket_token( array $claims, int $ttl = 90 ) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$ttl                   = max( self::TOKEN_TTL_MIN, min( self::TOKEN_TTL_MAX, $ttl ) );
		$token                 = bin2hex( random_bytes( 32 ) );
		$hash                  = hash( 'sha256', $token );
		$claims['expiresUnix'] = time() + $ttl;
		$claims['expiresAt']   = gmdate( 'c', $claims['expiresUnix'] );
		$claims['version']     = 1;
		$claims['nonce']       = wp_generate_uuid4();
		$encoded               = wp_json_encode( $claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error(
				'digitalogic_viewer_token_encoding_failed',
				__( 'The realtime token could not be encoded.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 500 )
			);
		}

		try {
			$stored = $client->set(
				self::TOKEN_PREFIX . $hash,
				$encoded,
				array(
					'nx',
					'ex' => $ttl,
				)
			);
			if ( $stored !== true ) {
				throw new \RuntimeException( 'Token collision or storage rejection.' );
			}
		} catch ( Throwable $error ) {
			return new WP_Error(
				'digitalogic_viewer_token_storage_failed',
				__( 'The realtime token could not be stored.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
		}

		return array(
			'token'     => $token,
			'expiresAt' => $claims['expiresAt'],
		);
	}

	/**
	 * Publish a bounded, PII-free canonical entity upsert/tombstone to the
	 * shared event stream and pub/sub channel.
	 *
	 * @param string[]            $changes
	 * @param array<string,mixed> $value
	 * @return array|WP_Error
	 */
	public static function publish_event(
		string $type,
		string $entity_type,
		string $entity_id,
		string $revision,
		array $changes = array(),
		array $value = array()
	) {
		$client = self::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$type        = self::event_type( $type );
		$entity_type = sanitize_key( $entity_type );
		$entity_id   = self::entity_id( $entity_id );
		$revision    = self::revision( $revision );
		$changes     = array_values(
			array_unique(
				array_slice(
					array_filter(
						array_map(
							static fn( $value ): string => sanitize_key( (string) $value ),
							$changes
						)
					),
					0,
					24
				)
			)
		);

		if (
			$type === ''
			|| $entity_type === ''
			|| $entity_id === ''
			|| $revision === ''
		) {
			return new WP_Error(
				'digitalogic_viewer_event_invalid',
				__( 'The realtime event identity is invalid.', 'digitalogic-viewer-bridge' )
			);
		}

		$valid_value = true;
		$value       = self::sanitize_event_value( $value, 0, $valid_value );
		if ( ! $valid_value || ! is_array( $value ) ) {
			return new WP_Error(
				'digitalogic_viewer_event_value_invalid',
				__( 'The realtime entity summary is invalid.', 'digitalogic-viewer-bridge' )
			);
		}

		$event   = array(
			'type'       => $type,
			'entityType' => $entity_type,
			'entityId'   => $entity_id,
			'revision'   => $revision,
			'occurredAt' => gmdate( 'c' ),
		);
		$payload = array();
		if ( $changes ) {
			$payload['changes'] = $changes;
		}
		if ( $value ) {
			$payload['value'] = $value;
		}
		if ( $payload ) {
			$event['payload'] = $payload;
		}
		$encoded = wp_json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'digitalogic_viewer_event_encoding_failed', 'Event encoding failed.' );
		}
		if ( strlen( $encoded ) > self::MAX_EVENT_BYTES ) {
			return new WP_Error(
				'digitalogic_viewer_event_too_large',
				__( 'The realtime event exceeds the safe transport limit.', 'digitalogic-viewer-bridge' )
			);
		}

		$idempotency_hash = hash(
			'sha256',
			implode( '|', array( $type, $entity_type, $entity_id, $revision ) )
				. '|'
				. wp_json_encode( $event['payload'] ?? array() )
		);
		$script           = <<<'LUA'
local existing = redis.call('GET', KEYS[3])
if existing == ARGV[4] then
    return {'duplicate', existing}
end
local stream_id = redis.call(
    'XADD',
    KEYS[1],
    'MAXLEN',
    '~',
    ARGV[2],
    '*',
    'envelope',
    ARGV[1]
)
redis.call('SET', KEYS[3], ARGV[4], 'EX', ARGV[3])
redis.call('PUBLISH', KEYS[2], ARGV[1])
return {'published', stream_id}
LUA;
		try {
			$result = $client->eval(
				$script,
				array(
					self::EVENT_STREAM,
					self::EVENT_CHANNEL,
					self::EVENT_IDEMPOTENCY_PREFIX . 'latest:' . hash( 'sha256', $entity_type . '|' . $entity_id ),
					$encoded,
					(string) self::EVENT_STREAM_MAXLEN,
					(string) self::EVENT_ID_TTL,
					$idempotency_hash,
				),
				3
			);
			if ( ! is_array( $result ) || ! in_array( $result[0] ?? '', array( 'published', 'duplicate' ), true ) ) {
				throw new \RuntimeException( 'Atomic Redis event publication failed.' );
			}
			return $event;
		} catch ( Throwable $error ) {
			return new WP_Error(
				'digitalogic_viewer_event_publish_failed',
				__( 'The realtime change event could not be delivered.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sanitize_event_value(
		$value,
		int $depth,
		bool &$valid
	) {
		if ( ! $valid ) {
			return null;
		}
		if (
			$value === null
			|| is_bool( $value )
			|| is_int( $value )
			|| ( is_float( $value ) && is_finite( $value ) )
		) {
			return $value;
		}
		if ( is_string( $value ) ) {
			if (
				mb_strlen( $value, 'UTF-8' ) > self::MAX_EVENT_STRING_LENGTH
				|| preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value )
			) {
				$valid = false;
				return null;
			}
			return $value;
		}
		if ( ! is_array( $value ) || $depth >= self::MAX_EVENT_DEPTH ) {
			$valid = false;
			return null;
		}
		$is_list = array_is_list( $value );
		$maximum = $is_list ? self::MAX_EVENT_LIST : self::MAX_EVENT_KEYS;
		if ( count( $value ) > $maximum ) {
			$valid = false;
			return null;
		}
		$safe = array();
		foreach ( $value as $key => $child ) {
			if ( ! $is_list ) {
				$key = (string) $key;
				if (
					! preg_match( '/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/D', $key )
					|| in_array( $key, array( '__proto__', 'prototype', 'constructor' ), true )
					|| preg_match(
						'/(?:pass(?:word)?|secret|token|authorization|cookie|e[-_]?mail|phone|mobile|address|card(?:number)?|cvv|iban|routing|account(?:number)?|ip(?:address)?|session)/i',
						$key
					)
				) {
					$valid = false;
					return null;
				}
			}
			$next = self::sanitize_event_value(
				$child,
				$depth + 1,
				$valid
			);
			if ( ! $valid ) {
				return null;
			}
			if ( $is_list ) {
				$safe[] = $next;
			} else {
				$safe[ $key ] = $next;
			}
		}
		return $safe;
	}

	/**
	 * @return array{host:string,port:int,timeout:float,password:string,database:?int}
	 */
	private static function config(): array {
		$defaults = array(
			'host'     => defined( 'DIGITALOGIC_VIEWER_REDIS_HOST' )
				? (string) DIGITALOGIC_VIEWER_REDIS_HOST
				: '127.0.0.1',
			'port'     => defined( 'DIGITALOGIC_VIEWER_REDIS_PORT' )
				? (int) DIGITALOGIC_VIEWER_REDIS_PORT
				: 6379,
			'timeout'  => 0.4,
			'password' => defined( 'DIGITALOGIC_VIEWER_REDIS_PASSWORD' )
				? (string) DIGITALOGIC_VIEWER_REDIS_PASSWORD
				: '',
			'database' => defined( 'DIGITALOGIC_VIEWER_REDIS_DATABASE' )
				? (int) DIGITALOGIC_VIEWER_REDIS_DATABASE
				: null,
		);
		$filtered = apply_filters( 'digitalogic_viewer_redis_config', $defaults );
		$config   = is_array( $filtered ) ? array_merge( $defaults, $filtered ) : $defaults;

		return array(
			'host'     => trim( (string) $config['host'] ) ?: '127.0.0.1',
			'port'     => max( 1, min( 65535, (int) $config['port'] ) ),
			'timeout'  => max( 0.1, min( 2.0, (float) $config['timeout'] ) ),
			'password' => (string) $config['password'],
			'database' => $config['database'] === null ? null : max( 0, (int) $config['database'] ),
		);
	}

	private static function event_type( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-z][a-z0-9_.-]{1,63}$/', $value ) ? $value : '';
	}

	private static function entity_id( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^[a-z][a-z0-9_.:-]{1,127}$/i', $value ) ? $value : '';
	}

	private static function revision( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^rev:[a-f0-9]{64}$/', $value ) ? $value : '';
	}
}
