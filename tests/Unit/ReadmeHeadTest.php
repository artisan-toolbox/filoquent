<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Filoquent;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;

it('exposes the preview version and the managed package identity', function () {
    $package = new Filoquent;

    expect((new ReflectionClass(Filoquent::class))->getInterfaceNames())
        ->toContain(Versionable::class, WithReadmeHead::class)
        ->and(Filoquent::VERSION)->toBe('0.1.0-alpha.1');

    $head = $package->readmeHead();

    expect($head->title())->toBe('Filoquent')
        ->and($head->description())->toContain('Preview')
        ->and($head->logo())->toBe(['src' => 'logo/filoquent-icon.svg', 'alt' => '', 'width' => 88])
        ->and(dirname(__DIR__, 2).'/logo/filoquent-icon.svg')->toBeFile()
        ->and(array_map(static fn (Badge $badge): string => $badge->imgAlt(), $head->badges()))
        ->toBe(['Preview', 'PHP', 'Laravel', 'Tests', 'Docs']);
});
