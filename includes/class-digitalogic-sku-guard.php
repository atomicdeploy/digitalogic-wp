<?php
/**
 * Canonical WooCommerce SKU normalization and uniqueness boundary.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The currently deployed v2.0.1 MU loader requires this class directly before
// ordinary plugins load. Keep that upgrade and rollback path self-contained.
if ( ! class_exists( 'Digitalogic_Product_Write_Lock', false ) ) {
	$digitalogic_product_write_lock_file = __DIR__ . '/class-digitalogic-product-write-lock.php';
	if ( is_readable( $digitalogic_product_write_lock_file ) ) {
		require_once $digitalogic_product_write_lock_file;
	}
	unset( $digitalogic_product_write_lock_file );
}

/** Normalize, validate, and serialize every supported WooCommerce SKU write. */
final class Digitalogic_SKU_Guard {

	public const VERSION = '2.1.0';

	private const SKU_META             = '_sku';
	private const MAX_LENGTH           = 100;
	private const LOCK_TIMEOUT_SECONDS = 5;

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,array{product_id:int,count:int,connection_id:int}> */
	private $held_locks = array();

	/** @var array<int,array{service:Digitalogic_Product_Write_Lock,count:int}> Guard-owned product-lock depths. */
	private $held_product_locks = array();

	/** @var array<int,bool> update_metadata() calls that must fall through to add_metadata(). */
	private $pending_update_add = array();

	/** @var array<int,array<int,string>> SKU scopes held while a post enters a WooCommerce type. */
	private $pending_promotions = array();

	/** @var array<int,array<int,array{product_id:int,lock_owner_id:int,sku:string,lock_name:string}>> Active Woo CRUD scopes by object identity. */
	private $active_woocommerce_writes = array();

	/** Return the shared guard. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register early WooCommerce, metadata, normalization, and cleanup hooks. */
	private function __construct() {
		if ( ! class_exists( 'Digitalogic_Product_Write_Lock', false ) ) {
			throw new RuntimeException( 'The product write lock is unavailable; the SKU guard cannot start.' );
		}
		Digitalogic_Product_Write_Lock::instance();

		// This short-circuit runs before WooCommerce's later importer bypass filter.
		add_filter( 'wc_product_pre_has_unique_sku', array( $this, 'woocommerce_unique_sku' ), PHP_INT_MAX, 3 );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'normalize_product_before_save' ), 2, 2 );

		// Canonicalize the persisted value and cover every WordPress metadata write API.
		add_filter( 'sanitize_post_meta__sku', array( $this, 'sanitize_sku_meta' ), PHP_INT_MAX, 4 );
		add_filter( 'add_post_metadata', array( $this, 'guard_add_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata', array( $this, 'guard_update_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata_by_mid', array( $this, 'guard_update_metadata_by_mid' ), PHP_INT_MAX, 4 );
		add_filter( 'delete_post_metadata', array( $this, 'guard_delete_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'delete_post_metadata_by_mid', array( $this, 'guard_delete_metadata_by_mid' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'guard_product_type_promotion' ), PHP_INT_MAX, 4 );
		add_action( 'added_post_meta', array( $this, 'release_after_metadata_write' ), PHP_INT_MAX, 4 );
		add_action( 'updated_post_meta', array( $this, 'release_after_metadata_write' ), PHP_INT_MAX, 4 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'verify_and_release_after_product_save' ), PHP_INT_MAX - 1, 1 );
		add_action( 'wp_insert_post', array( $this, 'release_after_product_type_promotion' ), PHP_INT_MAX, 3 );
		add_action( 'shutdown', array( $this, 'release_all_locks' ), PHP_INT_MAX );
	}

	/**
	 * Canonicalize an SKU exactly as the Digitalogic identity report does.
	 *
	 * @param mixed $value Entered SKU.
	 * @return string
	 */
	public static function normalize( $value ) {
		if ( class_exists( 'Digitalogic_Catalog_Identity_Reconciler' ) ) {
			return Digitalogic_Catalog_Identity_Reconciler::normalize_identifier( $value );
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( class_exists( 'Normalizer' ) ) {
			$unicode = Normalizer::normalize( $value, Normalizer::FORM_KC );
			if ( is_string( $unicode ) ) {
				$value = $unicode;
			}
		}

		$value = strtr(
			$value,
			array(
				'ك' => 'ک',
				'ي' => 'ی',
				'ى' => 'ی',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
			)
		);
		$value = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $value ) ?? $value;
		$value = preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', '', $value ) ?? $value;

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	/**
	 * Return a canonical SKU or a typed validation failure. Blank remains valid.
	 *
	 * @param mixed $value Entered SKU.
	 * @return string|WP_Error
	 */
	public static function validate( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return self::validation_error( 'digitalogic_sku_not_scalar', 'SKU must be a text value.' );
		}

		$raw        = null === $value ? '' : (string) $value;
		$normalized = self::normalize( $raw );
		if ( '' === $normalized ) {
			return '' === $raw
				? ''
				: self::validation_error( 'digitalogic_sku_empty_after_normalization', 'SKU contains no usable characters.' );
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $normalized, 'UTF-8' ) : strlen( $normalized );
		if ( $length > self::MAX_LENGTH ) {
			return self::validation_error( 'digitalogic_sku_too_long', 'SKU cannot exceed 100 characters.' );
		}
		if ( 1 !== preg_match( '/^[\p{L}\p{N}._:\/+#@()\-]+$/u', $normalized ) ) {
			return self::validation_error( 'digitalogic_sku_invalid_characters', 'SKU contains unsupported characters.' );
		}

		return $normalized;
	}

	/** Make WooCommerce validation authoritative even when an importer disables its native check. */
	public function woocommerce_unique_sku( $pre, $product_id, $sku ) {
		unset( $pre );
		$validated = self::validate( $sku );
		if ( is_wp_error( $validated ) ) {
			// Format validation belongs to the before-save boundary below. Returning
			// false here makes WooCommerce recursively generate suffixed suggestions
			// from an invalid base that can never become valid.
			return true;
		}
		if ( '' === $validated ) {
			return true;
		}

		$check = $this->find_collision( (int) $product_id, $validated );
		if ( $check['blocked'] ) {
			$this->record_block( (int) $product_id, $sku, (int) $check['collision_id'], (string) $check['reason'], 'woocommerce_crud' );
			return false;
		}

		return true;
	}

	/** Normalize the in-memory WooCommerce object before both postmeta and lookup-table writes. */
	public function normalize_product_before_save( $product, $data_store = null ) {
		unset( $data_store );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) || ! method_exists( $product, 'set_sku' ) ) {
			return;
		}

		$raw       = $product->get_sku( 'edit' );
		$validated = self::validate( $raw );
		if ( is_wp_error( $validated ) ) {
			$this->record_block( (int) $product->get_id(), $raw, 0, $validated->get_error_code(), 'woocommerce_save' );
			throw new WC_Data_Exception( $validated->get_error_code(), $validated->get_error_message(), 400 );
		}
		if ( (string) $raw !== $validated ) {
			$product->set_sku( $validated );
		}

		$product_id   = (int) $product->get_id();
		$object_id    = spl_object_id( $product );
		$lock_owner   = $product_id > 0 ? $product_id : -$object_id;
		$product_lock = true;
		if ( $product_id > 0 ) {
			$product_lock = $this->acquire_product_lock( $product_id );
		}
		if ( is_wp_error( $product_lock ) ) {
			$this->record_block( $product_id, $raw, 0, $product_lock->get_error_code(), 'woocommerce_save' );
			throw new WC_Data_Exception( $product_lock->get_error_code(), $product_lock->get_error_message(), 503 );
		}

		$lock_name = '';
		if ( '' !== $validated ) {
			$lock_name = (string) $this->acquire_lock( $lock_owner, $validated );
			if ( '' === $lock_name ) {
				if ( $product_id > 0 ) {
					$this->release_product_lock( $product_id );
				}
				$this->record_block( $product_id, $raw, 0, 'lock_unavailable', 'woocommerce_save' );
				throw new WC_Data_Exception( 'lock_unavailable', 'The SKU lock is unavailable.', 503 );
			}
			$collision = $this->find_collision( $product_id, $validated );
			if ( $collision['blocked'] ) {
				$this->release_lock( $lock_name, $lock_owner );
				if ( $product_id > 0 ) {
					$this->release_product_lock( $product_id );
				}
				$this->record_block( $product_id, $raw, (int) $collision['collision_id'], (string) $collision['reason'], 'woocommerce_save' );
				throw new WC_Data_Exception( (string) $collision['reason'], 'The SKU is already assigned to another product.', 400 );
			}
		}

		$this->active_woocommerce_writes[ $object_id ][] = array(
			'product_id'    => $product_id,
			'lock_owner_id' => $lock_owner,
			'sku'           => $validated,
			'lock_name'     => $lock_name,
			'product_lock'  => $product_id > 0,
		);
	}

	/** Preserve invalid input for the subsequent fail-closed write guard; canonicalize only valid values. */
	public function sanitize_sku_meta( $value, $meta_key = '', $object_type = '', $object_subtype = '' ) {
		unset( $meta_key, $object_type, $object_subtype );
		$validated = self::validate( $value );

		return is_wp_error( $validated ) ? $value : $validated;
	}

	/** Block a second _sku row on one product and cross-product collisions. */
	public function guard_add_metadata( $check, $object_id, $meta_key, $meta_value, $unique ) {
		unset( $unique );
		$key_state = $this->sku_meta_key_state( $meta_key );
		if ( 'other' === $key_state ) {
			return $check;
		}
		if ( 'canonical' === $key_state && $this->authorize_woocommerce_metadata_write( (int) $object_id, $meta_value, 'add_post_metadata' ) ) {
			return $check;
		}
		$this->record_block( (int) $object_id, $meta_value, 0, 'direct_sku_write_requires_woocommerce_crud', 'add_post_metadata' );
		return false;
	}

	/** Block direct key-based metadata updates that are invalid or collide. */
	public function guard_update_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $prev_value );
		$key_state = $this->sku_meta_key_state( $meta_key );
		if ( 'other' === $key_state ) {
			return $check;
		}
		if ( 'canonical' === $key_state && $this->authorize_woocommerce_metadata_write( (int) $object_id, $meta_value, 'update_post_metadata' ) ) {
			return $check;
		}
		$this->record_block( (int) $object_id, $meta_value, 0, 'direct_sku_write_requires_woocommerce_crud', 'update_post_metadata' );
		return false;
	}

	/** Cover update_metadata_by_mid(), which bypasses update_post_metadata. */
	public function guard_update_metadata_by_mid( $check, $meta_id, $meta_value, $meta_key ) {
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( ! $meta ) {
			return $check;
		}
		$object_id       = (int) $meta->post_id;
		$preliminary_key = false === $meta_key || null === $meta_key ? (string) $meta->meta_key : (string) $meta_key;
		if ( 'other' === $this->sku_meta_key_state( $preliminary_key ) ) {
			return $check;
		}
		$this->record_block( $object_id, $meta_value, 0, 'sku_by_mid_write_forbidden', 'update_post_metadata_by_mid' );
		return false;
	}

	/** Block raw SKU deletion unless it is the exact blank-SKU Woo CRUD scope. */
	public function guard_delete_metadata( $check, $object_id, $meta_key, $meta_value, $delete_all ) {
		unset( $delete_all );
		if ( 'other' === $this->sku_meta_key_state( $meta_key ) ) {
			return $check;
		}
		if ( $this->authorize_woocommerce_metadata_write( (int) $object_id, '', 'delete_post_metadata' ) ) {
			return $check;
		}

		$this->record_block( (int) $object_id, $meta_value, 0, 'direct_sku_write_requires_woocommerce_crud', 'delete_post_metadata' );
		return false;
	}

	/** Block by-mid SKU deletion because WooCommerce never uses that route. */
	public function guard_delete_metadata_by_mid( $check, $meta_id ) {
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( ! is_object( $meta ) || 'other' === $this->sku_meta_key_state( $meta->meta_key ?? '' ) ) {
			return $check;
		}

		$this->record_block( (int) ( $meta->post_id ?? 0 ), (string) ( $meta->meta_value ?? '' ), 0, 'sku_by_mid_write_forbidden', 'delete_post_metadata_by_mid' );
		return false;
	}

	/**
	 * Fence a non-product post that is being promoted into a WooCommerce type.
	 *
	 * @param array $data                Sanitized post data.
	 * @param array $postarr             Submitted post data.
	 * @param array $unsanitized_postarr Original submitted post data.
	 * @param bool  $update              Whether this updates an existing post.
	 * @return array
	 */
	public function guard_product_type_promotion( $data, $postarr, $unsanitized_postarr, $update ) {
		unset( $unsanitized_postarr );
		$object_id     = absint( $postarr['ID'] ?? 0 );
		$current_type  = (string) get_post_type( $object_id );
		$next_type     = (string) ( $data['post_type'] ?? '' );
		$product_types = array( 'product', 'product_variation' );
		if (
			! $update
			|| $object_id <= 0
			|| in_array( $current_type, $product_types, true )
			|| ! in_array( $next_type, $product_types, true )
		) {
			return $data;
		}

		$locked = $this->acquire_product_lock( $object_id );
		if ( is_wp_error( $locked ) ) {
			$this->record_block( $object_id, '', 0, $locked->get_error_code(), 'post_type_promotion' );
			throw new RuntimeException( 'The product SKU promotion lock is unavailable.' );
		}
		$rows = $this->read_sku_rows( $object_id );
		if ( null === $rows || ! empty( $rows ) ) {
			$this->release_product_lock( $object_id );
			$reason = null === $rows ? 'sku_row_check_failed' : 'staged_sku_requires_woocommerce_crud';
			$this->record_block( $object_id, '', $object_id, $reason, 'post_type_promotion' );
			throw new RuntimeException( 'The staged SKU cannot be promoted to a product.' );
		}
		$this->pending_promotions[ $object_id ][] = '';

		return $data;
	}

	/** Release exactly one product-promotion lock scope after WordPress persists it. */
	public function release_after_product_type_promotion( $post_id, $post, $update ) {
		unset( $post );
		$post_id = absint( $post_id );
		if ( ! $update || empty( $this->pending_promotions[ $post_id ] ) ) {
			return;
		}
		$sku = array_pop( $this->pending_promotions[ $post_id ] );
		if ( empty( $this->pending_promotions[ $post_id ] ) ) {
			unset( $this->pending_promotions[ $post_id ] );
		}
		if ( '' !== $sku ) {
			$this->release_lock( $this->sku_lock_name( $sku ), $post_id );
		}
		$this->release_product_lock( $post_id );
	}

	/**
	 * Allow only the exact metadata mutation emitted by an active Woo CRUD save.
	 *
	 * @param int    $object_id Product ID being persisted.
	 * @param mixed  $meta_value Candidate SKU.
	 * @param string $channel Metadata route.
	 * @return bool
	 * @throws WC_Data_Exception When an active Woo save drifts after its locked preflight.
	 */
	private function authorize_woocommerce_metadata_write( $object_id, $meta_value, $channel ) {
		$validated = self::validate( $meta_value );
		if ( is_wp_error( $validated ) ) {
			return false;
		}
		$scope_key = $this->active_scope_for_metadata( (int) $object_id, $validated );
		if ( null === $scope_key ) {
			return false;
		}

		list( $object_key, $stack_key ) = $scope_key;
		$scope                          = &$this->active_woocommerce_writes[ $object_key ][ $stack_key ];
		if ( (string) $scope['sku'] !== $validated ) {
			$this->fail_active_woocommerce_write( (int) $object_id, $meta_value, 'sku_value_changed_after_preflight', $channel );
		}
		if ( empty( $scope['product_lock'] ) ) {
			$locked = $this->acquire_product_lock( (int) $object_id );
			if ( is_wp_error( $locked ) ) {
				$this->fail_active_woocommerce_write( (int) $object_id, $meta_value, $locked->get_error_code(), $channel );
			}
			$scope['product_lock'] = true;
			$scope['product_id']   = (int) $object_id;
		} elseif ( is_wp_error( $this->verify_product_lock( (int) $object_id ) ) ) {
			$this->fail_active_woocommerce_write( (int) $object_id, $meta_value, 'product_write_lock_lost', $channel );
		}

		$rows = $this->read_sku_rows( (int) $object_id );
		if ( null === $rows || count( $rows ) > 1 || ( isset( $rows[0] ) && self::SKU_META !== (string) $rows[0]['meta_key'] ) ) {
			$this->fail_active_woocommerce_write( (int) $object_id, $meta_value, 'sku_row_integrity_failed', $channel );
		}
		if ( 'add_post_metadata' === $channel && ! empty( $rows ) ) {
			$this->fail_active_woocommerce_write( (int) $object_id, $meta_value, 'sku_row_already_exists', $channel );
		}
		if ( '' !== $validated ) {
			$collision = $this->find_collision( (int) $object_id, $validated );
			if ( $collision['blocked'] ) {
				$this->fail_active_woocommerce_write( (int) $object_id, $meta_value, (string) $collision['reason'], $channel, (int) $collision['collision_id'] );
			}
		}

		return true;
	}

	/** Bind one new-product scope to its assigned post ID, or find an existing scope. */
	private function active_scope_for_metadata( $object_id, $validated ) {
		foreach ( $this->active_woocommerce_writes as $object_key => &$stack ) {
			for ( $index = count( $stack ) - 1; $index >= 0; --$index ) {
				if ( (int) $stack[ $index ]['product_id'] === (int) $object_id ) {
					return array( (int) $object_key, $index );
				}
				if ( 0 === (int) $stack[ $index ]['product_id'] && (string) $stack[ $index ]['sku'] === (string) $validated ) {
					$stack[ $index ]['product_id'] = (int) $object_id;
					return array( (int) $object_key, $index );
				}
			}
		}
		unset( $stack );

		return null;
	}

	/** Abort one in-flight Woo CRUD write rather than allowing partial metadata. */
	private function fail_active_woocommerce_write( $object_id, $sku, $reason, $channel, $collision_id = 0 ) {
		$this->record_block( (int) $object_id, $sku, (int) $collision_id, (string) $reason, (string) $channel );
		throw new WC_Data_Exception( (string) $reason, 'The guarded SKU write changed after its locked preflight.', 409 );
	}

	/** Verify raw SKU and Woo lookup parity, then release the exact active save scope. */
	public function verify_and_release_after_product_save( $product ) {
		$object_key = is_object( $product ) ? spl_object_id( $product ) : 0;
		if ( $object_key <= 0 || empty( $this->active_woocommerce_writes[ $object_key ] ) ) {
			return;
		}
		$scope      = array_pop( $this->active_woocommerce_writes[ $object_key ] );
		$product_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $scope['product_id'];
		$failure    = '';

		try {
			$rows   = $this->read_sku_rows( $product_id );
			$lookup = $this->read_lookup_sku( $product_id );
			if ( ! $this->sku_readback_matches( $rows, $lookup, (string) $scope['sku'] ) ) {
				$this->refresh_woocommerce_lookup( $product_id );
				$rows   = $this->read_sku_rows( $product_id );
				$lookup = $this->read_lookup_sku( $product_id );
			}
			if ( ! $this->sku_readback_matches( $rows, $lookup, (string) $scope['sku'] ) ) {
				$failure = 'sku_lookup_readback_failed';
			}
		} finally {
			if ( '' !== (string) $scope['lock_name'] ) {
				$this->release_lock( (string) $scope['lock_name'], (int) $scope['lock_owner_id'] );
			}
			if ( ! empty( $scope['product_lock'] ) ) {
				$this->release_product_lock( $product_id );
			}
			if ( empty( $this->active_woocommerce_writes[ $object_key ] ) ) {
				unset( $this->active_woocommerce_writes[ $object_key ] );
			}
		}

		if ( '' !== $failure ) {
			$this->record_block( $product_id, (string) $scope['sku'], 0, $failure, 'woocommerce_after_save' );
			throw new WC_Data_Exception( $failure, 'WooCommerce SKU lookup parity could not be verified.', 500 );
		}
	}

	/** Read the uncached WooCommerce lookup SKU for one product. */
	private function read_lookup_sku( $product_id ) {
		global $wpdb;
		$table            = $wpdb->prefix . 'wc_product_meta_lookup';
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Post-save parity must bypass object and metadata caches.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT /* digitalogic_sku_lookup_readback */ product_id, sku
				 FROM {$table}
				 WHERE product_id = %d",
				(int) $product_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return null;
		}

		return (string) ( $rows[0]->sku ?? '' );
	}

	/** Compare exact canonical postmeta and lookup values. */
	private function sku_readback_matches( $rows, $lookup, $expected ) {
		if ( ! is_array( $rows ) || null === $lookup || count( $rows ) > 1 ) {
			return false;
		}
		$raw = isset( $rows[0] ) ? (string) $rows[0]['meta_value'] : '';

		return (string) $expected === $raw && (string) $expected === (string) $lookup;
	}

	/** Ask WooCommerce's installed data store to rebuild one lookup row. */
	private function refresh_woocommerce_lookup( $product_id ) {
		if ( ! class_exists( 'WC_Data_Store' ) ) {
			return;
		}
		$data_store = WC_Data_Store::load( 'product' );
		if ( is_object( $data_store ) && method_exists( $data_store, 'update_lookup_table' ) ) {
			$data_store->update_lookup_table( (int) $product_id, 'wc_product_meta_lookup' );
		}
	}

	/** Validate, acquire the SKU-scoped lock, and reject normalized collisions. */
	private function guard_metadata_value( $check, $object_id, $meta_value, $channel ) {
		$validated = self::validate( $meta_value );
		if ( is_wp_error( $validated ) ) {
			$this->record_block( $object_id, $meta_value, 0, $validated->get_error_code(), $channel );
			return false;
		}
		if ( '' === $validated ) {
			return $check;
		}

		$lock = $this->acquire_lock( $object_id, $validated );
		if ( null === $lock ) {
			$this->record_block( $object_id, $meta_value, 0, 'lock_unavailable', $channel );
			return false;
		}

		$collision = $this->find_collision( $object_id, $validated );
		if ( $collision['blocked'] ) {
			$this->release_lock( $lock, $object_id );
			$this->record_block( $object_id, $meta_value, (int) $collision['collision_id'], (string) $collision['reason'], $channel );
			return false;
		}

		return $check;
	}

	/** @return array{blocked:bool,collision_id:int,reason:string} */
	private function find_collision( $product_id, $normalized ) {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uniqueness requires one authoritative uncached cross-product read under advisory locks.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT /* digitalogic_sku_collision */ pm.post_id, pm.meta_key, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE LOWER(pm.meta_key) = LOWER(%s)
				   AND pm.post_id <> %d
				   AND p.post_type IN ('product', 'product_variation')",
				self::SKU_META,
				(int) $product_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return array(
				'blocked'      => true,
				'collision_id' => 0,
				'reason'       => 'uniqueness_check_failed',
			);
		}
		foreach ( $rows as $row ) {
			if ( $normalized === self::normalize( $row->meta_value ) ) {
				return array(
					'blocked'      => true,
					'collision_id' => (int) $row->post_id,
					'reason'       => 'normalized_duplicate',
				);
			}
		}

		return array(
			'blocked'      => false,
			'collision_id' => 0,
			'reason'       => '',
		);
	}

	/**
	 * Read every case-insensitive SKU row for one product from authoritative storage.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return array<int,array{meta_id:int,meta_key:string,meta_value:string}>|null
	 */
	private function read_sku_rows( $object_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Row-integrity fencing must bypass potentially stale metadata caches.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT /* digitalogic_sku_rows_for_product */ meta_id, meta_key, meta_value
				 FROM {$wpdb->postmeta}
				 WHERE post_id = %d
				   AND LOWER(meta_key) = LOWER(%s)
				 ORDER BY meta_id",
				(int) $object_id,
				self::SKU_META
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}

		return array_map(
			static fn( $row ) => array(
				'meta_id'    => (int) ( $row['meta_id'] ?? 0 ),
				'meta_key'   => (string) ( $row['meta_key'] ?? '' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Authoritative result column.
				'meta_value' => (string) ( $row['meta_value'] ?? '' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Authoritative result column.
			),
			$rows
		);
	}

	/** Classify exact, case-variant, and unrelated metadata keys. */
	private function sku_meta_key_state( $meta_key ) {
		$key = (string) $meta_key;
		if ( self::SKU_META === $key ) {
			return 'canonical';
		}

		return self::SKU_META === strtolower( $key ) ? 'alias' : 'other';
	}

	/** Return whether the metadata object is an existing post that can later become a product. */
	private function is_post_object( $object_id ) {
		$post_type = get_post_type( $object_id );
		return is_string( $post_type ) && '' !== $post_type;
	}

	/**
	 * Acquire the shared product lock before any SKU-row or collision read.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return true|WP_Error
	 */
	private function acquire_product_lock( $object_id ) {
		$object_id = (int) $object_id;
		if ( isset( $this->held_product_locks[ $object_id ] ) ) {
			$owned = $this->verify_product_lock( $object_id );
			if ( is_wp_error( $owned ) ) {
				return $owned;
			}
			$service  = $this->held_product_locks[ $object_id ]['service'];
			$acquired = $service->acquire( $object_id, self::LOCK_TIMEOUT_SECONDS );
			if ( is_wp_error( $acquired ) ) {
				return $acquired;
			}
			++$this->held_product_locks[ $object_id ]['count'];

			return true;
		}
		if ( ! class_exists( 'Digitalogic_Product_Write_Lock' ) ) {
			return self::validation_error( 'product_write_lock_unavailable', 'The product write lock service is unavailable.' );
		}

		$service  = Digitalogic_Product_Write_Lock::instance();
		$acquired = $service->acquire( $object_id, self::LOCK_TIMEOUT_SECONDS );
		if ( is_wp_error( $acquired ) ) {
			return $acquired;
		}
		$this->held_product_locks[ $object_id ] = array(
			'service' => $service,
			'count'   => 1,
		);

		return $this->verify_product_lock( $object_id );
	}

	/** Verify request-local and database ownership of an already acquired product lock. */
	private function verify_product_lock( $object_id ) {
		$object_id = (int) $object_id;
		$entry     = $this->held_product_locks[ $object_id ] ?? null;
		$service   = is_array( $entry ) ? ( $entry['service'] ?? null ) : null;
		if ( ! is_object( $service ) || ! $service->is_owned( $object_id ) ) {
			unset( $this->held_product_locks[ $object_id ], $this->pending_update_add[ $object_id ] );
			return self::validation_error( 'product_write_lock_lost', 'The product write lock was lost.' );
		}

		return true;
	}

	/** Release the exact guard-owned product-lock level. */
	private function release_product_lock( $object_id ) {
		$object_id = (int) $object_id;
		$entry     = $this->held_product_locks[ $object_id ] ?? null;
		$service   = is_array( $entry ) ? ( $entry['service'] ?? null ) : null;
		if ( is_object( $service ) ) {
			$service->release( $object_id );
		}
		if ( is_array( $entry ) && (int) ( $entry['count'] ?? 0 ) > 1 ) {
			--$this->held_product_locks[ $object_id ]['count'];
			return;
		}
		unset( $this->held_product_locks[ $object_id ], $this->pending_update_add[ $object_id ] );
	}

	/** Acquire a normalized-SKU advisory lock after the exact product lock. */
	private function acquire_lock( $object_id, $normalized ) {
		global $wpdb;
		$name = $this->sku_lock_name( $normalized );
		if ( isset( $this->held_locks[ $name ] ) ) {
			if ( (int) $this->held_locks[ $name ]['product_id'] !== (int) $object_id ) {
				return null;
			}
			++$this->held_locks[ $name ]['count'];
			return $name;
		}

		$connection_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory ownership is connection state.
		$got           = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory locks cannot be cached.
		if ( '1' !== (string) $got ) {
			return null;
		}
		$owner = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify live advisory-lock ownership.
		if ( $connection_id < 1 || $owner !== $connection_id ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Best-effort advisory-lock cleanup.
			return null;
		}
		$this->held_locks[ $name ] = array(
			'product_id'    => (int) $object_id,
			'count'         => 1,
			'connection_id' => $connection_id,
		);

		return $name;
	}

	/** Return the deterministic normalized-SKU advisory-lock name. */
	private function sku_lock_name( $normalized ) {
		return 'dgl_sku_' . substr( hash( 'sha256', (string) $normalized ), 0, 40 );
	}

	/** Release a product's SKU and object locks after the metadata write commits. */
	public function release_after_metadata_write( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( 'other' === $this->sku_meta_key_state( $meta_key ) ) {
			return;
		}
		if ( null !== $this->active_scope_for_metadata( (int) $object_id, self::normalize( $meta_value ) ) ) {
			return;
		}
		$validated = self::validate( $meta_value );
		if ( ! is_wp_error( $validated ) && '' !== $validated ) {
			$this->release_lock( $this->sku_lock_name( $validated ), (int) $object_id );
		}
		$this->release_product_lock( (int) $object_id );
	}

	/** Release every request-local advisory lock. */
	public function release_all_locks() {
		$this->active_woocommerce_writes = array();
		foreach ( array_keys( $this->held_locks ) as $name ) {
			$this->release_lock( $name, (int) $this->held_locks[ $name ]['product_id'], true );
		}
		foreach ( array_keys( $this->held_product_locks ) as $object_id ) {
			while ( isset( $this->held_product_locks[ $object_id ] ) ) {
				$this->release_product_lock( (int) $object_id );
			}
		}
		$this->pending_update_add = array();
		$this->pending_promotions = array();
	}

	/** Release one exact advisory lock and forget request-local ownership. */
	private function release_lock( $name, $object_id, $force = false ) {
		global $wpdb;
		if ( ! isset( $this->held_locks[ $name ] ) || (int) $this->held_locks[ $name ]['product_id'] !== (int) $object_id ) {
			return false;
		}
		if ( ! $force && (int) $this->held_locks[ $name ]['count'] > 1 ) {
			--$this->held_locks[ $name ]['count'];
			return true;
		}

		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release exact connection-scoped advisory lock.
		if ( '1' === (string) $released ) {
			unset( $this->held_locks[ $name ] );
			return true;
		}

		return false;
	}

	/** Record one blocked write without exposing private credentials or routes. */
	private function record_block( $target_id, $sku, $collision_id, $reason, $channel ) {
		$display = is_scalar( $sku ) ? (string) $sku : gettype( $sku );
		$message = sprintf(
			'SKU guard blocked "%s" for product %d (collision %d; %s via %s).',
			$display,
			(int) $target_id,
			(int) $collision_id,
			(string) $reason,
			(string) $channel
		);
		error_log( '[Digitalogic SKU Guard] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Security boundary failures require an operator-visible server record.
		if ( is_admin() && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( esc_html( $message ) );
		}
		do_action( 'digitalogic_sku_guard_blocked', (int) $target_id, $display, (int) $collision_id, (string) $reason, (string) $channel );
	}

	/** Return live normalization, validation, row-integrity, and collision status. */
	public static function status() {
		global $wpdb;
		$lookup_table     = $wpdb->prefix . 'wc_product_meta_lookup';
		$wpdb->last_error = '';
		$prepared         = $wpdb->prepare(
			"SELECT /* digitalogic_sku_status */ p.ID AS post_id, pm.meta_id, pm.meta_key, pm.meta_value,
			        lookup.product_id AS lookup_product_id, lookup.sku AS lookup_sku
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND LOWER(pm.meta_key) = LOWER(%s)
			 LEFT JOIN {$lookup_table} lookup ON lookup.product_id = p.ID
			 WHERE p.post_type IN ('product', 'product_variation')
			   AND (pm.meta_id IS NOT NULL OR lookup.sku IS NOT NULL)",
			self::SKU_META
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; health status must read authoritative rows and fail closed.
		$rows = false === $prepared ? null : $wpdb->get_results( $prepared );
		if ( false === $prepared || '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return array(
				'ok'                           => false,
				'query_ok'                     => false,
				'healthy'                      => false,
				'error'                        => 'sku_status_query_failed',
				'version'                      => self::VERSION,
				'implementation'               => 'digitalogic-wp',
				'normalized_collision_groups'  => null,
				'multiple_sku_row_products'    => null,
				'normalization_drift_products' => null,
				'invalid_sku_products'         => null,
				'lookup_parity_failures'       => null,
			);
		}
		$groups           = array();
		$per_product_rows = array();
		$drift            = array();
		$invalid          = array();
		$lookup_values    = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$product_id = (int) $row->post_id;
			if ( isset( $row->lookup_product_id ) && null !== $row->lookup_product_id ) {
				$lookup_values[ $product_id ] = null === $row->lookup_sku ? '' : (string) $row->lookup_sku;
			}
			if ( ! isset( $row->meta_id ) || null === $row->meta_id ) {
				continue;
			}
			$raw        = (string) $row->meta_value;
			$normalized = self::normalize( $raw );
			if ( '' !== $normalized ) {
				$groups[ $normalized ][ $product_id ] = true;
			}
			$per_product_rows[ $product_id ] = ( $per_product_rows[ $product_id ] ?? 0 ) + 1;
			if ( $raw !== $normalized ) {
				$drift[ $product_id ] = array(
					'stored'     => $raw,
					'normalized' => $normalized,
				);
			}
			$validated = self::validate( $raw );
			if ( is_wp_error( $validated ) ) {
				$invalid[ $product_id ] = $validated->get_error_code();
			} elseif ( self::SKU_META !== (string) ( $row->meta_key ?? '' ) ) {
				$invalid[ $product_id ] = 'noncanonical_sku_meta_key';
			}
		}

		$collisions = array();
		foreach ( $groups as $normalized => $ids ) {
			if ( count( $ids ) > 1 ) {
				$collisions[ $normalized ] = array_keys( $ids );
			}
		}
		$multiple_rows = array_filter( $per_product_rows, static fn( $count ) => $count > 1 );
		$lookup_drift  = array();
		$product_ids   = array_unique( array_merge( array_keys( $per_product_rows ), array_keys( $lookup_values ) ) );
		foreach ( $product_ids as $product_id ) {
			$meta_values = array();
			foreach ( $rows as $row ) {
				if ( (int) $row->post_id === (int) $product_id && isset( $row->meta_id ) && null !== $row->meta_id ) {
					$meta_values[] = (string) $row->meta_value;
				}
			}
			$raw    = 1 === count( $meta_values ) ? (string) $meta_values[0] : null;
			$lookup = $lookup_values[ $product_id ] ?? null;
			if ( null === $raw || null === $lookup || $raw !== $lookup ) {
				$lookup_drift[ (int) $product_id ] = array(
					'meta'   => $raw,
					'lookup' => $lookup,
				);
			}
		}
		$healthy = empty( $collisions ) && empty( $multiple_rows ) && empty( $drift ) && empty( $invalid ) && empty( $lookup_drift );

		return array(
			'ok'                           => $healthy,
			'query_ok'                     => true,
			'healthy'                      => $healthy,
			'error'                        => $healthy ? '' : 'sku_status_invariant_failed',
			'version'                      => self::VERSION,
			'implementation'               => 'digitalogic-wp',
			'normalized_collision_groups'  => count( $collisions ),
			'multiple_sku_row_products'    => count( $multiple_rows ),
			'normalization_drift_products' => count( $drift ),
			'invalid_sku_products'         => count( $invalid ),
			'lookup_parity_failures'       => count( $lookup_drift ),
			'collision_details'            => $collisions,
			'multiple_row_details'         => $multiple_rows,
			'normalization_drift_details'  => $drift,
			'invalid_sku_details'          => $invalid,
			'lookup_parity_details'        => $lookup_drift,
		);
	}

	/** Build one validation error without depending on WooCommerce load order. */
	private static function validation_error( $code, $message ) {
		return new WP_Error( $code, __( $message, 'digitalogic' ), array( 'status' => 400 ) );
	}
}
