<?php
declare(strict_types=1);
namespace PYH\Tests\Workflow;
use DomainException;use PDO;use PHPUnit\Framework\TestCase;use PYH\Application\AuditService;use PYH\Application\ProposalService;use PYH\Application\QuoteService;use PYH\Application\SearchService;use PYH\Database\ConnectionFactory;use PYH\Database\TestDatabasePolicy;use PYH\Domain\Quote\QuoteStatus;use PYH\Security\Actor;use PYH\Security\Password;use PYH\Security\PermissionEvaluator;use RuntimeException;
final class BookingConversionWorkflowTest extends TestCase{
 private PDO $pdo;private Actor $actor;private QuoteService $quotes;private ProposalService $proposals;private int $customer;private int $traveller;private int $enquiry;
 protected function setUp():void{$database=getenv('TEST_DB_DATABASE');$environment=getenv('APP_ENV');$host=getenv('TEST_DB_HOST')?:'127.0.0.1';if(!is_string($database)||!is_string($environment)){self::markTestSkipped('Phase 3 database is not configured.');}TestDatabasePolicy::assertDisposable($environment,$host,$database);$this->pdo=ConnectionFactory::create(['host'=>$host,'port'=>(int)(getenv('TEST_DB_PORT')?:3306),'database'=>$database,'username'=>getenv('TEST_DB_USERNAME')?:'','password'=>getenv('TEST_DB_PASSWORD')?:'','charset'=>'utf8mb4']);$this->clear();$this->seed();$audit=new AuditService($this->pdo,'test');$this->quotes=new QuoteService($this->pdo,new PermissionEvaluator(),$audit);$this->proposals=new ProposalService($this->pdo,new PermissionEvaluator(),$audit,$this->quotes);}
 private function conversion():\PYH\Application\QuoteBookingConversionService{return new \PYH\Application\QuoteBookingConversionService($this->pdo,new PermissionEvaluator(),new AuditService($this->pdo,'test'));}
 private function bookings():\PYH\Application\BookingService{return new \PYH\Application\BookingService($this->pdo,new PermissionEvaluator(),new AuditService($this->pdo,'test'));}
 /** @return array{int,int} */
 private function accepted():array{
  $id=$this->baseQuote('Package Holiday');$this->quotes->attachTraveller($this->actor,$id,$this->traveller,'Adult',true);
  $this->quotes->addComponent($this->actor,$id,['component_type'=>'Other','title'=>'Holiday package','supplier'=>'Main Supplier','supplier_reference'=>'MAIN-123','selling_price'=>'1234.56','supplier_cost'=>'1000.01','commission_amount'=>'234.55']);
  $this->passCompliance($id);$this->quotes->transition($this->actor,$id,QuoteStatus::Ready);$p=$this->proposals->generate($this->actor,$id);$this->proposals->recordSent($this->actor,$id,$p,'In Person',null);$this->proposals->recordDecision($this->actor,$id,$p,QuoteStatus::Accepted,'Customer');
  self::assertSame('Quoted',$this->scalar("SELECT status FROM enquiries WHERE id={$this->enquiry}"));
  return [$id,$this->proposals->bookingHandoff($this->actor,$id)];
 }
 public function testConversionWorkflowSnapshotsAndDuplicate():void{
  [$q,$h]=$this->accepted();$this->pdo->exec("UPDATE travellers SET first_name='Changed',date_of_birth='1990-02-03' WHERE id={$this->traveller}");
  $id=$this->conversion()->convert($this->actor,$q,$h);$b=$this->bookings()->load($this->actor,$id);
  self::assertSame('Booked',$b['status']);self::assertSame('1234.56',$b['core_selling_price']);self::assertSame('1000.01',$b['supplier_cost']);self::assertSame('234.55',$b['commission']);self::assertSame('MAIN-123',$b['supplier_reference']);self::assertSame($h,(int)$b['quote_booking_handoff_id']);self::assertSame($q,(int)$b['quote_id']);self::assertSame('2027-06-01',$b['departure_date']);
  $travellers=$this->bookings()->travellers($this->actor,$id);self::assertCount(1,$travellers);self::assertSame('Jane',$travellers[0]['first_name_snapshot']);self::assertSame('1980-01-01',$travellers[0]['date_of_birth_snapshot']);$this->pdo->exec("UPDATE travellers SET last_name='Changed again' WHERE id={$this->traveller}");self::assertSame($travellers,$this->bookings()->travellers($this->actor,$id));self::assertSame(1,(int)$travellers[0]['lead_traveller']);
  self::assertSame('Converted',$this->scalar("SELECT status FROM quotes WHERE id={$q}"));self::assertSame('Booked',$this->scalar("SELECT status FROM enquiries WHERE id={$this->enquiry}"));self::assertSame(0,(int)$this->scalar("SELECT COUNT(*) FROM enquiries WHERE status IN ('New','Contacted','Quoted')"));
  $this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,$h));self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM bookings'));
  self::assertSame('1234.56',$this->bookings()->totals($this->actor,$id)['balance']);self::assertSame($q,$this->bookings()->lineage($this->actor,$id)['quote_id']);
  $this->bookings()->update($this->actor,$id,['supplier_booking_reference'=>'CONF-1']);self::assertSame('Amended',$this->bookings()->load($this->actor,$id)['status']);
  foreach(['Cancelled','Travelled','Returned'] as $status){$this->expectRuntimeFailure(fn()=>$this->bookings()->update($this->actor,$id,['status'=>$status]));}
  foreach(['quote_id','customer_id','organisation_id','core_selling_price'] as $field){$this->expectRuntimeFailure(fn()=>$this->bookings()->update($this->actor,$id,[$field=>999]));}
 }
 public function testFailureAfterTravellerInsertRollsBackAllWrites():void{
  [$q,$h]=$this->accepted();$before=(int)$this->scalar('SELECT COUNT(*) FROM audit_events');
  $this->pdo->exec("CREATE TRIGGER conversion_failure BEFORE UPDATE ON quotes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected failure'");
  try{$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,$h));}finally{$this->pdo->exec('DROP TRIGGER conversion_failure');}
  self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM bookings'));self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM booking_travellers'));self::assertSame($before,(int)$this->scalar('SELECT COUNT(*) FROM audit_events'));self::assertSame('Accepted',$this->scalar("SELECT status FROM quotes WHERE id={$q}"));self::assertSame('Quoted',$this->scalar("SELECT status FROM enquiries WHERE id={$this->enquiry}"));self::assertGreaterThan(0,$this->conversion()->convert($this->actor,$q,$h));
 }
 public function testReadinessAndScopeFailuresAreControlled():void{
  [$q,$h]=$this->accepted();
  foreach(['Draft','Ready','Sent','Declined','Expired'] as $status){$this->pdo->exec("UPDATE quotes SET status='{$status}' WHERE id={$q}");$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,$h));}
  $this->pdo->exec("UPDATE quotes SET status='Accepted' WHERE id={$q}");$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,999999));
  $this->pdo->exec("UPDATE quote_booking_handoffs SET readiness_status='Blocked' WHERE id={$h}");$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,$h));$this->pdo->exec("UPDATE quote_booking_handoffs SET readiness_status='Ready' WHERE id={$h}");
  $actors=[new Actor($this->actor->userId+999,$this->actor->organisationId,$this->actor->locationId,['bookings.view','bookings.create','bookings.edit','quotes.view','scope.own'],true),new Actor($this->actor->userId,$this->actor->organisationId,999999,['bookings.view','bookings.create','bookings.edit','quotes.view','scope.location'],true),new Actor($this->actor->userId,$this->actor->organisationId+999,$this->actor->locationId,['bookings.view','bookings.create','bookings.edit','quotes.view','scope.organisation'],true)];
  foreach($actors as $a){$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($a,$q,$h));}
  $id=$this->conversion()->convert($this->actor,$q,$h);
  foreach($actors as $a){self::assertSame([],$this->bookings()->list($a));foreach(['load','travellers','lineage','totals'] as $method){try{$this->bookings()->$method($a,$id);self::fail('IDOR allowed');}catch(RuntimeException $e){self::assertSame('Record not found.',$e->getMessage());}}$this->expectRuntimeFailure(fn()=>$this->bookings()->update($a,$id,['internal_notes'=>'Tamper']));}
 }
 public function testReferenceCollisionRetriesAndRelationalHandoffUniqueness():void{
  [$q,$h]=$this->accepted();$qref=(string)$this->scalar("SELECT reference FROM quotes WHERE id={$q}");
  $first=(new \PYH\Domain\Booking\BookingReferenceGenerator(static fn(int $n):string=>str_repeat("\x01",$n)))->generate('Package Holiday',$qref);
  $this->pdo->prepare("INSERT INTO bookings (booking_reference,organisation_id,customer_id,product_type,booked_date,created_by_user_id,updated_by_user_id) VALUES (?, ?, ?, 'Package Holiday','2026-09-05',?,?)")->execute([$first,$this->actor->organisationId,$this->customer,$this->actor->userId,$this->actor->userId]);
  $dummy=(int)$this->pdo->lastInsertId();$calls=0;$g=new \PYH\Domain\Booking\BookingReferenceGenerator(static function(int $n)use(&$calls):string{return str_repeat(chr(++$calls),$n);});
  $service=new \PYH\Application\QuoteBookingConversionService($this->pdo,new PermissionEvaluator(),new AuditService($this->pdo,'test'),$g);
  $id=$service->convert($this->actor,$q,$h);self::assertSame(2,$calls);self::assertNotSame($first,$this->bookings()->load($this->actor,$id)['booking_reference']);
  try{$this->pdo->exec("UPDATE bookings SET quote_booking_handoff_id={$h} WHERE id={$dummy}");self::fail('Duplicate handoff accepted');}catch(\PDOException $e){self::assertSame(1062,$e->errorInfo[1]??null);}
 }
 public function testInvalidAcceptedEvidenceAndMissingCapability():void{
  [$q,$h]=$this->accepted();$this->expectRuntimeFailure(fn()=>$this->quotes->transition($this->actor,$q,QuoteStatus::Converted));$actor=new Actor($this->actor->userId,$this->actor->organisationId,$this->actor->locationId,['bookings.view','quotes.view','scope.own'],true);
  $this->expectRuntimeFailure(fn()=>$this->conversion()->convert($actor,$q,$h));
  $this->pdo->exec("UPDATE proposal_versions SET finalised_at_utc=NULL WHERE quote_id={$q}");$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,$h));
  $this->pdo->exec("UPDATE proposal_versions SET finalised_at_utc=UTC_TIMESTAMP(6) WHERE quote_id={$q}");
  $this->pdo->exec("UPDATE quote_booking_handoffs SET snapshot=JSON_SET(snapshot,'$.pricing.customer_total','999.00') WHERE id={$h}");$this->expectRuntimeFailure(fn()=>$this->conversion()->convert($this->actor,$q,$h));
  self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM bookings'));self::assertSame('Accepted',$this->scalar("SELECT status FROM quotes WHERE id={$q}"));
 }
 protected function tearDown():void{if(isset($this->pdo)){$this->clear();}}
 private function baseQuote(string $type):int{return$this->quotes->create($this->actor,['customer_id'=>$this->customer,'enquiry_id'=>$this->enquiry,'product_type'=>$type,'title'=>$type.' proposal','departure_date'=>'2027-06-01','return_date'=>'2027-06-08','expires_at_utc'=>'2027-05-01 12:00:00']);}
 private function passCompliance(int $id):void{$e=array_fill_keys(['total_price_clear','mandatory_charges_included','material_information_present','supplier_identity_present','deposit_balance_clear','significant_terms_present','availability_caveat_present'],true);self::assertSame('Pass',$this->quotes->reviewCompliance($this->actor,$id,$e));}
 private function seed():void{$this->pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('PYH Ltd','PYH')");$org=(int)$this->pdo->lastInsertId();$this->pdo->exec("INSERT INTO locations (organisation_id,name,internal_code) VALUES ({$org},'HQ','HQ')");$loc=(int)$this->pdo->lastInsertId();$hash=$this->pdo->quote(Password::hash('safe-password-123'));$this->pdo->exec("INSERT INTO users (organisation_id,location_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$org},{$loc},'Alex','Agent','alex@example.test',{$hash},'A1')");$user=(int)$this->pdo->lastInsertId();$statement=$this->pdo->query('SELECT permission_key FROM permissions');self::assertNotFalse($statement);$permissions=array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)));$this->actor=new Actor($user,$org,$loc,$permissions,true);$this->pdo->exec("INSERT INTO customers (organisation_id,owning_location_id,owning_agent_id,first_name,last_name,email,created_by_user_id,updated_by_user_id) VALUES ({$org},{$loc},{$user},'Jane','Doe','jane@example.test',{$user},{$user})");$this->customer=(int)$this->pdo->lastInsertId();$this->pdo->exec("INSERT INTO travellers (customer_id,first_name,last_name,date_of_birth) VALUES ({$this->customer},'Jane','Doe','1980-01-01')");$this->traveller=(int)$this->pdo->lastInsertId();$this->pdo->exec("INSERT INTO enquiries (reference,organisation_id,customer_id,assigned_location_id,assigned_agent_id,status,product_type,destinations,created_by_user_id,updated_by_user_id) VALUES ('PYH-E-TEST',{$org},{$this->customer},{$loc},{$user},'Contacted','Package Holiday',JSON_ARRAY('Madeira'),{$user},{$user})");$this->enquiry=(int)$this->pdo->lastInsertId();}
 private function clear():void{foreach(['booking_travellers','bookings','quote_booking_handoffs','quote_decisions','proposal_deliveries','proposal_versions','quote_compliance_reviews','quote_adjustments','quote_flight_sectors','quote_accommodation_details','quote_cruise_details','quote_travellers','quote_components','quotes','audit_events','communications','tasks','enquiry_assignment_history','enquiries','travellers','customers','consultant_profiles','user_roles'] as $t){$this->pdo->exec("DELETE FROM {$t}");}$this->pdo->exec('DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.organisation_id IS NOT NULL');$this->pdo->exec('DELETE FROM roles WHERE organisation_id IS NOT NULL');foreach(['users','locations','organisations'] as $t){$this->pdo->exec("DELETE FROM {$t}");}}
 private function scalar(string $sql):mixed{$s=$this->pdo->query($sql);self::assertNotFalse($s);return$s->fetchColumn();}
 /** @param callable():mixed $operation */ private function expectRuntimeFailure(callable $operation):void{try{$operation();self::fail('Access was allowed.');}catch(RuntimeException){self::addToAssertionCount(1);}}
}
