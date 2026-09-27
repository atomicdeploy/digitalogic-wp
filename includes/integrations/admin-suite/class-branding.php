<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Branding {

	public const ADMIN_STYLE   = 'digitalogic-admin-admin';
	private const LOGIN_STYLE  = 'digitalogic-admin-login';
	private const THEME_SCRIPT = 'digitalogic-admin-theme';
	private const STORAGE_KEY  = 'digitalogic-admin-theme-v1';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ) );
		add_action( 'login_enqueue_scripts', array( self::class, 'enqueue_login' ) );
		add_action( 'admin_bar_menu', array( self::class, 'add_theme_toggle' ), 90 );
		add_action( 'login_footer', array( self::class, 'render_login_toggle' ) );
		add_action( 'login_footer', array( self::class, 'restore_login_start_position' ), 1000 );

		add_filter( 'admin_body_class', array( self::class, 'admin_body_class' ) );
		add_filter( 'login_body_class', array( self::class, 'login_body_class' ) );
		add_filter( 'login_headerurl', array( self::class, 'login_header_url' ) );
		add_filter( 'login_headertext', array( self::class, 'login_header_text' ) );
	}

	public static function enqueue_admin(): void {
		self::enqueue_style( self::ADMIN_STYLE, 'assets/css/admin-suite.css' );
		self::enqueue_theme_script();
	}

	public static function enqueue_login(): void {
		self::enqueue_style( self::LOGIN_STYLE, 'assets/css/admin-suite-login.css' );
		self::add_dynamic_variables( self::LOGIN_STYLE );
		self::enqueue_theme_script();
	}

	public static function admin_body_class( string $classes ): string {
		return trim( $classes . ' digitalogic-admin-screen' );
	}

	/** @param string[] $classes
	 *  @return string[]
	 */
	public static function login_body_class( array $classes ): array {
		$classes[] = 'digitalogic-admin-login';

		if ( Brand::logo_url() !== '' ) {
			$classes[] = 'digitalogic-admin-has-logo';
		}

		return array_values( array_unique( $classes ) );
	}

	public static function login_header_url(): string {
		return home_url( '/' );
	}

	public static function login_header_text(): string {
		return (string) get_bloginfo( 'name' );
	}

	public static function add_theme_toggle( \WP_Admin_Bar $admin_bar ): void {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$design = Brand::design();
		$logo   = Brand::logo_url();
		$brand  = '<span class="digitalogic-admin-brand">';
		if ( $logo !== '' ) {
			$brand .= '<img src="' . esc_url( $logo ) . '" alt="" aria-hidden="true">';
		}
		$brand .= '<span>' . esc_html( $design['name'] ) . '</span></span>';

		$admin_bar->remove_node( 'wp-logo' );
		$admin_bar->add_node(
			array(
				'id'    => 'digitalogic-admin-brand',
				'title' => $brand,
				'href'  => home_url( '/' ),
				'meta'  => array(
					'class' => 'digitalogic-admin-brand-node',
					'title' => esc_attr( (string) get_bloginfo( 'name' ) ),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'digitalogic-admin-theme-toggle',
				'parent' => 'top-secondary',
				'title'  => '<span class="digitalogic-admin-theme-control" data-digitalogic-admin-theme-label>حالت نمایش</span>',
				'href'   => '#',
				'meta'   => array(
					'class' => 'digitalogic-admin-theme-toggle',
					'title' => 'تغییر حالت روشن و تیره',
					'html'  => false,
				),
			)
		);
	}

	public static function render_login_toggle(): void {
		echo '<button type="button" class="digitalogic-admin-login-theme-toggle" ';
		echo 'data-digitalogic-admin-theme-toggle aria-label="تغییر حالت روشن و تیره">';
		echo '<span aria-hidden="true">◐</span> <span data-digitalogic-admin-theme-label>حالت نمایش</span>';
		echo '</button>';
	}

	/** Keep an automatic core-field focus from scrolling past the OTP panel. */
	public static function restore_login_start_position(): void {
		if ( isset( $_GET['action'] ) && sanitize_key( wp_unslash( $_GET['action'] ) ) !== 'login' ) {
			return;
		}

		echo '<script>(function(){function digitalogicAdminLoginStart(){';
		echo 'var active=document.activeElement;';
		echo 'if(active&&active.id==="user_login"&&typeof active.blur==="function"){active.blur();}';
		echo 'window.scrollTo(0,0);';
		echo '}if(document.readyState==="complete"){setTimeout(digitalogicAdminLoginStart,350);}';
		echo 'else{window.addEventListener("load",function(){setTimeout(digitalogicAdminLoginStart,350);},{once:true});}';
		echo '}());</script>';
	}

	private static function enqueue_style( string $handle, string $relative_path ): void {
		$path = DIGITALOGIC_PLUGIN_DIR . $relative_path;
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_style( $handle, DIGITALOGIC_PLUGIN_URL . $relative_path, array(), (string) filemtime( $path ) );
	}

	private static function add_dynamic_variables( string $handle ): void {
		$design       = Brand::design();
		$declarations = array(
			'--digitalogic-admin-primary:' . $design['primary'],
			'--digitalogic-admin-accent:' . $design['accent'],
			'--digitalogic-admin-surface:' . $design['surface'],
			'--digitalogic-admin-font:' . $design['font'],
		);

		$logo = Brand::logo_url();
		if ( $logo !== '' ) {
			$safe_logo      = str_replace( array( '"', "\r", "\n" ), array( '%22', '', '' ), esc_url_raw( $logo ) );
			$declarations[] = '--digitalogic-admin-logo:url("' . $safe_logo . '")';
		}

		wp_add_inline_style(
			$handle,
			'body.digitalogic-admin-login{' . implode( ';', $declarations ) . '}'
		);
	}

	private static function enqueue_theme_script(): void {
		$path = DIGITALOGIC_PLUGIN_DIR . 'assets/js/admin-suite-theme-toggle.js';
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_script(
			self::THEME_SCRIPT,
			DIGITALOGIC_PLUGIN_URL . 'assets/js/admin-suite-theme-toggle.js',
			array(),
			(string) filemtime( $path ),
			false
		);

		wp_add_inline_script(
			self::THEME_SCRIPT,
			'window.DigitalogicAdminTheme=' . wp_json_encode(
				array(
					'storageKey' => self::STORAGE_KEY,
					'labels'     => array(
						'light'  => 'حالت روشن',
						'dark'   => 'حالت تیره',
						'toggle' => 'تغییر حالت روشن و تیره',
					),
				)
			) . ';',
			'before'
		);
	}
}
