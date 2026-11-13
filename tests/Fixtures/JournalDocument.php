<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Fixtures;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/** @property-read Assets|null $attachment */
#[Unguarded]
class JournalDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}
