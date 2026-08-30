<?php
/**
 * Provider-neutral pricing integration boundaries.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// These tiny contracts and their default implementations intentionally live
// together so the boundary is loaded atomically before either legacy
// orchestrator. Public methods are self-describing and documented at the
// interface or class level.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.VariableComment.Missing

/** Source protocol and authentication boundary. */
interface Digitalogic_Pricing_Provider_Adapter_Interface {
	public function authorize( WP_REST_Request $request, $source = null );
	public function scopes();
	public function normalize_source( $source );
	public function current_source( $source );
	public function source_state( $source_id, $dataset );
	public function source_status();
	public function event_principal();
	public function state_option_name();
	public function settings_option_name();
	public function stale_after_seconds();
	public function capabilities();
}

/** Store and pricing-engine boundary. */
interface Digitalogic_Pricing_Store_Adapter_Interface {
	public function pricing_state();
	public function pricing_settings_option();
	public function pricing_stale_after_days();
	public function catalog_revision( $source );
	public function catalog_snapshot( $args, $checkpoint = null );
	public function catalog_page( $args );
	public function max_page_size();
	public function invalidate_projection( $effect_id = null );
	public function reprice_open_transaction( $settings, $shipping_revision );
	public function repricing_cache_plan();
	public function flush_repricing_caches( $plan = null );
	public function publish_repricing_result( $result );
	public function with_repricing_lock( $callback );
	public function capabilities();
}

/** Downstream projection boundary. */
interface Digitalogic_Pricing_Consumer_Adapter_Interface {
	public function identity();
	public function project_catalog( $catalog );
	public function capabilities();
}

/**
 * Canonical semantic normalization shared by every adapter.
 *
 * Raw provider extensions never participate in identity. Only the normalized
 * model returned here is allowed into orchestration and safety decisions.
 */
final class Digitalogic_Pricing_Canonical_Model {

	/** Normalize a source identity while treating revision as a capability. */
	public static function source( $source ) {
		if ( ! is_array( $source ) || array_is_list( $source ) ) {
			return self::error( 'digitalogic_pricing_source_invalid', 'The source identity must be a JSON object.', 'source' );
		}
		foreach ( array( 'id', 'dataset' ) as $field ) {
			$value = $source[ $field ] ?? null;
			if ( ! is_string( $value ) || trim( $value ) !== $value || '' === $value || strlen( $value ) > 191 ) {
				return self::error( 'digitalogic_pricing_source_invalid', 'The source identity is missing or unsafe.', 'source.' . $field );
			}
		}

		$revision = '';
		if ( isset( $source['revision'] ) && '' !== $source['revision'] ) {
			if ( ! is_string( $source['revision'] ) || 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $source['revision'] ) ) {
				return self::error( 'digitalogic_pricing_source_revision_invalid', 'The optional source revision is unsafe.', 'source.revision' );
			}
			$revision = $source['revision'];
		}

		return array(
			'id'       => self::unicode( $source['id'] ),
			'dataset'  => self::unicode( $source['dataset'] ),
			'revision' => $revision,
		);
	}

	/** Produce a stable semantic digest independent of key order and scalar representation. */
	public static function digest( $value ) {
		$canonical = self::canonicalize( $value );
		$json      = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return 'sha256:' . hash( 'sha256', false === $json ? 'null' : $json );
	}

	/** Normalize recursively for deterministic semantic comparison. */
	public static function canonicalize( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				return array_map(
					static function ( $item ) use ( $key ) {
						return self::canonicalize( $item, $key );
					},
					array_values( $value )
				);
			}
			ksort( $value, SORT_STRING );
			$result = array();
			foreach ( $value as $child_key => $child ) {
				$result[ (string) $child_key ] = self::canonicalize( $child, (string) $child_key );
			}
			return $result;
		}
		if ( is_string( $value ) ) {
			$value = self::unicode( $value );
			if ( self::numeric_key( $key ) && 1 === preg_match( '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value ) ) {
				return self::decimal( $value );
			}
			if ( self::date_key( $key ) ) {
				$timestamp = strtotime( $value );
				if ( false !== $timestamp ) {
					return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
				}
			}
			return $value;
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && self::numeric_key( $key ) ) {
			return self::decimal( (string) $value );
		}

		return $value;
	}

	private static function numeric_key( $key ) {
		return 1 === preg_match( '/(?:amount|price|rate|percent|margin|weight|quantity|stock|count|total|page|limit|digits|seconds)\z/i', $key );
	}

	private static function date_key( $key ) {
		return 1 === preg_match( '/(?:date|_at)\z/i', $key );
	}

	private static function decimal( $value ) {
		$negative = str_starts_with( $value, '-' );
		$value    = $negative ? substr( $value, 1 ) : $value;
		$parts    = explode( '.', $value, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		$result   = $integer . ( '' === $fraction ? '' : '.' . $fraction );

		return $negative && '0' !== $result ? '-' . $result : $result;
	}

	private static function unicode( $value ) {
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $value, Normalizer::FORM_C );
			if ( is_string( $normalized ) ) {
				return $normalized;
			}
		}

		return $value;
	}

	private static function error( $code, $reason, $field ) {
		return new WP_Error(
			$code,
			$reason,
			array(
				'status'          => 400,
				'severity'        => 'error',
				'blocking'        => true,
				'retryable'       => false,
				'reason'          => $reason,
				'recovery_action' => 'correct_' . str_replace( '.', '_', $field ),
				'field'           => $field,
			)
		);
	}
}

/** Capability intersection and bounded recovery planning. */
final class Digitalogic_Pricing_Capabilities {
	private const FLAGS = array( 'revision', 'conditional_request', 'etag', 'incremental_sync', 'events', 'delete_tracking' );

	public static function negotiate( ...$documents ) {
		$documents = array_values( array_filter( $documents, 'is_array' ) );
		$result    = array();
		foreach ( self::FLAGS as $flag ) {
			$result[ $flag ] = ! empty( $documents );
			foreach ( $documents as $document ) {
				$result[ $flag ] = $result[ $flag ] && true === ( $document[ $flag ] ?? false );
			}
		}

		$algorithms = null;
		foreach ( $documents as $document ) {
			$current    = array_values( array_intersect( array( 'sha256' ), (array) ( $document['digest_algorithms'] ?? array() ) ) );
			$algorithms = null === $algorithms ? $current : array_values( array_intersect( $algorithms, $current ) );
		}
		$result['digest_algorithms'] = null === $algorithms ? array() : $algorithms;

		$order = array();
		if ( $result['events'] ) {
			$order[] = 'events';
		}
		if ( $result['incremental_sync'] ) {
			$order[] = 'incremental_sync';
		} elseif ( $result['conditional_request'] ) {
			$order[] = 'conditional_request';
		}
		$order[]                  = 'polling';
		$result['recovery_order'] = $order;
		$result['polling']        = array(
			'max_attempts'   => 5,
			'interval_ms'    => 2000,
			'max_elapsed_ms' => 30000,
		);

		return $result;
	}
}

/** Patris protocol adapter; provider vocabulary is contained here. */
final class Digitalogic_Patris_Pricing_Provider_Adapter implements Digitalogic_Pricing_Provider_Adapter_Interface {
	public function authorize( WP_REST_Request $request, $source = null ) {
		$feed = Digitalogic_Patris_Feed::instance();
		if ( empty( $feed->get_product_sync_source_scopes() ) ) {
			return new WP_Error( 'digitalogic_pricing_scope_required', 'An exact provider source scope is required.', array( 'status' => 403 ) );
		}
		$allowed = is_array( $source )
			? $feed->verify_product_sync_request_for_source( $request, $source, false )
			: $feed->verify_product_sync_request( $request );

		return $allowed ? true : new WP_Error( 'digitalogic_pricing_unauthorized', 'The provider credential or source scope is invalid.', array( 'status' => 401 ) );
	}

	public function scopes() {
		return Digitalogic_Patris_Feed::instance()->get_product_sync_source_scopes();
	}

	public function normalize_source( $source ) {
		return Digitalogic_Pricing_Canonical_Model::source( $source );
	}

	public function current_source( $source ) {
		$source = $this->normalize_source( $source );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$state   = $this->source_state( $source['id'], $source['dataset'] );
		$current = is_array( $state['source'] ?? null ) ? $state['source'] : array();
		if (
			! is_string( $current['id'] ?? null )
			|| ! is_string( $current['dataset'] ?? null )
			|| ! is_string( $current['revision'] ?? null )
			|| ! hash_equals( $source['id'], $current['id'] )
			|| ! hash_equals( $source['dataset'], $current['dataset'] )
			|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $current['revision'] )
		) {
			return new WP_Error(
				'digitalogic_pricing_source_scope_conflict',
				'The materialized source identity is unavailable or ambiguous.',
				array(
					'status'   => 409,
					'blocking' => true,
				)
			);
		}
		$submitted = '' !== $source['revision'] ? $source['revision'] : $current['revision'];

		return array(
			'id'                       => $source['id'],
			'dataset'                  => $source['dataset'],
			'submitted_revision'       => $submitted,
			'current_revision'         => $current['revision'],
			'revision_matches_current' => hash_equals( $current['revision'], $submitted ),
			'revision_capability'      => '' !== $source['revision'],
		);
	}

	public function source_state( $source_id, $dataset ) {
		return Digitalogic_Product_Sync_Receiver::instance()->get_source_state( $source_id, $dataset );
	}

	public function source_status() {
		return Digitalogic_Product_Sync_Receiver::instance()->get_status();
	}

	public function event_principal() {
		return 'patris_pricing';
	}

	public function state_option_name() {
		return Digitalogic_Product_Sync_Receiver::STATE_OPTION;
	}

	public function settings_option_name() {
		return 'digitalogic_patris_feed_settings';
	}

	public function stale_after_seconds() {
		$settings = Digitalogic_Patris_Feed::instance()->get_settings();
		return max( 1, absint( $settings['stale_after_hours'] ?? 48 ) ) * HOUR_IN_SECONDS;
	}

	public function capabilities() {
		return array(
			'revision'            => true,
			'conditional_request' => true,
			'etag'                => true,
			'incremental_sync'    => false,
			'events'              => true,
			'delete_tracking'     => true,
			'digest_algorithms'   => array( 'sha256' ),
		);
	}
}

/** WooCommerce/report projection adapter; store vocabulary is contained here. */
final class Digitalogic_WooCommerce_Pricing_Store_Adapter implements Digitalogic_Pricing_Store_Adapter_Interface {
	public function pricing_state() {
		return Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
	}

	public function pricing_settings_option() {
		return Digitalogic_Excel_Pricing_Sync::SETTINGS_OPTION;
	}

	public function pricing_stale_after_days() {
		return Digitalogic_Excel_Pricing_Sync::STALE_AFTER_DAYS;
	}

	public function catalog_revision( $source ) {
		return Digitalogic_Report_Engine::instance()->projection_revision( $source['id'], $source['dataset'] );
	}

	public function catalog_snapshot( $args, $checkpoint = null ) {
		return Digitalogic_Google_Sheets_Catalog::instance()->get_reconciled_products_snapshot( $args, $checkpoint );
	}

	public function catalog_page( $args ) {
		return Digitalogic_Google_Sheets_Catalog::instance()->get_page( $args );
	}

	public function max_page_size() {
		return Digitalogic_Google_Sheets_Catalog::MAX_PAGE_SIZE;
	}

	public function invalidate_projection( $effect_id = null ) {
		return null === $effect_id
			? Digitalogic_Report_Engine::instance()->invalidate_cache()
			: Digitalogic_Report_Engine::instance()->invalidate_cache_for_effect( $effect_id );
	}

	public function reprice_open_transaction( $settings, $shipping_revision ) {
		return Digitalogic_Pricing_Coordinator::instance()->reprice_open_transaction( $settings, $shipping_revision );
	}

	public function repricing_cache_plan() {
		return Digitalogic_Pricing_Coordinator::instance()->repricing_cache_plan();
	}

	public function flush_repricing_caches( $plan = null ) {
		return null === $plan
			? Digitalogic_Pricing_Coordinator::instance()->flush_repricing_caches()
			: Digitalogic_Pricing_Coordinator::instance()->flush_repricing_caches( $plan );
	}

	public function publish_repricing_result( $result ) {
		return Digitalogic_Pricing_Coordinator::instance()->publish_repricing_result( $result );
	}

	public function with_repricing_lock( $callback ) {
		return Digitalogic_Pricing_Coordinator::instance()->with_repricing_lock( $callback );
	}

	public function capabilities() {
		return array(
			'revision'            => true,
			'conditional_request' => true,
			'etag'                => true,
			'incremental_sync'    => false,
			'events'              => true,
			'delete_tracking'     => true,
			'digest_algorithms'   => array( 'sha256' ),
		);
	}
}

/** Excel workbook consumer adapter; workbook columns are contained here. */
final class Digitalogic_Excel_Pricing_Consumer_Adapter implements Digitalogic_Pricing_Consumer_Adapter_Interface {
	private const FIELDS = array(
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

	public function identity() {
		return array(
			'consumer_id' => 'digitalogic-price-calculator',
			'channel'     => 'excel-workbook',
			'capability'  => 'pricing_settings_ack',
		);
	}

	public function project_catalog( $catalog ) {
		$columns_by_key = array();
		foreach ( (array) ( $catalog['columns'] ?? array() ) as $column ) {
			$key = is_array( $column ) ? (string) ( $column['key'] ?? '' ) : '';
			if ( '' === $key || isset( $columns_by_key[ $key ] ) ) {
				return $this->schema_error();
			}
			$columns_by_key[ $key ] = $column;
		}
		$columns = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! isset( $columns_by_key[ $field ] ) ) {
				return $this->schema_error();
			}
			$columns[] = $columns_by_key[ $field ];
		}

		$rows = array();
		foreach ( (array) ( $catalog['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				return $this->schema_error();
			}
			$projected = array();
			foreach ( self::FIELDS as $field ) {
				if ( ! array_key_exists( $field, $row ) ) {
					return $this->schema_error();
				}
				$projected[ $field ] = $row[ $field ];
			}
			$rows[] = $projected;
		}
		$catalog['columns'] = $columns;
		$catalog['rows']    = $rows;

		return $catalog;
	}

	public function capabilities() {
		return array(
			'revision'            => true,
			'conditional_request' => true,
			'etag'                => true,
			'incremental_sync'    => false,
			'events'              => true,
			'delete_tracking'     => true,
			'digest_algorithms'   => array( 'sha256' ),
		);
	}

	private function schema_error() {
		return new WP_Error(
			'digitalogic_pricing_snapshot_projection_schema_invalid',
			'The canonical catalog cannot satisfy the consumer projection.',
			array(
				'status'          => 503,
				'severity'        => 'error',
				'blocking'        => true,
				'retryable'       => false,
				'reason'          => 'A required consumer field is missing or ambiguous.',
				'recovery_action' => 'repair_consumer_projection',
				'required_fields' => self::FIELDS,
			)
		);
	}
}

/** Request-local registry used by core orchestration and contract tests. */
final class Digitalogic_Pricing_Adapter_Registry {
	private static $instance = null;
	private $provider;
	private $store;
	private $consumer;

	private function __construct() {
		$this->provider = new Digitalogic_Patris_Pricing_Provider_Adapter();
		$this->store    = new Digitalogic_WooCommerce_Pricing_Store_Adapter();
		$this->consumer = new Digitalogic_Excel_Pricing_Consumer_Adapter();
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function reset() {
		self::$instance = null;
	}

	public function provider() {
		return $this->provider;
	}

	public function store() {
		return $this->store;
	}

	public function consumer() {
		return $this->consumer;
	}

	public function register_provider( Digitalogic_Pricing_Provider_Adapter_Interface $adapter ) {
		$this->provider = $adapter;
	}

	public function register_store( Digitalogic_Pricing_Store_Adapter_Interface $adapter ) {
		$this->store = $adapter;
	}

	public function register_consumer( Digitalogic_Pricing_Consumer_Adapter_Interface $adapter ) {
		$this->consumer = $adapter;
	}

	public function capabilities() {
		return Digitalogic_Pricing_Capabilities::negotiate(
			$this->provider->capabilities(),
			$this->store->capabilities(),
			$this->consumer->capabilities()
		);
	}
}
