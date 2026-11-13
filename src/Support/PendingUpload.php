<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Upload;

/** @internal */
final readonly class PendingUpload
{
    public function __construct(
        public ?Upload $upload,
        public FileNaming $naming,
        public ?string $name,
        public ?string $middleware,
    ) {}
}
