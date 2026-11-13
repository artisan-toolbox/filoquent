<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Fixtures;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property Assets|null $attachment
 * @property Assets|null $other_attachment
 */
#[Unguarded]
#[Table(name: 'path_documents')]
class PathDocument extends Model
{
    use HasFiloquent;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class, 'other_attachment' => AsFile::class];
    }
}
