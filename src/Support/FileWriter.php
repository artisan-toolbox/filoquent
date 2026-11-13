<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Asset;
use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Middleware;
use ArtisanToolbox\Filoquent\PendingAsset;
use ArtisanToolbox\Filoquent\Upload;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FileWriter
{
    /** @param Closure(Assets): void|null $beforeWrite */
    public function store(Upload $upload, string $filesystem, string $directory, FileNaming $naming = FileNaming::Uuid, ?string $name = null, ?string $middleware = null, ?Closure $beforeWrite = null): Assets
    {
        throw_if($middleware !== null && ! is_subclass_of($middleware, Middleware::class), FiloquentException::class, 'Upload middleware must extend the Filoquent Middleware class.');

        $output = $middleware === null ? $upload->asset() : resolve($middleware)->handle($upload);
        $pending = $output instanceof PendingAsset ? [$output] : $output;

        throw_if(! is_array($pending) || ! array_is_list($pending) || $pending === [], FiloquentException::class, 'Upload middleware must return a pending asset or a nonempty list of pending assets.');

        $assets = [];
        $sources = [];

        foreach ($pending as $file) {
            throw_if(! $file instanceof PendingAsset || isset($assets[$file->key]), FiloquentException::class, 'Upload middleware must return pending assets with unique keys.');

            $filename = $middleware !== null
                ? $file->name
                : ($name !== null
                    ? PendingAsset::fromPath($file->path, name: $name, extension: $file->extension)->name
                    : match ($naming) {
                        FileNaming::Uuid => (string) Str::uuid7(),
                        FileNaming::Original => $file->name,
                    });
            $assets[$file->key] = new Asset($filesystem, $directory.'/'.$filename.'.'.$file->extension, $file->default);
            $sources[$file->key] = $file;
        }

        return $this->write(new Assets($assets), $sources, $beforeWrite);
    }

    /**
     * @param  array<string, PendingAsset>  $sources
     * @param  Closure(Assets): void|null  $beforeWrite
     */
    private function write(Assets $assets, array $sources, ?Closure $beforeWrite): Assets
    {
        $paths = [];

        foreach ($assets->all() as $key => $asset) {
            $identity = $asset->filesystem."\0".strtolower($asset->path);

            throw_if(isset($paths[$identity]), FiloquentException::class, 'Asset destination paths must be unique, including letter case.');

            $paths[$identity] = true;
            $source = $sources[$key];

            throw_if(! is_file($source->path) || ! is_readable($source->path), FiloquentException::class, 'Every pending asset must remain readable until it is stored.');

            throw_if(Storage::disk($asset->filesystem)->exists($asset->path), FiloquentException::class, 'Filoquent will not overwrite an existing asset.');
        }

        $beforeWrite?->__invoke($assets);

        $created = [];

        try {
            foreach ($assets->all() as $key => $asset) {
                $source = $sources[$key];
                $stream = @fopen($source->path, 'rb');

                throw_unless(is_resource($stream), FiloquentException::class, 'Unable to open an asset for writing.');

                try {
                    $created[] = $asset;

                    throw_unless(Storage::disk($asset->filesystem)->put($asset->path, $stream), FiloquentException::class, 'Unable to write an asset to its filesystem.');
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($created) as $asset) {
                try {
                    throw_unless(Storage::disk($asset->filesystem)->delete($asset->path), FiloquentException::class, 'Unable to clean up a failed asset write.');
                } catch (Throwable $cleanupException) {
                    try {
                        report($cleanupException);
                    } catch (Throwable) {
                    }
                }
            }

            throw $exception instanceof FiloquentException ? $exception : new FiloquentException('Unable to write Filoquent assets.', previous: $exception);
        }

        return $assets;
    }
}
