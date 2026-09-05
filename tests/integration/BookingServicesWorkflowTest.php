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

final class BookingServicesWorkflowTest extends TestCase
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
        $this->reset();
        $this->seed();
    }

    private \PYH\Security\Actor $actor;
    private int $bookingId;
    private int $otherBooking;
    private function reset():void{$this->emptyDatabase();(new Installer($this->pdo,$this->root.'/database/schema/001_canonical.sql',new DatabaseChangeAuditor(new FileLogger($this->root.'/storage/logs/operations-test.log'),'test')))->install();}
    protected function tearDown():void{if(isset($this->pdo)){$this->reset();}}
    private function seed():void
    {
        $this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Operations','Operations')");$org=(int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO locations (organisation_id,name,internal_code) VALUES ({$org},'HQ','HQ')");$loc=(int)$this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO users (organisation_id,location_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},{$loc},'Ops','Tester','ops@example.test','test-only','OPS')");$user=(int)$this->pdo->lastInsertId();
        $permissions=array_column($this->rows('SELECT permission_key FROM permissions'),'permission_key');$this->actor=new \PYH\Security\Actor($user,$org,$loc,$permissions,true);
        $this->pdo->exec("INSERT INTO customers (organisation_id,owning_location_id,owning_agent_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES ({$org},{$loc},{$user},'Test','Customer',{$user},{$user})");$customer=(int)$this->pdo->lastInsertId();
        foreach(['A','B'] as $n){$this->pdo->exec("INSERT INTO bookings (booking_reference,organisation_id,location_id,assigned_user_id,customer_id,product_type,booked_date,departure_date,return_date,core_selling_price,commission,supplier_name,supplier_reference,supplier_booking_reference,created_by_user_id,updated_by_user_id) VALUES ('OPS-{$n}',{$org},{$loc},{$user},{$customer},'Cruise','2026-01-31','2026-06-15','2026-06-22',1000.00,101.01,'Main Supplier','MAIN','CONFIRMED',{$user},{$user})");if($n==='A'){$this->bookingId=(int)$this->pdo->lastInsertId();}else{$this->otherBooking=(int)$this->pdo->lastInsertId();}}
    }
    /**
     * @template T of \PYH\Application\BookingOperation
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class):\PYH\Application\BookingOperation{return new $class($this->pdo,new \PYH\Security\PermissionEvaluator(),new \PYH\Application\AuditService($this->pdo,'test'));}
    /** @param callable():mixed $fn */
    private function denied(callable $fn,?string $message=null):void{try{$fn();self::fail('Operation unexpectedly allowed.');}catch(\RuntimeException $e){if($message!==null){self::assertSame($message,$e->getMessage());}else{self::addToAssertionCount(1);}}}
    private function element():int{return $this->service(\PYH\Application\BookingElementService::class)->save($this->actor,$this->bookingId,['element_type'=>'Transfer','title'=>'Private transfer','supplier_name'=>'Element Supplier','supplier_reference'=>'ELEMENT','selling_price'=>'100.00','supplier_cost'=>'75.00','commission'=>'25.00']);}

    public function testElementsPaymentsSupplierAndNoCredentialWrites():void
    {
        $a=$this->actor;$b=$this->bookingId;$e=$this->element();$s=$this->service(\PYH\Application\BookingElementService::class);
        $before=$this->rows("SELECT supplier_name,supplier_reference,supplier_booking_reference FROM bookings WHERE id={$b}");
        $s->save($a,$b,['title'=>'Changed transfer'],$e);self::assertSame('Changed transfer',$s->list($a,$b)[0]['title']);$s->save($a,$b,['selling_price'=>'101.00'],$e);$s->save($a,$b,['selling_price'=>'100.00'],$e);$audit=json_decode((string)$this->rows("SELECT after_summary FROM audit_events WHERE action='booking.element_saved' ORDER BY id DESC LIMIT 1")[0]['after_summary'],true,512,JSON_THROW_ON_ERROR);self::assertSame('101.00',$audit['before']['selling_price']);self::assertSame('100.00',$audit['after']['selling_price']);
        $p=$this->service(\PYH\Application\BookingPaymentService::class);$p->record($a,$b,['transaction_type'=>'Payment','amount'=>'500.10','transaction_reference'=>'HAYS-RECORD']);$p->record($a,$b,['transaction_type'=>'Refund','amount'=>'50.05']);
        self::assertSame(['currency'=>'GBP','customer_total'=>'1100.00','payments'=>'500.10','refunds'=>'50.05','net_paid'=>'450.05','balance'=>'649.95'],$p->position($a,$b));
        $this->denied(fn()=>$p->record($a,$b,['transaction_type'=>'Refund','amount'=>'450.06']));$this->denied(fn()=>$p->record($a,$b,['transaction_type'=>'Payment','amount'=>'-1']));$this->denied(fn()=>$p->record($a,$b,['transaction_type'=>'Payment','amount'=>'1','card_number'=>'4111111111111111']));$this->denied(fn()=>$p->record($a,$b,['transaction_type'=>'Payment','amount'=>'1','notes'=>'CVV 123']));self::assertCount(2,$p->history($a,$b));
        $supplier=$this->service(\PYH\Application\BookingSupplierPaymentService::class);$id=$supplier->save($a,$b,['booking_element_id'=>$e,'amount'=>'75','status'=>'Due']);$this->denied(fn()=>$supplier->save($a,$this->otherBooking,['booking_element_id'=>$e,'amount'=>'75']));$this->denied(fn()=>$supplier->save($a,$b,['status'=>'Paid'],$id));$supplier->save($a,$b,['status'=>'Paid','paid_date'=>'2026-09-05'],$id);$this->denied(fn()=>$supplier->save($a,$b,['amount'=>'80'],$id));
        $s->archive($a,$b,$e);self::assertSame('Archived',$s->list($a,$b)[0]['status']);self::assertSame($before,$this->rows("SELECT supplier_name,supplier_reference,supplier_booking_reference FROM bookings WHERE id={$b}"));self::assertSame('1000.00',$p->position($a,$b)['customer_total']);
    }
    public function testCommissionScheduleIdempotencyAndReceivedHistory():void
    {
        $a=$this->actor;$b=$this->bookingId;$e=$this->element();$s=$this->service(\PYH\Application\BookingCommissionService::class);$s->generate($a,$b);$s->generate($a,$b);$s->generate($a,$b,$e);self::assertCount(4,$s->list($a,$b));$core=array_values(array_filter($s->list($a,$b),static fn(array $r):bool=>$r['booking_element_id']===null));
        self::assertSame(['50.50','50.51'],array_column($core,'amount'));self::assertSame(['2026-02-07','2026-07-07'],array_column($core,'due_date'));
        $id=(int)$core[0]['id'];$s->status($a,$b,$id,'Due');$s->status($a,$b,$id,'Received','2026-09-05');$received=$this->rows("SELECT * FROM booking_commission_ledger WHERE id={$id}");$this->denied(fn()=>$s->status($a,$b,$id,'Due'));
        foreach(['Clawback','Post-Cancellation'] as $type){$s->exceptional($a,$b,['instalment_type'=>$type,'amount'=>'10','expected_date'=>'2026-10-01','notes'=>'External reconciled evidence']);}
        self::assertSame($received,$this->rows("SELECT * FROM booking_commission_ledger WHERE id={$id}"));self::assertSame('50.50',$s->totals($a,$b)['Received']);self::assertSame('10.00',$s->totals($a,$b)['Owed']);
    }
    public function testAdjustmentCapabilitiesHistoryAndAtomicRollback():void
    {
        $a=$this->actor;$b=$this->bookingId;$s=$this->service(\PYH\Application\BookingAdjustmentService::class);$id=$s->submit($a,$b,['adjustment_type'=>'Discount','amount'=>'20','direction'=>'Decrease','reason'=>'Goodwill']);
        $noSelf=new \PYH\Security\Actor($a->userId,$a->organisationId,$a->locationId,array_values(array_diff($a->permissions,['bookings.self_approve_adjustment'])),true);$this->denied(fn()=>$s->decide($noSelf,$b,$id,'Approved','Self'));$s->decide($a,$b,$id,'Approved','Authorised owner');self::assertSame('980.00',$this->service(\PYH\Application\BookingPaymentService::class)->position($a,$b)['customer_total']);$s->decide($a,$b,$id,'Reversed','Reversal evidence');self::assertSame(['Submitted','Approved','Reversed'],array_column($this->rows("SELECT action FROM booking_adjustment_approvals WHERE adjustment_id={$id} ORDER BY id"),'action'));
        $reject=$s->submit($a,$b,['adjustment_type'=>'Fee','amount'=>'10','direction'=>'Increase','reason'=>'Fee']);$s->decide($a,$b,$reject,'Rejected','Not warranted');
        $pending=$s->submit($a,$b,['adjustment_type'=>'Fee','amount'=>'5','direction'=>'Increase','reason'=>'Decision rollback']);
        $this->pdo->exec("CREATE TRIGGER fail_operations_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Forced audit failure'");
        try{$this->denied(fn()=>$s->decide($a,$b,$pending,'Approved','Forced rollback'));$this->denied(fn()=>$s->submit($a,$b,['adjustment_type'=>'Fee','amount'=>'10','direction'=>'Increase','reason'=>'Rollback']));$this->denied(fn()=>$this->service(\PYH\Application\BookingCommissionService::class)->generate($a,$b));}finally{$this->pdo->exec('DROP TRIGGER fail_operations_audit');}
        self::assertCount(3,$s->list($a,$b));self::assertSame('Pending',$this->rows("SELECT status FROM booking_financial_adjustments WHERE id={$pending}")[0]['status']);self::assertSame(['Submitted'],array_column($this->rows("SELECT action FROM booking_adjustment_approvals WHERE adjustment_id={$pending}"),'action'));self::assertCount(0,$this->rows('SELECT id FROM booking_commission_ledger'));$this->reject("DELETE FROM booking_adjustment_approvals WHERE adjustment_id={$id}",1644);
    }
    public function testDocumentsChecklistAmendmentsAndSafeProjection():void
    {
        $a=$this->actor;$b=$this->bookingId;$e=$this->element();$docs=$this->service(\PYH\Application\BookingDocumentService::class);$private=$docs->register($a,$b,['document_type'=>'Supplier Correspondence','original_filename'=>'PRIVATE-SUPPLIER.pdf','storage_reference'=>'opaque_private_123','mime_type'=>'application/pdf','notes'=>'PRIVATE-NOTES']);$public=$docs->register($a,$b,['booking_element_id'=>$e,'document_type'=>'ATOL Certificate','original_filename'=>'atol.pdf','storage_reference'=>'opaque_public_123','mime_type'=>'application/pdf']);self::assertSame('Agent only',$docs->list($a,$b)[0]['visibility']);$docs->visibility($a,$b,$public,'Customer visible');$this->denied(fn()=>$docs->register($a,$this->otherBooking,['booking_element_id'=>$e,'document_type'=>'Other','original_filename'=>'x','storage_reference'=>'opaque_public_123','mime_type'=>'text/plain']));
        $check=$this->service(\PYH\Application\BookingChecklistService::class);$check->initialise($a,$b);$check->initialise($a,$b);self::assertSame(4,$check->summary($a,$b)['total']);$item=(int)$check->list($a,$b)[0]['id'];$check->save($a,$b,['due_at_utc'=>'2020-01-01 00:00:00'],$item);self::assertSame(1,$check->summary($a,$b)['overdue']);$check->save($a,$b,['status'=>'Completed'],$item);self::assertSame(1,$check->summary($a,$b)['complete']);self::assertTrue($check->evidence($a,$b)['atol_certificate_present']);
        $amend=$this->service(\PYH\Application\BookingAmendmentService::class);$id=$amend->draft($a,$b,['reason'=>'Customer change','description'=>'Travel one day later','after_summary'=>'{"departure_date":"2026-06-16"}','financial_effect_amount'=>'10','financial_effect_direction'=>'Increase']);$amend->transition($a,$b,$id,'Pending');$amend->transition($a,$b,$id,'Approved');$before=$this->rows("SELECT before_summary,after_summary FROM booking_amendments WHERE id={$id}");$this->pdo->exec("CREATE TRIGGER fail_amendment_audit BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Forced failure'");try{$this->denied(fn()=>$amend->transition($a,$b,$id,'Applied'));}finally{$this->pdo->exec('DROP TRIGGER fail_amendment_audit');}self::assertSame('2026-06-15',$this->rows("SELECT departure_date FROM bookings WHERE id={$b}")[0]['departure_date']);$amend->transition($a,$b,$id,'Applied');self::assertSame('2026-06-16',$this->rows("SELECT departure_date FROM bookings WHERE id={$b}")[0]['departure_date']);self::assertSame($before,$this->rows("SELECT before_summary,after_summary FROM booking_amendments WHERE id={$id}"));
        $projection=$this->service(\PYH\Application\CustomerBookingProjection::class)->project($a,$b);$json=json_encode($projection,JSON_THROW_ON_ERROR);foreach(['supplier_cost','commission','margin','clawback','internal_notes','PRIVATE-SUPPLIER','PRIVATE-NOTES','opaque_private','storage_reference','supplier_reference','audit'] as $forbidden){self::assertStringNotContainsString($forbidden,$json);}self::assertCount(1,$projection['documents']);self::assertSame('ATOL Certificate',$projection['documents'][0]['document_type']);self::assertSame('1110.00',$projection['customer_position']['customer_total']);
        $this->denied(fn()=>(new \PYH\Application\BookingService($this->pdo,new \PYH\Security\PermissionEvaluator(),new \PYH\Application\AuditService($this->pdo,'test')))->update($a,$b,['departure_date'=>'2026-06-17']));
    }
    public function testEveryServiceScopeAndFinanceDisclosure():void
    {
        $a=$this->actor;$b=$this->bookingId;$this->element();$classes=[\PYH\Application\BookingElementService::class,\PYH\Application\BookingSupplierPaymentService::class,\PYH\Application\BookingCommissionService::class,\PYH\Application\BookingAdjustmentService::class,\PYH\Application\BookingDocumentService::class,\PYH\Application\BookingChecklistService::class,\PYH\Application\BookingAmendmentService::class,\PYH\Application\BookingTimelineService::class];
        foreach([new \PYH\Security\Actor($a->userId+99,$a->organisationId,$a->locationId,array_values(array_diff($a->permissions,['scope.location','scope.organisation'])),true),new \PYH\Security\Actor($a->userId,$a->organisationId,99999,array_values(array_diff($a->permissions,['scope.organisation'])),true),new \PYH\Security\Actor($a->userId,$a->organisationId+99,$a->locationId,$a->permissions,true)] as $other){foreach($classes as $class){$this->denied(fn()=>$this->service($class)->list($other,$b),'Record not found.');}$this->denied(fn()=>$this->service(\PYH\Application\BookingPaymentService::class)->record($other,$b,['transaction_type'=>'Payment','amount'=>'1']),'Record not found.');$this->denied(fn()=>$this->service(\PYH\Application\CustomerBookingProjection::class)->project($other,$b),'Record not found.');}
        $ordinary=new \PYH\Security\Actor($a->userId,$a->organisationId,$a->locationId,['bookings.view','bookings.edit','scope.own'],true);foreach([\PYH\Application\BookingSupplierPaymentService::class,\PYH\Application\BookingCommissionService::class,\PYH\Application\BookingAdjustmentService::class] as $class){$this->denied(fn()=>$this->service($class)->list($ordinary,$b),'Permission denied.');}
        $json=json_encode($this->service(\PYH\Application\BookingElementService::class)->list($ordinary,$b),JSON_THROW_ON_ERROR);self::assertStringNotContainsString('supplier_cost',$json);self::assertStringNotContainsString('commission',$json);
    }
    public function testChildRecordIdorAndSearchDashboardBoundaries():void
    {
        $a=$this->actor;$b=$this->bookingId;$other=$this->otherBooking;$e=$this->element();
        $this->denied(fn()=>$this->service(\PYH\Application\BookingElementService::class)->save($a,$other,['title'=>'Tamper'],$e),'Record not found.');
        $supplier=$this->service(\PYH\Application\BookingSupplierPaymentService::class);$sp=$supplier->save($a,$b,['amount'=>'10']);$this->denied(fn()=>$supplier->save($a,$other,['amount'=>'20'],$sp),'Record not found.');
        $commission=$this->service(\PYH\Application\BookingCommissionService::class);$commission->generate($a,$b);$ledger=(int)$commission->list($a,$b)[0]['id'];$this->denied(fn()=>$commission->status($a,$other,$ledger,'Received','2026-09-05'),'Record not found.');
        $adjust=$this->service(\PYH\Application\BookingAdjustmentService::class);$ad=$adjust->submit($a,$b,['adjustment_type'=>'Fee','amount'=>'1','direction'=>'Increase','reason'=>'Test']);$this->denied(fn()=>$adjust->decide($a,$other,$ad,'Approved','Tamper'),'Record not found.');
        $docs=$this->service(\PYH\Application\BookingDocumentService::class);$doc=$docs->register($a,$b,['document_type'=>'Other','original_filename'=>'test.pdf','storage_reference'=>'opaque_test_key','mime_type'=>'application/pdf']);$this->denied(fn()=>$docs->visibility($a,$other,$doc,'Customer visible'),'Record not found.');
        $check=$this->service(\PYH\Application\BookingChecklistService::class);$check->initialise($a,$b);$item=(int)$check->list($a,$b)[0]['id'];$this->denied(fn()=>$check->save($a,$other,['status'=>'Completed'],$item),'Record not found.');
        $amend=$this->service(\PYH\Application\BookingAmendmentService::class);$am=$amend->draft($a,$b,['reason'=>'Test','description'=>'Test','after_summary'=>'{"departure_date":"2026-06-16"}']);$this->denied(fn()=>$amend->transition($a,$other,$am,'Pending'),'Record not found.');
        $p=new \PYH\Security\PermissionEvaluator();$search=new \PYH\Application\SearchService($this->pdo,$p);self::assertCount(2,$search->search($a,'OPS-'));$dashboard=new \PYH\Application\DashboardService($this->pdo,$p);self::assertSame(2,$dashboard->bookingAttention($a)['active_bookings']);
        $denied=new \PYH\Security\Actor($a->userId+100,$a->organisationId,$a->locationId,['bookings.view','scope.own'],true);self::assertSame([],$search->search($denied,'OPS-'));self::assertSame(0,$dashboard->bookingAttention($denied)['active_bookings']);
        $ordinary=new \PYH\Security\Actor($a->userId,$a->organisationId,$a->locationId,['bookings.view','scope.own'],true);$core=new \PYH\Application\BookingService($this->pdo,$p,new \PYH\Application\AuditService($this->pdo,'test'));self::assertArrayNotHasKey('supplier_cost',$core->load($ordinary,$b));self::assertArrayNotHasKey('commission',$core->list($ordinary)[0]);
        $timeline=json_encode($this->service(\PYH\Application\BookingTimelineService::class)->list($ordinary,$b),JSON_THROW_ON_ERROR);self::assertStringNotContainsString('Commission',$timeline);self::assertStringNotContainsString('Adjustment',$timeline);
    }
    public function testPermissionUpgradeParityAndIdempotency():void
    {
        $this->reset();$seedSql="SELECT r.name,p.permission_key FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE p.permission_key IN ('bookings.finance_view','bookings.finance_manage','bookings.record_payment','bookings.self_approve_adjustment') ORDER BY r.name,p.permission_key";
        $expected=$this->rows($seedSql);self::assertCount(11,$expected);$tables=$this->rows('SHOW TABLES');$ddl=[];foreach($tables as $row){$name=(string)array_values($row)[0];$ddl[$name]=$this->rows('SHOW CREATE TABLE `'.$name.'`')[0]['Create Table'];}
        $this->emptyDatabase();SqlFileRunner::run($this->pdo,$this->root.'/tests/fixtures/phase3_canonical.sql');$this->pdo->exec("INSERT INTO installation_metadata (schema_version,installed_at_utc) VALUES ('3.0.0-quote-engine',UTC_TIMESTAMP(6))");
        foreach(glob($this->root.'/database/migrations/*.sql')?:[] as $path){$name=basename($path);if($name<'20260905_140000'||$name>='20260905_220000'){continue;}SqlFileRunner::run($this->pdo,$path);$this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$name]);}
        self::assertSame('4.1.0-booking-core',$this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
        $m=new Migrator($this->pdo,$this->root.'/database/migrations',new DatabaseChangeAuditor(new FileLogger($this->root.'/storage/logs/operations-upgrade.log'),'test'));$m->migrate();self::assertSame($expected,$this->rows($seedSql));
        foreach($ddl as $name=>$definition){self::assertSame($definition,$this->rows('SHOW CREATE TABLE `'.$name.'`')[0]['Create Table']);}
        $ledger=$this->rows('SELECT * FROM schema_migrations ORDER BY migration');$m->migrate();self::assertSame($ledger,$this->rows('SELECT * FROM schema_migrations ORDER BY migration'));self::assertSame($expected,$this->rows($seedSql));self::assertSame('4.2.0-booking-operations',$this->rows('SELECT schema_version FROM installation_metadata')[0]['schema_version']);
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
