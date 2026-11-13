<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Asset;
use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Enums\OwnerTableState;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Illuminate\Database\Schema\Blueprint;
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

it('keeps writing confirmation and retirement stored as raw SQL strings', function (): void {
    $document = GcDocument::query()->create();
    $id = (string) Str::uuid7();
    $assets = new Assets(['original' => new Asset('local', 'assets/generations/'.$id.'/file.txt')]);
    $intent = UploadIntent::forModel($document, 'attachment', $id, $assets);

    DB::transaction(function () use ($document, $intent, $assets): void {
        UploadLedger::reserve($document, $intent);
        expect(DB::table('filoquent_uploads')->value('state'))->toBe('writing');
        UploadLedger::confirm($document, $intent);
        expect(DB::table('filoquent_uploads')->value('state'))->toBe('active');
        expect(UploadLedger::retire($document, $assets))->toBe($intent->id)
            ->and(DB::table('filoquent_uploads')->value('state'))->toBe('retired');
    });

    expect(UploadLedger::committed(DB::connection(), $intent))->toBeTrue();
});

it('promotes an existing journal intent to a raw pending string with its grace period intact', function (): void {
    $document = GcDocument::query()->create();
    $id = (string) Str::uuid7();
    $assets = new Assets(['original' => new Asset('local', 'assets/generations/'.$id.'/file.txt')]);
    $intent = UploadIntent::forModel($document, 'attachment', $id, $assets);

    UploadLedger::promote(DB::connection(), $intent);

    expect(DB::table('filoquent_uploads')->value('state'))->toBe('pending')
        ->and(UploadLedger::committed(DB::connection(), $intent))->toBeTrue()
        ->and(UploadLedger::retirePending(DB::connection(), $id))->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending');
});

it('keeps the historical owner registry default compatible with the ready enum value', function (): void {
    DB::table('filoquent_owner_tables')->insert(['owner_table' => DatabaseFixtures::ownerRoute('gc_documents')]);

    expect(DB::table('filoquent_owner_tables')->value('state'))->toBe('ready')
        ->toBe(OwnerTableState::Ready->value);
});

it('stores operator resolutions and reopened issues as raw SQL strings', function (bool $rename): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $tables = new OwnerTables(DB::connection());
    $route = DatabaseFixtures::ownerRoute('gc_documents');

    expect(DB::table('filoquent_owner_tables')->where('owner_table', $route)->value('state'))->toBe('ready');
    Schema::rename('gc_documents', 'publications');
    $tables->block('gc_documents');
    $issue = $tables->findPending('gc_documents');
    expect($issue?->state)->toBe('pending');

    $affected = $rename
        ? $tables->resolveRename('gc_documents', 'publications', $issue->issue_id)
        : $tables->resolveRemoved('gc_documents', $issue->issue_id);

    $row = DB::table('filoquent_owner_tables')->where('owner_table', $route)->first();
    expect($affected)->toBe(1)
        ->and($row->state)->toBe('ready')
        ->and($row->resolution)->toBe($rename ? 'renamed' : 'removed')
        ->and($row->resolved_to)->toBe($rename ? DatabaseFixtures::ownerRoute('publications') : null)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe($rename ? 'active' : 'pending');

    $tables->block('gc_documents');
    $reopened = $tables->findPending('gc_documents');
    expect($reopened?->state)->toBe('pending')
        ->and($reopened?->resolution)->toBeNull()
        ->and($reopened?->resolved_to)->toBeNull();
})->with([true, false]);
