<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Filoquent;
use ArtisanToolbox\Filoquent\FiloquentServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('registers Filoquent explicitly in the container', function () {
    expect(app()->bound(Filoquent::class))->toBeTrue();
});

it('returns the same instance from the container', function () {
    expect(resolve(Filoquent::class))->toBe(resolve(Filoquent::class));
});

it('registers cleanup commands without a path relocation command', function (): void {
    expect(Artisan::all())->toHaveKeys(['filoquent:prune', 'filoquent:gc'])
        ->not->toHaveKey('filoquent:move-subpath');
});

it('merges the package config', function () {
    expect(config('filoquent.base_path'))->toBe('assets');
});

it('preserves application configuration overrides', function () {
    config(['filoquent.base_path' => 'application-assets']);

    (new FiloquentServiceProvider(app()))->register();

    expect(config('filoquent.base_path'))->toBe('application-assets');
});

it('publishes the optional configuration without replacing application settings', function (string $tag) {
    $directory = sys_get_temp_dir().'/filoquent-config-'.bin2hex(random_bytes(8));
    mkdir($directory);
    app()->useConfigPath($directory);
    (new FiloquentServiceProvider(app()))->boot();

    try {
        expect(Artisan::call('vendor:publish', ['--tag' => $tag]))->toBe(0);
        expect(require $directory.'/filoquent.php')->toBe([
            'filesystem' => null,
            'base_path' => 'assets',
            'redis' => ['database' => null, 'connection' => 'default'],
        ]);
        file_put_contents($directory.'/filoquent.php', "<?php return ['base_path' => 'application-assets'];");

        expect(Artisan::call('vendor:publish', ['--tag' => $tag]))->toBe(0);
        expect(require $directory.'/filoquent.php')->toBe(['base_path' => 'application-assets']);
    } finally {
        File::delete($directory.'/filoquent.php');
        rmdir($directory);
    }
})->with(['filoquent', 'filoquent-config']);
