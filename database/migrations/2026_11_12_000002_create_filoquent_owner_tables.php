<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $ledgerSchema = null;

        if ($connection->getDriverName() === 'pgsql') {
            $ledger = $connection->getQueryGrammar()->wrapTable('filoquent_uploads');
            $ledgerSchema = $connection->selectFromWriteConnection('select n.nspname as schema_name from pg_catalog.pg_namespace n join pg_catalog.pg_class c on n.oid = c.relnamespace where c.oid = pg_catalog.to_regclass(?)', [$ledger])[0]->schema_name;
            throw_unless(is_string($ledgerSchema) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $ledgerSchema), FiloquentException::class, 'The PostgreSQL upload ledger schema must be available and representable in owner routes.');
        }
        $registry = $ledgerSchema === null ? 'filoquent_owner_tables' : $ledgerSchema.'.filoquent_owner_tables';
        Schema::create($registry, function (Blueprint $table): void {
            $owner = $table->string('owner_table', 128)->primary();

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $owner->charset('utf8mb4')->collation('utf8mb4_bin');
            } elseif (DB::connection()->getDriverName() === 'sqlsrv') {
                $owner->collation('Latin1_General_100_BIN2');
            }
            $table->string('state', 10)->default('ready');
            $table->uuid('issue_id')->nullable();
            $table->dateTime('detected_at', 6)->nullable();
            $table->dateTime('last_reported_at', 6)->nullable();
            $table->unsignedBigInteger('affected_uploads')->default(0);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->string('resolution', 16)->nullable();
            $table->boolean('requires_qualified_rename')->default(false);
            $table->string('resolved_to', 128)->nullable();
            $table->index(['state', 'last_reported_at', 'owner_table'], 'filoquent_owner_reports');
            $table->index(['state', 'owner_table'], 'filoquent_owner_pending');
        });
        Schema::table('filoquent_uploads', function (Blueprint $table): void {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->string('owner_table', 128)->charset('utf8mb4')->collation('utf8mb4_bin')->change();
            } elseif (DB::connection()->getDriverName() === 'sqlsrv') {
                $table->string('owner_table', 128)->collation('Latin1_General_100_BIN2')->change();
            }
            $table->string('journal_owner_table', 128)->nullable();
            $table->index(['owner_table', 'state', 'checked_at', 'id'], 'filoquent_owner_checked');
            $table->index(['owner_table', 'state', 'id'], 'filoquent_owner_uploads');
        });
        Schema::table('filoquent_upload_namespace', function (Blueprint $table): void {
            $table->string('gc_after_table', 128)->nullable();
        });
        $tables = new OwnerTables($connection);
        $after = null;
        $predicate = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'BINARY `owner_table` = ?',
            'sqlite' => '"owner_table" COLLATE BINARY = ?',
            'sqlsrv' => '[owner_table] COLLATE Latin1_General_100_BIN2 = ?',
            default => '"owner_table" = ?',
        };
        $alias = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'COALESCE(`journal_owner_table`, `owner_table`)',
            'sqlsrv' => 'COALESCE([journal_owner_table], [owner_table])',
            default => 'COALESCE("journal_owner_table", "owner_table")',
        };
        do {
            $routes = $connection->table('filoquent_uploads')->useWritePdo()->select('owner_table')->distinct()
                ->when($after !== null, fn (Builder $query): Builder => $query->where('owner_table', '>', $after))
                ->orderBy('owner_table')->limit(500)->pluck('owner_table');
            foreach ($routes as $route) {
                $canonical = $tables->canonical($route, $ledgerSchema);

                if ($canonical !== $route) {
                    $connection->table('filoquent_uploads')->where('owner_table', $route)->whereRaw($predicate, [$route])->update([
                        'journal_owner_table' => $connection->raw($alias),
                        'owner_table' => $canonical,
                    ]);
                }
                $after = $route;
            }
        } while ($routes->count() === 500);
        $connection->table($registry)->insertUsing(['owner_table'], $connection->table('filoquent_uploads')->select('owner_table')->distinct());

        if ($ledgerSchema !== null) {
            $connection->table('filoquent_uploads')->useWritePdo()->select('owner_table')
                ->where('state', 'active')->whereNotLike('journal_owner_table', '%.%')
                ->distinct()->chunkById(500, function ($routes) use ($connection, $registry): void {
                    foreach ($routes as $route) {
                        $connection->table($registry)->where('owner_table', $route->owner_table)->update([
                            'state' => 'pending',
                            'requires_qualified_rename' => true,
                            'issue_id' => (string) Str::uuid7(),
                            'detected_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                            'affected_uploads' => $connection->table('filoquent_uploads')->where('owner_table', $route->owner_table)->where('state', 'active')->count(),
                        ]);
                    }
                }, 'owner_table');
        }
    }

    public function down(): void
    {
        throw_if(DB::table('filoquent_owner_tables')->useWritePdo()->where('state', 'pending')->exists()
            || DB::table('filoquent_uploads')->useWritePdo()->whereNotNull('journal_owner_table')->exists(), FiloquentException::class, 'Resolve pending owner table issues and preserve all recovery aliases before rolling back owner table coordination.');
        Schema::table('filoquent_uploads', function (Blueprint $table): void {
            $table->dropIndex('filoquent_owner_checked');
            $table->dropIndex('filoquent_owner_uploads');
            $table->dropColumn('journal_owner_table');
        });
        Schema::table('filoquent_upload_namespace', function (Blueprint $table): void {
            $table->dropColumn('gc_after_table');
        });
        Schema::drop('filoquent_owner_tables');
    }
};
