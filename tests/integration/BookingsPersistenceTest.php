<?php

declare(strict_types=1);

namespace PYH\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use PYH\Database\ConnectionFactory;
use PYH\Database\DatabaseChangeAuditor;
use PYH\Database\Migrator;
use PYH\Database\SqlFileRunner;
use PYH\Database\TestDatabasePolicy;
use PYH\Installation\Installer;
use PYH\Logging\FileLogger;

final class BookingsPersistenceTest extends TestCase
{
    private PDO $pdo;
    private string $root;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_DATABASE') !== 'pyh_v16_phase4_test') {
            self::markTestSkipped('Requires the exact local Phase 4 disposable database.');
        }
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        TestDatabasePolicy::assertDisposable(getenv('APP_ENV') ?: '', $host, 'pyh_v16_phase4_test');
        $this->pdo = ConnectionFactory::create(['host' => $host, 'port' => (int) (getenv('TEST_DB_PORT') ?: 3306), 'database' => 'pyh_v16_phase4_test', 'username' => getenv('TEST_DB_USERNAME') ?: '', 'password' => getenv('TEST_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);
        $this->root = dirname(__DIR__, 2);
    }

    public function testFreshInstallAndPhaseThreeMigrationHaveIdenticalBookingsAndPermissionsAndRerunIsIdempotent(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-persistence-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = $this->rows('SHOW CREATE TABLE bookings');
        $permissions = $this->bookingPermissions();
        self::assertCount(43, $permissions);
        self::assertSame(['bookings.adjust', 'bookings.amend', 'bookings.approve_adjustment', 'bookings.checklist', 'bookings.create', 'bookings.documents', 'bookings.edit', 'bookings.finance_manage', 'bookings.finance_view', 'bookings.record_payment', 'bookings.self_approve_adjustment', 'bookings.view'], array_column($this->rows("SELECT permission_key FROM permissions WHERE permission_key LIKE 'bookings.%' ORDER BY permission_key"), 'permission_key'));
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version, installed_at_utc) VALUES ('3.0.0-quote-engine', UTC_TIMESTAMP(6))");
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'bookings'"));
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations', $auditor);
        $migrator->migrate();
        self::assertSame($canonical, $this->rows('SHOW CREATE TABLE bookings'));
        self::assertSame($permissions, $this->bookingPermissions());
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $ledger = $this->rows('SELECT * FROM schema_migrations ORDER BY migration');
        self::assertCount(11, $ledger);
        $migrator->migrate();
        self::assertSame($ledger, $this->rows('SELECT * FROM schema_migrations ORDER BY migration'));
        self::assertSame($canonical, $this->rows('SHOW CREATE TABLE bookings'));
        self::assertSame($permissions, $this->bookingPermissions());
    }

    public function testStorageTypesForeignKeysAndLeftmostIndexes(): void
    {
        self::assertSame(['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_0900_ai_ci'], $this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings'")[0]);
        $money = $this->rows("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME IN ('core_selling_price','supplier_cost','commission','deposit')");
        self::assertCount(4, $money);
        foreach ($money as $column) {
            self::assertSame('decimal(13,2)', $column['COLUMN_TYPE']);
        }
        $fks = $this->rows("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='bookings' ORDER BY k.COLUMN_NAME");
        self::assertSame(['assigned_user_id' => 'users', 'created_by_user_id' => 'users', 'customer_id' => 'customers', 'location_id' => 'locations', 'organisation_id' => 'organisations', 'quote_booking_handoff_id' => 'quote_booking_handoffs', 'quote_id' => 'quotes', 'updated_by_user_id' => 'users'], array_column($fks, 'REFERENCED_TABLE_NAME', 'COLUMN_NAME'));
        foreach ($fks as $fk) {
            self::assertSame('RESTRICT', $fk['DELETE_RULE']);
            self::assertSame('RESTRICT', $fk['UPDATE_RULE']);
        }
        self::assertSame([], $this->rows("SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='bookings' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)"));
    }

    public function testPersistenceUniqueReferenceAndEveryInvalidParentIsRejected(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Booking fixture','Booking fixture')");
            $org = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Booking','Fixture','booking-fixture@example.test','test-only','BF')");
            $user = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Booking','Customer',{$user},{$user})");
            $customer = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,core_selling_price,supplier_cost,commission,deposit,created_by_user_id,updated_by_user_id) VALUES ('PYH-B-TEST',{$org},{$customer},'Package Holiday','2026-09-05',1234.56,1000.00,234.56,100.01,{$user},{$user})");
            $id = $this->pdo->lastInsertId();
            self::assertSame(['core_selling_price' => '1234.56', 'supplier_cost' => '1000.00', 'commission' => '234.56', 'deposit' => '100.01', 'status' => 'Booked', 'quote_id' => null, 'quote_booking_handoff_id' => null], $this->rows("SELECT core_selling_price,supplier_cost,commission,deposit,status,quote_id,quote_booking_handoff_id FROM bookings WHERE id={$id}")[0]);
            $this->reject("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES ('PYH-B-TEST',{$org},{$customer},'Cruise','2026-09-05',{$user},{$user})", 1062);
            foreach (['organisation_id','location_id','assigned_user_id','customer_id','quote_id','quote_booking_handoff_id','created_by_user_id','updated_by_user_id'] as $column) {
                $this->reject("UPDATE bookings SET {$column}=999999999 WHERE id={$id}", 1452);
            }
            $this->reject("DELETE FROM customers WHERE id={$customer}", 1451);
            $this->reject("UPDATE bookings SET deposit=-1 WHERE id={$id}", 3819);
            $this->reject("UPDATE bookings SET status='Invalid' WHERE id={$id}", 3819);
        } finally {
            $this->pdo->rollBack();
        }
    }

    private function reject(string $sql, int $code): void
    {
        try {
            $this->pdo->exec($sql);
            self::fail('Database accepted an invalid write.');
        } catch (PDOException $exception) {
            self::assertSame($code, $exception->errorInfo[1] ?? null);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string, mixed>> */
    private function bookingPermissions(): array
    {
        return $this->rows("SELECT r.name,p.permission_key FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key LIKE 'bookings.%' ORDER BY r.name,p.permission_key");
    }

    private function emptyDatabase(): void
    {
        // This connection is guarded to the exact disposable Phase 4 database in setUp.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->rows('SHOW TABLES') as $row) {
                $table = str_replace('`', '``', (string) array_values($row)[0]);
                $this->pdo->exec("DROP TABLE `{$table}`");
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
