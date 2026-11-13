<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Console\Commands;

use ArtisanToolbox\Filoquent\Support\CommandRun;
use ArtisanToolbox\Filoquent\Support\OwnerTables;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

use function Laravel\Prompts\table;

/** @phpstan-import-type OwnerTable from OwnerTables */
#[Description('List missing owner-table groups and explicitly resolve verified table renames or permanent removals')]
#[Signature('filoquent:resolve-pending
                            {--connection= : Configured writer connection}
                            {--limit=100 : Pending table groups 1..1000}
                            {--after= : List groups after this physical table name}
                            {--table= : Pending physical table to resolve}
                            {--rename-to= : Verify and attach this group\'s active uploads to this table}
                            {--removed : Confirm owner table was permanently removed}
                            {--apply : Apply explicitly selected resolution}')]
final class ResolvePendingCommand extends Command
{
    public function handle(): int
    {
        return CommandRun::execute($this, $this->input, fn (CommandRun $run): int => $this->resolve($run));
    }

    private function resolve(CommandRun $run): int
    {
        $run->counts = array_fill_keys(['groups_examined', 'resolved_rename', 'resolved_removed', 'uploads_moved'], 0);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $after = $this->option('after');
        $source = $this->option('table');
        $target = $this->option('rename-to');
        $removed = (bool) $this->option('removed');
        $apply = (bool) $this->option('apply');
        $invalid = false;

        foreach (['after', 'table', 'rename-to'] as $option) {
            $value = $this->option($option);
            $invalid = $invalid || ($value !== null && ! $this->validTableName($value))
                || ($this->input->hasParameterOption('--'.$option) && $value === null);
        }
        $invalid = $invalid || ($target !== null && $removed)
            || ($source === null && ($target !== null || $removed || $apply))
            || ($apply && $target === null && ! $removed)
            || ($source !== null && $after !== null);

        if ($limit === false || $invalid) {
            $run->issue('invalid_options');
            $this->error('Supply a limit from 1 to 1000, valid physical table names, and one resolution with --table. Use --apply only with --rename-to or --removed.');

            return self::INVALID;
        }

        try {
            $connection = DB::connection($this->option('connection'));
            $run->connection = $connection->getName();

            if ($connection->transactionLevel() !== 0) {
                $run->issue('outer_transaction');
                $this->error('Pending owner-table resolution must run outside a database transaction.');

                return self::FAILURE;
            }
            $tables = new OwnerTables($connection);

            if ($source === null) {
                $groups = $tables->pending($limit, $after);
                $run->counts['groups_examined'] = $groups->count();
                $run->cursor = $groups->last()->owner_table ?? $after;
                $this->showGroups($groups->all());
                $this->line('Listed '.$groups->count().' pending groups. Continue with --after using the last physical table name.');
                $this->completeRun($run);

                return self::SUCCESS;
            }

            $group = $tables->findPending($source);

            if ($group === null) {
                $run->issue('group_not_pending');
                $this->error('No pending owner-table group matches the selected table. Refresh the list before resolving it.');

                return self::FAILURE;
            }
            $run->increment('groups_examined');
            $run->cursor = $source;
            $issue = $group->issue_id;
            $this->showGroups([$group]);

            if ($target === null && ! $removed && $this->interactive()) {
                $action = $this->prompt(new SelectPrompt('Choose a resolution for this pending issue', [
                    'cancel' => 'Cancel',
                    'rename' => 'Attach another table',
                    'removed' => 'Authorize permanent removal',
                ], default: 'cancel'), $run);

                if ($action === 'rename') {
                    $target = $this->prompt(new TextPrompt('Physical table containing the original owners', validate: fn (string $value): ?string => $this->validTableName($value) ? null : 'Supply a valid physical table name.'), $run);
                } elseif ($action === 'removed') {
                    $removed = true;
                }

                if ($action !== 'cancel') {
                    $this->describeResolution($source, $target);
                    $apply = $this->prompt(new ConfirmPrompt('Apply this resolution to issue '.$issue.' with '.$group->affected_uploads.' uploads recorded at detection?', default: false), $run);

                    if ($apply) {
                        $this->input->setOption('apply', true);
                    }
                }
            }

            if ($target === null && ! $removed) {
                $this->line('No resolution selected. Choose --rename-to or --removed and add --apply to authorize it.');
                $this->completeRun($run);

                return self::SUCCESS;
            }
            $this->describeResolution($source, $target);

            if (! $apply) {
                $this->info('Preview only. Add --apply to authorize the selected resolution.');
                $this->completeRun($run);

                return self::SUCCESS;
            }
            $moved = $target !== null ? $tables->resolveRename($source, $target, $issue) : $tables->resolveRemoved($source, $issue);
            $run->increment($target !== null ? 'resolved_rename' : 'resolved_removed');
            $run->increment('uploads_moved', $moved);
            $this->info('Resolved issue '.$issue.'; uploads moved '.$moved.'.');
            $this->completeRun($run);

            return self::SUCCESS;
        } catch (Throwable) {
            $run->completed = false;
            $run->partial = true;

            if ($run->interrupted) {
                $run->issue('interrupted');
                $this->error('Pending owner-table resolution interrupted.');

                return self::FAILURE;
            }
            $run->issue('resolution_failed');
            $this->error('Pending resolution could not finish. Refresh the list and verify the writer connection, issue identity, physical primary keys, and exact upload manifests.');

            return self::FAILURE;
        }
    }

    private function prompt(Prompt $prompt, CommandRun $run): mixed
    {
        $prompt->on('key', function (string $key) use ($run): void {
            if ($key === Key::CTRL_C) {
                $run->interrupted = true;

                throw new RuntimeException('Pending owner-table resolution interrupted.');
            }
        });

        return $prompt->prompt();
    }

    /** @param array<int, OwnerTable> $groups */
    private function showGroups(array $groups): void
    {
        if ($groups === []) {
            $this->info('No pending owner tables.');

            return;
        }
        $rows = [];

        foreach ($groups as $group) {
            $rows[] = [$group->owner_table, $group->issue_id, (string) $group->affected_uploads, (string) $group->detected_at];
        }

        if ($this->interactive()) {
            table(['Physical table', 'Issue', 'Uploads at detection', 'Detected at UTC'], $rows);

            return;
        }

        foreach ($rows as $row) {
            $this->line(implode(' | ', $row));
        }
    }

    private function describeResolution(string $source, ?string $target): void
    {
        $this->line($target !== null
            ? 'Verify all active upload owners from '.$source.' in '.$target.' and attach their receipts to that table.'
            : 'Confirm '.$source.' was permanently removed. Move its active uploads to pending cleanup with a new minimum 24-hour grace period. No files are deleted by this command.');
    }

    private function validTableName(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 128
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $value) === 1;
    }

    private function completeRun(CommandRun $run): void
    {
        $run->completed = true;
        $run->partial = false;
    }

    private function interactive(): bool
    {
        $output = $this->output->getOutput();

        return $this->input->isInteractive() && $output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL
            && $output->isDecorated() && $output instanceof StreamOutput
            && defined('STDIN') && stream_isatty(STDIN) && stream_isatty($output->getStream())
            && ! windows_os();
    }
}
