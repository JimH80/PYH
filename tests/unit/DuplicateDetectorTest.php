<?php

declare(strict_types=1);

namespace PYH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PYH\Domain\Customer\DuplicateDetector;

final class DuplicateDetectorTest extends TestCase
{
    public function testWarnsWithoutMerging(): void
    {
        $warnings = (new DuplicateDetector())->findWarnings(
            ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'JANE@example.com', 'phone' => '+44 7700 900123'],
            [['id' => 7, 'first_name' => 'jane', 'last_name' => 'doe', 'email' => 'jane@example.com', 'phone' => '07700900123']],
        );
        self::assertSame([['id' => 7, 'reasons' => ['email', 'phone', 'name']]], $warnings);
    }
}
