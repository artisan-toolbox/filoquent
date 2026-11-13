<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Enums;

enum OwnerTableState: string
{
    case Ready = 'ready';
    case Pending = 'pending';
}
