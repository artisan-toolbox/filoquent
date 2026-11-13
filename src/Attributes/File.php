<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Attributes;

use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class File
{
    public function __construct(
        public string $attribute,
        public FileNaming $naming = FileNaming::Uuid,
        public ?string $name = null,
        public ?string $middleware = null,
    ) {
        throw_unless(preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $attribute), FiloquentException::class, 'A file attribute requires a column name of at most 64 letters, digits, or underscores, starting with a letter or underscore.');
    }
}
