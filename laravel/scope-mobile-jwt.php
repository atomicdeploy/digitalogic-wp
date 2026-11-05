<?php
/** Scope the locked JWT library to avoid older WordPress plugin class collisions. */
$package = dirname( __DIR__ ) . '/vendor/firebase/php-jwt';
if ( ! is_dir( $package . '/src' ) ) {
	throw new RuntimeException( 'Install locked Composer dependencies first.' ); }
$target = $package . '/digitalogic-scoped';
if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) {
	throw new RuntimeException( 'Cannot prepare scoped JWT library.' ); }
foreach ( glob( $package . '/src/*.php' ) as $source ) {
	$code = file_get_contents( $source );
	if ( ! str_contains( $code, 'namespace Firebase\\JWT;' ) ) {
		throw new RuntimeException( 'JWT namespace changed; review the dependency update.' ); }
	$code = str_replace( 'Firebase\\JWT', 'Digitalogic\\Vendor\\Firebase\\JWT', $code );
	if ( file_put_contents( $target . '/' . basename( $source ), $code ) === false ) {
		throw new RuntimeException( 'Cannot write scoped JWT library.' ); }
}
