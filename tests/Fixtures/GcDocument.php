<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Fixtures;

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Unguarded]
#[Table(name: 'gc_documents')]
class GcDocument extends Model
{
    use HasFiloquent;
    use SoftDeletes;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}
