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

## Identity and preview status

- `ArtisanToolbox\Filoquent\Filoquent` owns `VERSION` and implements Maintainer's `Versionable` and `WithReadmeHead` contracts. After changing `readmeHead()`, run `php vendor/bin/maintainer readme:update --no-interaction`.
- The source version is `0.1.0-alpha.1`; the `1.x` branch is development source, not evidence of a stable release. Do not add a fixed Composer `version` or fabricate a release tag.
- The current public behavior is singleton registration and optional configuration publishing. File casts, uploads, model relationships, and filesystem synchronization are not implemented.
- Own the SVG artwork in `logo/` and keep the matching copies in the central documentation application synchronized.
- The declared compatibility is PHP ^8.5 and Laravel 13. Preserve Linux, Windows, lowest, and stable CI lanes within those constraints.
- Keep the shared PHPStan profile in `phpstan.neon.dist`; `phpstan.neon` includes that profile. Analyze source, configuration, and tests. The Composer extension installer registers Larastan, Carbon, and the Pest 5 PHPStan adapter, so do not include those extensions manually. Keep `configDirectories` pointed at this package's `config` directory when Larastan uses Testbench.
