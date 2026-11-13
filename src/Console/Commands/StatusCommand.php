<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Console\Commands;

use ArtisanToolbox\Filoquent\Enums\UploadState;
use ArtisanToolbox\Filoquent\Filoquent;
use ArtisanToolbox\Filoquent\Support\CommandRun;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\StorageAccess;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

#[Description('Show Filoquent configuration, connection health, and current upload counts without changing stored data')]
#[Signature('filoquent:status {--connection= : Database writer connection containing the upload ledger}')]
final class StatusCommand extends Command
{
    public function handle(): int
    {
        return CommandRun::execute($this, $this->input, function (CommandRun $run): int {
            $this->section('Filoquent', ['Version' => Filoquent::VERSION]);
            $storage = $this->filesystem($run);
            $ledger = $this->uploads($run);
            $redis = $this->redis($run);
            $run->completed = true;
            $this->newLine();

            return $storage && $ledger && $redis ? self::SUCCESS : self::FAILURE;
        });
    }

    private function filesystem(CommandRun $run): bool
    {
        $name = config('filoquent.filesystem') ?? config('filesystems.default');
        $rows = [
            'Disk' => $name,
            'Driver' => is_string($name) ? config('filesystems.disks.'.$name.'.driver') : null,
            'Base path' => config('filoquent.base_path', 'assets'),
        ];
        $healthy = false;

        try {
            throw_unless(is_string($name) && $name !== '', RuntimeException::class);
            $disk = (new StorageAccess)->forReading($name);
            $disk->fileExists('.filoquent-status-'.Str::uuid());
            $rows['Health'] = 'Accessible (read probe)';
            $healthy = true;

            if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
                $root = $disk->getConfig()['root'];
                $readable = is_readable($root);
                $writable = is_writable($root);
                $rows['Readable'] = $readable ? 'Yes' : 'No';
                $rows['Writable'] = $writable ? 'Yes' : 'No';
                $healthy = $readable && $writable;
                $rows['Health'] = $healthy ? 'Accessible (read probe)' : 'Unavailable';
            } else {
                $rows['Writable'] = 'Not tested';
            }
        } catch (Throwable) {
            $healthy = false;
            $rows['Health'] = 'Unavailable';
        }

        if (! $healthy) {
            $run->issue('status_filesystem_unavailable');
        }
        $this->section('Filesystem', $rows);

        return $healthy;
    }

    private function uploads(CommandRun $run): bool
    {
        $rows = ['Connection' => $this->option('connection') ?? config('database.default')];

        try {
            $connection = DB::connection($this->option('connection'));
            $run->connection = $connection->getName();
            $counts = $connection->table('filoquent_uploads')->useWritePdo()
                ->select('state')->selectRaw('COUNT(*) as total')->groupBy('state')->pluck('total', 'state');
            $run->counts = [
                'recorded_uploads' => (int) $counts->sum(),
                'active_uploads' => (int) $counts->get(UploadState::Active->value, 0),
                'pending_recovery' => (int) $counts->get(UploadState::Pending->value, 0),
                'awaiting_deletion' => (int) $counts->get(UploadState::Retired->value, 0),
                'writing_reservations' => (int) $counts->get(UploadState::Writing->value, 0),
            ];
            $rows['Health'] = 'Connected';
            foreach ($run->counts as $counter => $count) {
                $rows[ucfirst(str_replace('_', ' ', $counter))] = $count;
            }
            $this->section('Upload ledger', $rows);

            return true;
        } catch (Throwable) {
            $run->issue('status_ledger_unavailable');
            $rows['Health'] = 'Unavailable';
            $rows['Action'] = 'Check the writer connection and run migrations';
            $this->section('Upload ledger', $rows);

            return false;
        }
    }

    private function redis(CommandRun $run): bool
    {
        if (! RedisUploadJournal::enabled()) {
            $this->section('Redis upload journal', ['Status' => 'Disabled']);

            return true;
        }

        $rows = ['Status' => 'Enabled', 'Connection' => config('filoquent.redis.connection', 'default')];

        try {
            $journal = resolve(RedisUploadJournal::class);
            $configuration = $journal->configuration();
            $server = $configuration['default'];
            $rows += [
                'Client' => $configuration['client'],
                'Host' => $server['host'] ?? '127.0.0.1',
                'Port' => $server['port'] ?? 6379,
                'Database' => $server['database'],
                'Scheme' => $server['scheme'] ?? 'tcp',
                'Connect timeout' => $server['timeout'] ?? 'Client default',
                'Read timeout' => $server['read_timeout'] ?? 'Client default',
                'Persistent connection' => 'Disabled',
            ];
            $journal->ping();
            $rows['Health'] = 'Connected';

            try {
                $scope = UploadLedger::scope(DB::connection($this->option('connection')));
                $run->counts['journal_intents'] = $journal->count($scope);
                $rows['Journal intents'] = $run->counts['journal_intents'];
                $healthy = true;
            } catch (Throwable) {
                $run->issue('status_journal_count_unavailable');
                $rows['Journal intents'] = 'Unknown';
                $rows['Action'] = 'Check the SQL journal namespace and Redis journal index';
                $healthy = false;
            }
            $this->section('Redis upload journal', $rows);

            return $healthy;
        } catch (Throwable) {
            $run->issue('status_journal_unavailable');
            $rows['Health'] = 'Unavailable';
            $rows['Journal intents'] = 'Unknown';
            $rows['Action'] = 'Check Redis configuration, connectivity, and SQL journal migrations';
            $this->section('Redis upload journal', $rows);

            return false;
        }
    }

    /** @param array<string, mixed> $rows */
    private function section(string $title, array $rows): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>'.$title.'</>');
        foreach ($rows as $label => $value) {
            $text = is_scalar($value) ? (string) $value : 'Not configured';
            $text = OutputFormatter::escape(substr(preg_replace('/[\x00-\x1F\x7F]/', '', $text) ?? '', 0, 500));
            $color = match ($value) {
                'Accessible (read probe)', 'Connected', 'Yes' => 'green',
                'Unavailable', 'No', 'Unknown' => 'red',
                default => 'gray',
            };
            $this->components->twoColumnDetail($label, '<fg='.$color.'>'.$text.'</>');
        }
    }
}
