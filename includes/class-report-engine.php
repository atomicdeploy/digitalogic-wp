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

	private const MAX_SOURCE_PRODUCTS = 10000;
	private const MAX_WOO_PRODUCTS    = 10000;
	private const WOO_BATCH_SIZE      = 100;
	private const MAX_PAGE_SIZE       = 100;
	private const CACHE_GROUP          = 'digitalogic_reports';
	private const CACHE_TTL            = 300;
	private const CACHE_GENERATION_KEY = 'generation-v1';
	private const BUILD_LOCK_TTL       = 180;

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
	private $build_lock_token = '';
	private $active_build_lock_key = '';
	private $local_cache_generation = 'initial';

	/** Register every source mutation that can make a report stale. */
	private function __construct() {
		add_action( 'save_post_product', array( $this, 'invalidate_cache' ) );
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_product_updated', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_product_sync_applied', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_patris_feed_synced', array( $this, 'invalidate_cache' ) );
		add_action( 'digitalogic_woocommerce_currency_changed', array( $this, 'invalidate_cache' ) );
		add_action( 'updated_option', array( $this, 'invalidate_cache_for_option' ), 10, 3 );
		add_action( 'added_option', array( $this, 'invalidate_cache_for_added_option' ), 10, 2 );
		add_action( 'deleted_option', array( $this, 'invalidate_cache_for_deleted_option' ) );
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
				array( 'status' => 503, 'retry_after' => 2 )
			);
		}

		try {
			// A previous request may have populated this exact page while this
			// request was waiting for the atomic lock.
			$report = $force_refresh ? null : $this->get_cached_report( $args );
			if ( ! is_array( $report ) ) {
				$build_generation = $this->cache_generation();
				$selection        = $this->select_source_state( $args );
				$report           = $this->build_report( $args, $selection );
				if ( ! $this->set_cached_report( $args, $report, $build_generation ) ) {
					return new WP_Error(
						'digitalogic_report_source_changed',
						__( 'Report source data changed while the report was being built. Please retry.', 'digitalogic' ),
						array( 'status' => 503, 'retry_after' => 1 )
					);
				}
			}
		} finally {
			$this->release_build_lock();
		}

		return $report;
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
			$products[ $code ] = $product;
		}
		ksort( $products, SORT_STRING );

		$state = array(
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
	 * @param array $args Normalized report arguments.
	 * @param array $selection Selected source state.
	 * @return array
	 */
	private function build_report( $args, $selection ) {
		$state       = $selection['state'];
		$source      = $selection['source'];
		$products    = is_array( $state['products'] ?? null ) ? $state['products'] : array();
		$truncated   = count( $products ) > self::MAX_SOURCE_PRODUCTS;
		$products    = array_slice( $products, 0, self::MAX_SOURCE_PRODUCTS, true );
		$settings    = Digitalogic_Patris_Feed::instance()->get_settings();
		$stale_hours = max( 1, absint( $settings['stale_after_hours'] ?? 48 ) );
		$woo_result  = $this->get_woocommerce_products();
		$woo_rows    = $woo_result['products'];
		$woo_by_code = array();

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
		foreach ( $source_available ? $products : array() as $key => $product ) {
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

		$woo_only = 0;
		foreach ( $source_available ? $woo_rows : array() as $woo ) {
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

		usort(
			$rows,
			static function ( $left, $right ) {
				$code = strcmp( (string) $left['product_code'], (string) $right['product_code'] );
				return 0 !== $code ? $code : (int) ( $left['woo_id'] ?? 0 ) <=> (int) ( $right['woo_id'] ?? 0 );
			}
		);

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
		$pages         = max( 1, (int) ceil( $total / $args['per_page'] ) );
		$page          = min( $args['page'], $pages );
		$page_rows     = array_slice( $selected_rows, ( $page - 1 ) * $args['per_page'], $args['per_page'] );

		foreach ( $page_rows as $row ) {
			foreach ( $row['issues'] as $issue ) {
				if ( isset( $categories[ $issue ] ) ) {
					$categories[ $issue ]['items'][] = $row;
				}
			}
		}

		$report = array(
			'generated_at' => current_time( 'mysql' ),
			'status'       => $selection['status'],
			'brand'        => array(
				'en' => 'Digitalogic',
				'fa' => 'دیجیتالاجیک',
			),
			'view'         => $args['view'],
			'counts'       => array(
				'woocommerce_products'      => count( $woo_rows ),
				'patris_products'           => count( $products ),
				'matched_products'          => $matched,
				'source_only_products'      => $source_only,
				'positive_source_only_products' => $positive_only,
				'woocommerce_only_products' => $woo_only,
				'ambiguous_codes'           => $ambiguous,
				'warning_products'          => $warning_products,
				'drift_products'            => $drift_products,
				'variable_parents_excluded' => $woo_result['variable_parents_excluded'],
			),
			'pagination'   => array(
				'page'     => $page,
				'per_page' => $args['per_page'],
				'total'    => $total,
				'pages'    => $pages,
			),
			'limits'       => array(
				'max_source_products'      => self::MAX_SOURCE_PRODUCTS,
				'max_woocommerce_products' => self::MAX_WOO_PRODUCTS,
				'source_truncated'         => $truncated,
				'woocommerce_truncated'    => $woo_result['truncated'],
			),
			'filters'      => array(
				'category' => $args['category'],
			),
			'rows'         => $page_rows,
			'categories'   => array_values( $categories ),
		);

		if ( ! empty( $source ) ) {
			$report['source'] = $source;
		}

		return $report;
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
		if ( ! $found || ! is_array( $report ) || ! isset( $report['_cache_generation'] ) ) {
			return null;
		}

		$cached_generation = (string) $report['_cache_generation'];
		unset( $report['_cache_generation'] );
		if ( ! hash_equals( $this->cache_generation(), $cached_generation ) ) {
			$this->delete_cached_report( $args );
			return null;
		}

		return $report;
	}

	/** Invalidate every request-shaped report without requiring a key registry. */
	public function invalidate_cache() {
		$token                        = $this->new_cache_token();
		$this->local_cache_generation = $token;
		if ( function_exists( 'wp_cache_set' ) ) {
			try {
				wp_cache_set( self::CACHE_GENERATION_KEY, $token, self::CACHE_GROUP, 0 );
			} catch ( Throwable $error ) {
				// A cache backend outage must not make product writes fail.
			}
		}
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
				'dollar_price',
				'yuan_price',
				'options_dollar_price',
				'options_yuan_price',
				'woocommerce_currency',
			),
			true
		);
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
	private function set_cached_report( $args, $report, $build_generation ) {
		if ( ! function_exists( 'wp_cache_set' ) || ! is_array( $report ) ) {
			return true;
		}

		$build_generation = (string) $build_generation;
		if ( ! hash_equals( $build_generation, $this->cache_generation() ) ) {
			return false;
		}

		$cached_report                      = $report;
		$cached_report['_cache_generation'] = $build_generation;
		try {
			wp_cache_set( $this->cache_key( $args ), $cached_report, self::CACHE_GROUP, self::CACHE_TTL );
		} catch ( Throwable $error ) {
			return true;
		}

		if ( ! hash_equals( $build_generation, $this->cache_generation() ) ) {
			$this->delete_cached_report( $args );
			return false;
		}

		return true;
	}

	/** Read the current source-generation token. */
	private function cache_generation() {
		if ( ! function_exists( 'wp_cache_get' ) ) {
			return $this->local_cache_generation;
		}

		$found = false;
		try {
			$generation = wp_cache_get( self::CACHE_GENERATION_KEY, self::CACHE_GROUP, false, $found );
		} catch ( Throwable $error ) {
			return $this->local_cache_generation;
		}

		return $found && is_string( $generation ) && '' !== $generation
			? $generation
			: $this->local_cache_generation;
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
			'source_id' => (string) $args['source_id'],
			'dataset'   => (string) $args['dataset'],
		);
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $shape ) : json_encode( $shape );

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
	 * @return array{products:array,variable_parents_excluded:int,truncated:bool}
	 */
	private function get_woocommerce_products() {
		$rows              = array();
		$variable_excluded = 0;
		$fetched           = 0;
		$page              = 1;
		$truncated         = false;
		$statuses          = array( 'publish', 'draft', 'pending', 'private' );
		$product_types     = function_exists( 'wc_get_product_types' )
			? array_keys( (array) wc_get_product_types() )
			: array( 'simple', 'grouped', 'external', 'variable' );
		$product_types[]   = 'variation';
		$product_types     = array_values( array_unique( $product_types ) );

		while ( $fetched <= self::MAX_WOO_PRODUCTS ) {
			$remaining = self::MAX_WOO_PRODUCTS + 1 - $fetched;
			$limit     = min( self::WOO_BATCH_SIZE, $remaining );
			$batch     = wc_get_products(
				array(
					'status'  => $statuses,
					'type'    => $product_types,
					'limit'   => $limit,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$batch     = is_array( $batch ) ? $batch : array();
			if ( empty( $batch ) ) {
				break;
			}
			$batch_count = count( $batch );

			foreach ( $batch as $product ) {
				++$fetched;
				if ( $fetched > self::MAX_WOO_PRODUCTS ) {
					$truncated = true;
					break 2;
				}
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				if ( $product->is_type( 'variable' ) ) {
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
		);
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
			'foreign_currency'               => '_digitalogic_patris_foreign_currency',
			'foreign_price'                  => '_digitalogic_patris_foreign_price',
			'weight_grams'                   => '_digitalogic_patris_weight_grams',
			'total_stock'                    => '_digitalogic_patris_total_stock',
			'shipping_method_id'             => '_digitalogic_patris_shipping_method_id',
			'shipping_price_per_kg'          => '_digitalogic_patris_shipping_price_per_kg',
			'shipping_price_per_kg_currency' => '_digitalogic_patris_shipping_price_per_kg_currency',
			'markup_percent'                 => '_digitalogic_patris_markup_percent',
			'irt_per_cny'                    => '_digitalogic_patris_irt_per_cny',
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
		foreach ( array( 'name', 'foreign_currency', 'foreign_price', 'weight_grams', 'final_price' ) as $field ) {
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
		$this->append_required_number_issue( $row, $source, 'foreign_price', 'missing_foreign_price', 'null_foreign_price', true );
		$this->append_required_number_issue( $row, $source, 'weight_grams', 'missing_weight', 'null_weight', true );
		$this->append_required_number_issue( $row, $source, 'total_stock', 'missing_stock', 'null_stock', false );
		$this->append_required_number_issue( $row, $source, 'final_price', 'missing_final_price', 'null_final_price', true );
		$this->append_required_number_issue( $row, $source, 'markup_percent', 'missing_markup', 'null_markup', false );
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

		if ( ! array_key_exists( 'foreign_currency', $source ) ) {
			$this->add_issue( $row, 'missing_foreign_currency' );
		} elseif ( null === $source['foreign_currency'] ) {
			$this->add_issue( $row, 'null_foreign_currency' );
		} elseif ( 'CNY' !== $source['foreign_currency'] ) {
			$this->add_issue( $row, 'unexpected_foreign_currency' );
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
			$this->add_issue( $row, 'source_warning' );
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

		if ( array_key_exists( 'final_price', $source ) && null !== $source['final_price'] ) {
			$stock_unavailable  = array_key_exists( 'total_stock', $source )
				&& null !== $source['total_stock']
				&& is_numeric( $source['total_stock'] )
				&& $this->decimal_compare_zero( $source['total_stock'] ) <= 0;
			$expected_woo_price = $this->decimal_compare_zero( $source['final_price'] ) <= 0 || $stock_unavailable
				? '0'
				: $source['final_price'];
			$price_fields = array();
			foreach ( array( 'regular_price', 'active_price' ) as $field ) {
				if ( ! $this->values_equal( $expected_woo_price, $woo[ $field ] ) ) {
					$price_fields[] = $field;
				}
			}
			if ( '' !== $woo['sale_price'] && ! $this->values_equal( $expected_woo_price, $woo['sale_price'] ) ) {
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
			$expected_woo_stock = null === $source['total_stock'] || ! is_numeric( $source['total_stock'] )
				? null
				: (int) round( (float) $source['total_stock'] );
			$drift              = ! $this->values_equal( $expected_woo_stock, $woo['stock_quantity'] );
			$drift              = $drift || ! array_key_exists( 'total_stock', $canonical ) || ! $this->values_equal( $source['total_stock'], $canonical['total_stock'] );
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
			'missing_in_woocommerce'      => array( __( 'In Patris but missing in WooCommerce', 'digitalogic' ), 'danger' ),
			'positive_stock_missing_in_woocommerce' => array( __( 'Positive-stock product missing in WooCommerce', 'digitalogic' ), 'danger' ),
			'missing_in_patris'           => array( __( 'In WooCommerce but missing in Patris', 'digitalogic' ), 'warning' ),
			'missing_product_code'        => array( __( 'Missing exact product Code metadata', 'digitalogic' ), 'danger' ),
			'duplicate_product_code'      => array( __( 'Duplicate exact product Code metadata', 'digitalogic' ), 'danger' ),
			'source_warning'              => array( __( 'Source warnings require attention', 'digitalogic' ), 'warning' ),
			'missing_foreign_currency'    => array( __( 'Missing foreign currency', 'digitalogic' ), 'warning' ),
			'null_foreign_currency'       => array( __( 'Foreign currency is explicitly null', 'digitalogic' ), 'warning' ),
			'unexpected_foreign_currency' => array( __( 'Foreign currency is not CNY', 'digitalogic' ), 'warning' ),
			'missing_foreign_price'       => array( __( 'Missing CNY price', 'digitalogic' ), 'warning' ),
			'null_foreign_price'          => array( __( 'CNY price is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_weight'              => array( __( 'Missing weight', 'digitalogic' ), 'warning' ),
			'null_weight'                 => array( __( 'Weight is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_stock'               => array( __( 'Missing stock', 'digitalogic' ), 'warning' ),
			'null_stock'                  => array( __( 'Stock is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_final_price'         => array( __( 'Missing calculated price', 'digitalogic' ), 'danger' ),
			'null_final_price'            => array( __( 'Calculated price is explicitly null', 'digitalogic' ), 'danger' ),
			'missing_shipping'            => array( __( 'Missing shipping price inputs', 'digitalogic' ), 'warning' ),
			'null_shipping'               => array( __( 'Shipping price inputs contain explicit null', 'digitalogic' ), 'warning' ),
			'missing_markup'              => array( __( 'Missing profit margin', 'digitalogic' ), 'warning' ),
			'null_markup'                 => array( __( 'Profit margin is explicitly null', 'digitalogic' ), 'warning' ),
			'missing_exchange_rate'       => array( __( 'Missing CNY exchange rate', 'digitalogic' ), 'warning' ),
			'null_exchange_rate'          => array( __( 'CNY exchange rate is explicitly null', 'digitalogic' ), 'warning' ),
			'invalid_source_value'        => array( __( 'Invalid source value', 'digitalogic' ), 'danger' ),
			'zero_stock'                  => array( __( 'Zero or negative stock', 'digitalogic' ), 'info' ),
			'zero_price'                  => array( __( 'Zero or negative calculated price', 'digitalogic' ), 'danger' ),
			'missing_source_updated_at'   => array( __( 'Missing source update time', 'digitalogic' ), 'warning' ),
			'null_source_updated_at'      => array( __( 'Source update time is explicitly null', 'digitalogic' ), 'warning' ),
			'stale_source'                => array( __( 'Stale source data', 'digitalogic' ), 'warning' ),
			'price_drift'                 => array( __( 'Price differs from the current source', 'digitalogic' ), 'danger' ),
			'stock_drift'                 => array( __( 'Stock differs from the current source', 'digitalogic' ), 'danger' ),
			'stock_management_drift'      => array( __( 'Stock management differs from the current source', 'digitalogic' ), 'danger' ),
			'stock_status_drift'          => array( __( 'Stock availability differs from the current source', 'digitalogic' ), 'danger' ),
			'weight_drift'                => array( __( 'Weight differs from the current source', 'digitalogic' ), 'danger' ),
			'record_hash_drift'           => array( __( 'Record hash differs from the current source', 'digitalogic' ), 'danger' ),
			'source_updated_at_drift'     => array( __( 'Source update time differs in WooCommerce', 'digitalogic' ), 'warning' ),
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
