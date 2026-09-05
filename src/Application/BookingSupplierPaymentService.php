<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
use RuntimeException;
final class BookingSupplierPaymentService extends BookingOperation
{
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b,'bookings.finance_view');return $this->rows('SELECT * FROM booking_supplier_payments WHERE booking_id=? ORDER BY id',[$b]);}
    /** @param array<string,mixed> $d */
    public function save(Actor $a,int $b,array $d,?int $id=null):int{return $this->atomic(function()use($a,$b,$d,$id):int{
        $this->booking($a,$b,'bookings.finance_manage',true);$this->fields($d,['booking_element_id','status','amount','due_date','paid_date','payment_reference','notes']);
        $old=$id===null?[]:$this->one('SELECT * FROM booking_supplier_payments WHERE id=? AND booking_id=?',[$id,$b]);if(($old['status']??null)==='Paid'){throw new RuntimeException('Paid evidence cannot be rewritten.');}$v=array_replace($old,$d);
        $values=['booking_id'=>$b,'booking_element_id'=>$this->element($b,$v['booking_element_id']??null),'status'=>$this->choice($v['status']??'Not Due',['Not Due','Due','Paid']),'amount'=>$this->money($v['amount']??null),'due_date'=>BookingService::date($v['due_date']??null),'paid_date'=>BookingService::date($v['paid_date']??null),'payment_reference'=>$this->text($v['payment_reference']??null,100,false),'notes'=>$this->text($v['notes']??null,2000,false),'updated_by_user_id'=>$a->userId];if($values['status']==='Paid'&&$values['paid_date']===null){throw new RuntimeException('Paid date required.');}
        if($id===null){$id=$this->insert('booking_supplier_payments',$values+['created_by_user_id'=>$a->userId]);}else{$this->change('booking_supplier_payments',$id,$values);}$this->event($a,$b,'booking.supplier_payment',['supplier_payment_id'=>$id,'status'=>$values['status'],'before'=>array_intersect_key($old,array_flip(['amount','status','due_date','paid_date','payment_reference'])),'after'=>array_intersect_key($values,array_flip(['amount','status','due_date','paid_date','payment_reference']))]);return $id;
    });}
}
