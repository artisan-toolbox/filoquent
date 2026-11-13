<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Support;

use RuntimeException;

/** @internal Rolls back listener side effects when Eloquent returns a veto. */
final class OperationCancelled extends RuntimeException {}
