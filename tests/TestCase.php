<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests;

use ArtisanToolbox\Filoquent\FiloquentServiceProvider;
use ArtisanToolbox\Filoquent\Tests\Support\TestDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $configuration = TestDatabase::configuration();

        if ($configuration !== null) {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', $configuration);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (TestDatabase::configuration() !== null) {
            $connection = $this->app['db']->connection('testing');
            $schema = $connection->getSchemaBuilder();

            if ($connection->getDriverName() === 'pgsql') {
                foreach ($schema->getSchemas() as $namespace) {
                    $connection->statement('DROP SCHEMA "'.str_replace('"', '""', $namespace['name']).'" CASCADE');
                }
                $connection->statement('CREATE SCHEMA public');
            } else {
                $schema->dropAllViews();
                $schema->dropAllTables();
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            FiloquentServiceProvider::class,
        ];
    }
}
