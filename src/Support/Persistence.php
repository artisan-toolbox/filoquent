<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Asset;
use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Throwable;

/** @internal */
final class Persistence
{
    /** @var array<string, array{id: string, assets: Assets}|null> */
    private array $published = [];

    /** @var array<string, UploadIntent> */
    private array $intents = [];

    /** @var list<string> */
    public array $retired = [];

    /** @var list<string> */
    public array $deletingColumns = [];

    public ?object $deletingRow = null;

    public static function subpath(Model $model, string $attribute): string
    {
        $prefix = config('filoquent.base_path').'/'.hash('sha1', $model::class.'::'.$attribute);
        Asset::validatePath($prefix);

        return $prefix;
    }

    public static function directory(Model $model, string $id, string $attribute): string
    {
        throw_unless(Str::isUuid($id, 7), FiloquentException::class, 'An upload directory requires a UUIDv7.');

        return self::subpath($model, $attribute).'/'.$id;
    }

    /** @return list<string> */
    public static function columns(Model $model): array
    {
        return array_keys(array_filter($model->getCasts(), static fn (string $cast): bool => explode(':', $cast, 2)[0] === AsFile::class));
    }

    public static function row(Model $model): Builder
    {
        return $model->getConnection()->table($model->getTable())->useWritePdo()
            ->where($model->getKeyName(), self::ownerKey($model));
    }

    public static function ownerKey(Model $model): mixed
    {
        return $model->getRawOriginal($model->getKeyName()) ?? ($model->getAttributes()[$model->getKeyName()] ?? null);
    }

    /**
     * @param  array<string, PendingUpload>  $pending
     * @return array<string, string|null>
     */
    public function prepare(Model $model, array $pending): array
    {
        $current = $model->exists ? self::row($model)->lockForUpdate()->first(array_unique([...array_keys($pending), $model->getKeyName()])) : null;

        if ($model->exists) {
            $original = $model->getRawOriginal();
            foreach ($pending as $key => $assignment) {
                throw_if($current === null || ! array_key_exists($key, $original)
                    || ! AsFile::matches(AsFile::decode($original[$key]), AsFile::decode($current->{$key})), FiloquentException::class, "Reload {$key} before changing a missing or stale file attribute.");
            }

            if ($current !== null) {
                self::syncOwnerKey($model, $current->{$model->getKeyName()});
            }
        }

        $prepared = [];
        foreach ($pending as $key => $assignment) {
            $this->published[$key] = null;
            $prepared[$key] = null;

            if ($assignment->upload !== null) {
                $id = (string) Str::uuid7();
                $filesystem = config('filoquent.filesystem') ?? config('filesystems.default');
                $assets = resolve(FileWriter::class)->store(
                    $assignment->upload, $filesystem, self::directory($model, $id, $key),
                    $assignment->naming, $assignment->name, $assignment->middleware,
                    RedisUploadJournal::enabled() ? function (Assets $assets) use ($model, $key, $id): void {
                        $intent = UploadIntent::forModel($model, $key, $id, $assets);
                        UploadLedger::reserve($model, $intent);
                        resolve(RedisUploadJournal::class)->record($intent);
                        $this->intents[$key] = $intent;
                    } : null,
                );
                $this->published[$key] = ['id' => $id, 'assets' => $assets];
                $prepared[$key] = json_encode($assets, JSON_THROW_ON_ERROR);
            }

            $this->retire($model, $current?->{$key});
        }

        return $prepared;
    }

    public function finish(Model $model): void
    {
        if ($this->published === []) {
            return;
        }

        $current = self::row($model)->lockForUpdate()->first(array_unique([...array_keys($this->published), $model->getKeyName()]));

        if ($current !== null) {
            self::syncOwnerKey($model, $current->{$model->getKeyName()});
        }
        foreach ($this->published as $key => $generation) {
            throw_if($current === null || ! AsFile::matches(AsFile::decode($current->{$key}), $generation['assets'] ?? null), FiloquentException::class, "The model did not persist the expected {$key} manifest.");

            if ($generation !== null) {
                if (isset($this->intents[$key])) {
                    $intent = $this->intents[$key];
                    UploadLedger::confirm($model, $intent);
                    $connection = $model->getConnection();
                    $connection->afterCommit(static function () use ($connection, $intent): void {
                        try {
                            if ($connection->transactionLevel() === 0 && UploadLedger::scope($connection) === $intent->scope
                                && UploadLedger::committed($connection, $intent)) {
                                resolve(RedisUploadJournal::class)->acknowledge($intent->scope, $intent->id, $intent->encode());
                            }
                        } catch (Throwable $exception) {
                            try {
                                report($exception);
                            } catch (Throwable) {
                            }
                        }
                    });
                } else {
                    UploadLedger::activate($model, $key, $generation['id'], $generation['assets']);
                }
            }
        }
    }

    private static function syncOwnerKey(Model $model, mixed $key): void
    {
        $model->setRawAttributes(array_replace($model->getAttributes(), [$model->getKeyName() => $key]));
        $model->syncOriginalAttribute($model->getKeyName());
    }

    public function retire(Model $model, mixed $raw): void
    {
        if (($assets = AsFile::decode($raw)) !== null) {
            $id = UploadLedger::retire($model, $assets);

            if ($id !== null) {
                $this->retired[] = $id;
            }
        }
    }
}
