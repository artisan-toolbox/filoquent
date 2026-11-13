---
name: filoquent-development
description: Use Filoquent file attributes in Laravel applications. Apply when assigning uploads, local paths, or streams to Eloquent models, declaring native PHP File configuration attributes, configuring storage through environment variables, configuring file naming or middleware variants, reading stored assets, renaming owner tables, configuring optional Redis rollback recovery, checking referenced files, scheduling background maintenance, or safely draining retirements and collecting orphaned uploads. Do not select for unrelated Laravel work or generic filesystem tasks.
license: MIT
metadata:
  author: Allan Mariucci Carvalho
---

# Use Filoquent file attributes

Inspect `composer show artisan-toolbox/filoquent` and the installed API first. Filoquent requires PHP ^8.5 and Laravel 13. The published beta is `1.0.0-beta.1`; this source prepares `1.0.0-beta.2`. Native PHP `File` attributes and persisted-state enums described below require beta 2 source and remain unpublished until its release. APIs may change before the stable release. Never assume a newer API exists in an older installed package.

## Install and prepare

Start in an existing Laravel 13 application with a database and writable Laravel filesystem. Run from the application root:

```bash
composer require artisan-toolbox/filoquent:1.0.0-beta.1
php artisan migrate
```

The explicit prerelease version does not require changing the application's global `minimum-stability` or adding a custom repository. Laravel discovers `FiloquentServiceProvider`. When discovery is disabled, register it in `bootstrap/providers.php`. No frontend installation is needed. The package migrations create `filoquent_uploads` and its SQL journal namespace. Install them on every participating model connection.

Create a nullable JSON attribute in the application's model migration:

```php
$table->filoquent('attachment')->nullable();
```

Add the lifecycle trait and cast to that model. This example assumes its `documents` table has an `id`, `attachment`, and timestamps:

```php
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFiloquent;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}

$source = storage_path('app/filoquent-example.txt');
file_put_contents($source, 'Hello Filoquent');

$document = new Document;
$document->attachment = $source;
$document->save();

dump($document->attachment->default()->exists());
```

The dump is `true`. Assignment snapshots the source locally. Saving publishes bytes and a JSON manifest. Use a validated `$request->file('attachment')`, an absolute readable local path, or a readable stream. Seekable streams are read from their start with the caller's position restored. Nonseekable streams consume their remaining bytes. The caller retains its original file and stream ownership. Save pending uploads before native PHP serialization or queueing; serializing an owned pending snapshot is rejected.

## Configure and consume

### Declare files with native PHP attributes

In beta 2 source, native class attributes are an alternative to entries in `casts()`:

```php
use ArtisanToolbox\Filoquent\Attributes\File;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use ArtisanToolbox\Filoquent\Enums\FileNaming;
use Illuminate\Database\Eloquent\Model;

#[File('attachment', naming: FileNaming::Original)]
#[File('cover', name: 'Cover')]
class Document extends Model
{
    use HasFiloquent;
}
```

Create nullable `$table->filoquent('attachment')` and `$table->filoquent('cover')` columns before saving this model. Assigning and saving `attachment` preserves its sanitized original filename. The default naming policy is `FileNaming::Uuid`. The optional `name` and `middleware` arguments follow `AsFile::using()` behavior; middleware must extend `ArtisanToolbox\Filoquent\Middleware`. Defining metadata does not instantiate middleware, resolve Redis, or publish files.

Keep `HasFiloquent` on the model. Attributes belong above the class, not on PHP properties that would shadow Eloquent fields. A column name starts with a letter or underscore and contains at most 64 letters, digits, or underscores. Repeat `File` for different fields. Duplicate fields within one class throw `FiloquentException`. Declarations are inherited, and a child declaration for the same field replaces its parent's declaration.

Explicit `$casts` entries, `casts()` entries, and runtime `mergeCasts()` overrides take priority over metadata for the same field. Existing `AsFile::class` and `AsFile::using()` declarations remain supported. Configure each field through one declaration when possible. Existing saved manifests keep their paths after metadata changes. See [native attributes](https://atb.dev.br/packages/filoquent/configuration/#native-attributes).

### Configure storage and naming

Publish optional configuration with `php artisan vendor:publish --tag=filoquent-config`. `filoquent.filesystem` selects a Laravel disk or uses Laravel's default when null. `filoquent.base_path` defaults to `assets`. Configure S3-compatible adapters through Laravel, not a second Filoquent storage client.

Development source exposes every configuration value through environment variables. Inspect the installed `config/filoquent.php` before relying on these mappings. In an earlier release, publish the file and add the corresponding `env()` calls described in the [configuration reference](https://atb.dev.br/packages/filoquent/configuration/#environment).

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `FILOQUENT_FILESYSTEM` | `null` | Laravel disk name. Null uses the application's default disk. |
| `FILOQUENT_BASE_PATH` | `assets` | Relative directory for new uploads. |
| `FILOQUENT_REDIS_DB` | `null` | Reserved Redis logical database. Null disables recovery; zero is valid. |
| `FILOQUENT_REDIS_CONNECTION` | `default` | Existing Laravel Redis connection used as the journal base. |

`FILOQUENT_REDIS_DB` already exists in earlier releases. New mappings for the other settings require the updated configuration. After editing `.env`, rebuild any configuration cache with `php artisan config:cache` and restart long-running workers. Keep `env()` calls inside configuration files. Existing application configuration overrides package defaults. Development source removes the unused `placeholder` option; remove it from older published configuration files too. Existing files keep their recorded disk and path.

Import `ArtisanToolbox\Filoquent\Enums\FileNaming`. Use `AsFile::using(naming: FileNaming::Original)`, `AsFile::using(name: 'document')`, or `AsFile::using(middleware: DocumentVariants::class)`. Names omit extensions. `FileNaming::Uuid` is the default. The `naming` argument accepts only these enum cases, not raw strings. Invalid serialized cast policies fail when Laravel resolves the cast. Existing asset metadata needs no migration. Content MIME supplies the extension unless trusted middleware overrides it. The model directory is always the SHA1 of its FQN plus `::` plus the attribute name when an upload is created. It cannot be customized. That hash is not used for reading, integrity checks, replacement, or deletion. Every field upload has a separate UUIDv7 directory.

A populated attribute returns immutable `Assets`; null stays null. Use `default()`, `get('thumbnail')`, and `all()`. A missing variant returns null. String conversion returns the default URL. Each `Asset` exposes `filesystem`, `path`, `default`, `url()`, `temporaryUrl()`, `exists()`, and `readStream()`. Close returned streams. Private delivery and temporary URL support depend on the disk adapter. Serializing returns the key-to-metadata map without another DB query or filesystem listing.

Extend `ArtisanToolbox\Filoquent\Middleware` and implement `handle(Upload $file): PendingAsset|array`. Return `$file->asset()` for the original and `PendingAsset::fromPath(...)` for locally generated variants. The input exposes `path`, `name`, `extension`, `mime`, and `size`. The service container resolves middleware. It owns temporary generated sources and must keep them readable until storage completes. Output names override cast naming. Require unique keys and physical names and exactly one default. Supply an actual image library to generate image bytes; changing an extension performs no conversion. Keep irreversible external side effects out of middleware.

## Preserve ownership

Assign a new source to replace a field or null to clear it, then save. Hard instance deletion retires known owned files. Soft deletion retains them. Never assign raw manifests, copy manifests between attributes or rows, or reattach retired files. `replicate()` omits file attributes. Assign source bytes for independent copies.

Instance `save()`, `delete()`, and their quiet equivalents use the lifecycle. Assign before save rather than inside its observers. Use `save()` for pending uploads, not `saveOrIgnore()`. Bulk updates or deletes, upserts, raw SQL, cascades, and manual JSON imports bypass this protocol. Keep model primary keys stable. Wrap the complete `forceDelete()` call in an application transaction when a `forceDeleted` observer must participate in rollback; that notification occurs after the virtual delete method returns.

Stale replacements fail. After a caller-owned transaction rolls back, discard or refresh the instance and reassign a readable source. Cleanup cannot run before the outer commit. A failed cleanup retains its retired ledger row for retry. Without the optional Redis journal, crashes or rollbacks can leave unpublished bytes without a committed receipt. Those objects remain outside automatic discovery. Do not promise zero orphan retention or delete unknown files based on age.

Preview `php artisan filoquent:prune --limit=100`. Apply with `--apply` after reviewing it. It processes exact known retired paths, not all storage. `--connection` selects the database; `--after` resumes by UUID. Bound batches and retry failed records. Never scan all models or all storage on ordinary reads, writes, or deletes.

Preview `php artisan filoquent:gc --limit=100` to reconcile committed active receipts after manual row deletion or removed JSON columns. Apply with `--apply`. First confirmed absence marks a candidate; a second absence at least 24 hours later permits retirement. Every applied batch rotates indexed `checked_at` values, so repeated bounded runs progress without a reset cursor. Exact manifests in renamed columns preserve files. Soft-deleted rows remain live. Missing tables, missing primary keys, or partial/malformed references are unresolved and preserved. Run with full writer visibility across tenants and pause during schema migrations/restores. No model/field hash is used for collection. A storage object without either a SQL receipt or a Redis upload intent cannot be discovered by this command.

## Check files and schedule maintenance

Run `php artisan filoquent:health` from the application root after migrating the owning database. It checks all active upload receipts up to the highest starting UUID. It verifies every variant using the saved filesystem and path, including soft-deleted owners and exact manifests preserved after model or field renames. It reads physical owner rows without hydrating model classes. It does not change owners, upload ledger fields, files, or Redis. An unresolved built-in local disk needs an existing root directory, including local parents of composite disks. A missing root is inconclusive and is not created. Already resolved disks remain usable. Custom disk factories must avoid initialization side effects. Imported JSON without a committed receipt remains outside its coverage.

Use `--connection=media` for another owning database. All three maintenance commands require writer connections and reject `::read` selectors, including a reader selected as the default. Supply a value after every `--limit`, `--after`, or `--connection` flag that you use; a bare flag or an empty or `0` connection name exits 2 before maintenance. Omit `--limit` for the full starting range, or supply `--limit=1000` with a value from 1 to 10000. Resume a restricted suffix with a canonical lowercase `--after=<UUIDv7>` using the reported cursor. A resumed pass captures a new upper bound, so run fresh full checks periodically. The command reads fixed batches of at most 500 receipts without a population-wide count. Concurrent replacements, retired receipts, and removed references are skipped after a negative file probe is revalidated. Storage checks run outside SQL locks. This is a point-in-time existence check, not a content or permission audit.

Normal decorated interactive terminals show live batch progress. Pipes, `--no-ansi`, and `--no-interaction` use plain summaries. `--quiet` suppresses routine summaries, and `--silent` suppresses all console output. With supported `pcntl` signal handling, SIGINT and SIGTERM stop at a receipt boundary and log an interrupted partial result with the last fully examined cursor. A hard kill or process crash cannot guarantee a completion log.

Exit 0 means no missing or inconclusive results in the stated scope. Exit 1 means missing files, inconclusive verification, interruption, unexpected failure, or failed logging. Invalid limits or cursors exit 2. Inspect both coverage and counters. `completed` means the requested traversal ended normally, including runs with findings. `partial` marks a supplied cursor, a limit with more range remaining, or interruption. `range_exhausted` reports whether the starting range ended. Empty ranges are clean in their stated scope.

Health counters are `uploads_examined`, `files_checked`, `files_present`, `files_missing`, `files_inconclusive`, `uploads_skipped`, and `uploads_inconclusive`. File counters include accepted current references. A concurrently skipped receipt discards provisional file outcomes together. Unverifiable metadata counts as an inconclusive upload without inventing a file count.

All three commands emit `Filoquent maintenance started.` and `Filoquent maintenance completed.` through the application's default logger, including under `--silent`. Configure a writable channel accepting `info` messages. Healthy results use `info`, confirmed missing files use `warning`, and inconclusive, invalid, interrupted, or unexpected failures use `error`. Context includes the run ID, command, mode, requested and effective connections, limit, range, cursor, counters, duration, exit code, and coverage. Diagnostics contain at most 10 bounded issue samples and omit adapter exception text. GC and prune report bounded maintenance passes, always with partial coverage; they do not certify global file health.

If the start logger throws, no maintenance work runs. If completion logging throws, completed operations stay applied and an otherwise successful command exits 1. The original prune exception still propagates if both work and logging fail. Silent output stays silent even when logging fails. Application-owned filtering or discarded logs cannot be detected by Filoquent.

After reviewing cleanup previews, add this to the application's `routes/console.php` and run Laravel's scheduler in production:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('filoquent:health --silent')
    ->daily()->withoutOverlapping()->runInBackground();
Schedule::command('filoquent:gc --apply --limit=100 --silent')
    ->everyFiveMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('filoquent:prune --apply --limit=100 --silent')
    ->everyTenMinutes()->withoutOverlapping()->runInBackground();
```

Expect start and completion summaries in the configured application log while the console stays silent. Background processes still consume database and storage I/O. Health requires neither Redis nor a queue worker. Enabled Redis recovery retains its own prerequisites. On multiple scheduler hosts, optionally add `onOneServer()` with Laravel's supported shared cache. See [asset health](https://atb.dev.br/packages/filoquent/health/) for coverage and operational limits.

## Resolve missing owner tables

Model and column renames keep existing paths. Keep the physical table name explicit when renaming an Eloquent class. Rename the JSON column in a migration and update its cast and callers together. Receipt ownership uses the raw, database-normalized primary key, independently of an accessor's displayed key.

Before renaming a physical owner table, pause writers and maintenance and wait for in-flight work. Use a native Laravel migration to rename the table. Leave the old name absent until `filoquent:gc --apply` detects it and records a persistent pending group. Do not reuse the old name before detection. An unobserved rename followed by immediate old-name reuse cannot be distinguished from ordinary row deletion.

The GC groups all active receipts for the missing table through an index. It preserves their files and stops traversing that group, even if the old table name reappears. One grouped error per UTC day includes the active upload count at detection and the resolution command. Routine maintenance start and completion summaries remain per invocation. Managed model saves and deletions on the pending route fail before remote storage work. Other routes continue normally.

List pending groups without changing data:

```bash
php artisan filoquent:resolve-pending
```

After reviewing the group, attach it to the renamed table:

```bash
php artisan filoquent:resolve-pending --table=documents --rename-to=articles --apply
```

The command performs no DDL. It validates the destination's physical primary key and every active receipt's exact manifest on the corresponding row. It checks bounded pages, then retargets the group atomically without changing paths or files. A mismatch preserves the entire pending group. Use the original name as `--rename-to` to verify a restored table. Previously registered Redis intents remain recognizable after successive renames.

PostgreSQL routes always include the physical schema, including `public`. Copy the exact listed route. For the example in that schema, use `--table=public.documents --rename-to=public.articles --apply`. Later `search_path` changes cannot redirect saved ownership to another tenant. When upgrading older preview receipts without a schema, the ownership-gate migration preserves their original journal routes and blocks active groups. These groups require a qualified `--rename-to` destination containing every original manifest; `--removed` is refused until verification succeeds. Mixed-schema groups stay blocked if no single destination proves ownership. Preserve their files and reconcile original records rather than bypassing the block.

If the table was permanently removed and is still absent, authorize delayed cleanup explicitly:

```bash
php artisan filoquent:resolve-pending --table=documents --removed --apply
```

Only that group's active receipts become SQL pending cleanup claims. A new 24-hour grace period starts at confirmation. The command does not delete files. Any relation at the old name, including a PostgreSQL materialized view, or an unavailable schema prevents removal. Scheduled GC performs the later cleanup and prune retries exact failed retirements. Read-only listing and action previews need no `--apply`. The optional `--connection` selects the configured writer. Listings support `--limit=100` and a table-name `--after` cursor.

Keep direct SQL writes and DDL paused on both tables during resolution. Memory use is bounded, but the atomic rename resolution holds database locks proportional to the affected uploads. See [resolve missing owner tables](https://atb.dev.br/packages/filoquent/rename-owners/) for reversible migrations, interactive choices, and operational limits.

## Recover interrupted uploads with Redis

Redis recovery is optional and disabled when `filoquent.redis.database` is null. In an application with Laravel Redis configured, reserve a dedicated logical database on that standalone server and add `FILOQUENT_REDIS_DB=2` to its environment. Database 2 is an example, not a database the package reserves automatically. Keep caches, sessions, and queue data elsewhere. Install `illuminate/redis:^13.0` when the application does not already provide it, and enable PhpRedis or install the configured Predis client. Redis Cluster is unsupported. An optional config-only `filoquent.redis.connection` selects the base Laravel Redis connection, defaulting to `default`. No Redis credentials need to be duplicated. Refresh cached configuration after changing the setting.

Run `php artisan migrate` on every owning SQL connection before enabling recovery. Assignment still snapshots only a local source. Saving registers the final planned paths before storage writes. Unavailable Redis stops an enabled save before it writes remote bytes. A failed acknowledgment after the outer SQL commit leaves the save successful and the intent retryable. Rollbacks retain their intents.

Schedule bounded `filoquent:gc --apply` runs. Each run imports at most `--limit` Redis intents, checks at most `--limit` due pending receipts, and reconciles at most `--limit` active receipts. Preview changes neither SQL nor Redis nor storage. Import starts a new 24-hour grace period at the first SQL promotion, regardless of the intent's age. Duplicate imports preserve that age. Committed pending cleanup continues with Redis disabled or unavailable. Unavailable or invalid journal records make the command fail while the SQL phases still run. Retry failed retired cleanup with `filoquent:prune --apply`.

Keep journal data durable and outside cache flushes. Intents have no automatic expiry. Redis loss or eviction before SQL promotion can lose rollback recovery. A remote request that completes after its SQL session dies and after pending cleanup can still leave late bytes. Ledger namespaces include the SQL installation identity and application environment, under Laravel's Redis prefix. Pause writers and collection before cloning or restoring a database. Isolate the clone's SQL namespace, Redis environment or prefix, and storage before resuming. Never import Redis data into another SQL ledger or construct SQL connections from payloads.

Before rolling back the journal migration, stop writers, consolidate outstanding Redis intents, finish pending cleanup and prune retries, and disable the journal. Rollback refuses enabled journal configuration or SQL receipts that still require the journal schema. See [orphan collection](https://atb.dev.br/packages/filoquent/garbage-collection/) for the complete recovery procedure.

Paths are chosen only when creating uploads. Model and field renames preserve existing references, replacement, and deletion when the physical table and primary key remain the same. Changing the base path or default disk affects new uploads only. No path migration is required. Read and retire existing files through their saved manifests and ownership receipts. Keep old disk definitions available while references exist.

See [installation](https://atb.dev.br/packages/filoquent/installation/), [file attributes](https://atb.dev.br/packages/filoquent/files/), [configuration](https://atb.dev.br/packages/filoquent/configuration/), [storage lifecycle](https://atb.dev.br/packages/filoquent/storage/), and [orphan collection](https://atb.dev.br/packages/filoquent/garbage-collection/). Boost is optional development tooling; select `filoquent-development` with `boost:install` and refresh it with `boost:update` when available.
