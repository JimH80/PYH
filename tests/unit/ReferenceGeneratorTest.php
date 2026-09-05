<?php

declare(strict_types=1);

namespace PYH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PYH\Domain\Enquiry\ReferenceGenerator;

final class ReferenceGeneratorTest extends TestCase
{
    public function testReferenceHasStableNonDateFormat(): void
    {
        $generator = new ReferenceGenerator(static fn (int $length): string => str_repeat("\x01", $length));
        self::assertSame('PYH-E-1111111111', $generator->generate());
        self::assertDoesNotMatchRegularExpression('/20\d{2}/', $generator->generate());
    }

    public function testRandomReferencesAreDistinct(): void
    {
        $generator = new ReferenceGenerator();
        self::assertNotSame($generator->generate(), $generator->generate());
    }
}
