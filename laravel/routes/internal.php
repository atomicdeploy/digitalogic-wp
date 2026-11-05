<?php

use Illuminate\Support\Facades\Route;
use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use Digitalogic\Laravel\Http\Controllers\MobileIdentityController;
use Digitalogic\Laravel\Http\Controllers\MobileRequestController;
use Digitalogic\Laravel\Http\Controllers\MobileCartController;
use Digitalogic\Laravel\Http\Controllers\MobileCheckoutController;

Route::get('/mobile/checkout',array(MobileCheckoutController::class,'show'));
Route::post('/mobile/checkout',array(MobileCheckoutController::class,'submit'));
Route::post('/mobile/checkout/quote',array(MobileCheckoutController::class,'quote'));
Route::get('/mobile/checkout/operations/{id}',array(MobileCheckoutController::class,'lookup'));
Route::get('/mobile/bootstrap', array( MobileCatalogController::class, 'bootstrap' ));
Route::get('/mobile/categories', array( MobileCatalogController::class, 'categories' ));
Route::get('/mobile/products', array( MobileCatalogController::class, 'products' ));
Route::get('/mobile/products/{id}', array( MobileCatalogController::class, 'product' ))->whereNumber('id');
Route::post('/mobile/auth/otp/request', array( MobileIdentityController::class, 'requestOtp' ));
Route::post('/mobile/auth/otp/verify', array( MobileIdentityController::class, 'verifyOtp' ));
Route::post('/mobile/auth/google', array( MobileIdentityController::class, 'google' ));
Route::get('/mobile/auth/sessions', array( MobileIdentityController::class, 'sessions' ));
Route::post('/mobile/auth/logout', array( MobileIdentityController::class, 'logout' ));
Route::match(array( 'GET', 'PUT' ), '/mobile/account/profile', array( MobileIdentityController::class, 'profile' ));
Route::get('/mobile/account/orders', array( MobileIdentityController::class, 'orders' ));
Route::match(array( 'GET', 'PUT' ), '/mobile/account/favorites', array( MobileIdentityController::class, 'favorites' ));
Route::get('/mobile/forms', array(MobileRequestController::class,'forms'));
Route::post('/mobile/uploads', array(MobileRequestController::class,'createUpload'));
Route::match(array('GET','PUT','DELETE'), '/mobile/uploads/{id}', array(MobileRequestController::class,'upload'));
Route::post('/mobile/requests', array(MobileRequestController::class,'submit'));
Route::get('/mobile/requests/{id}', array(MobileRequestController::class,'lookup'));
Route::get('/mobile/account/requests', array(MobileRequestController::class,'requests'));
Route::match(array('GET','POST','PATCH','DELETE'), '/mobile/cart', array(MobileCartController::class,'cart'));
Route::post('/mobile/cart/claim', array(MobileCartController::class,'claim'));

Route::get(
    '/bridge/status',
    static function () {
        return response()->json(
            array(
                'mode'           => 'in_process',
                'laravel'        => app()->version(),
                'wordpress'      => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
                'plugins_loaded' => function_exists('did_action') && did_action('plugins_loaded') > 0,
                'woocommerce'    => function_exists('wc_get_product'),
            )
        );
    }
);
