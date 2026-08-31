<?php
/**
 * Durable, transition-only operator warnings for incomplete Patris products.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes identity-safe product-completeness transitions to the approved
 * private Digitalogic operator-notification adapter.
 */
final class Digitalogic_Patris_Incomplete_Product_Notifier {

	public const ALERT_SCHEMA      = 'digitalogic.alert-event';
	public const COMMITTED_HOOK    = 'digitalogic_patris_materializer_product_committed';
	public const FLUSH_HOOK        = 'digitalogic_patris_materializer_product_commits_complete';
	public const WORKER_HOOK       = 'digitalogic_patris_incomplete_product_alert_delivery';
	public const REPAIR_HOOK       = 'digitalogic_patris_incomplete_product_alert_repair';
	public const STORE_OPTION      = 'digitalogic_patris_incomplete_product_alert_store';
	public const ADAPTER_FILTER    = 'digitalogic_patris_incomplete_product_alert_delivery_adapter';
	public const REPAIR_IDS_FILTER = 'digitalogic_patris_incomplete_product_alert_repair_product_ids';

	private const STORE_SCHEMA_VERSION = 1;
	private const STORE_LOCK_NAME      = 'digitalogic_patris_incomplete_alerts';
	private const OPERATOR_KEY         = 'shokri';
	private const CHANNEL              = 'telegram';
	private const MAX_ATTEMPTS         = 3;
	private const MAX_WORKER_BATCH     = 25;
	private const MAX_CLAIM_BATCH      = 10;
	private const MAX_CAPTURE_BATCH    = 1000;
	private const REPAIR_BATCH_SIZE    = 200;
	private const REPAIR_INTERVAL      = 3600;
	private const MAX_OUTBOX_EVENTS    = 5000;
	private const MAX_PRODUCT_STATES   = 5000;
	private const MAX_RECEIPTS         = 5000;
	private const RECEIPT_TTL          = 180 * DAY_IN_SECONDS;
	private const LEASE_SECONDS        = 300;

	private const RECEIPT_PENDING_RETRY = 300;

	private const SOURCE_REVISION_META = '_digitalogic_patris_source_revision';
	private const MISSING_FIELDS_META  = '_digitalogic_patris_materialization_missing_fields';
	private const OWNER_SOURCE_META    = '_digitalogic_patris_owner_source_id';
	private const OWNER_DATASET_META   = '_digitalogic_patris_owner_dataset';
	private const OWNER_CODE_META      = '_digitalogic_patris_owner_product_code';
	private const PRODUCT_CODE_META    = '_digitalogic_patris_product_code';
	private const PRICE_STATUS_META    = '_digitalogic_patris_price_status';

	private const MISSING_FIELD_LABELS = array(
		'price'   => 'قیمت معتبر',
		'stock'   => 'موجودی واقعی',
		'weight'  => 'وزن',
		'freight' => 'روش، نرخ و ارز کرایه حمل',
		'markup'  => 'درصد سود',
		'image'   => 'تصویر محصول',
		'seo'     => 'عنوان، توضیحات و کلیدواژه سئو',
	);

	/**
	 * Shared notifier.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Request-local committed snapshots waiting for one durable batch flush.
	 *
	 * @var array
	 */
	private $capture_buffer = array();

	/** Return the shared notifier. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register capture, delivery, and schedule-repair hooks. */
	private function __construct() {
		add_action( self::COMMITTED_HOOK, array( $this, 'capture_committed_snapshot' ), 10, 1 );
		add_action( self::FLUSH_HOOK, array( $this, 'flush_captured_snapshots' ), PHP_INT_MAX );
		add_action( self::WORKER_HOOK, array( $this, 'run_delivery_worker' ) );
		add_action( self::REPAIR_HOOK, array( $this, 'run_repair_worker' ) );
		add_action( 'init', array( $this, 'ensure_delivery_worker' ), 40 );
		add_action( 'init', array( $this, 'ensure_repair_worker' ), 41 );
		add_action( 'shutdown', array( $this, 'flush_captured_snapshots' ), PHP_INT_MAX );
	}

	/**
	 * Preserve an exact post-commit snapshot without ever affecting creation.
	 *
	 * The materializer emits this hook only after its product and source locks
	 * have been released. All failures are reported coarsely and swallowed.
	 *
	 * @param mixed $snapshot Committed product-completeness snapshot.
	 */
	public function capture_committed_snapshot( $snapshot ): void {
		$product_code = is_array( $snapshot ) ? (string) ( $snapshot['product_code'] ?? '' ) : '';
		try {
			$normalized = $this->normalize_snapshot( $snapshot );
			if ( is_wp_error( $normalized ) ) {
				$this->report_failure( $normalized->get_error_code(), $product_code );
				return;
			}

			$this->capture_buffer[] = $normalized;
			if ( count( $this->capture_buffer ) >= self::MAX_CAPTURE_BATCH ) {
				$this->flush_captured_snapshots();
			}
		} catch ( Throwable $error ) {
			unset( $error );
			$this->report_failure( 'digitalogic_patris_incomplete_alert_capture_exception', $product_code );
		}
	}

	/**
	 * Commit all request-local snapshots with one locked option rewrite.
	 *
	 * @return bool Whether the batch is durable or there was no pending batch.
	 */
	public function flush_captured_snapshots(): bool {
		if ( empty( $this->capture_buffer ) ) {
			return true;
		}

		$snapshots            = $this->capture_buffer;
		$this->capture_buffer = array();
		try {
			$result = $this->persist_snapshot_transitions( $snapshots );
			if ( is_wp_error( $result ) ) {
				$this->capture_buffer = array_merge( $snapshots, $this->capture_buffer );
				$this->report_failure( $result->get_error_code() );
				return false;
			}
			if ( ! empty( $result['queued'] ) ) {
				$this->ensure_delivery_worker();
			}

			return true;
		} catch ( Throwable $error ) {
			unset( $error );
			$this->capture_buffer = array_merge( $snapshots, $this->capture_buffer );
			$this->report_failure( 'digitalogic_patris_incomplete_alert_flush_exception' );
			return false;
		}
	}

	/** Deliver a bounded batch of due outbox events. */
	public function run_delivery_worker(): void {
		try {
			$this->flush_captured_snapshots();
			$processed = 0;
			while ( $processed < self::MAX_WORKER_BATCH ) {
				$claims = $this->claim_due_events( self::MAX_WORKER_BATCH - $processed );
				if ( is_wp_error( $claims ) ) {
					$this->report_failure( $claims->get_error_code() );
					break;
				}
				if ( empty( $claims ) ) {
					break;
				}

				$deliveries = array();
				foreach ( $claims as $claim ) {
					$deliveries[ $claim['event_id'] ] = $this->deliver_alert_event( $claim['event'] );
				}
				$complete = $this->complete_claims( $claims, $deliveries );
				if ( is_wp_error( $complete ) ) {
					$first = reset( $claims );
					$this->report_failure(
						$complete->get_error_code(),
						(string) ( $first['event']['object']['product_code'] ?? '' )
					);
					break;
				}
				$processed += count( $claims );
			}
		} catch ( Throwable $error ) {
			unset( $error );
			$this->report_failure( 'digitalogic_patris_incomplete_alert_worker_exception' );
		}

		$this->ensure_delivery_worker();
	}

	/** Repair one missing one-shot schedule when deliverable outbox work exists. */
	public function ensure_delivery_worker(): bool {
		try {
			$store = $this->read_store();
			if ( is_wp_error( $store ) ) {
				$this->report_failure( $store->get_error_code() );
				return false;
			}

			$next = null;
			$now  = time();
			$seen = array();
			uasort(
				$store['outbox'],
				static function ( $left, $right ) {
					return (int) ( $left['sequence'] ?? 0 ) <=> (int) ( $right['sequence'] ?? 0 );
				}
			);
			foreach ( $store['outbox'] as $event_id => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$product_key = $this->valid_event_id( $entry['product_key'] ?? null )
					? (string) $entry['product_key']
					: 'event:' . $event_id;
				if ( isset( $seen[ $product_key ] ) ) {
					continue;
				}
				$seen[ $product_key ] = true;

				$receipt_pending = $this->is_receipt_pending_entry( $entry );
				if ( 'exhausted' === (string) ( $entry['status'] ?? '' ) && ! $receipt_pending ) {
					continue;
				}
				if ( (int) ( $entry['attempts'] ?? 0 ) >= self::MAX_ATTEMPTS && ! $receipt_pending ) {
					continue;
				}
				$lease_until = (int) ( $entry['lease_until'] ?? 0 );
				$due         = max( $now + 1, (int) ( $entry['next_attempt_at'] ?? 0 ) );
				if ( $lease_until > $now ) {
					$due = max( $due, $lease_until + 1 );
				}
				$next = null === $next ? $due : min( $next, $due );
			}
			if ( null === $next ) {
				return true;
			}

			if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::WORKER_HOOK, array() ) ) {
				return true;
			}
			if ( ! function_exists( 'wp_schedule_single_event' ) ) {
				$this->report_failure( 'digitalogic_patris_incomplete_alert_scheduler_unavailable' );
				return false;
			}

			$scheduled = wp_schedule_single_event( $next, self::WORKER_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) || false === $scheduled ) {
				if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::WORKER_HOOK, array() ) ) {
					return true;
				}
				$this->report_failure( 'digitalogic_patris_incomplete_alert_schedule_failed' );
				return false;
			}

			return true;
		} catch ( Throwable $error ) {
			unset( $error );
			$this->report_failure( 'digitalogic_patris_incomplete_alert_schedule_exception' );
			return false;
		}
	}

	/**
	 * Rebuild one bounded page of missed snapshots from committed product state.
	 *
	 * This is a repair path for a process interruption between product commit and
	 * outbox persistence. Only fully published, identity-consistent products with
	 * the paired materializer metadata can produce a reconstructed snapshot.
	 */
	public function run_repair_worker(): void {
		try {
			$store = $this->read_store();
			if ( is_wp_error( $store ) ) {
				$this->report_failure( $store->get_error_code() );
				return;
			}

			$page = max( 1, (int) ( $store['repair_page'] ?? 1 ) );
			$ids  = $this->repair_product_ids( $page, self::REPAIR_BATCH_SIZE );
			if ( is_wp_error( $ids ) ) {
				$this->report_failure( $ids->get_error_code() );
				return;
			}

			foreach ( $ids as $product_id ) {
				$snapshot = $this->repair_snapshot( $product_id );
				if ( is_wp_error( $snapshot ) ) {
					$this->report_failure( $snapshot->get_error_code() );
					continue;
				}
				if ( is_array( $snapshot ) ) {
					$this->capture_committed_snapshot( $snapshot );
				}
			}

			if ( ! $this->flush_captured_snapshots() ) {
				return;
			}

			$next_page = count( $ids ) < self::REPAIR_BATCH_SIZE ? 1 : $page + 1;
			$persisted = $this->persist_repair_page( $next_page );
			if ( is_wp_error( $persisted ) ) {
				$this->report_failure( $persisted->get_error_code() );
			}
		} catch ( Throwable $error ) {
			unset( $error );
			$this->report_failure( 'digitalogic_patris_incomplete_alert_repair_exception' );
		} finally {
			$this->ensure_repair_worker();
		}
	}

	/** Schedule one bounded periodic repair pass when none is pending. */
	public function ensure_repair_worker(): bool {
		try {
			if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::REPAIR_HOOK, array() ) ) {
				return true;
			}
			if ( ! function_exists( 'wp_schedule_single_event' ) ) {
				$this->report_failure( 'digitalogic_patris_incomplete_alert_repair_scheduler_unavailable' );
				return false;
			}

			$scheduled = wp_schedule_single_event( time() + self::REPAIR_INTERVAL, self::REPAIR_HOOK, array(), true );
			if ( is_wp_error( $scheduled ) || false === $scheduled ) {
				if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::REPAIR_HOOK, array() ) ) {
					return true;
				}
				$this->report_failure( 'digitalogic_patris_incomplete_alert_repair_schedule_failed' );
				return false;
			}

			return true;
		} catch ( Throwable $error ) {
			unset( $error );
			$this->report_failure( 'digitalogic_patris_incomplete_alert_repair_schedule_exception' );
			return false;
		}
	}

	/** Remove only pending worker schedules while preserving durable state. */
	public static function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::WORKER_HOOK, array() );
			wp_clear_scheduled_hook( self::REPAIR_HOOK, array() );
		}
	}

	/**
	 * Select one bounded page of products carrying the complete repair contract.
	 *
	 * @param int $page  One-based page.
	 * @param int $limit Maximum product IDs.
	 * @return array|WP_Error
	 */
	private function repair_product_ids( int $page, int $limit ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_query_unavailable', 'The product repair query is unavailable.' );
		}

		$args = array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => $limit,
			'paged'                  => $page,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded hourly repair over exact materializer metadata.
				'relation' => 'AND',
				array(
					'key'     => self::SOURCE_REVISION_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::MISSING_FIELDS_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::OWNER_SOURCE_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::OWNER_DATASET_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::OWNER_CODE_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::PRODUCT_CODE_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::PRICE_STATUS_META,
					'compare' => 'EXISTS',
				),
			),
		);
		try {
			$ids = get_posts( $args );
			$ids = apply_filters( self::REPAIR_IDS_FILTER, $ids, $page, $limit );
		} catch ( Throwable $error ) {
			unset( $error );
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_query_failed', 'The product repair query failed.' );
		}
		if ( ! is_array( $ids ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_query_invalid', 'The product repair query returned an invalid result.' );
		}

		$normalized = array();
		foreach ( $ids as $candidate ) {
			$product_id = is_object( $candidate ) ? absint( $candidate->ID ?? 0 ) : absint( $candidate );
			if ( $product_id > 0 ) {
				$normalized[] = $product_id;
			}
		}
		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_NUMERIC );

		return array_slice( $normalized, 0, $limit );
	}

	/**
	 * Reconstruct the exact committed snapshot from one identity-safe product.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return array|null|WP_Error
	 */
	private function repair_snapshot( int $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'get_post_meta' ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_product_unavailable', 'WooCommerce product repair is unavailable.' );
		}

		$product = wc_get_product( $product_id );
		$methods = array( 'get_name', 'get_sku', 'get_status', 'get_type', 'get_catalog_visibility', 'get_stock_status' );
		if ( ! is_object( $product ) ) {
			return null;
		}
		foreach ( $methods as $method ) {
			if ( ! is_callable( array( $product, $method ) ) ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_product_invalid', 'The repair product is invalid.' );
			}
		}

		$status = (string) $product->get_status();
		$type   = (string) $product->get_type();
		if ( 'publish' !== $status || ( 'variation' !== $type && 'visible' !== (string) $product->get_catalog_visibility() ) ) {
			return null;
		}

		$product_code    = trim( (string) get_post_meta( $product_id, self::PRODUCT_CODE_META, true ) );
		$owner_code      = trim( (string) get_post_meta( $product_id, self::OWNER_CODE_META, true ) );
		$sku             = trim( (string) $product->get_sku() );
		$source_id       = trim( (string) get_post_meta( $product_id, self::OWNER_SOURCE_META, true ) );
		$dataset         = trim( (string) get_post_meta( $product_id, self::OWNER_DATASET_META, true ) );
		$source_revision = trim( (string) get_post_meta( $product_id, self::SOURCE_REVISION_META, true ) );
		$price_status    = trim( (string) get_post_meta( $product_id, self::PRICE_STATUS_META, true ) );
		if (
			! $this->safe_identifier( $product_code )
			|| ! hash_equals( $product_code, $owner_code )
			|| ! hash_equals( $product_code, $sku )
			|| ! $this->safe_identifier( $source_id )
			|| ! $this->safe_identifier( $dataset )
			|| ! $this->valid_event_id( $source_revision )
			|| '' === $price_status
			|| sanitize_key( $price_status ) !== $price_status
		) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_identity_invalid', 'The repair product identity is invalid.' );
		}

		$missing_json = get_post_meta( $product_id, self::MISSING_FIELDS_META, true );
		if ( ! is_string( $missing_json ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_fields_invalid', 'The repair missing-field snapshot is invalid.' );
		}
		$missing_fields = json_decode( $missing_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_fields_invalid', 'The repair missing-field snapshot is invalid.' );
		}
		$normalized_fields = $this->normalize_missing_fields( $missing_fields );
		if ( is_wp_error( $normalized_fields ) || $missing_fields !== $normalized_fields ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_fields_invalid', 'The repair missing-field snapshot is invalid.' );
		}
		$missing_fields = $normalized_fields;

		$stock_status = (string) $product->get_stock_status();

		return array(
			'product_id'      => $product_id,
			'product_code'    => $product_code,
			'name'            => (string) $product->get_name(),
			'source_id'       => $source_id,
			'dataset'         => $dataset,
			'source_revision' => $source_revision,
			'missing_fields'  => $missing_fields,
			'visible'         => true,
			'purchasable'     => ! in_array( 'price', $missing_fields, true ) && 'outofstock' !== $stock_status,
			'price_status'    => $price_status,
		);
	}

	/**
	 * Persist the next bounded repair page under the shared store mutex.
	 *
	 * @param int $page One-based next page.
	 * @return true|WP_Error
	 */
	private function persist_repair_page( int $page ) {
		$lock = $this->acquire_store_lock();
		if ( false === $lock ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_store_busy', 'The alert store is busy.' );
		}

		try {
			$store = $this->read_store();
			if ( is_wp_error( $store ) ) {
				return $store;
			}
			$store['repair_page'] = max( 1, $page );
			if ( ! $this->store_verified( $store ) ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_repair_cursor_failed', 'The repair cursor could not be verified.' );
			}

			return true;
		} finally {
			$this->release_store_lock( $lock );
		}
	}

	/**
	 * Persist latest states and transitions with one verified option rewrite.
	 *
	 * @param array $snapshots Normalized committed snapshots in occurrence order.
	 * @return array|WP_Error
	 */
	private function persist_snapshot_transitions( array $snapshots ) {
		$lock = $this->acquire_store_lock();
		if ( false === $lock ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_store_busy', 'The alert store is busy.' );
		}

		try {
			$store = $this->read_store();
			if ( is_wp_error( $store ) ) {
				return $store;
			}

			$queued      = 0;
			$transitions = array();
			foreach ( $snapshots as $snapshot ) {
				$snapshot = $this->normalize_snapshot( $snapshot );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}

				$product_key = $this->product_key( $snapshot );
				$previous    = is_array( $store['products'][ $product_key ] ?? null )
					? $store['products'][ $product_key ]
					: array();
				$before      = $this->normalize_missing_fields( $previous['missing_fields'] ?? array() );
				if ( is_wp_error( $before ) ) {
					$before = array();
				}
				$current             = $snapshot['missing_fields'];
				$transition          = $this->transition( $previous, $before, $current );
				$transition_sequence = max( 0, (int) ( $previous['transition_sequence'] ?? 0 ) );

				if ( '' !== $transition ) {
					++$transition_sequence;
					$event = $this->build_alert_event( $snapshot, $before, $transition, $transition_sequence );
					if ( is_wp_error( $event ) ) {
						return $event;
					}
					$event_id = $event['event_id'];
					if ( ! isset( $store['receipts'][ $event_id ] ) && ! isset( $store['outbox'][ $event_id ] ) ) {
						if ( count( $store['outbox'] ) >= self::MAX_OUTBOX_EVENTS ) {
							return new WP_Error( 'digitalogic_patris_incomplete_alert_outbox_full', 'The alert outbox is full.' );
						}
						$now                          = gmdate( 'c' );
						$sequence                     = max( 1, (int) ( $store['next_sequence'] ?? 1 ) );
						$store['next_sequence']       = $sequence + 1;
						$store['outbox'][ $event_id ] = array(
							'event'           => $event,
							'product_key'     => $product_key,
							'sequence'        => $sequence,
							'status'          => 'pending',
							'attempts'        => 0,
							'next_attempt_at' => time(),
							'lease_token'     => '',
							'lease_until'     => 0,
							'last_error'      => '',
							'created_at'      => $now,
							'updated_at'      => $now,
						);
						++$queued;
					}
					$transitions[] = $transition;
				}

				$store['products'][ $product_key ] = array(
					'product_id'          => $snapshot['product_id'],
					'product_code'        => $snapshot['product_code'],
					'name'                => $snapshot['name'],
					'source_id'           => $snapshot['source_id'],
					'dataset'             => $snapshot['dataset'],
					'source_revision'     => $snapshot['source_revision'],
					'missing_fields'      => $current,
					'missing_fingerprint' => $this->missing_fingerprint( $current ),
					'transition_sequence' => $transition_sequence,
					'visible'             => $snapshot['visible'],
					'purchasable'         => $snapshot['purchasable'],
					'price_status'        => $snapshot['price_status'],
					'observed_at'         => gmdate( 'c' ),
				);
			}
			$store = $this->prune_store( $store );
			if ( ! $this->store_verified( $store ) ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_store_failed', 'The alert store could not be verified.' );
			}

			return array(
				'queued'      => $queued,
				'transitions' => $transitions,
			);
		} finally {
			$this->release_store_lock( $lock );
		}
	}

	/**
	 * Claim a bounded batch, preserving per-product transition order.
	 *
	 * @param int $limit Maximum claims.
	 * @return array|WP_Error
	 */
	private function claim_due_events( int $limit ) {
		$lock = $this->acquire_store_lock();
		if ( false === $lock ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_store_busy', 'The alert store is busy.' );
		}

		try {
			$store = $this->read_store();
			if ( is_wp_error( $store ) ) {
				return $store;
			}
			$changed = false;
			$claims  = array();
			$blocked = array();
			$now     = time();
			$limit   = max( 1, min( self::MAX_CLAIM_BATCH, $limit ) );
			uasort(
				$store['outbox'],
				static function ( $left, $right ) {
					return (int) ( $left['sequence'] ?? 0 ) <=> (int) ( $right['sequence'] ?? 0 );
				}
			);
			foreach ( $store['outbox'] as $event_id => $entry ) {
				if ( isset( $store['receipts'][ $event_id ] ) ) {
					unset( $store['outbox'][ $event_id ] );
					$changed = true;
					continue;
				}
				$product_key = $this->valid_event_id( $entry['product_key'] ?? null )
					? (string) $entry['product_key']
					: 'event:' . $event_id;
				if ( isset( $blocked[ $product_key ] ) ) {
					continue;
				}
				$attempts        = (int) ( $entry['attempts'] ?? 0 );
				$receipt_pending = $this->is_receipt_pending_entry( $entry );
				if ( $attempts >= self::MAX_ATTEMPTS && ! $receipt_pending ) {
					if ( 'exhausted' !== (string) ( $entry['status'] ?? '' ) ) {
						$store['outbox'][ $event_id ]['status']     = 'exhausted';
						$store['outbox'][ $event_id ]['updated_at'] = gmdate( 'c' );
						$changed                                    = true;
					}
					$blocked[ $product_key ] = true;
					continue;
				}
				if ( (int) ( $entry['next_attempt_at'] ?? 0 ) > $now || (int) ( $entry['lease_until'] ?? 0 ) > $now ) {
					$blocked[ $product_key ] = true;
					continue;
				}

				$token                        = 'sha256:' . hash( 'sha256', $event_id . '|' . wp_generate_uuid4() . '|' . microtime( true ) );
				$entry['status']              = 'delivering';
				$entry['attempts']            = $attempts + 1;
				$entry['lease_token']         = $token;
				$entry['lease_until']         = $now + self::LEASE_SECONDS;
				$entry['next_attempt_at']     = 0;
				$entry['updated_at']          = gmdate( 'c' );
				$store['outbox'][ $event_id ] = $entry;
				$blocked[ $product_key ]      = true;
				$changed                      = true;
				$claims[]                     = array(
					'event_id'    => (string) $event_id,
					'lease_token' => $token,
					'attempts'    => $entry['attempts'],
					'event'       => $entry['event'],
				);
				if ( count( $claims ) >= $limit ) {
					break;
				}
			}

			if ( $changed && ! $this->store_verified( $store ) ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_claim_failed', 'The alert claims could not be verified.' );
			}

			return $claims;
		} finally {
			$this->release_store_lock( $lock );
		}
	}

	/**
	 * Complete a batch of exact claims with one verified option rewrite.
	 *
	 * @param array $claims     Durable outbox claims.
	 * @param array $deliveries Adapter results keyed by event ID.
	 * @return true|WP_Error
	 */
	private function complete_claims( array $claims, array $deliveries ) {
		$lock = $this->acquire_store_lock();
		if ( false === $lock ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_store_busy', 'The alert store is busy.' );
		}

		try {
			$store = $this->read_store();
			if ( is_wp_error( $store ) ) {
				return $store;
			}
			$lost = false;
			foreach ( $claims as $claim ) {
				$event_id = (string) ( $claim['event_id'] ?? '' );
				$entry    = is_array( $store['outbox'][ $event_id ] ?? null ) ? $store['outbox'][ $event_id ] : array();
				if ( empty( $entry ) || ! hash_equals( (string) ( $entry['lease_token'] ?? '' ), (string) ( $claim['lease_token'] ?? '' ) ) ) {
					$lost = true;
					continue;
				}
				$delivery = $deliveries[ $event_id ] ?? new WP_Error( 'digitalogic_patris_incomplete_alert_delivery_missing', 'The adapter result is missing.' );

				if ( ! is_wp_error( $delivery ) ) {
					$store['receipts'][ $event_id ] = array(
						'event_id'            => $event_id,
						'transition'          => (string) ( $claim['event']['transition'] ?? '' ),
						'transition_sequence' => (int) ( $claim['event']['transition_sequence'] ?? 0 ),
						'product_code'        => (string) ( $claim['event']['object']['product_code'] ?? '' ),
						'missing_fingerprint' => (string) ( $claim['event']['fingerprint'] ?? '' ),
						'channel_receipts'    => array( self::CHANNEL => $delivery ),
						'delivered_at'        => gmdate( 'c' ),
					);
					unset( $store['outbox'][ $event_id ] );
				} else {
					$attempts                     = (int) ( $entry['attempts'] ?? 0 );
					$error_code                   = sanitize_key( (string) $delivery->get_error_code() );
					$receipt_pending              = $this->is_receipt_pending_error_code( $error_code );
					$entry['status']              = $receipt_pending ? 'receipt_pending' : ( $attempts >= self::MAX_ATTEMPTS ? 'exhausted' : 'pending' );
					$entry['next_attempt_at']     = $receipt_pending
						? time() + self::RECEIPT_PENDING_RETRY
						: ( $attempts >= self::MAX_ATTEMPTS ? 0 : time() + $this->retry_delay( $attempts ) );
					$entry['lease_token']         = '';
					$entry['lease_until']         = 0;
					$entry['last_error']          = $error_code;
					$entry['updated_at']          = gmdate( 'c' );
					$store['outbox'][ $event_id ] = $entry;
				}
			}

			$store = $this->prune_store( $store );
			if ( ! $this->store_verified( $store ) ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_completion_failed', 'The alert completions could not be verified.' );
			}
			if ( $lost ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_claim_lost', 'One or more alert claims are no longer current.' );
			}

			return true;
		} finally {
			$this->release_store_lock( $lock );
		}
	}

	/**
	 * Deliver one canonical AlertEvent through the approved private adapter.
	 *
	 * The adapter must use the deterministic event ID as its idempotency key and
	 * return a Telegram provider receipt. A relay acceptance or pending receipt
	 * is deliberately not delivery success and remains retryable in the outbox.
	 *
	 * @param mixed $event Canonical AlertEvent.
	 * @return array|WP_Error Normalized provider receipt or error.
	 */
	private function deliver_alert_event( $event ) {
		if ( ! is_array( $event ) || ! $this->valid_event_id( $event['event_id'] ?? null ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_event_invalid', 'The alert event is invalid.' );
		}
		if ( ! hash_equals( (string) $event['event_id'], (string) ( $event['idempotency_key'] ?? '' ) ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_identity_invalid', 'The alert idempotency identity is invalid.' );
		}
		if ( array( self::CHANNEL ) !== ( $event['notify_channels'] ?? null ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_channel_invalid', 'The alert channel allow-list is invalid.' );
		}
		if ( array( self::OPERATOR_KEY ) !== ( $event['audience']['operators'] ?? null ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_audience_invalid', 'The alert audience is invalid.' );
		}

		$adapter = apply_filters( self::ADAPTER_FILTER, null, $event );
		if ( ! is_callable( $adapter ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_route_unavailable', 'The private operator route is unavailable.' );
		}

		try {
			$receipt = call_user_func( $adapter, $event );
		} catch ( Throwable $error ) {
			unset( $error );
			return new WP_Error( 'digitalogic_patris_incomplete_alert_route_failed', 'The private operator route failed.' );
		}
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}

		return $this->normalize_provider_receipt( $receipt, $event );
	}

	/**
	 * Validate and minimize a successful Telegram provider receipt.
	 *
	 * @param mixed $receipt Private route receipt.
	 * @param array $event   Canonical AlertEvent.
	 * @return array|WP_Error
	 */
	private function normalize_provider_receipt( $receipt, array $event ) {
		if ( ! is_array( $receipt ) || array_is_list( $receipt ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_receipt_invalid', 'The private operator route returned an invalid receipt.' );
		}

		$provider_receipt = is_array( $receipt['provider_receipt'] ?? null ) ? $receipt['provider_receipt'] : array();
		$status           = sanitize_key( (string) ( $receipt['status'] ?? '' ) );
		$channel          = sanitize_key( (string) ( $receipt['channel'] ?? '' ) );
		$audience         = sanitize_key( (string) ( $receipt['audience'] ?? '' ) );
		$event_id         = (string) ( $receipt['event_id'] ?? '' );
		$idempotency_key  = (string) ( $receipt['idempotency_key'] ?? '' );
		$provider         = sanitize_key( (string) ( $provider_receipt['provider'] ?? $receipt['provider'] ?? '' ) );
		$message_id       = trim( (string) ( $provider_receipt['message_id'] ?? $receipt['provider_message_id'] ?? '' ) );
		$delivered_at     = trim( (string) ( $provider_receipt['delivered_at'] ?? $receipt['delivered_at'] ?? '' ) );
		$delivered_time   = strtotime( $delivered_at );
		$n8n_status_code  = isset( $receipt['n8n_status_code'] ) ? absint( $receipt['n8n_status_code'] ) : 0;

		if ( in_array( $status, array( 'pending', 'accepted', 'queued' ), true ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_route_pending', 'The private operator route has not produced a provider receipt yet.' );
		}
		if (
			! in_array( $status, array( 'delivered', 'sent', 'success' ), true )
			|| self::CHANNEL !== $channel
			|| self::OPERATOR_KEY !== $audience
			|| ! hash_equals( (string) $event['event_id'], $event_id )
			|| ! hash_equals( (string) $event['event_id'], $idempotency_key )
			|| self::CHANNEL !== $provider
			|| ! $this->receipt_identifier( $message_id )
			|| false === $delivered_time
			|| ( $n8n_status_code > 0 && ( $n8n_status_code < 200 || $n8n_status_code >= 300 ) )
		) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_receipt_invalid', 'The private operator route returned an invalid receipt.' );
		}

		$normalized = array(
			'status'             => 'delivered',
			'channel'            => self::CHANNEL,
			'audience'           => self::OPERATOR_KEY,
			'event_id'           => $event_id,
			'idempotency_key'    => $idempotency_key,
			'delivery_confirmed' => true,
			'provider_receipt'   => array(
				'provider'     => self::CHANNEL,
				'message_id'   => mb_substr( $message_id, 0, 191 ),
				'delivered_at' => gmdate( 'c', $delivered_time ),
			),
		);

		foreach ( array( 'route', 'relay_id', 'n8n_execution_id' ) as $key ) {
			$value = trim( (string) ( $receipt[ $key ] ?? '' ) );
			if ( $this->receipt_identifier( $value ) ) {
				$normalized[ $key ] = mb_substr( $value, 0, 191 );
			}
		}
		if ( $n8n_status_code > 0 ) {
			$normalized['n8n_status_code'] = $n8n_status_code;
		}

		return $normalized;
	}

	/**
	 * Build a secret-free canonical AlertEvent with one deterministic identity.
	 *
	 * @param array  $snapshot            Normalized committed snapshot.
	 * @param array  $before              Prior exact missing-field set.
	 * @param string $transition          Transition name.
	 * @param int    $transition_sequence Monotonic product transition sequence.
	 * @return array|WP_Error
	 */
	private function build_alert_event( array $snapshot, array $before, string $transition, int $transition_sequence ) {
		$current     = $snapshot['missing_fields'];
		$fingerprint = $this->missing_fingerprint( $current );
		$identity    = array(
			'schema'              => self::ALERT_SCHEMA,
			'source_id'           => $snapshot['source_id'],
			'dataset'             => $snapshot['dataset'],
			'source_revision'     => $snapshot['source_revision'],
			'product_code'        => $snapshot['product_code'],
			'transition'          => $transition,
			'transition_sequence' => $transition_sequence,
			'missing_fingerprint' => $fingerprint,
		);
		$json        = wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_identity_failed', 'The alert identity could not be encoded.' );
		}
		$event_id        = 'sha256:' . hash( 'sha256', $json );
		$labels          = $this->missing_labels( $current );
		$resolved_labels = $this->missing_labels( $before );
		$impact          = $this->impact_message( $snapshot );
		$is_recovery     = 'recovered' === $transition;

		if ( $is_recovery ) {
			$title           = 'رفع نقص اطلاعات محصول ' . $snapshot['product_code'];
			$operator_action = sprintf(
				'لطفاً اطلاعات کد %s را در Patris کنترل کنید؛ کمبود پیگیری‌شده‌ای باقی نمانده است.',
				$snapshot['product_code']
			);
			$message         = sprintf(
				'اطلاعات ناقص قبلی محصول «%s» با کد/SKU %s اکنون کامل شده است. موارد برطرف‌شده: %s. %s',
				$snapshot['name'],
				$snapshot['product_code'],
				'' !== $resolved_labels ? $resolved_labels : 'موارد پیگیری‌شده',
				$operator_action
			);
		} else {
			$title           = 'هشدار نقص اطلاعات محصول ' . $snapshot['product_code'];
			$operator_action = sprintf(
				'لطفاً در Patris برای کد %s این موارد را تکمیل یا اصلاح کنید: %s.',
				$snapshot['product_code'],
				$labels
			);
			$message         = sprintf(
				'محصول «%s» با کد/SKU %s در سایت ساخته شده است. اطلاعات ناقص: %s. اثر: %s %s',
				$snapshot['name'],
				$snapshot['product_code'],
				$labels,
				$impact,
				$operator_action
			);
		}
		$observed_at = gmdate( 'c' );

		return array(
			'schema'               => self::ALERT_SCHEMA,
			'event_id'             => $event_id,
			'idempotency_key'      => $event_id,
			'event_type'           => 'catalog.product_incomplete',
			'category'             => 'catalog',
			'source'               => 'digitalogic-patris-materializer',
			'source_id'            => 'digitalogic-patris-materializer',
			'resource'             => 'catalog.product:' . $snapshot['product_code'],
			'condition'            => 'catalog.product_incomplete',
			'state'                => $is_recovery ? 'resolved' : 'active',
			'status'               => $is_recovery ? 'resolved' : 'warning',
			'severity'             => $is_recovery ? 'info' : 'warning',
			'transition'           => $transition,
			'transition_sequence'  => $transition_sequence,
			'title'                => $title,
			'message'              => $message,
			'summary'              => $message,
			'product_id'           => $snapshot['product_id'],
			'product_code'         => $snapshot['product_code'],
			'sku'                  => $snapshot['product_code'],
			'name'                 => $snapshot['name'],
			'impact'               => $impact,
			'requested_action'     => $operator_action,
			'source_context'       => array(
				'system'   => 'patris',
				'id'       => $snapshot['source_id'],
				'dataset'  => $snapshot['dataset'],
				'revision' => $snapshot['source_revision'],
			),
			'object'               => array(
				'type'         => 'product',
				'product_id'   => $snapshot['product_id'],
				'product_code' => $snapshot['product_code'],
				'sku'          => $snapshot['product_code'],
				'name'         => $snapshot['name'],
				'visible'      => $snapshot['visible'],
				'purchasable'  => $snapshot['purchasable'],
				'price_status' => $snapshot['price_status'],
			),
			'missing_fields'       => $current,
			'added_missing_fields' => $is_recovery ? array() : array_values( array_diff( $current, $before ) ),
			'resolved_fields'      => $is_recovery ? $before : array(),
			'fingerprint'          => $fingerprint,
			'notify_channels'      => array( self::CHANNEL ),
			'audience'             => array( 'operators' => array( self::OPERATOR_KEY ) ),
			'observed_at'          => $observed_at,
			'created_at'           => $observed_at,
		);
	}

	/**
	 * Strictly normalize the materializer's intentionally small snapshot.
	 *
	 * @param mixed $snapshot Candidate snapshot.
	 * @return array|WP_Error
	 */
	private function normalize_snapshot( $snapshot ) {
		if ( ! is_array( $snapshot ) || array_is_list( $snapshot ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_snapshot_invalid', 'The committed snapshot is invalid.' );
		}

		$product_id      = absint( $snapshot['product_id'] ?? 0 );
		$product_code    = trim( (string) ( $snapshot['product_code'] ?? '' ) );
		$name            = trim( sanitize_text_field( (string) ( $snapshot['name'] ?? '' ) ) );
		$source_id       = trim( (string) ( $snapshot['source_id'] ?? '' ) );
		$dataset         = trim( (string) ( $snapshot['dataset'] ?? '' ) );
		$source_revision = (string) ( $snapshot['source_revision'] ?? '' );
		$price_status    = sanitize_key( (string) ( $snapshot['price_status'] ?? '' ) );
		$missing_fields  = $this->normalize_missing_fields( $snapshot['missing_fields'] ?? null );
		if (
			$product_id <= 0
			|| ! $this->safe_identifier( $product_code )
			|| '' === $name
			|| ! $this->safe_identifier( $source_id )
			|| ! $this->safe_identifier( $dataset )
			|| ! $this->valid_event_id( $source_revision )
			|| '' === $price_status
			|| ! is_bool( $snapshot['visible'] ?? null )
			|| ! is_bool( $snapshot['purchasable'] ?? null )
			|| is_wp_error( $missing_fields )
		) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_snapshot_invalid', 'The committed snapshot is invalid.' );
		}

		return array(
			'product_id'      => $product_id,
			'product_code'    => $product_code,
			'name'            => mb_substr( $name, 0, 191 ),
			'source_id'       => $source_id,
			'dataset'         => $dataset,
			'source_revision' => $source_revision,
			'missing_fields'  => $missing_fields,
			'visible'         => $snapshot['visible'],
			'purchasable'     => $snapshot['purchasable'],
			'price_status'    => mb_substr( $price_status, 0, 80 ),
		);
	}

	/**
	 * Normalize the exact public completeness vocabulary.
	 *
	 * @param mixed $fields Candidate field list.
	 * @return array|WP_Error
	 */
	private function normalize_missing_fields( $fields ) {
		if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_fields_invalid', 'The missing-field list is invalid.' );
		}
		$normalized = array();
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! array_key_exists( $field, self::MISSING_FIELD_LABELS ) ) {
				return new WP_Error( 'digitalogic_patris_incomplete_alert_fields_invalid', 'The missing-field list is invalid.' );
			}
			$normalized[] = $field;
		}
		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Select only new/increased incompleteness and full recovery transitions.
	 *
	 * @param array $previous Prior product state.
	 * @param array $before   Prior missing fields.
	 * @param array $current  Current missing fields.
	 * @return string
	 */
	private function transition( array $previous, array $before, array $current ): string {
		if ( empty( $previous ) ) {
			return empty( $current ) ? '' : 'incomplete';
		}
		if ( empty( $current ) ) {
			return empty( $before ) ? '' : 'recovered';
		}

		return empty( array_diff( $current, $before ) ) ? '' : 'increased';
	}

	/**
	 * Human-readable exact missing-field labels.
	 *
	 * @param array $fields Missing-field keys.
	 * @return string
	 */
	private function missing_labels( array $fields ): string {
		return implode(
			'، ',
			array_values(
				array_map(
					static function ( $field ) {
						return self::MISSING_FIELD_LABELS[ $field ];
					},
					$fields
				)
			)
		);
	}

	/**
	 * Describe customer-visible impact without fabricating a price or stock.
	 *
	 * @param array $snapshot Normalized snapshot.
	 * @return string
	 */
	private function impact_message( array $snapshot ): string {
		$missing_price = in_array( 'price', $snapshot['missing_fields'], true );
		if ( $snapshot['visible'] && ! $snapshot['purchasable'] && $missing_price ) {
			return 'محصول قابل مشاهده است، اما قابل خرید نیست و قیمت ندارد.';
		}
		if ( $snapshot['visible'] && ! $snapshot['purchasable'] ) {
			return 'محصول قابل مشاهده است، اما فعلاً قابل خرید نیست.';
		}
		if ( $snapshot['visible'] && $missing_price ) {
			return 'محصول قابل مشاهده است، اما قیمت ندارد.';
		}
		if ( $snapshot['visible'] ) {
			return 'محصول در سایت قابل مشاهده است.';
		}

		return 'محصول ساخته شده است، اما وضعیت نمایش آن باید بررسی شود.';
	}

	/**
	 * Return a stable product-scoped storage key without using raw option keys.
	 *
	 * @param array $snapshot Normalized snapshot.
	 * @return string
	 */
	private function product_key( array $snapshot ): string {
		return 'sha256:' . hash(
			'sha256',
			$snapshot['source_id'] . "\n" . $snapshot['dataset'] . "\n" . $snapshot['product_code']
		);
	}

	/**
	 * Return a stable exact missing-set identity.
	 *
	 * @param array $fields Missing-field keys.
	 * @return string
	 */
	private function missing_fingerprint( array $fields ): string {
		return 'sha256:' . hash( 'sha256', implode( "\n", $fields ) );
	}

	/**
	 * Load and minimally validate the single durable state/outbox/receipt store.
	 *
	 * @return array|WP_Error
	 */
	private function read_store() {
		$row = $this->read_store_row();
		$this->clear_store_cache();
		$raw = is_array( $row ) && array_key_exists( 'option_value', $row )
			? maybe_unserialize( $row['option_value'] )
			: null;
		if ( false === $row ) {
			$raw = get_option( self::STORE_OPTION, null );
		}
		if ( null === $raw ) {
			return $this->empty_store();
		}
		if (
			! is_array( $raw )
			|| self::STORE_SCHEMA_VERSION !== (int) ( $raw['schema_version'] ?? 0 )
			|| ! is_array( $raw['products'] ?? null )
			|| ! is_array( $raw['outbox'] ?? null )
			|| ! is_array( $raw['receipts'] ?? null )
		) {
			return new WP_Error( 'digitalogic_patris_incomplete_alert_store_invalid', 'The alert store is invalid.' );
		}

		return $this->prune_store( $raw );
	}

	/** Empty durable store. */
	private function empty_store(): array {
		return array(
			'schema_version' => self::STORE_SCHEMA_VERSION,
			'next_sequence'  => 1,
			'repair_page'    => 1,
			'products'       => array(),
			'outbox'         => array(),
			'receipts'       => array(),
		);
	}

	/**
	 * Keep completed history and product state bounded without dropping outbox.
	 *
	 * @param array $store Combined durable store.
	 * @return array
	 */
	private function prune_store( array $store ): array {
		$store['schema_version'] = self::STORE_SCHEMA_VERSION;
		$store['next_sequence']  = max( 1, (int) ( $store['next_sequence'] ?? 1 ) );
		$store['repair_page']    = max( 1, (int) ( $store['repair_page'] ?? 1 ) );
		$store['products']       = is_array( $store['products'] ?? null ) ? $store['products'] : array();
		$store['outbox']         = is_array( $store['outbox'] ?? null ) ? $store['outbox'] : array();
		$store['receipts']       = is_array( $store['receipts'] ?? null ) ? $store['receipts'] : array();
		foreach ( $store['outbox'] as &$entry ) {
			if ( ! is_array( $entry ) ) {
				$entry = array();
			}
			$sequence = (int) ( $entry['sequence'] ?? 0 );
			if ( $sequence < 1 ) {
				$sequence               = $store['next_sequence'];
				$entry['sequence']      = $sequence;
				$store['next_sequence'] = $sequence + 1;
			} else {
				$store['next_sequence'] = max( $store['next_sequence'], $sequence + 1 );
			}
		}
		unset( $entry );

		$cutoff = time() - self::RECEIPT_TTL;
		foreach ( $store['receipts'] as $event_id => $receipt ) {
			$delivered = is_array( $receipt ) ? strtotime( (string) ( $receipt['delivered_at'] ?? '' ) ) : false;
			if ( ! $this->valid_event_id( $event_id ) || false === $delivered || $delivered < $cutoff ) {
				unset( $store['receipts'][ $event_id ] );
			}
		}
		$this->sort_by_time( $store['receipts'], 'delivered_at' );
		if ( count( $store['receipts'] ) > self::MAX_RECEIPTS ) {
			$store['receipts'] = array_slice( $store['receipts'], -self::MAX_RECEIPTS, null, true );
		}

		$this->sort_by_time( $store['products'], 'observed_at' );
		if ( count( $store['products'] ) > self::MAX_PRODUCT_STATES ) {
			$store['products'] = array_slice( $store['products'], -self::MAX_PRODUCT_STATES, null, true );
		}

		return $store;
	}

	/**
	 * Sort associative records oldest-first by one ISO timestamp.
	 *
	 * @param array  $records Records to sort in place.
	 * @param string $field   Timestamp field.
	 * @return void
	 */
	private function sort_by_time( array &$records, string $field ): void {
		uasort(
			$records,
			static function ( $left, $right ) use ( $field ) {
				return strtotime( (string) ( $left[ $field ] ?? '' ) ) <=> strtotime( (string) ( $right[ $field ] ?? '' ) );
			}
		);
	}

	/**
	 * Persist and read back the exact combined store.
	 *
	 * @param array $store Combined durable store.
	 * @return bool
	 */
	private function store_verified( array $store ): bool {
		$this->clear_store_cache();
		$stored = update_option( self::STORE_OPTION, $store, false );
		$row    = $this->read_store_row();
		$exact  = is_array( $row )
			&& array_key_exists( 'option_value', $row )
			&& (string) maybe_serialize( $store ) === (string) $row['option_value'];
		$this->clear_store_cache();

		return ( true === $stored || $exact ) && $exact;
	}

	/** Return the raw durable alert-store row without trusting Redis. */
	private function read_store_row() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The alert-store mutex serializes this authoritative readback.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1 /* digitalogic_patris_alert_store_readback */",
				self::STORE_OPTION
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/** Evict option-cache locations that could mask the durable alert store. */
	private function clear_store_cache(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( self::STORE_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Acquire the database-wide store mutex with one bounded second of waiting.
	 *
	 * @return string|false
	 */
	private function acquire_store_lock() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 'process';
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::STORE_LOCK_NAME, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		} catch ( Throwable $error ) {
			return false;
		}

		return 1 === (int) $locked ? 'database' : false;
	}

	/**
	 * Release an acquired store mutex.
	 *
	 * @param mixed $lock Acquired lock marker.
	 * @return void
	 */
	private function release_store_lock( $lock ): void {
		global $wpdb;
		if ( 'database' !== $lock || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::STORE_LOCK_NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Release the exact advisory mutex above.
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Bounded exponential retry delay after attempts one and two.
	 *
	 * @param int $attempts Completed attempts.
	 * @return int
	 */
	private function retry_delay( int $attempts ): int {
		return $attempts <= 1 ? 60 : 300;
	}

	/**
	 * Whether a durable provider receipt may still arrive for this exact event ID.
	 *
	 * @param mixed $entry Durable outbox entry.
	 */
	private function is_receipt_pending_entry( $entry ): bool {
		$entry = is_array( $entry ) ? $entry : array();

		return 'receipt_pending' === (string) ( $entry['status'] ?? '' )
			|| $this->is_receipt_pending_error_code( (string) ( $entry['last_error'] ?? '' ) );
	}

	/**
	 * Only idempotent relay-pending outcomes may retry beyond the local limit.
	 *
	 * @param string $code Delivery error code.
	 */
	private function is_receipt_pending_error_code( string $code ): bool {
		return in_array(
			sanitize_key( $code ),
			array(
				'digitalogic_patris_incomplete_alert_adapter_pending',
				'digitalogic_patris_incomplete_alert_route_pending',
			),
			true
		);
	}

	/**
	 * Safe source/product identifiers only.
	 *
	 * @param string $value Candidate identifier.
	 * @return bool
	 */
	private function safe_identifier( string $value ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $value );
	}

	/**
	 * Validate one bounded, non-secret route receipt identifier.
	 *
	 * @param string $value Candidate identifier.
	 * @return bool
	 */
	private function receipt_identifier( string $value ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/D', $value );
	}

	/**
	 * Validate lowercase sha256 identities used by revisions, events, and claims.
	 *
	 * @param mixed $value Candidate identity.
	 * @return bool
	 */
	private function valid_event_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $value );
	}

	/**
	 * Emit only a coarse, secret-free local failure signal.
	 *
	 * @param string $code         Coarse error code.
	 * @param string $product_code Optional Product Code.
	 * @return void
	 */
	private function report_failure( string $code, string $product_code = '' ): void {
		$code         = sanitize_key( $code );
		$product_code = $this->safe_identifier( $product_code ) ? $product_code : '';
		try {
			do_action( 'digitalogic_patris_incomplete_product_alert_failed', $code, $product_code );
		} catch ( Throwable $error ) {
			unset( $error );
		}
		try {
			error_log( '[Digitalogic incomplete product alert] ' . $code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Coarse local operational signal contains no payload or secret.
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}
}
