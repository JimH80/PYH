<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
use PYH\Domain\Quote\Money;
use RuntimeException;

final class BookingPaymentService extends BookingOperation
{
    /** @return list<array<string,mixed>> */
    public function history(Actor $a,int $b):array{$this->booking($a,$b,'bookings.record_payment');return $this->rows('SELECT * FROM booking_payments WHERE booking_id=? ORDER BY processed_at_utc,id',[$b]);}
    /** @return array<string,string> */
    public function position(Actor $a,int $b):array
    {
        $booking=$this->booking($a,$b);$total=Money::minor((string)$booking['core_selling_price']);
        foreach($this->rows("SELECT selling_price FROM booking_elements WHERE booking_id=? AND status='Confirmed'",[$b]) as $r){$total+=Money::minor((string)$r['selling_price']);}
        foreach($this->rows("SELECT amount,direction FROM booking_financial_adjustments WHERE booking_id=? AND status='Approved'",[$b]) as $r){$total+=($r['direction']==='Increase'?1:-1)*Money::minor((string)$r['amount']);}
        foreach($this->rows("SELECT financial_effect_amount,financial_effect_direction FROM booking_amendments WHERE booking_id=? AND status='Applied'",[$b]) as $r){$total+=($r['financial_effect_direction']==='Decrease'?-1:1)*Money::minor((string)$r['financial_effect_amount']);}
        $paid=0;$refund=0;foreach($this->rows('SELECT transaction_type,amount FROM booking_payments WHERE booking_id=?',[$b]) as $r){if($r['transaction_type']==='Payment'){$paid+=Money::minor((string)$r['amount']);}else{$refund+=Money::minor((string)$r['amount']);}}
        return ['currency'=>(string)$booking['currency'],'customer_total'=>Money::decimal($total),'payments'=>Money::decimal($paid),'refunds'=>Money::decimal($refund),'net_paid'=>Money::decimal($paid-$refund),'balance'=>Money::decimal($total-$paid+$refund)];
    }
    /** @param array<string,mixed> $d */
    public function record(Actor $a,int $b,array $d):int{return $this->atomic(function()use($a,$b,$d):int{
        $this->booking($a,$b,'bookings.record_payment',true);$this->fields($d,['transaction_type','amount','payment_method','transaction_reference','processed_at_utc','notes']);
        $type=$this->choice($d['transaction_type']??null,['Payment','Refund']);$amount=$this->money($d['amount']??null,true);
        if($type==='Refund'&&Money::minor($amount)>Money::minor($this->position($a,$b)['net_paid'])){throw new RuntimeException('Refund exceeds net payments.');}
        foreach(['notes','transaction_reference','payment_method'] as $field){if(isset($d[$field])&&preg_match('/(?:\d[ -]?){13,19}|\b(?:cvv|cvc|card number|password|secret|token)\b/i',(string)$d[$field])){throw new RuntimeException('Do not record payment credentials.');}}
        $id=$this->insert('booking_payments',['booking_id'=>$b,'transaction_type'=>$type,'amount'=>$amount,'payment_method'=>$this->text($d['payment_method']??null,80,false),'transaction_reference'=>$this->text($d['transaction_reference']??null,100,false),'processed_at_utc'=>$this->timestamp($d['processed_at_utc']??gmdate('Y-m-d H:i:s'),true),'notes'=>$this->text($d['notes']??null,2000,false),'created_by_user_id'=>$a->userId]);$this->event($a,$b,'booking.customer_'.strtolower($type),['payment_id'=>$id,'amount'=>$amount]);return $id;
    });}
}
