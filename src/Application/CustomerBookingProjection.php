<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
final class CustomerBookingProjection extends BookingOperation
{
    /** @return array<string,mixed> */
    public function project(Actor $a,int $b):array
    {
        $booking=$this->booking($a,$b);
        return ['booking'=>array_intersect_key($booking,array_flip(['booking_reference','status','product_type','departure_date','return_date'])),'travellers'=>$this->rows('SELECT title_snapshot,first_name_snapshot,last_name_snapshot,date_of_birth_snapshot,traveller_type,lead_traveller FROM booking_travellers WHERE booking_id=? ORDER BY display_order',[$b]),'holiday'=>$this->rows("SELECT element_type,title,service_start_date,service_end_date FROM booking_elements WHERE booking_id=? AND status='Confirmed' ORDER BY id",[$b]),'customer_position'=>(new BookingPaymentService($this->pdo,$this->permissions,$this->audit))->position($a,$b),'documents'=>$this->rows("SELECT id,document_type,original_filename,mime_type FROM booking_documents WHERE booking_id=? AND visibility='Customer visible' ORDER BY id",[$b])];
    }
}
