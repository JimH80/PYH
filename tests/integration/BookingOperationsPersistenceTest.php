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

final class BookingOperationsPersistenceTest extends TestCase
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

    private const TABLES = ['booking_documents','booking_checklist_templates','booking_checklist_template_items','booking_checklist_items','booking_amendments'];

    public function testFreshInstallAndAdjustmentsSchemaUpgradeParityAndIdempotency(): void
    {
        $auditor = new DatabaseChangeAuditor(new FileLogger($this->root . '/storage/logs/phase4-payments-test.log'), 'test');
        $this->emptyDatabase();
        (new Installer($this->pdo, $this->root . '/database/schema/001_canonical.sql', $auditor))->install();
        $canonical = [];
        foreach (self::TABLES as $table) {
            $canonical[$table] = $this->rows("SHOW CREATE TABLE {$table}");
            self::assertCount(1, $canonical[$table]);
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $seed = $this->seedRows();
        self::assertCount(4, $seed);
        $permissions = $this->permissions();
        self::assertCount(12, $permissions);
        $this->emptyDatabase();
        SqlFileRunner::run($this->pdo, $this->root . '/tests/fixtures/phase3_canonical.sql');
        $this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        foreach (['20260905_140000_phase4_bookings_foundation.sql','20260905_150000_phase4_booking_travellers.sql','20260905_160000_phase4_booking_elements.sql','20260905_170000_phase4_booking_payments.sql','20260905_180000_phase4_commission_ledger.sql','20260905_190000_phase4_financial_adjustments.sql'] as $migration) {
            SqlFileRunner::run($this->pdo, $this->root . '/database/migrations/' . $migration);
            $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$migration]);
        }
        self::assertSame('4.0.5-financial-adjustments', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame([], $this->rows("SHOW TABLES LIKE 'booking_documents'"));
        $migrator = new Migrator($this->pdo, $this->root . '/database/migrations', $auditor);
        $migrator->migrate();
        foreach ($canonical as $table => $ddl) {
            self::assertSame($ddl, $this->rows("SHOW CREATE TABLE {$table}"));
        }
        self::assertSame('4.2.0-booking-operations', $this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        self::assertSame($seed, $this->seedRows());
        self::assertSame($permissions, $this->permissions());
        $ledger = $this->rows('SELECT * FROM schema_migrations ORDER BY migration');
        self::assertCount(11, $ledger);
        $migrator->migrate();
        self::assertSame($seed, $this->seedRows());
        self::assertSame($permissions, $this->permissions());
        self::assertSame($ledger, $this->rows('SELECT * FROM schema_migrations ORDER BY migration'));
        foreach ($canonical as $table => $ddl) {
            self::assertSame($ddl, $this->rows("SHOW CREATE TABLE {$table}"));
        }
    }

    /** @return list<array<string, mixed>> */
    private function permissions(): array
    {
        return $this->rows("SELECT r.name,p.permission_key FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key IN ('bookings.documents','bookings.checklist','bookings.amend') ORDER BY r.name,p.permission_key");
    }

    /** @return list<array<string,mixed>> */
    private function seedRows(): array
    {
        return $this->rows("SELECT t.template_code,t.template_name,t.booking_status_context,t.active,i.item_code,i.title,i.category,i.default_due_offset_days,i.display_order,i.active AS item_active FROM booking_checklist_templates t JOIN booking_checklist_template_items i ON i.template_id=t.id ORDER BY t.template_code,i.display_order");
    }

    public function testStorageAndFullForeignKeyIndexes(): void
    {
        $expected=[
            'booking_documents'=>['booking_id'=>'bookings','booking_id,booking_element_id'=>'booking_elements','uploaded_by_user_id'=>'users'],
            'booking_checklist_templates'=>[],
            'booking_checklist_template_items'=>['template_id'=>'booking_checklist_templates'],
            'booking_checklist_items'=>['booking_id'=>'bookings','template_item_id'=>'booking_checklist_template_items','assigned_user_id'=>'users','completed_by_user_id'=>'users'],
            'booking_amendments'=>['booking_id'=>'bookings','created_by_user_id'=>'users','approved_by_user_id'=>'users'],
        ];
        foreach(self::TABLES as $table){
            self::assertSame(['ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_0900_ai_ci'],$this->rows("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")[0]);
            $fks=$this->rows("SELECT k.CONSTRAINT_NAME,GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols,MAX(k.REFERENCED_TABLE_NAME) AS parent,MAX(r.DELETE_RULE) AS delete_rule,MAX(r.UPDATE_RULE) AS update_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' GROUP BY k.CONSTRAINT_NAME");
            self::assertCount(count($expected[$table]),$fks);
            foreach($fks as $fk){self::assertArrayHasKey($fk['cols'],$expected[$table]);self::assertSame($expected[$table][$fk['cols']] ?? null,$fk['parent']);self::assertSame('RESTRICT',$fk['delete_rule']);self::assertSame('RESTRICT',$fk['update_rule']);}
            self::assertSame([], $this->rows("SELECT DISTINCT k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='{$table}' AND k.REFERENCED_TABLE_NAME IS NOT NULL AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA=k.TABLE_SCHEMA AND s.TABLE_NAME=k.TABLE_NAME AND s.SEQ_IN_INDEX=1 AND NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE c WHERE c.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS si WHERE si.TABLE_SCHEMA=s.TABLE_SCHEMA AND si.TABLE_NAME=s.TABLE_NAME AND si.INDEX_NAME=s.INDEX_NAME AND si.SEQ_IN_INDEX=c.ORDINAL_POSITION AND si.COLUMN_NAME=c.COLUMN_NAME)))"));
        }
        self::assertSame('decimal(13,2)',$this->rows("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking_amendments' AND COLUMN_NAME='financial_effect_amount'")[0]['COLUMN_TYPE']);
        self::assertSame(['Supplier confirmation checked','Customer confirmation/documents sent','Payment schedule checked','Final balance due date checked'],array_column($this->seedRows(),'title'));
        self::assertSame(['BOOKED_DEFAULT'],array_values(array_unique(array_column($this->seedRows(),'template_code'))));
    }

    public function testDocumentsChecklistsAndAmendmentEvidence(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Operations fixture','Operations fixture')");
            $org=$this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO users (organisation_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},'Operations','Fixture','operations@example.test','test-only','OF')");
            $user=$this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO customers (organisation_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},'Operations','Customer',{$user},{$user})");
            $customer=$this->pdo->lastInsertId();
            $bookings=[];
            foreach(['A','B'] as $suffix){
                $this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES ('PYH-OPS-{$suffix}',{$org},{$customer},'Cruise','2026-09-05',{$user},{$user})");
                $bookings[]=$this->pdo->lastInsertId();
            }
            [$booking,$other]=$bookings;
            $this->pdo->exec("INSERT INTO booking_elements (booking_id,element_type,title,element_booked_date,created_by_user_id,updated_by_user_id) VALUES ({$booking},'Transfer','Transfer','2026-09-05',{$user},{$user})");
            $element=$this->pdo->lastInsertId();
            $this->pdo->exec("INSERT INTO booking_documents (booking_id,document_type,original_filename,storage_reference,mime_type,uploaded_by_user_id,uploaded_at_utc) VALUES ({$booking},'Confirmation','confirmation.pdf','opaque-storage-key-123','application/pdf',{$user},UTC_TIMESTAMP(6))");
            $doc=$this->pdo->lastInsertId();
            self::assertSame(['booking_element_id'=>null,'visibility'=>'Agent only','storage_reference'=>'opaque-storage-key-123'],$this->rows("SELECT booking_element_id,visibility,storage_reference FROM booking_documents WHERE id={$doc}")[0]);
            $this->pdo->exec("UPDATE booking_documents SET booking_element_id={$element},visibility='Customer visible' WHERE id={$doc}");
            self::assertSame('Customer visible',$this->rows("SELECT visibility FROM booking_documents WHERE id={$doc}")[0]['visibility']);
            foreach(['booking_id','booking_element_id','uploaded_by_user_id'] as $column){$this->reject("UPDATE booking_documents SET {$column}=999999999 WHERE id={$doc}",1452);}
            $this->reject("UPDATE booking_documents SET booking_id={$other} WHERE id={$doc}",1452);
            $this->reject("UPDATE booking_documents SET visibility='Public' WHERE id={$doc}",3819);
            $this->reject("DELETE FROM booking_elements WHERE id={$element}",1451);
            $template=$this->rows("SELECT id FROM booking_checklist_templates WHERE template_code='BOOKED_DEFAULT'")[0]['id'];
            $item=$this->rows("SELECT id FROM booking_checklist_template_items WHERE template_id={$template} ORDER BY display_order LIMIT 1")[0]['id'];
            $this->reject("INSERT INTO booking_checklist_template_items (template_id,item_code,title,category,display_order) VALUES ({$template},'SUPPLIER_CONFIRMATION','Duplicate','Supplier',0)",1062);
            $this->reject("INSERT INTO booking_checklist_template_items (template_id,item_code,title,category,display_order) VALUES (999999999,'INVALID','Invalid','Supplier',0)",1452);
            $this->reject("UPDATE booking_checklist_template_items SET display_order=-1 WHERE id={$item}",1264);
            $this->pdo->exec("INSERT INTO booking_checklist_items (booking_id,item_code,title,category,due_at_utc) VALUES ({$booking},'MANUAL','Manual check','Operations','2026-10-01 10:00:00.123456')");
            $check=$this->pdo->lastInsertId();
            self::assertSame(['template_item_id'=>null,'status'=>'Open','due_at_utc'=>'2026-10-01 10:00:00.123456'],$this->rows("SELECT template_item_id,status,due_at_utc FROM booking_checklist_items WHERE id={$check}")[0]);
            $this->pdo->exec("UPDATE booking_checklist_items SET template_item_id={$item},assigned_user_id={$user} WHERE id={$check}");
            foreach(['booking_id','template_item_id','assigned_user_id','completed_by_user_id'] as $column){$this->reject("UPDATE booking_checklist_items SET {$column}=999999999 WHERE id={$check}",1452);}
            $this->reject("INSERT INTO booking_checklist_items (booking_id,item_code,title,category) VALUES ({$booking},'MANUAL','Duplicate','Operations')",1062);
            $this->reject("UPDATE booking_checklist_items SET status='Invalid' WHERE id={$check}",3819);
            $this->reject("UPDATE booking_checklist_items SET status='Completed' WHERE id={$check}",3819);
            $this->pdo->exec("UPDATE booking_checklist_items SET status='Completed',completed_at_utc=UTC_TIMESTAMP(6),completed_by_user_id={$user} WHERE id={$check}");
            self::assertSame('Completed',$this->rows("SELECT status FROM booking_checklist_items WHERE id={$check}")[0]['status']);
            $this->reject("DELETE FROM booking_checklist_template_items WHERE id={$item}",1451);
            $this->reject("DELETE FROM booking_checklist_templates WHERE id={$template}",1451);
            $this->pdo->exec("INSERT INTO booking_amendments (booking_id,amendment_sequence,reason,description,before_summary,after_summary,created_by_user_id) VALUES ({$booking},1,'Customer request','Change travel date',JSON_OBJECT('date','2027-01-01'),JSON_OBJECT('date','2027-01-02'),{$user})");
            $amend=$this->pdo->lastInsertId();
            self::assertSame(['financial_effect_amount'=>'0.00','financial_effect_direction'=>'None','approved_by_user_id'=>null],$this->rows("SELECT financial_effect_amount,financial_effect_direction,approved_by_user_id FROM booking_amendments WHERE id={$amend}")[0]);
            foreach(['booking_id','created_by_user_id','approved_by_user_id'] as $column){$this->reject("UPDATE booking_amendments SET {$column}=999999999 WHERE id={$amend}",1452);}
            $this->reject("UPDATE booking_amendments SET status='Invalid' WHERE id={$amend}",3819);
            foreach(['Approved','Rejected','Applied'] as $status){$this->reject("UPDATE booking_amendments SET status='{$status}' WHERE id={$amend}",3819);}
            foreach(['Increase','Decrease'] as $direction){
                $this->pdo->exec("UPDATE booking_amendments SET financial_effect_amount=12.34,financial_effect_direction='{$direction}' WHERE id={$amend}");
                self::assertSame(['financial_effect_amount'=>'12.34','financial_effect_direction'=>$direction],$this->rows("SELECT financial_effect_amount,financial_effect_direction FROM booking_amendments WHERE id={$amend}")[0]);
            }
            $this->reject("UPDATE booking_amendments SET financial_effect_amount=-1 WHERE id={$amend}",3819);
            $this->reject("UPDATE booking_amendments SET financial_effect_direction='None' WHERE id={$amend}",3819);
            $evidence=$this->rows("SELECT reason,description,before_summary,after_summary FROM booking_amendments WHERE id={$amend}");
            foreach(['Approved','Rejected','Applied'] as $status){
                $this->pdo->exec("UPDATE booking_amendments SET status='{$status}',approved_by_user_id={$user},approved_at_utc=UTC_TIMESTAMP(6) WHERE id={$amend}");
                self::assertSame($status,$this->rows("SELECT status FROM booking_amendments WHERE id={$amend}")[0]['status']);
                self::assertSame($evidence,$this->rows("SELECT reason,description,before_summary,after_summary FROM booking_amendments WHERE id={$amend}"));
            }
            $this->reject("DELETE FROM bookings WHERE id={$booking}",1451);
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
