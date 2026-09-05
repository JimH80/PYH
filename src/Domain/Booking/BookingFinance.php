<?php
declare(strict_types=1);
namespace PYH\Domain\Booking;
use DomainException;
use PYH\Domain\Quote\Money;
final class BookingFinance{/**
 * @param list<array{transaction_type:string,amount:string}> $transactions
 * @return array{paid:string,refunded:string,net_paid:string,balance:string}
 */public function balance(string $total,array $transactions):array{$basis=Money::minor($total);$paid=0;$refund=0;foreach($transactions as$t){$v=Money::minor($t['amount']);if($v<=0)throw new DomainException('Transaction amount must be positive.');$t['transaction_type']==='Payment'?$paid+=$v:$refund+=$v;}if($refund>$paid)throw new DomainException('Refund cannot exceed payments received.');$net=$paid-$refund;return['paid'=>Money::decimal($paid),'refunded'=>Money::decimal($refund),'net_paid'=>Money::decimal($net),'balance'=>Money::decimal($basis-$net)];}}
