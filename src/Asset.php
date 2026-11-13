<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\StorageAccess;
use DateTimeInterface;
use Stringable;

final readonly class Asset implements Stringable
{
    public function __construct(
        public string $filesystem,
        public string $path,
        public bool $default = true,
    ) {
        throw_if($filesystem === '' || trim($filesystem) !== $filesystem || preg_match('/[\x00-\x20\x7f]/', $filesystem), FiloquentException::class, 'An asset filesystem must be a nonempty disk name.');

        self::validatePath($path);
    }

    /** @internal */
    public static function validatePath(string $path): void
    {
        throw_if($path === '' || preg_match('/[\\\\:\x00-\x1f\x7f]/', $path) || array_intersect(explode('/', $path), ['', '.', '..']) !== []
            || preg_match('~(?:^|/)[^/]*[. ](?:/|$)~', $path)
            || preg_match('~(?:^|/)(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.[^/]*)?(?:/|$)~i', $path), FiloquentException::class, 'An asset path must be a safe relative filesystem path.');
    }

    public function url(): string
    {
        return (new StorageAccess)->forReading($this->filesystem)->url($this->path);
    }

    /** @param array<string, mixed> $options */
    public function temporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        return (new StorageAccess)->forReading($this->filesystem)->temporaryUrl($this->path, $expiration, $options);
    }

    /** @return resource */
    public function readStream(): mixed
    {
        $stream = (new StorageAccess)->forReading($this->filesystem)->readStream($this->path);

        throw_unless(is_resource($stream), FiloquentException::class, 'Unable to read the asset stream.');

        return $stream;
    }

    public function exists(): bool
    {
        return (new StorageAccess)->forReading($this->filesystem)->fileExists($this->path);
    }

    public function __toString(): string
    {
        return $this->url();
    }

    /** @return array{filesystem: string, path: string, default: bool} */
    public function toArray(): array
    {
        return ['filesystem' => $this->filesystem, 'path' => $this->path, 'default' => $this->default];
    }
}
