<?php

declare(strict_types=1);

namespace PYH\Security;

use PDO;
use RuntimeException;

final class ActorProvider
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{actor: Actor, authority_level: int, name: string} */
    public function load(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT u.id, u.organisation_id, u.location_id, u.first_name, u.last_name, u.is_active, COALESCE(MAX(r.authority_level), 0) AS authority_level, GROUP_CONCAT(DISTINCT p.permission_key) AS permissions FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id AND r.is_active=TRUE LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id WHERE u.id=:id GROUP BY u.id');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row) || !(bool) $row['is_active']) { throw new RuntimeException('Authentication required.'); }
        $permissions = $row['permissions'] === null ? [] : explode(',', (string) $row['permissions']);
        $authority = (int) $row['authority_level'];
        return [
            'actor' => new Actor((int) $row['id'], (int) $row['organisation_id'], $row['location_id'] === null ? null : (int) $row['location_id'], $permissions, true),
            'authority_level' => $authority,
            'name' => (string) $row['first_name'] . ' ' . (string) $row['last_name'],
        ];
    }
}
