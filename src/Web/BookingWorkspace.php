<?php
declare(strict_types=1);
namespace PYH\Web;

use PDO;
use PYH\Application\{AuditService,BookingService,BookingElementService,BookingPaymentService,BookingSupplierPaymentService,BookingCommissionService,BookingAdjustmentService,BookingDocumentService,BookingChecklistService,BookingAmendmentService,BookingTimelineService};
use PYH\Security\{Actor,PermissionEvaluator,Html,Csrf};
use RuntimeException;

final class BookingWorkspace
{
    public const ROUTES=['/booking/elements','/booking/payments','/booking/supplier-payments','/booking/commission','/booking/adjustments','/booking/documents','/booking/checklist','/booking/amendments'];
    public function __construct(private readonly PDO $pdo,private readonly PermissionEvaluator $permissions,private readonly AuditService $audit){}
    public function mutate(string $path,Actor $a):int
    {
        $b=(int)($_POST['booking_id']??0);$id=empty($_POST['id'])?null:(int)$_POST['id'];$op=(string)($_POST['operation']??'save');$d=$_POST;unset($d['_csrf'],$d['booking_id'],$d['id'],$d['operation']);
        switch($path){
            case '/booking/elements':$s=new BookingElementService($this->pdo,$this->permissions,$this->audit);if($op==='archive'){$s->archive($a,$b,$id??0);}elseif($op==='save'){$s->save($a,$b,$d,$id);}else{throw new RuntimeException('Invalid operation.');}break;
            case '/booking/payments':(new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->record($a,$b,$d);break;
            case '/booking/supplier-payments':(new BookingSupplierPaymentService($this->pdo,$this->permissions,$this->audit))->save($a,$b,$d,$id);break;
            case '/booking/commission':$s=new BookingCommissionService($this->pdo,$this->permissions,$this->audit);if($op==='generate'){$s->generate($a,$b,empty($d['booking_element_id'])?null:(int)$d['booking_element_id']);}elseif($op==='exceptional'){$s->exceptional($a,$b,$d);}elseif($op==='status'){$s->status($a,$b,$id??0,(string)($d['status']??''),empty($d['received_date'])?null:(string)$d['received_date']);}else{throw new RuntimeException('Invalid operation.');}break;
            case '/booking/adjustments':$s=new BookingAdjustmentService($this->pdo,$this->permissions,$this->audit);if($op==='submit'){$s->submit($a,$b,$d);}elseif($op==='decide'){$s->decide($a,$b,$id??0,(string)($d['status']??''),(string)($d['decision_note']??''));}else{throw new RuntimeException('Invalid operation.');}break;
            case '/booking/documents':$s=new BookingDocumentService($this->pdo,$this->permissions,$this->audit);if($op==='upload'){$s->upload($a,$b,$_FILES['document']??[],(string)($d['document_type']??'Other'),empty($d['booking_element_id'])?null:(int)$d['booking_element_id'],(string)($d['visibility']??'Agent only'));}elseif($op==='visibility'){$s->visibility($a,$b,$id??0,(string)($d['visibility']??''));}elseif($op==='save'){$s->register($a,$b,$d);}else{throw new RuntimeException('Invalid operation.');}break;
            case '/booking/checklist':$s=new BookingChecklistService($this->pdo,$this->permissions,$this->audit);if($op==='initialise'){$s->initialise($a,$b);}elseif($op==='save'){$s->save($a,$b,$d,$id);}else{throw new RuntimeException('Invalid operation.');}break;
            case '/booking/amendments':$s=new BookingAmendmentService($this->pdo,$this->permissions,$this->audit);if($op==='draft'){$s->draft($a,$b,$d);}elseif($op==='transition'){$s->transition($a,$b,$id??0,(string)($d['status']??''));}else{throw new RuntimeException('Invalid operation.');}break;
            default:throw new RuntimeException('Record not found.');
        }return $b;
    }
    public function render(Actor $a,int $b,string $tab):string
    {
        $core=new BookingService($this->pdo,$this->permissions,$this->audit);$booking=$core->load($a,$b);$tabs=['overview'=>'Overview','travellers'=>'Travellers','holiday'=>'Holiday','payments'=>'Payments','documents'=>'Documents','checklist'=>'Checklist','finance'=>'Finance','timeline'=>'Timeline'];if(!isset($tabs[$tab])){throw new RuntimeException('Record not found.');}
        $out='<section class="card workspace-header"><div><small>PLAN YOUR HOLIDAY · BOOKING</small><h2>'.Html::escape((string)$booking['booking_reference']).'</h2><p>'.Html::escape((string)$booking['customer']).' · '.Html::escape((string)$booking['product_type']).'</p></div><span class="status">'.Html::escape((string)$booking['status']).'</span></section><nav class="workspace-tabs">';
        foreach($tabs as $key=>$label){if($key==='finance'&&!$this->permissions->allows($a,'bookings.finance_view')){continue;}$out.='<a '.($tab===$key?'aria-current="page"':'').' href="/booking?id='.$b.'&amp;tab='.$key.'">'.$label.'</a>';}$out.='</nav><section class="workspace-panel">';
        if($tab==='overview'){
            $p=(new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b);$out.=$this->table([$p]);
            $out.=$this->table([array_intersect_key($booking,array_flip(['supplier_name','supplier_reference','supplier_booking_reference','booked_date','departure_date','return_date','created_at_utc','updated_at_utc']))]);
            $out.=$this->table([$core->lineage($a,$b)]);
            if($this->permissions->allows($a,'bookings.checklist')){$out.=$this->table([(new BookingChecklistService($this->pdo,$this->permissions,$this->audit))->summary($a,$b)]);}
            if($this->permissions->allows($a,'bookings.documents')){$out.='<p>Documents: '.count((new BookingDocumentService($this->pdo,$this->permissions,$this->audit))->list($a,$b)).'</p>';}
            if($this->permissions->allows($a,'bookings.edit')){$out.=$this->coreForm($booking);}
            if($this->permissions->allows($a,'bookings.adjust')){$out.='<h2>My adjustment requests</h2>'.$this->table((new BookingAdjustmentService($this->pdo,$this->permissions,$this->audit))->submitted($a,$b)).$this->form('Request adjustment','adjustments',$b,'submit',['adjustment_type'=>['Discount','Fee','Commission-Funded','Price Correction','Other'],'amount','direction'=>['Decrease','Increase'],'reason','customer_visibility'=>['Internal','Customer Visible']]);}
            $out.='<h2>Recent activity</h2>'.$this->table(array_slice((new BookingTimelineService($this->pdo,$this->permissions,$this->audit))->list($a,$b),0,5));
        }elseif($tab==='travellers'){$out.=$this->table($core->travellers($a,$b));}
        elseif($tab==='holiday'){
            $out.=$this->table((new BookingElementService($this->pdo,$this->permissions,$this->audit))->list($a,$b));
            if($this->permissions->allows($a,'bookings.finance_manage')){$out.=$this->form('Add or edit holiday element','elements',$b,'save',['id','element_type'=>BookingElementService::TYPES,'title','element_booked_date','supplier_name','supplier_reference','service_start_date','service_end_date','selling_price','supplier_cost','commission','supplier_payment_status'=>['Not Due','Due','Paid'],'supplier_payment_due_date','notes']).$this->form('Archive element','elements',$b,'archive',['id']);}
            if(!$this->permissions->allows($a,'bookings.finance_manage')&&$this->permissions->allows($a,'bookings.edit')){$out.=$this->form('Edit element details','elements',$b,'save',['id','title','notes']);}
            if($this->permissions->allows($a,'bookings.amend')){$out.='<h2>Amendments</h2>'.$this->table((new BookingAmendmentService($this->pdo,$this->permissions,$this->audit))->list($a,$b)).$this->form('Draft amendment','amendments',$b,'draft',['reason','description','after_summary','financial_effect_amount','financial_effect_direction'=>['None','Increase','Decrease'],'customer_effect_summary','supplier_reference']).$this->form('Amendment transition','amendments',$b,'transition',['id','status'=>['Pending','Approved','Rejected','Applied']]);}
        }elseif($tab==='payments'){
            $out.='<p class="card">Payments are made through Hays Travel. Record events here for reconciliation only. Never enter card details or payment credentials.</p>'.$this->table([(new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b)]);
            $out.=$this->table((new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->history($a,$b));$out.=$this->form('Record Hays payment or refund','payments',$b,'save',['transaction_type'=>['Payment','Refund'],'amount','payment_method','transaction_reference','processed_at_utc','notes']);
        }elseif($tab==='documents'){
            $out.='<p>ATOL Certificates are supplier-issued documents, tracked separately from booking confirmations. Files are held in private storage and downloaded through authenticated access checks.</p>'.$this->table((new BookingDocumentService($this->pdo,$this->permissions,$this->audit))->list($a,$b));
            $out.='<form method="post" enctype="multipart/form-data" action="/booking/documents" class="card form grid"><input type="hidden" name="_csrf" value="'.Html::escape(Csrf::token()).'"><input type="hidden" name="booking_id" value="'.$b.'"><input type="hidden" name="operation" value="upload"><label>Supplier-issued document<input type="file" name="document" accept=".pdf,.png,.jpg,.jpeg,.txt" required></label><label>Document type<select name="document_type">';foreach(BookingDocumentService::TYPES as $type){$out.='<option>'.Html::escape($type).'</option>';}$out.='</select></label><p>Uploads default to Agent only. Maximum 10 MB.</p><button>Upload privately</button></form>';
            foreach((new BookingDocumentService($this->pdo,$this->permissions,$this->audit))->list($a,$b) as $doc){if(!$doc['local_file_available']){continue;}$out.='<p><a href="/booking/document?booking_id='.$b.'&amp;id='.(int)$doc['id'].'">Download '.Html::escape((string)$doc['original_filename']).'</a></p>';}
            $out.=$this->form('Register document','documents',$b,'save',['booking_element_id','document_type'=>BookingDocumentService::TYPES,'original_filename','storage_reference','mime_type','visibility'=>['Agent only','Customer visible'],'notes']).$this->form('Change visibility','documents',$b,'visibility',['id','visibility'=>['Agent only','Customer visible']]);
        }elseif($tab==='checklist'){
            $s=new BookingChecklistService($this->pdo,$this->permissions,$this->audit);$out.=$this->table([$s->summary($a,$b)]).$this->table([$s->evidence($a,$b)]);foreach($s->list($a,$b) as $r){$out.='<article class="card checklist-row"><strong>'.Html::escape((string)$r['title']).'</strong> <span class="status">'.Html::escape((string)$r['status']).'</span><p>Item '.(int)$r['id'].' · Due '.Html::escape((string)($r['due_at_utc']??'Not set')).' · Assigned '.Html::escape((string)($r['assigned_user_id']??'Unassigned')).'</p><details><summary>Notes</summary>'.Html::escape((string)($r['notes']??'')).'</details></article>';}
            $out.=$this->form('Use booked checklist','checklist',$b,'initialise',[]).$this->form('Add or update checklist item','checklist',$b,'save',['id','item_code','title','category','status'=>['Open','In Progress','Completed','Cancelled'],'due_at_utc','assigned_user_id','notes']);
        }elseif($tab==='finance'){
            $this->permissions->assertAllowed($a,'bookings.finance_view');$out.='<h2>Supplier payments</h2>'.$this->table((new BookingSupplierPaymentService($this->pdo,$this->permissions,$this->audit))->list($a,$b));$s=new BookingCommissionService($this->pdo,$this->permissions,$this->audit);$out.='<h2>Commission</h2>'.$this->table([$s->totals($a,$b)]).$this->table($s->list($a,$b));
            if($this->permissions->allows($a,'bookings.finance_manage')){$out.=$this->form('Supplier obligation','supplier-payments',$b,'save',['id','booking_element_id','status'=>['Not Due','Due','Paid'],'amount','due_date','paid_date','payment_reference','notes']).$this->form('Generate standard commission','commission',$b,'generate',['booking_element_id']).$this->form('Commission receipt/status','commission',$b,'status',['id','status'=>['Due','Received'],'received_date']).$this->form('Exceptional commission entry','commission',$b,'exceptional',['booking_element_id','instalment_type'=>['Post-Cancellation','Clawback'],'amount','expected_date','notes']);}
            $out.='<h2>Adjustments</h2>'.$this->table((new BookingAdjustmentService($this->pdo,$this->permissions,$this->audit))->list($a,$b));
            if($this->permissions->allows($a,'bookings.adjust')){$out.=$this->form('Submit adjustment','adjustments',$b,'submit',['adjustment_type'=>['Discount','Fee','Commission-Funded','Price Correction','Other'],'amount','direction'=>['Decrease','Increase'],'reason','customer_visibility'=>['Internal','Customer Visible']]);}
            if($this->permissions->allows($a,'bookings.approve_adjustment')){$out.=$this->form('Decide adjustment','adjustments',$b,'decide',['id','status'=>['Approved','Rejected','Reversed'],'decision_note']);}
        }else{$out.=$this->table((new BookingTimelineService($this->pdo,$this->permissions,$this->audit))->list($a,$b));}
        return $out.'</section>';
    }
    /** @param list<array<string,mixed>> $rows */
    private function table(array $rows):string{if($rows===[]){return '<p class="card empty">No records yet.</p>';}$out='<div class="card table-wrap"><table><thead><tr>';foreach(array_keys($rows[0]) as $key){$out.='<th>'.Html::escape(ucwords(str_replace('_',' ',$key))).'</th>';}$out.='</tr></thead><tbody>';foreach($rows as $row){$out.='<tr>';foreach($row as $value){$out.='<td>'.Html::escape(is_scalar($value)?(string)$value:'').'</td>';}$out.='</tr>'; }return $out.'</tbody></table></div>';}
    /** @param array<int|string,string|list<string>> $fields */
    private function form(string $title,string $route,int $b,string $operation,array $fields):string{$out='<details class="card"><summary>'.Html::escape($title).'</summary><form class="form grid" method="post" action="/booking/'.$route.'"><input type="hidden" name="_csrf" value="'.Html::escape(Csrf::token()).'"><input type="hidden" name="booking_id" value="'.$b.'"><input type="hidden" name="operation" value="'.$operation.'">';foreach($fields as $key=>$value){$name=is_int($key)?(is_string($value)?$value:throw new RuntimeException('Invalid form definition.')):$key;$out.='<label>'.Html::escape(ucwords(str_replace('_',' ',$name)));if(is_array($value)){$out.='<select name="'.$name.'">';foreach($value as $option){$out.='<option>'.Html::escape($option).'</option>';}$out.='</select>';}else{$out.='<input name="'.$name.'">';}$out.='</label>'; }return $out.'<button>Save</button></form></details>';}
    /** @param array<string,mixed> $b */
    private function coreForm(array $b):string{return '<form class="card form" method="post" action="/booking"><input type="hidden" name="_csrf" value="'.Html::escape(Csrf::token()).'"><input type="hidden" name="id" value="'.(int)$b['id'].'"><label>Internal notes<textarea name="internal_notes">'.Html::escape((string)($b['internal_notes']??'')).'</textarea></label><button>Save notes</button></form>';}
}
