# Changelog

## [1.0.0-alpha.1] - 2026-11-13

### Features

- **Expand CI test workflow for SQLite and native DBs** (`923edd1`)
  Extends the CI test matrix/workflow to cover additional database scenarios, specifically SQLite and native DBs. This improves confidence that the project behaves correctly across common database configurations and catches DB-specific regressions earlier. User impact is mostly for developers and maintainers (more robust validation before merges); end users benefit from higher-quality releases. Compatibility/migration: no changes required for consumers, but contributors should expect longer/more comprehensive CI runs due to added DB coverage.

- **Initial commit** (`d9b518f`)
  Adds the initial repository implementation. This establishes the baseline project structure and functionality that later documentation/CI fixes build upon. User impact: foundational—everything in later changes depends on this starting point. Compatibility/migration: none applicable beyond beginning from the initial state.

### Fixes

- **Limit phpstan parallelism on Windows with shared platform profile** (`6efafe2`)
  Adjusts phpstan configuration to limit parallelism on Windows by using a shared platform profile. This prevents platform-specific resource contention or configuration issues that can cause phpstan failures or instability in Windows-based CI runs. User impact is indirect: it improves developer/CI reliability for static analysis without changing production runtime code. Compatibility/migration: none for end users; contributors benefit when running phpstan locally or in Windows CI.

- **Allow maintainer checks to validate new source version** (`7526894`)
  Includes the changes introduced by commit 7526894: fix: allow maintainer checks to validate new source version.

### Maintenance

- **Add AI and documentation skills standards** (`a021d5c`)
  Introduces/updates repository standards related to AI/documentation skills, adding documentation skill guidance and related standards content (e.g., skill definition and authoring/review workflows). This matters because it standardizes how documentation and agent skills are written, verified, and kept consistent with actual package/API behavior. User impact is indirect: end users get better documentation consistency over time; maintainers/agents gain clearer process requirements. Compatibility/migration: none for runtime users; contributors/agent workflows may need to follow the new standards files when editing documentation or skill instructions.

- **Use 1.x for Filoquent development workflows** (`77997e2`)
  Changes the Filoquent development workflow to use the 1.x line. This is typically aimed at aligning dev dependencies/compatibility with the intended major version during development, reducing mismatch risk. User impact is for developers/maintainers working on the project (dependency resolution/compatibility); it may affect test/dev setup behavior but does not describe end-user runtime API changes. Compatibility/migration: developers may need to update local/dev dependency constraints or ensure their environment aligns with the 1.x workflow.

# Release notes

## Unreleased

### Added

- Read-only `filoquent:health` checks for files referenced by active ownership receipts, including variants, historical names, and soft-deleted owners. The default scans the full starting UUID range in bounded batches; optional `--limit`, `--after`, and `--connection` controls report their coverage explicitly.
- Automatic live health batch progress in supported interactive terminals, plain noninteractive summaries, and cooperative signal interruption with a continuation cursor.
- Application start and completion logs for health, garbage collection, and prune, independently of console verbosity. Summaries include bounded diagnostics, counters, coverage, duration, and exit status. Start logging failures abort work; completion logging failures return nonzero without repeating applied operations.
- Background scheduler recipes for health, garbage collection, and prune in the central documentation and consumer skill.

- Optional Redis upload recovery through `FILOQUENT_REDIS_DB`, with a private connection derived from Laravel's configured standalone Redis server.
- Transaction-private writing reservations and a persistent SQL ledger namespace, installed by a forward migration that permits provisional nullable owner keys.
- Bounded Redis intent import and due SQL pending cleanup in `filoquent:gc`. Each phase has its own `--limit` budget, and pending cleanup starts 24 hours after the first SQL promotion and continues without Redis. Root transaction commits acknowledge intents without making successful saves fail when Redis is unavailable.

- `FileNaming::Uuid` and `FileNaming::Original` enum cases for explicit filename policy configuration.

- Eloquent file attributes through `AsFile`, `HasFiloquent`, and the `Blueprint::filoquent()` JSON column macro.
- Uploaded file, absolute local path, and readable stream assignment with owned temporary snapshots and MIME-derived extensions.
- Immutable `Assets` and `Asset` access, default and named variants, URL generation, temporary URLs, and canonical JSON metadata.
- UUIDv7 upload directories, original or UUID filenames, custom stems, fixed model directories, and container-resolved upload middleware.
- Transactional indexed upload ownership, stale-write checks, soft-delete retention, and retryable exact-path retirement cleanup.
- Creation-only model FQN and attribute hashing; persisted receipt ownership and deletion remain valid after model, field, or base-path changes without recomputing the hash.
- Preview-first `filoquent:prune` with bounded cursor traversal for committed retirements.
- Preview-first `filoquent:gc` for removed columns and direct row deletions, with indexed rotation, renamed-column detection, soft-delete retention, and a minimum 24-hour absence confirmation.
- Behavior and access-path coverage for 100 and 1,000,000 rows, without a production throughput claim.

- Filoquent SVG identity with icon, light, and dark wordmarks in the Artisan Toolbox family.
- Maintainer version discovery through `Filoquent::VERSION` and a generated README head through `WithReadmeHead`.
- Central preview overview, installation, configuration, and development documentation at https://atb.dev.br/packages/filoquent/.
- Versioned contributor standards and a standalone CI consistency check.
- Consumer skill guidance for preview installation, singleton verification, configuration publishing, and current limitations.

### Changed

- Update the README and consumer skill for Beta status and the published `1.0.0-alpha.1` release, with installation from Packagist without a local checkout. Preserve version identifiers for the maintainer to set.
- Run Rector in the Composer validation suite and every Windows/Linux dependency lane. Keep the declared Laravel 13.0 compatibility and explicit-null disk fallback. Install the official Mockery PHPStan extension and constrain development ParaTest to the compatible 7.27 series.
- Require PHPStan 2.3.5, Rector 2.8.4 with its Laravel extension 2.7.3, and the Pest type-coverage plugin 5.1 for compatible analysis and PHPUnit 13 type coverage in the lowest dependency lane.
- Run the full database behavior suite on MySQL 8.4 and PostgreSQL 18 in both lowest and stable dependency CI lanes, with private Redis recovery services and isolated per-worker databases. Preserve SQLite on Ubuntu and Windows, run native Redis checks in both dependency lanes, and retain explicit SQLite-only fixtures.

- Reject valueless maintenance bounds, empty or `0` connection values, and explicit reader connection selectors before health, garbage collection, or prune work. Valid writer connections keep their existing behavior.

- Declare Illuminate Console and Log ^13.0 and Laravel Prompts ^0.3.25 as direct runtime dependencies for the maintenance commands and their reporting.

- Require `FileNaming` cases in `AsFile::using(naming: ...)` instead of strings. Replace string arguments with the corresponding enum case. The cast still serializes `uuid` and `original`; existing file metadata needs no migration. Invalid serialized policies now fail during cast resolution before uploads.

- Share the PHPStan profile between Composer and Maintainer, auto-register extensions once, and analyze source, configuration, and tests with the official Pest 5 PHPStan adapter.
- Point Larastan at the package configuration directory when it boots through Testbench.
- Exclude installed dependencies, build outputs, and contributor standards from package archives while retaining consumer skills and artwork.
- Align the CI matrix with the declared PHP ^8.5 and Laravel 13 requirements.
- Keep the source identified as an unreleased preview. Receipt reconciliation handles direct row and column deletions. Storage objects without SQL receipts or Redis intents remain preserved rather than deleted without ownership proof.

### Fixed

- Allow Maintainer release checks to validate a newly selected source version without a README identity test requiring the previous version number. Keep semantic-version validation in its dedicated test.
- Run PHPStan with one process on Windows to avoid concurrent Larastan writes to the shared Testbench bootstrap files. Keep full level 7 analysis, both dependency lanes, and default parallelism on other systems.
- Preserve database-normalized primary keys, including PostgreSQL UUIDs, for immediate replacement and deletion. Reconcile unresolved JSON manifests through engine-compatible comparisons on MySQL and PostgreSQL.
- Persist schema-qualified PostgreSQL owner routes independently of later search-path changes. Quarantine older unqualified active receipts until a qualified destination proves every manifest, and refuse removal when another relation still occupies the source name.
- Select available owner routes through a durable bounded cursor when missing-table groups are blocked, avoiding scans across blocked uploads on MySQL and PostgreSQL.
- Record raw physical owner keys rather than accessor values, preventing collection of files still referenced by a model with a formatted primary key.
- Preserve committed model state when application callbacks fail after commit, while restoring snapshots and releasing failed root transactions for retry across supported Laravel 13 versions. Preserve a new transaction opened by a post-commit callback.
- Keep retired receipts retryable while local volumes or composite disk roots are unavailable. Cleanup resumes after storage recovery without forgetting the original files.
- Store UTC microsecond cleanup dates without MySQL session time-zone conversion, preserving the full 24-hour recovery grace period.
- Make `Asset::exists()` check for a file rather than accepting a directory at its path.
- Persist missing owner tables as blocked groups, skip their indexed receipt ranges, and emit one grouped error per table per UTC day. Preserve the block when the old name reappears.
- Add `filoquent:resolve-pending` for read-only pending listings, atomic verified table remapping, and explicit removal authorization with a new 24-hour cleanup grace period. Keep original Redis intent routes recognizable after successive table renames.

### Removed

- Remove `filoquent:move-subpath` from the unreleased preview. Paths are chosen at upload creation, and saved manifests and ownership receipts keep existing files trackable after model, field, or configuration changes. Remove any scheduled invocations of this command. Existing files and metadata need no migration.

The source version is `0.1.0-alpha.1`. The `1.x` branch is a development branch, not a published stable release.
