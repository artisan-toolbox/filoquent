<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\File;

final readonly class PendingAsset
{
    private function __construct(
        public string $path,
        public string $key,
        public string $name,
        public string $extension,
        public bool $default,
    ) {}

    public static function fromPath(string $path, string $key = 'original', ?string $name = null, ?string $extension = null, bool $default = true): self
    {
        throw_if(str_contains($path, "\0") || str_contains($path, '://')
            || ! preg_match('~^(?:/|[a-zA-Z]:[\\\\/])~', $path) || ! is_file($path) || ! is_readable($path), FiloquentException::class, 'A pending asset requires an absolute readable local file path.');

        throw_unless(preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $key), FiloquentException::class, 'A variant key must start with a letter and contain only letters, digits, hyphens, or underscores.');

        $name ??= pathinfo($path, PATHINFO_FILENAME);

        throw_if($name === '' || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $name) || $name === '.' || $name === '..', FiloquentException::class, 'An asset name must be a filename without directory separators.');

        $name = Str::slug($name);
        $extension ??= (new File($path))->guessExtension() ?? 'bin';

        throw_if($name === '' || ! preg_match('/^[a-zA-Z0-9]+$/D', $extension), FiloquentException::class, 'An asset requires a usable name and an alphanumeric extension.');

        Asset::validatePath($name.'.'.$extension);

        return new self($path, $key, $name, strtolower($extension), $default);
    }
}
