<?php

declare(strict_types=1);

namespace PYH\Security;

use RuntimeException;

final class ScopeEvaluator
{
    public function scope(Actor $actor): DataScope
    {
        if (!$actor->active) { return DataScope::None; }
        if (in_array(DataScope::Organisation->value, $actor->permissions, true)) { return DataScope::Organisation; }
        if (in_array(DataScope::Location->value, $actor->permissions, true)) { return DataScope::Location; }
        if (in_array(DataScope::Own->value, $actor->permissions, true)) { return DataScope::Own; }
        return DataScope::None;
    }

    public function assertAccessible(Actor $actor, int $organisationId, ?int $locationId, ?int $ownerId): void
    {
        if (!$actor->active || $actor->organisationId !== $organisationId) { throw new RuntimeException('Record not found.'); }
        $allowed = match ($this->scope($actor)) {
            DataScope::Organisation => true,
            DataScope::Location => $locationId !== null && $locationId === $actor->locationId,
            DataScope::Own => $ownerId !== null && $ownerId === $actor->userId,
            DataScope::None => false,
        };
        if (!$allowed) { throw new RuntimeException('Record not found.'); }
    }

    /** @return array{sql: string, parameters: array<string, int>} */
    public function sql(Actor $actor, string $locationColumn, string $ownerColumn, string $prefix): array
    {
        return match ($this->scope($actor)) {
            DataScope::Organisation => ['sql' => '1=1', 'parameters' => []],
            DataScope::Location => ['sql' => "{$locationColumn}=:{$prefix}_location", 'parameters' => ["{$prefix}_location" => $actor->locationId ?? -1]],
            DataScope::Own => ['sql' => "{$ownerColumn}=:{$prefix}_user", 'parameters' => ["{$prefix}_user" => $actor->userId]],
            DataScope::None => ['sql' => '1=0', 'parameters' => []],
        };
    }
}
