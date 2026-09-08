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
	const POLICY_OPTION = 'digitalogic_patris_catalog_backfill_policy_v1';
	const MAX_BATCH     = 100;
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
			|| ( ! $positive && ( 'publish' === $policy['status'] || empty( $policy['allow_non_positive'] ) ) ) ) {
			return new WP_Error( 'digitalogic_patris_creation_policy_blocked', 'Missing product creation is outside the configured owner policy.' );
		}
		return $policy;
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
