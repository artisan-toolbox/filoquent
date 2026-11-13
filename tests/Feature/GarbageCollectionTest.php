<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Support\Reconciler;
use ArtisanToolbox\Filoquent\Tests\Fixtures\GcDocument;
use ArtisanToolbox\Filoquent\Tests\Support\DatabaseFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local']);
    Artisan::call('migrate');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
        $table->text('notes')->nullable();
        $table->softDeletes();
    });
});

it('retains damaged JSON that can hide an escaped upload reference', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $asset = $owner->getAttribute('attachment')->default();
    $escaped = str_replace(['/', '-'], ['\\/', '\\u002d'], $asset->path);
    DB::table('gc_documents')->update(['attachment' => null, 'notes' => '{"path":"'.$escaped.'"']);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1);
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and($asset->exists())->toBeTrue()
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull();
});

it('measures a full UTC grace period across workers in different local timezones', function (): void {
    $timezone = date_default_timezone_get();

    try {
        date_default_timezone_set('America/Sao_Paulo');
        $this->travelTo(CarbonImmutable::parse('2026-04-02T13:00:00-03:00'));
        $owner = GcDocument::query()->create(['attachment' => __FILE__]);
        $asset = $owner->getAttribute('attachment')->default();
        DB::table('gc_documents')->delete();
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and(CarbonImmutable::parse(DB::table('filoquent_uploads')->value('orphaned_at'), 'UTC')->format('Y-m-d H:i:s.u'))->toBe('2026-04-02 16:00:00.000000');

        date_default_timezone_set('UTC');
        $this->travelTo(CarbonImmutable::parse('2026-04-03T14:00:00Z'));
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and($asset->exists())->toBeTrue();
        $this->travel(2)->hours();
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and($asset->exists())->toBeFalse();
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('rotates later uploads while the application uses a timezone behind UTC', function (): void {
    $timezone = date_default_timezone_get();

    try {
        date_default_timezone_set('America/Sao_Paulo');
        $this->travelTo(CarbonImmutable::parse('2026-04-02T13:00:00-03:00'));
        GcDocument::query()->create(['attachment' => __FILE__]);
        $this->travel(1)->second();
        $second = GcDocument::query()->create(['attachment' => __FILE__]);
        $before = DB::table('filoquent_uploads')->where('owner_key', (string) $second->getKey())->value('checked_at');
        $this->travel(1)->second();
        for ($run = 0; $run < 4; $run++) {
            expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => 1]))->toBe(0);
        }
        expect(DB::table('filoquent_uploads')->where('owner_key', (string) $second->getKey())->value('checked_at'))->not->toBe($before);
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('uses writer schema even when a reader has not received the new table', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $connection = DB::connection();
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    $connection->forgetRecordModificationState();

    try {
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('live')
            ->and($owner->getAttribute('attachment')->default()->exists())->toBeTrue();
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('previews raw row deletion without changing receipts or storage', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    DB::table('gc_documents')->where('id', $owner->getKey())->delete();
    $before = DB::table('filoquent_uploads')->first();

    expect(Artisan::call('filoquent:gc'))->toBe(0)
        ->and(Artisan::output())->toContain('absent')
        ->and(DB::table('filoquent_uploads')->first())->toEqual($before);
    Storage::disk('local')->assertExists($path);
});

it('requires two confirmed absences at least 24 hours apart before removing raw-deleted uploads', function (): void {
    $this->travelTo(now()->startOfSecond());
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    $neighbor = dirname($path).'/unmanaged.txt';
    Storage::disk('local')->put($neighbor, 'keep');
    DB::table('gc_documents')->where('id', $owner->getKey())->delete();

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->not->toBeNull();
    Storage::disk('local')->assertExists($path);
    $this->travel(23)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    Storage::disk('local')->assertExists($path);
    $this->travel(1)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);
    Storage::disk('local')->assertMissing($path)->assertExists($neighbor);
});

it('reconciles a dropped file column while preserving files until the grace period completes', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    Schema::table('gc_documents', fn (Blueprint $table) => $table->dropColumn('attachment'));

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    Storage::disk('local')->assertExists($path);
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('retains exact manifests after model and field renames without rewriting provenance', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    DB::table('filoquent_uploads')->update(['owner_type' => 'Removed\\OriginalModel', 'attribute' => 'old_attachment']);
    Schema::table('gc_documents', fn (Blueprint $table) => $table->renameColumn('attachment', 'renamed_attachment'));
    DB::table('filoquent_uploads')->update(['orphaned_at' => now()->subDays(2)->format('Y-m-d H:i:s.u')]);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('owner_type'))->toBe('Removed\\OriginalModel')
        ->and(DB::table('filoquent_uploads')->value('attribute'))->toBe('old_attachment')
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull();
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    Storage::disk('local')->assertExists($path);
});

it('includes soft-deleted owners and resets the full grace period when a reference returns', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $manifest = $owner->getRawOriginal('attachment');
    $path = $owner->getAttribute('attachment')->default()->path;
    $owner->delete();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull();
    DB::table('gc_documents')->where('id', $owner->getKey())->update(['attachment' => null]);
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $this->travel(25)->hours();
    DB::table('gc_documents')->where('id', $owner->getKey())->update(['attachment' => $manifest]);
    Artisan::call('filoquent:gc', ['--apply' => true]);
    expect(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull();
    DB::table('gc_documents')->where('id', $owner->getKey())->update(['attachment' => null]);
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $this->travel(23)->hours();
    Artisan::call('filoquent:gc', ['--apply' => true]);
    Storage::disk('local')->assertExists($path);
});

it('retains partial and malformed references and clears an earlier absence mark', function (string $kind): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    $value = match ($kind) {
        'partial' => json_encode(['path' => $path], JSON_THROW_ON_ERROR),
        'escaped' => '{"path":"'.str_replace('a', '\\u0061', $path).'"}',
        default => '{broken:'.$path,
    };
    DB::table('gc_documents')->where('id', $owner->getKey())->update(['attachment' => null, 'notes' => $value]);
    DB::table('filoquent_uploads')->update(['orphaned_at' => now()->subDays(2)->format('Y-m-d H:i:s.u')]);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('unresolved')
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull();
    Storage::disk('local')->assertExists($path);
})->with(['partial', 'malformed', 'escaped']);

it('fails closed for missing tables keys and keys without a single-column primary index', function (string $kind): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;

    if ($kind === 'table') {
        Schema::drop('gc_documents');
    } elseif ($kind === 'key') {
        DB::table('filoquent_uploads')->update(['owner_key_name' => 'missing_key']);
    } else {
        if ($kind === 'partial_unique') {
            if (DB::getDriverName() === 'mysql') {
                Schema::table('gc_documents', fn (Blueprint $table) => $table->string('indexed_notes')->nullable()->unique());
            } else {
                DB::statement('CREATE UNIQUE INDEX gc_partial_notes ON gc_documents (notes) WHERE notes IS NOT NULL');
            }
        }
        DB::table('filoquent_uploads')->update(['owner_key_name' => $kind === 'partial_unique' && DB::getDriverName() === 'mysql' ? 'indexed_notes' : 'notes', 'owner_key' => 'missing']);
    }
    DB::table('filoquent_uploads')->update(['orphaned_at' => now()->subDays(2)->format('Y-m-d H:i:s.u')]);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull()
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active');
    Storage::disk('local')->assertExists($path);
})->with(['table', 'key', 'index', 'partial_unique']);

it('retains invalid receipt manifests without hydrating owners or authorizing cleanup', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    DB::table('gc_documents')->where('id', $owner->getKey())->delete();
    DB::table('filoquent_uploads')->update(['manifest' => '{}', 'orphaned_at' => now()->subDays(2)->format('Y-m-d H:i:s.u')]);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('active')
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull();
    Storage::disk('local')->assertExists($path);
});

it('does not reset observations when another worker already reconciled the selected receipt', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    DB::table('gc_documents')->where('id', $owner->getKey())->delete();
    /** @var object{id: string, owner_type: string, owner_table: string, owner_key_name: string, owner_key: string, attribute: string, manifest: string, state: string, checked_at: string, orphaned_at: string|null} $receipt */
    $receipt = DB::table('filoquent_uploads')->first();
    $first = new Reconciler(DB::connection());
    $second = new Reconciler(DB::connection());

    expect($first->reconcile($receipt, true)['status'])->toBe('absent');
    $observation = DB::table('filoquent_uploads')->first();
    expect($second->reconcile($receipt, true)['status'])->toBe('skipped')
        ->and(DB::table('filoquent_uploads')->first())->toEqual($observation);
});

it('looks up string primary keys and ignores unrelated JSON values', function (): void {
    Schema::drop('gc_documents');
    Schema::create('gc_documents', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->filoquent('attachment')->nullable();
        $table->text('notes')->nullable();
        $table->softDeletes();
    });
    $owner = new GcDocument;
    $owner->setKeyType('string');
    $owner->setIncrementing(false);
    $owner->setAttribute('id', 'document-42');
    $owner->setAttribute('attachment', __FILE__);
    $owner->save();
    $path = $owner->getAttribute('attachment')->default()->path;
    DB::table('gc_documents')->where('id', 'document-42')->update(['attachment' => null, 'notes' => '{"topic":"unrelated"}']);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('rotates bounded live and unresolved receipts even with a frozen clock', function (): void {
    $this->travelTo(now()->startOfSecond());
    $first = GcDocument::query()->create(['attachment' => __FILE__]);
    $second = GcDocument::query()->create(['attachment' => __FILE__]);
    DB::table('filoquent_uploads')->where('owner_key', (string) $first->getKey())->update(['owner_table' => DatabaseFixtures::ownerRoute('missing_gc_table')]);

    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(1)
        ->and(Artisan::output())->toContain('Examined 1');
    expect(Artisan::call('filoquent:gc', ['--apply' => true, '--limit' => '1']))->toBe(1)
        ->and(Artisan::output())->toContain('live 1');
    expect(DB::table('filoquent_uploads')->where('owner_key', (string) $second->getKey())->value('checked_at'))->toBe(now()->addMicrosecond()->format('Y-m-d H:i:s.u'));
});

it('retains a failed cleanup receipt for an exact prune retry', function (): void {
    $owner = GcDocument::query()->create(['attachment' => __FILE__]);
    $path = $owner->getAttribute('attachment')->default()->path;
    DB::table('gc_documents')->where('id', $owner->getKey())->delete();
    Artisan::call('filoquent:gc', ['--apply' => true]);
    $this->travel(25)->hours();
    $disk = Storage::disk('local');
    $refusing = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        /** @param string|array<int, string> $paths */
        public function delete($paths): bool
        {
            return false;
        }
    };
    Storage::set('local', $refusing);
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('retired');
    $disk->assertExists($path);
    Storage::set('local', $disk);
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0);
    $disk->assertMissing($path);
});

it('rejects invalid batch sizes and an outer transaction', function (): void {
    foreach (['0', '10001', 'invalid'] as $limit) {
        expect(Artisan::call('filoquent:gc', ['--limit' => $limit]))->toBe(2);
    }
    DB::beginTransaction();

    try {
        expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(1);
    } finally {
        DB::rollBack();
    }
});
