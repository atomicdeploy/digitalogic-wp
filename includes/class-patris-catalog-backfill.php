<?php
/**
 * Creation policy and transport presentation for the shared Patris materializer.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Patris_Catalog_Backfill {
	const POLICY_OPTION = 'digitalogic_patris_catalog_backfill_policy_v1';
	const MAX_BATCH = 100;
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'rest_endpoints', array( $this, 'remove_client_named_pricing_routes' ), 1000 );
		add_filter( 'rest_post_dispatch', array( $this, 'enrich_pricing_response' ), 20, 3 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->register_cli();
		}
	}

	/** Read fresh policy only at the missing-identity creation boundary. */
	public function creation_policy( $record, $source ) {
		if ( false === get_option( self::POLICY_OPTION, false ) ) {
			return $this->policy();
		}
		$policy = $this->policy();
		$positive = is_numeric( $record['final_price'] ?? null ) && (float) $record['final_price'] > 0
			&& is_numeric( $record['weight_grams'] ?? null ) && (float) $record['weight_grams'] > 0;
		if ( empty( $policy['enabled'] ) || ! $this->policy_matches_source( $policy, $source )
			|| ( ! $positive && ( 'publish' === $policy['status'] || empty( $policy['allow_non_positive'] ) ) ) ) {
			return new WP_Error( 'digitalogic_patris_creation_policy_blocked', 'Missing product creation is outside the configured owner policy.' );
		}
		return $policy;
	}
	public function policy() {
		$stored = get_option( self::POLICY_OPTION, array( 'enabled' => true, 'status' => 'publish', 'allow_non_positive' => true, 'batch_limit' => self::MAX_BATCH ) );
		return $this->normalize_policy( is_array( $stored ) ? $stored : array() );
	}

	public function configure( $policy ) {
		$normalized = $this->normalize_policy( $policy );
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

	private function normalize_policy( $policy ) {
		$status = strtolower( trim( (string) ( $policy['status'] ?? 'draft' ) ) );
		$status = 'publish' === $status ? 'publish' : 'draft';
		$limit = (int) ( $policy['batch_limit'] ?? 25 );
		$limit = max( 1, min( self::MAX_BATCH, $limit ) );
		return array(
			'enabled' => $this->bool_value( $policy['enabled'] ?? false ),
			'allow_non_positive' => $this->bool_value( $policy['allow_non_positive'] ?? false ),
			'status' => $status,
			'batch_limit' => $limit,
			'source_id' => trim( (string) ( $policy['source_id'] ?? '' ) ),
			'dataset' => trim( (string) ( $policy['dataset'] ?? '' ) ),
			'updated_at' => is_string( $policy['updated_at'] ?? null ) ? $policy['updated_at'] : '',
		);
	}

	private function policy_matches_source( $policy, $source ) {
		return '' !== (string) $policy['source_id'] && '' !== (string) $policy['dataset']
			&& hash_equals( (string) $policy['source_id'], (string) ( $source['id'] ?? '' ) )
			&& hash_equals( (string) $policy['dataset'], (string) ( $source['dataset'] ?? '' ) );
	}

	private function bool_value( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === strtolower( trim( (string) $value ) );
	}

	private function canonical_hash( $value ) {
		return hash( 'sha256', maybe_serialize( $value ) );
	}

	private function public_policy( $policy ) {
		return array_intersect_key( $policy, array_flip( array( 'enabled', 'allow_non_positive', 'status', 'batch_limit', 'source_id', 'dataset', 'updated_at' ) ) );
	}

	public function remove_client_named_pricing_routes( $endpoints ) {
		foreach ( array( 'excel', 'spreadsheet' ) as $client ) {
			foreach ( array( 'state', 'preview', 'apply' ) as $mode ) {
				unset( $endpoints[ '/digitalogic/' . $client . '/pricing-sync/' . $mode ] );
			}
		}
		return $endpoints;
	}

	private function require_cli_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			WP_CLI::error( 'Use --user=<administrator> with WooCommerce management capability.' );
		}
	}

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
		$data['catalog_materialization'] = array( 'policy' => $this->public_policy( $this->policy() ), 'owner' => 'product_sync_receiver' );
		$response->set_data( $data );
		return $response;
	}

	private function register_cli() {
		$service = $this;
		WP_CLI::add_command( 'digitalogic product-sync backfill-policy', function ( $args, $assoc ) use ( $service ) {
			$service->require_cli_admin();
			$current = $service->policy();
			if ( empty( $assoc ) ) {
				WP_CLI::line( wp_json_encode( $service->public_policy( $current ) ) );
				return;
			}
			$next = array_merge( $current, array(
				'enabled' => $assoc['enabled'] ?? $current['enabled'],
				'allow_non_positive' => $assoc['allow-non-positive'] ?? $current['allow_non_positive'],
				'status' => $assoc['status'] ?? $current['status'],
				'batch_limit' => $assoc['limit'] ?? $current['batch_limit'],
				'source_id' => $assoc['source-id'] ?? $current['source_id'],
				'dataset' => $assoc['dataset'] ?? $current['dataset'],
			) );
			$result = $service->configure( $next );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result );
			}
			WP_CLI::line( wp_json_encode( $service->public_policy( $result ) ) );
		} );
	}
}
