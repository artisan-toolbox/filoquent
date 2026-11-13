<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\OperationCancelled;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\InMemoryUploadJournal;
use ArtisanToolbox\Filoquent\Tests\Fixtures\JournalDocument;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

#[Table(name: 'journal_documents')]
class PresentedKeyDocument extends JournalDocument
{
    /** @return Attribute<non-falsy-string|null, never> */
    protected function id(): Attribute
    {
        return Attribute::get(static fn (mixed $id): ?string => $id === null ? null : "document-{$id}");
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filoquent.filesystem' => 'local', 'filoquent.redis.database' => null]);
    Artisan::call('migrate');
    Schema::create('journal_documents', function (Blueprint $table): void {
        $table->id();
        $table->filoquent('attachment')->nullable();
    });
});

it('keeps physical ownership when a primary key accessor changes its presentation', function (bool $journal, ?int $assigned): void {
    config(['filoquent.redis.database' => $journal ? 2 : null]);
    app()->instance(RedisUploadJournal::class, new InMemoryUploadJournal);
    $document = new PresentedKeyDocument(['attachment' => __FILE__]);

    if ($assigned !== null) {
        $document->setAttribute('id', $assigned);
    }
    $document->save();
    $key = $assigned ?? 1;
    $asset = $document->attachment?->default();

    expect($document->getKey())->toBe("document-{$key}")
        ->and(DB::table('filoquent_uploads')->value('owner_key'))->toBe((string) $key);

    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0);
    $this->travel(25)->hours();
    expect(Artisan::call('filoquent:gc', ['--apply' => true]))->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('orphaned_at'))->toBeNull()
        ->and($asset?->exists())->toBeTrue();

    $document->setAttribute('attachment', __FILE__);
    $document->save();
    $replacement = $document->attachment?->default();
    expect($asset?->exists())->toBeFalse()->and($replacement?->exists())->toBeTrue();
    $document->delete();
    expect($replacement?->exists())->toBeFalse()->and(DB::table('filoquent_uploads')->count())->toBe(0);
})->with([false, true])->with([null, 41]);

it('preserves committed model state when a postcommit callback fails', function (string $hook, bool $openTransaction): void {
    $callback = static function () use ($openTransaction): never {
        if ($openTransaction) {
            DB::beginTransaction();
            DB::table('journal_documents')->insert(['id' => 42]);
        }

        throw new RuntimeException('Committed notification failed.');
    };

    if ($hook === 'event') {
        Event::listen(TransactionCommitted::class, $callback);
    } else {
        JournalDocument::saved(static function () use ($hook, $callback): void {
            if ($hook === 'nested') {
                DB::transaction(static fn () => DB::afterCommit($callback));
            } else {
                DB::afterCommit($callback);
            }
        });
    }

    $document = new JournalDocument(['attachment' => __FILE__]);
    expect(fn (): bool => $document->save())->toThrow(RuntimeException::class, 'Committed notification failed.');

    if ($openTransaction) {
        expect(DB::transactionLevel())->toBe(1)
            ->and(DB::table('journal_documents')->where('id', 42)->exists())->toBeTrue();
        DB::rollBack();
    }

    $asset = $document->attachment?->default();
    expect($document->exists)->toBeTrue()
        ->and($document->getRawOriginal('id'))->toBe(1)
        ->and($document->isDirty())->toBeFalse()
        ->and($asset?->exists())->toBeTrue()
        ->and(DB::table('journal_documents')->count())->toBe(1)
        ->and(DB::table('filoquent_uploads')->where('state', 'active')->count())->toBe(1);

    Event::forget(TransactionCommitted::class);
    expect($document->saveQuietly())->toBeTrue()
        ->and(DB::table('journal_documents')->count())->toBe(1)
        ->and(DB::table('filoquent_uploads')->count())->toBe(1)
        ->and($document->attachment?->default()?->path)->toBe($asset?->path);
})->with(['root', 'nested', 'event'])->with([false, true]);

it('propagates a postcommit cancellation exception after keeping committed state', function (): void {
    JournalDocument::saved(static fn () => DB::afterCommit(static fn () => throw new OperationCancelled));
    $document = new JournalDocument(['attachment' => __FILE__]);

    expect(fn (): bool => $document->save())->toThrow(OperationCancelled::class)
        ->and($document->exists)->toBeTrue()
        ->and(DB::table('journal_documents')->count())->toBe(1);
});

it('restores pending model state and releases the connection after a failed commit', function (string $failure): void {
    // MySQL validates foreign keys before commit, so its commit failure uses Laravel's committing hook.
    $deferred = $failure === 'constraint' && DB::getDriverName() !== 'mysql';

    if ($deferred) {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        DB::statement('CREATE TABLE lifecycle_parents (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE lifecycle_children (parent_id INTEGER REFERENCES lifecycle_parents(id) DEFERRABLE INITIALLY DEFERRED)');
        JournalDocument::saved(static fn () => DB::table('lifecycle_children')->insert(['parent_id' => 42]));
    } else {
        Event::listen(TransactionCommitting::class, static fn () => throw new RuntimeException('Commit vetoed.'));
    }
    $document = new JournalDocument(['attachment' => __FILE__]);

    expect(fn (): bool => $document->save())->toThrow($deferred ? PDOException::class : RuntimeException::class)
        ->and($document->exists)->toBeFalse()
        ->and($document->getRawOriginal('id'))->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('journal_documents')->count())->toBe(0)
        ->and(DB::table('filoquent_uploads')->count())->toBe(0);

    Event::forget(TransactionCommitting::class);
    expect($document->saveQuietly())->toBeTrue()
        ->and($document->attachment?->default()?->exists())->toBeTrue()
        ->and(DB::table('journal_documents')->count())->toBe(1)
        ->and(DB::table('filoquent_uploads')->where('state', 'active')->count())->toBe(1);
})->with(['constraint', 'listener']);

it('preserves the original exception when an application rollback callback fails', function (): void {
    JournalDocument::saved(static function (): void {
        DB::afterRollBack(static fn () => throw new RuntimeException('Rollback notification failed.'));

        throw new RuntimeException('Observer failed.');
    });
    $document = new JournalDocument(['attachment' => __FILE__]);

    expect(fn (): bool => $document->save())->toThrow(RuntimeException::class, 'Observer failed.')
        ->and($document->exists)->toBeFalse()
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('journal_documents')->count())->toBe(0);
});

it('releases the transaction before writing when its manager does not track the connection', function (): void {
    DB::connection()->setTransactionManager(new DatabaseTransactionsManager);
    $document = new JournalDocument(['attachment' => __FILE__]);

    expect(fn (): bool => $document->save())->toThrow(FiloquentException::class, 'The connection must use the application database transaction manager.')
        ->and($document->exists)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(DB::table('journal_documents')->count())->toBe(0);
});

it('keeps a permanent deletion committed when its postcommit callback fails', function (): void {
    $document = JournalDocument::query()->create(['attachment' => __FILE__]);
    $asset = $document->attachment?->default();
    JournalDocument::deleted(static fn () => DB::afterCommit(static fn () => throw new RuntimeException('Committed delete notification failed.')));

    expect(fn (): ?bool => $document->delete())->toThrow(RuntimeException::class)
        ->and($document->exists)->toBeFalse()
        ->and(DB::table('journal_documents')->count())->toBe(0)
        ->and(DB::table('filoquent_uploads')->value('state'))->toBe('retired')
        ->and($asset?->exists())->toBeTrue();
    expect(Artisan::call('filoquent:prune', ['--apply' => true]))->toBe(0)
        ->and($asset?->exists())->toBeFalse();
});
