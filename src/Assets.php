<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\AssetContext;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

/**
 * @template TKey of string = string
 *
 * @template-covariant TVariants of object = object
 *
 * @mixin TVariants
 *
 * @implements Arrayable<string, array{filesystem: string, path: string, default: bool}>
 */
final readonly class Assets implements Arrayable, JsonSerializable, Stringable
{
    /** @var array<string, Asset> */
    private array $_assets;

    /** @param array<array-key, mixed> $assets */
    public function __construct(array $assets = [], private ?AssetContext $_context = null)
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

        $this->_assets = $validated;
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

    /** @param TKey $key */
    public function get(string $key): ?Asset
    {
        if (isset($this->_assets[$key])) {
            return $this->_assets[$key];
        }

        $fallback = $this->_context?->fallback();

        return $fallback === null ? null : ($this->regenerateMissing([$key], $fallback->source)->all()[$key] ?? null);
    }

    /** @param TKey $key */
    public function __get(string $key): ?Asset
    {
        return $this->get($key);
    }

    /** @param TKey $key */
    public function __isset(string $key): bool
    {
        return isset($this->_assets[$key]);
    }

    /**
     * Generate and save requested keys that are absent from the manifest.
     *
     * @param  list<TKey>  $keys
     * @return self<TKey, TVariants>
     *
     * @throws FiloquentException
     */
    public function regenerateMissing(array $keys, ?string $source = null): self
    {
        throw_if($this->_context === null, FiloquentException::class, 'Regeneration requires Assets read from a Filoquent model attribute.');

        return $this->_context->regenerate($this, $keys, $source);
    }

    /**
     * @internal
     */
    public function withContext(AssetContext $context): self
    {
        return new self($this->_assets, $context);
    }

    /** @return array{manifest: array<string, array{filesystem: string, path: string, default: bool}>} */
    public function __serialize(): array
    {
        return ['manifest' => $this->toArray()];
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        $manifest = $data['manifest'] ?? null;
        $legacy = $data["\0".self::class."\0assets"] ?? null;
        throw_unless(is_array($manifest) || is_array($legacy), FiloquentException::class, 'Serialized assets require a valid manifest.');
        $this->_assets = is_array($manifest) ? self::fromArray($manifest)->all() : (new self($legacy))->all();
        $this->_context = null;
    }

    public function default(): ?Asset
    {
        foreach ($this->_assets as $asset) {
            if ($asset->default) {
                return $asset;
            }
        }

        return null;
    }

    /** @return array<string, Asset> */
    public function all(): array
    {
        return $this->_assets;
    }

    public function __toString(): string
    {
        return $this->default()?->url() ?? '';
    }

    /** @return array<string, array{filesystem: string, path: string, default: bool}> */
    public function toArray(): array
    {
        return array_map(static fn (Asset $asset): array => $asset->toArray(), $this->_assets);
    }

    /** @return array<string, array{filesystem: string, path: string, default: bool}> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
