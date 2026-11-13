<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('compiles ledger indexes within the MySQL identifier limit', function (): void {
    config(['database.connections.compiled_mysql' => [
        'driver' => 'mysql', 'database' => 'unused', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ]]);
    $original = Schema::getFacadeRoot();
    $connection = DB::connection('compiled_mysql');
    Schema::swap($connection->getSchemaBuilder());

    try {
        $statements = $connection->pretend(function (): void {
            (require dirname(__DIR__, 2).'/database/migrations/2026_11_12_000000_create_filoquent_uploads_table.php')->up();
        });
    } finally {
        Schema::swap($original);
    }

    $indexes = 0;
    $columns = implode("\n", array_column($statements, 'query'));
    expect($columns)->toContain('`checked_at` datetime(6)', '`orphaned_at` datetime(6)')
        ->not->toContain('`checked_at` timestamp', '`orphaned_at` timestamp');
    foreach ($statements as $statement) {
        if (preg_match('/ add index `([^`]+)`/i', $statement['query'], $match)) {
            $indexes++;
            expect(strlen($match[1]))->toBeLessThanOrEqual(64);
        }
    }
    expect($indexes)->toBeGreaterThan(0);
});

it('upgrades an existing ledger without rewriting its committed receipts', function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_12_000000_create_filoquent_uploads_table.php')->up();
    DB::table('filoquent_uploads')->insert([
        'id' => (string) Str::uuid7(), 'owner_type' => 'Document', 'owner_table' => 'documents', 'owner_key_name' => 'id',
        'owner_key' => '42', 'attribute' => 'attachment', 'manifest' => '{}', 'state' => 'active',
    ]);
    $before = DB::table('filoquent_uploads')->first();
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_12_000001_add_filoquent_upload_journal.php')->up();
    $scope = DB::table('filoquent_upload_namespace')->value('namespace');
    expect(is_string($scope) && Str::isUuid($scope))->toBeTrue()
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before);
    DB::table('filoquent_uploads')->update(['owner_key' => null]);
    expect(DB::table('filoquent_uploads')->value('owner_key'))->toBeNull()
        ->and(DB::table('filoquent_upload_namespace')->value('namespace'))->toBe($scope);
});

it('refuses journal migration rollback while pending work needs its SQL namespace', function (): void {
    Artisan::call('migrate');
    DB::table('filoquent_uploads')->insert([
        'id' => (string) Str::uuid7(), 'owner_type' => 'Document', 'owner_table' => 'documents', 'owner_key_name' => 'id',
        'owner_key' => '42', 'attribute' => 'attachment', 'manifest' => '{}', 'state' => 'pending',
    ]);
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_12_000001_add_filoquent_upload_journal.php';
    expect(fn () => $migration->down())->toThrow(FiloquentException::class, 'clean up pending uploads')
        ->and(Schema::hasTable('filoquent_upload_namespace'))->toBeTrue()
        ->and(DB::table('filoquent_uploads')->count())->toBe(1);
});
