<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Fixtures\MaintenanceLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => null]);
    Artisan::call('migrate');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->softDeletes();
    });
    Log::swap(new MaintenanceLogger);
});

it('shows a two column operational overview without resolving disabled Redis', function (): void {
    app()->bind(function (): RedisUploadJournal {
        throw new RuntimeException('Disabled Redis must not be resolved.');
    });
    config(['filoquent.filesystem' => null]);

    expect(Artisan::call('filoquent:status', ['--no-ansi' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Filoquent', 'Filesystem', 'local', 'assets', 'Accessible', 'Redis', 'Disabled', 'Recorded uploads', 'Active uploads', 'Awaiting deletion')
        ->toMatch('/Recorded uploads\s+\.+\s+0/');
});

it('counts current upload states without changing receipts owners or stored files', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $owner = GcDocument::query()->create(['attachment' => __FILE__]);

        if ($i === 0) {
            $owner->delete();
        }
    }
    $ids = DB::table('filoquent_uploads')->orderBy('id')->pluck('id');
    DB::table('filoquent_uploads')->where('id', $ids[2])->update(['state' => 'pending']);
    DB::table('filoquent_uploads')->where('id', $ids[3])->update(['state' => 'retired']);
    DB::table('filoquent_uploads')->where('id', $ids[4])->update(['state' => 'writing']);
    $receipts = DB::table('filoquent_uploads')->orderBy('id')->get();
    $owners = DB::table('gc_documents')->get();
    $files = Storage::disk('local')->allFiles();

    expect(Artisan::call('filoquent:status'))->toBe(0);
    $output = Artisan::output();
    foreach (['Recorded uploads' => 5, 'Active uploads' => 2, 'Pending recovery' => 1, 'Awaiting deletion' => 1, 'Writing reservations' => 1] as $label => $count) {
        expect($output)->toMatch('/'.preg_quote($label, '/').'\s+\.+\s+'.$count.'/');
    }
    expect(DB::table('filoquent_uploads')->orderBy('id')->get())->toEqual($receipts)
        ->and(DB::table('gc_documents')->get())->toEqual($owners)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
});

it('reports unavailable storage without creating its missing root', function (): void {
    $root = storage_path('framework/testing/filoquent-status-'.bin2hex(random_bytes(8)));
    config(['filoquent.filesystem' => 'missing', 'filesystems.disks.missing' => ['driver' => 'local', 'root' => $root]]);

    expect(Artisan::call('filoquent:status'))->toBe(1)
        ->and(Artisan::output())->toContain('Unavailable', 'Recorded uploads')
        ->and(is_dir($root))->toBeFalse();
});

it('keeps other sections available when the upload ledger is missing', function (): void {
    Schema::drop('filoquent_uploads');

    expect(Artisan::call('filoquent:status'))->toBe(1)
        ->and(Artisan::output())->toContain('Unavailable', 'run migrations', 'Accessible', 'Disabled')
        ->and(Artisan::output())->not->toMatch('/Recorded uploads\s+\.+\s+0/');
});

it('shows effective Redis parameters and the scoped journal backlog without credentials', function (): void {
    config([
        'filoquent.redis.database' => 0,
        'filoquent.redis.connection' => 'uploads',
        'database.redis' => ['client' => 'phpredis', 'default' => [], 'uploads' => ['url' => 'tls://user:SECRET@redis.internal:6380/9?read_timeout=2']],
    ]);
    $journal = Mockery::mock(RedisUploadJournal::class)->makePartial();
    $journal->shouldReceive('ping')->once();
    $journal->shouldReceive('count')->once()->with(Mockery::type('string'))->andReturn(3);
    app()->instance(RedisUploadJournal::class, $journal);

    expect(Artisan::call('filoquent:status'))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Connected', 'redis.internal', '6380', 'tls', 'uploads', 'phpredis', 'Journal intents')
        ->toMatch('/Database\s+\.+\s+0/')
        ->toMatch('/Journal intents\s+\.+\s+3/')
        ->not->toContain('SECRET', 'tls://', 'user:');
});

it('reports Redis failures without leaking exceptions or losing SQL counts', function (bool $invalid): void {
    config(['filoquent.redis.database' => $invalid ? 'invalid' : 2]);
    $journal = Mockery::mock(RedisUploadJournal::class)->makePartial();
    $journal->shouldReceive('ping')->andThrow(new RuntimeException('SECRET https://private.invalid?signature=secret'));
    app()->instance(RedisUploadJournal::class, $journal);

    expect(Artisan::call('filoquent:status'))->toBe(1)
        ->and(Artisan::output())->toContain('Unavailable', 'Recorded uploads')
        ->and(Artisan::output())->not->toContain('SECRET', 'signature');
})->with([false, true]);

it('uses a selected writer and rejects invalid or valueless connection options', function (): void {
    $connection = DB::connection();
    config(['database.connections.status_writer' => $connection->getConfig()]);
    DB::connection('status_writer')->setPdo($connection->getPdo());
    DB::connection('status_writer')->setReadPdo(function (): never {
        throw new RuntimeException('Status must not read a replica.');
    });
    expect(Artisan::call('filoquent:status', ['--connection' => 'status_writer']))->toBe(0)
        ->and(Artisan::output())->toContain('status_writer');

    foreach ([null, '', '0', 'status_writer::read'] as $name) {
        expect(Artisan::call('filoquent:status', ['--connection' => $name]))->toBe(2);
    }
});

it('keeps silent output empty and starts a fresh report for each invocation', function (): void {
    expect(Artisan::call('filoquent:status', ['--silent' => true]))->toBe(0)
        ->and(Artisan::output())->toBe('');
    config(['filoquent.filesystem' => 'unconfigured']);
    expect(Artisan::call('filoquent:status', ['--silent' => true]))->toBe(1)
        ->and(Artisan::output())->toBe('');
});

it('uses a read probe on remote storage without listing or writing objects', function (): void {
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('fileExists')->once()->with(Mockery::pattern('/^\.filoquent-status-/'))->andReturn(false);
    $adapter = Mockery::mock(League\Flysystem\FilesystemAdapter::class);
    Storage::set('remote', new FilesystemAdapter($driver, $adapter, ['driver' => 's3']));
    config(['filoquent.filesystem' => 'remote', 'filesystems.disks.remote' => ['driver' => 's3', 'secret' => 'SECRET']]);

    expect(Artisan::call('filoquent:status'))->toBe(0)
        ->and(Artisan::output())->toContain('Accessible (read probe)', 'Not tested')
        ->and(Artisan::output())->not->toContain('SECRET');
});

it('preserves adapter failures as unavailable without exposing private error text', function (): void {
    $driver = Mockery::mock(FilesystemOperator::class);
    $driver->shouldReceive('fileExists')->once()->andThrow(new RuntimeException('SECRET signed-url?signature=secret'));
    Storage::set('local', new FilesystemAdapter($driver, Mockery::mock(League\Flysystem\FilesystemAdapter::class), ['driver' => 'custom']));

    expect(Artisan::call('filoquent:status'))->toBe(1)
        ->and(Artisan::output())->toContain('Unavailable', 'Recorded uploads')
        ->and(Artisan::output())->not->toContain('SECRET', 'signature');
});

it('distinguishes a connected Redis server from an unavailable SQL journal namespace', function (): void {
    config(['filoquent.redis.database' => 2]);
    Schema::drop('filoquent_upload_namespace');
    $journal = Mockery::mock(RedisUploadJournal::class)->makePartial();
    $journal->shouldReceive('ping')->once();
    $journal->shouldNotReceive('count');
    app()->instance(RedisUploadJournal::class, $journal);

    expect(Artisan::call('filoquent:status'))->toBe(1)
        ->and(Artisan::output())->toContain('Connected', 'Unknown', 'SQL journal namespace');
});

it('logs operational reports under silent output and preserves logging failure contracts', function (string $failure): void {
    $logger = new MaintenanceLogger;
    Log::swap($logger);
    $logger->beforeLog = function (string $message) use ($failure): void {
        throw_if(str_contains($message, $failure), RuntimeException::class, 'Logging unavailable.');
    };

    expect(Artisan::call('filoquent:status', ['--silent' => true]))->toBe($failure === 'neither' ? 0 : 1)
        ->and(Artisan::output())->toBe('')
        ->and($logger->records)->toHaveCount(match ($failure) {
            'started' => 0,
            'completed' => 1,
            default => 2,
        });

    if ($failure === 'neither') {
        expect($logger->report())->toMatchArray(['mode' => 'status', 'completed' => true, 'partial' => true, 'limit' => null, 'exit_code' => 0]);
    }
})->with(['started', 'completed', 'neither']);
