<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/** @internal */
class RedisUploadJournal
{
    private ?Connection $connection = null;

    public static function enabled(): bool
    {
        return config('filoquent.redis.database') !== null;
    }

    public function ping(): void
    {
        $reply = $this->client()->ping();

        throw_unless($reply === true || in_array((string) $reply, ['PONG', '+PONG'], true), FiloquentException::class, 'The Redis upload journal did not respond to its connection probe.');
    }

    public function count(string $scope): int
    {
        $count = $this->client()->zcard($this->index($scope));

        throw_unless(is_int($count) && $count >= 0, FiloquentException::class, 'Unable to count Redis upload intents.');

        return $count;
    }

    public function record(UploadIntent $intent): void
    {
        try {
            $result = $this->evaluate(<<<'LUA'
    if redis.call('EXISTS', KEYS[1]) ~= 0 then return 0 end
    local indexType = redis.call('TYPE', KEYS[2]).ok
    if indexType ~= 'none' and indexType ~= 'zset' then return redis.error_reply('The upload journal index is not a sorted set.') end
    if redis.call('ZSCORE', KEYS[2], ARGV[3]) ~= false then return 0 end
    redis.call('ZADD', KEYS[2], ARGV[2], ARGV[3])
    redis.call('SET', KEYS[1], ARGV[1])
    return 1
    LUA, 2, $this->key($intent->scope, $intent->id), $this->index($intent->scope), $intent->encode(), $this->now(), $intent->id);

            throw_if($result === 0, FiloquentException::class, 'The upload generation is already registered in the Redis journal.');

            throw_if($result !== 1, FiloquentException::class, 'Unable to atomically register the Redis upload intent.');
        } catch (Throwable $exception) {
            throw $exception instanceof FiloquentException ? $exception : new FiloquentException('Unable to register the Redis upload intent.', previous: $exception);
        }
    }

    /** @return array<array-key, string|null> */
    public function due(string $scope, int $limit): array
    {
        $ids = $this->client()->zrangebyscore($this->index($scope), '-inf', (string) $this->now(), ['limit' => [0, $limit]]);

        if ($ids === []) {
            return [];
        }

        $values = $this->client()->mget(array_map(fn (string $id): string => $this->key($scope, $id), $ids));
        $records = [];
        foreach ($ids as $offset => $id) {
            $value = $values[$offset];
            $records[$id] = is_string($value) ? $value : null;
        }

        return $records;
    }

    public function current(string $scope, string $id, string $payload): bool
    {
        return $this->client()->get($this->key($scope, $id)) === $payload;
    }

    public function acknowledge(string $scope, string $id, string $payload): void
    {
        $result = $this->evaluate(<<<'LUA'
if redis.call('MGET', KEYS[1])[1] ~= ARGV[1] then return 0 end
local indexType = redis.call('TYPE', KEYS[2]).ok
if indexType ~= 'none' and indexType ~= 'zset' then return redis.error_reply('The upload journal index is not a sorted set.') end
redis.call('DEL', KEYS[1])
redis.call('ZREM', KEYS[2], ARGV[2])
return 1
LUA, 2, $this->key($scope, $id), $this->index($scope), $payload, $id);

        throw_if($result !== 0 && $result !== 1, FiloquentException::class, 'Unable to acknowledge the Redis upload intent.');
    }

    public function retry(string $scope, string $id, ?string $payload): void
    {
        $result = $this->evaluate(<<<'LUA'
local current = redis.call('MGET', KEYS[1])[1]
if ARGV[1] == 'missing' then
    if current ~= false then return 0 end
elseif current ~= ARGV[2] then return 0 end
if redis.call('ZSCORE', KEYS[2], ARGV[3]) == false then return 0 end
redis.call('ZADD', KEYS[2], 'XX', ARGV[4], ARGV[3])
return 1
LUA, 2, $this->key($scope, $id), $this->index($scope), $payload === null ? 'missing' : 'present', $payload ?? '', $id, $this->now() + 60);

        throw_if($result !== 0 && $result !== 1, FiloquentException::class, 'Unable to reschedule the Redis upload intent.');
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $database = config('filoquent.redis.database');

        throw_if((! is_int($database) && (! is_string($database) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $database)))
            || filter_var($database, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false, FiloquentException::class, 'FILOQUENT_REDIS_DB must be a nonnegative integer or null to disable the journal.');

        $name = config('filoquent.redis.connection', 'default');
        $redis = config('database.redis', []);

        throw_if(! is_string($name) || ! is_array($redis) || isset($redis['clusters'][$name]), FiloquentException::class, 'The upload journal requires a standalone Redis connection. Redis Cluster is unsupported.');

        $base = $redis[$name] ?? null;

        throw_if(! is_array($base) && ! is_string($base), FiloquentException::class, 'The base Redis connection for the upload journal is not configured.');

        $parsed = (new ConfigurationUrlParser)->parseConfiguration($base);
        $driver = strtolower($parsed['driver'] ?? '');

        if (in_array($driver, ['tcp', 'tls'], true)) {
            $parsed['scheme'] = $driver;
        }
        unset($parsed['url'], $parsed['driver']);
        $parsed['database'] = (int) $database;
        $options = $redis['options'] ?? [];
        $parameters = $options['parameters'][$name] ?? $options['parameters'] ?? [];
        $options['parameters'] = array_replace($parameters, ['database' => (int) $database, 'persistent' => false]);
        unset($options['parameters']['persistent_id']);

        // Both connectors merge nested options after the connection configuration.
        $parsed['persistent'] = false;
        unset($parsed['persistent_id']);
        $parsed['options'] = array_replace($parsed['options'] ?? [], ['database' => (int) $database, 'persistent' => false]);
        unset($parsed['options']['persistent_id']);

        if (isset($parsed['options']['parameters'])) {
            $parsed['options']['parameters'] = array_replace($parsed['options']['parameters'], ['database' => (int) $database, 'persistent' => false]);
            unset($parsed['options']['parameters']['persistent_id']);
        }
        $options['persistent'] = false;
        unset($options['persistent_id']);

        $client = $redis['client'] ?? 'phpredis';

        throw_unless(in_array($client, ['phpredis', 'predis'], true), FiloquentException::class, 'The upload journal supports Laravel phpredis and predis clients.');

        if ($client === 'predis') {
            $options['exceptions'] = $parsed['options']['exceptions'] = true;
        }

        if ($client === 'phpredis') {
            // Lua stores raw JSON, independently of the application's value codec.
            $options['serializer'] = $parsed['serializer'] = $parsed['options']['serializer'] = 0;
            $options['compression'] = $parsed['compression'] = $parsed['options']['compression'] = 0;
        }

        return ['client' => $client, 'options' => $options, 'default' => $parsed];
    }

    private function evaluate(string $script, int $keyCount, string|float ...$arguments): mixed
    {
        try {
            $connection = $this->client();

            return $connection instanceof PhpRedisConnection
                ? $connection->eval($script, $keyCount, ...$arguments)
                : $connection->command('eval', [$script, $keyCount, ...$arguments]);
        } catch (Throwable $exception) {
            throw $exception instanceof FiloquentException ? $exception : new FiloquentException('The Redis upload journal command failed.', previous: $exception);
        }
    }

    private function client(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        throw_unless(class_exists(RedisManager::class), FiloquentException::class, 'Install illuminate/redis and configure a Laravel Redis client to enable the upload journal.');

        $config = $this->configuration();

        try {
            $manager = new RedisManager(app(), $config['client'], $config);

            if ($config['client'] === 'phpredis') {
                $manager->extend('phpredis', fn (): JournalPhpRedisConnector => new JournalPhpRedisConnector);
            }

            return $this->connection = $manager->connection();
        } catch (Throwable $exception) {
            throw new FiloquentException('Unable to connect to the Redis upload journal.', previous: $exception);
        }
    }

    private function index(string $scope): string
    {
        return 'filoquent:uploads:'.hash('sha256', (string) config('app.env')).':'.$scope.':due';
    }

    private function key(string $scope, string $id): string
    {
        return 'filoquent:uploads:'.hash('sha256', (string) config('app.env')).':'.$scope.':'.$id;
    }

    private function now(): float
    {
        return CarbonImmutable::now('UTC')->getTimestampMs() / 1000;
    }
}
