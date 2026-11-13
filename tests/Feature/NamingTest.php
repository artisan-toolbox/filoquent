<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @property-read Assets|null $attachment */
class NamingDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('naming_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
});

it('stores the original filename through an enum cast declaration and retains it after configuration changes', function (): void {
    $document = new NamingDocument;
    $document->mergeCasts(['attachment' => AsFile::using(naming: FileNaming::Original)]);
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('Résumé final.png', 'original content'));
    $document->save();

    $path = $document->attachment?->default()?->path;
    expect(basename($path))->toBe('resume-final.txt')
        ->and(Storage::disk('local')->get($path))->toBe('original content');
    $document->mergeCasts(['attachment' => AsFile::using(naming: FileNaming::Uuid)]);
    expect($document->attachment?->default()?->path)->toBe($path)
        ->and($document->fresh()?->attachment?->default()?->path)->toBe($path);
});

it('keeps UUID filenames as the default and supports explicitly selecting them', function (bool $explicit): void {
    $document = new NamingDocument;

    if ($explicit) {
        $document->mergeCasts(['attachment' => AsFile::using(naming: FileNaming::Uuid)]);
    }
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'uuid content'));
    $document->save();
    $path = $document->attachment?->default()?->path;

    expect(Str::isUuid(pathinfo($path, PATHINFO_FILENAME), 7))->toBeTrue()
        ->and(Storage::disk('local')->get($path))->toBe('uuid content');
})->with([false, true]);

it('keeps custom filename overrides with either enum policy', function (string $policy): void {
    $document = new NamingDocument;
    $document->mergeCasts(['attachment' => AsFile::using(naming: FileNaming::from($policy), name: 'My Report')]);
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('source.txt', 'custom content'));
    $document->save();

    expect(basename($document->attachment?->default()?->path))->toBe('my-report.txt');
})->with(['uuid', 'original']);

it('rejects strings at the public naming configuration boundary', function (): void {
    $using = new ReflectionMethod(AsFile::class, 'using');
    expect(fn (): mixed => $using->invoke(null, 'original'))->toThrow(TypeError::class);
});

it('rejects an invalid serialized naming policy before publishing files', function (): void {
    $document = new NamingDocument;
    $document->mergeCasts(['attachment' => AsFile::class.':unsupported']);

    expect(fn () => $document->setAttribute('attachment', __FILE__))->toThrow(FiloquentException::class)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(NamingDocument::query()->count())->toBe(0);
});

it('accepts the existing serialized naming values at the Laravel cast boundary', function (string $wire): void {
    $document = new NamingDocument;
    $document->mergeCasts(['attachment' => AsFile::class.':'.$wire]);
    $document->setAttribute('attachment', UploadedFile::fake()->createWithContent('My Source.txt', 'transport content'));
    $document->save();

    $path = $document->attachment?->default()?->path;
    expect(basename($path))->toBe('my-source.txt');
    expect($document->attachment?->toArray())->toBe([
        'original' => ['filesystem' => 'local', 'path' => $path, 'default' => true],
    ]);
})->with(['original', '%6Friginal']);
