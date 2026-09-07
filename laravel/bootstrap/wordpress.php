<?php

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	throw new RuntimeException( 'The Digitalogic Composer runtime is unavailable.' );
}

require_once $autoload;

return digitalogic_wordpress();
