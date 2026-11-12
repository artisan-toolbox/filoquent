# Release notes

## Unreleased

### Added

- Filoquent SVG identity with icon, light, and dark wordmarks in the Artisan Toolbox family.
- Maintainer version discovery through `Filoquent::VERSION` and a generated README head through `WithReadmeHead`.
- Central preview overview, installation, configuration, and development documentation at https://atb.dev.br/packages/filoquent/.
- Versioned contributor standards and a standalone CI consistency check.
- Consumer skill guidance for preview installation, singleton verification, configuration publishing, and current limitations.

### Changed

- Share the PHPStan profile between Composer and Maintainer, auto-register extensions once, and analyze source, configuration, and tests with the official Pest 5 PHPStan adapter.
- Point Larastan at the package configuration directory when it boots through Testbench.
- Exclude installed dependencies, build outputs, and contributor standards from package archives while retaining consumer skills and artwork.
- Align the CI matrix with the declared PHP ^8.5 and Laravel 13 requirements.
- Identify the package as an unreleased preview. File attributes and filesystem synchronization are not implemented.

The source version is `0.1.0-alpha.1`. The `1.x` branch is a development branch, not a published stable release.
