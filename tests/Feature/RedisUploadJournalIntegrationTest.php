<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\UuidInterface;

function integrationJournalRedis(): Connection
{
    return resolve('redis')->connection();
}

function integrationJournalKey(?string $id = null): string
{
    return 'filoquent:uploads:'.hash('sha256', (string) config('app.env')).':'.UploadLedger::scope(DB::connection()).':'.($id ?? 'due');
}

beforeEach(function (): void {
    $socket = getenv('FILOQUENT_TEST_REDIS_SOCKET');
    $host = getenv('FILOQUENT_TEST_REDIS_HOST');

    if ($socket === false && $host === false) {
        $this->markTestSkipped('Set FILOQUENT_TEST_REDIS_SOCKET or FILOQUENT_TEST_REDIS_HOST for the isolated Redis integration group.');
    }
    config([
        'filoquent.filesystem' => 'local', 'filoquent.redis.database' => 2,
        'database.redis' => [
            'client' => 'phpredis',
            'options' => ['prefix' => 'filoquent-integration:'.Str::uuid().':'],
            'default' => ['host' => $socket !== false ? $socket : $host, 'port' => $socket !== false ? 0 : (int) (getenv('FILOQUENT_TEST_REDIS_PORT') ?: 6379),
                'database' => 2, 'timeout' => 1, 'read_timeout' => 1, 'persistent' => true, 'persistent_id' => 'filoquent-shared-test-client'],
        ],
    ]);
    app()->forgetInstance('redis');
    app()->forgetInstance(RedisUploadJournal::class);
    Storage::fake('local');
    Artisan::call('migrate');
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
});

afterEach(function (): void {
    if (getenv('FILOQUENT_TEST_REDIS_SOCKET') === false && getenv('FILOQUENT_TEST_REDIS_HOST') === false) {
        return;
    }
    $connection = integrationJournalRedis();
    $index = integrationJournalKey();

    if ($connection->type($index) === Redis::REDIS_ZSET) {
        foreach ($connection->zrange($index, 0, -1) as $id) {
            $connection->del(integrationJournalKey($id));
        }
    }
    $connection->del($index);
});

it('reports the private journal connection and counts only the selected ledger intents read only', function (): void {
    config(['database.redis.default.database' => 1]);
    $shared = integrationJournalRedis();
    $shared->set('status-shared-marker', 'application database');

    try {
        DB::beginTransaction();
        JournalDocument::query()->create(['attachment' => __FILE__]);
        DB::rollBack();
        $journal = resolve(RedisUploadJournal::class);
        $scope = UploadLedger::scope(DB::connection());
        $before = $journal->due($scope, 100);
        $files = Storage::disk('local')->allFiles();
        $journal->ping();

        expect($journal->count($scope))->toBe(1)
            ->and($journal->count((string) Str::uuid()))->toBe(0)
            ->and(Artisan::call('filoquent:status'))->toBe(0)
            ->and(Artisan::output())->toContain('Connected', 'Journal intents')
            ->and($journal->due($scope, 100))->toBe($before)
            ->and(Storage::disk('local')->allFiles())->toBe($files)
            ->and(DB::table('filoquent_uploads')->count())->toBe(0)
            ->and($shared->get('status-shared-marker'))->toBe('application database');
    } finally {
        $shared->del('status-shared-marker');
        $shared->select(2);
    }
})->group('redis');

it('uses atomic duplicate rejection exact acknowledgments and nonresurrecting retries', function (): void {
    DB::beginTransaction();
    JournalDocument::query()->create(['attachment' => __FILE__]);
    DB::rollBack();
    $journal = resolve(RedisUploadJournal::class);
    $scope = UploadLedger::scope(DB::connection());
    $records = $journal->due($scope, 100);
    $id = array_key_first($records);

    throw_unless(is_string($id), RuntimeException::class, 'Expected a UUID journal record after rollback.');
    $payload = $records[$id];
    $intent = UploadIntent::decode($payload, $scope, $id);
    expect(fn () => $journal->record($intent))->toThrow(FiloquentException::class, 'already registered');
    $journal->acknowledge($scope, $id, $payload.'changed');
    expect($journal->due($scope, 100))->toBe($records);
    $journal->retry($scope, $id, $payload.'changed');
    expect($journal->due($scope, 100))->toBe($records);
    $journal->acknowledge($scope, $id, $payload);
    $journal->retry($scope, $id, $payload);
    expect($journal->due($scope, 100))->toBeEmpty()
        ->and(integrationJournalRedis()->exists(integrationJournalKey($id)))->toBe(0)
        ->and(integrationJournalRedis()->zcard(integrationJournalKey()))->toBe(0);
})->group('redis');

it('preserves the shared persistent connection database and application codecs', function (): void {
    config(['database.redis.default.database' => 1, 'database.redis.options.serializer' => Redis::SERIALIZER_PHP]);
    $shared = integrationJournalRedis();
    $shared->set('shared-marker', 'default database');

    try {
        DB::beginTransaction();
        $document = JournalDocument::query()->create(['attachment' => __FILE__]);
        DB::rollBack();
        expect($shared->get('shared-marker'))->toBe('default database');
        $scope = UploadLedger::scope(DB::connection());
        $journal = resolve(RedisUploadJournal::class);
        expect($journal->due($scope, 100))->toHaveCount(1)
            ->and(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
        $this->travel(24)->hours();
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and($document->attachment?->default()?->exists())->toBeFalse()
            ->and($shared->get('shared-marker'))->toBe('default database');
    } finally {
        $shared->del('shared-marker');
        $shared->select(2);
    }
})->group('redis');

it('fails corrupt-index registration before creating an unindexed intent or remote object', function (): void {
    $id = Str::uuid7();
    Str::createUuidsUsing(fn (): UuidInterface => $id);

    try {
        integrationJournalRedis()->set(integrationJournalKey(), 'corrupt index');
        expect(fn () => JournalDocument::query()->create(['attachment' => __FILE__]))->toThrow(FiloquentException::class)
            ->and(integrationJournalRedis()->exists(integrationJournalKey((string) $id)))->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    } finally {
        Str::createUuidsNormally();
    }
})->group('redis');

it('keeps preview read only and advances a bounded batch past malformed Redis records', function (): void {
    $poison = (string) Str::uuid7();
    $connection = integrationJournalRedis();
    $connection->set(integrationJournalKey($poison), '{bad');
    $connection->zadd(integrationJournalKey(), 0, $poison);
    DB::beginTransaction();
    JournalDocument::query()->create(['attachment' => __FILE__]);
    DB::rollBack();
    $before = $connection->zrange(integrationJournalKey(), 0, -1, true);
    expect(Artisan::call('filoquent:gc', ['--limit' => '1']))->toBe(1)
        ->and($connection->zrange(integrationJournalKey(), 0, -1, true))->toBe($before)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(1)
        ->and(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending')
        ->and($connection->get(integrationJournalKey($poison)))->toBe('{bad');
})->group('redis');

it('fails closed when Redis rejects the selected database without falling back to database zero', function (): void {
    $shared = integrationJournalRedis();
    config(['filoquent.redis.database' => 500000]);
    expect(fn () => JournalDocument::query()->create(['attachment' => __FILE__]))->toThrow(FiloquentException::class)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    $shared->select(0);

    try {
        expect($shared->exists(integrationJournalKey()))->toBe(0);
    } finally {
        $shared->select(2);
    }
})->group('redis');

it('rotates missing wrong-type and numeric journal entries without starving the next upload', function (string $poisonType): void {
    $id = $poisonType === 'numeric' ? '123' : (string) Str::uuid7();
    $connection = integrationJournalRedis();

    if ($poisonType === 'wrong-type') {
        $connection->rpush(integrationJournalKey($id), 'invalid record type');
    }
    $connection->zadd(integrationJournalKey(), 0, $id);
    DB::beginTransaction();
    JournalDocument::query()->create(['attachment' => __FILE__]);
    DB::rollBack();
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(1)
        ->and(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending')
        ->and($connection->zcard(integrationJournalKey()))->toBe(1);
})->with(['missing', 'wrong-type', 'numeric'])->group('redis');

it('preserves the payload and reports acknowledgment failure when the sorted index has the wrong type', function (): void {
    DB::beginTransaction();
    JournalDocument::query()->create(['attachment' => __FILE__]);
    DB::rollBack();
    $journal = resolve(RedisUploadJournal::class);
    $scope = UploadLedger::scope(DB::connection());
    $records = $journal->due($scope, 100);
    $id = (string) array_key_first($records);
    $payload = $records[$id];
    $connection = integrationJournalRedis();
    $connection->del(integrationJournalKey());
    $connection->set(integrationJournalKey(), 'invalid index type');

    try {
        expect(fn () => $journal->acknowledge($scope, $id, $payload))->toThrow(FiloquentException::class)
            ->and($connection->get(integrationJournalKey($id)))->toBe($payload);
    } finally {
        $connection->del(integrationJournalKey());
        $connection->zadd(integrationJournalKey(), 0, $id);
    }
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('pending');
})->group('redis');

it('fails registration closed when Redis ACL denies either publishing command', function (string $denied): void {
    $admin = integrationJournalRedis();
    $user = 'filoquent-test-'.Str::uuid();
    $password = (string) Str::uuid();
    $prefix = config('database.redis.options.prefix');
    $admin->client()->rawCommand('ACL', 'SETUSER', $user, 'on', '>'.$password, '~'.$prefix.'*', '+@all', '-'.$denied);

    try {
        config(['database.redis.default.username' => $user, 'database.redis.default.password' => $password]);
        app()->forgetInstance(RedisUploadJournal::class);
        expect(fn () => JournalDocument::query()->create(['attachment' => __FILE__]))->toThrow(FiloquentException::class)
            ->and(Storage::disk('local')->allFiles())->toBeEmpty()
            ->and(DB::table('filoquent_uploads')->count())->toBe(0);
        $ids = $admin->zrange(integrationJournalKey(), 0, -1);
        expect($ids)->toHaveCount($denied === 'set' ? 1 : 0);
        foreach ($ids as $id) {
            expect($admin->exists(integrationJournalKey($id)))->toBe(0);
        }
    } finally {
        $admin->client()->rawCommand('ACL', 'DELUSER', $user);
    }
})->with(['zadd', 'set'])->group('redis');

it('keeps a committed save successful and its intent discoverable when Redis denies acknowledgment deletion', function (): void {
    $admin = integrationJournalRedis();
    $user = 'filoquent-test-'.Str::uuid();
    $password = (string) Str::uuid();
    $prefix = config('database.redis.options.prefix');
    $admin->client()->rawCommand('ACL', 'SETUSER', $user, 'on', '>'.$password, '~'.$prefix.'*', '+@all', '-del');

    try {
        config(['database.redis.default.username' => $user, 'database.redis.default.password' => $password]);
        app()->forgetInstance(RedisUploadJournal::class);
        $document = JournalDocument::query()->create(['attachment' => __FILE__]);
        $scope = UploadLedger::scope(DB::connection());
        expect($document->fresh()?->attachment?->default()?->exists())->toBeTrue()
            ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active')
            ->and(resolve(RedisUploadJournal::class)->due($scope, 100))->toHaveCount(1);
        config(['database.redis.default.username' => null, 'database.redis.default.password' => null]);
        app()->forgetInstance(RedisUploadJournal::class);
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active')
            ->and(resolve(RedisUploadJournal::class)->due($scope, 100))->toBeEmpty();
    } finally {
        $admin->client()->rawCommand('ACL', 'DELUSER', $user);
    }
})->group('redis');
