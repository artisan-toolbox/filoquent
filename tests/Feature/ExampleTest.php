<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Filoquent;

it('resolves the singleton', function () {
    expect(app(Filoquent::class))->toBeInstanceOf(Filoquent::class);
});

it('returns the same instance from the container', function () {
    expect(app(Filoquent::class))->toBe(app(Filoquent::class));
});

it('merges the package config', function () {
    expect(config('filoquent.placeholder'))->toBe('default');
});
