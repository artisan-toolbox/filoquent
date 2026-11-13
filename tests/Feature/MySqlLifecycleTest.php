<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\InMemoryUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use ArtisanToolbox\Filoquent\Tests\Support\TestDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('keeps the full cleanup grace period across MySQL session time zones', function (bool $pending): void {
    $socket = getenv('FILOQUENT_TEST_MYSQL_SOCKET');
    $host = getenv('FILOQUENT_TEST_MYSQL_HOST');

    if (! $socket && ! $host) {
        $this->markTestSkipped('An isolated MySQL server is required for this integration test.');
    }
    $configuration = TestDatabase::configuration('mysql');
    config([
        'database.default' => 'mysql_east',
        'database.connections.mysql_east' => array_replace($configuration, ['timezone' => '+14:00']),
        'database.connections.mysql_west' => array_replace($configuration, ['timezone' => '-12:00']),
        'filoquent.filesystem' => 'mysql_uploads', 'filoquent.redis.database' => $pending ? 2 : null,
    ]);
    Storage::fake('mysql_uploads');
    app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
    CarbonImmutable::setTestNow('2026-11-12 15:00:00 UTC');

    try {
        Artisan::call('migrate:fresh', ['--force' => true]);
        Schema::create('journal_documents', function (Blueprint $table): void {
            $table->id();
            $table->filoquent('attachment')->nullable();
        });

        if ($pending) {
            DB::beginTransaction();
        }
        $document = JournalDocument::query()->create(['attachment' => __FILE__]);
        $asset = $document->attachment?->default();

        if ($pending) {
            DB::rollBack();
        } else {
            DB::table('journal_documents')->delete();
        }

        expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'mysql_east']))->toBe(0)
            ->and(DB::connection('mysql_east')->table('filoquent_uploads')->value('orphaned_at'))->toBe('2026-11-12 15:00:00.000000')
            ->and(DB::connection('mysql_west')->table('filoquent_uploads')->value('orphaned_at'))->toBe('2026-11-12 15:00:00.000000');
        expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'mysql_west']))->toBe(0)
            ->and($asset?->exists())->toBeTrue()
            ->and(DB::table('filoquent_uploads')->count())->toBe(1);

        $this->travel(24)->hours();
        expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'mysql_west']))->toBe(0)
            ->and($asset?->exists())->toBeFalse()
            ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    } finally {
        CarbonImmutable::setTestNow();
        DB::purge('mysql_east');
        DB::purge('mysql_west');
    }
})->with([false, true])->group('mysql');

/** @return array<string, mixed> */
function prepareMysqlOwnershipTest(): array
{
    $socket = getenv('FILOQUENT_TEST_MYSQL_SOCKET');
    $host = getenv('FILOQUENT_TEST_MYSQL_HOST');

    if (! $socket && ! $host) {
        test()->markTestSkipped('An isolated MySQL server is required for this integration test.');
    }
    $configuration = TestDatabase::configuration('mysql');
    config(['database.default' => 'mysql_ownership', 'database.connections.mysql_ownership' => $configuration,
        'database.connections.mysql_other_writer' => $configuration, 'filoquent.redis.database' => null,
        'filoquent.filesystem' => 'mysql_uploads']);
    Storage::fake('mysql_uploads');
    Artisan::call('migrate:fresh', ['--force' => true]);
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });

    return $configuration;
}

it('counts a managed upload committed immediately before the MySQL exclusive detection lock', function (): void {
    prepareMysqlOwnershipTest();
    JournalDocument::query()->create(['attachment' => __FILE__]);
    $connection = DB::connection();
    $interleaved = false;
    $connection->beforeExecuting(static function (string $query) use (&$interleaved): void {
        if (! $interleaved && str_contains($query, 'filoquent_owner_tables') && str_contains(strtolower($query), 'for update')) {
            $interleaved = true;
            $writer = new JournalDocument;
            $writer->setConnection('mysql_other_writer')->setAttribute('attachment', __FILE__)->save();
        }
    });
    (new OwnerTables($connection))->block('journal_documents');

    expect($interleaved)->toBeTrue()
        ->and(DB::table('filoquent_uploads')->count())->toBe(2)
        ->and((new OwnerTables($connection))->findPending('journal_documents')?->affected_uploads)->toBe(2);
})->group('mysql');

it('preserves a shared MySQL alias group and its original journal route through two resolutions', function (): void {
    $configuration = prepareMysqlOwnershipTest();
    $first = JournalDocument::query()->create(['attachment' => __FILE__]);
    $second = new JournalDocument;
    $second->setTable($configuration['database'].'.journal_documents')->setAttribute('attachment', __FILE__)->save();
    $assets = [$first->getAttribute('attachment')->default(), $second->getAttribute('attachment')->default()];
    expect(DB::table('filoquent_owner_tables')->count())->toBe(1);
    $from = 'journal_documents';

    foreach (['mysql_publications', 'mysql_renamed_publications'] as $to) {
        Schema::rename($from, $to);
        expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => 1]))->toBe(1)
            ->and((new OwnerTables(DB::connection()))->findPending($from)?->affected_uploads)->toBe(2)
            ->and(Artisan::call('filoquent:resolve-pending', ['--table' => $from, '--rename-to' => $to, '--apply' => true]))->toBe(0)
            ->and(DB::table('filoquent_uploads')->where('journal_owner_table', 'journal_documents')->count())->toBe(2);
        $from = $to;
    }
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(0);
    foreach ($assets as $asset) {
        expect($asset->exists())->toBeTrue();
    }
})->group('mysql');
