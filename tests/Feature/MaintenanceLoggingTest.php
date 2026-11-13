<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Tests\Fixtures\MaintenanceLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Artisan::call('migrate');
    app()->instance(MaintenanceLogger::class, new MaintenanceLogger);
    Log::swap(resolve(MaintenanceLogger::class));
});

it('logs each maintenance invocation independently of silent console output', function (string $command): void {
    expect(Artisan::call($command, ['--silent' => true]))->toBe(0)
        ->and(Artisan::output())->toBe('');
    $logger = resolve(MaintenanceLogger::class);
    expect($logger->records)->toHaveCount(2)
        ->and($logger->records[0]['level'])->toBe('info')
        ->and($logger->records[1]['level'])->toBe('info')
        ->and($logger->report())->toMatchArray(['command' => $command, 'exit_code' => 0, 'completed' => true, 'partial' => true, 'range_exhausted' => false, 'mode' => 'preview']);
})->with(['filoquent:gc', 'filoquent:prune']);

it('logs invalid maintenance options under silent output', function (string $command): void {
    expect(Artisan::call($command, ['--silent' => true, '--limit' => 0]))->toBe(2)
        ->and(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 2, 'completed' => false])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error');
})->with(['filoquent:gc', 'filoquent:prune']);

it('aborts before database or destructive work if the start logger throws', function (string $command): void {
    resolve(MaintenanceLogger::class)->beforeLog = function (): void {
        throw new RuntimeException('Secret logger configuration');
    };
    DB::shouldReceive('connection')->never();
    expect(Artisan::call($command, ['--silent' => true] + ($command === 'filoquent:health' ? [] : ['--apply' => true])))->toBe(1)
        ->and(Artisan::output())->toBe('');
})->with(['filoquent:health', 'filoquent:gc', 'filoquent:prune']);

it('returns failure without console output if completion logging fails after successful work', function (string $command): void {
    resolve(MaintenanceLogger::class)->beforeLog = function (string $message): void {
        throw_if($message === 'Filoquent maintenance completed.', RuntimeException::class, 'Secret logger configuration');
    };
    expect(Artisan::call($command, ['--silent' => true]))->toBe(1)
        ->and(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->records)->toHaveCount(1);
})->with(['filoquent:health', 'filoquent:gc', 'filoquent:prune']);

it('preserves the original prune exception when completion logging also fails', function (): void {
    $failure = new RuntimeException('Primary database failure');
    resolve(MaintenanceLogger::class)->beforeLog = function (string $message): void {
        throw_if($message === 'Filoquent maintenance completed.', RuntimeException::class, 'Secondary log failure');
    };
    DB::shouldReceive('connection')->once()->andThrow($failure);

    try {
        Artisan::call('filoquent:prune', ['--silent' => true]);
        test()->fail('Prune must preserve its exception contract.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }
});

it('records unexpected failures while preserving the existing command contracts', function (string $command): void {
    Schema::drop('filoquent_uploads');

    if ($command === 'filoquent:prune') {
        expect(fn (): int => Artisan::call($command, ['--silent' => true]))->toThrow(QueryException::class);
    } else {
        expect(Artisan::call($command, ['--silent' => true]))->toBe(1);
    }
    expect(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error')
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 1, 'completed' => false, 'partial' => true]);
})->with(['filoquent:health', 'filoquent:gc', 'filoquent:prune']);

it('redacts unsafe connection inputs from both maintenance log events', function (string $command): void {
    $name = 'mysql://private-user:private-password@private.invalid/database?signature=secret';

    if ($command === 'filoquent:prune') {
        expect(fn (): int => Artisan::call($command, ['--connection' => $name, '--silent' => true]))->toThrow(InvalidArgumentException::class);
    } else {
        expect(Artisan::call($command, ['--connection' => $name, '--silent' => true]))->toBe(1);
    }
    $logger = resolve(MaintenanceLogger::class);
    expect($logger->records[0]['context']['requested_connection'])->toBe('redacted')
        ->and($logger->report()['requested_connection'])->toBe('redacted')
        ->and(json_encode($logger->records, JSON_THROW_ON_ERROR))->not->toContain('private-password')->not->toContain('signature=secret');
})->with(['filoquent:health', 'filoquent:gc', 'filoquent:prune']);

it('rejects read-only selectors before work for every maintenance command', function (string $command): void {
    DB::shouldReceive('connection')->never();
    expect(Artisan::call($command, ['--connection' => 'database::read', '--silent' => true]))->toBe(2)
        ->and(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 2, 'completed' => false]);
    config(['database.default' => 'database::read']);
    expect(Artisan::call($command, ['--silent' => true]))->toBe(2);
})->with(['filoquent:health', 'filoquent:gc', 'filoquent:prune']);

it('rejects valueless supplied bounds rather than silently expanding their scope', function (string $command, string $option): void {
    DB::shouldReceive('connection')->never();
    expect(Artisan::call($command.' '.$option.' --silent'))->toBe(2)
        ->and(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 2, 'completed' => false]);
})->with([
    ['filoquent:health', '--limit'], ['filoquent:health', '--after'], ['filoquent:health', '--connection'],
    ['filoquent:gc', '--limit'], ['filoquent:gc', '--connection'],
    ['filoquent:prune', '--limit'], ['filoquent:prune', '--after'], ['filoquent:prune', '--connection'],
]);

it('rejects explicit empty and zero connection values instead of selecting the default writer', function (string $command): void {
    DB::shouldReceive('connection')->never();
    foreach (['', '0'] as $connection) {
        expect(Artisan::call($command, ['--connection' => $connection, '--silent' => true]))->toBe(2)
            ->and(Artisan::output())->toBe('')
            ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['completed' => false, 'exit_code' => 2]);
    }
})->with(['filoquent:health', 'filoquent:gc', 'filoquent:prune']);
