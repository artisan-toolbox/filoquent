<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Enums\OwnerTableResolution;
use ArtisanToolbox\Filoquent\Enums\OwnerTableState;
use ArtisanToolbox\Filoquent\Enums\UploadState;

it('keeps persisted lifecycle vocabularies compatible with existing SQL strings', function (): void {
    expect(array_column(UploadState::cases(), 'value', 'name'))->toBe([
        'Writing' => 'writing',
        'Active' => 'active',
        'Pending' => 'pending',
        'Retired' => 'retired',
    ])->and(array_column(OwnerTableState::cases(), 'value', 'name'))->toBe([
        'Ready' => 'ready',
        'Pending' => 'pending',
    ])->and(array_column(OwnerTableResolution::cases(), 'value', 'name'))->toBe([
        'Renamed' => 'renamed',
        'Removed' => 'removed',
    ]);
});
