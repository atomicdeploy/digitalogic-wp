<?php
/**
 * Canonical, transport-neutral catalog projection for Google Sheets clients.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds bounded Products and Categories datasets from the shared store model.
 */
final class Digitalogic_Google_Sheets_Catalog {

	public const MAX_PAGE_SIZE             = 250;
	private const PRODUCT_QUERY_PAGE_SIZE  = 100;
	private const RECONCILED_EXCEL_KEYS = array(
		'sync_key',
		'reconciliation_status',
		'patris_code',
		'woocommerce_id',
		'parent_id',
		'product_type',
		'publication_status',
		'name',
		'part_number',
		'sku',
		'categories',
		'category_ids',
		'currency',
		'regular_price',
		'sale_price',
		'effective_price',
		'patris_final_price',
		'price_status',
		'stock_quantity',
		'stock_status',
		'patris_total_stock',
		'patris_minimum_stock',
		'patris_location',
		'weight_grams',
		'woocommerce_weight',
		'woocommerce_weight_unit',
		'foreign_price',
		'foreign_currency',
		'partner_price_irr',
		'price_source_amount',
		'price_source_currency',
		'price_source_kind',
		'price_rounding_digits',
		'price_rounding_mode',
		'shipping_method_id',
		'shipping_method_name_en',
		'shipping_method_name_fa',
		'shipping_price_per_kg',
		'shipping_price_per_kg_currency',
		'profit_margin_percent',
		'permalink',
		'image_url',
		'updated_at',
		'sync_status',
		'sync_error',
		'record_revision',
	);

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared catalog projector.
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
	 * Build one bounded dataset page.
	 *
	 * @param array $args Request arguments.
	 * @return array|WP_Error
	 */
	public function get_page( $args = array() ) {
		$args = $this->normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( 'categories' === $args['dataset'] ) {
			return $this->get_categories_page( $args );
		}

		if ( 'reconciled_products' === $args['dataset'] ) {
			return $this->get_reconciled_products_page( $args );
		}

		return $this->get_products_page( $args );
	}

	/**
	 * Return a cheap fail-closed revision for every catalog projection input.
	 *
	 * @param array $args Optional exact source arguments.
	 * @return array|WP_Error
	 */
	public function get_revision( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		$source_id = isset( $args['source_id'] ) && is_scalar( $args['source_id'] )
			? sanitize_text_field( (string) $args['source_id'] )
			: '';
		$source_dataset = isset( $args['source_dataset'] ) && is_scalar( $args['source_dataset'] )
			? sanitize_text_field( (string) $args['source_dataset'] )
			: '';
		$revision = Digitalogic_Report_Engine::instance()->projection_revision( $source_id, $source_dataset );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) $revision ) ) {
			return new WP_Error(
				'digitalogic_sheets_catalog_revision_invalid',
				__( 'The catalog projection revision is unavailable.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		return array(
			'schema'   => 'digitalogic.google-sheets-catalog-revision/v1',
			'revision' => (string) $revision,
		);
	}

	/**
	 * Build the complete reconciled catalog once for snapshot storage.
	 *
	 * The public paged catalog contract remains unchanged. This trusted service
	 * method lets the pricing snapshot worker reconcile WooCommerce and Patris
	 * once, then persist inexpensive immutable transport pages.
	 *
	 * @param array         $args       Trusted locale and exact source arguments.
	 * @param callable|null $checkpoint Optional cancellation/progress checkpoint.
	 * @return array|WP_Error
	 */
	public function get_reconciled_products_snapshot( $args = array(), $checkpoint = null ) {
		$args            = is_array( $args ) ? $args : array();
		$args['dataset'] = 'reconciled_products';
		$args            = $this->normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$report_args = array(
			'view' => 'price_list',
		);
		if ( '' !== $args['source_id'] && '' !== $args['source_dataset'] ) {
			$report_args['source_id'] = $args['source_id'];
			$report_args['dataset']   = $args['source_dataset'];
		}

		if ( is_callable( $checkpoint ) && false === call_user_func( $checkpoint, 'reconciling', 5, 0, 0 ) ) {
			return $this->snapshot_cancelled_error();
		}

		$report = Digitalogic_Report_Engine::instance()->get_complete_report( $report_args, $checkpoint );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$integrity_warnings = array_values( (array) ( $report['integrity']['warnings'] ?? array() ) );
		if ( 'current' !== (string) ( $report['status'] ?? '' ) ) {
			return new WP_Error(
				'digitalogic_reconciled_projection_source_not_current',
				__( 'The exact reconciled source is not current.', 'digitalogic' ),
				array(
					'status'    => 409,
					'retryable' => false,
				)
			);
		}
		if ( ! empty( $report['limits']['source_truncated'] ) || ! empty( $report['limits']['woocommerce_truncated'] ) ) {
			return new WP_Error(
				'digitalogic_reconciled_projection_truncated',
				__( 'The complete reconciled projection exceeded a bounded source limit.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => false,
					'limits'    => (array) $report['limits'],
				)
			);
		}
		$blocking_integrity_warnings = $this->blocking_integrity_warnings( $integrity_warnings );
		if ( $blocking_integrity_warnings ) {
			return new WP_Error(
				'digitalogic_reconciled_projection_integrity_failed',
				__( 'The reconciled catalog contains an unsafe identity that cannot be projected.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 2,
					'retryable'   => true,
					'warnings'    => $blocking_integrity_warnings,
				)
			);
		}

		$dataset_revision = is_string( $report['snapshot_revision'] ?? null )
			? $report['snapshot_revision']
			: '';
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $dataset_revision ) ) {
			return new WP_Error(
				'digitalogic_reconciled_snapshot_revision_invalid',
				__( 'The reconciled catalog has no valid snapshot revision.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 2,
					'retryable'   => true,
				)
			);
		}

		$total       = absint( $report['pagination']['total'] ?? 0 );
		$report_rows = array_values( (array) ( $report['rows'] ?? array() ) );
		if ( count( $report_rows ) !== $total ) {
			return new WP_Error(
				'digitalogic_reconciled_snapshot_incomplete',
				__( 'The complete reconciled catalog row count is inconsistent.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 2,
					'retryable'   => true,
				)
			);
		}

		$integration_catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		if ( is_wp_error( $integration_catalog ) ) {
			return $integration_catalog;
		}

		$projection  = array(
			'columns' => null,
			'rows'    => array(),
		);
		$chunks      = array_chunk( $report_rows, 100 );
		$chunk_total = max( 1, count( $chunks ) );
		foreach ( $chunks as $index => $chunk ) {
			if (
				is_callable( $checkpoint )
				&& false === call_user_func( $checkpoint, 'transforming', 55 + (int) floor( 35 * $index / $chunk_total ), count( $projection['rows'] ), $total )
			) {
				return $this->snapshot_cancelled_error();
			}

			$chunk_projection = $this->transform_reconciled_products( $chunk, $integration_catalog );
			if ( is_wp_error( $chunk_projection ) ) {
				return $chunk_projection;
			}
			if ( null === $projection['columns'] ) {
				$projection['columns'] = $chunk_projection['columns'];
			} elseif ( $projection['columns'] !== $chunk_projection['columns'] ) {
				return new WP_Error(
					'digitalogic_reconciled_snapshot_schema_changed',
					__( 'The reconciled catalog schema changed while the snapshot was transformed.', 'digitalogic' ),
					array(
						'status'      => 503,
						'retry_after' => 2,
						'retryable'   => true,
					)
				);
			}
			$projection['rows'] = array_merge( $projection['rows'], $chunk_projection['rows'] );
		}
		if ( null === $projection['columns'] ) {
			$projection['columns'] = $this->reconciled_product_columns();
		}
		$column_keys = array_column( $projection['columns'], 'key' );
		if ( self::RECONCILED_EXCEL_KEYS !== $column_keys ) {
			return new WP_Error(
				'digitalogic_reconciled_snapshot_schema_changed',
				__( 'The reconciled catalog no longer matches the pinned Excel column contract.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => false,
				)
			);
		}

		$allowed_fields = array_fill_keys( self::RECONCILED_EXCEL_KEYS, true );
		$canonical_rows = array();
		foreach ( $projection['rows'] as $row ) {
			$unexpected = array_diff_key( (array) $row, $allowed_fields );
			if ( $unexpected ) {
				return new WP_Error(
					'digitalogic_reconciled_snapshot_row_schema_changed',
					__( 'A reconciled row contains fields outside the pinned Excel contract.', 'digitalogic' ),
					array(
						'status'            => 503,
						'retryable'         => false,
						'unexpected_fields' => array_values( array_keys( $unexpected ) ),
					)
				);
			}

			$canonical = array();
			foreach ( self::RECONCILED_EXCEL_KEYS as $field ) {
				$canonical[ $field ] = array_key_exists( $field, $row ) ? $row[ $field ] : null;
			}
			$canonical_rows[] = $canonical;
		}
		$projection['rows'] = $canonical_rows;
		if ( count( $projection['rows'] ) !== $total ) {
			return new WP_Error(
				'digitalogic_reconciled_snapshot_transform_incomplete',
				__( 'The reconciled catalog transform did not preserve every row.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 2,
					'retryable'   => true,
				)
			);
		}

		$response_args                = $args;
		$response_args['page']        = 1;
		$response_args['limit']       = max( 1, $total );
		$response                     = $this->response_envelope(
			$response_args,
			$projection['columns'],
			$projection['rows'],
			$total,
			1
		);
		$response['dataset_revision'] = $dataset_revision;
		$response['reconciliation']   = array(
			'status'           => (string) ( $report['status'] ?? '' ),
			'integrity_status' => (string) ( $report['integrity']['status'] ?? '' ),
			'warnings'         => $integrity_warnings,
			'counts'           => $this->reconciliation_counts( (array) ( $report['counts'] ?? array() ), $total ),
		);
		if ( is_array( $report['source'] ?? null ) ) {
			$response['reconciliation']['source'] = $report['source'];
		}

		return $response;
	}

	/**
	 * Convert canonical product-list rows into a Sheets-ready projection.
	 *
	 * This method is public so REST, WP-CLI, n8n, or another standalone adapter
	 * can reuse the exact same transformation without copying field rules.
	 *
	 * @param array      $products            Canonical product-manager rows.
	 * @param array|null $integration_catalog Optional preloaded integration catalog.
	 * @param array|null $assignment_batch    Optional preloaded assignment response.
	 * @return array|WP_Error Rows and their complete column definitions.
	 */
	public function transform_products( $products, $integration_catalog = null, $assignment_batch = null ) {
		$products = is_array( $products ) ? array_values( $products ) : array();
		if ( null === $integration_catalog ) {
			$integration_catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		}
		if ( is_wp_error( $integration_catalog ) ) {
			return $integration_catalog;
		}
		$integration_catalog = is_array( $integration_catalog ) ? $integration_catalog : array();

		$identifiers = array();
		foreach ( $products as $product ) {
			$identifier = $this->product_identifier( $product );
			if ( '' !== $identifier ) {
				$identifiers[ $identifier ] = true;
			}
		}

		if ( null === $assignment_batch ) {
			$assignment_batch = array( 'results' => array() );
			if ( $identifiers ) {
				$assignment_batch = Digitalogic_Shipping_Method_Service::instance()
					->get_product_assignments_by_codes( array_keys( $identifiers ) );
			}
		}
		if ( is_wp_error( $assignment_batch ) ) {
			return $assignment_batch;
		}

		$assignments = $this->index_assignments( $assignment_batch );
		$methods     = $this->index_methods( $integration_catalog );
		$warehouses  = $this->warehouse_names( $products, $integration_catalog );
		$currency    = isset( $integration_catalog['currency'] )
			&& is_array( $integration_catalog['currency'] )
			&& array_key_exists( 'local', $integration_catalog['currency'] )
			? $integration_catalog['currency']['local']
			: get_woocommerce_currency();
		$weight_unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
		$rows        = array();

		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}

			$identifier        = $this->product_identifier( $product );
			$assignment_result = '' !== $identifier && isset( $assignments[ $identifier ] )
				? $assignments[ $identifier ]
				: null;
			$assignment        = is_array( $assignment_result ) && 'ok' === ( $assignment_result['status'] ?? '' )
				? (array) ( $assignment_result['assignment'] ?? array() )
				: array();
			$method_id         = array_key_exists( 'shipping_method_id', $assignment )
				&& is_scalar( $assignment['shipping_method_id'] )
				? trim( (string) $assignment['shipping_method_id'] )
				: '';
			$method            = '' !== $method_id && isset( $methods[ $method_id ] ) ? $methods[ $method_id ] : array();
			$patris_code       = $identifier;
			$product_id        = absint( $product['id'] ?? 0 );
			$warnings          = array();
			// The raw Products dataset is Woo-backed, so its immutable Woo ID is
			// the row identity. Product Code is optional provider data and may be
			// assigned later; it must not rename an existing synchronized row.
			$sync_key = $product_id > 0 ? 'woo:' . $product_id : '';

			if ( '' === $sync_key ) {
				return new WP_Error(
					'digitalogic_sheets_sync_key_missing',
					__( 'Every raw catalog product must have a positive WooCommerce ID.', 'digitalogic' ),
					array( 'status' => 500 )
				);
			}

			if ( '' === $patris_code ) {
				$warnings[] = 'missing_patris_code';
			}
			if ( is_array( $assignment_result ) && 'error' === ( $assignment_result['status'] ?? '' ) ) {
				$warnings[] = (string) ( $assignment_result['error']['code'] ?? 'shipping_assignment_unavailable' );
			}
			foreach ( (array) ( $assignment['pricing_warnings'] ?? array() ) as $pricing_warning ) {
				if ( is_scalar( $pricing_warning ) && '' !== trim( (string) $pricing_warning ) ) {
					$warnings[] = trim( (string) $pricing_warning );
				}
			}
			if ( '' !== $method_id && ! $method ) {
				$warnings[] = 'shipping_method_not_found';
			}

			$effective_price = $this->first_present_value(
				$product,
				array( 'patris_final_price', 'price', 'sale_price', 'regular_price' )
			);
			if ( ! $effective_price['exists'] || null === $effective_price['value'] || '' === $effective_price['value'] ) {
				$warnings[] = 'missing_effective_price';
			}

			$row = array(
				'sync_key' => $sync_key,
			);

			$this->add_text_field( $row, 'patris_code', $product, 'patris_product_code', $warnings );
			$this->add_number_field( $row, 'woocommerce_id', $product, 'id', $warnings );
			$this->add_number_field( $row, 'parent_id', $product, 'parent_id', $warnings );
			$this->add_text_field( $row, 'product_type', $product, 'type', $warnings );
			$this->add_text_field( $row, 'publication_status', $product, 'status', $warnings );
			$this->add_text_field( $row, 'name', $product, 'name', $warnings );
			$this->add_text_field( $row, 'part_number', $product, 'part_number', $warnings );
			$this->add_text_field( $row, 'sku', $product, 'sku', $warnings );
			$this->add_categories_field( $row, $product, $warnings );
			$this->add_category_ids_field( $row, $product, $warnings );
			$this->add_explicit_text_value( $row, 'currency', $currency, $warnings );
			$this->add_number_field( $row, 'regular_price', $product, 'regular_price', $warnings );
			$this->add_number_field( $row, 'sale_price', $product, 'sale_price', $warnings );
			$this->add_explicit_number_value( $row, 'effective_price', $effective_price, $warnings );
			$this->add_number_field( $row, 'patris_final_price', $product, 'patris_final_price', $warnings );
			$this->add_text_field( $row, 'price_status', $product, 'patris_price_status', $warnings );
			$this->add_number_field( $row, 'stock_quantity', $product, 'stock_quantity', $warnings );
			$this->add_text_field( $row, 'stock_status', $product, 'stock_status', $warnings );
			$this->add_number_field( $row, 'patris_total_stock', $product, 'patris_total_stock', $warnings );
			$this->add_number_field( $row, 'patris_minimum_stock', $product, 'patris_minimum_stock', $warnings );
			$this->add_text_field( $row, 'patris_location', $product, 'patris_location', $warnings );
			$this->add_explicit_number_value( $row, 'weight_grams', $this->product_weight_grams( $product, $weight_unit, $warnings ), $warnings );
			$this->add_number_field( $row, 'woocommerce_weight', $product, 'weight', $warnings );
			$this->add_explicit_text_value( $row, 'woocommerce_weight_unit', $weight_unit, $warnings );
			$this->add_number_field( $row, 'foreign_price', $product, 'patris_foreign_price', $warnings );
			$this->add_text_field( $row, 'foreign_currency', $product, 'patris_foreign_currency', $warnings );
			$this->add_number_field( $row, 'partner_price_irr', $product, 'patris_partner_price_source', $warnings );
			$this->add_number_field( $row, 'price_source_amount', $product, 'patris_price_source_amount', $warnings );
			$this->add_text_field( $row, 'price_source_currency', $product, 'patris_price_source_currency', $warnings );
			$this->add_text_field( $row, 'price_source_kind', $product, 'patris_price_source_kind', $warnings );
			$this->add_number_field( $row, 'price_rounding_digits', $product, 'patris_price_rounding_digits', $warnings );
			$this->add_text_field( $row, 'price_rounding_mode', $product, 'patris_price_rounding_mode', $warnings );
			$this->add_text_field( $row, 'shipping_method_id', $assignment, 'shipping_method_id', $warnings );
			$this->add_text_field( $row, 'shipping_method_name_en', $method, 'name', $warnings );
			if ( '' !== $method_id ) {
				$method_name = $this->first_present_value( $method, array( 'name' ) );
				if ( $method_name['exists'] && null === $method_name['value'] ) {
					$row['shipping_method_name_fa'] = null;
				} else {
					$row['shipping_method_name_fa'] = $this->method_name_fa(
						$method_id,
						$method_name['exists'] && is_scalar( $method_name['value'] ) ? (string) $method_name['value'] : ''
					);
				}
			}
			// Shipping decimals are canonical strings; do not coerce them through
			// PHP float before the Sheets client writes the numeric column.
			$this->add_text_field( $row, 'shipping_price_per_kg', $method, 'price_per_kg', $warnings );
			$this->add_text_field( $row, 'shipping_price_per_kg_currency', $method, 'currency', $warnings );
			$this->add_number_field( $row, 'profit_margin_percent', $assignment, 'profit_percent', $warnings );
			$this->add_selected_text_field( $row, 'permalink', $product, array( 'canonical_url', 'permalink' ), $warnings );
			$this->add_text_field( $row, 'image_url', $product, 'image', $warnings );
			$this->add_selected_text_field( $row, 'updated_at', $product, array( 'patris_updated_at', 'date_modified' ), $warnings );

			$stock = $product['patris_warehouse_stock'] ?? null;
			if ( is_array( $stock ) ) {
				foreach ( $warehouses as $warehouse ) {
					if ( ! array_key_exists( $warehouse, $stock ) ) {
						continue;
					}
					$stock_value = $this->number_or_text( $stock[ $warehouse ] );
					if ( $stock_value['valid'] ) {
						$row[ $this->warehouse_key( $warehouse ) ] = $stock_value['value'];
					} else {
						$warnings[] = 'invalid_warehouse_stock:' . $warehouse;
					}
				}
			} elseif ( array_key_exists( 'patris_warehouse_stock', $product ) && null !== $stock ) {
				$warnings[] = 'invalid_patris_warehouse_stock';
			}

			$warnings           = array_values( array_unique( array_filter( $warnings, 'strlen' ) ) );
			$row['sync_status'] = $warnings ? 'warning' : 'ok';
			$row['sync_error']  = implode( ';', $warnings );

			$revision_material      = array(
				'row'           => $row,
				'exact_sources' => $this->exact_product_revision_sources( $product, $assignment, $method ),
			);
			$row['record_revision'] = 'sha256:' . hash(
				'sha256',
				wp_json_encode( $revision_material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			);
			$rows[]                 = $row;
		}

		return array(
			'columns' => $this->product_columns( $warehouses ),
			'rows'    => $rows,
		);
	}

	/**
	 * Normalize the query envelope.
	 *
	 * @param array $args Raw arguments.
	 * @return array|WP_Error
	 */
	private function normalize_args( $args ) {
		$args    = is_array( $args ) ? $args : array();
		$dataset = isset( $args['dataset'] ) ? sanitize_key( (string) $args['dataset'] ) : 'products';
		$locale  = isset( $args['locale'] ) ? sanitize_key( (string) $args['locale'] ) : 'en';

		if ( ! in_array( $dataset, array( 'products', 'reconciled_products', 'categories' ), true ) ) {
			return new WP_Error(
				'digitalogic_sheets_dataset_invalid',
				__( 'Dataset must be products, reconciled_products, or categories.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}
		if ( ! in_array( $locale, array( 'en', 'fa', 'bilingual' ), true ) ) {
			return new WP_Error(
				'digitalogic_sheets_locale_invalid',
				__( 'Locale must be en, fa, or bilingual.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'dataset'        => $dataset,
			'locale'         => $locale,
			'page'           => max( 1, absint( $args['page'] ?? 1 ) ),
			'limit'          => max( 1, min( self::MAX_PAGE_SIZE, absint( $args['limit'] ?? self::MAX_PAGE_SIZE ) ) ),
			'source_id'      => isset( $args['source_id'] ) && is_scalar( $args['source_id'] )
				? sanitize_text_field( (string) $args['source_id'] )
				: '',
			'source_dataset' => isset( $args['source_dataset'] ) && is_scalar( $args['source_dataset'] )
				? sanitize_text_field( (string) $args['source_dataset'] )
				: '',
		);
	}

	/**
	 * Build one paginated, leaf-only union of the selected Patris source and WooCommerce.
	 *
	 * Report Engine owns exact Product Code reconciliation. This adapter only
	 * turns the transport-neutral report rows into the same typed catalog shape
	 * used by Google Sheets, Excel, and other clients.
	 *
	 * @param array $args Normalized arguments.
	 * @return array|WP_Error
	 */
	private function get_reconciled_products_page( $args ) {
		$report_page_size  = 100;
		$offset            = ( $args['page'] - 1 ) * $args['limit'];
		$report_page       = (int) floor( $offset / $report_page_size ) + 1;
		$first_page_offset = $offset % $report_page_size;
		$report_args       = array(
			'view'     => 'price_list',
			'page'     => $report_page,
			'per_page' => $report_page_size,
		);
		if ( '' !== $args['source_id'] && '' !== $args['source_dataset'] ) {
			$report_args['source_id'] = $args['source_id'];
			$report_args['dataset']   = $args['source_dataset'];
		}

		$report = Digitalogic_Report_Engine::instance()->get_report( $report_args );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$integrity_warnings = array_values( (array) ( $report['integrity']['warnings'] ?? array() ) );
		$blocking_integrity_warnings = $this->blocking_integrity_warnings( $integrity_warnings );
		if ( ! empty( $blocking_integrity_warnings ) ) {
			return new WP_Error(
				'digitalogic_reconciled_projection_integrity_failed',
				__( 'The reconciled catalog contains an unsafe identity that cannot be projected.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 1,
					'warnings'    => $blocking_integrity_warnings,
				)
			);
		}

		$first_report     = $report;
		$dataset_revision = is_string( $report['snapshot_revision'] ?? null )
			? $report['snapshot_revision']
			: '';
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $dataset_revision ) ) {
			return new WP_Error(
				'digitalogic_reconciled_snapshot_revision_invalid',
				__( 'The reconciled catalog has no valid snapshot revision.', 'digitalogic' ),
				array(
					'status'      => 503,
					'retry_after' => 1,
				)
			);
		}

		$total     = absint( $report['pagination']['total'] ?? 0 );
		$rows      = array();
		$row_count = 0;
		if ( $offset < $total ) {
			while ( $row_count < $args['limit'] ) {
				$page_rows = array_values( (array) ( $report['rows'] ?? array() ) );
				if ( $first_page_offset > 0 ) {
					$page_rows         = array_slice( $page_rows, $first_page_offset );
					$first_page_offset = 0;
				}
				$remaining = $args['limit'] - count( $rows );
				$rows      = array_merge( $rows, array_slice( $page_rows, 0, $remaining ) );
				$row_count = count( $rows );

				if ( $row_count >= $args['limit'] || $report_page >= absint( $report['pagination']['pages'] ?? 0 ) ) {
					break;
				}

				++$report_page;
				$report_args['page'] = $report_page;
				$report              = Digitalogic_Report_Engine::instance()->get_report( $report_args );
				if ( is_wp_error( $report ) ) {
					return $report;
				}
				if (
					! hash_equals( $dataset_revision, (string) ( $report['snapshot_revision'] ?? '' ) )
					|| absint( $report['pagination']['total'] ?? 0 ) !== $total
				) {
					return new WP_Error(
						'digitalogic_reconciled_snapshot_changed',
						__( 'The reconciled catalog changed while this page was assembled; retry the complete fetch.', 'digitalogic' ),
						array(
							'status'      => 409,
							'retry_after' => 1,
						)
					);
				}
			}
		}

		$projection = $this->transform_reconciled_products( $rows );
		if ( is_wp_error( $projection ) ) {
			return $projection;
		}

		$pages    = $args['limit'] > 0 ? (int) ceil( $total / $args['limit'] ) : 0;
		$response = $this->response_envelope(
			$args,
			$projection['columns'],
			$projection['rows'],
			$total,
			$pages
		);

		$response['dataset_revision'] = $dataset_revision;
		$response['reconciliation']   = array(
			'status'           => (string) ( $first_report['status'] ?? '' ),
			'integrity_status' => (string) ( $first_report['integrity']['status'] ?? '' ),
			'warnings'         => array_values( (array) ( $first_report['integrity']['warnings'] ?? array() ) ),
			'counts'           => $this->reconciliation_counts( (array) ( $first_report['counts'] ?? array() ), $total ),
		);
		if ( is_array( $first_report['source'] ?? null ) ) {
			$response['reconciliation']['source'] = $first_report['source'];
		}

		return $response;
	}

	/**
	 * Convert Report Engine union rows into the catalog contract.
	 *
	 * Woo-backed rows reuse transform_products() so their record revision stays
	 * byte-for-byte compatible with the optimistic writeback service. Source
	 * fields are then overlaid for display without changing that Woo revision.
	 *
	 * @param array      $report_rows Report Engine rows.
	 * @param array|null $integration_catalog Preloaded pricing integration catalog.
	 * @return array|WP_Error
	 */
	private function transform_reconciled_products( $report_rows, $integration_catalog = null ) {
		$report_rows         = array_values( (array) $report_rows );
		$canonical_by_id     = array();
		$integration_catalog = null === $integration_catalog
			? Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog()
			: $integration_catalog;
		if ( is_wp_error( $integration_catalog ) ) {
			return $integration_catalog;
		}

		foreach ( $report_rows as $report_row ) {
			$woocommerce_id = absint( $report_row['woo_id'] ?? 0 );
			if ( ! $woocommerce_id || isset( $canonical_by_id[ $woocommerce_id ] ) ) {
				continue;
			}
			$canonical = Digitalogic_Product_Manager::instance()->get_product( $woocommerce_id );
			if ( is_array( $canonical ) && $canonical ) {
				$canonical_by_id[ $woocommerce_id ] = $canonical;
			}
		}

		$base_by_id = array();
		if ( $canonical_by_id ) {
			$base_projection = $this->transform_products(
				array_values( $canonical_by_id ),
				$integration_catalog
			);
			if ( is_wp_error( $base_projection ) ) {
				return $base_projection;
			}
			foreach ( (array) ( $base_projection['rows'] ?? array() ) as $base_row ) {
				$id = absint( $base_row['woocommerce_id'] ?? 0 );
				if ( $id ) {
					$base_by_id[ $id ] = $base_row;
				}
			}
		}

		$methods = $this->index_methods( $integration_catalog );
		$rows    = array();
		$seen    = array();
		foreach ( $report_rows as $report_row ) {
			if ( ! is_array( $report_row ) ) {
				continue;
			}

			$internal_status = (string) ( $report_row['status'] ?? '' );
			$status          = $this->public_reconciliation_status( $internal_status );
			$source          = is_array( $report_row['source'] ?? null ) ? $report_row['source'] : array();
			$woocommerce     = is_array( $report_row['woocommerce'] ?? null ) ? $report_row['woocommerce'] : array();
			$woocommerce_id  = absint( $report_row['woo_id'] ?? 0 );
			$product_code    = is_scalar( $report_row['product_code'] ?? null )
				? trim( (string) $report_row['product_code'] )
				: '';
			// Source-backed rows retain one identity while moving from Patris-only
			// to matched. Woo-only rows remain anchored to their immutable Woo ID.
			$sync_key = $source && '' !== $product_code && 'ambiguous' !== $status
				? 'patris:' . $product_code
				: ( $woocommerce_id ? 'woo:' . $woocommerce_id : '' );

			if ( '' === $sync_key || isset( $seen[ $sync_key ] ) ) {
				return new WP_Error(
					'digitalogic_reconciled_sync_key_invalid',
					__( 'Every reconciled row must have one unique, stable sync key.', 'digitalogic' ),
					array(
						'status'   => 500,
						'sync_key' => $sync_key,
					)
				);
			}
			$seen[ $sync_key ] = true;

			$row = $woocommerce_id && isset( $base_by_id[ $woocommerce_id ] )
				? $base_by_id[ $woocommerce_id ]
				: $this->minimal_reconciled_row( $source, $woocommerce, $woocommerce_id, $integration_catalog );

			$row['sync_key']              = $sync_key;
			$row['reconciliation_status'] = $status;
			if (
				'' !== $product_code
				&& (
					array_key_exists( 'product_code', $source )
					|| array_key_exists( 'product_code', $woocommerce )
				)
			) {
				$row['patris_code'] = $product_code;
			}

			$this->overlay_reconciled_source( $row, $source, $methods, $woocommerce_id );
			foreach ( array_keys( $row ) as $field ) {
				if ( 0 === strpos( (string) $field, 'warehouse_stock:' ) ) {
					unset( $row[ $field ] );
				}
			}

			$warnings = array_values(
				array_unique(
					array_filter(
						array_merge(
							$this->split_sync_warnings( $row['sync_error'] ?? '' ),
							array_map( 'strval', (array) ( $report_row['issues'] ?? array() ) )
						),
						'strlen'
					)
				)
			);

			$row['sync_status'] = $warnings ? 'warning' : 'ok';
			$row['sync_error']  = implode( ';', $warnings );
			if ( ! isset( $row['record_revision'] ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/', (string) $row['record_revision'] ) ) {
				$row['record_revision'] = 'sha256:' . hash(
					'sha256',
					wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				);
			}
			$rows[] = $row;
		}

		return array(
			'columns' => $this->reconciled_product_columns(),
			'rows'    => $rows,
		);
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
	 * Create a bounded row when no canonical Woo catalog row is available.
	 *
	 * @param array $source Source row, possibly empty.
	 * @param array $woocommerce Report Engine Woo row, possibly empty.
	 * @param int   $woocommerce_id Woo ID.
	 * @param array $integration_catalog Shared catalog.
	 * @return array
	 */
	private function minimal_reconciled_row( $source, $woocommerce, $woocommerce_id, $integration_catalog ) {
		$row = array();
		if ( $woocommerce_id ) {
			$row['woocommerce_id'] = $woocommerce_id;
		}
		$mapping = array(
			'parent_id'          => 'parent_id',
			'product_type'       => 'type',
			'publication_status' => 'status',
			'name'               => 'name',
			'regular_price'      => 'regular_price',
			'sale_price'         => 'sale_price',
			'stock_quantity'     => 'stock_quantity',
			'stock_status'       => 'stock_status',
			'permalink'          => 'permalink',
			'updated_at'         => 'updated_at',
		);
		foreach ( $mapping as $target => $field ) {
			if ( array_key_exists( $field, $woocommerce ) ) {
				$row[ $target ] = $woocommerce[ $field ];
			}
		}
		if ( array_key_exists( 'active_price', $woocommerce ) ) {
			$row['effective_price'] = $this->number_or_text( $woocommerce['active_price'] )['value'];
		}
		if ( array_key_exists( 'categories', $woocommerce ) ) {
			$row['categories'] = $woocommerce['categories'];
		}
		if ( array_key_exists( 'category_ids', $woocommerce ) ) {
			$row['category_ids'] = $woocommerce['category_ids'];
		}
		if ( is_array( $integration_catalog['currency'] ?? null ) && array_key_exists( 'local', $integration_catalog['currency'] ) ) {
			$row['currency'] = $integration_catalog['currency']['local'];
		}
		if ( ! $woocommerce && array_key_exists( 'name', $source ) ) {
			$row['name'] = $source['name'];
		}

		return $row;
	}

	/**
	 * Overlay source-owned product inputs without inventing absent values.
	 *
	 * @param array $row Catalog row, updated in place.
	 * @param array $source Sparse Report Engine source.
	 * @param array $methods Shipping methods indexed by ID.
	 * @param int   $woocommerce_id Current Woo leaf ID, or zero for Patris-only.
	 * @return void
	 */
	private function overlay_reconciled_source( &$row, $source, $methods, $woocommerce_id ) {
		$mapping = array(
			'name'                           => 'name',
			'patris_final_price'             => 'final_price',
			'patris_total_stock'             => 'total_stock',
			'patris_minimum_stock'           => 'minimum_stock',
			'patris_location'                => 'location',
			'weight_grams'                   => 'weight_grams',
			'foreign_price'                  => 'foreign_price',
			'foreign_currency'               => 'foreign_currency',
			'partner_price_irr'              => 'partner_price_source',
			'price_source_amount'            => 'price_source_amount',
			'price_source_currency'          => 'price_source_currency',
			'price_source_kind'              => 'price_source_kind',
			'price_rounding_digits'          => 'price_rounding_digits',
			'price_rounding_mode'            => 'price_rounding_mode',
			'shipping_method_id'             => 'shipping_method_id',
			'shipping_price_per_kg'          => 'shipping_price_per_kg',
			'shipping_price_per_kg_currency' => 'shipping_price_per_kg_currency',
			'profit_margin_percent'          => 'markup_percent',
			'updated_at'                     => 'source_updated_at',
		);
		foreach ( $mapping as $target => $field ) {
			if ( array_key_exists( $field, $source ) ) {
				$value          = $source[ $field ];
				$row[ $target ] = is_numeric( $value ) && ! in_array(
					$target,
					array( 'shipping_price_per_kg', 'shipping_price_per_kg_currency', 'foreign_currency', 'price_source_currency', 'price_source_kind', 'price_rounding_mode', 'shipping_method_id', 'patris_location', 'updated_at', 'name' ),
					true
				)
					? $this->number_or_text( $value )['value']
					: $value;
			}
		}
		if ( ! $woocommerce_id && array_key_exists( 'final_price', $source ) ) {
			$value                  = $source['final_price'];
			$row['effective_price'] = is_numeric( $value ) ? $this->number_or_text( $value )['value'] : $value;
		}

		$method_id = isset( $row['shipping_method_id'] ) && is_scalar( $row['shipping_method_id'] )
			? trim( (string) $row['shipping_method_id'] )
			: '';
		$method    = '' !== $method_id && isset( $methods[ $method_id ] ) ? $methods[ $method_id ] : array();
		if ( $method ) {
			if ( array_key_exists( 'name', $method ) ) {
				$row['shipping_method_name_en'] = $method['name'];
				$row['shipping_method_name_fa'] = $this->method_name_fa( $method_id, (string) $method['name'] );
			}
		}
	}

	/**
	 * Return external reconciliation vocabulary.
	 *
	 * @param string $status Report Engine status.
	 * @return string
	 */
	private function public_reconciliation_status( $status ) {
		$mapping = array(
			'matched'          => 'matched',
			'source_only'      => 'patris_only',
			'woocommerce_only' => 'woo_only',
			'ambiguous'        => 'ambiguous',
		);

		return $mapping[ $status ] ?? 'ambiguous';
	}

	/**
	 * Normalize semicolon-separated row warnings.
	 *
	 * @param mixed $warnings Existing warning cell.
	 * @return array
	 */
	private function split_sync_warnings( $warnings ) {
		return is_scalar( $warnings )
			? array_values( array_filter( array_map( 'trim', explode( ';', (string) $warnings ) ), 'strlen' ) )
			: array();
	}

	/**
	 * Add reconciliation and source-location columns to the shared catalog shape.
	 *
	 * @return array
	 */
	private function reconciled_product_columns() {
		// Per-warehouse keys are intentionally omitted here. A page-local set
		// would change the schema between pages; total stock and location remain
		// globally stable source fields.
		$columns = $this->product_columns( array() );
		array_splice(
			$columns,
			1,
			0,
			array(
				$this->column( 'reconciliation_status', 'Reconciliation Status', 'وضعیت تطبیق', 'text' ),
			)
		);

		$location = $this->column( 'patris_location', 'Product Location', 'محل کالا', 'text' );
		$index    = array_search( 'patris_minimum_stock', array_column( $columns, 'key' ), true );
		array_splice( $columns, false === $index ? count( $columns ) : $index + 1, 0, array( $location ) );

		return $columns;
	}

	/**
	 * Convert Report Engine count names into the public reconciliation contract.
	 *
	 * @param array $counts Report Engine counts.
	 * @param int   $total Total union rows.
	 * @return array
	 */
	private function reconciliation_counts( $counts, $total ) {
		return array(
			'patris_products'             => absint( $counts['patris_products'] ?? 0 ),
			'woocommerce_raw'             => absint( $counts['woocommerce_products_raw'] ?? 0 ),
			'woocommerce_leaves'          => absint( $counts['woocommerce_products'] ?? 0 ),
			'union_rows'                  => absint( $total ),
			'matched'                     => absint( $counts['matched_products'] ?? 0 ),
			'source_only'                 => absint( $counts['source_only_products'] ?? 0 ),
			'patris_only'                 => absint( $counts['source_only_products'] ?? 0 ),
			'woo_only'                    => absint( $counts['woocommerce_only_products'] ?? 0 ),
			'ambiguous_codes'             => absint( $counts['ambiguous_codes'] ?? 0 ),
			'variable_parents_excluded'   => absint( $counts['variable_parents_excluded'] ?? 0 ),
			'quarantined_identity_groups' => absint( $counts['quarantined_identity_groups'] ?? 0 ),
			'quarantined_source_rows'     => absint( $counts['quarantined_source_rows'] ?? 0 ),
			'quarantined_woo_rows'        => absint( $counts['quarantined_woo_rows'] ?? 0 ),
			'one_to_one_split_candidates' => absint( $counts['one_to_one_split_candidates'] ?? 0 ),
			'identity_collision_groups'   => absint( $counts['identity_collision_groups'] ?? 0 ),
			'source_code_collision_groups' => absint( $counts['source_code_collision_groups'] ?? 0 ),
			'woo_code_collision_groups'    => absint( $counts['woo_code_collision_groups'] ?? 0 ),
			'woo_sku_collision_groups'     => absint( $counts['woo_sku_collision_groups'] ?? 0 ),
			'unsafe_identity_groups'       => absint( $counts['unsafe_identity_groups'] ?? 0 ),
		);
	}

	/**
	 * Build a paginated Products response through the existing query service.
	 *
	 * @param array $args Normalized arguments.
	 * @return array|WP_Error
	 */
	private function get_products_page( $args ) {
		$offset            = ( $args['page'] - 1 ) * $args['limit'];
		$query_page        = (int) floor( $offset / self::PRODUCT_QUERY_PAGE_SIZE ) + 1;
		$first_page_offset = $offset % self::PRODUCT_QUERY_PAGE_SIZE;
		$products          = array();
		$total             = 0;
		$query_pages       = 0;
		$product_count     = 0;

		while ( $product_count < $args['limit'] ) {
			$result = Digitalogic_Product_Manager::instance()->query_products(
				array(
					'page'  => $query_page,
					'limit' => self::PRODUCT_QUERY_PAGE_SIZE,
					'sorts' => array(
						array(
							'field'     => 'id',
							'direction' => 'asc',
						),
					),
				)
			);
			if ( 0 === $query_pages ) {
				$total       = absint( $result['recordsFiltered'] ?? 0 );
				$query_pages = absint( $result['pages'] ?? 0 );
			}

			$page_products = array_values( (array) ( $result['products'] ?? array() ) );
			if ( $first_page_offset > 0 ) {
				$page_products     = array_slice( $page_products, $first_page_offset );
				$first_page_offset = 0;
			}
			$remaining     = $args['limit'] - count( $products );
			$products      = array_merge( $products, array_slice( $page_products, 0, $remaining ) );
			$product_count = count( $products );

			if ( ! $page_products || $query_page >= $query_pages ) {
				break;
			}
			++$query_page;
		}

		$projection = $this->transform_products( $products );
		if ( is_wp_error( $projection ) ) {
			return $projection;
		}
		$pages = $args['limit'] > 0 ? (int) ceil( $total / $args['limit'] ) : 0;

		return $this->response_envelope(
			$args,
			$projection['columns'],
			$projection['rows'],
			$total,
			$pages
		);
	}

	/**
	 * Build a paginated Categories response.
	 *
	 * @param array $args Normalized arguments.
	 * @return array|WP_Error
	 */
	private function get_categories_page( $args ) {
		$offset = ( $args['page'] - 1 ) * $args['limit'];
		$terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => $args['limit'],
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$total = wp_count_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $total ) ) {
			return $total;
		}
		$total = max( 0, (int) $total );
		$pages = $args['limit'] > 0 ? (int) ceil( $total / $args['limit'] ) : 0;
		$rows  = array();

		foreach ( (array) $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
				continue;
			}

			$warnings  = array();
			$parent_id = property_exists( $term, 'parent' ) && is_numeric( $term->parent )
				? absint( $term->parent )
				: 0;
			$row       = array(
				'sync_key' => 'category:' . absint( $term->term_id ),
			);
			$this->add_explicit_number_value(
				$row,
				'category_id',
				array(
					'exists' => true,
					'value'  => $term->term_id,
				),
				$warnings
			);
			if ( property_exists( $term, 'name' ) ) {
				$this->add_explicit_text_value( $row, 'name', $term->name, $warnings );
			}
			if ( property_exists( $term, 'slug' ) ) {
				$this->add_explicit_text_value( $row, 'slug', $term->slug, $warnings );
			}
			if ( property_exists( $term, 'parent' ) ) {
				$this->add_explicit_number_value(
					$row,
					'parent_id',
					array(
						'exists' => true,
						'value'  => $term->parent,
					),
					$warnings
				);
			}
			if ( $parent_id ) {
				$parent = get_term( $parent_id, 'product_cat' );
				if ( ! is_wp_error( $parent ) && is_object( $parent ) && property_exists( $parent, 'name' ) ) {
					$this->add_explicit_text_value( $row, 'parent_name', $parent->name, $warnings );
				} else {
					$warnings[] = 'parent_category_unavailable';
				}
			}
			if ( property_exists( $term, 'count' ) ) {
				$this->add_explicit_number_value(
					$row,
					'product_count',
					array(
						'exists' => true,
						'value'  => $term->count,
					),
					$warnings
				);
			}
			if ( property_exists( $term, 'description' ) ) {
				if ( null === $term->description ) {
					$row['description'] = null;
				} elseif ( is_scalar( $term->description ) ) {
					$row['description'] = wp_strip_all_tags( (string) $term->description );
				} else {
					$warnings[] = 'invalid_description';
				}
			}
			$link = get_term_link( $term, 'product_cat' );
			if ( is_wp_error( $link ) ) {
				$warnings[] = 'category_permalink_unavailable';
			} else {
				$this->add_explicit_text_value( $row, 'permalink', $link, $warnings );
			}
			$warnings               = array_values( array_unique( array_filter( $warnings, 'strlen' ) ) );
			$row['sync_status']     = $warnings ? 'warning' : 'ok';
			$row['sync_error']      = implode( ';', $warnings );
			$row['record_revision'] = 'sha256:' . hash(
				'sha256',
				wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			);
			$rows[]                 = $row;
		}

		return $this->response_envelope( $args, $this->category_columns(), $rows, $total, $pages );
	}

	/**
	 * Add localized labels, revision, and pagination metadata.
	 *
	 * @param array $args Normalized args.
	 * @param array $columns Column definitions.
	 * @param array $rows Dataset rows.
	 * @param int   $total Total records.
	 * @param int   $pages Total pages.
	 * @return array
	 */
	private function response_envelope( $args, $columns, $rows, $total, $pages ) {
		foreach ( $columns as &$column ) {
			$column['header'] = $this->localized_header( $column, $args['locale'] );
		}
		unset( $column );

		$revision_material = array(
			'dataset' => $args['dataset'],
			'page'    => $args['page'],
			'columns' => $columns,
			'rows'    => $rows,
		);

		return array(
			'dataset'       => $args['dataset'],
			'locale'        => $args['locale'],
			'generated_at'  => gmdate( 'c' ),
			'page_revision' => 'sha256:' . hash(
				'sha256',
				wp_json_encode( $revision_material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			),
			'columns'       => $columns,
			'rows'          => array_values( $rows ),
			'pagination'    => array(
				'page'     => $args['page'],
				'limit'    => $args['limit'],
				'total'    => $total,
				'pages'    => $pages,
				'has_more' => $args['page'] < $pages,
			),
		);
	}

	/**
	 * Return product column metadata.
	 *
	 * @param array $warehouses Dynamic warehouse labels.
	 * @return array
	 */
	private function product_columns( $warehouses ) {
		return Digitalogic_Product_Column_Schema::catalog_columns( $warehouses );
	}

	/**
	 * Return category column metadata.
	 *
	 * @return array
	 */
	private function category_columns() {
		return array(
			$this->column( 'sync_key', 'Sync Key', 'کلید همگام‌سازی', 'text' ),
			$this->column( 'category_id', 'Category ID', 'شناسه دسته‌بندی', 'integer' ),
			$this->column( 'name', 'Category Name', 'نام دسته‌بندی', 'text' ),
			$this->column( 'slug', 'Slug', 'نامک', 'text' ),
			$this->column( 'parent_id', 'Parent ID', 'شناسه والد', 'integer' ),
			$this->column( 'parent_name', 'Parent Category', 'دسته والد', 'text' ),
			$this->column( 'product_count', 'Product Count', 'تعداد محصولات', 'integer' ),
			$this->column( 'description', 'Description', 'توضیحات', 'text' ),
			$this->column( 'permalink', 'Category URL', 'نشانی دسته‌بندی', 'url' ),
			$this->column( 'sync_status', 'Sync Status', 'وضعیت همگام‌سازی', 'text' ),
			$this->column( 'sync_error', 'Sync Notes', 'توضیحات همگام‌سازی', 'text' ),
			$this->column( 'record_revision', 'Record Revision', 'شناسه بازبینی رکورد', 'text' ),
		);
	}

	/**
	 * Build one column definition.
	 *
	 * @param string $key Machine key.
	 * @param string $label_en English label.
	 * @param string $label_fa Persian label.
	 * @param string $type Cell type.
	 * @return array
	 */
	private function column( $key, $label_en, $label_fa, $type ) {
		return array(
			'key'      => $key,
			'label_en' => $label_en,
			'label_fa' => $label_fa,
			'type'     => $type,
		);
	}

	/**
	 * Resolve the selected locale label.
	 *
	 * @param array  $column Column metadata.
	 * @param string $locale Requested locale.
	 * @return string
	 */
	private function localized_header( $column, $locale ) {
		if ( 'fa' === $locale ) {
			return $column['label_fa'];
		}
		if ( 'bilingual' === $locale ) {
			return $column['label_en'] . ' / ' . $column['label_fa'];
		}

		return $column['label_en'];
	}

	/**
	 * Index a batch assignment response by exact Patris Code.
	 *
	 * @param array $batch Assignment batch.
	 * @return array
	 */
	private function index_assignments( $batch ) {
		$indexed = array();
		foreach ( (array) ( $batch['results'] ?? array() ) as $result ) {
			if ( is_array( $result ) && isset( $result['code'] ) ) {
				$indexed[ (string) $result['code'] ] = $result;
			}
		}

		return $indexed;
	}

	/**
	 * Index the canonical shipping catalog.
	 *
	 * @param array $catalog Integration catalog.
	 * @return array
	 */
	private function index_methods( $catalog ) {
		$source  = $catalog['shipping_methods'] ?? array();
		$methods = array();
		foreach ( (array) $source as $method ) {
			if ( is_array( $method ) && ! empty( $method['id'] ) ) {
				$methods[ (string) $method['id'] ] = $method;
			}
		}

		return $methods;
	}

	/**
	 * Determine one exact product lookup identifier.
	 *
	 * @param array $product Product row.
	 * @return string
	 */
	private function product_identifier( $product ) {
		if ( ! is_array( $product ) ) {
			return '';
		}
		if ( ! array_key_exists( 'patris_product_code', $product ) || ! is_scalar( $product['patris_product_code'] ) ) {
			return '';
		}

		return trim( (string) $product['patris_product_code'] );
	}

	/**
	 * List every configured or observed warehouse deterministically.
	 *
	 * @param array $products Product rows.
	 * @param array $catalog Integration catalog.
	 * @return array
	 */
	private function warehouse_names( $products, $catalog ) {
		$names = array();
		foreach ( (array) ( $catalog['selected_warehouses'] ?? array() ) as $name ) {
			$name = trim( (string) $name );
			if ( '' !== $name ) {
				$names[ $name ] = true;
			}
		}
		foreach ( $products as $product ) {
			foreach ( (array) ( $product['patris_warehouse_stock'] ?? array() ) as $name => $unused ) {
				$name = trim( (string) $name );
				if ( '' !== $name ) {
					$names[ $name ] = true;
				}
			}
		}

		$names = array_keys( $names );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Turn a warehouse label into a collision-resistant column key.
	 *
	 * @param string $warehouse Warehouse label.
	 * @return string
	 */
	private function warehouse_key( $warehouse ) {
		return Digitalogic_Product_Column_Schema::warehouse_key( $warehouse );
	}

	/**
	 * Return a Persian display name for known seeded methods.
	 *
	 * @param string $method_id Method ID.
	 * @param string $fallback Existing display name.
	 * @return string
	 */
	private function method_name_fa( $method_id, $fallback ) {
		$known = array(
			'air_express' => 'حمل هوایی (اکسپرس)',
			'air_freight' => 'حمل هوایی',
			'sea_freight' => 'حمل دریایی',
		);

		return $known[ $method_id ] ?? $fallback;
	}

	/**
	 * Copy one present text value without manufacturing a placeholder.
	 *
	 * @param array  $row Target row.
	 * @param string $target_key Target key.
	 * @param array  $source Source record.
	 * @param string $source_key Source key.
	 * @param array  $warnings Row warnings.
	 * @return void
	 */
	private function add_text_field( &$row, $target_key, $source, $source_key, &$warnings ) {
		if ( ! is_array( $source ) || ! array_key_exists( $source_key, $source ) ) {
			return;
		}

		$this->add_explicit_text_value( $row, $target_key, $source[ $source_key ], $warnings );
	}

	/**
	 * Copy the first present, usable text value from an ordered set of keys.
	 *
	 * @param array  $row Target row.
	 * @param string $target_key Target key.
	 * @param array  $source Source record.
	 * @param array  $source_keys Ordered source keys.
	 * @param array  $warnings Row warnings.
	 * @return void
	 */
	private function add_selected_text_field( &$row, $target_key, $source, $source_keys, &$warnings ) {
		$selected = $this->first_present_value( $source, $source_keys );
		if ( ! $selected['exists'] ) {
			return;
		}

		$this->add_explicit_text_value( $row, $target_key, $selected['value'], $warnings );
	}

	/**
	 * Add a value known to exist at its source, preserving explicit null/empty.
	 *
	 * @param array  $row Target row.
	 * @param string $target_key Target key.
	 * @param mixed  $value Explicit source value.
	 * @param array  $warnings Row warnings.
	 * @return void
	 */
	private function add_explicit_text_value( &$row, $target_key, $value, &$warnings ) {
		if ( null === $value ) {
			$row[ $target_key ] = null;
			return;
		}
		if ( is_scalar( $value ) ) {
			$row[ $target_key ] = (string) $value;
			return;
		}

		$warnings[] = 'invalid_' . $target_key;
	}

	/**
	 * Copy one present numeric value without collapsing absence into null.
	 *
	 * @param array  $row Target row.
	 * @param string $target_key Target key.
	 * @param array  $source Source record.
	 * @param string $source_key Source key.
	 * @param array  $warnings Row warnings.
	 * @return void
	 */
	private function add_number_field( &$row, $target_key, $source, $source_key, &$warnings ) {
		if ( ! is_array( $source ) || ! array_key_exists( $source_key, $source ) ) {
			return;
		}

		$this->add_explicit_number_value(
			$row,
			$target_key,
			array(
				'exists' => true,
				'value'  => $source[ $source_key ],
			),
			$warnings
		);
	}

	/**
	 * Add a present numeric descriptor while preserving explicit null/empty.
	 *
	 * @param array  $row Target row.
	 * @param string $target_key Target key.
	 * @param array  $descriptor Exists/value descriptor.
	 * @param array  $warnings Row warnings.
	 * @return void
	 */
	private function add_explicit_number_value( &$row, $target_key, $descriptor, &$warnings ) {
		if ( ! is_array( $descriptor ) || empty( $descriptor['exists'] ) ) {
			return;
		}

		$value = $descriptor['value'] ?? null;
		if ( null === $value || '' === $value ) {
			$row[ $target_key ] = $value;
			return;
		}

		$number = $this->finite_number( $value );
		if ( null === $number ) {
			$warnings[] = 'invalid_' . $target_key;
			return;
		}

		$row[ $target_key ] = $number;
	}

	/**
	 * Project category names only when the source key exists.
	 *
	 * @param array $row Target row.
	 * @param array $product Product record.
	 * @param array $warnings Row warnings.
	 * @return void
	 */
	private function add_categories_field( &$row, $product, &$warnings ) {
		if ( ! array_key_exists( 'categories', $product ) ) {
			return;
		}
		if ( null === $product['categories'] ) {
			$row['categories'] = null;
			return;
		}
		if ( ! is_array( $product['categories'] ) ) {
			$warnings[] = 'invalid_categories';
			return;
		}

		$row['categories'] = $this->category_names( $product['categories'] );
	}

	/**
	 * Project category IDs only when the source key exists.
	 *
	 * @param array $row Target row.
	 * @param array $product Product record.
	 * @param array $warnings Row warnings.
	 * @return void
	 */
	private function add_category_ids_field( &$row, $product, &$warnings ) {
		if ( ! array_key_exists( 'category_ids', $product ) ) {
			return;
		}
		if ( null === $product['category_ids'] ) {
			$row['category_ids'] = null;
			return;
		}
		if ( ! is_array( $product['category_ids'] ) ) {
			$warnings[] = 'invalid_category_ids';
			return;
		}

		$ids = array();
		foreach ( $product['category_ids'] as $id ) {
			if ( ! is_scalar( $id ) ) {
				$warnings[] = 'invalid_category_ids';
				return;
			}
			$ids[] = (string) $id;
		}
		$row['category_ids'] = implode( ',', $ids );
	}

	/**
	 * Flatten category names without leaking internal objects.
	 *
	 * @param mixed $categories Product category list.
	 * @return string
	 */
	private function category_names( $categories ) {
		$names = array();
		foreach ( (array) $categories as $category ) {
			if ( is_array( $category ) && isset( $category['name'] ) ) {
				$names[] = (string) $category['name'];
			} elseif ( is_object( $category ) && isset( $category->name ) ) {
				$names[] = (string) $category->name;
			} elseif ( is_scalar( $category ) ) {
				$names[] = (string) $category;
			}
		}

		return implode( ' | ', array_values( array_unique( array_filter( $names, 'strlen' ) ) ) );
	}

	/**
	 * Resolve grams from Patris first, then the WooCommerce store unit.
	 *
	 * @param array  $product Product row.
	 * @param string $store_unit WooCommerce weight unit.
	 * @param array  $warnings Row warnings.
	 * @return array Exists/value descriptor.
	 */
	private function product_weight_grams( $product, $store_unit, &$warnings ) {
		if ( array_key_exists( 'patris_weight_grams', $product ) ) {
			$value = $product['patris_weight_grams'];
			if ( null === $value || '' === $value ) {
				return array(
					'exists' => true,
					'value'  => $value,
				);
			}
			$number = $this->finite_number( $value );
			if ( null === $number ) {
				$warnings[] = 'invalid_weight_grams';
				return array( 'exists' => false );
			}

			return array(
				'exists' => true,
				'value'  => $number,
			);
		}

		if ( ! array_key_exists( 'weight', $product ) ) {
			return array( 'exists' => false );
		}
		$weight = $product['weight'];
		if ( null === $weight || '' === $weight ) {
			return array(
				'exists' => true,
				'value'  => $weight,
			);
		}
		$weight = $this->finite_number( $weight );
		if ( null === $weight ) {
			$warnings[] = 'invalid_woocommerce_weight';
			return array( 'exists' => false );
		}

		$grams = wc_get_weight( $weight, 'g', '' !== $store_unit ? $store_unit : 'kg' );
		$grams = $this->finite_number( $grams );
		if ( null === $grams ) {
			$warnings[] = 'invalid_weight_grams';
			return array( 'exists' => false );
		}

		return array(
			'exists' => true,
			'value'  => $grams,
		);
	}

	/**
	 * Read the first present value, preferring a non-null/non-empty candidate.
	 *
	 * @param array $values Source values.
	 * @param array $keys Ordered keys.
	 * @return array Exists/value descriptor.
	 */
	private function first_present_value( $values, $keys ) {
		$first = array( 'exists' => false );
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			if ( ! $first['exists'] ) {
				$first = array(
					'exists' => true,
					'value'  => $values[ $key ],
				);
			}
			if ( null !== $values[ $key ] && '' !== $values[ $key ] ) {
				return array(
					'exists' => true,
					'value'  => $values[ $key ],
				);
			}
		}

		return $first;
	}

	/**
	 * Convert finite numerics to spreadsheet numbers.
	 *
	 * @param mixed $value Source value.
	 * @return float|int|null
	 */
	private function finite_number( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) ) {
			return null;
		}

		if ( floor( $number ) === $number && $number <= PHP_INT_MAX && $number >= PHP_INT_MIN ) {
			return (int) $number;
		}

		return $number;
	}

	/**
	 * Preserve exact pre-display numeric sources in optimistic row revisions.
	 *
	 * Sheets display values intentionally remain numbers, but PHP floats cannot
	 * distinguish every allowed WooCommerce decimal. Hashing scalar source text
	 * alongside the display row prevents distinct prices from sharing a revision.
	 *
	 * @param array $product Canonical product record.
	 * @param array $assignment Product pricing/shipping assignment.
	 * @param array $method Selected shipping method.
	 * @return array
	 */
	private function exact_product_revision_sources( $product, $assignment, $method ) {
		$product_keys    = array(
			'id',
			'parent_id',
			'regular_price',
			'sale_price',
			'price',
			'patris_final_price',
			'stock_quantity',
			'patris_total_stock',
			'patris_minimum_stock',
			'patris_weight_grams',
			'weight',
			'patris_foreign_price',
			'patris_sale_price_source',
			'patris_partner_price_source',
			'patris_price_source_amount',
			'patris_price_rounding_digits',
			'patris_warehouse_stock',
		);
		$assignment_keys = array( 'shipping_method_id', 'profit_percent' );
		$method_keys     = array( 'price_per_kg' );

		return array(
			'product'    => $this->revision_source_fields( $product, $product_keys ),
			'assignment' => $this->revision_source_fields( $assignment, $assignment_keys ),
			'method'     => $this->revision_source_fields( $method, $method_keys ),
		);
	}

	/**
	 * Build stable descriptors for selected source fields.
	 *
	 * @param array $source Source record.
	 * @param array $keys Selected keys.
	 * @return array
	 */
	private function revision_source_fields( $source, $keys ) {
		$fields = array();
		foreach ( $keys as $key ) {
			if ( ! is_array( $source ) || ! array_key_exists( $key, $source ) ) {
				$fields[ $key ] = array( 'exists' => false );
				continue;
			}
			$fields[ $key ] = array(
				'exists' => true,
				'value'  => $this->stable_revision_value( $source[ $key ] ),
			);
		}

		return $fields;
	}

	/**
	 * Recursively stringify scalars and sort associative keys.
	 *
	 * @param mixed $value Source value.
	 * @return mixed
	 */
	private function stable_revision_value( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( ! is_array( $value ) ) {
			return array( 'unsupported_type' => get_debug_type( $value ) );
		}

		$is_list = array_is_list( $value );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		$stable = array();
		foreach ( $value as $key => $item ) {
			$stable[ $key ] = $this->stable_revision_value( $item );
		}

		return $stable;
	}

	/**
	 * Return integrity failures that make even a read-only projection unsafe.
	 *
	 * A duplicated WooCommerce SKU remains quarantined from identity fallback and
	 * writeback, but both records already have distinct immutable Woo IDs and
	 * stable projection keys. Keep that diagnostic in the projection metadata
	 * without starving every unrelated catalog row. All other integrity failures
	 * continue to fail the projection closed.
	 *
	 * @param array $warnings Report integrity warnings.
	 * @return array
	 */
	private function blocking_integrity_warnings( $warnings ) {
		return array_values(
			array_filter(
				(array) $warnings,
				static function ( $warning ) {
					return 'projection_integrity_duplicate_woo_sku' !== (string) ( $warning['code'] ?? '' );
				}
			)
		);
	}

	/**
	 * Preserve nonnumeric warehouse values for explicit integration states.
	 *
	 * @param mixed $value Warehouse value.
	 * @return array Valid/value descriptor.
	 */
	private function number_or_text( $value ) {
		if ( null === $value || '' === $value ) {
			return array(
				'valid' => true,
				'value' => $value,
			);
		}
		$number = $this->finite_number( $value );
		if ( null !== $number ) {
			return array(
				'valid' => true,
				'value' => $number,
			);
		}
		if ( is_scalar( $value ) ) {
			return array(
				'valid' => true,
				'value' => (string) $value,
			);
		}

		return array( 'valid' => false );
	}
}
