<?php

declare(strict_types=1);

use ArtisanToolbox\Filoquent\Support\UploadIntent;
use ArtisanToolbox\Filoquent\Support\UploadLedger;
use Illuminate\Database\Capsule\Manager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$inputPath = $argv[1] ?? throw new RuntimeException('Missing promotion input path.');

/** @var array{connection: array<string, mixed>, payload: string, scope: string, id: string, isolation: string} $input */
$input = json_decode(file_get_contents($inputPath) ?: throw new RuntimeException('Missing promotion input.'), true, flags: JSON_THROW_ON_ERROR);
$capsule = new Manager;
$capsule->addConnection($input['connection']);
$connection = $capsule->getConnection();
$isolation = $input['isolation'];

if (in_array($isolation, ['repeatable read', 'serializable'], true)) {
    $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL '.strtoupper($isolation));
}

$sessionIsolation = static function () use ($connection): string {
    $query = $connection->getDriverName() === 'pgsql'
        ? 'SHOW transaction_isolation'
        : 'SELECT @@SESSION.transaction_isolation AS transaction_isolation';

    return (string) $connection->selectOne($query)?->transaction_isolation;
};
$before = $sessionIsolation();
$connection->beforeExecuting(static function (string $query) use ($inputPath): void {
    if (str_contains(strtolower($query), 'insert into') || str_contains(strtolower($query), 'for update')) {
        file_put_contents($inputPath.'.ready', 'attempting promotion');
    }
});
$intent = UploadIntent::decode($input['payload'], $input['scope'], $input['id']);

try {
    UploadLedger::promote($connection, $intent);
    file_put_contents($inputPath.'.result', json_encode(['result' => 'promoted', 'before' => $before, 'after' => $sessionIsolation()], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($inputPath.'.result', json_encode(['result' => $exception::class, 'code' => $exception->getCode()], JSON_THROW_ON_ERROR));
    exit(1);
}
