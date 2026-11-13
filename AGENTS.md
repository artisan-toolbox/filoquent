# Filoquent

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `artisan-toolbox/filoquent`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Maintainer static analysis: `php vendor/bin/maintainer quality:check --tool=phpstan --no-interaction`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Shared contributor standards

- Use `atb-package-development` for package implementation and `atb-documentation` for public guides, README content, and changelogs. Installed standards are pinned to version 1.0.6.
- Run `node .ai/sync.mjs --check` before completing a change. Update generated `.ai` and `.agents` files only through the synchronizer documented in `https://atb.dev.br/docs/project/documentation-policy` and the workspace `docs/documentation/agent-standards.md` guide.
- Keep package consumer guidance in `resources/boost/skills/filoquent-development/SKILL.md`. It must describe implemented public APIs, not the intended future file-handling API.

## Identity and release status

- `ArtisanToolbox\Filoquent\Filoquent` owns `VERSION` and implements Maintainer's `Versionable` and `WithReadmeHead` contracts. After changing `readmeHead()`, run `php vendor/bin/maintainer readme:update --no-interaction`.
- The source version is `1.0.0-beta.2`, matching the published beta. The `1.x` branch remains development source and alone is not evidence that a newer version has been published. Do not add a fixed Composer `version` or fabricate a release tag.
- The beta implements AsFile casts, the HasFiloquent lifecycle trait, immutable assets, middleware variants, a JSON column macro, and an indexed upload ownership ledger. Keep deletion limited to exact committed retirements. The garbage collector reconciles committed receipts against physical owner rows in bounded indexed batches with delayed confirmation. The optional Redis journal registers validated upload intents before remote writes, using transaction-private SQL writing reservations to exclude competing pending imports. Garbage collection promotes intents into SQL pending receipts and starts their 24-hour cleanup grace period at promotion. Preserve disabled saves without Redis resolution. Unknown storage objects without SQL receipts or Redis intents remain outside automatic discovery. Preserve existing paths across configuration changes and never infer deletion authority from a filesystem listing.
- Own the SVG artwork in `logo/` and keep the matching copies in the central documentation application synchronized.
- The declared compatibility is PHP ^8.5 and Laravel 13. Preserve Linux, Windows, lowest, and stable CI lanes within those constraints. Run the complete database behavior suite on SQLite, MySQL 8.4, and PostgreSQL 18 in both dependency lanes. Preserve SQLite on Ubuntu and Windows, native Redis recovery in both lanes, and private Redis services in the MySQL and PostgreSQL jobs. SQLite-specific fixtures must select SQLite explicitly rather than being reported as native engine coverage.
- Keep the shared PHPStan profile in `phpstan.neon.dist`; `phpstan.neon` includes that profile. Analyze source, configuration, and tests. The Composer extension installer registers Larastan, Carbon, and the Pest 5 PHPStan adapter, so do not include those extensions manually. Keep `configDirectories` pointed at this package's `config` directory when Larastan uses Testbench.
- Preserve the shared `phpstan-platform.php` include. Windows analysis uses one process because Larastan boots the same Testbench skeleton in each worker. Other systems keep PHPStan's default parallelism. Do not replace this with skipped analysis or reduced rules.

## Maintenance command contracts

- `filoquent:health` is read-only. Preserve every owner, ledger field, and storage object. Traverse active receipts on the writer connection in indexed UUID pages of at most 500, with a fixed starting upper bound and no global count.
- Reuse physical ownership reconciliation for historical class and field names and soft-deleted rows. Probe file-specific existence outside SQL locks. Before publishing a negative probe, confirm current receipt identity and exact owner linkage in a short owner-before-receipt SQL-only transaction. Ignore GC observation timestamp changes.
- Keep GC and prune deletion authority, phase ordering, retry behavior, and exception contracts unchanged. Their logging must not add database queries solely for reporting or imply a global health audit.
- Every invocation owns a fresh report. Maintenance commands log start and completion independently of output verbosity. Preserve silent console output even if the logger throws. Abort before work if the start log fails, return failure after completion logging failure, and preserve a primary thrown exception.
- Keep diagnostics bounded to 10 samples and 500 bytes per field. Never log adapter exception dumps, credentials, or signed URLs. Verify the real interactive terminal, noninteractive output, silent mode, and cooperative interruption when changing presentation.
- Coordinate managed writes and missing-table resolutions through canonical physical owner routes. Preserve shared gate locks before owner and receipt locks. Pending groups remain blocked through table-name reuse and require explicit operator resolution.
- Aggregate missing-table uploads through covering indexes and emit one grouped error per physical table per UTC day. Skip blocked receipt ranges rather than traversing every affected upload. Routine start and completion information remains per invocation.
- Keep `filoquent:resolve-pending` read-only by default. Verify every target owner manifest before atomic remapping, preserve original Redis intent routes, and start a fresh 24-hour grace period after explicit removal authorization. Never perform table DDL or immediate storage deletion in this command.

## Native database test isolation

- `FILOQUENT_TEST_DATABASE_DRIVER` selects `sqlite`, `mysql`, or `pgsql`; SQLite remains the default. Native engines require explicit test-only connection settings and a `filoquent_` base database name. Do not silently fall back to SQLite after a requested native connection fails.
- Use disposable SQL endpoints whose credentials can create and drop databases. Each PHP worker owns a randomly named database. Reset only that owned database between tests and drop only that owned database at shutdown. Never accept an application database or shared service as a test fixture.
- Run the full native-engine Pest suites with two parallel processes, not only a lifecycle group. Preserve all quality checks and fail CI for a failed engine or dependency lane. Intrinsic SQLite fixtures remain explicit and documented.
