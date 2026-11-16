<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Concerns;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\PendingAsset;
use ArtisanToolbox\Filoquent\Support\FileAttributes;
use ArtisanToolbox\Filoquent\Support\OperationCancelled;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use ArtisanToolbox\Filoquent\Support\PendingUpload;
use ArtisanToolbox\Filoquent\Support\Persistence;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

/** @phpstan-require-extends Model */
trait HasFiloquent
{
    private ?Persistence $filoquentOperation = null;

    protected function initializeHasFiloquent(): void
    {
        $this->mergeCasts(array_diff_key(FileAttributes::casts(static::class), $this->getCasts()));
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        return $this->runFiloquentOperation(function (Persistence $operation) use ($options): bool {
            $saved = parent::save($options);

            if ($saved) {
                $operation->finish($this);
            }

            return $saved;
        }) === true;
    }

    /** @param array<string, mixed> $options
     * @param  array<int, string>|string|null  $uniqueBy
     */
    public function saveOrIgnore(array $options = [], array|string|null $uniqueBy = null): bool
    {
        $this->assertFiloquentIdle();
        foreach ($this->getAttributes() as $value) {
            throw_if($value instanceof PendingUpload, FiloquentException::class, 'Use save() to persist a pending Filoquent upload.');
        }

        return $this->runFiloquentOperation(fn (): bool => parent::saveOrIgnore($options, $uniqueBy)) === true;
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        $this->assertFiloquentIdle();

        try {
            return $this->runFiloquentOperation($this->deleteFiloquentModel(...));
        } catch (Throwable $exception) {
            if (property_exists($this, 'forceDeleting') && in_array(SoftDeletes::class, class_uses_recursive($this), true)) {
                $this->forceDeleting = false;
            }

            throw $exception;
        }
    }

    private function deleteFiloquentModel(Persistence $operation): ?bool
    {
        $operation->deletingColumns = Persistence::columns($this);
        $query = Persistence::row($this);
        $deleted = parent::delete();

        if ($deleted && ! $this->exists && $operation->deletingRow !== null) {
            throw_if($query->exists(), FiloquentException::class, 'The model still exists after a permanent delete.');
            foreach ($operation->deletingColumns as $column) {
                $operation->retire($this, $operation->deletingRow->{$column});
            }
        }

        return $deleted;
    }

    protected function getKeyForSaveQuery(): mixed
    {
        $operation = $this->filoquentOperation;

        if ($operation !== null && $operation->deletingColumns !== [] && $this->exists) {
            // A deleting listener may have replaced a file through another model instance.
            $operation->deletingRow = Persistence::row($this)->lockForUpdate()->first($operation->deletingColumns);
        }

        return parent::getKeyForSaveQuery();
    }

    /** @param list<string>|null $except */
    public function replicate(?array $except = null): static
    {
        return parent::replicate(array_merge($except ?? [], Persistence::columns($this)));
    }

    /** @internal */
    public function assertFiloquentIdle(): void
    {
        throw_if($this->filoquentOperation !== null, FiloquentException::class, 'Assign files before saving. Recursive file operations on one model are unsupported.');
    }

    /**
     * @internal
     *
     * @template TKey of string
     * @template TVariants of object
     *
     * @param  Assets<TKey, TVariants>  $snapshot
     * @param  list<TKey>  $keys
     * @return Assets<TKey, TVariants>
     */
    public function regenerateFiloquentVariants(string $attribute, Assets $snapshot, array $keys, ?string $middleware, ?string $source): Assets
    {
        $this->assertFiloquentIdle();
        throw_if(! $this->exists || $this->isDirty(), FiloquentException::class, 'Save or discard model changes before regenerating missing variants.');
        PendingAsset::validateKeys($keys);

        $attributes = $this->getAttributes();
        $current = AsFile::decode($attributes[$attribute] ?? null);
        throw_if($current === null, FiloquentException::class, 'Reload a stale file attribute before regenerating variants.');
        foreach ($snapshot->toArray() as $key => $entry) {
            throw_if(($current->toArray()[$key] ?? null) !== $entry, FiloquentException::class, 'Reload a stale file attribute before regenerating variants.');
        }

        $missing = array_values(array_diff(array_unique($keys), array_keys($current->all())));

        if ($missing === []) {
            return $this->getAttribute($attribute);
        }
        throw_if($middleware === null, FiloquentException::class, 'Missing variants require upload middleware on the file attribute.');

        $this->attributes[$attribute] = new PendingUpload(null, FileNaming::Uuid, null, $middleware, $current, $missing, $source);

        try {
            throw_unless($this->save(), FiloquentException::class, 'The model refused to save regenerated variants.');
        } catch (Throwable $exception) {
            if (($this->getAttributes()[$attribute] ?? null) instanceof PendingUpload) {
                $this->setRawAttributes($attributes);
            }

            throw $exception;
        }

        return $this->getAttribute($attribute);
    }

    /** @return array<string, mixed> */
    protected function getAttributesForInsert(): array
    {
        $this->prepareFiloquentAttributes();

        return parent::getAttributesForInsert();
    }

    /** @return array<string, mixed> */
    protected function getDirtyForUpdate(): array
    {
        $this->prepareFiloquentAttributes();

        return parent::getDirtyForUpdate();
    }

    private function prepareFiloquentAttributes(): void
    {
        $pending = [];
        foreach (Persistence::columns($this) as $key) {
            $value = $this->attributes[$key] ?? null;

            if ($value instanceof PendingUpload) {
                $pending[$key] = $value;
            } elseif ($value !== ($this->exists ? $this->getRawOriginal($key) : null)) {
                throw new FiloquentException("Assign an upload or null to {$key} instead of reusing stored metadata.");
            }
        }

        throw_if($this->exists && $this->isDirty($this->getKeyName()), FiloquentException::class, 'A model with managed file attributes must keep its original primary key.');

        if ($pending === []) {
            return;
        }

        throw_if($this->filoquentOperation === null, FiloquentException::class, 'Use save() to persist a pending Filoquent upload.');

        $this->attributes = array_replace($this->attributes, $this->filoquentOperation->prepare($this, $pending));
    }

    /**
     * @param  Closure(Persistence): (bool|null)  $action
     */
    private function runFiloquentOperation(Closure $action): ?bool
    {
        $this->assertFiloquentIdle();
        $snapshot = [$this->getAttributes(), $this->original, $this->changes, $this->previous, $this->exists, $this->wasRecentlyCreated];
        $connection = $this->getConnection();
        $this->filoquentOperation = $operation = new Persistence;
        $restore = true;

        $persist = function () use ($action, $operation, $connection, &$restore): ?bool {
            (new OwnerTables($connection))->guard($this->getTable());
            $result = $action($operation);
            throw_if($result === false, OperationCancelled::class);
            $restore = false;

            return $result;
        };

        try {
            $result = $connection->transactionLevel() > 0
                ? $connection->transaction($persist)
                : $this->runFiloquentRootTransaction($connection, $persist, $restore);
        } catch (Throwable $exception) {
            if ($restore) {
                [$attributes, $this->original, $this->changes, $this->previous, $this->exists, $this->wasRecentlyCreated] = $snapshot;
                $this->setRawAttributes($attributes);
            }

            if ($restore && $exception instanceof OperationCancelled) {
                return false;
            }

            throw $exception;
        } finally {
            $this->filoquentOperation = null;
        }

        if ($result && $connection->transactionLevel() === 0) {
            foreach ($operation->retired as $id) {
                UploadLedger::drain($connection, $id);
            }
        }

        return $result;
    }

    /** @param Closure(): (bool|null) $persist */
    private function runFiloquentRootTransaction(Connection $connection, Closure $persist, bool &$restore): ?bool
    {
        /** @var DatabaseTransactionsManager $transactions */
        $transactions = resolve('db.transactions');
        $connection->beginTransaction();
        $record = $transactions->getPendingTransactions()->last(
            static fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName() && $record->level === 1,
        );

        try {
            throw_if($record === null, FiloquentException::class, 'The connection must use the application database transaction manager.');
            $result = $persist();
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            // Laravel removes this record before dispatching any postcommit callbacks.
            if ($record === null || $transactions->getPendingTransactions()->containsStrict($record)) {
                $restore = true;

                try {
                    $connection->rollBack(0);
                } catch (Throwable $rollbackException) {
                    rescue(static fn () => report($rollbackException), report: false);
                }
            }

            throw $exception;
        }
    }
}
