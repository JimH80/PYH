<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
use PYH\Domain\Quote\Money;
use PYH\Domain\Booking\BookingStateMachine;
use PYH\Domain\Booking\BookingStatus;
use RuntimeException;
final class BookingAmendmentService extends BookingOperation
{
    private const FIELDS=['supplier_name','supplier_reference','supplier_booking_reference','booked_date','departure_date','return_date'];
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b,'bookings.amend');$rows=$this->rows('SELECT * FROM booking_amendments WHERE booking_id=? ORDER BY amendment_sequence',[$b]);if(!$this->permissions->allows($a,'bookings.finance_view')){foreach($rows as &$r){unset($r['financial_effect_amount'],$r['financial_effect_direction']);}}return $rows;}
    /** @param array<string,mixed> $d */
    public function draft(Actor $a,int $b,array $d):int{return $this->atomic(function()use($a,$b,$d):int{
        $booking=$this->booking($a,$b,'bookings.amend',true);$this->fields($d,['reason','description','after_summary','financial_effect_amount','financial_effect_direction','customer_effect_summary','supplier_reference']);
        $after=json_decode((string)($d['after_summary']??''),true);if(!is_array($after)||$after===[]){throw new RuntimeException('Provide proposed core fields as a JSON object.');}$this->fields($after,self::FIELDS);
        foreach($after as $key=>$value){$after[$key]=str_ends_with($key,'date')?BookingService::date($value,$key==='booked_date'):$this->text($value,$key==='supplier_name'?200:100,false);}
        $combined=array_replace($booking,$after);if($combined['departure_date']!==null&&$combined['return_date']!==null&&$combined['return_date']<$combined['departure_date']){throw new RuntimeException('Invalid travel date range.');}
        $amount=$this->money($d['financial_effect_amount']??'0');$direction=$this->choice($d['financial_effect_direction']??'None',['None','Increase','Decrease']);if($amount!=='0.00'){$this->permissions->assertAllowed($a,'bookings.finance_manage');}
        $sequence=(int)$this->one('SELECT COALESCE(MAX(amendment_sequence),0)+1 AS n FROM booking_amendments WHERE booking_id=?',[$b])['n'];
        $id=$this->insert('booking_amendments',['booking_id'=>$b,'amendment_sequence'=>$sequence,'reason'=>$this->text($d['reason']??null,4000),'description'=>$this->text($d['description']??null,4000),'before_summary'=>json_encode(array_intersect_key($booking,$after),JSON_THROW_ON_ERROR),'after_summary'=>json_encode($after,JSON_THROW_ON_ERROR),'financial_effect_amount'=>$amount,'financial_effect_direction'=>$direction,'customer_effect_summary'=>$this->text($d['customer_effect_summary']??null,4000,false),'supplier_reference'=>$this->text($d['supplier_reference']??null,100,false),'created_by_user_id'=>$a->userId]);$this->event($a,$b,'booking.amendment_drafted',['amendment_id'=>$id]);return $id;
    });}
    public function transition(Actor $a,int $b,int $id,string $to):void{$this->atomic(function()use($a,$b,$id,$to):void{
        $booking=$this->booking($a,$b,in_array($to,['Approved','Rejected'],true)?'bookings.approve_adjustment':'bookings.amend',true);$r=$this->one('SELECT * FROM booking_amendments WHERE id=? AND booking_id=?',[$id,$b]);$allowed=['Draft'=>['Pending'],'Pending'=>['Approved','Rejected'],'Approved'=>['Applied']];if(!in_array($to,$allowed[(string)$r['status']]??[],true)){throw new RuntimeException('Invalid amendment transition.');}
        if($to==='Approved'&&(int)$r['created_by_user_id']===$a->userId&&!$this->permissions->allows($a,'bookings.self_approve_adjustment')){throw new RuntimeException('Self approval requires explicit capability.');}
        $values=['status'=>$to];if(in_array($to,['Approved','Rejected'],true)){$values+=['approved_by_user_id'=>$a->userId,'approved_at_utc'=>gmdate('Y-m-d H:i:s')];}
        if($to==='Applied'){
            $before=json_decode((string)$r['before_summary'],true,512,JSON_THROW_ON_ERROR);$after=json_decode((string)$r['after_summary'],true,512,JSON_THROW_ON_ERROR);if(!is_array($before)||!is_array($after)){throw new RuntimeException('Invalid amendment evidence.');}$this->fields($after,self::FIELDS);if(array_intersect_key($booking,$before)!=$before){throw new RuntimeException('Booking changed since this amendment was drafted.');}
            if($booking['status']==='Booked'){(new BookingStateMachine())->assertCanTransition(BookingStatus::Booked,BookingStatus::Amended);}$this->change('bookings',$b,$after+['status'=>'Amended','updated_by_user_id'=>$a->userId]);
        }
        $this->change('booking_amendments',$id,$values);if(Money::minor((new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b)['customer_total'])<0){throw new RuntimeException('Amendment would make the customer total negative.');}$this->event($a,$b,'booking.amendment_'.strtolower($to),['amendment_id'=>$id]);
    });}
}
