<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Security\Actor;
use PYH\Security\Password;
use PYH\Security\PermissionEvaluator;

final class OrganisationService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions, private readonly AuditService $audit)
    {
    }

    /** @param array<string, mixed> $data */
    public function createLocation(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'locations.manage');
        $statement = $this->pdo->prepare('INSERT INTO locations (organisation_id, name, internal_code, address_line_1, city, postcode, telephone, email) VALUES (:organisation_id, :name, :code, :address, :city, :postcode, :telephone, :email)');
        $statement->execute(['organisation_id' => $actor->organisationId, 'name' => trim((string) $data['name']), 'code' => trim((string) $data['internal_code']), 'address' => $data['address_line_1'] ?? null, 'city' => $data['city'] ?? null, 'postcode' => $data['postcode'] ?? null, 'telephone' => $data['telephone'] ?? null, 'email' => $data['email'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor->organisationId, $actor->userId, 'location.created', 'location', $id, null, $data);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function updateOrganisation(Actor $actor, array $data): void
    {
        $this->permissions->assertAllowed($actor, 'organisation.manage');
        $statement = $this->pdo->prepare('UPDATE organisations SET legal_name=:legal_name, trading_name=:trading_name, telephone=:telephone, email=:email, website=:website, default_currency=:currency, timezone=:timezone, updated_by_user_id=:actor WHERE id=:id');
        $statement->execute(['legal_name' => trim((string) $data['legal_name']), 'trading_name' => trim((string) $data['trading_name']), 'telephone' => $data['telephone'] ?? null, 'email' => $data['email'] ?? null, 'website' => $data['website'] ?? null, 'currency' => $data['default_currency'] ?? 'GBP', 'timezone' => $data['timezone'] ?? 'Europe/London', 'actor' => $actor->userId, 'id' => $actor->organisationId]);
        $this->audit->record($actor->organisationId, $actor->userId, 'organisation.updated', 'organisation', $actor->organisationId, null, $data);
    }

    /** @param array<string, mixed> $data */
    public function createUser(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'users.manage');
        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
        $this->permissions->assertScoped($actor, $actor->organisationId, $locationId);
        $statement = $this->pdo->prepare('INSERT INTO users (organisation_id, location_id, first_name, last_name, email, password_hash, agent_code) VALUES (:organisation_id, :location_id, :first_name, :last_name, :email, :password_hash, :agent_code)');
        $statement->execute(['organisation_id' => $actor->organisationId, 'location_id' => $locationId, 'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'email' => strtolower(trim((string) $data['email'])), 'password_hash' => Password::hash((string) $data['password']), 'agent_code' => trim((string) $data['agent_code'])]);
        $id = (int) $this->pdo->lastInsertId();
        $safe = $data; unset($safe['password']);
        $this->audit->record($actor->organisationId, $actor->userId, 'user.created', 'user', $id, null, $safe);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function saveConsultantProfile(Actor $actor, int $userId, array $data): void
    {
        $this->permissions->assertAllowed($actor, 'users.manage');
        $statement = $this->pdo->prepare('INSERT INTO consultant_profiles (user_id, display_name, title, biography, media_reference, phone, email, is_public, specialist_areas, is_active) SELECT id, :display_name, :title, :biography, :media_reference, :phone, :email, :is_public, :specialist_areas, :is_active FROM users WHERE id=:user_id AND organisation_id=:organisation_id ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), title=VALUES(title), biography=VALUES(biography), media_reference=VALUES(media_reference), phone=VALUES(phone), email=VALUES(email), is_public=VALUES(is_public), specialist_areas=VALUES(specialist_areas), is_active=VALUES(is_active)');
        $statement->execute(['display_name' => $data['display_name'], 'title' => $data['title'] ?? null, 'biography' => $data['biography'] ?? null, 'media_reference' => $data['media_reference'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'is_public' => (int) ($data['is_public'] ?? false), 'specialist_areas' => json_encode($data['specialist_areas'] ?? [], JSON_THROW_ON_ERROR), 'is_active' => (int) ($data['is_active'] ?? true), 'user_id' => $userId, 'organisation_id' => $actor->organisationId]);
        $this->audit->record($actor->organisationId, $actor->userId, 'consultant_profile.saved', 'user', $userId, null, $data);
    }
}
