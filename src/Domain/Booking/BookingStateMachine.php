<?php
declare(strict_types=1);
namespace PYH\Domain\Booking;
use DomainException;
final class BookingStateMachine{public function assertCanTransition(BookingStatus $from,BookingStatus $to,bool $cancellationWorkflow=false):void{$ok=match($from){BookingStatus::Booked=>in_array($to,[BookingStatus::Amended,BookingStatus::Travelled],true)||($to===BookingStatus::Cancelled&&$cancellationWorkflow),BookingStatus::Amended=>$to===BookingStatus::Travelled||($to===BookingStatus::Cancelled&&$cancellationWorkflow),BookingStatus::Travelled=>$to===BookingStatus::Returned,default=>false};if(!$ok){throw new DomainException("Booking cannot transition from {$from->value} to {$to->value}.");}}}
