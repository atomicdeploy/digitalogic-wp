<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Plugin Name: Digitalogic Product Price Update Time
 * Description: Shows the authoritative product-price update time in absolute and relative Persian formats.
 * Version: 1.0.2
 * Author: Digitalogic
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DIGITALOGIC_PRICE_UPDATED_META = '_digitalogic_price_updated_at_utc';

/** Hide public price HTML even when a formatter uses a saved price. */
function digitalogic_hide_unavailable_price_html( $html, $product ) {
	return $product->is_in_stock() ? $html : '';
}
add_filter( 'woocommerce_get_price_html', 'digitalogic_hide_unavailable_price_html', PHP_INT_MAX, 2 );

/** Find whether the price range needs to exclude unavailable variations. */
function digitalogic_has_unavailable_variations( $product ) {
	if ( ! $product->is_type( 'variable' ) ) {
		return false;
	}
	foreach ( $product->get_visible_children() as $id ) {
		$variation = wc_get_product( $id );
		if ( $variation && ! $variation->is_in_stock() ) {
			return true;
		}
	}
	return false;
}

/** Filter the display range after WooCommerce loads its cached price data. */
function digitalogic_available_variation_display_prices( $prices, $product, $for_display ) {
	if ( ! $for_display ) {
		return $prices;
	}
	foreach ( array_keys( $prices['price'] ) as $id ) {
		$variation = wc_get_product( $id );
		if ( ! $variation || ! $variation->is_in_stock() ) {
			unset( $prices['price'][ $id ], $prices['regular_price'][ $id ], $prices['sale_price'][ $id ] );
		}
	}
	return $prices;
}
add_filter( 'woocommerce_variation_prices', 'digitalogic_available_variation_display_prices', PHP_INT_MAX, 3 );

/** Use WooCommerce's stock-filtered range when a tier formatter can reuse stale tiers. */
function digitalogic_available_tier_price_html( $html, $default_html, $product ) {
	return digitalogic_has_unavailable_variations( $product ) ? $default_html : $html;
}
add_filter( 'tiered_pricing_table/catalog_pricing/price_html', 'digitalogic_available_tier_price_html', PHP_INT_MAX, 3 );

/** Keep unavailable prices out of social metadata. */
function digitalogic_available_social_price( $price ) {
	$product = wc_get_product( get_queried_object_id() );
	return $product && ( ! $product->is_in_stock() || digitalogic_has_unavailable_variations( $product ) ) ? false : $price;
}
add_filter( 'rank_math/woocommerce/og_price', 'digitalogic_available_social_price', 99 );
add_filter( 'rank_math/opengraph/twitter/twitter_label1', 'digitalogic_available_social_price', 99 );
add_filter( 'rank_math/opengraph/twitter/twitter_data1', 'digitalogic_available_social_price', 99 );

/** Preserve the social card's other fields while removing its price. */
function digitalogic_available_slack_price( $data ) {
	if ( is_array( $data ) && false === digitalogic_available_social_price( true ) ) {
		unset( $data[ __( 'Price', 'seo-by-rank-math' ) ], $data['Price'] );
	}
	return $data;
}
add_filter( 'rank_math/opengraph/slack_enhanced_data', 'digitalogic_available_slack_price', 99 );

/** An offer can describe a selected variation even when its parent is in stock. */
function digitalogic_schema_offer_is_unavailable( $offer ) {
	return is_array( $offer ) && in_array(
		$offer['availability'] ?? '',
		array( 'OutOfStock', 'https://schema.org/OutOfStock', 'http://schema.org/OutOfStock' ),
		true
	);
}

/** Remove offers for unavailable stock from WooCommerce and Rank Math schema. */
function digitalogic_available_product_schema( $entity, $product = null ) {
	if ( ! is_array( $entity ) ) {
		return $entity;
	}
	if ( ! $product instanceof WC_Product ) {
		$product = wc_get_product( get_queried_object_id() );
	}
	if ( ! $product ) {
		return $entity;
	}
	$offers            = $entity['offers'] ?? array();
	$unavailable       = ! $product->is_in_stock();
	$unavailable_offer = digitalogic_schema_offer_is_unavailable( $offers ) || digitalogic_schema_offer_is_unavailable( $offers[0] ?? null );
	if ( $unavailable || $unavailable_offer ) {
		unset( $entity['offers'] );
		if ( empty( $entity['hasVariant'] ) && empty( $entity['review'] ) && empty( $entity['aggregateRating'] ) ) {
			return array();
		}
	} elseif ( current_filter() === 'rank_math/snippet/rich_snippet_product_entity' && digitalogic_has_unavailable_variations( $product ) ) {
		unset( $entity['offers'] );
	}
	if ( ! empty( $entity['hasVariant'] ) && is_array( $entity['hasVariant'] ) ) {
		foreach ( $entity['hasVariant'] as &$variant ) {
			$child_id = is_array( $variant ) && ! empty( $variant['sku'] ) ? wc_get_product_id_by_sku( $variant['sku'] ) : 0;
			$child    = $child_id ? wc_get_product( $child_id ) : false;
			if ( is_array( $variant ) && ( $unavailable || ( $child && ! $child->is_in_stock() ) || digitalogic_schema_offer_is_unavailable( $variant['offers'] ?? null ) ) ) {
				unset( $variant['offers'] );
			}
		}
		unset( $variant );
	}
	return $entity;
}
add_filter( 'woocommerce_structured_data_product', 'digitalogic_available_product_schema', 98, 2 );
add_filter( 'rank_math/snippet/rich_snippet_product_entity', 'digitalogic_available_product_schema', 98, 2 );

/** Apply the same policy to the public Store API product representations. */
function digitalogic_available_store_api_prices( $response, $server, $request ) {
	if ( $request->get_method() !== 'GET' || ! preg_match( '~^/wc/store/v1/products(?:/\d+)?$~D', $request->get_route() ) || $response->is_error() ) {
		return $response;
	}
	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}
	$collection = array_is_list( $data );
	$products   = $collection ? $data : array( $data );
	foreach ( $products as &$item ) {
		if ( ! isset( $item['prices'], $item['is_in_stock'] ) ) {
			continue;
		}
		if ( ! $item['is_in_stock'] ) {
			$item['price_html'] = '';
			$item['prices']['price'] = $item['prices']['regular_price'] = $item['prices']['sale_price'] = '';
			$item['prices']['price_range'] = null;
		} elseif ( 'variable' === ( $item['type'] ?? '' ) ) {
			$product = wc_get_product( $item['id'] );
			if ( $product && digitalogic_has_unavailable_variations( $product ) ) {
				$prices    = $product->get_variation_prices( true );
				foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
					$item['prices'][ $field ] = empty( $prices[ $field ] ) ? '' : (string) wc_add_number_precision( min( $prices[ $field ] ) );
				}
			}
		}
	}
	unset( $item );
	$response->set_data( $collection ? $products : $products[0] );
	return $response;
}
add_filter( 'rest_post_dispatch', 'digitalogic_available_store_api_prices', 20, 3 );


/**
 * Record future WooCommerce price changes at the moment a price projection is written.
 *
 * @param int    $meta_id    Meta row identifier.
 * @param int    $object_id  Product or variation identifier.
 * @param string $meta_key   Updated meta key.
 * @param mixed  $meta_value Updated value.
 * @return void
 */
function digitalogic_record_price_update_time( $meta_id, $object_id, $meta_key, $meta_value ) {
	unset( $meta_id, $meta_value );

	if ( ! in_array( $meta_key, array( '_price', '_regular_price', '_sale_price' ), true ) ) {
		return;
	}

	$post_type = get_post_type( $object_id );
	if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
		return;
	}

	$recorded_at = gmdate( 'c' );
	update_post_meta( $object_id, DIGITALOGIC_PRICE_UPDATED_META, $recorded_at );

	if ( 'product_variation' === $post_type ) {
		$parent_id = (int) wp_get_post_parent_id( $object_id );
		if ( $parent_id > 0 ) {
			update_post_meta( $parent_id, DIGITALOGIC_PRICE_UPDATED_META, $recorded_at );
		}
	}
}
add_action( 'added_post_meta', 'digitalogic_record_price_update_time', 10, 4 );
add_action( 'updated_post_meta', 'digitalogic_record_price_update_time', 10, 4 );
add_action( 'deleted_post_meta', 'digitalogic_record_price_update_time', 10, 4 );

/**
 * Convert one stored UTC/ISO value to a Unix timestamp.
 *
 * @param mixed $value Stored value.
 * @return int
 */
function digitalogic_price_update_timestamp( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 0;
	}

	try {
		$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		return max( 0, $date->getTimestamp() );
	} catch ( Exception $exception ) {
		unset( $exception );
		return 0;
	}
}

/**
 * Resolve the newest trustworthy timestamp that can affect the visible price.
 *
 * Exact tracked price writes win when present. The current pricing contract's
 * per-currency provenance supplies the historical baseline for managed Patris
 * products. Post modification is used only for products without either source.
 *
 * @param WC_Product $product Current product.
 * @return array{timestamp:int,source:string}
 */
function digitalogic_resolve_product_price_update( $product ) {
	$product_ids = array( (int) $product->get_id() );
	if ( $product->is_type( 'variable' ) ) {
		$product_ids = array_merge( $product_ids, array_map( 'absint', $product->get_children() ) );
	}

	$candidates = array();
	$currencies = array();
	foreach ( array_unique( array_filter( $product_ids ) ) as $product_id ) {
		$tracked = digitalogic_price_update_timestamp(
			get_post_meta( $product_id, DIGITALOGIC_PRICE_UPDATED_META, true )
		);
		if ( $tracked > 0 ) {
			$candidates[] = array(
				'timestamp' => $tracked,
				'source'    => 'tracked-price-write',
			);
		}

		$currency = strtoupper(
			trim( (string) get_post_meta( $product_id, '_digitalogic_patris_price_source_currency', true ) )
		);
		if ( in_array( $currency, array( 'USD', 'CNY' ), true ) ) {
			$currencies[] = strtolower( $currency );
		}
	}

	if ( class_exists( 'Digitalogic_Pricing_Service' ) && $currencies ) {
		$state = Digitalogic_Pricing_Service::instance()->current_canonical_state();
		if ( ! is_wp_error( $state ) ) {
			$provenance = isset( $state['rate_provenance'] ) && is_array( $state['rate_provenance'] )
				? $state['rate_provenance']
				: array();
			foreach ( array_unique( $currencies ) as $currency ) {
				$recorded_at = isset( $provenance[ $currency ]['recorded_at'] )
					? $provenance[ $currency ]['recorded_at']
					: '';
				$timestamp   = digitalogic_price_update_timestamp( $recorded_at );
				if ( $timestamp > 0 ) {
					$candidates[] = array(
						'timestamp' => $timestamp,
						'source'    => $currency . '-rate-provenance',
					);
				}
			}
		}
	}

	if ( ! $candidates ) {
		$modified = $product->get_date_modified();
		if ( $modified ) {
			$candidates[] = array(
				'timestamp' => (int) $modified->getTimestamp(),
				'source'    => 'product-modified-fallback',
			);
		}
	}

	if ( ! $candidates ) {
		return array(
			'timestamp' => 0,
			'source'    => 'unavailable',
		);
	}

	usort(
		$candidates,
		static function ( $left, $right ) {
			return (int) $right['timestamp'] <=> (int) $left['timestamp'];
		}
	);

	return $candidates[0];
}

/**
 * Format one UTC timestamp using the same Jalali formatter as the header.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function digitalogic_format_product_price_update_absolute( $timestamp ) {
	$local = ( new DateTimeImmutable( '@' . (int) $timestamp ) )
		->setTimezone( wp_timezone() )
		->format( 'Y-m-d H:i:s' );

	if ( function_exists( 'parsidate' ) ) {
		return parsidate( 'Y/m/d H:i', $local, 'per' );
	}

	return wp_date( 'Y/m/d H:i', (int) $timestamp, wp_timezone() );
}

/**
 * Build a public, display-only timestamp payload for one exact product leaf.
 *
 * @param WC_Product $product Exact product or variation.
 * @return array|null
 */
function digitalogic_product_price_update_payload( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() || '' === trim( (string) $product->get_price() ) ) {
		return null;
	}
	$resolved  = digitalogic_resolve_product_price_update( $product );
	$timestamp = (int) $resolved['timestamp'];
	if ( $timestamp <= 0 || $timestamp > time() + MINUTE_IN_SECONDS ) {
		return null;
	}
	$relative = human_time_diff( $timestamp, time() ) . ' پیش';
	if ( function_exists( 'per_number' ) ) {
		$relative = per_number( $relative );
	}
	return array(
		'datetime' => gmdate( 'c', $timestamp ),
		'absolute' => digitalogic_format_product_price_update_absolute( $timestamp ),
		'relative' => $relative,
		'source'   => sanitize_key( (string) $resolved['source'] ),
	);
}

/** Add the exact selected leaf's timestamp to WooCommerce variation JSON. */
function digitalogic_add_variation_price_update_payload( $data, $parent, $variation ) {
	unset( $parent );
	if ( is_array( $data ) && $variation instanceof WC_Product ) {
		$data['digitalogic_price_updated'] = digitalogic_product_price_update_payload( $variation );
	}
	return $data;
}
add_filter( 'woocommerce_available_variation', 'digitalogic_add_variation_price_update_payload', 30, 3 );

/**
 * Render the update time directly below the single-product price.
 *
 * @return void
 */
function digitalogic_render_product_price_update_time() {
	static $rendered_products = array();

	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() ) {
		return;
	}

	$product_id = (int) $product->get_id();
	if ( isset( $rendered_products[ $product_id ] ) ) {
		return;
	}

	$is_variable = $product->is_type( 'variable' );
	$payload     = $is_variable ? null : digitalogic_product_price_update_payload( $product );
	if ( ! $is_variable && null === $payload ) {
		return;
	}
	$rendered_products[ $product_id ] = true;

	echo '<div class="digitalogic-price-updated" data-digitalogic-contextual-price-update data-price-updated-source="' . esc_attr( $payload['source'] ?? '' ) . '"' . ( null === $payload ? ' hidden' : '' ) . '>';
	echo '<span class="digitalogic-price-updated__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>';
	echo '<span class="digitalogic-price-updated__label">' . esc_html__( 'آخرین به‌روزرسانی قیمت:', 'digitalogic' ) . '</span> ';
	echo '<time datetime="' . esc_attr( $payload['datetime'] ?? '' ) . '" title="' . esc_attr( isset( $payload['absolute'] ) ? $payload['absolute'] . ' به وقت تهران' : '' ) . '">';
	echo '<span class="digitalogic-price-updated__absolute" dir="ltr">' . esc_html( $payload['absolute'] ?? '' ) . '</span>';
	echo '<span class="digitalogic-price-updated__separator" aria-hidden="true"> • </span>';
	echo '<span class="digitalogic-price-updated__relative">' . esc_html( $payload['relative'] ?? '' ) . '</span>';
	echo '</time>';
	echo '</div>';
}
add_action( 'woocommerce_single_product_summary', 'digitalogic_render_product_price_update_time', 11 );

/**
 * Woodmart's active Elementor product layout renders its own price widget and
 * does not run woocommerce_single_product_summary. Keep the same renderer and
 * attach it immediately after that one widget; its per-product guard prevents
 * duplicate output if a future layout also restores the standard hook.
 *
 * @param object $widget Elementor widget instance.
 * @return void
 */
function digitalogic_render_price_update_after_woodmart_widget( $widget ) {
	if ( ! is_product() || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) ) {
		return;
	}

	if ( 'wd_single_product_price' !== $widget->get_name() ) {
		return;
	}

	digitalogic_render_product_price_update_time();
}
add_action( 'elementor/frontend/widget/after_render', 'digitalogic_render_price_update_after_woodmart_widget', 10, 1 );

/**
 * Match the active Woodmart child theme without adding another stylesheet.
 *
 * @return void
 */
function digitalogic_product_price_update_styles() {
	$css = '
	.digitalogic-price-updated {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 0.3em;
		margin: -8px 0 18px;
		color: var(--wd-text-color, #777);
		font-size: 0.86rem;
		line-height: 1.8;
	}
	.digitalogic-price-updated[hidden] { display: none !important; }
	.digitalogic-price-updated__icon { align-items: center; color: var(--wd-primary-color, #2f7d32); display: inline-flex; }
	.digitalogic-price-updated__icon svg { fill: none; height: 1rem; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; width: 1rem; }
	.digitalogic-price-updated__label {
		font-weight: 600;
		color: var(--wd-title-color, #242424);
	}
	.digitalogic-price-updated time {
		display: inline-flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 0.25em;
	}
	.digitalogic-price-updated__absolute {
		font-variant-numeric: tabular-nums;
	}
	.digitalogic-price-updated__relative {
		color: var(--wd-primary-color, #2f7d32);
	}
	@media (max-width: 575px) {
		.digitalogic-price-updated {
			font-size: 0.82rem;
			margin-bottom: 14px;
		}
	}';
	wp_add_inline_style( 'child-style', $css );
}
add_action( 'wp_enqueue_scripts', 'digitalogic_product_price_update_styles', 30 );
