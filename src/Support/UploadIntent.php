<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Assets;
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use stdClass;

/** @internal */
final readonly class UploadIntent
{
    public function __construct(
        public string $scope,
        public string $id,
        public string $ownerType,
        public string $ownerTable,
        public string $ownerKeyName,
        public ?string $ownerKey,
        public string $attribute,
        public Assets $assets,
    ) {
        throw_if(! Str::isUuid($scope) || ! Str::isUuid($id, 7)
            || $ownerType === '' || strlen($ownerType) > 255
            || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $ownerTable) || strlen($ownerTable) > 128
            || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $ownerKeyName) || strlen($ownerKeyName) > 64
            || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $attribute) || strlen($attribute) > 64
            || ($ownerKey !== null && ($ownerKey === '' || strlen($ownerKey) > 255)), FiloquentException::class, 'The upload intent contains invalid ownership metadata.');

        UploadLedger::generationDirectory($id, $assets);
    }

    public static function forModel(Model $model, string $attribute, string $id, Assets $assets): self
    {
        return new self(
            UploadLedger::scope($model->getConnection()), $id, $model::class, (new OwnerTables($model->getConnection()))->canonical($model->getTable()),
            $model->getKeyName(), ! $model->exists || Persistence::ownerKey($model) === null ? null : (string) Persistence::ownerKey($model), $attribute, $assets,
        );
    }

    public static function decode(string $payload, string $scope, string $id): self
    {
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        throw_if(! is_array($data) || count($data) !== 9 || ($data['version'] ?? null) !== 1
            || ($data['scope'] ?? null) !== $scope || ($data['id'] ?? null) !== $id
            || ! array_key_exists('owner_key', $data) || ($data['owner_key'] !== null && ! is_string($data['owner_key']))
            || ! isset($data['manifest']) || ! is_string($data['manifest']), FiloquentException::class, 'The upload intent has an unsupported payload or ledger namespace.');

        foreach (['owner_type', 'owner_table', 'owner_key_name', 'attribute'] as $field) {
            throw_if(! isset($data[$field]) || ! is_string($data[$field]), FiloquentException::class, 'The upload intent contains invalid ownership metadata.');
        }

        return new self($scope, $id, $data['owner_type'], $data['owner_table'], $data['owner_key_name'], $data['owner_key'], $data['attribute'], AsFile::decode($data['manifest']) ?? throw new FiloquentException('The upload intent has no manifest.'));
    }

    public function encode(): string
    {
        return json_encode(['version' => 1, 'scope' => $this->scope, ...$this->receipt()], JSON_THROW_ON_ERROR);
    }

    /** @return array{id: string, owner_type: string, owner_table: string, owner_key_name: string, owner_key: string|null, attribute: string, manifest: string} */
    public function receipt(): array
    {
        return [
            'id' => $this->id,
            'owner_type' => $this->ownerType,
            'owner_table' => $this->ownerTable,
            'owner_key_name' => $this->ownerKeyName,
            'owner_key' => $this->ownerKey,
            'attribute' => $this->attribute,
            'manifest' => json_encode($this->assets, JSON_THROW_ON_ERROR),
        ];
    }

    public function matches(stdClass $receipt): bool
    {
        return $receipt->id === $this->id && $receipt->owner_type === $this->ownerType
            && ($receipt->journal_owner_table ?? $receipt->owner_table) === $this->ownerTable && $receipt->owner_key_name === $this->ownerKeyName
            && ($this->ownerKey === null || $receipt->owner_key === $this->ownerKey)
            && $receipt->attribute === $this->attribute && AsFile::matches(AsFile::decode($receipt->manifest), $this->assets);
    }
}
