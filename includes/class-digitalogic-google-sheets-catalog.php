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

	public const SCHEMA         = 'digitalogic.google-sheets-catalog';
	public const SCHEMA_VERSION = 1;
	public const MAX_PAGE_SIZE  = 100;

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
			$integration_catalog = Digitalogic_Import_Freight_Service::instance()->get_integration_catalog();
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
				$assignment_batch = Digitalogic_Import_Freight_Service::instance()
					->get_product_assignments_by_codes( array_keys( $identifiers ) );
			}
		}
		if ( is_wp_error( $assignment_batch ) ) {
			return $assignment_batch;
		}

		$assignments = $this->index_assignments( $assignment_batch );
		$methods     = $this->index_methods( $integration_catalog );
		$warehouses  = $this->warehouse_names( $products, $integration_catalog );
		$currency    = isset( $integration_catalog['currency']['local'] )
			? (string) $integration_catalog['currency']['local']
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
			$method_id         = $this->first_string(
				$assignment,
				array( 'shipping_method_id', 'import_freight_method_id' )
			);
			$method            = '' !== $method_id && isset( $methods[ $method_id ] ) ? $methods[ $method_id ] : array();
			$patris_code       = trim( (string) ( $product['patris_product_code'] ?? '' ) );
			$product_id        = absint( $product['id'] ?? 0 );
			$warnings          = array();

			if ( '' === $patris_code ) {
				$warnings[] = 'missing_patris_code';
			}
			if ( is_array( $assignment_result ) && 'error' === ( $assignment_result['status'] ?? '' ) ) {
				$warnings[] = (string) ( $assignment_result['error']['code'] ?? 'shipping_assignment_unavailable' );
			}
			if ( '' !== $method_id && ! $method ) {
				$warnings[] = 'shipping_method_not_found';
			}

			$effective_price = $this->first_value(
				$product,
				array( 'patris_final_price', 'price', 'sale_price', 'regular_price' )
			);
			if ( null === $effective_price || '' === $effective_price ) {
				$warnings[] = 'missing_effective_price';
			}

			$row = array(
				'sync_key'                  => '' !== $patris_code ? $patris_code : ( '' !== $identifier ? $identifier : 'woo:' . $product_id ),
				'patris_code'               => $patris_code,
				'woocommerce_id'            => $product_id,
				'parent_id'                 => absint( $product['parent_id'] ?? 0 ),
				'product_type'              => (string) ( $product['type'] ?? '' ),
				'publication_status'        => (string) ( $product['status'] ?? '' ),
				'name'                      => (string) ( $product['name'] ?? '' ),
				'part_number'               => (string) ( $product['part_number'] ?? '' ),
				'sku'                       => (string) ( $product['sku'] ?? '' ),
				'categories'                => $this->category_names( $product['categories'] ?? array() ),
				'category_ids'              => implode( ',', array_map( 'strval', (array) ( $product['category_ids'] ?? array() ) ) ),
				'currency'                  => $currency,
				'regular_price'             => $this->number_or_null( $product['regular_price'] ?? null ),
				'sale_price'                => $this->number_or_null( $product['sale_price'] ?? null ),
				'effective_price'           => $this->number_or_null( $effective_price ),
				'patris_final_price'        => $this->number_or_null( $product['patris_final_price'] ?? null ),
				'price_status'              => (string) ( $product['patris_price_status'] ?? '' ),
				'stock_quantity'            => $this->number_or_null( $product['stock_quantity'] ?? null ),
				'stock_status'              => (string) ( $product['stock_status'] ?? '' ),
				'patris_total_stock'        => $this->number_or_null( $product['patris_total_stock'] ?? null ),
				'patris_minimum_stock'      => $this->number_or_null( $product['patris_minimum_stock'] ?? null ),
				'weight_grams'              => $this->product_weight_grams( $product, $weight_unit ),
				'woocommerce_weight'        => $this->number_or_null( $product['weight'] ?? null ),
				'woocommerce_weight_unit'   => $weight_unit,
				'foreign_price'             => $this->number_or_null( $product['patris_foreign_price'] ?? null ),
				'foreign_currency'          => (string) ( $product['patris_foreign_currency'] ?? '' ),
				'shipping_method_id'        => $method_id,
				'shipping_method_name_en'   => (string) ( $method['name'] ?? '' ),
				'shipping_method_name_fa'   => $this->method_name_fa( $method_id, (string) ( $method['name'] ?? '' ) ),
				'shipping_price_per_kg_cny' => $this->number_or_null( $method['price_per_kg_cny'] ?? null ),
				'profit_percent'            => $this->number_or_null( $assignment['profit_percent'] ?? null ),
				'profit_percent_source'     => (string) ( $assignment['profit_percent_source'] ?? '' ),
				'permalink'                 => (string) ( $product['canonical_url'] ?? $product['permalink'] ?? '' ),
				'image_url'                 => (string) ( $product['image'] ?? '' ),
				'updated_at'                => (string) ( $product['patris_updated_at'] ?? $product['date_modified'] ?? '' ),
				'sync_status'               => $warnings ? 'warning' : 'ok',
				'sync_error'                => implode( ';', array_values( array_unique( array_filter( $warnings ) ) ) ),
			);

			$stock = isset( $product['patris_warehouse_stock'] ) && is_array( $product['patris_warehouse_stock'] )
				? $product['patris_warehouse_stock']
				: array();
			foreach ( $warehouses as $warehouse ) {
				$row[ $this->warehouse_key( $warehouse ) ] = array_key_exists( $warehouse, $stock )
					? $this->number_or_text( $stock[ $warehouse ] )
					: null;
			}

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

			$parent_name = '';
			$parent_id   = absint( $term->parent ?? 0 );
			if ( $parent_id ) {
				$parent = get_term( $parent_id, 'product_cat' );
				if ( ! is_wp_error( $parent ) && is_object( $parent ) ) {
					$parent_name = (string) ( $parent->name ?? '' );
				}
			}
			$link                   = get_term_link( $term, 'product_cat' );
			$link                   = is_wp_error( $link ) ? '' : (string) $link;
			$row                    = array(
				'sync_key'      => 'category:' . absint( $term->term_id ),
				'category_id'   => absint( $term->term_id ),
				'name'          => (string) ( $term->name ?? '' ),
				'slug'          => (string) ( $term->slug ?? '' ),
				'parent_id'     => $parent_id,
				'parent_name'   => $parent_name,
				'product_count' => absint( $term->count ?? 0 ),
				'description'   => wp_strip_all_tags( (string) ( $term->description ?? '' ) ),
				'permalink'     => $link,
				'sync_status'   => 'ok',
				'sync_error'    => '',
			);
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
			'schema'         => self::SCHEMA,
			'schema_version' => self::SCHEMA_VERSION,
			'dataset'        => $args['dataset'],
			'locale'         => $args['locale'],
			'generated_at'   => gmdate( 'c' ),
			'page_revision'  => 'sha256:' . hash(
				'sha256',
				wp_json_encode( $revision_material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			),
			'columns'        => $columns,
			'rows'           => array_values( $rows ),
			'pagination'     => array(
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
				$this->column( 'record_revision', 'Record Revision', 'نسخه رکورد', 'text' ),
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
			$this->column( 'record_revision', 'Record Revision', 'نسخه رکورد', 'text' ),
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
	 * Index a batch assignment response by requested Code/SKU.
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
	 * Index either the current or the canonical-renamed shipping catalog.
	 *
	 * @param array $catalog Integration catalog.
	 * @return array
	 */
	private function index_methods( $catalog ) {
		$source  = isset( $catalog['shipping_methods'] )
			? $catalog['shipping_methods']
			: ( $catalog['import_freight_methods'] ?? array() );
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
		foreach ( array( 'patris_product_code', 'sku' ) as $key ) {
			$value = trim( (string) ( $product[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
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
	 * @return float|int|null
	 */
	private function product_weight_grams( $product, $store_unit ) {
		$patris = $this->number_or_null( $product['patris_weight_grams'] ?? null );
		if ( null !== $patris ) {
			return $patris;
		}

		$weight = $this->number_or_null( $product['weight'] ?? null );
		if ( null === $weight || $weight <= 0 ) {
			return null;
		}
		$grams = wc_get_weight( $weight, 'g', '' !== $store_unit ? $store_unit : 'kg' );

		return $this->number_or_null( $grams );
	}

	/**
	 * Read the first non-empty value.
	 *
	 * @param array $values Source values.
	 * @param array $keys Ordered keys.
	 * @return mixed|null
	 */
	private function first_value( $values, $keys ) {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $values ) && null !== $values[ $key ] && '' !== $values[ $key ] ) {
				return $values[ $key ];
			}
		}

		return null;
	}

	/**
	 * Read the first non-empty string.
	 *
	 * @param array $values Source values.
	 * @param array $keys Ordered keys.
	 * @return string
	 */
	private function first_string( $values, $keys ) {
		$value = $this->first_value( $values, $keys );

		return null === $value ? '' : trim( (string) $value );
	}

	/**
	 * Convert finite numerics to spreadsheet numbers and blanks to null.
	 *
	 * @param mixed $value Source value.
	 * @return float|int|null
	 */
	private function number_or_null( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
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
	 * @return mixed
	 */
	private function number_or_text( $value ) {
		$number = $this->number_or_null( $value );

		return null !== $number ? $number : ( is_scalar( $value ) ? (string) $value : null );
	}
}
