<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Filoquent;

it('exposes a semantic source version', function () {
    expect(Filoquent::VERSION)->toMatch('/^\d+\.\d+\.\d+(?:-(?:alpha|beta)\.\d+)?$/');
});
