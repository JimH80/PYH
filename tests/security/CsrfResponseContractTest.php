<?php

declare(strict_types=1);

namespace PYH\Tests\Security;

use PHPUnit\Framework\TestCase;
use PYH\Security\Csrf;
use PYH\Security\CsrfViolation;

final class CsrfResponseContractTest extends TestCase
{
    protected function setUp(): void { $_SESSION = ['csrf_token' => 'known-value']; }

    public function testMissingTokenIsRejected(): void
    {
        $this->expectException(CsrfViolation::class);
        Csrf::assertValid(null);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->expectException(CsrfViolation::class);
        Csrf::assertValid('attacker-value');
    }

    public function testValidTokenIsAccepted(): void
    {
        Csrf::assertValid('known-value');
        self::addToAssertionCount(1);
    }

    public function testExpectedApplicationRejectionsHaveControlledNonFiveHundredContracts(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertIsString($entrypoint);
        self::assertStringContainsString("'Permission denied.' => 403", $entrypoint);
        self::assertStringContainsString("'Record not found.' => 404", $entrypoint);
        self::assertStringContainsString('default => 422', $entrypoint);
    }
}
