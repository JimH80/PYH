<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;

final class AuditService
{
    public function __construct(private readonly PDO $pdo, private readonly string $environment)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(?int $organisationId, ?int $actorId, string $action, string $entityType, int|string|null $entityId, ?array $before = null, ?array $after = null, ?string $requestId = null): void
    {
        $statement = $this->pdo->prepare('INSERT INTO audit_events (organisation_id, actor_user_id, environment, action, entity_type, entity_id, request_id, before_summary, after_summary) VALUES (:organisation_id, :actor_id, :environment, :action, :entity_type, :entity_id, :request_id, :before_summary, :after_summary)');
        $statement->execute([
            'organisation_id' => $organisationId,
            'actor_id' => $actorId,
            'environment' => $this->environment,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'request_id' => $requestId,
            'before_summary' => $before === null ? null : json_encode($this->redact($before), JSON_THROW_ON_ERROR),
            'after_summary' => $after === null ? null : json_encode($this->redact($after), JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach (array_keys($values) as $key) {
            if (preg_match('/password|secret|token|credential/i', $key) === 1) {
                $values[$key] = '[REDACTED]';
            }
        }
        return $values;
    }
}
