<?php
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
 * Render the update time directly below the single-product price.
 *
 * @return void
 */
function digitalogic_render_product_price_update_time() {
	static $rendered_products = array();

	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$product_id = (int) $product->get_id();
	if ( isset( $rendered_products[ $product_id ] ) ) {
		return;
	}

	$resolved  = digitalogic_resolve_product_price_update( $product );
	$timestamp = (int) $resolved['timestamp'];
	if ( $timestamp <= 0 || $timestamp > time() + MINUTE_IN_SECONDS ) {
		return;
	}

	$absolute = digitalogic_format_product_price_update_absolute( $timestamp );
	$relative = human_time_diff( $timestamp, time() ) . ' پیش';
	if ( function_exists( 'per_number' ) ) {
		$relative = per_number( $relative );
	}
	$datetime                         = gmdate( 'c', $timestamp );
	$rendered_products[ $product_id ] = true;

	echo '<div class="digitalogic-price-updated" data-price-updated-source="' . esc_attr( $resolved['source'] ) . '">';
	echo '<span class="digitalogic-price-updated__label">' . esc_html__( 'آخرین به‌روزرسانی قیمت:', 'digitalogic' ) . '</span> ';
	echo '<time datetime="' . esc_attr( $datetime ) . '" title="' . esc_attr( $absolute . ' به وقت تهران' ) . '">';
	echo '<span class="digitalogic-price-updated__absolute" dir="ltr">' . esc_html( $absolute ) . '</span>';
	echo '<span class="digitalogic-price-updated__separator" aria-hidden="true"> • </span>';
	echo '<span class="digitalogic-price-updated__relative">' . esc_html( $relative ) . '</span>';
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
