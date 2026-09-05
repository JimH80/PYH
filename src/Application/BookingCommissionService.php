<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
use PYH\Domain\Booking\CommissionSchedule;
use PYH\Domain\Quote\Money;
use RuntimeException;
final class BookingCommissionService extends BookingOperation
{
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b,'bookings.finance_view');return $this->rows('SELECT * FROM booking_commission_ledger WHERE booking_id=? ORDER BY expected_date,id',[$b]);}
    /** @return array<string,string> */
    public function totals(Actor $a,int $b):array{$totals=array_fill_keys(['Expected','Due','Received','Cancelled','Owed'],0);foreach($this->list($a,$b) as $r){$totals[(string)$r['status']]+=Money::minor((string)$r['amount']);}return array_map(static fn(int $n):string=>Money::decimal($n),$totals);}
    public function generate(Actor $a,int $b,?int $element=null):void{$this->atomic(function()use($a,$b,$element):void{
        $booking=$this->booking($a,$b,'bookings.finance_manage',true);$this->element($b,$element);$source=$element===null?$booking:$this->one('SELECT * FROM booking_elements WHERE id=? AND booking_id=?',[$element,$b]);
        $date=BookingService::date($source['element_booked_date']??$booking['booked_date'],true);$travel=BookingService::date($booking['departure_date'],true);
        foreach((new CommissionSchedule())->core((string)$source['commission'],(string)$date,(string)$travel) as $row){$type=$row['type'].' 50%';if($this->rows('SELECT id FROM booking_commission_ledger WHERE booking_id=? AND booking_element_id <=> ? AND instalment_type=?',[$b,$element,$type])!==[]){continue;}$this->insert('booking_commission_ledger',['booking_id'=>$b,'booking_element_id'=>$element,'source_type'=>$element===null?'Core Booking':'Booking Element','instalment_type'=>$type,'amount'=>$row['amount'],'expected_date'=>$row['due_date'],'due_date'=>$row['due_date'],'created_by_user_id'=>$a->userId,'updated_by_user_id'=>$a->userId]);}
        $this->event($a,$b,'booking.commission_generated',['element_id'=>$element]);
    });}
    public function status(Actor $a,int $b,int $id,string $status,?string $received=null):void{$this->atomic(function()use($a,$b,$id,$status,$received):void{
        $this->booking($a,$b,'bookings.finance_manage',true);$r=$this->one('SELECT * FROM booking_commission_ledger WHERE id=? AND booking_id=?',[$id,$b]);
        if(!in_array($r['status'],['Expected','Due','Owed'],true)){throw new RuntimeException('Commission history is locked.');}$this->choice($status,['Due','Received']);
        if($status==='Due'&&($r['due_date']??$r['expected_date'])>gmdate('Y-m-d')){throw new RuntimeException('Commission is not due yet.');}
        $this->change('booking_commission_ledger',$id,['status'=>$status,'received_date'=>BookingService::date($received,$status==='Received'),'updated_by_user_id'=>$a->userId]);$this->event($a,$b,'booking.commission_status',['ledger_id'=>$id,'status'=>$status]);
    });}
    /** @param array<string,mixed> $d */
    public function exceptional(Actor $a,int $b,array $d):int{return $this->atomic(function()use($a,$b,$d):int{
        $this->booking($a,$b,'bookings.finance_manage',true);$this->fields($d,['booking_element_id','instalment_type','amount','expected_date','notes']);$element=$this->element($b,$d['booking_element_id']??null);$type=$this->choice($d['instalment_type']??null,['Post-Cancellation','Clawback']);
        $id=$this->insert('booking_commission_ledger',['booking_id'=>$b,'booking_element_id'=>$element,'source_type'=>$element===null?'Core Booking':'Booking Element','instalment_type'=>$type,'amount'=>$this->money($d['amount']??null),'expected_date'=>BookingService::date($d['expected_date']??null,true),'status'=>$type==='Clawback'?'Owed':'Expected','notes'=>$this->text($d['notes']??null,2000,true),'created_by_user_id'=>$a->userId,'updated_by_user_id'=>$a->userId]);$this->event($a,$b,'booking.commission_exception',['ledger_id'=>$id,'type'=>$type]);return $id;
    });}
}
