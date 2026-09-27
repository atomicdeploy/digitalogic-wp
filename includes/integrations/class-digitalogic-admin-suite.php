<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Canonical owner for the former Digitalogic Admin plugin capabilities.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

final class Digitalogic_Admin_Suite {
	private static bool $booted = false;

	public static function init(): void {
		if ( self::$booted || class_exists( '\\DigitalogicAdmin\\Plugin', false ) ) {
			return;
		}
		self::$booted = true;

		$directory = DIGITALOGIC_PLUGIN_DIR . 'includes/integrations/admin-suite/';
		require_once $directory . 'class-brand.php';
		require_once $directory . 'class-branding.php';
		require_once $directory . 'class-digits-login.php';
		require_once $directory . 'class-user-normalizer.php';
		require_once $directory . 'class-webhooks.php';
		require_once $directory . 'class-health.php';

		\DigitalogicAdmin\Webhooks::register();
		\DigitalogicAdmin\Health::register();
		if ( \DigitalogicAdmin\Brand::ui_enabled() ) {
			\DigitalogicAdmin\Branding::register();
			\DigitalogicAdmin\Digits_Login::register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $directory . 'class-cli-command.php';
			WP_CLI::add_command( 'digitalogic-admin users', '\\DigitalogicAdmin\\Users_Command' );
		}
	}

	public static function activate(): void {
		self::init();
		\DigitalogicAdmin\Webhooks::install_options();
	}
}
