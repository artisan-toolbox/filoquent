<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Attributes\File;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/** @internal */
final class FileAttributes
{
    /** @var array<class-string<Model>, array<string, string>> */
    private static array $casts = [];

    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    public static function casts(string $model): array
    {
        if (isset(self::$casts[$model])) {
            return self::$casts[$model];
        }

        $classes = [];
        $class = new ReflectionClass($model);
        do {
            $classes[] = $class;
            $class = $class->getParentClass();
        } while ($class !== false);

        $casts = [];
        foreach (array_reverse($classes) as $class) {
            $declared = [];
            foreach ($class->getAttributes(File::class) as $attribute) {
                $file = $attribute->newInstance();

                throw_if(isset($declared[$file->attribute]), FiloquentException::class, "The file attribute {$file->attribute} is declared more than once on {$class->getName()}.");

                $declared[$file->attribute] = AsFile::using($file->naming, $file->name, $file->middleware);
            }
            $casts = array_replace($casts, $declared);
        }

        return self::$casts[$model] = $casts;
    }
}
