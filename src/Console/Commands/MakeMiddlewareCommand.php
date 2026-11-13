<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;

#[Description('Create a Filoquent upload middleware class')]
#[Signature('filoquent:make-middleware
                            {name : The name of the upload middleware}
                            {--force : Overwrite an existing middleware class}')]
final class MakeMiddlewareCommand extends GeneratorCommand
{
    protected $type = 'Filoquent middleware';

    protected function getStub(): string
    {
        return __DIR__.'/../../../resources/stubs/middleware.stub';
    }

    /** @param string $rootNamespace */
    protected function getDefaultNamespace(mixed $rootNamespace): string
    {
        return $rootNamespace.'\\Files';
    }
}
