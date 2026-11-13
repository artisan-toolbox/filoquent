<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** @property-read Assets|null $attachment */
class ScaleDocument extends Model
{
    use HasFiloquent;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}

it('keeps file operations bounded and cleanup indexed at large row counts', function (int $population): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('scale_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });

    DatabaseFixtures::seedOwners($population, 'scale_documents');
    DatabaseFixtures::seedReceipts($population, 'scale_documents', ScaleDocument::class);
    expect(DB::table('scale_documents')->count())->toBe($population)
        ->and(DB::table('filoquent_uploads')->count())->toBe($population);

    $connection = DB::connection();
    $connection->enableQueryLog();
    $document = new ScaleDocument;
    $document->setAttribute('attachment', __FILE__);
    $document->save();
    expect(count($connection->getQueryLog()))->toBeLessThanOrEqual(12);

    $connection->flushQueryLog();
    $first = $document->attachment?->default();
    expect($first)->not->toBeNull()
        ->and($first?->exists())->toBeTrue()
        ->and($document->attachment?->toArray())->not->toBeEmpty()
        ->and($connection->getQueryLog())->toBeEmpty();

    $document->setAttribute('attachment', __FILE__);
    $document->save();
    expect(count($connection->getQueryLog()))->toBeLessThanOrEqual(16)
        ->and($first?->exists())->toBeFalse();

    $connection->flushQueryLog();
    $second = $document->attachment?->default();
    $document->delete();
    expect(count($connection->getQueryLog()))->toBeLessThanOrEqual(16)
        ->and($second?->exists())->toBeFalse();

    $connection->flushQueryLog();
    Artisan::call('filoquent:prune', ['--apply' => true, '--limit' => 1]);
    expect(count($connection->getQueryLog()))->toBeLessThanOrEqual(4);
    $connection->disableQueryLog();

    // Native planners may correctly prefer a sequential scan for the 100-row fixture.
    if ($population >= 1_000_000 || DB::getDriverName() === 'sqlite') {
        DatabaseFixtures::assertIndexed('SELECT id FROM filoquent_uploads WHERE state = ? AND id > ? ORDER BY id LIMIT 100', ['retired', '00000000-0000-0000-0000-000000000000']);
        DatabaseFixtures::assertIndexed('SELECT id FROM filoquent_uploads WHERE state = ? ORDER BY checked_at, id LIMIT 100', ['active']);
    }
})->with([100, 1_000_000]);
