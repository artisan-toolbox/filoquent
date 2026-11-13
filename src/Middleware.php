<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

abstract class Middleware
{
    /** @return PendingAsset|non-empty-list<PendingAsset> */
    abstract public function handle(Upload $file): PendingAsset|array;
}
