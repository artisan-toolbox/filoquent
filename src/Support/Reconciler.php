<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Throwable;

/**
 * @internal
 *
 * @phpstan-type Receipt object{id: string, owner_type: string, owner_table: string, owner_key_name: string, owner_key: string, attribute: string, manifest: string, state: string, checked_at: string, orphaned_at: string|null}
 * @phpstan-type Result array{status: 'live'|'absent'|'unresolved'|'retired'|'skipped'|'failed', message: string}
 */
final class Reconciler
{
    public const int GRACE_HOURS = 24;

    /** @var array<string, true> */
    private array $routes = [];

    public function __construct(private readonly Connection $connection) {}

    /**
     * @param  Receipt  $receipt
     * @return Result
     */
    public function reconcile(object $receipt, bool $apply): array
    {
        try {
            throw_if($this->connection->transactionLevel() !== 0, FiloquentException::class, 'Garbage collection must run outside a database transaction.');

            $assets = $this->assets($receipt);
            $tables = new OwnerTables($this->connection);

            if ($tables->isPending($receipt->owner_table)) {
                return ['status' => 'unresolved', 'message' => 'The owner table requires explicit resolution with filoquent:resolve-pending.'];
            }

            if (! isset($this->routes[$receipt->owner_table.'::'.$receipt->owner_key_name]) && ! $tables->exists($receipt->owner_table)) {
                if ($apply) {
                    $tables->block($receipt->owner_table);
                    $this->observeUnresolved($receipt);
                }

                return ['status' => 'unresolved', 'message' => 'The owner table is missing. Its upload group is preserved for explicit resolution.'];
            }
            $this->assertRoute($receipt);

            if (! $apply) {
                return $this->inspect($this->owner($receipt, false), $receipt, $assets);
            }

            $result = $this->connection->transaction(function () use ($receipt, $assets): array {
                (new OwnerTables($this->connection))->guard($receipt->owner_table);
                $owner = $this->owner($receipt, true);
                $query = $this->connection->table('filoquent_uploads')->useWritePdo()->where('id', $receipt->id)->where('state', 'active');
                /** @var Receipt|null $current */
                $current = $query->lockForUpdate()->first();

                if ($current === null || $current->owner_table !== $receipt->owner_table
                    || $current->owner_key_name !== $receipt->owner_key_name || $current->owner_key !== $receipt->owner_key
                    || $current->manifest !== $receipt->manifest || $current->checked_at !== $receipt->checked_at) {
                    return ['status' => 'skipped', 'message' => 'The ownership receipt changed.'];
                }

                $result = $this->inspect($owner, $current, $assets);
                $now = CarbonImmutable::now('UTC');
                $values = ['checked_at' => $this->checkedAt($current), 'orphaned_at' => null];

                if ($result['status'] === 'absent') {
                    $values['orphaned_at'] = $current->orphaned_at ?? $now->format('Y-m-d H:i:s.u');

                    if ($current->orphaned_at !== null && CarbonImmutable::parse($current->orphaned_at, 'UTC')->addHours(self::GRACE_HOURS)->lessThanOrEqualTo($now)) {
                        $this->assertRoute($current, refresh: true);
                        $values['state'] = 'retired';
                        $result = ['status' => 'retired', 'message' => 'Absence confirmed after the minimum 24-hour grace period.'];
                    }
                }

                $query->update($values);

                return $result;
            });

            if ($result['status'] === 'retired' && ! UploadLedger::drain($this->connection, $receipt->id)) {
                return ['status' => 'failed', 'message' => 'Retired upload cleanup failed. Retry with filoquent:prune --apply.'];
            }

            return $result;
        } catch (Throwable $exception) {
            if ($apply && $this->connection->transactionLevel() === 0) {
                try {
                    $tables = new OwnerTables($this->connection);

                    if (! $tables->exists($receipt->owner_table)) {
                        $tables->block($receipt->owner_table);
                    }
                    $this->observeUnresolved($receipt);
                } catch (Throwable) {
                    return ['status' => 'failed', 'message' => 'Could not update the unresolved ownership receipt. '.$exception->getMessage()];
                }
            }

            return ['status' => 'unresolved', 'message' => $exception->getMessage()];
        }
    }

    /** @param Receipt $receipt */
    private function observeUnresolved(object $receipt): void
    {
        $query = $this->connection->table('filoquent_uploads')->useWritePdo()->where('id', $receipt->id)->where('state', 'active')
            ->where('checked_at', $receipt->checked_at);
        match ($this->connection->getDriverName()) {
            'pgsql' => $query->whereRaw('CAST("manifest" AS text) = ?', [$receipt->manifest]),
            'mysql' => $query->whereRaw('`manifest` = CAST(? AS JSON)', [$receipt->manifest]),
            default => $query->where('manifest', $receipt->manifest),
        };
        $query->update(['checked_at' => $this->checkedAt($receipt), 'orphaned_at' => null]);
    }

    /**
     * Confirm a negative storage observation without changing lifecycle metadata.
     *
     * @param  Receipt  $receipt
     * @return Result
     */
    public function confirmReference(object $receipt): array
    {
        try {
            throw_if($this->connection->transactionLevel() !== 0, FiloquentException::class, 'Health checks must run outside a database transaction.');

            $assets = $this->assets($receipt);

            if ((new OwnerTables($this->connection))->isPending($receipt->owner_table)) {
                return ['status' => 'unresolved', 'message' => 'The owner table requires explicit resolution.'];
            }
            $this->assertRoute($receipt, refresh: true);

            return $this->connection->transaction(function () use ($receipt, $assets): array {
                $owner = $this->owner($receipt, true);
                /** @var Receipt|null $current */
                $current = $this->connection->table('filoquent_uploads')->useWritePdo()
                    ->where('id', $receipt->id)->where('state', 'active')->lockForUpdate()->first();

                if ($current === null || $current->owner_type !== $receipt->owner_type
                    || $current->owner_table !== $receipt->owner_table || $current->owner_key_name !== $receipt->owner_key_name
                    || $current->owner_key !== $receipt->owner_key || $current->attribute !== $receipt->attribute
                    || $current->manifest !== $receipt->manifest) {
                    return ['status' => 'skipped', 'message' => 'The ownership receipt changed.'];
                }

                return $this->inspect($owner, $current, $assets);
            });
        } catch (Throwable) {
            return ['status' => 'unresolved', 'message' => 'The current ownership reference could not be verified.'];
        }
    }

    /** @param Receipt $receipt */
    private function assets(object $receipt): Assets
    {
        foreach (['id', 'owner_type', 'owner_table', 'owner_key_name', 'owner_key', 'attribute', 'manifest'] as $field) {
            throw_if(! isset($receipt->{$field}) || $receipt->{$field} === '', FiloquentException::class, 'The ownership receipt contains invalid metadata.');
        }
        throw_if($this->connection->getDriverName() === 'pgsql' && ! str_contains($receipt->owner_table, '.'), FiloquentException::class, 'Historical PostgreSQL owner routes require explicit schema qualification before reconciliation.');

        $assets = AsFile::decode($receipt->manifest);

        throw_if($assets === null, FiloquentException::class, 'The ownership receipt has no manifest.');

        UploadLedger::generationDirectory($receipt->id, $assets);

        return $assets;
    }

    /** @param Receipt $receipt */
    private function assertRoute(object $receipt, bool $refresh = false): void
    {
        $route = $receipt->owner_table.'::'.$receipt->owner_key_name;

        if (! $refresh && isset($this->routes[$route])) {
            return;
        }

        throw_if(! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $receipt->owner_table)
            || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $receipt->owner_key_name), FiloquentException::class, 'The ownership receipt has an unsupported physical table or key name.');

        $schema = $this->connection->getSchemaBuilder();

        foreach ($schema->getIndexes($receipt->owner_table) as $index) {
            if ($index['primary'] && $index['columns'] === [$receipt->owner_key_name]) {
                $this->routes[$route] = true;

                return;
            }
        }

        throw new FiloquentException('The physical owner key needs a single-column primary index.');
    }

    /** @param Receipt $receipt */
    private function checkedAt(object $receipt): string
    {
        $now = CarbonImmutable::now('UTC');

        try {
            $next = CarbonImmutable::parse($receipt->checked_at, 'UTC')->addMicrosecond();

            return ($next->greaterThan($now) ? $next : $now)->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return $now->format('Y-m-d H:i:s.u');
        }
    }

    /** @param Receipt $receipt */
    private function owner(object $receipt, bool $lock): ?object
    {
        $query = $this->connection->table($receipt->owner_table)->useWritePdo()->where($receipt->owner_key_name, $receipt->owner_key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @param  Receipt  $receipt
     * @return Result
     */
    public function inspect(?object $owner, object $receipt, Assets $assets): array
    {
        if ($owner === null) {
            return ['status' => 'absent', 'message' => 'The physical owner row is missing.'];
        }

        $candidate = false;

        foreach (get_object_vars($owner) as $value) {
            if (! is_string($value)) {
                continue;
            }

            try {
                if (AsFile::matches(AsFile::decode($value), $assets)) {
                    return ['status' => 'live', 'message' => 'The owner row contains the exact upload manifest.'];
                }
            } catch (Throwable) {
            }

            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE && in_array(ltrim($value)[0] ?? '', ['{', '['], true)) {
                $candidate = true;
            }
            $search = $value.' '.json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $candidate = $candidate || str_contains(strtolower($search), strtolower($receipt->id));

            foreach ($assets->all() as $asset) {
                $candidate = $candidate || str_contains($search, $asset->path);
            }
        }

        return $candidate
            ? ['status' => 'unresolved', 'message' => 'The owner row contains a partial or changed upload reference.']
            : ['status' => 'absent', 'message' => 'The owner row no longer references this upload.'];
    }
}
