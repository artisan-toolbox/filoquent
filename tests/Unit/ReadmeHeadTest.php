<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Filoquent;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;

it('exposes the managed package identity', function () {
    $package = new Filoquent;

    expect((new ReflectionClass(Filoquent::class))->getInterfaceNames())
        ->toContain(Versionable::class, WithReadmeHead::class);

    $head = $package->readmeHead();

    expect($head->title())->toBe('Filoquent')
        ->and($head->description())->toContain('File attributes for Eloquent')
        ->and($head->logo())->toBe(['src' => 'logo/filoquent-icon.svg', 'alt' => '', 'width' => 88])
        ->and(dirname(__DIR__, 2).'/logo/filoquent-icon.svg')->toBeFile()
        ->and(array_map(static fn (Badge $badge): string => $badge->imgAlt(), $head->badges()))
        ->toBe(['Beta', 'PHP', 'Laravel', 'Tests', 'Docs']);
});
