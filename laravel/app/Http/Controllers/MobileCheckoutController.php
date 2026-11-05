<?php

namespace Digitalogic\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Short-lived cart intent only: never authenticates a user or creates an order. */
final class MobileCheckoutController {
	public function create( Request $request ): Response {
		if ( ! $this->rateLimit() ) {
			return $this->error( 'rate_limited', 'چند لحظه صبر کنید و دوباره تلاش کنید.', 429 );
		}
		$items = self::normalizeItems( $request->json( 'items' ) );
		if ( $items === null ) {
			return $this->error( 'invalid_items', 'سبد خرید معتبر نیست.', 400 );
		}
		$validated = $this->validateProducts( $items );
		if ( is_wp_error( $validated ) ) {
			return $this->error( $validated->get_error_code(), $validated->get_error_message(), 409 );
		}
		$expires = time() + 300;
		$token   = self::sign(
			array(
				'items'   => $items,
				'expires' => $expires,
				'nonce'   => bin2hex( random_bytes( 16 ) ),
			),
			wp_salt( 'auth' )
		);
		return $this->json(
			array(
				'url'        => home_url( '/app-checkout/#' ) . $token,
				'review_url' => home_url( '/app-checkout/#' ) . $token,
				'expires_at' => gmdate( 'c', $expires ),
				'expires_in' => 300,
				'item_count' => count( $items ),
			),
			201
		);
	}

	public function preview( Request $request ): Response {
		if ( ! $this->browserRequest( $request ) ) {
			return $this->error( 'forbidden_origin', 'درخواست معتبر نیست.', 403 );
		}
		$payload = self::verify( (string) $request->json( 'token', '' ), wp_salt( 'auth' ), time() );
		if ( $payload === null ) {
			return $this->error( 'expired_or_invalid', 'لینک منقضی شده؛ از اپ دوباره ادامه دهید.', 410 );
		}
		$products = $this->validateProducts( $payload['items'] );
		if ( is_wp_error( $products ) ) {
			return $this->error( $products->get_error_code(), $products->get_error_message(), 409 );
		}
		return $this->json(
			array(
				'items'            => $products,
				'expires_at'       => gmdate( 'c', $payload['expires'] ),
				'has_browser_cart' => function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty(),
			)
		);
	}

	public function redeem( Request $request ): Response {
		if ( ! $this->browserRequest( $request ) || ! wp_verify_nonce( $request->headers->get( 'X-WP-Nonce', '' ), 'digitalogic_mobile_checkout' ) ) {
			return $this->error( 'forbidden', 'صفحه را تازه‌سازی کنید و دوباره تلاش کنید.', 403 );
		}
		$payload = self::verify( (string) $request->json( 'token', '' ), wp_salt( 'auth' ), time() );
		if ( $payload === null ) {
			return $this->error( 'expired_or_invalid', 'لینک منقضی شده؛ از اپ دوباره ادامه دهید.', 410 );
		}
		$products = $this->validateProducts( $payload['items'] );
		if ( is_wp_error( $products ) ) {
			return $this->error( $products->get_error_code(), $products->get_error_message(), 409 );
		}
		// options.option_name has a unique key: only one concurrent request can
		// insert this claim. No transient read/delete race and no token is stored.
		$claim = '_digitalogic_mobile_claim_' . hash( 'sha256', $payload['nonce'] );
		if ( ! add_option( $claim, (string) $payload['expires'], '', false ) ) {
			return $this->error( 'already_used', 'این لینک قبلاً استفاده شده است. از اپ دوباره ادامه دهید.', 409 );
		}
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		$cart     = WC()->cart;
		$previous = $cart->get_cart();
		$coupons  = $cart->get_applied_coupons();
		try {
			$cart->empty_cart( false );
			foreach ( $payload['items'] as $item ) {
				$product   = wc_get_product( $item['id'] );
				$variation = $product->is_type( 'variation' );
				$key       = $cart->add_to_cart( $variation ? $product->get_parent_id() : $product->get_id(), $item['quantity'], $variation ? $product->get_id() : 0, $variation ? $product->get_variation_attributes() : array() );
				if ( ! $key ) {
					throw new \RuntimeException( 'Cart validation rejected an item.' );
				}
			}
			$cart->calculate_totals();
			WC()->session->set_customer_session_cookie( true );
			return $this->json(
				array(
					'redirect'   => wc_get_checkout_url(),
					'item_count' => $cart->get_cart_contents_count(),
				)
			);
		} catch ( \Throwable $error ) {
			$cart->set_cart_contents( $previous );
			$cart->set_applied_coupons( $coupons );
			$cart->calculate_totals();
			return $this->error( 'cart_changed', 'موجودی یا شرایط خرید تغییر کرده است. سبد قبلی حفظ شد؛ از اپ دوباره تلاش کنید.', 409 );
		}
	}

	public static function normalizeItems( $input ): ?array {
		if ( ! is_array( $input ) || ! array_is_list( $input ) || count( $input ) < 1 || count( $input ) > 40 ) {
			return null;
		}
		$items = array();
		foreach ( $input as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'id', 'quantity' ) ) || ! is_int( $item['id'] ?? null ) || ! is_int( $item['quantity'] ?? null ) || $item['id'] < 1 || $item['quantity'] < 1 || $item['quantity'] > 999 ) {
				return null;
			}
			$id           = $item['id'];
			$items[ $id ] = array(
				'id'       => $id,
				'quantity' => ( $items[ $id ]['quantity'] ?? 0 ) + $item['quantity'],
			);
			if ( $items[ $id ]['quantity'] > 999 ) {
				return null;
			}
		}
		return array_values( $items );
	}

	public static function sign( array $payload, string $secret ): string {
		$body = rtrim( strtr( base64_encode( json_encode( $payload, JSON_THROW_ON_ERROR ) ), '+/', '-_' ), '=' );
		return $body . '.' . hash_hmac( 'sha256', $body, $secret );
	}

	public static function verify( string $token, string $secret, int $now ): ?array {
		if ( strlen( $token ) > 8000 || ! preg_match( '/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D', $token, $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[1], $secret ), $parts[2] ) ) {
			return null;
		}
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true );
		if ( ! is_array( $payload ) || ! is_int( $payload['expires'] ?? null ) || $payload['expires'] <= $now || $payload['expires'] > $now + 300 || ! is_string( $payload['nonce'] ?? null ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $payload['nonce'] ) ) {
			return null;
		}
		$items = self::normalizeItems( $payload['items'] ?? null );
		return $items === null ? null : array(
			'items'   => $items,
			'expires' => $payload['expires'],
			'nonce'   => $payload['nonce'],
		);
	}

	private function validateProducts( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['id'] );
			$parent  = $product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
			if ( ! $product || ! $parent || $product->get_status() !== 'publish' || $parent->get_status() !== 'publish' || $parent->get_catalog_visibility() === 'hidden' || get_post_field( 'post_password', $parent->get_id() ) !== '' || ! $product->is_purchasable() || $product->is_type( 'variable' ) || ! $product->is_in_stock() || ! $product->has_enough_stock( $item['quantity'] ) || ( $product->is_sold_individually() && $item['quantity'] !== 1 ) ) {
				return new \WP_Error( 'product_unavailable', 'یکی از کالاها یا تعداد انتخاب‌شده قابل سفارش نیست. سبد خرید را دوباره بررسی کنید.' );
			}
			$out[] = array(
				'id'       => $product->get_id(),
				'sku'      => $product->get_sku(),
				'name'     => $product->get_name(),
				'quantity' => $item['quantity'],
				'image'    => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: null,
			);
		}
		return $out;
	}

	private function browserRequest( Request $request ): bool {
		$origin   = rtrim( $request->headers->get( 'Origin', '' ), '/' );
		$home     = wp_parse_url( home_url( '/' ) );
		$expected = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return $origin !== '' && hash_equals( $expected, $origin );
	}

	/** Atomic bounded admission; only keyed hashes of the transport IP are stored. */
	private function rateLimit(): bool {
		global $wpdb;
		$minute = (int) floor( time() / 60 );
		$prefix = '_digitalogic_mobile_rate_';
		$ip     = hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), wp_salt( 'nonce' ) );
		foreach ( array(
			'global' => 120,
			$ip      => 12,
		) as $scope => $limit ) {
			$key = $prefix . $minute . '_' . $scope;
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'0','off')", $key ) );
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=CAST(option_value AS UNSIGNED)+1 WHERE option_name=%s AND CAST(option_value AS UNSIGNED)<%d", $key, $limit ) );
			if ( $updated !== 1 ) {
				return false;
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name<%s LIMIT 200", $wpdb->esc_like( $prefix ) . '%', $prefix . ( $minute - 2 ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d LIMIT 200", $wpdb->esc_like( '_digitalogic_mobile_claim_' ) . '%', time() ) );
		return true;
	}

	private function error( string $code, string $message, int $status ): Response {
		return $this->json(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	private function json( array $data, int $status = 200 ): Response {
		return response()->json(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'Referrer-Policy'        => 'no-referrer',
				'X-Content-Type-Options' => 'nosniff',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}
