<?php
/**
 * Shared product-column contract for catalog transports and editable workbooks.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps human labels, machine keys, data types, and write permissions aligned.
 */
final class Digitalogic_Product_Column_Schema {

	/**
	 * Return the canonical catalog projection used by Sheets and automation.
	 *
	 * @param array $warehouses Warehouse display names.
	 * @return array
	 */
	public static function catalog_columns( $warehouses = array() ) {
		$columns = array(
			self::catalog_column( 'sync_key', 'Sync Key', 'کلید همگام سازی', 'text' ),
			self::catalog_column( 'patris_code', 'Patris Code', 'کد کالا', 'text' ),
			self::catalog_column( 'woocommerce_id', 'WooCommerce ID', 'شناسه ووکامرس', 'integer' ),
			self::catalog_column( 'parent_id', 'Parent ID', 'شناسه والد', 'integer' ),
			self::catalog_column( 'product_type', 'Product Type', 'نوع محصول', 'text' ),
			self::catalog_column( 'publication_status', 'Publication Status', 'وضعیت انتشار', 'text' ),
			self::catalog_column( 'name', 'Name', 'نام', 'text' ),
			self::catalog_column( 'part_number', 'Part Number', 'پارت نامبر', 'text' ),
			self::catalog_column( 'sku', 'SKU', 'شناسه کالا', 'text' ),
			self::catalog_column( 'categories', 'Categories', 'دسته بندی ها', 'text' ),
			self::catalog_column( 'category_ids', 'Category IDs', 'شناسه های دسته بندی', 'text' ),
			self::catalog_column( 'currency', 'Currency', 'ارز', 'text' ),
			self::catalog_column( 'regular_price', 'Regular Price', 'قیمت عادی', 'number' ),
			self::catalog_column( 'sale_price', 'Sale Price', 'قیمت فروش ویژه', 'number' ),
			self::catalog_column( 'effective_price', 'Effective Price', 'قیمت نهایی قابل استفاده', 'number' ),
			self::catalog_column( 'patris_final_price', 'Patris Final Price', 'قیمت نهایی پاتریس', 'number' ),
			self::catalog_column( 'price_status', 'Price Status', 'وضعیت قیمت', 'text' ),
			self::catalog_column( 'stock_quantity', 'WooCommerce Stock', 'موجودی ووکامرس', 'number' ),
			self::catalog_column( 'stock_status', 'Stock Status', 'وضعیت موجودی', 'text' ),
			self::catalog_column( 'patris_total_stock', 'Patris Total Stock', 'موجودی کل پاتریس', 'number' ),
			self::catalog_column( 'patris_minimum_stock', 'Minimum Stock', 'حداقل موجودی', 'number' ),
		);

		foreach ( self::normalize_warehouses( $warehouses ) as $warehouse ) {
			$columns[] = self::catalog_column(
				self::warehouse_key( $warehouse ),
				'Warehouse Stock: ' . $warehouse,
				'موجودی انبار: ' . $warehouse,
				'number'
			);
		}

		return array_merge(
			$columns,
			array(
				self::catalog_column( 'weight_grams', 'Weight (g)', 'وزن (گرم)', 'number' ),
				self::catalog_column( 'woocommerce_weight', 'WooCommerce Weight', 'وزن ووکامرس', 'number' ),
				self::catalog_column( 'woocommerce_weight_unit', 'Weight Unit', 'واحد وزن', 'text' ),
				self::catalog_column( 'foreign_price', 'Foreign Price', 'قیمت ارزی', 'number' ),
				self::catalog_column( 'foreign_currency', 'Foreign Currency', 'ارز خارجی', 'text' ),
				self::catalog_column( 'shipping_method_id', 'Shipping Method ID', 'شناسه روش حمل', 'text' ),
				self::catalog_column( 'shipping_method_name_en', 'Shipping Method', 'نام انگلیسی روش حمل', 'text' ),
				self::catalog_column( 'shipping_method_name_fa', 'Shipping Method (Persian)', 'روش حمل', 'text' ),
				self::catalog_column( 'shipping_price_per_kg', 'Shipping Price per kg', 'هزینه حمل هر کیلو', 'number' ),
				self::catalog_column( 'shipping_price_per_kg_currency', 'Shipping Price Currency', 'ارز هزینه حمل', 'text' ),
				self::catalog_column( 'profit_percent', 'Profit Margin (%)', 'حاشیه سود (درصد)', 'number' ),
				self::catalog_column( 'profit_percent_source', 'Profit Source', 'منبع حاشیه سود', 'text' ),
				self::catalog_column( 'permalink', 'Product URL', 'نشانی محصول', 'url' ),
				self::catalog_column( 'image_url', 'Image URL', 'نشانی تصویر', 'url' ),
				self::catalog_column( 'updated_at', 'Updated At', 'زمان به روزرسانی', 'datetime' ),
				self::catalog_column( 'sync_status', 'Sync Status', 'وضعیت همگام سازی', 'text' ),
				self::catalog_column( 'sync_error', 'Sync Notes', 'توضیحات همگام سازی', 'text' ),
				self::catalog_column( 'record_revision', 'Record Revision', 'شناسه بازبینی رکورد', 'text' ),
			)
		);
	}

	/**
	 * Return the operator workbook contract.
	 *
	 * Read-only context columns remain exportable, while writable columns map to
	 * the product manager or the established dynamic-pricing metadata contract.
	 *
	 * @param array $warehouses Warehouse display names.
	 * @return array
	 */
	public static function workbook_columns( $warehouses = array() ) {
		$columns = array(
			self::workbook_column( 'woocommerce_id', 'WooCommerce ID', 'شناسه ووکامرس', 'integer', false, '', array( 'ID', 'Product ID' ), 16 ),
			self::workbook_column( 'name', 'Name', 'نام', 'text', true, 'name', array(), 38 ),
			self::workbook_column( 'sku', 'SKU', 'شناسه کالا', 'text', true, 'sku', array( 'Product Code' ), 20 ),
			self::workbook_column( 'patris_code', 'Patris Code', 'کد کالای پاتریس', 'text', false, '', array( 'Code' ), 20 ),
			self::workbook_column( 'product_type', 'Product Type', 'نوع محصول', 'text', false, '', array( 'Type' ), 16 ),
			self::workbook_column( 'publication_status', 'Publication Status', 'وضعیت انتشار', 'text', true, 'status', array( 'Status' ), 18 ),
			self::workbook_column( 'regular_price', 'Regular Price', 'قیمت عادی', 'number', true, 'regular_price', array(), 18 ),
			self::workbook_column( 'sale_price', 'Sale Price', 'قیمت فروش ویژه', 'number', true, 'sale_price', array(), 18 ),
			self::workbook_column( 'stock_quantity', 'WooCommerce Stock', 'موجودی ووکامرس', 'number', true, 'stock_quantity', array( 'Stock Quantity' ), 20 ),
			self::workbook_column( 'stock_status', 'Stock Status', 'وضعیت موجودی', 'text', true, 'stock_status', array(), 18 ),
			self::workbook_column( 'woocommerce_weight', 'WooCommerce Weight', 'وزن ووکامرس', 'number', true, 'weight', array( 'Weight' ), 20 ),
			self::workbook_column( 'length', 'Length', 'طول', 'number', true, 'length', array(), 12 ),
			self::workbook_column( 'width', 'Width', 'عرض', 'number', true, 'width', array(), 12 ),
			self::workbook_column( 'height', 'Height', 'ارتفاع', 'number', true, 'height', array(), 12 ),
			self::workbook_column( 'dynamic_pricing', 'Dynamic Pricing', 'قیمت گذاری پویا', 'text', true, '_dynamic_pricing', array(), 20 ),
			self::workbook_column( 'currency_type', 'Currency Type', 'نوع ارز', 'text', true, '_currency_type', array(), 16 ),
			self::workbook_column( 'base_price', 'Base Price', 'قیمت پایه', 'number', true, '_base_price', array(), 16 ),
			self::workbook_column( 'markup', 'Markup', 'حاشیه سود', 'number', true, '_markup', array(), 14 ),
			self::workbook_column( 'markup_type', 'Markup Type', 'نوع حاشیه سود', 'text', true, '_markup_type', array(), 18 ),
			self::workbook_column( 'foreign_currency', 'Foreign Currency', 'ارز خارجی پاتریس', 'text', true, 'patris_foreign_currency', array(), 18 ),
			self::workbook_column( 'foreign_price', 'Foreign Price', 'قیمت ارزی پاتریس', 'number', true, 'patris_foreign_price', array(), 18 ),
			self::workbook_column( 'weight_grams', 'Weight (g)', 'وزن پاتریس (گرم)', 'number', true, 'patris_weight_grams', array(), 18 ),
			self::workbook_column( 'patris_total_stock', 'Patris Total Stock', 'موجودی کل پاتریس', 'number', true, 'patris_total_stock', array(), 20 ),
			self::workbook_column( 'patris_minimum_stock', 'Minimum Stock', 'حداقل موجودی', 'number', true, 'patris_minimum_stock', array(), 18 ),
			self::workbook_column( 'patris_location', 'Patris Location', 'موقعیت پاتریس', 'text', true, 'patris_location', array(), 18 ),
			self::workbook_column( 'patris_final_price', 'Patris Final Price', 'قیمت نهایی پاتریس', 'number', true, 'patris_final_price', array(), 20 ),
		);

		foreach ( self::normalize_warehouses( $warehouses ) as $warehouse ) {
			$columns[] = self::workbook_column(
				self::warehouse_key( $warehouse ),
				'Warehouse Stock: ' . $warehouse,
				'موجودی انبار: ' . $warehouse,
				'number',
				false,
				'',
				array(),
				18,
				'warehouse'
			);
		}

		return array_merge(
			$columns,
			array(
				self::workbook_column( 'shipping_method_id', 'Shipping Method ID', 'شناسه روش حمل', 'text', false, '', array(), 20 ),
				self::workbook_column( 'shipping_method_name_en', 'Shipping Method', 'نام انگلیسی روش حمل', 'text', false, '', array(), 22 ),
				self::workbook_column( 'shipping_method_name_fa', 'Shipping Method (Persian)', 'روش حمل', 'text', false, '', array(), 22 ),
				self::workbook_column( 'shipping_price_per_kg', 'Shipping Price per kg', 'هزینه حمل هر کیلو', 'number', false, '', array(), 22 ),
				self::workbook_column( 'shipping_price_per_kg_currency', 'Shipping Price Currency', 'ارز هزینه حمل', 'text', false, '', array(), 22 ),
				self::workbook_column( 'profit_percent', 'Profit Margin (%)', 'حاشیه سود (درصد)', 'number', false, '', array(), 18 ),
				self::workbook_column( 'profit_percent_source', 'Profit Source', 'منبع حاشیه سود', 'text', false, '', array(), 18 ),
			)
		);
	}

	/**
	 * Resolve a human, bilingual, legacy, or machine header row.
	 *
	 * @param array $headers Raw header cells.
	 * @return array|WP_Error Indexed canonical keys.
	 */
	public static function resolve_workbook_headers( $headers ) {
		$aliases = array();
		foreach ( self::workbook_columns() as $column ) {
			foreach ( self::column_aliases( $column ) as $alias ) {
				$aliases[ self::normalize_header( $alias ) ] = $column['key'];
			}
		}

		$resolved = array();
		$seen     = array();
		foreach ( array_values( (array) $headers ) as $index => $header ) {
			$raw        = trim( (string) $header );
			$normalized = self::normalize_header( $raw );
			$key        = $aliases[ $normalized ] ?? '';

			if ( '' === $key && 0 === strpos( $raw, 'warehouse_stock:' ) ) {
				$key = '' !== self::warehouse_name_from_key( $raw ) ? $raw : '';
			}
			if ( '' === $key ) {
				$warehouse = self::warehouse_from_header( $raw );
				$key       = '' !== $warehouse ? self::warehouse_key( $warehouse ) : '';
			}

			if ( '' === $raw || '' === $key || isset( $seen[ $key ] ) ) {
				return new WP_Error(
					'invalid_headers',
					__( 'Excel headers must be recognized, unique product-template columns.', 'digitalogic' )
				);
			}

			$resolved[ $index ] = $key;
			$seen[ $key ]       = true;
		}

		if ( ! isset( $seen['woocommerce_id'] ) ) {
			return new WP_Error( 'invalid_headers', __( 'Excel imports require a WooCommerce ID column.', 'digitalogic' ) );
		}

		$writable = array_filter(
			self::workbook_columns(),
			static function ( $column ) use ( $seen ) {
				return ! empty( $column['writable'] ) && isset( $seen[ $column['key'] ] );
			}
		);
		if ( ! $writable ) {
			return new WP_Error( 'invalid_headers', __( 'Excel imports require at least one writable product column.', 'digitalogic' ) );
		}

		return $resolved;
	}

	/**
	 * Find one workbook definition by machine key.
	 *
	 * @param string $key Machine key.
	 * @param array  $warehouses Warehouse display names.
	 * @return array|null
	 */
	public static function workbook_column_by_key( $key, $warehouses = array() ) {
		if ( 0 === strpos( (string) $key, 'warehouse_stock:' ) ) {
			$warehouse = self::warehouse_name_from_key( $key );
			return self::workbook_column(
				$key,
				'Warehouse Stock: ' . $warehouse,
				'موجودی انبار: ' . $warehouse,
				'number',
				false,
				'',
				array(),
				18,
				'warehouse'
			);
		}

		foreach ( self::workbook_columns( $warehouses ) as $column ) {
			if ( $column['key'] === $key ) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * Localize a column header without losing its stable machine key metadata.
	 *
	 * @param array  $column Column definition.
	 * @param string $locale en, fa, or bilingual.
	 * @return string
	 */
	public static function localized_header( $column, $locale ) {
		if ( 'fa' === $locale ) {
			return $column['label_fa'];
		}
		if ( 'bilingual' === $locale ) {
			return $column['label_en'] . ' / ' . $column['label_fa'];
		}

		return $column['label_en'];
	}

	/**
	 * Turn a warehouse label into a stable, collision-resistant column key.
	 *
	 * @param string $warehouse Warehouse display name.
	 * @return string
	 */
	public static function warehouse_key( $warehouse ) {
		return 'warehouse_stock:' . rawurlencode( trim( (string) $warehouse ) );
	}

	/**
	 * Recover a display name from a warehouse key.
	 *
	 * @param string $key Machine key.
	 * @return string
	 */
	public static function warehouse_name_from_key( $key ) {
		return rawurldecode( substr( (string) $key, strlen( 'warehouse_stock:' ) ) );
	}

	/**
	 * Return unique warehouse labels in deterministic order.
	 *
	 * @param array $warehouses Raw labels.
	 * @return array
	 */
	public static function normalize_warehouses( $warehouses ) {
		$names = array();
		foreach ( (array) $warehouses as $warehouse ) {
			$warehouse = trim( (string) $warehouse );
			if ( '' !== $warehouse ) {
				$names[ $warehouse ] = true;
			}
		}
		$names = array_keys( $names );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );

		return $names;
	}

	/**
	 * Construct public catalog metadata.
	 */
	private static function catalog_column( $key, $label_en, $label_fa, $type ) {
		return array(
			'key'      => $key,
			'label_en' => $label_en,
			'label_fa' => $label_fa,
			'type'     => $type,
		);
	}

	/**
	 * Construct workbook metadata.
	 */
	private static function workbook_column( $key, $label_en, $label_fa, $type, $writable, $manager_field, $aliases, $width, $group = 'product' ) {
		return array(
			'key'           => $key,
			'label_en'      => $label_en,
			'label_fa'      => $label_fa,
			'type'          => $type,
			'writable'      => (bool) $writable,
			'manager_field' => $manager_field,
			'aliases'       => array_values( (array) $aliases ),
			'width'         => (float) $width,
			'group'         => $group,
		);
	}

	/**
	 * List every accepted header for a workbook column.
	 */
	private static function column_aliases( $column ) {
		return array_merge(
			array(
				$column['key'],
				$column['label_en'],
				$column['label_fa'],
				self::localized_header( $column, 'bilingual' ),
			),
			$column['aliases']
		);
	}

	/**
	 * Extract the warehouse label from English, Persian, or bilingual headers.
	 */
	private static function warehouse_from_header( $header ) {
		$patterns = array(
			'/^Warehouse Stock:\s*(.+?)(?:\s+\/\s+موجودی انبار:.*)?$/ui',
			'/^موجودی انبار:\s*(.+?)(?:\s+\/\s+Warehouse Stock:.*)?$/ui',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, trim( (string) $header ), $matches ) ) {
				return trim( $matches[1] );
			}
		}

		return '';
	}

	/**
	 * Normalize visual Unicode differences without changing source values.
	 */
	private static function normalize_header( $header ) {
		$header = trim( (string) $header );
		$header = strtr(
			$header,
			array(
				'ي' => 'ی',
				'ك' => 'ک',
				'‌'  => ' ',
			)
		);
		$header = preg_replace( '/\s+/u', ' ', $header );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $header, 'UTF-8' ) : strtolower( $header );
	}
}
