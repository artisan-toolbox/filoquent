<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Tests\Fixtures\LegacyPathDocument;
use ArtisanToolbox\Filoquent\Tests\Fixtures\PathDocument;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['database.default' => 'testing', 'filoquent.filesystem' => 'path-uploads', 'filoquent.base_path' => 'assets']);
    Storage::fake('path-uploads');
    Schema::create('path_documents', function (Blueprint $table): void {
        $table->id();
        $table->json('attachment')->nullable();
        $table->json('other_attachment')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });

    Artisan::call('migrate', ['--force' => true]);
});

it('reads and retires prior uploads after a class rename and base path change', function (): void {
    $legacy = LegacyPathDocument::query()->create(['attachment' => __FILE__, 'other_attachment' => __FILE__]);
    $old = $legacy->attachment->default();
    $other = $legacy->other_attachment->default();
    expect($old->path)->toStartWith('assets/'.hash('sha1', LegacyPathDocument::class.'::attachment').'/')
        ->and($other->path)->toStartWith('assets/'.hash('sha1', LegacyPathDocument::class.'::other_attachment').'/');
    config(['filoquent.base_path' => 'new-root']);
    Storage::fake('future-uploads');
    config(['filoquent.filesystem' => 'future-uploads']);

    $current = PathDocument::query()->whereKey($legacy->getKey())->firstOrFail();
    expect($current->attachment->default()->path)->toBe($old->path)
        ->and($current->attachment->default()->exists())->toBeTrue();

    $current->setAttribute('attachment', UploadedFile::fake()->createWithContent('replacement.txt', 'new report'));
    $current->save();
    $replacement = $current->attachment->default();
    expect($old->exists())->toBeFalse()
        ->and($other->exists())->toBeTrue()
        ->and($other->filesystem)->toBe('path-uploads')
        ->and($replacement->filesystem)->toBe('future-uploads')
        ->and($replacement->path)->toStartWith('new-root/'.hash('sha1', PathDocument::class.'::attachment').'/');

    $current->forceDelete();
    expect($replacement->exists())->toBeFalse()
        ->and($other->exists())->toBeFalse()
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
});

it('preserves recorded paths and ownership through reads saves and soft deletion after configuration and model changes', function (): void {
    $legacy = LegacyPathDocument::query()->create(['attachment' => UploadedFile::fake()->createWithContent('report.txt', 'original report')]);
    $manifest = $legacy->fresh()->getRawOriginal('attachment');
    $receipts = DB::table('filoquent_uploads')->get()->toArray();
    $files = Storage::disk('path-uploads')->allFiles();
    Storage::fake('future-uploads');
    config(['filoquent.filesystem' => 'future-uploads', 'filoquent.base_path' => 'future-root']);

    $current = PathDocument::query()->whereKey($legacy->getKey())->firstOrFail();
    $asset = $current->attachment->default();
    expect($asset->filesystem)->toBe('path-uploads')
        ->and(Storage::disk($asset->filesystem)->get($asset->path))->toBe('original report');

    $current->save();
    $current->delete();
    $retained = PathDocument::withTrashed()->whereKey($legacy->getKey())->firstOrFail();
    expect($retained->trashed())->toBeTrue()
        ->and($retained->getRawOriginal('attachment'))->toBe($manifest)
        ->and(DB::table('filoquent_uploads')->get()->toArray())->toEqual($receipts)
        ->and(Storage::disk('path-uploads')->allFiles())->toBe($files)
        ->and(Storage::disk('future-uploads')->allFiles())->toBe([]);

    $retained->forceDelete();
    expect($asset->exists())->toBeFalse()
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
});
