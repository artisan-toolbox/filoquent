<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps full analysis with platform-safe concurrency through either profile', function (string $profile): void {
    $root = dirname(__DIR__, 2);
    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/bin/phpstan',
        'dump-parameters',
        '--configuration='.$profile,
        '--json',
    ], $root, ['PAO_DISABLE' => '1']);
    $process->setTimeout(60);
    $process->mustRun();
    $parameters = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($parameters['level'])->toBe(7)
        ->and(array_map(realpath(...), $parameters['paths']))->toBe([
            realpath($root.'/src'),
            realpath($root.'/config'),
            realpath($root.'/tests'),
        ])
        ->and(array_map(realpath(...), $parameters['bootstrapFiles']))->toContain(realpath($root.'/vendor/larastan/larastan/bootstrap.php'));

    if (PHP_OS_FAMILY === 'Windows') {
        expect($parameters['parallel']['maximumNumberOfProcesses'])->toBe(1);
    } else {
        expect($parameters['parallel']['maximumNumberOfProcesses'])->not->toBe(1);
    }
})->with(['phpstan.neon', 'phpstan.neon.dist']);
