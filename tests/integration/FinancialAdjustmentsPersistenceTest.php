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

final class FinancialAdjustmentsPersistenceTest extends TestCase
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

    public function testFreshInstallAndCommissionSchemaUpgradeParityAndIdempotency(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-payments-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = [];
        foreach (['booking_financial_adjustments','booking_adjustment_approvals'] as $table) {
            $canonical[$table] = $this->rows("SHOW CREATE TABLE {$table}");
            self::assertCount(1, $canonical[$table]);
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $permissions = $this->permissions();
        self::assertCount(7, $permissions);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        foreach (['20260905_140000_phase4_bookings_foundation.sql','20260905_150000_phase4_booking_travellers.sql','20260905_160000_phase4_booking_elements.sql','20260905_170000_phase4_booking_payments.sql','20260905_180000_phase4_commission_ledger.sql'] as $migration) {
            SqlFileRunner::run($this->pdo, $this->root . '/database/migrations/' . $migration);
            $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$migration]);
        }
        self::assertSame('4.0.4-commission-ledger', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_financial_adjustments'"));
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations', $auditor);
        $migrator->migrate();
        foreach ($canonical as $table => $ddl) {
            self::assertSame($ddl, $this->rows("SHOW CREATE TABLE {$table}"));
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame($permissions, $this->permissions());
        $ledger = $this->rows('SELECT * FROM schema_migrations ORDER BY migration');
        self::assertCount(11, $ledger);
        $migrator->migrate();
        self::assertSame($permissions, $this->permissions());
        self::assertSame($ledger, $this->rows('SELECT * FROM schema_migrations ORDER BY migration'));
        foreach ($canonical as $table => $ddl) {
            self::assertSame($ddl, $this->rows("SHOW CREATE TABLE {$table}"));
        }
    }

    /** @return list<array<string, mixed>> */
    private function permissions(): array
    {
        return $this->rows("SELECT r.name,p.permission_key FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key IN ('bookings.adjust','bookings.approve_adjustment') ORDER BY r.name,p.permission_key");
    }

    public function testStorageForeignKeysAndIndexes(): void
    {
        foreach (['booking_financial_adjustments','booking_adjustment_approvals'] as $table) {
            self::assertSame(['ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_0900_ai_ci'], $this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")[0]);
            $fks=$this->rows("SELECT k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' ORDER BY k.COLUMN_NAME");
            $expected=$table==='booking_financial_adjustments' ? ['booking_id'=>'bookings','current_decision_by_user_id'=>'users','submitted_by_user_id'=>'users'] : ['actor_user_id'=>'users','adjustment_id'=>'booking_financial_adjustments'];
            self::assertSame($expected,array_column($fks,'REFERENCED_TABLE_NAME','COLUMN_NAME'));
            foreach($fks as $fk){self::assertSame('RESTRICT',$fk['DELETE_RULE']);self::assertSame('RESTRICT',$fk['UPDATE_RULE']);}
            self::assertSame([], $this->rows("SELECT k.COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.COLUMN_NAME=k.COLUMN_NAME AND s.SEQ_IN_INDEX=1)"));
        }
        self::assertSame('decimal(13,2)',$this->rows("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_financial_adjustments' AND COLUMN_NAME='amount'")[0]['COLUMN_TYPE']);
        self::assertCount(2,$this->rows("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='booking_adjustment_approvals'"));
    }

    public function testFinancialRulesIndependentActorsAndImmutableReversalHistory(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Adjustment fixture','Adjustment fixture')");
            $org=$this->pdo->lastInsertId();
            $users=[];
            foreach(['submitter','decider'] as $name){
                $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Adjustment','{$name}','adjustment-{$name}@example.test','test-only','{$name}')");
                $users[]=$this->pdo->lastInsertId();
            }
            [$submitter,$decider]=$users;
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Adjustment','Customer',{$submitter},{$submitter})");
            $customer=$this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES ('PYH-ADJUST',{$org},{$customer},'Cruise','2026-09-05',{$submitter},{$submitter})");
            $booking=$this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO booking_financial_adjustments (booking_id,adjustment_type,amount,direction,reason,customer_visibility,submitted_by_user_id,submitted_at_utc) VALUES ({$booking},'Discount',12.34,'Decrease','Goodwill','Customer Visible',{$submitter},UTC_TIMESTAMP(6))");
            $id=$this->pdo->lastInsertId();
            self::assertSame(['amount'=>'12.34','status'=>'Pending','current_decision_by_user_id'=>null,'current_decision_at_utc'=>null],$this->rows("SELECT amount,status,current_decision_by_user_id,current_decision_at_utc FROM booking_financial_adjustments WHERE id={$id}")[0]);
            foreach(['booking_id','submitted_by_user_id'] as $column){
                $this->reject("UPDATE booking_financial_adjustments SET {$column}=999999999 WHERE id={$id}",1452);
                $this->reject("UPDATE booking_financial_adjustments SET {$column}=NULL WHERE id={$id}",1048);
            }
            foreach(['adjustment_type','customer_visibility','status','direction'] as $column){$this->reject("UPDATE booking_financial_adjustments SET {$column}='Invalid' WHERE id={$id}",3819);}
            foreach(['0','-0.01'] as $amount){$this->reject("UPDATE booking_financial_adjustments SET amount={$amount} WHERE id={$id}",3819);}
            $this->reject("UPDATE booking_financial_adjustments SET direction='Increase' WHERE id={$id}",3819);
            $this->reject("UPDATE booking_financial_adjustments SET reason=' ' WHERE id={$id}",3819);
            foreach(['Approved','Rejected','Reversed'] as $status){$this->reject("UPDATE booking_financial_adjustments SET status='{$status}' WHERE id={$id}",3819);}
            foreach([['Fee','Increase'],['Commission-Funded','Decrease'],['Price Correction','Increase'],['Price Correction','Decrease'],['Other','Increase'],['Other','Decrease']] as [$type,$direction]){
                $this->pdo->prepare('UPDATE booking_financial_adjustments SET adjustment_type=?,direction=? WHERE id=?')->execute([$type,$direction,$id]);
                self::assertSame($direction,$this->rows("SELECT direction FROM booking_financial_adjustments WHERE id={$id}")[0]['direction']);
            }
            $event="INSERT INTO booking_adjustment_approvals (adjustment_id,action,actor_user_id,decision_note) VALUES ";
            $this->reject($event."(999999999,'Submitted',{$submitter},NULL)",1452);
            $this->reject($event."({$id},'Submitted',999999999,NULL)",1452);
            $this->reject($event."({$id},'Invalid',{$submitter},NULL)",3819);
            $this->pdo->exec($event."({$id},'Submitted',{$submitter},'Initial submission')");
            $this->pdo->exec($event."({$id},'Rejected',{$decider},'Needs evidence')");
            $this->pdo->exec($event."({$id},'Submitted',{$submitter},'Evidence supplied')");
            $this->pdo->exec("UPDATE booking_financial_adjustments SET status='Approved',current_decision_by_user_id={$decider},current_decision_at_utc=UTC_TIMESTAMP(6) WHERE id={$id}");
            self::assertSame([(int)$submitter,(int)$decider],array_values($this->rows("SELECT submitted_by_user_id,current_decision_by_user_id FROM booking_financial_adjustments WHERE id={$id}")[0]));
            $this->reject("UPDATE booking_financial_adjustments SET current_decision_by_user_id=999999999 WHERE id={$id}",1452);
            $this->pdo->exec($event."({$id},'Approved',{$decider},'Approved with evidence')");
            $before=$this->rows("SELECT * FROM booking_adjustment_approvals WHERE adjustment_id={$id} ORDER BY id");
            $this->pdo->exec("UPDATE booking_financial_adjustments SET status='Reversed',current_decision_by_user_id={$submitter},current_decision_at_utc=UTC_TIMESTAMP(6) WHERE id={$id}");
            $this->pdo->exec($event."({$id},'Reversed',{$submitter},'Reversal evidence')");
            $after=$this->rows("SELECT * FROM booking_adjustment_approvals WHERE adjustment_id={$id} ORDER BY id");
            self::assertSame($before,array_slice($after,0,4));
            self::assertSame(['Submitted','Rejected','Submitted','Approved','Reversed'],array_column($after,'action'));
            self::assertSame([(int)$submitter,(int)$submitter],array_values($this->rows("SELECT submitted_by_user_id,current_decision_by_user_id FROM booking_financial_adjustments WHERE id={$id}")[0]));
            $this->reject("UPDATE booking_adjustment_approvals SET decision_note='Overwrite' WHERE adjustment_id={$id}",1644);
            $this->reject("DELETE FROM booking_adjustment_approvals WHERE adjustment_id={$id}",1644);
            $this->reject("DELETE FROM booking_financial_adjustments WHERE id={$id}",1451);
            $this->reject("DELETE FROM bookings WHERE id={$booking}",1451);
            self::assertSame($after,$this->rows("SELECT * FROM booking_adjustment_approvals WHERE adjustment_id={$id} ORDER BY id"));
        } finally {$this->pdo->rollBack();}
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
