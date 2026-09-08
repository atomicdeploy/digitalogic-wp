<?php
/**
 * Prevent commerce without an owner-sourced Patris identity.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.Files.FileName -- Follows the shared Patris module naming convention.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Enforce source identity and weight at commerce boundaries. */
final class Digitalogic_Patris_Mapping_Guard {
	/**
	 * Request-local source identity index.
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Register the mapping policy hooks.
	 */
	public static function boot() {
		add_filter( 'woocommerce_variation_is_visible', array( self::class, 'variation_visible' ), PHP_INT_MAX, 4 );
		add_filter( 'woocommerce_available_variation', array( self::class, 'variation_data' ), PHP_INT_MAX, 3 );
		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
			add_filter( 'woocommerce_product_get_' . $field, array( self::class, 'visible_price' ), PHP_INT_MAX, 2 );
			add_filter( 'woocommerce_product_variation_get_' . $field, array( self::class, 'visible_price' ), PHP_INT_MAX, 2 );
			add_filter( 'woocommerce_variation_prices_' . $field, array( self::class, 'visible_price' ), PHP_INT_MAX, 2 );
		}
		add_filter( 'woocommerce_is_purchasable', array( self::class, 'purchasable' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( self::class, 'purchasable' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_before_product_object_save', array( self::class, 'before_save' ), 100, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( self::class, 'before_save' ), 100, 1 );
		add_action( 'digitalogic_product_sync_state_committed', array( self::class, 'clear_index' ), 1, 0 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( self::class, 'price_hash' ), 10, 1 );
		add_action( 'updated_option', array( self::class, 'reset' ), 10, 1 );
		add_action( 'added_option', array( self::class, 'reset' ), 10, 1 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'add_post_metadata', array( self::class, 'price_metadata' ), 100, 4 );
		add_filter( 'update_post_metadata', array( self::class, 'price_metadata' ), 100, 4 );
	}

	/** Keep stock-bearing mapped models selectable even before a price is available. */
	private static function unpriced_stock_variation( $product ) {
		return $product instanceof WC_Product_Variation
			&& 'publish' === $product->get_status()
			&& self::connected( $product )
			&& $product->is_in_stock()
			&& (float) $product->get_stock_quantity() > 0
			&& '' === $product->get_price();
	}

	/** Price eligibility must not hide a model with real inventory. */
	public static function variation_visible( $visible, $variation_id, $parent_id, $variation ) {
		return self::unpriced_stock_variation( $variation ) ? true : $visible;
	}

	/** An absent price is not a zero price; purchasing remains unavailable. */
	public static function variation_data( $data, $parent, $variation ) {
		if ( self::unpriced_stock_variation( $variation ) ) {
			$data['price_html']            = '';
			$data['display_price']         = null;
			$data['display_regular_price'] = null;
			$data['is_purchasable']        = false;
		}
		return $data;
	}
	/**
	 * Discard the request-local source identity index.
	 */
	public static function clear_index() {
		self::$index = null; }
	/**
	 * Version the variation price cache.
	 *
	 * @param array $hash Existing price cache hash.
	 * @return array
	 */
	public static function price_hash( $hash ) {
		$hash['patris_mapping_policy'] = 1;
		return $hash; }

	/**
	 * Discard identity data after source state changes.
	 *
	 * @param string $name Changed option name.
	 */
	public static function reset( $name ) {
		if ( 'digitalogic_product_sync_state' === $name ) {
			self::$index = null; }
	}

	/**
	 * Parent containers derive prices from their independently checked leaves.
	 *
	 * @param WC_Product $product Product being checked.
	 * @return bool
	 */
	public static function connected( $product ) {
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && ! $child->is_type( array( 'variable', 'grouped' ) ) && self::connected( $child ) ) {
					return true; }
			}
			return false;
		}
		if ( null === self::$index ) {
			self::$index = array();
			$state       = get_option( 'digitalogic_product_sync_state', array() );
			foreach ( ( $state['sources'] ?? array() ) as $source ) {
				$scope = $source['source'] ?? array();
				foreach ( ( $source['products'] ?? array() ) as $code => $row ) {
					$key                 = ( $scope['id'] ?? '' ) . "\n" . ( $scope['dataset'] ?? '' ) . "\n" . $code;
					self::$index[ $key ] = (int) ( $source['applied_products'][ $code ]['woocommerce_id'] ?? 0 );
				}
			}
		}
		$code       = (string) $product->get_meta( '_digitalogic_patris_product_code', true );
		$owner_code = (string) $product->get_meta( '_digitalogic_patris_owner_product_code', true );
		$key        = (string) $product->get_meta( '_digitalogic_patris_owner_source_id', true ) . "\n"
		. (string) $product->get_meta( '_digitalogic_patris_owner_dataset', true ) . "\n" . $code;
		return '' !== $code && $owner_code === $code && isset( self::$index[ $key ] )
		&& self::$index[ $key ] === (int) $product->get_id();
	}

	/**
	 * Filter the displayed price.
	 *
	 * @param mixed      $price Existing price.
	 * @param WC_Product $product Product being displayed.
	 * @return mixed
	 */
	public static function visible_price( $price, $product ) {
		return self::priceable( $product ) ? $price : '';
	}
	/**
	 * Require an eligible source mapping for purchases.
	 *
	 * @param bool       $allowed Existing purchase permission.
	 * @param WC_Product $product Product being checked.
	 * @return bool
	 */
	public static function purchasable( $allowed, $product ) {
		return $allowed && self::priceable( $product );
	}
	/**
	 * Check source identity and positive source weight.
	 *
	 * @param WC_Product $product Product being checked.
	 * @return bool
	 */
	private static function priceable( $product ) {
		if ( ! self::connected( $product ) ) {
			return false; }
		if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return true; }
		$weight = $product->get_meta( '_digitalogic_patris_weight_grams', true );
		return is_numeric( $weight ) && (float) $weight > 0;
	}
	/**
	 * Block unauthorized unmapped price writes.
	 *
	 * @param WC_Product $product Product being saved.
	 * @throws RuntimeException When an unmapped product has a price.
	 */
	public static function before_save( $product ) {
		if ( Digitalogic_Patris_Price_Write_Guard::instance()->is_authorized_write() ) {
			return; }
		if ( ! self::connected( $product ) ) {
			$message = 'CRITICAL: Product ' . $product->get_id() . ' has no valid Patris mapping; price write blocked.';
			wc_get_logger()->critical( $message, array( 'source' => 'digitalogic-patris-integrity' ) );
			$report = get_option( 'digitalogic_patris_mapping_integrity', array() );
			$ids    = array_map( 'intval', $report['unmapped_ids'] ?? array() );
			if ( $product->get_id() && ! in_array( $product->get_id(), $ids, true ) ) {
				$ids[] = $product->get_id();
				update_option(
					'digitalogic_patris_mapping_integrity',
					array(
						'severity'     => 'critical',
						'checked_at'   => gmdate( 'c' ),
						'unmapped_ids' => $ids,
					),
					false
				);
			}
			if ( '' !== (string) $product->get_price( 'edit' ) ) {
				throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text; output consumers escape for their context.
			}
		}
	}
	/**
	 * Guard raw price metadata updates.
	 *
	 * @param mixed  $check Existing filter result.
	 * @param int    $id Product ID.
	 * @param string $key Metadata key.
	 * @param mixed  $value Proposed metadata value.
	 * @return mixed
	 */
	public static function price_metadata( $check, $id, $key, $value ) {
		if ( null !== $check || Digitalogic_Patris_Price_Write_Guard::instance()->is_authorized_write()
			|| ! in_array( $key, array( '_price', '_regular_price', '_sale_price' ), true ) || '' === (string) $value ) {
			return $check; }
		$p = wc_get_product( $id );
		if ( $p && ! self::connected( $p ) ) {
			wc_get_logger()->critical( 'CRITICAL: Unmapped Patris price metadata write blocked for ' . $id, array( 'source' => 'digitalogic-patris-integrity' ) );
			return false;
		}
		return $check;
	}
	/**
	 * Display the persisted mapping integrity warning.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce.
			return; }
		$report = get_option( 'digitalogic_patris_mapping_integrity', array() );
		if ( ! empty( $report['unmapped_ids'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'CRITICAL — نگاشت Patris نامعتبر است؛ قیمت و خرید این کالاها مسدود است: ' . implode( ', ', array_map( 'intval', $report['unmapped_ids'] ) ) ) . '</p></div>';
		}
	}
}
