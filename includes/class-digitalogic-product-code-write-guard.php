<?php
/**
 * Canonical Product Code metadata write boundary.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Default-deny every generic Product Code writer. */
final class Digitalogic_Product_Code_Write_Guard {

	private const META_KEY = '_digitalogic_patris_product_code';
	private const WRITERS  = array( 'editor', 'legacy_feed', 'materializer' );

	/** @var self|null */
	private static $instance = null;

	/** @var array<int,array> */
	private $writer_stack = array();

	/** @var array<int,int> Exact post IDs inside WordPress permanent-delete cleanup. */
	private $deletion_scopes = array();

	/** @var array<int,int> Source-lock nesting acquired for permanent deletion. */
	private $deletion_source_locks = array();

	/** Return the shared guard. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register key-based, by-mid, and WooCommerce REST boundaries. */
	private function __construct() {
		add_filter( 'add_post_metadata', array( $this, 'guard_key_set' ), 1, 5 );
		add_filter( 'update_post_metadata', array( $this, 'guard_key_set' ), 1, 5 );
		add_filter( 'delete_post_metadata', array( $this, 'guard_key_delete' ), 1, 5 );
		add_filter( 'update_post_metadata_by_mid', array( $this, 'guard_mid_update' ), 1, 4 );
		add_filter( 'delete_post_metadata_by_mid', array( $this, 'guard_mid_delete' ), 1, 2 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'reject_rest_write' ), 1, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this, 'reject_rest_write' ), 1, 3 );
		add_filter( 'pre_delete_post', array( $this, 'acquire_for_post_deletion' ), 1, 3 );
		add_action( 'before_delete_post', array( $this, 'begin_post_deletion' ), PHP_INT_MAX, 2 );
		add_action( 'deleted_post', array( $this, 'finish_post_deletion' ), 1, 2 );
		add_action( 'shutdown', array( $this, 'release_deletion_locks' ), PHP_INT_MAX );
	}

	/**
	 * Run one explicit writer only while it owns the shared source lock.
	 *
	 * @param string   $writer Exact internal writer class.
	 * @param array    $scope Exact object, operation, and value.
	 * @param callable $callback Bounded write.
	 * @return mixed|WP_Error
	 */
	public function with_authorized_write( $writer, $scope, $callback ) {
		$writer = (string) $writer;
		if (
			! in_array( $writer, self::WRITERS, true )
			|| ! is_array( $scope )
			|| ! is_callable( $callback )
			|| ! class_exists( 'Digitalogic_Product_Sync_Receiver' )
			|| ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
		) {
			return $this->error();
		}
		$operation  = (string) ( $scope['operation'] ?? '' );
		$product    = is_object( $scope['product'] ?? null ) ? $scope['product'] : null;
		$product_id = isset( $scope['product_id'] ) ? absint( $scope['product_id'] ) : 0;
		$object_id  = $product && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		if ( $object_id > 0 ) {
			if ( $product_id > 0 && $product_id !== $object_id ) {
				return $this->error();
			}
			$product_id = $object_id;
		}
		if (
			! in_array( $operation, array( 'set', 'delete' ), true )
			|| $product_id <= 0
			|| ( 'set' === $operation && ! is_string( $scope['value'] ?? null ) )
		) {
			return $this->error();
		}

		$this->writer_stack[] = array(
			'writer'     => $writer,
			'product_id' => $product_id,
			'operation'  => $operation,
			'value'      => 'set' === $operation ? $scope['value'] : null,
		);
		try {
			$result = call_user_func( $callback );
			return Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
				? $result
				: $this->lock_lost_error();
		} finally {
			array_pop( $this->writer_stack );
		}
	}

	/** Default-deny key-based add/update operations outside one exact scope. */
	public function guard_key_set( $check, $object_id, $meta_key, $meta_value = null, $extra = null ) {
		unset( $extra );
		if ( ! $this->is_canonical_key( $meta_key ) ) {
			return $check;
		}

		return self::META_KEY === (string) $meta_key && $this->authorized( 'set', $object_id, $meta_value ) ? $check : false;
	}

	/** Default-deny key-based deletion outside one exact scope. */
	public function guard_key_delete( $check, $object_id, $meta_key, $meta_value = null, $delete_all = false ) {
		unset( $meta_value );
		if ( ! $this->is_canonical_key( $meta_key ) ) {
			return $check;
		}
		if ( $delete_all ) {
			return false;
		}
		if ( $this->is_lifecycle_deletion( $object_id ) ) {
			return $check;
		}

		return self::META_KEY === (string) $meta_key && $this->authorized( 'delete', $object_id, null ) ? $check : false;
	}

	/** Default-deny by-mid updates after resolving the exact existing key. */
	public function guard_mid_update( $check, $meta_id, $meta_value, $meta_key = false ) {
		$meta          = $this->metadata_for_mid( $meta_id );
		$existing_key  = (string) ( $meta['meta_key'] ?? '' );
		$requested_key = is_string( $meta_key ) && '' !== $meta_key ? $meta_key : $existing_key;
		if ( ! $this->is_canonical_key( $existing_key ) && ! $this->is_canonical_key( $requested_key ) ) {
			return $check;
		}
		if ( self::META_KEY !== $existing_key || self::META_KEY !== $requested_key ) {
			return false;
		}

		return $this->authorized( 'set', (int) ( $meta['post_id'] ?? 0 ), $meta_value ) ? $check : false;
	}

	/** Default-deny by-mid deletes after resolving the exact existing key. */
	public function guard_mid_delete( $check, $meta_id ) {
		$meta = $this->metadata_for_mid( $meta_id );
		$key  = (string) ( $meta['meta_key'] ?? '' );
		$id   = (int) ( $meta['post_id'] ?? 0 );
		if ( ! $this->is_canonical_key( $key ) ) {
			return $check;
		}
		if ( $this->is_lifecycle_deletion( $id ) ) {
			return $check;
		}

		return self::META_KEY === $key && $this->authorized( 'delete', $id, null ) ? $check : false;
	}

	/** Reject product and variation REST meta_data bypasses with a typed error. */
	public function reject_rest_write( $product, $request, $creating = false ) {
		unset( $creating );
		$meta_data = is_object( $request ) && method_exists( $request, 'get_param' )
			? $request->get_param( 'meta_data' )
			: null;
		if ( ! is_array( $meta_data ) ) {
			return $product;
		}
		foreach ( $meta_data as $row ) {
			$row = is_object( $row ) ? get_object_vars( $row ) : $row;
			if ( is_array( $row ) && $this->is_canonical_key( $row['key'] ?? '' ) ) {
				return $this->error();
			}
		}

		return $product;
	}

	/** Open only the exact product lifecycle cleanup scope after all other pre-delete work. */
	public function begin_post_deletion( $post_id, $post = null ) {
		$type = is_object( $post ) ? (string) ( $post->post_type ?? '' ) : (string) get_post_type( $post_id );
		if ( in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			$id = absint( $post_id );
			if (
				empty( $this->deletion_source_locks[ $id ] )
				|| ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
			) {
				throw new RuntimeException( 'Canonical Product Code deletion lost its shared identity lock.' );
			}
			$this->deletion_scopes[ $id ] = (int) ( $this->deletion_scopes[ $id ] ?? 0 ) + 1;
		}
	}

	/** Close the exact lifecycle cleanup scope before later deleted-post callbacks run. */
	public function finish_post_deletion( $post_id, $post = null ) {
		unset( $post );
		$id = absint( $post_id );
		if ( ! isset( $this->deletion_scopes[ $id ] ) ) {
			return;
		}
		--$this->deletion_scopes[ $id ];
		if ( $this->deletion_scopes[ $id ] <= 0 ) {
			unset( $this->deletion_scopes[ $id ] );
		}
		$this->release_post_deletion_lock( $id );
	}

	/** Acquire the shared source lock before WordPress begins permanent deletion. */
	public function acquire_for_post_deletion( $delete, $post, $force_delete ) {
		if ( null !== $delete || ! $force_delete || ! is_object( $post ) ) {
			return $delete;
		}
		$type = (string) ( $post->post_type ?? '' );
		$id   = absint( $post->ID ?? 0 );
		if ( $id <= 0 || ! in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			return $delete;
		}
		$locked = Digitalogic_Product_Sync_Receiver::instance()->acquire_source_identity_lock( 0 );
		if ( is_wp_error( $locked ) ) {
			return false;
		}
		$this->deletion_source_locks[ $id ] = (int) ( $this->deletion_source_locks[ $id ] ?? 0 ) + 1;

		return $delete;
	}

	/** Release one exact permanent-deletion source lock. */
	private function release_post_deletion_lock( $post_id ) {
		$post_id = absint( $post_id );
		if ( empty( $this->deletion_source_locks[ $post_id ] ) ) {
			return;
		}
		--$this->deletion_source_locks[ $post_id ];
		if ( $this->deletion_source_locks[ $post_id ] <= 0 ) {
			unset( $this->deletion_source_locks[ $post_id ] );
		}
		Digitalogic_Product_Sync_Receiver::instance()->release_source_identity_lock();
	}

	/** Drain deletion locks if WordPress exits before deleted_post fires. */
	public function release_deletion_locks() {
		foreach ( array_keys( $this->deletion_source_locks ) as $post_id ) {
			while ( ! empty( $this->deletion_source_locks[ $post_id ] ) ) {
				$this->release_post_deletion_lock( $post_id );
			}
		}
		$this->deletion_scopes = array();
	}

	/** Return whether one explicit, lock-owned writer context is active. */
	private function authorized( $operation, $object_id, $value ) {
		if (
			empty( $this->writer_stack )
			|| ! class_exists( 'Digitalogic_Product_Sync_Receiver' )
			|| ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
		) {
			return false;
		}
		$scope      = end( $this->writer_stack );
		$authorized = (int) ( $scope['product_id'] ?? 0 );

		return $authorized > 0
			&& $authorized === absint( $object_id )
			&& hash_equals( (string) $scope['operation'], (string) $operation )
			&& ( 'delete' === $operation || ( is_string( $value ) && hash_equals( (string) $scope['value'], $value ) ) );
	}

	/** Match database-collation-equivalent key spellings without authorizing them. */
	private function is_canonical_key( $meta_key ) {
		return is_scalar( $meta_key ) && 0 === strcasecmp( self::META_KEY, (string) $meta_key );
	}

	/** Return whether WordPress is deleting this exact product or variation. */
	private function is_lifecycle_deletion( $object_id ) {
		$id = absint( $object_id );
		return $id > 0
			&& ! empty( $this->deletion_scopes[ $id ] )
			&& ! empty( $this->deletion_source_locks[ $id ] )
			&& Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned();
	}

	/** Resolve by-mid metadata identity without trusting request input. */
	private function metadata_for_mid( $meta_id ) {
		if ( ! function_exists( 'get_metadata_by_mid' ) ) {
			return array();
		}
		$meta = get_metadata_by_mid( 'post', absint( $meta_id ) );
		return is_object( $meta )
			? array(
				'post_id'  => (int) ( $meta->post_id ?? 0 ),
				'meta_key' => (string) ( $meta->meta_key ?? '' ),
			)
			: array();
	}

	/** Build the single machine-readable bypass error. */
	private function error() {
		return new WP_Error(
			'digitalogic_product_code_dedicated_operation_required',
			__( 'Product Code changes must use the dedicated audited operation.', 'digitalogic' ),
			array(
				'status' => 409,
				'schema' => Digitalogic_Product_Code_Editor::SCHEMA,
			)
		);
	}

	/** Return a retryable result when MySQL reconnects inside a writer scope. */
	private function lock_lost_error() {
		return new WP_Error(
			'digitalogic_product_code_source_lock_lost',
			__( 'The source identity lock was lost after a database reconnect. Retry the unchanged request.', 'digitalogic' ),
			array(
				'status'      => 503,
				'retryable'   => true,
				'retry_after' => 1,
			)
		);
	}
}
