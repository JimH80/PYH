<?php

declare(strict_types=1);

namespace PYH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PYH\Database\DatabaseChangeAuditor;
use PYH\Logging\Logger;

final class DatabaseChangeAuditorTest extends TestCase
{
    public function testAuditRecordContainsRequiredFields(): void
    {
        $logger = new class implements Logger {
            /** @var array<string, mixed> */
            public array $context = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->context = $context;
            }
        };

        (new DatabaseChangeAuditor($logger, 'test'))->record('migration-id', 'applied');

        self::assertSame('test', $logger->context['environment']);
        self::assertSame('migration-id', $logger->context['migration_identifier']);
        self::assertSame('applied', $logger->context['result']);
        self::assertArrayHasKey('executed_at_utc', $logger->context);
    }
}
