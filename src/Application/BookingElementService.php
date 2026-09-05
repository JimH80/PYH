<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
use RuntimeException;

final class BookingElementService extends BookingOperation
{
    public const TYPES=['Attraction','Travel Insurance','Airport Parking','Airport Hotel','Car Hire','Transfer','Flight','Accommodation','Cruise','Excursion','Lounge','Rail','Upgrade','Other'];
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b);$rows=$this->rows('SELECT * FROM booking_elements WHERE booking_id=? ORDER BY id',[$b]);if(!$this->permissions->allows($a,'bookings.finance_view')){foreach($rows as &$r){unset($r['supplier_cost'],$r['commission'],$r['supplier_payment_status'],$r['supplier_payment_due_date']);}}return $rows;}
    /** @param array<string,mixed> $data */
    public function save(Actor $a,int $b,array $data,?int $id=null):int
    {
        return $this->atomic(function()use($a,$b,$data,$id):int{
            $this->booking($a,$b,'bookings.edit',true);
            $allowed=['element_type','title','element_booked_date','supplier_name','supplier_reference','service_start_date','service_end_date','selling_price','supplier_cost','commission','supplier_payment_status','supplier_payment_due_date','notes'];$this->fields($data,$allowed);
            $old=$id===null?[]:$this->one('SELECT * FROM booking_elements WHERE id=? AND booking_id=?',[$id,$b]);
            if($id===null||array_intersect(array_keys($data),['selling_price','supplier_cost','commission','supplier_payment_status','supplier_payment_due_date'])!==[]){$this->permissions->assertAllowed($a,'bookings.finance_manage');}
            if($id!==null&&$old['status']!=='Confirmed'){throw new RuntimeException('Inactive element cannot be edited.');}
            if($id!==null&&array_intersect(array_keys($data),['commission'])!==[]&&$this->rows('SELECT id FROM booking_commission_ledger WHERE booking_element_id=?',[$id])!==[]){throw new RuntimeException('Existing commission evidence requires an exceptional ledger entry.');}
            $d=array_replace($old,$data);$v=['booking_id'=>$b,'element_type'=>$this->choice($d['element_type']??null,self::TYPES),'title'=>$this->text($d['title']??null),'element_booked_date'=>BookingService::date($d['element_booked_date']??gmdate('Y-m-d'),true),'supplier_name'=>$this->text($d['supplier_name']??null,200,false),'supplier_reference'=>$this->text($d['supplier_reference']??null,100,false),'service_start_date'=>BookingService::date($d['service_start_date']??null),'service_end_date'=>BookingService::date($d['service_end_date']??null),'selling_price'=>$this->money($d['selling_price']??'0'),'supplier_cost'=>$this->money($d['supplier_cost']??'0'),'commission'=>$this->money($d['commission']??'0'),'supplier_payment_status'=>$this->choice($d['supplier_payment_status']??'Not Due',['Not Due','Due','Paid']),'supplier_payment_due_date'=>BookingService::date($d['supplier_payment_due_date']??null),'notes'=>$this->text($d['notes']??null,4000,false),'updated_by_user_id'=>$a->userId];
            if($id===null){$id=$this->insert('booking_elements',$v+['created_by_user_id'=>$a->userId]);}else{$this->change('booking_elements',$id,$v);}
            if(\PYH\Domain\Quote\Money::minor((new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b)['customer_total'])<0){throw new RuntimeException('Customer total cannot be negative.');}
            $this->event($a,$b,'booking.element_saved',['element_id'=>$id,'fields'=>array_keys($data),'before'=>array_intersect_key($old,array_flip(['selling_price','supplier_cost','commission','supplier_name','supplier_reference','supplier_payment_status','supplier_payment_due_date'])),'after'=>array_intersect_key($v,array_flip(['selling_price','supplier_cost','commission','supplier_name','supplier_reference','supplier_payment_status','supplier_payment_due_date']))]);return $id;
        });
    }
    public function archive(Actor $a,int $b,int $id):void{$this->atomic(function()use($a,$b,$id):void{$this->booking($a,$b,'bookings.finance_manage',true);$e=$this->one('SELECT * FROM booking_elements WHERE id=? AND booking_id=?',[$id,$b]);if($e['status']!=='Confirmed'){throw new RuntimeException('Element is not Confirmed.');}$this->change('booking_elements',$id,['status'=>'Archived','updated_by_user_id'=>$a->userId]);if(\PYH\Domain\Quote\Money::minor((new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b)['customer_total'])<0){throw new RuntimeException('Customer total cannot be negative.');}$this->event($a,$b,'booking.element_archived',['element_id'=>$id]);});}
}
