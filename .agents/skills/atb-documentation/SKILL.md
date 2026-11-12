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

## Keep changes together

When behavior changes, review affected guides, usage skills, examples, tests, and changelogs in the same task.
Update the owning source rather than generated consumer copies.
Record why reviewed guidance remains unchanged when no edit is needed.
Preserve existing links, heading anchors, examples, and documented limitations unless the task explicitly changes them.

Run repository-defined content checks and relevant tests, formatting, analysis, and builds.
Report actual results and blockers. Metadata validation and a refreshed snapshot do not establish that a reader can complete a procedure.
Use the project's dependency refresh policy without relaxing compatibility or presenting a local checkout as a released version.
