<?php

declare(strict_types=1);

namespace PYH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PYH\Config\Environment;

final class EnvironmentTest extends TestCase
{
    public function testMissingValueUsesDefault(): void
    {
        putenv('PYH_TEST_MISSING');
        self::assertSame('fallback', Environment::get('PYH_TEST_MISSING', 'fallback'));
    }

    public function testBooleanValueIsParsedStrictly(): void
    {
        putenv('PYH_TEST_BOOL=true');
        self::assertTrue(Environment::bool('PYH_TEST_BOOL', false));
        putenv('PYH_TEST_BOOL');
    }
}
