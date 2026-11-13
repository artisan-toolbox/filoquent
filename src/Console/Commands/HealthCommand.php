<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Console\Commands;

use ArtisanToolbox\Filoquent\Support\CommandRun;
use ArtisanToolbox\Filoquent\Support\HealthInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\Progress;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

#[Description('Check files referenced by committed upload owners without changing models, receipts, or storage')]
#[Signature('filoquent:health
                            {--connection= : Database connection containing the upload ledger and owners}
                            {--limit= : Maximum uploads to examine, between 1 and 10000; omitted checks the full starting range}
                            {--after= : Start after this upload UUIDv7}')]
final class HealthCommand extends Command
{
    public function handle(): int
    {
        return CommandRun::execute($this, $this->input, function (CommandRun $run): int {
            $value = $this->option('limit');
            $limit = $value === null ? null : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
            $after = $this->option('after');

            if ($limit === false || ($after !== null && ! CommandRun::isCanonicalUploadId($after))) {
                $this->error('Supply a limit from 1 to 10000 and a canonical lowercase UUIDv7 cursor.');
                $run->issue('invalid_options');

                return self::INVALID;
            }

            try {
                $connection = DB::connection($this->option('connection'));
                $run->connection = $connection->getName();

                if ($connection->transactionLevel() !== 0) {
                    $this->error('Health checks must run outside a database transaction.');
                    $run->issue('outer_transaction');

                    return self::FAILURE;
                }

                /** @var Progress<int>|null $progress */
                $progress = null;
                $interactive = $this->interactiveProgress();

                if ($interactive) {
                    intro('Filoquent file health');
                }
                $previous = [];
                $async = null;

                if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                    $async = pcntl_async_signals(true);
                    foreach ([SIGINT, SIGTERM] as $signal) {
                        $previous[$signal] = pcntl_signal_get_handler($signal);
                        pcntl_signal($signal, function () use ($run): void {
                            $run->interrupted = true;
                        });
                    }
                }

                try {
                    $exit = (new HealthInspector($connection))->scan($limit, $after, $run, function (string $event, int $size) use (&$progress, $interactive, $run): void {
                        if ($event === 'start' && $interactive) {
                            $progress = new Progress('Checking this batch of '.$size.' uploads', $size, 'Examined '.$run->counts['uploads_examined'].' uploads so far.');
                            $progress->render();
                        } elseif ($event === 'advance') {
                            $progress?->hint('Examined '.$run->counts['uploads_examined'].' uploads so far.')->advance();
                        } elseif ($event === 'finish') {
                            $progress?->finish();
                            $progress = null;

                            if (! $interactive) {
                                $this->line('Examined '.$run->counts['uploads_examined'].' uploads so far.');
                            }
                        }
                    });
                } finally {
                    foreach ($previous as $signal => $handler) {
                        pcntl_signal($signal, $handler);
                    }

                    if ($async !== null) {
                        pcntl_async_signals($async);
                    }
                    $progress?->finish();
                }

                $counts = $run->counts;

                if ($interactive) {
                    $rows = [];
                    foreach ($counts as $counter => $count) {
                        $rows[] = [ucfirst(str_replace('_', ' ', $counter)), (string) $count];
                    }
                    table(['Counter', 'Total'], $rows);
                    outro($run->interrupted ? 'Health check interrupted.' : 'Health check finished.');
                } else {
                    $this->info('Uploads examined '.$counts['uploads_examined'].'; files checked '.$counts['files_checked'].'; present '.$counts['files_present'].'; missing '.$counts['files_missing'].'; inconclusive files '.$counts['files_inconclusive'].'; skipped uploads '.$counts['uploads_skipped'].'; inconclusive uploads '.$counts['uploads_inconclusive'].'.');
                }
                $this->line('Coverage '.($run->partial ? 'partial' : 'full starting range').'; range '.($run->rangeExhausted ? 'exhausted' : 'not exhausted').'; cursor '.($run->cursor ?? 'none').'; upper bound '.($run->upperBound ?? 'none').'.');

                if ($run->interrupted) {
                    $this->error('Health check interrupted. Resume after the reported cursor or run a fresh full check.');
                }

                return $exit;
            } catch (Throwable) {
                $run->completed = false;
                $run->partial = true;
                $run->issue('health_failed');
                $this->error('Health check could not finish. Check the database connection and upload ledger.');

                return self::FAILURE;
            }
        });
    }

    private function interactiveProgress(): bool
    {
        $output = $this->output->getOutput();

        return $this->input->isInteractive() && $output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL
            && $output->isDecorated() && $output instanceof StreamOutput
            && defined('STDIN') && stream_isatty(STDIN) && stream_isatty($output->getStream())
            && ! windows_os();
    }
}
