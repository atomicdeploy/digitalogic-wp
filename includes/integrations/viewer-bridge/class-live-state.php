<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use WC_Order;
use WC_Product;
use WP_Query;
use WP_REST_Request;
use WP_Term;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds bounded responses directly from the current WordPress, WooCommerce,
 * Patris-option, menu, and Redis state. No response is cached or persisted.
 */
final class Live_State {

	public const ROOT_CATEGORY_ID       = 'cat:root';
	public const PRODUCT_TRASH_STATUS   = 'viewer-trash';
	private const MAX_PRODUCT_LIMIT     = 1500;
	private const MAX_ORDER_LIMIT       = 100;
	private const MAX_CUSTOMER_LIMIT    = 100;
	private const MAX_TIMELINE_LIMIT    = 250;
	private const MAX_ATTRIBUTES        = 24;
	private const MAX_ATTRIBUTE_OPTIONS = 24;

	/**
	 * @return array<string,mixed>
	 */
	public static function build( WP_REST_Request $request ): array {
		$started  = microtime( true );
		$errors   = array();
		$warnings = array();

		$product_page   = self::bounded_int( $request->get_param( 'productPage' ), 1, 1, 100000 );
		$product_limit  = self::bounded_int(
			$request->get_param( 'productLimit' ),
			150,
			1,
			self::MAX_PRODUCT_LIMIT
		);
		$order_page     = self::bounded_int( $request->get_param( 'orderPage' ), 1, 1, 100000 );
		$order_limit    = self::bounded_int(
			$request->get_param( 'orderLimit' ),
			50,
			1,
			self::MAX_ORDER_LIMIT
		);
		$customer_page  = self::bounded_int( $request->get_param( 'customerPage' ), 1, 1, 100000 );
		$customer_limit = self::bounded_int(
			$request->get_param( 'customerLimit' ),
			50,
			1,
			self::MAX_CUSTOMER_LIMIT
		);
		$timeline_limit = self::bounded_int(
			$request->get_param( 'timelineLimit' ),
			120,
			1,
			self::MAX_TIMELINE_LIMIT
		);

		$patris                   = self::patris_context( $errors, $warnings );
		$category_context         = Canonical_Taxonomy::build( $patris, $errors, $warnings );
		$include_trashed_products = rest_sanitize_boolean(
			$request->get_param( 'includeTrashedProducts' )
		);
		$product_context          = self::product_index(
			$patris,
			$category_context,
			$errors,
			$include_trashed_products
		);
		$orders                   = self::orders( $order_page, $order_limit, $errors );

		self::apply_category_counts(
			$category_context['nodes'],
			$product_context['directSets'],
			$product_context['allIds'],
			$product_context['rootDirect'],
			$product_context['inventoryByProduct'],
			$warnings
		);

		$product_slice = array_slice(
			$product_context['descriptors'],
			( $product_page - 1 ) * $product_limit,
			$product_limit
		);
		$products      = self::serialize_products(
			$product_slice,
			$product_context,
			$patris,
			$orders['productOrders'],
			$orders['patrisProductSales'],
			$errors
		);
		$customers     = self::customers(
			$customer_page,
			$customer_limit,
			$orders,
			$errors
		);
		$timeline      = self::timeline( $orders['items'], $products, $timeline_limit );
		$kanban        = self::kanban( $orders['items'] );
		$redis_health  = Redis::health();
		if ( ( $orders['patrisSourceState'] ?? 'unavailable' ) !== 'live' ) {
			$errors[] = self::error(
				'patris_commerce_realtime_unavailable',
				'patris-commerce',
				__(
					'The Patris order and customer event source is unavailable or stale.',
					'digitalogic-viewer-bridge'
				),
				true,
				array(
					'sourceState' =>
						(string) ( $orders['patrisSourceState'] ?? 'unavailable' ),
				)
			);
		}
		if ( ! $redis_health['connected'] || ! $redis_health['relayConnected'] ) {
			$errors[] = self::error(
				$redis_health['connected']
					? 'realtime_relay_unavailable'
					: 'realtime_unavailable',
				'realtime',
				__(
					'Live data is current, but the realtime relay is unavailable.',
					'digitalogic-viewer-bridge'
				),
				true
			);
		}

		$categories = array_values( $category_context['nodes'] );
		usort(
			$categories,
			static function ( array $left, array $right ): int {
				return ( (int) ( $left['_order'] ?? PHP_INT_MAX ) )
					<=> ( (int) ( $right['_order'] ?? PHP_INT_MAX ) );
			}
		);
		$categories = array_map(
			static function ( array $node ): array {
				$node['presentationOrder'] =
					(int) ( $node['_order'] ?? 999999 );
				unset( $node['_order'] );
				return $node;
			},
			$categories
		);

		$generated_at = gmdate( 'c' );
		$state        = empty( $errors ) ? 'live' : 'partial';
		$duration_ms  = (int) round( ( microtime( true ) - $started ) * 1000 );

		return array(
			'schemaVersion'        => 'digitalogic.viewer.live-state.v1',
			'dataStatus'           => array(
				'state'      => $state,
				'mode'       => 'live-on-demand',
				'isFallback' => false,
				'errors'     => array_values( $errors ),
				'warnings'   => array_values( $warnings ),
				'sources'    => array(
					'woocommerce'    => array(
						'state'      => function_exists( 'WC' ) ? 'live' : 'unavailable',
						'observedAt' => $generated_at,
					),
					'wordpressMenu'  => array(
						'state'      => $category_context['menuFound'] ? 'live' : 'unavailable',
						'observedAt' => $generated_at,
						'menuSlug'   => 'digitalogic-product-categories',
					),
					'patris'         => array(
						'state'      => $patris['available'] ? 'live' : 'unavailable',
						'observedAt' => $patris['receivedAt'],
					),
					'patrisCommerce' => array(
						'state'             =>
							(string) ( $orders['patrisSourceState'] ?? 'unavailable' ),
						'observedAt'        => $orders['patrisReceivedAt'] ?? null,
						'sourceGeneratedAt' =>
							$orders['patrisGeneratedAt'] ?? null,
						'transport'         => 'shared-redis-event-mesh',
					),
					'orders'         => array(
						'state'       =>
							( $orders['patrisSourceState'] ?? '' ) === 'live'
								? 'live'
								: 'partial',
						'observedAt'  => $generated_at,
						'empty'       => $orders['total'] === 0,
						'emptyReason' => $orders['total'] === 0 ? 'no-live-orders' : null,
					),
					'realtime'       => array(
						'state'           => $redis_health['connected']
							&& $redis_health['relayConnected']
								? 'live'
								: 'unavailable',
						'observedAt'      => $generated_at,
						'relayObservedAt' => $redis_health['relayObservedAt'],
					),
				),
			),
			'errors'               => array_values( $errors ),
			'freshness'            => array(
				'generatedAt'               => $generated_at,
				'patrisReceivedAt'          => $patris['receivedAt'],
				'patrisCommerceReceivedAt'  =>
					$orders['patrisReceivedAt'] ?? null,
				'patrisCommerceGeneratedAt' =>
					$orders['patrisGeneratedAt'] ?? null,
				'durationMs'                => $duration_ms,
				'cache'                     => 'none',
			),
			'coverage'             => array(
				'categories' => array(
					'canonical'      => count( $categories ),
					'woocommerce'    => $category_context['wooCount'],
					'menuItems'      => $category_context['menuItemCount'],
					'patris'         => count( $patris['categories'] ),
					'patrisMapped'   => $category_context['mappedPatrisCount'],
					'patrisUnmapped' => $category_context['unmappedPatrisCount'],
				),
				'products'   => array(
					'canonical'                  => count( $product_context['descriptors'] ),
					'woocommerce'                => $product_context['wooCount'],
					'woocommerceTrashedIncluded' => $product_context['trashedWooCount'],
					'patris'                     => count( $patris['products'] ),
					'patrisSourceOnly'           => $product_context['patrisOnlyCount'],
				),
				'inventory'  => $product_context['inventoryCoverage'],
				'orders'     => array(
					'live'        => $orders['total'],
					'woocommerce' => $orders['woocommerceTotal'],
					'patris'      => $orders['patrisTotal'],
				),
				'sales'      => array(
					'patrisProductsLinked' =>
						count( $orders['patrisProductSales'] ),
					'combination'          =>
						'additive-no-cross-source-order-deduplication',
				),
				'customers'  => array(
					'live'        => $customers['total'],
					'woocommerce' => $customers['woocommerceTotal'],
					'patris'      => $customers['patrisTotal'],
				),
			),
			'pagination'           => array(
				'products'  => self::pagination(
					$product_page,
					$product_limit,
					count( $product_context['descriptors'] )
				),
				'orders'    => self::pagination( $order_page, $order_limit, $orders['total'] ),
				'customers' => self::pagination(
					$customer_page,
					$customer_limit,
					$customers['total']
				),
			),
			'categories'           => $categories,
			'categoryAliases'      => $category_context['aliases'],
			'categoryConflicts'    => $category_context['conflicts'],
			'canonicalPolicy'      => $category_context['policy'],
			'categoryPresentation' => $category_context['presentation'],
			'products'             => $products,
			'orders'               => $orders['items'],
			'customers'            => $customers['items'],
			'timeline'             => $timeline,
			'kanbanColumns'        => $kanban['columns'],
			'kanbanCards'          => $kanban['cards'],
			'websocket'            => array(
				'url'           => self::websocket_url(),
				'token'         => null,
				'expiresAt'     => null,
				'tokenEndpoint' => rest_url( 'digitalogic-viewer/v1/realtime-token' ),
				'protocol'      => 'digitalogic-viewer-v1',
				'connected'     => $redis_health['connected']
					&& $redis_health['relayConnected'],
			),
		);
	}

	/**
	 * Rebuild the canonical category context for guarded mutation checks.
	 *
	 * @return array<string,mixed>
	 */
	public static function canonical_context_for_actions(): array {
		$errors              = array();
		$warnings            = array();
		$patris              = self::patris_context( $errors, $warnings );
		$context             = Canonical_Taxonomy::build( $patris, $errors, $warnings );
		$context['errors']   = $errors;
		$context['warnings'] = $warnings;
		return $context;
	}

	/**
	 * Return one complete canonical category summary for a realtime upsert.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function category_event_value( string $canonical_id ): ?array {
		return self::category_event_values()[ $canonical_id ] ?? null;
	}

	/**
	 * Build every canonical category summary once for batch event emission.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function category_event_values(): array {
		$errors           = array();
		$warnings         = array();
		$patris           = self::patris_context( $errors, $warnings );
		$category_context = Canonical_Taxonomy::build(
			$patris,
			$errors,
			$warnings
		);
		$product_context  = self::product_index(
			$patris,
			$category_context,
			$errors,
			true
		);
		self::apply_category_counts(
			$category_context['nodes'],
			$product_context['directSets'],
			$product_context['allIds'],
			$product_context['rootDirect'],
			$product_context['inventoryByProduct'],
			$warnings
		);
		$values = array();
		foreach ( $category_context['nodes'] as $canonical_id => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node['presentationOrder'] =
				(int) ( $node['_order'] ?? 999999 );
			unset( $node['_order'] );
			$values[ (string) $canonical_id ] = $node;
		}
		return $values;
	}

	/**
	 * Return one complete canonical product summary for a realtime upsert.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function product_event_value( string $canonical_id ): ?array {
		return self::product_event_values( array( $canonical_id ) )[ $canonical_id ]
			?? null;
	}

	/**
	 * Build selected product summaries from one shared catalog/order read.
	 *
	 * @param string[] $canonical_ids
	 * @return array<string,array<string,mixed>>
	 */
	public static function product_event_values( array $canonical_ids ): array {
		// Prime only the requested Woo entities in bounded batches before
		// inventory and serialization read the same posts and metadata.
		$woo_ids = array();
		foreach ( $canonical_ids as $canonical_id ) {
			if ( preg_match( '/\Aproduct:woo:([1-9][0-9]*)\z/D', (string) $canonical_id, $match ) === 1 ) {
				$woo_ids[] = (int) $match[1];
			}
		}
		foreach ( array_chunk( array_values( array_unique( $woo_ids ) ), 250 ) as $chunk ) {
			_prime_post_caches( $chunk, true, true );
		}
		$wanted = array_fill_keys(
			array_values(
				array_filter(
					array_map( 'strval', $canonical_ids )
				)
			),
			true
		);
		if ( ! $wanted ) {
			return array();
		}
		// Reuse objects only during this event projection, never in a payload
		// or across operations. Inventory already loads every selected leaf.
		$event_products   = array();
		$errors           = array();
		$warnings         = array();
		$patris           = self::patris_context( $errors, $warnings );
		$category_context = Canonical_Taxonomy::build(
			$patris,
			$errors,
			$warnings
		);
		$product_context  = self::product_index(
			$patris,
			$category_context,
			$errors,
			true,
			$wanted,
			$event_products
		);
		$descriptors      = array();
		foreach ( $product_context['descriptors'] as $candidate ) {
			if ( isset( $wanted[ (string) ( $candidate['id'] ?? '' ) ] ) ) {
				$descriptors[] = $candidate;
			}
		}
		if ( ! $descriptors ) {
			return array();
		}
		$orders     = self::orders( 1, self::MAX_ORDER_LIMIT, $errors );
		$serialized = self::serialize_products(
			$descriptors,
			$product_context,
			$patris,
			$orders['productOrders'],
			$orders['patrisProductSales'],
			$errors,
			$event_products
		);
		$values     = array();
		foreach ( $serialized as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$values[ (string) $item['id'] ] = $item;
			}
		}
		return $values;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function order_event_value( WC_Order $order ): array {
		return self::serialize_order( $order );
	}

	/**
	 * Return a PII-free customer profile summary.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function customer_event_value( int $user_id ): ?array {
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return null;
		}
		$order_count = function_exists( 'wc_get_customer_order_count' )
			? max( 0, (int) wc_get_customer_order_count( $user_id ) )
			: 0;
		$total_spent = function_exists( 'wc_get_customer_total_spent' )
			? (string) wc_get_customer_total_spent( $user_id )
			: '0';
		$last        = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);
		$last_order  =
			is_array( $last )
			&& isset( $last[0] )
			&& $last[0] instanceof WC_Order
				? $last[0]
				: null;
		$last_date   = $last_order ? $last_order->get_date_created() : null;
		$segment     = sanitize_key(
			(string) get_user_meta(
				$user_id,
				'_digitalogic_viewer_segment',
				true
			)
		);
		if ( ! in_array( $segment, self::customer_segments(), true ) ) {
			$segment = self::derived_customer_segment( $order_count, $last_date );
		}
		$recent_orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 100,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'ids',
			)
		);
		$order_ids     = array_map(
			static fn( $id ): string => self::order_id( absint( $id ) ),
			array_values( array_filter( array_map( 'absint', (array) $recent_orders ) ) )
		);
		return array(
			'id'              => self::customer_id( $user_id ),
			'displayLabel'    => sprintf( 'مشتری #%d', $user_id ),
			'displayName'     => sprintf( 'مشتری #%d', $user_id ),
			'orderCount'      => $order_count,
			'totalSpentLabel' => self::money_label(
				$total_spent,
				get_woocommerce_currency()
			),
			'lastOrderAt'     => $last_date ? $last_date->date( 'c' ) : null,
			'segment'         => $segment,
			'orderIds'        => $order_ids,
			'revision'        => Revision::customer( $user_id ),
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability is registered by Store::add_capabilities during activation.
			'allowedActions'  => current_user_can( 'digitalogic_viewer_manage' )
				? array( 'view_orders', 'customer.set_segment' )
				: array( 'view_orders' ),
		);
	}

	/**
	 * Resolve a canonical ID to the matching PII-free event summary.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function event_value_by_id(
		string $entity_type,
		string $entity_id
	): ?array {
		if ( $entity_type === 'category' ) {
			return self::category_event_value( $entity_id );
		}
		if ( $entity_type === 'product' ) {
			return self::product_event_value( $entity_id );
		}
		if ( $entity_type === 'order' ) {
			if ( str_starts_with( $entity_id, 'order:patris:' ) ) {
				foreach ( Patris_Commerce::state()['orders'] as $record ) {
					if (
						is_array( $record )
						&& (string) ( $record['id'] ?? '' ) === $entity_id
					) {
						return Patris_Commerce::resolved_entity( 'order', $record );
					}
				}
				return null;
			}
			$order = wc_get_order( self::woo_id( $entity_id, 'order' ) );
			return $order instanceof WC_Order
				? self::order_event_value( $order )
				: null;
		}
		if ( $entity_type === 'customer' ) {
			if ( str_starts_with( $entity_id, 'customer:patris:' ) ) {
				foreach ( Patris_Commerce::state()['customers'] as $record ) {
					if (
						is_array( $record )
						&& (string) ( $record['id'] ?? '' ) === $entity_id
					) {
						return Patris_Commerce::resolved_entity( 'customer', $record );
					}
				}
				return null;
			}
			return self::customer_event_value(
				self::woo_id( $entity_id, 'customer' )
			);
		}
		return null;
	}

	/**
	 * A dedicated bounded live query for product -> order relationships.
	 *
	 * @return array<string,mixed>
	 */
	public static function product_orders(
		string $canonical_product_id,
		int $page,
		int $limit
	): array {
		global $wpdb;
		$product_id = self::woo_id( $canonical_product_id, 'product' );
		$limit      = max( 1, min( self::MAX_ORDER_LIMIT, $limit ) );
		$page       = max( 1, $page );

		$window    = min( 5000, max( $limit, $page * $limit ) );
		$woo_total = 0;
		$woo_items = array();
		if ( $product_id > 0 ) {
			$lookup    = $wpdb->prefix . 'wc_order_product_lookup';
			$where     = $wpdb->prepare(
				'(product_id = %d OR variation_id = %d)',
				$product_id,
				$product_id
			);
			$woo_total = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT order_id) FROM {$lookup} WHERE {$where}"
			);
			$ids       = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT order_id
                    FROM {$lookup}
                    WHERE {$where}
                    ORDER BY date_created DESC, order_id DESC
                    LIMIT %d",
					$window
				)
			);
		} else {
			$ids = array();
		}
		foreach ( $ids as $id ) {
			$order = wc_get_order( absint( $id ) );
			if ( $order instanceof WC_Order ) {
				$woo_items[] = self::serialize_order( $order );
			}
		}

		$patris_state     = Patris_Commerce::state();
		$patris_order_ids = array();
		foreach ( (array) ( $patris_state['productSales'] ?? array() ) as $sale ) {
			if ( ! is_array( $sale ) ) {
				continue;
			}
			$code = Store::patris_code(
				(string) ( $sale['productCode'] ?? '' )
			);
			if (
				$code === ''
				|| self::canonical_product_id_for_patris( $code )
					!== $canonical_product_id
			) {
				continue;
			}
			foreach ( (array) ( $sale['orderIds'] ?? array() ) as $order_id ) {
				$patris_order_ids[ (string) $order_id ] = true;
			}
		}
		$patris_items = array();
		foreach ( (array) ( $patris_state['orders'] ?? array() ) as $record ) {
			if (
				! is_array( $record )
				|| ! isset( $patris_order_ids[ (string) ( $record['id'] ?? '' ) ] )
			) {
				continue;
			}
			$patris_items[] = Patris_Commerce::resolved_entity(
				'order',
				$record
			);
		}

		$merged = array_merge( $woo_items, $patris_items );
		usort(
			$merged,
			static fn( array $left, array $right ): int =>
				strcmp(
					(string) ( $right['createdAt'] ?? '' ),
					(string) ( $left['createdAt'] ?? '' )
				)
				?: strcmp( (string) $left['id'], (string) $right['id'] )
		);
		$total               = $woo_total + count( $patris_items );
		$items               = array_slice(
			$merged,
			( $page - 1 ) * $limit,
			$limit
		);
		$patris_connected    = Patris_Commerce::connected();
		$patris_revision     = (string) (
			$patris_state['source']['revision'] ?? ''
		);
		$patris_source_state = $patris_revision === ''
			? 'unavailable'
			: ( $patris_connected ? 'live' : 'stale' );
		return array(
			'dataStatus' => array(
				'state'      => $patris_source_state === 'live'
					? 'live'
					: 'partial',
				'mode'       => 'live-on-demand',
				'isFallback' => false,
				'sources'    => array(
					'woocommerce'    => array( 'state' => 'live' ),
					'patrisCommerce' => array(
						'state'      => $patris_source_state,
						'observedAt' => $patris_state['receivedAt'] ?? null,
					),
				),
			),
			'errors'     => array(),
			'freshness'  => array(
				'generatedAt'              => gmdate( 'c' ),
				'patrisCommerceReceivedAt' =>
					$patris_state['receivedAt'] ?? null,
				'cache'                    => 'none',
			),
			'coverage'   => array(
				'woocommerceOrders' => $woo_total,
				'patrisOrders'      => count( $patris_items ),
			),
			'orders'     => $items,
			'pagination' => self::pagination( $page, $limit, $total ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $errors
	 * @param array<int,array<string,mixed>> $warnings
	 * @return array<string,mixed>
	 */
	private static function patris_context( array &$errors, array &$warnings ): array {
		$state   = get_option( 'digitalogic_product_sync_state', array() );
		$context = array(
			'available'       => false,
			'receivedAt'      => null,
			'categories'      => array(),
			'products'        => array(),
			'applied'         => array(),
			'sourceRefs'      => array(),
			'localCurrencies' => array(),
		);
		if ( ! is_array( $state ) || ! is_array( $state['sources'] ?? null ) ) {
			$errors[] = self::error(
				'patris_state_unavailable',
				'patris',
				__( 'The normalized Patris state is unavailable.', 'digitalogic-viewer-bridge' ),
				true
			);
			return $context;
		}

		foreach ( $state['sources'] as $source_index => $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$context['available'] = true;
			$source_data          = is_array( $source['source'] ?? null ) ? $source['source'] : array();
			$source_id            = sanitize_key( (string) ( $source_data['id'] ?? 'patris' ) );
			if ( $source_id === '' ) {
				$source_id = 'patris';
			}
			$context['sourceRefs'][] = 'patris:source:' . $source_id;
			$local_currency          = strtoupper(
				sanitize_key( (string) ( $source['local_currency'] ?? '' ) )
			);
			if ( $local_currency !== '' ) {
				$context['localCurrencies'][ $local_currency ] = true;
			}
			$received = (string) ( $source['received_at'] ?? $source['generated_at'] ?? '' );
			if ( $received !== '' ) {
				$context['receivedAt'] = self::iso_time( $received );
			}

			foreach ( (array) ( $source['categories'] ?? array() ) as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$code = Store::patris_code( (string) ( $record['category_code'] ?? '' ) );
				if ( $code === '' ) {
					continue;
				}
				if ( isset( $context['categories'][ $code ] ) ) {
					$warnings[] = self::warning(
						'patris_category_code_repeated_across_sources',
						'patris',
						$code
					);
					continue;
				}
				$record['_sourceId']            = $source_id;
				$context['categories'][ $code ] = $record;
			}
			foreach ( (array) ( $source['products'] ?? array() ) as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$code = Store::patris_code( (string) ( $record['product_code'] ?? '' ) );
				if ( $code === '' ) {
					continue;
				}
				if ( isset( $context['products'][ $code ] ) ) {
					$warnings[] = self::warning(
						'patris_product_code_repeated_across_sources',
						'patris',
						$code
					);
					continue;
				}
				$record['_sourceId']          = $source_id;
				$record['_localCurrency']     = $local_currency;
				$context['products'][ $code ] = $record;
			}
			foreach ( (array) ( $source['applied_products'] ?? array() ) as $code => $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$product_code = Store::patris_code(
					(string) ( $record['product_code'] ?? $code )
				);
				$woo_id       = absint( $record['woocommerce_id'] ?? 0 );
				if ( $product_code !== '' && $woo_id > 0 ) {
					$context['applied'][ $product_code ] = $woo_id;
				}
			}
		}

		$context['localCurrencies'] = array_keys( $context['localCurrencies'] );
		sort( $context['localCurrencies'], SORT_STRING );
		return $context;
	}

	/**
	 * @param array<string,mixed>            $patris
	 * @param array<int,array<string,mixed>> $errors
	 * @param array<int,array<string,mixed>> $warnings
	 * @return array<string,mixed>
	 */
	private static function category_context(
		array $patris,
		array &$errors,
		array &$warnings
	): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$errors[] = self::error(
				'woocommerce_categories_unavailable',
				'woocommerce',
				__( 'WooCommerce categories could not be read.', 'digitalogic-viewer-bridge' ),
				false
			);
			$terms    = array();
		}

		$terms_by_id         = array();
		$term_by_patris_code = array();
		foreach ( (array) $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$terms_by_id[ (int) $term->term_id ] = $term;
			$code                                = Store::patris_code(
				(string) get_term_meta(
					$term->term_id,
					'_digitalogic_patris_category_code',
					true
				)
			);
			if ( $code !== '' ) {
				if ( isset( $term_by_patris_code[ $code ] ) ) {
					$errors[] = self::error(
						'patris_category_mapping_ambiguous',
						'canonical-map',
						__( 'More than one Woo category claims a Patris code.', 'digitalogic-viewer-bridge' ),
						false,
						array( 'sourceRef' => 'patris:category:' . $code )
					);
				} else {
					$term_by_patris_code[ $code ] = (int) $term->term_id;
				}
			}
		}

		foreach ( Store::mappings() as $code => $mapping ) {
			$term_id = absint( $mapping['termId'] ?? 0 );
			if ( ! isset( $patris['categories'][ $code ] ) || ! isset( $terms_by_id[ $term_id ] ) ) {
				$warnings[] = self::warning(
					'reviewed_mapping_target_unavailable',
					'canonical-map',
					$code
				);
				continue;
			}
			if ( isset( $term_by_patris_code[ $code ] ) && $term_by_patris_code[ $code ] !== $term_id ) {
				$warnings[] = self::warning(
					'core_mapping_overrides_viewer_mapping',
					'canonical-map',
					$code
				);
				continue;
			}
			$term_by_patris_code[ $code ] = $term_id;
		}

		$menu                = wp_get_nav_menu_object( 'digitalogic-product-categories' );
		$menu_items          = $menu ? wp_get_nav_menu_items( (int) $menu->term_id ) : array();
		$menu_by_item        = array();
		$menu_parent_by_term = array();
		$menu_order_by_term  = array();
		foreach ( (array) $menu_items as $item ) {
			$menu_by_item[ (int) $item->ID ] = $item;
		}
		$menu_position = 1;
		foreach ( (array) $menu_items as $item ) {
			if ( $item->type !== 'taxonomy' || $item->object !== 'product_cat' ) {
				continue;
			}
			$term_id        = (int) $item->object_id;
			$parent_item_id = (int) $item->menu_item_parent;
			$parent_term_id = 0;
			if ( $parent_item_id > 0 && isset( $menu_by_item[ $parent_item_id ] ) ) {
				$parent = $menu_by_item[ $parent_item_id ];
				if ( $parent->type === 'taxonomy' && $parent->object === 'product_cat' ) {
					$parent_term_id = (int) $parent->object_id;
				}
			}
			$menu_parent_by_term[ $term_id ] = $parent_term_id;
			$menu_order_by_term[ $term_id ]  = $menu_position++;
		}

		$nodes = array(
			self::ROOT_CATEGORY_ID => array(
				'id'                     => self::ROOT_CATEGORY_ID,
				'name'                   => 'کاتالوگ دیجیتالاجیک',
				'parentId'               => null,
				'directProductCount'     => 0,
				'descendantProductCount' => 0,
				'descendantCount'        => 0,
				'sourceRefs'             => array( 'digitalogic:catalog-root' ),
				'iconKey'                => 'digitalogic',
				'revision'               => Revision::hash( array( 'root' => 'digitalogic-catalog' ) ),
				'_order'                 => 0,
			),
		);
		foreach ( $terms_by_id as $term_id => $term ) {
			$id             = self::category_id( $term_id );
			$parent_term_id = array_key_exists( $term_id, $menu_parent_by_term )
				? (int) $menu_parent_by_term[ $term_id ]
				: (int) $term->parent;
			$parent_id      = $parent_term_id > 0 && isset( $terms_by_id[ $parent_term_id ] )
				? self::category_id( $parent_term_id )
				: self::ROOT_CATEGORY_ID;
			$source_refs    = array( 'woocommerce:product_cat:' . $term_id );
			if ( array_key_exists( $term_id, $menu_order_by_term ) ) {
				// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Stable machine identifier, not a display label.
				$source_refs[] = 'wordpress:menu:digitalogic-product-categories';
			}
			$nodes[ $id ] = array(
				'id'                     => $id,
				'name'                   => sanitize_text_field( (string) $term->name ),
				'parentId'               => $parent_id,
				'directProductCount'     => 0,
				'descendantProductCount' => 0,
				'descendantCount'        => 0,
				'sourceRefs'             => $source_refs,
				'iconKey'                => self::category_icon( $term ),
				'revision'               => Revision::category( $term ),
				'_order'                 => $menu_order_by_term[ $term_id ] ?? ( 10000 + $term_id ),
			);
		}

		$mapped   = 0;
		$unmapped = 0;
		foreach ( $patris['categories'] as $code => $record ) {
			$term_id = absint( $term_by_patris_code[ $code ] ?? 0 );
			if ( $term_id > 0 && isset( $nodes[ self::category_id( $term_id ) ] ) ) {
				++$mapped;
				$node_id                           = self::category_id( $term_id );
				$nodes[ $node_id ]['sourceRefs'][] = 'patris:category:' . $code;
				$nodes[ $node_id ]['sourceRefs']   = array_values(
					array_unique( $nodes[ $node_id ]['sourceRefs'] )
				);
				continue;
			}
			++$unmapped;
			$node_id     = self::patris_category_id( $code );
			$parent_code = Store::patris_code( (string) ( $record['parent_code'] ?? '' ) );
			if ( $parent_code !== '' && isset( $term_by_patris_code[ $parent_code ] ) ) {
				$parent_id = self::category_id( (int) $term_by_patris_code[ $parent_code ] );
			} elseif ( $parent_code !== '' && isset( $patris['categories'][ $parent_code ] ) ) {
				$parent_id = self::patris_category_id( $parent_code );
			} else {
				$parent_id = self::ROOT_CATEGORY_ID;
			}
			$nodes[ $node_id ] = array(
				'id'                     => $node_id,
				'name'                   => sanitize_text_field( (string) ( $record['name'] ?? $code ) ),
				'parentId'               => $parent_id,
				'directProductCount'     => 0,
				'descendantProductCount' => 0,
				'descendantCount'        => 0,
				'sourceRefs'             => array( 'patris:category:' . $code ),
				'iconKey'                => self::icon_from_text( (string) ( $record['name'] ?? '' ) ),
				'revision'               => self::patris_revision( $record ),
				'_order'                 => 20000 + $unmapped,
			);
		}

		return array(
			'nodes'               => $nodes,
			'termsById'           => $terms_by_id,
			'patrisToTerm'        => $term_by_patris_code,
			'wooCount'            => count( $terms_by_id ),
			'menuFound'           => (bool) $menu,
			'menuItemCount'       => count( $menu_order_by_term ),
			'mappedPatrisCount'   => $mapped,
			'unmappedPatrisCount' => $unmapped,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function product_index(
		array $patris,
		array $category_context,
		array &$errors,
		bool $include_trashed_products = false,
		?array $inventory_ids = null,
		?array &$event_products = null
	): array {
		// Identity follows the reviewed canonical policy: published Woo
		// products are `product:woo:<id>`, applied Patris products reuse that
		// identity (including applied variations/drafts), and only unapplied
		// Patris products receive a `product:patris:<code>` identity.
		$query             = new WP_Query(
			array(
				'post_type'        => 'product',
				'post_status'      => $include_trashed_products
					? array( 'publish', self::PRODUCT_TRASH_STATUS )
					: 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$woo_ids           = array_values( array_unique( array_map( 'absint', (array) $query->posts ) ) );
		$woo_ids           = array_values( array_filter( $woo_ids ) );
		$published_ids     = array_values(
			array_filter(
				$woo_ids,
				static fn( int $id ): bool => get_post_status( $id ) === 'publish'
			)
		);
		$trashed_woo_count = count( $woo_ids ) - count( $published_ids );
		$published_set     = array_fill_keys( $published_ids, true );

		$category_ids_by_woo = array_fill_keys( $woo_ids, array() );
		if ( $woo_ids ) {
			$object_terms = wp_get_object_terms(
				$woo_ids,
				'product_cat',
				array( 'fields' => 'all_with_object_id' )
			);
			if ( is_wp_error( $object_terms ) ) {
				$errors[] = self::error(
					'product_category_relationships_unavailable',
					'woocommerce',
					__( 'Product/category relationships could not be read.', 'digitalogic-viewer-bridge' ),
					true
				);
			} else {
				foreach ( (array) $object_terms as $term ) {
					$object_id          = absint( $term->object_id ?? 0 );
					$term_id            = absint( $term->term_id ?? 0 );
					$canonical_category =
						(string) ( $category_context['canonicalByTerm'][ $term_id ] ?? '' );
					$canonical_category = self::redirect_category(
						$canonical_category,
						$category_context
					);
					if ( $object_id > 0 && $canonical_category !== '' ) {
						$category_ids_by_woo[ $object_id ][] = $canonical_category;
					}
				}
			}
		}

		foreach ( $woo_ids as $woo_id ) {
			if ( ! empty( $category_ids_by_woo[ $woo_id ] ) ) {
				$category_ids_by_woo[ $woo_id ] = array_values(
					array_unique( $category_ids_by_woo[ $woo_id ] )
				);
				continue;
			}
		}

		$descriptors_by_id = array();
		$direct_sets       = array();
		$all_ids           = array();
		$root_direct       = array();
		foreach ( $woo_ids as $woo_id ) {
			$canonical_id                       = self::product_id( $woo_id );
			$category_ids                       = $category_ids_by_woo[ $woo_id ] ?? array();
			$category_ids                       = array_values( array_unique( array_filter( $category_ids ) ) );
			$descriptors_by_id[ $canonical_id ] = array(
				'id'          => $canonical_id,
				'kind'        => 'woo',
				'wooId'       => $woo_id,
				'patrisCodes' => array(),
				'categoryIds' => $category_ids,
			);
			if ( isset( $published_set[ $woo_id ] ) ) {
				$all_ids[ $canonical_id ] = true;
			}
			if ( ! $category_ids ) {
				if ( isset( $published_set[ $woo_id ] ) ) {
					$root_direct[ $canonical_id ] = true;
				}
			}
			if ( isset( $published_set[ $woo_id ] ) ) {
				foreach ( $category_ids as $category_id ) {
					$direct_sets[ $category_id ][ $canonical_id ] = true;
				}
			}
		}

		$patris_only_count = 0;
		foreach ( $patris['products'] as $code_key => $record ) {
			// PHP converts numeric-looking array keys to integers. Patris
			// product codes are identifiers, so normalize them back to text
			// before passing them to strict string helpers or emitting them.
			$code               = (string) $code_key;
			$applied_id         = absint( $patris['applied'][ $code ] ?? 0 );
			$applied_is_trashed = $applied_id > 0
				&& get_post_status( $applied_id ) === self::PRODUCT_TRASH_STATUS;
			if ( $applied_is_trashed && ! $include_trashed_products ) {
				continue;
			}
			$canonical_id    = $applied_id > 0
				? self::product_id( $applied_id )
				: self::patris_product_id( $code );
			$patris_category = Store::patris_code( (string) ( $record['category_code'] ?? '' ) );
			$category_id     = self::canonical_category_for_patris(
				$patris_category,
				$category_context
			);
			$category_ids    = $category_id !== '' ? array( $category_id ) : array();
			if ( $applied_id > 0 ) {
				if ( ! isset( $descriptors_by_id[ $canonical_id ] ) ) {
					$descriptors_by_id[ $canonical_id ] = array(
						'id'          => $canonical_id,
						'kind'        => 'woo',
						'wooId'       => $applied_id,
						'patrisCodes' => array(),
						'categoryIds' => array(),
					);
				}
				$descriptors_by_id[ $canonical_id ]['patrisCodes'][] = $code;
				$descriptors_by_id[ $canonical_id ]['patrisCodes']   = array_values(
					array_unique( $descriptors_by_id[ $canonical_id ]['patrisCodes'] )
				);
				$descriptors_by_id[ $canonical_id ]['categoryIds']   = array_values(
					array_unique(
						array_merge(
							$descriptors_by_id[ $canonical_id ]['categoryIds'],
							$category_ids
						)
					)
				);
			} else {
				++$patris_only_count;
				$descriptors_by_id[ $canonical_id ] = array(
					'id'          => $canonical_id,
					'kind'        => 'patris',
					'wooId'       => 0,
					'patrisCodes' => array( $code ),
					'categoryIds' => $category_ids,
				);
			}
			if ( ! $applied_is_trashed ) {
				$all_ids[ $canonical_id ] = true;
				if ( $category_ids ) {
					unset( $root_direct[ $canonical_id ] );
				} elseif ( ! isset( $published_set[ $applied_id ] ) ) {
					$root_direct[ $canonical_id ] = true;
				}
				foreach ( $category_ids as $canonical_category_id ) {
					$direct_sets[ $canonical_category_id ][ $canonical_id ] = true;
				}
			}
		}

		$descriptors = array_values( $descriptors_by_id );
		usort(
			$descriptors,
			static fn( array $left, array $right ): int => strnatcasecmp(
				(string) $left['id'],
				(string) $right['id']
			)
		);

		$inventory_by_product        = array();
		$inventory_source_currencies = (array) $patris['localCurrencies'];
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$inventory_source_currencies[] = strtoupper(
				sanitize_key( (string) get_woocommerce_currency() )
			);
		}
		$inventory_source_currencies = array_values(
			array_unique( array_filter( $inventory_source_currencies ) )
		);
		sort( $inventory_source_currencies, SORT_STRING );
		$inventory_coverage = array(
			'currency'            => 'IRR',
			'valueDefinition'     => 'unit-price-rial-times-on-hand-quantity',
			'negativeStockPolicy' => 'preserved-as-operational-debt',
			'currencyConversion'  => array(
				'IRT'     => 'multiply-by-10',
				'IRR'     => 'identity',
				'unknown' => 'omit-value',
			),
			'sourceCurrencies'    => $inventory_source_currencies,
			'unitPriceKnown'      => 0,
			'stockQuantityKnown'  => 0,
			'inventoryValueKnown' => 0,
			'totalProducts'       => count( $descriptors ),
		);
		foreach ( $descriptors as $descriptor ) {
			// Event serialization needs only selected inventory values. Keep
			// every identity/category descriptor for the canonical mapping.
			if ( $inventory_ids !== null && ! isset( $inventory_ids[ (string) $descriptor['id'] ] ) ) {
				continue;
			}
			$metrics = self::product_inventory_metrics(
				$descriptor,
				$patris,
				$event_products
			);
			$inventory_by_product[ (string) $descriptor['id'] ] = $metrics;
			if ( array_key_exists( 'unitPriceRial', $metrics ) ) {
				++$inventory_coverage['unitPriceKnown'];
			}
			if ( array_key_exists( 'stockQuantity', $metrics ) ) {
				++$inventory_coverage['stockQuantityKnown'];
			}
			if ( array_key_exists( 'inventoryValueRial', $metrics ) ) {
				++$inventory_coverage['inventoryValueKnown'];
			}
		}
		$inventory_coverage['unitPriceMissing']      =
			$inventory_coverage['totalProducts']
			- $inventory_coverage['unitPriceKnown'];
		$inventory_coverage['stockQuantityMissing']  =
			$inventory_coverage['totalProducts']
			- $inventory_coverage['stockQuantityKnown'];
		$inventory_coverage['inventoryValueMissing'] =
			$inventory_coverage['totalProducts']
			- $inventory_coverage['inventoryValueKnown'];

		$context = array(
			'descriptors'        => $descriptors,
			'directSets'         => $direct_sets,
			'allIds'             => $all_ids,
			'rootDirect'         => $root_direct,
			'wooCount'           => count( $published_ids ),
			'trashedWooCount'    => $trashed_woo_count,
			'patrisOnlyCount'    => $patris_only_count,
			'categoriesByWoo'    => $category_ids_by_woo,
			'inventoryByProduct' => $inventory_by_product,
			'inventoryCoverage'  => $inventory_coverage,
		);
		if ( $inventory_ids !== null ) {
			// Partial event inventory must not masquerade as catalog coverage.
			unset( $context['inventoryCoverage'] );
		}
		return $context;
	}

	/**
	 * @param array<string,array<string,mixed>> $nodes
	 * @param array<string,array<string,bool>>  $direct_sets
	 * @param array<string,bool>                $all_ids
	 * @param array<string,bool>                $root_direct
	 * @param array<string,array<string,mixed>> $inventory_by_product
	 * @param array<int,array<string,mixed>>    $warnings
	 */
	private static function apply_category_counts(
		array &$nodes,
		array $direct_sets,
		array $all_ids,
		array $root_direct,
		array $inventory_by_product,
		array &$warnings
	): void {
		$children = array();
		foreach ( $nodes as $id => $node ) {
			$parent = $node['parentId'] ?? null;
			if ( is_string( $parent ) && isset( $nodes[ $parent ] ) && $parent !== $id ) {
				$children[ $parent ][] = $id;
			}
		}

		$memo               = array();
		$visiting           = array();
		$walk               = static function ( string $id ) use (
			&$walk,
			&$memo,
			&$visiting,
			$children,
			$direct_sets,
			&$warnings
		): array {
			if ( isset( $memo[ $id ] ) ) {
				return $memo[ $id ];
			}
			if ( isset( $visiting[ $id ] ) ) {
				$warnings[] = self::warning( 'category_cycle_detected', 'canonical-map', $id );
				return array();
			}
			$visiting[ $id ] = true;
			$subtree         = $direct_sets[ $id ] ?? array();
			foreach ( $children[ $id ] ?? array() as $child_id ) {
				$subtree += $walk( $child_id );
			}
			unset( $visiting[ $id ] );
			return $memo[ $id ] = $subtree;
		};
		$sum_inventory      = static function ( array $product_set ) use (
			$inventory_by_product
		): int {
			$total = 0;
			foreach ( array_keys( $product_set ) as $product_id ) {
				$value = $inventory_by_product[ $product_id ]['inventoryValueRial']
					?? null;
				if ( is_int( $value ) || is_float( $value ) ) {
					$total += (int) round( $value );
				}
			}
			return $total;
		};
		$inventory_coverage = static function ( array $product_set ) use (
			$inventory_by_product
		): array {
			$known = 0;
			foreach ( array_keys( $product_set ) as $product_id ) {
				if (
					array_key_exists(
						'inventoryValueRial',
						$inventory_by_product[ $product_id ] ?? array()
					)
				) {
					++$known;
				}
			}
			return array(
				'knownProducts'   => $known,
				'missingProducts' => count( $product_set ) - $known,
				'totalProducts'   => count( $product_set ),
			);
		};

		foreach ( $nodes as $id => &$node ) {
			$direct                         = $direct_sets[ $id ] ?? array();
			$subtree                        = $walk( $id );
			$node['directProductCount']     = count( $direct );
			$node['productCount']           = count( $direct );
			$node['countKnown']             = true;
			$node['descendantProductCount'] = count( $subtree );
			// Compatibility alias: both fields are the inclusive distinct
			// subtree product union, not the number of category descendants.
			$node['descendantCount']          = count( $subtree );
			$node['directInventoryValueRial'] = $sum_inventory( $direct );
			$node['inventoryValueRial']       = $sum_inventory( $subtree );
			$node['inventoryValueLabel']      = self::rial_label(
				$node['inventoryValueRial']
			);
			$node['directInventoryCoverage']  = $inventory_coverage( $direct );
			$node['inventoryValueCoverage']   =
				$inventory_coverage( $subtree );
		}
		unset( $node );

		if ( isset( $nodes[ self::ROOT_CATEGORY_ID ] ) ) {
			$nodes[ self::ROOT_CATEGORY_ID ]['directProductCount']       = count( $root_direct );
			$nodes[ self::ROOT_CATEGORY_ID ]['productCount']             =
				count( $root_direct );
			$nodes[ self::ROOT_CATEGORY_ID ]['countKnown']               = true;
			$nodes[ self::ROOT_CATEGORY_ID ]['descendantProductCount']   = count( $all_ids );
			$nodes[ self::ROOT_CATEGORY_ID ]['descendantCount']          = count( $all_ids );
			$nodes[ self::ROOT_CATEGORY_ID ]['directInventoryValueRial'] =
				$sum_inventory( $root_direct );
			$nodes[ self::ROOT_CATEGORY_ID ]['inventoryValueRial']       =
				$sum_inventory( $all_ids );
			$nodes[ self::ROOT_CATEGORY_ID ]['inventoryValueLabel']      =
				self::rial_label(
					$nodes[ self::ROOT_CATEGORY_ID ]['inventoryValueRial']
				);
			$nodes[ self::ROOT_CATEGORY_ID ]['directInventoryCoverage']  =
				$inventory_coverage( $root_direct );
			$nodes[ self::ROOT_CATEGORY_ID ]['inventoryValueCoverage']   =
				$inventory_coverage( $all_ids );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $errors
	 * @return array<string,mixed>
	 */
	private static function orders( int $page, int $limit, array &$errors ): array {
		$patris_state     = Patris_Commerce::state();
		$patris_connected = Patris_Commerce::connected();
		$patris_revision  = (string) (
			$patris_state['source']['revision'] ?? ''
		);
		$patris_items     = array();
		$product_orders   = array();
		$customer_orders  = array();
		foreach ( (array) ( $patris_state['orders'] ?? array() ) as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$serialized     = Patris_Commerce::resolved_entity( 'order', $record );
			$patris_items[] = $serialized;
			foreach ( (array) ( $serialized['productIds'] ?? array() ) as $product_id ) {
				$product_orders[ (string) $product_id ][] =
					(string) $serialized['id'];
			}
			$customer_orders[ (string) $serialized['customerId'] ][] =
				(string) $serialized['id'];
		}

		$patris_product_sales = array();
		foreach ( (array) ( $patris_state['productSales'] ?? array() ) as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$code = Store::patris_code(
				(string) ( $record['productCode'] ?? '' )
			);
			if ( $code === '' ) {
				continue;
			}
			$canonical_id               = self::canonical_product_id_for_patris( $code );
			$aggregate                  = $patris_product_sales[ $canonical_id ] ?? array(
				'soldQuantity'       => 0.0,
				'orderIds'           => array(),
				'sourceProductCodes' => array(),
				'sourceRevisions'    => array(),
			);
			$aggregate['soldQuantity'] += max(
				0,
				(float) ( $record['soldQuantity'] ?? 0 )
			);
			foreach ( (array) ( $record['orderIds'] ?? array() ) as $order_id ) {
				$aggregate['orderIds'][ (string) $order_id ] = true;
			}
			$aggregate['sourceProductCodes'][ $code ] = true;
			$aggregate['sourceRevisions'][]           =
				(string) ( $record['revision'] ?? '' );
			$patris_product_sales[ $canonical_id ]    = $aggregate;
		}
		foreach ( $patris_product_sales as $canonical_id => &$aggregate ) {
			$aggregate['soldQuantity'] = round(
				(float) $aggregate['soldQuantity'],
				6
			);
			$aggregate['orderIds']     = array_slice(
				array_keys( $aggregate['orderIds'] ),
				0,
				1000
			);
			sort( $aggregate['orderIds'], SORT_STRING );
			$aggregate['sourceProductCodes'] = array_keys(
				$aggregate['sourceProductCodes']
			);
			sort( $aggregate['sourceProductCodes'], SORT_NATURAL );
			$aggregate['revision'] = Revision::hash(
				array(
					'productId'       => $canonical_id,
					'sourceRevisions' => $aggregate['sourceRevisions'],
				)
			);
			unset( $aggregate['sourceRevisions'] );
		}
		unset( $aggregate );

		$woo_objects          = array();
		$woo_items            = array();
		$woo_total            = 0;
		$woo_window_truncated = false;
		if ( ! function_exists( 'wc_get_orders' ) ) {
			$errors[] = self::error(
				'woocommerce_orders_unavailable',
				'orders',
				__( 'WooCommerce order storage is unavailable.', 'digitalogic-viewer-bridge' ),
				false
			);
		} else {
			// The first N records from each independently sorted source are
			// sufficient to form the first N records of their merged stream.
			// Keep the server-side merge bounded for hostile page input.
			$window               = min( 5000, max( $limit, $page * $limit ) );
			$result               = wc_get_orders(
				array(
					'limit'    => $window,
					'page'     => 1,
					'paginate' => true,
					'orderby'  => 'date',
					'order'    => 'DESC',
					'return'   => 'objects',
				)
			);
			$woo_objects          = is_object( $result ) && isset( $result->orders )
				? (array) $result->orders
				: ( is_array( $result ) ? $result : array() );
			$woo_total            = is_object( $result ) && isset( $result->total )
				? max( 0, (int) $result->total )
				: count( $woo_objects );
			$woo_window_truncated = $woo_total > count( $woo_objects );
		}

		foreach ( $woo_objects as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$serialized  = self::serialize_order( $order );
			$woo_items[] = $serialized;
			foreach ( $serialized['productIds'] as $product_id ) {
				$product_orders[ $product_id ][] = $serialized['id'];
			}
			$customer_orders[ $serialized['customerId'] ][] = $serialized['id'];
		}
		foreach ( $product_orders as &$order_ids ) {
			$order_ids = array_values( array_unique( $order_ids ) );
		}
		unset( $order_ids );
		foreach ( $customer_orders as &$order_ids ) {
			$order_ids = array_values( array_unique( $order_ids ) );
		}
		unset( $order_ids );

		$merged = array_merge( $woo_items, $patris_items );
		usort(
			$merged,
			static fn( array $left, array $right ): int =>
				strcmp(
					(string) ( $right['createdAt'] ?? '' ),
					(string) ( $left['createdAt'] ?? '' )
				)
				?: strcmp( (string) $left['id'], (string) $right['id'] )
		);
		$items = array_slice(
			$merged,
			( $page - 1 ) * $limit,
			$limit
		);

		return array(
			'items'              => $items,
			'objects'            => $woo_objects,
			'total'              => $woo_total + count( $patris_items ),
			'woocommerceTotal'   => $woo_total,
			'patrisTotal'        => count( $patris_items ),
			'productOrders'      => $product_orders,
			'customerOrders'     => $customer_orders,
			'patrisProductSales' => $patris_product_sales,
			'patrisConnected'    => $patris_connected,
			'patrisSourceState'  => $patris_revision === ''
				? 'unavailable'
				: ( $patris_connected ? 'live' : 'stale' ),
			'patrisReceivedAt'   => $patris_state['receivedAt'] ?? null,
			'patrisGeneratedAt'  =>
				$patris_state['source']['generatedAt'] ?? null,
			'wooWindowTruncated' => $woo_window_truncated,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function serialize_order( WC_Order $order ): array {
		$product_ids = array();
		$item_count  = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			// Associate sales with the displayed parent product sphere; the
			// variation remains an attribute of that order line in Woo.
			$product_id = absint( $item->get_product_id() ?: $item->get_variation_id() );
			if ( $product_id > 0 ) {
				$product_ids[] = self::product_id( $product_id );
			}
			$item_count += max( 0, (int) $item->get_quantity() );
		}
		$customer_id           = absint( $order->get_customer_id() );
		$canonical_customer_id = $customer_id > 0
			? self::customer_id( $customer_id )
			: 'customer:guest';
		$created               = $order->get_date_created();
		$modified              = $order->get_date_modified();
		$status                = sanitize_key( $order->get_status() );

		return array(
			'id'             => self::order_id( $order->get_id() ),
			'number'         => sanitize_text_field( (string) $order->get_order_number() ),
			'status'         => $status,
			'createdAt'      => $created ? $created->date( 'c' ) : null,
			'modifiedAt'     => $modified ? $modified->date( 'c' ) : null,
			'totalLabel'     => self::money_label( (string) $order->get_total(), $order->get_currency() ),
			'itemCount'      => $item_count,
			'customerId'     => $canonical_customer_id,
			'customerLabel'  => $customer_id > 0
				? sprintf( 'مشتری #%d', $customer_id )
				: 'مشتری مهمان',
			'productIds'     => array_values( array_unique( $product_ids ) ),
			'revision'       => Revision::order( $order ),
			'allowedActions' => self::order_allowed_actions( $status ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function serialize_products(
		array $descriptors,
		array $product_context,
		array $patris,
		array $product_orders,
		array $patris_product_sales,
		array &$errors,
		array $event_products = array()
	): array {
		$items = array();
		foreach ( $descriptors as $descriptor ) {
			if ( $descriptor['kind'] === 'woo' ) {
				$product_id = (int) $descriptor['wooId'];
				$product    = $event_products[ $product_id ] ?? null;
				if ( ! $product instanceof WC_Product ) {
					$product = wc_get_product( $product_id );
				}
				if ( ! $product instanceof WC_Product ) {
					$errors[]      = self::error(
						'woocommerce_product_unavailable',
						'woocommerce',
						__( 'A WooCommerce product disappeared during the live read.', 'digitalogic-viewer-bridge' ),
						true,
						array( 'entityId' => $descriptor['id'] )
					);
					$fallback_code = (string) ( ( $descriptor['patrisCodes'][0] ?? '' ) );
					$fallback      = $patris['products'][ $fallback_code ] ?? null;
					if ( ! is_array( $fallback ) ) {
						continue;
					}
					$item    = array_merge(
						array(
							'id'             => $descriptor['id'],
							'name'           => sanitize_text_field(
								(string) ( $fallback['name'] ?? $fallback_code )
							),
							'sku'            => sanitize_text_field( (string) ( $fallback['serial'] ?? '' ) ),
							'categoryIds'    => array_values( $descriptor['categoryIds'] ),
							'status'         => 'source-applied-unavailable',
							'stockLabel'     => self::patris_stock_label( $fallback ),
							'soldQuantity'   => 0,
							'orderIds'       => array(),
							'attributes'     => self::patris_attributes( $fallback ),
							'sourceRefs'     => array(
								array(
									'source'     => 'patris.product-sync',
									'externalId' => $fallback_code,
									'path'       => '/products/' . rawurlencode( $fallback_code ),
								),
							),
							'revision'       => self::patris_revision( $fallback ),
							'allowedActions' => array(),
						),
						$product_context['inventoryByProduct'][ $descriptor['id'] ] ?? array()
					);
					$items[] = self::apply_patris_product_sales(
						$item,
						$patris_product_sales
					);
					continue;
				}
				$source_refs = array(
					array(
						'source'     => 'woocommerce.product',
						'externalId' => (string) $product->get_id(),
						'path'       => '/wp-admin/post.php?post=' . $product->get_id() . '&action=edit',
					),
				);
				foreach ( (array) ( $descriptor['patrisCodes'] ?? array() ) as $patris_code ) {
					$source_refs[] = array(
						'source'     => 'patris.product-sync',
						'externalId' => (string) $patris_code,
						'path'       => '/products/' . rawurlencode( (string) $patris_code ),
					);
				}
				$stock_status = sanitize_key( (string) $product->get_stock_status() );
				if (
					! in_array(
						$stock_status,
						array( 'instock', 'outofstock', 'onbackorder' ),
						true
					)
				) {
					$stock_status = 'outofstock';
				}
				$item      = array(
					'id'             => $descriptor['id'],
					'name'           => sanitize_text_field( $product->get_name() ),
					'sku'            => sanitize_text_field( (string) $product->get_sku() ),
					'categoryIds'    => array_values( $descriptor['categoryIds'] ),
					'status'         => sanitize_key( $product->get_status() ),
					'stockStatus'    => $stock_status,
					'stockLabel'     => self::stock_label( $stock_status ),
					'soldQuantity'   => max( 0, (int) $product->get_total_sales() ),
					'orderIds'       => array_values(
						array_unique( $product_orders[ $descriptor['id'] ] ?? array() )
					),
					'attributes'     => self::product_attributes( $product ),
					'sourceRefs'     => $source_refs,
					'revision'       => Revision::product( $product ),
					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability is registered by Store::add_capabilities during activation.
					'allowedActions' => current_user_can( 'digitalogic_viewer_manage' )
						? (
							$product->get_status() === self::PRODUCT_TRASH_STATUS
								? array( 'entity.restore' )
								: array(
									'product.rename',
									'product.set_status',
									'product.set_stock_status',
									'product.assign_categories',
									'entity.trash',
								)
						)
						: array(),
				);
				$item      = array_merge(
					$item,
					$product_context['inventoryByProduct'][ $descriptor['id'] ]
						?? array()
				);
				$image_url = self::product_image_url( $product );
				if ( $image_url !== null ) {
					$item['imageUrl'] = $image_url;
				}
				$items[] = self::apply_patris_product_sales(
					$item,
					$patris_product_sales
				);
				continue;
			}

			$code    = (string) ( $descriptor['patrisCodes'][0] ?? '' );
			$record  = $patris['products'][ $code ] ?? array();
			$item    = array_merge(
				array(
					'id'             => $descriptor['id'],
					'name'           => sanitize_text_field( (string) ( $record['name'] ?? $code ) ),
					'sku'            => sanitize_text_field( (string) ( $record['serial'] ?? '' ) ),
					'categoryIds'    => array_values( $descriptor['categoryIds'] ),
					'status'         => 'source-only',
					'stockLabel'     => self::patris_stock_label( $record ),
					'soldQuantity'   => 0,
					'orderIds'       => array(),
					'attributes'     => self::patris_attributes( $record ),
					'sourceRefs'     => array(
						array(
							'source'     => 'patris.product-sync',
							'externalId' => $code,
							'path'       => '/products/' . rawurlencode( $code ),
						),
					),
					'revision'       => self::patris_revision( $record ),
					'allowedActions' => array(),
				),
				$product_context['inventoryByProduct'][ $descriptor['id'] ] ?? array()
			);
			$items[] = self::apply_patris_product_sales(
				$item,
				$patris_product_sales
			);
		}
		return $items;
	}

	/**
	 * Merge source-specific sales without inventing cross-system identity.
	 * Woo and Patris order IDs occupy disjoint namespaces, but a future import
	 * may still represent the same real sale in both systems; expose that
	 * additive policy explicitly instead of claiming deduplication.
	 *
	 * @param array<string,mixed>               $item
	 * @param array<string,array<string,mixed>> $patris_product_sales
	 * @return array<string,mixed>
	 */
	private static function apply_patris_product_sales(
		array $item,
		array $patris_product_sales
	): array {
		$canonical_id           = (string) ( $item['id'] ?? '' );
		$patris_sales           = $patris_product_sales[ $canonical_id ] ?? null;
		$woo_quantity           = max( 0, (float) ( $item['soldQuantity'] ?? 0 ) );
		$patris_quantity        = is_array( $patris_sales )
			? max( 0, (float) ( $patris_sales['soldQuantity'] ?? 0 ) )
			: 0.0;
		$item['soldQuantity']   = round(
			$woo_quantity + $patris_quantity,
			6
		);
		$item['orderIds']       = array_slice(
			array_values(
				array_unique(
					array_merge(
						(array) ( $item['orderIds'] ?? array() ),
						is_array( $patris_sales )
							? (array) ( $patris_sales['orderIds'] ?? array() )
							: array()
					)
				)
			),
			0,
			1000
		);
		$item['salesBySource']  = array(
			'woocommerce' => $woo_quantity,
			'patris'      => $patris_quantity,
		);
		$item['salesSemantics'] =
			'additive-no-cross-source-order-deduplication';
		if ( is_array( $patris_sales ) ) {
			$item['salesRevision'] =
				(string) ( $patris_sales['revision'] ?? '' );
		}
		return $item;
	}

	/**
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	private static function customers(
		int $page,
		int $limit,
		array $orders,
		array &$errors
	): array {
		unset( $errors );
		$window = min( 2000, max( $limit, $page * $limit ) );
		$query  = new WP_User_Query(
			array(
				'role'        => 'customer',
				'number'      => $window,
				'offset'      => 0,
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'fields'      => 'ID',
				'count_total' => true,
			)
		);
		$ids    = array_values(
			array_filter( array_map( 'absint', (array) $query->get_results() ) )
		);
		$items  = array();
		foreach ( $ids as $user_id ) {
			$summary = self::customer_event_value( $user_id );
			if ( ! is_array( $summary ) ) {
				continue;
			}
			$canonical_id        = self::customer_id( $user_id );
			$summary['orderIds'] = array_slice(
				array_values(
					array_unique(
						array_merge(
							(array) ( $summary['orderIds'] ?? array() ),
							(array) (
								$orders['customerOrders'][ $canonical_id ]
								?? array()
							)
						)
					)
				),
				0,
				1000
			);
			$summary['source']   = 'woocommerce';
			$items[]             = $summary;
		}

		$guest_orders = $orders['customerOrders']['customer:guest'] ?? array();
		if ( $guest_orders ) {
			$items[] = array(
				'id'              => 'customer:guest',
				'displayLabel'    => 'مشتریان مهمان',
				'displayName'     => 'مشتریان مهمان',
				'orderCount'      => count( $guest_orders ),
				'totalSpentLabel' => null,
				'lastOrderAt'     => null,
				'segment'         => 'guest',
				'orderIds'        => array_values( array_unique( $guest_orders ) ),
				'revision'        => Revision::hash(
					array(
						'id'       => 'customer:guest',
						'orderIds' => $guest_orders,
					)
				),
				'allowedActions'  => array( 'view_orders' ),
			);
		}

		$patris_state = Patris_Commerce::state();
		foreach ( (array) ( $patris_state['customers'] ?? array() ) as $record ) {
			if ( is_array( $record ) ) {
				$items[] = Patris_Commerce::resolved_entity(
					'customer',
					$record
				);
			}
		}
		usort(
			$items,
			static fn( array $left, array $right ): int =>
				strcmp(
					(string) ( $right['lastOrderAt'] ?? '' ),
					(string) ( $left['lastOrderAt'] ?? '' )
				)
				?: strcmp( (string) $left['id'], (string) $right['id'] )
		);
		$total = (int) $query->get_total()
			+ count( (array) ( $patris_state['customers'] ?? array() ) )
			+ ( $guest_orders ? 1 : 0 );
		return array(
			'items'            => array_slice(
				$items,
				( $page - 1 ) * $limit,
				$limit
			),
			'total'            => $total,
			'woocommerceTotal' => (int) $query->get_total()
				+ ( $guest_orders ? 1 : 0 ),
			'patrisTotal'      =>
				count( (array) ( $patris_state['customers'] ?? array() ) ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function timeline( array $orders, array $products, int $limit ): array {
		$items = array();
		foreach ( $orders as $order ) {
			if ( ! empty( $order['createdAt'] ) ) {
				$items[] = array(
					'id'          => 'timeline:order-created:' . $order['id'],
					'at'          => $order['createdAt'],
					'type'        => 'order.created',
					'title'       => 'سفارش ثبت شد',
					'entityType'  => 'order',
					'entityId'    => $order['id'],
					'status'      => $order['status'],
					'description' => 'یک سفارش در ووکامرس ثبت شده است.',
				);
			}
			if (
				! empty( $order['modifiedAt'] )
				&& $order['modifiedAt'] !== $order['createdAt']
			) {
				$items[] = array(
					'id'          => 'timeline:order-updated:' . $order['id'],
					'at'          => $order['modifiedAt'],
					'type'        => 'order.updated',
					'title'       => 'سفارش به‌روزرسانی شد',
					'entityType'  => 'order',
					'entityId'    => $order['id'],
					'status'      => $order['status'],
					'description' => 'وضعیت یا محتوای سفارش تغییر کرده است.',
				);
			}
		}
		// Product records intentionally do not add synthetic timestamps: the
		// compact product contract omits modifiedAt. Live change events arrive
		// through the isolated Redis stream instead.
		usort(
			$items,
			static fn( array $left, array $right ): int => strcmp(
				(string) $right['at'],
				(string) $left['at']
			)
		);
		return array_slice( $items, 0, $limit );
	}

	/**
	 * @return array{columns:array<int,array<string,mixed>>,cards:array<int,array<string,mixed>>}
	 */
	private static function kanban( array $orders ): array {
		$columns = array();
		foreach ( wc_get_order_statuses() as $status => $label ) {
			$slug      = sanitize_key( str_replace( 'wc-', '', (string) $status ) );
			$columns[] = array(
				'id'          => 'order-status:' . $slug,
				'title'       => sanitize_text_field( (string) $label ),
				'status'      => $slug,
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability is registered by Store::add_capabilities during activation.
				'acceptsDrop' => current_user_can( 'digitalogic_viewer_manage' )
					&& in_array( $slug, self::mutable_order_statuses(), true ),
			);
		}
		$cards = array_map(
			static fn( array $order ): array => array(
				'id'             => 'kanban:' . $order['id'],
				'columnId'       => 'order-status:' . $order['status'],
				'entityId'       => $order['id'],
				'title'          => 'سفارش #' . $order['number'],
				'subtitle'       => $order['totalLabel'],
				'itemCount'      => $order['itemCount'],
				'revision'       => $order['revision'],
				'allowedActions' => $order['allowedActions'],
			),
			$orders
		);
		return array(
			'columns' => $columns,
			'cards'   => $cards,
		);
	}

	/**
	 * @return array<int,array{name:string,options:array<int,string>}>
	 */
	private static function product_attributes( WC_Product $product ): array {
		$out = array();
		foreach (
			array_slice(
				(array) $product->get_attributes(),
				0,
				self::MAX_ATTRIBUTES,
				true
			) as $attribute_key => $attribute
		) {
			$options = array();
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$attribute_name = (string) $attribute->get_name();
				$name           = wc_attribute_label( $attribute_name, $product );
				if ( $attribute->is_taxonomy() ) {
					$terms   = wc_get_product_terms(
						$product->get_id(),
						$attribute_name,
						array( 'fields' => 'names' )
					);
					$options = is_wp_error( $terms ) ? array() : (array) $terms;
				} else {
					$options = (array) $attribute->get_options();
				}
			} else {
				// Variations expose attributes as name => scalar rather than
				// WC_Product_Attribute instances. Legacy imports can also
				// contain array-shaped values, so serialize both defensively.
				$attribute_name = is_string( $attribute_key )
					? $attribute_key
					: '';
				if ( is_array( $attribute ) ) {
					$attribute_name = sanitize_key(
						(string) ( $attribute['name'] ?? $attribute_name )
					);
					$raw_options    = $attribute['options']
						?? ( $attribute['value'] ?? array() );
					$options        = is_array( $raw_options )
						? $raw_options
						: wc_get_text_attributes( (string) $raw_options );
				} elseif ( is_scalar( $attribute ) ) {
					$options = wc_get_text_attributes( (string) $attribute );
				}
				$name = $attribute_name !== ''
					? wc_attribute_label( $attribute_name, $product )
					: __( 'Attribute', 'digitalogic-viewer-bridge' );
				if (
					$attribute_name !== ''
					&& taxonomy_exists( $attribute_name )
					&& count( $options ) === 1
				) {
					$term = get_term_by(
						'slug',
						(string) $options[0],
						$attribute_name
					);
					if ( $term instanceof WP_Term ) {
						$options[0] = $term->name;
					}
				}
			}
			$out[] = array(
				'name'    => sanitize_text_field( (string) $name ),
				'options' => array_values(
					array_slice(
						array_filter(
							array_map(
								static fn( $value ): string => sanitize_text_field( (string) $value ),
								$options
							)
						),
						0,
						self::MAX_ATTRIBUTE_OPTIONS
					)
				),
			);
		}
		return $out;
	}

	/**
	 * Return a same-site HTTPS WooCommerce image URL, or omit it.
	 *
	 * The attachment ID comes from WooCommerce, and the generated URL must
	 * remain on the canonical WordPress host. This keeps private, mixed-
	 * content, credential-bearing, and arbitrary remote URLs out of the
	 * viewer contract.
	 */
	private static function product_image_url( WC_Product $product ): ?string {
		$attachment_id = absint( $product->get_image_id() );
		if ( $attachment_id < 1 ) {
			return null;
		}

		$raw_url = wp_get_attachment_image_url(
			$attachment_id,
			'woocommerce_thumbnail'
		);
		if ( ! is_string( $raw_url ) || $raw_url === '' ) {
			return null;
		}
		$url = esc_url_raw( $raw_url, array( 'https' ) );
		if ( $url === '' || strlen( $url ) > 2048 ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https'
			|| ! empty( $parts['user'] )
			|| ! empty( $parts['pass'] )
			|| ! empty( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 )
		) {
			return null;
		}

		$image_host     = strtolower( (string) ( $parts['host'] ?? '' ) );
		$home_host      = strtolower(
			(string) wp_parse_url( home_url( '/' ), PHP_URL_HOST )
		);
		$bare_home_host = preg_replace( '/^www\./', '', $home_host );
		$allowed_hosts  = array_values(
			array_unique(
				array_filter(
					array( $home_host, $bare_home_host, 'www.' . $bare_home_host )
				)
			)
		);
		if (
			$image_host === ''
			|| ! in_array( $image_host, $allowed_hosts, true )
		) {
			return null;
		}

		return $url;
	}

	/**
	 * @return array<int,array{name:string,options:array<int,string>}>
	 */
	private static function patris_attributes( array $record ): array {
		$fields = array(
			'unit'             => 'واحد',
			'serial'           => 'سریال کالا',
			'foreign_currency' => 'ارز مبنا',
		);
		$out    = array();
		foreach ( $fields as $field => $label ) {
			$value = sanitize_text_field( (string) ( $record[ $field ] ?? '' ) );
			if ( $value !== '' ) {
				$out[] = array(
					'name'    => $label,
					'options' => array( $value ),
				);
			}
		}
		return $out;
	}

	/**
	 * Build source-backed inventory metrics in Iranian rials.
	 *
	 * WooCommerce and the live Patris product-sync source currently declare
	 * IRT (toman), so those amounts are multiplied by ten. Unknown currencies
	 * are omitted instead of being guessed. Patris is authoritative for a
	 * mapped product; Woo fills only missing source fields and disagreements
	 * are returned as explicit source conflicts.
	 *
	 * @param array<string,mixed> $descriptor
	 * @param array<string,mixed> $patris
	 * @return array<string,mixed>
	 */
	private static function product_inventory_metrics(
		array $descriptor,
		array $patris,
		?array &$event_products = null
	): array {
		$unit_price_rial    = null;
		$stock_quantity     = null;
		$price_source       = null;
		$stock_source       = null;
		$woo_price_rial     = null;
		$woo_stock_quantity = null;
		if ( ( $descriptor['kind'] ?? '' ) === 'woo' ) {
			$product = wc_get_product( absint( $descriptor['wooId'] ?? 0 ) );
			if ( $product instanceof WC_Product ) {
				if ( $event_products !== null ) {
					$event_products[ (int) $descriptor['wooId'] ] = $product;
				}
				$woo_price = $product->get_price();
				if ( $woo_price !== '' && is_numeric( $woo_price ) ) {
					$woo_price_rial = self::amount_to_rial(
						(float) $woo_price,
						function_exists( 'get_woocommerce_currency' )
							? (string) get_woocommerce_currency()
							: ''
					);
				}
				$woo_stock = $product->get_stock_quantity();
				if ( $woo_stock !== null && is_numeric( $woo_stock ) ) {
					$woo_stock_quantity = (float) $woo_stock;
				}
			}
		}

		$patris_record = null;
		foreach ( (array) ( $descriptor['patrisCodes'] ?? array() ) as $code ) {
			if ( is_array( $patris['products'][ (string) $code ] ?? null ) ) {
				$patris_record = $patris['products'][ (string) $code ];
				break;
			}
		}
		$patris_price_rial     = null;
		$patris_stock_quantity = null;
		if ( is_array( $patris_record ) ) {
			if (
				is_numeric( $patris_record['final_price'] ?? null )
			) {
				$patris_price_rial = self::amount_to_rial(
					(float) $patris_record['final_price'],
					(string) ( $patris_record['_localCurrency'] ?? '' )
				);
			}
			if (
				is_numeric( $patris_record['total_stock'] ?? null )
			) {
				$patris_stock_quantity =
					(float) $patris_record['total_stock'];
			}
		}

		// The Patris product-sync projection is authoritative for mapped
		// inventory. Woo remains a safe fallback when a source field is
		// absent, and disagreements are exposed rather than silently hidden.
		if ( $patris_price_rial !== null ) {
			$unit_price_rial = $patris_price_rial;
			$price_source    = 'patris.product-sync';
		} elseif ( $woo_price_rial !== null ) {
			$unit_price_rial = $woo_price_rial;
			$price_source    = 'woocommerce';
		}
		if ( $patris_stock_quantity !== null ) {
			$stock_quantity = $patris_stock_quantity;
			$stock_source   = 'patris.product-sync';
		} elseif ( $woo_stock_quantity !== null ) {
			$stock_quantity = $woo_stock_quantity;
			$stock_source   = 'woocommerce';
		}

		$metrics   = array(
			'inventoryCurrency'  => 'IRR',
			'inventoryAuthority' => is_array( $patris_record )
				? 'patris.product-sync'
				: 'woocommerce',
		);
		$conflicts = array();
		if (
			$patris_price_rial !== null
			&& $woo_price_rial !== null
			&& $patris_price_rial !== $woo_price_rial
		) {
			$conflicts['unitPrice'] = array(
				'authoritativeRial' => $patris_price_rial,
				'alternateRial'     => $woo_price_rial,
				'alternateSource'   => 'woocommerce',
			);
		}
		if (
			$patris_stock_quantity !== null
			&& $woo_stock_quantity !== null
			&& abs( $patris_stock_quantity - $woo_stock_quantity ) > 0.000001
		) {
			$conflicts['stockQuantity'] = array(
				'authoritative'   => $patris_stock_quantity,
				'alternate'       => $woo_stock_quantity,
				'alternateSource' => 'woocommerce',
			);
		}
		if ( $conflicts ) {
			$metrics['inventorySourceConflicts'] = $conflicts;
		}
		if ( $unit_price_rial !== null ) {
			$metrics['unitPriceRial']   = $unit_price_rial;
			$metrics['unitPriceLabel']  = self::rial_label( $unit_price_rial );
			$metrics['unitPriceSource'] = $price_source;
		}
		if ( $stock_quantity !== null ) {
			$metrics['stockQuantity']       = $stock_quantity;
			$metrics['stockQuantityLabel']  = number_format_i18n(
				$stock_quantity,
				abs( $stock_quantity - round( $stock_quantity ) ) < 0.000001 ? 0 : 3
			);
			$metrics['stockQuantitySource'] = $stock_source;
		}
		if ( $unit_price_rial !== null && $stock_quantity !== null ) {
			// Negative on-hand quantities remain visible as operational debt;
			// the viewer may choose a minimum visual radius but must not hide
			// the source value by clamping it.
			$metrics['inventoryValueRial']  = (int) round(
				$unit_price_rial * $stock_quantity
			);
			$metrics['inventoryValueLabel'] = self::rial_label(
				$metrics['inventoryValueRial']
			);
		}
		return $metrics;
	}

	private static function amount_to_rial(
		float $amount,
		string $currency
	): ?int {
		if ( ! is_finite( $amount ) ) {
			return null;
		}
		$currency = strtoupper( sanitize_key( $currency ) );
		if ( $currency === 'IRT' ) {
			return (int) round( $amount * 10 );
		}
		if ( $currency === 'IRR' ) {
			return (int) round( $amount );
		}
		return null;
	}

	private static function rial_label( int $amount ): string {
		return number_format_i18n( $amount, 0 ) . ' ریال';
	}

	/**
	 * @return string[]
	 */
	public static function mutable_order_statuses(): array {
		return array( 'pending', 'processing', 'on-hold', 'completed' );
	}

	/**
	 * @return string[]
	 */
	public static function allowed_order_transitions( string $status ): array {
		$map = array(
			'pending'    => array( 'processing', 'on-hold' ),
			'processing' => array( 'completed', 'on-hold' ),
			'on-hold'    => array( 'processing' ),
			'failed'     => array( 'processing', 'on-hold' ),
			'completed'  => array(),
			'cancelled'  => array(),
		);
		return $map[ $status ] ?? array();
	}

	/**
	 * @return string[]
	 */
	public static function customer_segments(): array {
		return array( 'new', 'returning', 'loyal', 'inactive' );
	}

	public static function category_id( int $term_id ): string {
		return 'cat:wp:' . max( 0, $term_id );
	}

	public static function product_id( int $product_id ): string {
		return 'product:woo:' . max( 0, $product_id );
	}

	/**
	 * Resolve a Patris product code through the same applied-product map used
	 * by the canonical catalog. This prevents the commerce projection from
	 * creating a second sphere for a product already represented by Woo.
	 */
	public static function canonical_product_id_for_patris( string $code ): string {
		$code = Store::patris_code( $code );
		if ( $code === '' ) {
			return self::patris_product_id( 'unknown' );
		}
		static $canonical_by_code = null;
		if ( ! is_array( $canonical_by_code ) ) {
			$canonical_by_code = array();
			$state             = get_option( 'digitalogic_product_sync_state', array() );
			foreach ( (array) ( $state['sources'] ?? array() ) as $source ) {
				if ( ! is_array( $source ) ) {
					continue;
				}
				foreach ( (array) ( $source['applied_products'] ?? array() ) as $key => $record ) {
					if ( ! is_array( $record ) ) {
						continue;
					}
					$candidate = Store::patris_code(
						(string) ( $record['product_code'] ?? $key )
					);
					$woo_id    = absint( $record['woocommerce_id'] ?? 0 );
					if ( $candidate !== '' && $woo_id > 0 ) {
						$canonical_by_code[ $candidate ] =
							self::product_id( $woo_id );
					}
				}
			}
		}
		return $canonical_by_code[ $code ] ?? self::patris_product_id( $code );
	}

	public static function order_id( int $order_id ): string {
		return 'order:' . max( 0, $order_id );
	}

	public static function customer_id( int $user_id ): string {
		return 'customer:' . max( 0, $user_id );
	}

	public static function woo_id( string $canonical_id, string $type ): int {
		$patterns = array(
			'category' => '/^cat:wp:(\d+)$/',
			'product'  => '/^product:woo:(\d+)$/',
			'order'    => '/^order:(\d+)$/',
			'customer' => '/^customer:(\d+)$/',
		);
		if ( ! isset( $patterns[ $type ] ) || ! preg_match( $patterns[ $type ], $canonical_id, $match ) ) {
			return 0;
		}
		return absint( $match[1] );
	}

	public static function websocket_url(): string {
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$default = $host ? 'wss://' . $host . '/viewer-ws' : '';
		return esc_url_raw(
			(string) apply_filters( 'digitalogic_viewer_websocket_url', $default ),
			array( 'wss' )
		);
	}

	private static function patris_category_id( string $code ): string {
		return 'cat:patris:' . rawurlencode( $code );
	}

	private static function patris_product_id( string $code ): string {
		return 'product:patris:' . rawurlencode( $code );
	}

	private static function canonical_category_for_patris(
		string $code,
		array $category_context
	): string {
		if ( $code === '' ) {
			return '';
		}
		return self::redirect_category(
			(string) ( $category_context['canonicalByPatris'][ $code ] ?? '' ),
			$category_context
		);
	}

	private static function redirect_category(
		string $category_id,
		array $category_context
	): string {
		$visited = array();
		while (
			$category_id !== ''
			&& isset( $category_context['redirects'][ $category_id ] )
			&& ! isset( $visited[ $category_id ] )
		) {
			$visited[ $category_id ] = true;
			$category_id             = (string) $category_context['redirects'][ $category_id ];
		}
		return $category_id;
	}

	private static function category_icon( WP_Term $term ): string {
		$custom = sanitize_key(
			(string) get_term_meta( $term->term_id, '_digitalogic_viewer_icon_key', true )
		);
		return $custom !== '' ? $custom : self::icon_from_text( $term->name . ' ' . $term->slug );
	}

	private static function icon_from_text( string $text ): string {
		$text = mb_strtolower( $text, 'UTF-8' );
		$map  = array(
			'sensor'     => array( 'سنسور', 'حسگر', 'sensor' ),
			'chip'       => array( 'آی سی', 'ic', 'میکروکنترلر', 'پردازنده' ),
			'display'    => array( 'نمایشگر', 'lcd', 'oled', 'led' ),
			'power'      => array( 'تغذیه', 'رگولاتور', 'dc', 'باتری' ),
			'connector'  => array( 'کانکتور', 'اتصال' ),
			'motor'      => array( 'موتور', 'سروو' ),
			'wireless'   => array( 'وای‌فای', 'wifi', 'بلوتوث', 'مخابرات' ),
			'automotive' => array( 'ecu', 'خودرو', 'bosch', 'siemens' ),
			'passive'    => array( 'مقاومت', 'خازن', 'سلف', 'کریستال' ),
			'module'     => array( 'ماژول', 'برد' ),
			'laser'      => array( 'لیزر' ),
		);
		foreach ( $map as $icon => $needles ) {
			foreach ( $needles as $needle ) {
				if ( str_contains( $text, mb_strtolower( $needle, 'UTF-8' ) ) ) {
					return $icon;
				}
			}
		}
		return 'component';
	}

	private static function stock_label( string $status ): string {
		return match ( sanitize_key( $status ) ) {
			'instock' => 'موجود',
			'outofstock' => 'ناموجود',
			'onbackorder' => 'قابل پیش‌خرید',
			default => 'نامشخص',
		};
	}

	private static function patris_stock_label( array $record ): string {
		$stock = $record['total_stock'] ?? null;
		if ( ! is_numeric( $stock ) ) {
			return 'نامشخص';
		}
		return (float) $stock > 0 ? 'موجود' : ( (float) $stock < 0 ? 'نیازمند بررسی' : 'ناموجود' );
	}

	private static function patris_revision( array $record ): string {
		$hash = (string) ( $record['record_hash'] ?? '' );
		if ( preg_match( '/^(?:sha256:)?([a-f0-9]{64})$/', $hash, $match ) ) {
			return 'rev:' . $match[1];
		}
		return Revision::hash(
			array(
				'code'            => $record['product_code'] ?? $record['category_code'] ?? '',
				'name'            => $record['name'] ?? '',
				'sourceUpdatedAt' => $record['source_updated_at'] ?? '',
			)
		);
	}

	private static function order_allowed_actions( string $status ): array {
		$actions = array( 'view' );
		if (
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability is registered by Store::add_capabilities during activation.
			current_user_can( 'digitalogic_viewer_manage' )
			&& self::allowed_order_transitions( $status )
		) {
			$actions[] = 'order.set_status';
		}
		return $actions;
	}

	private static function derived_customer_segment( int $order_count, $last_date ): string {
		if ( $order_count <= 1 ) {
			return 'new';
		}
		if ( $order_count >= 10 ) {
			return 'loyal';
		}
		if ( $last_date && $last_date->getTimestamp() < time() - 365 * DAY_IN_SECONDS ) {
			return 'inactive';
		}
		return 'returning';
	}

	private static function money_label( string $amount, string $currency ): string {
		if ( ! function_exists( 'wc_price' ) ) {
			return trim( $amount . ' ' . $currency );
		}
		return html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
	}

	private static function pagination( int $page, int $limit, int $total ): array {
		$pages = $limit > 0 ? (int) ceil( $total / $limit ) : 0;
		return array(
			'page'        => $page,
			'limit'       => $limit,
			'total'       => $total,
			'pages'       => $pages,
			'hasNext'     => $page < $pages,
			'hasPrevious' => $page > 1 && $pages > 0,
		);
	}

	private static function bounded_int( $value, int $default, int $min, int $max ): int {
		$number = is_numeric( $value ) ? (int) $value : $default;
		return max( $min, min( $max, $number ) );
	}

	private static function iso_time( string $value ): ?string {
		$timestamp = strtotime( $value );
		return $timestamp ? gmdate( 'c', $timestamp ) : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function error(
		string $code,
		string $source,
		string $message,
		bool $retryable,
		array $context = array()
	): array {
		return array(
			'code'      => sanitize_key( $code ),
			'source'    => sanitize_key( $source ),
			'message'   => sanitize_text_field( $message ),
			'retryable' => $retryable,
			'context'   => $context,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function warning( string $code, string $source, string $reference ): array {
		return array(
			'code'      => sanitize_key( $code ),
			'source'    => sanitize_key( $source ),
			'reference' => sanitize_text_field( $reference ),
		);
	}
}
