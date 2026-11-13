<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use ArtisanToolbox\Filoquent\Console\Commands\GarbageCollectCommand;
use ArtisanToolbox\Filoquent\Console\Commands\HealthCommand;
use ArtisanToolbox\Filoquent\Console\Commands\MakeMiddlewareCommand;
use ArtisanToolbox\Filoquent\Console\Commands\PruneCommand;
use ArtisanToolbox\Filoquent\Console\Commands\ResolvePendingCommand;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

class FiloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filoquent.php', 'filoquent');

        $this->app->singleton(Filoquent::class);
        $this->app->singleton(RedisUploadJournal::class);
    }

    public function boot(): void
    {
        Blueprint::macro('filoquent', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->json($column);
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/filoquent.php' => config_path('filoquent.php'),
        ], ['filoquent', 'filoquent-config']);

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'filoquent-migrations');

        $this->commands([PruneCommand::class, GarbageCollectCommand::class, HealthCommand::class, ResolvePendingCommand::class, MakeMiddlewareCommand::class]);
    }
}
