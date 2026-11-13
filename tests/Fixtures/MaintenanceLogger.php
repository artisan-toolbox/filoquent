<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Fixtures;

use Closure;
use LogicException;
use Psr\Log\AbstractLogger;
use Stringable;

final class MaintenanceLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @var (Closure(string): void)|null */
    public ?Closure $beforeLog = null;

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($this->beforeLog !== null) {
            ($this->beforeLog)((string) $message);
        }
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $record = end($this->records);

        throw_if($record === false, LogicException::class, 'No maintenance report was logged.');

        return $record['context'];
    }
}
