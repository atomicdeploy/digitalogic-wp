<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use DateTimeImmutable;
use Throwable;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event-driven, PII-free Patris commerce projection.
 *
 * The workstation watcher pushes a complete deterministic projection only
 * after a source-file event. This class validates and normalizes it into the
 * same shared Redis database used by drafts, layouts, the event stream, n8n,
 * and the WebSocket relay. WordPress options are never used as a snapshot.
 */
final class Patris_Commerce {

	public const SCHEMA         = 'digitalogic.patris-commerce';
	public const VERSION        = 1;
	public const SOURCE_ID      = 'office-patris-commerce';
	public const SOURCE_DATASET = 'wraz.db';

	private const STATE_KEY                 = Redis::PREFIX . 'patris-commerce:state';
	private const EVENT_CHECKPOINT_KEY      =
		Redis::PREFIX . 'patris-commerce:event-checkpoint';
	private const HEARTBEAT_KEY             = Redis::PREFIX . 'patris-commerce:heartbeat';
	private const HEARTBEAT_TTL             = 75;
	private const SECRET_OPTION             = 'digitalogic_product_sync_secret';
	private const SECRET_HEADER             = 'x-patris-product-sync-secret';
	private const MAX_REQUEST_BYTES         = 8_388_608;
	private const MAX_ORDERS                = 5000;
	private const MAX_CUSTOMERS             = 2000;
	private const MAX_LINES_PER_ORDER       = 500;
	private const MAX_PRODUCT_LINKS         = 500;
	private const MAX_ORDER_LINKS           = 1000;
	private const INGEST_MEMORY_LIMIT       = '768M';
	private const INGEST_TIME_LIMIT_SECONDS = 300;

	/**
	 * @return true|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		$content_length = (int) $request->get_header( 'content-length' );
		if (
			$content_length < 0
			|| $content_length > self::MAX_REQUEST_BYTES
		) {
			return new WP_Error(
				'digitalogic_patris_commerce_request_too_large',
				__( 'The Patris commerce request is too large.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 413 )
			);
		}
		$expected = (string) get_option( self::SECRET_OPTION, '' );
		$provided = (string) $request->get_header( self::SECRET_HEADER );
		if (
			strlen( $expected ) < 32
			|| strlen( $provided ) < 32
			|| ! hash_equals( $expected, $provided )
		) {
			return new WP_Error(
				'digitalogic_patris_commerce_auth_required',
				__( 'Patris commerce authentication is required.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function ingest( WP_REST_Request $request ) {
		self::ensure_ingest_headroom();
		$raw = $request->get_body();
		if ( strlen( $raw ) > self::MAX_REQUEST_BYTES ) {
			return new WP_Error(
				'digitalogic_patris_commerce_request_too_large',
				__( 'The Patris commerce request is too large.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 413 )
			);
		}
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return self::invalid( 'payload' );
		}
		$normalized = self::normalize_projection( $payload );
		if ( $normalized instanceof WP_Error ) {
			return $normalized;
		}

		$current_state      = self::state();
		$current_generated  = strtotime(
			(string) ( $current_state['source']['generatedAt'] ?? '' )
		);
		$incoming_generated = strtotime(
			(string) ( $normalized['source']['generatedAt'] ?? '' )
		);
		if (
			( $current_state['source']['revision'] ?? '' )
				!== $normalized['source']['revision']
			&& $current_generated !== false
			&& $incoming_generated !== false
			&& $incoming_generated < $current_generated
		) {
			return new WP_Error(
				'digitalogic_patris_commerce_stale_projection',
				__(
					'An older Patris commerce projection was rejected.',
					'digitalogic-viewer-bridge'
				),
				array( 'status' => 409 )
			);
		}
		$previous = self::event_checkpoint();
		$encoded  = wp_json_encode(
			$normalized,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_REQUEST_BYTES ) {
			return self::invalid( 'normalized-size' );
		}
		$client = Redis::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		try {
			if ( ! $client->set( self::STATE_KEY, $encoded ) ) {
				throw new \RuntimeException( 'projection storage failed' );
			}
			$client->setex(
				self::HEARTBEAT_KEY,
				self::HEARTBEAT_TTL,
				gmdate( 'c' )
			);
		} catch ( Throwable $error ) {
			return new WP_Error(
				'digitalogic_patris_commerce_storage_failed',
				__( 'The Patris commerce projection could not be stored.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
		}

		$events = self::emit_projection_changes( $previous, $normalized );
		if ( $events['failed'] > 0 ) {
			return new WP_Error(
				'digitalogic_patris_commerce_event_delivery_failed',
				__(
					'The projection was stored, but one or more live events require retry.',
					'digitalogic-viewer-bridge'
				),
				array(
					'status'           => 503,
					'projectionStored' => true,
					'publishedEvents'  => $events['published'],
					'failedEvents'     => $events['failed'],
				)
			);
		}
		try {
			if ( ! $client->set( self::EVENT_CHECKPOINT_KEY, $encoded ) ) {
				throw new \RuntimeException( 'event checkpoint storage failed' );
			}
		} catch ( Throwable $error ) {
			return new WP_Error(
				'digitalogic_patris_commerce_checkpoint_failed',
				__(
					'Live events were emitted, but their checkpoint requires retry.',
					'digitalogic-viewer-bridge'
				),
				array(
					'status'           => 503,
					'projectionStored' => true,
				)
			);
		}
		return array(
			'ok'         => true,
			'schema'     => self::SCHEMA,
			'version'    => self::VERSION,
			'revision'   => $normalized['source']['revision'],
			'receivedAt' => $normalized['receivedAt'],
			'counts'     => array(
				'orders'       => count( $normalized['orders'] ),
				'customers'    => count( $normalized['customers'] ),
				'productSales' => count( $normalized['productSales'] ),
			),
			'events'     => $events,
		);
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function heartbeat( WP_REST_Request $request ) {
		$payload  = $request->get_json_params();
		$revision = is_array( $payload )
			? self::revision( (string) ( $payload['revision'] ?? '' ), 'sha256:' )
			: '';
		$state    = self::state();
		if (
			$revision === ''
			|| ( $state['source']['revision'] ?? '' ) !== $revision
		) {
			return new WP_Error(
				'digitalogic_patris_commerce_revision_mismatch',
				__( 'The Patris commerce heartbeat revision is not current.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 409 )
			);
		}
		$client = Redis::client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		try {
			$client->setex(
				self::HEARTBEAT_KEY,
				self::HEARTBEAT_TTL,
				gmdate( 'c' )
			);
		} catch ( Throwable $error ) {
			return new WP_Error(
				'digitalogic_patris_commerce_heartbeat_failed',
				__( 'The Patris commerce heartbeat could not be stored.', 'digitalogic-viewer-bridge' ),
				array( 'status' => 503 )
			);
		}
		return array(
			'ok'        => true,
			'revision'  => $revision,
			'connected' => true,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function state(): array {
		$empty  = array(
			'schema'       => self::SCHEMA,
			'version'      => self::VERSION,
			'source'       => array(
				'id'          => self::SOURCE_ID,
				'dataset'     => self::SOURCE_DATASET,
				'revision'    => '',
				'generatedAt' => null,
			),
			'receivedAt'   => null,
			'orders'       => array(),
			'customers'    => array(),
			'productSales' => array(),
		);
		$client = Redis::client();
		if ( is_wp_error( $client ) ) {
			return $empty;
		}
		try {
			$encoded = $client->get( self::STATE_KEY );
		} catch ( Throwable $error ) {
			return $empty;
		}
		if ( ! is_string( $encoded ) || $encoded === '' ) {
			return $empty;
		}
		$decoded = json_decode( $encoded, true );
		if (
			! is_array( $decoded )
			|| ( $decoded['schema'] ?? '' ) !== self::SCHEMA
			|| (int) ( $decoded['version'] ?? 0 ) !== self::VERSION
			|| ! is_array( $decoded['orders'] ?? null )
			|| ! is_array( $decoded['customers'] ?? null )
			|| ! is_array( $decoded['productSales'] ?? null )
		) {
			return $empty;
		}
		return $decoded;
	}

	/**
	 * The event checkpoint advances only after every projection delta has
	 * reached Redis. If delivery is interrupted, the next identical upload
	 * compares against the prior checkpoint and retries missing events; Redis
	 * idempotency absorbs events that were already accepted.
	 *
	 * @return array<string,mixed>
	 */
	private static function event_checkpoint(): array {
		$client = Redis::client();
		if ( is_wp_error( $client ) ) {
			return array();
		}
		try {
			$encoded = $client->get( self::EVENT_CHECKPOINT_KEY );
		} catch ( Throwable $error ) {
			return array();
		}
		if ( ! is_string( $encoded ) || $encoded === '' ) {
			return array();
		}
		$decoded = json_decode( $encoded, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function connected(): bool {
		$client = Redis::client();
		if ( is_wp_error( $client ) ) {
			return false;
		}
		try {
			return (bool) $client->exists( self::HEARTBEAT_KEY );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	public static function prime_ingest_runtime(): void {
		$request_method = strtoupper(
			(string) ( $_SERVER['REQUEST_METHOD'] ?? '' )
		);
		$request_uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$request_path   = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if (
			$request_method !== 'POST'
			|| ! preg_match(
				'#/wp-json/digitalogic-viewer/v1/patris-commerce/?$#D',
				$request_path
			)
		) {
			return;
		}
		$content_length = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
		$expected       = (string) get_option( self::SECRET_OPTION, '' );
		$provided       = (string) (
			$_SERVER['HTTP_X_PATRIS_PRODUCT_SYNC_SECRET'] ?? ''
		);
		if (
			$content_length < 1
			|| $content_length > self::MAX_REQUEST_BYTES
			|| strlen( $expected ) < 32
			|| strlen( $provided ) < 32
			|| ! hash_equals( $expected, $provided )
		) {
			return;
		}
		self::ensure_ingest_headroom();
		self::remove_cleantalk_rest_middleware();
	}

	private static function ensure_ingest_headroom(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$current_limit  = function_exists( 'wp_convert_hr_to_bytes' )
			? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) )
			: 0;
		$required_limit = 768 * MB_IN_BYTES;
		if ( $current_limit > 0 && $current_limit < $required_limit ) {
			@ini_set( 'memory_limit', self::INGEST_MEMORY_LIMIT );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::INGEST_TIME_LIMIT_SECONDS );
		}
	}

	private static function remove_cleantalk_rest_middleware(): void {
		$hook = $GLOBALS['wp_filter']['rest_pre_dispatch'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return;
		}
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( ! $function instanceof \Closure ) {
					continue;
				}
				try {
					$reflection = new \ReflectionFunction( $function );
					$file       = str_replace(
						'\\',
						'/',
						(string) $reflection->getFileName()
					);
				} catch ( Throwable $error ) {
					continue;
				}
				if (
					strpos(
						$file,
						'/cleantalk-spam-protect/'
					) === false
				) {
					continue;
				}
				remove_filter(
					'rest_pre_dispatch',
					$function,
					(int) $priority
				);
			}
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_projection( array $payload ) {
		if (
			! self::has_exact_keys(
				$payload,
				array( 'schema', 'version', 'source', 'orders', 'customers' )
			)
		) {
			return self::invalid( 'top-level-shape' );
		}
		if (
			( $payload['schema'] ?? '' ) !== self::SCHEMA
			|| (int) ( $payload['version'] ?? 0 ) !== self::VERSION
			|| ! is_array( $payload['source'] ?? null )
			|| ! is_array( $payload['orders'] ?? null )
			|| ! array_is_list( $payload['orders'] )
			|| ! is_array( $payload['customers'] ?? null )
			|| ! array_is_list( $payload['customers'] )
			|| count( $payload['orders'] ) > self::MAX_ORDERS
			|| count( $payload['customers'] ) > self::MAX_CUSTOMERS
		) {
			return self::invalid( 'contract' );
		}
		$source = $payload['source'];
		if (
			! self::has_exact_keys(
				$source,
				array( 'id', 'dataset', 'revision', 'generatedAt' )
			)
			|| ( $source['id'] ?? '' ) !== self::SOURCE_ID
			|| ( $source['dataset'] ?? '' ) !== self::SOURCE_DATASET
		) {
			return self::invalid( 'source' );
		}
		$source_revision = self::revision(
			(string) ( $source['revision'] ?? '' ),
			'sha256:'
		);
		$generated_at    = self::iso_time( (string) ( $source['generatedAt'] ?? '' ) );
		if ( $source_revision === '' || $generated_at === null ) {
			return self::invalid( 'source-revision' );
		}

		$customer_bases = array();
		foreach ( $payload['customers'] as $record ) {
			$customer = self::normalize_customer_base( $record );
			if ( $customer instanceof WP_Error ) {
				return $customer;
			}
			if ( isset( $customer_bases[ $customer['id'] ] ) ) {
				return self::invalid( 'duplicate-customer' );
			}
			$customer_bases[ $customer['id'] ] = $customer;
		}

		$orders = array();
		foreach ( $payload['orders'] as $record ) {
			$order = self::normalize_order( $record );
			if ( $order instanceof WP_Error ) {
				return $order;
			}
			if (
				isset( $orders[ $order['id'] ] )
				|| ! isset( $customer_bases[ $order['customerId'] ] )
			) {
				return self::invalid( 'order-identity' );
			}
			$orders[ $order['id'] ] = $order;
		}
		uasort(
			$orders,
			static fn( array $left, array $right ): int =>
				strcmp( $right['createdAt'], $left['createdAt'] )
				?: strcmp( $left['id'], $right['id'] )
		);

		$customer_aggregates = array();
		$product_aggregates  = array();
		foreach ( $orders as $order ) {
			$customer_id                          = $order['customerId'];
			$customer                             = $customer_aggregates[ $customer_id ] ?? array(
				'orderIds'       => array(),
				'productCodes'   => array(),
				'orderCount'     => 0,
				'totalSpentRial' => 0,
				'lastOrderAt'    => null,
			);
			$customer['orderIds'][ $order['id'] ] = true;
			++$customer['orderCount'];
			$customer['totalSpentRial'] += $order['totalRial'];
			if (
				$customer['lastOrderAt'] === null
				|| strcmp( $order['createdAt'], $customer['lastOrderAt'] ) > 0
			) {
				$customer['lastOrderAt'] = $order['createdAt'];
			}
			foreach ( $order['_lines'] as $line ) {
				$code                                = $line['productCode'];
				$customer['productCodes'][ $code ]   = true;
				$product                             = $product_aggregates[ $code ] ?? array(
					'soldQuantity' => 0.0,
					'orderIds'     => array(),
				);
				$product['soldQuantity']            += $line['quantity'];
				$product['orderIds'][ $order['id'] ] = true;
				$product_aggregates[ $code ]         = $product;
			}
			$customer_aggregates[ $customer_id ] = $customer;
		}

		$order_items = array();
		foreach ( $orders as $order ) {
			unset( $order['_lines'] );
			$order_items[] = $order;
		}
		$customer_items = array();
		foreach ( $customer_bases as $customer_id => $base ) {
			$aggregate     = $customer_aggregates[ $customer_id ] ?? array(
				'orderIds'       => array(),
				'productCodes'   => array(),
				'orderCount'     => 0,
				'totalSpentRial' => 0,
				'lastOrderAt'    => null,
			);
			$order_ids     = array_slice(
				array_keys( $aggregate['orderIds'] ),
				0,
				self::MAX_ORDER_LINKS
			);
			$product_codes = array_slice(
				array_map(
					'strval',
					array_keys( $aggregate['productCodes'] )
				),
				0,
				self::MAX_PRODUCT_LINKS
			);
			sort( $order_ids, SORT_STRING );
			sort( $product_codes, SORT_NATURAL );
			$customer             = array(
				'id'             => $customer_id,
				'displayLabel'   => $base['displayLabel'],
				'orderCount'     => $aggregate['orderCount'],
				'totalSpentRial' => $aggregate['totalSpentRial'],
				'lastOrderAt'    => $aggregate['lastOrderAt'],
				'productCodes'   => $product_codes,
				'orderIds'       => $order_ids,
				'segment'        => self::customer_segment(
					$aggregate['orderCount'],
					$aggregate['lastOrderAt']
				),
			);
			$customer['revision'] = Revision::hash( $customer );
			$customer_items[]     = $customer;
		}
		usort(
			$customer_items,
			static fn( array $left, array $right ): int =>
				strcmp(
					(string) ( $right['lastOrderAt'] ?? '' ),
					(string) ( $left['lastOrderAt'] ?? '' )
				)
				?: strcmp( $left['id'], $right['id'] )
		);

		$product_sales = array();
		foreach ( $product_aggregates as $code => $aggregate ) {
			$order_ids = array_slice(
				array_keys( $aggregate['orderIds'] ),
				0,
				self::MAX_ORDER_LINKS
			);
			sort( $order_ids, SORT_STRING );
			$item             = array(
				'productCode'  => (string) $code,
				'soldQuantity' => max(
					0,
					round( (float) $aggregate['soldQuantity'], 6 )
				),
				'orderIds'     => $order_ids,
			);
			$item['revision'] = Revision::hash( $item );
			$product_sales[]  = $item;
		}
		usort(
			$product_sales,
			static fn( array $left, array $right ): int =>
				strnatcasecmp(
					(string) $left['productCode'],
					(string) $right['productCode']
				)
		);
		$normalized_revision_input = wp_json_encode(
			array(
				'orders'       => $order_items,
				'customers'    => $customer_items,
				'productSales' => $product_sales,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $normalized_revision_input ) ) {
			return self::invalid( 'normalized-revision' );
		}
		$normalized_source_revision =
			'sha256:' . hash( 'sha256', $normalized_revision_input );
		unset( $normalized_revision_input );

		return array(
			'schema'       => self::SCHEMA,
			'version'      => self::VERSION,
			'source'       => array(
				'id'          => self::SOURCE_ID,
				'dataset'     => self::SOURCE_DATASET,
				'revision'    => $normalized_source_revision,
				'generatedAt' => $generated_at,
			),
			'receivedAt'   => gmdate( 'c' ),
			'orders'       => $order_items,
			'customers'    => $customer_items,
			'productSales' => $product_sales,
		);
	}

	/**
	 * @param mixed $record
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_customer_base( $record ) {
		if (
			! is_array( $record )
			|| ! self::has_exact_keys(
				$record,
				array( 'id', 'displayLabel' )
			)
		) {
			return self::invalid( 'customer-shape' );
		}
		$id             = (string) ( $record['id'] ?? '' );
		$label          = sanitize_text_field( (string) ( $record['displayLabel'] ?? '' ) );
		$expected_label = self::customer_label_for_id( $id );
		if (
			! preg_match( '/^customer:patris:[a-f0-9]{24}$/D', $id )
			|| $expected_label === ''
			|| ! hash_equals( $expected_label, $label )
		) {
			return self::invalid( 'customer' );
		}
		return array(
			'id'           => $id,
			'displayLabel' => $expected_label,
		);
	}

	/**
	 * @param mixed $record
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_order( $record ) {
		if (
			! is_array( $record )
			|| ! self::has_exact_keys(
				$record,
				array(
					'id',
					'number',
					'createdAt',
					'totalRial',
					'customerId',
					'customerLabel',
					'lines',
					'revision',
				)
			)
			|| ! is_array( $record['lines'] ?? null )
			|| ! array_is_list( $record['lines'] )
			|| count( $record['lines'] ) < 1
			|| count( $record['lines'] ) > self::MAX_LINES_PER_ORDER
		) {
			return self::invalid( 'order-shape' );
		}
		$id                = (string) ( $record['id'] ?? '' );
		$number            = sanitize_text_field( (string) ( $record['number'] ?? '' ) );
		$customer_id       = (string) ( $record['customerId'] ?? '' );
		$customer_label    = sanitize_text_field(
			(string) ( $record['customerLabel'] ?? '' )
		);
		$created_at        = self::iso_time( (string) ( $record['createdAt'] ?? '' ) );
		$total_rial        = self::nonnegative_integer( $record['totalRial'] ?? null );
		$provided_revision = self::revision(
			(string) ( $record['revision'] ?? '' ),
			'rev:'
		);
		if (
			! preg_match( '/^order:patris:[a-f0-9]{24}$/D', $id )
			|| ! preg_match( '/^P-[A-F0-9]{8,16}$/D', $number )
			|| ! preg_match( '/^customer:patris:[a-f0-9]{24}$/D', $customer_id )
			|| ! hash_equals(
				self::customer_label_for_id( $customer_id ),
				$customer_label
			)
			|| $created_at === null
			|| $total_rial === null
			|| $provided_revision === ''
		) {
			return self::invalid( 'order' );
		}
		$lines         = array();
		$product_codes = array();
		$item_count    = 0.0;
		foreach ( $record['lines'] as $line ) {
			if (
				! is_array( $line )
				|| ! self::has_exact_keys(
					$line,
					array( 'productCode', 'quantity' )
				)
			) {
				return self::invalid( 'order-line-shape' );
			}
			$code     = Store::patris_code(
				(string) ( $line['productCode'] ?? '' )
			);
			$quantity = self::finite_number( $line['quantity'] ?? null );
			if (
				$code === ''
				|| $quantity === null
				|| $quantity == 0.0
				|| abs( $quantity ) > 1_000_000_000
			) {
				return self::invalid( 'order-line' );
			}
			$lines[]                = array(
				'productCode' => $code,
				'quantity'    => $quantity,
			);
			$product_codes[ $code ] = true;
			$item_count            += abs( $quantity );
		}
		$product_codes = array_map(
			'strval',
			array_keys( $product_codes )
		);
		sort( $product_codes, SORT_NATURAL );
		$normalized             = array(
			'id'            => $id,
			'number'        => $number,
			'status'        => 'completed',
			'createdAt'     => $created_at,
			'modifiedAt'    => $created_at,
			'totalRial'     => $total_rial,
			'itemCount'     => round( $item_count, 6 ),
			'customerId'    => $customer_id,
			'customerLabel' => $customer_label,
			'productCodes'  => $product_codes,
			'_lines'        => $lines,
		);
		$normalized['revision'] = Revision::hash(
			array(
				'id'            => $id,
				'number'        => $number,
				'createdAt'     => $created_at,
				'totalRial'     => $total_rial,
				'customerId'    => $customer_id,
				'customerLabel' => $customer_label,
				'lines'         => $lines,
			)
		);
		return $normalized;
	}

	/**
	 * @return array{published:int,failed:int,bootstrap:bool}
	 */
	private static function emit_projection_changes(
		array $previous,
		array $current
	): array {
		$previous_revision = (string) (
			$previous['source']['revision'] ?? ''
		);
		$published         = 0;
		$failed            = 0;
		$bootstrap         = $previous_revision === '';
		if ( $previous_revision === '' ) {
			$event_revision = Revision::hash(
				array( 'sourceRevision' => $current['source']['revision'] )
			);
			$result         = Events::emit(
				'catalog.patris_commerce_ready',
				'catalog',
				'catalog:patris-commerce',
				$event_revision,
				array( 'orders', 'customers', 'sales' ),
				array(
					'id'           => 'catalog:patris-commerce',
					'orders'       => count( $current['orders'] ),
					'customers'    => count( $current['customers'] ),
					'productSales' => count( $current['productSales'] ),
				)
			);
			if ( $result instanceof WP_Error ) {
				++$failed;
			} else {
				++$published;
			}
		}
		foreach (
			array(
				'order'    => array(
					'collection' => 'orders',
					'id'         => 'id',
				),
				'customer' => array(
					'collection' => 'customers',
					'id'         => 'id',
				),
			) as $entity_type => $definition
		) {
			$before = self::index(
				(array) ( $previous[ $definition['collection'] ] ?? array() ),
				$definition['id']
			);
			$after  = self::index(
				(array) ( $current[ $definition['collection'] ] ?? array() ),
				$definition['id']
			);
			foreach (
				self::entity_changes( $before, $after ) as $id => $change
			) {
				$value  = $change['type'] === 'deleted'
					? array(
						'id'              => $id,
						'lifecycleStatus' => 'trashed',
						'revision'        => $change['revision'],
					)
					: self::resolved_entity(
						$entity_type,
						$change['value']
					);
				$result = Events::emit(
					$entity_type . '.' . $change['type'],
					$entity_type,
					$id,
					$change['revision'],
					array( 'patris' ),
					$value
				);
				if ( $result instanceof WP_Error ) {
					++$failed;
				} else {
					++$published;
				}
			}
		}

		$before_sales = self::index(
			(array) ( $previous['productSales'] ?? array() ),
			'productCode'
		);
		$after_sales  = self::index(
			(array) ( $current['productSales'] ?? array() ),
			'productCode'
		);
		$codes        = array_values(
			array_unique(
				array_merge(
					array_keys( $before_sales ),
					array_keys( $after_sales )
				)
			)
		);
		sort( $codes, SORT_NATURAL );
		foreach ( $codes as $code ) {
			$product_code = (string) $code;
			$before       = $before_sales[ $code ] ?? null;
			$after        = $after_sales[ $code ] ?? null;
			if (
				is_array( $before )
				&& is_array( $after )
				&& ( $before['revision'] ?? '' ) === ( $after['revision'] ?? '' )
			) {
				continue;
			}
			$record    = is_array( $after )
				? $after
				: array(
					'productCode'  => $product_code,
					'soldQuantity' => 0,
					'orderIds'     => array(),
					'revision'     => Revision::hash(
						array(
							'productCode'  => $product_code,
							'salesRemoved' => true,
						)
					),
				);
			$entity_id = Live_State::canonical_product_id_for_patris(
				$product_code
			);
			$value     = self::resolved_product_sales( $record, $entity_id );
			$result    = Events::emit(
				'product.updated',
				'product',
				$entity_id,
				(string) ( $value['revision'] ?? $record['revision'] ),
				array( 'patris', 'sales' ),
				$value
			);
			if ( $result instanceof WP_Error ) {
				++$failed;
			} else {
				++$published;
			}
		}
		return array(
			'published' => $published,
			'failed'    => $failed,
			'bootstrap' => $bootstrap,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,array<string,mixed>>
	 */
	private static function index( array $items, string $key ): array {
		$index = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = (string) ( $item[ $key ] ?? '' );
			if ( $id !== '' ) {
				$index[ $id ] = $item;
			}
		}
		return $index;
	}

	/**
	 * @return array<string,array{type:string,revision:string,value:array<string,mixed>}>
	 */
	private static function entity_changes( array $before, array $after ): array {
		$changes = array();
		$ids     = array_values(
			array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) )
		);
		sort( $ids, SORT_STRING );
		foreach ( $ids as $id ) {
			$old = $before[ $id ] ?? null;
			$new = $after[ $id ] ?? null;
			if (
				is_array( $old )
				&& is_array( $new )
				&& ( $old['revision'] ?? '' ) === ( $new['revision'] ?? '' )
			) {
				continue;
			}
			if ( ! is_array( $new ) ) {
				$changes[ $id ] = array(
					'type'     => 'deleted',
					'revision' => Revision::hash(
						array(
							'id'      => $id,
							'deleted' => true,
						)
					),
					'value'    => array(),
				);
			} else {
				$changes[ $id ] = array(
					'type'     => is_array( $old ) ? 'updated' : 'created',
					'revision' => (string) $new['revision'],
					'value'    => $new,
				);
			}
		}
		return $changes;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function resolved_entity(
		string $entity_type,
		array $record
	): array {
		if ( $entity_type === 'order' ) {
			$product_ids = array_values(
				array_unique(
					array_map(
						static fn( string $code ): string =>
							Live_State::canonical_product_id_for_patris( $code ),
						(array) ( $record['productCodes'] ?? array() )
					)
				)
			);
			return array(
				'id'             => (string) $record['id'],
				'number'         => (string) $record['number'],
				'status'         => 'completed',
				'statusLabel'    => 'تکمیل‌شده',
				'createdAt'      => $record['createdAt'],
				'modifiedAt'     => $record['modifiedAt'],
				'totalRial'      => (int) $record['totalRial'],
				'totalLabel'     => self::rial_label(
					(int) $record['totalRial']
				),
				'itemCount'      => $record['itemCount'],
				'customerId'     => (string) $record['customerId'],
				'customerLabel'  => (string) $record['customerLabel'],
				'productIds'     => $product_ids,
				'source'         => 'patris',
				'revision'       => (string) $record['revision'],
				'allowedActions' => array( 'view' ),
			);
		}
		$product_ids = array_values(
			array_unique(
				array_map(
					static fn( string $code ): string =>
						Live_State::canonical_product_id_for_patris( $code ),
					(array) ( $record['productCodes'] ?? array() )
				)
			)
		);
		return array(
			'id'              => (string) $record['id'],
			'displayLabel'    => (string) $record['displayLabel'],
			'displayName'     => (string) $record['displayLabel'],
			'orderCount'      => (int) $record['orderCount'],
			'totalSpentRial'  => (int) $record['totalSpentRial'],
			'totalSpentLabel' => self::rial_label(
				(int) $record['totalSpentRial']
			),
			'lastOrderAt'     => $record['lastOrderAt'],
			'productIds'      => $product_ids,
			'orderIds'        => array_values( (array) $record['orderIds'] ),
			'segment'         => (string) $record['segment'],
			'source'          => 'patris',
			'revision'        => (string) $record['revision'],
			'allowedActions'  => array( 'view_orders' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function resolved_product_sales(
		array $record,
		string $entity_id
	): array {
		$label            = self::product_label( (string) $record['productCode'] );
		$catalog_revision = (string) $record['revision'];
		$woo_id           = Live_State::woo_id( $entity_id, 'product' );
		$woo_quantity     = 0.0;
		$woo_order_ids    = array();
		if ( $woo_id > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $woo_id );
			if ( $product instanceof \WC_Product ) {
				$catalog_revision = Revision::product( $product );
				$woo_quantity     = max(
					0,
					(float) $product->get_total_sales()
				);
				global $wpdb;
				$lookup        = $wpdb->prefix . 'wc_order_product_lookup';
				$where         = $wpdb->prepare(
					'(product_id = %d OR variation_id = %d)',
					$woo_id,
					$woo_id
				);
				$woo_ids       = $wpdb->get_col(
					"SELECT DISTINCT order_id
                    FROM {$lookup}
                    WHERE {$where}
                    ORDER BY date_created DESC, order_id DESC
                    LIMIT 1000"
				);
				$woo_order_ids = array_map(
					static fn( $id ): string =>
						Live_State::order_id( absint( $id ) ),
					(array) $woo_ids
				);
			}
		}
		$patris_quantity = max(
			0,
			(float) ( $record['soldQuantity'] ?? 0 )
		);
		$order_ids       = array_slice(
			array_values(
				array_unique(
					array_merge(
						$woo_order_ids,
						array_values( (array) $record['orderIds'] )
					)
				)
			),
			0,
			self::MAX_ORDER_LINKS
		);
		return array(
			'id'             => $entity_id,
			'name'           => $label['name'],
			'sku'            => $label['sku'],
			'soldQuantity'   => round(
				$woo_quantity + $patris_quantity,
				6
			),
			'orderIds'       => $order_ids,
			'salesBySource'  => array(
				'woocommerce' => $woo_quantity,
				'patris'      => $patris_quantity,
			),
			'source'         => $entity_id ===
				'product:patris:' . rawurlencode( (string) $record['productCode'] )
					? 'patris'
					: 'merged',
			'revision'       => $catalog_revision,
			'salesRevision'  => (string) $record['revision'],
			'salesSemantics' =>
				'additive-no-cross-source-order-deduplication',
		);
	}

	/**
	 * @return array{name:string,sku:string}
	 */
	private static function product_label( string $code ): array {
		static $labels = null;
		if ( ! is_array( $labels ) ) {
			$labels = array();
			$state  = get_option( 'digitalogic_product_sync_state', array() );
			foreach ( (array) ( $state['sources'] ?? array() ) as $source ) {
				foreach ( (array) ( $source['products'] ?? array() ) as $record ) {
					if ( ! is_array( $record ) ) {
						continue;
					}
					$candidate = Store::patris_code(
						(string) ( $record['product_code'] ?? '' )
					);
					if ( $candidate === '' ) {
						continue;
					}
					$labels[ $candidate ] = array(
						'name' => sanitize_text_field(
							(string) ( $record['name'] ?? $candidate )
						),
						'sku'  => sanitize_text_field(
							(string) ( $record['serial'] ?? '' )
						),
					);
				}
			}
		}
		return $labels[ $code ] ?? array(
			'name' => 'کالای پاتریس',
			'sku'  => $code,
		);
	}

	private static function customer_segment(
		int $order_count,
		?string $last_order_at
	): string {
		if ( $order_count <= 1 ) {
			return 'new';
		}
		if ( $order_count >= 10 ) {
			return 'loyal';
		}
		$last = $last_order_at ? strtotime( $last_order_at ) : false;
		return $last && $last < time() - 365 * DAY_IN_SECONDS
			? 'inactive'
			: 'returning';
	}

	private static function customer_label_for_id( string $id ): string {
		if (
			! preg_match(
				'/^customer:patris:([a-f0-9]{24})$/D',
				$id,
				$match
			)
		) {
			return '';
		}
		return 'مشتری پاتریس · ' . strtoupper( substr( $match[1], 0, 6 ) );
	}

	private static function iso_time( string $value ): ?string {
		try {
			$parsed = new DateTimeImmutable( $value );
		} catch ( Throwable $error ) {
			return null;
		}
		return $parsed->format( 'c' );
	}

	private static function revision( string $value, string $prefix ): string {
		$pattern = $prefix === 'sha256:'
			? '/^sha256:[a-f0-9]{64}$/D'
			: '/^rev:[a-f0-9]{64}$/D';
		return preg_match( $pattern, strtolower( $value ) )
			? strtolower( $value )
			: '';
	}

	/**
	 * @param mixed $value
	 */
	private static function nonnegative_integer( $value ): ?int {
		if (
			( ! is_int( $value ) && ! is_float( $value ) )
			|| ! is_finite( (float) $value )
			|| $value < 0
			|| $value > PHP_INT_MAX
		) {
			return null;
		}
		return (int) round( (float) $value );
	}

	/**
	 * @param mixed $value
	 */
	private static function finite_number( $value ): ?float {
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) ) {
			return null;
		}
		return (float) $value;
	}

	private static function rial_label( int $amount ): string {
		return number_format_i18n( $amount, 0 ) . ' ریال';
	}

	/**
	 * @param string[] $expected
	 */
	private static function has_exact_keys( array $value, array $expected ): bool {
		$actual = array_map( 'strval', array_keys( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $actual === $expected;
	}

	private static function invalid( string $field ): WP_Error {
		return new WP_Error(
			'digitalogic_patris_commerce_invalid',
			__( 'The Patris commerce projection is invalid.', 'digitalogic-viewer-bridge' ),
			array(
				'status' => 400,
				'field'  => sanitize_key( $field ),
			)
		);
	}
}
