<?php

declare(strict_types=1);

namespace ArtisanToolbox\Filoquent\Tests\Support;

use InvalidArgumentException;
use PDO;

final class TestDatabase
{
    /** @var array<string, array<string, mixed>> */
    private static array $configurations = [];

    /** @return array<string, mixed>|null */
    public static function configuration(?string $driver = null): ?array
    {
        $driver ??= getenv('FILOQUENT_TEST_DATABASE_DRIVER') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return null;
        }

        throw_unless(in_array($driver, ['mysql', 'pgsql'], true), InvalidArgumentException::class, 'FILOQUENT_TEST_DATABASE_DRIVER must be sqlite, mysql, or pgsql.');

        if (isset(self::$configurations[$driver])) {
            return self::$configurations[$driver];
        }

        $prefix = 'FILOQUENT_TEST_'.strtoupper($driver).'_';
        $base = self::required($prefix.'DATABASE');

        throw_unless(preg_match('/^filoquent_[a-z0-9_]+$/D', $base), InvalidArgumentException::class, 'The disposable database name must start with filoquent_ and contain only lowercase letters, digits, and underscores.');
        $username = self::required($prefix.'USERNAME');
        $socket = getenv($prefix.'SOCKET') ?: '';
        $host = $socket !== '' ? $socket : self::required($prefix.'HOST');
        $port = getenv($prefix.'PORT') ?: ($driver === 'mysql' ? '3306' : '5432');
        $password = getenv($prefix.'PASSWORD') ?: '';
        $database = substr($base, 0, 32).'_'.bin2hex(random_bytes(8));
        $endpoint = $driver === 'mysql' && $socket !== '' ? 'unix_socket='.$socket : 'host='.$host.';port='.$port;
        $admin = new PDO($driver.':'.$endpoint.';dbname='.$base, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $quote = $driver === 'mysql' ? '`' : '"';
        $admin->exec('CREATE DATABASE '.$quote.$database.$quote);
        register_shutdown_function(static function () use ($admin, $database, $quote, $driver): void {
            if ($driver === 'pgsql') {
                $admin->exec('DROP DATABASE '.$quote.$database.$quote.' WITH (FORCE)');
            } else {
                $admin->exec('DROP DATABASE '.$quote.$database.$quote);
            }
        });

        return self::$configurations[$driver] = [
            'driver' => $driver,
            'host' => $driver === 'mysql' && $socket !== '' ? 'localhost' : $host,
            'port' => $port,
            'unix_socket' => $driver === 'mysql' ? $socket : '',
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'disable',
            'timezone' => $driver === 'mysql' ? '+00:00' : 'UTC',
        ];
    }

    private static function required(string $name): string
    {
        $value = getenv($name);

        throw_if($value === false || $value === '', InvalidArgumentException::class, $name.' must explicitly identify a disposable test service.');

        return $value;
    }
}
