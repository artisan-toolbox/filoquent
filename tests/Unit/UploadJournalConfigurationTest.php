<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;

it('derives a private connection after parsing URL credentials TLS and database', function (): void {
    config([
        'filoquent.redis.database' => '2',
        'filoquent.redis.connection' => 'uploads',
        'database.redis' => [
            'client' => 'phpredis',
            'options' => ['prefix' => 'application:', 'persistent' => true, 'persistent_id' => 'shared', 'serializer' => 1, 'compression' => 1,
                'parameters' => ['uploads' => ['database' => 5, 'persistent' => true, 'persistent_id' => 'parameters']]],
            'uploads' => ['url' => 'tls://journal-user:secret@redis.example:6380/7?database=8', 'database' => 1,
                'persistent' => true, 'persistent_id' => 'base', 'context' => ['ssl' => ['verify_peer' => true]],
                'options' => ['persistent' => true, 'persistent_id' => 'nested', 'database' => 9,
                    'parameters' => ['database' => 6, 'persistent' => true, 'persistent_id' => 'nested-parameters']]],
        ],
    ]);
    $original = config('database.redis');
    $configuration = (new RedisUploadJournal)->configuration();
    expect($configuration['default'])->toMatchArray([
        'scheme' => 'tls', 'host' => 'redis.example', 'port' => 6380, 'username' => 'journal-user', 'password' => 'secret',
        'database' => 2, 'persistent' => false, 'context' => ['ssl' => ['verify_peer' => true]], 'serializer' => 0, 'compression' => 0,
    ])->not->toHaveKeys(['url', 'persistent_id'])
        ->and($configuration['default']['options']['persistent'])->toBeFalse()
        ->and($configuration['default']['options']['parameters'])->toBe(['database' => 2, 'persistent' => false])
        ->and($configuration['options']['parameters'])->toBe(['database' => 2, 'persistent' => false])
        ->and($configuration['options']['prefix'])->toBe('application:')
        ->and(config('database.redis'))->toBe($original);
});

it('accepts zero and positive Redis database numbers', function (int|string $value): void {
    config(['filoquent.redis.database' => $value, 'database.redis.default' => ['host' => 'localhost', 'port' => 6379]]);
    expect((new RedisUploadJournal)->configuration()['default']['database'])->toBe((int) $value);
})->with([0, '0', 2, '15']);

it('rejects ambiguous invalid and negative Redis database numbers', function (mixed $value): void {
    config(['filoquent.redis.database' => $value]);
    expect(fn (): array => (new RedisUploadJournal)->configuration())->toThrow(FiloquentException::class, 'nonnegative integer');
})->with([-1, '-1', '', '2.0', '02', true, 2.1, '99999999999999999999999']);

it('rejects Redis Cluster without attempting a connection', function (): void {
    config(['filoquent.redis.database' => 2, 'database.redis' => ['clusters' => ['default' => [['host' => 'localhost']]]]]);
    expect(fn (): array => (new RedisUploadJournal)->configuration())->toThrow(FiloquentException::class, 'Cluster');
});

it('forces server errors to throw on the private Predis client', function (): void {
    config([
        'filoquent.redis.database' => 2,
        'database.redis' => ['client' => 'predis', 'options' => ['exceptions' => false],
            'default' => ['host' => 'localhost', 'options' => ['exceptions' => false]]],
    ]);
    $configuration = (new RedisUploadJournal)->configuration();
    expect($configuration['options']['exceptions'])->toBeTrue()
        ->and($configuration['default']['options']['exceptions'])->toBeTrue()
        ->and(config('database.redis.options.exceptions'))->toBeFalse()
        ->and(config('database.redis.default.options.exceptions'))->toBeFalse();
});
