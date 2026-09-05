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

final class BookingPaymentsPersistenceTest extends TestCase
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

    public function testFreshInstallAndElementsSchemaUpgradeParityAndIdempotency(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-payments-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = [];
        foreach (['booking_payments','booking_supplier_payments','booking_elements'] as $table) {
            $canonical[$table] = $this->rows("SHOW CREATE TABLE {$table}");
            self::assertCount(1, $canonical[$table]);
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        foreach (['20260905_140000_phase4_bookings_foundation.sql','20260905_150000_phase4_booking_travellers.sql','20260905_160000_phase4_booking_elements.sql'] as $migration) {
            SqlFileRunner::run($this->pdo, $this->root . '/database/migrations/' . $migration);
            $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$migration]);
        }
        self::assertSame('4.0.2-booking-elements', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_payments'"));
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_supplier_payments'"));
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
        foreach (['booking_payments','booking_supplier_payments'] as $table) {
            self::assertSame(['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_0900_ai_ci'], $this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")[0]);
            self::assertSame('decimal(13,2)', $this->rows("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME='amount'")[0]['COLUMN_TYPE']);
            $timestamps = $this->rows("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}' AND COLUMN_NAME LIKE '%at_utc'");
            self::assertCount(2, $timestamps);
            foreach ($timestamps as $column) {
                self::assertSame('timestamp(6)', $column['COLUMN_TYPE']);
            }
            $fks = $this->rows("SELECT k.CONSTRAINT_NAME,GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols,MAX(k.REFERENCED_TABLE_NAME) AS parent,GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS parent_cols,MAX(r.DELETE_RULE) AS delete_rule,MAX(r.UPDATE_RULE) AS update_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' GROUP BY k.CONSTRAINT_NAME ORDER BY k.CONSTRAINT_NAME");
            self::assertCount($table === 'booking_payments' ? 2 : 4, $fks);
            $expected = $table === 'booking_payments'
                ? ['booking_id' => ['bookings','id'], 'created_by_user_id' => ['users','id']]
                : ['booking_id' => ['bookings','id'], 'booking_id,booking_element_id' => ['booking_elements','booking_id,id'], 'created_by_user_id' => ['users','id'], 'updated_by_user_id' => ['users','id']];
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

    public function testPaymentRefundAndSupplierIntegrityIncludingCrossBookingElements(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Payment fixture','Payment fixture')");
            $org = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Payment','Fixture','payment-fixture@example.test','test-only','PF')");
            $user = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Payment','Customer',{$user},{$user})");
            $customer = $this->pdo->lastInsertId();
            $bookings = [];
            foreach (['A','B'] as $suffix) {
                $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES ('PYH-PAY-{$suffix}',{$org},{$customer},'Cruise','2026-09-05',{$user},{$user})");
                $bookings[] = $this->pdo->lastInsertId();
            }
            [$booking, $otherBooking] = $bookings;
            $this->pdo->exec("INSERT INTO booking_elements (booking_id,element_type,title,element_booked_date,created_by_user_id,updated_by_user_id) VALUES ({$booking},'Transfer','Transfer','2026-09-05',{$user},{$user})");
            $element = $this->pdo->lastInsertId();
            foreach (['Payment','Refund'] as $type) {
                $this->pdo->exec("INSERT INTO booking_payments (booking_id,transaction_type,amount,processed_at_utc,created_by_user_id) VALUES ({$booking},'{$type}',12.34,'2026-09-05 10:11:12.123456',{$user})");
                $payment = $this->pdo->lastInsertId();
                self::assertSame(['transaction_type' => $type, 'amount' => '12.34', 'processed_at_utc' => '2026-09-05 10:11:12.123456'], $this->rows("SELECT transaction_type,amount,processed_at_utc FROM booking_payments WHERE id={$payment}")[0]);
                foreach (['booking_id','created_by_user_id'] as $column) {
                    $this->reject("UPDATE booking_payments SET {$column}=999999999 WHERE id={$payment}",1452);
                    $this->reject("UPDATE booking_payments SET {$column}=NULL WHERE id={$payment}",1048);
                }
                foreach (['0','-0.01'] as $amount) {
                    $this->reject("UPDATE booking_payments SET amount={$amount} WHERE id={$payment}",3819);
                }
                $this->reject("UPDATE booking_payments SET transaction_type='Invalid' WHERE id={$payment}",3819);
            }
            $this->pdo->exec("INSERT INTO booking_supplier_payments (booking_id,amount,created_by_user_id,updated_by_user_id) VALUES ({$booking},0.00,{$user},{$user})");
            $supplier = $this->pdo->lastInsertId();
            self::assertSame(['booking_element_id' => null, 'status' => 'Not Due', 'amount' => '0.00'], $this->rows("SELECT booking_element_id,status,amount FROM booking_supplier_payments WHERE id={$supplier}")[0]);
            $this->pdo->exec("UPDATE booking_supplier_payments SET booking_element_id={$element},amount=100.01 WHERE id={$supplier}");
            foreach (['Not Due','Due','Paid'] as $status) {
                $this->pdo->exec("UPDATE booking_supplier_payments SET status='{$status}' WHERE id={$supplier}");
                self::assertSame($status, $this->rows("SELECT status FROM booking_supplier_payments WHERE id={$supplier}")[0]['status']);
            }
            foreach (['booking_id','booking_element_id','created_by_user_id','updated_by_user_id'] as $column) {
                $this->reject("UPDATE booking_supplier_payments SET {$column}=999999999 WHERE id={$supplier}",1452);
            }
            $this->reject("UPDATE booking_supplier_payments SET booking_id={$otherBooking} WHERE id={$supplier}",1452);
            $this->reject("INSERT INTO booking_supplier_payments (booking_id,booking_element_id,amount,created_by_user_id,updated_by_user_id) VALUES ({$otherBooking},{$element},1,{$user},{$user})",1452);
            $this->reject("UPDATE booking_elements SET booking_id={$otherBooking} WHERE id={$element}",1451);
            $this->reject("DELETE FROM booking_elements WHERE id={$element}",1451);
            $this->reject("UPDATE booking_supplier_payments SET amount=-0.01 WHERE id={$supplier}",3819);
            $this->reject("UPDATE booking_supplier_payments SET status='Invalid' WHERE id={$supplier}",3819);
            $this->pdo->exec("UPDATE booking_supplier_payments SET paid_date='2026-09-05' WHERE id={$supplier}");
            self::assertSame('2026-09-05', $this->rows("SELECT paid_date FROM booking_supplier_payments WHERE id={$supplier}")[0]['paid_date']);
            $this->reject("UPDATE booking_supplier_payments SET status='Due' WHERE id={$supplier}",3819);
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
