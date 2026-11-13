<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Tests\Support\TestDatabase;
use Symfony\Component\Process\Process;

it('rejects unsupported full-suite database engines', function (): void {
    expect(fn () => TestDatabase::configuration('sqlsrv'))->toThrow(InvalidArgumentException::class, 'must be sqlite, mysql, or pgsql');
});

it('rejects unsafe or incomplete native test endpoints before opening a connection', function (array $environment, string $message): void {
    $code = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).'; try { '.TestDatabase::class.'::configuration("mysql"); } catch (InvalidArgumentException $exception) { echo $exception->getMessage(); exit(42); }';
    $process = new Process([PHP_BINARY, '-r', $code], env: array_replace([
        'FILOQUENT_TEST_MYSQL_DATABASE' => false,
        'FILOQUENT_TEST_MYSQL_HOST' => false,
        'FILOQUENT_TEST_MYSQL_SOCKET' => false,
        'FILOQUENT_TEST_MYSQL_USERNAME' => false,
    ], $environment));
    $process->run();

    expect($process->getExitCode())->toBe(42)
        ->and($process->getOutput())->toContain($message);
})->with([
    'missing database' => [[], 'FILOQUENT_TEST_MYSQL_DATABASE must explicitly identify'],
    'application database' => [['FILOQUENT_TEST_MYSQL_DATABASE' => 'production'], 'must start with filoquent_'],
    'injected database identifier' => [['FILOQUENT_TEST_MYSQL_DATABASE' => 'filoquent_test;DROP DATABASE production'], 'must start with filoquent_'],
    'missing account' => [['FILOQUENT_TEST_MYSQL_DATABASE' => 'filoquent_test'], 'FILOQUENT_TEST_MYSQL_USERNAME must explicitly identify'],
    'missing endpoint' => [['FILOQUENT_TEST_MYSQL_DATABASE' => 'filoquent_test', 'FILOQUENT_TEST_MYSQL_USERNAME' => 'filoquent'], 'FILOQUENT_TEST_MYSQL_HOST must explicitly identify'],
]);
