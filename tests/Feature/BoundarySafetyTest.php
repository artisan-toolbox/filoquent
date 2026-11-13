<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** @property-read Assets|null $avatar */
#[Unguarded]
class BoundaryDocument extends Model
{
    use HasFiloquent;
    use SoftDeletes;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['avatar' => AsFile::class];
    }
}

#[Table(name: 'boundary_documents')]
class RenamedBoundaryDocument extends BoundaryDocument {}

/** @property-read Assets|null $profile_photo */
#[Table(name: 'boundary_documents')]
class RenamedFieldDocument extends BoundaryDocument
{
    protected function casts(): array
    {
        return ['profile_photo' => AsFile::class];
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('boundary_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('avatar')->nullable();
        $table->softDeletes();
    });
});

it('rejects serializing pending snapshots while preserving their original model', function (): void {
    $document = new BoundaryDocument(['avatar' => __FILE__]);
    expect(fn (): string => serialize($document))->toThrow(FiloquentException::class, 'Save pending uploads');
    expect($document->save())->toBeTrue();
    $asset = $document->avatar?->default();
    expect($asset?->exists())->toBeTrue()
        ->and(Storage::disk('local')->get($asset?->path))->toBe(file_get_contents(__FILE__));

    $copy = unserialize(serialize($document));
    expect($copy->toArray())->toBe($document->toArray())
        ->and($document->toArray()['avatar'])->toBe($document->avatar?->toArray());
});

it('preserves files when an enclosing transaction rolls back a forceDeleted observer failure', function (): void {
    $document = BoundaryDocument::query()->create(['avatar' => __FILE__]);
    $asset = $document->avatar?->default();
    BoundaryDocument::forceDeleted(function (): void {
        throw new RuntimeException('Notification failed.');
    });

    expect(fn () => DB::transaction(fn () => $document->forceDelete()))->toThrow(RuntimeException::class, 'Notification failed.');
    expect(BoundaryDocument::query()->whereKey($document->getKey())->exists())->toBeTrue()
        ->and($asset?->exists())->toBeTrue();
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0)
        ->and($asset?->exists())->toBeTrue();
});

it('reads and deletes a renamed model upload without its original class hash or current root', function (): void {
    $document = BoundaryDocument::query()->create(['avatar' => __FILE__]);
    $asset = $document->avatar?->default();
    expect($asset?->path)->toStartWith('assets/'.hash('sha1', BoundaryDocument::class.'::avatar').'/');
    config(['filoquent.base_path' => 'future-assets']);

    $renamed = RenamedBoundaryDocument::query()->whereKey($document->getKey())->firstOrFail();
    expect($renamed->avatar?->default()?->path)->toBe($asset?->path);
    $renamed->forceDelete();
    expect($asset?->exists())->toBeFalse()
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
});

it('uses the renamed class hash only for its next upload while retiring the previous generation', function (): void {
    $document = BoundaryDocument::query()->create(['avatar' => __FILE__]);
    $old = $document->avatar?->default();
    $renamed = RenamedBoundaryDocument::query()->whereKey($document->getKey())->firstOrFail();
    $renamed->setAttribute('avatar', __FILE__);
    $renamed->save();

    expect($renamed->avatar?->default()?->path)->toStartWith('assets/'.hash('sha1', RenamedBoundaryDocument::class.'::avatar').'/')
        ->and($old?->exists())->toBeFalse();
});

it('reads replaces and deletes existing files after the field and model are renamed', function (): void {
    $document = BoundaryDocument::query()->create(['avatar' => __FILE__]);
    $old = $document->avatar?->default();
    Schema::table('boundary_documents', function (Blueprint $table): void {
        $table->renameColumn('avatar', 'profile_photo');
    });

    $renamed = RenamedFieldDocument::query()->whereKey($document->getKey())->firstOrFail();
    expect($renamed->profile_photo?->default()?->path)->toBe($old?->path)
        ->and($old?->exists())->toBeTrue();

    $renamed->setAttribute('profile_photo', __FILE__);
    $renamed->save();
    $current = $renamed->profile_photo?->default();
    expect($current?->path)->toStartWith('assets/'.hash('sha1', RenamedFieldDocument::class.'::profile_photo').'/')
        ->and($old?->exists())->toBeFalse();
    $renamed->forceDelete();
    expect($current?->exists())->toBeFalse()
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
});
