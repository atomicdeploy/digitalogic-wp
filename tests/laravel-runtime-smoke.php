<?php

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The smoke test verifies preservation of the PHP error handler.

declare(strict_types=1);

// Fresh-process proof of Laravel-first then direct WordPress bootstrap. The WP
// fixture implements normal plugin functions; production WordPress is not run.
define( 'DIGITALOGIC_WORDPRESS_LOAD', __DIR__ . '/fixtures/laravel-wp-load.php' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

function checkRuntime( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

checkRuntime( ! defined( 'ABSPATH' ), 'Composer eagerly loaded WordPress.' );
checkRuntime( ! function_exists( '__' ), 'Laravel claimed the WordPress translation symbol.' );
checkRuntime( \Digitalogic\Laravel\Application::shared() === null, 'Composer eagerly booted Laravel.' );
$handler = static fn (): bool => false;
set_error_handler( $handler );
$app     = digitalogic_laravel();
checkRuntime( ! defined( 'ABSPATH' ), 'Laravel eagerly loaded WordPress.' );
checkRuntime( $app->hasBeenBootstrapped(), 'Laravel providers did not boot.' );
$wordpress = digitalogic_wordpress();
checkRuntime( $wordpress->loaded(), 'Reverse include did not load WordPress.' );
checkRuntime( ( $GLOBALS['table_prefix'] ?? null ) === 'digitalogic_boot_test_', 'Reverse bootstrap lost the WordPress table prefix.' );
checkRuntime( function_exists( '__' ), 'WordPress translation symbol is missing.' );
checkRuntime( __( 'WordPress translation' ) === 'WordPress translation', 'WordPress translation function was replaced.' );
checkRuntime( $app === Digitalogic_Laravel_Bridge::instance()->boot_laravel(), 'WordPress created another Laravel application.' );
checkRuntime( $app === digitalogic_integrated_runtime()['laravel'], 'Mutual bootstrap changed containers.' );
$calculator = $app->make( \Digitalogic\Pricing\Calculator::class );
checkRuntime( $calculator::class === \Digitalogic\Pricing\Calculator::class, 'Laravel uses another pricing implementation.' );
checkRuntime( set_error_handler( $handler ) === $handler, 'Laravel replaced the global PHP error handler.' );
restore_error_handler();
restore_error_handler();
echo "Laravel-first and WordPress mutual bootstrap: passed\n";
