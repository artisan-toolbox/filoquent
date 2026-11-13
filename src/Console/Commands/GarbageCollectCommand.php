<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Console\Commands;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\CommandRun;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Support\Reconciler;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/** @phpstan-import-type Receipt from Reconciler */
#[Description('Preview or reconcile indexed upload ownership after raw row deletion or removed file columns')]
#[Signature('filoquent:gc
                            {--apply : Record absence and remove uploads confirmed absent after 24 hours}
                            {--limit=100 : Maximum uploads per journal, pending, and active phase, between 1 and 10000}
                            {--connection= : Database connection containing the upload ledger and owners}')]
final class GarbageCollectCommand extends Command
{
    public function handle(): int
    {
        return CommandRun::execute($this, $this->input, fn (CommandRun $run): int => $this->collect($run));
    }

    private function collect(CommandRun $run): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $name = $this->option('connection');

        if ($limit === false) {
            $run->issue('invalid_options');
            $this->error('Supply a limit from 1 to 10000 and a database connection name.');

            return self::INVALID;
        }

        try {
            $connection = DB::connection($name);
            $run->connection = $connection->getName();
            $run->counts = array_fill_keys(['journal_examined', 'journal_unresolved', 'pending_examined', 'pending_unresolved', 'active_examined', 'active_live', 'active_absent', 'active_unresolved', 'active_retired', 'active_skipped', 'active_failed'], 0);

            if ($connection->transactionLevel() !== 0) {
                $run->issue('outer_transaction');
                $this->error('Garbage collection must run outside a database transaction.');

                return self::FAILURE;
            }

            $apply = (bool) $this->option('apply');
            $phaseFailures = $this->journal($connection, $limit, $apply, $run) + $this->pending($connection, $limit, $apply, $run);
            $reconciler = new Reconciler($connection);
            $receipts = $this->candidates($connection, $limit, $apply);
            $tables = new OwnerTables($connection);
            $deferred = [];
            $counts = ['live' => 0, 'absent' => 0, 'unresolved' => 0, 'retired' => 0, 'skipped' => 0, 'failed' => 0];
            $this->info($apply ? 'Applying ownership reconciliation with a minimum 24-hour grace period.' : 'Preview only. Use --apply to record observations.');

            foreach ($receipts as $receipt) {
                if (isset($deferred[$receipt->owner_table])) {
                    continue;
                }
                $result = $reconciler->reconcile($receipt, $apply);
                $run->increment('active_examined');

                if ($result['status'] === 'unresolved' && $tables->isPending($receipt->owner_table)) {
                    $deferred[$receipt->owner_table] = true;
                    $run->increment('active_skipped');

                    continue;
                }

                $counts[$result['status']]++;
                $run->increment('active_'.$result['status']);

                if (in_array($result['status'], ['unresolved', 'failed'], true)) {
                    $run->issue('active_'.$result['status'], $receipt->id);
                }
                $this->line($receipt->id.' '.$result['status'].' '.$result['message']);
            }

            $this->info('Examined '.$run->counts['active_examined'].'; live '.$counts['live'].'; absent '.$counts['absent'].'; unresolved '.$counts['unresolved'].'; retired '.$counts['retired'].'; skipped '.$counts['skipped'].'; failed '.$counts['failed'].'.');

            if ($apply) {
                foreach ($tables->claimReports(min($limit, 500)) as $group) {
                    try {
                        Log::error('Filoquent owner table requires explicit resolution.', [
                            'owner_table' => $group->owner_table,
                            'pending_id' => $group->issue_id,
                            'active_uploads_at_detection' => $group->affected_uploads,
                            'detected_at' => $group->detected_at,
                            'resolution_command' => 'php artisan filoquent:resolve-pending --table='.$group->owner_table,
                        ]);
                    } catch (Throwable) {
                        $run->increment('pending_table_report_failures');
                        $run->issue('pending_table_report_failed', $group->issue_id);
                    }
                    $this->error($group->owner_table.' has '.$group->affected_uploads.' affected uploads at detection. Use filoquent:resolve-pending. Files remain preserved.');
                }
            }
            $run->counts['pending_tables'] = $tables->pending(min($limit, 500))->count();

            $run->completed = true;

            return $phaseFailures + $counts['unresolved'] + $counts['failed'] + $run->counts['pending_tables'] + ($run->counts['pending_table_report_failures'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $run->issue('garbage_collection_failed');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return Collection<int, Receipt> */
    private function candidates(Connection $connection, int $limit, bool $apply): Collection
    {
        $uploads = $connection->table('filoquent_uploads')->useWritePdo()->where('state', 'active');

        if (! $connection->table('filoquent_owner_tables')->useWritePdo()->where('state', 'pending')->exists()) {
            /** @var Collection<int, Receipt> $receipts */
            $receipts = $uploads->oldest('checked_at')->orderBy('id')->limit($limit)->get();

            return $receipts;
        }

        $routes = $this->readyRoutes($connection, min($limit, 500), $apply);
        /** @var Collection<int, Receipt> $receipts */
        $receipts = new Collection;
        foreach ($routes as $route) {
            /** @var Collection<int, Receipt> $batch */
            $batch = (clone $uploads)->where('owner_table', $route->owner_table)
                ->oldest('checked_at')->orderBy('id')->limit($limit - $receipts->count())->get();
            $receipts = $receipts->concat($batch);

            if ($receipts->count() === $limit) {
                break;
            }
        }

        return $receipts;
    }

    /** @return Collection<int, stdClass> */
    private function readyRoutes(Connection $connection, int $limit, bool $apply): Collection
    {
        $select = static function (bool $lock) use ($connection, $limit): Collection {
            $namespace = $connection->table('filoquent_upload_namespace')->useWritePdo()->where('id', 1);
            $after = ($lock ? (clone $namespace)->lockForUpdate() : $namespace)->value('gc_after_table');
            $ready = $connection->table('filoquent_owner_tables')->useWritePdo()->where('state', 'ready');
            $routes = (clone $ready)->when($after !== null, fn (Builder $query): Builder => $query->where('owner_table', '>', $after))
                ->orderBy('owner_table')->limit($limit)->get(['owner_table']);

            if ($routes->isEmpty() && $after !== null) {
                $routes = $ready->orderBy('owner_table')->limit($limit)->get(['owner_table']);
            }

            if ($lock) {
                $namespace->update(['gc_after_table' => $routes->last()?->owner_table]);
            }

            return $routes;
        };

        return $apply ? $connection->transaction(static fn (): Collection => $select(true)) : $select(false);
    }

    private function journal(Connection $connection, int $limit, bool $apply, CommandRun $run): int
    {
        if (! RedisUploadJournal::enabled()) {
            return 0;
        }

        $failures = 0;

        try {
            $journal = resolve(RedisUploadJournal::class);
            $scope = UploadLedger::scope($connection);
            $records = $journal->due($scope, $limit);
            foreach ($records as $id => $payload) {
                $id = (string) $id;
                $run->increment('journal_examined');

                try {
                    throw_if($payload === null, FiloquentException::class, 'The indexed journal record is missing.');
                    $intent = UploadIntent::decode($payload, $scope, $id);

                    if ($apply && $journal->current($scope, $id, $payload)) {
                        UploadLedger::promote($connection, $intent);
                        $journal->acknowledge($scope, $id, $payload);
                    }
                    $this->line($id.($apply ? ' journal consolidated.' : ' journal awaiting consolidation.'));
                } catch (Throwable $exception) {
                    $failures++;
                    $run->increment('journal_unresolved');
                    $run->issue('journal_unresolved', $id);
                    $this->error($id.' journal unresolved. '.$exception->getMessage());

                    if ($apply) {
                        try {
                            $journal->retry($scope, $id, $payload);
                        } catch (Throwable $retryException) {
                            $this->error('Could not reschedule the journal record. '.$retryException->getMessage());
                        }
                    }
                }
            }
            $this->info('Journal examined '.count($records).'; unresolved '.$failures.'.');
        } catch (Throwable $exception) {
            $run->increment('journal_unresolved');
            $run->issue('journal_unavailable');
            $this->error('Journal unavailable. '.$exception->getMessage());

            return $failures + 1;
        }

        return $failures;
    }

    private function pending(Connection $connection, int $limit, bool $apply, CommandRun $run): int
    {
        $now = CarbonImmutable::now('UTC');
        $receipts = $connection->table('filoquent_uploads')->useWritePdo()->where('state', 'pending')
            ->where('checked_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->oldest('checked_at')->orderBy('id')->limit($limit)->get();
        $failures = 0;
        foreach ($receipts as $receipt) {
            $run->increment('pending_examined');

            try {
                throw_if($apply && ! UploadLedger::retirePending($connection, $receipt->id), FiloquentException::class, 'Retired upload cleanup failed. Retry with filoquent:prune --apply.');
                $this->line($receipt->id.($apply ? ' pending cleanup checked.' : ' pending cleanup due.'));
            } catch (Throwable $exception) {
                $failures++;
                $run->increment('pending_unresolved');
                $run->issue('pending_unresolved', $receipt->id);
                $this->error($receipt->id.' pending unresolved. '.$exception->getMessage());

                if ($apply) {
                    $connection->table('filoquent_uploads')->useWritePdo()->where('id', $receipt->id)->where('state', 'pending')
                        ->where('checked_at', $receipt->checked_at)
                        ->update(['checked_at' => $now->addSeconds(60)->format('Y-m-d H:i:s.u')]);
                }
            }
        }
        $this->info('Pending examined '.$receipts->count().'; unresolved '.$failures.'.');

        return $failures;
    }
}
