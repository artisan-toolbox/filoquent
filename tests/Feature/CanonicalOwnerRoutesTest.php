<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    // SQLite's main schema, attached databases, and case folding are the subject of these fixtures.
    config(['database.connections.canonical_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'], 'database.default' => 'canonical_sqlite']);
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => null]);
    Storage::fake('local');
    Artisan::call('migrate');
    Schema::create('CaseDocuments', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
});

function canonicalOwner(string $table): JournalDocument
{
    $owner = new JournalDocument;
    $owner->setTable($table);
    $owner->setAttribute('attachment', __FILE__);
    $owner->save();

    return $owner;
}

it('shares one canonical gate and upload count across qualified and case aliases', function (): void {
    canonicalOwner('CaseDocuments');
    canonicalOwner('main.casedocuments');
    $tables = new OwnerTables(DB::connection());
    expect(DB::table('filoquent_uploads')->pluck('owner_table')->all())->toBe(['casedocuments', 'casedocuments'])
        ->and(DB::table('filoquent_owner_tables')->pluck('owner_table')->all())->toBe(['casedocuments']);
    $tables->block('main.CASEDOCUMENTS');
    expect($tables->findPending('CaseDocuments')->affected_uploads)->toBe(2)
        ->and($tables->findPending('main.casedocuments'))->toEqual($tables->findPending('CaseDocuments'))
        ->and(fn () => canonicalOwner('CASEdocuments'))->toThrow(FiloquentException::class);
});

it('moves every alias after an observed rename and protects live bytes after old name reuse', function (): void {
    $first = canonicalOwner('CaseDocuments');
    $second = canonicalOwner('main.CaseDocuments');
    $assets = [$first->attachment->default(), $second->attachment->default()];
    Schema::rename('CaseDocuments', 'publications');
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => 1]))->toBe(1);
    Schema::create('CaseDocuments', fn (Blueprint $table) => $table->id());
    $tables = new OwnerTables(DB::connection());
    expect($tables->resolveRename('MAIN.CaseDocuments', 'main.Publications', $tables->findPending('casedocuments')->issue_id))->toBe(2)
        ->and(DB::table('filoquent_uploads')->pluck('owner_table')->all())->toBe(['publications', 'publications']);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    foreach ($assets as $asset) {
        expect($asset->exists())->toBeTrue();
    }
});

it('rejects removal through a case alias and restores through an equivalent qualified route', function (): void {
    canonicalOwner('casedocuments');
    $tables = new OwnerTables(DB::connection());
    $tables->block('CaseDocuments');
    $issue = $tables->findPending('main.CASEDOCUMENTS')->issue_id;
    expect($tables->exists('main.CASEDOCUMENTS'))->toBeTrue()
        ->and(fn () => $tables->resolveRemoved('CASEDOCUMENTS', $issue))->toThrow(FiloquentException::class)
        ->and($tables->resolveRename('CaseDocuments', 'main.CASEDOCUMENTS', $issue))->toBe(1)
        ->and($tables->isPending('casedocuments'))->toBeFalse();
});

it('retires through canonical model routes while new journal intents use that same route', function (): void {
    $owner = canonicalOwner('main.CaseDocuments');
    $receipt = DB::table('filoquent_uploads')->first();
    $intent = UploadIntent::forModel($owner, 'attachment', $receipt->id, $owner->attachment);
    expect($intent->ownerTable)->toBe('casedocuments');
    $owner->setTable('CASEDOCUMENTS');
    $owner->setAttribute('attachment', null);
    $owner->save();
    expect(DB::table('filoquent_uploads')->count())->toBe(0);
});

it('normalizes historic route metadata before registry backfill while retaining original journal identities', function (): void {
    $owner = canonicalOwner('CaseDocuments');
    $first = (array) DB::table('filoquent_uploads')->first();
    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000002_create_filoquent_owner_tables.php';
    $migration->down();
    DB::table('filoquent_uploads')->where('id', $first['id'])->update(['owner_table' => 'main.CaseDocuments']);
    $original = new UploadIntent(UploadLedger::scope(DB::connection()), $first['id'], $first['owner_type'], 'main.CaseDocuments', $first['owner_key_name'], $first['owner_key'], $first['attribute'], $owner->attachment);
    $migration->up();
    $receipt = DB::table('filoquent_uploads')->first();
    expect($receipt->owner_table)->toBe('casedocuments')
        ->and($receipt->journal_owner_table)->toBe('main.CaseDocuments')
        ->and($original->matches($receipt))->toBeTrue()
        ->and(DB::table('filoquent_owner_tables')->pluck('owner_table')->all())->toBe(['casedocuments'])
        ->and(fn () => $migration->down())->toThrow(FiloquentException::class);
    foreach (['publications', 'articles'] as $to) {
        $from = $receipt->owner_table;
        Schema::rename($from, $to);
        $tables = new OwnerTables(DB::connection());
        $tables->block($from);
        $tables->resolveRename($from, $to, $tables->findPending($from)->issue_id);
        $receipt = DB::table('filoquent_uploads')->first();
        expect($original->matches($receipt))->toBeTrue()
            ->and($receipt->journal_owner_table)->toBe('main.CaseDocuments');
    }
});

it('preserves distinct nondefault schemas and logical table prefixes', function (): void {
    DB::statement("ATTACH DATABASE ':memory:' AS tenant");
    Schema::create('tenant.CaseDocuments', fn (Blueprint $table) => $table->id());
    $tables = new OwnerTables(DB::connection());
    expect($tables->canonical('MAIN.CaseDocuments'))->toBe('casedocuments')
        ->and($tables->canonical('tenant.CaseDocuments'))->toBe('tenant.casedocuments')
        ->and($tables->exists('tenant.CASEDOCUMENTS'))->toBeTrue();
    config(['database.connections.canonical_prefixed' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'tenant_']]);
    $connection = DB::connection('canonical_prefixed');
    $connection->getSchemaBuilder()->create('CaseDocuments', fn (Blueprint $table) => $table->id());
    $prefixed = new OwnerTables($connection);
    expect($prefixed->canonical('main.CaseDocuments'))->toBe('casedocuments')
        ->and($prefixed->exists('main.CASEDOCUMENTS'))->toBeTrue();
});

it('keeps missing attached schemas unresolved until the writer can inspect them', function (): void {
    DB::statement("ATTACH DATABASE ':memory:' AS tenant");
    Schema::create('tenant.documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    canonicalOwner('tenant.documents');
    $tables = new OwnerTables(DB::connection());
    $tables->block('tenant.documents');
    $issue = $tables->findPending('tenant.documents')->issue_id;
    DB::statement('DETACH DATABASE tenant');
    expect(fn () => $tables->resolveRemoved('tenant.documents', $issue))->toThrow(FiloquentException::class)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active');
});

it('refuses removal when a compatibility view still exposes the live owner', function (): void {
    $owner = canonicalOwner('CaseDocuments');
    Schema::rename('CaseDocuments', 'publications');
    DB::statement('CREATE VIEW CaseDocuments AS SELECT * FROM publications');
    $tables = new OwnerTables(DB::connection());
    $tables->block('casedocuments');
    expect($tables->exists('casedocuments'))->toBeFalse()
        ->and(fn () => $tables->resolveRemoved('casedocuments', $tables->findPending('casedocuments')->issue_id))->toThrow(FiloquentException::class)
        ->and($owner->attachment->default()->exists())->toBeTrue();
});

it('does not report a reopened physical group twice on the same UTC day', function (): void {
    canonicalOwner('CaseDocuments');
    $tables = new OwnerTables(DB::connection());
    $tables->block('CaseDocuments');
    expect($tables->claimReports())->toHaveCount(1);
    $tables->resolveRename('CaseDocuments', 'main.CASEDOCUMENTS', $tables->findPending('CaseDocuments')->issue_id);
    $tables->block('main.casedocuments');
    expect($tables->claimReports())->toBe([]);
    $this->travel(1)->days();
    expect($tables->claimReports())->toHaveCount(1);
});

it('rejects unqualified attached owners before uploads while allowing their explicit schema', function (): void {
    DB::statement("ATTACH DATABASE ':memory:' AS tenant");
    Schema::create('tenant.documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    expect(fn () => canonicalOwner('documents'))->toThrow(FiloquentException::class)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    $owner = canonicalOwner('tenant.documents');
    expect(DB::table('filoquent_uploads')->value('owner_table'))->toBe('tenant.documents')
        ->and($owner->attachment->default()->exists())->toBeTrue();
});

it('keeps registered pending identities readable when a fallback object appears', function (): void {
    $tables = new OwnerTables(DB::connection());
    $tables->block('missing_documents');
    $issue = $tables->findPending('missing_documents');
    DB::statement("ATTACH DATABASE ':memory:' AS tenant");
    Schema::create('tenant.missing_documents', fn (Blueprint $table) => $table->id());
    expect($tables->findPending('main.MISSING_DOCUMENTS'))->toEqual($issue)
        ->and(fn () => $tables->resolveRemoved('missing_documents', $issue->issue_id))->toThrow(FiloquentException::class)
        ->and($tables->findPending('missing_documents'))->toEqual($issue);
});

it('keeps missing default groups resolvable when another schema shadows their old name', function (): void {
    $owner = canonicalOwner('main.CaseDocuments');
    Schema::rename('CaseDocuments', 'publications');
    DB::statement("ATTACH DATABASE ':memory:' AS tenant");
    Schema::create('tenant.CaseDocuments', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1);
    $tables = new OwnerTables(DB::connection());
    expect($tables->findPending('casedocuments'))->not->toBeNull();
    expect($tables->resolveRename('main.CaseDocuments', 'publications', $tables->findPending('casedocuments')->issue_id))->toBe(1)
        ->and($owner->attachment->default()->exists())->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('owner_table'))->toBe('publications');
});
