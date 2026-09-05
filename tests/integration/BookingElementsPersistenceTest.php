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

final class BookingElementsPersistenceTest extends TestCase
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

    public function testFreshInstallAndTravellerSchemaUpgradeParityAndIdempotency(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-elements-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = $this->rows('SHOW CREATE TABLE booking_elements');
        self::assertCount(1, $canonical);
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        foreach (['20260905_140000_phase4_bookings_foundation.sql','20260905_150000_phase4_booking_travellers.sql'] as $migration) {
            SqlFileRunner::run($this->pdo, $this->root . '/database/migrations/' . $migration);
            $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$migration]);
        }
        self::assertSame('4.0.1-booking-travellers', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_elements'"));
        $bookings = $this->rows('SHOW CREATE TABLE bookings');
        $travellers = $this->rows('SHOW CREATE TABLE booking_travellers');
        $permissions = $this->rows("SELECT rp.* FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key NOT IN ('bookings.adjust','bookings.approve_adjustment','bookings.documents','bookings.checklist','bookings.amend','bookings.finance_view','bookings.finance_manage','bookings.record_payment','bookings.self_approve_adjustment') ORDER BY role_id,permission_id");
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations', $auditor);
        $migrator->migrate();
        self::assertSame($canonical, $this->rows('SHOW CREATE TABLE booking_elements'));
        self::assertSame($bookings[0]['Create Table'], str_replace('  UNIQUE KEY `uq_bookings_handoff` (`quote_booking_handoff_id`),'."\n", '', $this->rows('SHOW CREATE TABLE bookings')[0]['Create Table']));
        self::assertSame($travellers, $this->rows('SHOW CREATE TABLE booking_travellers'));
        self::assertSame($permissions, $this->rows("SELECT rp.* FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key NOT IN ('bookings.adjust','bookings.approve_adjustment','bookings.documents','bookings.checklist','bookings.amend','bookings.finance_view','bookings.finance_manage','bookings.record_payment','bookings.self_approve_adjustment') ORDER BY role_id,permission_id"));
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $ledger = $this->rows('SELECT * FROM schema_migrations ORDER BY migration');
        self::assertCount(11, $ledger);
        $migrator->migrate();
        self::assertSame($ledger, $this->rows('SELECT * FROM schema_migrations ORDER BY migration'));
        self::assertSame($canonical, $this->rows('SHOW CREATE TABLE booking_elements'));
    }

    public function testEngineMoneyForeignKeysAndIndexes(): void
    {
        self::assertSame(['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_0900_ai_ci'], $this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_elements'")[0]);
        $money = $this->rows("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_elements' AND COLUMN_NAME IN ('selling_price','supplier_cost','commission')");
        self::assertCount(3, $money);
        foreach ($money as $column) {
            self::assertSame('decimal(13,2)', $column['COLUMN_TYPE']);
        }
        $fks = $this->rows("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='booking_elements' ORDER BY k.COLUMN_NAME");
        self::assertSame(['booking_id' => 'bookings', 'created_by_user_id' => 'users', 'updated_by_user_id' => 'users'], array_column($fks, 'REFERENCED_TABLE_NAME', 'COLUMN_NAME'));
        foreach ($fks as $fk) {
            self::assertSame('RESTRICT', $fk['DELETE_RULE']);
            self::assertSame('RESTRICT', $fk['UPDATE_RULE']);
        }
        self::assertSame([], $this->rows("SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='booking_elements' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)"));
        $indexes = array_column($this->rows("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_elements' GROUP BY INDEX_NAME"), 'columns_list', 'INDEX_NAME');
        self::assertSame('booking_id,element_type', $indexes['idx_booking_elements_type']);
        self::assertSame('booking_id,status', $indexes['idx_booking_elements_status']);
        self::assertSame('supplier_reference', $indexes['idx_booking_elements_supplier_reference']);
        self::assertSame('supplier_payment_status,supplier_payment_due_date', $indexes['idx_booking_elements_payment_due']);
        self::assertSame('service_start_date', $indexes['idx_booking_elements_service_start']);
        self::assertSame([], $this->rows("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('bookings','booking_elements')"));
    }

    public function testElementConstraintsAndInsertUpdateDeleteNeverChangeBookingSupplier(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Element fixture','Element fixture')");
            $org = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Element','Fixture','element-fixture@example.test','test-only','EF')");
            $user = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Element','Customer',{$user},{$user})");
            $customer = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,supplier_name,supplier_reference,supplier_booking_reference,created_by_user_id,updated_by_user_id) VALUES ('PYH-B-ELEMENT',{$org},{$customer},'Cruise','2026-09-05','Main Supplier','MAIN-REF','MAIN-BOOKING',{$user},{$user})");
            $booking = $this->pdo->lastInsertId();
            $supplierQuery = "SELECT supplier_name,supplier_reference,supplier_booking_reference FROM bookings WHERE id={$booking}";
            $supplier = $this->rows($supplierQuery);
            $this->pdo->exec("INSERT INTO booking_elements (booking_id,element_type,title,element_booked_date,supplier_name,supplier_reference,service_start_date,service_end_date,selling_price,supplier_cost,commission,created_by_user_id,updated_by_user_id) VALUES ({$booking},'Transfer','Airport transfer','2026-09-05','Element Supplier','ELEMENT-REF','2027-06-01','2027-06-02',123.45,100.01,23.44,{$user},{$user})");
            $element = $this->pdo->lastInsertId();
            self::assertSame($supplier, $this->rows($supplierQuery), 'Element insert changed the booking supplier.');
            self::assertSame(['selling_price' => '123.45', 'supplier_cost' => '100.01', 'commission' => '23.44', 'status' => 'Confirmed', 'supplier_payment_status' => 'Not Due'], $this->rows("SELECT selling_price,supplier_cost,commission,status,supplier_payment_status FROM booking_elements WHERE id={$element}")[0]);
            foreach (['booking_id','created_by_user_id','updated_by_user_id'] as $column) {
                $this->reject("UPDATE booking_elements SET {$column}=999999999 WHERE id={$element}", 1452);
            }
            $this->reject("UPDATE booking_elements SET booking_id=NULL WHERE id={$element}", 1048);
            foreach (['selling_price','supplier_cost','commission'] as $column) {
                $this->reject("UPDATE booking_elements SET {$column}=-0.01 WHERE id={$element}", 3819);
            }
            foreach (['element_type','supplier_payment_status','status'] as $column) {
                $this->reject("UPDATE booking_elements SET {$column}='Invalid' WHERE id={$element}", 3819);
            }
            $this->reject("UPDATE booking_elements SET service_end_date='2027-05-31' WHERE id={$element}", 3819);
            $this->reject("DELETE FROM bookings WHERE id={$booking}", 1451);
            foreach (['Attraction','Travel Insurance','Airport Parking','Airport Hotel','Car Hire','Transfer','Flight','Accommodation','Cruise','Excursion','Lounge','Rail','Upgrade','Other'] as $type) {
                $this->pdo->prepare('UPDATE booking_elements SET element_type=? WHERE id=?')->execute([$type, $element]);
                self::assertSame($type, $this->rows("SELECT element_type FROM booking_elements WHERE id={$element}")[0]['element_type']);
            }
            foreach (['Not Due','Due','Paid'] as $status) {
                $this->pdo->prepare('UPDATE booking_elements SET supplier_payment_status=? WHERE id=?')->execute([$status, $element]);
                self::assertSame($status, $this->rows("SELECT supplier_payment_status FROM booking_elements WHERE id={$element}")[0]['supplier_payment_status']);
            }
            foreach (['Confirmed','Cancelled','Archived'] as $status) {
                $this->pdo->prepare('UPDATE booking_elements SET status=? WHERE id=?')->execute([$status, $element]);
                self::assertSame($status, $this->rows("SELECT status FROM booking_elements WHERE id={$element}")[0]['status']);
            }
            $this->pdo->exec("UPDATE booking_elements SET supplier_name='Changed Element Supplier',supplier_reference='CHANGED-ELEMENT',service_start_date=NULL,service_end_date=NULL WHERE id={$element}");
            self::assertSame($supplier, $this->rows($supplierQuery), 'Element update changed the booking supplier.');
            self::assertSame(1, $this->pdo->exec("DELETE FROM booking_elements WHERE id={$element}"));
            self::assertSame([], $this->rows("SELECT id FROM booking_elements WHERE id={$element}"));
            self::assertSame($supplier, $this->rows($supplierQuery), 'Element delete changed the booking supplier.');
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
