<?php

declare(strict_types=1);

namespace PYH\Security;

use RuntimeException;

final class PermissionEvaluator
{
    public function allows(Actor $actor, string $permission): bool
    {
        return $actor->active && in_array($permission, $actor->permissions, true);
    }

    public function assertAllowed(Actor $actor, string $permission): void
    {
        if (!$this->allows($actor, $permission)) {
            throw new RuntimeException('Permission denied.');
        }
    }

    public function assertScoped(Actor $actor, int $organisationId, ?int $locationId = null, ?int $ownerId = null): void
    {
        (new ScopeEvaluator())->assertAccessible($actor, $organisationId, $locationId, $ownerId);
    }
}
