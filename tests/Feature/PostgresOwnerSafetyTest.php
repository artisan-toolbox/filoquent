<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

pest()->group('pgsql');

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the PostgreSQL database lane.');
    }
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => null]);
    Storage::fake('local');
    Artisan::call('migrate');
});

function postgresDocumentTable(string $table): void
{
    Schema::create($table, function (Blueprint $blueprint): void {
        $blueprint->id();
        $blueprint->filoquent('attachment')->nullable();
    });
}

function postgresDocument(string $table): JournalDocument
{
    $document = new JournalDocument;
    $document->setTable($table)->setAttribute('attachment', __FILE__)->save();

    return $document;
}

it('keeps live files attached to the writer schema when another tenant runs cleanup', function (): void {
    DB::statement('CREATE SCHEMA tenant_a');
    DB::statement('CREATE SCHEMA tenant_b');

    try {
        postgresDocumentTable('tenant_a.journal_documents');
        postgresDocumentTable('tenant_b.journal_documents');
        foreach (['tenant_a', 'tenant_b'] as $tenant) {
            config(['database.connections.'.$tenant => [...DB::connection()->getConfig(), 'search_path' => [$tenant, 'public']]]);
        }
        $document = new JournalDocument;
        $document->setConnection('tenant_a')->setAttribute('attachment', __FILE__)->save();
        $asset = $document->attachment->default();
        DB::connection('tenant_a')->statement('SET search_path TO tenant_b, public');
        expect((new OwnerTables(DB::connection('tenant_a')))->canonical('journal_documents'))->toBe('tenant_b.journal_documents');
        $options = ['--connection' => 'tenant_b', '--apply' => true];
        expect(DB::connection('tenant_b')->table('journal_documents')->count())->toBe(0);
        expect(Artisan::call('filoquent:gc', $options))->toBe(0);
        $this->travel(25)->hours();
        expect(Artisan::call('filoquent:gc', $options))->toBe(0)
            ->and($asset->exists())->toBeTrue()
            ->and(DB::table('filoquent_uploads')->value('owner_table'))->toBe('tenant_a.journal_documents')
            ->and(DB::table('filoquent_owner_tables')->pluck('owner_table')->all())->toBe(['tenant_a.journal_documents']);
    } finally {
        DB::statement('SET search_path TO public');
        DB::statement('DROP SCHEMA tenant_a CASCADE');
        DB::statement('DROP SCHEMA tenant_b CASCADE');
    }
});

it('coordinates prefixed long and mixed case model names at their exact physical table', function (): void {
    Schema::dropAllTables();
    DB::connection()->setTablePrefix('x_');
    Artisan::call('migrate');
    $name = 'Case'.str_repeat('a', 57);
    postgresDocumentTable($name.'first_alias');
    $asset = postgresDocument($name.'first_alias')->attachment->default();
    postgresDocument('public.'.$name.'second_alias');
    $tables = new OwnerTables(DB::connection());
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($tables->exists('public.'.$name.'second_alias'))->toBeTrue()
        ->and($tables->exists(strtolower($name)))->toBeFalse()
        ->and(DB::table('filoquent_owner_tables')->pluck('owner_table')->all())->toBe(['public.'.$name]);
    $tables->block($name.'first_alias');
    $issue = $tables->findPending('public.'.$name.'second_alias');
    expect($issue->affected_uploads)->toBe(2)
        ->and(fn () => $tables->resolveRemoved($name, $issue->issue_id))->toThrow(FiloquentException::class)
        ->and($tables->resolveRename($name, 'public.'.$name, $issue->issue_id))->toBe(2)
        ->and($asset->exists())->toBeTrue();
});

it('refuses removal through a materialized compatibility view and remaps every live file', function (): void {
    postgresDocumentTable('journal_documents');
    $asset = postgresDocument('journal_documents')->attachment->default();
    Schema::rename('journal_documents', 'archived_documents');
    DB::statement('CREATE MATERIALIZED VIEW journal_documents AS SELECT * FROM archived_documents');

    try {
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1);
        $options = ['--table' => 'journal_documents', '--apply' => true];
        expect(Artisan::call('filoquent:resolve-pending', [...$options, '--removed' => true]))->toBe(1)
            ->and(Artisan::call('filoquent:resolve-pending', [...$options, '--rename-to' => 'journal_documents']))->toBe(1)
            ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active')
            ->and(Artisan::call('filoquent:resolve-pending', [...$options, '--rename-to' => 'public.archived_documents']))->toBe(0);
        $this->travel(25)->hours();
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and($asset->exists())->toBeTrue();
    } finally {
        DB::statement('DROP MATERIALIZED VIEW journal_documents');
    }
});

it('quarantines legacy bare receipts until a qualified destination proves their manifests', function (): void {
    DB::statement('CREATE SCHEMA legacy_owner');

    try {
        postgresDocumentTable('legacy_owner.journal_documents');
        postgresDocumentTable('public.journal_documents');
        $asset = postgresDocument('legacy_owner.journal_documents')->attachment->default();
        $migration = require __DIR__.'/../../database/migrations/2026_11_12_000002_create_filoquent_owner_tables.php';
        $migration->down();
        DB::table('filoquent_uploads')->update(['owner_table' => 'journal_documents']);
        DB::statement('SET search_path TO legacy_owner, public');
        $migration->up();
        DB::statement('SET search_path TO public');
        $tables = new OwnerTables(DB::connection());
        $issue = $tables->findPending('public.journal_documents');
        expect($issue)->not->toBeNull()
            ->and($issue->affected_uploads)->toBe(1)
            ->and(DB::table('filoquent_uploads')->value('journal_owner_table'))->toBe('journal_documents')
            ->and(fn () => $tables->resolveRename('public.journal_documents', 'public.journal_documents', $issue->issue_id))->toThrow(FiloquentException::class);
        Schema::drop('public.journal_documents');
        DB::statement('SET search_path TO legacy_owner, public');
        expect(fn () => $tables->resolveRemoved('public.journal_documents', $issue->issue_id))->toThrow(FiloquentException::class)
            ->and(fn () => $tables->resolveRename('public.journal_documents', 'journal_documents', $issue->issue_id))->toThrow(FiloquentException::class)
            ->and($tables->resolveRename('public.journal_documents', 'legacy_owner.journal_documents', $issue->issue_id))->toBe(1)
            ->and(DB::table('filoquent_uploads')->value('owner_table'))->toBe('legacy_owner.journal_documents')
            ->and(DB::table('filoquent_uploads')->value('journal_owner_table'))->toBe('journal_documents');
        $this->travel(25)->hours();
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)->and($asset->exists())->toBeTrue();
    } finally {
        DB::statement('SET search_path TO public');
        DB::statement('DROP SCHEMA legacy_owner CASCADE');
    }
});
