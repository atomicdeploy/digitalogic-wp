<?php

use Digitalogic\Laravel\WordPressRuntime;
use Digitalogic\Laravel\Application;

if (!function_exists('digitalogic_wordpress')) {
    /** Boot WordPress and all active plugins in the current PHP process. */
    function digitalogic_wordpress(): WordPressRuntime
    {
        $app = Application::shared();
        if ($app !== null) {
            return $app->make(WordPressRuntime::class)->boot();
        }

        return (new WordPressRuntime())->boot();
    }
}

if (!function_exists('digitalogic_laravel')) {
    /** Lazily boot or return the shared Laravel application. */
    function digitalogic_laravel(): Application
    {
        return Application::bootShared();
    }
}

if (!function_exists('digitalogic_integrated_runtime')) {
    /** Boot both runtimes and return their request-local handles. */
    function digitalogic_integrated_runtime(): array
    {
        $wordpress = digitalogic_wordpress();
        $laravel = digitalogic_laravel();

        return [
            'wordpress' => $wordpress,
            'laravel' => $laravel,
        ];
    }
}
