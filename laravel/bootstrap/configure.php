<?php

use Digitalogic\Laravel\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__ . '/../routes/internal.php', apiPrefix: '_digitalogic')
    ->withMiddleware(static function (Middleware $middleware): void {
        // WordPress owns sessions and capabilities; no Laravel session middleware.
        $middleware->use([]);
        $middleware->group('api', []);
    })
    ->withExceptions()
    ->create();
