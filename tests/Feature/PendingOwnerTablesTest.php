<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => null]);
    Storage::fake('local');
    Artisan::call('migrate');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->softDeletes();
    });
});

it('requires a transaction for shared guards and keeps pending groups blocked after table name reuse', function (): void {
    $tables = new OwnerTables(DB::connection());
    expect(fn () => $tables->guard('gc_documents'))->toThrow(FiloquentException::class);
    DB::transaction(fn () => $tables->guard('gc_documents'));
    expect($tables->isPending('gc_documents'))->toBeFalse();
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'publications');
    $tables->block('gc_documents');
    $issue = $tables->findPending('gc_documents');
    Schema::create('gc_documents', fn (Blueprint $table) => $table->id());
    $tables->block('gc_documents');
    expect($tables->findPending('gc_documents'))->toEqual($issue)
        ->and(fn () => DB::transaction(fn () => $tables->guard('gc_documents')))->toThrow(FiloquentException::class);
    DB::transaction(fn () => $tables->guard('unrelated_documents'));
    expect($tables->isPending('unrelated_documents'))->toBeFalse();
});

it('reads pending state without creating rows and snapshots only active uploads once at detection', function (): void {
    $tables = new OwnerTables(DB::connection());
    expect($tables->isPending('unknown_documents'))->toBeFalse()
        ->and(DB::table('filoquent_owner_tables')->count())->toBe(0);
    GcDocument::query()->create(['attachment' => __FILE__]);
    $receipt = (array) DB::table('filoquent_uploads')->first();
    DB::table('filoquent_uploads')->insert([...$receipt, 'id' => (string) Str::uuid7(), 'state' => 'pending']);
    Schema::drop('gc_documents');
    DB::connection()->enableQueryLog();
    $tables->block('gc_documents');
    $tables->block('gc_documents');
    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();
    expect($tables->findPending('gc_documents')->affected_uploads)->toBe(1)
        ->and(array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')))->toHaveCount(1)
        ->and(DatabaseFixtures::sql(implode(' ', array_column($queries, 'query'))))->toContain('"owner_table" = ?', '"state" = ?');
});

it('claims one report per issue per UTC day without recounting receipts', function (): void {
    $tables = new OwnerTables(DB::connection());
    $this->travelTo(CarbonImmutable::parse('2026-11-12T23:59:59Z'));
    $tables->block('missing_documents');
    expect($tables->claimReports())->toHaveCount(1)
        ->and($tables->claimReports())->toBe([]);
    $this->travelTo(CarbonImmutable::parse('2026-11-13T00:00:00Z'));
    DB::connection()->enableQueryLog();
    expect($tables->claimReports())->toHaveCount(1)
        ->and($tables->claimReports())->toBe([]);
    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();
    expect(DatabaseFixtures::sql(implode(' ', array_column($queries, 'query'))))->not->toContain('filoquent_uploads', 'count(');
});

it('pages pending groups by exact table cursor and rejects unbounded limits', function (): void {
    $tables = new OwnerTables(DB::connection());
    foreach (['z_documents', 'a_documents', 'm_documents'] as $table) {
        $tables->block($table);
    }
    expect($tables->pending(2)->pluck('owner_table')->all())->toBe([DatabaseFixtures::ownerRoute('a_documents'), DatabaseFixtures::ownerRoute('m_documents')])
        ->and($tables->pending(2, DatabaseFixtures::ownerRoute('m_documents'))->pluck('owner_table')->all())->toBe([DatabaseFixtures::ownerRoute('z_documents')])
        ->and(fn () => $tables->pending(1001))->toThrow(FiloquentException::class);
});

it('inspects physical writer tables with prefixes and same-schema qualifiers while rejecting views', function (): void {
    $tables = new OwnerTables(DB::connection());
    DB::statement('CREATE VIEW document_view AS SELECT id FROM gc_documents');
    $connection = DB::connection();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    $connection->forgetRecordModificationState();

    try {
        expect($tables->exists('gc_documents'))->toBeTrue()
            ->and($tables->exists(match (DB::getDriverName()) {
                'mysql' => DB::connection()->getDatabaseName().'.gc_documents', 'pgsql' => 'public.gc_documents', default => 'main.gc_documents',
            }))->toBeTrue()
            ->and($tables->exists('document_view'))->toBeFalse()
            ->and($tables->exists('missing_documents'))->toBeFalse()
            ->and(fn () => $tables->exists('other.gc_documents'))->toThrow(FiloquentException::class);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
    config(['database.connections.pending_prefixed' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'tenant_']]);
    $prefixed = DB::connection('pending_prefixed');
    $prefixed->getSchemaBuilder()->create('documents', fn (Blueprint $table) => $table->id());
    expect((new OwnerTables($prefixed))->exists('documents'))->toBeTrue();
});

it('propagates schema failures without reporting a table as missing', function (): void {
    $connection = DB::connection();
    $connection->beforeExecuting(function (): never {
        throw new RuntimeException('Writer unavailable.');
    });
    expect(fn () => (new OwnerTables($connection))->exists('gc_documents'))->toThrow(RuntimeException::class, 'Writer unavailable.');
});

it('remaps exact manifests in renamed columns without touching files or provenance and preserves the first journal route', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $before = DB::table('filoquent_uploads')->first();
    Schema::rename('gc_documents', 'publications');
    Schema::table('publications', fn (Blueprint $table) => $table->renameColumn('attachment', 'document'));
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    Storage::swap(Mockery::mock(FilesystemManager::class));
    expect($tables->resolveRename('gc_documents', 'publications', $tables->findPending('gc_documents')->issue_id))->toBe(1);
    $after = DB::table('filoquent_uploads')->first();
    expect($after->owner_table)->toBe(DatabaseFixtures::ownerRoute('publications'))
        ->and($after->journal_owner_table)->toBe(DatabaseFixtures::ownerRoute('gc_documents'))
        ->and($after->owner_type)->toBe($before->owner_type)
        ->and($after->attribute)->toBe($before->attribute)
        ->and($after->manifest)->toBe($before->manifest)
        ->and($after->id)->toBe($before->id)
        ->and($tables->isPending('gc_documents'))->toBeFalse();
    Schema::rename('publications', 'articles');
    $tables->block('publications');
    expect($tables->resolveRename('publications', 'articles', $tables->findPending('publications')->issue_id))->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('journal_owner_table'))->toBe(DatabaseFixtures::ownerRoute('gc_documents'));
});

it('verifies a restored original table before clearing its pending issue', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    $issue = $tables->findPending('gc_documents')->issue_id;
    expect(fn () => $tables->resolveRemoved('gc_documents', $issue))->toThrow(FiloquentException::class)
        ->and($tables->resolveRename('gc_documents', 'gc_documents', $issue))->toBe(1)
        ->and($tables->isPending('gc_documents'))->toBeFalse();
});

it('atomically validates every UUID page before remapping a large upload group', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $template = (array) DB::table('filoquent_uploads')->first();
    $rows = [];
    $owners = [];
    for ($index = 1; $index <= 501; $index++) {
        $id = sprintf('00000000-0000-7000-8000-%012d', $index);
        $manifest = json_decode($template['manifest'], true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest as &$asset) {
            $asset['path'] = str_replace($template['id'], $id, $asset['path']);
        }
        unset($asset);
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
        $rows[] = [...$template, 'id' => $id, 'owner_key' => (string) $index, 'manifest' => $encoded];
        $owners[] = ['id' => $index, 'attachment' => $index === 501 ? null : $encoded];
    }
    DB::table('filoquent_uploads')->delete();
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('filoquent_uploads')->insert($chunk);
    }
    Schema::rename('gc_documents', 'publications');
    DB::table('publications')->delete();
    foreach (array_chunk($owners, 100) as $chunk) {
        DB::table('publications')->insert($chunk);
    }
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    $issue = $tables->findPending('gc_documents')->issue_id;
    expect(fn () => $tables->resolveRename('gc_documents', 'publications', $issue))->toThrow(FiloquentException::class)
        ->and(DB::table('filoquent_uploads')->where('owner_table', DatabaseFixtures::ownerRoute('gc_documents'))->count())->toBe(501)
        ->and($tables->isPending('gc_documents'))->toBeTrue()
        ->and(DB::table('filoquent_owner_tables')->where('owner_table', DatabaseFixtures::ownerRoute('publications'))->exists())->toBeFalse();
    DB::table('publications')->where('id', 501)->update(['attachment' => $rows[500]['manifest']]);
    DB::connection()->enableQueryLog();
    expect($tables->resolveRename('gc_documents', 'publications', $issue))->toBe(501);
    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();
    $ownerSelect = 'select * from '.DatabaseFixtures::sql(DB::connection()->getQueryGrammar()->wrapTable(DatabaseFixtures::ownerRoute('publications')));
    $ownerReads = array_filter($queries, fn (array $query): bool => str_starts_with(DatabaseFixtures::sql($query['query']), $ownerSelect));
    $receiptUpdates = array_filter($queries, fn (array $query): bool => str_starts_with(DatabaseFixtures::sql($query['query']), 'update "filoquent_uploads"'));
    expect($ownerReads)->toHaveCount(2)->and($receiptUpdates)->toHaveCount(1);
    foreach ($ownerReads as $query) {
        expect(count($query['bindings']))->toBeLessThanOrEqual(500);
    }
});

it('rolls back route mutations when closing the issue fails after the bulk receipt update', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'publications');
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    $before = DB::table('filoquent_uploads')->first();
    $issue = $tables->findPending('gc_documents');
    DB::connection()->beforeExecuting(function (string $query): void {
        throw_if(str_starts_with(DatabaseFixtures::sql($query), 'update "filoquent_owner_tables"'), RuntimeException::class, 'Simulated issue close failure.');
    });
    expect(fn () => $tables->resolveRename('gc_documents', 'publications', $issue->issue_id))->toThrow(RuntimeException::class)
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before)
        ->and($tables->findPending('gc_documents'))->toEqual($issue)
        ->and(DB::table('filoquent_owner_tables')->where('owner_table', DatabaseFixtures::ownerRoute('publications'))->exists())->toBeFalse();
});

it('moves only active uploads into a fresh SQL cleanup grace period after explicit removal', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $receipt = (array) DB::table('filoquent_uploads')->first();
    foreach (['pending', 'retired'] as $state) {
        DB::table('filoquent_uploads')->insert([...$receipt, 'id' => (string) Str::uuid7(), 'state' => $state]);
    }
    $untouched = DB::table('filoquent_uploads')->where('state', '!=', 'active')->orderBy('id')->get()->all();
    Schema::drop('gc_documents');
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    $this->travelTo(CarbonImmutable::parse('2026-11-12T15:00:00Z'));
    Storage::swap(Mockery::mock(FilesystemManager::class));
    expect($tables->resolveRemoved('gc_documents', $tables->findPending('gc_documents')->issue_id))->toBe(1);
    $after = DB::table('filoquent_uploads')->where('id', $receipt['id'])->first();
    expect($after->state)->toBe('pending')
        ->and(CarbonImmutable::parse($after->orphaned_at, 'UTC')->format('Y-m-d H:i:s.u'))->toBe('2026-11-12 15:00:00.000000')
        ->and(CarbonImmutable::parse($after->checked_at, 'UTC')->format('Y-m-d H:i:s.u'))->toBe('2026-11-13 15:00:00.000000')
        ->and($after->manifest)->toBe($receipt['manifest'])
        ->and(DB::table('filoquent_uploads')->where('id', '!=', $receipt['id'])->orderBy('id')->get()->all())->toEqual($untouched)
        ->and($tables->isPending('gc_documents'))->toBeFalse();
    $tables->block('gc_documents');
    expect($tables->findPending('gc_documents')->affected_uploads)->toBe(0);
});

it('rejects stale confirmations pending targets and outer transactions without moving receipts', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'publications');
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    $issue = $tables->findPending('gc_documents')->issue_id;
    expect(fn () => $tables->resolveRename('gc_documents', 'publications', (string) Str::uuid7()))->toThrow(FiloquentException::class);
    $tables->block('publications');
    expect(fn () => $tables->resolveRename('gc_documents', 'publications', $issue))->toThrow(FiloquentException::class);
    DB::transaction(function () use ($tables, $issue): void {
        expect(fn () => $tables->block('gc_documents'))->toThrow(FiloquentException::class)
            ->and(fn () => $tables->resolveRemoved('gc_documents', $issue))->toThrow(FiloquentException::class)
            ->and(fn () => $tables->claimReports())->toThrow(FiloquentException::class);
    });
    expect(DB::table('filoquent_uploads')->value('owner_table'))->toBe(DatabaseFixtures::ownerRoute('gc_documents'));
});

it('rejects invalid or protected owner routes before changing coordination', function (string $table): void {
    $tables = new OwnerTables(DB::connection());
    expect(fn () => $tables->block($table))->toThrow(FiloquentException::class)
        ->and(DB::table('filoquent_owner_tables')->count())->toBe(0);
})->with(['', '0', 'bad-name', 'filoquent_uploads', 'filoquent_upload_namespace', 'filoquent_owner_tables', 'migrations', 'main.FILOQUENT_UPLOADS']);

it('backfills existing routes using a database insert select and safely rolls back only unused coordination', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000002_create_filoquent_owner_tables.php';
    $migration->down();
    DB::connection()->enableQueryLog();
    $migration->up();
    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();
    expect(DB::table('filoquent_owner_tables')->pluck('owner_table')->all())->toBe([DatabaseFixtures::ownerRoute('gc_documents')])
        ->and(DB::table('filoquent_owner_tables')->value('state'))->toBe('ready')
        ->and(DatabaseFixtures::sql(implode(' ', array_column($queries, 'query'))))->toContain('select distinct "owner_table" from "filoquent_uploads"');
    $tables = new OwnerTables(DB::connection());
    $tables->block('gc_documents');
    expect(fn () => $migration->down())->toThrow(FiloquentException::class)
        ->and(Schema::hasColumn('filoquent_uploads', 'journal_owner_table'))->toBeTrue();
    $tables->resolveRename('gc_documents', 'gc_documents', $tables->findPending('gc_documents')->issue_id);
    expect(fn () => $migration->down())->toThrow(FiloquentException::class)
        ->and(Schema::hasTable('filoquent_owner_tables'))->toBeTrue();
});
