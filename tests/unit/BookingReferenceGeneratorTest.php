<?php

declare(strict_types=1);
namespace PYH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PYH\Domain\Booking\BookingReferenceGenerator;

final class BookingReferenceGeneratorTest extends TestCase
{
    public function testExistingDateFreeFormatAndProductPrefix(): void
    {
        $generator=new BookingReferenceGenerator(static fn(int $length):string=>str_repeat("\x01",$length));
        $suffix=substr(hash('sha256','PYH-Q-TEST'),0,6);
        self::assertSame('PYH-C-1111111111-'.$suffix,$generator->generate('Cruise','PYH-Q-TEST'));
        self::assertSame('PYH-P-1111111111-'.$suffix,$generator->generate('Package Holiday','PYH-Q-TEST'));
    }

    public function testRandomReferencesDiffer(): void
    {
        $generator=new BookingReferenceGenerator();
        self::assertNotSame($generator->generate('Cruise','Q'),$generator->generate('Cruise','Q'));
    }
}
