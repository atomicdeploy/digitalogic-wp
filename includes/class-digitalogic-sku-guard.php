<?php
/**
 * Canonical WooCommerce SKU normalization and uniqueness boundary.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Normalize, validate, and serialize every supported WooCommerce SKU write. */
final class Digitalogic_SKU_Guard {

	public const VERSION = '2.0.1';

	private const SKU_META             = '_sku';
	private const MAX_LENGTH           = 100;
	private const LOCK_TIMEOUT_SECONDS = 5;

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,string> */
	private $held_locks = array();

	/** Return the shared guard. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register early WooCommerce, metadata, normalization, and cleanup hooks. */
	private function __construct() {
		// This short-circuit runs before WooCommerce's later importer bypass filter.
		add_filter( 'wc_product_pre_has_unique_sku', array( $this, 'woocommerce_unique_sku' ), PHP_INT_MAX, 3 );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'normalize_product_before_save' ), 2, 2 );

		// Canonicalize the persisted value and cover every WordPress metadata write API.
		add_filter( 'sanitize_post_meta__sku', array( $this, 'sanitize_sku_meta' ), PHP_INT_MAX, 4 );
		add_filter( 'add_post_metadata', array( $this, 'guard_add_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata', array( $this, 'guard_update_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'update_post_metadata_by_mid', array( $this, 'guard_update_metadata_by_mid' ), PHP_INT_MAX, 4 );
		add_action( 'added_post_meta', array( $this, 'release_after_metadata_write' ), PHP_INT_MAX, 4 );
		add_action( 'updated_post_meta', array( $this, 'release_after_metadata_write' ), PHP_INT_MAX, 4 );
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
				'ك' => 'ک', 'ي' => 'ی', 'ى' => 'ی',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
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
	}

	/** Canonical persistence filter; pre-write guards reject invalid input before this runs. */
	public function sanitize_sku_meta( $value, $meta_key = '', $object_type = '', $object_subtype = '' ) {
		unset( $meta_key, $object_type, $object_subtype );
		return self::normalize( $value );
	}

	/** Block a second _sku row on one product and cross-product collisions. */
	public function guard_add_metadata( $check, $object_id, $meta_key, $meta_value, $unique ) {
		unset( $unique );
		if ( self::SKU_META !== (string) $meta_key || ! $this->is_product( (int) $object_id ) ) {
			return $check;
		}
		if ( ! empty( get_post_meta( (int) $object_id, self::SKU_META, false ) ) ) {
			$this->record_block( (int) $object_id, $meta_value, (int) $object_id, 'multiple_sku_rows', 'add_post_metadata' );
			return false;
		}

		return $this->guard_metadata_value( $check, (int) $object_id, $meta_value, 'add_post_metadata' );
	}

	/** Block direct key-based metadata updates that are invalid or collide. */
	public function guard_update_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $prev_value );
		if ( self::SKU_META !== (string) $meta_key || ! $this->is_product( (int) $object_id ) ) {
			return $check;
		}
		if ( (string) get_post_meta( (int) $object_id, self::SKU_META, true ) === (string) $meta_value ) {
			return $check;
		}

		return $this->guard_metadata_value( $check, (int) $object_id, $meta_value, 'update_post_metadata' );
	}

	/** Cover update_metadata_by_mid(), which bypasses update_post_metadata. */
	public function guard_update_metadata_by_mid( $check, $meta_id, $meta_value, $meta_key ) {
		$meta = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( ! $meta ) {
			return $check;
		}

		$effective_key = false === $meta_key || null === $meta_key ? (string) $meta->meta_key : (string) $meta_key;
		$object_id     = (int) $meta->post_id;
		if ( self::SKU_META !== $effective_key || ! $this->is_product( $object_id ) ) {
			return $check;
		}
		if ( self::SKU_META === (string) $meta->meta_key && (string) $meta->meta_value === (string) $meta_value ) {
			return $check;
		}

		return $this->guard_metadata_value( $check, $object_id, $meta_value, 'update_post_metadata_by_mid' );
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
			$this->release_lock( $lock );
			$this->record_block( $object_id, $meta_value, (int) $collision['collision_id'], (string) $collision['reason'], $channel );
			return false;
		}

		return $check;
	}

	/** @return array{blocked:bool,collision_id:int,reason:string} */
	private function find_collision( $product_id, $normalized ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.post_id <> %d
				   AND p.post_type IN ('product', 'product_variation')
				   AND p.post_status <> 'trash'",
				self::SKU_META,
				(int) $product_id
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return array( 'blocked' => true, 'collision_id' => 0, 'reason' => 'uniqueness_check_failed' );
		}
		foreach ( $rows as $row ) {
			if ( $normalized === self::normalize( $row->meta_value ) ) {
				return array( 'blocked' => true, 'collision_id' => (int) $row->post_id, 'reason' => 'normalized_duplicate' );
			}
		}

		return array( 'blocked' => false, 'collision_id' => 0, 'reason' => '' );
	}

	/** Return whether this metadata object is a WooCommerce product or variation. */
	private function is_product( $object_id ) {
		return in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true );
	}

	/** Acquire a normalized-SKU advisory lock so simultaneous creates cannot race. */
	private function acquire_lock( $object_id, $normalized ) {
		global $wpdb;
		$name = 'dgl_sku_' . substr( hash( 'sha256', $normalized ), 0, 40 );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT_SECONDS ) );
		if ( '1' !== (string) $got ) {
			return null;
		}
		$this->held_locks[ (int) $object_id . '|' . $name ] = $name;

		return $name;
	}

	/** Release a product's SKU lock after the metadata write commits. */
	public function release_after_metadata_write( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		if ( self::SKU_META !== (string) $meta_key ) {
			return;
		}
		$prefix = (int) $object_id . '|';
		foreach ( $this->held_locks as $key => $name ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				$this->release_lock( $name );
			}
		}
	}

	/** Release every request-local advisory lock. */
	public function release_all_locks() {
		foreach ( array_values( array_unique( $this->held_locks ) ) as $name ) {
			$this->release_lock( $name );
		}
	}

	/** Release one exact advisory lock and forget request-local ownership. */
	private function release_lock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		foreach ( $this->held_locks as $key => $held ) {
			if ( $held === $name ) {
				unset( $this->held_locks[ $key ] );
			}
		}
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
		error_log( '[Digitalogic SKU Guard] ' . $message );
		if ( is_admin() && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( esc_html( $message ) );
		}
		do_action( 'digitalogic_sku_guard_blocked', (int) $target_id, $display, (int) $collision_id, (string) $reason, (string) $channel );
	}

	/** Return live normalization, validation, row-integrity, and collision status. */
	public static function status() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT pm.meta_id, pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku'
			   AND p.post_type IN ('product', 'product_variation')
			   AND p.post_status <> 'trash'"
		);
		$groups = array();
		$per_product_rows = array();
		$drift = array();
		$invalid = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$raw        = (string) $row->meta_value;
			$normalized = self::normalize( $raw );
			if ( '' !== $normalized ) {
				$groups[ $normalized ][ (int) $row->post_id ] = true;
			}
			$per_product_rows[ (int) $row->post_id ] = ( $per_product_rows[ (int) $row->post_id ] ?? 0 ) + 1;
			if ( $raw !== $normalized ) {
				$drift[ (int) $row->post_id ] = array( 'stored' => $raw, 'normalized' => $normalized );
			}
			$validated = self::validate( $raw );
			if ( is_wp_error( $validated ) ) {
				$invalid[ (int) $row->post_id ] = $validated->get_error_code();
			}
		}

		$collisions = array();
		foreach ( $groups as $normalized => $ids ) {
			if ( count( $ids ) > 1 ) {
				$collisions[ $normalized ] = array_keys( $ids );
			}
		}
		$multiple_rows = array_filter( $per_product_rows, static fn( $count ) => $count > 1 );

		return array(
			'version'                     => self::VERSION,
			'implementation'              => 'digitalogic-wp',
			'normalized_collision_groups' => count( $collisions ),
			'multiple_sku_row_products'   => count( $multiple_rows ),
			'normalization_drift_products'=> count( $drift ),
			'invalid_sku_products'         => count( $invalid ),
			'collision_details'            => $collisions,
			'multiple_row_details'         => $multiple_rows,
			'normalization_drift_details'  => $drift,
			'invalid_sku_details'          => $invalid,
		);
	}

	/** Build one validation error without depending on WooCommerce load order. */
	private static function validation_error( $code, $message ) {
		return new WP_Error( $code, __( $message, 'digitalogic' ), array( 'status' => 400 ) );
	}
}

// Backward compatibility for the original standalone MU guard class name.
if ( ! class_exists( 'Digitalogic_Woo_SKU_Guard', false ) ) {
	class_alias( 'Digitalogic_SKU_Guard', 'Digitalogic_Woo_SKU_Guard' );
}
