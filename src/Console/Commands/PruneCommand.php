<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Console\Commands;

use ArtisanToolbox\Filoquent\Enums\UploadState;
use ArtisanToolbox\Filoquent\Support\CommandRun;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Description('Preview or remove files belonging to retired Filoquent uploads')]
#[Signature('filoquent:prune
                            {--connection= : Database connection containing the upload ledger}
                            {--limit=100 : Maximum retired uploads to examine}
                            {--after= : Start after this upload UUID}
                            {--apply : Delete the exact files recorded for retired uploads}')]
final class PruneCommand extends Command
{
    public function handle(): int
    {
        return CommandRun::execute($this, $this->input, fn (CommandRun $run): int => $this->prune($run));
    }

    private function prune(CommandRun $run): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $after = $this->option('after');

        if ($limit === false || ($after !== null && ! Str::isUuid($after, 7))) {
            $run->issue('invalid_options');
            $this->error('Supply a limit from 1 to 10000 and a valid UUIDv7 cursor.');

            return self::INVALID;
        }

        $connection = DB::connection($this->option('connection'));
        $run->connection = $connection->getName();
        $run->counts = ['retired_examined' => 0, 'retired_failed' => 0];
        $run->cursor = $after;

        if ($connection->transactionLevel() !== 0) {
            $run->issue('outer_transaction');
            $this->error('Prune must run outside a database transaction.');

            return self::FAILURE;
        }

        $ids = $connection->table('filoquent_uploads')->useWritePdo()
            ->where('state', UploadState::Retired->value)
            ->when($after, fn (Builder $query): Builder => $query->where('id', '>', $after))
            ->orderBy('id')->limit($limit)->pluck('id');
        $failures = 0;
        foreach ($ids as $id) {
            $this->line($id);

            if ($this->option('apply') && ! UploadLedger::drain($connection, $id)) {
                $this->error("Could not finish retiring {$id}. Its ledger entry is available for retry.");
                $failures++;
                $run->increment('retired_failed');
                $run->issue('retired_cleanup_failed', $id);
            }
            $run->increment('retired_examined');
            $run->cursor = $id;
        }

        $run->completed = true;
        $this->info($this->option('apply') ? "Examined {$ids->count()} retired uploads." : "Previewed {$ids->count()} retired uploads. Use --apply to remove their files.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
