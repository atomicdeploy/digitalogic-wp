<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use WC_Order;
use WC_Product;
use WP_Post;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits bounded, PII-free canonical entity upserts/tombstones. Browser clients
 * can apply these events directly without refetching a catalog snapshot.
 */
final class Events {

	/** @var array<string,bool> */
	private static array $emitted = array();
	/** @var array<int,bool> */
	private static array $created_products = array();
	/** @var array<int,bool> */
	private static array $created_orders = array();
	/** @var array<int,bool> IDs received exclusively from verified commit snapshots. */
	private static array $committed_products = array();

	public static function register(): void {
		add_action( 'created_product_cat', array( self::class, 'category_created' ), 20, 1 );
		add_action( 'edited_product_cat', array( self::class, 'category_changed' ), 20, 1 );
		add_action( 'delete_product_cat', array( self::class, 'category_deleted' ), 20, 4 );

		add_action( 'save_post_product', array( self::class, 'product_saved' ), 30, 3 );
		add_action( 'save_post_product_variation', array( self::class, 'product_saved' ), 30, 3 );
		add_action(
			'woocommerce_after_product_object_save',
			array( self::class, 'product_object_saved' ),
			30,
			1
		);

		add_action(
			'woocommerce_after_order_object_save',
			array( self::class, 'order_object_saved' ),
			30,
			1
		);
		add_action( 'woocommerce_new_order', array( self::class, 'order_created' ), 30, 1 );
		add_action(
			'woocommerce_order_status_changed',
			array( self::class, 'order_status_changed' ),
			30,
			4
		);

		add_action( 'profile_update', array( self::class, 'customer_changed' ), 30, 1 );
		add_action( 'user_register', array( self::class, 'customer_created' ), 30, 1 );
		add_action( 'updated_user_meta', array( self::class, 'user_meta_changed' ), 30, 4 );
		add_action( 'added_user_meta', array( self::class, 'user_meta_changed' ), 30, 4 );

		add_action( 'added_option', array( self::class, 'option_changed' ), 30, 2 );
		add_action( 'updated_option', array( self::class, 'option_changed' ), 30, 3 );
		add_action( 'digitalogic_product_sync_state_committed', array( self::class, 'source_state_committed' ), 30, 2 );
		add_action( 'digitalogic_patris_materializer_product_committed', array( self::class, 'product_committed' ), 30, 1 );
		add_action( 'digitalogic_product_sync_product_committed', array( self::class, 'product_committed' ), 30, 1 );
		add_action( 'digitalogic_patris_materializer_product_commits_complete', array( self::class, 'product_commits_complete' ), 30, 0 );
		add_action( 'wp_update_nav_menu', array( self::class, 'menu_changed' ), 30, 1 );
	}

	/**
	 * @param string[]            $changes
	 * @param array<string,mixed> $value
	 * @return array<string,mixed>|WP_Error|null
	 */
	public static function emit(
		string $type,
		string $entity_type,
		string $entity_id,
		string $revision,
		array $changes = array(),
		array $value = array()
	) {
		$key        = implode( '|', array( $type, $entity_type, $entity_id, $revision ) );
		$entity_key = $entity_type . '|' . $entity_id;
		if ( ( self::$emitted[ $entity_key ] ?? null ) === $key ) {
			return null;
		}
		$result = Redis::publish_event(
			$type,
			$entity_type,
			$entity_id,
			$revision,
			$changes,
			$value
		);
		if ( ! is_wp_error( $result ) ) {
			self::$emitted[ $entity_key ] = $key;
		}
		return $result;
	}

	public static function category_changed( int $term_id ): void {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		$entity_id = Live_State::category_id( $term_id );
		self::emit(
			'category.updated',
			'category',
			$entity_id,
			Revision::category( $term ),
			array( 'taxonomy' ),
			Live_State::category_event_value( $entity_id )
				?? self::category_fallback( $term )
		);
	}

	public static function category_created( int $term_id ): void {
		$term = get_term( $term_id, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		$entity_id = Live_State::category_id( $term_id );
		self::emit(
			'category.created',
			'category',
			$entity_id,
			Revision::category( $term ),
			array( 'taxonomy' ),
			Live_State::category_event_value( $entity_id )
				?? self::category_fallback( $term )
		);
	}

	/**
	 * @param mixed $deleted_term
	 * @param mixed $object_ids
	 */
	public static function category_deleted(
		int $term_id,
		int $taxonomy_term_id,
		$deleted_term,
		$object_ids
	): void {
		unset( $taxonomy_term_id, $object_ids );
		$revision = Revision::hash(
			array(
				'deleted' => true,
				'termId'  => $term_id,
				'name'    => $deleted_term instanceof WP_Term ? $deleted_term->name : '',
			)
		);
		self::emit(
			'category.deleted',
			'category',
			Live_State::category_id( $term_id ),
			$revision,
			array( 'deleted' ),
			array(
				'id'              => Live_State::category_id( $term_id ),
				'name'            => $deleted_term instanceof WP_Term
					? sanitize_text_field( $deleted_term->name )
					: 'دسته حذف‌شده',
				'lifecycleStatus' => 'trashed',
				'revision'        => $revision,
			)
		);
	}

	public static function product_saved( int $post_id, WP_Post $post, bool $update ): void {
		unset( $post );
		if ( self::pricing_write_is_locked() ) {
			return;
		}
		if (
			wp_is_post_autosave( $post_id )
			|| wp_is_post_revision( $post_id )
			|| get_post_status( $post_id ) === 'auto-draft'
		) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( $product instanceof WC_Product ) {
			if ( ! $update ) {
				self::$created_products[ $product->get_id() ] = true;
			}
			self::product_object_saved( $product );
		}
	}

	/**
	 * @param mixed $product
	 */
	public static function product_object_saved( $product ): void {
		if ( ! $product instanceof WC_Product || self::pricing_write_is_locked() ) {
			return;
		}
		$entity_id = Live_State::product_id( $product->get_id() );
		self::emit_product( $product, Live_State::product_event_value( $entity_id ) );
	}

	private static function pricing_write_is_locked(): bool {
		if ( class_exists( 'Digitalogic_Pricing_Service' )
			&& \Digitalogic_Pricing_Service::instance()->source_delivery_lock_is_owned() ) {
			return true;
		}
		// Currency jobs also hold the receiver lock through COMMIT/ROLLBACK,
		// without necessarily entering the source-delivery wrapper.
		return class_exists( 'Digitalogic_Product_Sync_Receiver' )
			&& \Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned();
	}

	/** Collect no precommit save IDs: a rolled-back transaction has no snapshots. */
	public static function product_committed( $snapshot ): void {
		if ( self::pricing_write_is_locked() || ! is_array( $snapshot ) ) {
			return;
		}
		$product_id = absint( $snapshot['product_id'] ?? 0 );
		if ( $product_id > 0 ) {
			self::$committed_products[ $product_id ] = true;
		}
	}

	public static function product_commits_complete(): void {
		if ( self::pricing_write_is_locked() ) {
			return;
		}
		$product_ids              = array_keys( self::$committed_products );
		self::$committed_products = array();
		if ( ! $product_ids ) {
			return;
		}
		$values = Live_State::product_event_values(
			array_map(
				static fn( $id ): string => Live_State::product_id( (int) $id ),
				$product_ids
			)
		);
		foreach ( $product_ids as $product_id ) {
			$entity_id = Live_State::product_id( $product_id );
			$value     = $values[ $entity_id ] ?? null;
			if (
				is_array( $value )
				&& ( $value['id'] ?? null ) === $entity_id
				&& is_string( $value['status'] ?? null )
				&& $value['status'] === get_post_status( $product_id )
				&& is_string( $value['revision'] ?? null )
				&& preg_match( '/\Arev:[a-f0-9]{64}\z/D', $value['revision'] ) === 1
			) {
				// The serializer already read and revised this product. Keep
				// the envelope bound to that exact value without a third load.
				self::emit(
					self::product_event_type( $product_id, $value['status'] ),
					'product',
					$entity_id,
					$value['revision'],
					array( 'catalog' ),
					$value
				);
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product ) {
				self::emit_product( $product, $value );
			}
		}
	}

	/** Preserve ordinary product event classification and fallback with a shared bulk value. */
	private static function emit_product( WC_Product $product, ?array $value ): void {
		$entity_id = Live_State::product_id( $product->get_id() );
		self::emit(
			self::product_event_type( $product->get_id(), $product->get_status() ),
			'product',
			$entity_id,
			Revision::product( $product ),
			array( 'catalog' ),
			$value ?? self::product_fallback( $product )
		);
	}

	/** Keep ordinary and committed product event classification identical. */
	private static function product_event_type( int $product_id, string $status ): string {
		$type = isset( self::$created_products[ $product_id ] )
			? 'product.created'
			: 'product.updated';
		if ( $status === Live_State::PRODUCT_TRASH_STATUS ) {
			$type = 'product.deleted';
		} elseif (
			(string) get_post_meta(
				$product_id,
				'_digitalogic_viewer_previous_status',
				true
			) !== ''
		) {
			$type = 'product.restored';
		}
		return $type;
	}

	/**
	 * @param mixed $order
	 */
	public static function order_object_saved( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		self::emit(
			isset( self::$created_orders[ $order->get_id() ] )
				? 'order.created'
				: 'order.updated',
			'order',
			Live_State::order_id( $order->get_id() ),
			Revision::order( $order ),
			array( 'order' ),
			Live_State::order_event_value( $order )
		);
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$customer_value = Live_State::customer_event_value( $customer_id );
			self::emit(
				'customer.updated',
				'customer',
				Live_State::customer_id( $customer_id ),
				Revision::customer( $customer_id ),
				array( 'orders' ),
				$customer_value ?? array(
					'id'           => Live_State::customer_id( $customer_id ),
					'displayLabel' => sprintf( 'مشتری #%d', $customer_id ),
					'displayName'  => sprintf( 'مشتری #%d', $customer_id ),
				)
			);
		}
		$product_ids = array();
		foreach ( array_slice( $order->get_items( 'line_item' ), 0, 250 ) as $item ) {
			$product_id = absint( $item->get_product_id() ?: $item->get_variation_id() );
			if ( $product_id > 0 ) {
				$product_ids[ $product_id ] = true;
			}
		}
		$canonical_product_ids = array_map(
			static fn( $product_id ): string =>
				Live_State::product_id( (int) $product_id ),
			array_keys( $product_ids )
		);
		$product_values        = Live_State::product_event_values(
			$canonical_product_ids
		);
		foreach ( array_keys( $product_ids ) as $product_id ) {
			$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
			if ( $product instanceof WC_Product ) {
				$product_revision     = Revision::product( $product );
				$canonical_product_id = Live_State::product_id( $product_id );
				self::emit(
					'product.updated',
					'product',
					$canonical_product_id,
					$product_revision,
					array( 'sales' ),
					$product_values[ $canonical_product_id ] ?? array(
						'id'           => $canonical_product_id,
						'name'         => sanitize_text_field( $product->get_name() ),
						'sku'          => sanitize_text_field( (string) $product->get_sku() ),
						'soldQuantity' => max(
							0,
							(int) $product->get_total_sales()
						),
						'orderIds'     => array(
							Live_State::order_id( $order->get_id() ),
						),
						'revision'     => $product_revision,
					)
				);
			}
		}
	}

	public static function order_created( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		self::$created_orders[ $order_id ] = true;
		self::order_object_saved( $order );
	}

	/**
	 * @param mixed $order
	 */
	public static function order_status_changed(
		int $order_id,
		string $from,
		string $to,
		$order
	): void {
		unset( $from, $to );
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		self::order_object_saved( $order );
	}

	public static function customer_changed( int $user_id ): void {
		if ( ! self::is_customer( $user_id ) ) {
			return;
		}
		$value = Live_State::customer_event_value( $user_id );
		self::emit(
			'customer.updated',
			'customer',
			Live_State::customer_id( $user_id ),
			Revision::customer( $user_id ),
			array( 'profile' ),
			$value ?? array(
				'id'           => Live_State::customer_id( $user_id ),
				'displayLabel' => sprintf( 'مشتری #%d', $user_id ),
				'displayName'  => sprintf( 'مشتری #%d', $user_id ),
			)
		);
	}

	public static function customer_created( int $user_id ): void {
		if ( ! self::is_customer( $user_id ) ) {
			return;
		}
		$value = Live_State::customer_event_value( $user_id );
		self::emit(
			'customer.created',
			'customer',
			Live_State::customer_id( $user_id ),
			Revision::customer( $user_id ),
			array( 'profile' ),
			$value ?? array(
				'id'           => Live_State::customer_id( $user_id ),
				'displayLabel' => sprintf( 'مشتری #%d', $user_id ),
				'displayName'  => sprintf( 'مشتری #%d', $user_id ),
			)
		);
	}

	/**
	 * @param mixed $meta_value
	 */
	public static function user_meta_changed(
		int $meta_id,
		int $user_id,
		string $meta_key,
		$meta_value
	): void {
		unset( $meta_id, $meta_value );
		$safe_keys = array(
			'_digitalogic_viewer_segment',
			'_customer_user',
		);
		if ( in_array( $meta_key, $safe_keys, true ) ) {
			self::customer_changed( $user_id );
		}
	}

	/**
	 * Supports both added_option(name, value) and
	 * updated_option(name, old_value, value).
	 *
	 * @param mixed $old_or_value
	 * @param mixed $new_value
	 */
	public static function option_changed(
		string $option,
		$old_or_value = null,
		$new_value = null
	): void {
		if ( $option !== 'digitalogic_product_sync_state' || self::pricing_write_is_locked() ) {
			return;
		}
		$state            = is_array( $new_value )
			? $new_value
			: ( is_array( $old_or_value ) ? $old_or_value : get_option( $option, array() ) );
		$previous         = is_array( $new_value ) && is_array( $old_or_value )
			? $old_or_value
			: array();
		$before           = self::patris_event_index( $previous );
		$after            = self::patris_event_index( $state );
		$category_changes = self::changed_source_records(
			$before['categories'],
			$after['categories']
		);
		$product_changes  = self::changed_source_records(
			$before['products'],
			$after['products']
		);
		$applied_codes    = array_values(
			array_unique(
				array_merge(
					array_keys( $before['applied'] ),
					array_keys( $after['applied'] )
				)
			)
		);
		$mapping_changed  = false;
		foreach ( $applied_codes as $code ) {
			if (
				( $before['applied'][ $code ] ?? 0 )
				!== ( $after['applied'][ $code ] ?? 0 )
			) {
				$mapping_changed = true;
				if ( ! isset( $product_changes[ $code ] ) ) {
					$product_changes[ $code ] = array(
						'type'     => 'updated',
						'revision' => (string) (
							$after['products'][ $code ]
							?? $before['products'][ $code ]
							?? Revision::hash(
								array(
									'code'           => $code,
									'mappingChanged' => true,
								)
							)
						),
					);
				}
			}
		}
		if ( ! $category_changes && ! $product_changes && ! $mapping_changed ) {
			return;
		}
		$source_count     = is_array( $state['sources'] ?? null )
			? count( $state['sources'] )
			: 0;
		$catalog_revision = Revision::hash(
			array(
				'categories' => $after['categories'],
				'products'   => $after['products'],
				'applied'    => $after['applied'],
			)
		);
		self::emit(
			'catalog.patris_updated',
			'catalog',
			'catalog:patris',
			$catalog_revision,
			array( 'patris', 'categories', 'products' ),
			array(
				'id'          => 'catalog:patris',
				'sourceCount' => $source_count,
				'revision'    => $catalog_revision,
			)
		);

		$changed_total = count( $category_changes ) + count( $product_changes );
		if ( $changed_total > 2500 ) {
			return;
		}

		$canonical       = Live_State::canonical_context_for_actions();
		$category_values = $category_changes
			? Live_State::category_event_values()
			: array();
		foreach ( $category_changes as $code => $change ) {
			$source_removed   = $change['type'] === 'source_removed';
			$retained_term_id = $source_removed
				? self::woo_category_for_patris_code( (string) $code )
				: 0;
			$entity_id        = $retained_term_id > 0
				? Live_State::category_id( $retained_term_id )
				: (string) (
					$canonical['canonicalByPatris'][ $code ]
					?? ( 'cat:patris:' . $code )
				);
			$revision         = (string) (
				$canonical['nodes'][ $entity_id ]['revision']
				?? $change['revision']
			);
			$event_type       = $source_removed && $retained_term_id < 1
				? 'category.deleted'
				: (
					$source_removed || $retained_term_id > 0
						? 'category.updated'
						: 'category.' . $change['type']
				);
			$value            = $event_type === 'category.deleted'
				? array(
					'id'              => $entity_id,
					'lifecycleStatus' => 'trashed',
					'revision'        => $revision,
				)
				: ( $category_values[ $entity_id ] ?? array(
					'id'       => $entity_id,
					'name'     => sanitize_text_field(
						(string) (
							$after['categoryRecords'][ $code ]['name']
							?? $code
						)
					),
					'revision' => $revision,
				) );
			self::emit(
				$event_type,
				'category',
				$entity_id,
				$revision,
				$source_removed
					? array( 'patris', 'source_removed' )
					: array( 'patris' ),
				$value
			);
		}
		ksort( $product_changes, SORT_NATURAL );
		$requested_product_ids = array();
		foreach ( array_keys( $product_changes ) as $code ) {
			$before_woo_id = absint( $before['applied'][ $code ] ?? 0 );
			$after_woo_id  = absint( $after['applied'][ $code ] ?? 0 );
			if ( $before_woo_id > 0 ) {
				$requested_product_ids[] =
					Live_State::product_id( $before_woo_id );
			}
			if ( $after_woo_id > 0 ) {
				$requested_product_ids[] =
					Live_State::product_id( $after_woo_id );
			} elseif ( isset( $after['products'][ $code ] ) ) {
				$requested_product_ids[] =
					Live_State::canonical_product_id_for_patris(
						(string) $code
					);
			}
		}
		$product_values = Live_State::product_event_values(
			array_values( array_unique( $requested_product_ids ) )
		);
		foreach ( $product_changes as $code => $change ) {
			$before_woo_id = absint( $before['applied'][ $code ] ?? 0 );
			$after_woo_id  = absint( $after['applied'][ $code ] ?? 0 );
			if (
				$before_woo_id > 0
				&& $before_woo_id !== $after_woo_id
			) {
				$old_entity_id = Live_State::product_id( $before_woo_id );
				$old_product   = wc_get_product( $before_woo_id );
				if ( $old_product instanceof WC_Product ) {
					$old_revision = Revision::product( $old_product );
					self::emit(
						'product.updated',
						'product',
						$old_entity_id,
						$old_revision,
						array( 'patris', 'source_unmapped' ),
						$product_values[ $old_entity_id ]
							?? self::product_fallback( $old_product )
					);
				}
			}
			$source_removed = $change['type'] === 'source_removed';
			$woo_id         = $after_woo_id > 0
				? $after_woo_id
				: ( $source_removed ? $before_woo_id : 0 );
			$product        = $woo_id > 0 ? wc_get_product( $woo_id ) : null;
			$revision       = $product instanceof WC_Product
				? Revision::product( $product )
				: $change['revision'];
			$entity_id      = $woo_id > 0
				? Live_State::product_id( $woo_id )
				: ( 'product:patris:' . $code );
			$event_type     = $source_removed && $woo_id < 1
				? 'product.deleted'
				: (
					$woo_id > 0
						? 'product.updated'
						: 'product.' . $change['type']
				);
			$value          = $event_type === 'product.deleted'
				? array(
					'id'              => $entity_id,
					'lifecycleStatus' => 'trashed',
					'revision'        => $revision,
				)
				: ( $product_values[ $entity_id ] ?? array(
					'id'       => $entity_id,
					'name'     => sanitize_text_field(
						(string) (
							$after['productRecords'][ $code ]['name']
							?? $code
						)
					),
					'sku'      => sanitize_text_field(
						(string) (
							$after['productRecords'][ $code ]['serial']
							?? ''
						)
					),
					'revision' => $revision,
				) );
			self::emit(
				$event_type,
				'product',
				$entity_id,
				$revision,
				$source_removed
					? array( 'patris', 'source_removed' )
					: array( 'patris' ),
				$value
			);
		}
	}

	public static function source_state_committed( $before, $after ): void {
		self::option_changed( 'digitalogic_product_sync_state', $before, $after );
	}

	public static function menu_changed( int $menu_id ): void {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu || (string) $menu->slug !== 'digitalogic-product-categories' ) {
			return;
		}
		$items      = wp_get_nav_menu_items( $menu_id );
		$safe_items = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( $item->type !== 'taxonomy' || $item->object !== 'product_cat' ) {
				continue;
			}
			$safe_items[] = array(
				'id'           => (int) $item->ID,
				'termId'       => (int) $item->object_id,
				'parentItemId' => (int) $item->menu_item_parent,
				'order'        => (int) $item->menu_order,
			);
		}
		self::emit(
			'catalog.menu_updated',
			'catalog',
			'catalog:categories',
			Revision::hash(
				array(
					'menuId' => $menu_id,
					'items'  => $safe_items,
				)
			),
			array( 'menu', 'categories' ),
			array(
				'id'        => 'catalog:categories',
				'menuId'    => $menu_id,
				'itemCount' => count( $safe_items ),
			)
		);
		foreach ( Live_State::category_event_values() as $entity_id => $value ) {
			if ( $entity_id === Live_State::ROOT_CATEGORY_ID ) {
				continue;
			}
			self::emit(
				'category.updated',
				'category',
				(string) $entity_id,
				(string) (
					$value['revision']
					?? Revision::hash(
						array(
							'entityId' => $entity_id,
							'menuId'   => $menu_id,
						)
					)
				),
				array( 'menu', 'presentation' ),
				$value
			);
		}
	}

	/**
	 * @return array{
	 *   categories:array<string,string>,
	 *   products:array<string,string>,
	 *   applied:array<string,int>,
	 *   categoryRecords:array<string,array<string,mixed>>,
	 *   productRecords:array<string,array<string,mixed>>
	 * }
	 */
	private static function patris_event_index( array $state ): array {
		$index = array(
			'categories'      => array(),
			'products'        => array(),
			'applied'         => array(),
			'categoryRecords' => array(),
			'productRecords'  => array(),
		);
		foreach ( (array) ( $state['sources'] ?? array() ) as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			foreach ( (array) ( $source['categories'] ?? array() ) as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$code = Store::patris_code( (string) ( $record['category_code'] ?? '' ) );
				if ( $code !== '' ) {
					$index['categories'][ $code ]      = self::safe_record_fingerprint(
						$code,
						$record
					);
					$index['categoryRecords'][ $code ] = $record;
				}
			}
			foreach ( (array) ( $source['products'] ?? array() ) as $record ) {
				if ( ! is_array( $record ) ) {
					continue;
				}
				$code = Store::patris_code( (string) ( $record['product_code'] ?? '' ) );
				if ( $code !== '' ) {
					$index['products'][ $code ]       = self::safe_record_fingerprint(
						$code,
						$record
					);
					$index['productRecords'][ $code ] = $record;
				}
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
					$index['applied'][ $product_code ] = $woo_id;
				}
			}
		}
		foreach (
			array(
				'categories',
				'products',
				'applied',
				'categoryRecords',
				'productRecords',
			) as $collection
		) {
			ksort( $index[ $collection ], SORT_NATURAL );
		}
		return $index;
	}

	private static function safe_record_fingerprint( string $code, array $record ): string {
		$hash = strtolower( (string) ( $record['record_hash'] ?? '' ) );
		if ( preg_match( '/^(?:sha256:)?([a-f0-9]{64})$/', $hash, $match ) ) {
			return 'rev:' . $match[1];
		}
		return Revision::hash(
			array(
				'code'            => $code,
				'parentCode'      => Store::patris_code(
					(string) ( $record['parent_code'] ?? '' )
				),
				'categoryCode'    => Store::patris_code(
					(string) ( $record['category_code'] ?? '' )
				),
				'sourceUpdatedAt' => sanitize_text_field(
					(string) ( $record['source_updated_at'] ?? '' )
				),
			)
		);
	}

	/**
	 * @return array<string,array{type:string,revision:string}>
	 */
	private static function changed_source_records( array $before, array $after ): array {
		$changes = array();
		$codes   = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		sort( $codes, SORT_NATURAL );
		foreach ( $codes as $code ) {
			$old_revision = (string) ( $before[ $code ] ?? '' );
			$new_revision = (string) ( $after[ $code ] ?? '' );
			if ( $old_revision === $new_revision ) {
				continue;
			}
			if ( $new_revision === '' ) {
				$changes[ $code ] = array(
					'type'     => 'source_removed',
					'revision' => Revision::hash(
						array(
							'code'          => $code,
							'sourceRemoved' => true,
						)
					),
				);
			} else {
				$changes[ $code ] = array(
					'type'     => $old_revision === '' ? 'created' : 'updated',
					'revision' => $new_revision,
				);
			}
		}
		return $changes;
	}

	private static function woo_category_for_patris_code( string $code ): int {
		$mapping        = Store::mappings()[ $code ] ?? null;
		$mapped_term_id = is_array( $mapping )
			? absint( $mapping['termId'] ?? 0 )
			: 0;
		if ( $mapped_term_id > 0 ) {
			return $mapped_term_id;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 0,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		foreach ( (array) $terms as $term_id ) {
			if (
				Store::patris_code(
					(string) get_term_meta(
						absint( $term_id ),
						'_digitalogic_patris_category_code',
						true
					)
				) === $code
			) {
				return absint( $term_id );
			}
		}
		return 0;
	}

	private static function is_customer( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return in_array( 'customer', (array) $user->roles, true );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function category_fallback( WP_Term $term ): array {
		return array(
			'id'              => Live_State::category_id( (int) $term->term_id ),
			'name'            => sanitize_text_field( $term->name ),
			'parentId'        => (int) $term->parent > 0
				? Live_State::category_id( (int) $term->parent )
				: Live_State::ROOT_CATEGORY_ID,
			'productCount'    => max( 0, (int) $term->count ),
			'descendantCount' => max( 0, (int) $term->count ),
			'countKnown'      => true,
			'source'          => 'woocommerce',
			'iconKey'         => 'component',
			'lifecycleStatus' => 'active',
			'revision'        => Revision::category( $term ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function product_fallback( WC_Product $product ): array {
		return array(
			'id'              => Live_State::product_id( $product->get_id() ),
			'name'            => sanitize_text_field( $product->get_name() ),
			'sku'             => sanitize_text_field( (string) $product->get_sku() ),
			'categoryIds'     => array_map(
				static fn( $term_id ): string => Live_State::category_id(
					absint( $term_id )
				),
				array_values(
					array_filter(
						array_map(
							'absint',
							(array) $product->get_category_ids()
						)
					)
				)
			),
			'status'          => sanitize_key( $product->get_status() ),
			'stockStatus'     => sanitize_key( $product->get_stock_status() ),
			'soldQuantity'    => max( 0, (int) $product->get_total_sales() ),
			'lifecycleStatus' =>
				$product->get_status() === Live_State::PRODUCT_TRASH_STATUS
					? 'trashed'
					: 'active',
			'revision'        => Revision::product( $product ),
		);
	}
}
