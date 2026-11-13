<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use ArtisanToolbox\Filoquent\Tests\Fixtures\InMemoryUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\UuidInterface;

class JournalVariants extends Middleware
{
    public function handle(Upload $file): array
    {
        return [$file->asset(name: 'original'), $file->asset(key: 'thumbnail', name: 'thumbnail', default: false)];
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => 2]);
    Artisan::call('migrate');
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
});

it('reserves before registration and leaves assignment local until saving', function (): void {
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $journal->onRecord = function (UploadIntent $intent): void {
        expect(DB::transactionLevel())->toBeGreaterThan(0)
            ->and(DB::table('filoquent_uploads')->where('id', $intent->id)->value('state'))->toBe('writing')
            ->and(DB::table('filoquent_uploads')->where('id', $intent->id)->value('owner_key'))->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    };
    $document = new JournalDocument(['attachment' => __FILE__]);
    expect($journal->records)->toBeEmpty()->and(DB::table('filoquent_uploads')->count())->toBe(0);
    $document->save();
    expect($document->attachment?->default()?->exists())->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active')
        ->and(DB::table('filoquent_uploads')->value('owner_key'))->toBe((string) $document->getKey())
        ->and(array_filter($journal->records))->toBeEmpty();
});

it('retains outer rollback intents and starts the grace period only on SQL promotion', function (): void {
    DB::beginTransaction();
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    $asset = $document->attachment?->default();
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $scope = UploadLedger::scope(DB::connection());
    expect($journal->records[$scope])->toHaveCount(1);
    DB::rollBack();
    $this->travel(10)->days();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending')
        ->and($asset?->exists())->toBeTrue();
    $this->travel(24 * 60 * 60 - 1)->seconds();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)->and($asset?->exists())->toBeTrue();
    config(['filoquent.redis.database' => null]);
    $this->travel(1)->seconds();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($asset?->exists())->toBeFalse()->and(DB::table('filoquent_uploads')->count())->toBe(0);
});

it('keeps observer failures and every middleware variant recoverable', function (): void {
    JournalDocument::saved(function (): void {
        throw new RuntimeException('Observer failed.');
    });
    $document = new JournalDocument;
    $document->mergeCasts(['attachment' => AsFile::using(middleware: JournalVariants::class)]);
    $document->setAttribute('attachment', __FILE__);
    expect(fn () => $document->save())->toThrow(RuntimeException::class, 'Observer failed.')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(24)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('aborts before storage when registration is unavailable or its reply is lost', function (bool $lostReply): void {
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $journal->failRegistration = ! $lostReply;
    $journal->loseRegistrationReply = $lostReply;
    expect(fn () => JournalDocument::query()->create(['attachment' => __FILE__]))->toThrow(FiloquentException::class)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);

    if ($lostReply) {
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending');
    }
})->with([false, true]);

it('keeps a committed save successful when acknowledgment fails and consolidates without downgrading it', function (): void {
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $journal->failAcknowledgment = true;
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    expect($document->fresh()?->attachment?->default()?->exists())->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active');
    $journal->failAcknowledgment = false;
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active')
        ->and(array_filter($journal->records))->toBeEmpty();
});

it('does not acknowledge on an inner savepoint commit or its rollback', function (): void {
    DB::beginTransaction();
    DB::beginTransaction();
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    $asset = $document->attachment?->default();
    DB::rollBack();
    DB::commit();
    expect(DB::table('journal_documents')->count())->toBe(0)
        ->and($asset?->exists())->toBeTrue()
        ->and(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending');
});

it('keeps preview nonmutating and rotates a poison record past a bounded batch', function (): void {
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $scope = UploadLedger::scope(DB::connection());
    $poison = (string) Str::uuid7();
    $journal->records[$scope][$poison] = '{bad';
    $journal->scores[$scope][$poison] = 0;
    DB::beginTransaction();
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    $asset = $document->attachment?->default();
    DB::rollBack();
    $before = [$journal->records, $journal->scores];
    expect(Artisan::call('filoquent:gc', ['--limit' => '1']))->toBe(1)
        ->and([$journal->records, $journal->scores])->toBe($before)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(1)
        ->and(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending')
        ->and($asset?->exists())->toBeTrue();
});

it('retains the first promotion age across duplicate imports and refuses conflicting receipts', function (): void {
    DB::beginTransaction();
    JournalDocument::query()->create(['attachment' => __FILE__]);
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $scope = UploadLedger::scope(DB::connection());
    $id = array_key_first($journal->records[$scope]);
    $payload = $journal->records[$scope][$id];
    DB::rollBack();
    $journal->failAcknowledgment = true;
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1);
    $age = DB::table('filoquent_uploads')->value('orphaned_at');
    $this->travel(2)->hours();
    $journal->failAcknowledgment = false;
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBe($age);
    $journal->records[$scope][$id] = $payload;
    $journal->scores[$scope][$id] = 0;
    DB::table('filoquent_uploads')->update(['attribute' => 'different']);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and($journal->records[$scope][$id])->toBe($payload);
});

it('rejects late writer confirmation after its reservation disappeared', function (): void {
    $document = new JournalDocument;
    $document->setAttribute('attachment', __FILE__);
    /** @var InMemoryUploadJournal $journal */
    $journal = resolve(RedisUploadJournal::class);
    $journal->onRecord = function (UploadIntent $intent): void {
        DB::table('filoquent_uploads')->where('id', $intent->id)->delete();
    };
    expect(fn () => $document->save())->toThrow(FiloquentException::class, 'writing reservation')
        ->and(DB::table('journal_documents')->count())->toBe(0);
});

it('retains failed partial writes and uses prune to retry failed pending deletion', function (): void {
    $disk = Storage::disk('local');
    $refusing = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        private int $writes = 0;

        /** @param string|resource|StreamInterface|File|UploadedFile $contents
         * @param  array<string, mixed>|string  $options
         */
        public function put($path, $contents, $options = []): bool
        {
            $result = parent::put($path, $contents, $options);

            return ++$this->writes === 1 && $result;
        }

        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            return false;
        }
    };
    Storage::set('local', $refusing);
    $document = new JournalDocument;
    $document->mergeCasts(['attachment' => AsFile::using(middleware: JournalVariants::class)]);
    $document->setAttribute('attachment', __FILE__);
    expect(fn () => $document->save())->toThrow(FiloquentException::class)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(24)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('retired');
    config(['filoquent.redis.database' => null]);
    Storage::set('local', $disk);
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0)->and($disk->allFiles())->toBeEmpty();
});

it('leaves colliding existing destinations outside the journal', function (): void {
    $uuid = Str::uuid7();
    $id = (string) $uuid;
    Str::createUuidsUsing(fn (): UuidInterface => $uuid);

    try {
        $path = 'assets/'.hash('sha1', JournalDocument::class.'::attachment').'/'.$id.'/'.$id.'.txt';
        Storage::disk('local')->put($path, 'existing');
        expect(fn () => JournalDocument::query()->create(['attachment' => UploadedFile::fake()->createWithContent('source.txt', 'collision source')]))->toThrow(FiloquentException::class, 'overwrite')
            ->and(resolve(RedisUploadJournal::class)->due(UploadLedger::scope(DB::connection()), 100))->toBeEmpty()
            ->and(Storage::disk('local')->get($path))->toBe('existing');
    } finally {
        Str::createUuidsNormally();
    }
});

it('does not resolve Redis while disabled for saves or garbage collection', function (): void {
    config(['filoquent.redis.database' => null]);
    app()->forgetInstance(RedisUploadJournal::class);
    app()->bind(RedisUploadJournal::class, fn (): never => throw new RuntimeException('Unexpected Redis resolution.'));
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    expect($document->attachment?->default()?->exists())->toBeTrue()
        ->and(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
});

it('keeps a damaged pending timestamp from starving a bounded cleanup batch', function (): void {
    DB::beginTransaction();
    $first = JournalDocument::query()->create(['attachment' => __FILE__])->attachment?->default();
    $second = JournalDocument::query()->create(['attachment' => __FILE__])->attachment?->default();
    DB::rollBack();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $damaged = DB::table('filoquent_uploads')->where('state', 'pending')->oldest('checked_at')->orderBy('id')->first();
    DB::table('filoquent_uploads')->where('id', $damaged->id)->update(['orphaned_at' => null]);
    $this->travel(25)->hours();

    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(1)
        ->and(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('id'))->toBe($damaged->id)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    $retained = AsFile::decode($damaged->manifest)?->default();
    expect($retained?->exists())->toBeTrue()
        ->and(($first?->exists() ? 1 : 0) + ($second?->exists() ? 1 : 0))->toBe(1);
});

it('isolates two actual SQL ledgers even when a routing alias is reused', function (): void {
    config(['database.connections.journal_route' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    Artisan::call('migrate', ['--database' => 'journal_route']);
    $scope = UploadLedger::scope(DB::connection('journal_route'));
    DB::connection('journal_route')->beginTransaction();
    $document = new JournalDocument(['attachment' => __FILE__]);
    $document->setConnection('journal_route');
    $document->getConnection()->getSchemaBuilder()->create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
    $document->save();
    $asset = $document->attachment?->default();
    DB::connection('journal_route')->rollBack();
    DB::purge('journal_route');
    Artisan::call('migrate', ['--database' => 'journal_route']);
    expect(UploadLedger::scope(DB::connection('journal_route')))->not->toBe($scope)
        ->and(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'journal_route']))->toBe(0)
        ->and(DB::connection('journal_route')->table('filoquent_uploads')->count())->toBe(0)
        ->and($asset?->exists())->toBeTrue();
});

it('keeps SQL uniqueness authoritative while a second PDO imports a live writer intent', function (bool $commit): void {
    $database = tempnam(sys_get_temp_dir(), 'filoquent-journal-sql-');
    config([
        'database.connections.journal_writer' => ['driver' => 'sqlite', 'database' => $database, 'busy_timeout' => 0],
        'database.connections.journal_collector' => ['driver' => 'sqlite', 'database' => $database, 'busy_timeout' => 0],
    ]);

    try {
        Artisan::call('migrate', ['--database' => 'journal_writer']);
        $writer = DB::connection('journal_writer');
        $writer->statement('PRAGMA journal_mode=WAL');
        $writer->getSchemaBuilder()->create('journal_documents', function (Blueprint $table): void {
            $table->id();
            $table->filoquent('attachment')->nullable();
        });
        $collector = DB::connection('journal_collector');
        $collector->statement('PRAGMA busy_timeout=0');
        expect($collector->getPdo())->not->toBe($writer->getPdo());
        /** @var InMemoryUploadJournal $journal */
        $journal = resolve(RedisUploadJournal::class);
        $journal->onRecord = function (UploadIntent $intent) use ($journal): void {
            $journal->records[$intent->scope][$intent->id] = $intent->encode();
            $journal->scores[$intent->scope][$intent->id] = 0;
            expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'journal_collector']))->toBe(1)
                ->and(DB::connection('journal_collector')->table('filoquent_uploads')->count())->toBe(0)
                ->and(Storage::disk('local')->allFiles())->toBeEmpty();
            unset($journal->records[$intent->scope][$intent->id], $journal->scores[$intent->scope][$intent->id]);
        };
        $writer->beginTransaction();
        $document = new JournalDocument(['attachment' => __FILE__]);
        $document->setConnection('journal_writer');
        $document->save();
        $asset = $document->attachment?->default();

        if ($commit) {
            $writer->commit();
            expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'journal_collector']))->toBe(0)
                ->and($collector->table('filoquent_uploads')->value('state'))->toBe('active');
        } else {
            $writer->rollBack();
            expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'journal_collector']))->toBe(0)
                ->and($collector->table('filoquent_uploads')->value('state'))->toBe('pending');
            $this->travel(24)->hours();
            expect(Artisan::call('filoquent:gc', ['--apply' => true, '--connection' => 'journal_collector']))->toBe(0)
                ->and($asset?->exists())->toBeFalse();
        }
    } finally {
        DB::purge('journal_writer');
        DB::purge('journal_collector');
        Illuminate\Support\Facades\File::delete($database);
        Illuminate\Support\Facades\File::delete($database.'-wal');
        Illuminate\Support\Facades\File::delete($database.'-shm');
    }
})->with([true, false]);

it('rejects a duplicate Redis UUID after a rolled back lost registration reply', function (): void {
    $uuid = Str::uuid7();
    Str::createUuidsUsing(fn (): UuidInterface => $uuid);

    try {
        /** @var InMemoryUploadJournal $journal */
        $journal = resolve(RedisUploadJournal::class);
        $journal->loseRegistrationReply = true;
        expect(fn () => JournalDocument::query()->create(['attachment' => __FILE__]))->toThrow(FiloquentException::class, 'reply was lost');
        $journal->loseRegistrationReply = false;
        expect(fn () => JournalDocument::query()->create(['attachment' => __FILE__]))->toThrow(FiloquentException::class, 'duplicated')
            ->and(DB::table('filoquent_uploads')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    } finally {
        Str::createUuidsNormally();
    }
});

it('continues SQL pending cleanup and active reconciliation when Redis cannot be read', function (): void {
    DB::beginTransaction();
    $pending = JournalDocument::query()->create(['attachment' => __FILE__]);
    $pendingAsset = $pending->attachment?->default();
    DB::rollBack();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $active = JournalDocument::query()->create(['attachment' => __FILE__]);
    DB::table('journal_documents')->where('id', $active->getKey())->delete();
    app()->instance(RedisUploadJournal::class, new class extends InMemoryUploadJournal
    {
        public function due(string $scope, int $limit): array
        {
            throw new FiloquentException('Redis is unavailable.');
        }
    });
    $this->travel(24)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and($pendingAsset?->exists())->toBeFalse()
        ->and(DB::table('filoquent_uploads')->where('state', 'pending')->count())->toBe(0)
        ->and(DB::table('filoquent_uploads')->where('state', 'active')->value('orphaned_at'))->not->toBeNull()
        ->and($active->attachment?->default()?->exists())->toBeTrue();
});
