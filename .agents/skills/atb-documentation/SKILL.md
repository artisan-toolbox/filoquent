---
name: atb-documentation
description: Write, review, or update Artisan Toolbox package guides, API documentation, READMEs, changelogs, and contributor documentation. Apply shared writing and maintenance standards when documentation or documented package behavior changes.
license: MIT
---

# Write Artisan Toolbox documentation

Read the repository's instructions and affected implementation, tests, and existing guides before editing.
Inspect the installed package version separately from the version documented for readers.
Preserve unrelated work and existing public contracts.

## Choose the reader's task

Identify the audience, starting environment, and result the reader needs.
Use the context already available. Ask only when missing information changes the procedure or its correctness.
Choose one Diataxis mode for each guide. Use [page patterns](references/page-patterns.md) when creating or restructuring content.

For package overviews, state purpose, stability, documented version, requirements, installation, a minimal working example, and its expected result.
Provide a dedicated Installation guide. Put prerequisites before the steps that use them.
Keep a package README concise and link to its package page at `https://atb.dev.br` for detailed guidance.

## Maintain package README badges

For a published package, start the badge row in `readmeHead()` with `Badge::releaseVersion()` linked to the package's GitHub Releases page.
Follow it with a static Packagist badge linked to `https://packagist.org/packages/artisan-toolbox/<package>`.
Use `Badge::make()` with the image URL `https://img.shields.io/badge/Packagist-F28D1A?style=flat-square&logo=packagist&logoColor=white` and the alt text `Packagist`.
Before the first release, use an in-development badge.
Follow the [README badge policy](https://atb.dev.br/docs/project/documentation-policy#readme-badges) for the remaining badges and the header regeneration command.

Keep numeric versions in the badges. Link to the installation guide for compatibility ranges and version constraints.
Use a package-scoped Composer stability flag such as `@beta` when installation requires allowing a prerelease.
Verify that changed installation commands resolve in a compatible application and that Maintainer regenerates the expected header.

## Write and verify

Apply [the writing standard](references/writing.md) to prose and examples.
Use public package APIs. Check names, defaults, configuration, errors, and compatibility against the implementation and behavior tests.
Define example inputs and execution directories. Install Composer dependencies before frontend dependencies.
Label unpublished APIs and local development branches as previews.
Run changed examples and compare their actual observable result with the documented result.

For the documentation application, read `documentation/authoring.md` and reuse its shared components and registered navigation.
The workspace `docs` repository is the only documentation source and application. Implement and maintain all documentation there.
`docs_old` has been retired and no longer exists. Do not require, recreate, or synchronize that checkout.
Use current Vue content and package behavior for authoring and verification. Historical migration records and fingerprints remain provenance, not a completion gate.

## Reuse documentation UI

For Vue UI in the `docs` application, inspect the installed `@artisan-toolbox/front-awesome` public exports and supported props, events, and slots before choosing components.
Use its Web Awesome Vue wrappers wherever a component fits the required behavior. Prefer these wrappers over handmade controls, native control replacements, or parallel implementations.
Import through public package entry points. Never copy package internals into the application.

Compose existing meaningful domain wrappers with these components. Preserve semantic HTML, Inertia navigation, and established keyboard, focus, and accessibility ownership.
Ordinary layout elements, lists, and links do not need a component replacement merely because a wrapper exists.
When a required behavior exceeds the public API or a substitution would break these contracts, record the concrete reason in the task or the owning architecture note.

## Keep changes together

When behavior changes, review affected guides, usage skills, examples, tests, and changelogs in the same task.
Update the owning source rather than generated consumer copies.
Record why reviewed guidance remains unchanged when no edit is needed.
Preserve existing links, heading anchors, examples, and documented limitations unless the task explicitly changes them.

Run repository-defined content checks and relevant tests, formatting, analysis, and builds.
Report actual results and blockers. Metadata validation and a refreshed snapshot do not establish that a reader can complete a procedure.
Use the project's dependency refresh policy without relaxing compatibility or presenting a local checkout as a released version.
