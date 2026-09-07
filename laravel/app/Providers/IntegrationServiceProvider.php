<?php

namespace Digitalogic\Laravel\Providers;

use Digitalogic\Laravel\WordPressRuntime;
use Digitalogic\Pricing\Calculator;
use Illuminate\Support\ServiceProvider;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WordPressRuntime::class, static fn (): WordPressRuntime => new WordPressRuntime());
        $this->app->alias(WordPressRuntime::class, 'digitalogic.wordpress');
        $this->app->singleton(Calculator::class);
    }
}
