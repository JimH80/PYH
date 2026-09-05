<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
final class BookingChecklistService extends BookingOperation
{
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b,'bookings.checklist');return $this->rows('SELECT * FROM booking_checklist_items WHERE booking_id=? ORDER BY due_at_utc,id',[$b]);}
    public function initialise(Actor $a,int $b):void{$this->atomic(function()use($a,$b):void{$booking=$this->booking($a,$b,'bookings.checklist',true);foreach($this->rows("SELECT i.* FROM booking_checklist_template_items i JOIN booking_checklist_templates t ON t.id=i.template_id WHERE t.template_code='BOOKED_DEFAULT' AND t.active=1 AND i.active=1 ORDER BY display_order") as $r){if($this->rows('SELECT id FROM booking_checklist_items WHERE booking_id=? AND item_code=?',[$b,$r['item_code']])!==[]){continue;}$due=$r['default_due_offset_days']===null?null:(new \DateTimeImmutable((string)$booking['booked_date'],new \DateTimeZone('UTC')))->modify(sprintf('%+d days',(int)$r['default_due_offset_days']))->format('Y-m-d H:i:s');$this->insert('booking_checklist_items',['booking_id'=>$b,'template_item_id'=>$r['id'],'item_code'=>$r['item_code'],'title'=>$r['title'],'category'=>$r['category'],'due_at_utc'=>$due]);}$this->event($a,$b,'booking.checklist_initialised');});}
    /** @return array{complete:int,total:int,overdue:int} */
    public function summary(Actor $a,int $b):array{$r=$this->list($a,$b);return ['complete'=>count(array_filter($r,static fn(array $i):bool=>$i['status']==='Completed')),'total'=>count($r),'overdue'=>count(array_filter($r,static fn(array $i):bool=>in_array($i['status'],['Open','In Progress'],true)&&$i['due_at_utc']!==null&&$i['due_at_utc']<gmdate('Y-m-d H:i:s')))];}
    /** @param array<string,mixed> $d */
    public function save(Actor $a,int $b,array $d,?int $id=null):int{return $this->atomic(function()use($a,$b,$d,$id):int{
        $this->booking($a,$b,'bookings.checklist',true);$this->fields($d,['item_code','title','category','status','due_at_utc','assigned_user_id','notes']);$old=$id===null?[]:$this->one('SELECT * FROM booking_checklist_items WHERE id=? AND booking_id=?',[$id,$b]);$v=array_replace($old,$d);$status=$this->choice($v['status']??'Open',['Open','In Progress','Completed','Cancelled']);
        if(($old['status']??null)==='Completed'){throw new \RuntimeException('Completed checklist evidence cannot be rewritten.');}
        $values=['booking_id'=>$b,'item_code'=>$this->text($v['item_code']??null,80),'title'=>$this->text($v['title']??null),'category'=>$this->text($v['category']??null,80),'status'=>$status,'due_at_utc'=>$this->timestamp(isset($d['due_at_utc'])?$d['due_at_utc']:(isset($old['due_at_utc'])?substr((string)$old['due_at_utc'],0,19):null)),'assigned_user_id'=>$this->user($a,$v['assigned_user_id']??null),'notes'=>$this->text($v['notes']??null,2000,false),'completed_at_utc'=>$status==='Completed'?gmdate('Y-m-d H:i:s'):null,'completed_by_user_id'=>$status==='Completed'?$a->userId:null];
        if($id===null){$id=$this->insert('booking_checklist_items',$values);}else{$this->change('booking_checklist_items',$id,$values);}$this->event($a,$b,'booking.checklist_saved',['item_id'=>$id,'status'=>$status]);return $id;
    });}
    /** @return array<string,bool> */
    public function evidence(Actor $a,int $b):array{$booking=$this->booking($a,$b,'bookings.checklist');return ['supplier_reference_present'=>!empty($booking['supplier_booking_reference']),'customer_balance_settled'=>(new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b)['balance']==='0.00','atol_certificate_present'=>$this->rows("SELECT id FROM booking_documents WHERE booking_id=? AND document_type='ATOL Certificate'",[$b])!==[]];}
}
