<?php

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digits_Login {

	private static bool $registered = false;
	private static bool $rendered   = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		// Keep WordPress's canonical login/reset controller and password
		// fallback while Digits owns OTP rendering and verification.
		remove_action( 'init', 'digits_redirect_wp_login', 5 );
		remove_action( 'login_init', 'digits_redirect_wp_login', 5 );

		add_filter( 'login_message', array( self::class, 'add_native_form' ), 20 );
	}

	public static function is_present(): bool {
		if (
			function_exists( 'df_digits_form_login' )
			|| function_exists( 'digits_render_new_form' )
			|| shortcode_exists( 'digits_login' )
		) {
			return true;
		}

		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

		return in_array( 'digits/digit.php', $active, true ) || isset( $network_active['digits/digit.php'] );
	}

	public static function add_native_form( string $message ): string {
		if ( self::$rendered || ! self::is_login_get_request() || ! self::is_present() ) {
			return $message;
		}

		$native_form = self::native_form_html();
		if ( $native_form === '' ) {
			return $message;
		}

		self::$rendered = true;

		return $message
			. '<section class="digitalogic-admin-digits-login" aria-labelledby="digitalogic-admin-digits-title">'
			. '<h2 id="digitalogic-admin-digits-title">ورود با شماره موبایل و رمز یک‌بارمصرف</h2>'
			. $native_form
			. '</section>'
			. '<p class="digitalogic-admin-login-divider"><span>'
			. 'یا با نام کاربری و رمز عبور وارد شوید.'
			. '</span></p>';
	}

	private static function is_login_get_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'login';

		return $action === '' || $action === 'login';
	}

	private static function native_form_html(): string {
		$initial_buffer_level = ob_get_level();

		try {
			if ( shortcode_exists( 'digits_login' ) ) {
				$html = trim( (string) do_shortcode( '[digits_login]' ) );
				if ( $html !== '' ) {
					return $html;
				}
			}

			if ( function_exists( 'df_digits_form_login' ) ) {
				ob_start();
				$returned = df_digits_form_login();
				$echoed   = (string) ob_get_clean();
				$html     = trim( $echoed . ( is_string( $returned ) ? $returned : '' ) );

				if ( $html !== '' ) {
					return $html;
				}
			}

			if ( function_exists( 'digits_render_new_form' ) ) {
				ob_start();
				digits_render_new_form(
					array(
						'page_type'   => 'login',
						'login_title' => __( 'Log In' ),
					)
				);

				return trim( (string) ob_get_clean() );
			}
		} catch ( \Throwable $error ) {
			while ( ob_get_level() > $initial_buffer_level ) {
				ob_end_clean();
			}

			return '';
		}

		return '';
	}
}
