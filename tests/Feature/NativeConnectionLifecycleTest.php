<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Tests\Support\TestDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

it('closes every native database session after each application lifecycle', function (): void {
    $configuration = TestDatabase::configuration();

    if ($configuration === null) {
        $this->markTestSkipped('An explicitly configured isolated native database is required.');
    }

    $observer = resolve('db.factory')->make($configuration)->getPdo();
    $sessions = $observer->prepare($configuration['driver'] === 'pgsql'
        ? 'SELECT COUNT(*) FROM pg_stat_activity WHERE datname = ?'
        : 'SELECT COUNT(*) FROM information_schema.processlist WHERE DB = ?');
    $retainedConnections = [];
    $teardownQueries = 0;

    for ($cycle = 0; $cycle < 4; $cycle++) {
        $primary = DB::connection('testing');
        $primary->selectOne('SELECT 1', [], false);
        config(['database.connections.lifecycle_alias' => $configuration + ['read' => [], 'write' => []]]);
        $alias = DB::connection('lifecycle_alias');
        $alias->selectOne('SELECT 1', [], false);
        $alias->selectOne('SELECT 1');
        $retainedConnections[] = $primary;
        $retainedConnections[] = $alias;

        $sessions->execute([$configuration['database']]);
        expect((int) $sessions->fetchColumn())->toBe(4);

        $this->beforeApplicationDestroyed(static function () use ($primary, &$teardownQueries): void {
            $primary->selectOne('SELECT 1', [], false);
            $teardownQueries++;
        });

        $this->reloadApplication();
        DB::connection('testing')->selectOne('SELECT 1', [], false);

        $deadline = microtime(true) + 2;

        do {
            $sessions->execute([$configuration['database']]);
            $activeSessions = (int) $sessions->fetchColumn();

            if ($activeSessions === 2) {
                break;
            }

            Sleep::usleep(10000);
        } while (microtime(true) < $deadline);

        expect($activeSessions)->toBe(2)
            ->and($teardownQueries)->toBe($cycle + 1);
    }

    foreach ($retainedConnections as $connection) {
        expect($connection->getRawPdo())->toBeNull()
            ->and($connection->getRawReadPdo())->toBeNull();
    }
});
