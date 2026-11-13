<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Enums;

enum OwnerTableResolution: string
{
    case Renamed = 'renamed';
    case Removed = 'removed';
}
