<?php
/**
 * Build-time namespace scoping for the pinned JWT verification dependency.
 *
 * @package Digitalogic
 */

$package = dirname( __DIR__ ) . '/vendor/firebase/php-jwt';
if ( ! is_dir( $package . '/src' ) ) {
	throw new RuntimeException( 'Install locked Composer dependencies first.' ); }
$target = $package . '/digitalogic-scoped';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) {
	throw new RuntimeException( 'Cannot prepare scoped JWT library.' ); }
foreach ( glob( $package . '/src/*.php' ) as $source ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
	$code = file_get_contents( $source );
	if ( ! str_contains( $code, 'namespace Firebase\\JWT;' ) ) {
		throw new RuntimeException( 'JWT namespace changed; review the dependency update.' ); }
	$code = str_replace( 'Firebase\\JWT', 'Digitalogic\\Vendor\\Firebase\\JWT', $code );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Standalone build/test fixture; WordPress filesystem transport is not loaded.
	if ( file_put_contents( $target . '/' . basename( $source ), $code ) === false ) {
		throw new RuntimeException( 'Cannot write scoped JWT library.' ); }
}
