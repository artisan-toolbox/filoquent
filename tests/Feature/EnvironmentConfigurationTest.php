<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\FiloquentServiceProvider;

it('loads environment settings while preserving defaults and application overrides', function (array $environment, array $overrides, array $expected): void {
    $keys = ['FILOQUENT_FILESYSTEM', 'FILOQUENT_BASE_PATH', 'FILOQUENT_REDIS_DB', 'FILOQUENT_REDIS_CONNECTION'];
    $original = [];
    $originalEnvironment = $_ENV;
    $originalServer = $_SERVER;
    $configuration = config('filoquent');

    foreach ($keys as $key) {
        $original[$key] = getenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        foreach ($environment as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        config(['filoquent' => $overrides]);
        (new FiloquentServiceProvider(app()))->register();

        expect(config('filoquent'))->toBe($expected);
    } finally {
        $_ENV = $originalEnvironment;
        $_SERVER = $originalServer;

        foreach ($original as $key => $process) {
            putenv($process === false ? $key : $key.'='.$process);
        }

        config(['filoquent' => $configuration]);
    }
})->with([
    'default settings' => [[], [], [
        'filesystem' => null,
        'base_path' => 'assets',
        'redis' => ['database' => null, 'connection' => 'default'],
    ]],
    'every environment setting' => [[
        'FILOQUENT_FILESYSTEM' => 'media',
        'FILOQUENT_BASE_PATH' => 'uploads',
        'FILOQUENT_REDIS_DB' => '2',
        'FILOQUENT_REDIS_CONNECTION' => 'uploads',
    ], [], [
        'filesystem' => 'media',
        'base_path' => 'uploads',
        'redis' => ['database' => '2', 'connection' => 'uploads'],
    ]],
    'explicit null fallback and disabled recovery' => [[
        'FILOQUENT_FILESYSTEM' => 'null',
        'FILOQUENT_REDIS_DB' => 'null',
    ], [], [
        'filesystem' => null,
        'base_path' => 'assets',
        'redis' => ['database' => null, 'connection' => 'default'],
    ]],
    'application settings take precedence' => [[
        'FILOQUENT_FILESYSTEM' => 'media',
        'FILOQUENT_BASE_PATH' => 'uploads',
        'FILOQUENT_REDIS_DB' => '2',
        'FILOQUENT_REDIS_CONNECTION' => 'uploads',
    ], [
        'filesystem' => null,
        'base_path' => 'application-assets',
        'redis' => ['database' => 0, 'connection' => 'application-redis'],
    ], [
        'filesystem' => null,
        'base_path' => 'application-assets',
        'redis' => ['database' => 0, 'connection' => 'application-redis'],
    ]],
]);
