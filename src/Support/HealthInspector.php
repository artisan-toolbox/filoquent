<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Enums\UploadState;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * @internal
 *
 * @phpstan-import-type Receipt from Reconciler
 */
final readonly class HealthInspector
{
    public function __construct(private Connection $connection) {}

    /** @param Closure('start'|'advance'|'finish', int): void $progress */
    public function scan(?int $limit, ?string $after, CommandRun $run, Closure $progress): int
    {
        $run->counts = array_fill_keys(['uploads_examined', 'files_checked', 'files_present', 'files_missing', 'files_inconclusive', 'uploads_skipped', 'uploads_inconclusive'], 0);
        $run->partial = $after !== null;
        $run->cursor = $after;
        $run->upperBound = $this->active()->when($after !== null, fn (Builder $query): Builder => $query->where('id', '>', $after))
            ->orderByDesc('id')->value('id');

        if ($run->upperBound === null) {
            $run->completed = ! $run->interrupted;
            $run->partial = $after !== null || $run->interrupted;
            $run->rangeExhausted = true;

            return $run->interrupted ? 1 : 0;
        }

        while (! $run->interrupted) {
            $remaining = $limit === null ? 500 : min(500, $limit - $run->counts['uploads_examined']);

            if ($remaining === 0) {
                $run->rangeExhausted = ! $this->range($run)->exists();

                break;
            }

            /** @var Collection<int, Receipt> $receipts */
            $receipts = $this->range($run)->orderBy('id')->limit($remaining)->get();

            if ($receipts->isEmpty()) {
                $run->rangeExhausted = true;

                break;
            }

            $reconciler = new Reconciler($this->connection);
            $progress('start', $receipts->count());

            try {
                foreach ($receipts as $receipt) {
                    if ($run->interrupted) {
                        break;
                    }
                    $this->examine($receipt, $reconciler, $run);
                    $run->increment('uploads_examined');
                    $run->cursor = $receipt->id;
                    $progress('advance', 1);
                }
            } finally {
                $progress('finish', $receipts->count());
            }

            if (! $run->interrupted && $receipts->count() < $remaining) {
                $run->rangeExhausted = true;

                break;
            }
        }

        $run->completed = ! $run->interrupted;
        $run->partial = $after !== null || ! $run->rangeExhausted || $run->interrupted;

        return $run->interrupted || $run->counts['files_missing'] + $run->counts['files_inconclusive'] + $run->counts['uploads_inconclusive'] > 0 ? 1 : 0;
    }

    /** @param Receipt $receipt */
    private function examine(object $receipt, Reconciler $reconciler, CommandRun $run): void
    {
        $reference = $reconciler->reconcile($receipt, false);

        if ($reference['status'] !== 'live') {
            $this->unlinked($reference['status'], $receipt->id, $run);

            return;
        }

        $assets = AsFile::decode($receipt->manifest);
        /** @var array<string, 'present'|'missing'|'inconclusive'> $results */
        $results = [];

        foreach ($assets?->all() ?? [] as $variant => $asset) {
            try {
                $results[$variant] = (new StorageAccess)->forReading($asset->filesystem)->fileExists($asset->path) ? 'present' : 'missing';
            } catch (Throwable) {
                $results[$variant] = 'inconclusive';
            }
        }

        if (in_array('missing', $results, true) || in_array('inconclusive', $results, true)) {
            $reference = $reconciler->confirmReference($receipt);

            if ($reference['status'] !== 'live') {
                $this->unlinked($reference['status'], $receipt->id, $run);

                return;
            }
        }

        foreach ($results as $variant => $status) {
            $run->increment('files_checked');
            $run->increment('files_'.$status);

            if ($status !== 'present') {
                $run->issue('file_'.$status, $receipt->id, $variant);
            }
        }
    }

    private function unlinked(string $status, string $id, CommandRun $run): void
    {
        if (in_array($status, ['absent', 'skipped'], true)) {
            $run->increment('uploads_skipped');
        } else {
            $run->increment('uploads_inconclusive');
            $run->issue('ownership_inconclusive', $id);
        }
    }

    private function active(): Builder
    {
        return $this->connection->table('filoquent_uploads')->useWritePdo()->where('state', UploadState::Active->value);
    }

    private function range(CommandRun $run): Builder
    {
        return $this->active()->where('id', '<=', $run->upperBound)
            ->when($run->cursor !== null, fn (Builder $query): Builder => $query->where('id', '>', $run->cursor));
    }
}
