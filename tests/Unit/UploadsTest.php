<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Asset;
use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\PendingAsset;
use ArtisanToolbox\Filoquent\Support\FileWriter;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\File;

it('snapshots an upload using its content type without consuming its source', function (): void {
    $source = UploadedFile::fake()->createWithContent('unsafe.php', 'Hello, Filoquent!');
    $upload = Upload::from($source);

    expect($upload->name)->toBe('unsafe')
        ->and($upload->extension)->toBe('txt')
        ->and($upload->size)->toBe(17)
        ->and(file_get_contents($upload->path))->toBe('Hello, Filoquent!')
        ->and(is_file($source->getPathname()))->toBeTrue();

    $snapshot = $upload->path;
    unset($upload);
    expect(is_file($snapshot))->toBeFalse()
        ->and(is_file($source->getPathname()))->toBeTrue();
});

it('preserves caller stream ownership and position', function (): void {
    $stream = fopen('php://temp', 'w+b');

    throw_unless(is_resource($stream), RuntimeException::class, 'Unable to create a test stream.');

    fwrite($stream, 'whole stream');
    fseek($stream, 5);
    $upload = Upload::from($stream);

    expect(file_get_contents($upload->path))->toBe('whole stream')
        ->and(ftell($stream))->toBe(5);
    unset($upload);
    expect(is_resource($stream))->toBeTrue();
    fclose($stream);
});

it('rejects nonlocal or invalid sources', function (mixed $source): void {
    expect(fn () => Upload::from($source))->toThrow(FiloquentException::class);
})->with([null, 42, 'https://example.com/file.txt', 'php://temp', __DIR__, 'relative.txt']);

it('round trips strict immutable asset metadata without storage access', function (): void {
    $manifest = ['original' => ['filesystem' => 'public', 'path' => 'uploads/photo.png', 'default' => true]];
    $assets = Assets::fromArray($manifest);

    expect($assets->toArray())->toBe($manifest)
        ->and($assets->jsonSerialize())->toBe($manifest)
        ->and($assets->default())->toBe($assets->get('original'))
        ->and($assets->get('missing'))->toBeNull()
        ->and(Assets::fromArray([])->default())->toBeNull()
        ->and((string) new Assets([]))->toBe('');
});

it('rejects malformed manifests', function (array $manifest): void {
    expect(fn () => Assets::fromArray($manifest))->toThrow(FiloquentException::class);
})->with([
    [[['filesystem' => 'public', 'path' => 'x.txt', 'default' => true]]],
    [['original' => ['filesystem' => 'public', 'path' => '../x.txt', 'default' => true]]],
    [['original' => ['filesystem' => 'public', 'path' => 'x.txt', 'default' => 1]]],
    [['original' => ['filesystem' => 'public', 'path' => 'x.txt', 'default' => false]]],
    [['original' => ['filesystem' => 'public', 'path' => 'x.txt', 'default' => true, 'unknown' => true]]],
    [['original' => ['filesystem' => 'public', 'path' => 'x.txt', 'default' => true], 'other' => ['filesystem' => 'public', 'path' => 'y.txt', 'default' => true]]],
]);

it('stores safe names and exposes filesystem access through an asset', function (): void {
    Storage::fake('uploads');
    $upload = Upload::from(UploadedFile::fake()->createWithContent('Résumé final.php', 'safe content'));
    $assets = (new FileWriter)->store($upload, 'uploads', 'isolated', FileNaming::Original);
    $asset = $assets->default();

    expect($asset)->toBeInstanceOf(Asset::class)
        ->and($asset->path)->toBe('isolated/resume-final.txt')
        ->and($asset->exists())->toBeTrue()
        ->and((string) $asset)->toBe($asset->url());
    $stream = $asset->readStream();
    expect(stream_get_contents($stream))->toBe('safe content');
    fclose($stream);
});

it('sanitizes custom names and validates explicit extensions', function (): void {
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.php', 'plain text'));
    expect($upload->asset(name: 'My- Résumé')->name)->toBe('my-resume')
        ->and($upload->asset(extension: 'SVG')->extension)->toBe('svg');
    expect(fn () => $upload->asset(name: '../escape'))->toThrow(FiloquentException::class);
    expect(fn () => $upload->asset(extension: '../php'))->toThrow(FiloquentException::class);
    expect(fn () => PendingAsset::fromPath($upload->path, key: '../variant'))->toThrow(FiloquentException::class);
});

it('resolves middleware from the container and writes all variants', function (): void {
    Storage::fake('uploads');
    app()->instance(UploadVariantsMiddleware::class, new UploadVariantsMiddleware('preview'));
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.php', 'variant content'));
    $assets = (new FileWriter)->store($upload, 'uploads', 'variants', middleware: UploadVariantsMiddleware::class);

    expect(array_keys($assets->all()))->toBe(['original', 'preview'])
        ->and($assets->default())->toBe($assets->get('original'))
        ->and($assets->get('preview')->default)->toBeFalse()
        ->and($assets->get('preview')->exists())->toBeTrue()
        ->and(Storage::disk('uploads')->get($assets->get('preview')->path))->toBe('variant content');
});

it('validates middleware results before creating any files', function (string $variant, bool $default): void {
    Storage::fake('uploads');
    app()->instance(UploadVariantsMiddleware::class, new UploadVariantsMiddleware($variant, $default));
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.txt', 'variant content'));

    expect(fn () => (new FileWriter)->store($upload, 'uploads', 'invalid', middleware: UploadVariantsMiddleware::class))
        ->toThrow(FiloquentException::class);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);
})->with([['original', false], ['preview', true]]);

it('refuses to overwrite a destination and leaves its contents intact', function (): void {
    Storage::fake('uploads');
    Storage::disk('uploads')->put('existing/source.txt', 'existing');
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.txt', 'replacement'));

    expect(fn () => (new FileWriter)->store($upload, 'uploads', 'existing', FileNaming::Original))->toThrow(FiloquentException::class);
    expect(Storage::disk('uploads')->get('existing/source.txt'))->toBe('existing');
});

it('reports an asset as missing when its exact path is a directory', function (): void {
    Storage::fake('uploads');
    Storage::disk('uploads')->makeDirectory('missing/avatar.txt');

    expect((new Asset('uploads', 'missing/avatar.txt'))->exists())->toBeFalse();
});

it('cleans up only files from the failed write attempt', function (bool $throws): void {
    $realDisk = Storage::fake('uploads');
    $realDisk->put('unrelated/keep.txt', 'keep');
    $disk = Mockery::mock($realDisk);
    $attempts = 0;
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, mixed $stream) use ($realDisk, &$attempts, $throws): bool {
        $realDisk->put($path, $stream);

        if (++$attempts === 2) {
            throw_if($throws, RuntimeException::class, 'The storage adapter failed.');

            return false;
        }

        return true;
    });
    Storage::shouldReceive('disk')->with('uploads')->andReturn($disk);
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.txt', 'content'));

    expect(fn () => (new FileWriter)->store($upload, 'uploads', 'failed', middleware: UploadVariantsMiddleware::class))->toThrow(FiloquentException::class);
    expect($realDisk->allFiles())->toBe(['unrelated/keep.txt']);
})->with([false, true]);

class UploadVariantsMiddleware extends Middleware
{
    public function __construct(private string $key = 'preview', private bool $default = false, private bool $originalDefault = true) {}

    /** @return non-empty-list<PendingAsset> */
    public function handle(Upload $file): array
    {
        return [$file->asset(default: $this->originalDefault), $file->asset($this->key, name: 'preview', default: $this->default)];
    }
}

it('reports cleanup failure while preserving the original adapter error', function (): void {
    Exceptions::fake();
    $realDisk = Storage::fake('uploads');
    $disk = Mockery::mock($realDisk);
    $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('Original adapter failure.'));
    $disk->shouldReceive('delete')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('uploads')->andReturn($disk);
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.txt', 'content'));

    try {
        (new FileWriter)->store($upload, 'uploads', 'failed');
        test()->fail('The write failure must propagate.');
    } catch (FiloquentException $exception) {
        expect($exception->getPrevious()?->getMessage())->toBe('Original adapter failure.');
    }

    Exceptions::assertReported(fn (FiloquentException $exception): bool => $exception->getMessage() === 'Unable to clean up a failed asset write.');
});

it('rejects missing defaults and duplicate filenames before writing', function (): void {
    Storage::fake('uploads');
    $upload = Upload::from(UploadedFile::fake()->createWithContent('preview.txt', 'content'));
    app()->instance(UploadVariantsMiddleware::class, new UploadVariantsMiddleware(originalDefault: false));

    expect(fn () => (new FileWriter)->store($upload, 'uploads', 'invalid', middleware: UploadVariantsMiddleware::class))->toThrow(FiloquentException::class);
    app()->instance(UploadVariantsMiddleware::class, new UploadVariantsMiddleware);
    expect(fn () => (new FileWriter)->store($upload, 'uploads', 'invalid', FileNaming::Original, middleware: UploadVariantsMiddleware::class))->toThrow(FiloquentException::class);
    expect(Storage::disk('uploads')->allFiles())->toBe([]);
});

it('retains a local source and accepts a Symfony file object', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'filoquent-test-');

    throw_if($path === false, RuntimeException::class, 'Unable to create the source fixture.');

    file_put_contents($path, 'source content');

    try {
        $upload = Upload::from(new File($path));
        file_put_contents($path, 'changed');
        expect(file_get_contents($upload->path))->toBe('source content');
        unset($upload);
        expect(file_get_contents($path))->toBe('changed');
    } finally {
        Illuminate\Support\Facades\File::delete($path);
    }
});

it('rejects an unsuccessful uploaded file', function (): void {
    $source = new Symfony\Component\HttpFoundation\File\UploadedFile('/not-present', 'failed.txt', error: UPLOAD_ERR_PARTIAL, test: true);
    expect(fn () => Upload::from($source))->toThrow(FiloquentException::class);
});

it('supports temporary URLs through the configured Laravel filesystem', function (): void {
    $disk = Storage::fake('uploads');
    $expiration = new DateTimeImmutable('+1 hour');
    $disk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expires, array $options) use ($expiration): string {
        expect($path)->toBe('source.txt')->and($expires)->toBe($expiration)->and($options)->toBe(['download' => true]);

        return 'https://example.test/temporary/source.txt';
    });

    expect((new Asset('uploads', 'source.txt'))->temporaryUrl($expiration, ['download' => true]))->toBe('https://example.test/temporary/source.txt');
});

it('preserves middleware names with the default UUID naming policy', function (): void {
    Storage::fake('uploads');
    $upload = Upload::from(UploadedFile::fake()->createWithContent('document.php', 'plain document'));
    $assets = (new FileWriter)->store($upload, 'uploads', 'named', middleware: UploadVariantsMiddleware::class);

    expect($assets->get('original')->path)->toBe('named/document.txt')
        ->and($assets->get('preview')->path)->toBe('named/preview.txt');
});

it('rejects nonportable or unsafe metadata paths without accessing storage', function (string $path): void {
    expect(fn () => new Asset('uploads', $path))->toThrow(FiloquentException::class);
})->with(['../outside', '/absolute', 'nested//file', 'nested/./file', 'nested/../file', 'nested/.. /file', 'nested./file', 'file.txt ', 'php://memory', 'nested\\file', "file\0.txt"]);

it('rejects an unreadable stream without closing its caller owned handle', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'filoquent-test-');

    throw_if($path === false, RuntimeException::class, 'Unable to create a source fixture.');

    $stream = fopen($path, 'wb');

    if (! is_resource($stream)) {
        Illuminate\Support\Facades\File::delete($path);

        throw new RuntimeException('Unable to open a source fixture.');
    }

    try {
        expect(fn () => Upload::from($stream))->toThrow(FiloquentException::class);
        expect(ftell($stream))->toBe(0);
    } finally {
        fclose($stream);
        Illuminate\Support\Facades\File::delete($path);
    }
});

it('rejects reserved Windows device names for pending assets', function (string $name): void {
    $upload = Upload::from(UploadedFile::fake()->createWithContent('source.txt', 'content'));
    expect(fn () => $upload->asset(name: $name))->toThrow(FiloquentException::class);
})->with(['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'LPT9']);
