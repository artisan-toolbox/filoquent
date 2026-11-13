<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * @property-write UploadedFile|string|null $avatar
 */
#[Unguarded]
class PersistenceUser extends Model
{
    use HasFiloquent;
    use SoftDeletes;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['avatar' => AsFile::class, 'cover' => AsFile::using(naming: FileNaming::Original)];
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);

    Artisan::call('migrate');
    Schema::create('persistence_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->filoquent('avatar')->nullable();
        $table->filoquent('cover')->nullable();
        $table->softDeletes();
    });
});

it('uses the Laravel default disk when the package disk is explicitly null', function (): void {
    config(['filoquent.filesystem' => null, 'filesystems.default' => 'local']);
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $asset = $model->getAttribute('avatar')?->default();

    expect($asset?->filesystem)->toBe('local')
        ->and($asset?->exists())->toBeTrue();
});

it('publishes the last assigned upload only when saving and retires its exact files', function (): void {
    $model = new PersistenceUser;
    $model->avatar = UploadedFile::fake()->createWithContent('old.txt', 'discard');
    $model->avatar = UploadedFile::fake()->createWithContent('first.txt', 'first');

    expect($model->getAttribute('avatar'))->toBeNull()
        ->and($model->toArray()['avatar'])->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();

    $model->saveQuietly();
    $first = $model->fresh()?->getAttribute('avatar')?->default()?->path;
    expect($first)->toBeString()
        ->and(Storage::disk('local')->get($first))->toBe('first');

    $model->avatar = UploadedFile::fake()->createWithContent('second.txt', 'second');
    expect($model->getAttribute('avatar')?->default()?->path)->toBe($first);
    $model->save();
    $second = $model->fresh()?->getAttribute('avatar')?->default()?->path;
    expect($second)->toBeString()->not->toBe($first)
        ->and(Storage::disk('local')->get($second))->toBe('second');
    Storage::disk('local')->assertMissing($first);

    $model->deleteQuietly();
    Storage::disk('local')->assertExists($second);
    $model->restore();
    expect($model->fresh()?->getAttribute('avatar')?->default()?->path)->toBe($second);
    $model->forceDeleteQuietly();
    Storage::disk('local')->assertMissing($second);
});

it('preserves a committed upload across an outer rollback and retries retirement after commit', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $first = $model->getAttribute('avatar')?->default()?->path;
    DB::beginTransaction();
    $model->avatar = null;
    $model->save();
    expect($model->fresh()?->getAttribute('avatar'))->toBeNull();
    Storage::disk('local')->assertExists($first);
    DB::rollBack();

    $model->refresh();
    expect($model->getAttribute('avatar')?->default()?->path)->toBe($first);
    DB::transaction(function () use ($model) {
        $model->avatar = null;
        $model->save();
    });

    Artisan::call('filoquent:prune');
    Storage::disk('local')->assertExists($first);
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0);
    Storage::disk('local')->assertMissing($first);
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0);
});

it('writes nothing when model creation or update is vetoed and permits a quiet retry', function (): void {
    PersistenceUser::creating(fn () => false);
    $model = new PersistenceUser(['avatar' => __FILE__]);
    expect($model->save())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
    expect($model->saveQuietly())->toBeTrue();
    $original = $model->getAttribute('avatar')?->default()?->path;
    PersistenceUser::updating(fn () => false);
    $model->avatar = __FILE__;
    expect($model->save())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([$original]);
});

it('rejects a stale or partially selected replacement before storing new bytes', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $stale = $model->fresh();
    $model->avatar = __FILE__;
    $model->save();
    $files = Storage::disk('local')->allFiles();

    foreach ([$stale, PersistenceUser::query()->select('id')->whereKey($model->getKey())->firstOrFail()] as $other) {
        $other->avatar = __FILE__;
        expect(fn () => $other->save())->toThrow(FiloquentException::class);
    }
    expect(Storage::disk('local')->allFiles())->toBe($files);
    $stale->forceDelete();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('excludes file ownership when replicating and rejects arbitrary metadata', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $copy = $model->replicateQuietly();
    $copy->save();
    expect($copy->fresh()?->getAttribute('avatar'))->toBeNull();
    expect(fn () => $copy->setAttribute('avatar', $model->getAttribute('avatar')))->toThrow(FiloquentException::class);
    DB::table('persistence_users')->where('id', $copy->getKey())->update(['avatar' => '{}']);
    expect(fn () => $copy->fresh()?->getAttribute('avatar'))->toThrow(FiloquentException::class);
});

it('restores pending state when a saved observer throws', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $old = $model->getAttribute('avatar')?->default()?->path;
    PersistenceUser::saved(fn () => throw new RuntimeException('observer failed'));
    $model->avatar = __FILE__;
    expect(fn () => $model->save())->toThrow(RuntimeException::class, 'observer failed')
        ->and($model->fresh()?->getAttribute('avatar')?->default()?->path)->toBe($old)
        ->and($model->getAttribute('avatar')?->default()?->path)->toBe($old);
    Storage::disk('local')->assertExists($old);
    $model->saveQuietly();
    Storage::disk('local')->assertMissing($old);
});

it('retires previously owned uploads after the configured base path changes', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $path = $model->getAttribute('avatar')->default()->path;
    config(['filoquent.base_path' => 'new-root']);

    $model->avatar = null;
    $model->save();
    Storage::disk('local')->assertMissing($path);
});

it('rolls back observer side effects when a saving listener vetoes publication', function (): void {
    $model = PersistenceUser::query()->create(['name' => 'before']);
    PersistenceUser::saving(function (PersistenceUser $owner): bool {
        DB::table('persistence_users')->where('id', $owner->getKey())->update(['name' => 'observer']);

        return false;
    });
    $model->avatar = __FILE__;
    expect($model->save())->toBeFalse()
        ->and($model->fresh()?->getAttribute('name'))->toBe('before')
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('retains unknown manifests and only deletes exact owned files', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $path = $model->getAttribute('avatar')->default()->path;
    $borrower = PersistenceUser::query()->create();
    DB::table('persistence_users')->where('id', $borrower->getKey())->update(['avatar' => $model->getRawOriginal('avatar')]);
    $borrower->forceDelete();
    Storage::disk('local')->assertExists($path);

    $neighbor = dirname($path).'/unmanaged.txt';
    Storage::disk('local')->put($neighbor, 'keep');
    $model->forceDelete();
    Storage::disk('local')->assertMissing($path)->assertExists($neighbor);
});

it('retries a failed storage deletion without losing the committed replacement', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $first = $model->getAttribute('avatar')->default()->path;
    $disk = Storage::disk('local');
    $refusing = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            return false;
        }
    };
    Storage::set('local', $refusing);
    $model->avatar = __FILE__;
    expect($model->save())->toBeTrue();
    $second = $model->fresh()?->getAttribute('avatar')->default()->path;
    $disk->assertExists([$first, $second]);
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(1);
    Storage::set('local', $disk);
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0);
    $disk->assertMissing($first)->assertExists($second);
});

it('keeps each transaction and its retirements on the model connection', function (): void {
    config(['database.connections.filoquent_tenant' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    Artisan::call('migrate', ['--database' => 'filoquent_tenant']);
    Schema::connection('filoquent_tenant')->create('persistence_users', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('avatar')->nullable();
        $table->filoquent('cover')->nullable();
        $table->softDeletes();
    });

    $model = new PersistenceUser;
    $model->setConnection('filoquent_tenant');
    $model->avatar = __FILE__;
    $model->save();
    $first = $model->getAttribute('avatar')->default()->path;
    $tenant = DB::connection('filoquent_tenant');
    $tenant->beginTransaction();
    DB::beginTransaction();
    $model->avatar = null;
    $model->save();
    DB::commit();
    Storage::disk('local')->assertExists($first);
    $tenant->rollBack();
    expect($model->fresh()?->getAttribute('avatar')->default()->path)->toBe($first)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    Storage::disk('local')->assertExists($first);
});

it('discards file assignments and refuses an insert-ignore or observer assignment', function (): void {
    $model = new PersistenceUser(['avatar' => __FILE__]);
    expect(fn () => $model->saveOrIgnore())->toThrow(FiloquentException::class);
    $model->discardChanges();
    $model->saveQuietly();
    expect($model->fresh()?->getAttribute('avatar'))->toBeNull();
    PersistenceUser::saving(fn (PersistenceUser $owner) => $owner->setAttribute('avatar', __FILE__));
    expect(fn () => $model->save())->toThrow(FiloquentException::class)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('resets force deletion after an observer throws so a soft delete retry keeps the files', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    $first = $model->getAttribute('avatar')->default()->path;
    PersistenceUser::deleting(fn () => throw new RuntimeException('delete cancelled'));
    expect(fn () => $model->forceDelete())->toThrow(RuntimeException::class, 'delete cancelled')
        ->and($model->isForceDeleting())->toBeFalse();
    $model->deleteQuietly();
    expect(PersistenceUser::withTrashed()->whereKey($model->getKey())->firstOrFail()->trashed())->toBeTrue();
    Storage::disk('local')->assertExists($first);
});

it('retires the final upload when a deleting observer replaces it through another instance', function (): void {
    $model = PersistenceUser::query()->create(['avatar' => __FILE__]);
    PersistenceUser::deleting(function (PersistenceUser $deleting): void {
        $other = $deleting->fresh();
        $other->setAttribute('avatar', __FILE__);
        $other->saveQuietly();
    });
    expect($model->forceDelete())->toBeTrue()
        ->and(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
