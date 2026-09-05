<?php
declare(strict_types=1);
namespace PYH\Application;
use PYH\Security\Actor;
final class BookingTimelineService extends BookingOperation
{
    /** @return list<array<string,mixed>> */
    public function list(Actor $a,int $b):array
    {
        $this->booking($a,$b);$finance=$this->permissions->allows($a,'bookings.finance_view');$out=[];
        foreach($this->rows("SELECT action,occurred_at_utc FROM audit_events WHERE organisation_id=? AND entity_type='booking' AND entity_id=? AND action LIKE 'booking.%' ORDER BY id DESC LIMIT 100",[$a->organisationId,(string)$b]) as $r){
            if(!$finance&&preg_match('/commission|supplier_payment|adjustment/',(string)$r['action'])){continue;}
            $out[]=['event'=>ucwords(str_replace(['booking.','_'],['',' '],(string)$r['action'])),'occurred_at_utc'=>$r['occurred_at_utc']];
        }return $out;
    }
}
