<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Attributes;

use Attribute;

/** Enable persisted missing-key fallback on a middleware class. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RegenerateMissing
{
    public function __construct(public ?string $source = null) {}
}
