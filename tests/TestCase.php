<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests;

use ArtisanToolbox\Filoquent\FiloquentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FiloquentServiceProvider::class,
        ];
    }
}
