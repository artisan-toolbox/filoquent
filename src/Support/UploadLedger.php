<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
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
            'state' => 'writing',
            'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public static function confirm(Model $model, UploadIntent $intent): void
    {
        $connection = $model->getConnection();
        $query = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $intent->id)->where('state', 'writing');
        $receipt = $query->lockForUpdate()->first();

        throw_if($connection->transactionLevel() === 0 || $receipt === null || ! $intent->matches($receipt)
            || Persistence::ownerKey($model) === null || $query->update(['state' => 'active', 'owner_key' => (string) Persistence::ownerKey($model)]) !== 1, FiloquentException::class, 'The upload writing reservation is missing or changed.');
    }

    public static function committed(Connection $connection, UploadIntent $intent): bool
    {
        if ($connection->transactionLevel() !== 0) {
            return false;
        }

        $receipt = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $intent->id)
            ->whereIn('state', ['active', 'pending', 'retired'])->first();

        return $receipt !== null && $intent->matches($receipt)
            && ($receipt->state !== 'active' || $receipt->owner_key !== null);
    }

    public static function promote(Connection $connection, UploadIntent $intent): void
    {
        throw_if($connection->transactionLevel() !== 0 || self::scope($connection) !== $intent->scope, FiloquentException::class, 'Upload promotion requires the matching ledger outside a transaction.');

        try {
            $connection->transaction(static function () use ($connection, $intent): void {
                $now = CarbonImmutable::now('UTC');
                $connection->table('filoquent_uploads')->insert([
                    ...$intent->receipt(),
                    'state' => 'pending',
                    'checked_at' => $now->addHours(Reconciler::GRACE_HOURS)->format('Y-m-d H:i:s.u'),
                    'orphaned_at' => $now->format('Y-m-d H:i:s.u'),
                ]);
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
            $query = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $id)->where('state', 'pending');
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

            return $query->update(['state' => 'retired']) === 1;
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
            'state' => 'active',
            'checked_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    public static function retire(Model $model, Assets $assets): ?string
    {
        $id = basename(dirname($assets->default()->path ?? ''));

        if (! Str::isUuid($id, 7)) {
            return null;
        }

        $query = $model->getConnection()->table('filoquent_uploads')->where('id', $id)->where('state', 'active');
        $receipt = $query->lockForUpdate()->first();

        if ($receipt === null
            || $receipt->owner_table !== (new OwnerTables($model->getConnection()))->canonical($model->getTable()) || $receipt->owner_key_name !== $model->getKeyName()
            || $receipt->owner_key !== (string) Persistence::ownerKey($model)
            || ! AsFile::matches(AsFile::decode($receipt->manifest), $assets)) {
            return null;
        }

        $query->update(['state' => 'retired']);

        return $id;
    }

    public static function drain(Connection $connection, string $id): bool
    {
        if ($connection->transactionLevel() !== 0) {
            return false;
        }

        try {
            $query = $connection->table('filoquent_uploads')->useWritePdo()->where('id', $id)->where('state', 'retired');
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
            throw_if(dirname($asset->path) !== $directory, FiloquentException::class, "File outside the directory owned by upload {$id}.");
        }

        return $directory;
    }
}
