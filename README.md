<!-- MAINTAINER:README_HEAD:START -->
<div id="maintainer-title" align="center">
    <img src="logo/filoquent-icon.svg" alt="" width="88">
    <h1>Filoquent</h1>
</div>

<p id="maintainer-badges" align="center">
<a href="https://atb.dev.br/packages/filoquent/"><img src="https://img.shields.io/badge/Status-Preview-FF5947?style=flat-square" alt="Preview"></a>
<a href="https://atb.dev.br/packages/filoquent/#requirements"><img src="https://img.shields.io/badge/PHP-%5E8.5-777BB4?style=flat-square" alt="PHP"></a>
<a href="https://atb.dev.br/packages/filoquent/#requirements"><img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square" alt="Laravel"></a>
<a href="https://github.com/artisan-toolbox/filoquent/actions/workflows/tests.yml?query=branch%3A1.x"><img src="https://img.shields.io/github/actions/workflow/status/artisan-toolbox/filoquent/tests.yml?branch=1.x&amp;label=Tests&amp;style=flat-square" alt="Tests"></a>
<a href="https://atb.dev.br/packages/filoquent/"><img src="https://img.shields.io/badge/Docs-Read-blue?style=flat-square" alt="Docs"></a>
</p>

<p id="maintainer-description" align="center">
    Preview foundations for connecting Laravel filesystem files to Eloquent models.
</p>
<!-- MAINTAINER:README_HEAD:END -->

Filoquent is an early preview for connecting Laravel's filesystem to Eloquent models. The current package provides service registration and configuration publishing. File attributes, uploads, and model synchronization are not implemented.

Requires PHP ^8.5 and Laravel 13. There is no stable release. With a reviewed development checkout at `packages/filoquent` in your Laravel application, run:

```bash
composer config repositories.filoquent path packages/filoquent
composer require artisan-toolbox/filoquent:1.x-dev
```

Verify registration in a Laravel route or command:

```php
use ArtisanToolbox\Filoquent\Filoquent;

$filoquent = app(Filoquent::class);

dump($filoquent === app(Filoquent::class));
```

The dump is `true`: the container returns the same instance on both calls. This check does not store a file.

Read the [documentation](https://atb.dev.br/packages/filoquent/), [contributing guide](.github/CONTRIBUTING.md), [security policy](.github/SECURITY.md), [changelog](CHANGELOG.md), and [MIT license](LICENSE.md).
