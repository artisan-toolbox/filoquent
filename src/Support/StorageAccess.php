<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** @internal */
final readonly class StorageAccess
{
    public function forReading(string $filesystem): FilesystemAdapter
    {
        return $this->resolve($filesystem, false);
    }

    public function forDeletion(string $filesystem): FilesystemAdapter
    {
        return $this->resolve($filesystem, true);
    }

    private function resolve(string $filesystem, bool $deleting): FilesystemAdapter
    {
        $configuration = config('filesystems.disks');

        if (! is_array($configuration)) {
            $disk = Storage::disk($filesystem);
        } else {
            $protected = $configuration;
            $target = $configuration[$filesystem] ?? null;
            // Direct cached disks may replace the configured local root, including Storage::fake().
            $strict = $deleting && is_array($target) && ($target['driver'] ?? null) !== 'local';
            $this->protectLocalRoots($protected, $filesystem, $strict);
            config(['filesystems.disks' => $protected]);

            try {
                $disk = Storage::disk($filesystem);
            } finally {
                config(['filesystems.disks' => $configuration]);
            }
        }

        $disks = is_array($configuration) ? $configuration : [];
        $this->protectLocalRoots($disks, $disk->getConfig(), true);

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $this->requireLocalRoot($disk->getConfig());
        }

        return $disk;
    }

    /**
     * @param  array<array-key, mixed>  $disks
     * @param  string|array<array-key, mixed>  $configuration
     * @param  array<string, true>  $visited
     * @return array<array-key, mixed>
     */
    private function protectLocalRoots(array &$disks, string|array $configuration, bool $strict, array $visited = []): array
    {
        if (is_string($configuration)) {
            throw_if(isset($visited[$configuration]), FiloquentException::class, 'A composite filesystem cannot reference itself.');
            $visited[$configuration] = true;
            $disk = $disks[$configuration] ?? null;

            if (! is_array($disk)) {
                return [];
            }

            return $disks[$configuration] = $this->protectLocalRoots($disks, $disk, $strict, $visited);
        }

        if (($configuration['driver'] ?? null) === 'local') {
            if ($strict) {
                $this->requireLocalRoot($configuration);
            } elseif (! is_string($configuration['root'] ?? null) || ! is_dir($configuration['root'])) {
                unset($configuration['driver']);
            }
        }

        $parents = match ($configuration['driver'] ?? null) {
            'scoped' => ['disk'],
            'read-through' => ['primary', 'fallback'],
            default => [],
        };
        foreach ($parents as $key) {
            $parent = $configuration[$key] ?? null;

            if (is_string($parent) || is_array($parent)) {
                $protected = $this->protectLocalRoots($disks, $parent, $strict, $visited);

                if (is_array($parent)) {
                    $configuration[$key] = $protected;
                }
            }
        }

        return $configuration;
    }

    /** @param array<array-key, mixed> $configuration */
    private function requireLocalRoot(array $configuration): void
    {
        throw_if(! is_string($configuration['root'] ?? null) || ! is_dir($configuration['root']), FiloquentException::class, 'A local filesystem root is unavailable.');
    }
}
