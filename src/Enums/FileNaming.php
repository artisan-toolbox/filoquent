<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Enums;

enum FileNaming: string
{
    case Uuid = 'uuid';
    case Original = 'original';
}
