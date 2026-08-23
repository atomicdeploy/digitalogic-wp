<?php
/**
 * Audited canonical Product Code editor.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs one bounded, revision-checked Product Code edit.
 *
 * The canonical code remains owned by an active product-sync source. This
 * service only edits WooCommerce-only/unmanaged identities; a source-backed
 * row must be corrected upstream and delivered through the normal sync path.
 */
final class Digitalogic_Product_Code_Editor {

	public const SCHEMA   = 'digitalogic.product-code-edit';
	public const META_KEY = '_digitalogic_patris_product_code';

	private const GOVERNANCE_SCHEMA           = 'digitalogic.product-code-governance';
	private const RECONCILIATION_SCHEMA       = 'digitalogic.product-code-reconciliation';
	private const RESERVATION_RELEASE_SCHEMA  = 'digitalogic.product-code-reservation-release';
	private const AUDIT_OPTION                = 'digitalogic_product_code_edit_operations';
	private const OPERATION_OPTION_STEM       = 'digitalogic_product_code_edit_';
	private const RECOVERY_OPTION_STEM        = 'digitalogic_product_code_recovery_';
	private const LEGACY_FEED_PRODUCTS_OPTION = 'digitalogic_patris_feed_products';
	private const OWNER_SOURCE_META           = '_digitalogic_patris_owner_source_id';
	private const OWNER_DATASET_META          = '_digitalogic_patris_owner_dataset';
	private const OWNER_CODE_META             = '_digitalogic_patris_owner_product_code';
	private const MAX_AUDIT_RECORDS           = 1024;
	private const MAX_CODE_BYTES              = 191;
	private const REQUEST_ID_PATTERN          = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D';
	private const REVISION_PATTERN            = '/\Asha256:[a-f0-9]{64}\z/D';
	private const RECORD_HASH_PATTERN         = '/\Asha256:[a-f0-9]{64}\z/D';

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Cache-bypassed source state used only for one admin response.
	 *
	 * @var array|WP_Error|null
	 */
	private $editability_source_state = null;

	/**
	 * Whether the admin-response source-state cache has been populated.
	 *
	 * @var bool
	 */
	private $editability_source_state_loaded = false;

	/**
	 * Request-scoped O(1) source ownership index.
	 *
	 * @var array|WP_Error|null
	 */
	private $editability_source_index = null;

	/**
	 * Whether the request-scoped source ownership index was built.
	 *
	 * @var bool
	 */
	private $editability_source_index_loaded = false;

	/**
	 * Number of full source-index builds in the current admin batch.
	 *
	 * @var int
	 */
	private $editability_source_index_builds = 0;

	/**
	 * Cache-bypassed recovery pointers prefetched for admin rows.
	 *
	 * @var array<int,array|WP_Error>
	 */
	private $editability_recovery_pointers = array();

	/**
	 * Cache-bypassed operation records prefetched for pending rows.
	 *
	 * @var array<string,array|WP_Error>
	 */
	private $editability_recovery_operations = array();

	/**
	 * Exact product IDs authorized for the current admin read batch.
	 *
	 * @var array<int,bool>
	 */
	private $editability_prepared_ids = array();

	/**
	 * Exact metadata/provenance rows for the current admin batch.
	 *
	 * @var array<int,array|WP_Error>
	 */
	private $editability_readbacks = array();

	/**
	 * Actor bound to the current admin read batch.
	 *
	 * @var int
	 */
	private $editability_actor_id = 0;

	/**
	 * Blog bound to the current admin read batch.
	 *
	 * @var int
	 */
	private $editability_blog_id = 0;

	/**
	 * Shared fail-closed batch preparation error.
	 *
	 * @var WP_Error|null
	 */
	private $editability_batch_error = null;

	/**
	 * Recovery-prefetch failure, isolated from source/readback health.
	 *
	 * @var WP_Error|null
	 */
	private $editability_recovery_error = null;

	/**
	 * Active site-wide operation lock name for mid-effect verification.
	 *
	 * @var string
	 */
	private $active_operation_lock_name = '';

	/**
	 * Active operation-lock connection ID.
	 *
	 * @var int
	 */
	private $active_operation_connection_id = 0;

	/**
	 * Exact receiver instance owning the active source lock.
	 *
	 * @var Digitalogic_Product_Sync_Receiver|null
	 */
	private $active_source_lock_receiver = null;

	/** Return the shared editor. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Prevent external construction. */
	private function __construct() {}

	/**
	 * Build the optimistic-concurrency revision for one exact code value.
	 *
	 * @param int    $product_id WooCommerce product or variation ID.
	 * @param string $product_code Exact code, including any leading zeroes.
	 * @return string
	 */
	public function revision_for( $product_id, $product_code ) {
		$material = array(
			'schema'       => self::SCHEMA,
			'product_id'   => (string) absint( $product_id ),
			'product_code' => (string) $product_code,
		);

		$json = $this->canonical_json( $material );
		return is_wp_error( $json ) ? '' : 'sha256:' . hash( 'sha256', $json );
	}

	/**
	 * Verify one canonical source writer from uncached database state.
	 *
	 * Source adapters call this before releasing the shared identity lock. It
	 * never writes, refreshes, or delivers catalog state.
	 *
	 * @return true|WP_Error
	 */
	public function verify_canonical_source_write( $product_id, $product_code ) {
		$product_id   = absint( $product_id );
		$product_code = $this->normalize_code( $product_code, false );
		if ( is_wp_error( $product_code ) ) {
			return $product_code;
		}
		if (
			$product_id <= 0
			|| ! class_exists( 'Digitalogic_Product_Sync_Receiver' )
			|| ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
		) {
			return $this->error(
				'digitalogic_product_code_source_verification_unavailable',
				__( 'The exact source Product Code readback requires the shared identity lock.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		$readback = $this->read_exact_product_code( $product_id );
		if ( is_wp_error( $readback ) || ! $this->readback_matches( $readback, $product_code ) ) {
			return $this->error(
				'digitalogic_product_code_source_readback_failed',
				__( 'The source Product Code write did not pass exact database readback.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		$conflicts = $this->find_code_conflicts( $product_id, $product_code );
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		if ( ! empty( $conflicts ) ) {
			return $this->error(
				'digitalogic_product_code_source_not_unique',
				__( 'The source Product Code readback conflicts with another product or variation.', 'digitalogic' ),
				409,
				array( 'woocommerce_ids' => array_column( $conflicts, 'ID' ) )
			);
		}

		return true;
	}

	/**
	 * Fail closed before a canonical source writer changes any product state.
	 *
	 * Unlike the general identifier resolver, this exact database check treats a
	 * trashed product or variation as the continuing owner of its Product Code
	 * until WordPress permanently deletes it. Callers must retain the shared
	 * source-identity lock from this check through their write and readback.
	 *
	 * @param int    $product_id Target product ID, or zero before creating a draft.
	 * @param string $product_code Desired canonical Product Code.
	 * @return true|WP_Error
	 */
	public function preflight_canonical_source_write( $product_id, $product_code ) {
		$product_id   = absint( $product_id );
		$product_code = $this->normalize_code( $product_code, false );
		if ( is_wp_error( $product_code ) ) {
			return $product_code;
		}
		if (
			! class_exists( 'Digitalogic_Product_Sync_Receiver' )
			|| ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
		) {
			return $this->error(
				'digitalogic_product_code_source_preflight_unavailable',
				__( 'The exact source Product Code preflight requires the shared identity lock.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		$conflicts = $this->find_code_conflicts( $product_id, $product_code );
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		if ( ! empty( $conflicts ) ) {
			return $this->error(
				'digitalogic_product_code_source_not_unique',
				__( 'The source Product Code belongs to another product or variation, including one in Trash.', 'digitalogic' ),
				409,
				array(
					'conflicting_product_ids' => array_values( array_map( 'intval', array_column( $conflicts, 'ID' ) ) ),
				)
			);
		}

		return true;
	}

	/** Capture one exact, rollback-safe canonical state for a source writer. */
	public function canonical_source_backup( $product_id ) {
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
		) {
			return $this->error(
				'digitalogic_product_code_source_backup_unavailable',
				__( 'The exact source Product Code backup requires the shared identity lock.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		$readback = $this->read_exact_product_code( $product_id );
		if (
			is_wp_error( $readback )
			|| empty( $readback['product_exists'] )
			|| ! empty( $readback['duplicate_rows'] )
			|| ! empty( $readback['invalid_key_rows'] )
		) {
			return $this->error(
				'digitalogic_product_code_source_backup_conflict',
				__( 'The source Product Code cannot be backed up until its metadata conflict is reconciled.', 'digitalogic' ),
				409
			);
		}

		return array(
			'meta_exists'  => (bool) $readback['meta_exists'],
			'product_code' => (string) $readback['product_code'],
		);
	}

	/**
	 * Return exact cache-bypassed canonical and materializer provenance.
	 *
	 * This read-only surface never acquires a lock or mutates caches. Source
	 * writers must call it again while holding the shared source and product
	 * locks immediately before any effect.
	 */
	public function canonical_source_provenance_readback( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return $this->error(
				'digitalogic_product_code_source_provenance_invalid',
				__( 'An existing product or variation is required for exact Product Code provenance.', 'digitalogic' ),
				400
			);
		}

		return $this->read_exact_product_code( $product_id );
	}

	/** Verify that a source writer restored its exact prior canonical state. */
	public function verify_canonical_source_restore( $product_id, $backup ) {
		if ( ! is_array( $backup ) || ! array_key_exists( 'meta_exists', $backup ) || ! is_bool( $backup['meta_exists'] ) ) {
			return $this->error( 'digitalogic_product_code_source_restore_invalid', __( 'The source Product Code rollback data is invalid.', 'digitalogic' ), 409 );
		}
		$readback = $this->canonical_source_backup( $product_id );
		if (
			is_wp_error( $readback )
			|| $readback['meta_exists'] !== $backup['meta_exists']
			|| ! hash_equals( (string) ( $backup['product_code'] ?? '' ), $readback['product_code'] )
		) {
			return $this->error(
				'digitalogic_product_code_source_restore_failed',
				__( 'The source Product Code rollback did not pass exact database readback.', 'digitalogic' ),
				409
			);
		}

		return true;
	}

	/**
	 * Return fail-closed editability for one admin product row.
	 *
	 * This is a UI hint only. The mutation repeats every exact guard while the
	 * shared source/identity lock is held.
	 *
	 * @param int    $product_id Product or variation ID.
	 * @param string $product_code Product Code rendered by WooCommerce.
	 * @return array{editable:bool,reason:string,product_code:string,revision:string,meta_exists:bool,cache_mismatch:bool}
	 */
	public function editability_for( $product_id, $product_code ) {
		$product_id   = absint( $product_id );
		$product_code = (string) $product_code;
		$readback     = $this->admin_readback_for( $product_id );
		if ( is_wp_error( $readback ) || ! $readback['product_exists'] ) {
			return array(
				'editable'       => false,
				'reason'         => 'state_unavailable',
				'product_code'   => $product_code,
				'revision'       => $this->revision_for( $product_id, $product_code ),
				'meta_exists'    => false,
				'cache_mismatch' => false,
			);
		}
		$exact_code     = $readback['product_code'];
		$exact_revision = $this->revision_for( $product_id, $exact_code );
		$cache_mismatch = ! hash_equals( $product_code, $exact_code );
		if ( $cache_mismatch ) {
			$this->invalidate_product_identity_cache( $product_id );
		}
		if ( $readback['duplicate_rows'] || ! empty( $readback['invalid_key_rows'] ) ) {
			return array(
				'editable'       => false,
				'reason'         => 'metadata_conflict',
				'product_code'   => $exact_code,
				'revision'       => $exact_revision,
				'meta_exists'    => $readback['meta_exists'],
				'cache_mismatch' => $cache_mismatch,
			);
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_post', $product_id ) ) {
			return array(
				'editable'       => false,
				'reason'         => 'permission_denied',
				'product_code'   => $exact_code,
				'revision'       => $exact_revision,
				'meta_exists'    => $readback['meta_exists'],
				'cache_mismatch' => $cache_mismatch,
			);
		}

		if ( is_wp_error( $this->editability_source_state ) ) {
			return array(
				'editable'       => false,
				'reason'         => 'source_state_unavailable',
				'product_code'   => $exact_code,
				'revision'       => $exact_revision,
				'meta_exists'    => $readback['meta_exists'],
				'cache_mismatch' => $cache_mismatch,
			);
		}

		$provenance = $this->editability_provenance_status( $readback );
		if ( is_wp_error( $this->editability_source_index ) || is_wp_error( $provenance ) ) {
			return array(
				'editable'       => false,
				'reason'         => 'source_state_unavailable',
				'product_code'   => $exact_code,
				'revision'       => $exact_revision,
				'meta_exists'    => $readback['meta_exists'],
				'cache_mismatch' => $cache_mismatch,
			);
		}
		$code_key = hash( 'sha256', $exact_code );
		if (
			'managed' === $provenance
			|| ! empty( $this->editability_source_index['codes'][ $code_key ] )
			|| ! empty( $this->editability_source_index['product_ids'][ (string) $product_id ] )
		) {
			return array(
				'editable'       => false,
				'reason'         => 'source_managed',
				'product_code'   => $exact_code,
				'revision'       => $exact_revision,
				'meta_exists'    => $readback['meta_exists'],
				'cache_mismatch' => $cache_mismatch,
			);
		}

		return array(
			'editable'       => true,
			'reason'         => '',
			'product_code'   => $exact_code,
			'revision'       => $exact_revision,
			'meta_exists'    => $readback['meta_exists'],
			'cache_mismatch' => $cache_mismatch,
		);
	}

	/** Start a fresh bounded admin read batch. */
	public function reset_editability_cache() {
		$this->editability_source_state        = null;
		$this->editability_source_state_loaded = false;
		$this->editability_source_index        = null;
		$this->editability_source_index_loaded = false;
		$this->editability_source_index_builds = 0;
		$this->editability_recovery_pointers   = array();
		$this->editability_recovery_operations = array();
		$this->editability_prepared_ids        = array();
		$this->editability_readbacks           = array();
		$this->editability_actor_id            = 0;
		$this->editability_blog_id             = 0;
		$this->editability_batch_error         = null;
		$this->editability_recovery_error      = null;
	}

	/**
	 * Prepare one bounded, cache-bypassed admin read model.
	 *
	 * The source-governance index is retained across successive pages in the
	 * same actor/blog request. Row metadata and recovery state are replaced for
	 * every page, so later lookups are O(1) and cannot silently fall back to a
	 * per-row database/source scan. Mutation never consumes this UI-only state.
	 *
	 * @param int[] $product_ids Product and variation IDs rendered together.
	 * @return true|WP_Error
	 */
	public function prepare_admin_read_batch( $product_ids ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
		$actor_id    = get_current_user_id();
		$blog_id     = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( count( $product_ids ) > 101 ) {
			$error                         = $this->error(
				'digitalogic_product_code_admin_batch_too_large',
				__( 'The Product Code admin read batch exceeded its bounded size.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
			$this->editability_batch_error = $error;
			return $error;
		}

		if (
			$this->editability_source_index_loaded
			&& ( $this->editability_actor_id !== $actor_id || $this->editability_blog_id !== $blog_id )
		) {
			$this->reset_editability_cache();
		}

		$this->editability_actor_id            = $actor_id;
		$this->editability_blog_id             = $blog_id;
		$this->editability_prepared_ids        = array_fill_keys( $product_ids, true );
		$this->editability_readbacks           = array();
		$this->editability_recovery_pointers   = array();
		$this->editability_recovery_operations = array();
		$this->editability_batch_error         = null;
		$this->editability_recovery_error      = null;

		if ( empty( $product_ids ) ) {
			return true;
		}

		$readbacks = $this->read_exact_product_codes( $product_ids );
		if ( is_wp_error( $readbacks ) ) {
			$this->editability_batch_error = $readbacks;
		} else {
			$this->editability_readbacks = $readbacks;
		}

		if ( ! $this->editability_source_index_loaded ) {
			$this->editability_source_state        = $this->read_exact_source_state();
			$this->editability_source_state_loaded = true;
			$this->editability_source_index        = is_wp_error( $this->editability_source_state )
				? $this->editability_source_state
				: $this->build_editability_source_index( $this->editability_source_state );
			$this->editability_source_index_loaded = true;
		}
		if ( is_wp_error( $this->editability_source_index ) ) {
			if ( ! is_wp_error( $this->editability_batch_error ) ) {
				$this->editability_batch_error = $this->editability_source_index;
			}
		}

		$recovery = $this->prefetch_admin_recovery_batch( $product_ids );
		if ( is_wp_error( $recovery ) ) {
			$this->editability_recovery_error = $recovery;
		}

		if ( is_wp_error( $this->editability_batch_error ) ) {
			return $this->editability_batch_error;
		}
		return is_wp_error( $this->editability_recovery_error ) ? $this->editability_recovery_error : true;
	}

	/**
	 * Prefetch recovery pointers and operations in at most two DB reads.
	 *
	 * @param int[] $product_ids Prepared product and variation IDs.
	 * @return true|WP_Error
	 */
	private function prefetch_admin_recovery_batch( $product_ids ) {
		$names = array();
		foreach ( $product_ids as $product_id ) {
			$names[ $product_id ] = $this->recovery_option_name( $product_id );
		}

		$stored = $this->read_exact_options( array_values( $names ) );
		if ( is_wp_error( $stored ) ) {
			foreach ( array_keys( $names ) as $product_id ) {
				$this->editability_recovery_pointers[ $product_id ] = $stored;
			}
			return $stored;
		}

		$operation_names = array();
		foreach ( $names as $product_id => $option_name ) {
			$entry   = $stored[ $option_name ];
			$pointer = $entry['exists'] && is_array( $entry['value'] ) ? $entry['value'] : ( $entry['exists'] ? $this->audit_unavailable() : array() );
			$this->editability_recovery_pointers[ $product_id ] = $pointer;
			if ( empty( $pointer ) || is_wp_error( $pointer ) ) {
				continue;
			}
			$request = $this->request_from_recovery_index( $pointer, $product_id );
			if ( is_wp_error( $request ) ) {
				$this->editability_recovery_pointers[ $product_id ] = $request;
				continue;
			}
			$operation_names[ $request['request_id'] ] = $this->operation_option_name( $request['request_id'] );
		}
		if ( empty( $operation_names ) ) {
			return true;
		}

		$operations = $this->read_exact_options( array_values( $operation_names ) );
		if ( is_wp_error( $operations ) ) {
			foreach ( array_keys( $operation_names ) as $request_id ) {
				$this->editability_recovery_operations[ $request_id ] = $operations;
			}
			return $operations;
		}
		foreach ( $operation_names as $request_id => $option_name ) {
			$entry = $operations[ $option_name ];
			$this->editability_recovery_operations[ $request_id ] = $entry['exists'] && is_array( $entry['value'] )
				? $entry['value']
				: ( $entry['exists'] ? $this->audit_unavailable() : array() );
		}

		return true;
	}

	/**
	 * Return the current actor's bounded server-side recovery handoff.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|WP_Error Empty when no incomplete request exists.
	 */
	public function recovery_intent_for( $product_id ) {
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! current_user_can( 'manage_woocommerce' )
			|| ! current_user_can( 'edit_post', $product_id )
		) {
			return $this->error(
				'digitalogic_product_code_recovery_forbidden',
				__( 'You are not allowed to recover this Product Code edit.', 'digitalogic' ),
				403
			);
		}

		$context = $this->admin_read_context_error( $product_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( is_wp_error( $this->editability_recovery_error ) ) {
			return $this->editability_recovery_error;
		}

		$actor_id = get_current_user_id();
		$index    = $this->editability_recovery_pointers[ $product_id ] ?? array();
		if ( is_wp_error( $index ) || empty( $index ) ) {
			return $index;
		}
		$request = $this->request_from_recovery_index( $index, $product_id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		$operation = $this->editability_recovery_operations[ $request['request_id'] ] ?? array();
		$status    = is_array( $operation ) ? (string) ( $operation['status'] ?? '' ) : '';
		if ( is_wp_error( $operation ) ) {
			return $this->audit_unavailable();
		}
		if (
			! empty( $operation )
			&& (
				! $this->operation_record_is_valid( $operation, $request, $status )
				|| (int) ( $operation['actor_id'] ?? 0 ) !== (int) ( $index['actor_id'] ?? 0 )
			)
		) {
			return $this->audit_unavailable();
		}
		if ( in_array( $status, array( 'completed', 'reconciled_no_effect', 'reservation_released' ), true ) ) {
			return array();
		}
		$takeover_required = (int) ( $index['actor_id'] ?? 0 ) !== $actor_id;
		if ( empty( $operation ) && 'reservation_pending' !== (string) ( $index['status'] ?? '' ) ) {
			return $this->audit_unavailable();
		}
		if ( empty( $operation ) ) {
			$status = 'reservation_pending';
		}

		return array(
			'schema'              => self::SCHEMA,
			'status'              => $status,
			'product_id'          => $product_id,
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'request_id'          => $request['request_id'],
			'request_fingerprint' => $request['fingerprint'],
			'takeover_required'   => $takeover_required,
		);
	}

	/**
	 * Inspect or explicitly resolve one outcome-unknown operation without
	 * changing the canonical Product Code.
	 *
	 * Dry-run is the default. Apply requires the exact preview digest, record
	 * fingerprint, observed code/revision, and resolution returned by dry-run.
	 *
	 * @param mixed $payload Reconciliation manifest.
	 * @return array|WP_Error
	 */
	public function reconcile_outcome( $payload ) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_forbidden',
				__( 'You are not allowed to reconcile Product Code operations.', 'digitalogic' ),
				403
			);
		}
		if ( ! is_array( $payload ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_manifest_invalid',
				__( 'The Product Code reconciliation manifest must be an object.', 'digitalogic' ),
				400
			);
		}
		$product_id = is_int( $payload['product_id'] ?? null ) || is_string( $payload['product_id'] ?? null )
			? (string) $payload['product_id']
			: '';
		$request_id = is_string( $payload['request_id'] ?? null ) ? (string) $payload['request_id'] : '';
		if (
			1 !== preg_match( '/\A[1-9][0-9]*\z/D', $product_id )
			|| (string) absint( $product_id ) !== $product_id
			|| 1 !== preg_match( self::REQUEST_ID_PATTERN, $request_id )
		) {
			return $this->error(
				'digitalogic_product_code_reconciliation_identity_invalid',
				__( 'The Product Code reconciliation identity is invalid.', 'digitalogic' ),
				400
			);
		}
		$product_id = absint( $product_id );
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_object_forbidden',
				__( 'You are not allowed to reconcile this product or variation.', 'digitalogic' ),
				403
			);
		}
		$manifest = array(
			'product_id'            => $product_id,
			'request_id'            => $request_id,
			'apply'                 => true === ( $payload['apply'] ?? false ),
			'record_fingerprint'    => is_string( $payload['record_fingerprint'] ?? null ) ? (string) $payload['record_fingerprint'] : '',
			'observed_product_code' => is_string( $payload['observed_product_code'] ?? null ) ? (string) $payload['observed_product_code'] : '',
			'observed_revision'     => is_string( $payload['observed_revision'] ?? null ) ? (string) $payload['observed_revision'] : '',
			'resolution'            => is_string( $payload['resolution'] ?? null ) ? sanitize_key( $payload['resolution'] ) : '',
			'preview_digest'        => is_string( $payload['preview_digest'] ?? null ) ? (string) $payload['preview_digest'] : '',
		);

		return $this->with_source_identity_lock(
			function () use ( $manifest ) {
				return $this->with_operation_lock(
					function () use ( $manifest ) {
						return Digitalogic_Product_Write_Lock::instance()->with_product_lock(
							$manifest['product_id'],
							function () use ( $manifest ) {
								return $this->reconcile_outcome_locked( $manifest );
							},
							0
						);
					}
				);
			}
		);
	}

	/**
	 * Apply one explicit Product Code edit.
	 *
	 * Required payload fields are product_id, expected_code, product_code,
	 * if_match, and request_id. String-typed codes are required so leading
	 * zeroes cannot be lost before the service boundary.
	 *
	 * @param mixed $payload Command payload.
	 * @return array|WP_Error
	 */
	public function edit( $payload ) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->error(
				'digitalogic_product_code_forbidden',
				__( 'You are not allowed to edit Product Codes.', 'digitalogic' ),
				403
			);
		}

		$validated = $this->validate_request( $payload );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( ! current_user_can( 'edit_post', $validated['product_id'] ) ) {
			return $this->error(
				'digitalogic_product_code_object_forbidden',
				__( 'You are not allowed to edit this product or variation.', 'digitalogic' ),
				403
			);
		}

		try {
			return $this->with_source_identity_lock(
				function () use ( $validated ) {
					return $this->with_operation_lock(
						function () use ( $validated ) {
							return $this->edit_with_operation_lock( $validated );
						}
					);
				}
			);
		} catch ( Throwable $exception ) {
			// A durable claim may already exist and the write may already have
			// happened. Never turn an unknown server outcome into a fresh request.
			unset( $exception );
			return $this->error(
				'digitalogic_product_code_retry_required',
				__( 'The Product Code outcome requires recovery with the same request_id.', 'digitalogic' ),
				503,
				array(
					'retryable'           => true,
					'retry_after'         => 1,
					'request_id'          => $validated['request_id'],
					'request_fingerprint' => $validated['fingerprint'],
				)
			);
		}
	}

	/**
	 * Validate one request without touching product state.
	 *
	 * @param mixed $payload Raw request.
	 * @return array|WP_Error
	 */
	private function validate_request( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $this->error(
				'digitalogic_product_code_payload_invalid',
				__( 'The Product Code request must be an object.', 'digitalogic' ),
				400
			);
		}

		$required = array( 'product_id', 'expected_code', 'product_code', 'if_match', 'request_id' );
		$missing  = array_values( array_diff( $required, array_keys( $payload ) ) );
		if ( ! empty( $missing ) ) {
			return $this->error(
				'digitalogic_product_code_fields_required',
				__( 'The Product Code request is missing required fields.', 'digitalogic' ),
				400,
				array( 'fields' => $missing )
			);
		}

		$product_id = is_int( $payload['product_id'] ) || is_string( $payload['product_id'] )
			? (string) $payload['product_id']
			: '';
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $product_id ) || (string) absint( $product_id ) !== $product_id ) {
			return $this->error(
				'digitalogic_product_code_product_invalid',
				__( 'An existing WooCommerce product or variation ID is required.', 'digitalogic' ),
				400
			);
		}

		$expected_code = $this->normalize_code( $payload['expected_code'], true );
		if ( is_wp_error( $expected_code ) ) {
			return $expected_code;
		}
		$product_code = $this->normalize_code( $payload['product_code'], false );
		if ( is_wp_error( $product_code ) ) {
			return $product_code;
		}

		$if_match = is_string( $payload['if_match'] ) ? $payload['if_match'] : '';
		if ( 1 !== preg_match( self::REVISION_PATTERN, $if_match ) ) {
			return $this->error(
				'digitalogic_product_code_if_match_invalid',
				__( 'A valid Product Code If-Match revision is required.', 'digitalogic' ),
				400
			);
		}

		$request_id = is_string( $payload['request_id'] ) ? $payload['request_id'] : '';
		if ( 1 !== preg_match( self::REQUEST_ID_PATTERN, $request_id ) ) {
			return $this->error(
				'digitalogic_product_code_request_id_invalid',
				__( 'request_id must contain 8 to 128 safe identifier characters.', 'digitalogic' ),
				400
			);
		}

		$request          = array(
			'product_id'    => (int) $product_id,
			'expected_code' => $expected_code,
			'product_code'  => $product_code,
			'if_match'      => $if_match,
			'request_id'    => $request_id,
		);
		$fingerprint_json = $this->canonical_json(
			array(
				'schema'        => self::SCHEMA,
				'product_id'    => (string) $request['product_id'],
				'expected_code' => $request['expected_code'],
				'product_code'  => $request['product_code'],
				'if_match'      => $request['if_match'],
			)
		);
		if ( is_wp_error( $fingerprint_json ) ) {
			return $fingerprint_json;
		}
		$request['fingerprint'] = 'sha256:' . hash( 'sha256', $fingerprint_json );

		return $request;
	}

	/**
	 * Normalize an exact code without coercing numbers or trimming silently.
	 *
	 * @param mixed $value Raw value.
	 * @param bool  $allow_empty Whether the expected previous value may be empty.
	 * @return string|WP_Error
	 */
	private function normalize_code( $value, $allow_empty ) {
		if ( ! is_string( $value ) ) {
			return $this->error(
				'digitalogic_product_code_string_required',
				__( 'Product Codes must be strings so leading zeroes are preserved.', 'digitalogic' ),
				400
			);
		}
		if ( trim( $value ) !== $value ) {
			return $this->error(
				'digitalogic_product_code_whitespace_invalid',
				__( 'Product Codes cannot contain leading or trailing whitespace.', 'digitalogic' ),
				400
			);
		}
		if ( 1 !== preg_match( '//u', $value ) ) {
			return $this->error(
				'digitalogic_product_code_encoding_invalid',
				__( 'Product Codes must contain valid UTF-8 text.', 'digitalogic' ),
				400
			);
		}
		if ( ( ! $allow_empty && '' === $value ) || strlen( $value ) > self::MAX_CODE_BYTES || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return $this->error(
				'digitalogic_product_code_value_invalid',
				__( 'The Product Code is empty, too long, or contains control characters.', 'digitalogic' ),
				400
			);
		}

		return $value;
	}

	/**
	 * Resolve idempotency before evaluating current state.
	 *
	 * @param array $request Validated request.
	 * @return array|WP_Error
	 */
	private function edit_with_operation_lock( $request ) {
		$reservation_without_operation = false;
		$existing = $this->operation_record( $request['request_id'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! empty( $existing ) ) {
			if ( ! hash_equals( (string) ( $existing['request_fingerprint'] ?? '' ), $request['fingerprint'] ) ) {
				return $this->error(
					'digitalogic_product_code_request_id_reused',
					__( 'That request_id is already bound to a different Product Code edit.', 'digitalogic' ),
					409
				);
			}
			$status = (string) ( $existing['status'] ?? '' );
			if ( ! $this->operation_record_is_valid( $existing, $request, $status ) ) {
				return $this->audit_unavailable();
			}
			if ( 'completed' === $status ) {
				$this->clear_recovery_index( $request );
				$current = $this->read_exact_product_code( $request['product_id'] );
				if (
					is_wp_error( $current )
					|| empty( $current['product_exists'] )
					|| ! empty( $current['duplicate_rows'] )
					|| ! empty( $current['invalid_key_rows'] )
				) {
					return $this->error(
						'digitalogic_product_code_replay_readback_unavailable',
						__( 'The completed Product Code edit is historical, but the current product state could not be read exactly.', 'digitalogic' ),
						503,
						array( 'retryable' => true )
					);
				}
				return $this->historical_result_with_current_readback( $request, $existing['result'], $current );
			}
			if ( 'reconciled_no_effect' === $status ) {
				$this->clear_recovery_index( $request );
				return $this->error(
					'digitalogic_product_code_reconciled_no_effect',
					__( 'This request was reconciled as having no Product Code effect; start a new edit from the current row.', 'digitalogic' ),
					409,
					array( 'retryable' => false )
				);
			}
			if ( 'reservation_released' === $status ) {
				$this->clear_recovery_index( $request );
				return $this->reconciled_no_effect_error( $request, $existing['result'] ?? array() );
			}
			if ( 'outcome_unknown' === $status ) {
				return $this->error(
					'digitalogic_product_code_outcome_unknown',
					__( 'The earlier Product Code edit has an unknown outcome and requires exact readback.', 'digitalogic' ),
					409,
					array(
						'retryable'        => false,
						'backup_reference' => (string) ( $existing['backup_reference'] ?? '' ),
					)
				);
			}
			if ( ! in_array( $status, array( 'in_progress', 'failed_retryable' ), true ) ) {
				return $this->audit_unavailable();
			}
		}

		$pending = $this->recovery_index( $request['product_id'] );
		if ( is_wp_error( $pending ) ) {
			return $pending;
		}
		if ( ! empty( $pending ) ) {
			$pending_request = $this->request_from_recovery_index( $pending, $request['product_id'] );
			if ( is_wp_error( $pending_request ) ) {
				return $pending_request;
			}
			$pending_operation = $this->operation_record( $pending_request['request_id'] );
			if ( is_wp_error( $pending_operation ) ) {
				return $pending_operation;
			}
			if (
				empty( $pending_operation )
				&& 'reservation_pending' !== (string) ( $pending['status'] ?? '' )
			) {
				return $this->audit_unavailable();
			}
			if ( empty( $pending_operation ) ) {
				$reservation_without_operation = true;
			}
			if (
				! empty( $pending_operation )
				&& (
					! $this->operation_record_is_valid( $pending_operation, $pending_request, (string) ( $pending_operation['status'] ?? '' ) )
					|| (int) ( $pending_operation['actor_id'] ?? 0 ) !== (int) ( $pending['actor_id'] ?? 0 )
				)
			) {
				return $this->audit_unavailable();
			}
			if (
				is_array( $pending_operation )
				&& in_array( (string) ( $pending_operation['status'] ?? '' ), array( 'completed', 'reconciled_no_effect', 'reservation_released' ), true )
				&& $this->operation_record_is_valid( $pending_operation, $pending_request, (string) $pending_operation['status'] )
			) {
				// A terminal operation is authoritative even when physical pointer
				// cleanup failed. It must never block the next bounded edit.
				$this->clear_recovery_index( $pending_request );
				$pending = array();
			}
		}
		if ( ! empty( $pending ) ) {
			$pending_request = $this->request_from_recovery_index( $pending, $request['product_id'] );
			if ( ! hash_equals( $pending_request['request_id'], $request['request_id'] ) ) {
				if ( (int) ( $pending['actor_id'] ?? 0 ) !== get_current_user_id() ) {
					return $this->error(
						'digitalogic_product_code_busy',
						__( 'Another Product Code operation is incomplete for this product. Retry after it is recovered.', 'digitalogic' ),
						503,
						array(
							'retryable'   => true,
							'retry_after' => 1,
						)
					);
				}
				return $this->error(
					'digitalogic_product_code_recovery_required',
					__( 'An earlier Product Code edit must be recovered with its original request_id before another edit can start.', 'digitalogic' ),
					409,
					array(
						'retryable' => true,
						'recovery'  => array(
							'status'              => (string) ( $pending['status'] ?? 'in_progress' ),
							'request_id'          => $pending_request['request_id'],
							'expected_code'       => $pending_request['expected_code'],
							'product_code'        => $pending_request['product_code'],
							'if_match'            => $pending_request['if_match'],
							'request_fingerprint' => $pending_request['fingerprint'],
						),
					)
				);
			}
		}

		$result = Digitalogic_Product_Write_Lock::instance()->with_product_lock(
			$request['product_id'],
			function () use ( $request, $existing, $reservation_without_operation ) {
				return $this->edit_with_product_lock( $request, $existing, $reservation_without_operation );
			},
			0
		);
		if ( is_wp_error( $result ) && 'product_write_lock_lost' === $result->get_error_code() ) {
			$terminal = $this->verified_terminal_result_for_request( $request );
			if ( null !== $terminal ) {
				return $this->historical_result_with_current_readback( $request, $terminal );
			}
			$record = $this->operation_record( $request['request_id'] );
			if (
				is_array( $record )
				&& in_array( (string) ( $record['status'] ?? '' ), array( 'in_progress', 'failed_retryable' ), true )
				&& $this->operation_record_is_valid( $record, $request, (string) $record['status'] )
			) {
				return $this->same_request_recovery_required(
					$request,
					(string) ( $record['backup_reference'] ?? '' ),
					'product_lock_lost'
				);
			}
		}

		return $result;
	}

	/**
	 * Validate and mutate while both fail-fast locks are held.
	 *
	 * @param array $request Validated request.
	 * @param array $existing Existing retryable/in-progress record.
	 * @param bool  $reservation_without_operation Whether the exact pointer predates every claim/effect.
	 * @return array|WP_Error
	 */
	private function edit_with_product_lock( $request, $existing, $reservation_without_operation = false ) {
		$before = $this->read_exact_product_code( $request['product_id'] );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( $reservation_without_operation ) {
			return $this->release_pre_effect_reservation( $request, $before );
		}
		if ( ! $before['product_exists'] ) {
			return $this->error(
				'digitalogic_product_code_product_not_found',
				__( 'The WooCommerce product or variation no longer exists.', 'digitalogic' ),
				404
			);
		}
		if ( $before['duplicate_rows'] || ! empty( $before['invalid_key_rows'] ) ) {
			return $this->error(
				'digitalogic_product_code_meta_conflict',
				__( 'The product has conflicting Product Code metadata rows and must be reconciled before editing.', 'digitalogic' ),
				409,
				array(
					'row_count'        => $before['row_count'],
					'invalid_key_rows' => (int) ( $before['invalid_key_rows'] ?? 0 ),
				)
			);
		}

		$current_revision = $this->revision_for( $request['product_id'], $before['product_code'] );
		$operation_restored_exact_before = false;
		if ( ! empty( $existing ) && in_array( (string) ( $existing['status'] ?? '' ), array( 'in_progress', 'failed_retryable' ), true ) ) {
			$recovered = $this->recover_existing_operation( $request, $existing, $before, $current_revision );
			if ( null !== $recovered ) {
				return $recovered;
			}
			$operation_restored_exact_before = true;
		}

		if ( ! hash_equals( $request['expected_code'], $before['product_code'] ) ) {
			return $this->precondition_error( $before, $current_revision, 'expected_code' );
		}
		if ( ! hash_equals( $request['if_match'], $current_revision ) ) {
			return $this->precondition_error( $before, $current_revision, 'if_match' );
		}

		$source_guard = $this->source_guard( $request['product_id'], $before['product_code'], $request['product_code'], $before );
		if ( is_wp_error( $source_guard ) ) {
			if ( $operation_restored_exact_before && 'digitalogic_product_code_source_managed' === $source_guard->get_error_code() ) {
				return $this->terminalize_restored_before_operation( $request, $existing, $before, $current_revision );
			}
			return $source_guard;
		}
		$governance_proof = $source_guard['proof'];

		$conflicts = $this->find_code_conflicts( $request['product_id'], $request['product_code'] );
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		if ( ! empty( $conflicts ) ) {
			if ( $operation_restored_exact_before ) {
				return $this->terminalize_restored_before_operation( $request, $existing, $before, $current_revision );
			}
			return $this->error(
				'digitalogic_product_code_not_unique',
				__( 'That exact Product Code already belongs to another product or variation.', 'digitalogic' ),
				409,
				array( 'woocommerce_ids' => array_column( $conflicts, 'ID' ) )
			);
		}

		if ( hash_equals( $before['product_code'], $request['product_code'] ) ) {
			$result = $this->success_result( $request, $before, $before, false, '', $governance_proof );
			$stored = $this->complete_operation(
				$request,
				$before,
				$result,
				1,
				array(
					'actor_id'         => get_current_user_id(),
					'governance_proof' => $governance_proof,
				)
			);
			return is_wp_error( $stored ) ? $stored : $result;
		}

		$projection_checkpoint = $this->projection_checkpoint( $request['product_id'] );
		if ( is_wp_error( $projection_checkpoint ) ) {
			return $projection_checkpoint;
		}

		$attempts         = max( 1, (int) ( $existing['attempts'] ?? 0 ) + 1 );
		$backup_reference = $this->backup_reference( $request, $before, $governance_proof );
		$claim            = array(
			'schema'              => self::SCHEMA,
			'status'              => 'in_progress',
			'request_fingerprint' => $request['fingerprint'],
			'product_id'          => $request['product_id'],
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'backup_reference'    => $backup_reference,
			'rollback_data'       => array(
				'meta_exists'  => $before['meta_exists'],
				'product_code' => $before['product_code'],
				'revision'     => $current_revision,
			),
			'actor_id'            => get_current_user_id(),
			'governance_proof'    => $governance_proof,
			'projection'          => $projection_checkpoint,
			'attempts'            => $attempts,
			'updated_at'          => gmdate( 'c' ),
		);
		// Publish the per-product recovery pointer before the per-request claim.
		// If the second durable write fails, reload can still recover the exact
		// request and retry the claim; there is never an undiscoverable orphan.
		$reservation           = $claim;
		$reservation['status'] = 'reservation_pending';
		$indexed               = $this->store_recovery_index( $request, $reservation );
		if ( is_wp_error( $indexed ) ) {
			return $indexed;
		}
		$stored = $this->store_operation( $request['request_id'], $claim );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$promoted = $this->store_recovery_index( $request, $claim );
		if ( is_wp_error( $promoted ) ) {
			return $promoted;
		}

		$updated = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
			'editor',
			array(
				'product_id' => $request['product_id'],
				'operation'  => 'set',
				'value'      => $request['product_code'],
			),
			function () use ( $request ) {
				return update_post_meta( $request['product_id'], self::META_KEY, $request['product_code'] );
			}
		);
		if ( is_wp_error( $updated ) || ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, $backup_reference, 'effect_lock_lost' );
		}
		$this->invalidate_product_identity_cache( $request['product_id'] );
		$after = $this->read_exact_product_code( $request['product_id'] );
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, $backup_reference, 'readback_lock_lost' );
		}
		if (
			false === $updated
			|| is_wp_error( $after )
			|| ! $this->readback_matches( $after, $request['product_code'] )
		) {
			return $this->rollback_after_failure(
				$request,
				$before,
				$claim,
				'digitalogic_product_code_readback_failed',
				__( 'The Product Code write did not pass exact database readback.', 'digitalogic' )
			);
		}

		$post_conflicts = $this->find_code_conflicts( $request['product_id'], $request['product_code'] );
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, $backup_reference, 'uniqueness_lock_lost' );
		}
		if ( is_wp_error( $post_conflicts ) || ! empty( $post_conflicts ) ) {
			return $this->rollback_after_failure(
				$request,
				$before,
				$claim,
				'digitalogic_product_code_uniqueness_readback_failed',
				__( 'The Product Code no longer passes the exact uniqueness readback.', 'digitalogic' )
			);
		}

		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, $backup_reference, 'projection_lock_lost' );
		}
		$projection = $this->verify_projection_checkpoint( $projection_checkpoint );
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, $backup_reference, 'projection_lock_lost' );
		}
		if ( is_wp_error( $projection ) ) {
			return $this->rollback_after_failure(
				$request,
				$before,
				$claim,
				'digitalogic_product_code_projection_pending',
				__( 'The Product Code was not finalized because its catalog revision could not be verified.', 'digitalogic' )
			);
		}
		$claim['projection'] = $projection;

		$result = $this->success_result( $request, $before, $after, true, $backup_reference, $governance_proof, $projection );
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, $backup_reference, 'completion_lock_lost' );
		}
		$stored = $this->complete_operation( $request, $before, $result, $attempts, $claim );
		if ( is_wp_error( $stored ) ) {
			// The exact after-state and invariants are already verified. Rolling it
			// back here could contradict a terminal record that was stored but whose
			// readback failed transiently. Keep the same request ID for recovery.
			return $this->error(
				'digitalogic_product_code_completion_pending',
				__( 'The Product Code changed, but its terminal audit readback is pending. Retry the unchanged request.', 'digitalogic' ),
				503,
				array(
					'retryable'        => true,
					'backup_reference' => $backup_reference,
				)
			);
		}

		try {
			Digitalogic_Logger::instance()->log(
				'update_product_code',
				'product',
				$request['product_id'],
				wp_json_encode(
					array(
						'product_code' => $before['product_code'],
						'revision'     => $current_revision,
					)
				),
				wp_json_encode(
					array(
						'product_code' => $after['product_code'],
						'revision'     => $result['revision'],
					)
				),
				'Updated canonical Product Code; audit ' . $backup_reference
			);
		} catch ( Throwable $exception ) {
			// The verified durable operation must still return its terminal result.
			unset( $exception );
		}
		return $result;
	}

	/**
	 * Recover an interrupted idempotent request from exact database state.
	 *
	 * Returning null means the original value is intact and a bounded retry may
	 * proceed. Any other value is a terminal response.
	 *
	 * @param array  $request Validated request.
	 * @param array  $existing Existing operation record.
	 * @param array  $current Exact database readback.
	 * @param string $current_revision Current revision.
	 * @return array|WP_Error|null
	 */
	private function recover_existing_operation( $request, $existing, $current, $current_revision ) {
		if ( $this->readback_matches( $current, $request['product_code'] ) ) {
			if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
				return $this->same_request_recovery_required(
					$request,
					(string) ( $existing['backup_reference'] ?? '' ),
					'recovery_lock_lost'
				);
			}
			$source_guard = $this->source_guard(
				$request['product_id'],
				(string) ( $existing['rollback_data']['product_code'] ?? $request['expected_code'] ),
				$request['product_code'],
				$current
			);
			if ( is_wp_error( $source_guard ) ) {
				if ( 'digitalogic_product_code_source_managed' === $source_guard->get_error_code() ) {
					return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'recovery_source_conflict' );
				}

				return $source_guard;
			}

			$conflicts = $this->find_code_conflicts( $request['product_id'], $request['product_code'] );
			if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
				return $this->same_request_recovery_required(
					$request,
					(string) ( $existing['backup_reference'] ?? '' ),
					'recovery_lock_lost'
				);
			}
			if ( is_wp_error( $conflicts ) ) {
				return $conflicts;
			}
			if ( ! empty( $conflicts ) ) {
				return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'recovery_uniqueness_conflict' );
			}
			$projection = $this->verify_projection_checkpoint( $existing['projection'] ?? array(), true );
			if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
				return $this->same_request_recovery_required(
					$request,
					(string) ( $existing['backup_reference'] ?? '' ),
					'recovery_lock_lost'
				);
			}
			if ( is_wp_error( $projection ) ) {
				$pending                 = $existing;
				$pending['status']       = 'failed_retryable';
				$pending['failure_code'] = 'recovery_projection_pending';
				$pending['updated_at']   = gmdate( 'c' );
				$this->store_operation( $request['request_id'], $pending );
				return $this->error(
					'digitalogic_product_code_completion_pending',
					__( 'The Product Code is stored, but its catalog revision still needs same-request recovery.', 'digitalogic' ),
					503,
					array(
						'retryable'   => true,
						'retry_after' => 1,
						'request_id'  => $request['request_id'],
					)
				);
			}

			$before              = array(
				'product_exists'        => true,
				'meta_exists'           => ! empty( $existing['rollback_data']['meta_exists'] ),
				'product_code'          => (string) ( $existing['rollback_data']['product_code'] ?? $request['expected_code'] ),
				'record_hash'           => '',
				'record_hash_row_count' => 0,
				'owner'                 => array(
					'source_id'    => '',
					'dataset'      => '',
					'product_code' => '',
				),
				'owner_row_counts'      => array(
					'source_id'    => 0,
					'dataset'      => 0,
					'product_code' => 0,
				),
				'row_count'             => 1,
				'duplicate_rows'        => false,
				'invalid_key_rows'      => 0,
			);
			$result              = $this->success_result(
				$request,
				$before,
				$current,
				true,
				(string) ( $existing['backup_reference'] ?? '' ),
				$existing['governance_proof'],
				$projection
			);
			$result['recovered'] = true;
			$result['recovery_governance_evidence_fingerprint'] = $source_guard['proof']['evidence_fingerprint'];
			$completion_context                                 = $existing;
			$completion_context['recovery_governance_proof']    = $source_guard['proof'];
			$completion_context['projection']                   = $projection;
			if ( (int) ( $existing['actor_id'] ?? 0 ) !== get_current_user_id() ) {
				$completion_context['recovered_by'] = get_current_user_id();
			}
			if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
				return $this->same_request_recovery_required(
					$request,
					(string) ( $existing['backup_reference'] ?? '' ),
					'recovery_lock_lost'
				);
			}
			$stored = $this->complete_operation(
				$request,
				$before,
				$result,
				(int) ( $existing['attempts'] ?? 1 ),
				$completion_context
			);
			return is_wp_error( $stored ) ? $stored : $result;
		}

		$rollback_code        = (string) ( $existing['rollback_data']['product_code'] ?? '' );
		$rollback_rev         = (string) ( $existing['rollback_data']['revision'] ?? '' );
		$rollback_meta_exists = ! empty( $existing['rollback_data']['meta_exists'] );
		$current_meta_exists  = ! empty( $current['meta_exists'] );
		$exact_absent         = $rollback_meta_exists || $this->readback_is_exact_absent_identity( $current );
		if (
			$rollback_meta_exists === $current_meta_exists
			&& $exact_absent
			&& hash_equals( $rollback_code, $current['product_code'] )
			&& hash_equals( $rollback_rev, $current_revision )
		) {
			return null;
		}

		return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'recovery_state_mismatch' );
	}

	/**
	 * Terminalize a pointer that was durably reserved before any claim/effect.
	 *
	 * The operation record is intentionally written before pointer cleanup. A
	 * failed physical delete therefore remains harmless: every later request can
	 * prove the pointer is terminal and ignore it without repeating an effect.
	 *
	 * @param array $request Validated request.
	 * @param array $current Exact cache-bypassed current state.
	 * @return WP_Error
	 */
	private function release_pre_effect_reservation( $request, $current ) {
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, '', 'reservation_release_lock_lost' );
		}
		$pointer = $this->recovery_index( $request['product_id'] );
		if ( is_wp_error( $pointer ) ) {
			return $pointer;
		}
		$pointer_request = $this->request_from_recovery_index( $pointer, $request['product_id'] );
		$operation       = $this->operation_record( $request['request_id'] );
		if (
			is_wp_error( $pointer_request )
			|| is_wp_error( $operation )
			|| ! empty( $operation )
			|| 'reservation_pending' !== (string) ( $pointer['status'] ?? '' )
			|| ! hash_equals( $request['request_id'], (string) ( $pointer_request['request_id'] ?? '' ) )
			|| ! hash_equals( $request['fingerprint'], (string) ( $pointer_request['fingerprint'] ?? '' ) )
		) {
			return $this->audit_unavailable();
		}
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required( $request, '', 'reservation_release_lock_lost' );
		}

		$released_at = gmdate( 'c' );

		$evidence      = array(
			'schema'              => self::RESERVATION_RELEASE_SCHEMA,
			'product_id'          => $request['product_id'],
			'request_id'          => $request['request_id'],
			'request_fingerprint' => $request['fingerprint'],
			'actor_id'            => (int) $pointer['actor_id'],
			'released_by'         => get_current_user_id(),
			'released_at'         => $released_at,
			'no_effect'           => true,
			'observed'            => array(
				'product_exists'   => ! empty( $current['product_exists'] ),
				'meta_exists'      => ! empty( $current['meta_exists'] ),
				'product_code'     => (string) ( $current['product_code'] ?? '' ),
				'revision'         => ! empty( $current['product_exists'] )
					? $this->revision_for( $request['product_id'], (string) ( $current['product_code'] ?? '' ) )
					: '',
				'row_count'        => (int) ( $current['row_count'] ?? 0 ),
				'duplicate_rows'   => ! empty( $current['duplicate_rows'] ),
				'invalid_key_rows' => (int) ( $current['invalid_key_rows'] ?? 0 ),
			),
		);
		$evidence_json = $this->canonical_json( $evidence );
		if ( is_wp_error( $evidence_json ) ) {
			return $evidence_json;
		}
		$evidence['evidence_fingerprint'] = 'sha256:' . hash( 'sha256', $evidence_json );

		$result = array(
			'schema'                       => self::RESERVATION_RELEASE_SCHEMA,
			'status'                       => 'reservation_released',
			'changed'                      => false,
			'replayed'                     => false,
			'product_id'                   => $request['product_id'],
			'current_product_code'         => (string) ( $current['product_code'] ?? '' ),
			'current_revision'             => (string) $evidence['observed']['revision'],
			'request_id'                   => $request['request_id'],
			'request_fingerprint'          => $request['fingerprint'],
			'release_evidence_fingerprint' => $evidence['evidence_fingerprint'],
			'verification'                 => array(
				'database_readback'             => true,
				'cache_bypassed'                => true,
				'reservation_without_operation' => true,
				'no_code_write'                 => true,
			),
		);

		$terminal = array(
			'schema'              => self::SCHEMA,
			'status'              => 'reservation_released',
			'request_fingerprint' => $request['fingerprint'],
			'product_id'          => $request['product_id'],
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'actor_id'            => (int) $pointer['actor_id'],
			'attempts'            => 0,
			'release'             => $evidence,
			'result'              => $result,
			'updated_at'          => $released_at,
		);

		$stored = $this->store_operation( $request['request_id'], $terminal );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$this->clear_recovery_index( $request );

		return $this->reconciled_no_effect_error( $request, $result );
	}

	/**
	 * Terminalize an exact restored before-state that cannot be reauthorized.
	 *
	 * @param array  $request Validated request.
	 * @param array  $existing Valid in-progress or failed-retryable operation.
	 * @param array  $current Exact cache-bypassed current state.
	 * @param string $current_revision Exact current revision.
	 * @return WP_Error
	 */
	private function terminalize_restored_before_operation( $request, $existing, $current, $current_revision ) {
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required(
				$request,
				(string) ( $existing['backup_reference'] ?? '' ),
				'reconciliation_lock_lost'
			);
		}
		$rollback_meta_exists = true === ( $existing['rollback_data']['meta_exists'] ?? null );
		$rollback_code        = (string) ( $existing['rollback_data']['product_code'] ?? '' );
		$rollback_revision    = (string) ( $existing['rollback_data']['revision'] ?? '' );
		$exact_absent         = $rollback_meta_exists || $this->readback_is_exact_absent_identity( $current );
		$current_meta_exists  = ! empty( $current['meta_exists'] );
		if (
			! in_array( (string) ( $existing['status'] ?? '' ), array( 'in_progress', 'failed_retryable' ), true )
			|| $current_meta_exists !== $rollback_meta_exists
			|| ! $exact_absent
			|| ! hash_equals( $rollback_code, (string) ( $current['product_code'] ?? '' ) )
			|| ! hash_equals( $rollback_revision, $current_revision )
		) {
			return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'reauthorization_state_mismatch' );
		}

		$current_conflicts = ! empty( $current['meta_exists'] )
			? $this->find_code_conflicts( $request['product_id'], (string) $current['product_code'] )
			: array();
		if ( is_wp_error( $current_conflicts ) ) {
			return $current_conflicts;
		}
		if ( ! empty( $current_conflicts ) ) {
			return $this->mark_operation_outcome_unknown( $request, $existing, $current, 'reauthorization_before_not_unique' );
		}
		$source = $this->reconciliation_source_evidence( $request, $current );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$record_fingerprint = $this->operation_record_fingerprint( $existing );
		if ( is_wp_error( $record_fingerprint ) ) {
			return $record_fingerprint;
		}
		$preview = $this->reconciliation_preview(
			$request,
			$record_fingerprint,
			(string) $current['product_code'],
			$current_revision,
			'before',
			$source
		);
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$evidence = $this->reconciliation_evidence( $preview );
		if ( is_wp_error( $evidence ) ) {
			return $evidence;
		}
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required(
				$request,
				(string) ( $existing['backup_reference'] ?? '' ),
				'reconciliation_lock_lost'
			);
		}

		$terminal                   = $existing;
		$terminal['status']         = 'reconciled_no_effect';
		$terminal['reconciliation'] = $evidence;
		$terminal['result']         = $this->reconciliation_no_effect_result( $request, $preview, $evidence );
		$terminal['updated_at']     = gmdate( 'c' );
		$stored                     = $this->store_operation( $request['request_id'], $terminal );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$this->clear_recovery_index( $request );

		return $this->reconciled_no_effect_error( $request, $terminal['result'] );
	}

	/**
	 * Return the stable terminal error used by the edit UI after no-effect resolution.
	 *
	 * @param array $request Validated request.
	 * @param array $result Terminal no-effect result.
	 * @return WP_Error
	 */
	private function reconciled_no_effect_error( $request, $result ) {
		return $this->error(
			'digitalogic_product_code_reconciled_no_effect',
			__( 'This request was reconciled as having no Product Code effect; start a new edit from the current row.', 'digitalogic' ),
			409,
			array(
				'retryable'           => false,
				'request_id'          => $request['request_id'],
				'request_fingerprint' => $request['fingerprint'],
				'current_code'        => (string) ( $result['current_product_code'] ?? $result['product_code'] ?? '' ),
				'current_revision'    => (string) ( $result['current_revision'] ?? $result['revision'] ?? '' ),
			)
		);
	}

	/** Build or apply one exact reconciliation while all identity locks are held. */
	private function reconcile_outcome_locked( $manifest ) {
		$product_id = (int) $manifest['product_id'];
		if ( ! $this->mutation_locks_are_owned( $product_id ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_lock_lost',
				__( 'The Product Code reconciliation lock was lost; retry the dry-run.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		$existing = $this->operation_record( $manifest['request_id'] );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( empty( $existing ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_not_found',
				__( 'The Product Code operation was not found.', 'digitalogic' ),
				404
			);
		}
		$request = $this->validate_request(
			array(
				'product_id'    => $existing['product_id'] ?? null,
				'expected_code' => $existing['expected_code'] ?? null,
				'product_code'  => $existing['product_code'] ?? null,
				'if_match'      => $existing['if_match'] ?? null,
				'request_id'    => $manifest['request_id'],
			)
		);
		$status  = (string) ( $existing['status'] ?? '' );
		if (
			is_wp_error( $request )
			|| $request['product_id'] !== $product_id
			|| ! $this->operation_record_is_valid( $existing, $request, $status )
		) {
			return $this->audit_unavailable();
		}
		if ( in_array( $status, array( 'completed', 'reconciled_no_effect' ), true ) ) {
			return $this->replayed_reconciliation_result( $existing, $request );
		}
		if ( 'reservation_released' === $status ) {
			return $this->reconciled_no_effect_error( $request, $existing['result'] ?? array() );
		}
		if ( 'outcome_unknown' !== $status ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_not_required',
				__( 'This Product Code operation does not require manual reconciliation.', 'digitalogic' ),
				409
			);
		}

		$pointer = $this->recovery_index( $product_id );
		if ( is_wp_error( $pointer ) ) {
			return $pointer;
		}
		$pointer_request = $this->request_from_recovery_index( $pointer, $product_id );
		if (
			is_wp_error( $pointer_request )
			|| ! hash_equals( $request['request_id'], $pointer_request['request_id'] )
			|| ! hash_equals( $request['fingerprint'], $pointer_request['fingerprint'] )
		) {
			return $this->audit_unavailable();
		}

		$current             = $this->read_exact_product_code( $product_id );
		$rollback_meta       = true === ( $existing['rollback_data']['meta_exists'] ?? null );
		$rollback_code       = (string) ( $existing['rollback_data']['product_code'] ?? '' );
		$rollback_revision   = (string) ( $existing['rollback_data']['revision'] ?? '' );
		$current_revision    = is_array( $current ) && ! empty( $current['product_exists'] )
			? $this->revision_for( $product_id, (string) ( $current['product_code'] ?? '' ) )
			: '';
		$exact_absent_before = ! $rollback_meta
			&& '' === $rollback_code
			&& $this->readback_is_exact_absent_identity( $current )
			&& hash_equals( $rollback_revision, $current_revision );
		if (
			is_wp_error( $current )
			|| ! $current['product_exists']
			|| ( ! $current['meta_exists'] && ! $exact_absent_before )
			|| $current['duplicate_rows']
			|| ! empty( $current['invalid_key_rows'] )
		) {
			return $this->error(
				'digitalogic_product_code_reconciliation_readback_failed',
				__( 'The exact Product Code state cannot be reconciled safely.', 'digitalogic' ),
				409,
				array( 'retryable' => false )
			);
		}
		$resolution = '';
		if (
			$rollback_meta === (bool) $current['meta_exists']
			&& hash_equals( $rollback_code, $current['product_code'] )
			&& hash_equals( $rollback_revision, $current_revision )
		) {
			$resolution = 'before';
		} elseif ( $this->readback_matches( $current, $request['product_code'] ) ) {
			$resolution = 'after';
		}
		if ( '' === $resolution ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_state_unresolved',
				__( 'The observed Product Code matches neither the exact backup nor the intended result.', 'digitalogic' ),
				409,
				array(
					'retryable'        => false,
					'current_revision' => $current_revision,
				)
			);
		}

		$conflicts = $current['meta_exists']
			? $this->find_code_conflicts( $product_id, $current['product_code'] )
			: array();
		if ( is_wp_error( $conflicts ) ) {
			return $conflicts;
		}
		if ( ! empty( $conflicts ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_not_unique',
				__( 'The observed Product Code is not unique and cannot be reconciled.', 'digitalogic' ),
				409,
				array( 'woocommerce_ids' => array_column( $conflicts, 'ID' ) )
			);
		}

		$source = $this->reconciliation_source_evidence( $request, $current );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'after' === $resolution && 'unmanaged' !== $source['status'] ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_source_managed',
				__( 'The intended Product Code is now source-managed and cannot be confirmed as an applied owner edit.', 'digitalogic' ),
				409,
				array( 'source_evidence_fingerprint' => $source['evidence_fingerprint'] )
			);
		}
		$record_fingerprint = $this->operation_record_fingerprint( $existing );
		if ( is_wp_error( $record_fingerprint ) ) {
			return $record_fingerprint;
		}
		$preview = $this->reconciliation_preview(
			$request,
			$record_fingerprint,
			$current['product_code'],
			$current_revision,
			$resolution,
			$source
		);
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( ! $manifest['apply'] ) {
			return $preview;
		}
		foreach (
			array(
				'record_fingerprint'    => $preview['record_fingerprint'],
				'observed_product_code' => $preview['observed_product_code'],
				'observed_revision'     => $preview['observed_revision'],
				'resolution'            => $preview['resolution'],
				'preview_digest'        => $preview['preview_digest'],
			) as $field => $expected
		) {
			if ( ! hash_equals( (string) $expected, (string) $manifest[ $field ] ) ) {
				return $this->error(
					'digitalogic_product_code_reconciliation_manifest_stale',
					__( 'The Product Code reconciliation manifest no longer matches the exact dry-run.', 'digitalogic' ),
					412,
					array( 'failed_field' => $field )
				);
			}
		}
		if ( ! $this->mutation_locks_are_owned( $product_id ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_lock_lost',
				__( 'The Product Code reconciliation lock was lost; retry the dry-run.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		$evidence = $this->reconciliation_evidence( $preview );
		if ( is_wp_error( $evidence ) ) {
			return $evidence;
		}
		if ( 'before' === $resolution ) {
			$terminal                   = $existing;
			$terminal['status']         = 'reconciled_no_effect';
			$terminal['reconciliation'] = $evidence;
			$terminal['result']         = $this->reconciliation_no_effect_result( $request, $preview, $evidence );
			$terminal['updated_at']     = gmdate( 'c' );
			$stored                     = $this->store_operation( $request['request_id'], $terminal );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$this->clear_recovery_index( $request );
			return $terminal['result'];
		}

		$source_guard = $this->source_guard(
			$product_id,
			$request['expected_code'],
			$request['product_code'],
			$current
		);
		if ( is_wp_error( $source_guard ) ) {
			return $source_guard;
		}
		$projection = $this->verify_projection_checkpoint( $existing['projection'] ?? array(), true );
		if ( is_wp_error( $projection ) || ! $this->mutation_locks_are_owned( $product_id ) ) {
			return is_wp_error( $projection ) ? $projection : $this->error(
				'digitalogic_product_code_reconciliation_lock_lost',
				__( 'The Product Code reconciliation lock was lost; retry the dry-run.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		$before              = array(
			'product_exists'        => true,
			'meta_exists'           => ! empty( $existing['rollback_data']['meta_exists'] ),
			'product_code'          => $request['expected_code'],
			'record_hash'           => '',
			'record_hash_row_count' => 0,
			'owner'                 => array(
				'source_id'    => '',
				'dataset'      => '',
				'product_code' => '',
			),
			'owner_row_counts'      => array(
				'source_id'    => 0,
				'dataset'      => 0,
				'product_code' => 0,
			),
			'row_count'             => 1,
			'duplicate_rows'        => false,
			'invalid_key_rows'      => 0,
		);
		$result              = $this->success_result(
			$request,
			$before,
			$current,
			true,
			(string) $existing['backup_reference'],
			$existing['governance_proof'],
			$projection
		);
		$result['recovered'] = true;
		$result['recovery_governance_evidence_fingerprint'] = $source_guard['proof']['evidence_fingerprint'];
		$result['reconciliation_evidence_fingerprint']      = $evidence['evidence_fingerprint'];
		$context                              = $existing;
		$context['recovery_governance_proof'] = $source_guard['proof'];
		$context['projection']                = $projection;
		$context['reconciliation']            = $evidence;
		if ( (int) ( $existing['actor_id'] ?? 0 ) !== get_current_user_id() ) {
			$context['recovered_by'] = get_current_user_id();
		}
		$stored = $this->complete_operation( $request, $before, $result, (int) $existing['attempts'], $context );

		return is_wp_error( $stored ) ? $stored : $result;
	}

	/** Build a compact source-governance status for reconciliation. */
	private function reconciliation_source_evidence( $request, $current ) {
		$state = $this->read_exact_source_state();
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$state_json = $this->canonical_json( $state );
		if ( is_wp_error( $state_json ) ) {
			return $state_json;
		}
		$source_state_fingerprint = 'sha256:' . hash( 'sha256', $state_json );
		$guard                    = $this->source_guard(
			$request['product_id'],
			$request['expected_code'],
			$request['product_code'],
			$current,
			$state
		);
		if ( ! is_wp_error( $guard ) ) {
			return array(
				'status'               => 'unmanaged',
				'evidence_fingerprint' => (string) $guard['proof']['evidence_fingerprint'],
			);
		}
		if ( 'digitalogic_product_code_source_managed' !== $guard->get_error_code() ) {
			return $guard;
		}
		$data    = is_array( $guard->get_error_data() ) ? $guard->get_error_data() : array();
		$reasons = array_values( array_filter( array_map( 'sanitize_key', (array) ( $data['reasons'] ?? array() ) ) ) );
		sort( $reasons, SORT_STRING );
		$material = $this->canonical_json(
			array(
				'schema'                   => self::RECONCILIATION_SCHEMA,
				'status'                   => 'managed',
				'reasons'                  => $reasons,
				'source_state_fingerprint' => $source_state_fingerprint,
			)
		);
		if ( is_wp_error( $material ) ) {
			return $material;
		}

		return array(
			'status'               => 'managed',
			'evidence_fingerprint' => 'sha256:' . hash( 'sha256', $material ),
		);
	}

	/** Hash one exact durable operation record. */
	private function operation_record_fingerprint( $record ) {
		$json = $this->canonical_json( $record );
		return is_wp_error( $json ) ? $json : 'sha256:' . hash( 'sha256', $json );
	}

	/** Build the exact dry-run response and its apply digest. */
	private function reconciliation_preview( $request, $record_fingerprint, $observed_code, $observed_revision, $resolution, $source ) {
		$material = array(
			'schema'                      => self::RECONCILIATION_SCHEMA,
			'product_id'                  => $request['product_id'],
			'request_id'                  => $request['request_id'],
			'request_fingerprint'         => $request['fingerprint'],
			'record_fingerprint'          => $record_fingerprint,
			'observed_product_code'       => $observed_code,
			'observed_revision'           => $observed_revision,
			'resolution'                  => $resolution,
			'source_status'               => $source['status'],
			'source_evidence_fingerprint' => $source['evidence_fingerprint'],
			'unique'                      => true,
		);
		$json     = $this->canonical_json( $material );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$material['status']         = 'dry_run';
		$material['apply_required'] = true;
		$material['preview_digest'] = 'sha256:' . hash( 'sha256', $json );

		return $material;
	}

	/** Bind the exact preview into a durable terminal audit proof. */
	private function reconciliation_evidence( $preview ) {
		$evidence = array(
			'schema'                      => self::RECONCILIATION_SCHEMA,
			'product_id'                  => (int) $preview['product_id'],
			'request_id'                  => (string) $preview['request_id'],
			'request_fingerprint'         => (string) $preview['request_fingerprint'],
			'record_fingerprint'          => (string) $preview['record_fingerprint'],
			'observed_product_code'       => (string) $preview['observed_product_code'],
			'observed_revision'           => (string) $preview['observed_revision'],
			'resolution'                  => (string) $preview['resolution'],
			'source_status'               => (string) $preview['source_status'],
			'source_evidence_fingerprint' => (string) $preview['source_evidence_fingerprint'],
			'unique'                      => true,
			'preview_digest'              => (string) $preview['preview_digest'],
			'reconciled_by'               => get_current_user_id(),
			'reconciled_at'               => gmdate( 'c' ),
		);
		$json     = $this->canonical_json( $evidence );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$evidence['evidence_fingerprint'] = 'sha256:' . hash( 'sha256', $json );

		return $evidence;
	}

	/** Build the terminal no-effect result for a restored exact before-state. */
	private function reconciliation_no_effect_result( $request, $preview, $evidence ) {
		return array(
			'schema'                              => self::RECONCILIATION_SCHEMA,
			'status'                              => 'reconciled_no_effect',
			'changed'                             => false,
			'replayed'                            => false,
			'product_id'                          => $request['product_id'],
			'product_code'                        => $preview['observed_product_code'],
			'revision'                            => $preview['observed_revision'],
			'request_id'                          => $request['request_id'],
			'request_fingerprint'                 => $request['fingerprint'],
			'record_fingerprint'                  => $preview['record_fingerprint'],
			'preview_digest'                      => $preview['preview_digest'],
			'reconciliation_evidence_fingerprint' => $evidence['evidence_fingerprint'],
			'verification'                        => array(
				'database_readback' => true,
				'cache_bypassed'    => true,
				'unique'            => true,
				'source_checked'    => true,
				'no_code_write'     => true,
			),
		);
	}

	/** Return an effect-free reconciliation replay with fresh current readback. */
	private function replayed_reconciliation_result( $record, $request ) {
		$evidence = $record['reconciliation'] ?? null;
		if ( ! is_array( $evidence ) ) {
			return $this->error(
				'digitalogic_product_code_reconciliation_not_required',
				__( 'This Product Code operation has no reconciliation record.', 'digitalogic' ),
				409
			);
		}
		$current = $this->read_exact_product_code( $request['product_id'] );
		if ( is_wp_error( $current ) || ! $current['product_exists'] || $current['duplicate_rows'] || ! empty( $current['invalid_key_rows'] ) ) {
			return $this->database_unavailable();
		}
		$result                         = (array) ( $record['result'] ?? array() );
		$result['replayed']             = true;
		$result['current_product_code'] = (string) $current['product_code'];
		$result['current_revision']     = $this->revision_for( $request['product_id'], $current['product_code'] );
		$result['current_readback']     = array(
			'database_readback' => true,
			'cache_bypassed'    => true,
		);

		return $result;
	}

	/**
	 * Persist an interrupted request as terminally ambiguous.
	 *
	 * @param array  $request Validated request.
	 * @param array  $existing Existing operation record.
	 * @param array  $current Exact database readback.
	 * @param string $reason Safe machine reason.
	 * @return WP_Error
	 */
	private function mark_operation_outcome_unknown( $request, $existing, $current, $reason ) {
		$unknown                       = $existing;
		$unknown['status']             = 'outcome_unknown';
		$unknown['failure_code']       = (string) $reason;
		$unknown['observed_code_hash'] = 'sha256:' . hash( 'sha256', (string) ( $current['product_code'] ?? '' ) );
		$unknown['updated_at']         = gmdate( 'c' );
		$stored                        = $this->store_operation( $request['request_id'], $unknown );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $this->error(
			'digitalogic_product_code_outcome_unknown',
			__( 'The interrupted Product Code edit cannot be finalized safely and requires exact reconciliation.', 'digitalogic' ),
			409,
			array(
				'retryable'        => false,
				'reason'           => (string) $reason,
				'backup_reference' => (string) ( $existing['backup_reference'] ?? '' ),
			)
		);
	}

	/**
	 * Return a typed error unless the product belongs to the current admin batch.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return null|WP_Error
	 */
	private function admin_read_context_error( $product_id ) {
		$actor_id = get_current_user_id();
		$blog_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if (
			$product_id <= 0
			|| ! isset( $this->editability_prepared_ids[ $product_id ] )
			|| $this->editability_actor_id !== $actor_id
			|| $this->editability_blog_id !== $blog_id
		) {
			return $this->error(
				'digitalogic_product_code_admin_read_unprepared',
				__( 'The Product Code row was not prepared for this exact admin response. Reload the page.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		return null;
	}

	/**
	 * Return one exact prepared metadata/provenance row without a fallback query.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array|WP_Error
	 */
	private function admin_readback_for( $product_id ) {
		$context = $this->admin_read_context_error( $product_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( is_wp_error( $this->editability_batch_error ) ) {
			return $this->editability_batch_error;
		}
		if ( ! array_key_exists( $product_id, $this->editability_readbacks ) ) {
			return $this->database_unavailable();
		}

		return $this->editability_readbacks[ $product_id ];
	}

	/**
	 * Build one validated source ownership index for the complete admin response.
	 *
	 * @param array $state Exact source state.
	 * @return array|WP_Error
	 */
	private function build_editability_source_index( $state ) {
		++$this->editability_source_index_builds;
		if (
			! is_array( $state )
			|| ! is_array( $state['sources'] ?? null )
			|| ! is_array( $state['legacy_feed_products'] ?? null )
		) {
			return $this->source_state_malformed( 'sources' );
		}

		$index = array(
			'codes'       => array(),
			'product_ids' => array(),
		);
		foreach ( array_values( $state['sources'] ) as $source_index => $source_state ) {
			if ( ! is_array( $source_state ) || ! is_array( $source_state['source'] ?? null ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . ']' );
			}
			foreach ( array( 'id', 'dataset', 'revision' ) as $source_field ) {
				$value = $source_state['source'][ $source_field ] ?? null;
				if ( ! is_string( $value ) || '' === $value ) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].source.' . $source_field );
				}
			}
			if ( 1 !== preg_match( self::REVISION_PATTERN, $source_state['source']['revision'] ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].source.revision' );
			}
			if ( ! is_array( $source_state['products'] ?? null ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].products' );
			}
			foreach ( $source_state['products'] as $product_index => $source_product ) {
				if (
					! is_array( $source_product )
					|| ! is_string( $source_product['product_code'] ?? null )
					|| '' === $source_product['product_code']
					|| ! hash_equals( (string) $product_index, $source_product['product_code'] )
					|| ! is_string( $source_product['record_hash'] ?? null )
					|| 1 !== preg_match( self::RECORD_HASH_PATTERN, $source_product['record_hash'] )
				) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].products[' . $product_index . ']' );
				}
				$index['codes'][ hash( 'sha256', $source_product['product_code'] ) ] = true;
			}
			foreach ( array( 'applied_products', 'pending_products', 'deferred_products' ) as $set_name ) {
				if ( ! is_array( $source_state[ $set_name ] ?? null ) ) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name );
				}
				foreach ( $source_state[ $set_name ] as $entry_index => $entry ) {
					if (
						! is_array( $entry )
						|| ! is_string( $entry['product_code'] ?? null )
						|| '' === $entry['product_code']
						|| ! hash_equals( (string) $entry_index, $entry['product_code'] )
						|| ! is_string( $entry['record_hash'] ?? null )
						|| 1 !== preg_match( self::RECORD_HASH_PATTERN, $entry['record_hash'] )
					) {
						return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name . '[' . $entry_index . ']' );
					}
					$entry_id = is_int( $entry['woocommerce_id'] ?? null ) || is_string( $entry['woocommerce_id'] ?? null )
						? (string) $entry['woocommerce_id']
						: '';
					if (
						( 'applied_products' === $set_name || array_key_exists( 'woocommerce_id', $entry ) )
						&& ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $entry_id ) || (string) absint( $entry_id ) !== $entry_id )
					) {
						return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name . '[' . $entry_index . '].woocommerce_id' );
					}
					$index['codes'][ hash( 'sha256', $entry['product_code'] ) ] = true;
					$product_id = absint( $entry_id );
					if ( $product_id > 0 ) {
						$index['product_ids'][ (string) $product_id ] = true;
					}
				}
			}
		}
		foreach ( $state['legacy_feed_products'] as $legacy_index => $legacy_product ) {
			if (
				! is_array( $legacy_product )
				|| ! is_string( $legacy_product['product_code'] ?? null )
				|| '' === $legacy_product['product_code']
				|| ! hash_equals( (string) $legacy_index, $legacy_product['product_code'] )
			) {
				return $this->source_state_malformed( 'legacy_feed_products[' . $legacy_index . ']' );
			}
			$index['codes'][ hash( 'sha256', $legacy_product['product_code'] ) ] = true;
		}

		return $index;
	}

	/**
	 * Classify exact on-product provenance without walking the shared source snapshot.
	 *
	 * @param array $readback Exact database readback.
	 * @return string|WP_Error
	 */
	private function editability_provenance_status( $readback ) {
		$owner        = is_array( $readback['owner'] ?? null ) ? $readback['owner'] : null;
		$owner_counts = is_array( $readback['owner_row_counts'] ?? null ) ? $readback['owner_row_counts'] : null;
		$hash_count   = (int) ( $readback['record_hash_row_count'] ?? -1 );
		$hash_value   = $readback['record_hash'] ?? null;
		if ( null === $owner || null === $owner_counts || $hash_count < 0 || ! is_string( $hash_value ) ) {
			return $this->source_state_malformed( 'product_provenance' );
		}
		if ( $hash_count > 0 ) {
			return 'managed';
		}
		if ( '' !== $hash_value ) {
			return $this->source_state_malformed( 'product_provenance.record_hash' );
		}
		$managed = false;
		foreach ( array( 'source_id', 'dataset', 'product_code' ) as $field ) {
			$count = (int) ( $owner_counts[ $field ] ?? -1 );
			$value = $owner[ $field ] ?? null;
			if ( $count < 0 || ! is_string( $value ) || ( 0 === $count && '' !== $value ) ) {
				return $this->source_state_malformed( 'product_owner_provenance.' . $field );
			}
			$managed = $managed || $count > 0;
		}

		return $managed ? 'managed' : 'unmanaged';
	}

	/**
	 * Reject source-owned or source-conflicting identity changes.
	 *
	 * @param int        $product_id Target ID.
	 * @param string     $expected_code Current code.
	 * @param string     $product_code Desired code.
	 * @param array      $provenance Exact current Product Code/provenance readback.
	 * @param array|null $state Exact source state supplied by a read-only UI cache.
	 * @return array|WP_Error
	 */
	private function source_guard( $product_id, $expected_code, $product_code, $provenance, $state = null ) {
		$reasons            = array();
		$sources            = array();
		$governance_sources = array();
		$record_hash_count  = is_array( $provenance ) ? (int) ( $provenance['record_hash_row_count'] ?? 0 ) : -1;
		$record_hash        = is_array( $provenance ) ? (string) ( $provenance['record_hash'] ?? '' ) : '';
		$owner_counts       = is_array( $provenance ) && is_array( $provenance['owner_row_counts'] ?? null )
			? $provenance['owner_row_counts']
			: null;
		$owner_values       = is_array( $provenance ) && is_array( $provenance['owner'] ?? null )
			? $provenance['owner']
			: null;
		if ( null === $owner_counts || null === $owner_values ) {
			return $this->source_state_malformed( 'product_owner_provenance' );
		}
		$owner_fields  = array( 'source_id', 'dataset', 'product_code' );
		$owner_present = 0;
		foreach ( $owner_fields as $owner_field ) {
			$count = (int) ( $owner_counts[ $owner_field ] ?? -1 );
			$value = $owner_values[ $owner_field ] ?? null;
			if ( $count < 0 || ! is_string( $value ) ) {
				return $this->source_state_malformed( 'product_owner_provenance.' . $owner_field );
			}
			if ( $count > 0 ) {
				++$owner_present;
			}
			if ( $count > 1 ) {
				$reasons[] = 'duplicate_materializer_owner_provenance';
			} elseif ( 1 === $count && '' === $value ) {
				$reasons[] = 'empty_materializer_owner_provenance';
			}
		}
		if ( $owner_present > 0 && count( $owner_fields ) !== $owner_present ) {
			$reasons[] = 'partial_materializer_owner_provenance';
		}
		if ( count( $owner_fields ) === $owner_present && empty( $reasons ) ) {
			$reasons[] = hash_equals( $expected_code, $owner_values['product_code'] )
				? 'managed_materializer_owner'
				: 'materializer_owner_identity_conflict';
		}
		if ( $record_hash_count < 0 ) {
			return $this->source_state_malformed( 'product_provenance' );
		}
		if ( $record_hash_count > 1 ) {
			$reasons[] = 'duplicate_record_hash_provenance';
		} elseif ( 1 === $record_hash_count ) {
			if ( '' === $record_hash ) {
				$reasons[] = 'empty_record_hash_provenance';
			} else {
				$reasons[] = 1 === preg_match( self::RECORD_HASH_PATTERN, $record_hash )
					? 'managed_record_hash'
					: 'invalid_record_hash_provenance';
			}
		}
		if ( ! empty( $reasons ) ) {
			return $this->error(
				'digitalogic_product_code_source_managed',
				__( 'This Product Code is governed by the current source. Correct it upstream and deliver the reviewed source revision.', 'digitalogic' ),
				409,
				array(
					'reasons' => array_values( array_unique( $reasons ) ),
					'sources' => array(),
				)
			);
		}

		if ( null === $state ) {
			$state = $this->read_exact_source_state();
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}
		if ( ! is_array( $state ) || ! is_array( $state['sources'] ?? null ) ) {
			return $this->source_state_malformed( 'sources' );
		}

		foreach ( array_values( $state['sources'] ) as $source_index => $source_state ) {
			if ( ! is_array( $source_state ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . ']' );
			}
			if ( ! is_array( $source_state['source'] ?? null ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].source' );
			}
			$source = $source_state['source'];
			foreach ( array( 'id', 'dataset', 'revision' ) as $source_field ) {
				if ( ! is_string( $source[ $source_field ] ?? null ) || '' === $source[ $source_field ] ) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].source.' . $source_field );
				}
			}
			if ( 1 !== preg_match( self::REVISION_PATTERN, $source['revision'] ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].source.revision' );
			}
			$governance_sources[] = array(
				'id'       => $source['id'],
				'dataset'  => $source['dataset'],
				'revision' => $source['revision'],
			);
			if ( ! is_array( $source_state['products'] ?? null ) ) {
				return $this->source_state_malformed( 'sources[' . $source_index . '].products' );
			}
			$products = $source_state['products'];
			$matched  = false;
			foreach ( $products as $product_index => $source_product ) {
				if (
					! is_array( $source_product )
					|| ! is_string( $source_product['product_code'] ?? null )
					|| '' === $source_product['product_code']
					|| ! hash_equals( (string) $product_index, $source_product['product_code'] )
					|| ! is_string( $source_product['record_hash'] ?? null )
					|| 1 !== preg_match( self::RECORD_HASH_PATTERN, $source_product['record_hash'] )
				) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].products[' . $product_index . ']' );
				}
				$source_code = $source_product['product_code'];
				if ( hash_equals( $expected_code, $source_code ) ) {
					$reasons[] = 'current_code_in_source';
					$matched   = true;
				}
				if ( hash_equals( $product_code, $source_code ) ) {
					$reasons[] = 'desired_code_in_source';
					$matched   = true;
				}
			}
			foreach ( array( 'applied_products', 'pending_products', 'deferred_products' ) as $set_name ) {
				if ( ! is_array( $source_state[ $set_name ] ?? null ) ) {
					return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name );
				}
				foreach ( $source_state[ $set_name ] as $entry_index => $entry ) {
					if (
						! is_array( $entry )
						|| ! is_string( $entry['product_code'] ?? null )
						|| '' === $entry['product_code']
						|| ! hash_equals( (string) $entry_index, $entry['product_code'] )
						|| ! is_string( $entry['record_hash'] ?? null )
						|| 1 !== preg_match( self::RECORD_HASH_PATTERN, $entry['record_hash'] )
					) {
						return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name . '[' . $entry_index . ']' );
					}
					$entry_code = $entry['product_code'];
					$entry_id   = is_int( $entry['woocommerce_id'] ?? null ) || is_string( $entry['woocommerce_id'] ?? null )
						? (string) $entry['woocommerce_id']
						: '';
					if (
						( 'applied_products' === $set_name || array_key_exists( 'woocommerce_id', $entry ) )
						&& ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $entry_id ) || (string) absint( $entry_id ) !== $entry_id )
					) {
						return $this->source_state_malformed( 'sources[' . $source_index . '].' . $set_name . '[' . $entry_index . '].woocommerce_id' );
					}
					if ( (string) $product_id === $entry_id ) {
						$reasons[] = 'product_bound_to_source';
						$matched   = true;
					}
					if ( hash_equals( $expected_code, $entry_code ) ) {
						$reasons[] = 'current_code_in_delivery_state';
						$matched   = true;
					}
					if ( '' !== $entry_code && hash_equals( $product_code, $entry_code ) ) {
						$reasons[] = 'desired_code_in_delivery_state';
						$matched   = true;
					}
				}
			}
			if ( $matched ) {
				$sources[] = array(
					'id'       => (string) ( $source['id'] ?? '' ),
					'dataset'  => (string) ( $source['dataset'] ?? '' ),
					'revision' => (string) ( $source['revision'] ?? '' ),
				);
			}
		}

		if ( ! is_array( $state['legacy_feed_products'] ?? null ) ) {
			return $this->source_state_malformed( 'legacy_feed_products' );
		}
		$legacy_products = $state['legacy_feed_products'];
		$legacy_matched  = false;
		foreach ( $legacy_products as $legacy_index => $legacy_product ) {
			if (
				! is_array( $legacy_product )
				|| ! is_string( $legacy_product['product_code'] ?? null )
				|| '' === $legacy_product['product_code']
				|| ! hash_equals( (string) $legacy_index, $legacy_product['product_code'] )
			) {
				return $this->source_state_malformed( 'legacy_feed_products[' . $legacy_index . ']' );
			}
			$legacy_code = $legacy_product['product_code'];
			if ( hash_equals( $expected_code, $legacy_code ) ) {
				$reasons[]      = 'current_code_in_legacy_feed';
				$legacy_matched = true;
			}
			if ( hash_equals( $product_code, $legacy_code ) ) {
				$reasons[]      = 'desired_code_in_legacy_feed';
				$legacy_matched = true;
			}
		}
		$legacy_json = $this->canonical_json( $legacy_products );
		if ( is_wp_error( $legacy_json ) ) {
			return $this->source_state_malformed( 'legacy_feed_products' );
		}
		$legacy_fingerprint = 'sha256:' . hash( 'sha256', $legacy_json );
		if ( $legacy_matched ) {
			$sources[] = array(
				'source_class'      => 'legacy_feed',
				'state_fingerprint' => $legacy_fingerprint,
			);
		}

		$reasons = array_values( array_unique( $reasons ) );
		if ( ! empty( $reasons ) ) {
			return $this->error(
				'digitalogic_product_code_source_managed',
				__( 'This Product Code is governed by the current source. Correct it upstream and deliver the reviewed source revision.', 'digitalogic' ),
				409,
				array(
					'reasons' => $reasons,
					'sources' => $sources,
				)
			);
		}

		usort(
			$governance_sources,
			static function ( $left, $right ) {
				return strcmp(
					(string) $left['id'] . "\0" . (string) $left['dataset'] . "\0" . (string) $left['revision'],
					(string) $right['id'] . "\0" . (string) $right['dataset'] . "\0" . (string) $right['revision']
				);
			}
		);
		$state_json = $this->canonical_json( $state );
		if ( is_wp_error( $state_json ) ) {
			return $this->source_state_malformed( 'sources' );
		}
		$proof      = array(
			'schema'                   => self::GOVERNANCE_SCHEMA,
			'sources'                  => $governance_sources,
			'source_state_fingerprint' => 'sha256:' . hash( 'sha256', $state_json ),
			'legacy_feed'              => array(
				'row_count'         => count( $legacy_products ),
				'state_fingerprint' => $legacy_fingerprint,
			),
			'checked'                  => array(
				'current_code_hash' => 'sha256:' . hash( 'sha256', $expected_code ),
				'desired_code_hash' => 'sha256:' . hash( 'sha256', $product_code ),
			),
		);
		$proof_json = $this->canonical_json( $proof );
		if ( is_wp_error( $proof_json ) ) {
			return $this->source_state_malformed( 'governance_proof' );
		}
		$proof['evidence_fingerprint'] = 'sha256:' . hash( 'sha256', $proof_json );

		return array(
			'managed' => false,
			'proof'   => $proof,
		);
	}

	/**
	 * Read product-sync source ownership directly from the options table.
	 *
	 * The source guard must not trust a stale object-cache entry while deciding
	 * whether a canonical identity is safe to edit.
	 *
	 * @return array|WP_Error
	 */
	private function read_exact_source_state() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $this->database_unavailable();
		}
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table name cannot be a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_source_state */
			SELECT option_value
			FROM {$options}
			WHERE option_name = %s
			LIMIT 1",
			Digitalogic_Product_Sync_Receiver::STATE_OPTION
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->database_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Source ownership must bypass object caches.
		$row   = $wpdb->get_row( $query, ARRAY_A );
		$state = array( 'sources' => array() );
		if ( null === $row ) {
			if ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) ) {
				return $this->error(
					'digitalogic_product_code_source_state_unavailable',
					__( 'The product-sync source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
					503,
					array( 'retryable' => true )
				);
			}
		} elseif ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return $this->error(
				'digitalogic_product_code_source_state_unavailable',
				__( 'The product-sync source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		} else {
			$state = maybe_unserialize( $row['option_value'] );
			if ( ! is_array( $state ) || ! is_array( $state['sources'] ?? null ) ) {
				return $this->error(
					'digitalogic_product_code_source_state_unavailable',
					__( 'The product-sync source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
					503,
					array( 'retryable' => true )
				);
			}
		}

		$legacy = $this->read_exact_option( self::LEGACY_FEED_PRODUCTS_OPTION );
		if ( is_wp_error( $legacy ) ) {
			return $this->error(
				'digitalogic_product_code_source_state_unavailable',
				__( 'The legacy catalog source state is unavailable, so the identity edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		if ( $legacy['exists'] && ! is_array( $legacy['value'] ) ) {
			return $this->source_state_malformed( 'legacy_feed_products' );
		}
		$state['legacy_feed_products'] = $legacy['exists'] ? $legacy['value'] : array();

		return $state;
	}

	/**
	 * Read Product Code and provenance directly from the database.
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error
	 */
	private function read_exact_product_code( $product_id ) {
		$product_id = absint( $product_id );
		$readbacks  = $this->read_exact_product_codes( array( $product_id ) );
		if ( is_wp_error( $readbacks ) ) {
			return $readbacks;
		}

		return $readbacks[ $product_id ] ?? $this->empty_exact_product_code_readback();
	}

	/**
	 * Read one bounded set of Product Code/provenance rows in a single query.
	 *
	 * @param int[] $product_ids Exact product and variation IDs.
	 * @return array<int,array>|WP_Error
	 */
	private function read_exact_product_codes( $product_ids ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->database_unavailable();
		}
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
		if ( empty( $product_ids ) ) {
			return array();
		}
		if ( count( $product_ids ) > 101 ) {
			return $this->database_unavailable();
		}
		$posts           = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts';
		$postmeta        = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		$id_placeholders = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );
		$params          = array_merge(
			array(
				self::META_KEY,
				'_digitalogic_patris_record_hash',
				self::OWNER_SOURCE_META,
				self::OWNER_DATASET_META,
				self::OWNER_CODE_META,
			),
			$product_ids
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- wpdb-owned tables and a bounded variadic placeholder list cannot be expressed statically.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_readback_batch */
			SELECT p.ID, p.post_type, p.post_status, pm.meta_id, pm.meta_key, pm.meta_value
			FROM {$posts} p
			LEFT JOIN {$postmeta} pm
				ON pm.post_id = p.ID
				AND (
					LOWER(CONVERT(pm.meta_key USING utf8mb4)) COLLATE utf8mb4_bin = LOWER(CONVERT(%s USING utf8mb4)) COLLATE utf8mb4_bin
					OR LOWER(CONVERT(pm.meta_key USING utf8mb4)) COLLATE utf8mb4_bin = LOWER(CONVERT(%s USING utf8mb4)) COLLATE utf8mb4_bin
					OR LOWER(CONVERT(pm.meta_key USING utf8mb4)) COLLATE utf8mb4_bin = LOWER(CONVERT(%s USING utf8mb4)) COLLATE utf8mb4_bin
					OR LOWER(CONVERT(pm.meta_key USING utf8mb4)) COLLATE utf8mb4_bin = LOWER(CONVERT(%s USING utf8mb4)) COLLATE utf8mb4_bin
					OR LOWER(CONVERT(pm.meta_key USING utf8mb4)) COLLATE utf8mb4_bin = LOWER(CONVERT(%s USING utf8mb4)) COLLATE utf8mb4_bin
				)
			WHERE p.ID IN ({$id_placeholders})
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status <> 'auto-draft'
			ORDER BY p.ID ASC, pm.meta_key ASC, pm.meta_id ASC",
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( false === $query ) {
			return $this->database_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact cache-bypassed mutation readback is required.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $this->database_unavailable();
		}
		$grouped = array_fill_keys( $product_ids, array() );
		foreach ( $rows as $row ) {
			$row_id = absint( $row['ID'] ?? 0 );
			if ( ! array_key_exists( $row_id, $grouped ) ) {
				return $this->database_unavailable();
			}
			$grouped[ $row_id ][] = $row;
		}
		$readbacks = array();
		foreach ( $grouped as $product_id => $product_rows ) {
			$readbacks[ $product_id ] = empty( $product_rows )
				? $this->empty_exact_product_code_readback()
				: $this->parse_exact_product_code_rows( $product_rows );
		}

		return $readbacks;
	}

	/** Return a canonical absent-product readback. */
	private function empty_exact_product_code_readback() {
		return array(
			'product_exists'        => false,
			'post_type'             => '',
			'post_status'           => '',
			'meta_exists'           => false,
			'product_code'          => '',
			'record_hash'           => '',
			'record_hash_row_count' => 0,
			'owner'                 => array(
				'source_id'    => '',
				'dataset'      => '',
				'product_code' => '',
			),
			'owner_row_counts'      => array(
				'source_id'    => 0,
				'dataset'      => 0,
				'product_code' => 0,
			),
			'row_count'             => 0,
			'duplicate_rows'        => false,
			'invalid_key_rows'      => 0,
		);
	}

	/**
	 * Parse one product's exact rows into the canonical readback shape.
	 *
	 * @param array[] $rows Exact metadata rows for one product.
	 * @return array
	 */
	private function parse_exact_product_code_rows( $rows ) {

		$code_rows        = array();
		$record_hash_rows = array();
		$owner_rows       = array(
			'source_id'    => array(),
			'dataset'      => array(),
			'product_code' => array(),
		);
		$invalid_key_rows = 0;
		foreach ( $rows as $row ) {
			$key = (string) ( $row['meta_key'] ?? '' );
			if ( self::META_KEY === $key ) {
				$code_rows[] = (string) ( $row['meta_value'] ?? '' );
			} elseif ( '_digitalogic_patris_record_hash' === $key ) {
				$record_hash_rows[] = (string) ( $row['meta_value'] ?? '' );
			} elseif ( self::OWNER_SOURCE_META === $key ) {
				$owner_rows['source_id'][] = (string) ( $row['meta_value'] ?? '' );
			} elseif ( self::OWNER_DATASET_META === $key ) {
				$owner_rows['dataset'][] = (string) ( $row['meta_value'] ?? '' );
			} elseif ( self::OWNER_CODE_META === $key ) {
				$owner_rows['product_code'][] = (string) ( $row['meta_value'] ?? '' );
			} elseif (
				0 === strcasecmp( self::META_KEY, $key )
				|| 0 === strcasecmp( '_digitalogic_patris_record_hash', $key )
				|| 0 === strcasecmp( self::OWNER_SOURCE_META, $key )
				|| 0 === strcasecmp( self::OWNER_DATASET_META, $key )
				|| 0 === strcasecmp( self::OWNER_CODE_META, $key )
			) {
				++$invalid_key_rows;
			}
		}

		return array(
			'product_exists'        => true,
			'post_type'             => (string) ( $rows[0]['post_type'] ?? '' ),
			'post_status'           => (string) ( $rows[0]['post_status'] ?? '' ),
			'meta_exists'           => ! empty( $code_rows ),
			'product_code'          => empty( $code_rows ) ? '' : (string) end( $code_rows ),
			'record_hash'           => empty( $record_hash_rows ) ? '' : (string) end( $record_hash_rows ),
			'record_hash_row_count' => count( $record_hash_rows ),
			'owner'                 => array(
				'source_id'    => empty( $owner_rows['source_id'] ) ? '' : (string) end( $owner_rows['source_id'] ),
				'dataset'      => empty( $owner_rows['dataset'] ) ? '' : (string) end( $owner_rows['dataset'] ),
				'product_code' => empty( $owner_rows['product_code'] ) ? '' : (string) end( $owner_rows['product_code'] ),
			),
			'owner_row_counts'      => array(
				'source_id'    => count( $owner_rows['source_id'] ),
				'dataset'      => count( $owner_rows['dataset'] ),
				'product_code' => count( $owner_rows['product_code'] ),
			),
			'row_count'             => count( $code_rows ),
			'duplicate_rows'        => count( $code_rows ) > 1,
			'invalid_key_rows'      => $invalid_key_rows,
		);
	}

	/**
	 * Query exact collisions across products and variations.
	 *
	 * @param int    $product_id Target ID.
	 * @param string $product_code Desired code.
	 * @return array|WP_Error
	 */
	private function find_code_conflicts( $product_id, $product_code ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->database_unavailable();
		}
		$posts    = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts';
		$postmeta = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table names cannot be placeholders.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_conflicts */
			SELECT DISTINCT p.ID, p.post_type
			FROM {$postmeta} pm
			INNER JOIN {$posts} p ON p.ID = pm.post_id
			WHERE LOWER(CONVERT(pm.meta_key USING utf8mb4)) COLLATE utf8mb4_bin
				= LOWER(CONVERT(%s USING utf8mb4)) COLLATE utf8mb4_bin
				AND BINARY pm.meta_value = BINARY %s
				AND p.ID <> %d
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status <> 'auto-draft'
			ORDER BY p.ID ASC
			LIMIT 3",
			self::META_KEY,
			$product_code,
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->database_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact uniqueness must bypass caches.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		return is_array( $rows ) ? $rows : $this->database_unavailable();
	}

	/**
	 * Restore the exact prior presence/value and verify it from the database.
	 *
	 * @param array $before Exact backup.
	 * @param int   $product_id Product ID.
	 * @return bool
	 */
	private function restore_backup( $before, $product_id ) {
		if ( ! $this->mutation_locks_are_owned( $product_id ) ) {
			return false;
		}
		$restored_write = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
			'editor',
			array(
				'product_id' => $product_id,
				'operation'  => $before['meta_exists'] ? 'set' : 'delete',
				'value'      => $before['product_code'],
			),
			function () use ( $before, $product_id ) {
				if ( $before['meta_exists'] ) {
					return update_post_meta( $product_id, self::META_KEY, $before['product_code'] );
				}

				return delete_post_meta( $product_id, self::META_KEY );
			}
		);
		if ( is_wp_error( $restored_write ) || ! $this->mutation_locks_are_owned( $product_id ) ) {
			return false;
		}
		$this->invalidate_product_identity_cache( $product_id );
		$restored = $this->read_exact_product_code( $product_id );

		return $this->mutation_locks_are_owned( $product_id )
			&& ! is_wp_error( $restored )
			&& $restored['meta_exists'] === $before['meta_exists']
			&& hash_equals( $before['product_code'], $restored['product_code'] )
			&& ! $restored['duplicate_rows']
			&& empty( $restored['invalid_key_rows'] );
	}

	/**
	 * Roll back a failed write and preserve a resumable audit state.
	 *
	 * @param array  $request Request.
	 * @param array  $before Backup.
	 * @param array  $claim In-progress record.
	 * @param string $code Typed error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	private function rollback_after_failure( $request, $before, $claim, $code, $message ) {
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required(
				$request,
				(string) ( $claim['backup_reference'] ?? '' ),
				'rollback_lock_lost'
			);
		}
		$rollback_checkpoint = $this->projection_checkpoint( $request['product_id'] );
		if ( is_wp_error( $rollback_checkpoint ) || ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required(
				$request,
				(string) ( $claim['backup_reference'] ?? '' ),
				'rollback_lock_lost'
			);
		}
		$rollback_verified = $this->restore_backup( $before, $request['product_id'] );
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required(
				$request,
				(string) ( $claim['backup_reference'] ?? '' ),
				'rollback_lock_lost'
			);
		}
		$projection            = $rollback_verified && ! is_wp_error( $rollback_checkpoint )
			? $this->verify_projection_checkpoint( $rollback_checkpoint )
			: $this->error( 'digitalogic_product_code_projection_pending', $message, 503 );
		$projection_verified   = ! is_wp_error( $projection );
		$claim['status']       = $rollback_verified ? 'failed_retryable' : 'outcome_unknown';
		$claim['rollback']     = array(
			'attempted'           => true,
			'verified'            => $rollback_verified,
			'projection_verified' => $projection_verified,
		);
		$claim['failure_code'] = $projection_verified ? $code : 'digitalogic_product_code_projection_pending';
		if ( $projection_verified ) {
			$claim['projection'] = $projection;
		}
		$claim['updated_at']      = gmdate( 'c' );
		$claim['observed_at_end'] = $rollback_verified ? 'before' : 'unknown';
		if ( ! $this->mutation_locks_are_owned( $request['product_id'] ) ) {
			return $this->same_request_recovery_required(
				$request,
				(string) ( $claim['backup_reference'] ?? '' ),
				'rollback_lock_lost'
			);
		}
		$this->store_operation( $request['request_id'], $claim );

		return $this->error(
			$rollback_verified ? ( $projection_verified ? $code : 'digitalogic_product_code_projection_pending' ) : 'digitalogic_product_code_outcome_unknown',
			$rollback_verified
				? ( $projection_verified ? $message : __( 'The Product Code was restored, but its catalog revision still needs same-request recovery.', 'digitalogic' ) )
				: __( 'The Product Code rollback did not pass exact readback.', 'digitalogic' ),
			$rollback_verified ? 503 : 409,
			array(
				'retryable'         => $rollback_verified,
				'rollback_verified' => $rollback_verified,
				'backup_reference'  => (string) ( $claim['backup_reference'] ?? '' ),
			)
		);
	}

	/**
	 * Narrowly invalidate only the affected product identity caches.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function invalidate_product_identity_cache( $product_id ) {
		wp_cache_delete( (int) $product_id, 'post_meta' );
		clean_post_cache( (int) $product_id );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . (int) $product_id );
		}
	}

	/** Capture the exact pre-write Living projection generation. */
	private function projection_checkpoint( $product_id ) {
		if ( ! class_exists( 'Digitalogic_Report_Engine' ) || ! class_exists( 'Digitalogic_Pricing_Snapshot' ) ) {
			return $this->error(
				'digitalogic_product_code_projection_unavailable',
				__( 'The catalog revision service is unavailable, so the Product Code edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		Digitalogic_Pricing_Snapshot::instance();
		$engine     = Digitalogic_Report_Engine::instance();
		$generation = $engine->current_projection_generation();
		if ( is_wp_error( $generation ) ) {
			return $this->error(
				'digitalogic_product_code_projection_unavailable',
				__( 'The catalog revision service is unavailable, so the Product Code edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		$probe = $engine->begin_product_meta_invalidation_probe( $product_id, self::META_KEY );
		if ( is_wp_error( $probe ) ) {
			return $this->error(
				'digitalogic_product_code_projection_unavailable',
				__( 'The catalog revision service is unavailable, so the Product Code edit was stopped.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		return array(
			'generation_before'      => $generation,
			'generation_before_hash' => 'sha256:' . hash( 'sha256', $generation ),
			'effect_probe'           => $probe,
			'verified'               => false,
		);
	}

	/** Prove persistent generation advancement and durable coalesced eventing. */
	private function verify_projection_checkpoint( $checkpoint, $allow_recovery_invalidation = false ) {
		$before = is_array( $checkpoint ) ? (string) ( $checkpoint['generation_before'] ?? '' ) : '';
		$probe  = is_array( $checkpoint ) ? (string) ( $checkpoint['effect_probe'] ?? '' ) : '';
		if (
			'' === $before
			|| '' === $probe
			|| ! hash_equals( 'sha256:' . hash( 'sha256', $before ), (string) ( $checkpoint['generation_before_hash'] ?? '' ) )
		) {
			return $this->error(
				'digitalogic_product_code_projection_unavailable',
				__( 'The saved catalog revision checkpoint is invalid.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}
		$engine            = Digitalogic_Report_Engine::instance();
		$effect            = $engine->consume_product_meta_invalidation_probe( $probe );
		$effect_generation = is_array( $effect ) ? (string) ( $effect['generation'] ?? '' ) : '';
		if ( '' === $effect_generation && $allow_recovery_invalidation ) {
			$recovery_before = $engine->current_projection_generation();
			if ( ! is_wp_error( $recovery_before ) && $engine->invalidate_cache() ) {
				$effect_generation = $engine->current_projection_generation();
				if ( is_wp_error( $effect_generation ) || hash_equals( (string) $recovery_before, (string) $effect_generation ) ) {
					$effect_generation = '';
				}
			}
		}
		$after = $engine->current_projection_generation();
		if (
			'' === $effect_generation
			|| hash_equals( $before, $effect_generation )
			|| is_wp_error( $after )
			|| ! Digitalogic_Pricing_Snapshot::instance()->ensure_state_revision_event()
		) {
			return $this->error(
				'digitalogic_product_code_projection_pending',
				__( 'The Product Code catalog revision could not be made durable.', 'digitalogic' ),
				503,
				array( 'retryable' => true )
			);
		}

		return array(
			'generation_before'            => $before,
			'generation_before_hash'       => 'sha256:' . hash( 'sha256', $before ),
			'generation_after'             => $after,
			'generation_after_hash'        => 'sha256:' . hash( 'sha256', $after ),
			'effect_generation'            => $effect_generation,
			'effect_generation_hash'       => 'sha256:' . hash( 'sha256', $effect_generation ),
			'state_revision_event_durable' => true,
			'verified'                     => true,
		);
	}

	/**
	 * Verify the exact intended one-row state.
	 *
	 * @param array  $readback Exact database readback.
	 * @param string $product_code Intended exact code.
	 * @return bool
	 */
	private function readback_matches( $readback, $product_code ) {
		return is_array( $readback )
			&& $readback['product_exists']
			&& $readback['meta_exists']
			&& 1 === $readback['row_count']
			&& ! $readback['duplicate_rows']
			&& empty( $readback['invalid_key_rows'] )
			&& hash_equals( $product_code, $readback['product_code'] );
	}

	/**
	 * Verify a genuinely absent canonical identity with no orphan provenance.
	 *
	 * @param array $readback Exact database readback.
	 * @return bool
	 */
	private function readback_is_exact_absent_identity( $readback ) {
		if ( ! is_array( $readback ) ) {
			return false;
		}
		$owner        = is_array( $readback['owner'] ?? null ) ? $readback['owner'] : array();
		$owner_counts = is_array( $readback['owner_row_counts'] ?? null ) ? $readback['owner_row_counts'] : array();

		return ! empty( $readback['product_exists'] )
			&& empty( $readback['meta_exists'] )
			&& '' === (string) ( $readback['product_code'] ?? '' )
			&& 0 === (int) ( $readback['row_count'] ?? -1 )
			&& empty( $readback['duplicate_rows'] )
			&& empty( $readback['invalid_key_rows'] )
			&& '' === (string) ( $readback['record_hash'] ?? '' )
			&& 0 === (int) ( $readback['record_hash_row_count'] ?? -1 )
			&& '' === (string) ( $owner['source_id'] ?? '' )
			&& '' === (string) ( $owner['dataset'] ?? '' )
			&& '' === (string) ( $owner['product_code'] ?? '' )
			&& 0 === (int) ( $owner_counts['source_id'] ?? -1 )
			&& 0 === (int) ( $owner_counts['dataset'] ?? -1 )
			&& 0 === (int) ( $owner_counts['product_code'] ?? -1 );
	}

	/**
	 * Build a stable backup reference without exposing unrelated data.
	 *
	 * @param array $request Validated request.
	 * @param array $before Exact before-state.
	 * @return string
	 */
	private function backup_reference( $request, $before, $governance_proof ) {
		$json = $this->canonical_json(
			array(
				'schema'              => self::SCHEMA,
				'product_id'          => (string) $request['product_id'],
				'product_code'        => $before['product_code'],
				'meta_exists'         => $before['meta_exists'],
				'request'             => $request['fingerprint'],
				'governance_evidence' => (string) ( $governance_proof['evidence_fingerprint'] ?? '' ),
			)
		);
		return is_wp_error( $json ) ? '' : 'sha256:' . hash( 'sha256', $json );
	}

	/**
	 * Build the public terminal result.
	 *
	 * @param array  $request Validated request.
	 * @param array  $before Exact before-state.
	 * @param array  $after Exact after-state.
	 * @param bool   $changed Whether a write occurred.
	 * @param string $backup_reference Stable backup reference.
	 * @return array
	 */
	private function success_result( $request, $before, $after, $changed, $backup_reference, $governance_proof, $projection = array() ) {
		$result = array(
			'schema'                          => self::SCHEMA,
			'status'                          => $changed ? 'applied' : 'unchanged',
			'changed'                         => (bool) $changed,
			'replayed'                        => false,
			'product_id'                      => $request['product_id'],
			'previous_product_code'           => $before['product_code'],
			'product_code'                    => $after['product_code'],
			'previous_revision'               => $this->revision_for( $request['product_id'], $before['product_code'] ),
			'revision'                        => $this->revision_for( $request['product_id'], $after['product_code'] ),
			'request_id'                      => $request['request_id'],
			'request_fingerprint'             => $request['fingerprint'],
			'backup_reference'                => $backup_reference,
			'governance_evidence_fingerprint' => (string) ( $governance_proof['evidence_fingerprint'] ?? '' ),
			'verification'                    => array(
				'database_readback'  => true,
				'cache_bypassed'     => true,
				'unique'             => true,
				'source_governance'  => true,
				'projection_current' => ! $changed || ! empty( $projection['verified'] ),
			),
		);
		if ( $changed ) {
			$result['projection'] = array(
				'generation_before_hash'       => (string) ( $projection['generation_before_hash'] ?? '' ),
				'generation_after_hash'        => (string) ( $projection['generation_after_hash'] ?? '' ),
				'effect_generation_hash'       => (string) ( $projection['effect_generation_hash'] ?? '' ),
				'state_revision_event_durable' => ! empty( $projection['state_revision_event_durable'] ),
			);
		}

		return $result;
	}

	/**
	 * Complete and verify one durable operation record.
	 *
	 * @param array $request Validated request.
	 * @param array $before Exact before-state.
	 * @param array $result Public terminal result.
	 * @param int   $attempts Attempt count.
	 * @return true|WP_Error
	 */
	private function complete_operation( $request, $before, $result, $attempts, $context = array() ) {
		$actor_id = isset( $context['actor_id'] ) ? (int) $context['actor_id'] : get_current_user_id();
		$record   = array(
			'schema'              => self::SCHEMA,
			'status'              => 'completed',
			'request_fingerprint' => $request['fingerprint'],
			'product_id'          => $request['product_id'],
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'backup_reference'    => $result['backup_reference'],
			'rollback_data'       => array(
				'meta_exists'  => $before['meta_exists'],
				'product_code' => $before['product_code'],
				'revision'     => $this->revision_for( $request['product_id'], $before['product_code'] ),
			),
			'actor_id'            => $actor_id,
			'governance_proof'    => $context['governance_proof'] ?? array(),
			'attempts'            => max( 1, (int) $attempts ),
			'result'              => $result,
			'updated_at'          => gmdate( 'c' ),
		);
		if ( isset( $context['recovered_by'] ) && (int) $context['recovered_by'] !== $actor_id ) {
			$record['recovered_by'] = (int) $context['recovered_by'];
		}
		if ( isset( $context['recovery_governance_proof'] ) ) {
			$record['recovery_governance_proof'] = $context['recovery_governance_proof'];
		}
		if ( isset( $context['projection'] ) ) {
			$record['projection'] = $context['projection'];
		}
		if ( isset( $context['reconciliation'] ) ) {
			$record['reconciliation'] = $context['reconciliation'];
		}

		$stored = $this->store_operation( $request['request_id'], $record );
		if ( ! is_wp_error( $stored ) ) {
			$this->clear_recovery_index( $request );
		}
		return $stored;
	}

	/**
	 * Return one operation from the bounded durable ledger.
	 *
	 * @param string $request_id Idempotency key.
	 * @return array|WP_Error
	 */
	private function operation_record( $request_id ) {
		$stored = $this->read_exact_option( $this->operation_option_name( $request_id ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! $stored['exists'] ) {
			return array();
		}

		return is_array( $stored['value'] ) ? $stored['value'] : $this->audit_unavailable();
	}

	/** Read one actor/product recovery index directly from durable storage. */
	private function recovery_index( $product_id ) {
		$stored = $this->read_exact_option( $this->recovery_option_name( $product_id ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! $stored['exists'] ) {
			return array();
		}

		return is_array( $stored['value'] ) ? $stored['value'] : $this->audit_unavailable();
	}

	/**
	 * Rebuild and verify the exact request bound into a recovery index.
	 *
	 * @param array $index Recovery pointer.
	 * @param int   $expected_product_id Product owning the pointer option.
	 * @return array|WP_Error
	 */
	private function request_from_recovery_index( $index, $expected_product_id ) {
		if (
			! is_array( $index )
			|| self::SCHEMA !== (string) ( $index['schema'] ?? '' )
			|| 'recovery-index' !== (string) ( $index['kind'] ?? '' )
			|| (int) ( $index['actor_id'] ?? 0 ) <= 0
			|| ! in_array( (string) ( $index['status'] ?? '' ), array( 'reservation_pending', 'in_progress', 'failed_retryable', 'outcome_unknown' ), true )
		) {
			return $this->audit_unavailable();
		}
		$request = $this->validate_request(
			array(
				'product_id'    => $index['product_id'] ?? null,
				'expected_code' => $index['expected_code'] ?? null,
				'product_code'  => $index['product_code'] ?? null,
				'if_match'      => $index['if_match'] ?? null,
				'request_id'    => $index['request_id'] ?? null,
			)
		);
		if (
			is_wp_error( $request )
			|| (int) ( $request['product_id'] ?? 0 ) !== absint( $expected_product_id )
			|| ! hash_equals( (string) ( $index['request_fingerprint'] ?? '' ), (string) ( $request['fingerprint'] ?? '' ) )
		) {
			return $this->audit_unavailable();
		}

		return $request;
	}

	/** Persist and exactly read back the minimal reload-safe recovery handoff. */
	private function store_recovery_index( $request, $record ) {
		$actor_id = (int) ( $record['actor_id'] ?? get_current_user_id() );
		$index    = array(
			'schema'              => self::SCHEMA,
			'kind'                => 'recovery-index',
			'status'              => (string) ( $record['status'] ?? 'in_progress' ),
			'actor_id'            => $actor_id,
			'product_id'          => $request['product_id'],
			'expected_code'       => $request['expected_code'],
			'product_code'        => $request['product_code'],
			'if_match'            => $request['if_match'],
			'request_id'          => $request['request_id'],
			'request_fingerprint' => $request['fingerprint'],
			'updated_at'          => gmdate( 'c' ),
		);
		$name     = $this->recovery_option_name( $request['product_id'] );
		$written  = update_option( $name, $index, false );
		wp_cache_delete( $name, 'options' );
		$readback = $this->read_exact_option( $name );
		if (
			is_wp_error( $readback )
			|| ! $readback['exists']
			|| $readback['value'] !== $index
			|| ( false === $written && ! $readback['exists'] )
		) {
			return $this->audit_unavailable();
		}

		return true;
	}

	/** Clear only the exact request's recovery handoff after terminal readback. */
	private function clear_recovery_index( $request ) {
		$name    = $this->recovery_option_name( $request['product_id'] );
		$current = $this->read_exact_option( $name );
		if ( is_wp_error( $current ) || ! $current['exists'] ) {
			return ! is_wp_error( $current );
		}
		$value = $current['value'];
		if (
			! is_array( $value )
			|| ! hash_equals( $request['request_id'], (string) ( $value['request_id'] ?? '' ) )
			|| ! hash_equals( $request['fingerprint'], (string) ( $value['request_fingerprint'] ?? '' ) )
		) {
			return false;
		}
		delete_option( $name );
		wp_cache_delete( $name, 'options' );
		$readback = $this->read_exact_option( $name );

		return ! is_wp_error( $readback ) && ! $readback['exists'];
	}

	/** Delete a pre-effect claim when its required recovery index cannot persist. */
	private function delete_operation_record( $request_id ) {
		$name = $this->operation_option_name( $request_id );
		delete_option( $name );
		wp_cache_delete( $name, 'options' );
		$readback = $this->read_exact_option( $name );

		return ! is_wp_error( $readback ) && ! $readback['exists'];
	}

	/**
	 * Validate a durable idempotency record before trusting or recovering it.
	 *
	 * @param array  $record Stored operation record.
	 * @param array  $request Validated request.
	 * @param string $status Stored status.
	 * @return bool
	 */
	private function operation_record_is_valid( $record, $request, $status ) {
		if ( 'reservation_released' === $status ) {
			return $this->reservation_release_record_is_valid( $record, $request );
		}
		if (
			! is_array( $record )
			|| self::SCHEMA !== (string) ( $record['schema'] ?? '' )
			|| ! in_array( $status, array( 'completed', 'in_progress', 'failed_retryable', 'outcome_unknown', 'reconciled_no_effect' ), true )
			|| ! is_int( $record['actor_id'] ?? null )
			|| (int) $record['actor_id'] <= 0
			|| (int) ( $record['product_id'] ?? 0 ) !== $request['product_id']
			|| ! hash_equals( $request['fingerprint'], (string) ( $record['request_fingerprint'] ?? '' ) )
			|| ! hash_equals( $request['expected_code'], (string) ( $record['expected_code'] ?? '' ) )
			|| ! hash_equals( $request['product_code'], (string) ( $record['product_code'] ?? '' ) )
			|| ! hash_equals( $request['if_match'], (string) ( $record['if_match'] ?? '' ) )
			|| (int) ( $record['attempts'] ?? 0 ) < 1
			|| ! is_array( $record['rollback_data'] ?? null )
			|| ! array_key_exists( 'meta_exists', $record['rollback_data'] )
			|| ! is_bool( $record['rollback_data']['meta_exists'] )
			|| ! hash_equals( $request['expected_code'], (string) ( $record['rollback_data']['product_code'] ?? '' ) )
			|| ! hash_equals( $request['if_match'], (string) ( $record['rollback_data']['revision'] ?? '' ) )
			|| ! $this->governance_proof_is_valid( $record['governance_proof'] ?? null, $request )
		) {
			return false;
		}

		$backup_reference = (string) ( $record['backup_reference'] ?? '' );
		if ( '' !== $backup_reference && 1 !== preg_match( self::REVISION_PATTERN, $backup_reference ) ) {
			return false;
		}
		$expected_backup = $this->backup_reference(
			$request,
			array(
				'meta_exists'  => $record['rollback_data']['meta_exists'],
				'product_code' => $record['rollback_data']['product_code'],
			),
			$record['governance_proof']
		);
		if ( 'reconciled_no_effect' === $status ) {
			$result         = $record['result'] ?? null;
			$reconciliation = $record['reconciliation'] ?? null;
			return '' !== $backup_reference
				&& hash_equals( $expected_backup, $backup_reference )
				&& $this->projection_record_is_valid( $record['projection'] ?? null, false )
				&& $this->reconciliation_evidence_is_valid( $reconciliation, $request, 'before' )
				&& is_array( $result )
				&& self::RECONCILIATION_SCHEMA === (string) ( $result['schema'] ?? '' )
				&& 'reconciled_no_effect' === (string) ( $result['status'] ?? '' )
				&& false === ( $result['changed'] ?? null )
				&& false === ( $result['replayed'] ?? null )
				&& (int) ( $result['product_id'] ?? 0 ) === $request['product_id']
				&& hash_equals( $request['expected_code'], (string) ( $result['product_code'] ?? '' ) )
				&& hash_equals( $request['if_match'], (string) ( $result['revision'] ?? '' ) )
				&& hash_equals( $request['request_id'], (string) ( $result['request_id'] ?? '' ) )
				&& hash_equals( $request['fingerprint'], (string) ( $result['request_fingerprint'] ?? '' ) )
				&& hash_equals( (string) $reconciliation['record_fingerprint'], (string) ( $result['record_fingerprint'] ?? '' ) )
				&& hash_equals( (string) $reconciliation['preview_digest'], (string) ( $result['preview_digest'] ?? '' ) )
				&& hash_equals( (string) $reconciliation['evidence_fingerprint'], (string) ( $result['reconciliation_evidence_fingerprint'] ?? '' ) )
				&& true === ( $result['verification']['database_readback'] ?? null )
				&& true === ( $result['verification']['cache_bypassed'] ?? null )
				&& true === ( $result['verification']['unique'] ?? null )
				&& true === ( $result['verification']['source_checked'] ?? null )
				&& true === ( $result['verification']['no_code_write'] ?? null );
		}
		if ( 'completed' !== $status ) {
			return '' !== $backup_reference
				&& hash_equals( $expected_backup, $backup_reference )
				&& $this->projection_record_is_valid( $record['projection'] ?? null, false );
		}

		$result = $record['result'] ?? null;
		if ( ! is_array( $result ) ) {
			return false;
		}
		$result_status = (string) ( $result['status'] ?? '' );
		$changed       = $result['changed'] ?? null;
		$verification  = $result['verification'] ?? null;
		if ( ! is_bool( $changed ) ) {
			return false;
		}
		$backup_valid = $changed
			? '' !== $backup_reference && hash_equals( $expected_backup, $backup_reference )
			: '' === $backup_reference;
		$recovered    = true === ( $result['recovered'] ?? false );
		if ( $recovered ) {
			$recovery_proof = $record['recovery_governance_proof'] ?? null;
			if (
				! $this->governance_proof_is_valid( $recovery_proof, $request )
				|| ! hash_equals(
					(string) $recovery_proof['evidence_fingerprint'],
					(string) ( $result['recovery_governance_evidence_fingerprint'] ?? '' )
				)
			) {
				return false;
			}
			if ( array_key_exists( 'recovered_by', $record ) ) {
				if (
					! is_int( $record['recovered_by'] )
					|| $record['recovered_by'] <= 0
					|| $record['recovered_by'] === $record['actor_id']
				) {
					return false;
				}
			}
		} elseif (
			array_key_exists( 'recovered', $result )
			|| array_key_exists( 'recovery_governance_evidence_fingerprint', $result )
			|| array_key_exists( 'recovery_governance_proof', $record )
			|| array_key_exists( 'recovered_by', $record )
		) {
			return false;
		}

		$reconciliation_valid = ! array_key_exists( 'reconciliation', $record )
			? ! array_key_exists( 'reconciliation_evidence_fingerprint', $result )
			: $this->reconciliation_evidence_is_valid( $record['reconciliation'], $request, 'after' )
				&& hash_equals(
					(string) $record['reconciliation']['evidence_fingerprint'],
					(string) ( $result['reconciliation_evidence_fingerprint'] ?? '' )
				);
		$projection_valid     = ! $changed || (
			$this->projection_record_is_valid( $record['projection'] ?? null, true )
			&& is_array( $result['projection'] ?? null )
			&& hash_equals( (string) $record['projection']['generation_before_hash'], (string) ( $result['projection']['generation_before_hash'] ?? '' ) )
			&& hash_equals( (string) $record['projection']['generation_after_hash'], (string) ( $result['projection']['generation_after_hash'] ?? '' ) )
			&& hash_equals( (string) $record['projection']['effect_generation_hash'], (string) ( $result['projection']['effect_generation_hash'] ?? '' ) )
			&& true === ( $result['projection']['state_revision_event_durable'] ?? null )
		);

		return $backup_valid
			&& $reconciliation_valid
			&& $projection_valid
			&& self::SCHEMA === (string) ( $result['schema'] ?? '' )
			&& in_array( $result_status, array( 'applied', 'unchanged' ), true )
			&& ( ( 'applied' === $result_status ) === $changed )
			&& false === ( $result['replayed'] ?? null )
			&& (int) ( $result['product_id'] ?? 0 ) === $request['product_id']
			&& hash_equals( $request['expected_code'], (string) ( $result['previous_product_code'] ?? '' ) )
			&& hash_equals( $request['product_code'], (string) ( $result['product_code'] ?? '' ) )
			&& hash_equals( $request['if_match'], (string) ( $result['previous_revision'] ?? '' ) )
			&& hash_equals( $this->revision_for( $request['product_id'], $request['product_code'] ), (string) ( $result['revision'] ?? '' ) )
			&& hash_equals( $request['request_id'], (string) ( $result['request_id'] ?? '' ) )
			&& hash_equals( $request['fingerprint'], (string) ( $result['request_fingerprint'] ?? '' ) )
			&& hash_equals( $backup_reference, (string) ( $result['backup_reference'] ?? '' ) )
			&& hash_equals(
				(string) $record['governance_proof']['evidence_fingerprint'],
				(string) ( $result['governance_evidence_fingerprint'] ?? '' )
			)
			&& is_array( $verification )
			&& true === ( $verification['database_readback'] ?? null )
			&& true === ( $verification['cache_bypassed'] ?? null )
			&& true === ( $verification['unique'] ?? null )
			&& true === ( $verification['source_governance'] ?? null )
			&& true === ( $verification['projection_current'] ?? null );
	}

	/**
	 * Validate and rehash one terminal pre-effect reservation release.
	 *
	 * @param array $record Stored terminal record.
	 * @param array $request Validated request.
	 * @return bool
	 */
	private function reservation_release_record_is_valid( $record, $request ) {
		$release  = is_array( $record['release'] ?? null ) ? $record['release'] : null;
		$result   = is_array( $record['result'] ?? null ) ? $record['result'] : null;
		$observed = is_array( $release['observed'] ?? null ) ? $release['observed'] : null;
		if (
			! is_array( $record )
			|| self::SCHEMA !== (string) ( $record['schema'] ?? '' )
			|| 'reservation_released' !== (string) ( $record['status'] ?? '' )
			|| ! is_int( $record['actor_id'] ?? null )
			|| (int) $record['actor_id'] <= 0
			|| 0 !== (int) ( $record['attempts'] ?? -1 )
			|| (int) ( $record['product_id'] ?? 0 ) !== $request['product_id']
			|| ! hash_equals( $request['fingerprint'], (string) ( $record['request_fingerprint'] ?? '' ) )
			|| ! hash_equals( $request['expected_code'], (string) ( $record['expected_code'] ?? '' ) )
			|| ! hash_equals( $request['product_code'], (string) ( $record['product_code'] ?? '' ) )
			|| ! hash_equals( $request['if_match'], (string) ( $record['if_match'] ?? '' ) )
			|| ! is_array( $release )
			|| ! is_array( $result )
			|| ! is_array( $observed )
			|| self::RESERVATION_RELEASE_SCHEMA !== (string) ( $release['schema'] ?? '' )
			|| (int) ( $release['product_id'] ?? 0 ) !== $request['product_id']
			|| ! hash_equals( $request['request_id'], (string) ( $release['request_id'] ?? '' ) )
			|| ! hash_equals( $request['fingerprint'], (string) ( $release['request_fingerprint'] ?? '' ) )
			|| (int) ( $release['actor_id'] ?? 0 ) !== (int) $record['actor_id']
			|| ! is_int( $release['released_by'] ?? null )
			|| (int) $release['released_by'] <= 0
			|| true !== ( $release['no_effect'] ?? null )
			|| ! is_string( $release['released_at'] ?? null )
			|| '' === (string) $release['released_at']
			|| ! hash_equals( (string) $release['released_at'], (string) ( $record['updated_at'] ?? '' ) )
			|| ! is_bool( $observed['product_exists'] ?? null )
			|| ! is_bool( $observed['meta_exists'] ?? null )
			|| ! is_string( $observed['product_code'] ?? null )
			|| ! is_string( $observed['revision'] ?? null )
			|| ! is_int( $observed['row_count'] ?? null )
			|| (int) $observed['row_count'] < 0
			|| ! is_bool( $observed['duplicate_rows'] ?? null )
			|| ! is_int( $observed['invalid_key_rows'] ?? null )
			|| (int) $observed['invalid_key_rows'] < 0
			|| ( (int) $observed['row_count'] > 0 ) !== (bool) $observed['meta_exists']
			|| ( (int) $observed['row_count'] > 1 ) !== (bool) $observed['duplicate_rows']
			|| ( ! $observed['product_exists'] && ( $observed['meta_exists'] || 0 !== $observed['row_count'] ) )
			|| ( $observed['product_exists'] && 1 !== preg_match( self::REVISION_PATTERN, $observed['revision'] ) )
			|| ( ! $observed['product_exists'] && '' !== $observed['revision'] )
			|| self::RESERVATION_RELEASE_SCHEMA !== (string) ( $result['schema'] ?? '' )
			|| 'reservation_released' !== (string) ( $result['status'] ?? '' )
			|| false !== ( $result['changed'] ?? null )
			|| false !== ( $result['replayed'] ?? null )
			|| (int) ( $result['product_id'] ?? 0 ) !== $request['product_id']
			|| ! hash_equals( (string) $observed['product_code'], (string) ( $result['current_product_code'] ?? '' ) )
			|| ! hash_equals( (string) $observed['revision'], (string) ( $result['current_revision'] ?? '' ) )
			|| ! hash_equals( $request['request_id'], (string) ( $result['request_id'] ?? '' ) )
			|| ! hash_equals( $request['fingerprint'], (string) ( $result['request_fingerprint'] ?? '' ) )
			|| true !== ( $result['verification']['database_readback'] ?? null )
			|| true !== ( $result['verification']['cache_bypassed'] ?? null )
			|| true !== ( $result['verification']['reservation_without_operation'] ?? null )
			|| true !== ( $result['verification']['no_code_write'] ?? null )
		) {
			return false;
		}
		$evidence_fingerprint = (string) ( $release['evidence_fingerprint'] ?? '' );
		if (
			1 !== preg_match( self::REVISION_PATTERN, $evidence_fingerprint )
			|| ! hash_equals( $evidence_fingerprint, (string) ( $result['release_evidence_fingerprint'] ?? '' ) )
		) {
			return false;
		}
		$material = $release;
		unset( $material['evidence_fingerprint'] );
		$json = $this->canonical_json( $material );

		return ! is_wp_error( $json )
			&& hash_equals( 'sha256:' . hash( 'sha256', $json ), $evidence_fingerprint );
	}

	/** Validate and recompute one exact manual-reconciliation proof. */
	private function reconciliation_evidence_is_valid( $evidence, $request, $resolution ) {
		$observed_code = 'before' === $resolution ? $request['expected_code'] : $request['product_code'];
		if (
			! is_array( $evidence )
			|| self::RECONCILIATION_SCHEMA !== (string) ( $evidence['schema'] ?? '' )
			|| (int) ( $evidence['product_id'] ?? 0 ) !== $request['product_id']
			|| ! hash_equals( $request['request_id'], (string) ( $evidence['request_id'] ?? '' ) )
			|| ! hash_equals( $request['fingerprint'], (string) ( $evidence['request_fingerprint'] ?? '' ) )
			|| 1 !== preg_match( self::REVISION_PATTERN, (string) ( $evidence['record_fingerprint'] ?? '' ) )
			|| ! hash_equals( $observed_code, (string) ( $evidence['observed_product_code'] ?? '' ) )
			|| ! hash_equals(
				$this->revision_for( $request['product_id'], $observed_code ),
				(string) ( $evidence['observed_revision'] ?? '' )
			)
			|| ! hash_equals( $resolution, (string) ( $evidence['resolution'] ?? '' ) )
			|| ! in_array( (string) ( $evidence['source_status'] ?? '' ), array( 'managed', 'unmanaged' ), true )
			|| ( 'after' === $resolution && 'unmanaged' !== (string) $evidence['source_status'] )
			|| 1 !== preg_match( self::REVISION_PATTERN, (string) ( $evidence['source_evidence_fingerprint'] ?? '' ) )
			|| true !== ( $evidence['unique'] ?? null )
			|| 1 !== preg_match( self::REVISION_PATTERN, (string) ( $evidence['preview_digest'] ?? '' ) )
			|| ! is_int( $evidence['reconciled_by'] ?? null )
			|| (int) $evidence['reconciled_by'] <= 0
			|| ! is_string( $evidence['reconciled_at'] ?? null )
			|| '' === (string) $evidence['reconciled_at']
		) {
			return false;
		}
		$preview_material = array(
			'schema'                      => self::RECONCILIATION_SCHEMA,
			'product_id'                  => $request['product_id'],
			'request_id'                  => $request['request_id'],
			'request_fingerprint'         => $request['fingerprint'],
			'record_fingerprint'          => (string) $evidence['record_fingerprint'],
			'observed_product_code'       => $observed_code,
			'observed_revision'           => (string) $evidence['observed_revision'],
			'resolution'                  => $resolution,
			'source_status'               => (string) $evidence['source_status'],
			'source_evidence_fingerprint' => (string) $evidence['source_evidence_fingerprint'],
			'unique'                      => true,
		);
		$preview_json     = $this->canonical_json( $preview_material );
		if (
			is_wp_error( $preview_json )
			|| ! hash_equals( 'sha256:' . hash( 'sha256', $preview_json ), (string) $evidence['preview_digest'] )
		) {
			return false;
		}
		$evidence_fingerprint = (string) ( $evidence['evidence_fingerprint'] ?? '' );
		$material             = $evidence;
		unset( $material['evidence_fingerprint'] );
		$json = $this->canonical_json( $material );

		return ! is_wp_error( $json )
			&& 1 === preg_match( self::REVISION_PATTERN, $evidence_fingerprint )
			&& hash_equals( 'sha256:' . hash( 'sha256', $json ), $evidence_fingerprint );
	}

	/** Validate one exact generation checkpoint or verified final state. */
	private function projection_record_is_valid( $projection, $terminal ) {
		if ( ! is_array( $projection ) ) {
			return false;
		}
		$before      = (string) ( $projection['generation_before'] ?? '' );
		$before_hash = (string) ( $projection['generation_before_hash'] ?? '' );
		if ( '' === $before || ! hash_equals( 'sha256:' . hash( 'sha256', $before ), $before_hash ) ) {
			return false;
		}
		if ( ! $terminal ) {
			if ( false === ( $projection['verified'] ?? null ) ) {
				return is_string( $projection['effect_probe'] ?? null ) && '' !== $projection['effect_probe'];
			}

			return $this->projection_record_is_valid( $projection, true );
		}

		$effect      = (string) ( $projection['effect_generation'] ?? '' );
		$effect_hash = (string) ( $projection['effect_generation_hash'] ?? '' );

		return true === ( $projection['verified'] ?? null )
			&& '' !== $effect
			&& ! hash_equals( $before, $effect )
			&& hash_equals( 'sha256:' . hash( 'sha256', $effect ), $effect_hash )
			&& '' !== (string) ( $projection['generation_after'] ?? '' )
			&& hash_equals(
				'sha256:' . hash( 'sha256', (string) $projection['generation_after'] ),
				(string) ( $projection['generation_after_hash'] ?? '' )
			)
			&& true === ( $projection['state_revision_event_durable'] ?? null );
	}

	/** Validate a compact governance proof and bind it to this exact request. */
	private function governance_proof_is_valid( $proof, $request ) {
		if (
			! is_array( $proof )
			|| self::GOVERNANCE_SCHEMA !== (string) ( $proof['schema'] ?? '' )
			|| ! is_array( $proof['sources'] ?? null )
			|| ! is_array( $proof['legacy_feed'] ?? null )
			|| ! is_array( $proof['checked'] ?? null )
			|| 1 !== preg_match( self::REVISION_PATTERN, (string) ( $proof['source_state_fingerprint'] ?? '' ) )
			|| 1 !== preg_match( self::REVISION_PATTERN, (string) ( $proof['legacy_feed']['state_fingerprint'] ?? '' ) )
			|| (int) ( $proof['legacy_feed']['row_count'] ?? -1 ) < 0
			|| ! hash_equals( 'sha256:' . hash( 'sha256', $request['expected_code'] ), (string) ( $proof['checked']['current_code_hash'] ?? '' ) )
			|| ! hash_equals( 'sha256:' . hash( 'sha256', $request['product_code'] ), (string) ( $proof['checked']['desired_code_hash'] ?? '' ) )
		) {
			return false;
		}

		$sorted = array_values( $proof['sources'] );
		foreach ( $sorted as $source ) {
			if (
				! is_array( $source )
				|| ! is_string( $source['id'] ?? null )
				|| '' === $source['id']
				|| ! is_string( $source['dataset'] ?? null )
				|| '' === $source['dataset']
				|| 1 !== preg_match( self::REVISION_PATTERN, (string) ( $source['revision'] ?? '' ) )
			) {
				return false;
			}
		}
		usort(
			$sorted,
			static function ( $left, $right ) {
				return strcmp(
					(string) $left['id'] . "\0" . (string) $left['dataset'] . "\0" . (string) $left['revision'],
					(string) $right['id'] . "\0" . (string) $right['dataset'] . "\0" . (string) $right['revision']
				);
			}
		);
		if ( $sorted !== array_values( $proof['sources'] ) ) {
			return false;
		}

		$evidence = (string) ( $proof['evidence_fingerprint'] ?? '' );
		$material = $proof;
		unset( $material['evidence_fingerprint'] );
		$json = $this->canonical_json( $material );
		return ! is_wp_error( $json )
			&& 1 === preg_match( self::REVISION_PATTERN, $evidence )
			&& hash_equals( 'sha256:' . hash( 'sha256', $json ), $evidence );
	}

	/**
	 * Store and read back one authoritative per-request operation record.
	 *
	 * The capped aggregate is only a best-effort navigation index. It must not
	 * turn a verified canonical record into a failed mutation: doing so could
	 * roll back the product after a terminal result had already become replayable.
	 *
	 * @param string $request_id Idempotency key.
	 * @param array  $record Operation record.
	 * @return true|WP_Error
	 */
	private function store_operation( $request_id, $record ) {
		$key             = hash( 'sha256', $request_id );
		$operation_name  = $this->operation_option_name( $request_id );
		$operation_write = update_option( $operation_name, $record, false );
		wp_cache_delete( $operation_name, 'options' );
		$operation_readback = $this->read_exact_option( $operation_name );
		if (
			is_wp_error( $operation_readback )
			|| ! $operation_readback['exists']
			|| ! is_array( $operation_readback['value'] )
			|| $operation_readback['value'] !== $record
			|| ( false === $operation_write && ! $operation_readback['exists'] )
		) {
			return $this->audit_unavailable();
		}
		if ( in_array( (string) ( $record['status'] ?? '' ), array( 'failed_retryable', 'outcome_unknown' ), true ) ) {
			$this->store_recovery_index(
				array(
					'product_id'    => (int) $record['product_id'],
					'expected_code' => (string) $record['expected_code'],
					'product_code'  => (string) $record['product_code'],
					'if_match'      => (string) $record['if_match'],
					'request_id'    => (string) $request_id,
					'fingerprint'   => (string) $record['request_fingerprint'],
				),
				$record
			);
		}

		$ledger = $this->read_exact_audit_ledger();
		if ( is_wp_error( $ledger ) ) {
			return true;
		}
		$operations = is_array( $ledger['operations'] ?? null )
			? $ledger['operations']
			: array();
		unset( $operations[ $key ] );
		$operations = array( $key => $record ) + $operations;
		$operations = array_slice( $operations, 0, self::MAX_AUDIT_RECORDS, true );
		$next       = array(
			'schema'     => self::SCHEMA,
			'operations' => $operations,
		);

		update_option( self::AUDIT_OPTION, $next, false );
		wp_cache_delete( self::AUDIT_OPTION, 'options' );
		return true;
	}

	/**
	 * Return the non-autoloaded durable option name for one request ID.
	 *
	 * The request identifier itself is never stored in an option name. Terminal
	 * records are not automatically pruned, so eviction from the bounded audit
	 * summary cannot make an old idempotency key executable again.
	 *
	 * @param string $request_id Exact request identifier.
	 * @return string
	 */
	private function operation_option_name( $request_id ) {
		return self::OPERATION_OPTION_STEM . hash( 'sha256', (string) $request_id );
	}

	/** Return the bounded actor/product recovery option name. */
	private function recovery_option_name( $product_id ) {
		return self::RECOVERY_OPTION_STEM . hash(
			'sha256',
			self::SCHEMA . "\n" . (string) absint( $product_id )
		);
	}

	/**
	 * Read one exact option directly from the database.
	 *
	 * @param string $option_name Sanitized internal option name.
	 * @return array|WP_Error Array with exists/value, or a typed failure.
	 */
	private function read_exact_option( $option_name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $this->audit_unavailable();
		}
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table name cannot be a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_operation_readback */
			SELECT option_value
			FROM {$options}
			WHERE option_name = %s
			LIMIT 1",
			(string) $option_name
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->audit_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact idempotency readback must bypass object caches.
		$row = $wpdb->get_row( $query, ARRAY_A );
		if ( null === $row ) {
			if ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) ) {
				return $this->audit_unavailable();
			}

			return array(
				'exists' => false,
				'value'  => null,
			);
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return $this->audit_unavailable();
		}

		return array(
			'exists' => true,
			'value'  => maybe_unserialize( $row['option_value'] ),
		);
	}

	/**
	 * Read a bounded option set in one cache-bypassed query.
	 *
	 * @param string[] $option_names Exact internal option names.
	 * @return array<string,array{exists:bool,value:mixed}>|WP_Error
	 */
	private function read_exact_options( $option_names ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->audit_unavailable();
		}
		$option_names = array_values( array_unique( array_map( 'strval', (array) $option_names ) ) );
		if ( empty( $option_names ) ) {
			return array();
		}
		if ( count( $option_names ) > 101 ) {
			return $this->audit_unavailable();
		}
		$options      = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- wpdb-owned table and a bounded variadic placeholder list cannot be expressed statically.
		$query = $wpdb->prepare(
			"/* digitalogic_product_code_options_batch */
			SELECT option_name, option_value
			FROM {$options}
			WHERE option_name IN ({$placeholders})
			ORDER BY option_name ASC",
			...$option_names
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( false === $query ) {
			return $this->audit_unavailable();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact recovery handoff must bypass object caches.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $this->audit_unavailable();
		}
		$result = array_fill_keys(
			$option_names,
			array(
				'exists' => false,
				'value'  => null,
			)
		);
		foreach ( $rows as $row ) {
			$name = is_array( $row ) && is_string( $row['option_name'] ?? null ) ? $row['option_name'] : '';
			if ( '' === $name || ! array_key_exists( $name, $result ) || $result[ $name ]['exists'] || ! array_key_exists( 'option_value', $row ) ) {
				return $this->audit_unavailable();
			}
			$result[ $name ] = array(
				'exists' => true,
				'value'  => maybe_unserialize( $row['option_value'] ),
			);
		}

		return $result;
	}

	/**
	 * Read the idempotency/audit ledger directly from the options table.
	 *
	 * A stale object-cache value must never cause a completed request to be
	 * executed again or cause another operation record to be discarded.
	 *
	 * @return array|WP_Error
	 */
	private function read_exact_audit_ledger() {
		$stored = $this->read_exact_option( self::AUDIT_OPTION );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( ! $stored['exists'] ) {
			return array(
				'schema'     => self::SCHEMA,
				'operations' => array(),
			);
		}
		$ledger = $stored['value'];
		if (
			! is_array( $ledger )
			|| self::SCHEMA !== (string) ( $ledger['schema'] ?? '' )
			|| ! is_array( $ledger['operations'] ?? null )
		) {
			return $this->audit_unavailable();
		}

		return $ledger;
	}

	/** Typed audit storage/readback failure. */
	private function audit_unavailable() {
		return $this->error(
			'digitalogic_product_code_audit_unavailable',
			__( 'The Product Code audit ledger is unavailable.', 'digitalogic' ),
			503,
			array( 'retryable' => true )
		);
	}

	/**
	 * Fail closed when nested source ownership evidence is malformed.
	 *
	 * @param string $location Safe structural location.
	 * @return WP_Error
	 */
	private function source_state_malformed( $location ) {
		return $this->error(
			'digitalogic_product_code_source_state_malformed',
			__( 'The product-sync source state is malformed, so the identity edit was stopped.', 'digitalogic' ),
			503,
			array(
				'retryable' => true,
				'location'  => (string) $location,
			)
		);
	}

	/**
	 * Build an exact precondition failure without mutating state.
	 *
	 * @param array  $before Exact current state.
	 * @param string $current_revision Exact current revision.
	 * @param string $field Failed precondition field.
	 * @return WP_Error
	 */
	private function precondition_error( $before, $current_revision, $field ) {
		return $this->error(
			'digitalogic_product_code_precondition_failed',
			__( 'The Product Code changed after it was loaded. Reload the row before editing.', 'digitalogic' ),
			412,
			array(
				'failed_field'     => $field,
				'current_code'     => $before['product_code'],
				'current_revision' => $current_revision,
				'retryable'        => false,
			)
		);
	}

	/**
	 * Run under the receiver's source-identity lock without waiting.
	 *
	 * The receiver acquires this lock before it can mutate its durable source
	 * state or WooCommerce identities. Sharing it closes the cross-product race
	 * between source delivery and the final uniqueness readback here.
	 *
	 * @param callable $callback Critical section.
	 * @return mixed|WP_Error
	 */
	private function with_source_identity_lock( $callback ) {
		$receiver = Digitalogic_Product_Sync_Receiver::instance();
		$locked   = $receiver->acquire_source_identity_lock( 0 );
		if ( is_wp_error( $locked ) ) {
			if ( 'digitalogic_product_sync_busy' !== $locked->get_error_code() ) {
				return $locked;
			}

			return $this->error(
				'digitalogic_product_code_source_busy',
				__( 'The catalog source is updating Product Codes. Retry the unchanged request.', 'digitalogic' ),
				503,
				array(
					'retryable'   => true,
					'retry_after' => 1,
				)
			);
		}

		$previous_receiver                 = $this->active_source_lock_receiver;
		$this->active_source_lock_receiver = $receiver;
		try {
			return call_user_func( $callback );
		} finally {
			$this->active_source_lock_receiver = $previous_receiver;
			$receiver->release_source_identity_lock();
		}
	}

	/**
	 * Run under a site-scoped fail-fast identity/audit lock.
	 *
	 * @param callable $callback Critical section.
	 * @return mixed|WP_Error
	 */
	private function with_operation_lock( $callback ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->database_unavailable();
		}
		$prefix    = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$lock_name = substr( 'digitalogic_code_edit_' . md5( $prefix ), 0, 64 );
		$prepared  = $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory lock is connection state.
		$locked = false !== $prepared ? $wpdb->get_var( $prepared ) : false;
		if ( 1 !== (int) $locked ) {
			return $this->error(
				'digitalogic_product_code_busy',
				__( 'Another Product Code edit is active. Retry the unchanged request.', 'digitalogic' ),
				503,
				array(
					'retryable'   => true,
					'retry_after' => 1,
				)
			);
		}
		$connection_id = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection identity is live session state.
		if ( ! $this->advisory_lock_is_owned( $lock_name, $connection_id ) ) {
			$release = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name );
			if ( false !== $release ) {
				$wpdb->get_var( $release ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Best-effort cleanup.
			}
			return $this->operation_lock_lost();
		}

		$previous_lock_name                   = $this->active_operation_lock_name;
		$previous_connection_id               = $this->active_operation_connection_id;
		$this->active_operation_lock_name     = $lock_name;
		$this->active_operation_connection_id = (int) $connection_id;

		try {
			$result = call_user_func( $callback );
			if ( $this->advisory_lock_is_owned( $lock_name, $connection_id ) ) {
				return $result;
			}
			$terminal = $this->verified_terminal_result_after_lock_loss( $result );
			if ( null !== $terminal ) {
				return $terminal;
			}
			if ( $this->is_same_request_recovery_error( $result ) ) {
				return $result;
			}

			return $this->operation_lock_lost();
		} finally {
			$this->active_operation_lock_name     = $previous_lock_name;
			$this->active_operation_connection_id = $previous_connection_id;
			$release                              = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name );
			if ( false !== $release && $this->advisory_lock_is_owned( $lock_name, $connection_id ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Release the connection-scoped advisory lock.
				$wpdb->get_var( $release );
			}
		}
	}

	/** Return a verified terminal record for one exact request, or null. */
	private function verified_terminal_result_for_request( $request ) {
		$record = $this->operation_record( $request['request_id'] );
		if (
			! is_array( $record )
			|| 'completed' !== (string) ( $record['status'] ?? '' )
			|| ! $this->operation_record_is_valid( $record, $request, 'completed' )
		) {
			return null;
		}

		return $record['result'];
	}

	/** Preserve a proven terminal result when final option IO reconnects MySQL. */
	private function verified_terminal_result_after_lock_loss( $result ) {
		if ( ! is_array( $result ) ) {
			return null;
		}
		$request = $this->validate_request(
			array(
				'product_id'    => $result['product_id'] ?? null,
				'expected_code' => $result['previous_product_code'] ?? null,
				'product_code'  => $result['product_code'] ?? null,
				'if_match'      => $result['previous_revision'] ?? null,
				'request_id'    => $result['request_id'] ?? null,
			)
		);
		if (
			is_wp_error( $request )
			|| ! hash_equals( (string) ( $result['request_fingerprint'] ?? '' ), (string) $request['fingerprint'] )
		) {
			return null;
		}
		$terminal = $this->verified_terminal_result_for_request( $request );
		if ( null === $terminal ) {
			return null;
		}

		return $this->historical_result_with_current_readback( $request, $terminal );
	}

	/**
	 * Bind a historical terminal record to one exact cache-bypassed current row.
	 *
	 * A connection-scoped lock may be lost only after the durable terminal
	 * record was stored. Another guarded writer can then advance the product
	 * before this request responds, so the historical result must never be
	 * presented as current state.
	 */
	private function historical_result_with_current_readback( $request, $terminal, $current = null ) {
		if ( ! is_array( $terminal ) ) {
			return $this->audit_unavailable();
		}
		if ( null === $current ) {
			$current = $this->read_exact_product_code( $request['product_id'] );
		}
		if (
			is_wp_error( $current )
			|| empty( $current['product_exists'] )
			|| ! empty( $current['duplicate_rows'] )
			|| ! empty( $current['invalid_key_rows'] )
		) {
			return $this->error(
				'digitalogic_product_code_replay_readback_unavailable',
				__( 'The completed Product Code edit is historical, but the current product state could not be read exactly.', 'digitalogic' ),
				503,
				array(
					'retryable'           => true,
					'request_id'          => $request['request_id'],
					'request_fingerprint' => $request['fingerprint'],
				)
			);
		}

		$terminal['replayed']             = true;
		$terminal['current_product_code'] = (string) $current['product_code'];
		$terminal['current_revision']     = $this->revision_for( $request['product_id'], (string) $current['product_code'] );
		$terminal['current_readback']     = array(
			'database_readback' => true,
			'cache_bypassed'    => true,
		);

		return $terminal;
	}

	/** Identify the structured same-key response that survives a lost lock. */
	private function is_same_request_recovery_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		$data = $result->get_error_data();

		return is_array( $data )
			&& ! empty( $data['retryable'] )
			&& is_string( $data['request_id'] ?? null )
			&& is_string( $data['request_fingerprint'] ?? null );
	}

	/** Verify every lock required for a canonical identity effect is still owned. */
	private function mutation_locks_are_owned( $product_id ) {
		if (
			'' === $this->active_operation_lock_name
			|| $this->active_operation_connection_id <= 0
			|| ! $this->advisory_lock_is_owned( $this->active_operation_lock_name, $this->active_operation_connection_id )
		) {
			return false;
		}

		$receiver = $this->active_source_lock_receiver;
		return $receiver instanceof Digitalogic_Product_Sync_Receiver
			&& $receiver->source_identity_lock_is_owned()
			&& Digitalogic_Product_Write_Lock::instance()->is_owned( $product_id );
	}

	/**
	 * Preserve an in-progress request when a connection-scoped lock is lost.
	 *
	 * Exact after-state recovery is intentionally deferred until the same
	 * request can reacquire source, operation, and product locks. The durable
	 * per-product pointer remains discoverable after a page reload.
	 */
	private function same_request_recovery_required( $request, $backup_reference, $reason ) {
		return $this->error(
			'digitalogic_product_code_recovery_pending',
			__( 'The Product Code operation lost its database lock. Retry the unchanged request so its exact outcome can be recovered.', 'digitalogic' ),
			503,
			array(
				'retryable'           => true,
				'retry_after'         => 1,
				'request_id'          => (string) $request['request_id'],
				'request_fingerprint' => (string) $request['fingerprint'],
				'backup_reference'    => (string) $backup_reference,
				'reason'              => sanitize_key( (string) $reason ),
			)
		);
	}

	/** Verify one named advisory lock still belongs to the acquiring connection. */
	private function advisory_lock_is_owned( $lock_name, $connection_id ) {
		global $wpdb;
		if ( (int) $connection_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$current     = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection identity is live session state.
		$owner_query = $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name );
		$owner       = false !== $owner_query ? $wpdb->get_var( $owner_query ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory ownership is live session state.

		return (int) $connection_id === (int) $current && (int) $connection_id === (int) $owner;
	}

	/** Typed same-key retry result after a connection-scoped operation lock is lost. */
	private function operation_lock_lost() {
		return $this->error(
			'digitalogic_product_code_operation_lock_lost',
			__( 'The Product Code operation lock was lost after a database reconnect. Retry the unchanged request.', 'digitalogic' ),
			503,
			array(
				'retryable'   => true,
				'retry_after' => 1,
			)
		);
	}

	/** Typed database availability failure. */
	private function database_unavailable() {
		return $this->error(
			'digitalogic_product_code_database_unavailable',
			__( 'The exact Product Code database readback is unavailable.', 'digitalogic' ),
			503,
			array( 'retryable' => true )
		);
	}

	/** Encode identity material without allowing malformed text to collapse hashes. */
	private function canonical_json( $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return $this->error(
				'digitalogic_product_code_encoding_invalid',
				__( 'The Product Code identity material is not valid UTF-8.', 'digitalogic' ),
				400
			);
		}

		return $json;
	}

	/**
	 * Construct one machine-readable error.
	 *
	 * @param string $code Machine code.
	 * @param string $message Human-readable message.
	 * @param int    $status HTTP status.
	 * @param array  $data Additional safe details.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status, $data = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status' => (int) $status,
					'schema' => self::SCHEMA,
				),
				$data
			)
		);
	}
}
