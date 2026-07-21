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

	/**
	 * Render a complete localized error document.
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

		nocache_headers();
		status_header( $status );
		wp_enqueue_style(
			'digitalogic-panel-error',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/panel-error.css',
			array(),
			DIGITALOGIC_VERSION
		);

		include DIGITALOGIC_PLUGIN_DIR . 'includes/panel/views/error.php';
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
		$status = in_array( (int) $status, array( 400, 401, 403, 404, 500, 503 ), true ) ? (int) $status : 500;
		$locale = determine_locale();
		$is_fa  = 0 === strpos( strtolower( (string) $locale ), 'fa' );
		$copy   = self::copy( $status, $is_fa );
		$user   = wp_get_current_user();

		$defaults = array(
			'eyebrow' => $copy['eyebrow'],
			'title'   => $copy['title'],
			'detail'  => $copy['detail'],
			'actions' => self::default_actions( $status, $is_fa ),
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
	 * @param int  $status HTTP status.
	 * @param bool $is_fa  Whether Persian copy is required.
	 * @return array
	 */
	private static function copy( $status, $is_fa ) {
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

		$set = $is_fa ? $persian : $english;
		return array_merge(
			$set[ $status ],
			array(
				'signed_in' => $is_fa ? 'واردشده با حساب %s' : 'Signed in as %s',
				'reference' => $is_fa ? 'کد پیگیری' : 'Reference',
			)
		);
	}

	/**
	 * Build safe recovery actions for the current authentication state.
	 *
	 * @param int  $status HTTP status.
	 * @param bool $is_fa  Whether Persian labels are required.
	 * @return array
	 */
	private static function default_actions( $status, $is_fa ) {
		$panel_url = home_url( '/panel/' );
		$actions   = array();

		if ( 401 === $status || ! is_user_logged_in() ) {
			$actions[] = array(
				'label'   => $is_fa ? 'ورود به پنل' : 'Sign in to the panel',
				'url'     => wp_login_url( $panel_url ),
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
