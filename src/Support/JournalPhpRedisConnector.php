<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Redis;

/** @internal */
final class JournalPhpRedisConnector extends PhpRedisConnector
{
    /** @param array<string, mixed> $config */
    protected function createClient(array $config): Redis
    {
        $client = parent::createClient($config);

        // PhpRedis can return false from SELECT and keep the previous database.
        if ($client->select($config['database']) !== true) {
            $client->close();

            throw new FiloquentException('The Redis server rejected FILOQUENT_REDIS_DB. No journal writes were attempted.');
        }

        return $client;
    }
}
