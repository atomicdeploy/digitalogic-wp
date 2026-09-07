<?php

use Illuminate\Support\Facades\Route;

Route::get('/bridge/status', static function () {
    return response()->json([
        'mode' => 'in_process',
        'laravel' => app()->version(),
        'wordpress' => function_exists('get_bloginfo') ? get_bloginfo('version') : null,
        'plugins_loaded' => function_exists('did_action') && did_action('plugins_loaded') > 0,
        'woocommerce' => function_exists('wc_get_product'),
    ]);
});
