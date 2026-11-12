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

it('merges the package config', function () {
    expect(config('filoquent.placeholder'))->toBe('default');
});

it('preserves application configuration overrides', function () {
    config(['filoquent.placeholder' => 'application-value']);

    (new FiloquentServiceProvider(app()))->register();

    expect(config('filoquent.placeholder'))->toBe('application-value');
});

it('publishes the optional configuration without replacing application settings', function (string $tag) {
    $directory = sys_get_temp_dir().'/filoquent-config-'.bin2hex(random_bytes(8));
    mkdir($directory);
    app()->useConfigPath($directory);
    (new FiloquentServiceProvider(app()))->boot();

    try {
        expect(Artisan::call('vendor:publish', ['--tag' => $tag]))->toBe(0);
        expect(require $directory.'/filoquent.php')->toBe(['placeholder' => 'default']);
        file_put_contents($directory.'/filoquent.php', "<?php return ['placeholder' => 'application-value'];");

        expect(Artisan::call('vendor:publish', ['--tag' => $tag]))->toBe(0);
        expect(require $directory.'/filoquent.php')->toBe(['placeholder' => 'application-value']);
    } finally {
        File::delete($directory.'/filoquent.php');
        rmdir($directory);
    }
})->with(['filoquent', 'filoquent-config']);
