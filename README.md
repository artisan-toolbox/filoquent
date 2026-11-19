<!-- MAINTAINER:README_HEAD:START -->
<div id="maintainer-title" align="center">
    <img src="logo/filoquent-icon.svg" alt="" width="88">
    <h1>Filoquent</h1>
</div>

<p id="maintainer-badges" align="center">
<a href="https://github.com/artisan-toolbox/filoquent/releases"><img src="https://img.shields.io/badge/version-1.0.0--beta.6-blue?style=flat-square" alt="version"></a>
<a href="https://packagist.org/packages/artisan-toolbox/filoquent"><img src="https://img.shields.io/badge/Packagist-F28D1A?style=flat-square&amp;logo=packagist&amp;logoColor=white" alt="Packagist"></a>
<a href="https://atb.dev.br/packages/filoquent/#requirements"><img src="https://img.shields.io/badge/PHP-%5E8.5-777BB4?style=flat-square" alt="PHP"></a>
<a href="https://atb.dev.br/packages/filoquent/#requirements"><img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square" alt="Laravel"></a>
<a href="https://github.com/artisan-toolbox/filoquent/actions/workflows/tests.yml?query=branch%3A1.x"><img src="https://img.shields.io/github/actions/workflow/status/artisan-toolbox/filoquent/tests.yml?branch=1.x&amp;label=Tests&amp;style=flat-square" alt="Tests"></a>
<a href="https://atb.dev.br/packages/filoquent/"><img src="https://img.shields.io/badge/Docs-Read-blue?style=flat-square" alt="Docs"></a>
</p>

<p id="maintainer-description" align="center">
    File attributes for Eloquent with upload variants and indexed storage ownership.
</p>
<!-- MAINTAINER:README_HEAD:END -->

Filoquent connects Laravel filesystems to Eloquent attributes. Assign an uploaded file, local path, or stream, then save the model. Middleware can produce named variants. An indexed ownership ledger coordinates replacement and permanent deletion.

Filoquent is in beta, and its public API may change before the first stable release. The consolidated migration requires a database that has never run Filoquent migrations. Check the [installation guide](https://atb.dev.br/packages/filoquent/installation/) before upgrading an existing database. For a new installation, run these commands from your Laravel application root.

```bash
composer require 'artisan-toolbox/filoquent:@beta'
php artisan migrate
```

Add a nullable JSON column using `$table->filoquent('attachment')->nullable()` and configure its model:

```php
use ArtisanToolbox\Filoquent\Casts\AsFile;
use ArtisanToolbox\Filoquent\Concerns\HasFiloquent;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFiloquent;

    protected function casts(): array
    {
        return ['attachment' => AsFile::class];
    }
}

$source = storage_path('app/filoquent-example.txt');
file_put_contents($source, 'Hello Filoquent');

$document = new Document;
$document->attachment = $source;
$document->save();

dump($document->attachment->default()->exists());
```

The dump is `true`.

Run the [installation guide](https://atb.dev.br/packages/filoquent/installation/) to create the example table and configure writable storage. File metadata retains its saved disk and path across cast changes. Soft deletes retain files. The garbage collector reconciles committed receipts after manual row or column deletion. Optional Redis recovery tracks interrupted writes. Objects without a SQL receipt or Redis intent remain outside automatic discovery.

Read the [documentation](https://atb.dev.br/packages/filoquent/), [contributing guide](.github/CONTRIBUTING.md), [security policy](.github/SECURITY.md), [changelog](CHANGELOG.md), and [MIT license](LICENSE.md).
