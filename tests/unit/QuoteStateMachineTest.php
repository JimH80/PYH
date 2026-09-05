<?php
declare(strict_types=1);
namespace PYH\Tests\Unit;
use DomainException;use PHPUnit\Framework\Attributes\DataProvider;use PHPUnit\Framework\TestCase;use PYH\Domain\Quote\QuoteStateMachine;use PYH\Domain\Quote\QuoteStatus;
final class QuoteStateMachineTest extends TestCase{
 #[DataProvider('allowed')] public function testAllowed(QuoteStatus $from,QuoteStatus $to):void{(new QuoteStateMachine())->assertCanTransition($from,$to);self::addToAssertionCount(1);}
 /** @return list<array{QuoteStatus,QuoteStatus}> */ public static function allowed():array{return[[QuoteStatus::Draft,QuoteStatus::Ready],[QuoteStatus::Ready,QuoteStatus::Draft],[QuoteStatus::Ready,QuoteStatus::Sent],[QuoteStatus::Sent,QuoteStatus::Accepted],[QuoteStatus::Sent,QuoteStatus::Declined],[QuoteStatus::Sent,QuoteStatus::Expired],[QuoteStatus::Accepted,QuoteStatus::Converted]];}
 #[DataProvider('forbidden')] public function testForbidden(QuoteStatus $from,QuoteStatus $to):void{$this->expectException(DomainException::class);(new QuoteStateMachine())->assertCanTransition($from,$to);}
 /** @return list<array{QuoteStatus,QuoteStatus}> */ public static function forbidden():array{return[[QuoteStatus::Draft,QuoteStatus::Sent],[QuoteStatus::Sent,QuoteStatus::Draft],[QuoteStatus::Accepted,QuoteStatus::Sent],[QuoteStatus::Converted,QuoteStatus::Draft],[QuoteStatus::Declined,QuoteStatus::Accepted]];}
}
