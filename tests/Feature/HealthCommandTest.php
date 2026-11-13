<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Fixtures\HealthRootProbe;
use ArtisanToolbox\Filoquent\Tests\Fixtures\MaintenanceLogger;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->text('notes')->nullable();
        $table->softDeletes();
    });
    app()->instance(MaintenanceLogger::class, new MaintenanceLogger);
    Log::swap(resolve(MaintenanceLogger::class));
});

it('checks the full empty range and logs clean coverage without console output in silent mode', function (): void {
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(0)
        ->and(Artisan::output())->toBe('');
    $logger = resolve(MaintenanceLogger::class);
    expect($logger->records)->toHaveCount(2)
        ->and($logger->records[0]['message'])->toBe('Filoquent maintenance started.')
        ->and($logger->records[1]['level'])->toBe('info')
        ->and($logger->report())->toMatchArray(['completed' => true, 'partial' => false, 'range_exhausted' => true, 'exit_code' => 0, 'upper_bound' => null, 'cursor' => null]);
});

it('checks all variants read-only including soft deleted owners with historical names', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $manifest = json_decode($owner->getRawOriginal('attachment'), true, flags: JSON_THROW_ON_ERROR);
    $original = $owner->getAttribute('attachment')->default();
    $manifest['thumbnail'] = ['filesystem' => 'local', 'path' => dirname($original->path).'/thumb.txt', 'default' => false];
    $json = json_encode($manifest, JSON_THROW_ON_ERROR);
    Storage::disk('local')->put($manifest['thumbnail']['path'], 'thumbnail');
    DB::table('gc_documents')->update(['attachment' => $json]);
    DB::table('filoquent_uploads')->update(['manifest' => $json, 'owner_type' => 'Removed\\Owner', 'attribute' => 'old_attachment']);
    $owner->delete();
    Schema::table('gc_documents', fn (Blueprint $table) => $table->renameColumn('attachment', 'renamed_attachment'));
    $before = DB::table('filoquent_uploads')->get();
    $owners = DB::table('gc_documents')->get();
    $files = Storage::disk('local')->allFiles();

    expect(Artisan::call('filoquent:health'))->toBe(0)
        ->and(Artisan::output())->toContain('files checked 2')->toContain('full starting range')
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_examined' => 1, 'files_checked' => 2, 'files_present' => 2])
        ->and(DB::table('filoquent_uploads')->get())->toEqual($before)
        ->and(DB::table('gc_documents')->get())->toEqual($owners)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
});

it('confirms a missing file and rejects a directory occupying the exact file path', function (bool $directory): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    Storage::disk('local')->delete($asset->path);

    if ($directory) {
        Storage::disk('local')->makeDirectory($asset->path);
    }
    $before = DB::table('filoquent_uploads')->first();
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(1)
        ->and(Artisan::output())->toBe('')
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_checked' => 1, 'files_missing' => 1, 'uploads_skipped' => 0])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('warning')
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before);
})->with([false, true]);

it('reports inconclusive ownership without probing files when metadata cannot be verified', function (string $kind): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    match ($kind) {
        'manifest' => DB::table('filoquent_uploads')->update(['manifest' => '{}']),
        'table' => Schema::drop('gc_documents'),
        'key' => DB::table('filoquent_uploads')->update(['owner_key_name' => 'notes']),
        'partial' => DB::table('gc_documents')->update(['attachment' => null, 'notes' => '{broken']),
        default => throw new LogicException('Unknown metadata scenario.'),
    };
    $before = DB::table('filoquent_uploads')->first();
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_inconclusive' => 1, 'files_checked' => 0])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error')
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before);
})->with(['manifest', 'table', 'key', 'partial']);

it('reports unavailable filesystems without leaking adapter exceptions into logs', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $manifest = str_replace('local', 'unconfigured', $owner->getRawOriginal('attachment'));
    DB::table('gc_documents')->update(['attachment' => $manifest]);
    DB::table('filoquent_uploads')->update(['manifest' => $manifest]);
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_inconclusive' => 1, 'uploads_inconclusive' => 0])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error');
});

it('discards negative checks after ownership changes and never probes storage under SQL locks', function (string $change, bool $throws): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $disk = Storage::disk('local');
    $callback = function () use ($change, $owner, $throws): bool {
        expect(DB::transactionLevel())->toBe(0);
        match ($change) {
            'owner_removed' => DB::table('gc_documents')->delete(),
            'reference_removed' => DB::table('gc_documents')->update(['attachment' => null]),
            'receipt_retired' => DB::table('filoquent_uploads')->update(['state' => 'retired']),
            'receipt_removed' => DB::table('filoquent_uploads')->delete(),
            'replacement' => $owner->update(['attachment' => __FILE__]),
            default => throw new LogicException('Unknown ownership scenario.'),
        };

        throw_if($throws, RuntimeException::class, 'SECRET https://private.invalid/?signature=secret');

        return false;
    };
    Storage::set('local', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig(), $callback) extends FilesystemAdapter
    {
        /** @param array<string, mixed> $config @param Closure(): bool $callback */
        public function __construct(FilesystemOperator $driver, League\Flysystem\FilesystemAdapter $adapter, array $config, private readonly Closure $callback)
        {
            parent::__construct($driver, $adapter, $config);
        }

        public function fileExists($path): bool
        {
            return ($this->callback)();
        }
    });
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_skipped' => 1, 'files_missing' => 0, 'files_checked' => 0, 'files_inconclusive' => 0])
        ->and(json_encode(resolve(MaintenanceLogger::class)->records, JSON_THROW_ON_ERROR))->not->toContain('SECRET')->not->toContain('signature');
})->with(['owner_removed', 'reference_removed', 'receipt_retired', 'receipt_removed', 'replacement'])->with([false, true]);

it('ignores concurrent garbage collection observation timestamps when confirming a missing file', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $disk = Storage::disk('local');
    Storage::set('local', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public function fileExists($path): bool
        {
            expect(DB::transactionLevel())->toBe(0);
            DB::table('filoquent_uploads')->update(['checked_at' => '2030-01-01 00:00:00', 'orphaned_at' => '2020-01-01 00:00:00']);

            return false;
        }
    });
    expect(Artisan::call('filoquent:health'))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_missing' => 1, 'uploads_skipped' => 0])
        ->and(CarbonImmutable::parse(DB::table('filoquent_uploads')->value('orphaned_at'), 'UTC')->format('Y-m-d H:i:s'))->toBe('2020-01-01 00:00:00');
});

it('distinguishes a limited range from exhausted suffixes and starts a fresh report for reused commands', function (): void {
    for ($i = 0; $i < 3; $i++) {
        GcDocument::query()->create(['attachment' => __FILE__]);
    }
    $ids = DB::table('filoquent_uploads')->orderBy('id')->pluck('id');
    expect(Artisan::call('filoquent:health', ['--limit' => 1]))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['partial' => true, 'completed' => true, 'range_exhausted' => false, 'cursor' => $ids[0], 'upper_bound' => $ids[2]])
        ->and(Artisan::call('filoquent:health', ['--after' => $ids[0], '--limit' => 2]))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['partial' => true, 'completed' => true, 'range_exhausted' => true, 'cursor' => $ids[2]])
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_examined' => 2])
        ->and(Artisan::call('filoquent:health', ['--limit' => 3]))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['partial' => false, 'range_exhausted' => true])
        ->and(Artisan::call('filoquent:health', ['--after' => $ids[2]]))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['partial' => true, 'range_exhausted' => true, 'cursor' => $ids[2]])
        ->and(collect(resolve(MaintenanceLogger::class)->records)->pluck('context.run_id')->unique())->toHaveCount(4);
});

it('traverses more than one indexed page without counts mutations or unbounded findings', function (): void {
    DatabaseFixtures::seedReceipts(503, 'gc_documents', 'Removed');
    $connection = DB::connection();
    $connection->enableQueryLog();
    expect(Artisan::call('filoquent:health', ['--no-interaction' => true]))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_examined' => 503, 'uploads_inconclusive' => 503])
        ->and(resolve(MaintenanceLogger::class)->report()['issues'])->toHaveCount(10)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['partial' => false, 'range_exhausted' => true]);
    $queries = DatabaseFixtures::sql(collect($connection->getQueryLog())->pluck('query')->implode(' '));
    $connection->disableQueryLog();
    expect(strtolower($queries))->not->toContain('count(')->not->toContain('offset')->not->toContain('update ')->not->toContain('delete ')->not->toContain('insert ')
        ->and($queries)->toContain('limit 500')->toContain('order by "id" desc limit 1')
        ->and(Artisan::output())->toContain('Examined 500 uploads so far.')->toContain('Examined 503 uploads so far.');
});

it('uses the writer for receipts schema and owners even when a reader is empty', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $connection = DB::connection();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    $connection->forgetRecordModificationState();

    try {
        expect(Artisan::call('filoquent:health'))->toBe(0)
            ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_present' => 1]);
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('logs invalid options and transaction failures in silent mode', function (): void {
    foreach ([['--limit' => '0'], ['--limit' => '10001'], ['--limit' => 'bad'], ['--after' => 'invalid']] as $options) {
        expect(Artisan::call('filoquent:health', $options + ['--silent' => true]))->toBe(2)
            ->and(Artisan::output())->toBe('')
            ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 2, 'completed' => false, 'partial' => true]);
    }
    DB::beginTransaction();

    try {
        expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(1)
            ->and(Artisan::output())->toBe('');
    } finally {
        DB::rollBack();
    }
});

it('keeps a startup upper bound when a new upload is committed during file probing', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $upper = DB::table('filoquent_uploads')->value('id');
    $disk = Storage::disk('local');
    Storage::set('local', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public function fileExists($path): bool
        {
            GcDocument::query()->create(['attachment' => __FILE__]);

            return true;
        }
    });
    expect(Artisan::call('filoquent:health'))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['upper_bound' => $upper, 'cursor' => $upper, 'partial' => false, 'range_exhausted' => true])
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_examined' => 1, 'files_present' => 1])
        ->and(DB::table('filoquent_uploads')->count())->toBe(2);
});

it('marks a mid traversal failure as partial and retains the last completed page cursor', function (): void {
    DatabaseFixtures::seedReceipts(501, 'gc_documents', 'Removed');
    $pages = 0;
    DB::listen(function (QueryExecuted $event) use (&$pages): void {
        throw_if(str_contains(DatabaseFixtures::sql($event->sql), 'order by "id" asc limit 500') && ++$pages === 2, RuntimeException::class, 'Database failed after the first page.');
    });
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['completed' => false, 'partial' => true, 'range_exhausted' => false, 'cursor' => '00000000-0000-7000-8000-000000000500'])
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['uploads_examined' => 500])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error');
});

it('uses error severity when presentation fails after a missing-file traversal completes', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    Storage::disk('local')->delete($owner->getAttribute('attachment')->default()->path);
    $output = new class extends BufferedOutput
    {
        protected function doWrite(string $message, bool $newline): void
        {
            throw_if(str_contains($message, 'Uploads examined'), RuntimeException::class, 'Console output failed.');
            parent::doWrite($message, $newline);
        }
    };
    expect(Artisan::call('filoquent:health', [], $output))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['completed' => false, 'partial' => true, 'exit_code' => 1])
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_missing' => 1])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error');
});

it('rejects noncanonical UUID cursor spellings rather than silently checking the wrong range', function (): void {
    $id = '019abcde-1234-7000-8000-000000000001';
    foreach ([strtoupper($id), 'urn:uuid:'.$id, '{'.$id.'}', str_replace('-', '', $id)] as $after) {
        expect(Artisan::call('filoquent:health', ['--after' => $after, '--silent' => true]))->toBe(2)
            ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['exit_code' => 2, 'completed' => false, 'partial' => true]);
    }
});

it('does not create a missing local disk root while checking a referenced file', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $root = storage_path('framework/testing/filoquent-health-absent-root');
    (new Filesystem)->deleteDirectory($root);
    $configuration = ['driver' => 'local', 'root' => $root, 'throw' => true];
    config(['filesystems.disks.health_missing' => $configuration]);
    $manifest = str_replace('local', 'health_missing', $owner->getRawOriginal('attachment'));
    DB::table('gc_documents')->update(['attachment' => $manifest]);
    DB::table('filoquent_uploads')->update(['manifest' => $manifest]);
    expect(Artisan::call('filoquent:health'))->toBe(1)
        ->and(is_dir($root))->toBeFalse()
        ->and(config('filesystems.disks.health_missing'))->toBe($configuration)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_inconclusive' => 1]);
});

it('restores local disk configuration when custom disk resolution throws', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $configuration = ['driver' => 'local', 'root' => '/unavailable-health-root'];
    config(['filesystems.disks.health_throwing' => $configuration]);
    Storage::extend('local', function (): never {
        throw new RuntimeException('Private storage configuration');
    });
    $manifest = str_replace('local', 'health_throwing', $owner->getRawOriginal('attachment'));
    DB::table('gc_documents')->update(['attachment' => $manifest]);
    DB::table('filoquent_uploads')->update(['manifest' => $manifest]);
    expect(Artisan::call('filoquent:health'))->toBe(1)
        ->and(config('filesystems.disks.health_throwing'))->toBe($configuration)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_inconclusive' => 1]);
});

it('reports interruption during an empty range as incomplete failure', function (): void {
    if (! function_exists('pcntl_signal_get_handler')) {
        test()->markTestSkipped('Cooperative signal handling requires pcntl.');
    }
    DB::listen(function (QueryExecuted $event): void {
        if (str_contains(DatabaseFixtures::sql($event->sql), 'order by "id" desc limit 1')) {
            $handler = pcntl_signal_get_handler(SIGTERM);

            if (is_callable($handler)) {
                $handler();
            }
        }
    });
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(1)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['completed' => false, 'partial' => true, 'interrupted' => true, 'exit_code' => 1])
        ->and(resolve(MaintenanceLogger::class)->records[1]['level'])->toBe('error');
});

it('does not create local roots reached through named or inline composite disk parents', function (bool $inline): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $primary = storage_path('framework/testing/filoquent-health-primary');
    $fallback = storage_path('framework/testing/filoquent-health-fallback');
    $files = new Filesystem;
    $files->deleteDirectory($primary);
    $files->deleteDirectory($fallback);
    $primaryConfig = ['driver' => 'local', 'root' => $primary];
    $fallbackConfig = ['driver' => 'local', 'root' => $fallback];
    config(['filesystems.disks.health_primary' => $primaryConfig, 'filesystems.disks.health_fallback' => $fallbackConfig]);
    $composite = ['driver' => 'read-through', 'primary' => $inline ? $primaryConfig : 'health_primary', 'fallback' => $inline ? $fallbackConfig : 'health_fallback'];
    config(['filesystems.disks.health_composite' => $composite]);
    $before = config('filesystems.disks');
    $manifest = str_replace('local', 'health_composite', $owner->getRawOriginal('attachment'));
    DB::table('gc_documents')->update(['attachment' => $manifest]);
    DB::table('filoquent_uploads')->update(['manifest' => $manifest]);
    expect(Artisan::call('filoquent:health'))->toBe(1)
        ->and(is_dir($primary))->toBeFalse()->and(is_dir($fallback))->toBeFalse()
        ->and(config('filesystems.disks'))->toBe($before)
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_inconclusive' => 1]);
})->with([false, true]);

it('selects an explicit writer connection and rejects reader overrides including the default', function (): void {
    GcDocument::query()->create(['attachment' => __FILE__]);
    $connection = DB::connection();
    config(['database.connections.health_writer' => array_replace($connection->getConfig(), ['name' => 'health_writer'])]);
    DB::connection('health_writer')->setPdo($connection->getPdo());
    expect(Artisan::call('filoquent:health', ['--connection' => 'health_writer']))->toBe(0)
        ->and(resolve(MaintenanceLogger::class)->report())->toMatchArray(['requested_connection' => 'health_writer', 'connection' => 'health_writer'])
        ->and(resolve(MaintenanceLogger::class)->report()['counts'])->toMatchArray(['files_present' => 1])
        ->and(Artisan::call('filoquent:health', ['--connection' => 'health_writer::read', '--silent' => true]))->toBe(2);
    config(['database.default' => 'health_writer::read']);
    expect(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(2);
});

it('does not stat unrelated local roots while checking another filesystem', function (): void {
    stream_wrapper_register('health-root', HealthRootProbe::class);

    try {
        expect(is_dir('health-root://unrelated'))->toBeFalse();
        expect(HealthRootProbe::$calls)->toBe(1);
        HealthRootProbe::$calls = 0;
        config(['filesystems.disks.unrelated' => ['driver' => 'local', 'root' => 'health-root://unrelated']]);
        GcDocument::query()->create(['attachment' => __FILE__]);
        expect(Artisan::call('filoquent:health'))->toBe(0)
            ->and(HealthRootProbe::$calls)->toBe(0);
    } finally {
        stream_wrapper_unregister('health-root');
    }
});
