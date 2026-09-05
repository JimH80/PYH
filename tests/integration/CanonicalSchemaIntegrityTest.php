<?php

declare(strict_types=1);

namespace PYH\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PYH\Database\ConnectionFactory;
use PYH\Database\TestDatabasePolicy;

final class CanonicalSchemaIntegrityTest extends TestCase
{
    public function testCanonicalPhaseTwoShapeAndForeignKeys(): void
    {
        $environment = getenv('APP_ENV');
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $database = getenv('TEST_DB_DATABASE');
        if (!is_string($environment) || !is_string($database)) {
            self::markTestSkipped('Phase 2 integration database is not configured.');
        }
        TestDatabasePolicy::assertDisposable($environment, $host, $database);
        $pdo = ConnectionFactory::create(['host' => $host, 'port' => (int) (getenv('TEST_DB_PORT') ?: 3306), 'database' => $database, 'username' => getenv('TEST_DB_USERNAME') ?: '', 'password' => getenv('TEST_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);

        $phaseFour = $database === 'pyh_v16_phase4_test';
        $phaseThree = $database === 'pyh_v16_phase3_test';
        self::assertSame($phaseFour ? 42 : ($phaseThree ? 29 : 17), (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $pdo->quote($database)));
        self::assertSame($phaseFour ? '4.2.0-booking-operations' : ($phaseThree ? '3.0.0-quote-engine' : '2.0.0-core-crm'), $this->scalar($pdo, 'SELECT schema_version FROM installation_metadata'));
        self::assertSame(0, (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA=" . $pdo->quote($database) . " AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.ORDINAL_POSITION=1 AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)"));
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }
}
