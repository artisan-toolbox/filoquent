<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Fixtures;

use ArtisanToolbox\Filoquent\Exceptions\FiloquentException;
use ArtisanToolbox\Filoquent\Support\RedisUploadJournal;
use ArtisanToolbox\Filoquent\Support\UploadIntent;
use Carbon\CarbonImmutable;
use Closure;

class InMemoryUploadJournal extends RedisUploadJournal
{
    /** @var array<string, array<string, string|null>> */
    public array $records = [];

    /** @var array<string, array<string, float>> */
    public array $scores = [];

    public bool $failRegistration = false;

    public bool $failAcknowledgment = false;

    public bool $loseRegistrationReply = false;

    /** @var Closure(UploadIntent): void|null */
    public ?Closure $onRecord = null;

    public function record(UploadIntent $intent): void
    {
        $this->onRecord?->__invoke($intent);

        throw_if($this->failRegistration || isset($this->records[$intent->scope][$intent->id]), FiloquentException::class, 'Journal registration unavailable or duplicated.');
        $this->records[$intent->scope][$intent->id] = $intent->encode();
        $this->scores[$intent->scope][$intent->id] = $this->now();

        throw_if($this->loseRegistrationReply, FiloquentException::class, 'The registration reply was lost.');
    }

    public function due(string $scope, int $limit): array
    {
        $scores = $this->scores[$scope] ?? [];
        asort($scores);
        $result = [];
        foreach ($scores as $id => $score) {
            if ($score <= $this->now() && count($result) < $limit) {
                $result[$id] = $this->records[$scope][$id] ?? null;
            }
        }

        return $result;
    }

    public function current(string $scope, string $id, string $payload): bool
    {
        return ($this->records[$scope][$id] ?? null) === $payload;
    }

    public function acknowledge(string $scope, string $id, string $payload): void
    {
        throw_if($this->failAcknowledgment, FiloquentException::class, 'Journal acknowledgment unavailable.');

        if ($this->current($scope, $id, $payload)) {
            unset($this->records[$scope][$id], $this->scores[$scope][$id]);
        }
    }

    public function retry(string $scope, string $id, ?string $payload): void
    {
        if (array_key_exists($id, $this->scores[$scope] ?? []) && ($this->records[$scope][$id] ?? null) === $payload) {
            $this->scores[$scope][$id] = $this->now() + 60;
        }
    }

    private function now(): float
    {
        return CarbonImmutable::now('UTC')->getTimestampMs() / 1000;
    }
}
