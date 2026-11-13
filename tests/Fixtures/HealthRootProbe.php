<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Fixtures;

final class HealthRootProbe
{
    public mixed $context = null;

    public static int $calls = 0;

    public function url_stat(string $path, int $flags): false
    {
        self::$calls++;

        return false;
    }
}
