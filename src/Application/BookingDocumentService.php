<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
final class BookingDocumentService extends BookingOperation
{
    public const TYPES=['ATOL Certificate','Booking Confirmation','Invoice','Ticket / E-ticket','Voucher','Insurance Document','Cruise Document','Amendment Confirmation','Cancellation Confirmation / Invoice','Supplier Correspondence','Customer Document','Other'];
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array{$this->booking($a,$b,'bookings.documents');$rows=$this->rows('SELECT id,booking_element_id,document_type,original_filename,mime_type,visibility,notes,uploaded_at_utc,storage_reference FROM booking_documents WHERE booking_id=? ORDER BY id',[$b]);foreach($rows as &$row){$key=(string)$row['storage_reference'];$row['local_file_available']=preg_match('/^[a-f0-9]{48}$/',$key)===1&&is_file(dirname(__DIR__,2).'/storage/private/booking-documents/'.$b.'/'.$key);unset($row['storage_reference']);}return $rows;}
    /** @param array<string,mixed> $d */
    public function register(Actor $a,int $b,array $d):int{return $this->atomic(function()use($a,$b,$d):int{
        $this->booking($a,$b,'bookings.documents',true);$this->fields($d,['booking_element_id','document_type','original_filename','storage_reference','mime_type','visibility','notes']);$storage=$this->text($d['storage_reference']??null,255);if(preg_match('/^[A-Za-z0-9_-]{8,255}$/',(string)$storage)!==1){throw new \RuntimeException('Use an opaque application storage identifier.');}
        $id=$this->insert('booking_documents',['booking_id'=>$b,'booking_element_id'=>$this->element($b,$d['booking_element_id']??null),'document_type'=>$this->choice($d['document_type']??null,self::TYPES),'original_filename'=>$this->text($d['original_filename']??null,255),'storage_reference'=>$storage,'mime_type'=>$this->text($d['mime_type']??null,150),'visibility'=>$this->choice($d['visibility']??'Agent only',['Agent only','Customer visible']),'notes'=>$this->text($d['notes']??null,2000,false),'uploaded_by_user_id'=>$a->userId,'uploaded_at_utc'=>gmdate('Y-m-d H:i:s')]);$this->event($a,$b,'booking.document_registered',['document_id'=>$id]);return $id;
    });}
    /** @param array<string,mixed> $file */
    public function upload(Actor $a,int $b,array $file,string $type,?int $element=null,string $visibility='Agent only'):int
    {
        $this->booking($a,$b,'bookings.documents');
        $tmp=$file['tmp_name']??null;
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($tmp) || !is_uploaded_file($tmp)){throw new \RuntimeException('Invalid document upload.');}
        $size=filesize($tmp);if($size===false||$size<1||$size>10*1024*1024){throw new \RuntimeException('Document must be between 1 byte and 10 MB.');}
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);if(!in_array($mime,['application/pdf','image/png','image/jpeg','text/plain'],true)){throw new \RuntimeException('Unsupported document file type.');}
        $key=bin2hex(random_bytes(24));$directory=dirname(__DIR__,2).'/storage/private/booking-documents/'.$b;
        if(!is_dir($directory)&&!mkdir($directory,0700,true)){throw new \RuntimeException('Document storage unavailable.');}
        $target=$directory.'/'.$key;if(!move_uploaded_file($tmp,$target)){throw new \RuntimeException('Document storage unavailable.');}chmod($target,0600);
        try{return $this->register($a,$b,['booking_element_id'=>$element,'document_type'=>$type,'original_filename'=>basename(str_replace('\\','/',(string)($file['name']??'document'))),'storage_reference'=>$key,'mime_type'=>$mime,'visibility'=>$visibility]);}catch(\Throwable $e){unlink($target);throw $e;}
    }
    /** @return array{path:string,filename:string} */
    public function download(Actor $a,int $b,int $id):array
    {
        $this->booking($a,$b,'bookings.documents');$r=$this->one('SELECT storage_reference,original_filename FROM booking_documents WHERE id=? AND booking_id=?',[$id,$b]);$key=(string)$r['storage_reference'];
        if(preg_match('/^[a-f0-9]{48}$/',$key)!==1){throw new \RuntimeException('Record not found.');}
        $path=dirname(__DIR__,2).'/storage/private/booking-documents/'.$b.'/'.$key;if(!is_file($path)){throw new \RuntimeException('Record not found.');}
        return ['path'=>$path,'filename'=>(string)$r['original_filename']];
    }
    public function visibility(Actor $a,int $b,int $id,string $visibility):void{$this->atomic(function()use($a,$b,$id,$visibility):void{$this->booking($a,$b,'bookings.documents',true);$this->one('SELECT id FROM booking_documents WHERE id=? AND booking_id=?',[$id,$b]);$this->change('booking_documents',$id,['visibility'=>$this->choice($visibility,['Agent only','Customer visible'])]);$this->event($a,$b,'booking.document_visibility',['document_id'=>$id,'visibility'=>$visibility]);});}
}
