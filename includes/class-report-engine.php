<?php
/**
 * Bounded current-state Patris report for Digitalogic.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compare the living transformed receiver state with WooCommerce.
 */
final class Digitalogic_Report_Engine {

	private const MAX_SOURCE_PRODUCTS     = 10000;
	private const MAX_WOO_PRODUCTS        = 10000;
	private const WOO_BATCH_SIZE          = 100;
	private const MAX_PAGE_SIZE           = 100;
	private const CACHE_GROUP             = 'digitalogic_reports';
	private const CACHE_TTL               = 300;
	private const CACHE_GENERATION_KEY    = 'generation';
	private const CACHE_GENERATION_OPTION = 'digitalogic_report_cache_generation';
	private const CACHE_EFFECTS_OPTION    = 'digitalogic_report_cache_effects';
	private const CACHE_GENERATION_LOCK   = 'digitalogic_report_cache_generation_lock';
	private const MAX_EFFECT_RECEIPTS     = 100;
	private const EFFECT_RETRY_HOOK       = 'digitalogic_report_effect_invalidation_retry';
	private const BUILD_LOCK_TTL          = 180;

	/**
	 * Shared report engine.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Token and key for the request-owned atomic build lock.
	 *
	 * @var string
	 */
	private $build_lock_token       = '';
	private $active_build_lock_key  = '';
	private $local_cache_generation = 'initial';

	/** @var array<string,array{object_id:int,meta_key:string,generation:string}> Request-local exact-effect probes. */
	private $product_meta_invalidation_probes = array();

	/** Register every source mutation that can make a report stale. */
	private function __construct() {
		$generation                   = get_option( self::CACHE_GENERATION_OPTION, null );
		$this->local_cache_generation = is_string( $generation ) && '' !== $generation ? $generation : '';

		add_action( 'admin_init', array( $this, 'install_cache_generation' ) );
		add_action( 'save_post_product', array( $this, 'invalidate_cache' ) );
		add_action( 'save_post_product_variation', array( $this, 'invalidate_cache' ) );
		add_action( 'save_post_attachment', array( $this, 'invalidate_cache' ) );
		add_action( 'edit_attachment', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_attachment', array( $this, 'invalidate_cache' ) );
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_cache' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'invalidate_cache' ) );
		add_action( 'before_delete_post', array( $this, 'invalidate_cache_for_deleted_product' ) );
		add_action( 'set_object_terms', array( $this, 'invalidate_cache_for_product_terms' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'invalidate_cache_for_product_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_cache_for_product_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_cache_for_product_meta' ), 10, 3 );
		add_action( 'created_product_cat', array( $this, 'invalidate_cache' ) );
		add_action( 'edited_product_cat', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_product_cat', array( $this, 'invalidate_cache' ) );
		add_action( 'created_pa_model', array( $this, 'invalidate_cache' ) );
		add_action( 'edited_pa_model', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_pa_model', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_product_updated', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_product_sync_applied', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_patris_feed_synced', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_woocommerce_currency_changed', array( $this, 'invalidate_cache' ) );
		add_action( 'updated_option', array( $this, 'invalidate_cache_for_option' ), 10, 3 );
		add_action( 'added_option', array( $this, 'invalidate_cache_for_added_option' ), 10, 2 );
		add_action( 'deleted_option', array( $this, 'invalidate_cache_for_deleted_option' ) );
		add_action( self::EFFECT_RETRY_HOOK, array( $this, 'retry_effect_invalidation' ) );
	}

	/**
	 * Return the shared report engine.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Install the persistent generation outside read-only REST requests.
	 *
	 * Activation and admin migration paths call this method. Revision GET/HEAD
	 * never creates the option and fails closed until installation succeeds.
	 *
	 * @return bool Whether a persistent nonempty generation was verified.
	 */
	public function install_cache_generation() {
		$generation = get_option( self::CACHE_GENERATION_OPTION, null );
		if ( is_string( $generation ) && '' !== $generation ) {
			$this->local_cache_generation = $generation;
			return true;
		}

		$candidate = $this->new_cache_token();
		add_option( self::CACHE_GENERATION_OPTION, $candidate, '', false );
		$generation = get_option( self::CACHE_GENERATION_OPTION, null );
		if ( is_string( $generation ) && '' !== $generation ) {
			$this->local_cache_generation = $generation;
			return true;
		}

		return false;
	}

	/** Read the persistent projection generation without an option-cache layer. */
	public function current_projection_generation() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return new WP_Error(
				'digitalogic_report_generation_unavailable',
				__( 'The report projection generation is unavailable.', 'digitalogic' ),
				array( 'status' => 503 )
			);
		}
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table name cannot be a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_report_generation_readback */ SELECT option_value FROM {$options} WHERE option_name = %s LIMIT 1",
			self::CACHE_GENERATION_OPTION
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact post-write generation readback is required.
		$row = false === $query ? null : $wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return new WP_Error(
				'digitalogic_report_generation_unavailable',
				__( 'The report projection generation is unavailable.', 'digitalogic' ),
				array( 'status' => 503 )
			);
		}
		$generation = maybe_unserialize( $row['option_value'] );
		if ( ! is_string( $generation ) || '' === $generation ) {
			return new WP_Error(
				'digitalogic_report_generation_unavailable',
				__( 'The report projection generation is unavailable.', 'digitalogic' ),
				array( 'status' => 503 )
			);
		}

		return $generation;
	}

	/** Ensure one product mutation advanced the persistent projection generation. */
	public function ensure_projection_invalidated( $previous_generation ) {
		$current = $this->current_projection_generation();
		if ( ! is_wp_error( $current ) && ! hash_equals( (string) $previous_generation, $current ) ) {
			return $current;
		}
		if ( ! $this->invalidate_cache() ) {
			return new WP_Error(
				'digitalogic_report_invalidation_unavailable',
				__( 'The report projection could not be invalidated.', 'digitalogic' ),
				array( 'status' => 503 )
			);
		}
		$current = $this->current_projection_generation();
		if ( is_wp_error( $current ) || hash_equals( (string) $previous_generation, (string) $current ) ) {
			return new WP_Error(
				'digitalogic_report_invalidation_unavailable',
				__( 'The report projection invalidation did not pass exact readback.', 'digitalogic' ),
				array( 'status' => 503 )
			);
		}

		return $current;
	}

	/**
	 * Start one request-local probe for an exact product-meta invalidation.
	 *
	 * The opaque token is safe to persist in an in-progress recovery record. A
	 * later request will intentionally find no live probe and must perform its
	 * own explicit recovery invalidation.
	 */
	public function begin_product_meta_invalidation_probe( $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		$meta_key  = (string) $meta_key;
		if ( $object_id <= 0 || '' === $meta_key ) {
			return new WP_Error(
				'digitalogic_report_invalidation_probe_invalid',
				__( 'The report invalidation probe is invalid.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}
		$token = function_exists( 'wp_generate_uuid4' )
			? wp_generate_uuid4()
			: hash( 'sha256', $object_id . '|' . $meta_key . '|' . microtime( true ) . '|' . wp_rand() );
		$this->product_meta_invalidation_probes[ $token ] = array(
			'object_id'  => $object_id,
			'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Probe payload field, not a query argument.
			'generation' => '',
		);

		return $token;
	}

	/** Consume one probe and distinguish a live failed effect from a later request. */
	public function consume_product_meta_invalidation_probe( $token ) {
		$token = (string) $token;
		if ( '' === $token || ! array_key_exists( $token, $this->product_meta_invalidation_probes ) ) {
			return array(
				'known'      => false,
				'generation' => '',
			);
		}
		$probe = $this->product_meta_invalidation_probes[ $token ];
		unset( $this->product_meta_invalidation_probes[ $token ] );

		return array(
			'known'      => true,
			'generation' => (string) ( $probe['generation'] ?? '' ),
		);
	}

	/**
	 * Return a cheap revision for every input that can change report output.
	 *
	 * This is an invalidation revision, not the full dataset digest. It lets a
	 * client decide whether a cached immutable snapshot may still be reused
	 * without rebuilding the WooCommerce/Patris union.
	 *
	 * @param string $source_id Exact source ID.
	 * @param string $dataset   Exact source dataset.
	 * @return string
	 */
	public function projection_revision( $source_id, $dataset ) {
		$source_id = (string) $source_id;
		$dataset   = (string) $dataset;
		if ( ( '' === $source_id ) !== ( '' === $dataset ) ) {
			return new WP_Error(
				'digitalogic_report_projection_scope_incomplete',
				__( 'The report projection source ID and dataset must be supplied together.', 'digitalogic' ),
				array(
					'status'    => 400,
					'retryable' => false,
				)
			);
		}
		if ( '' === $source_id ) {
			$selection = $this->select_source_state( $this->normalize_args( array( 'view' => 'price_list' ) ) );
			$source    = is_array( $selection['source'] ?? null ) ? $selection['source'] : array();
			$source_id = (string) ( $source['id'] ?? '' );
			$dataset   = (string) ( $source['dataset'] ?? '' );
			if ( '' === $source_id || '' === $dataset ) {
				return new WP_Error(
					'digitalogic_report_projection_source_unavailable',
					__( 'The current report projection source is unavailable.', 'digitalogic' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				);
			}
		}

		$generation = $this->cache_generation();
		if ( '' === $generation ) {
			return new WP_Error(
				'digitalogic_report_generation_uninitialized',
				__( 'The report projection generation has not been installed.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => false,
				)
			);
		}

		return 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'schema'             => 'digitalogic.report-projection-generation',
					'generation'         => $generation,
					'source_id'          => (string) $source_id,
					'dataset'            => (string) $dataset,
					'freshness_revision' => $this->source_freshness_revision( $source_id, $dataset ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Build one bounded page of the current report.
	 *
	 * Supported arguments are view, category, page, per_page, source_id and
	 * dataset. Unknown input is ignored. The source object in each row is kept
	 * sparse: an absent source key stays absent and an explicit source null stays
	 * null.
	 *
	 * @param array $args Untrusted transport arguments.
	 * @return array|WP_Error
	 */
	public function get_report( $args = array() ) {
		$raw_args      = is_array( $args ) ? $args : array();
		$force_refresh = $this->is_truthy( $raw_args['force_refresh'] ?? false );
		$args          = $this->normalize_args( $raw_args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		return $this->get_normalized_report( $args, $force_refresh );
	}

	/**
	 * Build the complete report projection once for an immutable snapshot.
	 *
	 * This internal service surface intentionally bypasses public page-size
	 * limits. Callers must persist and page the result instead of rebuilding the
	 * WooCommerce/Patris union for every transport page.
	 *
	 * @param array         $args       Trusted report arguments.
	 * @param callable|null $checkpoint Optional cancellation/progress checkpoint.
	 * @return array|WP_Error
	 */
	public function get_complete_report( $args = array(), $checkpoint = null ) {
		$raw_args      = is_array( $args ) ? $args : array();
		$force_refresh = $this->is_truthy( $raw_args['force_refresh'] ?? false );
		$args          = $this->normalize_args( $raw_args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$args['complete'] = true;
		$args['page']     = 1;

		return $this->get_normalized_report( $args, $force_refresh, $checkpoint );
	}

	/**
	 * Read or atomically build one normalized report shape.
	 *
	 * @param array         $args          Normalized report arguments.
	 * @param bool          $force_refresh Whether to bypass a cached value.
	 * @param callable|null $checkpoint    Optional cancellation/progress checkpoint.
	 * @return array|WP_Error
	 */
	private function get_normalized_report( $args, $force_refresh, $checkpoint = null ) {
		$cached_report = $this->get_cached_report( $args );
		$report        = $force_refresh ? null : $cached_report;
		if ( is_array( $report ) ) {
			return $report;
		}

		if ( ! $this->acquire_build_lock( $args ) ) {
			if ( is_array( $cached_report ) ) {
				$cached_report['refresh_deferred'] = true;
				return $cached_report;
			}

			return new WP_Error(
				'digitalogic_report_build_in_progress',
				__( 'Another report build is already running. Please retry shortly.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 2,
					'retryable'   => true,
				)
			);
		}

		try {
			// A previous request may have populated this exact page while this
			// request was waiting for the atomic lock.
			$report = $force_refresh ? null : $this->get_cached_report( $args );
			if ( ! is_array( $report ) ) {
				if ( is_callable( $checkpoint ) && false === call_user_func( $checkpoint, 'reconciling', 10, 0, 0 ) ) {
					return $this->snapshot_cancelled_error();
				}
				$build_generation = $this->cache_generation();
				$build_freshness  = $this->cache_freshness_revision( $args );
				$selection        = $this->select_source_state( $args );
				$report           = $this->build_report( $args, $selection, $checkpoint );
				if ( is_wp_error( $report ) ) {
					return $report;
				}
				if ( ! $this->set_cached_report( $args, $report, $build_generation, $build_freshness ) ) {
					return new WP_Error(
						'digitalogic_report_source_changed',
						__( 'Report source data changed while the report was being built. Please retry.', 'digitalogic' ),
						array(
							'status'      => 503,
							'retry_after' => 1,
							'retryable'   => true,
						)
					);
				}
			}
		} finally {
			$this->release_build_lock();
		}

		return $report;
	}

	/**
	 * Return one exact product from the same current source selection used by reports.
	 *
	 * This read-only helper lets mutation adapters prove that a uniquely resolved
	 * Woo leaf is still a current matched row before accepting a write.
	 *
	 * @param string $product_code Exact Product Code, including leading zeros.
	 * @return array|WP_Error
	 */
	public function get_current_source_product( $product_code ) {
		if ( ! is_string( $product_code ) || '' === $product_code ) {
			return new WP_Error(
				'digitalogic_report_product_code_invalid',
				__( 'An exact Product Code is required.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}

		$args      = $this->normalize_args( array( 'view' => 'price_list' ) );
		$selection = $this->select_source_state( $args );
		if ( ! in_array( $selection['status'], array( 'current', 'static' ), true ) ) {
			return new WP_Error(
				'digitalogic_report_source_unavailable',
				__( 'The current source state is unavailable.', 'digitalogic' ),
				array( 'status' => 409 )
			);
		}

		$products = is_array( $selection['state']['products'] ?? null )
			? $selection['state']['products']
			: array();
		$product  = $products[ $product_code ] ?? null;
		if (
			! is_array( $product )
			|| ! is_string( $product['product_code'] ?? null )
			|| $product_code !== $product['product_code']
		) {
			return new WP_Error(
				'digitalogic_report_product_not_in_current_source',
				__( 'The Product Code is not present in the current source state.', 'digitalogic' ),
				array( 'status' => 409 )
			);
		}

		return array(
			'source'  => $selection['source'],
			'product' => $product,
		);
	}

	/**
	 * Compare one already-validated static envelope without mutating receiver state.
	 *
	 * The receiver owns validation. This method only projects its canonical,
	 * sparse result into the same report engine used by the persisted living
	 * state.
	 *
	 * @param array $envelope Validated canonical envelope.
	 * @param array $args Untrusted transport arguments.
	 * @return array|WP_Error
	 */
	public function get_report_from_validated_envelope( $envelope, $args = array() ) {
		if (
			! is_array( $envelope )
			|| ! is_array( $envelope['source'] ?? null )
			|| ! is_array( $envelope['products'] ?? null )
			|| ! array_is_list( $envelope['products'] )
		) {
			return new WP_Error( 'digitalogic_report_static_envelope_invalid', __( 'The static product snapshot is not a validated canonical envelope.', 'digitalogic' ) );
		}

		$source_id = $envelope['source']['id'] ?? null;
		$dataset   = $envelope['source']['dataset'] ?? null;
		if ( ! is_string( $source_id ) || '' === $source_id || ! is_string( $dataset ) || '' === $dataset ) {
			return new WP_Error( 'digitalogic_report_static_source_invalid', __( 'The static product snapshot has no valid source scope.', 'digitalogic' ) );
		}

		$products = array();
		foreach ( $envelope['products'] as $product ) {
			$code = is_array( $product ) ? ( $product['product_code'] ?? null ) : null;
			if ( ! is_string( $code ) || '' === $code ) {
				return new WP_Error( 'digitalogic_report_static_product_invalid', __( 'The static product snapshot contains an invalid product.', 'digitalogic' ) );
			}
			if ( isset( $products[ $code ] ) ) {
				return new WP_Error(
					'digitalogic_report_static_duplicate_product_code',
					__( 'The static product snapshot contains a duplicate Product Code.', 'digitalogic' ),
					array(
						'status'       => 409,
						'product_code' => $code,
					)
				);
			}
			$products[ $code ] = $product;
		}
		ksort( $products, SORT_STRING );

		$state  = array(
			'source'          => $envelope['source'],
			'generated_at'    => $envelope['generated_at'] ?? '',
			'last_event_id'   => $envelope['event_id'] ?? '',
			'last_event_type' => $envelope['event_type'] ?? '',
			'products'        => $products,
		);
		$source = array(
			'id'      => $source_id,
			'dataset' => $dataset,
		);
		if ( array_key_exists( 'revision', $envelope['source'] ) ) {
			$source['revision'] = $envelope['source']['revision'];
		}
		foreach ( array( 'generated_at', 'event_id', 'event_type' ) as $field ) {
			if ( array_key_exists( $field, $envelope ) ) {
				$output_field            = 'event_id' === $field ? 'last_event_id' : ( 'event_type' === $field ? 'last_event_type' : $field );
				$source[ $output_field ] = $envelope[ $field ];
			}
		}

		$args = $this->normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		return $this->build_report(
			$args,
			array(
				'status' => 'static',
				'state'  => $state,
				'source' => $source,
			)
		);
	}

	/**
	 * Build a bounded report from one selected canonical source.
	 *
	 * @param array         $args Normalized report arguments.
	 * @param array         $selection Selected source state.
	 * @param callable|null $checkpoint Optional cancellation/progress checkpoint.
	 * @return array|WP_Error
	 */
	private function build_report( $args, $selection, $checkpoint = null ) {
		$state       = $selection['state'];
		$source      = $selection['source'];
		$products    = is_array( $state['products'] ?? null ) ? $state['products'] : array();
		$truncated   = count( $products ) > self::MAX_SOURCE_PRODUCTS;
		$products    = array_slice( $products, 0, self::MAX_SOURCE_PRODUCTS, true );
		$settings    = Digitalogic_Patris_Feed::instance()->get_settings();
		$stale_hours = max( 1, absint( $settings['stale_after_hours'] ?? 48 ) );
		$woo_result  = $this->get_woocommerce_products( $checkpoint );
		if ( is_wp_error( $woo_result ) ) {
			return $woo_result;
		}
		$woo_rows             = $woo_result['products'];
		$provider_identities  = $this->provider_identity_diagnostics( $products, $woo_rows );
		$woo_result['integrity_warnings'] = array_values(
			array_merge( $woo_result['integrity_warnings'], $provider_identities['integrity_warnings'] )
		);
		if ( is_callable( $checkpoint ) && false === call_user_func( $checkpoint, 'reconciling', 25, 0, count( $products ) + count( $woo_rows ) ) ) {
			return $this->snapshot_cancelled_error();
		}
		$snapshot_revision = 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'generation'  => $this->cache_generation(),
					'source'      => $source,
					'products'    => $products,
					'woocommerce' => $woo_rows,
					'integrity'   => $woo_result['integrity_warnings'],
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
		$woo_by_code       = array();

		foreach ( $woo_rows as $woo ) {
			if ( ! isset( $woo['product_code'] ) || '' === $woo['product_code'] ) {
				continue;
			}
			$woo_by_code[ $woo['product_code'] ][] = $woo;
		}

		$rows              = array();
		$matched           = 0;
		$source_only       = 0;
		$positive_only     = 0;
		$ambiguous         = 0;
		$matched_woo_ids   = array();
		$source_code_index = array();

		$source_available = in_array( $selection['status'], array( 'current', 'static' ), true );
		$source_processed = 0;
		foreach ( $source_available ? $products : array() as $key => $product ) {
			++$source_processed;
			if (
				0 === $source_processed % 250
				&& is_callable( $checkpoint )
				&& false === call_user_func( $checkpoint, 'reconciling', 30, $source_processed, count( $products ) + count( $woo_rows ) )
			) {
				return $this->snapshot_cancelled_error();
			}
			if ( ! is_array( $product ) || ! is_string( $product['product_code'] ?? null ) ) {
				continue;
			}
			$code = $product['product_code'];
			if ( '' === $code || (string) $key !== $code ) {
				continue;
			}
			$source_code_index[ $code ] = true;
			$matches                    = $woo_by_code[ $code ] ?? array();

			if ( empty( $matches ) ) {
				++$source_only;
				$row = $this->source_row( $product, 'source_only' );
				$this->add_issue( $row, 'missing_in_woocommerce' );
				if (
					array_key_exists( 'total_stock', $product )
					&& null !== $product['total_stock']
					&& is_numeric( $product['total_stock'] )
					&& $this->decimal_compare_zero( $product['total_stock'] ) > 0
				) {
					++$positive_only;
					$this->add_issue( $row, 'positive_stock_missing_in_woocommerce' );
				}
				$this->append_source_issues( $row, $product, $stale_hours );
				$rows[] = $row;
				continue;
			}

			if ( 1 === count( $matches ) ) {
				++$matched;
				$woo                           = reset( $matches );
				$matched_woo_ids[ $woo['id'] ] = true;
				$row                           = $this->source_row( $product, 'matched', $woo );
				$this->append_source_issues( $row, $product, $stale_hours );
				$this->append_drift_issues( $row, $product, $woo );
				$rows[] = $row;
				continue;
			}

			++$ambiguous;
			foreach ( $matches as $woo ) {
				$matched_woo_ids[ $woo['id'] ] = true;
				$row                           = $this->source_row( $product, 'ambiguous', $woo );
				$this->add_issue( $row, 'duplicate_product_code' );
				$this->append_source_issues( $row, $product, $stale_hours );
				$rows[] = $row;
			}
		}

		$woo_only      = 0;
		$woo_processed = 0;
		foreach ( $source_available ? $woo_rows : array() as $woo ) {
			++$woo_processed;
			if (
				0 === $woo_processed % 250
				&& is_callable( $checkpoint )
				&& false === call_user_func( $checkpoint, 'reconciling', 40, count( $products ) + $woo_processed, count( $products ) + count( $woo_rows ) )
			) {
				return $this->snapshot_cancelled_error();
			}
			if ( isset( $matched_woo_ids[ $woo['id'] ] ) ) {
				continue;
			}

			++$woo_only;
			$row = $this->woo_row( $woo );
			$this->add_issue( $row, 'missing_in_patris' );
			if ( ! isset( $woo['product_code'] ) || '' === $woo['product_code'] ) {
				$this->add_issue( $row, 'missing_product_code' );
			} elseif ( isset( $source_code_index[ $woo['product_code'] ] ) ) {
				// A duplicate exact Code is already represented by its source rows.
				$this->add_issue( $row, 'duplicate_product_code' );
			}
			$rows[] = $row;
		}

		foreach ( $rows as &$identity_row ) {
			$source_code = is_scalar( $identity_row['source']['product_code'] ?? null )
				? (string) $identity_row['source']['product_code']
				: '';
			$woo_id      = absint( $identity_row['woocommerce']['id'] ?? 0 );
			foreach ( (array) ( $provider_identities['source_issues'][ $source_code ] ?? array() ) as $identity_issue ) {
				$this->add_issue( $identity_row, $identity_issue );
			}
			foreach ( (array) ( $provider_identities['woo_issues'][ $woo_id ] ?? array() ) as $identity_issue ) {
				$this->add_issue( $identity_row, $identity_issue );
			}
		}
		unset( $identity_row );

		$identity_reconciliation          = Digitalogic_Catalog_Identity_Reconciler::instance()->annotate_rows( $rows );
		$rows                             = $identity_reconciliation['rows'];
		$woo_result['integrity_warnings'] = array_values(
			array_merge( $woo_result['integrity_warnings'], $identity_reconciliation['integrity_warnings'] )
		);

		usort(
			$rows,
			static function ( $left, $right ) {
				$code = strcmp( (string) $left['product_code'], (string) $right['product_code'] );
				return 0 !== $code ? $code : (int) ( $left['woo_id'] ?? 0 ) <=> (int) ( $right['woo_id'] ?? 0 );
			}
		);
		if ( is_callable( $checkpoint ) && false === call_user_func( $checkpoint, 'reconciling', 50, count( $rows ), count( $rows ) ) ) {
			return $this->snapshot_cancelled_error();
		}

		$definitions = $this->category_definitions();
		$categories  = array();
		foreach ( $definitions as $key => $definition ) {
			$categories[ $key ] = array(
				'key'      => $key,
				'title'    => $definition[0],
				'severity' => $definition[1],
				'count'    => 0,
				'items'    => array(),
			);
		}

		$warning_products = 0;
		$drift_products   = 0;
		foreach ( $rows as $row ) {
			if ( ! empty( $row['issues'] ) ) {
				++$warning_products;
			}
			$has_drift = false;
			foreach ( $row['issues'] as $issue ) {
				if ( isset( $categories[ $issue ] ) ) {
					++$categories[ $issue ]['count'];
				}
				if ( str_ends_with( $issue, '_drift' ) ) {
					$has_drift = true;
				}
			}
			if ( $has_drift ) {
				++$drift_products;
			}
		}

		$selected_rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $args ) {
					if ( 'warnings' === $args['view'] && empty( $row['issues'] ) ) {
						return false;
					}
					return '' === $args['category'] || in_array( $args['category'], $row['issues'], true );
				}
			)
		);
		$total         = count( $selected_rows );
		$complete      = ! empty( $args['complete'] );
		$per_page      = $complete ? max( 1, $total ) : $args['per_page'];
		$pages         = $complete ? 1 : max( 1, (int) ceil( $total / $per_page ) );
		$page          = $complete ? 1 : min( $args['page'], $pages );
		$page_rows     = $complete
			? $selected_rows
			: array_slice( $selected_rows, ( $page - 1 ) * $per_page, $per_page );

		foreach ( $page_rows as $row ) {
			foreach ( $row['issues'] as $issue ) {
				if ( isset( $categories[ $issue ] ) ) {
					$categories[ $issue ]['items'][] = $row;
				}
			}
		}

		$report = array(
			'generated_at'      => current_time( 'mysql' ),
			'snapshot_revision' => $snapshot_revision,
			'status'            => $selection['status'],
			'brand'             => array(
				'en' => 'Digitalogic',
				'fa' => 'دیجیتالاجیک',
			),
			'view'              => $args['view'],
			'counts'            => array(
				'woocommerce_products_raw'      => count( $woo_rows ) + $woo_result['variable_parents_excluded'],
				'woocommerce_products'          => count( $woo_rows ),
				'patris_products'               => count( $products ),
				'matched_products'              => $matched,
				'source_only_products'          => $source_only,
				'positive_source_only_products' => $positive_only,
				'woocommerce_only_products'     => $woo_only,
				'ambiguous_codes'               => $ambiguous,
				'warning_products'              => $warning_products,
				'drift_products'                => $drift_products,
				'variable_parents_excluded'     => $woo_result['variable_parents_excluded'],
				'quarantined_identity_groups'   => $identity_reconciliation['counts']['quarantined_identity_groups'],
				'quarantined_source_rows'       => $identity_reconciliation['counts']['quarantined_source_rows'],
				'quarantined_woo_rows'          => $identity_reconciliation['counts']['quarantined_woo_rows'],
				'one_to_one_split_candidates'   => $identity_reconciliation['counts']['one_to_one_split_candidates'],
				'identity_collision_groups'     => $identity_reconciliation['counts']['identity_collision_groups'],
				'source_code_collision_groups'  => $provider_identities['counts']['source_code_collision_groups'],
				'woo_code_collision_groups'     => $provider_identities['counts']['woo_code_collision_groups'],
				'woo_sku_collision_groups'      => $provider_identities['counts']['woo_sku_collision_groups'],
				'unsafe_identity_groups'        => $identity_reconciliation['counts']['quarantined_identity_groups'] + $provider_identities['counts']['provider_identity_collision_groups'],
			),
			'pagination'        => array(
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $total,
				'pages'    => $pages,
			),
			'limits'            => array(
				'max_source_products'      => self::MAX_SOURCE_PRODUCTS,
				'max_woocommerce_products' => self::MAX_WOO_PRODUCTS,
				'source_truncated'         => $truncated,
				'woocommerce_truncated'    => $woo_result['truncated'],
			),
			'filters'           => array(
				'category' => $args['category'],
			),
			'integrity'         => array(
				'status'   => empty( $woo_result['integrity_warnings'] ) ? 'current' : 'warning',
				'warnings' => $woo_result['integrity_warnings'],
			),
			'rows'              => $page_rows,
			'categories'        => array_values( $categories ),
		);

		if ( ! empty( $source ) ) {
			$report['source'] = $source;
		}

		return $report;
	}

	/** Return a stable cooperative-cancellation error for snapshot workers. */
	private function snapshot_cancelled_error() {
		return new WP_Error(
			'digitalogic_pricing_snapshot_cancelled',
			__( 'The pricing snapshot build was cancelled.', 'digitalogic' ),
			array(
				'status'    => 409,
				'retryable' => false,
			)
		);
	}

	/**
	 * Read one locale- and request-specific cached report.
	 *
	 * @param array $args Normalized report arguments.
	 * @return array|null
	 */
	private function get_cached_report( $args ) {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return null;
		}

		$found = false;
		try {
			$report = wp_cache_get( $this->cache_key( $args ), self::CACHE_GROUP, false, $found );
		} catch ( Throwable $error ) {
			return null;
		}
		if (
			! $found
			|| ! is_array( $report )
			|| ! isset( $report['_cache_generation'], $report['_cache_freshness_revision'] )
		) {
			return null;
		}

		$cached_generation = (string) $report['_cache_generation'];
		$cached_freshness  = (string) $report['_cache_freshness_revision'];
		unset( $report['_cache_generation'], $report['_cache_freshness_revision'] );
		if (
			! hash_equals( $this->cache_generation(), $cached_generation )
			|| ! hash_equals(
				$this->cache_freshness_revision( $args ),
				$cached_freshness
			)
		) {
			$this->delete_cached_report( $args );
			return null;
		}

		return $report;
	}

	/** Invalidate every request-shaped report without requiring a key registry. */
	public function invalidate_cache() {
		$result = $this->with_generation_lock(
			function () {
				$token = $this->new_cache_token();
				if ( ! $this->store_generation_token( $token ) ) {
					return false;
				}
				do_action( 'digitalogic_report_projection_invalidated', $token );

				return true;
			}
		);

		return true === $result;
	}

	/**
	 * Invalidate the projection exactly once for one already-committed effect.
	 *
	 * A durable pending receipt is written before the generation changes. A
	 * replay therefore either observes the same target generation, or notices
	 * that a newer unrelated invalidation already won and never writes an older
	 * token back over it.
	 *
	 * @param string $effect_id      Stable sha256 effect identity.
	 * @param bool   $schedule_retry Schedule one bounded recovery attempt on failure.
	 * @return array|WP_Error Verified receipt.
	 */
	public function invalidate_cache_for_effect( $effect_id, $schedule_retry = true ) {
		$effect_id = (string) $effect_id;
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $effect_id ) ) {
			return new WP_Error(
				'digitalogic_report_effect_identity_invalid',
				'شناسهٔ اثر تغییر گزارش معتبر نیست.',
				array( 'blocking' => true )
			);
		}

		$result = $this->with_generation_lock(
			function () use ( $effect_id ) {
				$receipts = get_option( self::CACHE_EFFECTS_OPTION, array() );
				$receipts = is_array( $receipts ) ? $receipts : array();
				$receipt  = is_array( $receipts[ $effect_id ] ?? null ) ? $receipts[ $effect_id ] : array();
				if ( in_array( (string) ( $receipt['status'] ?? '' ), array( 'complete', 'superseded' ), true ) ) {
					return $receipt;
				}

				$current = $this->cache_generation();
				if ( ! $receipt ) {
					$receipt = array(
						'effect_id'           => $effect_id,
						'status'              => 'pending',
						'previous_generation' => $current,
						'target_generation'   => 'sha256:' . hash( 'sha256', "report-effect\0" . $effect_id ),
						'created_at'          => time(),
						'completed_at'        => 0,
					);
					$receipts[ $effect_id ] = $receipt;
					if ( ! $this->store_effect_receipts( $receipts ) ) {
						return new WP_Error(
							'digitalogic_report_effect_receipt_store_failed',
							'ثبت رسید پایدار تغییر گزارش ممکن نشد.',
							array( 'blocking' => false, 'retry_after' => 2 )
						);
					}
				}

				$previous = (string) ( $receipt['previous_generation'] ?? '' );
				$target   = (string) ( $receipt['target_generation'] ?? '' );
				$current  = $this->cache_generation();
				if ( ! hash_equals( $target, $current ) ) {
					if ( ! hash_equals( $previous, $current ) ) {
						$receipt['status']       = 'superseded';
						$receipt['completed_at'] = time();
						$receipts[ $effect_id ]  = $receipt;
						if ( ! $this->store_effect_receipts( $receipts ) ) {
							return new WP_Error(
								'digitalogic_report_effect_receipt_store_failed',
								'ثبت پایان رسید تغییر گزارش ممکن نشد.',
								array( 'blocking' => false, 'retry_after' => 2 )
							);
						}

						return $receipt;
					}
					if ( ! $this->store_generation_token( $target ) ) {
						return new WP_Error(
							'digitalogic_report_effect_generation_store_failed',
							'ثبت generation گزارش ممکن نشد.',
							array( 'blocking' => false, 'retry_after' => 2 )
						);
					}
				}

				$receipt['status']       = 'complete';
				$receipt['completed_at'] = time();
				$receipts[ $effect_id ]  = $receipt;
				if ( ! $this->store_effect_receipts( $receipts ) ) {
					return new WP_Error(
						'digitalogic_report_effect_receipt_store_failed',
						'ثبت پایان رسید تغییر گزارش ممکن نشد.',
						array( 'blocking' => false, 'retry_after' => 2 )
					);
				}

				return $receipt;
			}
		);

		if ( false === $result ) {
			$result = new WP_Error(
				'digitalogic_report_generation_lock_busy',
				'تغییر دیگری در حال تازه‌سازی گزارش است.',
				array( 'blocking' => false, 'retry_after' => 2 )
			);
		}
		if ( is_wp_error( $result ) && $schedule_retry ) {
			$data                       = $result->get_error_data();
			$data                       = is_array( $data ) ? $data : array();
			$data['retry_scheduled']     = $this->schedule_effect_invalidation_retry( $effect_id );
			$data['retry_after']         = 2;
			$data['recovery_attempts']   = 1;
			$data['recovery_is_bounded'] = true;

			return new WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}

		return $result;
	}

	/** Execute the one bounded post-commit recovery attempt without recursion. */
	public function retry_effect_invalidation( $effect_id ) {
		$result = $this->invalidate_cache_for_effect( $effect_id, false );
		if ( is_wp_error( $result ) ) {
			do_action( 'digitalogic_report_effect_invalidation_failed', $effect_id, $result->get_error_code() );
		}

		return $result;
	}

	/** Persist at most one exact retry; no repeating loop can occupy WordPress. */
	private function schedule_effect_invalidation_retry( $effect_id ) {
		$args = array( (string) $effect_id );
		if (
			function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::EFFECT_RETRY_HOOK, $args, 'digitalogic-pricing' )
		) {
			return true;
		}
		if ( false !== wp_next_scheduled( self::EFFECT_RETRY_HOOK, $args ) ) {
			return true;
		}
		$timestamp = time() + 2;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (bool) as_schedule_single_action(
				$timestamp,
				self::EFFECT_RETRY_HOOK,
				$args,
				'digitalogic-pricing',
				true
			);
		}

		$scheduled = wp_schedule_single_event( $timestamp, self::EFFECT_RETRY_HOOK, $args, true );

		return ! is_wp_error( $scheduled ) && false !== $scheduled;
	}

	/** Invalidate reports when an option that feeds reconciliation changes. */
	public function invalidate_cache_for_option( $option, $old_value = null, $value = null ) {
		if ( $this->is_report_option( $option ) ) {
			$this->invalidate_cache();
		}
	}

	/** WordPress added_option callback adapter. */
	public function invalidate_cache_for_added_option( $option, $value = null ) {
		$this->invalidate_cache_for_option( $option );
	}

	/** WordPress deleted_option callback adapter. */
	public function invalidate_cache_for_deleted_option( $option ) {
		$this->invalidate_cache_for_option( $option );
	}

	/** Invalidate when a WooCommerce leaf or parent is about to be deleted. */
	public function invalidate_cache_for_deleted_product( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			$this->invalidate_cache();
		}
	}

	/** Invalidate taxonomy-only changes that alter product identity/projection. */
	public function invalidate_cache_for_product_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		unset( $terms, $tt_ids );
		if (
			'product_type' === (string) $taxonomy
			&& 'product' === get_post_type( $object_id )
		) {
			self::invalidate_product_type_caches( (int) $object_id );
		}
		if (
			in_array( (string) $taxonomy, array( 'product_type', 'product_cat', 'pa_model' ), true )
			&& in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true )
		) {
			$this->invalidate_cache();
		}
	}

	/**
	 * Clear every WooCommerce cache layer that can retain a stale product type.
	 *
	 * @param int           $product_id       Product ID.
	 * @param callable|null $instance_remover Optional fail-closed remover used by CLI tests.
	 * @return true|WP_Error
	 */
	public static function invalidate_product_type_caches( $product_id, $instance_remover = null ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || ! is_callable( array( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce product cache invalidation is unavailable.' );
		}

		if ( is_callable( $instance_remover ) ) {
			$removed = call_user_func( $instance_remover, $product_id );
			if ( is_wp_error( $removed ) || false === $removed ) {
				return is_wp_error( $removed )
					? $removed
					: new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce product-object cache invalidation failed.' );
			}
		} else {
			$class = 'Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
			if ( class_exists( $class ) && function_exists( 'wc_get_container' ) ) {
				try {
					$cache = wc_get_container()->get( $class );
					if ( ! is_object( $cache ) || ! is_callable( array( $cache, 'remove' ) ) ) {
						return new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce product-object cache invalidation is unavailable.' );
					}
					$cache->remove( $product_id );
				} catch ( Throwable $error ) {
					unset( $error );

					return new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce product-object cache invalidation failed.' );
				}
			}
		}

		if ( function_exists( 'clean_object_term_cache' ) ) {
			clean_object_term_cache( $product_id, 'product' );
		}
		WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );

		return true;
	}

	/** Invalidate direct assignment/meta writes consumed by Excel rows. */
	public function invalidate_cache_for_product_meta( $meta_id, $object_id, $meta_key ) {
		unset( $meta_id );
		$meta_key = (string) $meta_key;
		$consumed = array(
			'_sku',
			'_product_attributes',
			'_regular_price',
			'_price',
			'_sale_price',
			'_stock',
			'_manage_stock',
			'_stock_status',
			'_weight',
			'_thumbnail_id',
			'_digitalogic_shipping_method_id',
			'_digitalogic_markup',
			'_digitalogic_markup_type',
			'_digitalogic_patris_product_code',
			'_digitalogic_patris_name',
			'_digitalogic_patris_serial',
			'_digitalogic_patris_unit',
			'_digitalogic_patris_sale_price_source',
			'_digitalogic_patris_partner_price_source',
			'_digitalogic_patris_foreign_currency',
			'_digitalogic_patris_foreign_price',
			'_digitalogic_patris_price_source_amount',
			'_digitalogic_patris_price_source_currency',
			'_digitalogic_patris_price_source_kind',
			'_digitalogic_patris_weight_grams',
			'_digitalogic_patris_total_stock',
			'_digitalogic_patris_minimum_stock',
			'_digitalogic_patris_warehouse_stock',
			'_digitalogic_patris_location',
			'_digitalogic_patris_shipping_method_id',
			'_digitalogic_patris_shipping_price_per_kg',
			'_digitalogic_patris_shipping_price_per_kg_currency',
			'_digitalogic_patris_markup_percent',
			'_digitalogic_patris_irt_per_cny',
			'_digitalogic_patris_final_price',
			'_digitalogic_patris_price_rounding_digits',
			'_digitalogic_patris_price_rounding_mode',
			'_digitalogic_patris_price_status',
			'_digitalogic_patris_updated_at',
			'_digitalogic_patris_flags',
			'_digitalogic_patris_null_fields',
			'_digitalogic_patris_missing_fields',
			'_digitalogic_patris_record_hash',
			'attribute_pa_model',
			'_wp_attached_file',
			'_wp_attachment_metadata',
		);
		if (
			( in_array( $meta_key, $consumed, true ) || str_starts_with( $meta_key, 'attribute_' ) )
			&& in_array( get_post_type( $object_id ), array( 'product', 'product_variation', 'attachment' ), true )
		) {
			$invalidated = $this->invalidate_cache();
			if ( $invalidated ) {
				$generation = $this->current_projection_generation();
				if ( ! is_wp_error( $generation ) ) {
					foreach ( $this->product_meta_invalidation_probes as &$probe ) {
						if ( (int) $probe['object_id'] === (int) $object_id && hash_equals( (string) $probe['meta_key'], $meta_key ) ) {
							$probe['generation'] = $generation;
						}
					}
					unset( $probe );
				}
			}
		}
	}

	/** Return whether an option contributes to report output. */
	private function is_report_option( $option ) {
		return in_array(
			(string) $option,
			array(
				'digitalogic_product_sync_state',
				'digitalogic_patris_feed_settings',
				'digitalogic_patris_feed_products',
				'digitalogic_patris_feed_customers',
				'digitalogic_shipping_methods',
				'digitalogic_pricing_default_percentage_markup',
				'digitalogic_pricing_rounding_digits',
				'dollar_price',
				'yuan_price',
				'options_dollar_price',
				'options_yuan_price',
				'options_update_date',
				'update_date',
				Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION,
				'woocommerce_currency',
				'woocommerce_weight_unit',
				'home',
				'siteurl',
				'permalink_structure',
				'upload_path',
				'upload_url_path',
			),
			true
		);
	}

	/**
	 * Hash the exact source rows whose time-relative freshness warning is active.
	 *
	 * This bounded metadata pass avoids a WooCommerce/report rebuild while still
	 * changing the cheap composite revision at every stale-source transition.
	 */
	private function source_freshness_revision( $source_id, $dataset ) {
		$state       = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( (string) $source_id, (string) $dataset );
		$products    = is_array( $state['products'] ?? null ) ? $state['products'] : array();
		$settings    = Digitalogic_Patris_Feed::instance()->get_settings();
		$stale_hours = max( 1, absint( $settings['stale_after_hours'] ?? 48 ) );
		$stale_keys  = array();
		foreach ( $products as $key => $product ) {
			$updated_at = is_array( $product ) ? ( $product['source_updated_at'] ?? null ) : null;
			if ( $this->is_stale( $updated_at, $stale_hours ) ) {
				$stale_keys[] = (string) $key;
			}
		}
		sort( $stale_keys, SORT_STRING );

		return 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'stale_after_hours' => $stale_hours,
					'stale_keys'        => $stale_keys,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/** Resolve the exact source selected by a report shape and hash freshness. */
	private function cache_freshness_revision( $args ) {
		$source_id = (string) ( $args['source_id'] ?? '' );
		$dataset   = (string) ( $args['dataset'] ?? '' );
		if ( '' === $source_id && '' === $dataset ) {
			$selection = $this->select_source_state( $args );
			$source    = is_array( $selection['source'] ?? null ) ? $selection['source'] : array();
			$source_id = (string) ( $source['id'] ?? '' );
			$dataset   = (string) ( $source['dataset'] ?? '' );
		}

		return $this->source_freshness_revision( $source_id, $dataset );
	}

	/** Acquire the lock for this exact normalized report page. */
	private function acquire_build_lock( $args ) {
		$this->active_build_lock_key = $this->build_lock_key( $args );
		if ( ! function_exists( 'wp_cache_add' ) ) {
			$this->build_lock_token = 'request-local';
			return true;
		}

		$token = $this->new_cache_token();
		try {
			$acquired = wp_cache_add( $this->active_build_lock_key, $token, self::CACHE_GROUP, self::BUILD_LOCK_TTL );
		} catch ( Throwable $error ) {
			$this->build_lock_token = 'request-local';
			return true;
		}
		$this->build_lock_token = $acquired ? $token : '';

		return (bool) $acquired;
	}

	/** Release only the lock still owned by this request. */
	private function release_build_lock() {
		if ( 'request-local' === $this->build_lock_token ) {
			$this->build_lock_token      = '';
			$this->active_build_lock_key = '';
			return;
		}
		if (
			'' === $this->build_lock_token
			|| '' === $this->active_build_lock_key
			|| ! function_exists( 'wp_cache_get' )
			|| ! function_exists( 'wp_cache_delete' )
		) {
			$this->build_lock_token      = '';
			$this->active_build_lock_key = '';
			return;
		}

		$found = false;
		try {
			$current = wp_cache_get( $this->active_build_lock_key, self::CACHE_GROUP, false, $found );
			if ( $found && is_string( $current ) && hash_equals( $this->build_lock_token, $current ) ) {
				wp_cache_delete( $this->active_build_lock_key, self::CACHE_GROUP );
			}
		} catch ( Throwable $error ) {
			// The report itself remains valid if the cache backend disappears.
		}
		$this->build_lock_token      = '';
		$this->active_build_lock_key = '';
	}

	/** Return the atomic-lock key for one normalized request shape. */
	private function build_lock_key( $args ) {
		return 'build-lock-v3-' . md5( $this->cache_key( $args ) );
	}

	/** Publish a cache entry only if no source mutation raced the build. */
	private function set_cached_report( $args, $report, $build_generation, $build_freshness ) {
		if ( ! function_exists( 'wp_cache_set' ) || ! is_array( $report ) ) {
			return true;
		}

		$build_generation = (string) $build_generation;
		$build_freshness  = (string) $build_freshness;
		if (
			! hash_equals( $build_generation, $this->cache_generation() )
			|| ! hash_equals(
				$build_freshness,
				$this->cache_freshness_revision( $args )
			)
		) {
			return false;
		}

		$cached_report                              = $report;
		$cached_report['_cache_generation']         = $build_generation;
		$cached_report['_cache_freshness_revision'] = $build_freshness;
		try {
			wp_cache_set( $this->cache_key( $args ), $cached_report, self::CACHE_GROUP, self::CACHE_TTL );
		} catch ( Throwable $error ) {
			return true;
		}

		if (
			! hash_equals( $build_generation, $this->cache_generation() )
			|| ! hash_equals(
				$build_freshness,
				$this->cache_freshness_revision( $args )
			)
		) {
			$this->delete_cached_report( $args );
			return false;
		}

		return true;
	}

	/** Read the current source-generation token. */
	private function cache_generation() {
		$generation = get_option( self::CACHE_GENERATION_OPTION, '' );
		if ( is_string( $generation ) && '' !== $generation ) {
			$this->local_cache_generation = $generation;

			return $generation;
		}

		$this->local_cache_generation = '';

		return '';
	}

	/** Persist and read back one exact generation token. */
	private function store_generation_token( $token ) {
		$token  = (string) $token;
		$stored = update_option( self::CACHE_GENERATION_OPTION, $token, false )
			|| get_option( self::CACHE_GENERATION_OPTION, null ) === $token;
		if ( ! $stored ) {
			$stored = add_option( self::CACHE_GENERATION_OPTION, $token, '', false )
				|| get_option( self::CACHE_GENERATION_OPTION, null ) === $token;
		}
		if ( ! $stored ) {
			return false;
		}

		$this->local_cache_generation = $token;
		if ( function_exists( 'wp_cache_set' ) ) {
			try {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( self::CACHE_GENERATION_KEY, self::CACHE_GROUP );
				}
				wp_cache_set( self::CACHE_GENERATION_KEY, $token, self::CACHE_GROUP, 0 );
			} catch ( Throwable $error ) {
				// A cache backend outage cannot make the authoritative option stale.
				unset( $error );
			}
		}

		return true;
	}

	/** Persist a bounded, readback-verified map of effect receipts. */
	private function store_effect_receipts( $receipts ) {
		$receipts = is_array( $receipts ) ? $receipts : array();
		if ( count( $receipts ) > self::MAX_EFFECT_RECEIPTS ) {
			uasort(
				$receipts,
				static function ( $left, $right ) {
					$left  = is_array( $left ) ? (int) ( $left['created_at'] ?? 0 ) : 0;
					$right = is_array( $right ) ? (int) ( $right['created_at'] ?? 0 ) : 0;

					return $left <=> $right;
				}
			);
			$receipts = array_slice( $receipts, -self::MAX_EFFECT_RECEIPTS, null, true );
		}

		$stored = update_option( self::CACHE_EFFECTS_OPTION, $receipts, false )
			|| get_option( self::CACHE_EFFECTS_OPTION, null ) === $receipts;
		if ( ! $stored ) {
			$stored = add_option( self::CACHE_EFFECTS_OPTION, $receipts, '', false )
				|| get_option( self::CACHE_EFFECTS_OPTION, null ) === $receipts;
		}

		return $stored;
	}

	/** Run one generation transition under a zero-wait database mutex. */
	private function with_generation_lock( $callback ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$prefix   = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$lock     = substr( self::CACHE_GENERATION_LOCK . '_' . md5( $prefix ), 0, 64 );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) );
		if ( 1 !== (int) $acquired ) {
			return false;
		}

		try {
			return call_user_func( $callback );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Create an ownership/generation token. */
	private function new_cache_token() {
		$prefix = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : 'report';

		return $prefix . '-' . uniqid( '', true );
	}

	/** Delete one exact request-shaped cache entry. */
	private function delete_cached_report( $args ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			try {
				wp_cache_delete( $this->cache_key( $args ), self::CACHE_GROUP );
			} catch ( Throwable $error ) {
				// A cache backend outage does not invalidate the generated response.
			}
		}
	}

	/** Build a deterministic cache key containing locale and every normalized argument. */
	private function cache_key( $args ) {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return $this->cache_key_for_locale( $locale, $args );
	}

	/** Build a deterministic locale/request cache key. */
	private function cache_key_for_locale( $locale, $args ) {
		$shape = array(
			'locale'    => (string) $locale,
			'view'      => (string) $args['view'],
			'category'  => (string) $args['category'],
			'page'      => (int) $args['page'],
			'per_page'  => (int) $args['per_page'],
			'complete'  => ! empty( $args['complete'] ),
			'source_id' => (string) $args['source_id'],
			'dataset'   => (string) $args['dataset'],
		);
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $shape ) : json_encode( $shape );

		return 'current-v3-' . md5( (string) $json );
	}

	/** Parse explicit force-refresh values without treating the string false as true. */
	private function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 1.0 === (float) $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Normalize transport arguments and enforce report bounds.
	 *
	 * @param mixed $args Raw arguments.
	 * @return array|WP_Error
	 */
	private function normalize_args( $args ) {
		$args      = is_array( $args ) ? $args : array();
		$view      = isset( $args['view'] ) ? sanitize_key( (string) $args['view'] ) : 'warnings';
		$category  = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
		$per_page  = $args['per_page'] ?? ( $args['limit'] ?? 50 );
		$source_id = isset( $args['source_id'] ) && is_scalar( $args['source_id'] )
			? sanitize_text_field( (string) $args['source_id'] )
			: '';
		$dataset   = isset( $args['dataset'] ) && is_scalar( $args['dataset'] )
			? sanitize_text_field( (string) $args['dataset'] )
			: '';

		if ( '' !== $category && ! isset( $this->category_definitions()[ $category ] ) ) {
			return new WP_Error(
				'digitalogic_unknown_report_category',
				__( 'Unknown report category.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'view'      => in_array( $view, array( 'warnings', 'price_list' ), true ) ? $view : 'warnings',
			'category'  => isset( $this->category_definitions()[ $category ] ) ? $category : '',
			'page'      => max( 1, absint( $args['page'] ?? 1 ) ),
			'per_page'  => max( 1, min( self::MAX_PAGE_SIZE, absint( $per_page ) ) ),
			'source_id' => strlen( $source_id ) <= 191 ? $source_id : '',
			'dataset'   => strlen( $dataset ) <= 191 ? $dataset : '',
		);
	}

	/**
	 * Select one deterministic current receiver projection.
	 *
	 * @param array $args Normalized arguments.
	 * @return array{status:string,state:array,source:array}
	 */
	private function select_source_state( $args ) {
		$receiver_state = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$sources        = is_array( $receiver_state['sources'] ?? null ) ? $receiver_state['sources'] : array();
		$candidates     = array();

		foreach ( $sources as $key => $state ) {
			if ( ! is_array( $state ) || ! is_array( $state['source'] ?? null ) ) {
				continue;
			}
			$id      = (string) ( $state['source']['id'] ?? '' );
			$dataset = (string) ( $state['source']['dataset'] ?? '' );
			if ( '' === $id || '' === $dataset ) {
				continue;
			}
			$order        = is_array( $state['generated_at_order'] ?? null ) && 2 === count( $state['generated_at_order'] )
				? array( (int) $state['generated_at_order'][0], (int) $state['generated_at_order'][1] )
				: array( (int) strtotime( (string) ( $state['generated_at'] ?? '' ) ), 0 );
			$candidates[] = array(
				'key'     => (string) $key,
				'id'      => $id,
				'dataset' => $dataset,
				'order'   => $order,
				'state'   => $state,
			);
		}

		if ( ( '' === $args['source_id'] ) !== ( '' === $args['dataset'] ) ) {
			return array(
				'status' => 'source_scope_incomplete',
				'state'  => array(),
				'source' => array(),
			);
		}

		if ( '' !== $args['source_id'] ) {
			foreach ( $candidates as $candidate ) {
				if ( $candidate['id'] === $args['source_id'] && $candidate['dataset'] === $args['dataset'] ) {
					return $this->selected_source( $candidate );
				}
			}

			return array(
				'status' => 'source_not_found',
				'state'  => array(),
				'source' => array(),
			);
		}

		if ( empty( $candidates ) ) {
			return array(
				'status' => 'source_state_empty',
				'state'  => array(),
				'source' => array(),
			);
		}

		usort(
			$candidates,
			static function ( $left, $right ) {
				$seconds = $right['order'][0] <=> $left['order'][0];
				if ( 0 !== $seconds ) {
					return $seconds;
				}
				$nanos = $right['order'][1] <=> $left['order'][1];
				return 0 !== $nanos ? $nanos : strcmp( $left['key'], $right['key'] );
			}
		);

		return $this->selected_source( reset( $candidates ) );
	}

	/**
	 * Format selected source metadata without inventing null values.
	 *
	 * @param array $candidate Selected receiver candidate.
	 * @return array
	 */
	private function selected_source( $candidate ) {
		$state  = $candidate['state'];
		$source = array(
			'id'      => $candidate['id'],
			'dataset' => $candidate['dataset'],
		);
		foreach ( array( 'revision' ) as $field ) {
			if ( array_key_exists( $field, $state['source'] ) ) {
				$source[ $field ] = $state['source'][ $field ];
			}
		}
		foreach ( array( 'generated_at', 'received_at', 'last_event_id', 'last_event_type' ) as $field ) {
			if ( array_key_exists( $field, $state ) ) {
				$source[ $field ] = $state[ $field ];
			}
		}

		return array(
			'status' => 'current',
			'state'  => $state,
			'source' => $source,
		);
	}

	/**
	 * Load WooCommerce products in small batches with a hard cap.
	 *
	 * Variable parents are intentionally excluded: only purchasable leaf records
	 * participate in Code matching and drift checks.
	 *
	 * Product objects can carry a stale WooCommerce product-type cache even
	 * after their durable product_type taxonomy relationship is correct. The
	 * projection therefore classifies parents from an uncached taxonomy
	 * readback and reports every object/taxonomy disagreement as an integrity
	 * warning. Consumers must not accept a catalog while such a warning exists.
	 *
	 * @param callable|null $checkpoint Optional cancellation/progress checkpoint.
	 * @return array{products:array,variable_parents_excluded:int,truncated:bool,integrity_warnings:array}|WP_Error
	 */
	private function get_woocommerce_products( $checkpoint = null ) {
		$rows               = array();
		$variable_excluded  = 0;
		$integrity_warnings = array();
		$fetched            = 0;
		$page               = 1;
		$truncated          = false;
		$statuses           = array( 'publish', 'draft', 'pending', 'private' );
		$product_types      = function_exists( 'wc_get_product_types' )
			? array_keys( (array) wc_get_product_types() )
			: array( 'simple', 'grouped', 'external', 'variable' );
		$product_types[]    = 'variation';
		$product_types      = array_values( array_unique( $product_types ) );

		while ( $fetched <= self::MAX_WOO_PRODUCTS ) {
			if (
				is_callable( $checkpoint )
				&& false === call_user_func( $checkpoint, 'loading_woocommerce', 12 + min( 12, (int) floor( 12 * $fetched / self::MAX_WOO_PRODUCTS ) ), $fetched, self::MAX_WOO_PRODUCTS )
			) {
				return $this->snapshot_cancelled_error();
			}
			// Keep page size fixed. Changing `limit` on the sentinel request would
			// change the page offset and re-read an earlier product at the 10k cap.
			$limit = self::WOO_BATCH_SIZE;
			$batch = wc_get_products(
				array(
					'status'  => $statuses,
					'type'    => $product_types,
					'limit'   => $limit,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$batch = is_array( $batch ) ? $batch : array();
			if ( empty( $batch ) ) {
				break;
			}
			$batch_count   = count( $batch );
			$type_readback = $this->uncached_product_types( $batch );
			if ( is_wp_error( $type_readback ) ) {
				$integrity_warnings[] = array(
					'code'     => 'projection_integrity_product_type_readback_failed',
					'severity' => 'critical',
					'message'  => $type_readback->get_error_message(),
				);
				$type_readback        = array();
			}

			foreach ( $batch as $product ) {
				++$fetched;
				if ( $fetched > self::MAX_WOO_PRODUCTS ) {
					$truncated = true;
					break 2;
				}
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$product_id   = (int) $product->get_id();
				$object_type  = (string) $product->get_type();
				$durable_type = isset( $type_readback[ $product_id ] )
					? (string) $type_readback[ $product_id ]['type']
					: $object_type;
				$type_issues  = isset( $type_readback[ $product_id ] )
					? (array) $type_readback[ $product_id ]['issues']
					: array();
				foreach ( $type_issues as $type_issue ) {
					$integrity_warnings[] = $type_issue;
				}
				if ( $durable_type !== $object_type ) {
					$integrity_warnings[] = array(
						'code'           => 'product_type_cache_drift',
						'severity'       => 'critical',
						'woocommerce_id' => $product_id,
						'durable_type'   => $durable_type,
						'object_type'    => $object_type,
					);
				}
				if ( 'variable' === $durable_type ) {
					++$variable_excluded;
					continue;
				}
				$rows[] = $this->woocommerce_product( $product );
			}

			unset( $batch );
			$this->flush_runtime_cache();
			if ( $batch_count < $limit ) {
				break;
			}
			++$page;
		}

		return array(
			'products'                  => $rows,
			'variable_parents_excluded' => $variable_excluded,
			'truncated'                 => $truncated,
			'integrity_warnings'        => array_values( $integrity_warnings ),
		);
	}

	/**
	 * Resolve durable product types without consulting relationship caches.
	 *
	 * @param WC_Product[] $products Current WooCommerce batch.
	 * @return array|WP_Error Map keyed by product ID.
	 */
	private function uncached_product_types( $products ) {
		$ids = array();
		foreach ( (array) $products as $product ) {
			if ( $product instanceof WC_Product && $product->get_id() > 0 ) {
				$ids[] = (int) $product->get_id();
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$terms = wp_get_object_terms(
			$ids,
			'product_type',
			array(
				'fields'  => 'all_with_object_id',
				'orderby' => 'none',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$term_types = array();
		foreach ( (array) $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->object_id ) ) {
				continue;
			}
			$product_id = (int) $term->object_id;
			$type       = sanitize_title( (string) ( $term->slug ?? $term->name ?? '' ) );
			if ( $product_id > 0 && '' !== $type ) {
				$term_types[ $product_id ][ $type ] = true;
			}
		}

		$result = array();
		foreach ( $ids as $product_id ) {
			$post_type = (string) get_post_type( $product_id );
			$issues    = array();
			if ( 'product_variation' === $post_type ) {
				$durable_type = 'variation';
			} elseif ( 'product' === $post_type ) {
				$types = array_keys( $term_types[ $product_id ] ?? array() );
				sort( $types, SORT_STRING );
				if ( count( $types ) > 1 ) {
					$issues[] = array(
						'code'           => 'projection_integrity_product_type_taxonomy_ambiguous',
						'severity'       => 'critical',
						'woocommerce_id' => $product_id,
						'durable_types'  => $types,
					);
				}
				$durable_type = in_array( 'variable', $types, true )
					? 'variable'
					: ( empty( $types ) ? 'simple' : reset( $types ) );
			} else {
				$durable_type = '';
				$issues[]     = array(
					'code'           => 'projection_integrity_product_post_type_invalid',
					'severity'       => 'critical',
					'woocommerce_id' => $product_id,
					'post_type'      => $post_type,
				);
			}

			$result[ $product_id ] = array(
				'type'   => $durable_type,
				'issues' => $issues,
			);
		}

		return $result;
	}

	/** Release per-batch WordPress runtime objects when the cache supports it. */
	private function flush_runtime_cache() {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Format current WooCommerce operational and canonical metadata.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private function woocommerce_product( $product ) {
		$id   = (int) $product->get_id();
		$code = (string) $product->get_meta( '_digitalogic_patris_product_code', true );
		$row  = array(
			'id'             => $id,
			'name'           => (string) $product->get_name(),
			'type'           => (string) $product->get_type(),
			'parent_id'      => (int) $product->get_parent_id(),
			'sku'            => (string) $product->get_sku(),
			'status'         => (string) $product->get_status(),
			'regular_price'  => (string) $product->get_regular_price(),
			'active_price'   => (string) $product->get_price(),
			'sale_price'     => (string) $product->get_sale_price(),
			'stock_quantity' => $product->get_stock_quantity(),
			'manage_stock'   => $product->get_manage_stock(),
			'stock_status'   => (string) $product->get_stock_status(),
			'store_weight'   => (string) $product->get_weight(),
			'edit_url'       => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		);
		if ( '' !== $code ) {
			$row['product_code'] = $code;
		}

		$canonical = $this->read_canonical_woocommerce_meta( $product );
		if ( ! empty( $canonical ) ) {
			$row['canonical'] = $canonical;
		}

		return $row;
	}

	/**
	 * Reconstruct sparse receiver metadata persisted on a WooCommerce product.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private function read_canonical_woocommerce_meta( $product ) {
		$id      = (int) $product->get_id();
		$nulls   = $this->decode_string_list( $product->get_meta( '_digitalogic_patris_null_fields', true ) );
		$missing = $this->decode_string_list( $product->get_meta( '_digitalogic_patris_missing_fields', true ) );
		$mapping = array(
			'name'                           => '_digitalogic_patris_name',
			'sale_price_source'              => '_digitalogic_patris_sale_price_source',
			'partner_price_source'           => '_digitalogic_patris_partner_price_source',
			'foreign_currency'               => '_digitalogic_patris_foreign_currency',
			'foreign_price'                  => '_digitalogic_patris_foreign_price',
			'price_source_amount'            => '_digitalogic_patris_price_source_amount',
			'price_source_currency'          => '_digitalogic_patris_price_source_currency',
			'price_source_kind'              => '_digitalogic_patris_price_source_kind',
			'weight_grams'                   => '_digitalogic_patris_weight_grams',
			'total_stock'                    => '_digitalogic_patris_total_stock',
			'shipping_method_id'             => '_digitalogic_patris_shipping_method_id',
			'shipping_price_per_kg'          => '_digitalogic_patris_shipping_price_per_kg',
			'shipping_price_per_kg_currency' => '_digitalogic_patris_shipping_price_per_kg_currency',
			'markup_percent'                 => '_digitalogic_patris_markup_percent',
			'irt_per_cny'                    => '_digitalogic_patris_irt_per_cny',
			'price_rounding_digits'          => '_digitalogic_patris_price_rounding_digits',
			'price_rounding_mode'            => '_digitalogic_patris_price_rounding_mode',
			'final_price'                    => '_digitalogic_patris_final_price',
			'source_updated_at'              => '_digitalogic_patris_updated_at',
			'record_hash'                    => '_digitalogic_patris_record_hash',
		);
		$result  = array();
		foreach ( $mapping as $field => $meta_key ) {
			if ( in_array( $field, $missing, true ) ) {
				continue;
			}
			if ( in_array( $field, $nulls, true ) ) {
				$result[ $field ] = null;
				continue;
			}
			if ( metadata_exists( 'post', $id, $meta_key ) ) {
				$result[ $field ] = $product->get_meta( $meta_key, true );
			}
		}

		return $result;
	}

	/**
	 * Decode a persisted JSON string list.
	 *
	 * @param mixed $value JSON value.
	 * @return array
	 */
	private function decode_string_list( $value ) {
		$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
		return is_array( $decoded )
			? array_values( array_filter( $decoded, 'is_string' ) )
			: array();
	}

	/**
	 * Create a source-backed row and preserve its sparse shape.
	 *
	 * @param array      $source Source product.
	 * @param string     $status Match status.
	 * @param array|null $woo Current WooCommerce row.
	 * @return array
	 */
	private function source_row( $source, $status, $woo = null ) {
		$row = array(
			'product_code' => (string) $source['product_code'],
			'status'       => $status,
			'source'       => $source,
			'issues'       => array(),
		);
		foreach ( array( 'name', 'sale_price_source', 'partner_price_source', 'foreign_currency', 'foreign_price', 'price_source_amount', 'price_source_currency', 'price_source_kind', 'weight_grams', 'price_rounding_digits', 'price_rounding_mode', 'final_price' ) as $field ) {
			if ( array_key_exists( $field, $source ) ) {
				$row[ $field ] = $source[ $field ];
			}
		}
		if ( array_key_exists( 'total_stock', $source ) ) {
			$row['stock'] = $source['total_stock'];
		}
		if ( array_key_exists( 'source_updated_at', $source ) ) {
			$row['source_updated_at'] = $source['source_updated_at'];
			$row['updated_at']        = $source['source_updated_at'];
		}
		if ( is_array( $woo ) ) {
			$row['woocommerce'] = $woo;
			$row['woo_id']      = $woo['id'];
			$row['woo_name']    = $woo['name'];
			$row['edit_url']    = $woo['edit_url'];
		}

		return $row;
	}

	/**
	 * Create a WooCommerce-only row without manufacturing a source object.
	 *
	 * @param array $woo Current WooCommerce row.
	 * @return array
	 */
	private function woo_row( $woo ) {
		$code = isset( $woo['product_code'] ) && '' !== $woo['product_code']
			? $woo['product_code']
			: 'woo:' . $woo['id'];

		$row = array(
			'product_code' => $code,
			'status'       => 'woocommerce_only',
			'woocommerce'  => $woo,
			'woo_id'       => $woo['id'],
			'woo_name'     => $woo['name'],
			'edit_url'     => $woo['edit_url'],
			'issues'       => array(),
		);
		if ( null !== $woo['stock_quantity'] ) {
			$row['stock'] = $woo['stock_quantity'];
		}

		return $row;
	}

	/**
	 * Add source completeness, warning, and freshness findings.
	 *
	 * @param array $row Row, updated in place.
	 * @param array $source Canonical sparse source product.
	 * @param int   $stale_hours Freshness threshold.
	 * @return void
	 */
	private function append_source_issues( &$row, $source, $stale_hours ) {
		$price_source_fields  = array( 'price_source_amount', 'price_source_currency', 'price_source_kind' );
		$present_price_fields = array_values( array_intersect( $price_source_fields, array_keys( $source ) ) );
		$price_source_kind    = count( $present_price_fields ) === count( $price_source_fields )
			? (string) $source['price_source_kind']
			: '';

		if ( empty( $present_price_fields ) ) {
			$this->add_issue( $row, 'missing_price_source' );
			$this->append_unselected_price_fact_issues( $row, $source );
		} elseif ( count( $present_price_fields ) !== count( $price_source_fields ) ) {
			$this->add_issue( $row, 'incomplete_price_source', array_values( array_diff( $price_source_fields, $present_price_fields ) ) );
		} elseif ( in_array( null, array_intersect_key( $source, array_flip( $price_source_fields ) ), true ) ) {
			$this->add_issue( $row, 'null_price_source' );
		} elseif ( 'foreign_price' === $price_source_kind && 'CNY' === ( $source['price_source_currency'] ?? null ) ) {
			$this->append_required_number_issue( $row, $source, 'foreign_price', 'missing_foreign_price', 'null_foreign_price', true );
			$this->append_required_number_issue( $row, $source, 'weight_grams', 'missing_weight', 'null_weight', true );
			$this->append_cny_price_issues( $row, $source );
		} elseif ( 'partner_price' === $price_source_kind && 'IRR' === ( $source['price_source_currency'] ?? null ) ) {
			$this->append_required_number_issue( $row, $source, 'partner_price_source', 'missing_partner_price', 'null_partner_price', true );
			$this->append_domestic_price_issues( $row, $source );
			$this->add_issue( $row, 'partner_price_fallback' );
		} elseif ( 'sale_price_direct' === $price_source_kind && 'IRR' === ( $source['price_source_currency'] ?? null ) ) {
			$this->append_required_number_issue( $row, $source, 'sale_price_source', 'missing_sale_price_source', 'null_sale_price_source', true );
			$this->append_domestic_price_issues( $row, $source );
			$this->add_issue( $row, 'sale_price_direct_fallback' );
		} else {
			$this->add_issue( $row, 'invalid_price_source', $price_source_fields );
		}

		$this->append_required_number_issue( $row, $source, 'total_stock', 'missing_stock', 'null_stock', false );
		$this->append_required_number_issue( $row, $source, 'final_price', 'missing_final_price', 'null_final_price', true );
		if ( 'sale_price_direct' !== $price_source_kind ) {
			$this->append_required_number_issue( $row, $source, 'markup_percent', 'missing_markup', 'null_markup', false );
			$this->append_rounding_issues( $row, $source );
		}

		if ( array_key_exists( 'total_stock', $source ) && is_numeric( $source['total_stock'] ) && (float) $source['total_stock'] <= 0 ) {
			$this->add_issue( $row, 'zero_stock' );
		}
		if ( array_key_exists( 'final_price', $source ) && is_numeric( $source['final_price'] ) && (float) $source['final_price'] <= 0 ) {
			$this->add_issue( $row, 'zero_price' );
		}

		if ( ! array_key_exists( 'source_updated_at', $source ) ) {
			$this->add_issue( $row, 'missing_source_updated_at' );
		} elseif ( null === $source['source_updated_at'] ) {
			$this->add_issue( $row, 'null_source_updated_at' );
		} elseif ( $this->is_stale( $source['source_updated_at'], $stale_hours ) ) {
			$this->add_issue( $row, 'stale_source' );
		}

		if ( ! empty( $source['warnings'] ) && is_array( $source['warnings'] ) ) {
			$row['source_warnings'] = array_values( $source['warnings'] );
			$complete_partner_path  = 'partner_price' === $price_source_kind
				&& 'IRR' === ( $source['price_source_currency'] ?? null )
				&& array_key_exists( 'price_source_amount', $source )
				&& is_numeric( $source['price_source_amount'] )
				&& $this->decimal_compare_zero( $source['price_source_amount'] ) > 0
				&& array_key_exists( 'final_price', $source )
				&& is_numeric( $source['final_price'] )
				&& $this->decimal_compare_zero( $source['final_price'] ) > 0;
			$complete_direct_path   = 'sale_price_direct' === $price_source_kind
				&& 'IRR' === ( $source['price_source_currency'] ?? null )
				&& array_key_exists( 'price_source_amount', $source )
				&& is_numeric( $source['price_source_amount'] )
				&& $this->decimal_compare_zero( $source['price_source_amount'] ) > 0
				&& array_key_exists( 'final_price', $source )
				&& is_numeric( $source['final_price'] )
				&& $this->decimal_compare_zero( $source['final_price'] ) > 0;
			$ignored_warnings       = $complete_partner_path
				? array(
					'partner_price_fallback_used',
					'freight_not_applied_for_partner_price',
					'foreign_price_missing',
					'foreign_price_non_positive',
					'weight_missing',
					'weight_unparsed',
					'weight_ambiguous',
					'weight_source_conflict',
				)
				: array();
			if ( $complete_direct_path ) {
				$ignored_warnings = array_merge(
					$ignored_warnings,
					array(
						'sale_price_direct_fallback_used',
						'freight_not_applied_for_sale_price_direct',
						'foreign_price_missing',
						'foreign_price_non_positive',
						'weight_missing',
						'weight_unparsed',
						'weight_ambiguous',
						'weight_source_conflict',
					)
				);
			}
			$attention_warnings = array_values(
				array_diff(
					$row['source_warnings'],
					$ignored_warnings
				)
			);
			if ( $attention_warnings ) {
				$this->add_issue( $row, 'source_warning' );
			}
		}
	}

	/**
	 * Detect provider-local identity collisions before any projection is built.
	 *
	 * Optional fields are ignored when absent. Every detected collision is
	 * blocking and carries the exact provider records needed for remediation.
	 *
	 * @param array $products Canonical source products keyed by Product Code.
	 * @param array $woo_rows Provider-neutral WooCommerce leaf rows.
	 * @return array{integrity_warnings:array,source_issues:array,woo_issues:array,counts:array}
	 */
	private function provider_identity_diagnostics( array $products, array $woo_rows ): array {
		$warnings     = array();
		$source_issues = array();
		$woo_issues    = array();
		$counts        = array(
			'provider_identity_collision_groups' => 0,
			'source_code_collision_groups'       => 0,
			'woo_code_collision_groups'          => 0,
			'woo_sku_collision_groups'           => 0,
		);

		$source_groups = array();
		foreach ( $products as $product ) {
			$code = is_scalar( $product['product_code'] ?? null ) ? trim( (string) $product['product_code'] ) : '';
			if ( '' === $code ) {
				continue;
			}
			$source_groups[ Digitalogic_Catalog_Identity_Reconciler::normalize_identifier( $code ) ][ $code ] = array(
				'product_code' => $code,
				'name'         => is_scalar( $product['name'] ?? null ) ? trim( (string) $product['name'] ) : '',
			);
		}
		foreach ( $source_groups as $normalized => $records_by_code ) {
			if ( count( $records_by_code ) < 2 ) {
				continue;
			}
			$records = array_values( $records_by_code );
			usort( $records, static fn( $left, $right ) => strcmp( $left['product_code'], $right['product_code'] ) );
			foreach ( array_keys( $records_by_code ) as $code ) {
				$source_issues[ $code ][] = 'duplicate_normalized_source_product_code';
			}
			$warnings[] = $this->provider_identity_warning(
				'projection_integrity_duplicate_source_product_code',
				$normalized,
				$records,
				'Give every canonical source product a unique normalized Product Code before refreshing.'
			);
			++$counts['provider_identity_collision_groups'];
			++$counts['source_code_collision_groups'];
		}

		foreach (
			array(
				'product_code' => array(
					'code'        => 'projection_integrity_duplicate_woo_product_code',
					'issue'       => 'duplicate_normalized_woo_product_code',
					'count'       => 'woo_code_collision_groups',
					'remediation' => 'Keep the authoritative Product Code on exactly one WooCommerce product or variation.',
				),
				'sku'          => array(
					'code'        => 'projection_integrity_duplicate_woo_sku',
					'issue'       => 'duplicate_normalized_woo_sku',
					'count'       => 'woo_sku_collision_groups',
					'remediation' => 'Give each WooCommerce product or variation a unique SKU, or clear the non-authoritative SKU.',
				),
			) as $field => $definition
		) {
			$groups = array();
			foreach ( $woo_rows as $woo ) {
				$value = is_scalar( $woo[ $field ] ?? null ) ? trim( (string) $woo[ $field ] ) : '';
				$id    = absint( $woo['id'] ?? 0 );
				if ( '' === $value || ! $id ) {
					continue;
				}
				$groups[ Digitalogic_Catalog_Identity_Reconciler::normalize_identifier( $value ) ][ $id ] = array(
					'woocommerce_id' => $id,
					'product_code'   => is_scalar( $woo['product_code'] ?? null ) ? trim( (string) $woo['product_code'] ) : '',
					'sku'            => is_scalar( $woo['sku'] ?? null ) ? trim( (string) $woo['sku'] ) : '',
					'name'           => is_scalar( $woo['name'] ?? null ) ? trim( (string) $woo['name'] ) : '',
					'type'           => is_scalar( $woo['type'] ?? null ) ? trim( (string) $woo['type'] ) : '',
					'parent_id'      => absint( $woo['parent_id'] ?? 0 ),
				);
			}
			foreach ( $groups as $normalized => $records_by_id ) {
				if ( count( $records_by_id ) < 2 ) {
					continue;
				}
				$records = array_values( $records_by_id );
				usort( $records, static fn( $left, $right ) => $left['woocommerce_id'] <=> $right['woocommerce_id'] );
				foreach ( array_keys( $records_by_id ) as $id ) {
					$woo_issues[ $id ][] = $definition['issue'];
				}
				$warnings[] = $this->provider_identity_warning(
					$definition['code'],
					$normalized,
					$records,
					$definition['remediation']
				);
				++$counts['provider_identity_collision_groups'];
				++$counts[ $definition['count'] ];
			}
		}

		return array(
			'integrity_warnings' => $warnings,
			'source_issues'      => $source_issues,
			'woo_issues'         => $woo_issues,
			'counts'             => $counts,
		);
	}

	/** Build one stable provider-local quarantine warning. */
	private function provider_identity_warning( string $code, string $normalized, array $records, string $remediation ): array {
		$quarantine_id = 'sha256:' . hash(
			'sha256',
			wp_json_encode(
				array(
					'code'       => $code,
					'normalized' => $normalized,
					'records'    => $records,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);

		return array(
			'code'            => $code,
			'severity'        => 'critical',
			'quarantine_id'   => $quarantine_id,
			'normalized_value' => $normalized,
			'records'         => $records,
			'remediation'     => $remediation,
		);
	}

	/**
	 * Explain why no selected price source could be produced.
	 *
	 * @param array $row Mutable report row.
	 * @param array $source Sparse source product.
	 * @return void
	 */
	private function append_unselected_price_fact_issues( &$row, $source ) {
		if ( ! array_key_exists( 'foreign_price', $source ) ) {
			$this->add_issue( $row, 'missing_foreign_price' );
		} elseif ( null === $source['foreign_price'] ) {
			$this->add_issue( $row, 'null_foreign_price' );
		} elseif ( ! is_numeric( $source['foreign_price'] ) || $this->decimal_compare_zero( $source['foreign_price'] ) <= 0 ) {
			$this->add_issue( $row, 'zero_foreign_price' );
		}

		if ( ! array_key_exists( 'partner_price_source', $source ) ) {
			$this->add_issue( $row, 'missing_partner_price' );
		} elseif ( null === $source['partner_price_source'] ) {
			$this->add_issue( $row, 'null_partner_price' );
		} elseif ( ! is_numeric( $source['partner_price_source'] ) || $this->decimal_compare_zero( $source['partner_price_source'] ) <= 0 ) {
			$this->add_issue( $row, 'zero_partner_price' );
		}

		if (
			array_key_exists( 'foreign_price', $source )
			&& null !== $source['foreign_price']
			&& is_numeric( $source['foreign_price'] )
			&& $this->decimal_compare_zero( $source['foreign_price'] ) > 0
		) {
			$this->append_required_number_issue( $row, $source, 'weight_grams', 'missing_weight', 'null_weight', true );
			$this->append_cny_price_issues( $row, $source );
		}
	}

	/**
	 * Add the inputs that only the CNY landed-price path consumes.
	 *
	 * @param array $row Mutable report row.
	 * @param array $source Sparse source product.
	 * @return void
	 */
	private function append_cny_price_issues( &$row, $source ) {
		$this->append_foreign_currency_issue( $row, $source );
		$this->append_required_number_issue( $row, $source, 'irt_per_cny', 'missing_exchange_rate', 'null_exchange_rate', true );

		$shipping_missing = array();
		$shipping_null    = array();
		foreach ( array( 'shipping_method_id', 'shipping_price_per_kg', 'shipping_price_per_kg_currency' ) as $field ) {
			if ( ! array_key_exists( $field, $source ) ) {
				$shipping_missing[] = $field;
			} elseif ( null === $source[ $field ] ) {
				$shipping_null[] = $field;
			}
		}
		if ( $shipping_missing ) {
			$this->add_issue( $row, 'missing_shipping', $shipping_missing );
		}
		if ( $shipping_null ) {
			$this->add_issue( $row, 'null_shipping', $shipping_null );
		}
	}

	/**
	 * Require the explicit zero-rate domestic route selected for partner price.
	 *
	 * @param array $row Mutable report row.
	 * @param array $source Sparse source product.
	 * @return void
	 */
	private function append_domestic_price_issues( &$row, $source ) {
		$fields  = array( 'shipping_method_id', 'shipping_price_per_kg', 'shipping_price_per_kg_currency' );
		$missing = array_values( array_diff( $fields, array_keys( $source ) ) );
		$nulls   = array();
		foreach ( array_intersect( $fields, array_keys( $source ) ) as $field ) {
			if ( null === $source[ $field ] ) {
				$nulls[] = $field;
			}
		}
		if ( $missing ) {
			$this->add_issue( $row, 'missing_shipping', $missing );
		}
		if ( $nulls ) {
			$this->add_issue( $row, 'null_shipping', $nulls );
		}
		if ( $missing || $nulls ) {
			return;
		}

		$invalid = array();
		if ( Digitalogic_Shipping_Method_Service::DOMESTIC_METHOD_ID !== $source['shipping_method_id'] ) {
			$invalid[] = 'shipping_method_id';
		}
		if ( ! is_numeric( $source['shipping_price_per_kg'] ) || 0 !== $this->decimal_compare_zero( $source['shipping_price_per_kg'] ) ) {
			$invalid[] = 'shipping_price_per_kg';
		}
		if ( 'IRR' !== $source['shipping_price_per_kg_currency'] ) {
			$invalid[] = 'shipping_price_per_kg_currency';
		}
		if ( $invalid ) {
			$this->add_issue( $row, 'invalid_domestic_shipping', $invalid );
		}
	}

	/**
	 * Preserve absent, explicit-null, and invalid currency states.
	 *
	 * @param array $row Mutable report row.
	 * @param array $source Sparse source product.
	 * @return void
	 */
	private function append_foreign_currency_issue( &$row, $source ) {
		if ( ! array_key_exists( 'foreign_currency', $source ) ) {
			$this->add_issue( $row, 'missing_foreign_currency' );
		} elseif ( null === $source['foreign_currency'] ) {
			$this->add_issue( $row, 'null_foreign_currency' );
		} elseif ( 'CNY' !== $source['foreign_currency'] ) {
			$this->add_issue( $row, 'unexpected_foreign_currency' );
		}
	}

	/**
	 * Report the exact living rounding policy carried by every priced snapshot.
	 *
	 * @param array $row Mutable report row.
	 * @param array $source Sparse source product.
	 * @return void
	 */
	private function append_rounding_issues( &$row, $source ) {
		if ( ! array_key_exists( 'price_rounding_digits', $source ) ) {
			$this->add_issue( $row, 'missing_rounding_digits' );
		} elseif ( null === $source['price_rounding_digits'] ) {
			$this->add_issue( $row, 'null_rounding_digits' );
			if ( array_key_exists( 'price_rounding_mode', $source ) ) {
				if ( null === $source['price_rounding_mode'] ) {
					$this->add_issue( $row, 'null_rounding_mode' );
				} else {
					$this->add_issue( $row, 'invalid_rounding_policy', array( 'price_rounding_mode' ) );
				}
			}
			return;
		} elseif ( ! is_int( $source['price_rounding_digits'] ) || $source['price_rounding_digits'] < 0 || $source['price_rounding_digits'] > 9 ) {
			$this->add_issue( $row, 'invalid_rounding_policy', array( 'price_rounding_digits' ) );
		}

		if ( ! array_key_exists( 'price_rounding_mode', $source ) ) {
			$this->add_issue( $row, 'missing_rounding_mode' );
		} elseif ( null === $source['price_rounding_mode'] ) {
			$this->add_issue( $row, 'null_rounding_mode' );
		} elseif ( 'nearest_half_up' !== $source['price_rounding_mode'] ) {
			$this->add_issue( $row, 'invalid_rounding_policy', array( 'price_rounding_mode' ) );
		}
	}

	/**
	 * Add a required numeric-input issue without conflating missing and null.
	 *
	 * @param array  $row Row, updated in place.
	 * @param array  $source Source record.
	 * @param string $field Field name.
	 * @param string $missing_issue Missing-key finding.
	 * @param string $null_issue Explicit-null finding.
	 * @param bool   $positive Whether the value must be positive.
	 * @return void
	 */
	private function append_required_number_issue( &$row, $source, $field, $missing_issue, $null_issue, $positive ) {
		if ( ! array_key_exists( $field, $source ) ) {
			$this->add_issue( $row, $missing_issue );
			return;
		}
		if ( null === $source[ $field ] ) {
			$this->add_issue( $row, $null_issue );
			return;
		}
		if ( ! is_numeric( $source[ $field ] ) || ( $positive && $this->decimal_compare_zero( $source[ $field ] ) <= 0 ) ) {
			$this->add_issue( $row, 'invalid_source_value', array( $field ) );
		}
	}

	/**
	 * Compare source values with the values actually persisted in WooCommerce.
	 *
	 * @param array $row Row, updated in place.
	 * @param array $source Source product.
	 * @param array $woo Current WooCommerce row.
	 * @return void
	 */
	private function append_drift_issues( &$row, $source, $woo ) {
		$canonical = is_array( $woo['canonical'] ?? null ) ? $woo['canonical'] : array();

		if (
			array_key_exists( 'final_price', $source )
			&& null !== $source['final_price']
			&& is_numeric( $source['final_price'] )
			&& $this->decimal_compare_zero( $source['final_price'] ) > 0
		) {
			$expected_woo_price = $source['final_price'];
			$price_fields       = array();
			foreach ( array( 'regular_price', 'active_price' ) as $field ) {
				if ( ! $this->values_equal( $expected_woo_price, $woo[ $field ] ) ) {
					$price_fields[] = $field;
				}
			}
			if ( '' !== trim( (string) $woo['sale_price'] ) ) {
				$price_fields[] = 'sale_price';
			}
			if ( ! array_key_exists( 'final_price', $canonical ) || ! $this->values_equal( $source['final_price'], $canonical['final_price'] ) ) {
				$price_fields[] = 'canonical.final_price';
			}
			if ( $price_fields ) {
				$this->add_issue( $row, 'price_drift', $price_fields );
			}
		}

		if ( array_key_exists( 'total_stock', $source ) ) {
			$expected_woo_stock = null;
			if ( null !== $source['total_stock'] && is_numeric( $source['total_stock'] ) ) {
				$expected_woo_stock = $this->decimal_compare_zero( $source['total_stock'] ) <= 0
					? 0
					: max( 1, (int) floor( (float) $source['total_stock'] ) );
			}
			$drift = ! $this->values_equal( $expected_woo_stock, $woo['stock_quantity'] );
			$drift = $drift || ! array_key_exists( 'total_stock', $canonical ) || ! $this->values_equal( $source['total_stock'], $canonical['total_stock'] );
			if ( $drift ) {
				$this->add_issue( $row, 'stock_drift' );
			}
			if ( null !== $expected_woo_stock ) {
				if ( true !== $woo['manage_stock'] ) {
					$this->add_issue( $row, 'stock_management_drift', array( 'manage_stock' ) );
				}
				$expected_stock_status = $this->decimal_compare_zero( $source['total_stock'] ) > 0 ? 'instock' : 'outofstock';
				if ( $expected_stock_status !== $woo['stock_status'] ) {
					$this->add_issue( $row, 'stock_status_drift', array( 'stock_status' ) );
				}
			}
		}

		if ( array_key_exists( 'weight_grams', $source ) ) {
			$expected_store_weight = null === $source['weight_grams']
				? ''
				: Digitalogic_Unit_Converter::grams_to_store_weight( $source['weight_grams'] );
			if ( null === $expected_store_weight ) {
				$expected_store_weight = '';
			}
			if (
				! $this->values_equal( $expected_store_weight, $woo['store_weight'] )
				|| ! array_key_exists( 'weight_grams', $canonical )
				|| ! $this->values_equal( $source['weight_grams'], $canonical['weight_grams'] )
			) {
				$this->add_issue( $row, 'weight_drift' );
			}
		}

		if ( array_key_exists( 'record_hash', $source ) ) {
			if ( ! array_key_exists( 'record_hash', $canonical ) || ! $this->values_equal( $source['record_hash'], $canonical['record_hash'] ) ) {
				$this->add_issue( $row, 'record_hash_drift' );
			}
		}

		$provenance_fields = array(
			'sale_price_source',
			'partner_price_source',
			'price_source_amount',
			'price_source_currency',
			'price_source_kind',
			'shipping_method_id',
			'shipping_price_per_kg',
			'shipping_price_per_kg_currency',
			'price_rounding_digits',
			'price_rounding_mode',
		);
		$provenance_drift  = array();
		foreach ( $provenance_fields as $field ) {
			if (
				array_key_exists( $field, $source )
				&& ( ! array_key_exists( $field, $canonical ) || ! $this->values_equal( $source[ $field ], $canonical[ $field ] ) )
			) {
				$provenance_drift[] = 'canonical.' . $field;
			}
		}
		if ( $provenance_drift ) {
			$this->add_issue( $row, 'pricing_provenance_drift', $provenance_drift );
		}

		if ( array_key_exists( 'source_updated_at', $source ) ) {
			if ( ! array_key_exists( 'source_updated_at', $canonical ) || ! $this->values_equal( $source['source_updated_at'], $canonical['source_updated_at'] ) ) {
				$this->add_issue( $row, 'source_updated_at_drift' );
			}
		}
	}

	/**
	 * Add one unique issue and optional affected fields.
	 *
	 * @param array  $row Row, updated in place.
	 * @param string $issue Issue key.
	 * @param array  $fields Affected canonical fields.
	 * @return void
	 */
	private function add_issue( &$row, $issue, $fields = array() ) {
		if ( ! in_array( $issue, $row['issues'], true ) ) {
			$row['issues'][] = $issue;
		}
		if ( $fields ) {
			$existing                      = is_array( $row['issue_fields'][ $issue ] ?? null ) ? $row['issue_fields'][ $issue ] : array();
			$row['issue_fields'][ $issue ] = array_values(
				array_unique( array_merge( $existing, array_map( 'strval', $fields ) ) )
			);
		}
	}

	/**
	 * Report category metadata.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private function category_definitions() {
		return array(
			'duplicate_normalized_source_product_code' => array( __( 'Duplicate normalized Product Code in canonical source', 'digitalogic' ), 'danger' ),
			'duplicate_normalized_woo_product_code'    => array( __( 'Duplicate normalized Product Code in WooCommerce', 'digitalogic' ), 'danger' ),
			'duplicate_normalized_woo_sku'             => array( __( 'Duplicate normalized WooCommerce SKU', 'digitalogic' ), 'danger' ),
			'identity_quarantined'                  => array( __( 'Unsafe catalog identity is quarantined', 'digitalogic' ), 'danger' ),
			'split_identity_candidate'              => array( __( 'Possible split source and WooCommerce identity', 'digitalogic' ), 'danger' ),
			'normalized_identity_collision'         => array( __( 'More than one normalized identity candidate exists', 'digitalogic' ), 'danger' ),
			'missing_in_woocommerce'                => array( __( 'In source but missing in WooCommerce', 'digitalogic' ), 'danger' ),
			'positive_stock_missing_in_woocommerce' => array( __( 'Positive-stock product missing in WooCommerce', 'digitalogic' ), 'danger' ),
			'missing_in_patris'                     => array( __( 'In WooCommerce but missing in source', 'digitalogic' ), 'warning' ),
			'missing_product_code'                  => array( __( 'Missing exact product Code metadata', 'digitalogic' ), 'danger' ),
			'duplicate_product_code'                => array( __( 'Duplicate exact product Code metadata', 'digitalogic' ), 'danger' ),
			'source_warning'                        => array( __( 'Source warnings require attention', 'digitalogic' ), 'warning' ),
			'missing_foreign_currency'              => array( __( 'Missing foreign currency', 'digitalogic' ), 'warning' ),
			'null_foreign_currency'                 => array( __( 'Foreign currency is explicitly null', 'digitalogic' ), 'warning' ),
			'unexpected_foreign_currency'           => array( __( 'Foreign currency is not CNY', 'digitalogic' ), 'warning' ),
			'missing_foreign_price'                 => array( __( 'Missing CNY price', 'digitalogic' ), 'warning' ),
			'null_foreign_price'                    => array( __( 'CNY price is explicitly null', 'digitalogic' ), 'warning' ),
			'zero_foreign_price'                    => array( __( 'CNY price is zero or non-positive', 'digitalogic' ), 'warning' ),
			'missing_partner_price'                 => array( __( 'Missing partner price', 'digitalogic' ), 'warning' ),
			'null_partner_price'                    => array( __( 'Partner price is explicitly null', 'digitalogic' ), 'warning' ),
			'zero_partner_price'                    => array( __( 'Partner price is zero or non-positive', 'digitalogic' ), 'warning' ),
			'missing_sale_price_source'             => array( __( 'Missing source sale price', 'digitalogic' ), 'warning' ),
			'null_sale_price_source'                => array( __( 'Source sale price is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_price_source'                  => array( __( 'No usable price source was selected', 'digitalogic' ), 'danger' ),
			'incomplete_price_source'               => array( __( 'Selected price-source provenance is incomplete', 'digitalogic' ), 'danger' ),
			'null_price_source'                     => array( __( 'Selected price-source provenance contains explicit null', 'digitalogic' ), 'danger' ),
			'invalid_price_source'                  => array( __( 'Selected price-source provenance is invalid', 'digitalogic' ), 'danger' ),
			'partner_price_fallback'                => array( __( 'Partner-price fallback selected', 'digitalogic' ), 'info' ),
			'sale_price_direct_fallback'            => array( __( 'Direct source sale-price fallback selected', 'digitalogic' ), 'info' ),
			'missing_weight'                        => array( __( 'Missing weight', 'digitalogic' ), 'warning' ),
			'null_weight'                           => array( __( 'Weight is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_stock'                         => array( __( 'Missing stock', 'digitalogic' ), 'warning' ),
			'null_stock'                            => array( __( 'Stock is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_final_price'                   => array( __( 'Missing calculated price', 'digitalogic' ), 'danger' ),
			'null_final_price'                      => array( __( 'Calculated price is explicitly null', 'digitalogic' ), 'danger' ),
			'missing_shipping'                      => array( __( 'Missing shipping price inputs', 'digitalogic' ), 'warning' ),
			'null_shipping'                         => array( __( 'Shipping price inputs contain explicit null', 'digitalogic' ), 'warning' ),
			'invalid_domestic_shipping'             => array( __( 'Domestic price source must use the zero-rate domestic method in IRR', 'digitalogic' ), 'danger' ),
			'missing_markup'                        => array( __( 'Missing profit margin', 'digitalogic' ), 'warning' ),
			'null_markup'                           => array( __( 'Profit margin is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_exchange_rate'                 => array( __( 'Missing CNY exchange rate', 'digitalogic' ), 'warning' ),
			'null_exchange_rate'                    => array( __( 'CNY exchange rate is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_rounding_digits'               => array( __( 'Missing price-rounding digits', 'digitalogic' ), 'warning' ),
			'null_rounding_digits'                  => array( __( 'Price-rounding digits are explicitly null', 'digitalogic' ), 'warning' ),
			'missing_rounding_mode'                 => array( __( 'Missing price-rounding mode', 'digitalogic' ), 'warning' ),
			'null_rounding_mode'                    => array( __( 'Price-rounding mode is explicitly null', 'digitalogic' ), 'warning' ),
			'invalid_rounding_policy'               => array( __( 'Price-rounding policy is invalid', 'digitalogic' ), 'danger' ),
			'invalid_source_value'                  => array( __( 'Invalid source value', 'digitalogic' ), 'danger' ),
			'zero_stock'                            => array( __( 'Zero or negative stock', 'digitalogic' ), 'info' ),
			'zero_price'                            => array( __( 'Zero or negative calculated price', 'digitalogic' ), 'danger' ),
			'missing_source_updated_at'             => array( __( 'Missing source update time', 'digitalogic' ), 'warning' ),
			'null_source_updated_at'                => array( __( 'Source update time is explicitly null', 'digitalogic' ), 'warning' ),
			'stale_source'                          => array( __( 'Stale source data', 'digitalogic' ), 'warning' ),
			'price_drift'                           => array( __( 'Price differs from the current source', 'digitalogic' ), 'danger' ),
			'stock_drift'                           => array( __( 'Stock differs from the current source', 'digitalogic' ), 'danger' ),
			'stock_management_drift'                => array( __( 'Stock management differs from the current source', 'digitalogic' ), 'danger' ),
			'stock_status_drift'                    => array( __( 'Stock availability differs from the current source', 'digitalogic' ), 'danger' ),
			'weight_drift'                          => array( __( 'Weight differs from the current source', 'digitalogic' ), 'danger' ),
			'record_hash_drift'                     => array( __( 'Record hash differs from the current source', 'digitalogic' ), 'danger' ),
			'pricing_provenance_drift'              => array( __( 'Price-source provenance differs in WooCommerce', 'digitalogic' ), 'danger' ),
			'source_updated_at_drift'               => array( __( 'Source update time differs in WooCommerce', 'digitalogic' ), 'warning' ),
		);
	}

	/**
	 * Compare typed values exactly, normalizing only numeric representation.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 * @return bool
	 */
	private function values_equal( $left, $right ) {
		if ( null === $left || null === $right ) {
			return null === $left && null === $right;
		}
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return $this->normalize_decimal( $left ) === $this->normalize_decimal( $right );
		}

		return (string) $left === (string) $right;
	}

	/**
	 * Normalize a finite decimal without binary floating-point comparison.
	 *
	 * @param mixed $value Numeric value.
	 * @return string
	 */
	private function normalize_decimal( $value ) {
		$text = trim( (string) $value );
		if ( ! preg_match( '/^([+-]?)([0-9]+)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?$/', $text, $matches ) ) {
			return $text;
		}
		$sign     = '-' === $matches[1] ? '-' : '';
		$integer  = $matches[2];
		$fraction = $matches[3] ?? '';
		$exponent = isset( $matches[4] ) ? (int) $matches[4] : 0;
		$digits   = $integer . $fraction;
		$point    = strlen( $integer ) + $exponent;

		if ( $point <= 0 ) {
			$integer  = '0';
			$fraction = str_repeat( '0', -$point ) . $digits;
		} elseif ( $point >= strlen( $digits ) ) {
			$integer  = $digits . str_repeat( '0', $point - strlen( $digits ) );
			$fraction = '';
		} else {
			$integer  = substr( $digits, 0, $point );
			$fraction = substr( $digits, $point );
		}

		$integer  = ltrim( $integer, '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = rtrim( $fraction, '0' );
		if ( '0' === $integer && '' === $fraction ) {
			$sign = '';
		}

		return $sign . $integer . ( '' !== $fraction ? '.' . $fraction : '' );
	}

	/**
	 * Compare a numeric source value with zero.
	 *
	 * @param mixed $value Numeric source value.
	 * @return int
	 */
	private function decimal_compare_zero( $value ) {
		$normalized = $this->normalize_decimal( $value );
		if ( '0' === $normalized ) {
			return 0;
		}

		return str_starts_with( $normalized, '-' ) ? -1 : 1;
	}

	/**
	 * Use the configured report threshold against source_updated_at.
	 *
	 * @param mixed $updated_at Source update time.
	 * @param int   $hours Freshness threshold.
	 * @return bool
	 */
	private function is_stale( $updated_at, $hours ) {
		if ( ! is_string( $updated_at ) || '' === $updated_at ) {
			return true;
		}
		$timestamp = strtotime( $updated_at );
		if ( false === $timestamp ) {
			return true;
		}
		return $timestamp < time() - ( max( 1, absint( $hours ) ) * HOUR_IN_SECONDS );
	}
}
