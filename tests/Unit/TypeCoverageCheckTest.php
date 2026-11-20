<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('rejects incomplete type coverage when Pest returns a successful result', function (): void {
    $files = new Filesystem;
    $root = dirname(__DIR__, 2);
    $project = sys_get_temp_dir().'/atb-type-coverage-'.bin2hex(random_bytes(8));

    try {
        $files->ensureDirectoryExists($project.'/vendor/bin');
        $files->ensureDirectoryExists($project.'/tests/Support');
        $files->ensureDirectoryExists($project.'/src');
        $script = '/tests/Support/check-type-coverage.php';
        $files->copy($root.$script, $project.$script);
        $files->put($project.'/vendor/autoload.php', '<?php require '.var_export($root.'/vendor/autoload.php', true).';');
        $files->put($project.'/src/Reported.php', '<?php');
        $files->put($project.'/src/Missing.php', '<?php');
        $files->put($project.'/vendor/bin/pest', <<<'PHP'
            <?php

            $report = substr($argv[array_key_last($argv)], strlen('--type-coverage-json='));
            file_put_contents($report, '{"result":[{"file":"src/Reported.php","percentage":100}]}');
            PHP);

        $process = new Process([PHP_BINARY, $project.$script], $project);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Type coverage is incomplete. Missing files: src/Missing.php');
    } finally {
        $files->deleteDirectory($project);
    }
});
