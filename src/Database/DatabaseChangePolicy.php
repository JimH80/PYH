<?php

declare(strict_types=1);

namespace PYH\Database;

use RuntimeException;

final class DatabaseChangePolicy
{
    public static function assertAllowed(
        string $environment,
        bool $productionOverride,
        bool $stagingConfirmation,
    ): void {
        $environment = strtolower(trim($environment));

        if (in_array($environment, ['local', 'test'], true)) {
            return;
        }

        if ($environment === 'staging' && $stagingConfirmation) {
            return;
        }

        if ($environment === 'production' && $productionOverride) {
            return;
        }

        $requirement = match ($environment) {
            'staging' => 'Set CONFIRM_STAGING_DATABASE_CHANGES=true for this deployment only.',
            'production' => 'Set ALLOW_PRODUCTION_DATABASE_CHANGES=true for this deployment only.',
            default => 'Only local and test are allowed without an environment-specific override.',
        };

        throw new RuntimeException("Database changes are denied in environment '{$environment}'. {$requirement}");
    }
}
