<?php

declare(strict_types=1);

namespace PYH\Database;

use RuntimeException;

final class TestDatabasePolicy
{
    public static function assertDisposable(string $environment, string $host, string $database): void
    {
        $allowed = ['pyh_v16_phase2_test', 'pyh_v16_phase3_test', 'pyh_v16_phase4_test'];
        if ($environment !== 'test' || !in_array($host, ['127.0.0.1', 'localhost'], true) || !in_array($database, $allowed, true)) {
            throw new RuntimeException('Destructive tests are restricted to an exact approved local PYH test database with APP_ENV=test.');
        }
    }
}
