<?php
/**
 * Product-title and variation-topology safeguards.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prevent catalogue titles and ESP32 variations from collapsing unlike products. */
final class Digitalogic_Product_Topology_Guard {

	/** Register write guards and the legacy ESP32 redirect. */
	public static function init(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'sanitize_product_title' ), 90, 2 );
		add_action( 'woocommerce_before_product_variation_object_save', array( self::class, 'validate_variation_family' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'redirect_legacy_esp32_product' ), 1 );
	}

	/**
	 * Remove catalogue-selection instructions from product names.
	 *
	 * @param array<string,mixed> $data    Prepared post data.
	 * @param array<string,mixed> $postarr Raw post data.
	 * @return array<string,mixed>
	 */
	public static function sanitize_product_title( array $data, array $postarr = array() ): array {
		unset( $postarr );
		if ( ! in_array( (string) ( $data['post_type'] ?? '' ), array( 'product', 'product_variation' ), true ) ) {
			return $data;
		}

		$title = self::clean_product_title( (string) ( $data['post_title'] ?? '' ) );
		if ( '' !== $title ) {
			$data['post_title'] = $title;
		}

		return $data;
	}

	/**
	 * Return a product name without a trailing "select model" instruction.
	 *
	 * @param string $title Candidate product title.
	 */
	public static function clean_product_title( string $title ): string {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );
		if ( ! preg_match( '/انتخاب\s+مدل/u', $title ) ) {
			return $title;
		}

		$clean = preg_replace( '/\s*[؛:|\-–—]*\s*انتخاب\s+مدل(?:\s+.*)?$/u', '', $title );

		return (string) preg_replace( '/^[\s\-–—؛:|]+|[\s\-–—؛:|]+$/u', '', (string) $clean );
	}

	/**
	 * Reject a variation save when one parent would contain different ESP32 silicon families.
	 *
	 * @param mixed $variation  WooCommerce variation object.
	 * @param mixed $data_store WooCommerce data store.
	 * @throws WC_Data_Exception When the parent would mix silicon families.
	 */
	public static function validate_variation_family( $variation, $data_store = null ): void {
		unset( $data_store );
		if ( ! $variation instanceof WC_Product_Variation ) {
			return;
		}

		$parent_id = (int) $variation->get_parent_id();
		$family    = self::variation_family( $variation, $parent_id );
		if ( $parent_id <= 0 || '' === $family ) {
			return;
		}

		$sibling_ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'post_parent'    => $parent_id,
				'post__not_in'   => array_filter( array( (int) $variation->get_id() ) ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		foreach ( $sibling_ids as $sibling_id ) {
			$sibling = wc_get_product( (int) $sibling_id );
			if ( ! $sibling instanceof WC_Product_Variation ) {
				continue;
			}
			$sibling_family = self::variation_family( $sibling, $parent_id );
			if ( '' !== $sibling_family && $family !== $sibling_family ) {
				throw new WC_Data_Exception(
					'digitalogic_mixed_esp32_silicon_family',
					esc_html__( 'ESP32, ESP32-C3 and ESP32-S3 are separate products. Variations under one parent must use the same silicon family and physical base model.', 'digitalogic' )
				);
			}
		}
	}

	/**
	 * Return the explicit ESP32 family carried by variation attributes.
	 *
	 * @param WC_Product_Variation $variation Variation being classified.
	 * @param int                  $parent_id Parent product ID when already known.
	 */
	public static function variation_family( WC_Product_Variation $variation, int $parent_id = 0 ): string {
		$attribute_text = implode( ' ', array_map( 'strval', $variation->get_variation_attributes() ) );
		$families       = self::esp32_families( $attribute_text );
		if ( 1 === count( $families ) ) {
			return $families[0];
		}

		$parent_id = $parent_id > 0 ? $parent_id : (int) $variation->get_parent_id();
		$families  = self::esp32_families( get_the_title( $parent_id ) );

		return 1 === count( $families ) ? $families[0] : '';
	}

	/**
	 * Extract distinct ESP32 silicon families from a label.
	 *
	 * @param string $text Product or variation identity text.
	 * @return string[]
	 */
	public static function esp32_families( string $text ): array {
		$text     = strtoupper( str_replace( array( '_', '–', '—' ), '-', $text ) );
		$families = array();
		$patterns = array(
			'ESP32-C3' => '/(?:ESP32\s*-?\s*C3|\bC3\b)/',
			'ESP32-S3' => '/(?:ESP32\s*-?\s*S3|\bS3\b)/',
			'ESP32-C6' => '/(?:ESP32\s*-?\s*C6|\bC6\b)/',
			'ESP32-S2' => '/(?:ESP32\s*-?\s*S2|\bS2\b)/',
			'ESP32-H2' => '/(?:ESP32\s*-?\s*H2|\bH2\b)/',
		);
		foreach ( $patterns as $family => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				$families[] = $family;
			}
		}
		if ( preg_match( '/(?:WROOM-32|WROVER|ESP32)(?!\s*-?\s*(?:C3|S3|C6|S2|H2))/', $text ) ) {
			$families[] = 'ESP32';
		}

		return array_values( array_unique( $families ) );
	}

	/** Redirect the retired mixed-family product URL without losing old links. */
	public static function redirect_legacy_esp32_product(): void {
		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$url         = self::legacy_esp32_redirect_url( $request_uri );
		if ( '' !== $url ) {
			wp_safe_redirect( $url, 301, 'Digitalogic' );
			exit;
		}
	}

	/**
	 * Return the correct destination for an old mixed-family product URL.
	 *
	 * @param string $request_uri Current relative request URI.
	 */
	public static function legacy_esp32_redirect_url( string $request_uri ): string {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '/product/esp-32/' !== trailingslashit( $path ) ) {
			return '';
		}

		$query = array();
		parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $query );
		$model = sanitize_key( (string) ( $query['attribute_pa_model'] ?? '' ) );
		if ( 'c3' === $model ) {
			return home_url( '/product/esp32-c3-development-board/' );
		}
		if ( 's3' === $model ) {
			return home_url( '/product/esp32-s3-development-board/' );
		}

		return add_query_arg(
			array(
				's'         => in_array( $model, array( 'wroom-32', 'wroom-32d', 'wroom-32u', 'wrover' ), true ) ? 'ESP32 ' . strtoupper( $model ) : 'ESP32',
				'post_type' => 'product',
			),
			home_url( '/' )
		);
	}
}
