<?php
/**
 * Standalone branded error pages for Digitalogic-owned browser routes.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders plugin-owned browser errors without the generic WordPress die page.
 */
final class Digitalogic_Panel_Error_Page {

	/** Default panel authorization context. */
	public const CONTEXT_PANEL = 'panel';

	/** Patris report administration context. */
	public const CONTEXT_PATRIS_REPORTS = 'patris-reports';

	/** Product metadata diagnostics context. */
	public const CONTEXT_PRODUCT_DIAGNOSTICS = 'product-diagnostics';

	/** Digitalogic UI settings context. */
	public const CONTEXT_UI_SETTINGS = 'ui-settings';

	/** Public comment reputation context. */
	public const CONTEXT_COMMENT_GUARD = 'comment-guard';

	/** Private storefront request-file download context. */
	public const CONTEXT_REQUEST_DOWNLOAD = 'request-download';

	/** Standalone document rendering mode. */
	public const MODE_DOCUMENT = 'document';

	/** WordPress-admin-safe embedded rendering mode. */
	public const MODE_ADMIN_EMBEDDED = 'admin-embedded';

	/**
	 * Render a localized browser error response.
	 *
	 * This intentionally avoids wp_die() so plugin errors never fall back to the
	 * generic WordPress error screen or expose a Query Monitor call stack.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Stable support reference.
	 * @param string $message Optional message override.
	 * @param array  $args    Optional title, eyebrow, actions, and detail values.
	 * @return void
	 */
	public static function render( $status, $code, $message = '', $args = array() ) {
		$status = in_array( (int) $status, array( 400, 401, 403, 404, 500, 503 ), true ) ? (int) $status : 500;
		$view   = self::view_model( $status, $code, $message, $args );

		if ( self::MODE_DOCUMENT === $view['mode'] ) {
			nocache_headers();
			status_header( $status );
		}
		wp_enqueue_style(
			'digitalogic-panel-error',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/panel-error.css',
			array(),
			DIGITALOGIC_VERSION
		);

		include DIGITALOGIC_PLUGIN_DIR . 'includes/panel/views/error.php';
	}

	/**
	 * Render a localized error inside the existing WordPress admin document.
	 *
	 * Admin page callbacks run after the admin header. This mode deliberately
	 * avoids emitting a second doctype, html, head, or body element.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Stable support reference.
	 * @param string $message Optional message override.
	 * @param array  $args    Optional contextual view values.
	 * @return void
	 */
	public static function render_admin( $status, $code, $message = '', $args = array() ) {
		$args['mode'] = self::MODE_ADMIN_EMBEDDED;
		self::render( $status, $code, $message, $args );
	}

	/**
	 * Build an escaped-at-output view model for the error template.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Stable support reference.
	 * @param string $message Optional message override.
	 * @param array  $args    Optional overrides.
	 * @return array
	 */
	public static function view_model( $status, $code, $message = '', $args = array() ) {
		$status  = in_array( (int) $status, array( 400, 401, 403, 404, 500, 503 ), true ) ? (int) $status : 500;
		$locale  = determine_locale();
		$is_fa   = 0 === strpos( strtolower( (string) $locale ), 'fa' );
		$context = isset( $args['context'] ) ? sanitize_key( (string) $args['context'] ) : self::CONTEXT_PANEL;
		$mode    = isset( $args['mode'] ) && self::MODE_ADMIN_EMBEDDED === $args['mode'] ? self::MODE_ADMIN_EMBEDDED : self::MODE_DOCUMENT;
		$copy    = self::copy( $status, $is_fa, $context );
		$user    = wp_get_current_user();

		$defaults = array(
			'eyebrow' => $copy['eyebrow'],
			'title'   => $copy['title'],
			'detail'  => $copy['detail'],
			'actions' => self::default_actions( $status, $is_fa, $context ),
		);
		$args     = wp_parse_args( $args, $defaults );

		if ( '' !== trim( (string) $message ) ) {
			$args['detail'] = $message;
		}

		$reference = strtoupper( preg_replace( '/[^A-Za-z0-9_-]+/', '-', (string) $code ) );
		$reference = trim( $reference, '-' );
		if ( '' === $reference ) {
			$reference = 'PANEL-ERROR';
		}

		$display_name = '';
		if ( is_user_logged_in() && is_object( $user ) ) {
			$display_name = trim( (string) ( $user->display_name ?? $user->user_login ?? '' ) );
		}

		$view = array(
			'status'          => (int) $status,
			'code'            => 'DG-' . $reference,
			'locale'          => str_replace( '_', '-', (string) $locale ),
			'direction'       => $is_fa ? 'rtl' : 'ltr',
			'context'         => $context,
			'mode'            => $mode,
			'eyebrow'         => (string) $args['eyebrow'],
			'title'           => (string) $args['title'],
			'detail'          => (string) $args['detail'],
			'actions'         => is_array( $args['actions'] ) ? $args['actions'] : array(),
			'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'logo_url'        => DIGITALOGIC_PLUGIN_URL . 'assets/images/icon.svg',
			'style_handle'    => 'digitalogic-panel-error',
			'signed_in_label' => $display_name ? sprintf( $copy['signed_in'], $display_name ) : '',
			'reference_label' => $copy['reference'],
		);

		return (array) apply_filters( 'digitalogic_panel_error_view_model', $view, $status, $code );
	}

	/**
	 * Return complete English or Persian copy for a supported status.
	 *
	 * @param int    $status  HTTP status.
	 * @param bool   $is_fa   Whether Persian copy is required.
	 * @param string $context Browser error context.
	 * @return array
	 */
	private static function copy( $status, $is_fa, $context ) {
		$english = array(
			400 => array(
				'eyebrow' => 'Invalid request',
				'title'   => 'We could not process this request',
				'detail'  => 'Check the requested address or values and try again.',
			),
			401 => array(
				'eyebrow' => 'Sign-in required',
				'title'   => 'Please sign in to continue',
				'detail'  => 'The Digitalogic panel uses your existing WordPress session.',
			),
			403 => array(
				'eyebrow' => 'Access restricted',
				'title'   => 'This account does not have panel access',
				'detail'  => 'You are signed in, but this WordPress account is not assigned store-management access. Ask a site administrator or sign in with another account.',
			),
			404 => array(
				'eyebrow' => 'Page not found',
				'title'   => 'That Digitalogic page is not here',
				'detail'  => 'The address may have changed, or the page may no longer be available.',
			),
			500 => array(
				'eyebrow' => 'Unexpected error',
				'title'   => 'Digitalogic could not complete this request',
				'detail'  => 'Nothing was changed. Try again, or share the reference below with support.',
			),
			503 => array(
				'eyebrow' => 'Temporarily unavailable',
				'title'   => 'The Digitalogic service is not ready',
				'detail'  => 'The service is starting or undergoing maintenance. Please try again shortly.',
			),
		);
		$persian = array(
			400 => array(
				'eyebrow' => 'درخواست نامعتبر',
				'title'   => 'امکان پردازش این درخواست نبود',
				'detail'  => 'نشانی یا اطلاعات واردشده را بررسی کنید و دوباره تلاش کنید.',
			),
			401 => array(
				'eyebrow' => 'نیاز به ورود',
				'title'   => 'برای ادامه وارد حساب شوید',
				'detail'  => 'پنل دیجیتالوجیک از همان نشست وردپرس شما استفاده می‌کند.',
			),
			403 => array(
				'eyebrow' => 'دسترسی محدود',
				'title'   => 'این حساب به پنل دسترسی ندارد',
				'detail'  => 'شما وارد شده‌اید، اما این حساب وردپرس دسترسی مدیریت فروشگاه را ندارد. با مدیر سایت هماهنگ کنید یا با حساب دیگری وارد شوید.',
			),
			404 => array(
				'eyebrow' => 'صفحه پیدا نشد',
				'title'   => 'این صفحه دیجیتالوجیک در دسترس نیست',
				'detail'  => 'ممکن است نشانی صفحه تغییر کرده باشد یا دیگر در دسترس نباشد.',
			),
			500 => array(
				'eyebrow' => 'خطای پیش‌بینی‌نشده',
				'title'   => 'انجام این درخواست ممکن نشد',
				'detail'  => 'هیچ تغییری انجام نشد. دوباره تلاش کنید یا کد پیگیری زیر را برای پشتیبانی بفرستید.',
			),
			503 => array(
				'eyebrow' => 'موقتاً در دسترس نیست',
				'title'   => 'سرویس دیجیتالوجیک هنوز آماده نیست',
				'detail'  => 'سرویس در حال راه‌اندازی یا نگهداری است. کمی بعد دوباره تلاش کنید.',
			),
		);

		$set     = $is_fa ? $persian : $english;
		$context = self::context_copy( $status, $is_fa, $context );
		return array_merge(
			$set[ $status ],
			$context,
			array(
				'signed_in' => $is_fa ? 'واردشده با حساب %s' : 'Signed in as %s',
				'reference' => $is_fa ? 'کد پیگیری' : 'Reference',
			)
		);
	}

	/**
	 * Return localized copy for plugin-owned forbidden-page contexts.
	 *
	 * @param int    $status  HTTP status.
	 * @param bool   $is_fa   Whether Persian copy is required.
	 * @param string $context Browser error context.
	 * @return array
	 */
	private static function context_copy( $status, $is_fa, $context ) {
		if ( 404 === (int) $status && self::CONTEXT_REQUEST_DOWNLOAD === $context ) {
			return $is_fa
				? array(
					'eyebrow' => 'فایل پیدا نشد',
					'title'   => 'فایل این درخواست دیگر در دسترس نیست',
					'detail'  => 'ممکن است فایل پیوست حذف یا جابه‌جا شده باشد. برای بررسی درخواست به پیشخوان وردپرس بازگردید.',
				)
				: array(
					'eyebrow' => 'File not found',
					'title'   => 'This request file is no longer available',
					'detail'  => 'The attachment may have been removed or moved. Return to the WordPress dashboard to review the request.',
				);
		}

		if ( 403 !== (int) $status ) {
			return array();
		}

		$english = array(
			self::CONTEXT_PATRIS_REPORTS      => array(
				'eyebrow' => 'Access restricted',
				'title'   => 'Patris reports are not available to this account',
				'detail'  => 'This page requires store-management access. Ask a site administrator to grant the appropriate WordPress capability.',
			),
			self::CONTEXT_PRODUCT_DIAGNOSTICS => array(
				'eyebrow' => 'Access restricted',
				'title'   => 'Product diagnostics are not available to this account',
				'detail'  => 'This page contains store diagnostics and requires store-management access.',
			),
			self::CONTEXT_UI_SETTINGS         => array(
				'eyebrow' => 'Administrator access required',
				'title'   => 'Digitalogic interface settings are restricted',
				'detail'  => 'Only a WordPress administrator can change the Digitalogic interface settings.',
			),
			self::CONTEXT_COMMENT_GUARD       => array(
				'eyebrow' => 'Comment blocked',
				'title'   => 'We could not accept this comment',
				'detail'  => 'Your comment could not be accepted from this network.',
			),
			self::CONTEXT_REQUEST_DOWNLOAD    => array(
				'eyebrow' => 'Download restricted',
				'title'   => 'This request file is protected',
				'detail'  => 'Only an authorized store manager can download private request attachments.',
			),
		);
		$persian = array(
			self::CONTEXT_PATRIS_REPORTS      => array(
				'eyebrow' => 'دسترسی محدود',
				'title'   => 'این حساب به گزارش‌های پاتریس دسترسی ندارد',
				'detail'  => 'این صفحه به دسترسی مدیریت فروشگاه نیاز دارد. از مدیر سایت بخواهید مجوز مناسب وردپرس را به حساب شما اختصاص دهد.',
			),
			self::CONTEXT_PRODUCT_DIAGNOSTICS => array(
				'eyebrow' => 'دسترسی محدود',
				'title'   => 'این حساب به عیب‌یابی محصولات دسترسی ندارد',
				'detail'  => 'این صفحه شامل اطلاعات عیب‌یابی فروشگاه است و به دسترسی مدیریت فروشگاه نیاز دارد.',
			),
			self::CONTEXT_UI_SETTINGS         => array(
				'eyebrow' => 'نیازمند دسترسی مدیر',
				'title'   => 'تنظیمات نمای دیجیتالوجیک محدود است',
				'detail'  => 'فقط مدیر وردپرس می‌تواند تنظیمات نمای دیجیتالوجیک را تغییر دهد.',
			),
			self::CONTEXT_COMMENT_GUARD       => array(
				'eyebrow' => 'دیدگاه مسدود شد',
				'title'   => 'امکان پذیرش این دیدگاه نبود',
				'detail'  => 'ارسال دیدگاه از این شبکه پذیرفته نشد.',
			),
			self::CONTEXT_REQUEST_DOWNLOAD    => array(
				'eyebrow' => 'دانلود محدود است',
				'title'   => 'فایل این درخواست محافظت‌شده است',
				'detail'  => 'فقط مدیر مجاز فروشگاه می‌تواند فایل‌های پیوست خصوصی درخواست‌ها را دانلود کند.',
			),
		);
		$set     = $is_fa ? $persian : $english;

		return isset( $set[ $context ] ) ? $set[ $context ] : array();
	}

	/**
	 * Build safe recovery actions for the current authentication state.
	 *
	 * @param int    $status  HTTP status.
	 * @param bool   $is_fa   Whether Persian labels are required.
	 * @param string $context Browser error context.
	 * @return array
	 */
	private static function default_actions( $status, $is_fa, $context ) {
		$panel_url = home_url( '/panel/' );
		$actions   = array();

		if ( self::CONTEXT_COMMENT_GUARD === $context ) {
			$actions[] = array(
				'label'   => $is_fa ? 'بازگشت به فروشگاه' : 'Return to the store',
				'url'     => home_url( '/' ),
				'primary' => true,
			);

			return $actions;
		}

		if ( 401 === $status || ! is_user_logged_in() ) {
			$actions[] = array(
				'label'   => $is_fa ? 'ورود به پنل' : 'Sign in to the panel',
				'url'     => wp_login_url( $panel_url ),
				'primary' => true,
			);
		} elseif ( in_array( $context, array( self::CONTEXT_PATRIS_REPORTS, self::CONTEXT_PRODUCT_DIAGNOSTICS, self::CONTEXT_UI_SETTINGS, self::CONTEXT_REQUEST_DOWNLOAD ), true ) ) {
			$actions[] = array(
				'label'   => $is_fa ? 'بازگشت به پیشخوان وردپرس' : 'Return to WordPress dashboard',
				'url'     => admin_url(),
				'primary' => true,
			);
		} elseif ( 403 === $status ) {
			$actions[] = array(
				'label'   => $is_fa ? 'ورود با حساب دیگر' : 'Use another account',
				'url'     => wp_logout_url( wp_login_url( $panel_url ) ),
				'primary' => true,
			);
			$actions[] = array(
				'label' => $is_fa ? 'نمایه وردپرس' : 'WordPress profile',
				'url'   => admin_url( 'profile.php' ),
			);
		} else {
			$actions[] = array(
				'label'   => $is_fa ? 'تلاش دوباره' : 'Try again',
				'url'     => $panel_url,
				'primary' => true,
			);
		}

		$actions[] = array(
			'label' => $is_fa ? 'بازگشت به فروشگاه' : 'Return to the store',
			'url'   => home_url( '/' ),
		);

		return $actions;
	}
}
