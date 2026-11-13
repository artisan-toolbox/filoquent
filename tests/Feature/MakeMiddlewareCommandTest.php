<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** @property-read Assets|null $attachment */
class GeneratedMiddlewareDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;
}

beforeEach(function (): void {
    app()->getNamespace();
    $directory = sys_get_temp_dir().'/filoquent-middleware-'.bin2hex(random_bytes(8));
    config([
        'filoquent-test.original_app_path' => app_path(),
        'filoquent-test.directory' => $directory,
    ]);
    app()->useAppPath($directory.'/app');
});

afterEach(function (): void {
    app()->useAppPath(config('filoquent-test.original_app_path'));
    File::deleteDirectory(config('filoquent-test.directory'));
});

it('registers the upload middleware generator', function (): void {
    expect(Artisan::all())->toHaveKey('filoquent:make-middleware');
});

it('creates upload middleware in the application files namespace', function (string $name, string $namespace, string $path): void {
    expect(Artisan::call('filoquent:make-middleware', ['name' => $name]))->toBe(0);
    $source = File::get(app_path($path));

    expect($source)->toContain(
        'declare(strict_types=1);',
        'namespace '.$namespace.';',
        'use ArtisanToolbox\\Filoquent\\Middleware;',
        'use ArtisanToolbox\\Filoquent\\PendingAsset;',
        'use ArtisanToolbox\\Filoquent\\Upload;',
        'final class NamedOriginal extends Middleware',
        'public function handle(Upload $file): PendingAsset|array',
        'return $file->asset();',
    )->not->toContain('{{', 'DummyNamespace', 'DummyClass');
    expect(Artisan::output())->toContain('created successfully');
})->with([
    'short name' => ['NamedOriginal', 'App\\Files', 'Files/NamedOriginal.php'],
    'nested name' => ['Images/NamedOriginal', 'App\\Files\\Images', 'Files/Images/NamedOriginal.php'],
    'fully qualified application name' => ['App\\Media\\NamedOriginal', 'App\\Media', 'Media/NamedOriginal.php'],
]);

it('uses the configured application namespace', function (): void {
    (new ReflectionProperty(app(), 'namespace'))->setValue(app(), 'Example\\');

    expect(Artisan::call('filoquent:make-middleware', ['name' => 'NamedOriginal']))->toBe(0)
        ->and(File::get(app_path('Files/NamedOriginal.php')))->toContain('namespace Example\\Files;');
});

it('preserves existing middleware unless force is supplied', function (): void {
    Artisan::call('filoquent:make-middleware', ['name' => 'NamedOriginal']);
    $path = app_path('Files/NamedOriginal.php');
    File::put($path, '<?php // Application customization.');

    expect(Artisan::call('filoquent:make-middleware', ['name' => 'NamedOriginal']))->toBe(0)
        ->and(Artisan::output())->toContain('Filoquent middleware already exists.')
        ->and(File::get($path))->toBe('<?php // Application customization.');

    expect(Artisan::call('filoquent:make-middleware', ['name' => 'NamedOriginal', '--force' => true]))->toBe(0)
        ->and(File::get($path))->toContain('final class NamedOriginal extends Middleware')
        ->not->toContain('Application customization');
});

it('rejects a reserved PHP class name without creating files', function (): void {
    expect(Artisan::call('filoquent:make-middleware', ['name' => 'class']))->toBe(0)
        ->and(Artisan::output())->toContain('The name "class" is reserved by PHP.')
        ->and(File::exists(app_path('Files')))->toBeFalse();
});

it('generates middleware without resolving storage or the database', function (): void {
    DB::shouldReceive('connection')->never();
    Storage::shouldReceive('disk')->never();

    expect(Artisan::call('filoquent:make-middleware', ['name' => 'NamedOriginal']))->toBe(0)
        ->and(File::exists(app_path('Files/NamedOriginal.php')))->toBeTrue();
});

it('saves and reads the original upload through the generated middleware', function (): void {
    Artisan::call('filoquent:make-middleware', ['name' => 'GeneratedOriginal']);
    require app_path('Files/GeneratedOriginal.php');

    $class = 'App\\Files\\GeneratedOriginal';
    app()->bind(Middleware::class, $class);
    $middleware = resolve(Middleware::class);
    expect($middleware::class)->toBe($class);
    $input = UploadedFile::fake()->createWithContent('report.txt', 'Generated middleware upload');
    $upload = Upload::from($input);
    $pending = $middleware->handle($upload);
    expect($pending)->toEqual($upload->asset());

    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('generated_middleware_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });

    $document = new GeneratedMiddlewareDocument;
    $document->mergeCasts(['attachment' => AsFile::using(middleware: $class)]);
    $document->setAttribute('attachment', $input);
    $document->save();
    $asset = $document->fresh()?->attachment?->default();

    expect($asset)->not->toBeNull()
        ->and($asset?->exists())->toBeTrue()
        ->and(Storage::disk('local')->get($asset?->path))->toBe('Generated middleware upload')
        ->and(array_keys($document->fresh()?->attachment?->all() ?? []))->toBe(['original']);
});
