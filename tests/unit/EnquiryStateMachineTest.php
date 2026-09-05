<?php

declare(strict_types=1);

namespace PYH\Tests\Unit;

use DomainException;
use PHPUnit\Framework\TestCase;
use PYH\Domain\Enquiry\EnquiryStateMachine;
use PYH\Domain\Enquiry\EnquiryStatus;

final class EnquiryStateMachineTest extends TestCase
{
    public function testLiveAndArchivedStatesAreExplicit(): void
    {
        self::assertTrue(EnquiryStatus::New->isOpen());
        self::assertTrue(EnquiryStatus::Contacted->isOpen());
        self::assertTrue(EnquiryStatus::Quoted->isOpen());
        self::assertFalse(EnquiryStatus::Booked->isOpen());
        self::assertFalse(EnquiryStatus::Lost->isOpen());
        self::assertFalse(EnquiryStatus::Closed->isOpen());
    }

    public function testQuotedCanBecomeBookedOrLost(): void
    {
        $machine = new EnquiryStateMachine();
        $machine->assertCanTransition(EnquiryStatus::Quoted, EnquiryStatus::Booked);
        $machine->assertCanTransition(EnquiryStatus::Quoted, EnquiryStatus::Lost);
        self::addToAssertionCount(2);
    }

    public function testBookedCannotReturnToLiveWorkspace(): void
    {
        $this->expectException(DomainException::class);
        (new EnquiryStateMachine())->assertCanTransition(EnquiryStatus::Booked, EnquiryStatus::Contacted);
    }
}
