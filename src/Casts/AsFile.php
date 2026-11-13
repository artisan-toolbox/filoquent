<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Casts;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\PendingUpload;
use ArtisanToolbox\Filoquent\Upload;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/** @implements CastsAttributes<Assets, mixed> */
final class AsFile implements CastsAttributes, SerializesCastableAttributes
{
    public bool $withoutObjectCaching = true;

    private readonly FileNaming $naming;

    public function __construct(
        string $naming = 'uuid',
        private readonly string $name = '',
        private readonly string $middleware = '',
    ) {
        $this->naming = FileNaming::tryFrom(rawurldecode($naming))
            ?? throw new FiloquentException('The serialized file naming policy is not supported.');
    }

    public static function using(FileNaming $naming = FileNaming::Uuid, ?string $name = null, ?string $middleware = null): string
    {
        return self::class.':'.implode(',', array_map(rawurlencode(...), [$naming->value, $name ?? '', $middleware ?? '']));
    }

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Assets
    {
        return self::decode($value instanceof PendingUpload ? $model->getRawOriginal($key) : $value);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): PendingUpload
    {
        throw_if(! in_array(HasFiloquent::class, class_uses_recursive($model), true) || ! method_exists($model, 'assertFiloquentIdle'), FiloquentException::class, 'Models using AsFile must use HasFiloquent before assigning a file.');

        $model->assertFiloquentIdle();

        return new PendingUpload(
            $value === null ? null : Upload::from($value),
            $this->naming,
            $this->name === '' ? null : rawurldecode($this->name),
            $this->middleware === '' ? null : rawurldecode($this->middleware),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{filesystem: string, path: string, default: bool}>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return $value->toArray();
    }

    /** @internal */
    public static function matches(?Assets $left, ?Assets $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        $a = $left->toArray();
        $b = $right->toArray();
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /** @internal */
    public static function decode(mixed $raw): ?Assets
    {
        if ($raw === null) {
            return null;
        }

        try {
            $manifest = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new FiloquentException('Invalid Filoquent JSON manifest.', previous: $exception);
        }

        throw_if(! is_array($manifest) || $manifest === [], FiloquentException::class, 'A stored Filoquent manifest must be a nonempty object.');

        return Assets::fromArray($manifest);
    }
}
