<?php

declare(strict_types=1);

return PHP_OS_FAMILY === 'Windows'
    ? ['parameters' => ['parallel' => ['maximumNumberOfProcesses' => 1]]]
    : [];
