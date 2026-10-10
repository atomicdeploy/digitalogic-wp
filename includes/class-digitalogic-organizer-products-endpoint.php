<?php
/**
 * Least-privilege WooCommerce catalog source for the organizer.
 *
 * The endpoint intentionally uses a dedicated capability and an exact
 * method/route contract. Granting the capability does not grant access to any
 * other Digitalogic REST surface.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Organizer_Products_Endpoint {

	public const CAPABILITY          = 'digitalogic_read_organizer_products';
	public const ROUTE               = '/digitalogic/integration/organizer-products';
	public const MEDIA_ROUTE_PATTERN = '/digitalogic/integration/organizer-product-media/patris-product-code/(?P<patris_product_code>[0-9]{3}(?:[0-9]{3}){0,3})';

	private const MAX_PAGE_SIZE          = 500;
	private const DEFAULT_PAGE_SIZE      = 250;
	private const MAX_SNAPSHOT_PRODUCTS  = 50000;
	private const MAX_MEDIA_ITEMS        = 100;
	private const MEDIA_DIGEST_CACHE_TTL = 600;
	private const PATRIS_CODE_PATTERN    = '/^(?:[0-9]{3}|[0-9]{6}|[0-9]{9}|[0-9]{12})$/D';
	private const MEDIA_ROUTE_MATCH      = '#^/digitalogic/integration/organizer-product-media/patris-product-code/(?:[0-9]{3}|[0-9]{6}|[0-9]{9}|[0-9]{12})$#D';

	/** @var self|null */
	private static $instance = null;

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the unversioned, read-only integration route.
	 *
	 * @return void
	 */
	public function register_route() {
		register_rest_route(
			'digitalogic',
			'/integration/organizer-products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_snapshot_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1;
						},
					),
					'per_page' => array(
						'default'           => self::DEFAULT_PAGE_SIZE,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= self::MAX_PAGE_SIZE;
						},
					),
				),
			)
		);

		register_rest_route(
			'digitalogic',
			'/integration/organizer-product-media/patris-product-code/(?P<patris_product_code>[0-9]{3}(?:[0-9]{3}){0,3})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_media' ),
				'permission_callback' => array( $this, 'check_media_permission' ),
				'args'                => array(
					'patris_product_code' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && 1 === preg_match( self::PATRIS_CODE_PATTERN, $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Require the catalog capability for the exact product-media route.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public function check_media_permission( WP_REST_Request $request ) {
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : '';
		if ( 'GET' !== $method || 1 !== preg_match( self::MEDIA_ROUTE_MATCH, $route ) ) {
			return new WP_Error(
				'digitalogic_organizer_media_scope_mismatch',
				'Organizer media access is restricted to its exact read-only route.',
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'digitalogic_organizer_media_forbidden',
				'Organizer media authentication failed.',
				array( 'status' => get_current_user_id() > 0 ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Require the dedicated capability for the exact route and method.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : '';
		if ( 'GET' !== $method || self::ROUTE !== $route ) {
			return new WP_Error(
				'digitalogic_organizer_catalog_scope_mismatch',
				'Organizer catalog access is restricted to its exact read-only route.',
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'digitalogic_organizer_catalog_forbidden',
				'Organizer catalog authentication failed.',
				array( 'status' => get_current_user_id() > 0 ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Return one page of a complete, content-addressed snapshot.
	 *
	 * A full projection is materialized for every request before it is sliced.
	 * Clients must require the same revision for every page and restart when it
	 * changes. This prevents a quietly mixed catalog across page requests.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_snapshot_page( WP_REST_Request $request ) {
		$snapshot = $this->build_snapshot();
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( self::MAX_PAGE_SIZE, $per_page ) : self::DEFAULT_PAGE_SIZE;
		$count    = count( $snapshot['products'] );
		$pages    = max( 1, (int) ceil( $count / $per_page ) );
		if ( $page > $pages ) {
			return new WP_Error(
				'digitalogic_organizer_catalog_page_out_of_range',
				'The requested organizer catalog page does not exist.',
				array(
					'status' => 400,
					'pages'  => $pages,
				)
			);
		}

		$products = array_slice( $snapshot['products'], ( $page - 1 ) * $per_page, $per_page );
		$returned = count( $products );
		$complete = 1 === $page && $returned === $count;

		return new WP_REST_Response(
			array(
				'schema'            => 'digitalogic.organizer-products.snapshot',
				'observed_at'       => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
				'revision'          => 'sha256:' . $snapshot['hash'],
				'hash'              => $snapshot['hash'],
				'snapshot_complete' => true,
				'identity_complete' => $snapshot['diagnostics']['invalid_code_count'] === 0
					&& $snapshot['diagnostics']['duplicate_code_count'] === 0
					&& $snapshot['diagnostics']['unreadable_product_count'] === 0,
				'complete'          => $complete,
				'count'             => $count,
				'scanned_count'     => $snapshot['scanned_count'],
				'page'              => $page,
				'per_page'          => $per_page,
				'pages'             => $pages,
				'returned_count'    => $returned,
				'next_page'         => $page < $pages ? $page + 1 : null,
				'products'          => $products,
				'diagnostics'       => $snapshot['diagnostics'],
			),
			200
		);
	}

	/**
	 * Return committed WordPress media for one exact Patris product code.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_product_media( WP_REST_Request $request ) {
		$projection = $this->project_media_by_patris_product_code( (string) $request->get_param( 'patris_product_code' ) );
		if ( is_wp_error( $projection ) ) {
			return $projection;
		}

		return new WP_REST_Response( $projection, 200 );
	}

	/**
	 * Project committed media without using Woo SKU as identity or fallback.
	 *
	 * Attachment byte digests are cached by attachment, path, byte count and
	 * modification time. This route hashes only attachments for the requested
	 * product and never adds media work to the full catalog snapshot.
	 *
	 * @param string $patris_product_code Exact Patris product code.
	 * @return array|WP_Error
	 */
	public function project_media_by_patris_product_code( $patris_product_code ) {
		if ( ! is_string( $patris_product_code ) || 1 !== preg_match( self::PATRIS_CODE_PATTERN, $patris_product_code ) ) {
			return $this->media_error( 'digitalogic_media_invalid_patris_code', 400 );
		}
		if ( ! class_exists( 'Digitalogic_Product_Identifier_Resolver' ) ) {
			return $this->media_error( 'digitalogic_media_identity_resolver_unavailable', 503 );
		}

		$ids = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => 2,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact indexed Product Code identity lookup.
					array(
						'key'     => Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META,
						'value'   => $patris_product_code,
						'compare' => '=',
					),
				),
			)
		);
		if ( ! is_array( $ids ) ) {
			return $this->media_error( 'digitalogic_media_identity_query_failed', 503 );
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( 0 === count( $ids ) ) {
			return $this->media_error( 'digitalogic_media_product_not_found', 404 );
		}
		if ( 1 !== count( $ids ) || $ids[0] < 1 ) {
			return $this->media_error( 'digitalogic_media_product_identity_conflict', 409 );
		}

		$id          = $ids[0];
		$stored_code = get_post_meta( $id, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true );
		if ( ! is_string( $stored_code ) || ! hash_equals( $patris_product_code, $stored_code ) ) {
			return $this->media_error( 'digitalogic_media_product_identity_mismatch', 409 );
		}

		$product = wc_get_product( $id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_image_id' ) || ! method_exists( $product, 'get_gallery_image_ids' ) ) {
			return $this->media_error( 'digitalogic_media_product_unreadable', 503 );
		}

		$attachment_ids = array();
		$primary_id     = (int) $product->get_image_id();
		if ( $primary_id > 0 ) {
			$attachment_ids[] = array(
				'kind' => 'primary',
				'id'   => $primary_id,
			);
		}
		foreach ( (array) $product->get_gallery_image_ids() as $gallery_id ) {
			$gallery_id = (int) $gallery_id;
			if ( $gallery_id > 0 && ! $this->has_media_attachment( $attachment_ids, $gallery_id ) ) {
				$attachment_ids[] = array(
					'kind' => 'gallery',
					'id'   => $gallery_id,
				);
			}
		}
		if ( count( $attachment_ids ) > self::MAX_MEDIA_ITEMS ) {
			return $this->media_error( 'digitalogic_media_attachment_limit_exceeded', 503 );
		}

		$images          = array();
		$revision_images = array();
		foreach ( $attachment_ids as $attachment ) {
			$projected = $this->project_media_attachment( $attachment['id'], $attachment['kind'] );
			if ( is_wp_error( $projected ) ) {
				return $projected;
			}
			$images[]          = $projected['public'];
			$revision_images[] = $projected['revision'];
		}

		$canonical_url = get_permalink( $id );
		if ( ! is_string( $canonical_url ) || ! $this->safe_product_url( $canonical_url ) ) {
			return $this->media_error( 'digitalogic_media_canonical_url_unavailable', 503 );
		}

		$revision_seed = array(
			'schema'              => 'digitalogic.wordpress-media-revision',
			'patris_product_code' => $patris_product_code,
			'woocommerce_id'      => $id,
			'canonical_url'       => $canonical_url,
			'images'              => $revision_images,
		);
		try {
			$encoded = wp_json_encode( $revision_seed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		} catch ( Throwable $error ) {
			return $this->media_error( 'digitalogic_media_revision_unavailable', 503 );
		}
		if ( ! is_string( $encoded ) ) {
			return $this->media_error( 'digitalogic_media_revision_unavailable', 503 );
		}

		return array(
			'source'              => 'wordpress',
			'patris_product_code' => $patris_product_code,
			'woocommerce_id'      => $id,
			'canonical_url'       => $canonical_url,
			'images'              => $images,
			'media_revision'      => hash( 'sha256', $encoded ),
			'loaded'              => true,
		);
	}

	/** @return array|WP_Error */
	private function project_media_attachment( $attachment_id, $kind ) {
		$url  = wp_get_attachment_url( $attachment_id );
		$path = get_attached_file( $attachment_id, true );
		if ( ! is_string( $url ) || ! $this->safe_upload_url( $url ) || ! is_string( $path ) || ! is_readable( $path ) ) {
			return $this->media_error( 'digitalogic_media_attachment_unavailable', 503 );
		}

		$bytes    = filesize( $path );
		$modified = filemtime( $path );
		if ( ! is_int( $bytes ) || $bytes < 0 || ! is_int( $modified ) || $modified < 0 ) {
			return $this->media_error( 'digitalogic_media_attachment_revision_unavailable', 503 );
		}

		$digest = $this->media_attachment_digest( (int) $attachment_id, $path, $bytes, $modified );
		if ( ! is_string( $digest ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
			return $this->media_error( 'digitalogic_media_attachment_revision_unavailable', 503 );
		}

		return array(
			'public'   => array(
				'source'        => 'wordpress',
				'kind'          => $kind,
				'attachment_id' => (int) $attachment_id,
				'url'           => $url,
			),
			'revision' => array(
				'kind'          => $kind,
				'attachment_id' => (int) $attachment_id,
				'url'           => $url,
				'bytes'         => $bytes,
				'modified'      => $modified,
				'sha256'        => $digest,
			),
		);
	}

	private function media_attachment_digest( $attachment_id, $path, $bytes, $modified ) {
		$cache_key = 'sha256:' . hash( 'sha256', $attachment_id . ':' . $path . ':' . $bytes . ':' . $modified );
		$found     = false;
		$cached    = wp_cache_get( $cache_key, 'digitalogic_organizer_media', false, $found );
		if ( $found && is_string( $cached ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $cached ) ) {
			return $cached;
		}

		$digest = hash_file( 'sha256', $path );
		if ( is_string( $digest ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
			wp_cache_set( $cache_key, $digest, 'digitalogic_organizer_media', self::MEDIA_DIGEST_CACHE_TTL );
			return $digest;
		}

		return null;
	}

	private function has_media_attachment( $items, $attachment_id ) {
		foreach ( $items as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === (int) $attachment_id ) {
				return true;
			}
		}
		return false;
	}

	private function safe_upload_url( $value ) {
		$parts = wp_parse_url( $value );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), array( 'digitalogic.ir', 'www.digitalogic.ir' ), true )
			&& 0 === strpos( (string) ( $parts['path'] ?? '' ), '/wp-content/uploads/' )
			&& empty( $parts['user'] )
			&& empty( $parts['pass'] )
			&& empty( $parts['port'] );
	}

	private function safe_product_url( $value ) {
		$parts = wp_parse_url( $value );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), array( 'digitalogic.ir', 'www.digitalogic.ir' ), true )
			&& 0 === strpos( (string) ( $parts['path'] ?? '' ), '/product/' )
			&& empty( $parts['user'] )
			&& empty( $parts['pass'] )
			&& empty( $parts['port'] );
	}

	private function media_error( $code, $status ) {
		return new WP_Error( $code, 'Committed WordPress media is unavailable.', array( 'status' => $status ) );
	}

	/**
	 * Materialize the current WooCommerce product and variation projection.
	 *
	 * @return array|WP_Error
	 */
	private function build_snapshot() {
		$ids = get_posts(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => self::MAX_SNAPSHOT_PRODUCTS + 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		if ( ! is_array( $ids ) ) {
			return $this->snapshot_error( 'digitalogic_organizer_catalog_query_failed', 'The organizer catalog could not be read.' );
		}
		if ( count( $ids ) > self::MAX_SNAPSHOT_PRODUCTS ) {
			return new WP_Error(
				'digitalogic_organizer_catalog_snapshot_too_large',
				'The organizer catalog exceeds the reviewed snapshot bound.',
				array(
					'status'            => 503,
					'snapshot_complete' => false,
					'max_products'      => self::MAX_SNAPSHOT_PRODUCTS,
				)
			);
		}

		$products     = array();
		$code_index   = array();
		$no_code      = array();
		$invalid_code = array();
		$unreadable   = array();
		$allowed_keys = array( 'id', 'name', 'patris_product_code', 'canonical_url', 'sku', 'status', 'type' );

		foreach ( $ids as $raw_id ) {
			$id      = (int) $raw_id;
			$product = wc_get_product( $id );
			if ( ! is_object( $product ) ) {
				$unreadable[] = $id;
				continue;
			}

			$raw_code = get_post_meta( $id, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true );
			if ( '' === $raw_code || null === $raw_code ) {
				$no_code[] = $id;
				continue;
			}
			$reason = $this->invalid_code_reason( $raw_code );
			if ( null !== $reason ) {
				$invalid_code[] = array(
					'id'     => $id,
					'reason' => $reason,
				);
				continue;
			}

			$code      = (string) $raw_code;
			$permalink = get_permalink( $id );
			$row       = array(
				'id'                  => $id,
				'name'                => (string) $product->get_name(),
				'patris_product_code' => $code,
				'canonical_url'       => is_string( $permalink ) ? $permalink : '',
				'sku'                 => (string) $product->get_sku(),
				'status'              => (string) $product->get_status(),
				'type'                => (string) $product->get_type(),
			);
			if ( $allowed_keys !== array_keys( $row ) ) {
				return $this->snapshot_error( 'digitalogic_organizer_catalog_projection_failed', 'The organizer catalog projection is invalid.' );
			}

			$products[] = $row;
			$index_key  = 'code:' . $code;
			if ( ! isset( $code_index[ $index_key ] ) ) {
				$code_index[ $index_key ] = array(
					'patris_product_code' => $code,
					'ids'                 => array(),
				);
			}
			$code_index[ $index_key ]['ids'][] = $id;
		}

		$duplicates = array_values(
			array_filter(
				$code_index,
				static function ( $entry ) {
					return count( $entry['ids'] ) > 1;
				}
			)
		);
		usort(
			$duplicates,
			static function ( $left, $right ) {
				return strcmp( $left['patris_product_code'], $right['patris_product_code'] );
			}
		);

		$diagnostics   = array(
			'no_code_count'            => count( $no_code ),
			'invalid_code_count'       => count( $invalid_code ),
			'duplicate_code_count'     => count( $duplicates ),
			'unreadable_product_count' => count( $unreadable ),
			'no_code_ids'              => $no_code,
			'invalid_codes'            => $invalid_code,
			'duplicate_codes'          => $duplicates,
			'unreadable_product_ids'   => $unreadable,
		);
		$hash_material = wp_json_encode(
			array(
				'products'    => $products,
				'diagnostics' => $diagnostics,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $hash_material ) ) {
			return $this->snapshot_error( 'digitalogic_organizer_catalog_hash_failed', 'The organizer catalog revision could not be computed.' );
		}

		return array(
			'products'      => $products,
			'diagnostics'   => $diagnostics,
			'scanned_count' => count( $ids ),
			'hash'          => hash( 'sha256', $hash_material ),
		);
	}

	/**
	 * Validate an exact Product Code without normalizing or using the SKU.
	 *
	 * @param mixed $value Stored metadata value.
	 * @return string|null Diagnostic reason, or null when valid.
	 */
	private function invalid_code_reason( $value ) {
		if ( ! is_string( $value ) ) {
			return 'not_text';
		}
		if ( '' === $value ) {
			return 'empty';
		}
		$valid_text = preg_match( '//u', $value );
		if ( 1 !== $valid_text ) {
			return 'invalid_utf8';
		}
		if ( trim( $value ) !== $value ) {
			return 'surrounding_whitespace';
		}
		if ( 1 !== preg_match( '/\A[0-9]+\z/D', $value ) ) {
			return 'invalid_characters';
		}
		if ( ! in_array( strlen( $value ), array( 3, 6, 9, 12 ), true ) ) {
			return 'invalid_length';
		}

		return null;
	}

	/**
	 * Return a sanitized source failure with explicit incompleteness.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Safe error message.
	 * @return WP_Error
	 */
	private function snapshot_error( $code, $message ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'            => 503,
				'snapshot_complete' => false,
			)
		);
	}
}
