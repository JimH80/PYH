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

final class CommissionLedgerPersistenceTest extends TestCase
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

    public function testFreshInstallAndPaymentsSchemaUpgradeParityAndIdempotency(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-payments-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = [];
        foreach (['booking_commission_ledger'] as $table) {
            $canonical[$table] = $this->rows("SHOW CREATE TABLE {$table}");
            self::assertCount(1, $canonical[$table]);
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        foreach (['20260905_140000_phase4_bookings_foundation.sql','20260905_150000_phase4_booking_travellers.sql','20260905_160000_phase4_booking_elements.sql','20260905_170000_phase4_booking_payments.sql'] as $migration) {
            SqlFileRunner::run($this->pdo, $this->root . '/database/migrations/' . $migration);
            $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$migration]);
        }
        self::assertSame('4.0.3-booking-payments', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_commission_ledger'"));
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations', $auditor);
        $migrator->migrate();
        foreach ($canonical as $table => $ddl) {
            self::assertSame($ddl, $this->rows("SHOW CREATE TABLE {$table}"));
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $ledger = $this->rows('SELECT * FROM schema_migrations ORDER BY migration');
        self::assertCount(11, $ledger);
        $migrator->migrate();
        self::assertSame($ledger, $this->rows('SELECT * FROM schema_migrations ORDER BY migration'));
        foreach ($canonical as $table => $ddl) {
            self::assertSame($ddl, $this->rows("SHOW CREATE TABLE {$table}"));
        }
    }

    public function testStorageForeignKeysAndFullLeftmostIndexes(): void
    {
        foreach (['booking_commission_ledger'] as $table) {
            self::assertSame(['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_0900_ai_ci'], $this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")[0]);
            self::assertSame('decimal(13,2)', $this->rows("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME='amount'")[0]['COLUMN_TYPE']);
            $timestamps = $this->rows("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME LIKE '%at_utc'");
            self::assertCount(2, $timestamps);
            foreach ($timestamps as $column) {
                self::assertSame('timestamp(6)', $column['COLUMN_TYPE']);
            }
            $fks = $this->rows("SELECT k.CONSTRAINT_NAME,GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols,MAX(k.REFERENCED_TABLE_NAME) AS parent,GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS parent_cols,MAX(r.DELETE_RULE) AS delete_rule,MAX(r.UPDATE_RULE) AS update_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' GROUP BY k.CONSTRAINT_NAME ORDER BY k.CONSTRAINT_NAME");
            self::assertCount(4, $fks);
            $expected = ['booking_id' => ['bookings','id'], 'booking_id,booking_element_id' => ['booking_elements','booking_id,id'], 'created_by_user_id' => ['users','id'], 'updated_by_user_id' => ['users','id']];
            foreach ($fks as $fk) {
                self::assertSame($expected[$fk['cols']], [$fk['parent'], $fk['parent_cols']]);
                self::assertSame('RESTRICT', $fk['delete_rule']);
                self::assertSame('RESTRICT', $fk['update_rule']);
            }
            // Match every column of each composite FK at the same index position.
            self::assertSame([], $this->rows("SELECT DISTINCT k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.SEQ_IN_INDEX=1 AND NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE c WHERE c.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS si WHERE si.TABLE_SCHEMA=s.TABLE_SCHEMA AND si.TABLE_NAME=s.TABLE_NAME AND si.INDEX_NAME=s.INDEX_NAME AND si.SEQ_IN_INDEX=c.ORDINAL_POSITION AND si.COLUMN_NAME=c.COLUMN_NAME)))"));
        }
        self::assertSame('+00:00', $this->rows('SELECT @@session.time_zone AS zone')[0]['zone']);
    }

    public function testSourceIntegrityStandardUniquenessAndExceptionalHistory(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Commission fixture','Commission fixture')");
            $org = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Commission','Fixture','commission-fixture@example.test','test-only','CF')");
            $user = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Commission','Customer',{$user},{$user})");
            $customer = $this->pdo->lastInsertId();
            $bookings = [];
            foreach (['A','B'] as $suffix) {
                $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES ('PYH-COM-{$suffix}',{$org},{$customer},'Cruise','2026-09-05',{$user},{$user})");
                $bookings[] = $this->pdo->lastInsertId();
            }
            [$booking, $otherBooking] = $bookings;
            $elements = [];
            foreach (['First','Second'] as $title) {
                $this->pdo->exec("INSERT INTO booking_elements (booking_id,element_type,title,element_booked_date,created_by_user_id,updated_by_user_id) VALUES ({$booking},'Transfer','{$title}','2026-09-05',{$user},{$user})");
                $elements[] = $this->pdo->lastInsertId();
            }
            [$element, $secondElement] = $elements;
            $insert = "INSERT INTO booking_commission_ledger (booking_id,booking_element_id,source_type,instalment_type,amount,expected_date,created_by_user_id,updated_by_user_id) VALUES ";
            foreach (['Booking 50%','Travel 50%'] as $instalment) {
                foreach (['NULL',''.$element,''.$secondElement] as $sourceId) {
                    $source = $sourceId === 'NULL' ? 'Core Booking' : 'Booking Element';
                    $sql = $insert . "({$booking},{$sourceId},'{$source}','{$instalment}',100.01,'2026-10-01',{$user},{$user})";
                    $this->pdo->exec($sql);
                    $id = $this->pdo->lastInsertId();
                    self::assertSame(['source_type' => $source, 'instalment_type' => $instalment, 'amount' => '100.01'], $this->rows("SELECT source_type,instalment_type,amount FROM booking_commission_ledger WHERE id={$id}")[0]);
                    $this->reject($sql,1062);
                }
            }
            $coreSql = $insert . "({$booking},NULL,'Core Booking','Post-Cancellation',0,'2026-10-01',{$user},{$user})";
            $this->pdo->exec($coreSql);
            $core = $this->pdo->lastInsertId();
            $this->reject("UPDATE booking_commission_ledger SET booking_element_id={$element} WHERE id={$core}",3819);
            $this->reject("UPDATE booking_commission_ledger SET source_type='Booking Element' WHERE id={$core}",3819);
            foreach (['source_type','instalment_type','status'] as $column) {
                $this->reject("UPDATE booking_commission_ledger SET {$column}='Invalid' WHERE id={$core}",3819);
            }
            foreach (['booking_id','created_by_user_id','updated_by_user_id'] as $column) {
                $this->reject("UPDATE booking_commission_ledger SET {$column}=999999999 WHERE id={$core}",1452);
            }
            $this->reject("UPDATE booking_commission_ledger SET amount=-0.01 WHERE id={$core}",3819);
            $this->reject("UPDATE booking_commission_ledger SET status='Received' WHERE id={$core}",3819);
            $this->pdo->exec("UPDATE booking_commission_ledger SET source_type='Booking Element',booking_element_id={$element} WHERE id={$core}");
            $this->reject("UPDATE booking_commission_ledger SET booking_element_id=NULL WHERE id={$core}",3819);
            $this->reject("UPDATE booking_commission_ledger SET booking_element_id=999999999 WHERE id={$core}",1452);
            $this->reject("UPDATE booking_commission_ledger SET booking_id={$otherBooking} WHERE id={$core}",1452);
            $this->reject($insert . "({$otherBooking},{$element},'Booking Element','Clawback',1,'2026-10-01',{$user},{$user})",1452);
            $this->reject("UPDATE booking_commission_ledger SET instalment_type='Booking 50%' WHERE id={$core}",1062);
            foreach (['Expected','Due','Cancelled','Owed'] as $status) {
                $this->pdo->exec("UPDATE booking_commission_ledger SET status='{$status}' WHERE id={$core}");
                self::assertSame(['status' => $status, 'received_date' => null], $this->rows("SELECT status,received_date FROM booking_commission_ledger WHERE id={$core}")[0]);
            }
            $this->pdo->exec("UPDATE booking_commission_ledger SET status='Received',received_date='2026-10-02' WHERE id={$core}");
            $received = $this->rows("SELECT * FROM booking_commission_ledger WHERE id={$core}");
            $this->reject("UPDATE booking_commission_ledger SET received_date=NULL WHERE id={$core}",3819);
            foreach (['Post-Cancellation','Clawback'] as $instalment) {
                foreach (['NULL',''.$element] as $sourceId) {
                    $source = $sourceId === 'NULL' ? 'Core Booking' : 'Booking Element';
                    for ($i = 0; $i < 2; ++$i) {
                        $this->pdo->exec($insert . "({$booking},{$sourceId},'{$source}','{$instalment}',25.00,'2026-10-03',{$user},{$user})");
                        $id = $this->pdo->lastInsertId();
                        $this->pdo->exec("UPDATE booking_commission_ledger SET status='Owed' WHERE id={$id}");
                    }
                }
            }
            self::assertSame(8, (int) $this->rows("SELECT COUNT(*) AS n FROM booking_commission_ledger WHERE booking_id={$booking} AND status='Owed'")[0]['n']);
            self::assertSame($received, $this->rows("SELECT * FROM booking_commission_ledger WHERE id={$core}"), 'Exceptional entries must not replace received history.');
            $this->reject("DELETE FROM booking_elements WHERE id={$element}",1451);
            $this->reject("DELETE FROM bookings WHERE id={$booking}",1451);
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
