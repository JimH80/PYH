<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
use PYH\Domain\Booking\AdjustmentStateMachine;
use PYH\Domain\Quote\Money;
use RuntimeException;
final class BookingAdjustmentService extends BookingOperation
{
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b,'bookings.finance_view');return $this->rows('SELECT * FROM booking_financial_adjustments WHERE booking_id=? ORDER BY id',[$b]);}
    /** @return list<array<string,mixed>> */
    public function submitted(Actor $a,int $b):array{$this->booking($a,$b,'bookings.adjust');return $this->rows('SELECT id,adjustment_type,amount,direction,reason,status FROM booking_financial_adjustments WHERE booking_id=? AND submitted_by_user_id=? ORDER BY id',[$b,$a->userId]);}
    /** @param array<string,mixed> $d */
    public function submit(Actor $a,int $b,array $d):int{return $this->atomic(function()use($a,$b,$d):int{
        $this->booking($a,$b,'bookings.adjust',true);$this->fields($d,['adjustment_type','amount','direction','reason','customer_visibility']);$type=$this->choice($d['adjustment_type']??null,['Discount','Fee','Commission-Funded','Price Correction','Other']);
        $id=$this->insert('booking_financial_adjustments',['booking_id'=>$b,'adjustment_type'=>$type,'amount'=>$this->money($d['amount']??null,true),'direction'=>$this->choice($d['direction']??null,['Increase','Decrease']),'reason'=>$this->text($d['reason']??null,4000),'customer_visibility'=>$this->choice($d['customer_visibility']??'Internal',['Internal','Customer Visible']),'submitted_by_user_id'=>$a->userId,'submitted_at_utc'=>gmdate('Y-m-d H:i:s')]);
        $this->insert('booking_adjustment_approvals',['adjustment_id'=>$id,'action'=>'Submitted','actor_user_id'=>$a->userId]);$this->event($a,$b,'booking.adjustment_submitted',['adjustment_id'=>$id]);return $id;
    });}
    public function decide(Actor $a,int $b,int $id,string $to,string $note):void{$this->atomic(function()use($a,$b,$id,$to,$note):void{
        $this->booking($a,$b,'bookings.approve_adjustment',true);$r=$this->one('SELECT * FROM booking_financial_adjustments WHERE id=? AND booking_id=?',[$id,$b]);(new AdjustmentStateMachine())->assertTransition((string)$r['status'],$to,(int)$r['submitted_by_user_id']===$a->userId,$this->permissions->allows($a,'bookings.self_approve_adjustment'));
        $this->change('booking_financial_adjustments',$id,['status'=>$to,'current_decision_by_user_id'=>$a->userId,'current_decision_at_utc'=>gmdate('Y-m-d H:i:s')]);
        if(Money::minor((new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b)['customer_total'])<0){throw new RuntimeException('Adjustment would make the customer total negative.');}
        $this->insert('booking_adjustment_approvals',['adjustment_id'=>$id,'action'=>$to,'actor_user_id'=>$a->userId,'decision_note'=>$this->text($note,4000)]);$this->event($a,$b,'booking.adjustment_'.strtolower($to),['adjustment_id'=>$id]);
    });}
}
