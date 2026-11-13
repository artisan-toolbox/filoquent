<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Attributes\File;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\PendingAsset;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[File('attachment', naming: FileNaming::Original)]
#[File('cover', name: 'Cover, final')]
#[Table('attribute_documents')]
class AttributeDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}

#[File('attachment', name: 'Child report')]
class ChildAttributeDocument extends AttributeDocument {}

class MethodCastAttributeDocument extends AttributeDocument
{
    protected function casts(): array
    {
        return ['attachment' => AsFile::using(name: 'Method report')];
    }
}

class PropertyCastAttributeDocument extends AttributeDocument
{
    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->casts['attachment'] = AsFile::class;

        parent::__construct($attributes);
    }
}

#[File('attachment')]
#[File('attachment', naming: FileNaming::Original)]
class DuplicateAttributeDocument extends AttributeDocument {}

class AttributeVariants extends Middleware
{
    public function handle(Upload $file): PendingAsset|array
    {
        return [$file->asset(), $file->asset('preview', name: 'preview', default: false)];
    }
}

#[File('attachment', middleware: AttributeVariants::class)]
class MiddlewareAttributeDocument extends AttributeDocument {}

#[File('attachment', middleware: stdClass::class)]
class InvalidMiddlewareAttributeDocument extends AttributeDocument {}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('attribute_documents', function (Blueprint $table): void {
        $table->id();
        $table->boolean('enabled')->default(false);
        $table->filoquent('attachment')->nullable();
        $table->filoquent('cover')->nullable();
    });
});

it('runs the documented first upload with an attribute or the existing cast', function (bool $attribute): void {
    $document = new AttributeDocument;

    if (! $attribute) {
        $document->mergeCasts(['attachment' => AsFile::class]);
    }

    $document->setAttribute('enabled', 1);
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('report.txt', 'Hello Filoquent'));
    expect($document->getAttribute('attachment'))->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();

    $document->save();
    $saved = $document->fresh();
    $asset = $saved?->getAttribute('attachment')?->default();
    expect($asset?->exists())->toBeTrue()
        ->and(Storage::disk('local')->get($asset->path))->toBe('Hello Filoquent')
        ->and($saved?->getAttribute('enabled'))->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active');

    if ($attribute) {
        expect(basename($asset->path))->toBe('report.txt');
    } else {
        expect(Str::isUuid(pathinfo($asset->path, PATHINFO_FILENAME), 7))->toBeTrue();
    }
})->with([true, false]);

it('registers multiple fields and encodes filename configuration through the existing cast', function (): void {
    $document = new AttributeDocument;
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('report.txt', 'report'));
    $document->setAttribute('cover', UploadedFile::fake()->createWithContent('source.txt', 'cover'));
    $document->save();

    expect(basename($document->fresh()?->getAttribute('cover')?->default()?->path))->toBe('cover-final.txt')
        ->and(DB::table('filoquent_uploads')->count())->toBe(2);
});

it('inherits declarations and lets a child replace a parent declaration', function (): void {
    $document = new ChildAttributeDocument;
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'child'));
    $document->setAttribute('cover', UploadedFile::fake()->createWithContent('source.txt', 'cover'));
    $document->save();

    expect(basename($document->getAttribute('attachment')?->default()?->path))->toBe('child-report.txt')
        ->and(basename($document->getAttribute('cover')?->default()?->path))->toBe('cover-final.txt');
});

it('gives explicit property and method casts priority over attribute declarations', function (AttributeDocument $document, string $filename): void {
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'explicit'));
    $document->save();
    $name = pathinfo($document->getAttribute('attachment')?->default()?->path, PATHINFO_FILENAME);

    expect($filename === 'uuid' ? Str::isUuid($name, 7) : $name === $filename)->toBeTrue();
})->with([
    [fn (): AttributeDocument => new MethodCastAttributeDocument, 'method-report'],
    [fn (): AttributeDocument => new PropertyCastAttributeDocument, 'uuid'],
]);

it('keeps runtime cast overrides local to the model instance', function (): void {
    $first = new AttributeDocument;
    $first->mergeCasts(['attachment' => AsFile::using(name: 'Runtime report')]);
    $second = new AttributeDocument;

    foreach ([$first, $second] as $document) {
        $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'isolated'));
        $document->save();
    }

    expect(basename($first->getAttribute('attachment')?->default()?->path))->toBe('runtime-report.txt')
        ->and(basename($second->getAttribute('attachment')?->default()?->path))->toBe('source.txt');
});

it('runs middleware variants through the same lifecycle as casts', function (): void {
    $document = new MiddlewareAttributeDocument;
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'variants'));
    $document->save();

    expect(Storage::disk('local')->get($document->fresh()?->getAttribute('attachment')?->get('preview')?->path))->toBe('variants')
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
    $document->delete();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('preserves existing assets through replacement rollback and excludes files from replication', function (): void {
    $document = new AttributeDocument;
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('first.txt', 'first'));
    $document->save();
    $first = $document->getAttribute('attachment')?->default()?->path;
    expect($document->replicate()->getAttribute('attachment'))->toBeNull();

    DB::beginTransaction();

    try {
        $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('second.txt', 'second'));
        $document->save();
    } finally {
        DB::rollBack();
    }
    $document->refresh();
    expect($document->getAttribute('attachment')?->default()?->path)->toBe($first)
        ->and(Storage::disk('local')->get($first))->toBe('first');

    $document->setAttribute('attachment', null);
    $document->save();
    expect($document->fresh()?->getAttribute('attachment'))->toBeNull();
    Storage::disk('local')->assertMissing($first);
});

it('rejects duplicate declarations before writing files or rows', function (): void {
    expect(fn () => new DuplicateAttributeDocument)->toThrow(FiloquentException::class, 'declared more than once')
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(DB::table('attribute_documents')->count())->toBe(0);
});

it('rejects invalid column names at the configuration boundary', function (string $attribute): void {
    expect(fn () => new File($attribute))->toThrow(FiloquentException::class);
})->with(['', 'nested.path', 'file->key', "bad\0key", str_repeat('a', 65)]);

it('rejects strings where native attributes require a naming enum', function (): void {
    $constructor = new ReflectionClass(File::class);
    expect(fn () => $constructor->newInstance('attachment', 'original'))->toThrow(TypeError::class);
});

it('rejects invalid middleware without publishing files', function (): void {
    $document = new InvalidMiddlewareAttributeDocument;
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'invalid'));

    expect(fn () => $document->save())->toThrow(FiloquentException::class)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(DB::table('attribute_documents')->count())->toBe(0);
});
