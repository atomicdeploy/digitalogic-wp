<?php
/** Unversioned public mobile entry point; commerce writes remain with WooCommerce. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Mobile_API {
	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'dispatch' ), 0 );
		add_action( 'admin_menu', array( self::class, 'admin_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'order_changed' ), 20, 4 );
	}

	public static function order_changed( $id, $from, $to, $order ): void {
		if (!is_object($order) || !method_exists($order,'get_customer_id')) { return; }
		$user_id=(int)$order->get_customer_id();
		if ($user_id>0 && class_exists('Digitalogic_Panel')) {
			Digitalogic_Panel::record_event('mobile.order.updated',array('order_id'=>(int)$id,'user_id'=>$user_id,'status'=>sanitize_key($to),'audience'=>array('users'=>array($user_id))));
		}
	}

	/** Match only the bounded public mobile route, never internal panel routes. */
	public static function route( string $path ): ?string {
		if ( preg_match( '~^/app-api/(bootstrap|categories|products(?:/[1-9][0-9]{0,9})?|checkout(?:/preview|/redeem)?|auth/(?:otp/request|otp/verify|google|sessions|logout)|account/(?:profile|orders|favorites))/?$~D', $path, $matches ) ) {
			return '/_digitalogic/mobile/' . $matches[1];
		}
		return null;
	}

	public static function methods( string $route ): array {
		if ( preg_match( '~/(?:checkout(?:/preview|/redeem)?|auth/(?:otp/request|otp/verify|google|logout))$~D', $route ) ) {
			return array( 'POST' );
		}
		return preg_match( '~/account/(?:profile|favorites)$~D', $route ) ? array( 'GET', 'HEAD', 'PUT' ) : array( 'GET', 'HEAD' );
	}

	public static function dispatch(): void {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = (string) $path;
		if ( $base !== '' && str_starts_with( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		if ( rtrim( $path, '/' ) === '/app-checkout' ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Frame-Options: DENY' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			require DIGITALOGIC_PLUGIN_DIR . 'templates/mobile-checkout.php';
			exit;
		}
		if ( ! str_starts_with( $path, '/app-api/' ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$route = self::route( $path );
		if ( $route === null ) {
			wp_send_json( array( 'code' => 'not_found', 'message' => 'مسیر پیدا نشد.' ), 404 );
		}
		$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		$write = in_array( $method, array( 'POST', 'PUT' ), true );
		if ( ! in_array( $method, self::methods( $route ), true ) ) {
			header( 'Allow: ' . implode( ', ', self::methods( $route ) ) );
			wp_send_json( array( 'code' => 'method_not_allowed' ), 405 );
		}
		if ( $write && ( ! str_starts_with( strtolower( $_SERVER['CONTENT_TYPE'] ?? '' ), 'application/json' ) || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 8192 ) ) {
			wp_send_json( array( 'code' => 'invalid_content_type_or_size' ), 400 );
		}
		try {
			$app = Digitalogic_Laravel_Bridge::instance()->boot_laravel();
			if ( is_wp_error( $app ) ) {
				throw new RuntimeException( 'Mobile runtime unavailable.' );
			}
			$content = $write ? file_get_contents( 'php://input', false, null, 0, 8193 ) : null;
			if ( $write && ( ! is_string( $content ) || strlen( $content ) > 8192 ) ) {
				wp_send_json( array( 'code' => 'request_too_large' ), 413 );
			}
			$request = \Illuminate\Http\Request::create( $route, $method, wp_unslash( $_GET ), array(), array(), array(), $content );
			$request->headers->set( 'Accept', 'application/json' );
			$request->headers->set( 'If-None-Match', wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) );
			$request->headers->set( 'Content-Type', $_SERVER['CONTENT_TYPE'] ?? '' );
			$request->headers->set( 'Origin', $_SERVER['HTTP_ORIGIN'] ?? '' );
			$request->headers->set( 'X-WP-Nonce', $_SERVER['HTTP_X_WP_NONCE'] ?? '' );
			$request->headers->set( 'Authorization', wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
			$kernel   = $app->make( \Illuminate\Contracts\Http\Kernel::class );
			$response = $kernel->handle( $request );
			$response->send();
			$kernel->terminate( $request, $response );
		} catch ( Throwable $error ) {
			wp_send_json( array( 'code' => 'service_unavailable', 'message' => 'چند لحظه دیگر دوباره تلاش کنید.' ), 503 );
		}
		exit;
	}

	public static function admin_menu(): void {
		add_submenu_page( 'woocommerce', 'اپ دیجیتالاجیک', 'اپ دیجیتالاجیک', 'manage_woocommerce', 'digitalogic-mobile', array( self::class, 'settings_page' ) );
	}

	public static function register_settings(): void {
		register_setting( 'digitalogic_mobile', 'digitalogic_mobile_merchandising', array( 'sanitize_callback' => array( self::class, 'sanitize_merchandising' ) ) );
		register_setting( 'digitalogic_mobile', 'digitalogic_mobile_update_manifest', array( 'sanitize_callback' => array( self::class, 'sanitize_update_manifest' ) ) );
		register_setting( 'digitalogic_mobile', 'digitalogic_mobile_native_auth', array( 'type' => 'boolean', 'sanitize_callback' => static fn( $value ) => (bool) $value ) );
		add_filter( 'option_page_capability_digitalogic_mobile', static fn() => 'manage_woocommerce' );
	}

	/** Verify the published same-origin manifest before advertising updates. */
	public static function sanitize_update_manifest( $value ): string {
		$url = esc_url_raw( is_scalar( $value ) ? (string) $value : '', array( 'https' ) );
		if ( $url === '' ) {
			return '';
		}
		$valid = wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
		$response = $valid ? wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 32768 ) ) : null;
		$data = $response && ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data ) || ! is_numeric( $data['version_code'] ?? null ) || (int) $data['version_code'] < 1 || ! is_string( $data['version_name'] ?? null ) || ! preg_match( '/\A[a-fA-F0-9]{64}\z/D', $data['sha256'] ?? '' ) || wp_parse_url( $data['apk_url'] ?? '', PHP_URL_SCHEME ) !== 'https' || wp_parse_url( $data['apk_url'] ?? '', PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			add_settings_error( 'digitalogic_mobile_update_manifest', 'invalid_manifest', 'فایل انتشار معتبر و قابل دسترس نیست. ابتدا انتشار امضاشده را روی سرور قرار دهید.' );
			return (string) get_option( 'digitalogic_mobile_update_manifest', '' );
		}
		return $url;
	}

	public static function sanitize_merchandising( $input ): array {
		$slides = array();
		foreach ( array_slice( is_array( $input ) ? $input : array(), 0, 6 ) as $slide ) {
			if ( ! is_array( $slide ) ) {
				continue;
			}
			$title = sanitize_text_field( $slide['title'] ?? '' );
			$image = esc_url_raw( $slide['image'] ?? '', array( 'https' ) );
			$url   = esc_url_raw( $slide['url'] ?? '', array( 'https' ) );
			if ( $title === '' || $image === '' || $url === '' || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				continue;
			}
			$slides[] = array( 'title' => $title, 'image' => $image, 'url' => $url );
		}
		return $slides;
	}

	public static function settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$slides = get_option( 'digitalogic_mobile_merchandising', array() );
		echo '<div class="wrap" dir="rtl"><h1>ویترین اپ دیجیتالاجیک</h1><p>بنرها پس از تازه‌سازی در اپ نمایش داده می‌شوند. ردیف خالی نمایش داده نمی‌شود. لینک مقصد باید متعلق به همین فروشگاه باشد.</p><form action="options.php" method="post">';
		settings_fields( 'digitalogic_mobile' );
		echo '<p><input type="hidden" name="digitalogic_mobile_native_auth" value="0"><label><input type="checkbox" name="digitalogic_mobile_native_auth" value="1" ' . checked( get_option( 'digitalogic_mobile_native_auth', false ), true, false ) . '> ورود و حساب بومی اپ</label></p><p>نشست‌های اپ از ورود مرورگر و پنل مستقل‌اند. ورود پیامکی از تنظیمات فعلی سرویس پیامک استفاده می‌کند.</p>';
		echo '<p><label>نشانی فایل انتشار اپ (HTTPS)<br><input dir="ltr" class="large-text" type="url" name="digitalogic_mobile_update_manifest" value="' . esc_attr( get_option( 'digitalogic_mobile_update_manifest', '' ) ) . '"></label></p><p>تنها پس از انتشار APK امضاشده و فایل انتشار معتبر، به‌روزرسانی به کاربران پیشنهاد می‌شود.</p>';
		for ( $index = 0; $index < 6; $index++ ) {
			echo '<fieldset style="padding:16px;margin:12px 0;background:white;border:1px solid #ddd"><legend>بنر ' . esc_html( (string) ( $index + 1 ) ) . '</legend>';
			foreach ( array( 'title' => 'عنوان', 'image' => 'نشانی تصویر HTTPS', 'url' => 'لینک مقصد' ) as $key => $label ) {
				echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="' . ( $key === 'title' ? 'text' : 'url' ) . '" name="digitalogic_mobile_merchandising[' . esc_attr( (string) $index ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $slides[ $index ][ $key ] ?? '' ) . '"></label></p>';
			}
			echo '</fieldset>';
		}
		submit_button( 'ذخیره ویترین' );
		echo '</form></div>';
	}
}
