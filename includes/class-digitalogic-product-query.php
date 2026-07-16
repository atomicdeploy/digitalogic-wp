<?php
/**
 * Canonical product-list query contract shared by every transport.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes product-list requests and builds bounded database query plans.
 */
final class Digitalogic_Product_Query {

	private const PRODUCT_STATUSES = array( 'publish', 'draft', 'pending', 'private' );
	private const PRODUCT_TYPES    = array( 'simple', 'variable', 'variation', 'grouped', 'external' );
	private const STOCK_STATUSES   = array( 'instock', 'outofstock', 'onbackorder' );

	private const TEXT_FILTERS = array(
		'name',
		'sku',
		'part_number',
		'patris_product_code',
		'patris_foreign_currency',
		'patris_location',
		'patris_updated_at',
	);

	private const NUMERIC_FILTERS = array(
		'regular_price',
		'sale_price',
		'stock_quantity',
		'weight',
		'patris_foreign_price',
		'patris_weight_grams',
		'patris_final_price',
	);

	private const SORT_FIELDS = array(
		'id',
		'name',
		'sku',
		'regular_price',
		'sale_price',
		'stock_quantity',
		'weight',
		'patris_foreign_price',
		'patris_weight_grams',
		'patris_final_price',
		'patris_updated_at',
	);

	/**
	 * Normalize an untrusted list request without accepting arbitrary query keys.
	 *
	 * @param array $args Raw request arguments.
	 * @return array
	 */
	public static function normalize_args( $args ) {
		$args    = is_array( $args ) ? $args : array();
		$filters = isset( $args['filters'] ) && is_array( $args['filters'] ) ? $args['filters'] : array();

		foreach ( array( 'sku', 'type', 'status', 'stock_status' ) as $legacy_key ) {
			if ( ! array_key_exists( $legacy_key, $filters ) && array_key_exists( $legacy_key, $args ) ) {
				$filters[ $legacy_key ] = $args[ $legacy_key ];
			}
		}

		self::copy_legacy_range( $filters, $args, 'regular_price', 'price_min', 'price_max' );
		self::copy_legacy_range( $filters, $args, 'stock_quantity', 'stock_min', 'stock_max' );
		self::copy_legacy_range( $filters, $args, 'weight', 'weight_min', 'weight_max' );

		$normalized_filters = array();
		if ( isset( $filters['id'] ) && preg_match( '/^[1-9][0-9]*$/', (string) $filters['id'] ) ) {
			$normalized_filters['id'] = (string) $filters['id'];
		}

		foreach ( self::TEXT_FILTERS as $key ) {
			if ( ! array_key_exists( $key, $filters ) ) {
				continue;
			}

			$value = self::text( $filters[ $key ], 160 );
			if ( '' !== $value ) {
				$normalized_filters[ $key ] = $value;
			}
		}

		$status = self::enum_filter( $filters['status'] ?? '', self::PRODUCT_STATUSES );
		if ( '' !== $status && array() !== $status ) {
			$normalized_filters['status'] = $status;
		}

		$type = self::enum_filter( $filters['type'] ?? '', self::PRODUCT_TYPES );
		if ( '' !== $type && array() !== $type ) {
			$normalized_filters['type'] = $type;
		}

		$stock_status = self::enum_filter( $filters['stock_status'] ?? '', self::STOCK_STATUSES );
		if ( '' !== $stock_status && array() !== $stock_status ) {
			$normalized_filters['stock_status'] = $stock_status;
		}

		foreach ( self::NUMERIC_FILTERS as $key ) {
			if ( ! isset( $filters[ $key ] ) || ! is_array( $filters[ $key ] ) ) {
				continue;
			}

			$range = array();
			foreach ( array( 'min', 'max' ) as $bound ) {
				if ( ! array_key_exists( $bound, $filters[ $key ] ) ) {
					continue;
				}

				$value = self::decimal( $filters[ $key ][ $bound ] );
				if ( null !== $value ) {
					$range[ $bound ] = $value;
				}
			}

			if ( $range ) {
				$normalized_filters[ $key ] = $range;
			}
		}

		$image = isset( $args['image'] ) ? sanitize_key( (string) wp_unslash( $args['image'] ) ) : '';
		if ( '' === $image && isset( $args['image_filter'] ) ) {
			$image = sanitize_key( (string) wp_unslash( $args['image_filter'] ) );
		}
		if ( ! in_array( $image, array( 'with', 'without' ), true ) ) {
			$image = 'all';
		}

		$sorts = isset( $args['sorts'] ) ? $args['sorts'] : ( isset( $args['sort'] ) ? $args['sort'] : array() );
		if ( isset( $sorts['field'] ) ) {
			$sorts = array( $sorts );
		}

		$normalized_sorts = array();
		foreach ( (array) $sorts as $sort ) {
			if ( ! is_array( $sort ) ) {
				continue;
			}

			$field = isset( $sort['field'] ) ? sanitize_key( (string) $sort['field'] ) : '';
			if ( ! in_array( $field, self::SORT_FIELDS, true ) ) {
				continue;
			}

			$normalized_sorts[] = array(
				'field'     => $field,
				'direction' => isset( $sort['direction'] ) && strtolower( (string) $sort['direction'] ) === 'asc' ? 'asc' : 'desc',
			);
			break;
		}

		return array(
			'page'    => isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1,
			'limit'   => isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 50,
			'search'  => isset( $args['search'] ) ? self::text( $args['search'], 160 ) : '',
			'filters' => $normalized_filters,
			'image'   => $image,
			'sorts'   => $normalized_sorts,
		);
	}

	/**
	 * Build a bounded WP_Query request. Every filter is part of SQL before pagination.
	 *
	 * @param array $args Normalized or raw request arguments.
	 * @param bool  $count_only Whether to request only one ID for a count.
	 * @return array
	 */
	public static function build_wp_query_args( $args, $count_only = false ) {
		$args    = self::normalize_args( $args );
		$filters = $args['filters'];
		$query   = array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => self::PRODUCT_STATUSES,
			'posts_per_page'         => $count_only ? 1 : $args['limit'],
			'paged'                  => $count_only ? 1 : $args['page'],
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'order'                  => 'DESC',
		);

		$search = array();
		if ( '' !== $args['search'] ) {
			$search[] = $args['search'];
		}
		if ( ! empty( $filters['name'] ) ) {
			$search[] = $filters['name'];
		}
		if ( $search ) {
			$query['s'] = implode( ' ', array_unique( $search ) );
		}

		if ( ! empty( $filters['id'] ) ) {
			$query['post__in'] = array( absint( $filters['id'] ) );
		}

		if ( ! empty( $filters['status'] ) ) {
			$query['post_status'] = (array) $filters['status'];
		}

		if ( ! empty( $filters['type'] ) ) {
			$types         = (array) $filters['type'];
			$has_variation = in_array( 'variation', $types, true );
			$product_types = array_values( array_diff( $types, array( 'variation' ) ) );

			if ( $has_variation && empty( $product_types ) ) {
				$query['post_type'] = array( 'product_variation' );
			} elseif ( ! $has_variation ) {
				$query['post_type'] = array( 'product' );
				$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Product type must be filtered before pagination.
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => $product_types,
					),
				);
			} else {
				$query['post_type'] = array( 'product', 'product_variation' );
				$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Product and variation types must be filtered before pagination.
					'relation' => 'OR',
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => $product_types,
					),
					array(
						'taxonomy' => 'product_type',
						'operator' => 'NOT EXISTS',
					),
				);
			}
		}

		$meta_query = array( 'relation' => 'AND' );
		$text_meta  = array(
			'sku'                     => '_sku',
			'patris_product_code'     => '_digitalogic_patris_product_code',
			'patris_foreign_currency' => '_digitalogic_patris_foreign_currency',
			'patris_location'         => '_digitalogic_patris_location',
			'patris_updated_at'       => '_digitalogic_patris_updated_at',
		);
		foreach ( $text_meta as $filter_key => $meta_key ) {
			if ( ! empty( $filters[ $filter_key ] ) ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => $filters[ $filter_key ],
					'compare' => 'LIKE',
				);
			}
		}

		if ( ! empty( $filters['part_number'] ) ) {
			$query['digitalogic_product_part_number_filter'] = $filters['part_number'];
		}

		if ( ! empty( $filters['stock_status'] ) ) {
			$stock_statuses = (array) $filters['stock_status'];
			$meta_query[]   = array(
				'key'     => '_stock_status',
				'value'   => 1 === count( $stock_statuses ) ? reset( $stock_statuses ) : $stock_statuses,
				'compare' => 1 === count( $stock_statuses ) ? '=' : 'IN',
			);
		}

		$numeric_meta = self::numeric_meta_keys();
		foreach ( $numeric_meta as $filter_key => $meta_key ) {
			if ( empty( $filters[ $filter_key ] ) || ! is_array( $filters[ $filter_key ] ) ) {
				continue;
			}

			if ( isset( $filters[ $filter_key ]['min'] ) ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => $filters[ $filter_key ]['min'],
					'compare' => '>=',
					'type'    => 'DECIMAL(24,8)',
				);
			}
			if ( isset( $filters[ $filter_key ]['max'] ) ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => $filters[ $filter_key ]['max'],
					'compare' => '<=',
					'type'    => 'DECIMAL(24,8)',
				);
			}
		}

		if ( 'with' === $args['image'] || 'without' === $args['image'] ) {
			$query['digitalogic_product_image_filter'] = $args['image'];
		}

		if ( count( $meta_query ) > 1 ) {
			$query['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Canonical filters must run before pagination.
		}

		if ( ! $count_only && $args['sorts'] ) {
			$query = self::apply_sort( $query, $args['sorts'][0] );
		}

		return $query;
	}

	/**
	 * Whether the request needs a distinct unfiltered total.
	 *
	 * @param array $args Normalized or raw request arguments.
	 * @return bool
	 */
	public static function has_active_filters( $args ) {
		$args = self::normalize_args( $args );

		return '' !== $args['search'] || array() !== $args['filters'] || 'all' !== $args['image'];
	}

	/**
	 * Apply the one supported global sort to the bounded list query.
	 *
	 * @param array $query Query arguments.
	 * @param array $sort Normalized sort definition.
	 * @return array
	 */
	private static function apply_sort( $query, $sort ) {
		$field          = $sort['field'];
		$query['order'] = 'asc' === $sort['direction'] ? 'ASC' : 'DESC';
		if ( 'id' === $field ) {
			$query['orderby'] = 'ID';
			return $query;
		}
		if ( 'name' === $field ) {
			$query['orderby'] = array(
				'title' => $query['order'],
				'ID'    => $query['order'],
			);
			return $query;
		}

		$meta_keys = array_merge(
			self::numeric_meta_keys(),
			array(
				'sku'               => '_sku',
				'patris_updated_at' => '_digitalogic_patris_updated_at',
			)
		);
		if ( isset( $meta_keys[ $field ] ) ) {
			$query['digitalogic_product_sort_meta']      = $meta_keys[ $field ];
			$query['digitalogic_product_sort_numeric']   = in_array( $field, self::NUMERIC_FILTERS, true ) ? 1 : 0;
			$query['digitalogic_product_sort_direction'] = $query['order'];
			$query['orderby']                            = 'none';
		}

		return $query;
	}

	/**
	 * Return the allowlisted numeric filter-to-meta mapping.
	 *
	 * @return array
	 */
	private static function numeric_meta_keys() {
		return array(
			'regular_price'        => '_regular_price',
			'sale_price'           => '_sale_price',
			'stock_quantity'       => '_stock',
			'weight'               => '_weight',
			'patris_foreign_price' => '_digitalogic_patris_foreign_price',
			'patris_weight_grams'  => '_digitalogic_patris_weight_grams',
			'patris_final_price'   => '_digitalogic_patris_final_price',
		);
	}

	/**
	 * Translate an older min/max request into the canonical range shape.
	 *
	 * @param array  $filters Mutable filter set.
	 * @param array  $args Raw request arguments.
	 * @param string $key Canonical range field.
	 * @param string $min_key Legacy minimum field.
	 * @param string $max_key Legacy maximum field.
	 * @return void
	 */
	private static function copy_legacy_range( &$filters, $args, $key, $min_key, $max_key ) {
		if ( isset( $filters[ $key ] ) && is_array( $filters[ $key ] ) ) {
			return;
		}

		$range = array();
		if ( array_key_exists( $min_key, $args ) ) {
			$range['min'] = $args[ $min_key ];
		}
		if ( array_key_exists( $max_key, $args ) ) {
			$range['max'] = $args[ $max_key ];
		}
		if ( $range ) {
			$filters[ $key ] = $range;
		}
	}

	/**
	 * Normalize a bounded scalar text value.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max_length Maximum character length.
	 * @return string
	 */
	private static function text( $value, $max_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	/**
	 * Normalize one enum or a legacy enum list without casting arrays.
	 *
	 * @param mixed $value Raw scalar or array value.
	 * @param array $allowed Allowed keys.
	 * @return string|array
	 */
	private static function enum_filter( $value, $allowed ) {
		$is_list = is_array( $value );
		$values  = $is_list ? $value : array( $value );
		$result  = array();

		foreach ( $values as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = sanitize_key( (string) wp_unslash( $item ) );
			if ( in_array( $item, $allowed, true ) ) {
				$result[] = $item;
			}
		}

		$result = array_values( array_unique( $result ) );
		return $is_list ? $result : ( $result[0] ?? '' );
	}

	/**
	 * Normalize Latin, Persian, or Arabic digits to a database-safe decimal.
	 *
	 * @param mixed $value Raw numeric value.
	 * @return string|null
	 */
	private static function decimal( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = strtr(
			trim( (string) wp_unslash( $value ) ),
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
				'٫' => '.',
				'٬' => '',
				',' => '',
			)
		);

		if ( '' === $value || ! preg_match( '/^-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/', $value ) ) {
			return null;
		}

		return $value;
	}
}
