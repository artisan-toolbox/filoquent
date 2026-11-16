<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Asset;
use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Attributes\RegenerateMissing;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use ArtisanToolbox\Filoquent\Tests\Fixtures\InMemoryUploadJournal;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * @property-read Assets<string, object{thumb: Asset|null}> $image
 * @property string|null $title
 */
#[Unguarded]
class RegenerationDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['image' => AsFile::class];
    }
}

class RegenerationMiddleware extends Middleware
{
    public int $calls = 0;

    public function handle(Upload $file): array
    {
        $this->calls++;

        return [$file->asset(name: 'changed-original'), $file->asset('thumb', name: 'thumb', default: false), $file->asset('large', name: 'large', default: false)];
    }
}

class HyphenatedRegenerationMiddleware extends Middleware
{
    public function handle(Upload $file): array
    {
        return [$file->asset(), $file->asset('large-thumb', name: 'large-thumb', default: false)];
    }
}

#[RegenerateMissing]
class FallbackRegenerationMiddleware extends RegenerationMiddleware {}

#[RegenerateMissing(source: 'thumb')]
class AlternateSourceMiddleware extends RegenerationMiddleware {}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('regeneration_documents', function (Blueprint $table): void {
        $table->id();
        $table->string('title')->nullable();
        $table->filoquent('image')->nullable();
    });
    app()->singleton(RegenerationMiddleware::class);
    app()->singleton(FallbackRegenerationMiddleware::class);
});

function regenerationDocument(bool $fallback = false): RegenerationDocument
{
    $document = RegenerationDocument::query()->create(['image' => UploadedFile::fake()->createWithContent('source.txt', 'original bytes')]);
    $document->mergeCasts(['image' => AsFile::using(middleware: $fallback ? FallbackRegenerationMiddleware::class : RegenerationMiddleware::class)]);

    return $document;
}

it('regenerates only requested absent keys and preserves original paths bytes and default', function (): void {
    $document = regenerationDocument();
    $original = $document->image->toArray();
    $assets = $document->image->regenerateMissing(['thumb']);

    expect(array_keys($assets->all()))->toBe(['original', 'thumb'])
        ->and($assets->toArray()['original'])->toBe($original['original'])
        ->and($assets->get('thumb')?->default)->toBeFalse()
        ->and(Storage::disk('local')->get($assets->get('thumb')->path))->toBe('original bytes')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2)
        ->and(DB::table('filoquent_uploads')->count())->toBe(1)
        ->and(Assets::fromArray(json_decode(DB::table('filoquent_uploads')->value('manifest'), true, flags: JSON_THROW_ON_ERROR))->toArray())->toEqualCanonicalizing($assets->toArray());
    $document->image->regenerateMissing(['original', 'thumb']);
    expect(resolve(RegenerationMiddleware::class)->calls)->toBe(1);
    $document->delete();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('generates a fallback key before returning and returns null for unsupported keys', function (bool $magic): void {
    $document = regenerationDocument(fallback: true);
    $assets = $document->image;
    expect(isset($assets->thumb))->toBeFalse()->and(resolve(FallbackRegenerationMiddleware::class)->calls)->toBe(0);
    $thumb = $magic ? $assets->thumb : $assets->get('thumb');

    expect($thumb?->exists())->toBeTrue()
        ->and($document->fresh()?->image->get('thumb')?->path)->toBe($thumb->path)
        ->and($assets->get('thumb')?->path)->toBe($thumb->path)
        ->and(resolve(FallbackRegenerationMiddleware::class)->calls)->toBe(1)
        ->and($assets->get('unsupported'))->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
})->with([false, true]);

it('keeps missing reads passive without the middleware attribute', function (): void {
    $document = regenerationDocument();
    expect($document->image->get('thumb'))->toBeNull()
        ->and($document->image->thumb)->toBeNull()
        ->and(resolve(RegenerationMiddleware::class)->calls)->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('does not save unrelated dirty attributes during regeneration or fallback', function (bool $fallback): void {
    $document = regenerationDocument(fallback: $fallback);
    $document->title = 'unsaved';
    expect(fn () => $fallback ? $document->image->get('thumb') : $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class, 'Save or discard')
        ->and($document->fresh()?->title)->toBeNull()
        ->and($document->title)->toBe('unsaved')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with([false, true]);

it('rejects stale snapshots before running middleware', function (): void {
    $document = regenerationDocument();
    $stale = $document->image;
    $document->setAttribute('image', UploadedFile::fake()->createWithContent('other.txt', 'replacement'));
    $document->save();
    $calls = resolve(RegenerationMiddleware::class)->calls;
    expect(fn () => $stale->regenerateMissing(['thumb']))->toThrow(FiloquentException::class)
        ->and(resolve(RegenerationMiddleware::class)->calls)->toBe($calls)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
});

it('keeps recorded filesystem and directory after configuration changes', function (): void {
    $document = regenerationDocument();
    $original = $document->image->default();
    config(['filoquent.filesystem' => 'unconfigured', 'filoquent.base_path' => 'new-root']);
    $assets = $document->image->regenerateMissing(['thumb']);
    expect($assets->get('thumb')?->filesystem)->toBe('local')
        ->and($assets->get('thumb')?->path)->toStartWith(dirname($original->path).'/')
        ->and($assets->default()?->path)->toBe($original->path);
    $document->delete();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('does not recreate a missing physical file when its key is already recorded', function (): void {
    $document = regenerationDocument(fallback: true);
    $thumb = $document->image->get('thumb');
    Storage::disk('local')->delete($thumb->path);
    $document->image->regenerateMissing(['thumb']);
    expect($document->image->get('thumb')?->exists())->toBeFalse()
        ->and(resolve(FallbackRegenerationMiddleware::class)->calls)->toBe(1);
});

it('requires a bound model middleware and a retained readable source', function (): void {
    $document = RegenerationDocument::query()->create(['image' => __FILE__]);
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class, 'middleware');
    expect(fn () => Assets::fromArray($document->image->toArray())->regenerateMissing(['thumb']))->toThrow(FiloquentException::class);
    $document->mergeCasts(['image' => AsFile::using(middleware: RegenerationMiddleware::class)]);
    expect(fn () => $document->image->regenerateMissing(['thumb'], source: 'missing'))->toThrow(FiloquentException::class);
    Storage::disk('local')->delete($document->image->default()->path);
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('recovers only additions after an observer failure through the journal', function (): void {
    $document = regenerationDocument();
    config(['filoquent.redis.database' => 2]);
    app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
    $original = $document->image->toArray();
    RegenerationDocument::saved(fn () => throw new RuntimeException('observer failed'));
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(RuntimeException::class, 'observer failed')
        ->and($document->image->toArray())->toBe($original)
        ->and($document->fresh()?->image->toArray())->toBe($original)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(24)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([$original['original']['path']]);
});

it('keeps committed additions live when journal acknowledgment is retried', function (): void {
    $document = regenerationDocument();
    config(['filoquent.redis.database' => 2]);
    $journal = new InMemoryUploadJournal;
    app()->instance(RedisUploadJournal::class, $journal);
    $journal->failAcknowledgment = true;
    $assets = $document->image->regenerateMissing(['thumb', 'large']);
    $scope = UploadLedger::scope(DB::connection());
    expect($journal->records[$scope])->toHaveCount(1);
    $journal->failAcknowledgment = false;
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($journal->records[$scope])->toBeEmpty()
        ->and($assets->get('thumb')?->exists())->toBeTrue()
        ->and($assets->get('large')?->exists())->toBeTrue()
        ->and(DB::table('filoquent_uploads')->count())->toBe(1);
});

it('runs later backfills from a retained source and honors a fallback source attribute', function (): void {
    $document = regenerationDocument();
    $document->image->regenerateMissing(['thumb']);
    $thumb = $document->image->get('thumb');
    Storage::disk('local')->put($thumb->path, 'retained reduced bytes');
    $document->mergeCasts(['image' => AsFile::using(middleware: AlternateSourceMiddleware::class)]);
    $large = $document->image->get('large');
    expect(Storage::disk('local')->get($large->path))->toBe('retained reduced bytes')
        ->and(Storage::disk('local')->get($document->image->get('original')->path))->toBe('original bytes');
    $document->delete();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a stale database owner and a borrowed manifest before processing', function (bool $borrowed): void {
    $document = regenerationDocument();

    if ($borrowed) {
        $borrower = RegenerationDocument::query()->create();
        DB::table('regeneration_documents')->where('id', $borrower->getKey())->update(['image' => $document->getRawOriginal('image')]);
        $document = $borrower->fresh();
        $document->mergeCasts(['image' => AsFile::using(middleware: RegenerationMiddleware::class)]);
    } else {
        $other = $document->fresh();
        $other->setAttribute('image', __FILE__);
        $other->save();
    }
    $files = Storage::disk('local')->allFiles();
    $calls = resolve(RegenerationMiddleware::class)->calls;
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class)
        ->and(resolve(RegenerationMiddleware::class)->calls)->toBe($calls)
        ->and(Storage::disk('local')->allFiles())->toBe($files);
})->with([false, true]);

it('preserves the original after a cancelled save without running middleware', function (): void {
    $document = regenerationDocument();
    $original = $document->image->toArray();
    RegenerationDocument::saving(fn () => false);
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class, 'refused')
        ->and($document->image->toArray())->toBe($original)
        ->and($document->isDirty())->toBeFalse()
        ->and(resolve(RegenerationMiddleware::class)->calls)->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('keeps an outer rollback recoverable without retiring original files', function (): void {
    $document = regenerationDocument();
    config(['filoquent.redis.database' => 2]);
    $journal = new InMemoryUploadJournal;
    app()->instance(RedisUploadJournal::class, $journal);
    $original = $document->image->toArray();
    DB::beginTransaction();
    $document->image->regenerateMissing(['thumb']);
    DB::rollBack();
    $document->refresh();
    expect($document->image->toArray())->toBe($original);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(24)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([$original['original']['path']]);
});

it('aborts before new bytes when journal registration fails', function (): void {
    $document = regenerationDocument();
    config(['filoquent.redis.database' => 2]);
    $journal = new InMemoryUploadJournal;
    $journal->failRegistration = true;
    app()->instance(RedisUploadJournal::class, $journal);
    $original = $document->image->toArray();
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class)
        ->and($document->fresh()?->image->toArray())->toBe($original)
        ->and(DB::table('filoquent_uploads')->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('acknowledges an original upload intent even after its manifest has gained variants', function (): void {
    config(['filoquent.redis.database' => 2]);
    $journal = new InMemoryUploadJournal;
    $journal->failAcknowledgment = true;
    app()->instance(RedisUploadJournal::class, $journal);
    $document = regenerationDocument();
    $document->image->regenerateMissing(['thumb']);
    $scope = UploadLedger::scope(DB::connection());
    expect($journal->records[$scope])->toHaveCount(2);
    $journal->failAcknowledgment = false;
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($journal->records[$scope])->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('removes only new files when a backfill storage write fails', function (): void {
    $document = regenerationDocument();
    $disk = Storage::disk('local');
    $original = $document->image->toArray();
    $failing = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public function put($path, $contents, $options = []): bool
        {
            parent::put($path, $contents, $options);

            return false;
        }
    };
    Storage::set('local', $failing);
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(FiloquentException::class)
        ->and($document->fresh()?->image->toArray())->toBe($original)
        ->and($disk->allFiles())->toBe([$original['original']['path']]);
});

it('serializes standalone assets as detached metadata without implicit generation', function (): void {
    $document = regenerationDocument(fallback: true);
    $copy = unserialize(serialize($document->image));
    expect($copy->toArray())->toBe($document->image->toArray())
        ->and($copy->get('thumb'))->toBeNull();
    expect(fn () => $copy->regenerateMissing(['thumb']))->toThrow(FiloquentException::class);
});

it('keeps committed regenerated state when an after commit callback throws', function (): void {
    $document = regenerationDocument();
    RegenerationDocument::saved(function (RegenerationDocument $saved): void {
        $saved->getConnection()->afterCommit(fn () => throw new RuntimeException('postcommit failure'));
    });
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(RuntimeException::class, 'postcommit failure')
        ->and($document->image->get('thumb')?->exists())->toBeTrue()
        ->and($document->fresh()?->image->get('thumb')?->path)->toBe($document->image->get('thumb')?->path);
});

it('copies an existing asset source without transferring its ownership', function (): void {
    $document = regenerationDocument();
    $original = $document->image->default();
    $copy = RegenerationDocument::query()->create(['image' => $original]);
    $copied = $copy->image->default();
    expect($copied->path)->not->toBe($original->path)
        ->and(Storage::disk('local')->get($copied->path))->toBe('original bytes');
    $copy->delete();
    expect($original->exists())->toBeTrue()->and($copied->exists())->toBeFalse();
});

it('keeps added variants visible to the read only health audit', function (): void {
    $document = regenerationDocument();
    $document->image->regenerateMissing(['thumb', 'large']);
    $before = $document->fresh()?->image->toArray();
    expect(Artisan::call('filoquent:health'))->toBe(0)
        ->and($document->fresh()?->image->toArray())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
});

it('regenerates hyphenated keys and leaves recorded hyphenated keys unchanged', function (): void {
    $document = regenerationDocument();
    $document->mergeCasts(['image' => AsFile::using(middleware: HyphenatedRegenerationMiddleware::class)]);
    $assets = $document->image->regenerateMissing(['large-thumb']);
    $thumb = $assets->get('large-thumb');

    expect($thumb?->exists())->toBeTrue()
        ->and($assets->__get('large-thumb'))->toBe($thumb)
        ->and($document->image->regenerateMissing(['large-thumb'])->toArray())->toBe($assets->toArray())
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('acknowledges committed additions after an explicit owner table remap', function (): void {
    $document = regenerationDocument();
    config(['filoquent.redis.database' => 2]);
    $journal = new InMemoryUploadJournal;
    $journal->failAcknowledgment = true;
    app()->instance(RedisUploadJournal::class, $journal);
    $assets = $document->image->regenerateMissing(['thumb']);
    $scope = UploadLedger::scope(DB::connection());
    Schema::rename('regeneration_documents', 'renamed_regeneration_documents');
    $tables = new OwnerTables(DB::connection());
    $tables->block('regeneration_documents');
    $issue = $tables->findPending('regeneration_documents');
    $tables->resolveRename('regeneration_documents', 'renamed_regeneration_documents', $issue->issue_id);
    $journal->failAcknowledgment = false;

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($journal->records[$scope])->toBeEmpty()
        ->and(DB::table('filoquent_uploads')->count())->toBe(1)
        ->and($assets->get('thumb')?->exists())->toBeTrue()
        ->and($assets->default()?->exists())->toBeTrue();
});

it('recovers abandoned additions after explicit owner table removal', function (): void {
    $document = regenerationDocument();
    config(['filoquent.redis.database' => 2]);
    $journal = new InMemoryUploadJournal;
    app()->instance(RedisUploadJournal::class, $journal);
    RegenerationDocument::saved(fn () => throw new RuntimeException('observer failed'));
    expect(fn () => $document->image->regenerateMissing(['thumb']))->toThrow(RuntimeException::class);
    $scope = UploadLedger::scope(DB::connection());
    $tables = new OwnerTables(DB::connection());
    Schema::drop('regeneration_documents');
    $tables->block('regeneration_documents');
    $issue = $tables->findPending('regeneration_documents');
    $tables->resolveRemoved('regeneration_documents', $issue->issue_id);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and($journal->records[$scope])->toBeEmpty()
        ->and(DB::table('filoquent_uploads')->where('state', 'pending')->count())->toBe(2);
    $this->travel(24)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a manifest borrowed from another field on the same owner', function (bool $redis): void {
    $document = regenerationDocument();
    $original = $document->image->toArray();
    $receipt = DB::table('filoquent_uploads')->value('manifest');
    Schema::table('regeneration_documents', function (Blueprint $table): void {
        $table->filoquent('second_image')->nullable();
    });
    DB::table('regeneration_documents')->where('id', $document->getKey())->update(['second_image' => $document->getRawOriginal('image')]);
    $document->refresh();
    $document->mergeCasts(['second_image' => AsFile::using(middleware: RegenerationMiddleware::class)]);

    if ($redis) {
        config(['filoquent.redis.database' => 2]);
        app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
    }

    expect(fn () => $document->getAttribute('second_image')->regenerateMissing(['thumb']))->toThrow(FiloquentException::class)
        ->and(resolve(RegenerationMiddleware::class)->calls)->toBe(0)
        ->and($document->fresh()?->image->toArray())->toBe($original)
        ->and(DB::table('filoquent_uploads')->value('manifest'))->toBe($receipt)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with([false, true]);

it('regenerates after a file column rename without rewriting receipt provenance', function (bool $redis): void {
    $document = regenerationDocument();
    $original = $document->image->default();
    Schema::table('regeneration_documents', function (Blueprint $table): void {
        $table->renameColumn('image', 'renamed_image');
    });
    $document->refresh();
    $document->mergeCasts(['renamed_image' => AsFile::using(middleware: RegenerationMiddleware::class)]);

    if ($redis) {
        config(['filoquent.redis.database' => 2]);
        app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
    }

    $assets = $document->getAttribute('renamed_image')->regenerateMissing(['thumb']);
    expect($assets->get('thumb')?->exists())->toBeTrue()
        ->and($assets->default()?->path)->toBe($original->path)
        ->and(DB::table('filoquent_uploads')->value('attribute'))->toBe('image')
        ->and(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
})->with([false, true]);
