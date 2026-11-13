<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Tests\Fixtures\InMemoryUploadJournal;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

#[Unguarded]
#[WithoutIncrementing]
class NativeUuidDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}

it('records the actual persisted spelling of a native primary key', function (bool $enabled): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => $enabled ? 2 : null]);
    $journal = new InMemoryUploadJournal;
    app()->instance(RedisUploadJournal::class, $journal);
    $intents = [];
    $journal->onRecord = static function (UploadIntent $intent) use (&$intents): void {
        $intents[] = $intent;
    };
    Artisan::call('migrate');
    Schema::create('native_uuid_documents', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->filoquent('attachment')->nullable();
    });
    $assigned = 'A44EFD5E-17CD-4CE6-AD4C-A2A41B2C3D4E';
    $expected = DB::getDriverName() === 'pgsql' ? strtolower($assigned) : $assigned;
    $document = NativeUuidDocument::query()->create(['id' => $assigned, 'attachment' => __FILE__]);
    $asset = $document->getAttribute('attachment')->default();

    expect(DB::table('native_uuid_documents')->value('id'))->toBe($expected)
        ->and($document->getRawOriginal('id'))->toBe($expected)
        ->and(DB::table('filoquent_uploads')->value('owner_key'))->toBe($expected)
        ->and(Artisan::call('filoquent:health', ['--silent' => true]))->toBe(0)
        ->and($asset->exists())->toBeTrue();

    if ($enabled) {
        expect($intents[0]->ownerKey)->toBeNull()
            ->and($intents[0]->matches(DB::table('filoquent_uploads')->first()))->toBeTrue();
    }

    $fresh = NativeUuidDocument::query()->whereKey($expected)->firstOrFail();
    $fresh->setAttribute('attachment', __FILE__)->save();
    $replacement = $fresh->getAttribute('attachment')->default();
    expect($asset->exists())->toBeFalse()->and($replacement->exists())->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('owner_key'))->toBe($expected);

    if ($enabled) {
        expect($intents[1]->ownerKey)->toBe($expected);
    }
    $fresh->delete();
    expect($replacement->exists())->toBeFalse()->and(DB::table('filoquent_uploads')->count())->toBe(0);
})->with([false, true]);
