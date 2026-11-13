# Changelog

## [1.0.0-beta.3] - 2026-11-13

### Features

- **Add filoquent:status command and document release status** (`184e3a6`)
  Introduces a new Artisan command, `filoquent:status`, and updates documentation to reflect the 1.0.0-beta.2 release status/versioning context for beta users.

Functional additions (user impact):
- Adds `php artisan filoquent:status` to report the current Filoquent version and operational health across:
  - Filesystem accessibility via `filoquent.filesystem` / `filoquent.base_path`.
  - Database upload ledger health from the `filoquent_uploads` table, including per-state upload counts. The `--connection` option selects the writer connection used for reading ledger data.
  - Redis upload journal availability, including a Redis connectivity probe and journal intent counts (scoped via SQL journal scope for the selected DB connection).
- The command is read-only: it does not modify stored data, files, the database ledger table, or Redis journal contents.
- Exit codes and reliability: the command returns `SUCCESS` only when all relevant checks (filesystem, ledger, and Redis journal) succeed; otherwise it returns `FAILURE`. When checks are unavailable it emits issue keys like `status_filesystem_unavailable`, `status_ledger_unavailable`, `status_journal_unavailable`, and `status_journal_count_unavailable`, along with suggested remediation actions.
- Adds supporting implementation details and behavior changes required by the command:
  - Registers the command in `FiloquentServiceProvider`.
  - Enhances `CommandRun::context()` so mode metadata is correctly set for `filoquent:status` and so `limit` is only set when `--limit` is explicitly provided (prevents unintended mode/limit side effects for status reporting and downstream logic keyed off context).
  - Extends `RedisUploadJournal` with `ping()` (Redis connectivity verification) and `count(scope)` (journal intent cardinality via `zcard`) with strict validation.

Testing coverage (why it matters):
- Adds integration coverage ensuring the command:
  - Uses the private Redis journal connection and counts only intents for the selected ledger scope.
  - Does not mutate local storage files, does not add rows to `filoquent_uploads`, does not leak Redis marker state, and keeps transactional behavior stable.
- Adds a dedicated `StatusCommandTest` suite verifying output structure/fields, non-mutation guarantees, graceful handling when storage backends or ledger rows are unavailable, credential non-disclosure in Redis reporting, correct `--connection` behavior (including rejection of invalid names), `--silent` behavior, and remote storage accessibility via read probes.

Documentation and compatibility/migration notes:
- Updates `AGENTS.md` and `CHANGELOG.md` restructuring to document this capability under Added and to clarify beta/source identity. Wording is adjusted so the beta source explicitly references `1.0.0-beta.2` (and distinguishes pre-`1.0.0-beta.2` historical development).
- Updates `README.md` and skill docs to target `1.0.0-beta.2` instead of `1.0.0-beta.1`.
- Updates `resources/boost/skills/filoquent-development/SKILL.md` to document `filoquent:status`, including discovery (`php artisan list filoquent`), required migration context, `--connection=uploads` usage, what the report includes/excludes, how to interpret counts, and operational expectations (including that credentials and adapter exception text are omitted).

Compatibility considerations:
- API-level compatibility is not stated as changed beyond the `filoquent:status` addition and the internal context-mode/limit handling used by the command. Users should primarily expect a new read-only diagnostic capability.
- Install/upgrade guidance is updated to reference beta `1.0.0-beta.2`, and this release’s documentation emphasizes that beta artifacts may still change before stable release.

## Unreleased

### Added

- `filoquent:status` displays a Laravel-style two-column overview of filesystem access, effective private Redis connection parameters and health, scoped journal backlog, and current upload ledger counts. It supports `--connection`, preserves stored data, omits credentials and adapter exception text, and returns a nonzero exit code for unavailable checks.

### Documentation

- Align installation, configuration upgrade guidance, and consumer skill instructions with the published `1.0.0-beta.2` release.

## [1.0.0-beta.2] - 2026-11-13

### Added

- `php artisan filoquent:make-middleware NamedOriginal` generates a ready-to-use upload middleware under the application's `Files` namespace. Nested names and fully qualified application class names use Laravel's generator conventions. Existing files remain unchanged unless `--force` is supplied. The generated middleware returns the original pending asset and can be selected through `AsFile::using(middleware: ...)` or a native `File` attribute.
- Optional repeatable native `#[File('attachment', ...)]` attributes on Eloquent model classes using `HasFiloquent`. Attributes register the existing `AsFile` cast and accept the same naming, filename, and middleware options. Explicit casts take priority; children can replace inherited declarations. Duplicate declarations within one class and invalid column names fail before persistence.
- String-backed `UploadState`, `OwnerTableState`, and `OwnerTableResolution` enums for persisted upload states, owner-table states, and pending-issue resolutions. Runtime SQL reads and writes use their exact existing backing values. Existing rows, manifests, indexes, migrations, cleanup authority, and grace periods remain compatible; no schema migration is needed for this update.

### Changed

- Publish source identity `1.0.0-beta.2`. The central guides and consumer skill document the native attribute alternative and its precedence.

## [1.0.0-beta.1] - 2026-11-13

### Maintenance

- **Update beta release documentation and README installation steps** (`9681dd4`)
  Updates the 1.0.0-beta.1 release documentation to reflect the new Beta status and the correct prerelease version to install.

Key changes:
- CHANGELOG.md: Adds/updates a “Changed” entry documenting the documentation updates, including how to install the package from Packagist (without requiring a local checkout) and noting that maintainers may need to preserve version identifiers for later setup.
- README.md: 
  - Updates the maintainer/badge status from “Preview” to “Beta” and refines the head description text from “Preview file attributes…” to “File attributes…”.
  - Revises the setup/requirements section to state that Filoquent version 1.0.0-alpha.1 is available on Packagist under the beta channel and warns that APIs may change before the stable release.
  - Changes Composer installation guidance from a local path repository + dev requirement (e.g., using a `packages/filoquent` path repo and `artisan-toolbox/filoquent:1.x-dev`) to installing a specific version via Packagist: `composer require artisan-toolbox/filoquent:1.0.0-alpha.1`.
- Skill documentation (resources/boost/skills/filoquent-development/SKILL.md): Replaces guidance aimed at an unpublished dev preview (including PHP ^8.5/Laravel 13 and identity `0.1.0-alpha.1`) with instructions for the published prerelease and the same beta/API-change warning. It also removes custom Composer path repository setup and switches to a direct versioned Composer require (`composer require artisan-toolbox/filoquent:1.0.0-alpha.1`).
- Tests: Updates `tests/Unit/ReadmeHeadTest.php` expectations so automated checks for README head metadata match the updated description and badge alt text/image metadata (Preview → Beta).

Why it matters / user impact:
- This is guidance-only (no production behavior changes shown), but it directly affects how users install the prerelease: it switches from a local dev checkout setup to requiring an explicit prerelease version from Packagist.
- Users relying on the old “Preview” instructions or custom Composer repository configuration will need to follow the new direct `composer require` workflow.

Compatibility / migration notes:
- No runtime/API compatibility changes are claimed in this diff; the main migration consideration is installation configuration: moving away from local checkout/path repositories toward version-pinned Composer requirements for the prerelease.

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

## Historical development notes

These notes record development before `1.0.0-beta.2` was published. Preview labels and version references below describe that earlier source state. Use the versioned entries above and the current installation guides for release guidance.

### Added

- Environment variables for the filesystem disk, upload base path, and base Redis connection. All active configuration values now have environment mappings, with Laravel-style comments explaining their purpose and limits.
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

- Remove the unused `placeholder` configuration option. Remove it from previously published configuration files; it had no effect on package behavior. Existing active defaults remain unchanged.
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

Version `1.0.0-beta.2` is the published beta. The `1.x` branch remains a development branch and alone does not establish that a newer version has been published.
