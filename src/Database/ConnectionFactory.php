<?php

declare(strict_types=1);

namespace PYH\Database;

use InvalidArgumentException;
use PDO;

final class ConnectionFactory
{
    /** @param array<string, mixed> $config */
    public static function create(array $config): PDO
    {
        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new InvalidArgumentException("Missing database configuration: {$key}");
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'],
        );

        $pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
