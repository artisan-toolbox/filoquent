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
        $pending = $this->prepare($upload, $middleware);

        $assets = [];
        $sources = [];

        foreach ($pending as $file) {
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
     * @internal
     *
     * @param  list<string>  $keys
     * @param  Closure(Assets): void|null  $beforeWrite
     */
    public function storeMissing(Assets $existing, array $keys, string $directory, string $middleware, ?string $source = null, ?Closure $beforeWrite = null): ?Assets
    {
        $retained = $existing->all();
        $input = $source === null ? ($retained['original'] ?? $existing->default()) : ($retained[$source] ?? null);
        throw_if($input === null, FiloquentException::class, 'The regeneration source key is not present in the manifest.');
        $upload = Upload::from($input);
        $pending = $this->prepare($upload, $middleware);
        $assets = [];
        $sources = [];
        foreach ($pending as $file) {
            if (isset($retained[$file->key]) || ! in_array($file->key, $keys, true)) {
                continue;
            }
            $assets[$file->key] = new Asset($input->filesystem, $directory.'/'.$file->name.'.'.$file->extension, $assets === []);
            $sources[$file->key] = $file;
        }

        return $assets === [] ? null : $this->write(new Assets($assets), $sources, $beforeWrite);
    }

    /** @return non-empty-list<PendingAsset> */
    private function prepare(Upload $upload, ?string $middleware): array
    {
        throw_if($middleware !== null && ! is_subclass_of($middleware, Middleware::class), FiloquentException::class, 'Upload middleware must extend the Filoquent Middleware class.');
        $output = $middleware === null ? $upload->asset() : resolve($middleware)->handle($upload);
        $pending = $output instanceof PendingAsset ? [$output] : $output;
        throw_if(! is_array($pending) || ! array_is_list($pending) || $pending === [], FiloquentException::class, 'Upload middleware must return a pending asset or a nonempty list of pending assets.');
        $keys = [];
        $paths = [];
        $defaults = 0;
        foreach ($pending as $file) {
            throw_if(! $file instanceof PendingAsset || isset($keys[$file->key]), FiloquentException::class, 'Upload middleware must return pending assets with unique keys.');
            $path = strtolower($file->name.'.'.$file->extension);
            throw_if(isset($paths[$path]), FiloquentException::class, 'Asset destination paths must be unique, including letter case.');
            $keys[$file->key] = true;
            $paths[$path] = true;
            $defaults += (int) $file->default;
        }
        throw_if($defaults !== 1, FiloquentException::class, 'A nonempty asset manifest must have exactly one default.');

        return $pending;
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
