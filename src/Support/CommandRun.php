<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/** @internal */
final class CommandRun
{
    /** @var array<string, int> */
    public array $counts = [];

    public ?string $connection = null;

    public ?string $upperBound = null;

    public ?string $cursor = null;

    public bool $completed = false;

    public bool $partial = true;

    public bool $rangeExhausted = false;

    public bool $interrupted = false;

    /** @var list<array<string, string>> */
    private array $issues = [];

    private readonly string $id;

    private readonly int $started;

    private function __construct(private readonly Command $command)
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        $this->id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        $this->started = hrtime(true);
    }

    /** @param Closure(self): int $work */
    public static function execute(Command $command, InputInterface $input, Closure $work): int
    {
        $run = new self($command);

        try {
            Log::info('Filoquent maintenance started.', $run->context(null));
        } catch (Throwable) {
            $command->error('Could not log the maintenance start. No maintenance work ran.');

            return Command::FAILURE;
        }

        $exception = null;
        $exit = Command::FAILURE;

        try {
            $name = $command->option('connection') ?? config('database.default');
            $invalid = ! is_string($name) || ! $name || str_ends_with($name, '::read');
            foreach (['limit', 'after', 'connection'] as $option) {
                $invalid = $invalid || ($command->hasOption($option) && $input->hasParameterOption('--'.$option) && $command->option($option) === null);
            }

            if ($invalid) {
                $run->issue('invalid_options');
                $command->error('Supply option values and a database writer connection. Do not select ::read.');
                $exit = Command::INVALID;
            } else {
                $exit = $work($run);
            }
        } catch (Throwable $failure) {
            $exception = $failure;
            $run->completed = false;
            $run->partial = true;
            $run->issue('unexpected_failure');
        }

        try {
            $severity = $exception !== null ? 'error' : ($exit === Command::SUCCESS || $run->pendingTablesOnly() ? 'info' : ($run->missingOnly() ? 'warning' : 'error'));
            Log::log($severity, 'Filoquent maintenance completed.', $run->context($exit));
        } catch (Throwable) {
            if ($exception === null) {
                $command->error('Maintenance reporting failed. Completed operations were not repeated.');
                $exit = $exit === Command::SUCCESS ? Command::FAILURE : $exit;
            }
        }

        throw_if($exception !== null, $exception);

        return $exit;
    }

    public function increment(string $counter, int $amount = 1): void
    {
        $this->counts[$counter] = ($this->counts[$counter] ?? 0) + $amount;
    }

    public function issue(string $reason, ?string $upload = null, ?string $variant = null): void
    {
        if (count($this->issues) >= 10) {
            return;
        }

        $sample = ['reason' => substr($reason, 0, 500)];

        if ($upload !== null) {
            $sample['upload'] = self::isCanonicalUploadId($upload) ? $upload : 'invalid_upload_id';
        }

        if ($variant !== null) {
            $sample['variant'] = substr($variant, 0, 500);
        }
        $this->issues[] = $sample;
    }

    /** @return array<string, mixed> */
    private function context(?int $exit): array
    {
        $after = $this->command->hasOption('after') ? $this->command->option('after') : null;
        $limit = $this->command->hasOption('limit') ? $this->command->option('limit') : null;
        $requestedConnection = $this->command->option('connection');

        return [
            'run_id' => $this->id,
            'command' => $this->command->getName(),
            'mode' => match ($this->command->getName()) {
                'filoquent:health' => 'health',
                'filoquent:status' => 'status',
                default => $this->command->option('apply') ? 'apply' : 'preview',
            },
            'requested_connection' => $this->connectionLabel($requestedConnection),
            'connection' => $this->connectionLabel($this->connection),
            'limit' => $limit === null ? null : (filter_var($limit, FILTER_VALIDATE_INT) === false ? 'invalid' : (int) $limit),
            'after' => $this->command->getName() === 'filoquent:resolve-pending' ? $this->tableLabel($after) : (self::isCanonicalUploadId($after) ? $after : null),
            'upper_bound' => $this->uploadLabel($this->upperBound),
            'cursor' => $this->command->getName() === 'filoquent:resolve-pending' ? $this->tableLabel($this->cursor) : $this->uploadLabel($this->cursor),
            'counts' => $this->counts,
            'duration_ms' => (hrtime(true) - $this->started) / 1_000_000,
            'exit_code' => $exit,
            'completed' => $this->completed,
            'partial' => $this->partial,
            'range_exhausted' => $this->rangeExhausted,
            'interrupted' => $this->interrupted,
            'issues' => $this->issues,
        ];
    }

    public static function isCanonicalUploadId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $id) === 1;
    }

    private function connectionLabel(mixed $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return is_string($name) && preg_match('/\A[A-Za-z0-9_.:-]{1,500}\z/', $name) ? $name : 'redacted';
    }

    private function uploadLabel(?string $id): ?string
    {
        return $id === null || self::isCanonicalUploadId($id) ? $id : 'invalid_upload_id';
    }

    private function missingOnly(): bool
    {
        return $this->completed && ! $this->interrupted
            && ($this->counts['files_missing'] ?? 0) > 0
            && ($this->counts['files_inconclusive'] ?? 0) === 0
            && ($this->counts['uploads_inconclusive'] ?? 0) === 0;
    }

    private function tableLabel(mixed $table): ?string
    {
        return is_string($table) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $table) && strlen($table) <= 128 ? $table : null;
    }

    private function pendingTablesOnly(): bool
    {
        return $this->command->getName() === 'filoquent:gc' && $this->completed
            && ($this->counts['pending_tables'] ?? 0) > 0
            && ($this->counts['active_unresolved'] ?? 0) + ($this->counts['active_failed'] ?? 0)
                + ($this->counts['journal_unresolved'] ?? 0) + ($this->counts['pending_unresolved'] ?? 0)
                + ($this->counts['pending_table_report_failures'] ?? 0) === 0;
    }
}
