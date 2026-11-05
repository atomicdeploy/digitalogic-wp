<?php

namespace Digitalogic\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public catalog projection. No private pricing metadata, tokens or customer data. */
final class MobileCatalogController {
	public function bootstrap( Request $request ): Response {
		return $this->cached(
			$request,
			'bootstrap',
			array(),
			function (): array {
				$update_manifest = (string) get_option( 'digitalogic_mobile_update_manifest', '' );
				$featured        = $this->productsData(
					self::normalizeQuery(
						array(
							'per_page'     => 10,
							'stock_status' => 'instock',
							'has_images'   => '1',
						)
					)
				);
				return array(
					'site'         => array(
						'name'                => get_bloginfo( 'name' ),
						'url'                 => home_url( '/' ),
						'locale'              => 'fa-IR',
						'direction'           => 'rtl',
						'currency'            => get_woocommerce_currency(),
						'currency_minor_unit' => wc_get_price_decimals(),
					),
					'endpoints'    => array(
						'catalog'          => home_url( '/app-api/products' ),
						'categories'       => home_url( '/app-api/categories' ),
						'cart'             => rest_url( 'wc/store/v1/cart' ),
						'account'          => wc_get_page_permalink( 'myaccount' ),
						'login'            => wc_get_page_permalink( 'myaccount' ),
						'checkout'         => wc_get_checkout_url(),
						'orders'           => wc_get_account_endpoint_url( 'orders' ),
						'profile'          => wc_get_account_endpoint_url( 'edit-account' ),
						'checkout_handoff' => home_url( '/app-api/checkout' ),
						'update_manifest'  => $update_manifest,
						'websocket'        => 'wss://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/wordpress-ws',
						'forms'            => home_url( '/app-api/forms' ),
						'uploads'          => home_url( '/app-api/uploads' ),
						'requests'         => home_url( '/app-api/requests' ),
						'native_requests'  => home_url( '/app-api/account/requests' ),
						'otp_request'      => home_url( '/app-api/auth/otp/request' ),
						'otp_verify'       => home_url( '/app-api/auth/otp/verify' ),
						'google'           => home_url( '/app-api/auth/google' ),
						'native_profile'   => home_url( '/app-api/account/profile' ),
						'native_orders'    => home_url( '/app-api/account/orders' ),
						'native_favorites' => home_url( '/app-api/account/favorites' ),
						'native_sessions'  => home_url( '/app-api/auth/sessions' ),
						'native_logout'    => home_url( '/app-api/auth/logout' ),
						'events'           => rest_url( 'digitalogic/v1/events/stream' ),
						'freshness'        => rest_url( 'digitalogic/v1/events/search-freshness' ),
						'pcb'              => home_url( '/سفارش-چاپ-برد-pcb/' ),
						'import'           => home_url( '/import-of-electronic-products/' ),
					),
					'capabilities' => array(
						'catalog'             => true,
						'cart'                => true,
						'browser_checkout'    => true,
						'native_cart_handoff' => true,
						'native_checkout'     => false,
						'native_auth'         => MobileIdentityController::otpEnabled(),
						'google_auth'         => MobileIdentityController::googleEnabled(),
						'otp_autofill'        => false,
						'cloud_favorites'     => MobileIdentityController::enabled(),
						'native_forms' => MobileRequestController::enabled(),
						'push_notifications'  => false,
						'websocket'           => (bool) get_option( 'digitalogic_mobile_websocket', false ),
						'sse'                 => true,
						'self_update'         => $update_manifest !== '',
					),
					'realtime'     => array(
						'protocol'           => 'digitalogic.shopper',
						'ping_interval'      => 25,
						'reconnect_interval' => 3,
					),
					'auth'         => array(
						'google_server_client_id' => \Digitalogic\Laravel\Services\MobileGoogleIdentity::audience(),
						'signup_url'              => wp_registration_url(),
					),
					'policy'       => array(
						'product_export'            => false,
						'telemetry_opt_in_required' => true,
						'checkout_online_required'  => true,
						'max_catalog_age_seconds'   => 60,
					),
					'categories'   => $this->categoriesData(),
					'carousels'    => \Digitalogic_Mobile_API::sanitize_merchandising( get_option( 'digitalogic_mobile_merchandising', array() ) ),
					'collections'  => array(
						array(
							'id'    => 'ready',
							'title' => 'آماده برای پروژه بعدی',
							'query' => array(
								'stock_status' => 'instock',
								'orderby'      => 'date',
							),
						),
						array(
							'id'    => 'popular',
							'title' => 'انتخاب‌های محبوب',
							'query' => array( 'orderby' => 'popularity' ),
						),
					),
					'featured'     => $featured['items'],
					'generated_at' => gmdate( 'c' ),
				);
			}
		);
	}

	public function categories( Request $request ): Response {
		return $this->cached(
			$request,
			'categories',
			array(),
			fn() => array(
				'items'        => $this->categoriesData(),
				'generated_at' => gmdate( 'c' ),
			)
		);
	}

	public function products( Request $request ): Response {
		$query = self::normalizeQuery( $request->query() );
		return $this->cached( $request, 'products', $query, fn() => $this->productsData( $query ) );
	}

	public function product( Request $request, string $id ): Response {
		$product = wc_get_product( (int) $id );
		if ( ! $product || ! $this->isPublic( $product ) ) {
			return response()->json( array( 'code' => 'product_not_found' ), 404 )->header( 'Cache-Control', 'no-store' );
		}
		return $this->cached( $request, 'product', array( 'id' => (int) $id ), fn() => $this->project( $product, true ) );
	}

	/** Normalize before cache lookup and SQL; only documented filters have an effect. */
	public static function normalizeQuery( array $input ): array {
		$input = array_filter( $input, 'is_scalar' );
		$sort  = (string) ( $input['orderby'] ?? 'date' );
		$stock = (string) ( $input['stock_status'] ?? '' );
		return array(
			'page'         => max( 1, min( 500, (int) ( $input['page'] ?? 1 ) ) ),
			'per_page'     => max( 1, min( 48, (int) ( $input['per_page'] ?? 24 ) ) ),
			'search'       => mb_substr( sanitize_text_field( is_scalar( $input['search'] ?? '' ) ? (string) ( $input['search'] ?? '' ) : '' ), 0, 120 ),
			'category'     => max( 0, (int) ( $input['category'] ?? 0 ) ),
			'orderby'      => in_array( $sort, array( 'date', 'price', 'popularity', 'title' ), true ) ? $sort : 'date',
			'order'        => strtoupper( (string) ( $input['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
			'stock_status' => in_array( $stock, array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $stock : '',
			'has_images'   => in_array( (string) ( $input['has_images'] ?? '' ), array( '1', 'true' ), true ),
		);
	}

	/** Indexed lookup chooses a bounded page; WooCommerce remains price/identity authority. */
	private function productsData( array $query ): array {
		global $wpdb;
		$lookup     = $wpdb->prefix . 'wc_product_meta_lookup';
		$where      = "p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''";
		$values     = array();
		$visibility = $query['search'] === '' ? 'exclude-from-catalog' : 'exclude-from-search';
		$where     .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} vr JOIN {$wpdb->term_taxonomy} vt ON vt.term_taxonomy_id=vr.term_taxonomy_id JOIN {$wpdb->terms} v ON v.term_id=vt.term_id WHERE vr.object_id=p.ID AND vt.taxonomy='product_visibility' AND v.slug=%s)";
		$values[]   = $visibility;
		if ( $query['has_images'] ) {
			$where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} image WHERE image.post_id=p.ID AND image.meta_key='_thumbnail_id' AND CAST(image.meta_value AS UNSIGNED)>0)";
		}
		if ( $query['search'] !== '' ) {
			$where   .= ' AND (p.post_title LIKE %s OR l.sku LIKE %s)';
			$values[] = '%' . $wpdb->esc_like( $query['search'] ) . '%';
			$values[] = '%' . $wpdb->esc_like( $query['search'] ) . '%';
		}
		if ( $query['category'] > 0 ) {
			$children = get_term_children( $query['category'], 'product_cat' );
			$ids      = array_merge( array( $query['category'] ), is_wp_error( $children ) ? array() : array_map( 'absint', $children ) );
			$ids      = implode( ',', array_map( 'absint', $ids ) );
			$where   .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} cr JOIN {$wpdb->term_taxonomy} ct ON ct.term_taxonomy_id=cr.term_taxonomy_id WHERE cr.object_id=p.ID AND ct.taxonomy='product_cat' AND ct.term_id IN ({$ids}))";
		}
		if ( $query['stock_status'] !== '' ) {
			$where   .= ' AND l.stock_status=%s';
			$values[] = $query['stock_status'];
		} elseif ( get_option( 'woocommerce_hide_out_of_stock_items' ) === 'yes' ) {
			$where .= " AND l.stock_status != 'outofstock'";
		}
		$sorts = array(
			'date'       => 'p.post_date_gmt',
			'price'      => 'l.min_price',
			'popularity' => 'l.total_sales',
			'title'      => 'p.post_title',
		);
		$order = $sorts[ $query['orderby'] ] . ' ' . $query['order'] . ', p.ID ' . $query['order'];
		$from  = "FROM {$wpdb->posts} p LEFT JOIN {$lookup} l ON l.product_id=p.ID WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only trusted table names and allowlisted sort fragments are interpolated.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $values ) );
		$args  = array_merge( $values, array( $query['per_page'], ( $query['page'] - 1 ) * $query['per_page'] ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Filters and bounds are prepared; sort fields are allowlisted.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID {$from} ORDER BY {$order} LIMIT %d OFFSET %d", $args ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$items[] = $this->project( $product, false );
			}
		}
		$more = $query['page'] * $query['per_page'] < $total;
		return array(
			'items'        => $items,
			'pagination'   => array(
				'page'      => $query['page'],
				'per_page'  => $query['per_page'],
				'has_more'  => $more,
				'next_page' => $more ? $query['page'] + 1 : null,
				'total'     => $total,
			),
			'generated_at' => gmdate( 'c' ),
		);
	}

	private function categoriesData(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_values(
			array_map(
				function ( $term ): array {
					$image_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
					$url      = get_term_link( $term );
					return array(
						'id'        => $term->term_id,
						'name'      => $term->name,
						'slug'      => $term->slug,
						'parent'    => $term->parent,
						'count'     => $term->count,
						'permalink' => is_wp_error( $url ) ? '' : $url,
						'image'     => $image_id > 0 ? $this->image( $image_id ) : null,
					);
				},
				$terms
			)
		);
	}

	private function isPublic( $product ): bool {
		if ( $product->get_status() !== 'publish' || get_post_field( 'post_password', $product->get_id() ) !== '' || $product->get_catalog_visibility() === 'hidden' ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return $parent && $this->isPublic( $parent );
		}
		return true;
	}

	/** Values are decimal strings in the store's currency minor unit, never inferred. */
	public static function minorUnits( $value, int $decimals ): ?string {
		if ( $value === '' || $value === null || ! is_numeric( $value ) ) {
			return null;
		}
		$decimal = number_format( (float) $value, max( 0, $decimals ), '.', '' );
		return str_replace( '.', '', $decimal );
	}

	public static function displayName( string $name, string $sku ): string {
		return $sku === '' ? $name : trim( preg_replace( '/\s*\[\s*' . preg_quote( $sku, '/' ) . '\s*\]\s*$/u', '', $name ) );
	}

	private function project( $product, bool $details ): array {
		$minor      = (int) wc_get_price_decimals();
		$image_ids  = array_values( array_unique( array_filter( array_merge( array( $product->get_image_id() ), $details ? $product->get_gallery_image_ids() : array() ) ) ) );
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() ) {
				continue;
			}
			$options      = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			$attributes[] = array(
				'id'        => $attribute->get_id(),
				'name'      => wc_attribute_label( $attribute->get_name() ),
				'slug'      => $attribute->get_name(),
				'variation' => (bool) $attribute->get_variation(),
				'options'   => is_wp_error( $options ) ? array() : array_values( $options ),
			);
		}
		$categories = get_the_terms( $product->get_id(), 'product_cat' );
		$categories = is_array( $categories ) ? $categories : array();
		$data       = array(
			'id'                => $product->get_id(),
			'parent_id'         => $product->get_parent_id(),
			'sku'               => $product->get_sku(),
			'name'              => self::displayName( html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ), $product->get_sku() ),
			'type'              => $product->get_type(),
			'permalink'         => $product->get_permalink(),
			'short_description' => wp_kses_post( strip_shortcodes( $product->get_short_description() ) ),
			'description'       => $details ? wp_kses_post( strip_shortcodes( $product->get_description() ) ) : '',
			'prices'            => array(
				'price'               => self::minorUnits( $product->get_price(), $minor ),
				'regular_price'       => self::minorUnits( $product->get_regular_price(), $minor ),
				'sale_price'          => self::minorUnits( $product->get_sale_price(), $minor ),
				'currency_code'       => get_woocommerce_currency(),
				'currency_minor_unit' => $minor,
				'currency_symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			),
			'is_in_stock'       => $product->is_in_stock(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'is_purchasable'    => $product->is_purchasable(),
			'on_sale'           => $product->is_on_sale(),
			'sold_individually' => $product->is_sold_individually(),
			'images'            => array_map( fn( $id ) => $this->image( (int) $id ), $image_ids ),
			'attributes'        => $attributes,
			'categories'        => array_map(
				static fn( $term ) => array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				),
				$categories
			),
			'variations'        => array(),
			'average_rating'    => $product->get_average_rating(),
			'review_count'      => $product->get_review_count(),
			'modified_at'       => $product->get_date_modified() ? $product->get_date_modified()->date( 'c' ) : null,
		);
		if ( $details && $product->is_type( 'variable' ) ) {
			foreach ( array_slice( $product->get_children(), 0, 100 ) as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child || ! $this->isPublic( $child ) ) {
					continue;
				}
				$data['variations'][] = array(
					'id'             => $child_id,
					'sku'            => $child->get_sku(),
					'name'           => self::displayName( $child->get_name(), $child->get_sku() ),
					'attributes'     => $child->get_variation_attributes(),
					'price'          => self::minorUnits( $child->get_price(), $minor ),
					'is_in_stock'    => $child->is_in_stock(),
					'is_purchasable' => $child->is_purchasable(),
					'stock_quantity' => $child->get_stock_quantity(),
					'image'          => $child->get_image_id() ? $this->image( $child->get_image_id() ) : null,
				);
			}
		}
		if ( $details ) {
			$data['related_ids'] = wc_get_related_products( $product->get_id(), 8 );
			$data['documents']   = class_exists( 'Digitalogic_Product_Resources' ) ? \Digitalogic_Product_Resources::instance()->get_documents( $product->get_id() ) : array();
		}
		return $data;
	}

	private function image( int $id ): array {
		return array(
			'id'        => $id,
			'src'       => wp_get_attachment_image_url( $id, 'full' ) ?: '',
			'thumbnail' => wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ) ?: '',
			'medium'    => wp_get_attachment_image_url( $id, 'medium_large' ) ?: '',
			'srcset'    => wp_get_attachment_image_srcset( $id, 'full' ) ?: '',
			'alt'       => get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	private function cached( Request $request, string $route, array $query, callable $build ): Response {
		$generation = class_exists( 'Digitalogic_Report_Engine' ) ? \Digitalogic_Report_Engine::instance()->current_projection_generation() : '';
		$generation = is_wp_error( $generation ) ? '' : $generation;
		$key        = 'catalog-' . md5( wp_json_encode( array( $route, $query, $generation, get_woocommerce_currency(), get_option( 'digitalogic_mobile_merchandising', array() ), get_option( 'digitalogic_mobile_update_manifest', '' ), get_option( 'digitalogic_mobile_native_auth', false ), get_option( 'digitalogic_mobile_google_android_client', '' ), get_option( 'digitalogic_mobile_websocket', false ), get_option('digitalogic_mobile_forms',false), 'identity-3' ) ) );
		$private    = get_current_user_id() > 0;
		$data       = $private ? false : wp_cache_get( $key, 'digitalogic-mobile' );
		$hit        = is_array( $data );
		if ( ! $hit ) {
			$data = $build();
			if ( ! $private ) {
				wp_cache_set( $key, $data, 'digitalogic-mobile', 60 );
			}
		}
		$response = response()->json(
			$data,
			200,
			array(
				'Cache-Control'                 => $private ? 'private, no-store' : 'public, max-age=15, stale-while-revalidate=45',
				'X-Digitalogic-Cache'           => $hit ? 'HIT' : 'MISS',
				'X-Content-Type-Options'        => 'nosniff',
				'Access-Control-Expose-Headers' => 'ETag, X-Digitalogic-Cache',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$response->setEtag( hash( 'sha256', $response->getContent() ), true );
		// Apache may append its transfer encoding to a weak validator. Compare
		// equivalent GET representations against the original semantic validator.
		$request->headers->set( 'If-None-Match', preg_replace( '/-(?:gzip|br)(?=")/', '', $request->headers->get( 'If-None-Match', '' ) ) );
		$response->isNotModified( $request );
		return $response;
	}
}
