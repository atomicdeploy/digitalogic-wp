<?php
/**
 * Private WooCommerce product supplier-link storage.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store reviewed seller links without exposing them to storefront transports.
 */
final class Digitalogic_Product_Supplier_Links {

	/**
	 * Protected product meta key.
	 */
	public const META_KEY = '_digitalogic_private_supplier_links_v1';

	/**
	 * Maximum links stored against one product.
	 */
	private const MAX_LINKS = 50;

	/**
	 * Maximum accepted stdin or request payload size.
	 */
	public const MAX_INPUT_BYTES = 1048576;

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register storage and privacy boundaries.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'scrub_rest_response' ), 999, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'scrub_rest_response' ), 999, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'prevent_rest_write' ), 999, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_variation_object', array( $this, 'prevent_rest_write' ), 999, 3 );
		add_filter( 'woocommerce_webhook_payload', array( $this, 'scrub_webhook_payload' ), 999, 4 );
	}

	/**
	 * Register protected metadata without a core REST schema.
	 *
	 * @return void
	 */
	public function register_meta() {
		register_post_meta(
			'product',
			self::META_KEY,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_registered_meta' ),
				'auth_callback'     => array( $this, 'authorize_meta' ),
			)
		);
	}

	/**
	 * Permit direct metadata access only to administrators who can edit the product.
	 *
	 * @param bool   $allowed Existing authorization decision.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id Product ID.
	 * @return bool
	 */
	public function authorize_meta( $allowed, $meta_key, $post_id ) {
		unset( $allowed, $meta_key );

		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Best-effort sanitation for callers of the generic metadata API.
	 *
	 * Feature writes use replace_links(), which returns validation errors instead
	 * of silently dropping a malformed row.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return array
	 */
	public function sanitize_registered_meta( $value ) {
		$normalized = $this->normalize_links( $value, array() );

		return is_wp_error( $normalized ) ? array() : $normalized;
	}

	/**
	 * Read private links for one parent product.
	 *
	 * Callers must enforce the administrator boundary before presenting this
	 * value. The service deliberately exposes no frontend hook or public route.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array|WP_Error
	 */
	public function get_links( $product_id ) {
		$product = $this->validate_parent_product( $product_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$stored = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$stored,
				static function ( $row ) {
					return is_array( $row )
						&& isset( $row['id'], $row['url'], $row['marketplace'] )
						&& is_string( $row['id'] )
						&& is_string( $row['url'] )
						&& is_string( $row['marketplace'] );
				}
			)
		);
	}

	/**
	 * Replace all private links on one parent product.
	 *
	 * @param int   $product_id Parent product ID.
	 * @param mixed $raw_links Raw link rows.
	 * @return array|WP_Error Normalized persisted rows.
	 */
	public function replace_links( $product_id, $raw_links ) {
		$product = $this->validate_parent_product( $product_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$existing = $this->get_links( $product_id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$normalized = $this->normalize_links( $raw_links, $existing );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( empty( $normalized ) ) {
			delete_post_meta( $product_id, self::META_KEY );
			$readback = get_post_meta( $product_id, self::META_KEY, true );
			if ( '' !== $readback && array() !== $readback ) {
				return $this->storage_error();
			}

			return array();
		}

		update_post_meta( $product_id, self::META_KEY, $normalized );
		$readback = get_post_meta( $product_id, self::META_KEY, true );
		if ( $readback !== $normalized ) {
			return $this->storage_error();
		}

		return $normalized;
	}

	/**
	 * Add one link without allowing duplicates.
	 *
	 * @param int   $product_id Parent product ID.
	 * @param mixed $raw_link Raw link object.
	 * @return array|WP_Error Full normalized link collection.
	 */
	public function add_link( $product_id, $raw_link ) {
		$links = $this->get_links( $product_id );
		if ( is_wp_error( $links ) ) {
			return $links;
		}

		$links[] = $raw_link;

		return $this->replace_links( $product_id, $links );
	}

	/**
	 * Remove one link by its stable private identifier.
	 *
	 * @param int    $product_id Parent product ID.
	 * @param string $link_id Link identifier.
	 * @return array|WP_Error Remaining normalized links.
	 */
	public function remove_link( $product_id, $link_id ) {
		$link_id = sanitize_key( $this->scalar_string( $link_id ) );
		if ( ! preg_match( '/^sl_[a-f0-9]{20}$/', $link_id ) ) {
			return new WP_Error(
				'digitalogic_supplier_link_id_invalid',
				'شناسه لینک تأمین‌کننده معتبر نیست.',
				array( 'status' => 400 )
			);
		}

		$links = $this->get_links( $product_id );
		if ( is_wp_error( $links ) ) {
			return $links;
		}

		$remaining = array_values(
			array_filter(
				$links,
				static function ( $link ) use ( $link_id ) {
					return ! isset( $link['id'] ) || $link_id !== $link['id'];
				}
			)
		);

		if ( count( $remaining ) === count( $links ) ) {
			return new WP_Error(
				'digitalogic_supplier_link_not_found',
				'لینک تأمین‌کننده پیدا نشد.',
				array( 'status' => 404 )
			);
		}

		return $this->replace_links( $product_id, $remaining );
	}

	/**
	 * Return a non-sensitive list-table summary.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array|WP_Error
	 */
	public function get_summary( $product_id ) {
		$links = $this->get_links( $product_id );
		if ( is_wp_error( $links ) ) {
			return $links;
		}

		$providers = array();
		foreach ( $links as $link ) {
			$provider = isset( $link['marketplace'] ) ? sanitize_key( $link['marketplace'] ) : 'other';
			if ( 'iranian_market' === $provider && ! empty( $link['site_name'] ) ) {
				$provider .= ':' . sanitize_text_field( $link['site_name'] );
			}
			$providers[] = $provider;
		}

		return array(
			'count'     => count( $links ),
			'providers' => array_values( array_unique( $providers ) ),
		);
	}

	/**
	 * Remove private metadata from WooCommerce REST responses.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $wc_object WooCommerce product object.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function scrub_rest_response( $response, $wc_object = null, $request = null ) {
		unset( $wc_object, $request );
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}

		$response->set_data( $this->scrub_payload( $response->get_data() ) );

		return $response;
	}

	/**
	 * Refuse WooCommerce REST writes to the private metadata key.
	 *
	 * @param mixed $product WooCommerce product object.
	 * @param mixed $request REST request.
	 * @param bool  $creating Whether a new object is being created.
	 * @return mixed
	 */
	public function prevent_rest_write( $product, $request, $creating = false ) {
		unset( $creating );
		if ( ! is_object( $product ) || ! method_exists( $product, 'delete_meta_data' ) || ! method_exists( $product, 'get_id' ) ) {
			return $product;
		}

		$meta_data = is_object( $request ) && method_exists( $request, 'get_param' )
			? $request->get_param( 'meta_data' )
			: null;
		if ( ! is_array( $meta_data ) || ! $this->contains_private_meta( $meta_data ) ) {
			return $product;
		}

		$product_id = (int) $product->get_id();
		$existing   = $product_id > 0 ? get_post_meta( $product_id, self::META_KEY, true ) : '';
		foreach ( $meta_data as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && self::is_private_meta_key( $row['key'] ) ) {
				$product->delete_meta_data( (string) $row['key'] );
			}
		}
		$product->delete_meta_data( self::META_KEY );
		if ( is_array( $existing ) && ! empty( $existing ) && method_exists( $product, 'update_meta_data' ) ) {
			$product->update_meta_data( self::META_KEY, $existing );
		}

		return $product;
	}

	/**
	 * Remove private metadata from every WooCommerce webhook payload.
	 *
	 * @param mixed  $payload Webhook payload.
	 * @param string $resource_name Resource name.
	 * @param int    $resource_id Resource ID.
	 * @param int    $webhook_id Webhook ID.
	 * @return mixed
	 */
	public function scrub_webhook_payload( $payload, $resource_name = '', $resource_id = 0, $webhook_id = 0 ) {
		unset( $resource_name, $resource_id, $webhook_id );

		return $this->scrub_payload( $payload );
	}

	/**
	 * Normalize a bounded collection while preserving created timestamps.
	 *
	 * @param mixed $raw_links Raw rows.
	 * @param array $existing Existing normalized rows.
	 * @return array|WP_Error
	 */
	private function normalize_links( $raw_links, $existing ) {
		if ( ! is_array( $raw_links ) ) {
			return new WP_Error(
				'digitalogic_supplier_links_invalid',
				'فهرست لینک‌های تأمین‌کننده معتبر نیست.',
				array( 'status' => 400 )
			);
		}
		if ( count( $raw_links ) > self::MAX_LINKS ) {
			return new WP_Error(
				'digitalogic_supplier_links_limit',
				'تعداد لینک‌های تأمین‌کننده از حد مجاز بیشتر است.',
				array(
					'status'  => 400,
					'maximum' => self::MAX_LINKS,
				)
			);
		}

		$existing_by_id = array();
		foreach ( $existing as $row ) {
			if ( isset( $row['id'] ) && is_string( $row['id'] ) ) {
				$existing_by_id[ $row['id'] ] = $row;
			}
		}

		$normalized        = array();
		$seen_urls         = array();
		$seen_ids          = array();
		$seen_supplied_ids = array();
		foreach ( array_values( $raw_links ) as $index => $raw_link ) {
			$supplied_id = is_array( $raw_link ) && isset( $raw_link['id'] )
				? sanitize_key( $this->scalar_string( $raw_link['id'] ) )
				: '';
			if ( isset( $existing_by_id[ $supplied_id ] ) ) {
				if ( isset( $seen_supplied_ids[ $supplied_id ] ) ) {
					return $this->duplicate_id_error( $index );
				}
				$seen_supplied_ids[ $supplied_id ] = true;
			}

			$row = $this->normalize_link( $raw_link, $existing_by_id );
			if ( is_wp_error( $row ) ) {
				$data          = (array) $row->get_error_data();
				$data['index'] = $index;

				return new WP_Error( $row->get_error_code(), $row->get_error_message(), $data );
			}
			if ( isset( $seen_urls[ $row['url'] ] ) ) {
				continue;
			}
			if ( isset( $seen_ids[ $row['id'] ] ) ) {
				return $this->duplicate_id_error( $index );
			}

			$seen_urls[ $row['url'] ] = true;
			$seen_ids[ $row['id'] ]   = true;
			$normalized[]             = $row;
		}

		return $normalized;
	}

	/**
	 * Normalize one private supplier row.
	 *
	 * @param mixed $raw_link Raw row.
	 * @param array $existing_by_id Existing rows keyed by ID.
	 * @return array|WP_Error
	 */
	private function normalize_link( $raw_link, $existing_by_id ) {
		if ( ! is_array( $raw_link ) ) {
			return new WP_Error(
				'digitalogic_supplier_link_invalid',
				'یکی از لینک‌های تأمین‌کننده معتبر نیست.',
				array( 'status' => 400 )
			);
		}

		$url = $this->normalize_url( $raw_link['url'] ?? '' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$marketplace = sanitize_key( $this->scalar_string( $raw_link['marketplace'] ?? '' ) );
		if ( '' === $marketplace ) {
			$marketplace = $this->infer_marketplace( $url );
		}
		if ( ! in_array( $marketplace, $this->marketplaces(), true ) ) {
			return new WP_Error(
				'digitalogic_supplier_marketplace_invalid',
				'نوع بازار یا پلتفرم معتبر نیست.',
				array( 'status' => 400 )
			);
		}

		$supplied_id = isset( $raw_link['id'] ) ? sanitize_key( $this->scalar_string( $raw_link['id'] ) ) : '';
		$link_id     = preg_match( '/^sl_[a-f0-9]{20}$/', $supplied_id ) && isset( $existing_by_id[ $supplied_id ] )
			? $supplied_id
			: '';
		if ( '' === $link_id ) {
			$link_id = 'sl_' . substr( hash( 'sha256', $marketplace . "\0" . $url ), 0, 20 );
		}

		$existing   = $existing_by_id[ $link_id ] ?? array();
		$now        = current_time( 'mysql', true );
		$created_at = isset( $existing['created_at'] )
			? (string) $existing['created_at']
			: $this->normalize_datetime( $raw_link['created_at'] ?? '', $now );

		$source = sanitize_key( $this->scalar_string( $raw_link['source'] ?? 'manual' ) );
		if ( ! in_array( $source, $this->sources(), true ) ) {
			$source = 'manual';
		}

		$status = sanitize_key( $this->scalar_string( $raw_link['status'] ?? 'candidate' ) );
		if ( ! in_array( $status, $this->statuses(), true ) ) {
			$status = 'candidate';
		}

		$site_name = $this->sanitize_text( $raw_link['site_name'] ?? '', 120 );
		if ( '' === $site_name ) {
			$site_name = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		return array(
			'id'           => $link_id,
			'marketplace'  => $marketplace,
			'site_name'    => $site_name,
			'url'          => $url,
			'source_title' => $this->sanitize_text( $raw_link['source_title'] ?? '', 240 ),
			'seller'       => $this->sanitize_text( $raw_link['seller'] ?? '', 160 ),
			'seller_sku'   => $this->sanitize_text( $raw_link['seller_sku'] ?? '', 120 ),
			'source'       => $source,
			'status'       => $status,
			'note'         => $this->sanitize_note( $raw_link['note'] ?? '' ),
			'created_at'   => $created_at,
			'updated_at'   => $now,
			'last_checked' => $this->normalize_date( $raw_link['last_checked'] ?? '' ),
		);
	}

	/**
	 * Normalize and bound an HTTP(S) URL without retaining credentials/fragments.
	 *
	 * @param mixed $raw_url Raw URL.
	 * @return string|WP_Error
	 */
	private function normalize_url( $raw_url ) {
		if ( ! is_scalar( $raw_url ) ) {
			return $this->url_error();
		}

		$raw_url = trim( (string) $raw_url );
		if ( '' === $raw_url || strlen( $raw_url ) > 4096 ) {
			return $this->url_error();
		}

		$url   = esc_url_raw( $raw_url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| empty( $parts['scheme'] )
			|| empty( $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return $this->url_error();
		}

		$normalized = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}
		$normalized .= isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$normalized .= '?' . $parts['query'];
		}

		return $normalized;
	}

	/**
	 * Infer a known marketplace from the listing host.
	 *
	 * @param string $url Normalized URL.
	 * @return string
	 */
	private function infer_marketplace( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$map  = array(
			'1688.com'       => '1688',
			'taobao.com'     => 'taobao',
			'tmall.com'      => 'tmall',
			'alibaba.com'    => 'alibaba',
			'aliexpress.com' => 'aliexpress',
		);
		foreach ( $map as $domain => $marketplace ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $marketplace;
			}
		}

		return str_ends_with( $host, '.ir' ) ? 'iranian_market' : 'other';
	}

	/**
	 * Whether a REST request explicitly addresses the private metadata key.
	 *
	 * @param array $meta_data REST metadata rows.
	 * @return bool
	 */
	private function contains_private_meta( $meta_data ) {
		foreach ( $meta_data as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) && self::is_private_meta_key( $row['key'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively scrub the protected key from response and webhook arrays.
	 *
	 * @param mixed $payload Arbitrary payload.
	 * @return mixed
	 */
	private function scrub_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}

		foreach ( $payload as $key => $value ) {
			if ( 'meta_data' === $key && is_array( $value ) ) {
				$value = array_values(
					array_filter(
						$value,
						static function ( $row ) {
							return ! is_array( $row )
								|| ! isset( $row['key'] )
								|| ! self::is_private_meta_key( $row['key'] );
						}
					)
				);
			}
			$payload[ $key ] = $this->scrub_payload( $value );
		}

		return $payload;
	}

	/**
	 * Validate that storage targets a parent WooCommerce product.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	private function validate_parent_product( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error(
				'digitalogic_supplier_links_parent_product_required',
				'لینک تأمین‌کننده باید به محصول اصلی ووکامرس متصل شود، نه تنوع محصول.',
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Known marketplace identifiers.
	 *
	 * @return array
	 */
	private function marketplaces() {
		return array( 'taobao', '1688', 'tmall', 'alibaba', 'aliexpress', 'iranian_market', 'other' );
	}

	/**
	 * Known provenance identifiers.
	 *
	 * @return array
	 */
	private function sources() {
		return array( 'purchase_history', 'iranian_market', 'manual', 'other' );
	}

	/**
	 * Known review-state identifiers.
	 *
	 * @return array
	 */
	private function statuses() {
		return array( 'candidate', 'matched', 'purchased', 'preferred', 'inactive' );
	}

	/**
	 * Sanitize one bounded single-line value.
	 *
	 * @param mixed $value Raw text.
	 * @param int   $maximum Maximum characters.
	 * @return string
	 */
	private function sanitize_text( $value, $maximum ) {
		return mb_substr( sanitize_text_field( $this->scalar_string( $value ) ), 0, $maximum );
	}

	/**
	 * Sanitize a bounded private note.
	 *
	 * @param mixed $value Raw note.
	 * @return string
	 */
	private function sanitize_note( $value ) {
		return mb_substr( sanitize_textarea_field( $this->scalar_string( $value ) ), 0, 1000 );
	}

	/**
	 * Preserve a valid UTC-ish storage timestamp or use the supplied fallback.
	 *
	 * @param mixed  $value Raw timestamp.
	 * @param string $fallback Current timestamp.
	 * @return string
	 */
	private function normalize_datetime( $value, $fallback ) {
		$value = trim( $this->scalar_string( $value ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? $value : $fallback;
	}

	/**
	 * Preserve a valid source-check date.
	 *
	 * @param mixed $value Raw date.
	 * @return string
	 */
	private function normalize_date( $value ) {
		$value = trim( $this->scalar_string( $value ) );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Build a generic URL validation error without reflecting private input.
	 *
	 * @return WP_Error
	 */
	private function url_error() {
		return new WP_Error(
			'digitalogic_supplier_link_url_invalid',
			'نشانی اینترنتی تأمین‌کننده معتبر نیست.',
			array( 'status' => 400 )
		);
	}

	/**
	 * Build a stable storage error without including private payload content.
	 *
	 * @return WP_Error
	 */
	private function storage_error() {
		return new WP_Error(
			'digitalogic_supplier_links_storage_failed',
			'ذخیره لینک‌های تأمین‌کننده کامل نشد. دوباره تلاش کنید.',
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
	}

	/**
	 * Build a duplicate stable-ID error without exposing private row content.
	 *
	 * @param int $index Duplicate row index.
	 * @return WP_Error
	 */
	private function duplicate_id_error( $index ) {
		return new WP_Error(
			'digitalogic_supplier_link_id_duplicate',
			'شناسه لینک تأمین‌کننده تکراری است.',
			array(
				'status' => 400,
				'index'  => (int) $index,
			)
		);
	}

	/**
	 * Convert only scalar input to text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function scalar_string( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Match the protected key using the same ASCII-insensitive semantics as
	 * typical WordPress postmeta database collations.
	 *
	 * @param mixed $key Candidate metadata key.
	 * @return bool
	 */
	private static function is_private_meta_key( $key ) {
		return is_scalar( $key ) && 0 === strcasecmp( self::META_KEY, (string) $key );
	}
}
