<?php
/**
 * Digits compatibility for the Woodmart guest login sidebar.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads a sidebar-only Digits compatibility layer.
 */
final class Digitalogic_Sidebar_Login {
	private const SCRIPT_HANDLE = 'digitalogic-sidebar-login';
	private const SCRIPT_FILE   = 'assets/js/sidebar-login.js';
	private const STYLE_HANDLE  = 'digitalogic-sidebar-login';
	private const STYLE_FILE    = 'assets/css/sidebar-login.css';

	/**
	 * Register storefront asset hooks.
	 */
	public static function init(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_sidebar_assets' ), 100 );
	}

	/**
	 * Load the final, narrowly scoped Woodmart compatibility assets.
	 */
	public static function enqueue_sidebar_assets(): void {
		if ( ! self::should_enqueue() ) {
			return;
		}

		$path = DIGITALOGIC_PLUGIN_DIR . self::STYLE_FILE;
		if ( ! is_readable( $path ) ) {
			return;
		}

		$dependencies = self::enqueued_digits_styles();

		wp_enqueue_style(
			self::STYLE_HANDLE,
			DIGITALOGIC_PLUGIN_URL . self::STYLE_FILE,
			$dependencies,
			(string) filemtime( $path )
		);

		$script_path = DIGITALOGIC_PLUGIN_DIR . self::SCRIPT_FILE;
		if ( ! is_readable( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			DIGITALOGIC_PLUGIN_URL . self::SCRIPT_FILE,
			array(),
			(string) filemtime( $script_path ),
			true
		);
	}

	/**
	 * The sidebar exists only on public pages for signed-out visitors.
	 */
	private static function should_enqueue(): bool {
		return ! is_admin() && ! is_user_logged_in();
	}

	/**
	 * Return already-enqueued Digits layers so this compatibility sheet loads last.
	 *
	 * Depending on a merely registered handle would cause WordPress to print that
	 * vendor stylesheet, including Digits' intentionally omitted global login CSS.
	 *
	 * @return array<string>
	 */
	private static function enqueued_digits_styles(): array {
		$styles = wp_styles();
		if ( ! is_object( $styles ) || ! isset( $styles->registered ) || ! is_array( $styles->registered ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array( 'digits-login-style', 'digits-style', 'digits-login-style-rtl' ),
				static fn( string $handle ): bool => isset( $styles->registered[ $handle ] ) && wp_style_is( $handle, 'enqueued' )
			)
		);
	}
}
