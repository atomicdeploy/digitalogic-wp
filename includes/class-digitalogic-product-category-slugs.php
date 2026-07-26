<?php
/**
 * Source-neutral public slugs for integration-managed product categories.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns managed category slug generation, lookup, migration redirects.
 */
final class Digitalogic_Product_Category_Slugs {

	public const CATEGORY_CODE_META     = '_digitalogic_patris_category_code';
	public const CATEGORY_MANAGED_META  = '_digitalogic_patris_category_managed';
	public const LEGACY_SLUGS_META      = '_digitalogic_product_category_legacy_slugs';
	public const NEUTRAL_PREFIX         = 'product-category-';
	public const LEGACY_PREFIX          = 'patris-';
	public const LEGACY_REDIRECT_STATUS = 301;

	/**
	 * Singleton service.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the singleton service.
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
	 * Register the public compatibility redirect.
	 */
	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy_category' ), 1 );
	}

	/**
	 * Build the stable public slug for a source category Code.
	 *
	 * @param string $category_code Exact source category Code.
	 * @return string
	 */
	public static function neutral_slug( $category_code ) {
		$suffix = sanitize_title( trim( (string) $category_code ) );

		return '' === $suffix ? '' : self::NEUTRAL_PREFIX . $suffix;
	}

	/**
	 * Build the historic public slug for a source category Code.
	 *
	 * @param string $category_code Exact source category Code.
	 * @return string
	 */
	public static function legacy_slug( $category_code ) {
		$suffix = sanitize_title( trim( (string) $category_code ) );

		return '' === $suffix ? '' : self::LEGACY_PREFIX . $suffix;
	}

	/**
	 * Record one exact historic slug before a managed term is migrated.
	 *
	 * @param int    $term_id Exact term ID.
	 * @param string $legacy_slug Historic public slug.
	 * @return bool
	 */
	public function remember_legacy_slug( $term_id, $legacy_slug ) {
		$legacy_slug = sanitize_title( (string) $legacy_slug );
		if ( ! str_starts_with( $legacy_slug, self::LEGACY_PREFIX ) ) {
			return false;
		}

		$stored   = $this->normalize_legacy_slugs( get_term_meta( (int) $term_id, self::LEGACY_SLUGS_META, true ) );
		$stored[] = $legacy_slug;
		$stored   = array_values( array_unique( $stored ) );
		sort( $stored, SORT_STRING );
		if ( false === update_term_meta( (int) $term_id, self::LEGACY_SLUGS_META, $stored ) ) {
			return false;
		}

		return in_array(
			$legacy_slug,
			$this->normalize_legacy_slugs( get_term_meta( (int) $term_id, self::LEGACY_SLUGS_META, true ) ),
			true
		);
	}

	/**
	 * Resolve one unambiguous product category by its authoritative source Code.
	 *
	 * @param string $category_code Exact source category Code.
	 * @return object|false
	 */
	public function find_by_category_code( $category_code ) {
		$category_code = trim( (string) $category_code );
		if ( '' === $category_code ) {
			return false;
		}

		$matches = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => self::CATEGORY_CODE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $category_code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 2,
			)
		);

		return ! is_wp_error( $matches ) && 1 === count( $matches ) ? reset( $matches ) : false;
	}

	/**
	 * Return the canonical URL for one historic managed category slug.
	 *
	 * This method is public so the resolution can be verified without emitting
	 * headers or terminating a request.
	 *
	 * @param string $requested_path Product-category query path or leaf slug.
	 * @return string
	 */
	public function resolve_legacy_category_redirect( $requested_path ) {
		$segments = array_values( array_filter( explode( '/', trim( (string) $requested_path, '/' ) ), 'strlen' ) );
		$slug     = sanitize_title( empty( $segments ) ? '' : end( $segments ) );
		if ( '' === $slug || ! str_starts_with( $slug, self::LEGACY_PREFIX ) ) {
			return '';
		}

		$managed_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => self::CATEGORY_MANAGED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( is_wp_error( $managed_terms ) ) {
			return '';
		}

		$matched = array();
		foreach ( $managed_terms as $term ) {
			$term_id       = (int) ( $term->term_id ?? 0 );
			$category_code = (string) get_term_meta( $term_id, self::CATEGORY_CODE_META, true );
			$neutral_slug  = self::neutral_slug( $category_code );
			if ( '' === $category_code || (string) ( $term->slug ?? '' ) !== $neutral_slug ) {
				continue;
			}

			$legacy_slugs = $this->legacy_slugs( $term_id, $category_code );
			if ( in_array( $slug, $legacy_slugs, true ) ) {
				$matched[] = $term;
			}
		}

		if ( 1 !== count( $matched ) ) {
			return '';
		}

		$url = get_term_link( reset( $matched ), 'product_cat' );

		return is_wp_error( $url ) ? '' : (string) $url;
	}

	/**
	 * Permanently redirect a historic product-category path to its exact owner.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_category() {
		$requested_path = (string) get_query_var( 'product_cat', '' );
		$url            = $this->resolve_legacy_category_redirect( $requested_path );
		if ( '' === $url ) {
			return;
		}

		if ( wp_safe_redirect( $url, self::LEGACY_REDIRECT_STATUS, 'Digitalogic' ) ) {
			exit;
		}
	}

	/**
	 * Read the closed legacy-slug allowlist for one managed term.
	 *
	 * @param int    $term_id Exact term ID.
	 * @param string $category_code Exact source category Code.
	 * @return array
	 */
	private function legacy_slugs( $term_id, $category_code ) {
		$slugs   = $this->normalize_legacy_slugs( get_term_meta( (int) $term_id, self::LEGACY_SLUGS_META, true ) );
		$default = self::legacy_slug( $category_code );
		if ( '' !== $default ) {
			$slugs[] = $default;
		}
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs, SORT_STRING );

		return $slugs;
	}

	/**
	 * Normalize a stored historic-slug allowlist.
	 *
	 * @param mixed $stored Stored term metadata.
	 * @return array
	 */
	private function normalize_legacy_slugs( $stored ) {
		$stored = is_array( $stored ) ? $stored : ( '' === $stored ? array() : array( $stored ) );
		$slugs  = array();
		foreach ( $stored as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $slug );
			if ( str_starts_with( $slug, self::LEGACY_PREFIX ) ) {
				$slugs[] = $slug;
			}
		}
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs, SORT_STRING );

		return $slugs;
	}
}
