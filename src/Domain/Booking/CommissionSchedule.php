<?php
declare(strict_types=1);
namespace PYH\Domain\Booking;
use DateTimeImmutable;
use PYH\Domain\Quote\Money;
final class CommissionSchedule{/** @return list<array{type:string,amount:string,due_date:string}> */public function core(string $commission,string $bookedDate,string $departureDate):array{$minor=Money::minor($commission);$first=intdiv($minor,2);$second=$minor-$first;return[['type'=>'Booking','amount'=>Money::decimal($first),'due_date'=>$this->seventhAfter($bookedDate)],['type'=>'Travel','amount'=>Money::decimal($second),'due_date'=>$this->seventhAfter($departureDate)]];}private function seventhAfter(string$date):string{$d=new DateTimeImmutable($date);$next=$d->modify('first day of next month');return$next->setDate((int)$next->format('Y'),(int)$next->format('m'),7)->format('Y-m-d');}}
