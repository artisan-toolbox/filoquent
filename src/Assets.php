<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

/** @implements Arrayable<string, array{filesystem: string, path: string, default: bool}> */
final readonly class Assets implements Arrayable, JsonSerializable, Stringable
{
    /** @var array<string, Asset> */
    private array $assets;

    /** @param array<array-key, mixed> $assets */
    public function __construct(array $assets = [])
    {
        $defaults = 0;
        $paths = [];
        $validated = [];

        foreach ($assets as $key => $asset) {
            throw_if(! is_string($key) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $key) || ! $asset instanceof Asset, FiloquentException::class, 'Assets must map valid variant keys to Asset objects.');

            $identity = $asset->filesystem."\0".$asset->path;

            throw_if(isset($paths[$identity]), FiloquentException::class, 'Asset paths must be unique within a manifest.');

            $validated[$key] = $asset;
            $paths[$identity] = true;
            $defaults += (int) $asset->default;
        }

        throw_if($assets !== [] && $defaults !== 1, FiloquentException::class, 'A nonempty asset manifest must have exactly one default.');

        $this->assets = $validated;
    }

    /** @param array<array-key, mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        $assets = [];

        foreach ($manifest as $key => $entry) {
            throw_if(! is_string($key) || ! is_array($entry) || count($entry) !== 3
                || ! isset($entry['filesystem'], $entry['path'], $entry['default'])
                || ! is_string($entry['filesystem']) || ! is_string($entry['path']) || ! is_bool($entry['default']), FiloquentException::class, 'Each asset entry must contain only filesystem, path, and default metadata.');

            $assets[$key] = new Asset($entry['filesystem'], $entry['path'], $entry['default']);
        }

        return new self($assets);
    }

    public function get(string $key): ?Asset
    {
        return $this->assets[$key] ?? null;
    }

    public function default(): ?Asset
    {
        foreach ($this->assets as $asset) {
            if ($asset->default) {
                return $asset;
            }
        }

        return null;
    }

    /** @return array<string, Asset> */
    public function all(): array
    {
        return $this->assets;
    }

    public function __toString(): string
    {
        return $this->default()?->url() ?? '';
    }

    /** @return array<string, array{filesystem: string, path: string, default: bool}> */
    public function toArray(): array
    {
        return array_map(static fn (Asset $asset): array => $asset->toArray(), $this->assets);
    }

    /** @return array<string, array{filesystem: string, path: string, default: bool}> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
