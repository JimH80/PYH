<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use PYH\Security\ScopeEvaluator;
use RuntimeException;

final class TaskCommunicationService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions, private readonly AuditService $audit)
    {
    }

    /** @param array<string, mixed> $data */
    public function createTask(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'tasks.create');
        $location = isset($data['location_id']) ? (int) $data['location_id'] : $actor->locationId;
        $assignee = isset($data['assigned_user_id']) ? (int) $data['assigned_user_id'] : $actor->userId;
        $this->permissions->assertScoped($actor, $actor->organisationId, $location, $assignee);
        $statement = $this->pdo->prepare('INSERT INTO tasks (organisation_id, location_id, assigned_user_id, created_by_user_id, related_entity_type, related_entity_id, title, description, due_at_utc, priority) VALUES (:organisation_id, :location_id, :assignee, :actor, :entity_type, :entity_id, :title, :description, :due_at, :priority)');
        $statement->execute(['organisation_id' => $actor->organisationId, 'location_id' => $location, 'assignee' => $assignee, 'actor' => $actor->userId, 'entity_type' => $data['related_entity_type'] ?? null, 'entity_id' => $data['related_entity_id'] ?? null, 'title' => trim((string) $data['title']), 'description' => $data['description'] ?? null, 'due_at' => $data['due_at_utc'] ?? null, 'priority' => $data['priority'] ?? 'Normal']);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor->organisationId, $actor->userId, 'task.created', 'task', $id, null, $data);
        return $id;
    }

    public function completeTask(Actor $actor, int $id): void
    {
        $this->permissions->assertAllowed($actor, 'tasks.edit');
        $scope = (new ScopeEvaluator())->sql($actor, 'location_id', 'assigned_user_id', 'task');
        $statement = $this->pdo->prepare("UPDATE tasks SET status='Completed', completed_at_utc=CURRENT_TIMESTAMP(6) WHERE id=:id AND organisation_id=:organisation_id AND status IN ('Open','In Progress') AND {$scope['sql']}");
        $statement->execute(['id' => $id, 'organisation_id' => $actor->organisationId, ...$scope['parameters']]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('Record not found or task cannot be completed.'); }
        $this->audit->record($actor->organisationId, $actor->userId, 'task.completed', 'task', $id, null, ['status' => 'Completed']);
    }

    /** @param array<string, mixed> $data */
    public function updateTask(Actor $actor, int $id, array $data): void
    {
        $this->permissions->assertAllowed($actor, 'tasks.edit');
        $scope = (new ScopeEvaluator())->sql($actor, 'location_id', 'assigned_user_id', 'task');
        $statement = $this->pdo->prepare("UPDATE tasks SET title=:title, description=:description, due_at_utc=:due_at, priority=:priority, status=:status WHERE id=:id AND organisation_id=:organisation_id AND {$scope['sql']}");
        $statement->execute(['title' => trim((string) $data['title']), 'description' => $data['description'] ?? null, 'due_at' => $data['due_at_utc'] ?? null, 'priority' => $data['priority'] ?? 'Normal', 'status' => $data['status'] ?? 'Open', 'id' => $id, 'organisation_id' => $actor->organisationId, ...$scope['parameters']]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('Record not found.'); }
        $this->audit->record($actor->organisationId, $actor->userId, 'task.updated', 'task', $id, null, $data);
    }

    /** @param array<string, mixed> $data */
    public function logCommunication(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'communications.create');
        $scope = (new ScopeEvaluator())->sql($actor, 'c.owning_location_id', 'c.owning_agent_id', 'communication');
        $statement = $this->pdo->prepare("INSERT INTO communications (organisation_id, customer_id, enquiry_id, direction, communication_type, subject, body, occurred_at_utc, created_by_user_id, is_internal) SELECT :organisation_id, c.id, :enquiry_id, :direction, :type, :subject, :body, :occurred_at, :actor, :is_internal FROM customers c WHERE c.id=:customer_id AND c.organisation_id=:organisation_id2 AND {$scope['sql']}");
        $statement->execute(['organisation_id' => $actor->organisationId, 'enquiry_id' => $data['enquiry_id'] ?? null, 'direction' => $data['direction'], 'type' => $data['communication_type'], 'subject' => $data['subject'] ?? null, 'body' => $data['body'], 'occurred_at' => $data['occurred_at_utc'], 'actor' => $actor->userId, 'is_internal' => (int) ($data['is_internal'] ?? true), 'customer_id' => $data['customer_id'], 'organisation_id2' => $actor->organisationId, ...$scope['parameters']]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('Record not found.'); }
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor->organisationId, $actor->userId, 'communication.created', 'communication', $id, null, ['customer_id' => $data['customer_id'], 'type' => $data['communication_type']]);
        return $id;
    }
}
