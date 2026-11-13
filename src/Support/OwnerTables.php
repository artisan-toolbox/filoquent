<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Enums\OwnerTableResolution;
use ArtisanToolbox\Filoquent\Enums\OwnerTableState;
use ArtisanToolbox\Filoquent\Enums\UploadState;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;
use Throwable;
use WeakMap;

/**
 * Persistent coordination between managed owner writes and operator resolutions.
 *
 * @internal
 *
 * @phpstan-type OwnerTable stdClass&object{owner_table: string, state: string, issue_id: string, detected_at: string, last_reported_at: string|null, affected_uploads: int, resolved_at: string|null, resolution: string|null, resolved_to: string|null, requires_qualified_rename: bool}
 */
final class OwnerTables
{
    /** @var WeakMap<Connection, array{schema: string, fold: bool}>|null */
    private static ?WeakMap $identities = null;

    /** @var WeakMap<Connection, int>|null */
    private static ?WeakMap $postgresIdentifierLimits = null;

    public function __construct(private readonly Connection $connection) {}

    public function canonical(string $table, ?string $legacyPostgresSchema = null): string
    {
        $table = $this->logical($table, $legacyPostgresSchema);
        [$qualifier, $name] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($table);

        if ($qualifier !== null) {
            $this->assertSchema($qualifier);
        } else {
            $resolved = $this->resolvedSchema($name);
            throw_if($resolved !== null && $resolved !== $this->identity()['schema'], FiloquentException::class, 'The unqualified owner table resolves outside the selected default schema. Use an explicit qualified owner table name before writing or migrating its receipts.');
        }

        return $table;
    }

    private function logical(string $table, ?string $legacyPostgresSchema = null): string
    {
        $this->validate($table);

        if ($this->connection->getDriverName() === 'pgsql') {
            [$schema, $name] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($table);
            $limit = $this->postgresIdentifierLimit();
            $prefix = $this->connection->getTablePrefix();
            throw_if(strlen($prefix) >= $limit || str_contains($prefix, '.'), FiloquentException::class, 'The PostgreSQL table prefix cannot represent a unique physical owner name.');
            $name = substr($name, 0, $limit - strlen($prefix));
            $schema ??= $legacyPostgresSchema;

            if ($schema === null) {
                $schema = $this->postgresRelation($name)->schema_name
                    ?? $this->connection->selectFromWriteConnection('select pg_catalog.current_schema() as schema_name')[0]->schema_name;
            }
            throw_unless(is_string($schema) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $schema), FiloquentException::class, 'The resolved owner schema cannot be represented in an ownership receipt.');
            $route = substr($schema, 0, $limit).'.'.$name;
            $this->validate($route);

            return $route;
        }

        $identity = $this->identity();
        $table = $identity['fold'] ? strtolower($table) : $table;
        $schema = $identity['fold'] ? strtolower($identity['schema']) : $identity['schema'];
        [$qualifier, $name] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($table);

        return $qualifier === null || $qualifier === $schema ? $name : $qualifier.'.'.$name;
    }

    public function guard(string $table): void
    {
        throw_if($this->connection->transactionLevel() === 0, FiloquentException::class, 'Owner table guards require the owning SQL transaction.');
        $table = $this->canonical($table);
        $this->ensure($table);
        $row = $this->route('filoquent_owner_tables', $table)->sharedLock()->first();

        throw_if($row === null || $row->state !== OwnerTableState::Ready->value, FiloquentException::class, 'The owner table has a pending Filoquent issue. Resolve it with filoquent:resolve-pending before writing this upload group.');
    }

    public function isPending(string $table): bool
    {
        return $this->findPending($table) !== null;
    }

    /** @return OwnerTable|null */
    public function findPending(string $table): ?object
    {
        $table = $this->logical($table);

        /** @var OwnerTable|null $row */
        $row = $this->route('filoquent_owner_tables', $table)->where('state', OwnerTableState::Pending->value)->first();

        return $row;
    }

    public function block(string $table): void
    {
        $this->outsideTransaction();
        $table = $this->logical($table);
        $this->connection->transaction(function () use ($table): void {
            $row = $this->route('filoquent_owner_tables', $table)->lockForUpdate()->first();

            if ($row === null) {
                $this->ensure($table);
                $row = $this->route('filoquent_owner_tables', $table)->lockForUpdate()->first();
            }

            if ($row !== null && $row->state === OwnerTableState::Pending->value) {
                return;
            }

            $this->route('filoquent_owner_tables', $table)->update([
                'state' => OwnerTableState::Pending->value,
                'issue_id' => (string) Str::uuid7(),
                'detected_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'affected_uploads' => $this->route('filoquent_uploads', $table)->where('state', UploadState::Active->value)->count(),
                'resolved_at' => null,
                'resolution' => null,
                'resolved_to' => null,
            ]);
        });
    }

    public function exists(string $table): bool
    {
        $table = $this->logical($table);
        $schema = $this->connection->getSchemaBuilder();
        [$qualifier, $name] = $schema->parseSchemaAndTable($table);

        if ($qualifier !== null) {
            $this->assertSchema($qualifier);
        }

        if ($this->connection->getDriverName() === 'pgsql') {
            return in_array($this->postgresRelation($table)?->kind, ['r', 'p'], true);
        }
        $identity = $this->identity();
        $current = $qualifier ?? $identity['schema'];
        $physical = $this->connection->getTablePrefix().$name;
        $physical = $identity['fold'] ? strtolower($physical) : $physical;

        foreach ($schema->getTables($current) as $candidate) {
            $candidateName = $identity['fold'] ? strtolower($candidate['name']) : $candidate['name'];

            if ($candidateName === $physical) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, OwnerTable> */
    public function pending(int $limit = 100, ?string $after = null): Collection
    {
        $this->limit($limit);
        $after = $after === null ? null : $this->logical($after);

        /** @var Collection<int, OwnerTable> $rows */
        $rows = $this->connection->table('filoquent_owner_tables')->useWritePdo()->where('state', OwnerTableState::Pending->value)
            ->when($after !== null, fn (Builder $query): Builder => $query->where('owner_table', '>', $after))
            ->orderBy('owner_table')->limit($limit)->get();

        return $rows;
    }

    /** @return array<OwnerTable> */
    public function claimReports(int $limit = 100): array
    {
        $this->outsideTransaction();
        $this->limit($limit);

        return $this->connection->transaction(function () use ($limit): array {
            $now = CarbonImmutable::now('UTC');
            $today = $now->startOfDay()->format('Y-m-d H:i:s.u');
            /** @var Collection<int, OwnerTable> $rows */
            $rows = $this->connection->table('filoquent_owner_tables')->useWritePdo()->where('state', OwnerTableState::Pending->value)
                ->where(fn (Builder $query): Builder => $query->whereNull('last_reported_at')->orWhere('last_reported_at', '<', $today))
                ->oldest('last_reported_at')->orderBy('owner_table')->limit($limit)->lockForUpdate()->get();
            $claimed = [];

            foreach ($rows as $row) {
                $query = $this->route('filoquent_owner_tables', $row->owner_table)->where('state', OwnerTableState::Pending->value)->where('issue_id', $row->issue_id);
                $row->last_reported_at === null ? $query->whereNull('last_reported_at') : $query->where('last_reported_at', $row->last_reported_at);

                if ($query->update(['last_reported_at' => $now->format('Y-m-d H:i:s.u')]) === 1) {
                    $row->last_reported_at = $now->format('Y-m-d H:i:s.u');
                    $claimed[] = $row;
                }
            }

            return $claimed;
        });
    }

    public function resolveRename(string $from, string $to, string $expectedIssueId): int
    {
        $this->outsideTransaction();
        $from = $this->logical($from);
        $qualified = str_contains($to, '.');
        $to = $this->canonical($to);

        return $this->connection->transaction(function () use ($from, $to, $qualified, $expectedIssueId): int {
            $tables = array_values(array_unique([$from, $to]));
            sort($tables, SORT_STRING);
            $locked = [];

            foreach ($tables as $table) {
                $this->ensure($table);
                $locked[$table] = $this->route('filoquent_owner_tables', $table)->lockForUpdate()->first();
            }

            $this->assertIssue($locked[$from], $expectedIssueId);
            throw_if($locked[$from]->requires_qualified_rename && ! $qualified, FiloquentException::class, 'Legacy PostgreSQL receipts require an explicit schema-qualified destination.');
            throw_if($from !== $to && ($locked[$to] === null || $locked[$to]->state !== OwnerTableState::Ready->value), FiloquentException::class, 'The destination owner table has its own pending issue.');
            throw_unless($this->exists($to), FiloquentException::class, 'The destination physical owner table does not exist on the selected writer.');
            $key = $this->primaryKey($to);
            $this->validateOwners($from, $to, $key);
            $alias = match ($this->connection->getDriverName()) {
                'mysql', 'mariadb' => 'COALESCE(`journal_owner_table`, `owner_table`)',
                'sqlsrv' => 'COALESCE([journal_owner_table], [owner_table])',
                default => 'COALESCE("journal_owner_table", "owner_table")',
            };
            $affected = $this->route('filoquent_uploads', $from)->where('state', UploadState::Active->value)->update([
                'journal_owner_table' => $this->connection->raw($alias),
                'owner_table' => $to,
                'orphaned_at' => null,
                'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            $this->close($from, OwnerTableResolution::Renamed, $to);

            return $affected;
        });
    }

    public function resolveRemoved(string $from, string $expectedIssueId): int
    {
        $this->outsideTransaction();
        $from = $this->logical($from);

        return $this->connection->transaction(function () use ($from, $expectedIssueId): int {
            $row = $this->route('filoquent_owner_tables', $from)->lockForUpdate()->first();
            $this->assertIssue($row, $expectedIssueId);
            throw_if($row->requires_qualified_rename, FiloquentException::class, 'The original schema of these legacy receipts is unknown. Verify a schema-qualified destination before authorizing any cleanup.');
            $this->canonical($from);
            $present = $this->connection->getDriverName() === 'pgsql'
                ? $this->postgresRelation($from) !== null
                : $this->exists($from) || $this->viewExists($from);
            throw_if($present, FiloquentException::class, 'The physical owner table or a compatible view still exists. Restore or remap its references instead of authorizing removal.');
            $now = CarbonImmutable::now('UTC');
            $affected = $this->route('filoquent_uploads', $from)->where('state', UploadState::Active->value)->update([
                'state' => UploadState::Pending->value,
                'orphaned_at' => $now->format('Y-m-d H:i:s.u'),
                'checked_at' => $now->addHours(Reconciler::GRACE_HOURS)->format('Y-m-d H:i:s.u'),
            ]);
            $this->close($from, OwnerTableResolution::Removed, null);

            return $affected;
        });
    }

    private function validateOwners(string $from, string $to, string $key): void
    {
        $after = null;

        do {
            $receipts = $this->route('filoquent_uploads', $from)->where('state', UploadState::Active->value)
                ->when($after !== null, fn (Builder $query): Builder => $query->where('id', '>', $after))
                ->orderBy('id')->limit(500)->lockForUpdate()->get();
            $keys = [];

            foreach ($receipts as $receipt) {
                throw_if($receipt->owner_key_name !== $key || ! is_string($receipt->owner_key) || $receipt->owner_key === '', FiloquentException::class, 'Every upload must retain the same single-column physical owner key.');
                $keys[] = $receipt->owner_key;
            }

            $owners = $keys === [] ? [] : $this->connection->table($to)->useWritePdo()->whereIn($key, array_values(array_unique($keys)))->lockForUpdate()->get()->keyBy($key)->all();

            foreach ($receipts as $receipt) {
                $assets = AsFile::decode($receipt->manifest);
                throw_if($assets === null, FiloquentException::class, 'The ownership receipt has no manifest.');
                UploadLedger::generationDirectory($receipt->id, $assets);
                $matches = false;

                foreach (get_object_vars($owners[$receipt->owner_key] ?? (object) []) as $value) {
                    if (! is_string($value)) {
                        continue;
                    }

                    try {
                        $matches = AsFile::matches(AsFile::decode($value), $assets);
                    } catch (Throwable) {
                        continue;
                    }

                    if ($matches) {
                        break;
                    }
                }

                throw_unless($matches, FiloquentException::class, 'Every active upload must have its exact manifest at the same physical key in the destination table.');
                $after = $receipt->id;
            }
        } while ($receipts->count() === 500);
    }

    private function primaryKey(string $table): string
    {
        foreach ($this->connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['primary'] && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        throw new FiloquentException('The destination physical owner table needs a single-column primary key.');
    }

    private function assertIssue(?stdClass $row, string $expectedIssueId): void
    {
        throw_if($row === null || $row->state !== OwnerTableState::Pending->value || ! is_string($row->issue_id) || ! hash_equals($row->issue_id, $expectedIssueId), FiloquentException::class, 'The pending owner table issue changed. Inspect the current issue before confirming its resolution.');
    }

    private function close(string $table, OwnerTableResolution $resolution, ?string $to): void
    {
        $this->route('filoquent_owner_tables', $table)->update([
            'state' => OwnerTableState::Ready->value,
            'resolved_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'resolution' => $resolution->value,
            'requires_qualified_rename' => false,
            'resolved_to' => $to,
        ]);
    }

    private function ensure(string $table): void
    {
        if (! $this->route('filoquent_owner_tables', $table)->exists()) {
            $this->connection->table('filoquent_owner_tables')->insertOrIgnore(['owner_table' => $table, 'state' => OwnerTableState::Ready->value, 'affected_uploads' => 0]);
        }
    }

    private function route(string $ledger, string $table): Builder
    {
        $predicate = match ($this->connection->getDriverName()) {
            'mysql', 'mariadb' => 'BINARY `owner_table` = ?',
            'sqlite' => '"owner_table" COLLATE BINARY = ?',
            'sqlsrv' => '[owner_table] COLLATE Latin1_General_100_BIN2 = ?',
            default => '"owner_table" = ?',
        };

        return $this->connection->table($ledger)->useWritePdo()->where('owner_table', $table)->whereRaw($predicate, [$table]);
    }

    private function viewExists(string $table): bool
    {
        [$qualifier, $name] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($table);
        $identity = $this->identity();
        $physical = $this->connection->getTablePrefix().$name;
        foreach ($this->connection->getSchemaBuilder()->getViews($qualifier ?? $identity['schema']) as $view) {
            if ($identity['fold'] ? strcasecmp($view['name'], $physical) === 0 : $view['name'] === $physical) {
                return true;
            }
        }

        return false;
    }

    private function assertSchema(string $schema): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $accessible = $this->connection->selectFromWriteConnection("select 1 from pg_catalog.pg_namespace where nspname = ? and pg_catalog.has_schema_privilege(oid, 'USAGE')", [$schema]);
            throw_if($accessible === [], FiloquentException::class, 'The owner schema is unavailable on the selected writer. Restore its connection before resolving this group.');

            return;
        }
        $identity = $this->identity();
        foreach ($this->connection->getSchemaBuilder()->getSchemas() as $candidate) {
            if ($identity['fold'] ? strcasecmp($candidate['name'], $schema) === 0 : $candidate['name'] === $schema) {
                return;
            }
        }

        throw new FiloquentException('The owner schema is unavailable on the selected writer. Restore its connection before resolving this group.');
    }

    private function postgresIdentifierLimit(): int
    {
        self::$postgresIdentifierLimits ??= new WeakMap;

        return self::$postgresIdentifierLimits[$this->connection] ??= (int) $this->connection->selectFromWriteConnection('show max_identifier_length')[0]->max_identifier_length;
    }

    /** @return (stdClass&object{schema_name: string, kind: string})|null */
    private function postgresRelation(string $table): ?stdClass
    {
        $quoted = $this->connection->getQueryGrammar()->wrapTable($table);

        return $this->connection->selectFromWriteConnection('select n.nspname as schema_name, c.relkind as kind from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where c.oid = pg_catalog.to_regclass(?)', [$quoted])[0] ?? null;
    }

    private function resolvedSchema(string $name): ?string
    {
        $physical = $this->connection->getTablePrefix().$name;

        if ($this->connection->getDriverName() === 'sqlite') {
            if ($this->connection->selectFromWriteConnection("SELECT 1 FROM sqlite_temp_master WHERE name = ? COLLATE NOCASE AND type IN ('table', 'view') LIMIT 1", [$physical]) !== []) {
                return 'temp';
            }

            if ($this->connection->selectFromWriteConnection("SELECT 1 FROM main.sqlite_master WHERE name = ? COLLATE NOCASE AND type IN ('table', 'view') LIMIT 1", [$physical]) !== []) {
                return 'main';
            }
            foreach ($this->connection->selectFromWriteConnection('PRAGMA database_list') as $schema) {
                if (in_array($schema->name, ['main', 'temp'], true)) {
                    continue;
                }
                $quoted = '"'.str_replace('"', '""', $schema->name).'"';

                if ($this->connection->selectFromWriteConnection("SELECT 1 FROM {$quoted}.sqlite_master WHERE name = ? COLLATE NOCASE AND type IN ('table', 'view') LIMIT 1", [$physical]) !== []) {
                    throw_unless(preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $schema->name), FiloquentException::class, 'The resolved owner schema cannot be represented in an ownership receipt.');

                    return strtolower($schema->name);
                }
            }
        } elseif ($this->connection->getDriverName() === 'sqlsrv') {
            $quoted = '['.str_replace(']', ']]', $physical).']';
            $row = $this->connection->selectFromWriteConnection('SELECT SCHEMA_NAME(schema_id) AS schema_name FROM sys.objects WHERE object_id = OBJECT_ID(?)', [$quoted])[0] ?? null;

            if ($row !== null) {
                throw_unless(preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $row->schema_name), FiloquentException::class, 'The resolved owner schema cannot be represented in an ownership receipt.');

                return $this->identity()['fold'] ? strtolower($row->schema_name) : $row->schema_name;
            }
        }

        return null;
    }

    /** @return array{schema: string, fold: bool} */
    private function identity(): array
    {
        self::$identities ??= new WeakMap;

        if (isset(self::$identities[$this->connection])) {
            return self::$identities[$this->connection];
        }

        $schema = $this->connection->getSchemaBuilder()->getCurrentSchemaName();
        throw_if(! is_string($schema) || $schema === '', FiloquentException::class, 'The selected writer must declare its default owner schema.');
        $fold = match ($this->connection->getDriverName()) {
            'sqlite' => true,
            'mysql', 'mariadb' => $this->mysqlFoldsIdentifiers(),
            'sqlsrv' => $this->sqlServerFoldsIdentifiers(),
            default => throw new FiloquentException('The selected writer cannot determine owner table identifier semantics.'),
        };

        return self::$identities[$this->connection] = ['schema' => $fold ? strtolower($schema) : $schema, 'fold' => $fold];
    }

    private function mysqlFoldsIdentifiers(): bool
    {
        $row = $this->connection->selectFromWriteConnection('SELECT @@lower_case_table_names AS identifier_case')[0] ?? null;
        $value = $row->identifier_case ?? null;
        throw_unless(in_array($value, [0, 1, 2, '0', '1', '2'], true), FiloquentException::class, 'The selected writer did not report its table identifier case mode.');

        return (int) $value !== 0;
    }

    private function sqlServerFoldsIdentifiers(): bool
    {
        $row = $this->connection->selectFromWriteConnection("SELECT CONVERT(varchar(128), DATABASEPROPERTYEX(DB_NAME(), 'Collation')) AS identifier_collation")[0] ?? null;
        $collation = $row->identifier_collation ?? null;
        throw_if(! is_string($collation), FiloquentException::class, 'The selected writer did not report its database identifier collation.');

        if (preg_match('/_(?:CS|BIN|BIN2)(?:_|$)/i', $collation)) {
            return false;
        }
        throw_unless(preg_match('/_CI(?:_|$)/i', $collation), FiloquentException::class, 'The selected writer has unsupported database identifier case semantics.');

        return true;
    }

    private function validate(string $table): void
    {
        $name = $this->connection->getName();
        throw_if(! is_string($name) || $name === '' || str_contains($name, '::') || ! is_array(config('database.connections.'.$name)), FiloquentException::class, 'Choose a configured writer connection for owner table coordination.');
        throw_if(strlen($table) > 128 || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $table), FiloquentException::class, 'Use a valid logical owner table name on the selected writer schema.');
        $migrations = config('database.migrations');
        $protected = ['filoquent_uploads', 'filoquent_upload_namespace', 'filoquent_owner_tables', 'migrations', is_array($migrations) ? ($migrations['table'] ?? 'migrations') : $migrations];
        $segments = explode('.', $table);
        $logical = end($segments);
        $prefix = $this->connection->getTablePrefix();

        foreach ($protected as $value) {
            throw_if(strcasecmp($logical, (string) $value) === 0 || strcasecmp($logical, $prefix.$value) === 0, FiloquentException::class, 'Filoquent and migration infrastructure cannot be used as owner tables.');
        }
    }

    private function outsideTransaction(): void
    {
        throw_if($this->connection->transactionLevel() !== 0, FiloquentException::class, 'Owner table issue mutations must run outside an existing SQL transaction.');
    }

    private function limit(int $limit): void
    {
        throw_if($limit < 1 || $limit > 1000, FiloquentException::class, 'Owner table issue batches must contain between 1 and 1000 rows.');
    }
}
