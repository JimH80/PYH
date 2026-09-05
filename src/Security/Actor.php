<?php

declare(strict_types=1);

namespace PYH\Security;

final class Actor
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly int $userId,
        public readonly int $organisationId,
        public readonly ?int $locationId,
        public readonly array $permissions,
        public readonly bool $active = true,
    ) {
    }
}
