<?php
declare(strict_types=1);
namespace PYH\Tests\Unit;
use DomainException;use InvalidArgumentException;use PHPUnit\Framework\TestCase;use PYH\Domain\Quote\Money;use PYH\Domain\Quote\PricingCalculator;
final class QuotePricingTest extends TestCase{
 public function testMoneyRoundTripsWithoutFloatingPoint():void{self::assertSame(123456789,Money::minor('1234567.89'));self::assertSame('-12.05',Money::decimal(-1205));}
 public function testOptionalExtrasExcludedUntilSelectedAndAdjustmentsApplied():void{$c=new PricingCalculator();$r=$c->calculate([['selling_price'=>'1000.10','inclusion_state'=>'Included'],['selling_price'=>'50.00','inclusion_state'=>'Optional','selected'=>false],['selling_price'=>'25.50','inclusion_state'=>'Optional','selected'=>true]],[['amount'=>'10.00','adjustment_type'=>'Fee'],['amount'=>'5.60','adjustment_type'=>'Discount']]);self::assertSame(['included_total'=>'1025.60','adjustments_total'=>'4.40','customer_total'=>'1030.00','optional_total'=>'50.00'],$r);}
 public function testRejectsPrecisionDrift():void{$this->expectException(InvalidArgumentException::class);Money::minor('1.001');}
 public function testRejectsNonsensicalNegativeTotal():void{$this->expectException(DomainException::class);(new PricingCalculator())->calculate([], [['amount'=>'1.00','adjustment_type'=>'Discount']]);}
}
