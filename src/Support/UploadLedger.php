<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Enums\UploadState;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/** @internal */
final class UploadLedger
{
    public static function scope(Connection $connection): string
    {
        $scope = $connection->table('filoquent_upload_namespace')->useWritePdo()->where('id', 1)->value('namespace');

        throw_if(! is_string($scope) || ! Str::isUuid($scope), FiloquentException::class, 'Run the Filoquent journal migration before enabling Redis recovery.');

        return $scope;
    }

    public static function reserve(Model $model, UploadIntent $intent): void
    {
        $connection = $model->getConnection();

        throw_if($connection->transactionLevel() === 0 || self::scope($connection) !== $intent->scope, FiloquentException::class, 'Upload reservations require the owning model transaction and ledger.');

        $connection->table('filoquent_uploads')->insert([
            ...$intent->receipt(),
            'state' => UploadState::Writing->value,
            'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public static function confirm(Model $model, UploadIntent $intent): void
    {
        $connection = $model->getConnection();
        $query = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $intent->id)->where('state', UploadState::Writing->value);
        $receipt = $query->lockForUpdate()->first();

        throw_if($connection->transactionLevel() === 0 || $receipt === null || ! $intent->matches($receipt)
            || Persistence::ownerKey($model) === null, FiloquentException::class, 'The upload writing reservation is missing or changed.');

        if ($intent->parentId !== null) {
            throw_unless(self::extensionCommitted($connection, $intent), FiloquentException::class, 'The parent receipt did not persist the added variants.');
            $query->delete();
        } else {
            throw_unless($query->update(['state' => UploadState::Active->value, 'owner_key' => (string) Persistence::ownerKey($model)]) === 1, FiloquentException::class, 'The upload writing reservation is missing or changed.');
        }
    }

    public static function committed(Connection $connection, UploadIntent $intent): bool
    {
        if ($connection->transactionLevel() !== 0) {
            return false;
        }

        $receipt = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $intent->id)
            ->whereIn('state', [UploadState::Active->value, UploadState::Pending->value, UploadState::Retired->value])->first();

        return ($receipt !== null && $intent->matches($receipt)
            && ($receipt->state !== UploadState::Active->value || $receipt->owner_key !== null))
            || ($intent->parentId !== null && self::extensionCommitted($connection, $intent));
    }

    private static function extensionCommitted(Connection $connection, UploadIntent $intent, bool $lock = false): bool
    {
        $receipt = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $intent->parentId)
            ->whereIn('state', [UploadState::Active->value, UploadState::Retired->value])
            ->when($lock, static fn (Builder $query): Builder => $query->lockForUpdate())->first();

        if ($receipt === null || ($receipt->owner_table !== $intent->ownerTable && ($receipt->journal_owner_table ?? null) !== $intent->ownerTable)
            || $receipt->owner_key_name !== $intent->ownerKeyName || $receipt->owner_key !== $intent->ownerKey) {
            return false;
        }
        $assets = AsFile::decode($receipt->manifest);

        if ($assets === null) {
            return false;
        }
        self::generationDirectory($receipt->id, $assets);
        foreach ($intent->assets->all() as $key => $asset) {
            if (($assets->toArray()[$key] ?? null) !== ['filesystem' => $asset->filesystem, 'path' => $asset->path, 'default' => false]) {
                return false;
            }
        }

        return true;
    }

    public static function promote(Connection $connection, UploadIntent $intent): void
    {
        throw_if($connection->transactionLevel() !== 0 || self::scope($connection) !== $intent->scope, FiloquentException::class, 'Upload promotion requires the matching ledger outside a transaction.');

        try {
            $connection->transaction(static function () use ($connection, $intent): void {
                if ($intent->parentId !== null && $connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $now = CarbonImmutable::now('UTC');
                $connection->table('filoquent_uploads')->insert([
                    ...$intent->receipt(),
                    'state' => UploadState::Pending->value,
                    'checked_at' => $now->addHours(Reconciler::GRACE_HOURS)->format('Y-m-d H:i:s.u'),
                    'orphaned_at' => $now->format('Y-m-d H:i:s.u'),
                ]);

                if ($intent->parentId !== null && self::extensionCommitted($connection, $intent, lock: true)) {
                    $connection->table('filoquent_uploads')->where('id', $intent->id)->where('state', UploadState::Pending->value)->delete();
                }
            });
        } catch (UniqueConstraintViolationException $exception) {
            // PostgreSQL needs the failed insert transaction rolled back before this read.
            throw_unless(self::committed($connection, $intent), static fn (): FiloquentException => new FiloquentException('The journal upload conflicts with an existing ownership receipt.', previous: $exception));
        }
    }

    public static function retirePending(Connection $connection, string $id): bool
    {
        throw_if($connection->transactionLevel() !== 0, FiloquentException::class, 'Pending upload cleanup must run outside a database transaction.');

        $retired = $connection->transaction(static function () use ($connection, $id): bool {
            $query = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $id)->where('state', UploadState::Pending->value);
            $receipt = $query->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC');

            if ($receipt === null) {
                return false;
            }

            throw_if($receipt->orphaned_at === null, FiloquentException::class, 'The pending upload has no promotion timestamp.');

            $eligible = CarbonImmutable::parse($receipt->orphaned_at, 'UTC')->addHours(Reconciler::GRACE_HOURS);

            if ($eligible->greaterThan($now)) {
                $query->update(['checked_at' => $eligible->format('Y-m-d H:i:s.u')]);

                return false;
            }

            new UploadIntent(self::scope($connection), $receipt->id, $receipt->owner_type, $receipt->owner_table,
                $receipt->owner_key_name, $receipt->owner_key, $receipt->attribute,
                AsFile::decode($receipt->manifest) ?? throw new FiloquentException('The pending upload has no manifest.'));

            return $query->update(['state' => UploadState::Retired->value]) === 1;
        });

        return ! $retired || self::drain($connection, $id);
    }

    public static function activate(Model $model, string $attribute, string $id, Assets $assets): void
    {
        $directory = self::generationDirectory($id, $assets);

        throw_unless(str_starts_with($directory, config('filoquent.base_path').'/'), FiloquentException::class, "Upload {$id} is outside the configured base path.");

        $model->getConnection()->table('filoquent_uploads')->insert([
            'id' => $id,
            'owner_type' => $model::class,
            'owner_table' => (new OwnerTables($model->getConnection()))->canonical($model->getTable()),
            'owner_key_name' => $model->getKeyName(),
            'owner_key' => (string) Persistence::ownerKey($model),
            'attribute' => $attribute,
            'manifest' => json_encode($assets, JSON_THROW_ON_ERROR),
            'state' => UploadState::Active->value,
            'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public static function lockForExtension(Model $model, string $attribute, Assets $assets): string
    {
        $id = basename(dirname($assets->default()->path ?? ''));
        self::generationDirectory($id, $assets);
        $receipt = $model->getConnection()->table('filoquent_uploads')->useWritePdo()->where('id', $id)
            ->where('state', UploadState::Active->value)->lockForUpdate()->first();
        throw_if($receipt === null || $receipt->owner_table !== (new OwnerTables($model->getConnection()))->canonical($model->getTable())
            || $receipt->owner_key_name !== $model->getKeyName() || $receipt->owner_key !== (string) Persistence::ownerKey($model)
            || ! AsFile::matches(AsFile::decode($receipt->manifest), $assets), FiloquentException::class, 'Regeneration requires the exact active ownership receipt.');

        foreach (get_object_vars(Persistence::row($model)->first() ?? (object) []) as $column => $value) {
            if ($column === $attribute || ! is_string($value)) {
                continue;
            }

            try {
                $other = AsFile::decode($value);
            } catch (FiloquentException) {
                continue;
            }
            throw_if(AsFile::matches($other, $assets), FiloquentException::class, 'Regeneration requires an upload referenced by only one owner field.');
        }

        return $id;
    }

    public static function retire(Model $model, Assets $assets): ?string
    {
        $id = basename(dirname($assets->default()->path ?? ''));

        if (! Str::isUuid($id, 7)) {
            return null;
        }

        $query = $model->getConnection()->table('filoquent_uploads')->where('id', $id)->where('state', UploadState::Active->value);
        $receipt = $query->lockForUpdate()->first();

        if ($receipt === null
            || $receipt->owner_table !== (new OwnerTables($model->getConnection()))->canonical($model->getTable()) || $receipt->owner_key_name !== $model->getKeyName()
            || $receipt->owner_key !== (string) Persistence::ownerKey($model)
            || ! AsFile::matches(AsFile::decode($receipt->manifest), $assets)) {
            return null;
        }

        $query->update(['state' => UploadState::Retired->value]);

        return $id;
    }

    public static function drain(Connection $connection, string $id): bool
    {
        if ($connection->transactionLevel() !== 0) {
            return false;
        }

        try {
            $query = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $id)->where('state', UploadState::Retired->value);
            $receipt = $query->first();

            if ($receipt === null) {
                return true;
            }

            $assets = AsFile::decode($receipt->manifest);

            throw_if($assets === null, FiloquentException::class, "Missing manifest for retired upload {$id}.");

            self::generationDirectory($id, $assets);
            foreach ($assets->all() as $asset) {
                $disk = (new StorageAccess)->forDeletion($asset->filesystem);

                if ($disk->exists($asset->path) && ! $disk->delete($asset->path)) {
                    throw new FiloquentException("Could not remove retired file {$asset->path} from {$asset->filesystem}.");
                }
            }

            $query->delete();

            return true;
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
            }

            return false;
        }
    }

    public static function generationDirectory(string $id, Assets $assets): string
    {
        $directory = dirname($assets->default()->path ?? '');

        throw_if(! Str::isUuid($id, 7) || basename($directory) !== $id
            || count(explode('/', $directory)) < 3, FiloquentException::class, "Unexpected storage directory for upload {$id}.");

        foreach ($assets->all() as $asset) {
            $parent = dirname($asset->path);
            $extension = dirname($parent) === $directory.'/variants' && Str::isUuid(basename($parent), 7) && ! $asset->default;
            throw_if($parent !== $directory && ! $extension, FiloquentException::class, "File outside the directory owned by upload {$id}.");
        }

        return $directory;
    }
}
