<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Types;

use ArtisanToolbox\Filoquent\Asset;
use ArtisanToolbox\Filoquent\Assets;

use function PHPStan\Testing\assertType;

/** @param Assets<'original'|'thumb', object{original: Asset|null, thumb: Asset|null}> $assets */
function typedVariants(Assets $assets): void
{
    assertType('ArtisanToolbox\\Filoquent\\Asset|null', $assets->get('thumb'));
    assertType('ArtisanToolbox\\Filoquent\\Asset|null', $assets->thumb);
    assertType('ArtisanToolbox\\Filoquent\\Asset|null', $assets->regenerateMissing(['thumb'])->thumb);
}

/** @param Assets<'assets'|'context', object{assets: Asset|null, context: Asset|null}> $assets */
function variantsNamedLikeInternalState(Assets $assets): void
{
    assertType('ArtisanToolbox\\Filoquent\\Asset|null', $assets->assets);
    assertType('ArtisanToolbox\\Filoquent\\Asset|null', $assets->context);
}
