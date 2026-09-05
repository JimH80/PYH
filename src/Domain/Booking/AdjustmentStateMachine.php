<?php
declare(strict_types=1);
namespace PYH\Domain\Booking;
use DomainException;
final class AdjustmentStateMachine{public function assertTransition(string$from,string$to,bool$self,bool$canSelfApprove=false):void{$ok=($from==='Pending'&&in_array($to,['Approved','Rejected'],true))||($from==='Approved'&&$to==='Reversed');if(!$ok||($to==='Approved'&&$self&&!$canSelfApprove))throw new DomainException('Financial adjustment transition denied.');}}
