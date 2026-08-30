<?php
/**
 * Bounded WooCommerce product-type cache-prefix repair command.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Repair only product cache prefixes selected by the current drift report.
 */
class Digitalogic_Product_Type_Cache_CLI {
	private const OUTPUT_SCHEMA             = 'digitalogic.product-type-cache-repair/v1';
	private const MAX_CANDIDATES            = 100;
	private const MAX_VARIATIONS_PER_PARENT = 1000;

	/**
	 * Audit or repair stale WooCommerce product-type cache prefixes.
	 *
	 * The command derives candidates from a freshly rebuilt current Patris
	 * report. It never accepts product IDs and never changes a post, taxonomy,
	 * product type, variation, price, or other catalog value.
	 *
	 * ## OPTIONS
	 *
	 * [--source-id=<source-id>]
	 * : Exact current source ID. Required with --apply.
	 *
	 * [--dataset=<dataset>]
	 * : Exact current source dataset. Required with --apply.
	 *
	 * [--apply]
	 * : Invalidate the derived Woo cache prefix for every validated candidate.
	 *
	 * [--max-candidates=<count>]
	 * : Required with --apply. Refuse if the current report contains more.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic product-type-cache repair --user=<administrator>
	 *     wp digitalogic product-type-cache repair --source-id=patris-office --dataset=kala.db --apply --max-candidates=15 --user=<administrator>
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 * @when after_wp_load
	 */
	public function repair( $args, $assoc_args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run this command with --user=<administrator>.' );
			return;
		}
		if ( ! empty( $args ) ) {
			WP_CLI::error( 'This command does not accept positional arguments.' );
			return;
		}

		$parsed = $this->parse_arguments( $assoc_args );
		if ( is_wp_error( $parsed ) ) {
			WP_CLI::error( $parsed->get_error_message() );
			return;
		}

		$report = $this->build_report( $parsed );
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
			return;
		}

		$candidates = $this->report_candidates( $report, $parsed );
		if ( is_wp_error( $candidates ) ) {
			WP_CLI::error( $candidates->get_error_message() );
			return;
		}

		$validated = array();
		foreach ( $candidates as $candidate ) {
			$validation = $this->validate_candidate( $candidate );
			if ( is_wp_error( $validation ) ) {
				WP_CLI::error( $validation->get_error_message() );
				return;
			}
			$validated[] = $validation;
		}

		$output = array(
			'schema'                   => self::OUTPUT_SCHEMA,
			'mode'                     => $parsed['apply'] ? 'applied' : 'dry-run',
			'source'                   => (array) $report['source'],
			'snapshot_revision'        => (string) ( $report['snapshot_revision'] ?? '' ),
			'candidate_revision'       => $this->candidate_revision( $report, $validated ),
			'candidate_count'          => count( $validated ),
			'repaired_count'           => 0,
			'remaining_drift_count'    => count( $validated ),
			'cache_groups_invalidated' => array(),
			'items'                    => $validated,
			'catalog_writes'           => array(
				'products'   => 0,
				'taxonomies' => 0,
				'prices'     => 0,
			),
		);

		if ( ! $parsed['apply'] ) {
			WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
			return;
		}

		foreach ( $validated as $index => $item ) {
			$product_id  = (int) $item['woocommerce_id'];
			$invalidated = $this->invalidate_product_cache_prefix( $product_id );
			if ( is_wp_error( $invalidated ) ) {
				$output['items'][ $index ]['repair_error'] = $invalidated->get_error_message();
				WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
				WP_CLI::error( 'Product-type cache repair stopped before cache-prefix invalidation.' );
				return;
			}
			$output['cache_groups_invalidated'][] = 'product_' . $product_id;

			$result = $this->verify_repaired_candidate( $item );
			if ( is_wp_error( $result ) ) {
				$output['items'][ $index ]['repair_error'] = $result->get_error_message();
				WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
				WP_CLI::error( 'Product-type cache repair stopped after a failed readback.' );
				return;
			}

			$output['items'][ $index ] = $result;
			++$output['repaired_count'];
			$output['remaining_drift_count'] = $output['candidate_count'] - $output['repaired_count'];
		}

		$readback = $this->build_report( $parsed );
		if ( is_wp_error( $readback ) ) {
			WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
			WP_CLI::error( 'Product-type cache repair could not rebuild the post-repair report.' );
			return;
		}
		$remaining = $this->drift_warnings( $readback );
		if ( is_wp_error( $remaining ) ) {
			WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
			WP_CLI::error( $remaining->get_error_message() );
			return;
		}

		$output['remaining_drift_count'] = count( $remaining );
		WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
		if ( ! empty( $remaining ) ) {
			WP_CLI::error( 'The post-repair report still contains product-type cache drift.' );
		}
	}

	/**
	 * Parse the bounded fail-closed command contract.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return array|WP_Error
	 */
	private function parse_arguments( $assoc_args ) {
		$allowed = array( 'apply', 'dataset', 'max-candidates', 'source-id' );
		foreach ( array_keys( (array) $assoc_args ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				return new WP_Error( 'digitalogic_product_type_cache_argument', 'Unknown command argument.' );
			}
		}

		$apply     = isset( $assoc_args['apply'] );
		$source_id = isset( $assoc_args['source-id'] ) ? trim( (string) $assoc_args['source-id'] ) : '';
		$dataset   = isset( $assoc_args['dataset'] ) ? trim( (string) $assoc_args['dataset'] ) : '';
		if ( ( '' === $source_id ) !== ( '' === $dataset ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_scope', 'Specify both --source-id and --dataset, or neither.' );
		}
		if ( $apply && ( '' === $source_id || '' === $dataset ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_scope', 'An exact --source-id and --dataset are required with --apply.' );
		}

		$maximum = self::MAX_CANDIDATES;
		if ( isset( $assoc_args['max-candidates'] ) ) {
			$value = (string) $assoc_args['max-candidates'];
			if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || (int) $value > self::MAX_CANDIDATES ) {
				return new WP_Error( 'digitalogic_product_type_cache_limit', '--max-candidates must be a canonical integer from 0 through 100.' );
			}
			$maximum = (int) $value;
		} elseif ( $apply ) {
			return new WP_Error( 'digitalogic_product_type_cache_limit', '--max-candidates is required with --apply.' );
		}

		return array(
			'apply'          => $apply,
			'source_id'      => $source_id,
			'dataset'        => $dataset,
			'max_candidates' => $maximum,
		);
	}

	/**
	 * Build a fresh complete report in the asserted source scope.
	 *
	 * @param array $parsed Parsed command arguments.
	 * @return array|WP_Error
	 */
	protected function build_report( $parsed ) {
		$args = array(
			'view'          => 'price_list',
			'force_refresh' => true,
		);
		if ( '' !== $parsed['source_id'] ) {
			$args['source_id'] = $parsed['source_id'];
			$args['dataset']   = $parsed['dataset'];
		}

		return Digitalogic_Report_Engine::instance()->get_complete_report( $args );
	}

	/**
	 * Select exact cache-drift warnings and reject an unsafe report.
	 *
	 * @param array $report Fresh complete report.
	 * @param array $parsed Parsed command arguments.
	 * @return array|WP_Error
	 */
	private function report_candidates( $report, $parsed ) {
		if ( 'current' !== (string) ( $report['status'] ?? '' ) || ! is_array( $report['source'] ?? null ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_report', 'The current source report is unavailable.' );
		}
		if ( ! empty( $report['limits']['source_truncated'] ) || ! empty( $report['limits']['woocommerce_truncated'] ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_report', 'The current source report is truncated.' );
		}
		if (
			'' !== $parsed['source_id']
			&& (
				(string) ( $report['source']['id'] ?? '' ) !== $parsed['source_id']
				|| (string) ( $report['source']['dataset'] ?? '' ) !== $parsed['dataset']
			)
		) {
			return new WP_Error( 'digitalogic_product_type_cache_scope', 'The rebuilt report does not match the asserted source scope.' );
		}

		$warnings = $this->drift_warnings( $report );
		if ( is_wp_error( $warnings ) ) {
			return $warnings;
		}
		if ( count( $warnings ) > $parsed['max_candidates'] ) {
			return new WP_Error( 'digitalogic_product_type_cache_limit', 'The current candidate count exceeds --max-candidates; no cache was changed.' );
		}

		return $warnings;
	}

	/**
	 * Return repairable warnings, rejecting every unrelated integrity failure.
	 *
	 * @param array $report Complete report.
	 * @return array|WP_Error
	 */
	private function drift_warnings( $report ) {
		$candidates = array();
		foreach ( (array) ( $report['integrity']['warnings'] ?? array() ) as $warning ) {
			if ( ! is_array( $warning ) || 'product_type_cache_drift' !== (string) ( $warning['code'] ?? '' ) ) {
				return new WP_Error( 'digitalogic_product_type_cache_integrity', 'The report contains an unrelated integrity warning; no cache was changed.' );
			}
			$product_id = absint( $warning['woocommerce_id'] ?? 0 );
			if (
				$product_id <= 0
				|| 'variable' !== (string) ( $warning['durable_type'] ?? '' )
				|| 'simple' !== (string) ( $warning['object_type'] ?? '' )
				|| isset( $candidates[ $product_id ] )
			) {
				return new WP_Error( 'digitalogic_product_type_cache_integrity', 'The cache-drift warning shape is unsupported or ambiguous; no cache was changed.' );
			}
			$candidates[ $product_id ] = $warning;
		}
		ksort( $candidates, SORT_NUMERIC );

		return array_values( $candidates );
	}

	/**
	 * Verify one report-derived candidate before any cache mutation.
	 *
	 * @param array $candidate One report warning.
	 * @return array|WP_Error
	 */
	private function validate_candidate( $candidate ) {
		$product_id = (int) $candidate['woocommerce_id'];
		if ( 'product' !== $this->read_post_type( $product_id ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_candidate', 'A candidate is not a current WooCommerce product; no cache was changed.' );
		}

		$product = $this->read_product( $product_id );
		if ( ! $product instanceof WC_Product || 'simple' !== (string) $product->get_type() ) {
			return new WP_Error( 'digitalogic_product_type_cache_candidate', 'A candidate factory class changed since the report; no cache was changed.' );
		}

		$durable = $this->read_durable_product_type( $product_id );
		if ( is_wp_error( $durable ) ) {
			return $durable;
		}
		$variations = $this->read_variation_ids( $product_id );
		if ( is_wp_error( $variations ) ) {
			return $variations;
		}

		return array(
			'woocommerce_id'  => $product_id,
			'variation_count' => count( $variations ),
			'variation_ids'   => $variations,
			'before'          => array(
				'factory_class' => get_class( $product ),
				'factory_type'  => (string) $product->get_type(),
				'durable_type'  => $durable,
			),
		);
	}

	/**
	 * Verify all durable facts after one exact cache-prefix invalidation.
	 *
	 * @param array $item Validated candidate evidence.
	 * @return array|WP_Error
	 */
	private function verify_repaired_candidate( $item ) {
		$product_id = (int) $item['woocommerce_id'];
		$product    = $this->read_product( $product_id );
		$durable    = $this->read_durable_product_type( $product_id );
		$variations = $this->read_variation_ids( $product_id );
		if (
			! $product instanceof WC_Product
			|| 'variable' !== (string) $product->get_type()
			|| is_wp_error( $durable )
			|| 'variable' !== $durable
			|| is_wp_error( $variations )
			|| $item['variation_ids'] !== $variations
		) {
			return new WP_Error( 'digitalogic_product_type_cache_readback', 'Factory, taxonomy, or variation readback failed after cache-prefix invalidation.' );
		}

		$item['after'] = array(
			'factory_class' => get_class( $product ),
			'factory_type'  => (string) $product->get_type(),
			'durable_type'  => $durable,
		);

		return $item;
	}

	/**
	 * Read the product object returned by the current WooCommerce factory.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|false
	 */
	protected function read_product( $product_id ) {
		return wc_get_product( $product_id );
	}

	/**
	 * Read the current post type.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	protected function read_post_type( $product_id ) {
		return (string) get_post_type( $product_id );
	}

	/**
	 * Read the single durable product_type term without factory type caches.
	 *
	 * @param int $product_id Product ID.
	 * @return string|WP_Error
	 */
	protected function read_durable_product_type( $product_id ) {
		$terms = wp_get_object_terms(
			array( $product_id ),
			'product_type',
			array(
				'fields'  => 'all_with_object_id',
				'orderby' => 'none',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_taxonomy', 'The durable product-type taxonomy could not be read; no cache was changed.' );
		}

		$types = array();
		foreach ( (array) $terms as $term ) {
			$type = sanitize_title( (string) ( $term->slug ?? $term->name ?? '' ) );
			if ( '' !== $type ) {
				$types[ $type ] = true;
			}
		}
		$types = array_keys( $types );
		sort( $types, SORT_STRING );
		if ( array( 'variable' ) !== $types ) {
			return new WP_Error( 'digitalogic_product_type_cache_taxonomy', 'A candidate no longer has exactly one durable variable product-type term; no cache was changed.' );
		}

		return 'variable';
	}

	/**
	 * Read a bounded exact list of nondeleted variation IDs.
	 *
	 * @param int $product_id Parent product ID.
	 * @return int[]|WP_Error
	 */
	protected function read_variation_ids( $product_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_parent'    => $product_id,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'fields'         => 'ids',
				'numberposts'    => self::MAX_VARIATIONS_PER_PARENT + 1,
				'posts_per_page' => self::MAX_VARIATIONS_PER_PARENT + 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		if ( empty( $ids ) || count( $ids ) > self::MAX_VARIATIONS_PER_PARENT ) {
			return new WP_Error( 'digitalogic_product_type_cache_variations', 'A candidate has no variation or exceeds the bounded variation readback; no cache was changed.' );
		}

		return $ids;
	}

	/**
	 * Invalidate only the WooCommerce per-product cache prefix.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	protected function invalidate_product_cache_prefix( $product_id ) {
		if ( ! is_callable( array( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) ) {
			return new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce cache-prefix invalidation is unavailable.' );
		}

		$instance_cache = $this->remove_product_instance_cache( $product_id );
		if ( is_wp_error( $instance_cache ) ) {
			return $instance_cache;
		}

		if ( function_exists( 'clean_object_term_cache' ) ) {
			clean_object_term_cache( $product_id, 'product' );
		}
		WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );

		return true;
	}

	/**
	 * Remove WooCommerce's optional product-object cache entry.
	 *
	 * WooCommerce 10.5 introduced an independent ProductCache in front of the
	 * product-type data-store cache. Absence is a successful no-op; an actual
	 * container/cache failure remains fail closed so readback is never claimed.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	protected function remove_product_instance_cache( $product_id ) {
		$class = 'Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
		if ( ! class_exists( $class ) || ! function_exists( 'wc_get_container' ) ) {
			return true;
		}

		try {
			$cache = wc_get_container()->get( $class );
			if ( ! is_object( $cache ) || ! is_callable( array( $cache, 'remove' ) ) ) {
				return new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce product-object cache invalidation is unavailable.' );
			}
			$cache->remove( (int) $product_id );
		} catch ( Throwable $error ) {
			unset( $error );

			return new WP_Error( 'digitalogic_product_type_cache_unavailable', 'WooCommerce product-object cache invalidation failed.' );
		}

		return true;
	}

	/**
	 * Hash the exact source/report/candidate preconditions shown to operators.
	 *
	 * @param array $report    Complete report.
	 * @param array $validated Validated candidate evidence.
	 * @return string
	 */
	private function candidate_revision( $report, $validated ) {
		$evidence = array(
			'source'            => (array) ( $report['source'] ?? array() ),
			'snapshot_revision' => (string) ( $report['snapshot_revision'] ?? '' ),
			'candidates'        => $validated,
		);

		return 'sha256:' . hash( 'sha256', wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES ) );
	}
}

WP_CLI::add_command(
	'digitalogic product-type-cache repair',
	array( 'Digitalogic_Product_Type_Cache_CLI', 'repair' )
);
