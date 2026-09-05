<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Domain\Enquiry\EnquiryStatus;
use PYH\Domain\Quote\ComplianceEvaluator;
use PYH\Domain\Quote\Money;
use PYH\Domain\Quote\PricingCalculator;
use PYH\Domain\Quote\QuoteReferenceGenerator;
use PYH\Domain\Quote\QuoteStateMachine;
use PYH\Domain\Quote\QuoteStatus;
use PYH\Domain\Quote\ReadinessValidator;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use RuntimeException;

final class QuoteService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions, private readonly AuditService $audit) {}

    /** @param array<string,mixed> $data */
    public function create(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'quotes.create');
        $customerId = (int) ($data['customer_id'] ?? 0); $enquiryId = $this->nullableInt($data['enquiry_id'] ?? null);
        $location = $this->nullableInt($data['location_id'] ?? null) ?? $actor->locationId;
        $agent = $this->nullableInt($data['assigned_agent_id'] ?? null) ?? $actor->userId;
        $this->accessibleCustomer($actor, $customerId); $this->permissions->assertScoped($actor, $actor->organisationId, $location, $agent);
        if ($enquiryId !== null) { $this->accessibleEnquiry($actor, $enquiryId, $customerId); }
        $reference = (new QuoteReferenceGenerator())->generate();
        $sql = 'INSERT INTO quotes (reference,organisation_id,location_id,assigned_agent_id,customer_id,enquiry_id,product_type,title,currency,destination_summary,departure_date,return_date,duration_nights,departure_point,expires_at_utc,customer_introduction,customer_notes,internal_notes,created_by_user_id,updated_by_user_id) VALUES (:reference,:org,:location,:agent,:customer,:enquiry,:product,:title,:currency,:destination,:departure,:return_date,:duration,:departure_point,:expiry,:introduction,:customer_notes,:internal_notes,:created_by,:updated_by)';
        $this->pdo->prepare($sql)->execute(['reference'=>$reference,'org'=>$actor->organisationId,'location'=>$location,'agent'=>$agent,'customer'=>$customerId,'enquiry'=>$enquiryId,'product'=>$this->required($data,'product_type'),'title'=>$this->required($data,'title'),'currency'=>strtoupper((string)($data['currency'] ?? 'GBP')),'destination'=>$data['destination_summary']??null,'departure'=>$data['departure_date']??null,'return_date'=>$data['return_date']??null,'duration'=>$data['duration_nights']??null,'departure_point'=>$data['departure_point']??null,'expiry'=>$data['expires_at_utc']??null,'introduction'=>$data['customer_introduction']??null,'customer_notes'=>$data['customer_notes']??null,'internal_notes'=>$data['internal_notes']??null,'created_by'=>$actor->userId,'updated_by'=>$actor->userId]);
        $id=(int)$this->pdo->lastInsertId(); $this->audit->record($actor->organisationId,$actor->userId,'quote.created','quote',$id,null,['reference'=>$reference,'status'=>'Draft']); return $id;
    }

    /** @param array<string,mixed> $data */
    public function update(Actor $actor, int $quoteId, array $data): void
    {
        $this->permissions->assertAllowed($actor, 'quotes.edit'); $quote=$this->quote($actor,$quoteId);
        if (!in_array($quote['status'], ['Draft','Ready'], true)) { throw new RuntimeException('Sent or completed quotes require a governed revision.'); }
        $sql='UPDATE quotes SET product_type=:product,title=:title,currency=:currency,destination_summary=:destination,departure_date=:departure,return_date=:return_date,duration_nights=:duration,departure_point=:departure_point,expires_at_utc=:expiry,customer_introduction=:introduction,customer_notes=:customer_notes,internal_notes=:internal_notes,updated_by_user_id=:actor WHERE id=:id';
        $this->pdo->prepare($sql)->execute(['product'=>$this->required($data,'product_type'),'title'=>$this->required($data,'title'),'currency'=>strtoupper((string)($data['currency']??$quote['currency'])),'destination'=>$data['destination_summary']??null,'departure'=>$data['departure_date']??null,'return_date'=>$data['return_date']??null,'duration'=>$data['duration_nights']??null,'departure_point'=>$data['departure_point']??null,'expiry'=>$data['expires_at_utc']??null,'introduction'=>$data['customer_introduction']??null,'customer_notes'=>$data['customer_notes']??null,'internal_notes'=>$data['internal_notes']??null,'actor'=>$actor->userId,'id'=>$quoteId]);
        $this->audit->record($actor->organisationId,$actor->userId,'quote.edited','quote',$quoteId,['status'=>$quote['status']],['fields'=>'overview']);
    }

    public function attachTraveller(Actor $actor, int $quoteId, int $travellerId, string $type, bool $lead, string $notes=''): int
    {
        $this->assertEditable($actor,$quoteId); $quote=$this->quote($actor,$quoteId);
        $s=$this->pdo->prepare('SELECT t.* FROM travellers t JOIN customers c ON c.id=t.customer_id WHERE t.id=:id AND t.customer_id=:customer AND c.organisation_id=:org'); $s->execute(['id'=>$travellerId,'customer'=>$quote['customer_id'],'org'=>$actor->organisationId]); $t=$s->fetch();
        if(!is_array($t)){throw new RuntimeException('Traveller not found.');}
        if($lead){$this->pdo->prepare('UPDATE quote_travellers SET is_lead=FALSE WHERE quote_id=:quote')->execute(['quote'=>$quoteId]);}
        $order=(int)$this->scalar('SELECT COALESCE(MAX(display_order),0)+1 FROM quote_travellers WHERE quote_id=:id',['id'=>$quoteId]);
        $this->pdo->prepare('INSERT INTO quote_travellers (quote_id,traveller_id,display_order,traveller_type,is_lead,title_snapshot,first_name_snapshot,last_name_snapshot,date_of_birth_snapshot,proposal_notes) VALUES (:quote,:traveller,:position,:type,:lead,:title,:first,:last,:dob,:notes)')->execute(['quote'=>$quoteId,'traveller'=>$travellerId,'position'=>$order,'type'=>$type,'lead'=>$lead,'title'=>$t['title'],'first'=>$t['first_name'],'last'=>$t['last_name'],'dob'=>$t['date_of_birth'],'notes'=>$notes?:null]);
        $id=(int)$this->pdo->lastInsertId(); $this->audit->record($actor->organisationId,$actor->userId,'quote.traveller_attached','quote',$quoteId,null,['quote_traveller_id'=>$id]); return $id;
    }

    /** @param array<string,mixed> $data */
    public function addComponent(Actor $actor, int $quoteId, array $data): int
    {
        $this->assertEditable($actor,$quoteId); $position=(int)($data['display_order']??$this->scalar('SELECT COALESCE(MAX(display_order),0)+1 FROM quote_components WHERE quote_id=:id',['id'=>$quoteId]));
        foreach(['selling_price','supplier_cost','commission_amount'] as $money){if(Money::minor((string)($data[$money]??'0.00'))<0){throw new \DomainException('Component money values cannot be negative.');}}
        $sql='INSERT INTO quote_components (quote_id,component_type,display_order,title,supplier,supplier_reference,start_at_utc,end_at_utc,origin,destination,customer_description,customer_notes,internal_notes,inclusion_state,is_selected,selling_price,supplier_cost,commission_amount) VALUES (:quote,:type,:position,:title,:supplier,:supplier_reference,:start_at,:end_at,:origin,:destination,:description,:customer_notes,:internal_notes,:inclusion,:selected,:selling,:cost,:commission)';
        $this->pdo->prepare($sql)->execute(['quote'=>$quoteId,'type'=>$this->required($data,'component_type'),'position'=>$position,'title'=>$this->required($data,'title'),'supplier'=>$data['supplier']??null,'supplier_reference'=>$data['supplier_reference']??null,'start_at'=>$data['start_at_utc']??null,'end_at'=>$data['end_at_utc']??null,'origin'=>$data['origin']??null,'destination'=>$data['destination']??null,'description'=>$data['customer_description']??null,'customer_notes'=>$data['customer_notes']??null,'internal_notes'=>$data['internal_notes']??null,'inclusion'=>$data['inclusion_state']??'Included','selected'=>($data['is_selected']??false)?1:0,'selling'=>$data['selling_price']??'0.00','cost'=>$data['supplier_cost']??'0.00','commission'=>$data['commission_amount']??'0.00']);
        $id=(int)$this->pdo->lastInsertId(); $this->addDetail($id,(string)$data['component_type'],$data); $this->recalculate($actor,$quoteId); $this->audit->record($actor->organisationId,$actor->userId,'quote.component_added','quote',$quoteId,null,['component_id'=>$id,'type'=>$data['component_type']]); return $id;
    }

    /** @param array<string,mixed> $data */
    private function addDetail(int $id,string $type,array $data): void
    {
        if($type==='Accommodation'){$this->pdo->prepare('INSERT INTO quote_accommodation_details (component_id,property_name,resort,check_in_date,check_out_date,nights,room_type,board_basis,occupancy,room_count,included_features) VALUES (:id,:property,:resort,:checkin,:checkout,:nights,:room,:board,:occupancy,:count,:features)')->execute(['id'=>$id,'property'=>$this->required($data,'property_name'),'resort'=>$data['resort']??null,'checkin'=>$this->required($data,'check_in_date'),'checkout'=>$this->required($data,'check_out_date'),'nights'=>(int)($data['nights']??0),'room'=>$data['room_type']??null,'board'=>$data['board_basis']??null,'occupancy'=>$data['occupancy']??null,'count'=>(int)($data['room_count']??1),'features'=>json_encode($data['included_features']??[],JSON_THROW_ON_ERROR)]);}
        if($type==='Cruise'){$this->pdo->prepare('INSERT INTO quote_cruise_details (component_id,cruise_line,ship,sailing_date,duration_nights,embarkation_port,disembarkation_port,itinerary_summary,cabin_category,cabin_type,experience_notes,inclusions) VALUES (:id,:line,:ship,:sailing,:duration,:embark,:disembark,:itinerary,:category,:cabin,:experience,:inclusions)')->execute(['id'=>$id,'line'=>$this->required($data,'cruise_line'),'ship'=>$this->required($data,'ship'),'sailing'=>$this->required($data,'sailing_date'),'duration'=>(int)($data['duration_nights']??0),'embark'=>$this->required($data,'embarkation_port'),'disembark'=>$this->required($data,'disembarkation_port'),'itinerary'=>$data['itinerary_summary']??null,'category'=>$data['cabin_category']??null,'cabin'=>$data['cabin_type']??null,'experience'=>$data['experience_notes']??null,'inclusions'=>json_encode($data['inclusions']??[],JSON_THROW_ON_ERROR)]);}
    }

    /** @param array<string,mixed> $data */
    public function addFlightSector(Actor $actor,int $quoteId,int $componentId,array $data): int
    {
        $this->assertEditable($actor,$quoteId); $this->ownedComponent($quoteId,$componentId,'Flight');
        $this->pdo->prepare('INSERT INTO quote_flight_sectors (component_id,journey_direction,sector_order,airline,flight_number,departure_airport,arrival_airport,departure_at_utc,arrival_at_utc,cabin_class,baggage,connection_notes,duration_minutes) VALUES (:component,:direction,:position,:airline,:number,:departure,:arrival,:departure_at,:arrival_at,:cabin,:baggage,:connections,:duration)')->execute(['component'=>$componentId,'direction'=>$data['journey_direction']??'Other','position'=>(int)($data['sector_order']??1),'airline'=>$this->required($data,'airline'),'number'=>$data['flight_number']??null,'departure'=>$this->required($data,'departure_airport'),'arrival'=>$this->required($data,'arrival_airport'),'departure_at'=>$this->required($data,'departure_at_utc'),'arrival_at'=>$this->required($data,'arrival_at_utc'),'cabin'=>$data['cabin_class']??null,'baggage'=>$data['baggage']??null,'connections'=>$data['connection_notes']??null,'duration'=>$data['duration_minutes']??null]); return (int)$this->pdo->lastInsertId();
    }

    public function addAdjustment(Actor $actor,int $quoteId,string $type,string $amount,string $reason,string $visibility): int
    {
        $this->permissions->assertAllowed($actor,'quotes.adjust'); $this->assertEditable($actor,$quoteId); Money::minor($amount);
        $this->pdo->prepare('INSERT INTO quote_adjustments (quote_id,adjustment_type,amount,reason,visibility,created_by_user_id) VALUES (:quote,:type,:amount,:reason,:visibility,:actor)')->execute(['quote'=>$quoteId,'type'=>$type,'amount'=>$amount,'reason'=>$reason,'visibility'=>$visibility,'actor'=>$actor->userId]);
        $id=(int)$this->pdo->lastInsertId(); $this->recalculate($actor,$quoteId); $this->audit->record($actor->organisationId,$actor->userId,'quote.adjustment_applied','quote',$quoteId,null,['adjustment_id'=>$id,'type'=>$type,'amount'=>$amount,'visibility'=>$visibility]); return $id;
    }

    /** @return array{included_total:string, adjustments_total:string, customer_total:string, optional_total:string} */
    public function recalculate(Actor $actor,int $quoteId): array
    {
        $this->quote($actor,$quoteId); $components=$this->all('SELECT selling_price,inclusion_state,is_selected AS selected FROM quote_components WHERE quote_id=:id',['id'=>$quoteId]); $adjustments=$this->all('SELECT amount,adjustment_type FROM quote_adjustments WHERE quote_id=:id',['id'=>$quoteId]);
        $totals=(new PricingCalculator())->calculate($components,$adjustments); $quote=$this->quote($actor,$quoteId); $deposit=Money::minor((string)$quote['deposit_amount']); $balance=Money::minor($totals['customer_total'])-$deposit; if($balance<0){throw new RuntimeException('Deposit cannot exceed customer total.');}
        $this->pdo->prepare('UPDATE quotes SET included_total=:included,adjustments_total=:adjustments,customer_total=:total,optional_total=:optional,balance_amount=:balance,updated_by_user_id=:actor WHERE id=:id')->execute(['included'=>$totals['included_total'],'adjustments'=>$totals['adjustments_total'],'total'=>$totals['customer_total'],'optional'=>$totals['optional_total'],'balance'=>Money::decimal($balance),'actor'=>$actor->userId,'id'=>$quoteId]); return $totals;
    }

    /** @param array<string,mixed> $evidence */
    public function reviewCompliance(Actor $actor,int $quoteId,array $evidence,string $source='System'): string
    {
        $this->permissions->assertAllowed($actor,'quotes.compliance'); $this->quote($actor,$quoteId); $result=(new ComplianceEvaluator())->evaluate($evidence);
        $this->pdo->prepare('INSERT INTO quote_compliance_reviews (quote_id,result,reasons,evidence,source,reviewed_by_user_id) VALUES (:quote,:result,:reasons,:evidence,:source,:actor)')->execute(['quote'=>$quoteId,'result'=>$result['result'],'reasons'=>json_encode($result['reasons'],JSON_THROW_ON_ERROR),'evidence'=>json_encode($evidence,JSON_THROW_ON_ERROR),'source'=>$source,'actor'=>$actor->userId]);
        $this->audit->record($actor->organisationId,$actor->userId,'quote.compliance_evaluated','quote',$quoteId,null,['result'=>$result['result']]); return $result['result'];
    }

    /** @return array<string,list<string>> */
    public function readiness(Actor $actor,int $quoteId): array
    {
        $q=$this->quote($actor,$quoteId); $travellers=$this->all('SELECT * FROM quote_travellers WHERE quote_id=:id',['id'=>$quoteId]); $components=$this->all('SELECT * FROM quote_components WHERE quote_id=:id',['id'=>$quoteId]);
        $calculated=(new PricingCalculator())->calculate($components,$this->all('SELECT amount,adjustment_type FROM quote_adjustments WHERE quote_id=:id',['id'=>$quoteId])); $reconciles=$calculated['customer_total']===(string)$q['customer_total'];
        $compliance=(string)($this->scalar('SELECT COALESCE((SELECT result FROM quote_compliance_reviews WHERE quote_id=:id ORDER BY id DESC LIMIT 1),\'\')',['id'=>$quoteId]));
        $errors=(new ReadinessValidator())->validate($q,$travellers,$components,$reconciles,$compliance); $this->audit->record($actor->organisationId,$actor->userId,'quote.readiness_evaluated','quote',$quoteId,null,['ready'=>$errors===[],'groups'=>array_keys($errors)]); return $errors;
    }

    public function transition(Actor $actor,int $quoteId,QuoteStatus $to): void
    {
        if ($to === QuoteStatus::Converted) { throw new RuntimeException('Use the transactional booking conversion service.'); }
        $permission=match($to){QuoteStatus::Sent=>'quotes.send',QuoteStatus::Accepted=>'quotes.accept',QuoteStatus::Declined,QuoteStatus::Expired=>'quotes.decline',default=>'quotes.edit'}; $this->permissions->assertAllowed($actor,$permission);
        $q=$this->quote($actor,$quoteId); $from=QuoteStatus::from((string)$q['status']); (new QuoteStateMachine())->assertCanTransition($from,$to);
        if(in_array($to,[QuoteStatus::Ready,QuoteStatus::Sent],true)&&$this->readiness($actor,$quoteId)!==[]){throw new RuntimeException('Quote is not ready.');}
        if($to===QuoteStatus::Sent && (int)$this->scalar('SELECT COUNT(*) FROM proposal_deliveries d JOIN proposal_versions p ON p.id=d.proposal_version_id WHERE p.quote_id=:id AND p.version_number=:version',['id'=>$quoteId,'version'=>$q['revision_number']])<1){throw new RuntimeException('A finalised delivery record is required before Sent.');}
        $s=$this->pdo->prepare('UPDATE quotes SET status=:to,updated_by_user_id=:actor WHERE id=:id AND status=:from');$s->execute(['to'=>$to->value,'actor'=>$actor->userId,'id'=>$quoteId,'from'=>$from->value]);if($s->rowCount()!==1){throw new RuntimeException('Quote changed concurrently.');}
        if($to===QuoteStatus::Ready && $q['enquiry_id']!==null){$this->markEnquiryQuoted((int)$q['enquiry_id'],$actor->userId);}
        $this->audit->record($actor->organisationId,$actor->userId,'quote.status_transition','quote',$quoteId,['status'=>$from->value],['status'=>$to->value]);
    }

    public function createRevision(Actor $actor,int $quoteId): void
    {
        $this->permissions->assertAllowed($actor,'quotes.edit');$q=$this->quote($actor,$quoteId);if($q['status']!=='Sent'){throw new RuntimeException('Only sent quotes can create a revision.');}
        $this->pdo->prepare("UPDATE quotes SET status='Draft',revision_number=revision_number+1,updated_by_user_id=:actor WHERE id=:id")->execute(['actor'=>$actor->userId,'id'=>$quoteId]);$this->audit->record($actor->organisationId,$actor->userId,'quote.revision_created','quote',$quoteId,['revision'=>$q['revision_number']],['revision'=>(int)$q['revision_number']+1]);
    }

    /** @return array<string,mixed> */
    public function quote(Actor $actor,int $id): array
    {
        $this->permissions->assertAllowed($actor,'quotes.view');$s=$this->pdo->prepare('SELECT * FROM quotes WHERE id=:id AND organisation_id=:org');$s->execute(['id'=>$id,'org'=>$actor->organisationId]);$q=$s->fetch();if(!is_array($q)){throw new RuntimeException('Record not found.');}$this->permissions->assertScoped($actor,(int)$q['organisation_id'],$q['location_id']===null?null:(int)$q['location_id'],$q['assigned_agent_id']===null?null:(int)$q['assigned_agent_id']);return $q;
    }

    private function assertEditable(Actor $actor,int $id): void{$this->permissions->assertAllowed($actor,'quotes.edit');$q=$this->quote($actor,$id);if(!in_array($q['status'],['Draft','Ready'],true)){throw new RuntimeException('Quote content is immutable after sending; create a revision.');}}
    /** @return array<string,mixed> */ private function accessibleCustomer(Actor $a,int $id):array{$s=$this->pdo->prepare('SELECT * FROM customers WHERE id=:id AND organisation_id=:org');$s->execute(['id'=>$id,'org'=>$a->organisationId]);$r=$s->fetch();if(!is_array($r)){throw new RuntimeException('Record not found.');}$this->permissions->assertScoped($a,(int)$r['organisation_id'],$r['owning_location_id']===null?null:(int)$r['owning_location_id'],$r['owning_agent_id']===null?null:(int)$r['owning_agent_id']);return$r;}
    private function accessibleEnquiry(Actor $a,int $id,int $customer):void{$s=$this->pdo->prepare('SELECT * FROM enquiries WHERE id=:id AND organisation_id=:org AND customer_id=:customer');$s->execute(['id'=>$id,'org'=>$a->organisationId,'customer'=>$customer]);$r=$s->fetch();if(!is_array($r)){throw new RuntimeException('Record not found.');}$this->permissions->assertScoped($a,(int)$r['organisation_id'],$r['assigned_location_id']===null?null:(int)$r['assigned_location_id'],$r['assigned_agent_id']===null?null:(int)$r['assigned_agent_id']);}
    private function ownedComponent(int $quote,int $component,string $type):void{if((int)$this->scalar('SELECT COUNT(*) FROM quote_components WHERE id=:id AND quote_id=:quote AND component_type=:type',['id'=>$component,'quote'=>$quote,'type'=>$type])!==1){throw new RuntimeException('Component not found.');}}
    private function markEnquiryQuoted(int $id,int $actor):void{$status=(string)$this->scalar('SELECT status FROM enquiries WHERE id=:id',['id'=>$id]);if($status==='Contacted'){$this->pdo->prepare("UPDATE enquiries SET status='Quoted',updated_by_user_id=:actor WHERE id=:id AND status='Contacted'")->execute(['actor'=>$actor,'id'=>$id]);}elseif($status!==EnquiryStatus::Quoted->value){throw new RuntimeException('Originating enquiry must be Contacted or Quoted.');}}
    /**
     * @param array<string,mixed> $p
     * @return list<array<string,mixed>>
     */
    private function all(string $sql,array $p):array{$s=$this->pdo->prepare($sql);$s->execute($p);return array_values($s->fetchAll());}
    /** @param array<string,mixed> $p */ private function scalar(string $sql,array $p):mixed{$s=$this->pdo->prepare($sql);$s->execute($p);return$s->fetchColumn();}
    /** @param array<string,mixed> $d */ private function required(array $d,string $k):string{$v=trim((string)($d[$k]??''));if($v===''){throw new RuntimeException("{$k} is required.");}return$v;}
    private function nullableInt(mixed $v):?int{return$v===null||$v===''?null:(int)$v;}
}
