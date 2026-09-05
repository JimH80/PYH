<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Domain\Enquiry\EnquiryStateMachine;
use PYH\Domain\Enquiry\EnquiryStatus;
use PYH\Domain\Enquiry\ReferenceGenerator;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use RuntimeException;

final class CoreCrmService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionEvaluator $permissions,
        private readonly AuditService $audit,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createCustomer(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'customers.create');
        $locationId = isset($data['owning_location_id']) ? (int) $data['owning_location_id'] : $actor->locationId;
        $agentId = isset($data['owning_agent_id']) ? (int) $data['owning_agent_id'] : $actor->userId;
        $this->permissions->assertScoped($actor, $actor->organisationId, $locationId, $agentId);
        $statement = $this->pdo->prepare('INSERT INTO customers (organisation_id, owning_location_id, owning_agent_id, title, first_name, last_name, email, phone, preferred_contact_method, notes, created_by_user_id, updated_by_user_id) VALUES (:organisation_id, :location_id, :agent_id, :title, :first_name, :last_name, :email, :phone, :preferred_contact_method, :notes, :created_by, :updated_by)');
        $statement->execute([
            'organisation_id' => $actor->organisationId, 'location_id' => $locationId, 'agent_id' => $agentId,
            'title' => $data['title'] ?? null, 'first_name' => $this->required($data, 'first_name'),
            'last_name' => $this->required($data, 'last_name'), 'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null, 'preferred_contact_method' => $data['preferred_contact_method'] ?? null,
            'notes' => $data['notes'] ?? null, 'created_by' => $actor->userId, 'updated_by' => $actor->userId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor->organisationId, $actor->userId, 'customer.created', 'customer', $id, null, $data);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function createTraveller(Actor $actor, int $customerId, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'customers.edit');
        $customer = $this->scopedCustomer($actor, $customerId);
        $statement = $this->pdo->prepare('INSERT INTO travellers (customer_id, title, first_name, last_name, date_of_birth, relationship_label, accessibility_notes, dietary_notes) VALUES (:customer_id, :title, :first_name, :last_name, :date_of_birth, :relationship_label, :accessibility_notes, :dietary_notes)');
        $statement->execute(['customer_id' => $customerId, 'title' => $data['title'] ?? null, 'first_name' => $this->required($data, 'first_name'), 'last_name' => $this->required($data, 'last_name'), 'date_of_birth' => $data['date_of_birth'] ?? null, 'relationship_label' => $data['relationship_label'] ?? null, 'accessibility_notes' => $data['accessibility_notes'] ?? null, 'dietary_notes' => $data['dietary_notes'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor->organisationId, $actor->userId, 'traveller.created', 'traveller', $id, null, ['customer_id' => $customer['id'], ...$data]);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function updateCustomer(Actor $actor, int $customerId, array $data): void
    {
        $this->permissions->assertAllowed($actor, 'customers.edit');
        $before = $this->scopedCustomer($actor, $customerId);
        $statement = $this->pdo->prepare('UPDATE customers SET title=:title, first_name=:first_name, last_name=:last_name, email=:email, phone=:phone, alternate_phone=:alternate_phone, address_line_1=:address, city=:city, postcode=:postcode, preferred_contact_method=:contact, notes=:notes, updated_by_user_id=:actor WHERE id=:id');
        $statement->execute(['title' => $data['title'] ?? null, 'first_name' => $this->required($data, 'first_name'), 'last_name' => $this->required($data, 'last_name'), 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'alternate_phone' => $data['alternate_phone'] ?? null, 'address' => $data['address_line_1'] ?? null, 'city' => $data['city'] ?? null, 'postcode' => $data['postcode'] ?? null, 'contact' => $data['preferred_contact_method'] ?? null, 'notes' => $data['notes'] ?? null, 'actor' => $actor->userId, 'id' => $customerId]);
        $this->audit->record($actor->organisationId, $actor->userId, 'customer.updated', 'customer', $customerId, $before, $data);
    }

    /** @param array<string, mixed> $data */
    public function createEnquiry(Actor $actor, array $data): int
    {
        $this->permissions->assertAllowed($actor, 'enquiries.create');
        $customerId = (int) ($data['customer_id'] ?? 0);
        $this->scopedCustomer($actor, $customerId);
        $locationId = isset($data['assigned_location_id']) ? (int) $data['assigned_location_id'] : $actor->locationId;
        $agentId = isset($data['assigned_agent_id']) ? (int) $data['assigned_agent_id'] : $actor->userId;
        $this->permissions->assertScoped($actor, $actor->organisationId, $locationId, $agentId);
        $reference = (new ReferenceGenerator())->generate();
        $statement = $this->pdo->prepare('INSERT INTO enquiries (reference, organisation_id, customer_id, assigned_location_id, assigned_agent_id, product_type, departure_point, destinations, preferred_start_date, preferred_end_date, flexibility, duration_nights, adults, children, children_ages, budget_amount, budget_currency, must_haves, accessibility_requirements, special_occasions, preferences, additional_notes, preferred_contact_method, lead_source, created_by_user_id, updated_by_user_id) VALUES (:reference, :organisation_id, :customer_id, :location_id, :agent_id, :product_type, :departure_point, :destinations, :start_date, :end_date, :flexibility, :duration, :adults, :children, :children_ages, :budget, :currency, :must_haves, :accessibility, :occasions, :preferences, :notes, :contact_method, :lead_source, :created_by, :updated_by)');
        $statement->execute([
            'reference' => $reference, 'organisation_id' => $actor->organisationId, 'customer_id' => $customerId,
            'location_id' => $locationId, 'agent_id' => $agentId, 'product_type' => $this->required($data, 'product_type'),
            'departure_point' => $data['departure_point'] ?? null, 'destinations' => json_encode($data['destinations'] ?? [], JSON_THROW_ON_ERROR),
            'start_date' => $data['preferred_start_date'] ?? null, 'end_date' => $data['preferred_end_date'] ?? null,
            'flexibility' => $data['flexibility'] ?? null, 'duration' => $data['duration_nights'] ?? null,
            'adults' => $data['adults'] ?? 1, 'children' => $data['children'] ?? 0,
            'children_ages' => json_encode($data['children_ages'] ?? [], JSON_THROW_ON_ERROR),
            'budget' => $data['budget_amount'] ?? null, 'currency' => $data['budget_currency'] ?? 'GBP',
            'must_haves' => $data['must_haves'] ?? null, 'accessibility' => $data['accessibility_requirements'] ?? null,
            'occasions' => $data['special_occasions'] ?? null, 'preferences' => $data['preferences'] ?? null,
            'notes' => $data['additional_notes'] ?? null, 'contact_method' => $data['preferred_contact_method'] ?? null,
            'lead_source' => $data['lead_source'] ?? null, 'created_by' => $actor->userId, 'updated_by' => $actor->userId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($actor->organisationId, $actor->userId, 'enquiry.created', 'enquiry', $id, null, ['reference' => $reference, 'status' => 'New']);
        return $id;
    }

    public function transitionEnquiry(Actor $actor, int $enquiryId, EnquiryStatus $to): void
    {
        $this->permissions->assertAllowed($actor, $to->isOpen() ? 'enquiries.edit' : 'enquiries.close');
        $row = $this->scopedEnquiry($actor, $enquiryId);
        $from = EnquiryStatus::from((string) $row['status']);
        (new EnquiryStateMachine())->assertCanTransition($from, $to);
        $statement = $this->pdo->prepare('UPDATE enquiries SET status=:status, updated_by_user_id=:actor WHERE id=:id AND status=:from_status');
        $statement->execute(['status' => $to->value, 'actor' => $actor->userId, 'id' => $enquiryId, 'from_status' => $from->value]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Enquiry was changed concurrently.');
        }
        $this->audit->record($actor->organisationId, $actor->userId, 'enquiry.status_changed', 'enquiry', $enquiryId, ['status' => $from->value], ['status' => $to->value]);
    }

    public function reassignEnquiry(Actor $actor, int $enquiryId, ?int $locationId, ?int $agentId): void
    {
        $this->permissions->assertAllowed($actor, 'enquiries.assign');
        $row = $this->scopedEnquiry($actor, $enquiryId);
        $this->permissions->assertScoped($actor, $actor->organisationId, $locationId, $agentId);
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE enquiries SET assigned_location_id=:location_id, assigned_agent_id=:agent_id, updated_by_user_id=:actor WHERE id=:id');
            $update->execute(['location_id' => $locationId, 'agent_id' => $agentId, 'actor' => $actor->userId, 'id' => $enquiryId]);
            $history = $this->pdo->prepare('INSERT INTO enquiry_assignment_history (enquiry_id, from_location_id, to_location_id, from_agent_id, to_agent_id, assigned_by_user_id) VALUES (:enquiry_id, :from_location, :to_location, :from_agent, :to_agent, :actor)');
            $history->execute(['enquiry_id' => $enquiryId, 'from_location' => $row['assigned_location_id'], 'to_location' => $locationId, 'from_agent' => $row['assigned_agent_id'], 'to_agent' => $agentId, 'actor' => $actor->userId]);
            $this->audit->record($actor->organisationId, $actor->userId, 'enquiry.reassigned', 'enquiry', $enquiryId, ['location_id' => $row['assigned_location_id'], 'agent_id' => $row['assigned_agent_id']], ['location_id' => $locationId, 'agent_id' => $agentId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function updateEnquiry(Actor $actor, int $enquiryId, array $data): void
    {
        $this->permissions->assertAllowed($actor, 'enquiries.edit');
        $before = $this->scopedEnquiry($actor, $enquiryId);
        $statement = $this->pdo->prepare('UPDATE enquiries SET product_type=:product_type, departure_point=:departure, destinations=:destinations, preferred_start_date=:start_date, preferred_end_date=:end_date, flexibility=:flexibility, duration_nights=:duration, adults=:adults, children=:children, children_ages=:children_ages, budget_amount=:budget, must_haves=:must_haves, accessibility_requirements=:accessibility, special_occasions=:occasions, preferences=:preferences, additional_notes=:notes, preferred_contact_method=:contact, lead_source=:lead_source, updated_by_user_id=:actor WHERE id=:id');
        $statement->execute(['product_type' => $this->required($data, 'product_type'), 'departure' => $data['departure_point'] ?? null, 'destinations' => json_encode($data['destinations'] ?? [], JSON_THROW_ON_ERROR), 'start_date' => $data['preferred_start_date'] ?? null, 'end_date' => $data['preferred_end_date'] ?? null, 'flexibility' => $data['flexibility'] ?? null, 'duration' => $data['duration_nights'] ?? null, 'adults' => $data['adults'] ?? 1, 'children' => $data['children'] ?? 0, 'children_ages' => json_encode($data['children_ages'] ?? [], JSON_THROW_ON_ERROR), 'budget' => $data['budget_amount'] ?? null, 'must_haves' => $data['must_haves'] ?? null, 'accessibility' => $data['accessibility_requirements'] ?? null, 'occasions' => $data['special_occasions'] ?? null, 'preferences' => $data['preferences'] ?? null, 'notes' => $data['additional_notes'] ?? null, 'contact' => $data['preferred_contact_method'] ?? null, 'lead_source' => $data['lead_source'] ?? null, 'actor' => $actor->userId, 'id' => $enquiryId]);
        $this->audit->record($actor->organisationId, $actor->userId, 'enquiry.updated', 'enquiry', $enquiryId, $before, $data);
    }

    public function archiveCustomer(Actor $actor, int $customerId): void
    {
        $this->permissions->assertAllowed($actor, 'customers.archive');
        $this->scopedCustomer($actor, $customerId);
        $statement = $this->pdo->prepare('UPDATE customers SET is_active=FALSE, archived_at_utc=CURRENT_TIMESTAMP(6), updated_by_user_id=:actor WHERE id=:id');
        $statement->execute(['actor' => $actor->userId, 'id' => $customerId]);
        $this->audit->record($actor->organisationId, $actor->userId, 'customer.archived', 'customer', $customerId, ['is_active' => true], ['is_active' => false]);
    }

    /** @return array<string, mixed> */
    private function scopedCustomer(Actor $actor, int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM customers WHERE id=:id AND organisation_id=:organisation_id');
        $statement->execute(['id' => $id, 'organisation_id' => $actor->organisationId]);
        $row = $statement->fetch();
        if (!is_array($row)) { throw new RuntimeException('Record not found.'); }
        $this->permissions->assertScoped($actor, (int) $row['organisation_id'], $row['owning_location_id'] === null ? null : (int) $row['owning_location_id'], $row['owning_agent_id'] === null ? null : (int) $row['owning_agent_id']);
        return $row;
    }

    /** @return array<string, mixed> */
    private function scopedEnquiry(Actor $actor, int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM enquiries WHERE id=:id AND organisation_id=:organisation_id');
        $statement->execute(['id' => $id, 'organisation_id' => $actor->organisationId]);
        $row = $statement->fetch();
        if (!is_array($row)) { throw new RuntimeException('Record not found.'); }
        $this->permissions->assertScoped($actor, (int) $row['organisation_id'], $row['assigned_location_id'] === null ? null : (int) $row['assigned_location_id'], $row['assigned_agent_id'] === null ? null : (int) $row['assigned_agent_id']);
        return $row;
    }

    /** @param array<string, mixed> $data */
    private function required(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') { throw new RuntimeException("{$key} is required."); }
        return $value;
    }
}
