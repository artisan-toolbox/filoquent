<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use Illuminate\Support\ServiceProvider;

class FiloquentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filoquent.php', 'filoquent');

        $this->app->singleton(Filoquent::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/filoquent.php' => config_path('filoquent.php'),
        ], ['filoquent', 'filoquent-config']);
    }
}
