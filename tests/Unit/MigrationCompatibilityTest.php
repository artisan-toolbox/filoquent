<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('installs the complete ledger schema and a stable journal namespace', function (): void {
    expect(Artisan::call('migrate'))->toBe(0);
    expect(Schema::getColumnListing('filoquent_uploads'))->toBe([
        'id', 'owner_type', 'owner_table', 'owner_key_name', 'owner_key', 'attribute', 'manifest',
        'state', 'checked_at', 'orphaned_at', 'journal_owner_table',
    ])->and(Schema::getColumnListing('filoquent_upload_namespace'))->toBe(['id', 'namespace', 'gc_after_table'])
        ->and(Schema::getColumnListing('filoquent_owner_tables'))->toBe([
            'owner_table', 'state', 'issue_id', 'detected_at', 'last_reported_at', 'affected_uploads',
            'resolved_at', 'resolution', 'requires_qualified_rename', 'resolved_to',
        ]);

    foreach (['owner_key', 'journal_owner_table', 'orphaned_at'] as $column) {
        expect(collect(Schema::getColumns('filoquent_uploads'))->firstWhere('name', $column)['nullable'])->toBeTrue();
    }
    foreach ([['state', 'id'], ['state', 'checked_at', 'id'], ['owner_table', 'state', 'checked_at', 'id'], ['owner_table', 'state', 'id']] as $columns) {
        expect(Schema::hasIndex('filoquent_uploads', $columns))->toBeTrue();
    }
    foreach ([['state', 'last_reported_at', 'owner_table'], ['state', 'owner_table']] as $columns) {
        expect(Schema::hasIndex('filoquent_owner_tables', $columns))->toBeTrue();
    }
    $scope = DB::table('filoquent_upload_namespace')->where('id', 1)->value('namespace');
    expect(is_string($scope) && Str::isUuid($scope))->toBeTrue()
        ->and(DB::table('filoquent_upload_namespace')->count())->toBe(1)
        ->and(DB::table('filoquent_upload_namespace')->value('gc_after_table'))->toBeNull();

    DB::table('filoquent_owner_tables')->insert(['owner_table' => 'documents']);
    $owner = DB::table('filoquent_owner_tables')->first();
    expect($owner->state)->toBe('ready')
        ->and((int) $owner->affected_uploads)->toBe(0)
        ->and((bool) $owner->requires_qualified_rename)->toBeFalse();
    expect(Artisan::call('migrate'))->toBe(0)
        ->and(DB::table('filoquent_upload_namespace')->value('namespace'))->toBe($scope);
});

it('compiles ledger indexes within the MySQL identifier limit', function (): void {
    config(['database.connections.compiled_mysql' => [
        'driver' => 'mysql', 'database' => 'unused', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ]]);
    $original = Schema::getFacadeRoot();
    $default = DB::getDefaultConnection();
    $connection = DB::connection('compiled_mysql');
    $connection->setPdo(new PDO('sqlite::memory:'));
    Schema::swap($connection->getSchemaBuilder());
    DB::setDefaultConnection('compiled_mysql');

    try {
        $statements = $connection->pretend(function (): void {
            (require dirname(__DIR__, 2).'/database/migrations/2026_11_12_000000_create_filoquent_tables.php')->up();
        });
    } finally {
        Schema::swap($original);
        DB::setDefaultConnection($default);
    }

    $indexes = 0;
    $columns = implode("\n", array_column($statements, 'query'));
    expect($columns)->toContain('`checked_at` datetime(6)', '`orphaned_at` datetime(6)')
        ->not->toContain('`checked_at` timestamp', '`orphaned_at` timestamp')
        ->and(substr_count($columns, 'collate \'utf8mb4_bin\''))->toBe(2);
    foreach ($statements as $statement) {
        if (preg_match('/ add index `([^`]+)`/i', $statement['query'], $match)) {
            $indexes++;
            expect(strlen($match[1]))->toBeLessThanOrEqual(64);
        }
    }
    expect($indexes)->toBeGreaterThan(0);
});

it('accepts pending receipts without an owner key in the installed schema', function (): void {
    Artisan::call('migrate');
    $scope = DB::table('filoquent_upload_namespace')->value('namespace');
    DB::table('filoquent_uploads')->insert([
        'id' => (string) Str::uuid7(), 'owner_type' => 'Document', 'owner_table' => 'documents', 'owner_key_name' => 'id',
        'owner_key' => null, 'attribute' => 'attachment', 'manifest' => '{}', 'state' => 'pending',
    ]);
    expect(DB::table('filoquent_uploads')->value('owner_key'))->toBeNull()
        ->and(DB::table('filoquent_upload_namespace')->value('namespace'))->toBe($scope);
});

it('preserves every table and receipt when rollback would discard recovery state', function (string $reason): void {
    config(['filoquent.redis.database' => null]);
    Artisan::call('migrate');
    DB::table('filoquent_uploads')->insert([
        'id' => (string) Str::uuid7(), 'owner_type' => 'Document', 'owner_table' => 'documents', 'owner_key_name' => 'id',
        'owner_key' => '42', 'attribute' => 'attachment', 'manifest' => '{}', 'state' => 'active',
    ]);

    if ($reason === 'redis') {
        config(['filoquent.redis.database' => 0]);
    } elseif ($reason === 'owner-group') {
        DB::table('filoquent_owner_tables')->insert(['owner_table' => 'documents', 'state' => 'pending']);
    } else {
        DB::table('filoquent_uploads')->update(match ($reason) {
            'owner-key' => ['owner_key' => null],
            'alias' => ['journal_owner_table' => 'old_documents'],
            default => ['state' => $reason],
        });
    }
    $before = DB::table('filoquent_uploads')->first();
    $scope = DB::table('filoquent_upload_namespace')->value('namespace');
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_12_000000_create_filoquent_tables.php';
    expect(fn () => $migration->down())->toThrow(FiloquentException::class)
        ->and(Schema::hasTable('filoquent_owner_tables'))->toBeTrue()
        ->and(Schema::hasTable('filoquent_uploads'))->toBeTrue()
        ->and(Schema::hasTable('filoquent_upload_namespace'))->toBeTrue()
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before)
        ->and(DB::table('filoquent_upload_namespace')->value('namespace'))->toBe($scope);
})->with(['pending', 'writing', 'owner-key', 'redis', 'owner-group', 'alias']);

it('rolls back and reinstalls all package tables in one migration step', function (): void {
    config(['filoquent.redis.database' => null]);
    expect(Artisan::call('migrate'))->toBe(0)
        ->and(DB::table('migrations')->whereLike('migration', '%filoquent%')->count())->toBe(1);
    $scope = DB::table('filoquent_upload_namespace')->value('namespace');
    expect(Artisan::call('migrate:rollback', ['--step' => 1]))->toBe(0);
    foreach (['filoquent_owner_tables', 'filoquent_upload_namespace', 'filoquent_uploads'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Artisan::call('migrate'))->toBe(0)
        ->and(DB::table('filoquent_upload_namespace')->value('namespace'))->not->toBe($scope)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0)
        ->and(DB::table('filoquent_owner_tables')->count())->toBe(0);
});
