<?php

return array(
	'name'            => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : 'Digitalogic',
	'env'             => 'production',
	'debug'           => false,
	'url'             => function_exists( 'home_url' ) ? home_url( '/' ) : 'http://localhost',
	'timezone'        => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC',
	'locale'          => function_exists( 'determine_locale' ) ? determine_locale() : 'en',
	'fallback_locale' => 'en',
	'cipher'          => 'AES-256-CBC',
	'key'             => null,
	'previous_keys'   => array(),
	'maintenance'     => array(
		'driver' => 'file',
	),
);
