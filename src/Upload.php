<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final readonly class Upload
{
    /** @param resource $snapshot */
    private function __construct(
        public string $path,
        public string $name,
        public string $extension,
        public string $mime,
        public int $size,
        private mixed $snapshot,
    ) {}

    private function __clone() {}

    public static function from(mixed $source): self
    {
        if ($source instanceof self) {
            return $source;
        }

        $owned = false;
        $name = 'upload';
        $position = false;

        throw_if($source instanceof UploadedFile && ! $source->isValid(), FiloquentException::class, 'The uploaded file did not complete successfully.');

        if ($source instanceof File || is_string($source)) {
            $path = $source instanceof File ? $source->getPathname() : $source;
            PendingAsset::fromPath($path);
            $name = $source instanceof UploadedFile ? $source->getClientOriginalName() : basename($path);
            $stream = @fopen($path, 'rb');
            $owned = true;
        } elseif (is_resource($source) && get_resource_type($source) === 'stream') {
            $stream = $source;
            $metadata = stream_get_meta_data($stream);

            if ($metadata['seekable']) {
                $position = ftell($stream);

                throw_if($position === false || ! rewind($stream), FiloquentException::class, 'Unable to rewind the input stream.');
            }
        } else {
            throw new FiloquentException('Uploads require an uploaded file, an absolute local path, or a readable stream.');
        }

        throw_unless(is_resource($stream), FiloquentException::class, 'Unable to open the upload source.');

        $target = tmpfile();

        try {
            throw_if(! is_resource($target) || @stream_copy_to_stream($stream, $target) === false || ! fflush($target), FiloquentException::class, 'Unable to snapshot the upload source.');

            $path = stream_get_meta_data($target)['uri'] ?? null;

            throw_unless(is_string($path), FiloquentException::class, 'The upload snapshot has no local path.');
            $pending = PendingAsset::fromPath($path, name: pathinfo($name, PATHINFO_FILENAME));
            $size = filesize($path);

            throw_if($size === false, FiloquentException::class, 'Unable to determine the upload size.');

            return new self($path, $pending->name, $pending->extension, (new File($path))->getMimeType() ?? 'application/octet-stream', $size, $target);
        } catch (Throwable $exception) {
            if (is_resource($target)) {
                fclose($target);
            }

            throw $exception instanceof FiloquentException ? $exception : new FiloquentException('Unable to snapshot the upload source.', previous: $exception);
        } finally {
            if ($owned) {
                fclose($stream);
            } elseif ($position !== false) {
                fseek($stream, $position);
            }
        }
    }

    public function asset(string $key = 'original', ?string $name = null, ?string $extension = null, bool $default = true): PendingAsset
    {
        return PendingAsset::fromPath($this->path, $key, $name ?? $this->name, $extension ?? $this->extension, $default);
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new FiloquentException('Save pending uploads before serializing or queueing the model.');
    }

    public function __destruct()
    {
        fclose($this->snapshot);
    }
}
