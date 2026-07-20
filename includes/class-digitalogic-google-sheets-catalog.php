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

	public const MAX_PAGE_SIZE = 100;

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

		return 'categories' === $args['dataset']
			? $this->get_categories_page( $args )
			: $this->get_products_page( $args );
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
			$sync_key          = '' !== $patris_code
				? $patris_code
				: ( $product_id > 0 ? 'woo:' . $product_id : '' );

			if ( '' === $sync_key ) {
				return new WP_Error(
					'digitalogic_sheets_sync_key_missing',
					__( 'Every catalog product must have a Patris Code or a positive WooCommerce ID.', 'digitalogic' ),
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
			$this->add_explicit_number_value( $row, 'weight_grams', $this->product_weight_grams( $product, $weight_unit, $warnings ), $warnings );
			$this->add_number_field( $row, 'woocommerce_weight', $product, 'weight', $warnings );
			$this->add_explicit_text_value( $row, 'woocommerce_weight_unit', $weight_unit, $warnings );
			$this->add_number_field( $row, 'foreign_price', $product, 'patris_foreign_price', $warnings );
			$this->add_text_field( $row, 'foreign_currency', $product, 'patris_foreign_currency', $warnings );
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
			$this->add_number_field( $row, 'shipping_price_per_kg_cny', $method, 'shipping_price_per_kg_cny', $warnings );
			$this->add_number_field( $row, 'profit_percent', $assignment, 'profit_percent', $warnings );
			$this->add_text_field( $row, 'profit_percent_source', $assignment, 'profit_percent_source', $warnings );
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

			$row['record_revision'] = 'sha256:' . hash(
				'sha256',
				wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
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

		if ( ! in_array( $dataset, array( 'products', 'categories' ), true ) ) {
			return new WP_Error(
				'digitalogic_sheets_dataset_invalid',
				__( 'Dataset must be products or categories.', 'digitalogic' ),
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
			'dataset' => $dataset,
			'locale'  => $locale,
			'page'    => max( 1, absint( $args['page'] ?? 1 ) ),
			'limit'   => max( 1, min( self::MAX_PAGE_SIZE, absint( $args['limit'] ?? self::MAX_PAGE_SIZE ) ) ),
		);
	}

	/**
	 * Build a paginated Products response through the existing query service.
	 *
	 * @param array $args Normalized arguments.
	 * @return array|WP_Error
	 */
	private function get_products_page( $args ) {
		$result     = Digitalogic_Product_Manager::instance()->query_products(
			array(
				'page'  => $args['page'],
				'limit' => $args['limit'],
				'sorts' => array(
					array(
						'field'     => 'id',
						'direction' => 'asc',
					),
				),
			)
		);
		$projection = $this->transform_products( $result['products'] ?? array() );
		if ( is_wp_error( $projection ) ) {
			return $projection;
		}

		return $this->response_envelope(
			$args,
			$projection['columns'],
			$projection['rows'],
			absint( $result['recordsFiltered'] ?? 0 ),
			absint( $result['pages'] ?? 0 )
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
		$columns = array(
			$this->column( 'sync_key', 'Sync Key', 'کلید همگام‌سازی', 'text' ),
			$this->column( 'patris_code', 'Patris Code', 'کد پاتریس', 'text' ),
			$this->column( 'woocommerce_id', 'WooCommerce ID', 'شناسه ووکامرس', 'integer' ),
			$this->column( 'parent_id', 'Parent ID', 'شناسه والد', 'integer' ),
			$this->column( 'product_type', 'Product Type', 'نوع محصول', 'text' ),
			$this->column( 'publication_status', 'Publication Status', 'وضعیت انتشار', 'text' ),
			$this->column( 'name', 'Name', 'نام', 'text' ),
			$this->column( 'part_number', 'Part Number', 'پارت نامبر', 'text' ),
			$this->column( 'sku', 'SKU', 'شناسه کالا', 'text' ),
			$this->column( 'categories', 'Categories', 'دسته‌بندی‌ها', 'text' ),
			$this->column( 'category_ids', 'Category IDs', 'شناسه‌های دسته‌بندی', 'text' ),
			$this->column( 'currency', 'Currency', 'ارز', 'text' ),
			$this->column( 'regular_price', 'Regular Price', 'قیمت عادی', 'number' ),
			$this->column( 'sale_price', 'Sale Price', 'قیمت فروش ویژه', 'number' ),
			$this->column( 'effective_price', 'Effective Price', 'قیمت نهایی قابل استفاده', 'number' ),
			$this->column( 'patris_final_price', 'Patris Final Price', 'قیمت نهایی پاتریس', 'number' ),
			$this->column( 'price_status', 'Price Status', 'وضعیت قیمت', 'text' ),
			$this->column( 'stock_quantity', 'WooCommerce Stock', 'موجودی ووکامرس', 'number' ),
			$this->column( 'stock_status', 'Stock Status', 'وضعیت موجودی', 'text' ),
			$this->column( 'patris_total_stock', 'Patris Total Stock', 'موجودی کل پاتریس', 'number' ),
			$this->column( 'patris_minimum_stock', 'Minimum Stock', 'حداقل موجودی', 'number' ),
		);

		foreach ( $warehouses as $warehouse ) {
			$columns[] = $this->column(
				$this->warehouse_key( $warehouse ),
				'Warehouse Stock: ' . $warehouse,
				'موجودی انبار: ' . $warehouse,
				'number'
			);
		}

		return array_merge(
			$columns,
			array(
				$this->column( 'weight_grams', 'Weight (g)', 'وزن (گرم)', 'number' ),
				$this->column( 'woocommerce_weight', 'WooCommerce Weight', 'وزن ووکامرس', 'number' ),
				$this->column( 'woocommerce_weight_unit', 'Weight Unit', 'واحد وزن', 'text' ),
				$this->column( 'foreign_price', 'Foreign Price', 'قیمت ارزی', 'number' ),
				$this->column( 'foreign_currency', 'Foreign Currency', 'ارز خارجی', 'text' ),
				$this->column( 'shipping_method_id', 'Shipping Method ID', 'شناسه روش حمل', 'text' ),
				$this->column( 'shipping_method_name_en', 'Shipping Method', 'نام انگلیسی روش حمل', 'text' ),
				$this->column( 'shipping_method_name_fa', 'Shipping Method (Persian)', 'روش حمل', 'text' ),
				$this->column( 'shipping_price_per_kg_cny', 'Shipping Price per kg (CNY)', 'هزینه حمل هر کیلو (یوان)', 'number' ),
				$this->column( 'profit_percent', 'Profit Margin (%)', 'حاشیه سود (درصد)', 'number' ),
				$this->column( 'profit_percent_source', 'Profit Source', 'منبع حاشیه سود', 'text' ),
				$this->column( 'permalink', 'Product URL', 'نشانی محصول', 'url' ),
				$this->column( 'image_url', 'Image URL', 'نشانی تصویر', 'url' ),
				$this->column( 'updated_at', 'Updated At', 'زمان به‌روزرسانی', 'datetime' ),
				$this->column( 'sync_status', 'Sync Status', 'وضعیت همگام‌سازی', 'text' ),
				$this->column( 'sync_error', 'Sync Notes', 'توضیحات همگام‌سازی', 'text' ),
				$this->column( 'record_revision', 'Record Revision', 'شناسه بازبینی رکورد', 'text' ),
			)
		);
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
		return 'warehouse_stock:' . rawurlencode( (string) $warehouse );
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
