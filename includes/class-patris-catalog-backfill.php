<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Shared includes omit the common Digitalogic class prefix.
/**
 * Creation policy and transport presentation for the shared Patris materializer.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Expose creation policy without a second product writer. */
final class Digitalogic_Patris_Catalog_Backfill {
	const POLICY_OPTION           = 'digitalogic_patris_catalog_backfill_policy_v1';
	const WATCHDOG_OPTION         = 'digitalogic_patris_catalog_reconciliation_watchdog_v1';
	const WATCHDOG_HOOK           = 'digitalogic_patris_catalog_reconciliation_watchdog';
	const MAX_BATCH               = 100;
	const WATCHDOG_BATCH_LIMIT    = 25;
	const WATCHDOG_INTERVAL       = 900;
	const WATCHDOG_RETRY_INTERVAL = 300;
	/**
	 * Shared policy adapter.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Return the shared policy adapter.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register route presentation and the privileged policy command.
	 */
	private function __construct() {
		add_filter( 'rest_endpoints', array( $this, 'remove_client_named_pricing_routes' ), 1000 );
		add_filter( 'rest_post_dispatch', array( $this, 'enrich_pricing_response' ), 20, 3 );
		add_action( self::WATCHDOG_HOOK, array( $this, 'run_reconciliation_watchdog' ) );
		add_action( 'init', array( $this, 'ensure_reconciliation_watchdog' ), 42 );
		add_action( 'digitalogic_product_sync_state_committed', array( $this, 'wake_reconciliation_watchdog' ), 40, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->register_cli();
		}
	}

	/**
	 * Read fresh policy only at the missing-identity creation boundary.
	 *
	 * @param array $record Canonical source product.
	 * @param array $source Exact source identity.
	 * @return array|WP_Error
	 */
	public function creation_policy( $record, $source ) {
		if ( false === get_option( self::POLICY_OPTION, false ) ) {
			return $this->policy();
		}
		$policy   = $this->policy();
		$positive = is_numeric( $record['final_price'] ?? null ) && (float) $record['final_price'] > 0
			&& is_numeric( $record['weight_grams'] ?? null ) && (float) $record['weight_grams'] > 0;
		if ( empty( $policy['enabled'] ) || ! $this->policy_matches_source( $policy, $source )
			|| ( ! $positive && empty( $policy['allow_non_positive'] ) ) ) {
			return new WP_Error( 'digitalogic_patris_creation_policy_blocked', 'Missing product creation is outside the configured owner policy.' );
		}
		return $policy;
	}

	/**
	 * Ensure one bounded catalog-reconciliation watchdog is queued.
	 *
	 * @return bool Whether a current or newly-created schedule exists.
	 */
	public function ensure_reconciliation_watchdog() {
		return $this->schedule_reconciliation_watchdog( self::WATCHDOG_INTERVAL );
	}

	/**
	 * Wake the watchdog sooner when committed receiver state still has pending work.
	 *
	 * @param array $before Previous receiver state (unused).
	 * @param array $after  Committed receiver state.
	 * @return void
	 */
	public function wake_reconciliation_watchdog( $before, $after ) {
		unset( $before );
		if ( $this->pending_count( $after ) > 0 ) {
			$this->schedule_reconciliation_watchdog( self::WATCHDOG_RETRY_INTERVAL, true );
		}
	}

	/**
	 * Reconcile one receiver-owned batch and persist a nonsecret operational receipt.
	 *
	 * @return array|WP_Error Receiver reconciliation result.
	 */
	public function run_reconciliation_watchdog() {
		$started_at = gmdate( 'c' );
		$previous   = get_option( self::WATCHDOG_OPTION, array() );
		$previous   = is_array( $previous ) ? $previous : array();
		$this->persist_watchdog_status(
			array_merge(
				$previous,
				array(
					'last_started_at' => $started_at,
					'last_status'     => 'running',
					'last_error'      => null,
				)
			)
		);

		$result      = Digitalogic_Product_Sync_Receiver::instance()->reconcile(
			null,
			null,
			self::WATCHDOG_BATCH_LIMIT
		);
		$finished_at = gmdate( 'c' );
		$delay       = self::WATCHDOG_INTERVAL;
		if ( is_wp_error( $result ) ) {
			$delay  = self::WATCHDOG_RETRY_INTERVAL;
			$status = array_merge(
				$previous,
				array(
					'last_started_at'  => $started_at,
					'last_finished_at' => $finished_at,
					'last_status'      => 'error',
					'last_error'       => array(
						'code'    => (string) $result->get_error_code(),
						'message' => (string) $result->get_error_message(),
					),
				)
			);
		} else {
			$pending  = max( 0, (int) ( $result['pending_products'] ?? 0 ) );
			$deferred = max( 0, (int) ( $result['deferred_products'] ?? 0 ) );
			if ( $pending > 0 ) {
				$delay = self::WATCHDOG_RETRY_INTERVAL;
			}
			$status = array_merge(
				$previous,
				array(
					'last_started_at'                     => $started_at,
					'last_finished_at'                    => $finished_at,
					'last_status'                         => (string) ( $result['status'] ?? 'completed' ),
					'last_error'                          => null,
					'pending_products'                    => $pending,
					'deferred_products'                   => $deferred,
					'materialization_queued'              => max( 0, (int) ( $result['materialization_queued'] ?? 0 ) ),
					'materialization_metadata_backfilled' => max( 0, (int) ( $result['materialization_metadata_backfilled'] ?? 0 ) ),
					'materialization_mismatch_stopped'    => max( 0, (int) ( $result['materialization_mismatch_stopped'] ?? 0 ) ),
					'source_count'                        => max( 0, (int) ( $result['source_count'] ?? 0 ) ),
				)
			);
		}

		$this->persist_watchdog_status( $status );
		$this->schedule_reconciliation_watchdog( $delay, true );

		return $result;
	}

	/**
	 * Return bounded watchdog status with the live next-run timestamp.
	 *
	 * @return array
	 */
	public function watchdog_status() {
		$stored = get_option( self::WATCHDOG_OPTION, array() );
		$stored = $this->bounded_watchdog_status( is_array( $stored ) ? $stored : array() );
		$next   = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::WATCHDOG_HOOK, array() ) : false;

		return array_merge(
			array(
				'scheduled'        => false !== $next,
				'next_run_at'      => false !== $next ? gmdate( 'c', (int) $next ) : null,
				'interval_seconds' => self::WATCHDOG_INTERVAL,
				'retry_seconds'    => self::WATCHDOG_RETRY_INTERVAL,
				'batch_limit'      => self::WATCHDOG_BATCH_LIMIT,
				'last_started_at'  => null,
				'last_finished_at' => null,
				'last_status'      => 'never_run',
				'last_error'       => null,
			),
			$stored
		);
	}

	/** Remove only this plugin-owned watchdog schedule. */
	public static function deactivate_reconciliation_watchdog() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::WATCHDOG_HOOK, array() );
		}
	}

	/**
	 * Count pending delivery records in a committed receiver state.
	 *
	 * @param array $state Receiver state.
	 * @return int
	 */
	private function pending_count( $state ) {
		$count = 0;
		foreach ( (array) ( is_array( $state ) ? ( $state['sources'] ?? array() ) : array() ) as $source ) {
			$count += count( (array) ( is_array( $source ) ? ( $source['pending_products'] ?? array() ) : array() ) );
		}
		return $count;
	}

	/**
	 * Queue exactly one future watchdog event, optionally bringing it forward.
	 *
	 * @param int  $delay Seconds from now.
	 * @param bool $replace_later Replace an existing later event.
	 * @return bool
	 */
	private function schedule_reconciliation_watchdog( $delay, $replace_later = false ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$timestamp = time() + max( 60, (int) $delay );
		$current   = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::WATCHDOG_HOOK, array() ) : false;
		if ( false !== $current && ( ! $replace_later || (int) $current <= $timestamp ) ) {
			return true;
		}
		if ( false !== $current && function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::WATCHDOG_HOOK, array() );
		}
		$scheduled = wp_schedule_single_event( $timestamp, self::WATCHDOG_HOOK, array(), true );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			$status = get_option( self::WATCHDOG_OPTION, array() );
			$status = is_array( $status ) ? $status : array();

			$status['schedule_error'] = is_wp_error( $scheduled )
				? (string) $scheduled->get_error_code()
				: 'schedule_failed';
			$this->persist_watchdog_status( $status );
			return false;
		}
		$status = get_option( self::WATCHDOG_OPTION, array() );
		if ( is_array( $status ) && array_key_exists( 'schedule_error', $status ) ) {
			unset( $status['schedule_error'] );
			$this->persist_watchdog_status( $status );
		}
		return true;
	}

	/**
	 * Persist watchdog diagnostics with option-cache readback.
	 *
	 * @param array $status Operational status fields.
	 * @return bool Whether exact readback succeeded.
	 */
	private function persist_watchdog_status( $status ) {
		$status = $this->bounded_watchdog_status( is_array( $status ) ? $status : array() );
		update_option( self::WATCHDOG_OPTION, $status, false );
		wp_cache_delete( self::WATCHDOG_OPTION, 'options' );
		return get_option( self::WATCHDOG_OPTION, array() ) === $status;
	}

	/**
	 * Keep operational readback fixed-shape and free of unbounded error data.
	 *
	 * @param array $status Operational status fields.
	 * @return array Bounded public status fields.
	 */
	private function bounded_watchdog_status( $status ) {
		$bounded = array_intersect_key(
			$status,
			array_flip(
				array(
					'last_started_at',
					'last_finished_at',
					'last_status',
					'last_error',
					'pending_products',
					'deferred_products',
					'materialization_queued',
					'materialization_metadata_backfilled',
					'materialization_mismatch_stopped',
					'source_count',
					'schedule_error',
				)
			)
		);
		foreach ( array( 'last_started_at', 'last_finished_at', 'last_status', 'schedule_error' ) as $field ) {
			if ( array_key_exists( $field, $bounded ) && null !== $bounded[ $field ] ) {
				$bounded[ $field ] = substr( (string) $bounded[ $field ], 0, 191 );
			}
		}
		foreach ( array( 'pending_products', 'deferred_products', 'materialization_queued', 'materialization_metadata_backfilled', 'materialization_mismatch_stopped', 'source_count' ) as $field ) {
			if ( array_key_exists( $field, $bounded ) ) {
				$bounded[ $field ] = max( 0, (int) $bounded[ $field ] );
			}
		}
		if ( null !== ( $bounded['last_error'] ?? null ) ) {
			$error = is_array( $bounded['last_error'] ) ? $bounded['last_error'] : array();

			$bounded['last_error'] = array(
				'code'    => substr( (string) ( $error['code'] ?? '' ), 0, 191 ),
				'message' => substr( (string) ( $error['message'] ?? '' ), 0, 300 ),
			);
		}
		return $bounded;
	}
	/**
	 * Read the effective creation policy.
	 *
	 * @return array
	 */
	public function policy() {
		$stored = get_option(
			self::POLICY_OPTION,
			array(
				'enabled'            => true,
				'status'             => 'publish',
				'allow_non_positive' => true,
				'batch_limit'        => self::MAX_BATCH,
			)
		);
		return $this->normalize_policy( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist and verify the owner creation policy.
	 *
	 * @param array $policy Requested owner policy.
	 * @return array|WP_Error
	 */
	public function configure( $policy ) {
		$normalized               = $this->normalize_policy( $policy );
		$normalized['updated_at'] = gmdate( 'c' );
		if ( ! update_option( self::POLICY_OPTION, $normalized, false ) ) {
			$current = get_option( self::POLICY_OPTION, array() );
			if ( ! is_array( $current ) || $this->canonical_hash( $current ) !== $this->canonical_hash( $normalized ) ) {
				return new WP_Error( 'digitalogic_patris_backfill_policy_store_failed', 'The catalog materialization policy could not be stored.' );
			}
		}
		wp_cache_delete( self::POLICY_OPTION, 'options' );
		$readback = get_option( self::POLICY_OPTION, array() );
		if ( ! is_array( $readback ) || $this->canonical_hash( $readback ) !== $this->canonical_hash( $normalized ) ) {
			return new WP_Error( 'digitalogic_patris_backfill_policy_readback_failed', 'The catalog materialization policy failed readback.' );
		}
		return $normalized;
	}

	/**
	 * Normalize supported owner policy fields.
	 *
	 * @param array $policy Stored or requested policy.
	 * @return array
	 */
	private function normalize_policy( $policy ) {
		$status = strtolower( trim( (string) ( $policy['status'] ?? 'draft' ) ) );
		$status = 'publish' === $status ? 'publish' : 'draft';
		$limit  = (int) ( $policy['batch_limit'] ?? 25 );
		$limit  = max( 1, min( self::MAX_BATCH, $limit ) );
		return array(
			'enabled'            => $this->bool_value( $policy['enabled'] ?? false ),
			'allow_non_positive' => $this->bool_value( $policy['allow_non_positive'] ?? false ),
			'status'             => $status,
			'batch_limit'        => $limit,
			'source_id'          => trim( (string) ( $policy['source_id'] ?? '' ) ),
			'dataset'            => trim( (string) ( $policy['dataset'] ?? '' ) ),
			'updated_at'         => is_string( $policy['updated_at'] ?? null ) ? $policy['updated_at'] : '',
		);
	}

	/**
	 * Compare the exact configured source identity.
	 *
	 * @param array $policy Normalized policy.
	 * @param array $source Incoming source identity.
	 * @return bool
	 */
	private function policy_matches_source( $policy, $source ) {
		return '' !== (string) $policy['source_id'] && '' !== (string) $policy['dataset']
			&& hash_equals( (string) $policy['source_id'], (string) ( $source['id'] ?? '' ) )
			&& hash_equals( (string) $policy['dataset'], (string) ( $source['dataset'] ?? '' ) );
	}

	/**
	 * Normalize a policy boolean.
	 *
	 * @param mixed $value Stored or command value.
	 * @return bool
	 */
	private function bool_value( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === strtolower( trim( (string) $value ) );
	}

	/**
	 * Hash a policy for exact persistence verification.
	 *
	 * @param mixed $value Policy value.
	 * @return string
	 */
	private function canonical_hash( $value ) {
		return hash( 'sha256', maybe_serialize( $value ) );
	}

	/**
	 * Select policy fields exposed to operators.
	 *
	 * @param array $policy Normalized policy.
	 * @return array
	 */
	private function public_policy( $policy ) {
		return array_intersect_key( $policy, array_flip( array( 'enabled', 'allow_non_positive', 'status', 'batch_limit', 'source_id', 'dataset', 'updated_at' ) ) );
	}

	/**
	 * Keep pricing routes independent of client names.
	 *
	 * @param array $endpoints Registered REST routes.
	 * @return array
	 */
	public function remove_client_named_pricing_routes( $endpoints ) {
		foreach ( array( 'excel', 'spreadsheet' ) as $client ) {
			foreach ( array( 'state', 'preview', 'apply' ) as $mode ) {
				unset( $endpoints[ '/digitalogic/' . $client . '/pricing-sync/' . $mode ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Require WooCommerce management before policy access.
	 *
	 * @return void
	 */
	private function require_cli_admin() {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this management capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			WP_CLI::error( 'Use --user=<administrator> with WooCommerce management capability.' );
		}
	}

	/**
	 * Attach the shared owner policy to pricing responses.
	 *
	 * @param WP_REST_Response $response Response to enrich.
	 * @param WP_REST_Server   $server Current REST server.
	 * @param WP_REST_Request  $request Current request.
	 * @return WP_REST_Response
	 */
	public function enrich_pricing_response( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		if ( ! preg_match( '#\A/digitalogic/pricing/sync/(state|preview|apply)\z#D', $request->get_route() ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['schema'] ) ) {
			return $response;
		}
		$data['catalog_materialization'] = array(
			'policy' => $this->public_policy( $this->policy() ),
			'owner'  => 'product_sync_receiver',
		);
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Register the privileged owner policy command.
	 *
	 * @return void
	 */
	private function register_cli() {
		$service = $this;
		WP_CLI::add_command(
			'digitalogic product-sync backfill-policy',
			function ( $args, $assoc ) use ( $service ) {
				$service->require_cli_admin();
				$current = $service->policy();
				if ( empty( $assoc ) ) {
					WP_CLI::line( wp_json_encode( $service->public_policy( $current ) ) );
					return;
				}
				$next   = array_merge(
					$current,
					array(
						'enabled'            => $assoc['enabled'] ?? $current['enabled'],
						'allow_non_positive' => $assoc['allow-non-positive'] ?? $current['allow_non_positive'],
						'status'             => $assoc['status'] ?? $current['status'],
						'batch_limit'        => $assoc['limit'] ?? $current['batch_limit'],
						'source_id'          => $assoc['source-id'] ?? $current['source_id'],
						'dataset'            => $assoc['dataset'] ?? $current['dataset'],
					)
				);
				$result = $service->configure( $next );
				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result );
				}
				WP_CLI::line( wp_json_encode( $service->public_policy( $result ) ) );
			}
		);
	}
}
