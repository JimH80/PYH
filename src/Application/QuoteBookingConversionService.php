<?php

declare(strict_types=1);
namespace PYH\Application;

use PDO;
use PDOException;
use RuntimeException;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use PYH\Security\ScopeEvaluator;
use PYH\Domain\Booking\BookingReferenceGenerator;
use PYH\Domain\Quote\Money;
use PYH\Domain\Quote\QuoteStateMachine;
use PYH\Domain\Quote\QuoteStatus;
use PYH\Domain\Enquiry\EnquiryStateMachine;
use PYH\Domain\Enquiry\EnquiryStatus;

final class QuoteBookingConversionService
{
    public function __construct(private readonly PDO $pdo,private readonly PermissionEvaluator $permissions,private readonly AuditService $audit,private readonly ?BookingReferenceGenerator $references=null) {}

    public function convert(Actor $actor,int $quoteId,int $handoffId): int
    {
        $this->permissions->assertAllowed($actor,'bookings.create');
        $this->permissions->assertAllowed($actor,'bookings.view');
        $this->permissions->assertAllowed($actor,'quotes.view');
        $this->pdo->beginTransaction();
        try {
            $q=$this->one('SELECT * FROM quotes WHERE id=? AND organisation_id=? FOR UPDATE',[$quoteId,$actor->organisationId]);
            $this->scope($actor,$q,'location_id','assigned_agent_id');
            if($q['status']!=='Accepted'){throw new RuntimeException('Quote is not Accepted or has already been converted.');}
            $h=$this->one('SELECT * FROM quote_booking_handoffs WHERE id=? AND quote_id=? FOR UPDATE',[$handoffId,$quoteId]);
            if($h['readiness_status']!=='Ready' || json_decode((string)$h['blocking_reasons'],true)!==[]){throw new RuntimeException('Handoff is not ready.');}
            $s=$this->pdo->prepare('SELECT id FROM bookings WHERE quote_booking_handoff_id=?');$s->execute([$handoffId]);if($s->fetchColumn()!==false){throw new RuntimeException('Handoff already converted.');}
            $decision=$this->one("SELECT * FROM quote_decisions WHERE quote_id=? AND decision='Accepted' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$quoteId]);
            $p=$this->one('SELECT * FROM proposal_versions WHERE id=? AND quote_id=? FOR UPDATE',[$h['accepted_proposal_version_id'],$quoteId]);
            if((int)$decision['proposal_version_id']!==(int)$p['id'] || (int)$p['version_number']!==(int)$q['revision_number'] || $p['finalised_at_utc']===null){throw new RuntimeException('Accepted proposal does not match handoff.');}
            $snapshot=json_decode((string)$h['snapshot'],true,512,JSON_THROW_ON_ERROR);
            $proposal=json_decode((string)$p['snapshot'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($snapshot) || !is_array($proposal)){throw new RuntimeException('Invalid handoff snapshot.');}
            $accepted=$snapshot;unset($accepted['commercial'],$accepted['handoff']);
            if($accepted!=$proposal || (int)($snapshot['handoff']['quote_id']??0)!==$quoteId || (int)($snapshot['handoff']['accepted_proposal_version_id']??0)!==(int)$p['id']){throw new RuntimeException('Handoff does not match accepted evidence.');}
            $customer=$this->one('SELECT * FROM customers WHERE id=? AND organisation_id=? FOR UPDATE',[$q['customer_id'],$actor->organisationId]);
            $this->scope($actor,$customer,'owning_location_id','owning_agent_id');
            $enquiry=null;
            if($q['enquiry_id']!==null){$enquiry=$this->one('SELECT * FROM enquiries WHERE id=? AND organisation_id=? AND customer_id=? FOR UPDATE',[$q['enquiry_id'],$actor->organisationId,$q['customer_id']]);$this->scope($actor,$enquiry,'assigned_location_id','assigned_agent_id');if($enquiry['status']!=='Quoted'){throw new RuntimeException('Originating enquiry is not Quoted.');}}
            $travellers=$snapshot['travellers']??null;
            if(!is_array($travellers) || $travellers===[]){throw new RuntimeException('Structured traveller snapshots are required.');}
            $leads=0;
            foreach($travellers as $t){
                if(!is_array($t) || !isset($t['first_name_snapshot'],$t['last_name_snapshot'],$t['traveller_type'],$t['display_order']) || !in_array($t['traveller_type'],['Adult','Child','Infant'],true) || trim((string)$t['first_name_snapshot'])==='' || trim((string)$t['last_name_snapshot'])===''){throw new RuntimeException('Structured traveller snapshots are required; regenerate the proposal.');}
                BookingService::date($t['date_of_birth_snapshot']??null);$leads+=(int)(bool)($t['is_lead']??false);
                if(isset($t['traveller_id'])){$this->one('SELECT id FROM travellers WHERE id=? AND customer_id=?',[$t['traveller_id'],$q['customer_id']]);}
            }
            if($leads!==1){throw new RuntimeException('Exactly one lead traveller is required.');}
            $travel=$snapshot['quote']??[];$pricing=$snapshot['pricing']??[];
            $departure=BookingService::date($travel['departure_date']??null);$return=BookingService::date($travel['return_date']??null);
            if($departure!==null && $return!==null && $return<$departure){throw new RuntimeException('Invalid travel dates.');}
            $total=$this->money($pricing['customer_total']??null);$deposit=$this->money($pricing['deposit']??null);
            if($deposit>$total){throw new RuntimeException('Deposit exceeds accepted price.');}
            $cost=0;$commission=0;$suppliers=[];$references=[];
            $componentIds=array_column($snapshot['components']??[],'id');
            foreach($snapshot['commercial']??[] as $component){if(!in_array($component['id'],$componentIds)){continue;}$cost+=$this->money($component['supplier_cost']);$commission+=$this->money($component['commission_amount']);if(!empty($component['supplier'])){$suppliers[]=(string)$component['supplier'];}if(!empty($component['supplier_reference'])){$references[]=(string)$component['supplier_reference'];}}
            $supplier=implode(' / ',array_unique($suppliers));$reference=implode(' / ',array_unique($references));
            if(strlen($supplier)>200 || strlen($reference)>100){throw new RuntimeException('Supplier summary exceeds booking field capacity.');}
            $product=(string)($travel['product_type']??'');$currency=(string)($travel['currency']??'');
            if($product==='' || strlen($product)>80 || preg_match('/^[A-Z]{3}$/',$currency)!==1){throw new RuntimeException('Invalid booking product or currency.');}
            $booking=0;
            for($attempt=0;$attempt<5;++$attempt){
                $bookingReference=($this->references??new BookingReferenceGenerator())->generate($product,(string)$q['reference']);
                try {
                    $this->pdo->prepare('INSERT INTO bookings (booking_reference,organisation_id,location_id,assigned_user_id,customer_id,quote_id,quote_booking_handoff_id,product_type,supplier_name,supplier_reference,booked_date,departure_date,return_date,currency,core_selling_price,supplier_cost,commission,deposit,final_balance_due,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_DATE(),?,?,?,?,?,?,?,?,?,?)')->execute([$bookingReference,$q['organisation_id'],$q['location_id'],$q['assigned_agent_id'],$q['customer_id'],$quoteId,$handoffId,$product,$supplier?:null,$reference?:null,$departure,$return,$currency,Money::decimal($total),Money::decimal($cost),Money::decimal($commission),Money::decimal($deposit),BookingService::date($pricing['balance_due_date']??null),$actor->userId,$actor->userId]);
                    $booking=(int)$this->pdo->lastInsertId();break;
                }catch(PDOException $e){if(($e->errorInfo[1]??null)!==1062 || !str_contains($e->getMessage(),'uq_bookings_reference')){throw $e;}}
            }
            if($booking===0){throw new RuntimeException('Unable to allocate booking reference.');}
            $this->audit->record($actor->organisationId,$actor->userId,'booking.created','booking',$booking,null,['quote_id'=>$quoteId,'handoff_id'=>$handoffId]);
            foreach($travellers as $t){
                $this->pdo->prepare('INSERT INTO booking_travellers (booking_id,traveller_id,lead_traveller,display_order,title_snapshot,first_name_snapshot,last_name_snapshot,date_of_birth_snapshot,traveller_type) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$booking,$t['traveller_id']??null,(int)(bool)$t['is_lead'],$t['display_order'],$t['title_snapshot']??null,$t['first_name_snapshot'],$t['last_name_snapshot'],$t['date_of_birth_snapshot']??null,$t['traveller_type']]);
                $this->audit->record($actor->organisationId,$actor->userId,'booking.traveller_snapshot_created','booking',$booking,null,['booking_traveller_id'=>(int)$this->pdo->lastInsertId()]);
            }
            (new QuoteStateMachine())->assertCanTransition(QuoteStatus::Accepted,QuoteStatus::Converted);
            $this->pdo->prepare("UPDATE quotes SET status='Converted',updated_by_user_id=? WHERE id=?")->execute([$actor->userId,$quoteId]);
            $this->audit->record($actor->organisationId,$actor->userId,'quote.converted','quote',$quoteId,['status'=>'Accepted'],['status'=>'Converted','booking_id'=>$booking]);
            if($enquiry!==null){(new EnquiryStateMachine())->assertCanTransition(EnquiryStatus::Quoted,EnquiryStatus::Booked);$this->pdo->prepare("UPDATE enquiries SET status='Booked',updated_by_user_id=? WHERE id=?")->execute([$actor->userId,$enquiry['id']]);$this->audit->record($actor->organisationId,$actor->userId,'enquiry.booked','enquiry',(int)$enquiry['id'],['status'=>'Quoted'],['status'=>'Booked','booking_id'=>$booking]);}
            $this->pdo->commit();return $booking;
        }catch(\Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}if($e instanceof PDOException || $e instanceof \JsonException || $e instanceof \InvalidArgumentException || $e instanceof \DomainException){throw new RuntimeException('Booking conversion could not be completed.',0,$e);}throw $e;}
    }

    private function money(mixed $value): int
    {
        if(!is_string($value) || !preg_match('/^\d{1,11}(?:\.\d{1,2})?$/',$value)){throw new RuntimeException('Invalid accepted money.');}$minor=Money::minor($value);return $minor;
    }
    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    private function one(string $sql,array $params): array
    {
        $s=$this->pdo->prepare($sql);$s->execute($params);$r=$s->fetch();if(!is_array($r)){throw new RuntimeException('Record not found.');}return $r;
    }
    /** @param array<string,mixed> $row */
    private function scope(Actor $actor,array $row,string $location,string $owner): void
    {
        (new ScopeEvaluator())->assertAccessible($actor,(int)$row['organisation_id'],$row[$location]===null?null:(int)$row[$location],$row[$owner]===null?null:(int)$row[$owner]);
    }
}
