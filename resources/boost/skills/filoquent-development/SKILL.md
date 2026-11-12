---
name: filoquent-development
description: Install or inspect the Filoquent preview in a Laravel application, verify service registration, and publish its configuration. Use for Filoquent-specific adoption requests; file attributes and Eloquent file synchronization are not implemented in this preview.
license: MIT
metadata:
  author: Allan Mariucci Carvalho
---

# Use the Filoquent preview

Inspect `composer show artisan-toolbox/filoquent` and the installed public API before choosing an example. This preview requires PHP ^8.5 and Laravel 13 components. It registers `Filoquent` as a singleton and supports configuration publishing. It does not yet upload files, cast model attributes, define file relationships, or synchronize filesystem changes.

## Install the development source

Use a reviewed development checkout at `packages/filoquent` inside the consuming Laravel application. Run from that application's root:

```bash
composer config repositories.filoquent path packages/filoquent
composer require artisan-toolbox/filoquent:1.x-dev
```

Keep the source checkout out of the application's Git history. The `1.x-dev` branch and `Filoquent::VERSION` value `0.1.0-alpha.1` identify development source, not a stable release. No frontend installation is needed. Laravel discovers the provider automatically unless package discovery is disabled.

## Verify registration

Run inside a Laravel route or command:

```php
use ArtisanToolbox\Filoquent\Filoquent;

$filoquent = app(Filoquent::class);

dump($filoquent === app(Filoquent::class));
```

The dump is `true` because both calls return the same instance. This verifies registration without writing a file or querying a database.

## Publish optional configuration

From the application root, run:

```bash
php artisan vendor:publish --tag=filoquent-config
```

The command creates `config/filoquent.php`. The `filoquent` tag publishes the same file. Its only current key, `placeholder`, defaults to `default` and has no filesystem behavior. No configuration is required for the registration check. Do not invent disk, upload, attribute, or model APIs from the package's intended purpose.

If resolution fails, inspect Composer installation and Laravel package discovery. When discovery is disabled, register `ArtisanToolbox\Filoquent\FiloquentServiceProvider` in `bootstrap/providers.php`.

See the [preview overview](https://atb.dev.br/packages/filoquent/), [installation guide](https://atb.dev.br/packages/filoquent/installation/), and [configuration reference](https://atb.dev.br/packages/filoquent/configuration/).
