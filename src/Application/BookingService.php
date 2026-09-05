<?php

declare(strict_types=1);
namespace PYH\Application;

use PDO;
use RuntimeException;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use PYH\Security\ScopeEvaluator;
use PYH\Domain\Quote\Money;
use PYH\Domain\Booking\BookingStateMachine;
use PYH\Domain\Booking\BookingStatus;

final class BookingService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions, private readonly AuditService $audit) {}

    /** @return list<array<string,mixed>> */
    public function list(Actor $actor): array
    {
        $this->permissions->assertAllowed($actor,'bookings.view');
        $scope=(new ScopeEvaluator())->sql($actor,'b.location_id','b.assigned_user_id','booking');
        $s=$this->pdo->prepare("SELECT b.*,CONCAT(c.first_name,' ',c.last_name) customer,CONCAT(u.first_name,' ',u.last_name) assigned_agent FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users u ON u.id=b.assigned_user_id WHERE b.organisation_id=:org AND {$scope['sql']} ORDER BY b.id DESC LIMIT 100");
        $s->execute(['org'=>$actor->organisationId,...$scope['parameters']]);
        $rows=array_values($s->fetchAll());
        if(!$this->permissions->allows($actor,'bookings.finance_view')){foreach($rows as &$row){unset($row['supplier_cost'],$row['commission']);}}
        return $rows;
    }

    /** @return array<string,mixed> */
    public function load(Actor $actor,int $id,bool $lock=false): array
    {
        $this->permissions->assertAllowed($actor,'bookings.view');
        $s=$this->pdo->prepare('SELECT b.*,CONCAT(c.first_name,\' \',c.last_name) customer FROM bookings b JOIN customers c ON c.id=b.customer_id WHERE b.id=? AND b.organisation_id=?'.($lock?' FOR UPDATE':''));
        $s->execute([$id,$actor->organisationId]);$r=$s->fetch();
        if(!is_array($r)){throw new RuntimeException('Record not found.');}
        (new ScopeEvaluator())->assertAccessible($actor,(int)$r['organisation_id'],$r['location_id']===null?null:(int)$r['location_id'],$r['assigned_user_id']===null?null:(int)$r['assigned_user_id']);
        if(!$this->permissions->allows($actor,'bookings.finance_view')){unset($r['supplier_cost'],$r['commission']);}
        return $r;
    }

    /** @return list<array<string,mixed>> */
    public function travellers(Actor $actor,int $id): array
    {
        $this->load($actor,$id);$s=$this->pdo->prepare('SELECT * FROM booking_travellers WHERE booking_id=? ORDER BY display_order,id');$s->execute([$id]);return array_values($s->fetchAll());
    }

    /** @return array<string,string> */
    public function totals(Actor $actor,int $id): array
    {
        $b=$this->load($actor,$id);$position=(new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($actor,$id);
        $result=['currency'=>$position['currency'],'selling_price'=>$position['customer_total'],'deposit'=>(string)$b['deposit'],'net_paid'=>$position['net_paid'],'balance'=>$position['balance']];
        if($this->permissions->allows($actor,'bookings.finance_view')){$result+=['supplier_cost'=>(string)$b['supplier_cost'],'commission'=>(string)$b['commission']];}
        return $result;
    }

    /** @return array<string,int|string|null> */
    public function lineage(Actor $actor,int $id): array
    {
        $b=$this->load($actor,$id);$result=['quote_id'=>null,'quote_reference'=>null,'enquiry_id'=>null,'enquiry_reference'=>null];
        if($b['quote_id']===null || !$this->permissions->allows($actor,'quotes.view')){return $result;}
        try {$q=(new QuoteService($this->pdo,$this->permissions,$this->audit))->quote($actor,(int)$b['quote_id']);}catch(RuntimeException){return $result;}
        $result['quote_id']=(int)$q['id'];$result['quote_reference']=(string)$q['reference'];
        if($q['enquiry_id']!==null && $this->permissions->allows($actor,'enquiries.view')){
            $s=$this->pdo->prepare('SELECT * FROM enquiries WHERE id=? AND organisation_id=?');$s->execute([$q['enquiry_id'],$actor->organisationId]);$e=$s->fetch();
            if(is_array($e)){try{(new ScopeEvaluator())->assertAccessible($actor,(int)$e['organisation_id'],$e['assigned_location_id']===null?null:(int)$e['assigned_location_id'],$e['assigned_agent_id']===null?null:(int)$e['assigned_agent_id']);$result['enquiry_id']=(int)$e['id'];$result['enquiry_reference']=(string)$e['reference'];}catch(RuntimeException){}}
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    public function update(Actor $actor,int $id,array $data): void
    {
        $this->permissions->assertAllowed($actor,'bookings.edit');
        $allowed=['supplier_name','supplier_reference','supplier_booking_reference','booked_date','departure_date','return_date','internal_notes'];
        if(array_diff(array_keys($data),$allowed)!==[]){throw new RuntimeException('Unsupported booking update.');}
        $this->pdo->beginTransaction();
        try {
            $b=$this->load($actor,$id,true);
            if(!in_array($b['status'],['Booked','Amended'],true)){throw new RuntimeException('Booking cannot be edited in its current state.');}
            foreach (['supplier_name','supplier_reference','booked_date','departure_date','return_date'] as $material) { if (array_key_exists($material,$data) && $data[$material]!==$b[$material]) { throw new RuntimeException('Use an amendment for material booking changes.'); } }
            $new=array_replace($b,$data);
            foreach(['booked_date','departure_date','return_date'] as $field){$new[$field]=self::date($new[$field]??null,$field==='booked_date');}
            if($new['departure_date']!==null && $new['return_date']!==null && $new['return_date']<$new['departure_date']){throw new RuntimeException('Return date precedes departure.');}
            $params=[];$sets=[];
            foreach($allowed as $field){$value=$new[$field]??null;if($value!==null && !is_string($value)){throw new RuntimeException('Invalid booking field.');}if($value!==null && strlen($value)>($field==='supplier_name'?200:($field==='internal_notes'?65535:100))){throw new RuntimeException('Booking field too long.');}$sets[]=$field.'=?';$params[]=$value;}
            $status=(string)$b['status'];
            if($status==='Booked'){(new BookingStateMachine())->assertCanTransition(BookingStatus::Booked,BookingStatus::Amended);$status='Amended';}
            $params[]=$status;$params[]=$actor->userId;$params[]=$id;
            $this->pdo->prepare('UPDATE bookings SET '.implode(',',$sets).',status=?,updated_by_user_id=? WHERE id=?')->execute($params);
            $this->audit->record($actor->organisationId,$actor->userId,'booking.updated','booking',$id,['status'=>$b['status']],['status'=>$status,'fields'=>array_keys($data)]);
            $this->pdo->commit();
        }catch(\Throwable $e){$this->pdo->rollBack();throw $e;}
    }

    public static function date(mixed $value,bool $required=false): ?string
    {
        if(($value===null || $value==='') && !$required){return null;}
        if(!is_string($value)){throw new RuntimeException('Invalid date.');}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if($date===false || $date->format('Y-m-d')!==$value){throw new RuntimeException('Invalid date.');}return $value;
    }
}
