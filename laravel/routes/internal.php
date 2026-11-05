<?php

use Illuminate\Support\Facades\Route;
use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use Digitalogic\Laravel\Http\Controllers\MobileCheckoutController;

Route::get( '/mobile/bootstrap', array( MobileCatalogController::class, 'bootstrap' ) );
Route::get( '/mobile/categories', array( MobileCatalogController::class, 'categories' ) );
Route::get( '/mobile/products', array( MobileCatalogController::class, 'products' ) );
Route::get( '/mobile/products/{id}', array( MobileCatalogController::class, 'product' ) )->whereNumber( 'id' );
Route::post( '/mobile/checkout', array( MobileCheckoutController::class, 'create' ) );
Route::post( '/mobile/checkout/preview', array( MobileCheckoutController::class, 'preview' ) );
Route::post( '/mobile/checkout/redeem', array( MobileCheckoutController::class, 'redeem' ) );

Route::get(
	'/bridge/status',
	static function () {
		return response()->json(
			array(
				'mode'           => 'in_process',
				'laravel'        => app()->version(),
				'wordpress'      => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : null,
				'plugins_loaded' => function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) > 0,
				'woocommerce'    => function_exists( 'wc_get_product' ),
			)
		);
	}
);
