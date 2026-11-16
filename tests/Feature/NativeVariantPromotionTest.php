<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Enums\UploadState;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use ArtisanToolbox\Filoquent\Tests\Fixtures\InMemoryUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class NativePromotionMiddleware extends Middleware
{
    public function handle(Upload $file): array
    {
        return [$file->asset(name: 'original'), $file->asset('thumb', name: 'thumb', default: false)];
    }
}

beforeEach(function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Requires a disposable MySQL or PostgreSQL endpoint.');
    }
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => 2]);
    app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
    Storage::fake('local');
    Artisan::call('migrate');
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
});

it('keeps concurrent promotion outside live additions across writer outcomes and collector isolation', function (bool $rollback, bool $newRoot, string $isolation): void {
    if ($newRoot) {
        DB::beginTransaction();
    }
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    $original = $document->attachment?->default();
    $document->mergeCasts(['attachment' => AsFile::using(middleware: NativePromotionMiddleware::class)]);

    if (! $newRoot) {
        DB::beginTransaction();
    }
    $thumb = $document->attachment?->regenerateMissing(['thumb'])->get('thumb');
    $scope = UploadLedger::scope(DB::connection());
    $records = resolve(RedisUploadJournal::class)->due($scope, 10);
    $id = null;
    foreach ($records as $candidate => $payload) {
        if ($payload !== null && UploadIntent::decode($payload, $scope, $candidate)->parentId !== null) {
            $id = $candidate;

            break;
        }
    }
    throw_unless(is_string($id), RuntimeException::class, 'Expected a variant extension intent.');
    expect(DB::table('filoquent_uploads')->where('id', $id)->exists())->toBeFalse();
    $input = tempnam(sys_get_temp_dir(), 'filoquent-promotion-');
    throw_unless(is_string($input), RuntimeException::class, 'Could not allocate isolated promotion input.');
    $process = null;

    try {
        file_put_contents($input, json_encode(['connection' => DB::connection()->getConfig(), 'payload' => $records[$id], 'scope' => $scope, 'id' => $id, 'isolation' => $isolation], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/Fixtures/PromoteUploadIntent.php', $input], [0 => ['pipe', 'r'], 1 => ['file', $input.'.stdout', 'w'], 2 => ['file', $input.'.stderr', 'w']], $pipes);
        throw_unless(is_resource($process), RuntimeException::class, 'Could not start the independent promotion process.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 10;
        while (! is_file($input.'.ready') && proc_get_status($process)['running'] && microtime(true) < $deadline) {
            Sleep::usleep(10000);
        }
        expect(is_file($input.'.ready'))->toBeTrue();
        Sleep::usleep(200000);
        expect(proc_get_status($process)['running'])->toBeTrue()
            ->and(is_file($input.'.result'))->toBeFalse();

        if ($rollback) {
            DB::rollBack();
        } else {
            DB::commit();
        }
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            Sleep::usleep(10000);
        }
        expect(proc_get_status($process)['running'])->toBeFalse();
        /** @var array{result: string, before?: string, after?: string} $result */
        $result = json_decode(file_get_contents($input.'.result') ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        expect($result['result'])->toBe('promoted')
            ->and($result['after'] ?? null)->toBe($result['before'] ?? 'missing isolation')
            ->and(DB::table('filoquent_uploads')->where('id', $id)->where('state', UploadState::Pending->value)->exists())->toBe($rollback)
            ->and($document->fresh()?->attachment?->get('thumb')?->path)->toBe($rollback ? null : $thumb?->path)
            ->and($thumb?->exists())->toBeTrue()
            ->and($original?->exists())->toBeTrue();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        if (is_resource($process)) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
        foreach (['', '.ready', '.result', '.stdout', '.stderr'] as $suffix) {
            if (is_file($input.$suffix)) {
                File::delete($input.$suffix);
            }
        }
    }
})->with([false, true])->with([false, true])->with(
    getenv('FILOQUENT_TEST_DATABASE_DRIVER') === 'pgsql' ? ['default', 'repeatable read', 'serializable'] : ['default'],
)->group('native-promotion');
