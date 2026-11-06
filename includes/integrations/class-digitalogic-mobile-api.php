<?php
/**
 * Unversioned public mobile entry point; commerce writes remain with WooCommerce.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Native adapter over canonical WordPress and WooCommerce authority. */
final class Digitalogic_Mobile_API {

	/**
	 * Changed cart users.
	 *
	 * @var array
	 */
	private static array $changed_cart_users = array();
	/**
	 * Complete explicit native registration under the existing Digits identity policy.
	 *
	 * @return void Native adapter result.
	 */
	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'dispatch' ), 0 );
		add_action( 'admin_menu', array( self::class, 'admin_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'order_changed' ), 20, 4 );
		foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_item_restored', 'woocommerce_cart_emptied' ) as $event ) {
			add_action( $event, array( self::class, 'cart_changed' ), 90, 0 );}
		// Woo session persistence runs at shutdown priority20. Notify after the website's save.
		add_action( 'shutdown', array( self::class, 'flush_cart_changes' ), 40 );
	}

	/**
	 * Handle cart changed within the native adapter boundary.
	 *
	 * @return void Native adapter result.
	 */
	public static function cart_changed(): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			self::$changed_cart_users[ $user_id ] = true;}
	}
	/**
	 * Handle flush cart changes within the native adapter boundary.
	 *
	 * @return void Native adapter result.
	 */
	public static function flush_cart_changes(): void {
		if ( ! class_exists( 'Digitalogic_Panel' ) ) {
			return;}
		foreach ( array_keys( self::$changed_cart_users ) as $user_id ) {
			Digitalogic_Panel::record_event(
				'mobile.cart.updated',
				array(
					'user_id'  => (int) $user_id,
					'scope'    => 'cart',
					'audience' => array( 'users' => array( (int) $user_id ) ),
				)
			);}
		self::$changed_cart_users = array();
	}

	/**
	 * Handle order changed within the native adapter boundary.
	 *
	 * @param mixed $id Exact scoped identifier.
	 * @param mixed $from From.
	 * @param mixed $to To.
	 * @param mixed $order Order.
	 * @return void Native adapter result.
	 */
	public static function order_changed( $id, $from, $to, $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 && class_exists( 'Digitalogic_Panel' ) ) {
			Digitalogic_Panel::record_event(
				'mobile.order.updated',
				array(
					'order_id' => (int) $id,
					'user_id'  => $user_id,
					'status'   => sanitize_key( $to ),
					'audience' => array( 'users' => array( $user_id ) ),
				)
			);
		}
	}

	/**
	 * Match only the bounded public mobile route, never internal panel routes.
	 *
	 * @param string $path Path.
	 * @return string|null Native adapter result.
	 */
	public static function route( string $path ): ?string {
		if ( preg_match( '~^/app-api/(bootstrap|categories|links/resolve|forms|checkout(?:/quote|/operations/[A-Za-z0-9-]{16,64})?|cart(?:/claim)?|uploads(?:/[a-f0-9]{32})?|requests(?:/[A-Za-z0-9-]{16,64})?|products(?:/[1-9][0-9]{0,9})?|auth/(?:otp/request|otp/verify|google|register|sessions|logout)|account/(?:profile|orders|favorites|requests|phone/request|phone/verify|reauth/google(?:/challenge)?))/?$~D', $path, $matches ) ) {
			return '/_digitalogic/mobile/' . $matches[1];
		}
		return null;
	}

	/**
	 * Handle methods within the native adapter boundary.
	 *
	 * @param string $route Route.
	 * @return array Native adapter result.
	 */
	public static function methods( string $route ): array {
		if ( str_ends_with( $route, '/mobile/checkout' ) ) {
			return array( 'GET', 'HEAD', 'POST' );}
		if ( str_ends_with( $route, '/checkout/quote' ) ) {
			return array( 'POST' );}
		if ( str_ends_with( $route, '/mobile/cart' ) ) {
			return array( 'GET', 'HEAD', 'POST', 'PATCH', 'DELETE' );
		}
		if ( preg_match( '~/account/(?:phone/(?:request|verify)|reauth/google(?:/challenge)?)$~D', $route ) ) {
			return array( 'POST' );
		}
		if ( str_ends_with( $route, '/cart/claim' ) ) {
			return array( 'POST' );
		}
		if ( preg_match( '~/(?:uploads|requests|auth/(?:otp/request|otp/verify|google|register|logout))$~D', $route ) && ! str_ends_with( $route, '/account/requests' ) ) {
			return array( 'POST' );
		}
		if ( preg_match( '~/uploads/[a-f0-9]{32}$~D', $route ) ) {
			return array( 'GET', 'HEAD', 'PUT', 'DELETE' );
		}
		return preg_match( '~/account/(?:profile|favorites)$~D', $route ) ? array( 'GET', 'HEAD', 'PUT' ) : array( 'GET', 'HEAD' );
	}

	/**
	 * Handle dispatch within the native adapter boundary.
	 *
	 * @return void Native adapter result.
	 * @throws RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function dispatch(): void {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = (string) $path;
		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		if ( ! str_starts_with( $path, '/app-api/' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$route = self::route( $path );
		if ( null === $route ) {
			wp_send_json(
				array(
					'code'    => 'not_found',
					'message' => 'مسیر پیدا نشد.',
				),
				404
			);
		}
		$method = strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		if ( 'OPTIONS' === $method ) {
			header( 'Access-Control-Allow-Methods: ' . implode( ', ', self::methods( $route ) ) . ', OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-Digitalogic-Cart, Upload-Offset, Content-Range, X-WP-Nonce' );
			header( 'Access-Control-Max-Age: 600' );
			status_header( 204 );
			exit;
		}
		$write      = in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true );
		$binary     = 'PUT' === $method && preg_match( '~/uploads/[a-f0-9]{32}$~D', $route );
		$body_limit = $binary ? 524288 : ( ( str_starts_with( $route, '/_digitalogic/mobile/requests' ) || str_starts_with( $route, '/_digitalogic/mobile/checkout' ) ) ? 32768 : 8192 );
		if ( ! in_array( $method, self::methods( $route ), true ) ) {
			header( 'Allow: ' . implode( ', ', self::methods( $route ) ) );
			wp_send_json( array( 'code' => 'method_not_allowed' ), 405 );
		}
		if ( $write && ( ! str_starts_with( strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) ) ), $binary ? 'application/octet-stream' : 'application/json' ) || absint( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > $body_limit ) ) {
			wp_send_json( array( 'code' => 'invalid_content_type_or_size' ), 400 );
		}
		try {
			$app = Digitalogic_Laravel_Bridge::instance()->boot_laravel();
			if ( is_wp_error( $app ) ) {
				throw new RuntimeException( 'Mobile runtime unavailable.' );
			}
			$content = $write ? file_get_contents( 'php://input', false, null, 0, $body_limit + 1 ) : null;
			if ( $write && ( ! is_string( $content ) || strlen( $content ) > $body_limit ) ) {
				wp_send_json( array( 'code' => 'request_too_large' ), 413 );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public query parameters are bounded in each route; mutations require native owner authorization, not browser nonces.
			$request = \Illuminate\Http\Request::create( $route, $method, wp_unslash( $_GET ), array(), array(), array(), $content );
			$request->headers->set( 'Accept', 'application/json' );
			$request->headers->set( 'If-None-Match', sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) );
			$request->headers->set( 'Content-Type', sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ?? '' ) ) );
			$request->headers->set( 'Origin', sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) ) );
			$request->headers->set( 'X-WP-Nonce', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) ) );
			$request->headers->set( 'Authorization', sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) ) );
			$request->headers->set( 'Upload-Offset', sanitize_text_field( wp_unslash( $_SERVER['HTTP_UPLOAD_OFFSET'] ?? '' ) ) );
			$request->headers->set( 'Content-Range', sanitize_text_field( wp_unslash( $_SERVER['HTTP_CONTENT_RANGE'] ?? '' ) ) );
			$request->headers->set( 'X-Digitalogic-Cart', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DIGITALOGIC_CART'] ?? '' ) ) );
			$kernel   = $app->make( \Illuminate\Contracts\Http\Kernel::class );
			$response = $kernel->handle( $request );
			$response->send();
			$kernel->terminate( $request, $response );
		} catch ( Throwable $error ) {
			wp_send_json(
				array(
					'code'    => 'service_unavailable',
					'message' => 'چند لحظه دیگر دوباره تلاش کنید.',
				),
				503
			);
		}
		exit;
	}

	/**
	 * Handle admin menu within the native adapter boundary.
	 *
	 * @return void Native adapter result.
	 */
	public static function admin_menu(): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by the required WooCommerce provider.
		add_submenu_page( 'woocommerce', 'اپ دیجیتالاجیک', 'اپ دیجیتالاجیک', 'manage_woocommerce', 'digitalogic-mobile', array( self::class, 'settings_page' ) );
	}

	/**
	 * Handle register settings within the native adapter boundary.
	 *
	 * @return void Native adapter result.
	 */
	public static function register_settings(): void {
		register_setting( 'digitalogic_mobile', 'digitalogic_mobile_address_map', array( 'sanitize_callback' => array( self::class, 'sanitize_address_map' ) ) );
		register_setting( 'digitalogic_mobile', 'digitalogic_mobile_merchandising', array( 'sanitize_callback' => array( self::class, 'sanitize_merchandising' ) ) );
		register_setting( 'digitalogic_mobile', 'digitalogic_mobile_update_manifest', array( 'sanitize_callback' => array( self::class, 'sanitize_update_manifest' ) ) );
		register_setting(
			'digitalogic_mobile',
			'digitalogic_mobile_native_auth',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $value ) => (bool) $value,
			)
		);
		add_filter( 'option_page_capability_digitalogic_mobile', static fn() => 'manage_woocommerce' );
	}

	/**
	 * Verify the published same-origin manifest before advertising updates.
	 *
	 * @param mixed $value Value.
	 * @return string Native adapter result.
	 */
	public static function sanitize_update_manifest( $value ): string {
		$url = esc_url_raw( is_scalar( $value ) ? (string) $value : '', array( 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$valid    = wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
		$response = $valid ? wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 32768,
			)
		) : null;
		$data     = $response && ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data ) || ! is_numeric( $data['version_code'] ?? null ) || (int) $data['version_code'] < 1 || ! is_string( $data['version_name'] ?? null ) || ! preg_match( '/\A[a-fA-F0-9]{64}\z/D', $data['sha256'] ?? '' ) || wp_parse_url( $data['apk_url'] ?? '', PHP_URL_SCHEME ) !== 'https' || wp_parse_url( $data['apk_url'] ?? '', PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			add_settings_error( 'digitalogic_mobile_update_manifest', 'invalid_manifest', 'فایل انتشار معتبر و قابل دسترس نیست. ابتدا انتشار امضاشده را روی سرور قرار دهید.' );
			return (string) get_option( 'digitalogic_mobile_update_manifest', '' );
		}
		return $url;
	}

	/**
	 * Validate only public tile-provider settings; no customer coordinates enter this option.
	 *
	 * @param mixed $value Value.
	 * @return array Native adapter result.
	 */
	public static function sanitize_address_map( $value ): array {
		if ( ! class_exists( '\Digitalogic\Laravel\Services\MobileAddresses' ) ) {
			require_once DIGITALOGIC_PLUGIN_DIR . 'laravel/app/Services/MobileAddresses.php';
		}
		return \Digitalogic\Laravel\Services\MobileAddresses::sanitize_map( $value );
	}

	/**
	 * Handle sanitize merchandising within the native adapter boundary.
	 *
	 * @param mixed $input Validated or pending native input.
	 * @return array Native adapter result.
	 */
	public static function sanitize_merchandising( $input ): array {
		$slides = array();
		foreach ( array_slice( is_array( $input ) ? $input : array(), 0, 6 ) as $slide ) {
			if ( ! is_array( $slide ) ) {
				continue;
			}
			$title = sanitize_text_field( $slide['title'] ?? '' );
			$image = esc_url_raw( $slide['image'] ?? '', array( 'https' ) );
			$url   = esc_url_raw( $slide['url'] ?? '', array( 'https' ) );
			if ( '' === $title || '' === $image || '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				continue;
			}
			$slides[] = array(
				'title' => $title,
				'image' => $image,
				'url'   => $url,
			);
		}
		return $slides;
	}

	/**
	 * Handle settings page within the native adapter boundary.
	 *
	 * @return void Native adapter result.
	 */
	public static function settings_page(): void {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by the required WooCommerce provider.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$slides = get_option( 'digitalogic_mobile_merchandising', array() );
		echo '<div class="wrap" dir="rtl"><h1>ویترین اپ دیجیتالاجیک</h1><p>بنرها پس از تازه‌سازی در اپ نمایش داده می‌شوند. ردیف خالی نمایش داده نمی‌شود. لینک مقصد باید متعلق به همین فروشگاه باشد.</p><form action="options.php" method="post">';
		settings_fields( 'digitalogic_mobile' );
		$map = self::sanitize_address_map( get_option( 'digitalogic_mobile_address_map', array() ) );
		echo '<fieldset><legend>نقشه نشانی مشتری</legend><input type="hidden" name="digitalogic_mobile_address_map[enabled]" value="0"><label><input type="checkbox" name="digitalogic_mobile_address_map[enabled]" value="1" ' . checked( $map['enabled'], true, false ) . '> نمایش نقشه اختیاری</label>';
		foreach ( array(
			'tile_url'         => 'نشانی عمومی کاشی‌ها با {z}/{x}/{y}',
			'attribution_text' => 'متن حق استفاده از نقشه',
			'attribution_url'  => 'پیوند حق استفاده از نقشه',
		) as $key => $label ) {
			echo '<p><label>' . esc_html( $label ) . '<br><input dir="ltr" class="large-text" name="digitalogic_mobile_address_map[' . esc_attr( $key ) . ']" value="' . esc_attr( $map[ $key ] ) . '"></label></p>';
		}
		echo '<p>فقط نشانی عمومی سرویس را وارد کنید. نقطه شروع نقشه، نشانی انتخاب‌شده مشتری نیست.</p></fieldset>';
		echo '<p><input type="hidden" name="digitalogic_mobile_native_auth" value="0"><label><input type="checkbox" name="digitalogic_mobile_native_auth" value="1" ' . checked( get_option( 'digitalogic_mobile_native_auth', false ), true, false ) . '> ورود و حساب بومی اپ</label></p><p>نشست‌های اپ از ورود مرورگر و پنل مستقل‌اند. ورود پیامکی از تنظیمات فعلی سرویس پیامک استفاده می‌کند.</p>';
		echo '<p><label>نشانی فایل انتشار اپ (HTTPS)<br><input dir="ltr" class="large-text" type="url" name="digitalogic_mobile_update_manifest" value="' . esc_attr( get_option( 'digitalogic_mobile_update_manifest', '' ) ) . '"></label></p><p>تنها پس از انتشار APK امضاشده و فایل انتشار معتبر، به‌روزرسانی به کاربران پیشنهاد می‌شود.</p>';
		for ( $index = 0; $index < 6; $index++ ) {
			echo '<fieldset style="padding:16px;margin:12px 0;background:white;border:1px solid #ddd"><legend>بنر ' . esc_html( (string) ( $index + 1 ) ) . '</legend>';
			foreach ( array(
				'title' => 'عنوان',
				'image' => 'نشانی تصویر HTTPS',
				'url'   => 'لینک مقصد',
			) as $key => $label ) {
				echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="' . ( 'title' === $key ? 'text' : 'url' ) . '" name="digitalogic_mobile_merchandising[' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $slides[ $index ][ $key ] ?? '' ) . '"></label></p>';
			}
			echo '</fieldset>';
		}
		submit_button( 'ذخیره ویترین' );
		echo '</form></div>';
	}
}
