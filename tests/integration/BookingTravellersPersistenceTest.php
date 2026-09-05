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

final class BookingTravellersPersistenceTest extends TestCase
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

    public function testFreshInstallAndFoundationUpgradeParityAndIdempotency(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-travellers-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = $this->rows('SHOW CREATE TABLE booking_travellers');
        self::assertCount(1, $canonical);
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        SqlFileRunner::run($this->pdo, $this->root . '/database/migrations/20260905_140000_phase4_bookings_foundation.sql');
        $this->pdo->exec("INSERT INTO schema_migrations (migration) VALUES ('20260905_140000_phase4_bookings_foundation.sql')");
        self::assertSame('4.0.0-bookings-foundation', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_travellers'"));
        $bookings = $this->rows('SHOW CREATE TABLE bookings');
        $permissions = $this->rows("SELECT rp.* FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key NOT IN ('bookings.adjust','bookings.approve_adjustment','bookings.documents','bookings.checklist','bookings.amend','bookings.finance_view','bookings.finance_manage','bookings.record_payment','bookings.self_approve_adjustment') ORDER BY role_id,permission_id");
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations', $auditor);
        $migrator->migrate();
        self::assertSame($canonical, $this->rows('SHOW CREATE TABLE booking_travellers'));
        self::assertSame($bookings[0]['Create Table'], str_replace('  UNIQUE KEY `uq_bookings_handoff` (`quote_booking_handoff_id`),'."\n", '', $this->rows('SHOW CREATE TABLE bookings')[0]['Create Table']));
        self::assertSame($permissions, $this->rows("SELECT rp.* FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key NOT IN ('bookings.adjust','bookings.approve_adjustment','bookings.documents','bookings.checklist','bookings.amend','bookings.finance_view','bookings.finance_manage','bookings.record_payment','bookings.self_approve_adjustment') ORDER BY role_id,permission_id"));
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $ledger = $this->rows('SELECT * FROM schema_migrations ORDER BY migration');
        self::assertCount(11, $ledger);
        $migrator->migrate();
        self::assertSame($ledger, $this->rows('SELECT * FROM schema_migrations ORDER BY migration'));
        self::assertSame($canonical, $this->rows('SHOW CREATE TABLE booking_travellers'));
    }

    public function testEngineForeignKeysAndIndexes(): void
    {
        self::assertSame(['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_0900_ai_ci'], $this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_travellers'")[0]);
        $fks = $this->rows("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='booking_travellers' ORDER BY k.COLUMN_NAME");
        self::assertSame(['booking_id' => 'bookings', 'traveller_id' => 'travellers'], array_column($fks, 'REFERENCED_TABLE_NAME', 'COLUMN_NAME'));
        foreach ($fks as $fk) {
            self::assertSame('RESTRICT', $fk['DELETE_RULE']);
            self::assertSame('RESTRICT', $fk['UPDATE_RULE']);
        }
        self::assertSame([], $this->rows("SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='booking_travellers' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)"));
        $indexes = array_column($this->rows("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_travellers' GROUP BY INDEX_NAME"), 'columns_list', 'INDEX_NAME');
        self::assertSame('booking_id,display_order', $indexes['idx_booking_travellers_order']);
        self::assertSame('booking_id,lead_traveller', $indexes['idx_booking_travellers_lead']);
        self::assertSame('traveller_id', $indexes['idx_booking_travellers_master']);
    }

    public function testSnapshotsOrderingNullableMasterLeadUniquenessAndForeignKeySafety(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Traveller fixture','Traveller fixture')");
            $org = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Traveller','Fixture','traveller-fixture@example.test','test-only','TF')");
            $user = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Traveller','Customer',{$user},{$user})");
            $customer = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES ('PYH-B-TRAVELLER',{$org},{$customer},'Cruise','2026-09-05',{$user},{$user})");
            $booking = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO travellers (customer_id,title,first_name,last_name,date_of_birth,relationship_label,accessibility_notes,dietary_notes) VALUES ({$customer},'Ms','Jane','Original','1980-01-02','Partner','Step-free access','Vegetarian')");
            $traveller = $this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO booking_travellers (booking_id,traveller_id,lead_traveller,display_order,title_snapshot,first_name_snapshot,last_name_snapshot,date_of_birth_snapshot,traveller_type,relationship_label_snapshot,assistance_notes_snapshot,dietary_notes_snapshot) SELECT {$booking},id,1,0,title,first_name,last_name,date_of_birth,'Adult',relationship_label,accessibility_notes,dietary_notes FROM travellers WHERE id={$traveller}");
            $lead = $this->pdo->lastInsertId();
            $before = $this->rows("SELECT * FROM booking_travellers WHERE id={$lead}");
            $this->pdo->exec("UPDATE travellers SET title='Dr',first_name='Changed',last_name='Changed',date_of_birth='1981-03-04',relationship_label='Other',accessibility_notes='Changed',dietary_notes='Changed' WHERE id={$traveller}");
            self::assertSame($before, $this->rows("SELECT * FROM booking_travellers WHERE id={$lead}"));
            self::assertSame('Jane', $before[0]['first_name_snapshot']);
            self::assertSame('Step-free access', $before[0]['assistance_notes_snapshot']);
            $this->pdo->exec("INSERT INTO booking_travellers (booking_id,display_order,first_name_snapshot,last_name_snapshot,traveller_type) VALUES ({$booking},2,'Infant','Snapshot','Infant'),({$booking},1,'Child','Snapshot','Child')");
            $rows = $this->rows("SELECT first_name_snapshot,display_order,lead_traveller,traveller_id,date_of_birth_snapshot FROM booking_travellers WHERE booking_id={$booking} ORDER BY display_order");
            self::assertSame(['Jane','Child','Infant'], array_column($rows, 'first_name_snapshot'));
            self::assertSame([0,1,2], array_column($rows, 'display_order'));
            self::assertSame([1,0,0], array_column($rows, 'lead_traveller'));
            self::assertNull($rows[1]['traveller_id']);
            self::assertNull($rows[1]['date_of_birth_snapshot']);
            $this->reject("UPDATE booking_travellers SET booking_id=999999999 WHERE id={$lead}", 1452);
            $this->reject("UPDATE booking_travellers SET traveller_id=999999999 WHERE id={$lead}", 1452);
            $this->reject("UPDATE booking_travellers SET lead_traveller=1 WHERE booking_id={$booking} AND display_order=1", 1062);
            $this->reject("UPDATE booking_travellers SET lead_traveller=2 WHERE id={$lead}", 3819);
            $this->reject("UPDATE booking_travellers SET display_order=-1 WHERE id={$lead}", 1264);
            $this->reject("UPDATE booking_travellers SET traveller_type='Other' WHERE id={$lead}", 3819);
            $this->reject("DELETE FROM bookings WHERE id={$booking}", 1451);
            $this->reject("DELETE FROM travellers WHERE id={$traveller}", 1451);
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
