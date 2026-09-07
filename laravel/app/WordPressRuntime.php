<?php

namespace Digitalogic\Laravel;

use RuntimeException;

final class WordPressRuntime {

	private static bool $booting = false;

	private static ?string $loadPath = null;

	public function boot(): self {
		if ( $this->loaded() ) {
			self::$loadPath = defined( 'ABSPATH' )
				? rtrim( (string) ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . 'wp-load.php'
				: self::$loadPath;
			return $this;
		}

		if ( self::$booting ) {
			throw new RuntimeException( 'WordPress is already being booted by this PHP request.' );
		}

		$loadPath      = $this->resolveLoadPath();
		self::$booting = true;

		try {
			// wp-config assigns this value; wp-settings reads its global binding.
			// Including from a method must preserve that normal file-scope behavior.
			global $table_prefix;
			require_once $loadPath;
		} finally {
			self::$booting = false;
		}

		if ( ! $this->loaded() ) {
			throw new RuntimeException( 'wp-load.php returned without a usable WordPress runtime.' );
		}

		self::$loadPath = $loadPath;
		return $this;
	}

	public function loaded(): bool {
		return defined( 'ABSPATH' )
			&& function_exists( 'get_bloginfo' )
			&& function_exists( 'add_action' );
	}

	public function pluginsLoaded(): bool {
		return $this->loaded()
			&& function_exists( 'did_action' )
			&& did_action( 'plugins_loaded' ) > 0;
	}

	public function loadPath(): ?string {
		return self::$loadPath;
	}

	private function resolveLoadPath(): string {
		$candidates = array();

		if ( defined( 'DIGITALOGIC_WORDPRESS_LOAD' ) ) {
			$candidates[] = (string) DIGITALOGIC_WORDPRESS_LOAD;
		}

		if ( isset( $_SERVER['DIGITALOGIC_WORDPRESS_LOAD'] ) && is_string( $_SERVER['DIGITALOGIC_WORDPRESS_LOAD'] ) ) {
			$candidates[] = $_SERVER['DIGITALOGIC_WORDPRESS_LOAD'];
		}
		if ( isset( $_ENV['DIGITALOGIC_WORDPRESS_LOAD'] ) && is_string( $_ENV['DIGITALOGIC_WORDPRESS_LOAD'] ) ) {
			$candidates[] = $_ENV['DIGITALOGIC_WORDPRESS_LOAD'];
		}

		$pluginRoot   = dirname( __DIR__, 2 );
		$candidates[] = dirname( $pluginRoot, 3 ) . DIRECTORY_SEPARATOR . 'wp-load.php';

		foreach ( array_unique( $candidates ) as $candidate ) {
			$candidate = rtrim( (string) $candidate, '/\\' );
			if ( is_dir( $candidate ) ) {
				$candidate .= DIRECTORY_SEPARATOR . 'wp-load.php';
			}

			$realPath = realpath( $candidate );
			if ( $realPath !== false && is_file( $realPath ) && is_readable( $realPath ) ) {
				return $realPath;
			}
		}

		throw new RuntimeException( 'Unable to locate a readable WordPress wp-load.php.' );
	}
}
