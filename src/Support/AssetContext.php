<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Attributes\RegenerateMissing;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Middleware;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/** @internal */
final readonly class AssetContext
{
    public function __construct(private Model $model, private string $attribute) {}

    public function fallback(): ?RegenerateMissing
    {
        $middleware = AsFile::middlewareFor($this->model, $this->attribute);

        if ($middleware === null || ! is_subclass_of($middleware, Middleware::class)) {
            return null;
        }

        $attributes = (new ReflectionClass($middleware))->getAttributes(RegenerateMissing::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @template TKey of string
     * @template TVariants of object
     *
     * @param  Assets<TKey, TVariants>  $assets
     * @param  list<TKey>  $keys
     * @return Assets<TKey, TVariants>
     */
    public function regenerate(Assets $assets, array $keys, ?string $source): Assets
    {
        throw_unless(method_exists($this->model, 'regenerateFiloquentVariants'), FiloquentException::class, 'Regeneration requires a model using HasFiloquent.');

        return $this->model->regenerateFiloquentVariants($this->attribute, $assets, $keys, AsFile::middlewareFor($this->model, $this->attribute), $source);
    }
}
