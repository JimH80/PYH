<?php
declare(strict_types=1);
namespace PYH\Tests\Unit;
use DomainException;use PHPUnit\Framework\TestCase;use PYH\Domain\Booking\AdjustmentStateMachine;use PYH\Domain\Booking\BookingFinance;use PYH\Domain\Booking\BookingStateMachine;use PYH\Domain\Booking\BookingStatus;use PYH\Domain\Booking\CancelledFreezePolicy;use PYH\Domain\Booking\CommissionSchedule;use RuntimeException;
final class BookingDomainTest extends TestCase{
 public function testGovernedLifecycle():void{$m=new BookingStateMachine();$m->assertCanTransition(BookingStatus::Booked,BookingStatus::Amended);$m->assertCanTransition(BookingStatus::Amended,BookingStatus::Travelled);$m->assertCanTransition(BookingStatus::Travelled,BookingStatus::Returned);self::addToAssertionCount(3);}
 public function testCancellationRequiresWorkflow():void{$this->expectException(DomainException::class);(new BookingStateMachine())->assertCanTransition(BookingStatus::Booked,BookingStatus::Cancelled);}
 public function testCancelledIsTerminal():void{$this->expectException(DomainException::class);(new BookingStateMachine())->assertCanTransition(BookingStatus::Cancelled,BookingStatus::Booked,true);}
 public function testPaymentRefundBalanceUsesMinorUnits():void{self::assertSame(['paid'=>'500.10','refunded'=>'50.05','net_paid'=>'450.05','balance'=>'549.95'],(new BookingFinance())->balance('1000.00',[['transaction_type'=>'Payment','amount'=>'500.10'],['transaction_type'=>'Refund','amount'=>'50.05']]));}
 public function testOverRefundRejected():void{$this->expectException(DomainException::class);(new BookingFinance())->balance('10.00',[['transaction_type'=>'Payment','amount'=>'1.00'],['transaction_type'=>'Refund','amount'=>'2.00']]);}
 public function testCommissionSplitAndDates():void{$r=(new CommissionSchedule())->core('101.01','2026-01-31','2026-06-15');self::assertSame('50.50',$r[0]['amount']);self::assertSame('50.51',$r[1]['amount']);self::assertSame('2026-02-07',$r[0]['due_date']);self::assertSame('2026-07-07',$r[1]['due_date']);}
 public function testCancelledFreezeAllowsOnlyDedicatedFlows():void{$p=new CancelledFreezePolicy();$p->assertAllowed('post_cancel_commission');self::addToAssertionCount(1);$this->expectException(RuntimeException::class);$p->assertAllowed('payment');}
 public function testSubmitterCannotSelfApprove():void{$this->expectException(DomainException::class);(new AdjustmentStateMachine())->assertTransition('Pending','Approved',true,false);}
 public function testApprovedAdjustmentCanBeReversedWithoutRewriting():void{(new AdjustmentStateMachine())->assertTransition('Approved','Reversed',false);self::addToAssertionCount(1);}
}
