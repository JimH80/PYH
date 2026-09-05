<?php

declare(strict_types=1);

namespace PYH\Tests\Workflow;

use PHPUnit\Framework\TestCase;
use PYH\Database\DatabaseChangePolicy;
use RuntimeException;

final class InstallerSafetyTest extends TestCase
{
    public function testLocalAndTestAreAllowedWithoutOverride(): void
    {
        DatabaseChangePolicy::assertAllowed('local', false, false);
        DatabaseChangePolicy::assertAllowed('test', false, false);
        self::addToAssertionCount(2);
    }

    public function testStagingRequiresItsOwnConfirmation(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseChangePolicy::assertAllowed('staging', true, false);
    }

    public function testStagingIsAllowedWithItsConfirmation(): void
    {
        DatabaseChangePolicy::assertAllowed('staging', false, true);
        self::addToAssertionCount(1);
    }

    public function testProductionRequiresItsOwnOverride(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseChangePolicy::assertAllowed('production', false, true);
    }

    public function testProductionIsAllowedWithItsOverride(): void
    {
        DatabaseChangePolicy::assertAllowed('production', true, false);
        self::addToAssertionCount(1);
    }

    public function testUnknownEnvironmentFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        DatabaseChangePolicy::assertAllowed('preview', false, false);
    }
}
