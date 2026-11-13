<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Fixtures\MaintenanceLogger;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => null]);
    Artisan::call('migrate');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->text('notes')->nullable();
        $table->softDeletes();
    });
});

it('groups missing uploads and emits only one error per table per UTC day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 23:59:59 UTC'));
    $logger = new MaintenanceLogger;
    Log::swap($logger);
    $assets = [];
    foreach (range(1, 3) as $index) {
        $assets[] = GcDocument::query()->create(['attachment' => __FILE__])->getAttribute('attachment')->default();
    }
    Schema::rename('gc_documents', 'renamed_group_documents');
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => 1, '--silent' => true]))->toBe(1);
    $issue = (new OwnerTables(DB::connection()))->findPending('gc_documents');
    expect($issue?->affected_uploads)->toBe(3);

    foreach (range(1, 3) as $attempt) {
        expect(Artisan::call('filoquent:gc', ['--apply' => true, '--silent' => true]))->toBe(1);
    }
    $errors = collect($logger->records)->where('level', 'error')->values();
    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe('Filoquent owner table requires explicit resolution.')
        ->and($errors[0]['context']['active_uploads_at_detection'])->toBe(3);

    $this->travel(2)->seconds();
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--silent' => true]))->toBe(1)
        ->and(collect($logger->records)->where('level', 'error'))->toHaveCount(2);

    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
    });
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--silent' => true]))->toBe(1);
    foreach ($assets as $asset) {
        expect($asset->exists())->toBeTrue();
    }
    expect(DB::table('filoquent_uploads')->where('state', 'active')->count())->toBe(3)
        ->and(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--rename-to' => 'renamed_group_documents', '--apply' => true, '--silent' => true]))->toBe(0)
        ->and(Artisan::call('filoquent:gc', ['--apply' => true, '--silent' => true]))->toBe(0)
        ->and(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(0);
});

it('reaches a healthy table without traversing a million blocked receipts', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $preserved = $owner->getAttribute('attachment')->default();
    DatabaseFixtures::seedReceipts(999999, 'gc_documents', GcDocument::class);
    Schema::drop('gc_documents');
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => 1, '--silent' => true]))->toBe(1)
        ->and((new OwnerTables(DB::connection()))->findPending('gc_documents')?->affected_uploads)->toBe(1_000_000);
    Schema::create('healthy_group_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->softDeletes();
    });
    $healthy = new GcDocument;
    $healthy->setTable('healthy_group_documents');
    $healthy->setAttribute('attachment', __FILE__)->save();
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => 1, '--silent' => true]))->toBe(1)
        ->and($preserved->exists())->toBeTrue()
        ->and($healthy->getAttribute('attachment')->default()->exists())->toBeTrue();
    $queries = $connection->getQueryLog();
    $connection->disableQueryLog();
    $selection = collect($queries)->first(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
        && str_contains($query['query'], 'filoquent_uploads')
        && str_contains($query['query'], 'checked_at')
        && in_array(DatabaseFixtures::ownerRoute('healthy_group_documents'), $query['bindings'], true)
        && in_array('active', $query['bindings'], true));
    expect($selection)->not->toBeNull();
    DatabaseFixtures::assertIndexed($selection['query'], array_values($selection['bindings']), 'filoquent_owner_checked');
    $healthyReceipt = DB::table('filoquent_uploads')->where('owner_table', DatabaseFixtures::ownerRoute('healthy_group_documents'))->first();
    expect($healthyReceipt?->orphaned_at)->toBeNull();
});

it('keeps case-insensitive physical table names live instead of offering destructive resolution', function (): void {
    // This fixture verifies SQLite case folding, which PostgreSQL and Linux MySQL do not provide.
    config(['database.connections.grouped_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'], 'database.default' => 'grouped_sqlite']);
    Artisan::call('migrate');
    Schema::create('CaseDocuments', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->softDeletes();
    });
    $owner = new GcDocument;
    $owner->setTable('casedocuments');
    $owner->setAttribute('attachment', __FILE__)->save();
    $asset = $owner->getAttribute('attachment')->default();

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and((new OwnerTables(DB::connection()))->findPending('casedocuments'))->toBeNull()
        ->and(Artisan::call('filoquent:resolve-pending', ['--table' => 'casedocuments', '--removed' => true, '--apply' => true]))->toBe(1);
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($asset->exists())->toBeTrue()
        ->and(DB::table('CaseDocuments')->count())->toBe(1);
});

it('attempts every daily group report even when one logging call fails', function (): void {
    $logger = new MaintenanceLogger;
    $attempts = 0;
    $logger->beforeLog = static function (string $message) use (&$attempts): void {
        throw_if($message === 'Filoquent owner table requires explicit resolution.' && ++$attempts === 1, RuntimeException::class, 'The first log transport failed.');
    };
    Log::swap($logger);
    foreach (['first_group', 'second_group', 'third_group'] as $table) {
        Schema::create($table, function (Blueprint $definition): void {
            $definition->id();
            $definition->filoquent('attachment')->nullable();
        });
        $owner = new GcDocument;
        $owner->setTable($table)->setAttribute('attachment', __FILE__)->save();
        Schema::drop($table);
    }

    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--silent' => true]))->toBe(1)
        ->and($attempts)->toBe(3)
        ->and(collect($logger->records)->where('message', 'Filoquent owner table requires explicit resolution.'))->toHaveCount(2);
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--silent' => true]))->toBe(1)
        ->and($attempts)->toBe(3);
});

it('blocks saveOrIgnore on a pending route after its name is reused', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    Schema::rename('gc_documents', 'held_group_documents');
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1);
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    $newOwner = new GcDocument;
    $newOwner->setAttribute('id', 99);

    expect(fn (): bool => $newOwner->saveOrIgnore(uniqueBy: 'id'))->toThrow(FiloquentException::class)
        ->and(DB::table('gc_documents')->count())->toBe(0)
        ->and($newOwner->exists)->toBeFalse()
        ->and($asset->exists())->toBeTrue();
});
