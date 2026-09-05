<?php
declare(strict_types=1);
namespace PYH\Domain\Booking;
use RuntimeException;
final class CancelledFreezePolicy{private const ALLOWED=['commission_received','post_cancel_commission','cancellation_checklist'];public function assertAllowed(string$operation):void{if(!in_array($operation,self::ALLOWED,true))throw new RuntimeException('Cancelled booking is frozen.');}}
