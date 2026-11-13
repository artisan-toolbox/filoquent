<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\StorageAccess;
use ArtisanToolbox\Filoquent\Tests\Fixtures\HealthRootProbe;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->storageRoot = storage_path('framework/testing/availability-'.Str::uuid());
    (new Filesystem)->makeDirectory($this->storageRoot, 0755, true);
    config(['filoquent.redis.database' => null]);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->storageRoot);
});

it('retains retired local uploads during a volume outage and removes them after recovery', function (bool $warm): void {
    config(['filoquent.filesystem' => 'available', 'filesystems.disks.available' => ['driver' => 'local', 'root' => $this->storageRoot.'/volume', 'throw' => true]]);
    Artisan::call('migrate');
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    $owner = JournalDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    DB::transaction(function () use ($owner): void {
        $owner->setAttribute('attachment', null)->save();
    });
    $before = DB::table('filoquent_uploads')->first();
    expect(rename($this->storageRoot.'/volume', $this->storageRoot.'/offline'))->toBeTrue();

    if (! $warm) {
        Storage::forgetDisk('available');
    }
    $configuration = config('filesystems.disks');

    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before)
        ->and(is_dir($this->storageRoot.'/volume'))->toBeFalse()
        ->and(is_file($this->storageRoot.'/offline/'.$asset->path))->toBeTrue()
        ->and(config('filesystems.disks'))->toBe($configuration);

    expect(rename($this->storageRoot.'/offline', $this->storageRoot.'/volume'))->toBeTrue()
        ->and(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0)
        ->and($asset->exists())->toBeFalse();
})->with([false, true]);

it('checks every read-through parent before resolving or deleting through a cached disk', function (bool $inline, bool $warm, string $missing): void {
    $primary = ['driver' => 'local', 'root' => $this->storageRoot.'/primary'];
    $fallback = ['driver' => 'local', 'root' => $this->storageRoot.'/fallback'];
    (new Filesystem)->makeDirectory($primary['root']);
    (new Filesystem)->makeDirectory($fallback['root']);
    config(['filesystems.disks.availability_primary' => $primary, 'filesystems.disks.availability_fallback' => $fallback,
        'filesystems.disks.available' => ['driver' => 'read-through', 'primary' => $inline ? $primary : 'availability_primary', 'fallback' => $inline ? $fallback : 'availability_fallback'],
    ]);

    if ($warm) {
        Storage::disk('available');
    }
    expect(rename($this->storageRoot.'/'.$missing, $this->storageRoot.'/offline'))->toBeTrue();
    $configuration = config('filesystems.disks');

    expect(fn () => (new StorageAccess)->forDeletion('available'))->toThrow(FiloquentException::class)
        ->and(is_dir($this->storageRoot.'/'.$missing))->toBeFalse()
        ->and(config('filesystems.disks'))->toBe($configuration);

    expect(rename($this->storageRoot.'/offline', $this->storageRoot.'/'.$missing))->toBeTrue()
        ->and((new StorageAccess)->forDeletion('available')->delete('already-absent.txt'))->toBeTrue();
})->with([false, true])->with([false, true])->with(['primary', 'fallback']);

it('does not recreate an unavailable local volume when reading an asset', function (string $method): void {
    config(['filoquent.filesystem' => 'available', 'filesystems.disks.available' => ['driver' => 'local', 'root' => $this->storageRoot.'/volume', 'throw' => true]]);
    Artisan::call('migrate');
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    $owner = JournalDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    DB::transaction(function () use ($owner): void {
        $owner->setAttribute('attachment', null)->save();
    });
    expect(rename($this->storageRoot.'/volume', $this->storageRoot.'/offline'))->toBeTrue();
    Storage::forgetDisk('available');

    expect(fn () => $method === 'temporaryUrl' ? $asset->temporaryUrl(now()->addMinute()) : $asset->{$method}())->toThrow(InvalidArgumentException::class)
        ->and(is_dir($this->storageRoot.'/volume'))->toBeFalse()
        ->and(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->count())->toBe(1)
        ->and(is_file($this->storageRoot.'/offline/'.$asset->path))->toBeTrue();

    expect(rename($this->storageRoot.'/offline', $this->storageRoot.'/volume'))->toBeTrue()
        ->and(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
})->with(['exists', 'url', 'temporaryUrl', 'readStream']);

it('rejects missing roots through nested named or inline scoped parents before resolution', function (bool $inline): void {
    $parent = ['driver' => 'local', 'root' => $this->storageRoot.'/unavailable'];
    $scope = ['driver' => 'scoped', 'disk' => $inline ? $parent : 'availability_parent', 'prefix' => 'nested'];
    config(['filesystems.disks.availability_parent' => $parent, 'filesystems.disks.availability_scope' => $scope,
        'filesystems.disks.available' => ['driver' => 'read-through', 'primary' => $inline ? $scope : 'availability_scope', 'fallback' => ['driver' => 'local', 'root' => $this->storageRoot.'/also-unavailable']],
    ]);
    $configuration = config('filesystems.disks');
    expect(fn () => (new StorageAccess)->forDeletion('available'))->toThrow(FiloquentException::class)
        ->and(is_dir($parent['root']))->toBeFalse()
        ->and(is_dir($this->storageRoot.'/also-unavailable'))->toBeFalse()
        ->and(config('filesystems.disks'))->toBe($configuration);
})->with([false, true]);

it('uses the fake local root and rejects it when that actual root becomes unavailable', function (): void {
    config(['filesystems.disks.available' => ['driver' => 'local', 'root' => $this->storageRoot.'/unused']]);
    $fake = Storage::fake('available');
    expect((new StorageAccess)->forDeletion('available'))->toBe($fake);
    $root = $fake->getConfig()['root'];
    expect(rename($root, $this->storageRoot.'/offline'))->toBeTrue();

    try {
        expect(fn () => (new StorageAccess)->forDeletion('available'))->toThrow(FiloquentException::class)
            ->and(is_dir($root))->toBeFalse()
            ->and(is_dir($this->storageRoot.'/unused'))->toBeFalse();
    } finally {
        rename($this->storageRoot.'/offline', $root);
    }
});

it('does not inspect unrelated local roots or issue SQL while resolving a disk', function (): void {
    config(['filesystems.disks.available' => ['driver' => 'local', 'root' => $this->storageRoot]]);
    stream_wrapper_register('availability-root', HealthRootProbe::class);
    HealthRootProbe::$calls = 0;
    config(['filesystems.disks.unrelated' => ['driver' => 'local', 'root' => 'availability-root://unrelated']]);
    DB::connection()->enableQueryLog();
    DB::connection()->flushQueryLog();

    try {
        expect((new StorageAccess)->forDeletion('available')->delete('absent.txt'))->toBeTrue()
            ->and((new StorageAccess)->forReading('available')->fileExists('absent.txt'))->toBeFalse()
            ->and(HealthRootProbe::$calls)->toBe(0)
            ->and(DB::connection()->getQueryLog())->toBe([]);
    } finally {
        stream_wrapper_unregister('availability-root');
    }
});
