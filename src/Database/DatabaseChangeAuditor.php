<?php

declare(strict_types=1);

namespace PYH\Database;

use DateTimeImmutable;
use DateTimeZone;
use PYH\Logging\Logger;

final class DatabaseChangeAuditor
{
    public function __construct(
        private readonly Logger $logger,
        private readonly string $environment,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function record(string $identifier, string $result, array $context = []): void
    {
        $this->logger->log('info', 'Database change execution.', [
            ...$context,
            'environment' => $this->environment,
            'migration_identifier' => $identifier,
            'executed_at_utc' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'result' => $result,
        ]);
    }
}
