<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-digitalogic-product-write-lock.php';
require_once dirname( __DIR__ ) . '/includes/class-digitalogic-sku-guard.php';

if ( ! function_exists( 'is_admin' ) ) {
	/** Test-only non-admin request context. */
	function is_admin() {
		return false;
	}
}

if ( ! class_exists( 'WC_Data_Exception' ) ) {
	/** Minimal WooCommerce data exception used by the guarded save boundary. */
	class WC_Data_Exception extends Exception {
		/** @var string */
		private $error_code;

		/**
		 * @param string $error_code Stable WooCommerce error code.
		 * @param string $message    Public validation message.
		 * @param int    $http_status HTTP status.
		 */
		public function __construct( $error_code, $message = '', $http_status = 400 ) {
			unset( $http_status );
			$this->error_code = (string) $error_code;
			parent::__construct( (string) $message );
		}

		/** Return the stable validation code. */
		public function getErrorCode() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Match WooCommerce's public API.
			return $this->error_code;
		}
	}
}

/** WooCommerce product double for the before-save normalization boundary. */
final class Digitalogic_Test_SKU_Product {
	/** @var int */
	private $id;

	/** @var string */
	private $sku;

	/** @var array<string,mixed> */
	private $changes;

	/** @param int $id Product ID. @param string $sku Editable SKU. @param bool $dirty Whether SKU changed. */
	public function __construct( $id, $sku, $dirty = true ) {
		$this->id      = (int) $id;
		$this->sku     = (string) $sku;
		$this->changes = $dirty ? array( 'sku' => (string) $sku ) : array();
	}

	/** @return int */
	public function get_id() {
		return $this->id;
	}

	/** @param string $context Read context. @return string */
	public function get_sku( $context = 'view' ) {
		unset( $context );
		return $this->sku;
	}

	/** @param string $sku Canonical SKU. */
	public function set_sku( $sku ) {
		$this->sku            = (string) $sku;
		$this->changes['sku'] = (string) $sku;
	}

	/** @return array<string,mixed> */
	public function get_changes() {
		return $this->changes;
	}

	/** Persist through the same before/meta/lookup/after ordering as Woo CRUD. */
	public function save() {
		$unique = apply_filters( 'wc_product_pre_has_unique_sku', null, $this->id, $this->sku );
		if ( false === $unique ) {
			throw new WC_Data_Exception( 'product_invalid_sku', 'Invalid or duplicated SKU.', 400 );
		}
		do_action( 'woocommerce_before_product_object_save', $this, null );
		$updated = update_post_meta( $this->id, '_sku', $this->sku );
		if ( false === $updated && (string) get_post_meta( $this->id, '_sku', true ) !== $this->sku ) {
			throw new WC_Data_Exception( 'sku_meta_write_failed', 'SKU metadata was not saved.', 500 );
		}
		$GLOBALS['digitalogic_test_wc_lookup_rows'][ $this->id ] = array(
			'product_id' => $this->id,
			'sku'        => $this->sku,
		);
		do_action( 'woocommerce_after_product_object_save', $this, null );
		$this->changes = array();

		return $this->id;
	}
}

/** Database double for exact normalized SKU collision and status queries. */
final class Digitalogic_Test_SKU_WPDB extends Digitalogic_Test_WPDB {
	/** @var int */
	public $sku_query_count = 0;

	/** @var bool */
	public $sku_status_query_failure = false;

	/**
	 * @param mixed $prepared Prepared SQL payload.
	 * @param mixed $output   Requested output format.
	 * @return array<int,object>
	 */
	public function get_results( $prepared, $output = ARRAY_A ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test wpdb signature.
		$query        = is_array( $prepared ) && isset( $prepared['query'] ) ? (string) $prepared['query'] : (string) $prepared;
		$args         = is_array( $prepared ) && isset( $prepared['args'] ) ? (array) $prepared['args'] : array();
		$is_rows      = false !== strpos( $query, 'digitalogic_sku_rows_for_product' );
		$is_collision = false !== strpos( $query, 'digitalogic_sku_collision' );
		$is_status    = false !== strpos( $query, 'digitalogic_sku_status' );
		$is_lookup    = false !== strpos( $query, 'digitalogic_sku_lookup_readback' );
		if ( ! $is_rows && ! $is_collision && ! $is_status && ! $is_lookup ) {
			return parent::get_results( $prepared, $output );
		}
		if ( $is_status && $this->sku_status_query_failure ) {
			$this->last_error = 'Injected SKU status query failure.';
			return null;
		}

		++$this->sku_query_count;
		$target_id   = ( $is_rows || $is_lookup ) ? (int) ( $args[0] ?? 0 ) : 0;
		$excluded_id = $is_collision ? (int) ( $args[1] ?? 0 ) : 0;
		$rows        = array();
		if ( $is_lookup ) {
			$lookup           = $GLOBALS['digitalogic_test_wc_lookup_rows'][ $target_id ] ?? null;
			$this->last_error = '';
			return is_array( $lookup )
				? array(
					(object) array(
						'product_id' => $target_id,
						'sku'        => $lookup['sku'] ?? null,
					),
				)
				: array();
		}
		foreach ( $GLOBALS['digitalogic_test_posts'] as $post_id => $post ) {
			if ( ( $target_id > 0 && $target_id !== (int) $post_id ) || $excluded_id === (int) $post_id ) {
				continue;
			}
			if ( ! $is_rows && ! in_array( (string) ( $post['post_type'] ?? '' ), array( 'product', 'product_variation' ), true ) ) {
				continue;
			}

			$post_rows = $this->sku_rows_for_post( (int) $post_id, $post );
			foreach ( $post_rows as $row ) {
				if ( $is_status ) {
					$row['lookup_product_id'] = isset( $GLOBALS['digitalogic_test_wc_lookup_rows'][ $post_id ] ) ? (int) $post_id : null;
					$row['lookup_sku']        = $GLOBALS['digitalogic_test_wc_lookup_rows'][ $post_id ]['sku'] ?? null;
				}
				$rows[] = $is_rows ? $row : (object) $row;
			}
			if ( $is_status && empty( $post_rows ) && isset( $GLOBALS['digitalogic_test_wc_lookup_rows'][ $post_id ]['sku'] ) ) {
				$rows[] = (object) array(
					'post_id'           => (int) $post_id,
					'meta_id'           => null,
					'meta_key'          => null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test fixture field name.
					'meta_value'        => null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture field name.
					'lookup_product_id' => (int) $post_id,
					'lookup_sku'        => $GLOBALS['digitalogic_test_wc_lookup_rows'][ $post_id ]['sku'],
				);
			}
		}

		$this->last_error = '';
		return $rows;
	}

	/**
	 * @param int   $post_id Product ID.
	 * @param array $post    Test post fixture.
	 * @return array<int,array{meta_id:int,post_id:int,meta_key:string,meta_value:string}>
	 */
	private function sku_rows_for_post( $post_id, $post ) {
		$rows  = array();
		$index = 0;
		$keys  = array_unique( array_merge( array_keys( (array) ( $post['meta'] ?? array() ) ), array_keys( (array) ( $post['meta_rows'] ?? array() ) ) ) );
		foreach ( $keys as $meta_key ) {
			if ( '_sku' !== strtolower( (string) $meta_key ) ) {
				continue;
			}
			$values = isset( $post['meta_rows'][ $meta_key ] )
				? array_values( (array) $post['meta_rows'][ $meta_key ] )
				: array( $post['meta'][ $meta_key ] );
			foreach ( $values as $value ) {
				++$index;
				$meta_id = ( $post_id * 10 ) + $index;
				foreach ( (array) $GLOBALS['digitalogic_test_meta_by_mid'] as $candidate_id => $candidate ) {
					if ( (int) ( $candidate['post_id'] ?? 0 ) === $post_id && (string) ( $candidate['meta_key'] ?? '' ) === (string) $meta_key ) {
						$meta_id = (int) $candidate_id;
						break;
					}
				}
				$rows[] = array(
					'meta_id'    => $meta_id,
					'post_id'    => $post_id,
					'meta_key'   => (string) $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fake query result column.
					'meta_value' => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fake query result column.
				);
			}
		}

		return $rows;
	}
}

/** Regression coverage for the canonical plugin-owned SKU guard. */
final class SkuGuardTest extends TestCase {
	/** @var Digitalogic_SKU_Guard */
	private $guard;

	/** @var Digitalogic_Test_SKU_WPDB */
	private $database;

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_posts']            = array();
		$GLOBALS['digitalogic_test_post_meta_cache']  = array();
		$GLOBALS['digitalogic_test_meta_by_mid']      = array();
		$GLOBALS['digitalogic_test_filters']          = array();
		$GLOBALS['digitalogic_test_actions']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
		$GLOBALS['digitalogic_test_wc_lookup_rows']   = array();

		$this->database  = new Digitalogic_Test_SKU_WPDB();
		$GLOBALS['wpdb'] = $this->database;
		$this->reset_singleton( Digitalogic_Product_Write_Lock::class );
		$this->reset_singleton( Digitalogic_SKU_Guard::class );
		Digitalogic_Product_Write_Lock::instance();
		$this->guard = Digitalogic_SKU_Guard::instance();
	}

	protected function tearDown(): void {
		$this->guard->release_all_locks();
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
	}

	public function test_normalizes_unicode_digits_hidden_space_dash_and_case_before_save(): void {
		$entered = " ab\u{200B}\u{2013}۱۲۳ ";

		$this->assertSame( 'AB-123', Digitalogic_SKU_Guard::normalize( $entered ) );
		$this->assertSame( 'AB-123', Digitalogic_SKU_Guard::validate( $entered ) );

		$product = new Digitalogic_Test_SKU_Product( 10, $entered );
		$this->guard->normalize_product_before_save( $product );

		$this->assertSame( 'AB-123', $product->get_sku( 'edit' ) );
	}

	public function test_invalid_sku_does_not_trigger_recursive_uniqueness_suggestions_and_fails_at_save(): void {
		$this->assertTrue( $this->guard->woocommerce_unique_sku( false, 10, 'BAD?' ) );
		$this->assertSame( 0, $this->database->sku_query_count );

		$product = new Digitalogic_Test_SKU_Product( 10, 'BAD?' );
		try {
			$this->guard->normalize_product_before_save( $product );
			$this->fail( 'The invalid SKU must fail at the guarded save boundary.' );
		} catch ( WC_Data_Exception $exception ) {
			$this->assertSame( 'digitalogic_sku_invalid_characters', $exception->getErrorCode() );
			$this->assertSame( 'SKU contains unsupported characters.', $exception->getMessage() );
		}

		$blocked = $GLOBALS['digitalogic_test_actions']['digitalogic_sku_guard_blocked'];
		$this->assertSame( 'digitalogic_sku_invalid_characters', $blocked[0][3] );
		$this->assertSame( 'woocommerce_save', $blocked[0][4] );
	}

	public function test_importer_bypass_cannot_create_normalized_product_variation_duplicate(): void {
		$this->seed_product( 11, 'product', " ab\u{2013}۱۲۳ " );
		$this->seed_product( 12, 'product_variation', '' );

		$this->assertFalse( $this->guard->woocommerce_unique_sku( true, 12, 'AB-123' ) );
		$this->assertSame( 1, $this->database->sku_query_count );

		$blocked = $GLOBALS['digitalogic_test_actions']['digitalogic_sku_guard_blocked'];
		$this->assertSame( 12, $blocked[0][0] );
		$this->assertSame( 11, $blocked[0][2] );
		$this->assertSame( 'normalized_duplicate', $blocked[0][3] );
		$this->assertSame( 'woocommerce_crud', $blocked[0][4] );
	}

	public function test_direct_metadata_apis_block_duplicates_and_persist_one_canonical_row(): void {
		$this->seed_product( 23, 'product', 'KEEP-23' );
		$GLOBALS['digitalogic_test_meta_by_mid'][77] = array(
			'post_id'    => 23,
			'meta_key'   => '_sku', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fake metadata row.
			'meta_value' => 'KEEP-23', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fake metadata row.
		);

		$this->assertFalse( add_post_meta( 23, '_sku', 'ADD-23', true ) );
		$this->assertFalse( update_post_meta( 23, '_sku', 'UPDATE-23' ) );
		$this->assertFalse( update_metadata_by_mid( 'post', 77, 'MID-23', false ) );
		$this->assertFalse( delete_post_meta( 23, '_sku' ) );
		$this->assertSame( 'KEEP-23', get_post_meta( 23, '_sku', true ) );
		$this->assertSame( 'KEEP-23', $GLOBALS['digitalogic_test_wc_lookup_rows'][23]['sku'] );
		$this->assertSame( array(), $this->database->used_locks );
	}

	public function test_same_object_normalized_update_is_idempotent_and_lock_is_released(): void {
		$this->seed_product( 31, 'product', 'OLD-31' );
		$product = new Digitalogic_Test_SKU_Product( 31, " new\u{2013}۳۱ " );

		$this->assertSame( 31, $product->save() );
		$this->assertSame( 'NEW-31', get_post_meta( 31, '_sku', true ) );
		$this->assertSame( 'NEW-31', $GLOBALS['digitalogic_test_wc_lookup_rows'][31]['sku'] );
		$this->assertSame( array(), $this->database->used_locks );
	}

	public function test_concurrent_normalized_sku_lock_fails_closed_without_write(): void {
		$this->seed_product( 41, 'product', 'OLD-41' );
		$lock_name                                = 'dgl_sku_' . substr( hash( 'sha256', 'CONCURRENT-41' ), 0, 40 );
		$this->database->used_locks[ $lock_name ] = 9999;

		$product = new Digitalogic_Test_SKU_Product( 41, 'CONCURRENT-41' );
		try {
			$product->save();
			$this->fail( 'An externally owned normalized SKU lock must abort Woo CRUD.' );
		} catch ( WC_Data_Exception $exception ) {
			$this->assertSame( 'lock_unavailable', $exception->getErrorCode() );
		}
		$this->assertSame( 'OLD-41', get_post_meta( 41, '_sku', true ) );
		$this->assertSame( 'OLD-41', $GLOBALS['digitalogic_test_wc_lookup_rows'][41]['sku'] );

		$blocked = $GLOBALS['digitalogic_test_actions']['digitalogic_sku_guard_blocked'];
		$this->assertSame( 'lock_unavailable', $blocked[0][3] );
		$this->assertSame( 'woocommerce_save', $blocked[0][4] );
	}

	public function test_update_missing_falls_through_to_add_under_object_then_sku_lock(): void {
		$this->seed_product( 42, 'product', null );
		$product = new Digitalogic_Test_SKU_Product( 42, " new\u{2013}۴۲ " );

		$this->assertSame( 42, $product->save() );
		$this->assertSame( 'NEW-42', get_post_meta( 42, '_sku', true ) );
		$this->assertSame( 'NEW-42', $GLOBALS['digitalogic_test_wc_lookup_rows'][42]['sku'] );
		$this->assertGreaterThanOrEqual( 2, count( $this->database->lock_names ) );
		$this->assertStringStartsWith( 'digitalogic_product_', $this->database->lock_names[0] );
		$this->assertContains( 'dgl_sku_' . substr( hash( 'sha256', 'NEW-42' ), 0, 40 ), $this->database->lock_names );
		$this->assertSame( array(), $this->database->used_locks );
	}

	public function test_alias_keys_and_invalid_direct_values_are_rejected_without_erasing_state(): void {
		$this->seed_product( 43, 'product', 'KEEP-43' );
		$this->seed_product( 44, 'product_variation', null );

		$this->assertFalse( add_post_meta( 44, '_SKU', 'ALIAS-44', true ) );
		$this->assertFalse( update_post_meta( 43, '_Sku', 'ALIAS-43' ) );
		$this->assertFalse( update_post_meta( 43, '_sku', array( 'INVALID' ) ) );
		$this->assertFalse( update_post_meta( 43, '_sku', " \u{200B}\u{FEFF} " ) );
		$this->assertSame( 'KEEP-43', get_post_meta( 43, '_sku', true ) );
		$this->assertSame( '', get_post_meta( 44, '_sku', true ) );
		$this->assertSame( '', get_post_meta( 44, '_SKU', true ) );

		$reasons = array_column( $GLOBALS['digitalogic_test_actions']['digitalogic_sku_guard_blocked'], 3 );
		$this->assertSame( array_fill( 0, 4, 'direct_sku_write_requires_woocommerce_crud' ), $reasons );
	}

	public function test_by_mid_rename_cannot_create_second_row_and_invalid_value_is_preserved_for_rejection(): void {
		$this->seed_product( 45, 'product', 'KEEP-45' );
		$GLOBALS['digitalogic_test_posts'][45]['meta']['legacy_key'] = 'legacy';
		$GLOBALS['digitalogic_test_meta_by_mid'][451]                = array(
			'post_id'    => 45,
			'meta_key'   => '_sku', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fake metadata row.
			'meta_value' => 'KEEP-45', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fake metadata row.
		);
		$GLOBALS['digitalogic_test_meta_by_mid'][452]                = array(
			'post_id'    => 45,
			'meta_key'   => 'legacy_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fake metadata row.
			'meta_value' => 'legacy', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fake metadata row.
		);

		$this->assertFalse( update_metadata_by_mid( 'post', 452, 'SECOND-45', '_sku' ) );
		$this->assertFalse( update_metadata_by_mid( 'post', 451, array( 'INVALID' ) ) );
		$this->assertSame( 'KEEP-45', get_post_meta( 45, '_sku', true ) );
		$this->assertSame( 'legacy', get_post_meta( 45, 'legacy_key', true ) );
		$this->assertSame( '_sku', $GLOBALS['digitalogic_test_meta_by_mid'][451]['meta_key'] );
		$this->assertSame( 'KEEP-45', $GLOBALS['digitalogic_test_meta_by_mid'][451]['meta_value'] );
	}

	public function test_trash_reserves_normalized_sku_for_products_and_variations(): void {
		$this->seed_product( 46, 'product', "trash\u{2013}۴۶" );
		$GLOBALS['digitalogic_test_posts'][46]['post_status'] = 'trash';
		$this->seed_product( 47, 'product_variation', null );

		$product = new Digitalogic_Test_SKU_Product( 47, 'TRASH-46' );
		try {
			$product->save();
			$this->fail( 'A trashed product must continue reserving its normalized SKU.' );
		} catch ( WC_Data_Exception $exception ) {
			$this->assertSame( 'product_invalid_sku', $exception->getErrorCode() );
		}
		$this->assertSame( '', get_post_meta( 47, '_sku', true ) );
		$this->assertSame( '', $GLOBALS['digitalogic_test_wc_lookup_rows'][47]['sku'] );
		$blocked = $GLOBALS['digitalogic_test_actions']['digitalogic_sku_guard_blocked'];
		$this->assertSame( 46, $blocked[0][2] );
		$this->assertSame( 'normalized_duplicate', $blocked[0][3] );
	}

	public function test_reentrant_same_sku_write_balances_both_lock_depths(): void {
		$this->seed_product( 48, 'product', 'SAME-48' );

		$this->assertSame( 48, ( new Digitalogic_Test_SKU_Product( 48, 'SAME-48', false ) )->save() );
		$this->assertSame( 'SAME-48', get_post_meta( 48, '_sku', true ) );
		$this->assertSame( 'SAME-48', $GLOBALS['digitalogic_test_wc_lookup_rows'][48]['sku'] );
		$this->assertSame( array(), $this->database->used_locks );
	}

	public function test_different_skus_on_same_skuless_product_are_serialized_by_object_lock(): void {
		$this->seed_product( 49, 'product', 'KEEP-49' );
		$this->seed_product( 59, 'product_variation', 'DUP-59' );
		add_filter(
			'wc_product_pre_has_unique_sku',
			static fn() => true,
			PHP_INT_MAX,
			3
		);
		$product = new Digitalogic_Test_SKU_Product( 49, 'DUP-59' );

		try {
			$product->save();
			$this->fail( 'A late importer bypass must still abort at the locked before-save boundary.' );
		} catch ( WC_Data_Exception $exception ) {
			$this->assertSame( 'normalized_duplicate', $exception->getErrorCode() );
		}
		$this->assertSame( 'KEEP-49', get_post_meta( 49, '_sku', true ) );
		$this->assertSame( 'KEEP-49', $GLOBALS['digitalogic_test_wc_lookup_rows'][49]['sku'] );
	}

	public function test_nested_different_sku_action_releases_only_its_exact_scope(): void {
		$this->seed_product( 50, 'product', 'KEEP-50' );
		$GLOBALS['digitalogic_test_meta_by_mid'][501] = array(
			'post_id'    => 50,
			'meta_key'   => '_sku', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fake metadata row.
			'meta_value' => 'KEEP-50', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Fake metadata row.
		);
		add_filter( 'sanitize_post_meta__sku', static fn() => 'COLLIDING-AFTER-GUARD', PHP_INT_MAX, 4 );

		$this->assertFalse( update_metadata_by_mid( 'post', 501, 'SAFE-BEFORE-SANITIZE', false ) );
		$this->assertSame( 'KEEP-50', get_post_meta( 50, '_sku', true ) );
		$this->assertSame( 'KEEP-50', $GLOBALS['digitalogic_test_wc_lookup_rows'][50]['sku'] );
	}

	public function test_nonproduct_promotion_rejects_duplicate_drift_and_invalid_staged_skus(): void {
		$this->seed_product( 60, 'product', 'DUP-60' );
		$this->seed_product( 61, 'post', "dup\u{2013}۶۰" );
		$this->seed_product( 62, 'post', 'BAD?' );
		$this->seed_product( 63, 'post', 'UNIQUE-63' );
		$this->seed_product( 65, 'post', null );

		foreach ( array( 61, 62, 63 ) as $post_id ) {
			try {
				wp_update_post(
					array(
						'ID'        => $post_id,
						'post_type' => 'product',
					)
				);
				$this->fail( 'A staged duplicate or invalid SKU must block product-type promotion.' );
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'The staged SKU cannot be promoted to a product.', $exception->getMessage() );
			}
			$this->assertSame( 'post', get_post_type( $post_id ) );
		}

		$this->assertSame(
			65,
			wp_update_post(
				array(
					'ID'        => 65,
					'post_type' => 'product_variation',
				)
			)
		);
		$this->assertSame( 'product_variation', get_post_type( 65 ) );
		$this->assertSame( '', get_post_meta( 65, '_sku', true ) );
		$this->assertSame( array(), $this->database->used_locks );
	}

	public function test_absent_object_cannot_pre_stage_sku_before_explicit_product_creation(): void {
		$this->assertFalse( add_post_meta( 64, '_sku', 'ORPHAN-64', true ) );
		$this->assertFalse( update_post_meta( 64, '_SKU', 'ORPHAN-64' ) );
		$this->assertArrayNotHasKey( 64, $GLOBALS['digitalogic_test_posts'] );
		$reasons = array_column( $GLOBALS['digitalogic_test_actions']['digitalogic_sku_guard_blocked'], 3 );
		$this->assertSame( array_fill( 0, 2, 'direct_sku_write_requires_woocommerce_crud' ), $reasons );
	}

	public function test_status_reports_normalized_collision_multiple_rows_drift_and_invalid_value(): void {
		$this->seed_product( 51, 'product', "abc\u{2013}۱۲۳" );
		$this->seed_product( 52, 'product_variation', 'ABC-123' );
		$this->seed_product( 53, 'product', 'BAD?' );
		$this->seed_product( 54, 'product', 'ROW-A' );
		$GLOBALS['digitalogic_test_posts'][54]['meta_rows']['_sku'] = array( 'ROW-A', 'ROW-B' );

		$status = Digitalogic_SKU_Guard::status();

		$this->assertFalse( $status['ok'] );
		$this->assertTrue( $status['query_ok'] );
		$this->assertFalse( $status['healthy'] );
		$this->assertSame( 'sku_status_invariant_failed', $status['error'] );
		$this->assertSame( '2.1.0', $status['version'] );
		$this->assertSame( 'digitalogic-wp', $status['implementation'] );
		$this->assertSame( 1, $status['normalized_collision_groups'] );
		$this->assertSame( array( 51, 52 ), $status['collision_details']['ABC-123'] );
		$this->assertSame( 1, $status['multiple_sku_row_products'] );
		$this->assertSame( 2, $status['multiple_row_details'][54] );
		$this->assertSame( 1, $status['normalization_drift_products'] );
		$this->assertSame( 1, $status['invalid_sku_products'] );
		$this->assertGreaterThanOrEqual( 1, $status['lookup_parity_failures'] );
		$this->assertSame( 'digitalogic_sku_invalid_characters', $status['invalid_sku_details'][53] );
	}

	public function test_status_query_failure_is_explicit_and_never_reports_healthy_zeroes(): void {
		$this->database->sku_status_query_failure = true;

		$status = Digitalogic_SKU_Guard::status();

		$this->assertFalse( $status['ok'] );
		$this->assertFalse( $status['query_ok'] );
		$this->assertFalse( $status['healthy'] );
		$this->assertSame( 'sku_status_query_failed', $status['error'] );
		$this->assertNull( $status['normalized_collision_groups'] );
		$this->assertNull( $status['multiple_sku_row_products'] );
		$this->assertNull( $status['normalization_drift_products'] );
		$this->assertNull( $status['invalid_sku_products'] );
		$this->assertNull( $status['lookup_parity_failures'] );
	}

	/**
	 * @param int         $id        Product ID.
	 * @param string      $post_type Product or variation post type.
	 * @param string|null $sku       Optional stored SKU.
	 */
	private function seed_product( $id, $post_type, $sku ) {
		$meta = array();
		if ( null !== $sku && '' !== $sku ) {
			$meta['_sku'] = (string) $sku;
		}
		$GLOBALS['digitalogic_test_posts'][ (int) $id ]          = array(
			'post_type'   => (string) $post_type,
			'post_status' => 'publish',
			'post_parent' => 'product_variation' === $post_type ? 11 : 0,
			'meta'        => $meta,
		);
		$GLOBALS['digitalogic_test_wc_lookup_rows'][ (int) $id ] = array(
			'product_id' => (int) $id,
			'sku'        => null === $sku ? '' : (string) $sku,
		);
	}

	/** Reset one plugin singleton between request-shaped test cases. */
	private function reset_singleton( $class_name ) {
		$instance = new ReflectionProperty( $class_name, 'instance' );
		$instance->setValue( null, null );
	}
}
