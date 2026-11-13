<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Enums;

enum UploadState: string
{
    case Writing = 'writing';
    case Active = 'active';
    case Pending = 'pending';
    case Retired = 'retired';
}
