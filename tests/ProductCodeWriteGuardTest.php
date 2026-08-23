<?php
/**
 * Canonical Product Code writer-boundary tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify generic WooCommerce and metadata writers cannot bypass the editor. */
final class ProductCodeWriteGuardTest extends TestCase {

	/** Reset the metadata boundary and one product fixture. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_filters']              = array();
		$GLOBALS['digitalogic_test_action_callbacks']     = array();
		$GLOBALS['digitalogic_test_actions']              = array();
		$GLOBALS['digitalogic_test_options']              = array();
		$GLOBALS['digitalogic_test_option_cache']         = array();
		$GLOBALS['digitalogic_test_meta_by_mid']          = array();
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_meta_update_failures'] = array();
		$GLOBALS['digitalogic_test_meta_delete_failures'] = array();
		$GLOBALS['digitalogic_test_posts']                = array(
			901 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => '00901' ),
			),
			902 => array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => '00902' ),
			),
		);
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();

		$this->reset_singleton( Digitalogic_Product_Code_Write_Guard::class );
		$this->reset_singleton( Digitalogic_Product_Sync_Receiver::class );
		Digitalogic_Product_Code_Write_Guard::instance();
	}

	/** Generic WordPress and WooCommerce CRUD writes are default-denied. */
	public function test_generic_metadata_and_woo_crud_writes_are_blocked(): void {
		$this->assertFalse( update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'CHANGED' ) );
		$this->assertSame( '00901', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );

		$product = new WC_Product( 901 );
		$product->update_meta_data( Digitalogic_Product_Code_Editor::META_KEY, 'CRUD-CHANGED' );
		$product->save();

		$this->assertSame( '00901', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** An explicit writer context is still refused unless it owns the source lock. */
	public function test_authorized_context_requires_shared_source_lock(): void {
		$guard  = Digitalogic_Product_Code_Write_Guard::instance();
		$denied = $guard->with_authorized_write(
			'editor',
			array( 'product_id' => 901, 'operation' => 'set', 'value' => 'LOCKLESS' ),
			static function () {
				return update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'LOCKLESS' );
			}
		);
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'digitalogic_product_code_dedicated_operation_required', $denied->get_error_code() );

		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );
		try {
			$written = $guard->with_authorized_write(
				'editor',
				array( 'product_id' => 901, 'operation' => 'set', 'value' => 'LOCKED' ),
				static function () {
					return update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'LOCKED' );
				}
			);
		} finally {
			$receiver->release_source_identity_lock();
		}
		$this->assertSame( 1, $written );
		$this->assertSame( 'LOCKED', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** A database reconnect invalidates request-local depth before any effect. */
	public function test_authorized_context_fails_closed_after_source_lock_connection_is_lost(): void {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );
		$GLOBALS['wpdb']->connection_id = 2002;

		$result = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
			'editor',
			array( 'product_id' => 901, 'operation' => 'set', 'value' => 'MUST-NOT-WRITE' ),
			static function () {
				return update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'MUST-NOT-WRITE' );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $receiver->source_identity_lock_is_owned() );
		$this->assertSame( '00901', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** A reconnect inside the writer callback cannot reuse its request-local scope. */
	public function test_writer_scope_revalidates_connection_ownership_at_the_metadata_effect(): void {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );

		$result = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
			'editor',
			array( 'product_id' => 901, 'operation' => 'set', 'value' => 'MUST-NOT-WRITE' ),
			static function () {
				$GLOBALS['wpdb']->connection_id = 2002;
				return update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'MUST-NOT-WRITE' );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_source_lock_lost', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['retryable'] );
		$this->assertSame( '00901', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Product and variation REST meta_data requests get the same typed refusal. */
	public function test_product_and_variation_rest_meta_data_are_rejected_with_or_without_id(): void {
		$guard = Digitalogic_Product_Code_Write_Guard::instance();
		$rows  = array(
			array( 'key' => Digitalogic_Product_Code_Editor::META_KEY, 'value' => 'REST' ),
			array( 'id' => 77, 'key' => Digitalogic_Product_Code_Editor::META_KEY, 'value' => 'REST-MID' ),
		);
		foreach ( array( 901, 902 ) as $product_id ) {
			foreach ( $rows as $row ) {
				$request = new WP_REST_Request( array( 'meta_data' => array( $row ) ) );
				$result  = $guard->reject_rest_write( new WC_Product( $product_id ), $request, false );
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( 'digitalogic_product_code_dedicated_operation_required', $result->get_error_code() );
				$this->assertSame( 409, $result->get_error_data()['status'] );
			}
		}
	}

	/** Existing metadata IDs cannot bypass the key boundary. */
	public function test_by_mid_update_and_delete_are_blocked(): void {
		$GLOBALS['digitalogic_test_meta_by_mid'][77] = array(
			'post_id'  => 901,
			'meta_key' => Digitalogic_Product_Code_Editor::META_KEY,
		);
		$guard                                       = Digitalogic_Product_Code_Write_Guard::instance();

		$this->assertFalse( $guard->guard_mid_update( null, 77, 'MID', false ) );
		$this->assertFalse( $guard->guard_mid_update( null, 77, 'MID', Digitalogic_Product_Code_Editor::META_KEY ) );
		$this->assertFalse( $guard->guard_mid_update( null, 77, 'MID', '_some_other_key' ) );
		$this->assertFalse( $guard->guard_mid_delete( null, 77 ) );
		$this->assertNull( $guard->guard_mid_update( null, 88, 'OTHER', '_unrelated_meta' ) );

		$GLOBALS['digitalogic_test_meta_by_mid'][88] = array(
			'post_id'  => 901,
			'meta_key' => '_unrelated_meta',
		);
		$this->assertFalse( $guard->guard_mid_update( null, 88, 'MID', Digitalogic_Product_Code_Editor::META_KEY ) );
	}

	/** Re-entrant hooks cannot spend an exact authorization on another product. */
	public function test_authorized_scope_cannot_write_another_product_or_value(): void {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );
		try {
			$result = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'editor',
				array( 'product_id' => 901, 'operation' => 'set', 'value' => 'EXACT' ),
				static function () {
					$other = update_post_meta( 902, Digitalogic_Product_Code_Editor::META_KEY, 'EXACT' );
					$wrong = update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'WRONG' );
					$exact = update_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, 'EXACT' );
					return array( $other, $wrong, $exact );
				}
			);
		} finally {
			$receiver->release_source_identity_lock();
		}

		$this->assertSame( array( false, false, 1 ), $result );
		$this->assertSame( '00902', get_post_meta( 902, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertSame( 'EXACT', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Writer authorization snapshots an immutable product ID at context entry. */
	public function test_authorized_scope_does_not_follow_a_mutated_product_object(): void {
		$product  = new WC_Product( 901 );
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );
		try {
			$result = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'editor',
				array( 'product' => $product, 'operation' => 'set', 'value' => 'IMMUTABLE' ),
				static function () use ( $product ) {
					$property = new ReflectionProperty( WC_Product::class, 'id' );
					$property->setValue( $product, 902 );
					return update_post_meta( 902, Digitalogic_Product_Code_Editor::META_KEY, 'IMMUTABLE' );
				}
			);
		} finally {
			$receiver->release_source_identity_lock();
		}
		$this->assertFalse( $result );
		$this->assertSame( '00902', get_post_meta( 902, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Case variants and delete-all cannot exploit a narrow internal scope. */
	public function test_case_variants_and_delete_all_are_always_blocked(): void {
		$guard = Digitalogic_Product_Code_Write_Guard::instance();
		$upper = strtoupper( Digitalogic_Product_Code_Editor::META_KEY );
		$this->assertFalse( $guard->guard_key_set( null, 901, $upper, 'CASE', '' ) );
		$this->assertFalse( $guard->guard_key_delete( null, 901, $upper, '', false ) );
		$this->assertInstanceOf(
			WP_Error::class,
			$guard->reject_rest_write( new WC_Product( 901 ), new WP_REST_Request( array( 'meta_data' => array( array( 'key' => $upper, 'value' => 'CASE' ) ) ) ), false )
		);

		$GLOBALS['digitalogic_test_meta_by_mid'][79] = array(
			'post_id'  => 901,
			'meta_key' => $upper,
		);
		$this->assertFalse( $guard->guard_mid_update( null, 79, 'CASE', false ) );
		$this->assertFalse( $guard->guard_mid_delete( null, 79 ) );

		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$this->assertTrue( $receiver->acquire_source_identity_lock( 0 ) );
		try {
			$result = $guard->with_authorized_write(
				'editor',
				array( 'product_id' => 901, 'operation' => 'delete' ),
				static function () use ( $guard ) {
					return $guard->guard_key_delete( null, 901, Digitalogic_Product_Code_Editor::META_KEY, '', true );
				}
			);
		} finally {
			$receiver->release_source_identity_lock();
		}
		$this->assertFalse( $result );
		$this->assertSame( '00901', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Permanent product cleanup removes canonical metadata without opening a generic bypass. */
	public function test_permanent_product_and_variation_deletion_leave_no_orphan_meta(): void {
		$this->assertNotFalse( wp_delete_post( 901, true ) );
		$this->assertNotFalse( wp_delete_post( 902, true ) );
		$this->assertArrayNotHasKey( 901, $GLOBALS['digitalogic_test_posts'] );
		$this->assertArrayNotHasKey( 902, $GLOBALS['digitalogic_test_posts'] );

		$GLOBALS['digitalogic_test_posts'][903] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( Digitalogic_Product_Code_Editor::META_KEY => '00903' ),
		);
		$this->assertFalse( delete_post_meta( 903, Digitalogic_Product_Code_Editor::META_KEY ) );
		$this->assertSame( '00903', get_post_meta( 903, Digitalogic_Product_Code_Editor::META_KEY, true ) );
	}

	/** Permanent deletion fails immediately when another source writer owns the lock. */
	public function test_permanent_deletion_is_blocked_before_lifecycle_effect_when_source_lock_is_busy(): void {
		$GLOBALS['wpdb']->acquire_results = array( 0 );

		$this->assertFalse( wp_delete_post( 901, true ) );
		$this->assertArrayHasKey( 901, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( '00901', get_post_meta( 901, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertSame( array( 0 ), $GLOBALS['wpdb']->lock_timeouts );
	}

	/** Reset one private singleton. */
	private function reset_singleton( $class_name ) {
		$property = new ReflectionProperty( $class_name, 'instance' );
		$property->setValue( null, null );
	}
}
