<?php

declare(strict_types=1);

namespace PYH\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PYH\Database\TestDatabasePolicy;
use RuntimeException;

final class TestDatabasePolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function unsafeTargets(): iterable
    {
        yield 'production' => ['production', '127.0.0.1', 'pyh_v16_phase2_test'];
        yield 'remote' => ['test', 'db.example.com', 'pyh_v16_phase2_test'];
        yield 'wrong database' => ['test', '127.0.0.1', 'pyh'];
    }

    #[DataProvider('unsafeTargets')]
    public function testUnsafeDestructiveTargetsAreRejected(string $environment, string $host, string $database): void
    {
        $this->expectException(RuntimeException::class);
        TestDatabasePolicy::assertDisposable($environment, $host, $database);
    }

    public function testExactDisposableTargetIsAllowed(): void
    {
        TestDatabasePolicy::assertDisposable('test', '127.0.0.1', 'pyh_v16_phase2_test');
        self::addToAssertionCount(1);
    }
}
