<?php
/**
 * Transactional Patris topology repair tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Exact dry-run, transaction, and rollback coverage for reviewed topology repair. */
final class PatrisTopologyRepairTest extends TestCase {

	private const SOURCE_ID       = 'tests';
	private const DATASET         = 'ALLANBAR';
	private const SOURCE_REVISION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** Reset the exact source, identity, taxonomy, lock, and transaction surfaces. */
	protected function setUp(): void {
		parent::setUp();
		foreach (
			array(
				'digitalogic_test_options',
				'digitalogic_test_option_cache',
				'digitalogic_test_posts',
				'digitalogic_test_post_meta_cache',
				'digitalogic_test_terms',
				'digitalogic_test_term_meta',
				'digitalogic_test_wc_products',
				'digitalogic_test_wc_product_saves',
				'digitalogic_test_wc_save_failures',
				'digitalogic_test_wc_save_fail_once',
				'digitalogic_test_transaction_failures',
				'digitalogic_test_actions',
				'digitalogic_test_action_callbacks',
				'digitalogic_test_filters',
				'digitalogic_test_cache_deletes',
				'digitalogic_test_cache_delete_failures',
				'digitalogic_test_object_cache',
				'digitalogic_test_wc_transient_deletes',
				'digitalogic_test_object_term_cache_cleans',
				'digitalogic_test_wc_cache_group_invalidations',
				'digitalogic_test_wc_product_instance_cache_removals',
				'digitalogic_test_wc_product_instance_cache_failure_ids',
				'digitalogic_test_wc_delete_meta_noop_ids',
			)
			as $global_name
		) {
			$GLOBALS[ $global_name ] = array();
		}
		$GLOBALS['digitalogic_test_next_post_id']                     = 300;
		$GLOBALS['digitalogic_test_next_term_id']                     = 500;
		$GLOBALS['digitalogic_test_object_cache_enabled']             = true;
		$GLOBALS['digitalogic_test_wc_defer_new_product_id']          = false;
		$GLOBALS['digitalogic_test_enqueue_product_sync_on_term_set'] = false;
		unset( $GLOBALS['wc_deferred_product_sync'] );
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

		foreach (
			array(
				Digitalogic_Patris_Topology_Repair::class,
				Digitalogic_Patris_Catalog_Materializer::class,
				Digitalogic_Product_Sync_Receiver::class,
				Digitalogic_Product_Identifier_Resolver::class,
				Digitalogic_Product_Code_Editor::class,
				Digitalogic_Product_Code_Write_Guard::class,
				Digitalogic_Product_Write_Lock::class,
			)
			as $class_name
		) {
			$this->resetSingleton( $class_name );
		}

		$this->addFixtures();
	}

	/** Dry-run is inert; apply moves identity once and preserves every reviewed child. */
	public function test_dry_run_then_atomic_apply_has_exact_readback(): void {
		$before_posts = $GLOBALS['digitalogic_test_posts'];
		$before_terms = $GLOBALS['digitalogic_test_terms'];

		$GLOBALS['digitalogic_test_wc_delete_meta_noop_ids'] = array( 200 );

		$dry_run = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan() );

		$this->assertNotInstanceOf( WP_Error::class, $dry_run );
		$this->assertSame( 'dry_run', $dry_run['mode'] );
		$this->assertSame( 2, $dry_run['empty_parent_count'] );
		$this->assertSame( 9, $dry_run['locked_product_count'] );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $before_terms, $GLOBALS['digitalogic_test_terms'] );
		$this->assertNotContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );

		$applied = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertNotInstanceOf( WP_Error::class, $applied );
		$this->assertSame( 'apply', $applied['mode'] );
		$this->assertSame( 300, $applied['new_base_variation_id'] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertSame( 'variable', wc_get_product( 100 )->get_type() );
		$this->assertSame( 'variable', wc_get_product( 110 )->get_type() );
		$this->assertSame( 'variable', wc_get_product( 200 )->get_type() );
		$this->assertSame( '', wc_get_product( 200 )->get_sku() );
		$this->assertSame( '', get_post_meta( 200, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );

		$base = wc_get_product( 300 );
		$this->assertInstanceOf( WC_Product::class, $base );
		$this->assertSame( 'variation', $base->get_type() );
		$this->assertSame( 200, $base->get_parent_id() );
		$this->assertSame( 'draft', $base->get_status() );
		$this->assertSame( 'BASE', $base->get_sku() );
		$this->assertSame( 'BASE', get_post_meta( 300, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		$this->assertSame( 'ch340', $base->get_variation_attributes()['attribute_pa_model'] );
		$this->assertSame( 300, (int) Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'patris_code' => 'BASE' ) )['woocommerce_id'] );
		$this->assertSame( array( 101, 102 ), wc_get_product( 100 )->get_children() );
		$this->assertSame( array( 111 ), wc_get_product( 110 )->get_children() );
		$this->assertSame( array( 201, 202, 203, 300 ), wc_get_product( 200 )->get_children() );
		$this->assertContains( 200, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 'product_200', $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
	}

	/** Any write failure restores posts, terms, counters, identity, and parent types. */
	public function test_apply_failure_rolls_back_every_topology_effect(): void {
		$before_posts                                 = $GLOBALS['digitalogic_test_posts'];
		$before_terms                                 = $GLOBALS['digitalogic_test_terms'];
		$GLOBALS['digitalogic_test_wc_save_failures'] = array( 200 );

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_topology_repair_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['rollback_attempted'] );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertNotContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertSame( $before_posts, $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( $before_terms, $GLOBALS['digitalogic_test_terms'] );
		$this->assertSame( 300, $GLOBALS['digitalogic_test_next_post_id'] );
		$this->assertSame( 500, $GLOBALS['digitalogic_test_next_term_id'] );
		$this->assertSame( 'simple', wc_get_product( 100 )->get_type() );
		$this->assertSame( 'simple', wc_get_product( 110 )->get_type() );
		$this->assertSame( 'simple', wc_get_product( 200 )->get_type() );
		$this->assertSame( 'BASE', wc_get_product( 200 )->get_sku() );
		$this->assertSame( 'BASE', get_post_meta( 200, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		$this->assertFalse( get_term_by( 'slug', 'ch340', 'pa_model' ) );
		$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
	}

	/** Rollback drops only syncs queued by the transaction and clears parent term caches. */
	public function test_apply_failure_restores_exact_deferred_sync_queue_and_term_caches(): void {
		$GLOBALS['wc_deferred_product_sync']                          = array( 999 );
		$GLOBALS['digitalogic_test_enqueue_product_sync_on_term_set'] = true;
		$GLOBALS['digitalogic_test_wc_save_failures']                 = array( 200 );

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 999 ), $GLOBALS['wc_deferred_product_sync'] );
		$this->assertContains(
			array( 100, 'product' ),
			$GLOBALS['digitalogic_test_object_term_cache_cleans']
		);
		$this->assertContains(
			array( 110, 'product' ),
			$GLOBALS['digitalogic_test_object_term_cache_cleans']
		);
		$this->assertContains(
			array( 200, 'product' ),
			$GLOBALS['digitalogic_test_object_term_cache_cleans']
		);
		$this->assertContains( 200, $GLOBALS['digitalogic_test_wc_product_instance_cache_removals'] );
		$this->assertContains( 'product_200', $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
	}

	/** Exact relationship groups are evicted before transactional readback. */
	public function test_apply_evicts_stale_persistent_relationship_caches(): void {
		$GLOBALS['digitalogic_test_object_cache']['product_type_relationships:200'] = array( 2 );
		$GLOBALS['digitalogic_test_object_cache']['product_cat_relationships:200']  = array( 12 );
		$GLOBALS['digitalogic_test_object_cache']['pa_model_relationships:200']     = array();

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayNotHasKey( 'product_type_relationships:200', $GLOBALS['digitalogic_test_object_cache'] );
		$this->assertArrayNotHasKey( 'product_cat_relationships:200', $GLOBALS['digitalogic_test_object_cache'] );
		$this->assertArrayNotHasKey( 'pa_model_relationships:200', $GLOBALS['digitalogic_test_object_cache'] );
		$this->assertContains( array( 200, 'product_type_relationships' ), $GLOBALS['digitalogic_test_cache_deletes'] );
		$this->assertContains( array( 200, 'product_cat_relationships' ), $GLOBALS['digitalogic_test_cache_deletes'] );
		$this->assertContains( array( 200, 'pa_model_relationships' ), $GLOBALS['digitalogic_test_cache_deletes'] );
	}

	/** A relationship cache that survives exact deletion makes outcome unknown. */
	public function test_relationship_cache_invalidation_failure_fails_closed(): void {
		$GLOBALS['digitalogic_test_object_cache']['pa_model_relationships:200'] = array();
		$GLOBALS['digitalogic_test_cache_delete_failures']                      = array( 'pa_model_relationships:200' );

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_topology_outcome_unknown', $result->get_error_code() );
		$this->assertSame( 'digitalogic_patris_topology_cache_unavailable', $result->get_error_data()['cause'] );
		$this->assertSame( 'digitalogic_patris_topology_cache_unavailable', $result->get_error_data()['rollback_term_cache'] );
		$this->assertArrayHasKey( 'pa_model_relationships:200', $GLOBALS['digitalogic_test_object_cache'] );
	}

	/** A product-instance cache failure makes rollback outcome explicitly unaudited. */
	public function test_product_instance_cache_failure_returns_outcome_unknown(): void {
		$GLOBALS['digitalogic_test_wc_product_instance_cache_failure_ids'] = array( 200 );

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_topology_outcome_unknown', $result->get_error_code() );
		$this->assertSame( 'digitalogic_patris_topology_cache_unavailable', $result->get_error_data()['cause'] );
		$this->assertSame( 'digitalogic_patris_topology_cache_unavailable', $result->get_error_data()['rollback_cache'] );
		$this->assertTrue( $result->get_error_data()['rollback_confirmed'] );
		$this->assertSame( 'simple', $GLOBALS['digitalogic_test_posts'][200]['product_type'] );
		$this->assertSame( 'BASE', $GLOBALS['digitalogic_test_posts'][200]['meta']['_sku'] );
	}

	/** Exact readback predicate and product ID survive rollback without leaking values. */
	public function test_readback_failure_reports_stable_exact_cause(): void {
		$GLOBALS['digitalogic_test_wc_after_save'] = static function () {
			$GLOBALS['digitalogic_test_posts'][100]['product_type'] = 'simple';
		};

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_topology_repair_failed', $result->get_error_code() );
		$this->assertSame(
			'digitalogic_patris_topology_readback_failed:empty_parent_type:100',
			$result->get_error_data()['cause']
		);
		$this->assertFalse( array_key_exists( 'wc_deferred_product_sync', $GLOBALS ) );
	}

	/** A failed COMMIT is never represented as a confirmed rollback or safe retry. */
	public function test_commit_failure_returns_outcome_unknown_for_exact_audit(): void {
		$GLOBALS['digitalogic_test_transaction_failures'] = array( 'COMMIT' );

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $this->plan(), true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_topology_outcome_unknown', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['commit_attempted'] );
		$this->assertTrue( $result->get_error_data()['rollback_attempted'] );
		$this->assertTrue( $result->get_error_data()['rollback_confirmed'] );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );
		$this->assertSame( 'simple', wc_get_product( 200 )->get_type() );
		$this->assertSame( 'BASE', wc_get_product( 200 )->get_sku() );
		$this->assertFalse( Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() );
	}

	/** A source revision change rejects apply before locks, terms, or transactions. */
	public function test_apply_fails_closed_when_source_revision_changed(): void {
		$plan                       = $this->plan();
		$plan['source']['revision'] = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

		$result = Digitalogic_Patris_Topology_Repair::instance()->run( $plan, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_topology_source_changed', $result->get_error_code() );
		$this->assertNotContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertFalse( get_term_by( 'slug', 'ch340', 'pa_model' ) );
	}

	/** Build the exact synthetic legacy parents, children, terms, and source state. */
	private function addFixtures(): void {
		$this->addProduct( 100, 'product', 'simple', '', '', 0 );
		$this->addProduct( 101, 'product_variation', 'variation', 'EMPTY-A', 'EMPTY-A', 100 );
		$this->addProduct( 102, 'product_variation', 'variation', '', '', 100 );
		$this->addProduct( 110, 'product', 'simple', '', '', 0 );
		$this->addProduct( 111, 'product_variation', 'variation', 'EMPTY-B', 'EMPTY-B', 110 );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 1 );
		$attribute->set_name( 'pa_model' );
		$attribute->set_options( array( 449, 450 ) );
		$attribute->set_variation( true );
		$this->addProduct( 200, 'product', 'simple', 'BASE', 'BASE', 0, array( 'pa_model' => $attribute ) );
		$this->addProduct( 201, 'product_variation', 'variation', 'VAR-1', 'VAR-1', 200, array(), array( 'attribute_pa_model' => 'nrf24l01' ) );
		$this->addProduct( 202, 'product_variation', 'variation', 'VAR-2', 'VAR-2', 200, array(), array( 'attribute_pa_model' => 'super-mini' ) );
		$this->addProduct( 203, 'product_variation', 'variation', '', '', 200, array(), array( 'attribute_pa_model' => '' ) );

		$GLOBALS['digitalogic_test_terms'][449] = array(
			'term_id'  => 449,
			'name'     => 'NRF24L01',
			'slug'     => 'nrf24l01',
			'parent'   => 0,
			'taxonomy' => 'pa_model',
		);
		$GLOBALS['digitalogic_test_terms'][450] = array(
			'term_id'  => 450,
			'name'     => 'Super Mini',
			'slug'     => 'super-mini',
			'parent'   => 0,
			'taxonomy' => 'pa_model',
		);

		$products = array();
		foreach ( array( 'EMPTY-A', 'EMPTY-B', 'BASE', 'VAR-1', 'VAR-2' ) as $code ) {
			$products[ $code ] = array( 'product_code' => $code );
		}
		$key = hash( 'sha256', self::SOURCE_ID . "\n" . self::DATASET );
		update_option(
			Digitalogic_Product_Sync_Receiver::STATE_OPTION,
			array(
				'sources' => array(
					$key => array(
						'source'   => array(
							'id'       => self::SOURCE_ID,
							'dataset'  => self::DATASET,
							'revision' => self::SOURCE_REVISION,
						),
						'products' => $products,
					),
				),
			),
			false
		);
	}

	/**
	 * Add one exact simple parent or raw variation child.
	 *
	 * @param int    $id           Product ID.
	 * @param string $post_type    WordPress post type.
	 * @param string $product_type WooCommerce product type.
	 * @param string $sku          Exact SKU or blank.
	 * @param string $code         Exact Product Code or blank.
	 * @param int    $parent_id    Parent ID or zero.
	 * @param array  $attributes   Product attributes.
	 * @param array  $meta         Additional metadata.
	 * @return void
	 */
	private function addProduct( $id, $post_type, $product_type, $sku, $code, $parent_id, $attributes = array(), $meta = array() ): void {
		$meta['_sku'] = $sku;
		if ( '' !== $code ) {
			$meta[ Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META ] = $code;
		}
		$GLOBALS['digitalogic_test_posts'][ $id ] = array(
			'post_type'    => $post_type,
			'post_status'  => 'publish',
			'product_type' => $product_type,
			'post_parent'  => $parent_id,
			'post_title'   => 'Reviewed topology fixture ' . $id,
			'attributes'   => $attributes,
			'meta'         => $meta,
		);
	}

	/** Return the exact source-revision-pinned reviewed plan. */
	private function plan(): array {
		return array(
			'schema'          => 'digitalogic.patris-topology-repair/v1',
			'source'          => array(
				'id'       => self::SOURCE_ID,
				'dataset'  => self::DATASET,
				'revision' => self::SOURCE_REVISION,
			),
			'empty_parents'   => array(
				array(
					'parent_id' => 100,
					'children'  => array(
						101 => 'EMPTY-A',
						102 => '',
					),
				),
				array(
					'parent_id' => 110,
					'children'  => array( 111 => 'EMPTY-B' ),
				),
			),
			'identity_parent' => array(
				'parent_id'          => 200,
				'children'           => array(
					201 => 'VAR-1',
					202 => 'VAR-2',
					203 => '',
				),
				'product_code'       => 'BASE',
				'attribute_taxonomy' => 'pa_model',
				'current_term_ids'   => array( 449, 450 ),
				'base_term_name'     => 'CH340',
				'base_term_slug'     => 'ch340',
			),
		);
	}

	/**
	 * Reset one private singleton so cleared hook registries are reattached.
	 *
	 * @param string $class_name Singleton class name.
	 * @return void
	 */
	private function resetSingleton( $class_name ): void {
		$property = ( new ReflectionClass( $class_name ) )->getProperty( 'instance' );
		$property->setValue( null, null );
	}
}
