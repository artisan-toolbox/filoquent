<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Fixtures\MaintenanceLogger;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->softDeletes();
    });
    app()->instance(MaintenanceLogger::class, new MaintenanceLogger);
    Log::swap(resolve(MaintenanceLogger::class));
});

it('lists pending groups read only and reports an empty bounded list', function (): void {
    expect(Artisan::call('filoquent:resolve-pending', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('No pending owner tables.')
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['groups_examined' => 0]);
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::drop('gc_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $before = DB::table('filoquent_uploads')->get();
    $groups = (new OwnerTables(DB::connection()))->pending();
    $connection = DB::connection();
    $connection->enableQueryLog();

    expect(Artisan::call('filoquent:resolve-pending', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('gc_documents')->toContain((string) $groups->first()->issue_id);
    $queries = strtolower(collect($connection->getQueryLog())->pluck('query')->implode(' '));
    $connection->disableQueryLog();
    expect($queries)->not->toContain('insert ')->not->toContain('update ')->not->toContain('delete ')->not->toContain('count(')->not->toContain('offset')
        ->and(DB::table('filoquent_uploads')->get())->toEqual($before)
        ->and((new OwnerTables($connection))->pending())->toEqual($groups)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['completed' => true, 'exit_code' => 0])
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['groups_examined' => 1, 'resolved_rename' => 0, 'resolved_removed' => 0, 'uploads_moved' => 0]);
});

it('previews a selected resolution without changing uploads or pending groups', function (array $action, string $description): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'renamed_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $before = DB::table('filoquent_uploads')->get();
    $groups = (new OwnerTables(DB::connection()))->pending();

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--no-interaction' => true] + $action))->toBe(0)
        ->and(Artisan::output())->toContain('Preview only.')->toContain($description)->toContain((string) $groups->first()->issue_id)
        ->and(DB::table('filoquent_uploads')->get())->toEqual($before)
        ->and((new OwnerTables(DB::connection()))->pending())->toEqual($groups);
})->with([
    'rename' => [['--rename-to' => 'renamed_documents'], 'renamed_documents'],
    'removal' => [['--removed' => true], '24-hour'],
]);

it('attaches a natively renamed table after checking its actual owner rows', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    Schema::rename('gc_documents', 'renamed_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--rename-to' => 'renamed_documents', '--apply' => true, '--no-interaction' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('owner_table'))->toBe(DatabaseFixtures::ownerRoute('renamed_documents'))
        ->and((new OwnerTables(DB::connection()))->isPending('gc_documents'))->toBeFalse()
        ->and($asset->exists())->toBeTrue()
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['groups_examined' => 1, 'resolved_rename' => 1, 'uploads_moved' => 1]);
    $this->travel(26)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($asset->exists())->toBeTrue();
});

it('requires explicit verified attachment when a missing table is restored under its original name', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'renamed_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    Schema::rename('renamed_documents', 'gc_documents');

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--removed' => true, '--apply' => true, '--no-interaction' => true]))->toBe(1)
        ->and((new OwnerTables(DB::connection()))->isPending('gc_documents'))->toBeTrue()
        ->and(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--rename-to' => 'gc_documents', '--apply' => true, '--no-interaction' => true]))->toBe(0)
        ->and((new OwnerTables(DB::connection()))->isPending('gc_documents'))->toBeFalse()
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active');
});

it('shows a selected group without prompting or mutating when interaction is disabled', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::drop('gc_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $group = (new OwnerTables(DB::connection()))->findPending('gc_documents');
    $before = DB::table('filoquent_uploads')->get();

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('No resolution selected.')
        ->and((new OwnerTables(DB::connection()))->findPending('gc_documents'))->toEqual($group)
        ->and(DB::table('filoquent_uploads')->get())->toEqual($before);
});

it('keeps a pending issue unchanged when the replacement table has lost an exact manifest', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'renamed_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    DB::table('renamed_documents')->update(['attachment' => null]);
    $before = DB::table('filoquent_uploads')->get();
    $group = (new OwnerTables(DB::connection()))->findPending('gc_documents');

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--rename-to' => 'renamed_documents', '--apply' => true, '--no-interaction' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->get())->toEqual($before)
        ->and((new OwnerTables(DB::connection()))->findPending('gc_documents'))->toEqual($group);
});

it('uses a bounded physical table cursor and the writer even when a reader is empty', function (): void {
    foreach (['a_missing', 'b_missing', 'z_missing'] as $table) {
        $owner = GcDocument::query()->create(['attachment' => __FILE__]);
        DB::table('filoquent_uploads')->where('owner_key', (string) $owner->getKey())->update(['owner_table' => $table]);
    }
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $connection = DB::connection();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    $connection->forgetRecordModificationState();

    try {
        expect(Artisan::call('filoquent:resolve-pending', ['--after' => 'a_missing', '--limit' => 1, '--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('b_missing')->not->toContain('a_missing')->not->toContain('z_missing')
            ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['groups_examined' => 1]);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('confirms permanent removal with a fresh grace period and never deletes bytes in the resolution command', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-04-02T16:00:00Z'));
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    Schema::drop('gc_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $this->travel(48)->hours();

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--removed' => true, '--apply' => true, '--no-interaction' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending')
        ->and($asset->exists())->toBeTrue()
        ->and((new OwnerTables(DB::connection()))->isPending('gc_documents'))->toBeFalse()
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['resolved_removed' => 1, 'uploads_moved' => 1]);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($asset->exists())->toBeTrue();
    $this->travel(23)->hours();
    Artisan::call('filoquent:gc', ['--apply' => true]);
    expect($asset->exists())->toBeTrue();
    $this->travel(2)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($asset->exists())->toBeFalse();
});

it('rejects invalid command options and remains silent', function (array $options): void {
    expect(Artisan::call('filoquent:resolve-pending', $options + ['--silent' => true, '--no-interaction' => true]))->toBe(2)
        ->and(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 2, 'completed' => false]);
})->with([
    'zero limit' => [['--limit' => '0']],
    'large limit' => [['--limit' => '1001']],
    'invalid limit' => [['--limit' => 'many']],
    'empty limit' => [['--limit' => '']],
    'valueless limit' => [['--limit' => null]],
    'empty cursor' => [['--after' => '']],
    'valueless cursor' => [['--after' => null]],
    'control cursor' => [['--after' => "table\nname"]],
    'long cursor' => [['--after' => str_repeat('a', 129)]],
    'expression cursor' => [['--after' => 'table; drop table uploads']],
    'empty table' => [['--table' => '']],
    'valueless table' => [['--table' => null]],
    'empty target' => [['--table' => 'gc_documents', '--rename-to' => '']],
    'valueless target' => [['--table' => 'gc_documents', '--rename-to' => null]],
    'rename without table' => [['--rename-to' => 'renamed_documents']],
    'removal without table' => [['--removed' => true]],
    'apply without action' => [['--table' => 'gc_documents', '--apply' => true]],
    'apply without table' => [['--apply' => true]],
    'conflicting actions' => [['--table' => 'gc_documents', '--rename-to' => 'renamed_documents', '--removed' => true]],
    'read connection' => [['--connection' => 'testing::read']],
    'valueless connection' => [['--connection' => null]],
    'list cursor with selected group' => [['--table' => 'gc_documents', '--after' => 'a_missing']],
]);

it('rejects resolution inside an outer transaction and reports lookup failures without private details', function (): void {
    DB::beginTransaction();

    try {
        expect(Artisan::call('filoquent:resolve-pending', ['--silent' => true]))->toBe(1)
            ->and(Artisan::output())->toBe('');
    } finally {
        DB::rollBack();
    }
    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'not_pending', '--removed' => true, '--apply' => true, '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('No pending owner-table group');
});

it('rejects a stale issue captured before the selected resolution applies', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    Schema::rename('gc_documents', 'renamed_documents');
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $issue = (new OwnerTables(DB::connection()))->findPending('gc_documents');
    $changed = false;
    DB::listen(function (QueryExecuted $event) use (&$changed): void {
        if (! $changed && str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'filoquent_owner_tables')) {
            $changed = true;
            DB::table('filoquent_owner_tables')->where('owner_table', DatabaseFixtures::ownerRoute('gc_documents'))->update(['issue_id' => '019abcde-1234-7000-8000-000000000001']);
        }
    });

    expect(Artisan::call('filoquent:resolve-pending', ['--table' => 'gc_documents', '--rename-to' => 'renamed_documents', '--apply' => true, '--no-interaction' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('owner_table'))->toBe(DatabaseFixtures::ownerRoute('gc_documents'))
        ->and((new OwnerTables(DB::connection()))->findPending('gc_documents')->issue_id)->not->toBe($issue->issue_id);
});
