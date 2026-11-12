---
name: atb-package-development
description: Implement, fix, or review Artisan Toolbox Laravel packages using the shared workflow for public contracts, framework integration, behavior tests, compatibility, usage skills, documentation, and validation. Use for package development rather than unrelated application features.
license: MIT
---

# Develop Artisan Toolbox packages

Start with the package's `AGENTS.md`, current uncommitted work, manifests, implementation, tests, and public documentation.
Use the same workflow across packages while preserving each package's supported environment and architecture.

## Establish the contract

Identify the requested observable behavior and affected public APIs, defaults, commands, configuration, routes, resources, and consuming packages.
Inspect Core before adding capability already shared across packages.
Keep package-specific behavior in its owner. Move functionality to Core only when a concrete shared need justifies it, without introducing circular dependencies.
Preserve compatibility unless the task explicitly authorizes a breaking change.
For bug fixes, trace the symptom to its cause and add a regression test that fails without the fix.

## Implement and test

Use Laravel-native integration, the package's declared language versions, and its existing formatter and static analysis rules.
Prefer focused changes and explicit types. Add PHPDoc for contracts that native types cannot describe clearly.
Test public behavior, relevant failures, edge cases, and framework integration with the package's existing tools.
Run the smallest relevant tests during development and the complete configured validation suite before completion.
Do not weaken tests, skip checks, or mock away the behavior under test.

When available, use the repository's `package-scaffold`, `package-testing`, `package-compatibility`, and `package-release` skills for their respective tasks.
Inspect their instructions rather than assuming that every package has identical internals.
If those skills are unavailable, follow the owning repository's documented commands and conventions.
Do not install additional tooling just to satisfy an optional reference.

## Maintain the adoption path

Use `atb-documentation` for documentation work. It is included in this standards bundle.
Review `resources/boost/skills/` against changes to public APIs, installation, configuration, compatibility, errors, and recommended usage.
Use `package-generate-skill` when available to maintain consumer guidance from implementation and documentation together.
Keep consumer skills focused on adopting public APIs, without imposing this repository's contributor workflow on consuming applications.

Update affected central guides, migrated pages, changelogs, and minimal README examples in the same task.
When the workspace integration application is available, use it for additional realistic checks while retaining package-owned automated coverage.
For Core changes, identify and validate known consuming packages.

## Deliver evidence

Inspect the final diff for unrelated changes, altered public contracts, and generated files that need regeneration.
Report what changed, why, affected repositories and versions, tests and checks actually run, and remaining blockers.
Record unchanged skill or documentation guidance when a review confirms it remains accurate.
Use repository-defined release procedures only when release work is requested. Do not publish, deploy, or send messages merely because implementation is complete.
