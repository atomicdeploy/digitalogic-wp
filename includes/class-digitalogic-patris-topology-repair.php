<?php
/**
 * Explicit, reviewed repair for legacy source-variation topology.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Repair only an exact, administrator-reviewed legacy topology plan. */
final class Digitalogic_Patris_Topology_Repair {

	private const SCHEMA = 'digitalogic.patris-topology-repair/v1';

	/**
	 * Shared repair service.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Return the shared repair service. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Prevent external construction. */
	private function __construct() {}

	/**
	 * Inspect or atomically apply one exact reviewed topology plan.
	 *
	 * This is intentionally not part of normal reconciliation. Identity hazards
	 * remain deferred until an administrator supplies every current parent,
	 * child, source, and attribute fact in the plan and explicitly requests apply.
	 *
	 * @param array $plan Reviewed exact topology plan.
	 * @param bool  $apply Whether to perform the fenced transaction.
	 * @return array|WP_Error
	 */
	public function run( $plan, $apply = false ) {
		$normalized = $this->normalize_plan( $plan );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$cache_fence = $this->fence_topology_relationship_caches(
			array_merge(
				array_keys( $normalized['empty_parents'] ),
				array( $normalized['identity_parent']['parent_id'] )
			),
			$normalized['identity_parent']['attribute_taxonomy']
		);
		if ( is_wp_error( $cache_fence ) ) {
			return $cache_fence;
		}
		$inspected = $this->inspect_plan( $normalized );
		if ( is_wp_error( $inspected ) || ! $apply ) {
			return $inspected;
		}

		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$locked   = $receiver->acquire_source_identity_lock( 0 );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		try {
			return $this->with_product_locks(
				$normalized['locked_product_ids'],
				function () use ( $normalized ) {
					$rechecked = $this->inspect_plan( $normalized );
					if ( is_wp_error( $rechecked ) ) {
						return $rechecked;
					}

					return $this->apply_transaction( $normalized );
				}
			);
		} finally {
			$receiver->release_source_identity_lock();
		}
	}

	/**
	 * Normalize the bounded manifest without inferring any identity.
	 *
	 * @param array $plan Reviewed topology plan.
	 * @return array|WP_Error
	 */
	private function normalize_plan( $plan ) {
		if (
			! is_array( $plan )
			|| self::SCHEMA !== (string) ( $plan['schema'] ?? '' )
			|| ! is_array( $plan['source'] ?? null )
			|| ! is_array( $plan['empty_parents'] ?? null )
			|| ! is_array( $plan['identity_parent'] ?? null )
		) {
			return $this->error( 'digitalogic_patris_topology_plan_invalid', 'The reviewed topology plan is invalid.' );
		}
		$source = array(
			'id'       => trim( (string) ( $plan['source']['id'] ?? '' ) ),
			'dataset'  => trim( (string) ( $plan['source']['dataset'] ?? '' ) ),
			'revision' => trim( (string) ( $plan['source']['revision'] ?? '' ) ),
		);
		if (
			'' === $source['id']
			|| '' === $source['dataset']
			|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $source['revision'] )
			|| empty( $plan['empty_parents'] )
			|| count( $plan['empty_parents'] ) > 25
		) {
			return $this->error( 'digitalogic_patris_topology_plan_invalid', 'The reviewed topology plan is invalid.' );
		}

		$empty_parents = array();
		$locked_ids    = array();
		foreach ( $plan['empty_parents'] as $entry ) {
			$parent_id = absint( is_array( $entry ) ? ( $entry['parent_id'] ?? 0 ) : 0 );
			$children  = $this->normalize_child_map( is_array( $entry ) ? ( $entry['children'] ?? null ) : null );
			if ( $parent_id <= 0 || is_wp_error( $children ) || isset( $empty_parents[ $parent_id ] ) ) {
				return $this->error( 'digitalogic_patris_topology_plan_invalid', 'The reviewed topology plan is invalid.' );
			}
			$empty_parents[ $parent_id ] = $children;
			$locked_ids[]                = $parent_id;
			$locked_ids                  = array_merge( $locked_ids, array_keys( $children ) );
		}
		ksort( $empty_parents, SORT_NUMERIC );

		$identity              = $plan['identity_parent'];
		$identity_parent_id    = absint( $identity['parent_id'] ?? 0 );
		$identity_children     = $this->normalize_child_map( $identity['children'] ?? null );
		$identity_product_code = trim( (string) ( $identity['product_code'] ?? '' ) );
		$taxonomy              = sanitize_key( (string) ( $identity['attribute_taxonomy'] ?? '' ) );
		$term_name             = trim( wp_strip_all_tags( (string) ( $identity['base_term_name'] ?? '' ) ) );
		$term_slug             = sanitize_title( (string) ( $identity['base_term_slug'] ?? '' ) );
		$term_ids              = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $identity['current_term_ids'] ?? array() ) ) ) ) );
		sort( $term_ids, SORT_NUMERIC );
		if (
			$identity_parent_id <= 0
			|| isset( $empty_parents[ $identity_parent_id ] )
			|| is_wp_error( $identity_children )
			|| '' === $identity_product_code
			|| '' === $taxonomy
			|| '' === $term_name
			|| '' === $term_slug
			|| empty( $term_ids )
		) {
			return $this->error( 'digitalogic_patris_topology_plan_invalid', 'The reviewed topology plan is invalid.' );
		}
		$locked_ids[] = $identity_parent_id;
		$locked_ids   = array_merge( $locked_ids, array_keys( $identity_children ) );
		$locked_ids   = array_values( array_unique( array_map( 'absint', $locked_ids ) ) );
		sort( $locked_ids, SORT_NUMERIC );

		return array(
			'schema'             => self::SCHEMA,
			'source'             => $source,
			'empty_parents'      => $empty_parents,
			'identity_parent'    => array(
				'parent_id'          => $identity_parent_id,
				'children'           => $identity_children,
				'product_code'       => $identity_product_code,
				'attribute_taxonomy' => $taxonomy,
				'base_term_name'     => $term_name,
				'base_term_slug'     => $term_slug,
				'current_term_ids'   => $term_ids,
			),
			'locked_product_ids' => $locked_ids,
		);
	}

	/**
	 * Normalize an exact child-ID-to-Code map, preserving intentional blanks.
	 *
	 * @param array $children Exact child-ID-to-Code map.
	 * @return array|WP_Error
	 */
	private function normalize_child_map( $children ) {
		if ( ! is_array( $children ) || empty( $children ) || count( $children ) > 50 ) {
			return $this->error( 'digitalogic_patris_topology_plan_invalid', 'The reviewed topology plan is invalid.' );
		}
		$normalized = array();
		foreach ( $children as $child_id => $code ) {
			$child_id = absint( $child_id );
			if ( $child_id <= 0 || isset( $normalized[ $child_id ] ) || ! is_string( $code ) ) {
				return $this->error( 'digitalogic_patris_topology_plan_invalid', 'The reviewed topology plan is invalid.' );
			}
			$normalized[ $child_id ] = trim( $code );
		}
		ksort( $normalized, SORT_NUMERIC );

		return $normalized;
	}

	/**
	 * Prove the exact current source, parent, child, owner, and attribute facts.
	 *
	 * @param array $plan Normalized topology plan.
	 * @return array|WP_Error
	 */
	private function inspect_plan( $plan ) {
		$source = Digitalogic_Product_Sync_Receiver::instance()->get_source_state(
			$plan['source']['id'],
			$plan['source']['dataset']
		);
		if (
			! is_array( $source )
			|| ! hash_equals( $plan['source']['revision'], (string) ( $source['source']['revision'] ?? '' ) )
		) {
			return $this->error( 'digitalogic_patris_topology_source_changed', 'The source revision changed before topology repair.' );
		}

		$source_products = is_array( $source['products'] ?? null ) ? $source['products'] : array();
		foreach ( $plan['empty_parents'] as $parent_id => $children ) {
			$valid = $this->inspect_parent( $parent_id, $children, '', array(), $source_products );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$identity = $plan['identity_parent'];
		$valid    = $this->inspect_parent(
			$identity['parent_id'],
			$identity['children'],
			$identity['product_code'],
			$identity,
			$source_products
		);
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return array(
			'schema'                => self::SCHEMA,
			'mode'                  => 'dry_run',
			'source_revision'       => $plan['source']['revision'],
			'empty_parent_count'    => count( $plan['empty_parents'] ),
			'identity_parent_id'    => $identity['parent_id'],
			'identity_product_code' => $identity['product_code'],
			'locked_product_count'  => count( $plan['locked_product_ids'] ),
			'new_base_variation_id' => null,
		);
	}

	/**
	 * Inspect one exact simple parent and its complete raw variation child map.
	 *
	 * @param int    $parent_id      Exact parent product ID.
	 * @param array  $children       Complete child-ID-to-Code map.
	 * @param string $parent_code    Expected parent Code and SKU, or blank.
	 * @param array  $identity       Reviewed identity-parent attribute facts.
	 * @param array  $source_products Exact source product records.
	 * @return true|WP_Error
	 */
	private function inspect_parent( $parent_id, $children, $parent_code, $identity, $source_products ) {
		$product = wc_get_product( $parent_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) || 0 !== $product->get_parent_id() ) {
			return $this->error( 'digitalogic_patris_topology_parent_changed', 'A reviewed legacy parent changed before repair.' );
		}
		$actual_children = $this->read_child_map( $parent_id );
		if ( is_wp_error( $actual_children ) || $actual_children !== $children ) {
			return $this->error( 'digitalogic_patris_topology_children_changed', 'A reviewed legacy child set changed before repair.' );
		}

		$readback = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $parent_id );
		if ( is_wp_error( $readback ) ) {
			return $readback;
		}
		$owner_count = array_sum( array_map( 'intval', (array) ( $readback['owner_row_counts'] ?? array() ) ) );
		if ( 0 !== $owner_count || ! empty( $readback['duplicate_rows'] ) || ! empty( $readback['invalid_key_rows'] ) ) {
			return $this->error( 'digitalogic_patris_topology_parent_identity_changed', 'A reviewed legacy parent identity changed before repair.' );
		}
		$stored_code = ! empty( $readback['meta_exists'] ) ? (string) ( $readback['product_code'] ?? '' ) : '';
		if (
			( '' === $parent_code && ! empty( $readback['meta_exists'] ) )
			|| ( '' !== $parent_code && empty( $readback['meta_exists'] ) )
			|| $stored_code !== $parent_code
			|| (string) $product->get_sku() !== $parent_code
		) {
			return $this->error( 'digitalogic_patris_topology_parent_identity_changed', 'A reviewed legacy parent identity changed before repair.' );
		}

		foreach ( $children as $child_id => $code ) {
			$child = wc_get_product( $child_id );
			if ( ! $child instanceof WC_Product || ! $child->is_type( 'variation' ) || (int) $child->get_parent_id() !== (int) $parent_id ) {
				return $this->error( 'digitalogic_patris_topology_children_changed', 'A reviewed legacy child set changed before repair.' );
			}
			if ( '' !== $code ) {
				$valid = $this->source_code_targets( $code, $child_id, $source_products );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
			}
		}

		if ( '' === $parent_code ) {
			return true;
		}
		$valid = $this->source_code_targets( $parent_code, $parent_id, $source_products );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$taxonomy  = $identity['attribute_taxonomy'];
		$attribute = $product->get_attributes()[ $taxonomy ] ?? null;
		$options   = $attribute instanceof WC_Product_Attribute ? array_map( 'absint', $attribute->get_options() ) : array();
		sort( $options, SORT_NUMERIC );
		if (
			! $attribute instanceof WC_Product_Attribute
			|| ! $attribute->get_variation()
			|| $options !== $identity['current_term_ids']
			|| term_exists( $identity['base_term_name'], $taxonomy )
			|| get_term_by( 'slug', $identity['base_term_slug'], $taxonomy )
		) {
			return $this->error( 'digitalogic_patris_topology_attribute_changed', 'The reviewed legacy variation attribute changed before repair.' );
		}

		return true;
	}

	/**
	 * Read every raw variation child and exact canonical Code.
	 *
	 * @param int $parent_id Exact parent product ID.
	 * @return array|WP_Error
	 */
	private function read_child_map( $parent_id ) {
		$children = array();
		$ids      = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' ),
				'post_parent'    => $parent_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'cache_results'  => false,
			)
		);
		if ( ! is_array( $ids ) ) {
			return $this->error( 'digitalogic_patris_topology_children_unavailable', 'The reviewed legacy child set is unavailable.' );
		}
		foreach ( $ids as $child_id ) {
			$post = get_post( $child_id );
			if ( $post && (int) $post->post_parent === (int) $parent_id ) {
				$children[ (int) $child_id ] = (string) get_post_meta(
					$child_id,
					Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META,
					true
				);
			}
		}
		ksort( $children, SORT_NUMERIC );

		return $children;
	}

	/**
	 * Require one exact source row and one exact resolver target.
	 *
	 * @param string $code            Exact source Product Code.
	 * @param int    $expected_id     Expected WooCommerce target ID.
	 * @param array  $source_products Exact source product records.
	 * @return true|WP_Error
	 */
	private function source_code_targets( $code, $expected_id, $source_products ) {
		$record = null;
		foreach ( $source_products as $key => $candidate ) {
			$candidate_code = is_array( $candidate ) ? (string) ( $candidate['product_code'] ?? $key ) : '';
			if ( hash_equals( $code, $candidate_code ) ) {
				$record = $candidate;
				break;
			}
		}
		$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'patris_code' => $code ) );
		if ( ! is_array( $record ) || is_wp_error( $resolved ) || (int) ( $resolved['woocommerce_id'] ?? 0 ) !== (int) $expected_id ) {
			return $this->error( 'digitalogic_patris_topology_source_identity_changed', 'A reviewed source identity changed before repair.' );
		}

		return true;
	}

	/**
	 * Apply all reviewed changes inside one database transaction.
	 *
	 * @param array $plan Normalized topology plan.
	 * @return array|WP_Error
	 * @throws RuntimeException When a transactional write or readback fails.
	 */
	private function apply_transaction( $plan ) {
		global $wpdb;
		if ( ! $this->repair_locks_are_owned( $plan['locked_product_ids'] ) ) {
			return $this->error( 'digitalogic_patris_topology_lock_lost', 'The reviewed topology locks were lost before repair.' );
		}
		$parent_ids  = array_merge(
			array_keys( $plan['empty_parents'] ),
			array( $plan['identity_parent']['parent_id'] )
		);
		$cache_fence = $this->fence_topology_relationship_caches(
			$parent_ids,
			$plan['identity_parent']['attribute_taxonomy']
		);
		if ( is_wp_error( $cache_fence ) ) {
			return $cache_fence;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact topology repair requires an explicit atomic transaction.
		if ( ! is_object( $wpdb ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->error( 'digitalogic_patris_topology_transaction_unavailable', 'The topology repair transaction is unavailable.' );
		}

		$new_variation_id       = 0;
		$term_id                = 0;
		$cause                  = '';
		$commit_attempted       = false;
		$deferred_sync_snapshot = $this->snapshot_deferred_product_sync();
		try {
			foreach ( array_keys( $plan['empty_parents'] ) as $parent_id ) {
				$this->require_success( wp_set_object_terms( $parent_id, 'variable', 'product_type' ) );
			}

			$identity     = $plan['identity_parent'];
			$parent_id    = $identity['parent_id'];
			$product_code = $identity['product_code'];
			$taxonomy     = $identity['attribute_taxonomy'];
			$inserted     = wp_insert_term(
				$identity['base_term_name'],
				$taxonomy,
				array( 'slug' => $identity['base_term_slug'] )
			);
			$this->require_success( $inserted );
			$term_id = absint( $inserted['term_id'] ?? 0 );
			if ( $term_id <= 0 ) {
				throw new RuntimeException( 'term_readback_failed' );
			}

			$parent = wc_get_product( $parent_id );
			$parent->set_sku( '' );
			$parent->delete_meta_data( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META );
			$cleared = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'materializer',
				array(
					'product_id' => $parent_id,
					'operation'  => 'delete',
				),
				static function () use ( $parent, $parent_id ) {
					$saved = $parent->save();
					if ( is_wp_error( $saved ) || ! $saved ) {
						return $saved;
					}

					// WooCommerce can omit an unloaded custom-meta deletion from the
					// product object's pending changes. Delete the exact guarded row
					// explicitly; the database readback below is authoritative when the
					// row was already absent and delete_post_meta() returns false.
					delete_post_meta( $parent_id, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META );

					return $saved;
				}
			);
			$this->require_success( $cleared );
			$this->require_success( wp_set_object_terms( $parent_id, 'variable', 'product_type' ) );
			$this->require_success( $this->flush_products( array( $parent_id ) ) );
			$released_identity = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $parent_id );
			if (
				is_wp_error( $released_identity )
				|| empty( $released_identity['product_exists'] )
				|| ! empty( $released_identity['meta_exists'] )
				|| ! empty( $released_identity['duplicate_rows'] )
				|| ! empty( $released_identity['invalid_key_rows'] )
			) {
				throw new RuntimeException( 'parent_identity_clear_failed' );
			}
			$this->require_success( Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( 0, $product_code ) );

			$parent = new WC_Product_Variable( $parent_id );
			if ( '' !== (string) $parent->get_sku( 'edit' ) ) {
				throw new RuntimeException( 'parent_sku_clear_failed' );
			}
			$attributes = $parent->get_attributes();
			$attribute  = $attributes[ $taxonomy ] ?? null;
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				throw new RuntimeException( 'attribute_readback_failed' );
			}
			$options = array_values( array_unique( array_merge( array_map( 'absint', $attribute->get_options() ), array( $term_id ) ) ) );
			sort( $options, SORT_NUMERIC );
			$attribute->set_options( $options );
			$attribute->set_variation( true );
			$attributes[ $taxonomy ] = $attribute;
			$parent->set_attributes( $attributes );
			$this->require_success( $parent->save() );
			$this->require_success( wp_set_object_terms( $parent_id, $options, $taxonomy, false ) );
			$stored_options = $this->read_taxonomy_term_ids( $parent_id, $taxonomy );
			if ( is_wp_error( $stored_options ) || $options !== $stored_options ) {
				throw new RuntimeException( 'parent_attribute_terms_write_failed' );
			}

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_name( $identity['base_term_name'] );
			$variation->set_status( 'draft' );
			$variation->set_attributes( array( $taxonomy => $identity['base_term_slug'] ) );
			$this->require_success( $variation->save() );
			$new_variation_id = (int) $variation->get_id();
			if ( $new_variation_id <= max( $plan['locked_product_ids'] ) ) {
				throw new RuntimeException( 'variation_identity_invalid' );
			}

			$created = Digitalogic_Product_Write_Lock::instance()->with_product_lock(
				$new_variation_id,
				function () use ( $new_variation_id, $product_code ) {
					$product = wc_get_product( $new_variation_id );
					if ( ! $product instanceof WC_Product || ! $product->is_type( 'variation' ) ) {
						return $this->error( 'digitalogic_patris_topology_variation_unavailable', 'The reviewed base variation is unavailable.' );
					}
					$product->set_sku( $product_code );
					$product->update_meta_data( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, $product_code );
					$written = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
						'materializer',
						array(
							'product_id' => $new_variation_id,
							'operation'  => 'set',
							'value'      => $product_code,
						),
						static function () use ( $product ) {
							return $product->save();
						}
					);
					if ( is_wp_error( $written ) || ! $written ) {
						return is_wp_error( $written ) ? $written : $this->error( 'digitalogic_patris_topology_variation_write_failed', 'The reviewed base variation could not be created.' );
					}

					return Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_write( $new_variation_id, $product_code );
				},
				0
			);
			$this->require_success( $created );

			$this->require_success( $this->flush_products( array_merge( $plan['locked_product_ids'], array( $new_variation_id ) ) ) );
			$this->require_success( $this->flush_topology_term_caches( $parent_ids, $term_id, $taxonomy ) );
			$verified = $this->verify_applied( $plan, $new_variation_id, $term_id );
			$this->require_success( $verified );
			if ( ! $this->repair_locks_are_owned( $plan['locked_product_ids'] ) ) {
				throw new RuntimeException( 'lock_lost' );
			}
			$commit_attempted = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commit the exact reviewed transaction before releasing its locks.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'commit_failed' );
			}
			$this->restore_deferred_product_sync( $deferred_sync_snapshot );
		} catch ( Throwable $exception ) {
			$cause = $exception->getMessage();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Roll back every reviewed topology write on any failure.
			$rollback = $wpdb->query( 'ROLLBACK' );
			$this->restore_deferred_product_sync( $deferred_sync_snapshot );
			$rollback_cache      = $this->flush_products( array_merge( $plan['locked_product_ids'], array_filter( array( $new_variation_id ) ) ) );
			$rollback_term_cache = $this->flush_topology_term_caches(
				$parent_ids,
				$term_id,
				$plan['identity_parent']['attribute_taxonomy']
			);
			if ( $commit_attempted || false === $rollback || is_wp_error( $rollback_cache ) || is_wp_error( $rollback_term_cache ) || 'lock_lost' === $cause ) {
				return $this->error(
					'digitalogic_patris_topology_outcome_unknown',
					'The reviewed topology repair requires exact audit before any retry.',
					array(
						'cause'               => $cause,
						'rollback_cache'      => is_wp_error( $rollback_cache ) ? $rollback_cache->get_error_code() : '',
						'rollback_term_cache' => is_wp_error( $rollback_term_cache ) ? $rollback_term_cache->get_error_code() : '',
						'commit_attempted'    => $commit_attempted,
						'rollback_attempted'  => true,
						'rollback_confirmed'  => false !== $rollback,
					)
				);
			}

			return $this->error(
				'digitalogic_patris_topology_repair_failed',
				'The reviewed topology repair was rolled back.',
				array(
					'cause'              => $cause,
					'rollback_attempted' => true,
				)
			);
		}

		return array(
			'schema'                => self::SCHEMA,
			'mode'                  => 'apply',
			'source_revision'       => $plan['source']['revision'],
			'empty_parent_count'    => count( $plan['empty_parents'] ),
			'identity_parent_id'    => $plan['identity_parent']['parent_id'],
			'identity_product_code' => $plan['identity_parent']['product_code'],
			'new_base_variation_id' => $new_variation_id,
		);
	}

	/**
	 * Verify every post-transaction parent, child, identity, and attribute fact.
	 *
	 * @param array $plan             Normalized topology plan.
	 * @param int   $new_variation_id Created base variation ID.
	 * @param int   $term_id          Created base attribute term ID.
	 * @return true|WP_Error
	 */
	private function verify_applied( $plan, $new_variation_id, $term_id ) {
		$materializer = Digitalogic_Patris_Catalog_Materializer::instance();
		foreach ( $plan['empty_parents'] as $parent_id => $children ) {
			$parent = wc_get_product( $parent_id );
			$actual = $this->read_child_map( $parent_id );
			if ( ! $parent instanceof WC_Product ) {
				return $this->readback_failure( 'empty_parent_unavailable', $parent_id );
			}
			if ( ! $parent->is_type( 'variable' ) ) {
				return $this->readback_failure( 'empty_parent_type', $parent_id );
			}
			if ( is_wp_error( $actual ) || $actual !== $children ) {
				return $this->readback_failure( 'empty_parent_children', $parent_id, $actual );
			}
			foreach ( $children as $child_id => $code ) {
				$valid = '' !== $code ? $materializer->validate_source_product_target( $child_id, $plan['source'] ) : true;
				if ( is_wp_error( $valid ) ) {
					return $this->readback_failure( 'empty_child_target', $child_id, $valid );
				}
			}
		}

		$identity                               = $plan['identity_parent'];
		$parent                                 = wc_get_product( $identity['parent_id'] );
		$child                                  = wc_get_product( $new_variation_id );
		$resolved                               = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'patris_code' => $identity['product_code'] ) );
		$actual_children                        = $this->read_child_map( $identity['parent_id'] );
		$expected_children                      = $identity['children'];
		$expected_children[ $new_variation_id ] = $identity['product_code'];
		ksort( $expected_children, SORT_NUMERIC );
		$term           = get_term( $term_id, $identity['attribute_taxonomy'] );
		$attribute      = $parent instanceof WC_Product ? ( $parent->get_attributes()[ $identity['attribute_taxonomy'] ] ?? null ) : null;
		$options        = $attribute instanceof WC_Product_Attribute ? array_map( 'absint', $attribute->get_options() ) : array();
		$stored_options = $this->read_taxonomy_term_ids( $identity['parent_id'], $identity['attribute_taxonomy'] );
		if ( ! $parent instanceof WC_Product ) {
			return $this->readback_failure( 'identity_parent_unavailable', $identity['parent_id'] );
		}
		if ( ! $parent->is_type( 'variable' ) ) {
			return $this->readback_failure( 'identity_parent_type', $identity['parent_id'] );
		}
		if ( '' !== (string) $parent->get_sku() ) {
			return $this->readback_failure( 'identity_parent_sku_not_cleared', $identity['parent_id'] );
		}
		if ( '' !== (string) $parent->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) ) {
			return $this->readback_failure( 'identity_parent_code_not_cleared', $identity['parent_id'] );
		}
		if ( ! $child instanceof WC_Product ) {
			return $this->readback_failure( 'identity_variation_unavailable', $new_variation_id );
		}
		if ( ! $child->is_type( 'variation' ) ) {
			return $this->readback_failure( 'identity_variation_type', $new_variation_id );
		}
		if ( (int) $child->get_parent_id() !== (int) $identity['parent_id'] ) {
			return $this->readback_failure( 'identity_variation_parent', $new_variation_id );
		}
		if ( is_wp_error( $actual_children ) || $actual_children !== $expected_children ) {
			return $this->readback_failure( 'identity_children', $identity['parent_id'], $actual_children );
		}
		if ( 'draft' !== (string) $child->get_status() ) {
			return $this->readback_failure( 'identity_variation_status', $new_variation_id );
		}
		if ( (string) $child->get_sku() !== $identity['product_code'] ) {
			return $this->readback_failure( 'identity_variation_sku', $new_variation_id );
		}
		if ( (string) ( $child->get_variation_attributes()[ 'attribute_' . $identity['attribute_taxonomy'] ] ?? '' ) !== $identity['base_term_slug'] ) {
			return $this->readback_failure( 'identity_variation_attribute', $new_variation_id );
		}
		if ( is_wp_error( $resolved ) || (int) ( $resolved['woocommerce_id'] ?? 0 ) !== $new_variation_id ) {
			return $this->readback_failure( 'identity_resolver', $new_variation_id, $resolved );
		}
		if (
			is_wp_error( $term )
			|| ! is_object( $term )
			|| (string) $term->slug !== $identity['base_term_slug']
			|| (string) $term->name !== $identity['base_term_name']
		) {
			return $this->readback_failure( 'identity_term', $identity['parent_id'], $term );
		}
		if ( ! in_array( $term_id, $options, true ) ) {
			return $this->readback_failure( 'identity_parent_attribute', $identity['parent_id'] );
		}
		if ( is_wp_error( $stored_options ) || ! in_array( $term_id, $stored_options, true ) ) {
			return $this->readback_failure( 'identity_parent_attribute_storage', $identity['parent_id'], $stored_options );
		}
		$valid = $materializer->validate_source_product_target( $new_variation_id, $plan['source'] );
		if ( is_wp_error( $valid ) ) {
			return $this->readback_failure( 'identity_variation_target', $new_variation_id, $valid );
		}
		foreach ( $identity['children'] as $child_id => $code ) {
			$valid = '' !== $code ? $materializer->validate_source_product_target( $child_id, $plan['source'] ) : true;
			if ( is_wp_error( $valid ) ) {
				return $this->readback_failure( 'identity_child_target', $child_id, $valid );
			}
		}

		return true;
	}

	/**
	 * Acquire every existing product lock in deterministic numeric order.
	 *
	 * @param int[]    $product_ids Exact existing product IDs.
	 * @param callable $callback    Bounded repair callback.
	 * @return mixed
	 */
	private function with_product_locks( $product_ids, $callback ) {
		$wrapped = $callback;
		foreach ( array_reverse( $product_ids ) as $product_id ) {
			$next    = $wrapped;
			$wrapped = static function () use ( $product_id, $next ) {
				return Digitalogic_Product_Write_Lock::instance()->with_product_lock( $product_id, $next, 0 );
			};
		}

		return call_user_func( $wrapped );
	}

	/**
	 * Confirm the source lock and every reviewed existing-product lock.
	 *
	 * @param int[] $product_ids Exact existing product IDs.
	 * @return bool
	 */
	private function repair_locks_are_owned( $product_ids ) {
		if ( ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() ) {
			return false;
		}
		$locks = Digitalogic_Product_Write_Lock::instance();
		foreach ( $product_ids as $product_id ) {
			if ( ! $locks->is_owned( $product_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Convert a WP_Error/false result into one transactional exception.
	 *
	 * @param mixed $result Transactional operation result.
	 * @return void
	 * @throws RuntimeException When the operation did not succeed.
	 */
	private function require_success( $result ) {
		if ( is_wp_error( $result ) ) {
			$data  = $result->get_error_data();
			$parts = array( $result->get_error_code() );
			if ( is_array( $data ) && ! empty( $data['cause'] ) ) {
				$parts[] = sanitize_key( (string) $data['cause'] );
			}
			if ( is_array( $data ) && ! empty( $data['product_id'] ) ) {
				$parts[] = (string) absint( $data['product_id'] );
			}
			if ( is_array( $data ) && ! empty( $data['validation_code'] ) ) {
				$parts[] = sanitize_key( (string) $data['validation_code'] );
			}
			throw new RuntimeException( implode( ':', $parts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal stable error codes, caught and never rendered.
		}
		if ( false === $result || null === $result || 0 === $result ) {
			throw new RuntimeException( 'write_rejected' );
		}
	}

	/**
	 * Flush exact product and metadata caches after commit or rollback.
	 *
	 * @param int[] $product_ids Exact product IDs.
	 * @return true|WP_Error
	 */
	private function flush_products( $product_ids ) {
		foreach ( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) as $product_id ) {
			$instance_cache = $this->remove_product_instance_cache( $product_id );
			if ( is_wp_error( $instance_cache ) ) {
				return $instance_cache;
			}
			wp_cache_delete( $product_id, 'post_meta' );
			clean_post_cache( $product_id );
			wc_delete_product_transients( $product_id );
			if ( ! class_exists( 'WC_Cache_Helper' ) || ! is_callable( array( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) ) {
				return $this->error( 'digitalogic_patris_topology_cache_unavailable', 'WooCommerce cache-prefix invalidation is unavailable.' );
			}
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
		}

		return true;
	}

	/**
	 * Remove WooCommerce's optional product-instance cache entry.
	 *
	 * @param int $product_id Exact product ID.
	 * @return true|WP_Error
	 */
	private function remove_product_instance_cache( $product_id ) {
		$class = 'Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
		if ( ! class_exists( $class ) || ! function_exists( 'wc_get_container' ) ) {
			return true;
		}

		try {
			$cache = wc_get_container()->get( $class );
			if ( ! is_object( $cache ) || ! is_callable( array( $cache, 'remove' ) ) ) {
				return $this->error( 'digitalogic_patris_topology_cache_unavailable', 'WooCommerce product-instance cache invalidation is unavailable.' );
			}
			$cache->remove( (int) $product_id );
		} catch ( Throwable $error ) {
			unset( $error );

			return $this->error( 'digitalogic_patris_topology_cache_unavailable', 'WooCommerce product-instance cache invalidation failed.' );
		}

		return true;
	}

	/** Snapshot WooCommerce's request-local deferred parent sync queue. */
	private function snapshot_deferred_product_sync() {
		return array(
			'exists' => array_key_exists( 'wc_deferred_product_sync', $GLOBALS ),
			'value'  => $GLOBALS['wc_deferred_product_sync'] ?? null,
		);
	}

	/**
	 * Restore the exact pre-transaction deferred queue after rollback.
	 *
	 * @param array $snapshot Exact request-local queue snapshot.
	 * @return void
	 */
	private function restore_deferred_product_sync( $snapshot ) {
		if ( ! empty( $snapshot['exists'] ) ) {
			$GLOBALS['wc_deferred_product_sync'] = $snapshot['value'] ?? null;
			return;
		}

		unset( $GLOBALS['wc_deferred_product_sync'] );
	}

	/**
	 * Remove persistent relationship entries, then keep the exact groups
	 * request-local so a deferred cache write cannot escape the transaction.
	 *
	 * @param int[]  $parent_ids Exact reviewed parent IDs.
	 * @param string $taxonomy   Exact variation taxonomy.
	 * @return true|WP_Error
	 */
	private function fence_topology_relationship_caches( $parent_ids, $taxonomy ) {
		if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
			return $this->error( 'digitalogic_patris_topology_cache_unavailable', 'Request-local taxonomy relationship cache fencing is unavailable.' );
		}
		$cache_groups = $this->topology_relationship_cache_groups( $taxonomy );
		$cleared      = $this->delete_topology_relationship_caches( $parent_ids, $cache_groups );
		if ( is_wp_error( $cleared ) ) {
			return $cleared;
		}

		wp_cache_add_non_persistent_groups( $cache_groups );

		return $this->delete_topology_relationship_caches( $parent_ids, $cache_groups );
	}

	/**
	 * Return every product relationship cache group relevant to the repair.
	 *
	 * @param string $taxonomy Exact variation taxonomy.
	 * @return string[]
	 */
	private function topology_relationship_cache_groups( $taxonomy ) {
		$taxonomies   = function_exists( 'get_object_taxonomies' )
			? (array) get_object_taxonomies( 'product', 'names' )
			: array();
		$taxonomies[] = 'product_type';
		if ( '' !== (string) $taxonomy ) {
			$taxonomies[] = (string) $taxonomy;
		}
		$taxonomies = array_values( array_unique( array_filter( $taxonomies, 'is_string' ) ) );

		return array_map(
			static function ( $relationship_taxonomy ) {
				return $relationship_taxonomy . '_relationships';
			},
			$taxonomies
		);
	}

	/**
	 * Delete and prove absence of exact relationship cache entries.
	 *
	 * @param int[]    $parent_ids  Exact reviewed parent IDs.
	 * @param string[] $cache_groups Exact relationship cache groups.
	 * @return true|WP_Error
	 */
	private function delete_topology_relationship_caches( $parent_ids, $cache_groups ) {
		$parent_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $parent_ids ) ) ) );
		foreach ( $parent_ids as $parent_id ) {
			foreach ( $cache_groups as $cache_group ) {
				wp_cache_delete( $parent_id, $cache_group );
				$found = false;
				wp_cache_get( $parent_id, $cache_group, true, $found );
				if ( $found ) {
					return $this->error( 'digitalogic_patris_topology_cache_unavailable', 'A reviewed taxonomy relationship cache could not be invalidated.' );
				}
			}
		}

		return true;
	}

	/**
	 * Read exact stored taxonomy term IDs without a relationship cache.
	 *
	 * @param int    $product_id Exact product ID.
	 * @param string $taxonomy   Exact taxonomy name.
	 * @return int[]|WP_Error
	 */
	private function read_taxonomy_term_ids( $product_id, $taxonomy ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->term_relationships, $wpdb->term_taxonomy ) || ! is_callable( array( $wpdb, 'get_col' ) ) ) {
			return $this->error( 'digitalogic_patris_topology_term_readback_failed', 'Exact taxonomy relationship readback is unavailable.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact transactional topology readback must bypass non-transactional object caches.
		$term_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT /* digitalogic_patris_topology_term_readback */ tt.term_id
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = %d AND tt.taxonomy = %s
				ORDER BY tt.term_id ASC",
				absint( $product_id ),
				(string) $taxonomy
			)
		);
		if ( ! is_array( $term_ids ) || '' !== (string) $wpdb->last_error ) {
			return $this->error( 'digitalogic_patris_topology_term_readback_failed', 'Exact taxonomy relationship readback failed.' );
		}

		return array_values( array_unique( array_map( 'absint', $term_ids ) ) );
	}

	/**
	 * Flush non-transactional object-term caches touched by topology writes.
	 *
	 * @param int[]  $parent_ids Exact reviewed parent IDs.
	 * @param int    $term_id Created term ID, or zero before insertion.
	 * @param string $taxonomy Exact attribute taxonomy.
	 * @return true|WP_Error
	 */
	private function flush_topology_term_caches( $parent_ids, $term_id, $taxonomy ) {
		$parent_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $parent_ids ) ) ) );
		if ( ! empty( $parent_ids ) ) {
			clean_object_term_cache( $parent_ids, 'product' );
		}
		if ( absint( $term_id ) > 0 && '' !== (string) $taxonomy ) {
			clean_term_cache( absint( $term_id ), (string) $taxonomy );
		}

		return $this->delete_topology_relationship_caches(
			$parent_ids,
			$this->topology_relationship_cache_groups( $taxonomy )
		);
	}

	/**
	 * Return one stable, nonsecret exact-readback reason.
	 *
	 * @param string         $cause Stable predicate name.
	 * @param int            $product_id Exact affected product ID, or zero.
	 * @param WP_Error|mixed $validation Optional nested validation failure.
	 * @return WP_Error
	 */
	private function readback_failure( $cause, $product_id = 0, $validation = null ) {
		$data = array(
			'cause'      => sanitize_key( (string) $cause ),
			'product_id' => absint( $product_id ),
		);
		if ( is_wp_error( $validation ) ) {
			$data['validation_code'] = sanitize_key( $validation->get_error_code() );
		}

		return $this->error(
			'digitalogic_patris_topology_readback_failed',
			'The reviewed topology repair failed exact readback.',
			$data
		);
	}

	/**
	 * Build one stable repair error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Nonsecret error message.
	 * @param array  $data    Additional nonsecret error data.
	 * @return WP_Error
	 */
	private function error( $code, $message, $data = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status'    => 409,
					'retryable' => false,
				),
				(array) $data
			)
		);
	}
}
