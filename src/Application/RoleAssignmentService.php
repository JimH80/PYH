<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use RuntimeException;

final class RoleAssignmentService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions, private readonly AuditService $audit)
    {
    }

    public function assign(Actor $actor, int $targetUserId, int $roleId, int $actorAuthorityLevel): void
    {
        $this->permissions->assertAllowed($actor, 'roles.manage');
        if ($targetUserId === $actor->userId) { throw new RuntimeException('Self privilege changes are not permitted.'); }
        $statement = $this->pdo->prepare('SELECT u.organisation_id, r.authority_level, r.organisation_id AS role_organisation_id FROM users u JOIN roles r ON r.id=:role_id WHERE u.id=:user_id');
        $statement->execute(['role_id' => $roleId, 'user_id' => $targetUserId]);
        $row = $statement->fetch();
        if (!is_array($row) || (int) $row['organisation_id'] !== $actor->organisationId || ($row['role_organisation_id'] !== null && (int) $row['role_organisation_id'] !== $actor->organisationId)) { throw new RuntimeException('Record not found.'); }
        if ((int) $row['authority_level'] >= $actorAuthorityLevel) { throw new RuntimeException('Role exceeds authority ceiling.'); }
        $insert = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by_user_id) VALUES (:user_id, :role_id, :actor_id) ON DUPLICATE KEY UPDATE assigned_by_user_id=VALUES(assigned_by_user_id), assigned_at_utc=CURRENT_TIMESTAMP(6)');
        $insert->execute(['user_id' => $targetUserId, 'role_id' => $roleId, 'actor_id' => $actor->userId]);
        $this->audit->record($actor->organisationId, $actor->userId, 'role.assigned', 'user', $targetUserId, null, ['role_id' => $roleId]);
    }
}
