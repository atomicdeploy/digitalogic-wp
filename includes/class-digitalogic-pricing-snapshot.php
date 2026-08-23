<?php
/**
 * Immutable, revision-bound WooCommerce/Patris pricing snapshots.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Internal service methods keep concise type-neutral parameter documentation.

/**
 * Builds the expensive reconciled projection once and serves cached pages.
 */
final class Digitalogic_Pricing_Snapshot {

	public const REVISION_SCHEMA       = 'digitalogic.pricing-sync-revision';
	public const REQUEST_SCHEMA        = 'digitalogic.pricing-snapshot-request';
	public const BUILD_SCHEMA          = 'digitalogic.pricing-snapshot-build';
	public const SNAPSHOT_SCHEMA       = 'digitalogic.pricing-snapshot';
	public const PAGE_SCHEMA           = 'digitalogic.pricing-snapshot-page';
	public const STATE_EVENT_SCHEMA    = 'digitalogic.pricing-state-change';
	public const SOURCE_EVENT_SCHEMA   = 'digitalogic.pricing-source-change';
	public const TERMINAL_EVENT_SCHEMA = 'digitalogic.pricing-snapshot-build-event';
	public const PROJECTION            = 'excel';
	public const PROJECTION_SCHEMA     = 'digitalogic.pricing-projection/excel';
	public const PRICING_POLICY_SCHEMA = 'digitalogic.pricing-policy';

	private const BUILD_HOOK                        = 'digitalogic_pricing_snapshot_build';
	private const BUILD_WATCHDOG_HOOK               = 'digitalogic_pricing_snapshot_build_watchdog';
	private const CLEANUP_HOOK                      = 'digitalogic_pricing_snapshot_cleanup_idempotency';
	private const STATE_EVENT_HOOK                  = 'digitalogic_pricing_state_event_delivery';
	private const STATE_EVENT_HANDOFF_HOOK          = 'digitalogic_pricing_state_event_handoff';
	private const STATE_EVENT_RECOVERY_HOOK         = 'digitalogic_pricing_state_event_recovery';
	private const TERMINAL_EVENT_HOOK               = 'digitalogic_pricing_snapshot_terminal_event_delivery';
	private const FRESHNESS_HOOK                    = 'digitalogic_pricing_freshness_boundary';
	private const ACTION_GROUP                      = 'digitalogic-pricing-snapshots';
	private const ACTIVE_BUILD_OPTION               = 'digitalogic_pricing_snapshot_active';
	private const STATE_EVENT_OUTBOX                = 'digitalogic_pricing_state_event_outbox';
	private const STATE_EVENT_RECEIPTS              = 'digitalogic_pricing_state_event_receipts';
	private const SOURCE_EVENT_OUTBOX               = 'digitalogic_pricing_source_event_outbox';
	private const TERMINAL_EVENT_OUTBOX             = 'digitalogic_pricing_snapshot_terminal_event_outbox';
	private const FRESHNESS_SCHEDULE                = 'digitalogic_pricing_freshness_boundary_schedule';
	private const ADMISSION_LOCK_NAME               = 'digitalogic_pricing_snapshot_admission';
	private const STATE_EVENT_SCHEDULE_LOCK_NAME    = 'digitalogic_pricing_state_event_schedule';
	private const TERMINAL_EVENT_SCHEDULE_LOCK_NAME = 'digitalogic_pricing_terminal_event_schedule';
	private const STATE_EVENT_RECEIPT_TTL           = HOUR_IN_SECONDS;
	private const STATE_EVENT_RECEIPT_LIMIT         = 200;
	private const SNAPSHOT_TTL                      = 900;
	private const METADATA_TTL                      = 1800;
	private const BUILD_TTL                         = 1800;
	private const QUEUE_START_TTL                   = 30;
	private const WORKER_LEASE_TTL                  = 60;
	private const RETRY_AFTER                       = 2;
	private const DEFAULT_PAGE_SIZE                 = 250;
	private const MAX_PAGE_SIZE                     = 250;
	private const MAX_ROWS                          = 20000;
	private const PROJECTION_FIELDS                 = array(
		'sync_key',
		'reconciliation_status',
		'patris_code',
		'woocommerce_id',
		'sku',
		'weight_grams',
		'foreign_price',
		'patris_location',
		'categories',
		'foreign_currency',
		'shipping_price_per_kg',
		'shipping_price_per_kg_currency',
		'profit_margin_percent',
		'price_source_amount',
		'price_source_currency',
		'price_source_kind',
		'effective_price',
		'patris_total_stock',
		'stock_quantity',
		'name',
		'updated_at',
		'record_revision',
		'permalink',
		'patris_final_price',
		'sale_price',
		'publication_status',
	);

	/**
	 * Shared snapshot service.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Request-local worker lease owner.
	 *
	 * @var string
	 */
	private $active_worker_token = '';

	/**
	 * Request-local leased build.
	 *
	 * @var string
	 */
	private $active_worker_build_id = '';

	/**
	 * Request-local nesting depth for the admission database mutex.
	 *
	 * @var int
	 */
	private $admission_lock_held = 0;

	/**
	 * Exact checkpoint failure observed by the active worker.
	 *
	 * @var WP_Error|null
	 */
	private $active_worker_error = null;

	/**
	 * Whether this request changed an input to the composite pricing revision.
	 *
	 * @var bool
	 */
	private $state_revision_event_pending = false;

	/**
	 * Final revisions already published by this request, keyed by exact source.
	 *
	 * @var array
	 */
	private $emitted_state_revisions = array();

	/**
	 * Receiver state captured immediately before direct option deletion.
	 *
	 * @var array
	 */
	private $source_state_before_delete = array();

	/**
	 * Prevent nested rescheduling while a due boundary is being delivered.
	 *
	 * @var bool
	 */
	private $freshness_boundary_running = false;

	/** Register the asynchronous worker and exact invalidation hook. */
	private function __construct() {
		add_action( self::BUILD_HOOK, array( $this, 'run_build' ) );
		add_action( self::BUILD_WATCHDOG_HOOK, array( $this, 'run_build_watchdog' ), 10, 2 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_idempotency' ), 10, 3 );
		add_action( self::STATE_EVENT_HOOK, array( $this, 'run_state_revision_event_delivery' ), 10, 2 );
		add_action( self::STATE_EVENT_HANDOFF_HOOK, array( $this, 'run_state_revision_event_handoff' ), 10, 2 );
		add_action( self::STATE_EVENT_RECOVERY_HOOK, array( $this, 'run_state_revision_event_handoff' ), 10, 2 );
		add_action( self::TERMINAL_EVENT_HOOK, array( $this, 'run_terminal_event_delivery' ) );
		add_action( self::FRESHNESS_HOOK, array( $this, 'run_freshness_boundary' ), 10, 2 );
		add_action( 'digitalogic_excel_pricing_apply_committed', array( $this, 'invalidate_after_apply' ) );
		add_action( 'digitalogic_excel_pricing_settings_updated', array( $this, 'reschedule_freshness_boundary' ) );
		add_action( 'digitalogic_product_sync_applied', array( $this, 'reschedule_freshness_boundary' ) );
		add_action( 'digitalogic_product_sync_state_committed', array( $this, 'capture_committed_source_state' ), 10, 2 );
		add_action( 'digitalogic_report_projection_invalidated', array( $this, 'schedule_state_revision_event' ) );
		add_action( 'updated_option', array( $this, 'capture_source_state_update' ), 20, 3 );
		add_action( 'added_option', array( $this, 'capture_source_state_addition' ), 20, 2 );
		add_action( 'delete_option', array( $this, 'capture_source_state_before_delete' ), 1 );
		add_action( 'deleted_option', array( $this, 'capture_source_state_deletion' ), 20 );
		add_action( 'admin_init', array( $this, 'install_freshness_boundary_schedule' ), 20 );
		add_action( 'shutdown', array( $this, 'publish_scheduled_state_revision_events' ), 1000 );
	}

	/** Return the shared service. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Authenticate a snapshot route against its explicit source query/body.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		$feed = Digitalogic_Patris_Feed::instance();
		if ( empty( $feed->get_product_sync_source_scopes() ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_scope_required',
				'An exact Patris source scope is required for pricing snapshots.',
				403,
				false
			);
		}

		$source = $this->request_source( $request );
		if ( is_wp_error( $source ) || ! $feed->verify_product_sync_request_for_source( $request, $source, false ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_unauthorized',
				'The pricing snapshot credential or source scope is invalid.',
				401,
				false
			);
		}

		return true;
	}

	/**
	 * Return a cheap composite revision without building catalog rows.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error Transport result.
	 */
	public function revision( WP_REST_Request $request ) {
		$source = $this->request_source( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$locale    = $this->normalize_locale( $request->get_param( 'locale' ) );
		$page_size = $this->normalize_page_size( $request->get_param( 'page_size' ) );
		if ( is_wp_error( $locale ) ) {
			return $locale;
		}
		if ( is_wp_error( $page_size ) ) {
			return $page_size;
		}
		$current = $this->current_revision_data( $source );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$etag = $this->etag( $current['state_revision'] );
		if ( $this->etag_matches( $request->get_header( 'if-none-match' ), $etag ) ) {
			return $this->transport(
				null,
				304,
				array(
					'ETag'          => $etag,
					'Cache-Control' => 'private, no-cache, must-revalidate',
				)
			);
		}

		$data    = array(
			'schema'                  => self::REVISION_SCHEMA,
			'projection'              => self::PROJECTION,
			'projection_schema'       => self::PROJECTION_SCHEMA,
			'state_revision'          => $current['state_revision'],
			'source'                  => $current['source'],
			'catalog_revision'        => $current['catalog_revision'],
			'pricing_state_revision'  => $current['pricing_state_revision'],
			'pricing_policy_revision' => $current['pricing_policy_revision'],
			'locale'                  => $locale,
			'page_size'               => $page_size,
			'capabilities'            => $this->capabilities(),
			'diagnostics'             => array(),
		);
		$headers = array(
			'ETag'          => $etag,
			'Cache-Control' => 'private, no-cache, must-revalidate',
		);
		if ( 'HEAD' === strtoupper( (string) $request->get_method() ) ) {
			$data = null;
		}

		return $this->transport( $data, 200, $headers );
	}

	/**
	 * Admit or reuse one exact asynchronous snapshot build.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error Transport result.
	 */
	public function start( WP_REST_Request $request ) {
		$payload = $this->validate_start_request( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$fingerprint = $this->digest(
			array(
				'projection'              => self::PROJECTION,
				'source'                  => $payload['source'],
				'locale'                  => $payload['locale'],
				'page_size'               => $payload['page_size'],
				'max_age_seconds'         => $payload['max_age_seconds'],
				'expected_state_revision' => $payload['expected_state_revision'],
				'client_id'               => $payload['client_id'],
				'channel'                 => $payload['channel'],
			)
		);
		$admission   = $this->acquire_admission_lock();
		if ( is_wp_error( $admission ) ) {
			return $admission;
		}

		try {
			$build_id    = 'build_' . $this->token();
			$idempotency = $this->claim_idempotency( $payload['request_id'], $fingerprint, $build_id );
			if ( is_wp_error( $idempotency ) ) {
				return $idempotency;
			}
			if ( empty( $idempotency['claimed'] ) ) {
				$existing = $this->read_job( (string) ( $idempotency['record']['build_id'] ?? '' ) );
				if ( is_array( $existing ) ) {
					return $this->job_transport( $existing, true, $payload['request_id'] );
				}

				return $this->error(
					'digitalogic_pricing_snapshot_idempotency_pending',
					'The original idempotent snapshot request has not published readable build state yet.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}

			$current = $this->current_revision_data( $payload['source'] );
			if ( is_wp_error( $current ) ) {
				$this->release_idempotency( $payload['request_id'], $build_id );
				return $current;
			}
			if ( ! hash_equals( $payload['expected_state_revision'], $current['state_revision'] ) ) {
				$this->release_idempotency( $payload['request_id'], $build_id );
				return $this->error(
					'digitalogic_pricing_snapshot_state_revision_conflict',
					'The pricing snapshot state changed; read the revision endpoint again.',
					412,
					false,
					array( 'current_state_revision' => $current['state_revision'] )
				);
			}

			$build_key = $this->build_key( $current['state_revision'], $payload['locale'], $payload['page_size'] );
			$ready     = $this->current_snapshot_meta( $current['state_revision'], $payload['locale'], $payload['page_size'] );
			if ( is_array( $ready ) && $this->snapshot_age( $ready ) <= $payload['max_age_seconds'] ) {
				$job                      = $this->new_job( $payload, $current, $build_key, 'ready', $build_id );
				$job['snapshot_token']    = $ready['snapshot_token'];
				$job['revision']          = $ready['revision'];
				$job['snapshot_revision'] = $ready['revision'];
				$job['digest']            = $ready['digest'];
				$job['row_count']         = $ready['row_count'];
				$job['page_count']        = $ready['page_count'];
				$job['expires_at']        = $ready['expires_at'];
				$job['cached']            = true;
				$job['progress']          = $this->progress( 'ready', 100, $ready['row_count'], $ready['row_count'] );
				if ( ! $this->persist_terminal_event_outbox( $job ) ) {
					$this->release_idempotency( $payload['request_id'], $build_id );
					return $this->terminal_event_storage_error();
				}
				if ( ! $this->store_job( $job ) ) {
					$this->discard_staged_terminal_event_outbox( $job );
					$this->delete_job( $job['build_id'] );
					$this->release_idempotency( $payload['request_id'], $build_id );
					return $this->error(
						'digitalogic_pricing_snapshot_storage_unavailable',
						'The cached pricing snapshot result could not be reserved.',
						503,
						true,
						array(),
						self::RETRY_AFTER
					);
				}
				if ( ! $this->commit_terminal_event_outbox( $job ) ) {
					return $this->terminal_event_storage_error();
				}
				$this->unschedule_build_watchdog( $job );
				$this->unschedule_build_activation( $job );
				$this->publish_scheduled_terminal_events();

				return $this->job_transport( $job, false );
			}

			$slot = $this->acquire_build_slot( $build_id, $build_key );
			if ( is_wp_error( $slot ) ) {
				$this->release_idempotency( $payload['request_id'], $build_id );
				return $slot;
			}
			if ( is_array( $slot ) && ! empty( $slot['build_id'] ) ) {
				$existing = $this->read_job( $slot['build_id'] );
				if ( is_array( $existing ) ) {
					if ( ! $this->move_idempotency( $payload['request_id'], $fingerprint, $build_id, $existing['build_id'] ) ) {
						$this->release_idempotency( $payload['request_id'], $build_id );
						return $this->error(
							'digitalogic_pricing_snapshot_storage_unavailable',
							'The coalesced snapshot request could not reserve its idempotency key.',
							503,
							true,
							array(),
							self::RETRY_AFTER
						);
					}
					$existing = $this->append_terminal_request_id( $existing, $payload['request_id'] );
					if ( is_wp_error( $existing ) ) {
						$this->release_idempotency( $payload['request_id'], $slot['build_id'] );
						return $existing;
					}
					return $this->job_transport( $existing, true, $payload['request_id'] );
				}
				$this->release_idempotency( $payload['request_id'], $build_id );
				return $this->busy_error();
			}

			$job = $this->new_job( $payload, $current, $build_key, 'queued', $build_id );
			if ( ! $this->store_job( $job ) ) {
				$this->delete_job( $build_id );
				$this->release_idempotency( $payload['request_id'], $build_id );
				$this->release_build_slot( $build_id );
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The pricing snapshot build state could not be stored.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}

			// The scheduler may start immediately. Release admission before enqueue so
			// the worker can claim its lease instead of being mistaken for a duplicate.
			$this->release_admission_lock();
			$queued = $this->enqueue_build( $build_id );
			if ( is_wp_error( $queued ) ) {
				if ( ! $this->fail_job( $build_id, $queued ) ) {
					$this->delete_job( $build_id );
					$this->release_idempotency( $payload['request_id'], $build_id );
					$this->release_build_slot( $build_id );
				}
				return $queued;
			}

			return $this->job_transport( $job, false );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Return current build progress. */
	public function status( WP_REST_Request $request ) {
		$job = $this->job_for_request( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return $this->job_transport( $job, false, null, $request );
	}

	/** Cancel a queued/running build without publishing a partial snapshot. */
	public function cancel( WP_REST_Request $request ) {
		$admission = $this->acquire_admission_lock();
		if ( is_wp_error( $admission ) ) {
			return $admission;
		}
		try {
			$job = $this->job_for_request( $request );
			if ( is_wp_error( $job ) ) {
				return $job;
			}
			if ( 'ready' === $job['status'] ) {
				return $this->error(
					'digitalogic_pricing_snapshot_already_ready',
					'A completed immutable snapshot cannot be cancelled.',
					409,
					false
				);
			}
			if ( in_array( $job['status'], array( 'failed', 'cancelled' ), true ) ) {
				return $this->job_transport( $job, true );
			}

			if (
			! set_transient( $this->cancel_key( $job['build_id'] ), true, self::BUILD_TTL )
			&& true !== get_transient( $this->cancel_key( $job['build_id'] ) )
			) {
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The cancellation intent could not be stored.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}
			$previous_status   = $job['status'];
			$job['status']     = 'cancelled';
			$job['updated_at'] = gmdate( 'c' );
			$job['code']       = 'request_cancelled';
			$job['progress']   = $this->progress(
				'cancelled',
				(int) ( $job['progress']['percent'] ?? 0 ),
				(int) ( $job['progress']['completed'] ?? 0 ),
				(int) ( $job['progress']['total'] ?? 0 )
			);
			if ( ! $this->persist_terminal_event_outbox( $job ) ) {
				return $this->terminal_event_storage_error();
			}
			if ( ! $this->store_job( $job ) ) {
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The cancellation state could not be stored; the build was not reported as cancelled.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}
			if ( ! $this->commit_terminal_event_outbox( $job ) ) {
				return $this->terminal_event_storage_error();
			}
			$this->unschedule_build_watchdog( $job );
			$this->unschedule_build_activation( $job );
			if ( 'queued' === $previous_status ) {
				$this->release_build_slot( $job['build_id'] );
			}
			$this->publish_scheduled_terminal_events();

			return $this->job_transport( $job, true );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Return the complete immutable snapshot from cached pages. */
	public function snapshot( WP_REST_Request $request ) {
		$meta = $this->snapshot_for_request( $request );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$etag = $this->etag( $meta['digest'] );

		$rows = array();
		for ( $page = 1; $page <= $meta['page_count']; ++$page ) {
			$page_rows = get_transient( $this->page_key( $meta['snapshot_token'], $page ) );
			if ( ! is_array( $page_rows ) ) {
				return $this->error(
					'digitalogic_pricing_snapshot_cache_incomplete',
					'A cached pricing snapshot page is unavailable.',
					503,
					true,
					array( 'page' => $page ),
					self::RETRY_AFTER
				);
			}
			$rows = array_merge( $rows, $page_rows );
		}
		if ( count( $rows ) !== $meta['row_count'] || ! hash_equals( $meta['digest'], $this->snapshot_digest( $meta, $rows ) ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_digest_mismatch',
				'The cached pricing snapshot failed its integrity check.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		}
		if ( $this->etag_matches( $request->get_header( 'if-none-match' ), $etag ) ) {
			return $this->transport( null, 304, $this->snapshot_headers( $meta, $etag ) );
		}

		return $this->transport(
			$this->snapshot_payload( $meta, $rows ),
			200,
			$this->snapshot_headers( $meta, $etag )
		);
	}

	/** Return one inexpensive immutable snapshot page. */
	public function page( WP_REST_Request $request ) {
		$meta = $this->snapshot_for_request( $request );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$page = absint( $request->get_param( 'page' ) );
		if ( $page < 1 || $page > $meta['page_count'] ) {
			return $this->error(
				'digitalogic_pricing_snapshot_page_invalid',
				'The requested snapshot page is outside the immutable page range.',
				404,
				false,
				array( 'page_count' => $meta['page_count'] )
			);
		}
		$rows = get_transient( $this->page_key( $meta['snapshot_token'], $page ) );
		if ( ! is_array( $rows ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_cache_incomplete',
				'The cached pricing snapshot page is unavailable.',
				503,
				true,
				array( 'page' => $page ),
				self::RETRY_AFTER
			);
		}

		$page_digest = is_string( $meta['page_digests'][ $page - 1 ] ?? null )
			? $meta['page_digests'][ $page - 1 ]
			: '';
		if ( ! $this->is_revision( $page_digest ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_page_digest_invalid',
				'The cached pricing snapshot page has no valid integrity digest.',
				503,
				true,
				array( 'page' => $page ),
				self::RETRY_AFTER
			);
		}
		$actual_page_digest = $this->digest(
			array(
				'page' => $page,
				'rows' => array_values( $rows ),
			)
		);
		if ( ! hash_equals( $page_digest, $actual_page_digest ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_page_digest_mismatch',
				'The cached pricing snapshot page failed its integrity check.',
				503,
				true,
				array( 'page' => $page ),
				self::RETRY_AFTER
			);
		}
		$etag = $this->etag( $page_digest );
		if ( $this->etag_matches( $request->get_header( 'if-none-match' ), $etag ) ) {
			return $this->transport( null, 304, $this->snapshot_headers( $meta, $etag ) );
		}

		$data = array(
			'schema'                  => self::PAGE_SCHEMA,
			'projection'              => self::PROJECTION,
			'projection_schema'       => self::PROJECTION_SCHEMA,
			'snapshot_token'          => $meta['snapshot_token'],
			'revision'                => $meta['revision'],
			'snapshot_revision'       => $meta['revision'],
			'digest'                  => $meta['digest'],
			'page_digest'             => $page_digest,
			'state_revision'          => $meta['state_revision'],
			'pricing_state_revision'  => $meta['pricing_state_revision'],
			'pricing_policy_revision' => $meta['pricing_policy_revision'],
			'catalog_revision'        => $meta['catalog_revision'],
			'dataset_revision'        => $meta['dataset_revision'],
			'source'                  => $meta['source'],
			'expires_at'              => $meta['expires_at'],
			'row_count'               => $meta['row_count'],
			'distinct_sync_keys'      => $meta['distinct_sync_keys'],
			'remote_total'            => $meta['row_count'],
			'page_size'               => $meta['page_size'],
			'page_count'              => $meta['page_count'],
			'page_digests'            => $meta['page_digests'],
			'integrity'               => $meta['integrity'],
			'mutation_guard'          => $meta['mutation_guard'],
			'settings'                => $meta['settings'],
			'reconciliation'          => $meta['reconciliation'],
			'columns'                 => $meta['columns'],
			'rows'                    => array_values( $rows ),
			'pagination'              => array(
				'page'     => $page,
				'limit'    => $meta['page_size'],
				'total'    => $meta['row_count'],
				'pages'    => $meta['page_count'],
				'has_more' => $page < $meta['page_count'],
			),
		);

		return $this->transport( $data, 200, $this->snapshot_headers( $meta, $etag ) );
	}

	/**
	 * Action Scheduler worker entrypoint.
	 *
	 * @param string $build_id Exact build ID.
	 * @return void
	 * @throws RuntimeException When an admission failure cannot be persisted or rescheduled.
	 */
	public function run_build( $build_id ) {
		$worker_token = $this->acquire_worker_lease( $build_id );
		if ( is_wp_error( $worker_token ) ) {
			if ( ! $this->retry_worker( $build_id ) ) {
				if ( ! $this->fail_unleased_queued_job( $build_id, $this->scheduler_retry_error() ) ) {
					throw new RuntimeException( 'The snapshot worker could not persist or reschedule its admission failure.' );
				}
			}
			return;
		}
		if ( ! is_string( $worker_token ) || '' === $worker_token ) {
			return;
		}

		$this->active_worker_build_id = (string) $build_id;
		$this->active_worker_token    = $worker_token;
		$this->active_worker_error    = null;
		try {
			$this->run_build_with_lease( $build_id );
		} catch ( Throwable $error ) {
			$this->record_worker_failure( $build_id, $this->worker_exception_error() );
		} finally {
			$this->release_worker_lease( $build_id, $worker_token );
			$this->active_worker_build_id = '';
			$this->active_worker_token    = '';
			$this->active_worker_error    = null;
		}
	}

	/**
	 * Terminalize or re-arm one exact nonterminal build without client polling.
	 *
	 * @param string $build_id      Exact build ID.
	 * @param string $watchdog_token Per-job random fence.
	 * @return void
	 * @throws RuntimeException When neither a retry nor a durable terminal can be stored.
	 */
	public function run_build_watchdog( $build_id, $watchdog_token ) {
		if (
			1 !== preg_match( '/\Abuild_[a-f0-9]{32}\z/D', (string) $build_id )
			|| 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', (string) $watchdog_token )
		) {
			return;
		}
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			if ( ! $this->schedule_build_watchdog( $build_id, $watchdog_token, time() + self::RETRY_AFTER ) ) {
				throw new RuntimeException( 'The snapshot watchdog could not reacquire admission or reschedule itself.' );
			}
			return;
		}

		$next_timestamp = 0;
		try {
			$job = $this->read_job( (string) $build_id );
			if (
				! is_array( $job )
				|| ! is_string( $watchdog_token )
				|| ! hash_equals( (string) ( $job['watchdog_token'] ?? '' ), $watchdog_token )
			) {
				return;
			}
			if ( in_array( (string) ( $job['status'] ?? '' ), array( 'ready', 'failed', 'cancelled' ), true ) ) {
				$this->unschedule_build_watchdog( $job );
				$this->unschedule_build_activation( $job );
				return;
			}

			if ( 'queued' === (string) $job['status'] ) {
				$job = $this->refresh_queued_job( $job );
			} elseif ( 'running' === (string) $job['status'] ) {
				$job = $this->refresh_stalled_job( $job );
			} else {
				if ( ! $this->fail_job( $build_id, $this->worker_exception_error() ) ) {
					throw new RuntimeException( 'The snapshot watchdog found invalid build state and could not terminalize it.' );
				}
				return;
			}

			if ( ! is_array( $job ) || in_array( (string) ( $job['status'] ?? '' ), array( 'ready', 'failed', 'cancelled' ), true ) ) {
				return;
			}

			$worker          = get_option( $this->worker_key( $build_id ), null );
			$worker_expires  = is_array( $worker ) ? (int) ( $worker['expires_at'] ?? 0 ) : 0;
			$job_deadline    = strtotime( (string) ( $job['deadline_at'] ?? '' ) );
			$queue_deadline  = strtotime( (string) ( $job['start_deadline_at'] ?? '' ) );
			$next_candidates = array_filter(
				array(
					$worker_expires > time() ? $worker_expires + 1 : 0,
					false !== $job_deadline && $job_deadline > time() ? $job_deadline + 1 : 0,
					'queued' === (string) $job['status'] && false !== $queue_deadline && $queue_deadline > time() ? $queue_deadline + 1 : 0,
				)
			);
			$next_timestamp  = $next_candidates ? min( $next_candidates ) : time() + self::RETRY_AFTER;
		} finally {
			$this->release_admission_lock();
		}

		if ( $next_timestamp > 0 && ! $this->schedule_build_watchdog( $build_id, $watchdog_token, $next_timestamp ) ) {
			if ( ! $this->fail_job( $build_id, $this->watchdog_unavailable_error() ) ) {
				throw new RuntimeException( 'The snapshot watchdog could neither reschedule nor terminalize its build.' );
			}
		}
	}

	/** Execute one build only while this request owns its worker lease. */
	private function run_build_with_lease( $build_id ) {
		$transition = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $transition ) ) {
			if ( ! $this->retry_worker( $build_id ) ) {
				$this->record_worker_failure( $build_id, $this->scheduler_retry_error() );
			}
			return;
		}
		try {
			$job = $this->read_job( $build_id );
			if ( ! is_array( $job ) ) {
				return;
			}
			if ( 'cancelled' === $job['status'] || $this->cancellation_requested( $build_id ) ) {
				$this->record_worker_failure( $build_id, $this->cancelled_error() );
				return;
			}
			if ( ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				return;
			}
			if ( 'queued' === $job['status'] && $this->queue_deadline_expired( $job ) ) {
				$this->record_worker_failure( $build_id, $this->scheduler_deadline_error() );
				return;
			}
			if ( ! $this->active_worker_lease_owned( $build_id ) ) {
				return;
			}

			$job['status']     = 'running';
			$job['updated_at'] = gmdate( 'c' );
			$job['progress']   = $this->progress( 'reconciling', 1, 0, 0 );
			if ( ! $this->store_job( $job ) ) {
				$this->record_worker_failure(
					$build_id,
					$this->error( 'digitalogic_pricing_snapshot_storage_unavailable', 'The build progress could not be stored.', 503, true )
				);
				return;
			}
		} finally {
			$this->release_admission_lock();
		}

		$current = $this->current_revision_data( $job['source'] );
		if ( is_wp_error( $current ) || ! hash_equals( $job['state_revision'], (string) ( $current['state_revision'] ?? '' ) ) ) {
			$this->record_worker_failure(
				$build_id,
				is_wp_error( $current ) ? $current : $this->state_changed_error()
			);
			return;
		}

		$checkpoint = function ( $phase, $percent, $completed, $total ) use ( $build_id ) {
			return $this->checkpoint( $build_id, $phase, $percent, $completed, $total );
		};
		$catalog    = Digitalogic_Google_Sheets_Catalog::instance()->get_reconciled_products_snapshot(
			array(
				'locale'         => $job['locale'],
				'source_id'      => $job['source']['id'],
				'source_dataset' => $job['source']['dataset'],
			),
			$checkpoint
		);
		if ( is_wp_error( $catalog ) ) {
			$this->record_worker_failure( $build_id, is_wp_error( $this->active_worker_error ) ? $this->active_worker_error : $catalog );
			return;
		}
		$catalog_source = (array) ( $catalog['reconciliation']['source'] ?? array() );
		foreach ( array( 'id', 'dataset', 'revision' ) as $source_field ) {
			if (
				! is_string( $catalog_source[ $source_field ] ?? null )
				|| ! hash_equals( $job['source'][ $source_field ], $catalog_source[ $source_field ] )
			) {
				$this->record_worker_failure( $build_id, $this->state_changed_error() );
				return;
			}
		}
		if ( ! $this->checkpoint( $build_id, 'verifying', 92, count( (array) ( $catalog['rows'] ?? array() ) ), count( (array) ( $catalog['rows'] ?? array() ) ) ) ) {
			$this->record_worker_failure( $build_id, is_wp_error( $this->active_worker_error ) ? $this->active_worker_error : $this->cancelled_error() );
			return;
		}

		$after = $this->current_revision_data( $job['source'] );
		if ( is_wp_error( $after ) || ! hash_equals( $job['state_revision'], (string) ( $after['state_revision'] ?? '' ) ) ) {
			$this->record_worker_failure(
				$build_id,
				is_wp_error( $after ) ? $after : $this->state_changed_error()
			);
			return;
		}

		$published = $this->publish_snapshot( $job, $after, $catalog );
		if ( is_wp_error( $published ) ) {
			$this->record_worker_failure( $build_id, $published );
			return;
		}
	}

	/**
	 * Durably stage the report/state effect for one committed pricing change.
	 *
	 * @param array $result Versionless committed result.
	 * @return true|WP_Error
	 */
	public function invalidate_after_apply( $result = array() ) {
		$result    = is_array( $result ) ? $result : array();
		$effect_id = (string) ( $result['effect_id'] ?? '' );
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $effect_id ) ) {
			$effect_id = 'sha256:' . hash(
				'sha256',
				"pricing-effect\0"
				. (string) ( $result['state_revision'] ?? '' ) . "\0"
				. (string) ( $result['previous_revision'] ?? '' ) . "\0"
				. (string) ( $result['request_id'] ?? '' )
			);
		}

		$state_event = $this->ensure_state_revision_event();
		$invalidated = Digitalogic_Report_Engine::instance()->invalidate_cache_for_effect( $effect_id );
		if ( is_wp_error( $state_event ) ) {
			return $state_event;
		}
		if ( is_wp_error( $invalidated ) ) {
			return $invalidated;
		}

		return true;
	}

	/** Install or repair the single next time-derived revision transition. */
	public function install_freshness_boundary_schedule() {
		$current = get_option( self::FRESHNESS_SCHEDULE, array() );
		if (
			is_array( $current )
			&& isset( $current['timestamp'], $current['fingerprint'] )
			&& $this->freshness_boundary_is_scheduled(
				(int) $current['timestamp'],
				array( (int) $current['timestamp'], (string) $current['fingerprint'] )
			)
		) {
			return true;
		}

		return $this->reschedule_freshness_boundary();
	}

	/** Remove the tracked one-shot action when the plugin is deactivated. */
	public function deactivate_freshness_boundary_schedule() {
		$current = get_option( self::FRESHNESS_SCHEDULE, array() );
		$this->unschedule_freshness_boundary( is_array( $current ) ? $current : array() );
		delete_option( self::FRESHNESS_SCHEDULE );
	}

	/** Cancel and replace the one-shot freshness action after an input changes. */
	public function reschedule_freshness_boundary() {
		if ( $this->freshness_boundary_running ) {
			return true;
		}
		$boundary = $this->next_freshness_boundary();
		if ( is_wp_error( $boundary ) ) {
			do_action( 'digitalogic_pricing_state_event_failed', $boundary->get_error_code(), array() );
			return false;
		}
		$current = get_option( self::FRESHNESS_SCHEDULE, array() );
		$current = is_array( $current ) ? $current : array();
		if ( null === $boundary ) {
			$this->unschedule_freshness_boundary( $current );
			delete_option( self::FRESHNESS_SCHEDULE );
			return null === get_option( self::FRESHNESS_SCHEDULE, null );
		}

		$timestamp   = (int) $boundary['timestamp'];
		$fingerprint = $this->digest(
			array(
				'schema'    => 'digitalogic.pricing-freshness-boundary',
				'timestamp' => $timestamp,
				'reasons'   => $boundary['reasons'],
			)
		);
		$args        = array( $timestamp, $fingerprint );
		if (
			(int) ( $current['timestamp'] ?? 0 ) === $timestamp
			&& hash_equals( (string) ( $current['fingerprint'] ?? '' ), $fingerprint )
			&& $this->freshness_boundary_is_scheduled( $timestamp, $args )
		) {
			return true;
		}

		$this->unschedule_freshness_boundary( $current );
		$scheduled = false;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$scheduled = (bool) as_schedule_single_action( $timestamp, self::FRESHNESS_HOOK, $args, self::ACTION_GROUP, true );
		} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
			$scheduled = wp_schedule_single_event( $timestamp, self::FRESHNESS_HOOK, $args, true );
			$scheduled = ! is_wp_error( $scheduled ) && false !== $scheduled;
		}
		$record = array(
			'timestamp'   => $timestamp,
			'fingerprint' => $fingerprint,
			'reasons'     => $boundary['reasons'],
		);
		if ( ! $scheduled || ! $this->store_option_verified( self::FRESHNESS_SCHEDULE, $record ) ) {
			$this->unschedule_freshness_boundary( $record );
			do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_freshness_schedule_unavailable', array() );
			return false;
		}

		return true;
	}

	/** Deliver one due boundary exactly once, then schedule only the next transition. */
	public function run_freshness_boundary( $timestamp, $fingerprint ) {
		$timestamp   = (int) $timestamp;
		$fingerprint = (string) $fingerprint;
		$current     = get_option( self::FRESHNESS_SCHEDULE, array() );
		if (
			! is_array( $current )
			|| (int) ( $current['timestamp'] ?? 0 ) !== $timestamp
			|| ! hash_equals( (string) ( $current['fingerprint'] ?? '' ), $fingerprint )
		) {
			return;
		}
		if ( $timestamp > time() ) {
			$this->reschedule_freshness_boundary();
			return;
		}

		$sources   = $this->current_state_event_sources();
		$persisted = $this->persist_state_revision_outbox( $sources, 'freshness-boundary' );
		if ( ! $persisted ) {
			$this->schedule_state_revision_event_retry( $sources );
			delete_option( self::FRESHNESS_SCHEDULE );
			$this->reschedule_freshness_boundary();
			return;
		}
		delete_option( self::FRESHNESS_SCHEDULE );
		if ( null !== get_option( self::FRESHNESS_SCHEDULE, null ) ) {
			$this->schedule_state_revision_event_retry( $sources );
			$this->reschedule_freshness_boundary();
			return;
		}

		$this->freshness_boundary_running = true;
		try {
			$this->publish_scheduled_state_revision_events();
		} finally {
			$this->freshness_boundary_running = false;
		}
		$this->reschedule_freshness_boundary();
	}

	/** Capture an exact receiver-state transition committed by the custom store. */
	public function capture_committed_source_state( $before, $after ) {
		$this->persist_source_lifecycle_transition( $before, $after );
		$this->reschedule_freshness_boundary();
	}

	/** Capture direct receiver-option updates after WordPress commits them. */
	public function capture_source_state_update( $option, $before, $after ) {
		if ( Digitalogic_Product_Sync_Receiver::STATE_OPTION === (string) $option ) {
			$this->persist_source_lifecycle_transition( $before, $after );
		}
		if ( $this->is_freshness_input_option( $option ) ) {
			$this->reschedule_freshness_boundary();
		}
	}

	/** Capture direct receiver-option creation after WordPress commits it. */
	public function capture_source_state_addition( $option, $value ) {
		$this->capture_source_state_update( $option, array( 'sources' => array() ), $value );
	}

	/** Retain the old receiver identity until a direct deletion succeeds. */
	public function capture_source_state_before_delete( $option ) {
		if ( Digitalogic_Product_Sync_Receiver::STATE_OPTION === (string) $option ) {
			$before                           = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
			$this->source_state_before_delete = is_array( $before ) ? $before : array();
		}
	}

	/** Publish terminal source removals only after direct option deletion. */
	public function capture_source_state_deletion( $option ) {
		if ( Digitalogic_Product_Sync_Receiver::STATE_OPTION === (string) $option ) {
			$this->persist_source_lifecycle_transition( $this->source_state_before_delete, array( 'sources' => array() ) );
			$this->source_state_before_delete = array();
		}
		if ( $this->is_freshness_input_option( $option ) ) {
			$this->reschedule_freshness_boundary();
		}
	}

	/** Mark this request for one final, coalesced pricing revision event. */
	public function schedule_state_revision_event() {
		$result = $this->ensure_state_revision_event();
		if ( is_wp_error( $result ) ) {
			do_action( 'digitalogic_pricing_state_event_failed', $result->get_error_code(), array() );
		}
	}

	/** Persist and schedule one readback-verified composite state event. */
	public function ensure_state_revision_event() {
		$this->state_revision_event_pending = true;
		$sources                            = $this->current_state_event_sources();
		$persisted                          = $this->persist_state_revision_outbox( $sources );
		if ( ! $persisted ) {
			$this->schedule_state_revision_event_retry( $sources );
			return new WP_Error(
				'digitalogic_pricing_state_outbox_unavailable',
				'ثبت صف پایدار رویداد وضعیت قیمت ممکن نشد.',
				array(
					'blocking'    => false,
					'retry_after' => 2,
				)
			);
		}
		if ( ! $this->schedule_state_revision_event_retry() ) {
			return new WP_Error(
				'digitalogic_pricing_state_retry_unavailable',
				'اجرای دوبارهٔ رویداد وضعیت قیمت زمان‌بندی نشد.',
				array(
					'blocking'    => false,
					'retry_after' => 2,
				)
			);
		}

		return true;
	}

	/** Persist and schedule the exact source revision transition from repricing. */
	public function ensure_source_lifecycle_event( $before, $after ) {
		if ( ! $this->persist_source_lifecycle_transition( $before, $after ) ) {
			return new WP_Error(
				'digitalogic_pricing_source_outbox_unavailable',
				'ثبت صف پایدار تغییر منبع قیمت ممکن نشد.',
				array(
					'blocking'    => false,
					'retry_after' => 2,
				)
			);
		}

		return true;
	}

	/** Retry a durable state-event outbox from Action Scheduler or WP-Cron. */
	public function run_state_revision_event_delivery( $fallback_sources = array(), $fallback_source_events = array() ) {
		$fallback_sources       = is_array( $fallback_sources ) ? $fallback_sources : array();
		$fallback_source_events = is_array( $fallback_source_events ) ? $fallback_source_events : array();
		if ( empty( get_option( self::SOURCE_EVENT_OUTBOX, array() ) ) && ! empty( $fallback_source_events ) ) {
			if ( ! $this->persist_source_event_outbox( $fallback_source_events ) ) {
				$this->schedule_state_revision_event_retry( $fallback_sources, $fallback_source_events );
				return;
			}
		}
		if ( empty( get_option( self::STATE_EVENT_OUTBOX, array() ) ) ) {
			$fallback_sources = $this->pending_state_event_fallback_sources( $fallback_sources );
			if ( ! empty( $fallback_sources ) && ! $this->persist_state_revision_outbox( $fallback_sources ) ) {
				$this->schedule_state_revision_event_retry( $fallback_sources, $fallback_source_events );
				return;
			}
		}
		if ( empty( get_option( self::STATE_EVENT_OUTBOX, array() ) ) && empty( get_option( self::SOURCE_EVENT_OUTBOX, array() ) ) ) {
			return;
		}
		$this->publish_scheduled_state_revision_events();
	}

	/** Convert one unique degraded handoff into one primary pending delivery. */
	public function run_state_revision_event_handoff( $fallback_sources = array(), $fallback_source_events = array() ) {
		$args      = array(
			is_array( $fallback_sources ) ? array_values( $fallback_sources ) : array(),
			is_array( $fallback_source_events ) ? array_values( $fallback_source_events ) : array(),
		);
		$lock_name = $this->state_event_schedule_lock_name( $args );
		$locked    = $this->acquire_state_event_schedule_lock( $lock_name );
		if ( ! $locked ) {
			$scheduled = $this->state_event_retry_is_pending( $args );
			if ( ! $scheduled ) {
				$scheduled = $this->schedule_state_event_retry_without_lock( $args );
			}
		} else {
			try {
				$scheduled = $this->schedule_state_event_retry_under_lock( $args );
			} finally {
				$this->release_state_event_schedule_lock( $lock_name );
			}
		}
		if ( ! $scheduled ) {
			do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_retry_unavailable', array() );
		}

		return $scheduled;
	}

	/**
	 * Publish the final cheap composite revision for every exact current source.
	 *
	 * The durable panel queue is written before its Redis delivery attempt. The
	 * payload is intentionally noncommercial and secret-free; the WebSocket
	 * server exposes it only to the exact scoped Patris pricing principal.
	 */
	public function publish_scheduled_state_revision_events() {
		if ( $this->state_revision_event_pending ) {
			$this->state_revision_event_pending = false;
			$this->persist_state_revision_outbox();
		}
		if ( ! class_exists( 'Digitalogic_Panel' ) ) {
			$this->schedule_state_revision_event_retry();
			return;
		}

		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			$this->schedule_state_revision_event_retry();
			return;
		}

		$retry = false;
		try {
			$source_outbox  = get_option( self::SOURCE_EVENT_OUTBOX, array() );
			$source_outbox  = is_array( $source_outbox ) ? $source_outbox : array();
			$source_drained = true;
			foreach ( $source_outbox as $event_key => $entry ) {
				$name = (string) ( $entry['name'] ?? '' );
				$data = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
				if ( 'pricing.source.removed' === $name && ! $this->retire_state_event_delivery_for_source( $data['source'] ?? array() ) ) {
					$source_outbox[ $event_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
					$source_outbox[ $event_key ]['updated_at'] = gmdate( 'c' );
					$source_drained                            = false;
					$retry                                     = true;
					do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_retirement_unavailable', (array) ( $data['source'] ?? array() ) );
					break;
				}
				$result = Digitalogic_Panel::record_event_result( $name, $data );
				if ( is_wp_error( $result ) ) {
					$source_outbox[ $event_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
					$source_outbox[ $event_key ]['updated_at'] = gmdate( 'c' );
					$source_drained                            = false;
					$retry                                     = true;
					do_action( 'digitalogic_pricing_state_event_failed', $result->get_error_code(), (array) ( $data['source'] ?? array() ) );
					break;
				}
				unset( $source_outbox[ $event_key ] );
			}
			if ( empty( $source_outbox ) ) {
				delete_option( self::SOURCE_EVENT_OUTBOX );
				if ( null !== get_option( self::SOURCE_EVENT_OUTBOX, null ) ) {
					$source_drained = false;
					$retry          = true;
					do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_source_outbox_unavailable', array() );
				}
			} elseif ( ! $this->store_option_verified( self::SOURCE_EVENT_OUTBOX, $source_outbox ) ) {
				$source_drained = false;
				$retry          = true;
				do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_source_outbox_unavailable', array() );
			}

			// Source lifecycle always precedes the composite state derived from it.
			if ( $source_drained ) {
				$outbox = get_option( self::STATE_EVENT_OUTBOX, array() );
				$outbox = is_array( $outbox ) ? $outbox : array();
				foreach ( $outbox as $source_key => $entry ) {
					$source  = is_array( $entry['source'] ?? null ) ? $entry['source'] : array();
					$current = null;
					for ( $rebase_attempt = 0; $rebase_attempt < 3; ++$rebase_attempt ) {
						$source  = $this->latest_state_event_source( $source );
						$current = $this->current_revision_data( $source );
						if (
							! is_wp_error( $current )
							|| 'digitalogic_pricing_snapshot_source_revision_conflict' !== $current->get_error_code()
						) {
							break;
						}
					}
					$outbox[ $source_key ]['source'] = $source;
					if ( is_wp_error( $current ) ) {
						$outbox[ $source_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
						$outbox[ $source_key ]['updated_at'] = gmdate( 'c' );
						$retry                               = true;
						do_action( 'digitalogic_pricing_state_event_failed', $current->get_error_code(), $source );
						break;
					}

					$idempotency_key  = $this->state_event_idempotency_key( $source, $current['state_revision'] );
					$receipt_exists   = $this->state_event_receipt_matches( $source_key, $current['state_revision'], $idempotency_key );
					$outbox_delivered = ! $receipt_exists && $this->state_event_outbox_delivery_matches( $entry, $current['state_revision'], $idempotency_key );
					$panel_has_event  = ! $receipt_exists && ! $outbox_delivered && $this->panel_has_state_event( $source, $current['state_revision'], $idempotency_key );
					if ( $receipt_exists || $outbox_delivered || $panel_has_event ) {
						if ( ! $receipt_exists && ! $outbox_delivered ) {
							$outbox[ $source_key ] = $this->mark_state_event_outbox_delivered( $entry, $current['state_revision'], $idempotency_key );
							if ( ! $this->store_option_verified( self::STATE_EVENT_OUTBOX, $outbox ) ) {
								$outbox[ $source_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
								$outbox[ $source_key ]['updated_at'] = gmdate( 'c' );
								$retry                               = true;
								do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_outbox_unavailable', $source );
								break;
							}
						}
						if ( ! $receipt_exists && ! $this->persist_state_event_receipt( $source_key, $current['state_revision'], $idempotency_key ) ) {
							$outbox[ $source_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
							$outbox[ $source_key ]['updated_at'] = gmdate( 'c' );
							$retry                               = true;
							do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_receipt_unavailable', $source );
							break;
						}

						$this->emitted_state_revisions[ $source_key ] = $current['state_revision'];
						unset( $outbox[ $source_key ] );
						continue;
					}
					$cause  = 'freshness-boundary' === (string) ( $entry['cause'] ?? '' )
						? 'freshness-boundary'
						: 'projection-invalidated';
					$result = Digitalogic_Panel::record_event_result(
						'pricing.state.changed',
						array(
							'schema'                  => self::STATE_EVENT_SCHEMA,
							'projection'              => self::PROJECTION,
							'source'                  => $current['source'],
							'state_revision'          => $current['state_revision'],
							'etag'                    => $this->etag( $current['state_revision'] ),
							'catalog_revision'        => $current['catalog_revision'],
							'pricing_state_revision'  => $current['pricing_state_revision'],
							'pricing_policy_revision' => $current['pricing_policy_revision'],
							'cause'                   => $cause,
							'idempotency_key'         => $idempotency_key,
							'revision_path'           => '/wp-json/digitalogic/pricing/sync/revision',
							'audience'                => array(
								'services' => array( 'patris_pricing' ),
							),
						)
					);
					if ( is_wp_error( $result ) ) {
						$outbox[ $source_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
						$outbox[ $source_key ]['updated_at'] = gmdate( 'c' );
						$retry                               = true;
						do_action( 'digitalogic_pricing_state_event_failed', $result->get_error_code(), $source );
						break;
					}
					$outbox[ $source_key ] = $this->mark_state_event_outbox_delivered( $entry, $current['state_revision'], $idempotency_key );
					if ( ! $this->store_option_verified( self::STATE_EVENT_OUTBOX, $outbox ) ) {
						$outbox[ $source_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
						$outbox[ $source_key ]['updated_at'] = gmdate( 'c' );
						$retry                               = true;
						do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_outbox_unavailable', $source );
						break;
					}
					if ( ! $this->persist_state_event_receipt( $source_key, $current['state_revision'], $idempotency_key ) ) {
						$outbox[ $source_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
						$outbox[ $source_key ]['updated_at'] = gmdate( 'c' );
						$retry                               = true;
						do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_receipt_unavailable', $source );
						break;
					}

					$this->emitted_state_revisions[ $source_key ] = $current['state_revision'];
					unset( $outbox[ $source_key ] );
				}

				if ( empty( $outbox ) ) {
					delete_option( self::STATE_EVENT_OUTBOX );
					if ( function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( self::STATE_EVENT_OUTBOX, 'options' );
					}
					if (
						null !== get_option( self::STATE_EVENT_OUTBOX, null )
						&& ! $this->store_option_verified( self::STATE_EVENT_OUTBOX, array() )
					) {
						$retry = true;
						do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_outbox_unavailable', array() );
					}
				} elseif ( ! $this->store_option_verified( self::STATE_EVENT_OUTBOX, $outbox ) ) {
					$retry = true;
					do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_outbox_unavailable', array() );
				}
			}
		} finally {
			$this->release_admission_lock();
		}

		if ( $retry ) {
			$this->schedule_state_revision_event_retry();
		}
	}

	/** Retry the durable snapshot-terminal outbox from Action Scheduler or WP-Cron. */
	public function run_terminal_event_delivery() {
		$this->publish_scheduled_terminal_events();
	}

	/**
	 * Publish committed build terminals at least once to the scoped pricing stream.
	 *
	 * Entries are staged before the terminal job write and promoted to a durable
	 * committed phase only after that job can be re-read. A committed entry remains
	 * deliverable after the short-lived job expires. Stable idempotency keys let the
	 * Patris consumer deduplicate the rare queue-rotation crash window.
	 */
	public function publish_scheduled_terminal_events() {
		$outbox = get_option( self::TERMINAL_EVENT_OUTBOX, array() );
		$outbox = is_array( $outbox ) ? $outbox : array();
		if ( empty( $outbox ) ) {
			return;
		}
		if ( ! class_exists( 'Digitalogic_Panel' ) ) {
			$this->schedule_terminal_event_retry();
			return;
		}

		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			$this->schedule_terminal_event_retry();
			return;
		}

		$retry       = false;
		$retry_delay = self::RETRY_AFTER;
		try {
			$outbox      = get_option( self::TERMINAL_EVENT_OUTBOX, array() );
			$outbox      = is_array( $outbox ) ? $outbox : array();
			$changed     = false;
			$can_publish = true;
			foreach ( $outbox as $event_key => $entry ) {
				$build_id   = (string) ( $entry['build_id'] ?? '' );
				$request_id = (string) ( $entry['request_id'] ?? '' );
				if ( empty( $entry['committed'] ) ) {
					$job = $this->read_job( $build_id );
					if (
						is_array( $job )
						&& in_array( (string) ( $job['status'] ?? '' ), array( 'ready', 'failed', 'cancelled' ), true )
						&& in_array( $request_id, $this->terminal_request_ids( $job ), true )
					) {
						$data = $this->terminal_event_data( $job, $request_id );
						if ( ! is_array( $data ) || ! hash_equals( (string) $event_key, (string) $data['idempotency_key'] ) ) {
							$retry = true;
							do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_payload_invalid', $build_id );
							break;
						}
						$outbox[ $event_key ]['data']         = $data;
						$outbox[ $event_key ]['committed']    = true;
						$outbox[ $event_key ]['committed_at'] = time();
						$outbox[ $event_key ]['updated_at']   = gmdate( 'c' );
						$changed                              = true;
						$this->unschedule_build_watchdog( $job );
						$this->unschedule_build_activation( $job );
						continue;
					}

					$created_at = (int) ( $entry['created_at'] ?? 0 );
					if ( $created_at <= 0 ) {
						$created_at                         = time();
						$outbox[ $event_key ]['created_at'] = $created_at;
						$changed                            = true;
					}
					if ( $created_at > 0 && $created_at + self::BUILD_TTL <= time() ) {
						unset( $outbox[ $event_key ] );
						$changed = true;
						do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_stage_abandoned', $build_id );
						continue;
					}
					$retry       = true;
					$retry_delay = min( 60, max( self::RETRY_AFTER, $created_at > 0 ? $created_at + self::BUILD_TTL - time() : 60 ) );
				}
			}

			if ( $changed && ! $this->store_terminal_event_outbox_state( $outbox ) ) {
				$retry       = true;
				$can_publish = false;
				do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_outbox_unavailable', '' );
			}

			foreach ( $can_publish ? $outbox : array() as $event_key => $entry ) {
				if ( empty( $entry['committed'] ) ) {
					continue;
				}
				$build_id = (string) ( $entry['build_id'] ?? '' );
				$data     = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
				if (
					! hash_equals( (string) $event_key, (string) ( $data['idempotency_key'] ?? '' ) )
					|| ! $this->terminal_event_payload_is_visible( $data )
				) {
					$retry = true;
					do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_payload_invalid', $build_id );
					break;
				}
				$receipt = $this->terminal_panel_event_receipt( $data );
				if ( is_wp_error( $receipt ) ) {
					if ( 'digitalogic_pricing_terminal_event_conflict' === $receipt->get_error_code() ) {
						unset( $outbox[ $event_key ] );
						do_action( 'digitalogic_pricing_terminal_event_failed', $receipt->get_error_code(), $build_id );
						continue;
					}
					$retry = true;
					do_action( 'digitalogic_pricing_terminal_event_failed', $receipt->get_error_code(), $build_id );
					break;
				}
				if ( ! $receipt ) {
					$result = Digitalogic_Panel::record_event_result( 'pricing.snapshot.build.terminal', $data );
					if ( is_wp_error( $result ) ) {
						$outbox[ $event_key ]['attempts']   = min( 1000, 1 + (int) ( $entry['attempts'] ?? 0 ) );
						$outbox[ $event_key ]['updated_at'] = gmdate( 'c' );
						$retry                              = true;
						do_action( 'digitalogic_pricing_terminal_event_failed', $result->get_error_code(), $build_id );
						break;
					}
				}
				unset( $outbox[ $event_key ] );
			}

			if ( $can_publish && ! $this->store_terminal_event_outbox_state( $outbox ) ) {
				$retry = true;
				do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_outbox_unavailable', '' );
			}
		} finally {
			$this->release_admission_lock();
		}

		if ( $retry && ! $this->schedule_terminal_event_retry( $retry_delay ) ) {
			do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_retry_unavailable', '' );
		}
	}

	/** Stage exact terminal envelopes before the corresponding job becomes terminal. */
	private function persist_terminal_event_outbox( $job ) {
		$status = (string) ( $job['status'] ?? '' );
		if ( ! in_array( $status, array( 'ready', 'failed', 'cancelled' ), true ) ) {
			return false;
		}
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$outbox = get_option( self::TERMINAL_EVENT_OUTBOX, array() );
			$outbox = is_array( $outbox ) ? $outbox : array();
			foreach ( $this->terminal_request_ids( $job ) as $request_id ) {
				$data = $this->terminal_event_data( $job, $request_id );
				if ( ! is_array( $data ) ) {
					return false;
				}
				$event_key = (string) $data['idempotency_key'];
				$previous  = is_array( $outbox[ $event_key ] ?? null ) ? $outbox[ $event_key ] : array();
				if ( ! empty( $previous['committed'] ) && ( $previous['data'] ?? null ) !== $data ) {
					do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_event_conflict', (string) $job['build_id'] );
					return false;
				}
				$same_entry           = (string) ( $previous['build_id'] ?? '' ) === (string) $job['build_id']
					&& (string) ( $previous['request_id'] ?? '' ) === $request_id;
				$outbox[ $event_key ] = array(
					'name'       => 'pricing.snapshot.build.terminal',
					'data'       => $data,
					'build_id'   => (string) $job['build_id'],
					'request_id' => $request_id,
					'attempts'   => (int) ( $previous['attempts'] ?? 0 ),
					'created_at' => $same_entry ? (int) ( $previous['created_at'] ?? time() ) : time(),
					'committed'  => $same_entry && ! empty( $previous['committed'] ),
					'updated_at' => gmdate( 'c' ),
				);
				if ( $same_entry && ! empty( $previous['committed_at'] ) ) {
					$outbox[ $event_key ]['committed_at'] = (int) $previous['committed_at'];
				}
			}
			if (
				empty( $outbox )
				|| ! $this->schedule_terminal_event_retry()
				|| ! $this->store_option_verified( self::TERMINAL_EVENT_OUTBOX, $outbox )
			) {
				do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_outbox_unavailable', (string) ( $job['build_id'] ?? '' ) );
				return false;
			}
			return true;
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Promote staged terminal envelopes only after their exact terminal job commits. */
	private function commit_terminal_event_outbox( $job ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$outbox = get_option( self::TERMINAL_EVENT_OUTBOX, array() );
			$outbox = is_array( $outbox ) ? $outbox : array();
			foreach ( $this->terminal_request_ids( $job ) as $request_id ) {
				$data      = $this->terminal_event_data( $job, $request_id );
				$event_key = is_array( $data ) ? (string) ( $data['idempotency_key'] ?? '' ) : '';
				$entry     = is_array( $outbox[ $event_key ] ?? null ) ? $outbox[ $event_key ] : array();
				if (
					'' === $event_key
					|| (string) ( $entry['build_id'] ?? '' ) !== (string) ( $job['build_id'] ?? '' )
					|| (string) ( $entry['request_id'] ?? '' ) !== $request_id
					|| ( $entry['data'] ?? null ) !== $data
				) {
					return false;
				}
				$outbox[ $event_key ]['committed']    = true;
				$outbox[ $event_key ]['committed_at'] = time();
				$outbox[ $event_key ]['updated_at']   = gmdate( 'c' );
			}

			return $this->store_option_verified( self::TERMINAL_EVENT_OUTBOX, $outbox );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Remove only uncommitted staged envelopes after a known terminal-store abort. */
	private function discard_staged_terminal_event_outbox( $job ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$outbox = get_option( self::TERMINAL_EVENT_OUTBOX, array() );
			$outbox = is_array( $outbox ) ? $outbox : array();
			foreach ( $outbox as $event_key => $entry ) {
				if (
					empty( $entry['committed'] )
					&& (string) ( $entry['build_id'] ?? '' ) === (string) ( $job['build_id'] ?? '' )
				) {
					unset( $outbox[ $event_key ] );
				}
			}

			return $this->store_terminal_event_outbox_state( $outbox );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Persist or delete the complete terminal outbox with verified readback. */
	private function store_terminal_event_outbox_state( $outbox ) {
		if ( empty( $outbox ) ) {
			delete_option( self::TERMINAL_EVENT_OUTBOX );
			return null === get_option( self::TERMINAL_EVENT_OUTBOX, null );
		}

		return $this->store_option_verified( self::TERMINAL_EVENT_OUTBOX, $outbox );
	}

	/** Validate one committed payload through the same authenticated stream gate. */
	private function terminal_event_payload_is_visible( $data ) {
		$source = is_array( $data['source'] ?? null ) ? $data['source'] : array();
		return class_exists( 'Digitalogic_Event_Mesh' )
			&& Digitalogic_Event_Mesh::event_visible_to(
				array(
					'name' => 'pricing.snapshot.build.terminal',
					'data' => $data,
				),
				0,
				'',
				'patris_pricing',
				array(
					'id'      => (string) ( $source['id'] ?? '' ),
					'dataset' => (string) ( $source['dataset'] ?? '' ),
				)
			);
	}

	/** Build the secret-free terminal payload consumed by Patris pricing v1. */
	private function terminal_event_data( $job, $request_id ) {
		$build_id = (string) ( $job['build_id'] ?? '' );
		$status   = (string) ( $job['status'] ?? '' );
		$source   = is_array( $job['source'] ?? null ) ? $job['source'] : array();
		if (
			1 !== preg_match( '/\Abuild_[a-f0-9]{32}\z/D', $build_id )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', (string) $request_id )
			|| ! in_array( $status, array( 'ready', 'failed', 'cancelled' ), true )
			|| '' === (string) ( $source['id'] ?? '' )
			|| '' === (string) ( $source['dataset'] ?? '' )
			|| ! $this->is_revision( $source['revision'] ?? null )
			|| ! $this->is_revision( $job['state_revision'] ?? null )
			|| ! $this->is_revision( $job['pricing_state_revision'] ?? null )
			|| ! $this->is_revision( $job['catalog_revision'] ?? null )
		) {
			return null;
		}

		$data = array(
			'schema'                 => self::TERMINAL_EVENT_SCHEMA,
			'projection'             => self::PROJECTION,
			'build_id'               => $build_id,
			'request_id'             => (string) $request_id,
			'status'                 => $status,
			'source'                 => $source,
			'state_revision'         => (string) $job['state_revision'],
			'pricing_state_revision' => (string) $job['pricing_state_revision'],
			'catalog_revision'       => (string) $job['catalog_revision'],
			'retryable'              => (bool) ( $job['retryable'] ?? false ),
			'idempotency_key'        => $this->digest(
				array(
					'schema'     => self::TERMINAL_EVENT_SCHEMA,
					'build_id'   => $build_id,
					'request_id' => (string) $request_id,
				)
			),
			'audience'               => array(
				'services' => array( 'patris_pricing' ),
			),
		);
		if ( 'ready' === $status ) {
			if (
				1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', (string) ( $job['snapshot_token'] ?? '' ) )
				|| ! $this->is_revision( $job['snapshot_revision'] ?? null )
				|| ! $this->is_revision( $job['digest'] ?? null )
				|| ! hash_equals( (string) $job['snapshot_revision'], (string) $job['digest'] )
			) {
				return null;
			}
			$data['snapshot_token']    = (string) $job['snapshot_token'];
			$data['snapshot_revision'] = (string) $job['snapshot_revision'];
			$data['digest']            = (string) $job['digest'];
			$data['snapshot_path']     = '/wp-json/digitalogic/pricing/sync/snapshots/' . rawurlencode( $job['snapshot_token'] ) . $this->source_query( $source );
		} else {
			$code = (string) ( $job['code'] ?? '' );
			if ( '' === $code || strlen( $code ) > 128 || 1 !== preg_match( '/\A[a-z0-9_:-]+\z/D', $code ) ) {
				return null;
			}
			$data['code'] = $code;
		}

		return $data;
	}

	/** Return every unique request ID attached to one single-flight build. */
	private function terminal_request_ids( $job ) {
		$request_ids   = isset( $job['terminal_request_ids'] ) && is_array( $job['terminal_request_ids'] )
			? $job['terminal_request_ids']
			: array();
		$request_ids[] = (string) ( $job['request_id'] ?? '' );
		$request_ids   = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $request_ids ),
					static function ( $request_id ) {
						return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', $request_id );
					}
				)
			)
		);

		return array_slice( $request_ids, 0, 128 );
	}

	/** Find an exact durable panel receipt, rejecting an idempotency collision. */
	private function terminal_panel_event_receipt( $data ) {
		$events = get_option( 'digitalogic_panel_events', array() );
		foreach ( is_array( $events ) ? $events : array() as $event ) {
			$stored = is_array( $event['data'] ?? null ) ? $event['data'] : array();
			if (
				'pricing.snapshot.build.terminal' !== (string) ( $event['name'] ?? '' )
				|| ! hash_equals( (string) ( $data['idempotency_key'] ?? '' ), (string) ( $stored['idempotency_key'] ?? '' ) )
			) {
				continue;
			}
			if ( $stored === $data ) {
				return true;
			}
			return $this->error(
				'digitalogic_pricing_terminal_event_conflict',
				'A conflicting snapshot terminal event already owns this idempotency key.',
				503,
				false
			);
		}

		return false;
	}

	/** Return one retryable error when terminal delivery cannot be made durable. */
	private function terminal_event_storage_error() {
		return $this->error(
			'digitalogic_pricing_terminal_event_storage_unavailable',
			'The snapshot terminal event could not be made durable.',
			503,
			true,
			array(),
			self::RETRY_AFTER
		);
	}

	/** Drop fallback sources whose exact current state was already recorded. */
	private function pending_state_event_fallback_sources( $fallback_sources ) {
		$fallback_sources = is_array( $fallback_sources ) ? array_values( $fallback_sources ) : array();
		$lock             = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return $fallback_sources;
		}

		try {
			$current_sources = array();
			foreach ( $this->current_state_event_sources() as $current_source ) {
				$key                     = (string) $current_source['id'] . "\n" . (string) $current_source['dataset'];
				$current_sources[ $key ] = $current_source;
			}

			$pending = array();
			foreach ( $fallback_sources as $candidate ) {
				$source     = is_array( $candidate ) ? $candidate : array();
				$source_key = (string) ( $source['id'] ?? '' ) . "\n" . (string) ( $source['dataset'] ?? '' );
				if ( ! isset( $current_sources[ $source_key ] ) ) {
					continue;
				}

				$source  = $current_sources[ $source_key ];
				$current = $this->current_revision_data( $source );
				if ( is_wp_error( $current ) ) {
					$pending[ $source_key ] = $source;
					continue;
				}

				$idempotency_key = $this->state_event_idempotency_key( $source, $current['state_revision'] );
				if ( $this->state_event_receipt_matches( $source_key, $current['state_revision'], $idempotency_key ) ) {
					continue;
				}
				if ( $this->panel_has_state_event( $source, $current['state_revision'], $idempotency_key ) ) {
					if ( ! $this->persist_state_event_receipt( $source_key, $current['state_revision'], $idempotency_key ) ) {
						do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_receipt_unavailable', $source );
						$pending[ $source_key ] = $source;
					}
					continue;
				}

				$pending[ $source_key ] = $source;
			}

			return array_values( $pending );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Return the stable event identity for one exact source and composite revision. */
	private function state_event_idempotency_key( $source, $state_revision ) {
		return $this->digest(
			array(
				'schema'         => self::STATE_EVENT_SCHEMA,
				'source_id'      => (string) ( $source['id'] ?? '' ),
				'source_dataset' => (string) ( $source['dataset'] ?? '' ),
				'state_revision' => (string) $state_revision,
			)
		);
	}

	/** Return whether the durable outbox records this exact panel delivery. */
	private function state_event_outbox_delivery_matches( $entry, $state_revision, $idempotency_key ) {
		$entry = is_array( $entry ) ? $entry : array();

		return '' !== (string) ( $entry['delivered_state_revision'] ?? '' )
			&& hash_equals( (string) $entry['delivered_state_revision'], (string) $state_revision )
			&& hash_equals( (string) ( $entry['delivered_idempotency_key'] ?? '' ), (string) $idempotency_key );
	}

	/** Carry the exact delivered identity in the outbox until its receipt is durable. */
	private function mark_state_event_outbox_delivered( $entry, $state_revision, $idempotency_key ) {
		$entry                              = is_array( $entry ) ? $entry : array();
		$entry['delivered_state_revision']  = (string) $state_revision;
		$entry['delivered_idempotency_key'] = (string) $idempotency_key;
		$entry['delivered_at']              = gmdate( 'c' );

		return $entry;
	}

	/** Check the bounded durable receipt for one exact state-event identity. */
	private function state_event_receipt_matches( $source_key, $state_revision, $idempotency_key ) {
		$receipts = $this->normalize_state_event_receipts( get_option( self::STATE_EVENT_RECEIPTS, array() ) );
		$receipt  = is_array( $receipts[ $source_key ] ?? null ) ? $receipts[ $source_key ] : array();

		return '' !== (string) ( $receipt['state_revision'] ?? '' )
			&& hash_equals( (string) $receipt['state_revision'], (string) $state_revision )
			&& hash_equals( (string) ( $receipt['idempotency_key'] ?? '' ), (string) $idempotency_key );
	}

	/** Store the newest delivered event identity for each exact source. */
	private function persist_state_event_receipt( $source_key, $state_revision, $idempotency_key ) {
		$receipts                = $this->normalize_state_event_receipts( get_option( self::STATE_EVENT_RECEIPTS, array() ) );
		$receipts[ $source_key ] = array(
			'state_revision'  => (string) $state_revision,
			'idempotency_key' => (string) $idempotency_key,
			'recorded_at'     => gmdate( 'c' ),
		);
		$receipts                = $this->normalize_state_event_receipts( $receipts );

		return $this->store_option_verified( self::STATE_EVENT_RECEIPTS, $receipts );
	}

	/** Retire every durable state-delivery identity before source removal is published. */
	private function retire_state_event_delivery_for_source( $source ) {
		$source     = is_array( $source ) ? $source : array();
		$source_key = (string) ( $source['id'] ?? '' ) . "\n" . (string) ( $source['dataset'] ?? '' );
		if ( '' === (string) ( $source['id'] ?? '' ) || '' === (string) ( $source['dataset'] ?? '' ) ) {
			return false;
		}

		$stored_receipts = get_option( self::STATE_EVENT_RECEIPTS, null );
		if ( null !== $stored_receipts ) {
			$receipts = $this->normalize_state_event_receipts( $stored_receipts );
			unset( $receipts[ $source_key ] );
			if ( $stored_receipts !== $receipts && ! $this->store_option_verified( self::STATE_EVENT_RECEIPTS, $receipts ) ) {
				return false;
			}
		}

		$current_source = null;
		foreach ( $this->current_state_event_sources() as $candidate ) {
			$candidate_key = (string) $candidate['id'] . "\n" . (string) $candidate['dataset'];
			if ( hash_equals( $source_key, $candidate_key ) ) {
				$current_source = $candidate;
				break;
			}
		}

		$stored_outbox = get_option( self::STATE_EVENT_OUTBOX, null );
		if ( null !== $stored_outbox || null !== $current_source ) {
			$outbox = is_array( $stored_outbox ) ? $stored_outbox : array();
			if ( null !== $current_source ) {
				$existing              = is_array( $outbox[ $source_key ] ?? null ) ? $outbox[ $source_key ] : array();
				$now                   = gmdate( 'c' );
				$outbox[ $source_key ] = array(
					'source'        => $current_source,
					'first_seen_at' => (string) ( $existing['first_seen_at'] ?? $now ),
					'updated_at'    => $now,
					'attempts'      => (int) ( $existing['attempts'] ?? 0 ),
					'cause'         => 'freshness-boundary' === (string) ( $existing['cause'] ?? '' )
						? 'freshness-boundary'
						: 'projection-invalidated',
				);
			} else {
				unset( $outbox[ $source_key ] );
			}
			if ( $stored_outbox !== $outbox && ! $this->store_option_verified( self::STATE_EVENT_OUTBOX, $outbox ) ) {
				return false;
			}
		}

		return true;
	}

	/** Remove expired or malformed receipts and retain the newest bounded set. */
	private function normalize_state_event_receipts( $receipts ) {
		$receipts   = is_array( $receipts ) ? $receipts : array();
		$cutoff     = time() - self::STATE_EVENT_RECEIPT_TTL;
		$normalized = array();
		foreach ( $receipts as $source_key => $receipt ) {
			$receipt       = is_array( $receipt ) ? $receipt : array();
			$recorded_at   = (string) ( $receipt['recorded_at'] ?? '' );
			$recorded_time = '' === $recorded_at ? false : strtotime( $recorded_at );
			if (
				false === $recorded_time
				|| $recorded_time < $cutoff
				|| ! $this->is_revision( $receipt['state_revision'] ?? null )
				|| ! $this->is_revision( $receipt['idempotency_key'] ?? null )
			) {
				continue;
			}
			$normalized[ (string) $source_key ] = array(
				'state_revision'  => (string) $receipt['state_revision'],
				'idempotency_key' => (string) $receipt['idempotency_key'],
				'recorded_at'     => gmdate( 'c', $recorded_time ),
			);
		}
		uasort(
			$normalized,
			static function ( $left, $right ) {
				return strtotime( (string) $left['recorded_at'] ) <=> strtotime( (string) $right['recorded_at'] );
			}
		);
		if ( count( $normalized ) > self::STATE_EVENT_RECEIPT_LIMIT ) {
			$normalized = array_slice( $normalized, -self::STATE_EVENT_RECEIPT_LIMIT, null, true );
		}

		return $normalized;
	}

	/** Find an already durable panel event before appending a duplicate. */
	private function panel_has_state_event( $source, $state_revision, $idempotency_key ) {
		if ( ! class_exists( 'Digitalogic_Panel' ) || ! method_exists( 'Digitalogic_Panel', 'get_events_since' ) ) {
			return false;
		}

		foreach ( Digitalogic_Panel::get_events_since( 0 ) as $event ) {
			$data         = is_array( $event['data'] ?? null ) ? $event['data'] : array();
			$event_source = is_array( $data['source'] ?? null ) ? $data['source'] : array();
			if (
				'pricing.state.changed' === (string) ( $event['name'] ?? '' )
				&& self::STATE_EVENT_SCHEMA === (string) ( $data['schema'] ?? '' )
				&& hash_equals( (string) $idempotency_key, (string) ( $data['idempotency_key'] ?? '' ) )
				&& hash_equals( (string) $state_revision, (string) ( $data['state_revision'] ?? '' ) )
				&& hash_equals( (string) ( $source['id'] ?? '' ), (string) ( $event_source['id'] ?? '' ) )
				&& hash_equals( (string) ( $source['dataset'] ?? '' ), (string) ( $event_source['dataset'] ?? '' ) )
			) {
				return true;
			}
		}

		return false;
	}

	/** Merge every exact current source into the persistent state-event outbox. */
	private function persist_state_revision_outbox( $candidate_sources = null, $cause = 'projection-invalidated' ) {
		$candidate_sources = is_array( $candidate_sources ) ? $candidate_sources : $this->current_state_event_sources();
		$cause             = 'freshness-boundary' === (string) $cause ? 'freshness-boundary' : 'projection-invalidated';
		$current_sources   = array();
		foreach ( $this->current_state_event_sources() as $current_source ) {
			$current_sources[ (string) $current_source['id'] . "\n" . (string) $current_source['dataset'] ] = $current_source;
		}
		$sources = array();
		foreach ( $candidate_sources as $candidate ) {
			$source = is_array( $candidate ) ? $candidate : array();
			$key    = (string) ( $source['id'] ?? '' ) . "\n" . (string) ( $source['dataset'] ?? '' );
			if ( isset( $current_sources[ $key ] ) ) {
				$source = $current_sources[ $key ];
			}
			if (
				'' === (string) ( $source['id'] ?? '' )
				|| '' === (string) ( $source['dataset'] ?? '' )
				|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $source['revision'] ?? '' ) )
			) {
				continue;
			}
			$sources[ (string) $source['id'] . "\n" . (string) $source['dataset'] ] = $source;
		}
		if ( empty( $sources ) ) {
			return false;
		}

		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$outbox = get_option( self::STATE_EVENT_OUTBOX, array() );
			$outbox = is_array( $outbox ) ? $outbox : array();
			$now    = gmdate( 'c' );
			foreach ( $sources as $source_key => $source ) {
				$existing              = is_array( $outbox[ $source_key ] ?? null ) ? $outbox[ $source_key ] : array();
				$first_seen            = (string) ( $existing['first_seen_at'] ?? $now );
				$outbox[ $source_key ] = array(
					'source'        => $source,
					'first_seen_at' => $first_seen,
					'updated_at'    => $now,
					'attempts'      => (int) ( $existing['attempts'] ?? 0 ),
					'cause'         => $cause,
				);
				if ( $this->is_revision( $existing['delivered_state_revision'] ?? null ) && $this->is_revision( $existing['delivered_idempotency_key'] ?? null ) ) {
					$outbox[ $source_key ]['delivered_state_revision']  = (string) $existing['delivered_state_revision'];
					$outbox[ $source_key ]['delivered_idempotency_key'] = (string) $existing['delivered_idempotency_key'];
					$outbox[ $source_key ]['delivered_at']              = (string) ( $existing['delivered_at'] ?? $now );
				}
			}

			return $this->store_option_verified( self::STATE_EVENT_OUTBOX, $outbox );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Persist exact added, changed, and removed source identities for delivery. */
	private function persist_source_lifecycle_transition( $before, $after ) {
		$before = $this->source_identity_map( $before );
		$after  = $this->source_identity_map( $after );
		$keys   = array_values( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) );
		sort( $keys, SORT_STRING );
		$events = array();
		foreach ( $keys as $key ) {
			$previous = $before[ $key ] ?? null;
			$current  = $after[ $key ] ?? null;
			if ( is_array( $previous ) && is_array( $current ) && hash_equals( $previous['revision'], $current['revision'] ) ) {
				continue;
			}
			$change = ! is_array( $previous ) ? 'added' : ( ! is_array( $current ) ? 'removed' : 'changed' );
			$source = is_array( $current ) ? $current : $previous;
			if ( ! is_array( $source ) ) {
				continue;
			}
			$name            = 'removed' === $change ? 'pricing.source.removed' : 'pricing.source.changed';
			$idempotency_key = $this->digest(
				array(
					'schema'            => self::SOURCE_EVENT_SCHEMA,
					'change'            => $change,
					'source'            => $source,
					'previous_revision' => is_array( $previous ) ? $previous['revision'] : null,
				)
			);
			$events[]        = array(
				'name' => $name,
				'data' => array(
					'schema'                       => self::SOURCE_EVENT_SCHEMA,
					'projection'                   => self::PROJECTION,
					'change'                       => $change,
					'source'                       => $source,
					'previous_source_revision'     => is_array( $previous ) ? $previous['revision'] : null,
					'idempotency_key'              => $idempotency_key,
					'revision_validation_required' => true,
					'revision_path'                => '/wp-json/digitalogic/pricing/sync/revision',
					'audience'                     => array(
						'services' => array( 'patris_pricing' ),
					),
				),
			);
		}
		if ( empty( $events ) ) {
			return true;
		}

		$persisted = $this->persist_source_event_outbox( $events );
		if ( ! $persisted ) {
			do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_source_outbox_unavailable', array() );
		}
		$this->schedule_state_revision_event_retry( array(), $persisted ? array() : $events );

		return $persisted;
	}

	/** Return valid source identities keyed by exact ID and dataset. */
	private function source_identity_map( $state ) {
		$state = is_array( $state ) ? $state : array();
		$map   = array();
		foreach ( (array) ( $state['sources'] ?? array() ) as $entry ) {
			$source = is_array( $entry ) && is_array( $entry['source'] ?? null ) ? $entry['source'] : array();
			if (
				'' === (string) ( $source['id'] ?? '' )
				|| '' === (string) ( $source['dataset'] ?? '' )
				|| ! $this->is_revision( $source['revision'] ?? null )
			) {
				continue;
			}
			$identity = array(
				'id'       => (string) $source['id'],
				'dataset'  => (string) $source['dataset'],
				'revision' => (string) $source['revision'],
			);
			$map[ $identity['id'] . "\n" . $identity['dataset'] ] = $identity;
		}
		ksort( $map, SORT_STRING );

		return $map;
	}

	/** Merge lifecycle envelopes into a persistent at-least-once outbox. */
	private function persist_source_event_outbox( $events ) {
		$events = is_array( $events ) ? $events : array();
		if ( empty( $events ) ) {
			return false;
		}
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$outbox = get_option( self::SOURCE_EVENT_OUTBOX, array() );
			$outbox = is_array( $outbox ) ? $outbox : array();
			$now    = gmdate( 'c' );
			foreach ( $events as $event ) {
				$data = is_array( $event['data'] ?? null ) ? $event['data'] : array();
				$key  = (string) ( $data['idempotency_key'] ?? '' );
				if ( ! $this->is_revision( $key ) || ! in_array( (string) ( $event['name'] ?? '' ), array( 'pricing.source.changed', 'pricing.source.removed' ), true ) ) {
					continue;
				}
				$outbox[ $key ] = array(
					'name'          => (string) $event['name'],
					'data'          => $data,
					'first_seen_at' => (string) ( $outbox[ $key ]['first_seen_at'] ?? $now ),
					'updated_at'    => $now,
					'attempts'      => (int) ( $outbox[ $key ]['attempts'] ?? 0 ),
				);
			}
			if ( count( $outbox ) > 200 ) {
				$outbox = array_slice( $outbox, -200, null, true );
			}

			return ! empty( $outbox ) && $this->store_option_verified( self::SOURCE_EVENT_OUTBOX, $outbox );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Return the single earliest future currency or source freshness transition. */
	private function next_freshness_boundary() {
		$now        = time();
		$candidates = array();
		$pricing    = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( ! is_wp_error( $pricing ) ) {
			foreach ( array( 'usd', 'cny' ) as $currency ) {
				$date   = (string) ( $pricing['freshness'][ $currency ]['effective_date'] ?? '' );
				$parsed = Digitalogic_Currency_Date_Formatter::instance()->parse( $date );
				if ( ! $parsed instanceof DateTimeImmutable ) {
					continue;
				}
				$effective = $parsed->setTime( 0, 0 )->getTimestamp();
				$expires   = $parsed->setTime( 0, 0 )->modify( '+' . ( Digitalogic_Excel_Pricing_Sync::STALE_AFTER_DAYS + 1 ) . ' days' )->getTimestamp();
				if ( $effective > $now ) {
					$candidates[ $effective ][] = 'currency-' . $currency . '-effective';
				}
				if ( $expires > $now ) {
					$candidates[ $expires ][] = 'currency-' . $currency . '-stale';
				}
			}
		}

		$settings      = Digitalogic_Patris_Feed::instance()->get_settings();
		$stale_seconds = max( 1, absint( $settings['stale_after_hours'] ?? 48 ) ) * HOUR_IN_SECONDS;
		foreach ( $this->current_state_event_sources() as $source ) {
			$state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( $source['id'], $source['dataset'] );
			foreach ( (array) ( $state['products'] ?? array() ) as $product ) {
				$updated_at = is_array( $product ) ? (string) ( $product['source_updated_at'] ?? '' ) : '';
				$updated    = '' === $updated_at ? false : strtotime( $updated_at );
				$boundary   = false === $updated ? 0 : $updated + $stale_seconds + 1;
				if ( $boundary > $now ) {
					$candidates[ $boundary ][] = 'source-stale';
				}
			}
		}
		if ( empty( $candidates ) ) {
			return null;
		}
		ksort( $candidates, SORT_NUMERIC );
		$timestamp = (int) array_key_first( $candidates );
		$reasons   = array_values( array_unique( $candidates[ $timestamp ] ) );
		sort( $reasons, SORT_STRING );

		return array(
			'timestamp' => $timestamp,
			'reasons'   => $reasons,
		);
	}

	/** Return whether the exact one-shot action still exists in its scheduler. */
	private function freshness_boundary_is_scheduled( $timestamp, $args ) {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( self::FRESHNESS_HOOK, $args, self::ACTION_GROUP );
			return is_numeric( $next ) && (int) $next === (int) $timestamp;
		}
		if ( function_exists( 'wp_next_scheduled' ) ) {
			return (int) wp_next_scheduled( self::FRESHNESS_HOOK, $args ) === (int) $timestamp;
		}

		return false;
	}

	/** Remove only this plugin's one-shot freshness action. */
	private function unschedule_freshness_boundary( $record = null ) {
		$record = is_array( $record ) ? $record : get_option( self::FRESHNESS_SCHEDULE, array() );
		$args   = isset( $record['timestamp'], $record['fingerprint'] )
			? array( (int) $record['timestamp'], (string) $record['fingerprint'] )
			: array();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::FRESHNESS_HOOK, $args, self::ACTION_GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::FRESHNESS_HOOK, $args );
		}
	}

	/** Return whether an option can move a future freshness transition. */
	private function is_freshness_input_option( $option ) {
		return in_array(
			(string) $option,
			array(
				Digitalogic_Product_Sync_Receiver::STATE_OPTION,
				'digitalogic_patris_feed_settings',
				Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION,
				'options_update_date',
				'update_date',
			),
			true
		);
	}

	/** Return only exact current source identities safe to persist in scheduler args. */
	private function current_state_event_sources() {
		$status  = Digitalogic_Product_Sync_Receiver::instance()->get_status();
		$sources = array();
		foreach ( (array) ( $status['sources'] ?? array() ) as $summary ) {
			$source = is_array( $summary['source'] ?? null ) ? $summary['source'] : array();
			if (
				'' !== (string) ( $source['id'] ?? '' )
				&& '' !== (string) ( $source['dataset'] ?? '' )
				&& 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $source['revision'] ?? '' ) )
			) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	/** Rebase one durable event envelope onto the newest exact source identity. */
	private function latest_state_event_source( $source ) {
		$source  = is_array( $source ) ? $source : array();
		$id      = (string) ( $source['id'] ?? '' );
		$dataset = (string) ( $source['dataset'] ?? '' );
		foreach ( $this->current_state_event_sources() as $candidate ) {
			if (
				hash_equals( $id, (string) ( $candidate['id'] ?? '' ) )
				&& hash_equals( $dataset, (string) ( $candidate['dataset'] ?? '' ) )
			) {
				return $candidate;
			}
		}

		return $source;
	}

	/** Schedule one bounded asynchronous retry for the persistent event outbox. */
	private function schedule_state_revision_event_retry( $fallback_sources = array(), $fallback_source_events = array() ) {
		$fallback_sources       = is_array( $fallback_sources ) ? array_values( $fallback_sources ) : array();
		$fallback_source_events = is_array( $fallback_source_events ) ? array_values( $fallback_source_events ) : array();
		$args                   = array( $fallback_sources, $fallback_source_events );
		$lock_name              = $this->state_event_schedule_lock_name( $args );
		$locked                 = $this->acquire_state_event_schedule_lock( $lock_name );
		if ( ! $locked ) {
			$scheduled = $this->state_event_retry_is_pending( $args );
			if ( ! $scheduled && true === $this->state_event_handoff_is_running( $args ) ) {
				$scheduled = true;
			}
			if ( ! $scheduled ) {
				$scheduled = $this->schedule_state_event_retry_without_lock( $args );
			}
			if ( ! $scheduled ) {
				do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_retry_unavailable', array() );
			}

			return $scheduled;
		}

		try {
			$scheduled = $this->schedule_state_event_retry_under_lock( $args );
		} finally {
			$this->release_state_event_schedule_lock( $lock_name );
		}
		if ( ! $scheduled ) {
			do_action( 'digitalogic_pricing_state_event_failed', 'digitalogic_pricing_state_retry_unavailable', array() );
		}

		return $scheduled;
	}

	/** Schedule one exact successor while the caller owns its content-addressed mutex. */
	private function schedule_state_event_retry_under_lock( $args ) {
		$pending_state = $this->state_event_retry_pending_state( $args );
		if ( true === $pending_state ) {
			return true;
		}
		if ( null === $pending_state ) {
			return $this->schedule_state_event_retry_without_lock( $args );
		}

		$timestamp = time() + self::RETRY_AFTER;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			try {
				// Exact absence plus this mutex permits one non-unique replacement while the primary is running.
				$result = as_schedule_single_action( $timestamp, self::STATE_EVENT_HOOK, $args, $this->state_event_action_group( $args ), false );
				if ( $this->state_event_action_result_succeeded( $result ) ) {
					return true;
				}
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}

		return $this->schedule_state_event_retry_without_lock( $args );
	}

	/** Return whether one exact state-event retry is already pending. */
	private function state_event_retry_is_pending( $args ) {
		return true === $this->state_event_retry_pending_state( $args );
	}

	/** Return true/false for exact pending state, or null when a scheduler cannot be read. */
	private function state_event_retry_pending_state( $args ) {
		$known = true;
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			try {
				$groups = array_unique( array( $this->state_event_action_group( $args ), self::ACTION_GROUP ) );
				$hooks  = array( self::STATE_EVENT_HOOK, self::STATE_EVENT_HANDOFF_HOOK, self::STATE_EVENT_RECOVERY_HOOK );
				foreach ( $hooks as $hook ) {
					foreach ( $groups as $group ) {
						$actions = as_get_scheduled_actions(
							array(
								'hook'     => $hook,
								'args'     => $args,
								'group'    => $group,
								'status'   => 'pending',
								'per_page' => 1,
							),
							'ids'
						);

						if ( ! empty( $actions ) ) {
							return true;
						}
					}
				}
			} catch ( Throwable $error ) {
				$known = false;
			}
		}
		if ( function_exists( 'wp_next_scheduled' ) ) {
			try {
				if (
					false !== wp_next_scheduled( self::STATE_EVENT_HOOK, $args )
					|| false !== wp_next_scheduled( self::STATE_EVENT_HANDOFF_HOOK, $args )
					|| false !== wp_next_scheduled( self::STATE_EVENT_RECOVERY_HOOK, $args )
				) {
					return true;
				}
			} catch ( Throwable $error ) {
				$known = false;
			}
		}

		return $known ? false : null;
	}

	/** Return whether an exact handoff/recovery is already executing, or null if unreadable. */
	private function state_event_handoff_is_running( $args ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		try {
			$groups = array_unique( array( $this->state_event_action_group( $args ), self::ACTION_GROUP ) );
			$hooks  = array( self::STATE_EVENT_HANDOFF_HOOK, self::STATE_EVENT_RECOVERY_HOOK );
			foreach ( $hooks as $hook ) {
				foreach ( $groups as $group ) {
					$actions = as_get_scheduled_actions(
						array(
							'hook'     => $hook,
							'args'     => $args,
							'group'    => $group,
							'status'   => 'in-progress',
							'per_page' => 1,
						),
						'ids'
					);
					if ( ! empty( $actions ) ) {
						return true;
					}
				}
			}
		} catch ( Throwable $error ) {
			return null;
		}

		return false;
	}

	/** Preserve one exact fallback through WP-Cron when its scheduler lock times out. */
	private function schedule_state_event_retry_without_lock( $args ) {
		$timestamp = time() + self::RETRY_AFTER;
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			if ( $this->schedule_wp_cron_state_event_retry( $timestamp, $args ) ) {
				return true;
			}
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$hooks = array( self::STATE_EVENT_HOOK, self::STATE_EVENT_HANDOFF_HOOK, self::STATE_EVENT_RECOVERY_HOOK );
			foreach ( $hooks as $hook ) {
				try {
					// Native uniqueness is atomic; alternate hooks cover an already-running predecessor.
					$result = as_schedule_single_action( $timestamp, $hook, $args, $this->state_event_action_group( $args ), true );
					if ( $this->state_event_action_result_succeeded( $result ) ) {
						return true;
					}
				} catch ( Throwable $error ) {
					unset( $error );
				}
				if ( $this->state_event_retry_is_pending( $args ) ) {
					return true;
				}
			}
		}

		return $this->state_event_retry_is_pending( $args );
	}

	/** Return whether Action Scheduler confirmed insertion of one exact action. */
	private function state_event_action_result_succeeded( $result ) {
		return ! is_wp_error( $result ) && is_numeric( $result ) && 0 < (int) $result;
	}

	/** Schedule WP-Cron and accept it only after exact persisted readback. */
	private function schedule_wp_cron_state_event_retry( $timestamp, $args ) {
		try {
			wp_schedule_single_event( $timestamp, self::STATE_EVENT_HOOK, $args, true );

			return function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::STATE_EVENT_HOOK, $args );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** Return the Action Scheduler group dedicated to one exact fallback identity. */
	private function state_event_action_group( $args ) {
		return self::ACTION_GROUP . '-state-' . substr( $this->state_event_schedule_digest( $args ), 0, 16 );
	}

	/** Return a stable scheduler identity digest for one exact fallback argument set. */
	private function state_event_schedule_digest( $args ) {
		$encoded = wp_json_encode( $args );

		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/** Return the database-lock identity for one exact fallback argument set. */
	private function state_event_schedule_lock_name( $args ) {
		return self::STATE_EVENT_SCHEDULE_LOCK_NAME . ':' . substr( $this->state_event_schedule_digest( $args ), 0, 16 );
	}

	/** Serialize exact pending-action readback and insertion across PHP requests. */
	private function acquire_state_event_schedule_lock( $lock_name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		try {
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', (string) $lock_name, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		} catch ( Throwable $error ) {
			return false;
		}

		return 1 === (int) $acquired;
	}

	/** Release the state-event scheduler mutex owned by this request. */
	private function release_state_event_schedule_lock( $lock_name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}

		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', (string) $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/** Schedule one bounded retry for the persistent snapshot-terminal outbox. */
	private function schedule_terminal_event_retry( $delay = self::RETRY_AFTER ) {
		$locked = $this->acquire_terminal_event_schedule_lock();
		if ( ! $locked ) {
			$scheduled = $this->terminal_event_retry_is_pending();
			if ( ! $scheduled ) {
				do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_retry_unavailable', '' );
			}

			return $scheduled;
		}

		$timestamp = time() + max( self::RETRY_AFTER, min( self::BUILD_TTL, (int) $delay ) );
		try {
			if ( $this->terminal_event_retry_is_pending() ) {
				return true;
			}

			$scheduled = false;
			if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
				$scheduled = (bool) as_schedule_single_action( $timestamp, self::TERMINAL_EVENT_HOOK, array(), self::ACTION_GROUP, false );
			} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
				$scheduled = wp_schedule_single_event( $timestamp, self::TERMINAL_EVENT_HOOK, array(), true );
				$scheduled = ! is_wp_error( $scheduled ) && false !== $scheduled;
			}
			$scheduled = $scheduled || $this->terminal_event_retry_is_pending();
		} finally {
			$this->release_terminal_event_schedule_lock();
		}
		if ( ! $scheduled ) {
			do_action( 'digitalogic_pricing_terminal_event_failed', 'digitalogic_pricing_terminal_retry_unavailable', '' );
		}

		return $scheduled;
	}

	/** Return whether one exact terminal-event retry is already pending. */
	private function terminal_event_retry_is_pending() {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::TERMINAL_EVENT_HOOK,
					'args'     => array(),
					'group'    => self::ACTION_GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);

			return ! empty( $actions );
		}
		if ( function_exists( 'wp_next_scheduled' ) ) {
			return false !== wp_next_scheduled( self::TERMINAL_EVENT_HOOK, array() );
		}

		return false;
	}

	/** Serialize terminal retry readback and insertion across PHP requests. */
	private function acquire_terminal_event_schedule_lock() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::TERMINAL_EVENT_SCHEDULE_LOCK_NAME, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.

		return 1 === (int) $acquired;
	}

	/** Release the terminal-event scheduler mutex owned by this request. */
	private function release_terminal_event_schedule_lock() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}

		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::TERMINAL_EVENT_SCHEDULE_LOCK_NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/** Remove one expired idempotency option only when its exact claim matches. */
	public function cleanup_idempotency( $request_id, $fingerprint, $expires_at ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return;
		}
		try {
			$key     = $this->idempotency_key( $request_id );
			$current = get_option( $key, null );
			if (
				is_array( $current )
				&& hash_equals( (string) ( $current['fingerprint'] ?? '' ), (string) $fingerprint )
				&& (int) ( $current['expires_at'] ?? 0 ) === (int) $expires_at
				&& (int) $expires_at <= time()
			) {
				delete_option( $key );
			}
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Build and atomically publish immutable pages plus metadata. */
	private function publish_snapshot( $job, $current, $catalog ) {
		if ( $this->cancellation_requested( $job['build_id'] ) || ! $this->active_worker_lease_owned( $job['build_id'] ) ) {
			return $this->cancelled_error();
		}
		$catalog = $this->project_excel_catalog( $catalog );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		$rows = array_values( (array) ( $catalog['rows'] ?? array() ) );
		if ( count( $rows ) > self::MAX_ROWS ) {
			return $this->error(
				'digitalogic_pricing_snapshot_row_limit_exceeded',
				'The reconciled projection exceeds the bounded snapshot row limit.',
				503,
				false,
				array( 'max_rows' => self::MAX_ROWS )
			);
		}
		$seen = array();
		foreach ( $rows as $row ) {
			$key = is_array( $row ) && is_string( $row['sync_key'] ?? null ) ? $row['sync_key'] : '';
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				return $this->error(
					'digitalogic_pricing_snapshot_sync_key_invalid',
					'Every pricing snapshot row must have one unique sync_key.',
					503,
					false
				);
			}
			$seen[ $key ] = true;
		}

		$row_count  = count( $rows );
		$page_count = max( 1, (int) ceil( $row_count / $job['page_size'] ) );
		$created    = time();
		$token      = 'snap_' . $this->token();
		$meta       = array(
			'schema'                  => self::SNAPSHOT_SCHEMA,
			'projection'              => self::PROJECTION,
			'projection_schema'       => self::PROJECTION_SCHEMA,
			'snapshot_token'          => $token,
			'source'                  => $job['source'],
			'state_revision'          => $current['state_revision'],
			'pricing_state_revision'  => $current['pricing_state_revision'],
			'pricing_policy_revision' => $current['pricing_policy_revision'],
			'catalog_revision'        => $current['catalog_revision'],
			'dataset_revision'        => (string) ( $catalog['dataset_revision'] ?? '' ),
			'created_at'              => gmdate( 'c', $created ),
			'expires_at'              => gmdate( 'c', $created + self::SNAPSHOT_TTL ),
			'expires_timestamp'       => $created + self::SNAPSHOT_TTL,
			'row_count'               => $row_count,
			'distinct_sync_keys'      => count( $seen ),
			'page_size'               => $job['page_size'],
			'page_count'              => $page_count,
			'locale'                  => $job['locale'],
			'columns'                 => array_values( (array) ( $catalog['columns'] ?? array() ) ),
			'reconciliation'          => (array) ( $catalog['reconciliation'] ?? array() ),
			'settings'                => $current['settings'],
			'mutation_guard'          => $this->mutation_guard( $current['pricing_state_revision'] ),
		);
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $meta['dataset_revision'] ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_dataset_revision_invalid',
				'The complete projection has no valid dataset revision.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		}
		$page_rows_by_number = array();
		$page_digests        = array();
		for ( $page = 1; $page <= $page_count; ++$page ) {
			$page_rows_by_number[ $page ] = array_slice( $rows, ( $page - 1 ) * $job['page_size'], $job['page_size'] );
			$page_digests[]               = $this->digest(
				array(
					'page' => $page,
					'rows' => $page_rows_by_number[ $page ],
				)
			);
		}
		$meta['page_digests']    = $page_digests;
		$meta['digest']          = $this->snapshot_digest( $meta, $rows );
		$meta['revision']        = $meta['digest'];
		$meta['etag']            = $this->etag( $meta['digest'] );
		$catalog_metadata_digest = $this->digest(
			array(
				'dataset_revision' => $meta['dataset_revision'],
				'columns'          => $meta['columns'],
				'reconciliation'   => $meta['reconciliation'],
				'row_count'        => $row_count,
			)
		);
		$meta['integrity']       = array(
			'algorithm'               => 'sha256',
			'payload_digest'          => $meta['digest'],
			'state_digest'            => $this->digest( array( $meta['pricing_state_revision'], $meta['settings'], $meta['mutation_guard'] ) ),
			'catalog_metadata_digest' => $catalog_metadata_digest,
			'page_revisions_digest'   => $this->digest( $page_digests ),
			'dataset_revision'        => $meta['dataset_revision'],
			'row_count'               => $row_count,
			'distinct_sync_keys'      => count( $seen ),
			'remote_total'            => $row_count,
			'page_count'              => $page_count,
			'warning_count'           => count( (array) ( $meta['reconciliation']['warnings'] ?? array() ) ),
		);

		$stored_pages = array();
		for ( $page = 1; $page <= $page_count; ++$page ) {
			$page_rows = $page_rows_by_number[ $page ];
			$key       = $this->page_key( $token, $page );
			if ( ! $this->store_transient_verified( $key, $page_rows, self::SNAPSHOT_TTL ) ) {
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'A pricing snapshot page could not be stored.',
					503,
					true,
					array( 'page' => $page ),
					self::RETRY_AFTER
				);
			}
			$stored_pages[] = $page;
		}
		if ( ! $this->checkpoint( $job['build_id'], 'publishing', 98, $row_count, $row_count ) ) {
			$this->delete_snapshot_pages( $token, $stored_pages );
			return is_wp_error( $this->active_worker_error ) ? $this->active_worker_error : $this->cancelled_error();
		}

		$commit_lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $commit_lock ) ) {
			$this->delete_snapshot_pages( $token, $stored_pages );
			return $commit_lock;
		}
		try {
			$latest = $this->read_job( $job['build_id'] );
			if ( is_array( $latest ) && 'running' === $latest['status'] && $this->build_deadline_expired( $latest ) ) {
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->build_deadline_error();
			}
			if (
				! is_array( $latest )
				|| 'running' !== $latest['status']
				|| $this->cancellation_requested( $job['build_id'] )
				|| ! $this->active_worker_lease_owned( $job['build_id'] )
			) {
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->cancelled_error();
			}

			if ( ! $this->store_transient_verified( $this->meta_key( $token ), $meta, self::METADATA_TTL ) ) {
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The pricing snapshot metadata could not be stored.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}
			if ( ! $this->store_transient_verified( $this->ready_key( $meta['state_revision'], $meta['locale'], $meta['page_size'] ), $token, self::SNAPSHOT_TTL ) ) {
				delete_transient( $this->meta_key( $token ) );
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The pricing snapshot cache pointer could not be stored.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}

			$latest['status']            = 'ready';
			$latest['updated_at']        = gmdate( 'c' );
			$latest['snapshot_token']    = $token;
			$latest['revision']          = $meta['revision'];
			$latest['snapshot_revision'] = $meta['revision'];
			$latest['digest']            = $meta['digest'];
			$latest['row_count']         = $row_count;
			$latest['page_count']        = $page_count;
			$latest['expires_at']        = $meta['expires_at'];
			$latest['progress']          = $this->progress( 'ready', 100, $row_count, $row_count );
			if ( ! $this->persist_terminal_event_outbox( $latest ) ) {
				delete_transient( $this->ready_key( $meta['state_revision'], $meta['locale'], $meta['page_size'] ) );
				delete_transient( $this->meta_key( $token ) );
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->terminal_event_storage_error();
			}
			if ( ! $this->store_job( $latest ) ) {
				delete_transient( $this->ready_key( $meta['state_revision'], $meta['locale'], $meta['page_size'] ) );
				delete_transient( $this->meta_key( $token ) );
				$this->delete_snapshot_pages( $token, $stored_pages );
				return $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The completed pricing snapshot state could not be stored.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
			}
			if ( ! $this->commit_terminal_event_outbox( $latest ) ) {
				return $this->terminal_event_storage_error();
			}
			$this->unschedule_build_watchdog( $latest );
			$this->unschedule_build_activation( $latest );
			$this->release_build_slot( $job['build_id'] );
			$this->publish_scheduled_terminal_events();

			return $meta;
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Validate the exact snapshot build request and optimistic revision. */
	private function validate_start_request( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_payload_invalid', 'The snapshot request must be a JSON object.', 400, false );
		}
		if ( 'snapshot' !== ( $payload['operation'] ?? null ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_operation_invalid', 'The snapshot operation must be exactly snapshot.', 400, false );
		}
		foreach ( array(
			'client_id'  => 64,
			'channel'    => 32,
			'request_id' => 128,
		) as $field => $maximum ) {
			$value = $payload[ $field ] ?? null;
			if ( ! is_string( $value ) || strlen( $value ) < ( 'request_id' === $field ? 8 : 1 ) || strlen( $value ) > $maximum || 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $value ) ) {
				return $this->error(
					'digitalogic_pricing_snapshot_request_context_invalid',
					'Snapshot request provenance must use bounded nonsecret identifiers.',
					400,
					false,
					array( 'field' => $field )
				);
			}
		}
		$idempotency_key = $payload['idempotency_key'] ?? $payload['request_id'];
		if ( ! is_string( $idempotency_key ) || ! hash_equals( $payload['request_id'], $idempotency_key ) || ! hash_equals( $payload['request_id'], (string) $request->get_header( 'idempotency-key' ) ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_idempotency_invalid', 'Idempotency-Key, request_id, and idempotency_key must match.', 400, false );
		}
		$expected = $payload['expected_state_revision'] ?? null;
		if ( ! $this->is_revision( $expected ) || '"' . $expected . '"' !== $request->get_header( 'if-match' ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_if_match_invalid', 'If-Match must exactly quote expected_state_revision.', 428, false );
		}
		$source = Digitalogic_Excel_Pricing_Sync::instance()->normalize_snapshot_source( $payload['source'] ?? null );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$locale    = $this->normalize_locale( $payload['locale'] ?? 'fa' );
		$page_size = $this->normalize_page_size( $payload['page_size'] ?? self::DEFAULT_PAGE_SIZE );
		if ( is_wp_error( $locale ) ) {
			return $locale;
		}
		if ( is_wp_error( $page_size ) ) {
			return $page_size;
		}
		$max_age = isset( $payload['max_age_seconds'] ) ? (int) $payload['max_age_seconds'] : self::SNAPSHOT_TTL;
		if ( $max_age < 0 || $max_age > self::SNAPSHOT_TTL ) {
			return $this->error( 'digitalogic_pricing_snapshot_max_age_invalid', 'max_age_seconds is outside the supported cache window.', 400, false );
		}

		return array(
			'client_id'               => $payload['client_id'],
			'channel'                 => $payload['channel'],
			'request_id'              => $payload['request_id'],
			'source'                  => $source,
			'locale'                  => $locale,
			'page_size'               => $page_size,
			'max_age_seconds'         => $max_age,
			'expected_state_revision' => $expected,
		);
	}

	/** Read every cheap revision component and bind them deterministically. */
	private function current_revision_data( $source ) {
		$validated = Digitalogic_Excel_Pricing_Sync::instance()->validate_snapshot_source( $source );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$pricing = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}
		$catalog_revision = Digitalogic_Report_Engine::instance()->projection_revision(
			$validated['source']['id'],
			$validated['source']['dataset']
		);
		if ( is_wp_error( $catalog_revision ) ) {
			return $catalog_revision;
		}
		$pricing_policy_revision = $this->digest(
			array(
				'schema'                 => self::PRICING_POLICY_SCHEMA,
				'pricing_state_revision' => $pricing['state_revision'],
				'attribute_owners'       => (array) ( $pricing['attribute_owners'] ?? array() ),
			)
		);
		$state_revision          = $this->digest(
			array(
				'contract'                => 'living',
				'source_revision'         => $validated['source']['revision'],
				'catalog_revision'        => $catalog_revision,
				'pricing_policy_revision' => $pricing_policy_revision,
			)
		);

		return array(
			'source'                  => $validated['source'],
			'source_context'          => $validated['context'],
			'catalog_revision'        => $catalog_revision,
			'pricing_state_revision'  => $pricing['state_revision'],
			'pricing_policy_revision' => $pricing_policy_revision,
			'state_revision'          => $state_revision,
			'settings'                => $pricing['settings'],
		);
	}

	/** Return a stored job only when its exact source matches the request. */
	private function job_for_request( WP_REST_Request $request ) {
		$build_id = (string) $request->get_param( 'build_id' );
		$job      = $this->read_job( $build_id );
		if ( ! is_array( $job ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_build_not_found', 'The pricing snapshot build was not found.', 404, false );
		}
		if ( 'queued' === $job['status'] && $this->queue_deadline_expired( $job ) ) {
			$job = $this->refresh_queued_job( $job );
		}
		if ( is_array( $job ) && 'running' === $job['status'] ) {
			$job = $this->refresh_stalled_job( $job );
		}
		if ( ! is_array( $job ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_build_not_found', 'The pricing snapshot build was not found.', 404, false );
		}
		if ( $this->cancellation_requested( $build_id ) && ! in_array( $job['status'], array( 'ready', 'failed', 'cancelled' ), true ) ) {
			$job = $this->mark_job_cancelled( $job );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$source = $this->request_source( $request );
		if ( is_wp_error( $source ) || $source !== $job['source'] ) {
			return $this->error( 'digitalogic_pricing_snapshot_source_mismatch', 'The request source does not own this snapshot build.', 404, false );
		}

		return $job;
	}

	/** Return immutable metadata only for the exact request source. */
	private function snapshot_for_request( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'snapshot_token' );
		$meta  = $this->read_snapshot_meta( $token );
		if ( ! is_array( $meta ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_not_found', 'The pricing snapshot was not found.', 404, false );
		}
		$source = $this->request_source( $request );
		if ( is_wp_error( $source ) || $source !== $meta['source'] ) {
			return $this->error( 'digitalogic_pricing_snapshot_source_mismatch', 'The request source does not own this snapshot.', 404, false );
		}
		if ( (int) $meta['expires_timestamp'] <= time() ) {
			return $this->error( 'digitalogic_pricing_snapshot_expired', 'The immutable pricing snapshot has expired.', 410, false );
		}

		return $meta;
	}

	/** Build one new job record. */
	private function new_job( $payload, $current, $build_key, $status, $build_id = '' ) {
		$created = time();
		return array(
			'schema'                 => self::BUILD_SCHEMA,
			'build_id'               => '' !== $build_id ? $build_id : 'build_' . $this->token(),
			'request_id'             => $payload['request_id'],
			'terminal_request_ids'   => array( $payload['request_id'] ),
			'watchdog_token'         => $this->token(),
			'client_id'              => $payload['client_id'],
			'channel'                => $payload['channel'],
			'source'                 => $payload['source'],
			'locale'                 => $payload['locale'],
			'page_size'              => $payload['page_size'],
			'state_revision'         => $current['state_revision'],
			'pricing_state_revision' => $current['pricing_state_revision'],
			'catalog_revision'       => $current['catalog_revision'],
			'build_key'              => $build_key,
			'status'                 => $status,
			'created_at'             => gmdate( 'c', $created ),
			'updated_at'             => gmdate( 'c', $created ),
			'start_deadline_at'      => gmdate( 'c', $created + self::QUEUE_START_TTL ),
			'deadline_at'            => gmdate( 'c', $created + self::BUILD_TTL ),
			'cached'                 => false,
			'progress'               => $this->progress( 'queued', 0, 0, 0 ),
		);
	}

	/** Persist one coalesced request so every waiter receives its own terminal frame. */
	private function append_terminal_request_id( $job, $request_id ) {
		$request_ids   = isset( $job['terminal_request_ids'] ) && is_array( $job['terminal_request_ids'] )
			? $job['terminal_request_ids']
			: array( (string) ( $job['request_id'] ?? '' ) );
		$request_ids[] = (string) $request_id;
		$request_ids   = array_values( array_unique( array_filter( $request_ids, 'is_string' ) ) );
		if ( count( $request_ids ) > 128 ) {
			return $this->busy_error();
		}
		$job['terminal_request_ids'] = $request_ids;
		if ( ! $this->store_job( $job ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_storage_unavailable',
				'The coalesced snapshot request could not be attached to its terminal event.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		}

		return $job;
	}

	/** Store a bounded progress checkpoint and report whether work may continue. */
	private function checkpoint( $build_id, $phase, $percent, $completed, $total ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			$this->active_worker_error = $lock;
			return false;
		}
		try {
			if ( ! $this->active_worker_lease_owned( $build_id ) ) {
				$this->active_worker_error = $this->error(
					'digitalogic_pricing_snapshot_worker_lease_lost',
					'The snapshot worker no longer owns this build lease.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
				return false;
			}
			if ( $this->cancellation_requested( $build_id ) ) {
				return false;
			}
			$job = $this->read_job( $build_id );
			if ( ! is_array( $job ) || 'running' !== $job['status'] ) {
				return false;
			}
			if ( $this->build_deadline_expired( $job ) ) {
				$this->active_worker_error = $this->build_deadline_error();
				$this->fail_job( $build_id, $this->active_worker_error );
				return false;
			}

			$job['updated_at'] = gmdate( 'c' );
			$job['progress']   = $this->progress( $phase, $percent, $completed, $total );
			if ( ! $this->store_job( $job ) ) {
				$this->active_worker_error = $this->error(
					'digitalogic_pricing_snapshot_storage_unavailable',
					'The snapshot progress checkpoint could not be stored.',
					503,
					true,
					array(),
					self::RETRY_AFTER
				);
				return false;
			}

			return true;
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Return one normalized progress object. */
	private function progress( $phase, $percent, $completed, $total ) {
		return array(
			'phase'     => sanitize_key( (string) $phase ),
			'percent'   => max( 0, min( 100, (int) $percent ) ),
			'completed' => max( 0, (int) $completed ),
			'total'     => max( 0, (int) $total ),
		);
	}

	/** Schedule a build and watchdog through independent durable one-shot paths. */
	private function enqueue_build( $build_id ) {
		$job = $this->read_job( $build_id );
		if (
			! is_array( $job )
			|| ! $this->schedule_build_watchdog(
				$build_id,
				(string) ( $job['watchdog_token'] ?? '' ),
				max( time() + 1, 1 + (int) strtotime( (string) ( $job['start_deadline_at'] ?? '' ) ) )
			)
		) {
			return $this->watchdog_unavailable_error();
		}
		$override = apply_filters( 'digitalogic_pricing_snapshot_enqueue', null, $build_id );
		$as_path  = null;
		if ( null !== $override ) {
			$as_path = static function () use ( $override ) {
				return (bool) $override;
			};
		}
		if (
			! $this->schedule_dual_one_shot(
				self::BUILD_HOOK,
				array( (string) $build_id ),
				time() + 1,
				'async',
				$as_path
			)
		) {
			return $this->error( 'digitalogic_pricing_snapshot_scheduler_unavailable', 'The snapshot worker could not be scheduled.', 503, true, array(), 30 );
		}

		return true;
	}

	/** Schedule one source/job-fenced watchdog through both durable paths. */
	private function schedule_build_watchdog( $build_id, $watchdog_token, $timestamp ) {
		if (
			1 !== preg_match( '/\Abuild_[a-f0-9]{32}\z/D', (string) $build_id )
			|| 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', (string) $watchdog_token )
		) {
			return false;
		}
		return $this->schedule_dual_one_shot(
			self::BUILD_WATCHDOG_HOOK,
			array( (string) $build_id, (string) $watchdog_token ),
			max( time() + 1, (int) $timestamp )
		);
	}

	/** Remove only this exact build's pending watchdog actions. */
	private function unschedule_build_watchdog( $job ) {
		$args = array(
			(string) ( $job['build_id'] ?? '' ),
			(string) ( $job['watchdog_token'] ?? '' ),
		);
		if ( '' === $args[0] || '' === $args[1] ) {
			return;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BUILD_WATCHDOG_HOOK, $args, self::ACTION_GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::BUILD_WATCHDOG_HOOK, $args );
		}
	}

	/** Remove only this exact build's pending activation actions. */
	private function unschedule_build_activation( $job ) {
		$args = array( (string) ( $job['build_id'] ?? '' ) );
		if ( '' === $args[0] ) {
			return;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BUILD_HOOK, $args, self::ACTION_GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::BUILD_HOOK, $args );
		}
	}

	/**
	 * Attempt Action Scheduler and WP-Cron independently for one exact action.
	 *
	 * Optional callables are used only by focused tests to exercise the complete
	 * durability matrix without making Action Scheduler a global test fixture.
	 */
	private function schedule_dual_one_shot( $hook, $args, $timestamp, $action_scheduler_mode = 'single', $action_scheduler = null, $wp_cron = null, $wp_cron_verifier = null ) {
		$args      = array_values( (array) $args );
		$timestamp = max( time() + 1, (int) $timestamp );
		$as_ok     = false;
		$cron_ok   = false;

		try {
			if ( is_callable( $action_scheduler ) ) {
				$as_result = call_user_func( $action_scheduler, $hook, $args, $timestamp, $action_scheduler_mode );
				$as_ok     = ! is_wp_error( $as_result ) && (bool) $as_result;
			} elseif ( 'async' === $action_scheduler_mode && function_exists( 'as_enqueue_async_action' ) ) {
				$as_result = as_enqueue_async_action( $hook, $args, self::ACTION_GROUP, false );
				$as_ok     = ! is_wp_error( $as_result ) && (bool) $as_result;
			} elseif ( 'single' === $action_scheduler_mode && function_exists( 'as_schedule_single_action' ) ) {
				$as_result = as_schedule_single_action( $timestamp, $hook, $args, self::ACTION_GROUP, false );
				$as_ok     = ! is_wp_error( $as_result ) && (bool) $as_result;
			}
		} catch ( Throwable $error ) {
			$as_ok = false;
		}

		try {
			if ( is_callable( $wp_cron ) ) {
				$cron_result = call_user_func( $wp_cron, $hook, $args, $timestamp );
				$cron_ok     = ! is_wp_error( $cron_result ) && false !== $cron_result;
				if ( is_callable( $wp_cron_verifier ) ) {
					$cron_readback = call_user_func( $wp_cron_verifier, $hook, $args );
					$cron_ok       = is_numeric( $cron_readback ) && (int) $cron_readback > 0;
				}
			} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
				$cron_result = wp_schedule_single_event( $timestamp, $hook, $args, true );
				if ( function_exists( 'wp_next_scheduled' ) ) {
					$cron_readback = wp_next_scheduled( $hook, $args );
					$cron_ok       = is_numeric( $cron_readback ) && (int) $cron_readback > 0;
				} else {
					$cron_ok = ! is_wp_error( $cron_result ) && false !== $cron_result;
				}
			}
		} catch ( Throwable $error ) {
			$cron_ok = false;
		}

		return $as_ok || $cron_ok;
	}

	/** Schedule one checked, non-unique retry when worker admission was contended. */
	private function retry_worker( $build_id ) {
		return $this->schedule_dual_one_shot(
			self::BUILD_HOOK,
			array( (string) $build_id ),
			time() + self::RETRY_AFTER
		);
	}

	/**
	 * Persist one leased worker failure, or schedule a checked retry.
	 *
	 * Throwing only when both durable paths fail prevents Action Scheduler from
	 * recording a false successful delivery while the shared job is nonterminal.
	 *
	 * @throws RuntimeException When neither durable failure path succeeds.
	 */
	private function record_worker_failure( $build_id, $error ) {
		if ( $this->fail_job( $build_id, $error ) || $this->retry_worker( $build_id ) ) {
			return;
		}

		throw new RuntimeException( 'The snapshot worker failure could not be persisted or rescheduled.' );
	}

	/**
	 * Fail only a still-queued job with no live worker lease.
	 *
	 * This is the no-lease delivery path: it must never terminalize a different
	 * Action Scheduler invocation that acquired or already owns the live lease.
	 */
	private function fail_unleased_queued_job( $build_id, $error ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$job = $this->read_job( $build_id );
			if ( ! is_array( $job ) || in_array( $job['status'], array( 'ready', 'failed', 'cancelled' ), true ) ) {
				return true;
			}
			if ( 'queued' !== $job['status'] ) {
				return true;
			}

			$worker_key = $this->worker_key( $build_id );
			$lease      = get_option( $worker_key, null );
			if ( is_array( $lease ) && (int) ( $lease['expires_at'] ?? 0 ) > time() ) {
				return true;
			}
			if ( is_array( $lease ) ) {
				delete_option( $worker_key );
			}

			return $this->fail_job( $build_id, $error );
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Acquire one global non-queueing build slot, or coalesce an identical build. */
	private function acquire_build_slot( $build_id, $build_key ) {
		$slot = array(
			'build_id'   => $build_id,
			'build_key'  => $build_key,
			'expires_at' => time() + self::BUILD_TTL,
		);
		if ( add_option( self::ACTIVE_BUILD_OPTION, $slot, '', false ) ) {
			return true;
		}

		$current = get_option( self::ACTIVE_BUILD_OPTION, null );
		if ( is_array( $current ) && ! empty( $current['build_id'] ) ) {
			$current_job = $this->read_job( (string) $current['build_id'] );
			if ( is_array( $current_job ) && 'queued' === $current_job['status'] && $this->queue_deadline_expired( $current_job ) ) {
				$current_job = $this->refresh_queued_job( $current_job );
			}
			if ( is_array( $current_job ) && 'running' === $current_job['status'] ) {
				$current_job = $this->refresh_stalled_job( $current_job );
			}
			$current     = get_option( self::ACTIVE_BUILD_OPTION, null );
			$current_job = is_array( $current ) && ! empty( $current['build_id'] )
				? $this->read_job( (string) $current['build_id'] )
				: null;
			if ( ! is_array( $current_job ) || in_array( $current_job['status'], array( 'ready', 'failed', 'cancelled' ), true ) ) {
				delete_option( self::ACTIVE_BUILD_OPTION );
				$current = null;
			}
		}
		if ( ! is_array( $current ) && add_option( self::ACTIVE_BUILD_OPTION, $slot, '', false ) ) {
			return true;
		}
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= time() ) {
			// A live worker is fenced by its lease and fixed build deadline. Never
			// steal its slot merely because an older option timestamp elapsed.
			$current_job = $this->read_job( (string) ( $current['build_id'] ?? '' ) );
			if ( ! is_array( $current_job ) || 'running' !== $current_job['status'] ) {
				delete_option( self::ACTIVE_BUILD_OPTION );
				if ( add_option( self::ACTIVE_BUILD_OPTION, $slot, '', false ) ) {
					return true;
				}
				$current = get_option( self::ACTIVE_BUILD_OPTION, null );
			}
		}
		if ( is_array( $current ) && hash_equals( (string) ( $current['build_key'] ?? '' ), $build_key ) ) {
			return array( 'build_id' => (string) ( $current['build_id'] ?? '' ) );
		}

		return $this->busy_error();
	}

	/** Release only the active slot owned by this build. */
	private function release_build_slot( $build_id ) {
		$acquired_here = false;
		if ( ! $this->admission_lock_held ) {
			$lock = $this->acquire_admission_lock();
			if ( is_wp_error( $lock ) ) {
				return false;
			}
			$acquired_here = true;
		}

		$current = get_option( self::ACTIVE_BUILD_OPTION, null );
		if ( is_array( $current ) && hash_equals( (string) ( $current['build_id'] ?? '' ), (string) $build_id ) ) {
			delete_option( self::ACTIVE_BUILD_OPTION );
		}
		if ( $acquired_here ) {
			$this->release_admission_lock();
		}

		return true;
	}

	/** Acquire the short nonblocking mutex that serializes admission/CAS state. */
	private function acquire_admission_lock( $timeout = 0 ) {
		if ( $this->admission_lock_held > 0 ) {
			++$this->admission_lock_held;
			return true;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_admission_unavailable',
				'The pricing snapshot admission mutex is unavailable.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		}
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::ADMISSION_LOCK_NAME, max( 0, min( 1, (int) $timeout ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		if ( 1 !== (int) $acquired ) {
			return $this->busy_error();
		}
		$this->admission_lock_held = 1;

		return true;
	}

	/** Release only the admission mutex owned by this request. */
	private function release_admission_lock() {
		if ( $this->admission_lock_held < 1 ) {
			return;
		}
		if ( $this->admission_lock_held > 1 ) {
			--$this->admission_lock_held;
			return;
		}
		global $wpdb;
		try {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::ADMISSION_LOCK_NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		} catch ( Throwable $error ) {
			unset( $error );
		}
		$this->admission_lock_held = 0;
	}

	/** Atomically acquire one worker lease; duplicate scheduler deliveries exit. */
	private function acquire_worker_lease( $build_id ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
			$key     = $this->worker_key( $build_id );
			$current = get_option( $key, null );
			if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) > time() ) {
				return '';
			}
			if ( is_array( $current ) ) {
				delete_option( $key );
			}
			$token = $this->token();
			$lease = array(
				'build_id'   => (string) $build_id,
				'token'      => $token,
				'expires_at' => time() + self::WORKER_LEASE_TTL,
			);

			if ( add_option( $key, $lease, '', false ) ) {
				return $token;
			}

			return $this->error(
				'digitalogic_pricing_snapshot_worker_lease_unavailable',
				'The snapshot worker lease could not be persisted.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Renew and verify the current request's exact worker lease. */
	private function active_worker_lease_owned( $build_id ) {
		if (
			! hash_equals( $this->active_worker_build_id, (string) $build_id )
			|| '' === $this->active_worker_token
		) {
			return false;
		}
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			$current = get_option( $this->worker_key( $build_id ), null );

			return is_array( $current )
				&& hash_equals( (string) ( $current['token'] ?? '' ), $this->active_worker_token )
				&& (int) ( $current['expires_at'] ?? 0 ) > time();
		}
		try {
			$key     = $this->worker_key( $build_id );
			$current = get_option( $key, null );
			if (
				! is_array( $current )
				|| ! hash_equals( (string) ( $current['token'] ?? '' ), $this->active_worker_token )
			) {
				return false;
			}
			$current['expires_at'] = time() + self::WORKER_LEASE_TTL;

			return update_option( $key, $current, false ) || get_option( $key, null ) === $current;
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Release only the exact worker lease owned by this invocation. */
	private function release_worker_lease( $build_id, $worker_token ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return;
		}
		try {
			$key     = $this->worker_key( $build_id );
			$current = get_option( $key, null );
			if ( is_array( $current ) && hash_equals( (string) ( $current['token'] ?? '' ), (string) $worker_token ) ) {
				delete_option( $key );
			}
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Mark a non-cancelled job failed and release its build slot. */
	private function fail_job( $build_id, $error ) {
		$lock = $this->acquire_admission_lock( 1 );
		if ( is_wp_error( $lock ) ) {
			return false;
		}
		try {
			$job = $this->read_job( $build_id );
			if ( ! is_array( $job ) ) {
				return false;
			}

			if ( in_array( $job['status'], array( 'ready', 'failed', 'cancelled' ), true ) ) {
				if ( 'cancelled' === $job['status'] && hash_equals( $this->active_worker_build_id, (string) $build_id ) ) {
					$this->release_build_slot( $build_id );
				}
				if ( ! $this->persist_terminal_event_outbox( $job ) ) {
					return false;
				}
				if ( ! $this->commit_terminal_event_outbox( $job ) ) {
					return false;
				}
				$this->unschedule_build_watchdog( $job );
				$this->unschedule_build_activation( $job );
				$this->publish_scheduled_terminal_events();
				return true;
			}

			// A worker that lost its exact lease must never fail or release the
			// successor's job. Non-worker watchdogs have no request-local token.
			if ( hash_equals( $this->active_worker_build_id, (string) $build_id ) && '' !== $this->active_worker_token ) {
				$lease = get_option( $this->worker_key( $build_id ), null );
				if (
					! is_array( $lease )
					|| ! hash_equals( (string) ( $lease['token'] ?? '' ), $this->active_worker_token )
					|| (int) ( $lease['expires_at'] ?? 0 ) <= time()
				) {
					return false;
				}
			}

			if ( $this->cancellation_requested( $build_id ) ) {
				$job = $this->cancelled_job( $job );
			} else {
				$data              = is_wp_error( $error ) && is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
				$job['status']     = 'failed';
				$job['updated_at'] = gmdate( 'c' );
				$job['code']       = is_wp_error( $error ) ? $error->get_error_code() : 'digitalogic_pricing_snapshot_failed';
				$job['retryable']  = (bool) ( $data['retryable'] ?? false );
				if ( isset( $data['retry_after'] ) ) {
					$job['retry_after'] = max( 1, (int) $data['retry_after'] );
				}
				$job['progress'] = $this->progress(
					'failed',
					(int) ( $job['progress']['percent'] ?? 0 ),
					(int) ( $job['progress']['completed'] ?? 0 ),
					(int) ( $job['progress']['total'] ?? 0 )
				);
			}
			if ( ! $this->persist_terminal_event_outbox( $job ) ) {
				return false;
			}
			if ( ! $this->store_job( $job ) ) {
				return false;
			}
			if ( ! $this->commit_terminal_event_outbox( $job ) ) {
				return false;
			}
			$this->unschedule_build_watchdog( $job );
			$this->unschedule_build_activation( $job );
			$this->release_build_slot( $build_id );
			$this->publish_scheduled_terminal_events();

			return true;
		} finally {
			$this->release_admission_lock();
		}
	}

	/** Persist a monotonic terminal cancellation marker. */
	private function mark_job_cancelled( $job ) {
		$job = $this->cancelled_job( $job );
		if ( ! $this->persist_terminal_event_outbox( $job ) ) {
			return $this->terminal_event_storage_error();
		}
		if ( ! $this->store_job( $job ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_storage_unavailable',
				'The completed cancellation state could not be stored.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		}
		if ( ! $this->commit_terminal_event_outbox( $job ) ) {
			return $this->terminal_event_storage_error();
		}
		$this->unschedule_build_watchdog( $job );
		$this->unschedule_build_activation( $job );
		$this->publish_scheduled_terminal_events();

		return $job;
	}

	/** Convert one nonterminal job into its canonical cancellation state. */
	private function cancelled_job( $job ) {
		$job['status']     = 'cancelled';
		$job['updated_at'] = gmdate( 'c' );
		$job['code']       = 'digitalogic_pricing_snapshot_cancelled';
		$job['retryable']  = false;
		$job['progress']   = $this->progress(
			'cancelled',
			(int) ( $job['progress']['percent'] ?? 0 ),
			(int) ( $job['progress']['completed'] ?? 0 ),
			(int) ( $job['progress']['total'] ?? 0 )
		);
		return $job;
	}

	/** Return whether the monotonic cancellation tombstone exists. */
	private function cancellation_requested( $build_id ) {
		return true === get_transient( $this->cancel_key( $build_id ) );
	}

	/** Return whether Action Scheduler missed the bounded worker-start SLA. */
	private function queue_deadline_expired( $job ) {
		$deadline = strtotime( (string) ( $job['start_deadline_at'] ?? '' ) );

		return false !== $deadline && $deadline <= time();
	}

	/** Return whether the fixed build lifetime elapsed. */
	private function build_deadline_expired( $job ) {
		$deadline = strtotime( (string) ( $job['deadline_at'] ?? '' ) );

		return false !== $deadline && $deadline <= time();
	}

	/** Stable cancellation error used by workers and publication rollback. */
	private function cancelled_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_cancelled',
			'The pricing snapshot build was cancelled.',
			409,
			false
		);
	}

	/** Retryable terminal error for a worker that never started. */
	private function scheduler_deadline_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_scheduler_start_timeout',
			'The snapshot worker did not start within the bounded queue window.',
			503,
			true,
			array(),
			30
		);
	}

	/** Retryable terminal error when a contended worker cannot schedule a retry. */
	private function scheduler_retry_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_scheduler_retry_unavailable',
			'The contended snapshot worker could not schedule a bounded retry.',
			503,
			true,
			array(),
			30
		);
	}

	/** Retryable terminal error for an uncaught worker failure. */
	private function worker_exception_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_worker_exception',
			'The snapshot worker stopped before it could complete the build.',
			503,
			true,
			array(),
			30
		);
	}

	/** Retryable terminal error when no autonomous watchdog can be scheduled. */
	private function watchdog_unavailable_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_watchdog_unavailable',
			'The snapshot build watchdog could not be scheduled.',
			503,
			true,
			array(),
			30
		);
	}

	/** Retryable terminal error for a worker that exceeded its build lifetime. */
	private function build_deadline_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_build_timeout',
			'The snapshot worker exceeded the bounded build lifetime.',
			503,
			true,
			array(),
			30
		);
	}

	/** Fail an expired queued job only if no worker lease raced its transition. */
	private function refresh_queued_job( $job ) {
		$acquired_here = false;
		if ( ! $this->admission_lock_held ) {
			$lock = $this->acquire_admission_lock();
			if ( is_wp_error( $lock ) ) {
				return $job;
			}
			$acquired_here = true;
		}
		try {
			$current_job = $this->read_job( (string) ( $job['build_id'] ?? '' ) );
			if ( ! is_array( $current_job ) || 'queued' !== $current_job['status'] || ! $this->queue_deadline_expired( $current_job ) ) {
				return is_array( $current_job ) ? $current_job : $job;
			}
			$worker = get_option( $this->worker_key( $current_job['build_id'] ), null );
			if ( is_array( $worker ) && (int) ( $worker['expires_at'] ?? 0 ) > time() ) {
				return $current_job;
			}
			$this->fail_job( $current_job['build_id'], $this->scheduler_deadline_error() );

			$refreshed_job = $this->read_job( $current_job['build_id'] );

			return is_array( $refreshed_job ) ? $refreshed_job : $current_job;
		} finally {
			if ( $acquired_here ) {
				$this->release_admission_lock();
			}
		}
	}

	/** Fail a running job only after its exact worker lease has expired. */
	private function refresh_stalled_job( $job ) {
		$acquired_here = false;
		if ( ! $this->admission_lock_held ) {
			$lock = $this->acquire_admission_lock();
			if ( is_wp_error( $lock ) ) {
				return $job;
			}
			$acquired_here = true;
		}
		try {
			$current_job = $this->read_job( (string) ( $job['build_id'] ?? '' ) );
			if ( ! is_array( $current_job ) || 'running' !== $current_job['status'] ) {
				return is_array( $current_job ) ? $current_job : $job;
			}
			if ( $this->build_deadline_expired( $current_job ) ) {
				$this->fail_job( $current_job['build_id'], $this->build_deadline_error() );

				$refreshed_job = $this->read_job( $current_job['build_id'] );

				return is_array( $refreshed_job ) ? $refreshed_job : $current_job;
			}
			$worker = get_option( $this->worker_key( $current_job['build_id'] ), null );
			if ( is_array( $worker ) && (int) ( $worker['expires_at'] ?? 0 ) > time() ) {
				return $current_job;
			}
			if ( is_array( $worker ) ) {
				delete_option( $this->worker_key( $current_job['build_id'] ) );
			}
			$this->fail_job(
				$current_job['build_id'],
				$this->error(
					'digitalogic_pricing_snapshot_worker_stalled',
					'The pricing snapshot worker stopped heartbeating.',
					503,
					true,
					array(),
					30
				)
			);

			$refreshed_job = $this->read_job( $current_job['build_id'] );

			return is_array( $refreshed_job ) ? $refreshed_job : $current_job;
		} finally {
			if ( $acquired_here ) {
				$this->release_admission_lock();
			}
		}
	}

	/** Format a build response with status/cancel/snapshot links. */
	private function job_transport( $job, $replayed, $request_id = null, $request = null ) {
		$data = array_intersect_key(
			$job,
			array_fill_keys(
				array(
					'schema',
					'build_id',
					'request_id',
					'status',
					'source',
					'locale',
					'state_revision',
					'pricing_state_revision',
					'catalog_revision',
					'created_at',
					'updated_at',
					'start_deadline_at',
					'deadline_at',
					'progress',
					'cached',
					'code',
					'retryable',
					'retry_after',
					'snapshot_token',
					'snapshot_revision',
					'row_count',
					'page_count',
					'expires_at',
				),
				true
			)
		);
		if ( is_string( $request_id ) && '' !== $request_id ) {
			$data['request_id'] = $request_id;
		}
		$query              = $this->source_query( $job['source'] );
		$data['replayed']   = (bool) $replayed;
		$data['status_url'] = '/wp-json/digitalogic/pricing/sync/builds/' . rawurlencode( $job['build_id'] ) . $query;
		$data['cancel_url'] = $data['status_url'];
		if ( ! empty( $job['snapshot_token'] ) ) {
			$data['snapshot_url'] = '/wp-json/digitalogic/pricing/sync/snapshots/' . rawurlencode( $job['snapshot_token'] ) . $query;
			$data['pages_url']    = $data['snapshot_url'] . '/pages/{page}';
		}

		$status = 'queued' === $job['status'] || 'running' === $job['status'] ? 202 : 200;
		if ( 'failed' === $job['status'] && ! empty( $job['retryable'] ) ) {
			$status = 503;
		}
		$headers = array(
			'Cache-Control' => 'private, no-cache, must-revalidate',
		);
		if ( 202 === $status ) {
			$headers['Retry-After'] = (string) self::RETRY_AFTER;
			$data['retry_after']    = self::RETRY_AFTER;
			$data['retry_after_ms'] = self::RETRY_AFTER * 1000;
		}
		if ( 503 === $status ) {
			$retry_after            = max( 1, (int) ( $job['retry_after'] ?? self::RETRY_AFTER ) );
			$headers['Retry-After'] = (string) $retry_after;
			$data['retry_after']    = $retry_after;
			$data['retry_after_ms'] = $retry_after * 1000;
		}
		if ( $request instanceof WP_REST_Request ) {
			$status_etag = $this->etag( $this->digest( $data ) );
			if ( 200 === $status && $this->etag_matches( $request->get_header( 'if-none-match' ), $status_etag ) ) {
				$headers['ETag'] = $status_etag;
				return $this->transport( null, 304, $headers );
			}
			$headers['ETag'] = $status_etag;
		} elseif ( ! empty( $job['digest'] ) ) {
			$headers['ETag'] = $this->etag( $job['digest'] );
		}

		return $this->transport( $data, $status, $headers );
	}

	/** Return the canonical complete snapshot payload. */
	private function snapshot_payload( $meta, $rows ) {
		return array(
			'schema'                  => self::SNAPSHOT_SCHEMA,
			'projection'              => self::PROJECTION,
			'projection_schema'       => self::PROJECTION_SCHEMA,
			'snapshot_token'          => $meta['snapshot_token'],
			'snapshot_revision'       => $meta['revision'],
			'state_revision'          => $meta['state_revision'],
			'pricing_state_revision'  => $meta['pricing_state_revision'],
			'pricing_policy_revision' => $meta['pricing_policy_revision'],
			'catalog_revision'        => $meta['catalog_revision'],
			'dataset_revision'        => $meta['dataset_revision'],
			'source'                  => $meta['source'],
			'created_at'              => $meta['created_at'],
			'expires_at'              => $meta['expires_at'],
			'row_count'               => $meta['row_count'],
			'distinct_sync_keys'      => $meta['distinct_sync_keys'],
			'page_size'               => $meta['page_size'],
			'page_count'              => $meta['page_count'],
			'page_digests'            => $meta['page_digests'],
			'integrity'               => $meta['integrity'],
			'mutation_guard'          => $meta['mutation_guard'],
			'settings'                => $meta['settings'],
			'reconciliation'          => $meta['reconciliation'],
			'capabilities'            => $this->capabilities(),
			'diagnostics'             => array(),
			'catalog'                 => array(
				'dataset'          => 'reconciled_products',
				'locale'           => $meta['locale'],
				'dataset_revision' => $meta['dataset_revision'],
				'columns'          => $meta['columns'],
				'rows'             => array_values( $rows ),
				'reconciliation'   => $meta['reconciliation'],
				'pagination'       => array(
					'page'     => 1,
					'limit'    => $meta['page_size'],
					'total'    => $meta['row_count'],
					'pages'    => $meta['page_count'],
					'has_more' => false,
				),
			),
		);
	}

	/** Select only the workbook-owned fields from the canonical catalog. */
	private function project_excel_catalog( $catalog ) {
		$columns_by_key = array();
		foreach ( (array) ( $catalog['columns'] ?? array() ) as $column ) {
			$key = is_array( $column ) ? (string) ( $column['key'] ?? '' ) : '';
			if ( '' === $key || isset( $columns_by_key[ $key ] ) ) {
				return $this->projection_schema_error();
			}
			$columns_by_key[ $key ] = $column;
		}

		$columns = array();
		foreach ( self::PROJECTION_FIELDS as $field ) {
			if ( ! isset( $columns_by_key[ $field ] ) ) {
				return $this->projection_schema_error();
			}
			$columns[] = $columns_by_key[ $field ];
		}

		$rows = array();
		foreach ( (array) ( $catalog['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->projection_schema_error();
			}
			$projected = array();
			foreach ( self::PROJECTION_FIELDS as $field ) {
				if ( ! array_key_exists( $field, $row ) ) {
					return $this->projection_schema_error();
				}
				$projected[ $field ] = $row[ $field ];
			}
			$rows[] = $projected;
		}

		$catalog['columns'] = $columns;
		$catalog['rows']    = $rows;

		return $catalog;
	}

	/** Fail closed when a required workbook field has no canonical value. */
	private function projection_schema_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_projection_schema_invalid',
			'The canonical catalog cannot satisfy the Excel projection.',
			503,
			false,
			array( 'required_fields' => self::PROJECTION_FIELDS )
		);
	}

	/** Hash the stable snapshot representation, excluding token and timestamps. */
	private function snapshot_digest( $meta, $rows ) {
		return $this->digest(
			array(
				'contract'                => 'living',
				'state_revision'          => $meta['state_revision'],
				'pricing_state_revision'  => $meta['pricing_state_revision'],
				'pricing_policy_revision' => $meta['pricing_policy_revision'],
				'catalog_revision'        => $meta['catalog_revision'],
				'dataset_revision'        => $meta['dataset_revision'],
				'source'                  => $meta['source'],
				'locale'                  => $meta['locale'],
				'columns'                 => $meta['columns'],
				'reconciliation'          => $meta['reconciliation'],
				'settings'                => $meta['settings'],
				'page_digests'            => $meta['page_digests'],
				'rows'                    => array_values( $rows ),
			)
		);
	}

	/** Return mutation endpoints and the pricing-only If-Match revision. */
	private function mutation_guard( $pricing_state_revision ) {
		return array(
			'expected_state_revision' => $pricing_state_revision,
			'preview'                 => array(
				'method'                   => 'POST',
				'path'                     => '/wp-json/digitalogic/pricing/sync/preview',
				'requires_idempotency_key' => true,
				'requires_if_match'        => true,
			),
			'apply'                   => array(
				'method'                   => 'POST',
				'path'                     => '/wp-json/digitalogic/pricing/sync/apply',
				'requires_idempotency_key' => true,
				'requires_if_match'        => true,
				'confirmation'             => 'APPLY',
			),
		);
	}

	/** Advertise optional transport capabilities without negotiating a version. */
	private function capabilities() {
		return array(
			'revision'            => true,
			'conditional_request' => true,
			'etag'                => true,
			'incremental_sync'    => false,
			'events'              => true,
			'delete_tracking'     => true,
			'digest_algorithms'   => array( 'sha256' ),
			'recovery_order'      => array( 'events', 'conditional_request', 'polling' ),
		);
	}

	/** Return immutable snapshot headers. */
	private function snapshot_headers( $meta, $etag ) {
		$remaining = max( 0, (int) $meta['expires_timestamp'] - time() );
		return array(
			'ETag'          => $etag,
			'Cache-Control' => 'private, max-age=' . $remaining . ', must-revalidate',
		);
	}

	/** Return a current ready snapshot summary, if one exists. */
	private function current_snapshot_summary( $state_revision, $locale, $page_size ) {
		$meta = $this->current_snapshot_meta( $state_revision, $locale, $page_size );
		if ( ! is_array( $meta ) ) {
			return null;
		}

		return array_intersect_key(
			$meta,
			array_fill_keys( array( 'snapshot_token', 'revision', 'digest', 'expires_at', 'row_count', 'page_size', 'page_count' ), true )
		);
	}

	/** Resolve the ready pointer only when metadata is current and unexpired. */
	private function current_snapshot_meta( $state_revision, $locale, $page_size ) {
		$token = get_transient( $this->ready_key( $state_revision, $locale, $page_size ) );
		$meta  = is_string( $token ) ? $this->read_snapshot_meta( $token ) : null;
		if ( ! is_array( $meta ) || (int) ( $meta['expires_timestamp'] ?? 0 ) <= time() || ! hash_equals( $state_revision, (string) ( $meta['state_revision'] ?? '' ) ) ) {
			return null;
		}

		return $meta;
	}

	/** Return snapshot age in seconds. */
	private function snapshot_age( $meta ) {
		$created = strtotime( (string) ( $meta['created_at'] ?? '' ) );
		return false === $created ? PHP_INT_MAX : max( 0, time() - $created );
	}

	/** Return and validate the explicit source from JSON or query parameters. */
	private function request_source( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		$source  = is_array( $payload ) && is_array( $payload['source'] ?? null )
			? $payload['source']
			: null;
		if ( null === $source ) {
			$source = array(
				'id'       => $request->get_param( 'source_id' ),
				'dataset'  => $request->get_param( 'source_dataset' ),
				'revision' => $request->get_param( 'source_revision' ),
			);
		}
		$validated = Digitalogic_Excel_Pricing_Sync::instance()->normalize_snapshot_source( $source );

		return $validated;
	}

	/** Normalize the fixed Persian snapshot locale. */
	private function normalize_locale( $locale ) {
		$locale = null === $locale || '' === $locale ? 'fa' : (string) $locale;
		if ( ! in_array( $locale, array( 'fa', 'fa_IR' ), true ) ) {
			return $this->error( 'digitalogic_pricing_snapshot_locale_invalid', 'Pricing snapshots support only fa or fa_IR.', 400, false );
		}

		return 'fa';
	}

	/** Normalize the immutable transport page size. */
	private function normalize_page_size( $page_size ) {
		$page_size = null === $page_size || '' === $page_size ? self::DEFAULT_PAGE_SIZE : (int) $page_size;
		if ( $page_size < 1 || $page_size > self::MAX_PAGE_SIZE ) {
			return $this->error(
				'digitalogic_pricing_snapshot_page_size_invalid',
				'Pricing snapshot page_size must be between 1 and 250.',
				400,
				false
			);
		}

		return $page_size;
	}

	/** Return a stable request-source query without credentials. */
	private function source_query( $source ) {
		return '?source_id=' . rawurlencode( $source['id'] )
			. '&source_dataset=' . rawurlencode( $source['dataset'] )
			. '&source_revision=' . rawurlencode( $source['revision'] );
	}

	/** Return a non-queueing capacity error. */
	private function busy_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_build_busy',
			'Another pricing snapshot build owns the bounded worker slot.',
			429,
			true,
			array(),
			self::RETRY_AFTER
		);
	}

	/** Return a stable revision-race error. */
	private function state_changed_error() {
		return $this->error(
			'digitalogic_pricing_snapshot_state_changed',
			'The source, catalog, or pricing policy changed during snapshot construction.',
			409,
			true,
			array(),
			self::RETRY_AFTER
		);
	}

	/** Read one build record. */
	private function read_job( $build_id ) {
		if ( 1 !== preg_match( '/\Abuild_[a-f0-9]{32}\z/D', (string) $build_id ) ) {
			return null;
		}
		$job = get_transient( $this->job_key( $build_id ) );

		return is_array( $job ) ? $job : null;
	}

	/** Store one build record with exact readback. */
	private function store_job( $job ) {
		return isset( $job['build_id'] )
			&& $this->store_transient_verified( $this->job_key( $job['build_id'] ), $job, self::BUILD_TTL );
	}

	/** Read immutable snapshot metadata, retained beyond payload expiry for 410. */
	private function read_snapshot_meta( $token ) {
		if ( 1 !== preg_match( '/\Asnap_[a-f0-9]{32}\z/D', (string) $token ) ) {
			return null;
		}
		$meta = get_transient( $this->meta_key( $token ) );

		return is_array( $meta ) ? $meta : null;
	}

	/** Atomically claim an idempotency key before any job or build side effect. */
	private function claim_idempotency( $request_id, $fingerprint, $build_id ) {
		$key    = $this->idempotency_key( $request_id );
		$record = array(
			'fingerprint' => $fingerprint,
			'build_id'    => $build_id,
			'expires_at'  => time() + self::BUILD_TTL,
		);
		if ( add_option( $key, $record, '', false ) ) {
			$this->schedule_idempotency_cleanup( $request_id, $record );
			return array(
				'claimed' => true,
				'record'  => $record,
			);
		}

		$current = get_option( $key, null );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= time() ) {
			delete_option( $key );
			if ( add_option( $key, $record, '', false ) ) {
				$this->schedule_idempotency_cleanup( $request_id, $record );
				return array(
					'claimed' => true,
					'record'  => $record,
				);
			}
			$current = get_option( $key, null );
		}
		if ( ! is_array( $current ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_idempotency_unavailable',
				'The snapshot idempotency reservation is unavailable.',
				503,
				true,
				array(),
				self::RETRY_AFTER
			);
		}
		if ( ! hash_equals( (string) ( $current['fingerprint'] ?? '' ), $fingerprint ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_idempotency_conflict',
				'This Idempotency-Key was already used for a different snapshot request.',
				409,
				false
			);
		}

		return array(
			'claimed' => false,
			'record'  => $current,
		);
	}

	/** Schedule bounded cleanup for one persistent atomic idempotency claim. */
	private function schedule_idempotency_cleanup( $request_id, $record ) {
		$args = array(
			(string) $request_id,
			(string) ( $record['fingerprint'] ?? '' ),
			(int) ( $record['expires_at'] ?? 0 ),
		);
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $args[2], self::CLEANUP_HOOK, $args, self::ACTION_GROUP, true );
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $args[2], self::CLEANUP_HOOK, $args );
		}
	}

	/** Move a caller-owned idempotency claim to a coalesced leader build. */
	private function move_idempotency( $request_id, $fingerprint, $owned_build_id, $leader_build_id ) {
		$key     = $this->idempotency_key( $request_id );
		$current = get_option( $key, null );
		if (
			! is_array( $current )
			|| ! hash_equals( (string) ( $current['fingerprint'] ?? '' ), $fingerprint )
			|| ! hash_equals( (string) ( $current['build_id'] ?? '' ), $owned_build_id )
		) {
			return false;
		}
		$current['build_id'] = $leader_build_id;

		return ( update_option( $key, $current, false ) || get_option( $key, null ) === $current );
	}

	/** Release only the idempotency reservation still owned by this start. */
	private function release_idempotency( $request_id, $build_id ) {
		$key     = $this->idempotency_key( $request_id );
		$current = get_option( $key, null );
		if ( is_array( $current ) && hash_equals( (string) ( $current['build_id'] ?? '' ), (string) $build_id ) ) {
			delete_option( $key );
		}
	}

	/** Store a transient and require exact immediate readback. */
	private function store_transient_verified( $key, $value, $ttl ) {
		$stored = set_transient( $key, $value, $ttl );

		return ( $stored || get_transient( $key ) === $value ) && get_transient( $key ) === $value;
	}

	/** Store a non-autoloaded durable option and require exact readback. */
	private function store_option_verified( $key, $value ) {
		$stored = update_option( $key, $value, false );

		return ( $stored || get_option( $key, null ) === $value ) && get_option( $key, null ) === $value;
	}

	/** Delete only one request-local job record. */
	private function delete_job( $build_id ) {
		$job = $this->read_job( $build_id );
		if ( is_array( $job ) ) {
			$this->unschedule_build_watchdog( $job );
			$this->unschedule_build_activation( $job );
		} else {
			$this->unschedule_build_activation( array( 'build_id' => (string) $build_id ) );
		}
		delete_transient( $this->job_key( $build_id ) );
	}

	/** Delete only the pages staged by the current failed build. */
	private function delete_snapshot_pages( $token, $pages ) {
		foreach ( $pages as $page ) {
			delete_transient( $this->page_key( $token, $page ) );
		}
	}

	/** Return the transient key for one build job. */
	private function job_key( $build_id ) {
		return 'digitalogic_pricing_snapshot_job_' . hash( 'sha256', (string) $build_id );
	}

	/** Return the transient key for immutable snapshot metadata. */
	private function meta_key( $token ) {
		return 'digitalogic_pricing_snapshot_meta_' . hash( 'sha256', (string) $token );
	}

	/** Return the transient key for one immutable snapshot page. */
	private function page_key( $token, $page ) {
		return 'digitalogic_pricing_snapshot_page_' . hash( 'sha256', (string) $token . ':' . (int) $page );
	}

	/** Return the option key for one cancellation tombstone. */
	private function cancel_key( $build_id ) {
		return 'digitalogic_pricing_snapshot_cancel_' . hash( 'sha256', (string) $build_id );
	}

	/** Return the option key for one worker lease. */
	private function worker_key( $build_id ) {
		return 'digitalogic_pricing_snapshot_worker_' . hash( 'sha256', (string) $build_id );
	}

	/** Return the transient key for one reusable ready snapshot. */
	private function ready_key( $state_revision, $locale, $page_size ) {
		return 'digitalogic_pricing_snapshot_ready_' . hash( 'sha256', $state_revision . ':' . $locale . ':' . (int) $page_size );
	}

	/** Return the option key for one idempotency claim. */
	private function idempotency_key( $request_id ) {
		return 'digitalogic_pricing_snapshot_idempotency_' . hash( 'sha256', (string) $request_id );
	}

	/** Return the stable single-flight key for one exact build input. */
	private function build_key( $state_revision, $locale, $page_size ) {
		return $this->digest(
			array(
				'state_revision' => $state_revision,
				'locale'         => $locale,
				'page_size'      => (int) $page_size,
			)
		);
	}

	/** Return a strong quoted ETag. */
	private function etag( $revision ) {
		return '"' . $revision . '"';
	}

	/** Match a bounded If-None-Match list, including weak validators and *. */
	private function etag_matches( $header, $etag ) {
		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return false;
		}
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '*' === $candidate || $etag === $candidate || 'W/' . $etag === $candidate ) {
				return true;
			}
		}

		return false;
	}

	/** Return a canonical sha256 revision. */
	private function digest( $value ) {
		return 'sha256:' . hash(
			'sha256',
			wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/** Return a bounded nonsecret random identifier. */
	private function token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $error ) {
			return substr( hash( 'sha256', wp_generate_uuid4() . ':' . uniqid( '', true ) ), 0, 32 );
		}
	}

	/** Validate a sha256 revision. */
	private function is_revision( $value ) {
		return is_string( $value ) && 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $value );
	}

	/** Wrap data with transport-only status and response headers. */
	private function transport( $data, $status, $headers = array() ) {
		return array(
			'__transport' => array(
				'status'  => (int) $status,
				'headers' => $headers,
			),
			'data'        => $data,
		);
	}

	/** Create a stable machine-readable error. */
	private function error( $code, $message, $status, $retryable, $details = array(), $retry_after = null ) {
		$data = array(
			'status'          => (int) $status,
			'severity'        => 'error',
			'blocking'        => ! $retryable,
			'reason'          => (string) $message,
			'retryable'       => (bool) $retryable,
			'recovery_action' => $retryable ? 'retry_after_delay' : 'refresh_or_review_input',
			'details'         => is_array( $details ) ? $details : array(),
		);
		if ( null !== $retry_after ) {
			$data['retry_after'] = max( 1, (int) $retry_after );
		}

		return new WP_Error( $code, $message, $data );
	}
}
