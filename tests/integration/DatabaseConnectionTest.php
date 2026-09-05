<?php

declare(strict_types=1);

namespace PYH\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PYH\Database\ConnectionFactory;

final class DatabaseConnectionTest extends TestCase
{
    public function testMySqlConnectionUsesVersionEight(): void
    {
        if (getenv('TEST_DB_DATABASE') === false) {
            self::markTestSkipped('Set isolated TEST_DB_* variables to run database integration tests.');
        }

        $pdo = ConnectionFactory::create([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'database' => getenv('TEST_DB_DATABASE'),
            'username' => getenv('TEST_DB_USERNAME') ?: '',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
        ]);

        self::assertMatchesRegularExpression('/^8\./', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $timeZoneQuery = $pdo->query('SELECT @@session.time_zone');
        self::assertNotFalse($timeZoneQuery);
        self::assertSame('+00:00', $timeZoneQuery->fetchColumn());
    }
}
