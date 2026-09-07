<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Composer prepares one hash-verified local file before WordPress is loaded.

/**
 * WordPress owns the global __() symbol. Laravel's translator remains available
 * through trans() and its container. Prepare this once at dependency installation,
 * so either framework can load first without a PHP redeclaration fatal error.
 */
$path   = dirname( __DIR__ ) . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';
$source = file_get_contents( $path );
if ( $source === false ) {
	throw new RuntimeException( 'Laravel foundation helpers are missing from the Composer runtime.' );
}

// These identify the locked upstream file and the one reviewed transformation.
// A dependency upgrade must explicitly review this boundary before installation.
$upstreamHash = '84a94be4f68fd0f749a87f05a7cb1b78ddc07c7d2121b56c6f05ff6ef80d34c6';
$preparedHash = '167d8627a0f1f1e3cc4e150836dc221cc7eda53a33fb42b5d6ede531a2948e80';
if ( ! in_array( hash( 'sha256', $source ), array( $upstreamHash, $preparedHash ), true ) ) {
	throw new RuntimeException( 'The locked Laravel helper changed; review its WordPress translation boundary.' );
}

$marker   = '// Digitalogic: global __() is owned by WordPress; use trans() in Laravel.';
$pattern  = '/^if \(! function_exists\(\'__\'\)\) \{\R.*?^\}\R/ms';
$prepared = preg_replace( $pattern, $marker . "\n", $source, -1, $count );
if ( ! is_string( $prepared ) || ( $count !== 1 && ! str_contains( $source, $marker ) ) ) {
	throw new RuntimeException( 'Laravel translation helper layout changed; review the WordPress symbol boundary.' );
}
if ( preg_match( '/function\s+__\s*\(/', $prepared ) ) {
	throw new RuntimeException( 'Laravel still declares the WordPress translation symbol.' );
}
if ( hash( 'sha256', $prepared ) !== $preparedHash ) {
	throw new RuntimeException( 'The Laravel translation boundary did not produce its reviewed hash.' );
}
if ( $prepared !== $source && file_put_contents( $path, $prepared ) !== strlen( $prepared ) ) {
	throw new RuntimeException( 'Unable to prepare the shared Laravel/WordPress translation boundary.' );
}
fwrite( STDOUT, "Prepared Laravel helpers: WordPress owns __(); Laravel uses trans().\n" );
