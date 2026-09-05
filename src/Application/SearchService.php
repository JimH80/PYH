<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use PYH\Security\ScopeEvaluator;

final class SearchService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions)
    {
    }

    /** @return list<array<string, mixed>> */
    public function search(Actor $actor, string $query, int $limit = 25): array
    {
        $needle = trim($query);
        if (mb_strlen($needle) < 2) { return []; }
        $limit = max(1, min($limit, 50));
        $like = '%' . addcslashes($needle, '%_\\') . '%';
        $results = [];
        $scopes = new ScopeEvaluator();
        if ($this->permissions->allows($actor, 'customers.view')) {
            $scope = $scopes->sql($actor, 'owning_location_id', 'owning_agent_id', 'customer');
            $statement = $this->pdo->prepare("SELECT 'customer' AS result_type, id, CONCAT(first_name, ' ', last_name) AS label, email AS detail FROM customers WHERE organisation_id=:organisation_id AND is_active=TRUE AND {$scope['sql']} AND (CONCAT(first_name, ' ', last_name) LIKE :query_name ESCAPE '\\\\' OR email LIKE :query_email ESCAPE '\\\\' OR phone LIKE :query_phone ESCAPE '\\\\') LIMIT {$limit}");
            $params = ['organisation_id' => $actor->organisationId, 'query_name' => $like, 'query_email' => $like, 'query_phone' => $like, ...$scope['parameters']];
            $statement->execute($params); $results = [...$results, ...$statement->fetchAll()];
        }
        if ($this->permissions->allows($actor, 'enquiries.view')) {
            $scope = $scopes->sql($actor, 'assigned_location_id', 'assigned_agent_id', 'enquiry');
            $statement = $this->pdo->prepare("SELECT 'enquiry' AS result_type, id, reference AS label, product_type AS detail FROM enquiries WHERE organisation_id=:organisation_id AND {$scope['sql']} AND reference LIKE :query ESCAPE '\\\\' LIMIT {$limit}");
            $params = ['organisation_id' => $actor->organisationId, 'query' => $like, ...$scope['parameters']];
            $statement->execute($params); $results = [...$results, ...$statement->fetchAll()];
        }
        if ($this->permissions->allows($actor, 'users.view')) {
            $statement = $this->pdo->prepare("SELECT 'user' AS result_type, id, CONCAT(first_name, ' ', last_name) AS label, agent_code AS detail FROM users WHERE organisation_id=:organisation_id AND is_active=TRUE AND (CONCAT(first_name, ' ', last_name) LIKE :query_name ESCAPE '\\\\' OR agent_code LIKE :query_code ESCAPE '\\\\') LIMIT {$limit}");
            $statement->execute(['organisation_id' => $actor->organisationId, 'query_name' => $like, 'query_code' => $like]); $results = [...$results, ...$statement->fetchAll()];
        }
        if ($this->permissions->allows($actor, 'quotes.view')) {
            $scope = $scopes->sql($actor, 'location_id', 'assigned_agent_id', 'quote');
            $statement = $this->pdo->prepare("SELECT 'quote' AS result_type, id, reference AS label, CONCAT(title, ' · ', status) AS detail FROM quotes WHERE organisation_id=:organisation_id AND {$scope['sql']} AND (reference LIKE :query_reference ESCAPE '\\\\' OR title LIKE :query_title ESCAPE '\\\\') LIMIT {$limit}");
            $statement->execute(['organisation_id' => $actor->organisationId, 'query_reference' => $like, 'query_title' => $like, ...$scope['parameters']]);
            $results = [...$results, ...$statement->fetchAll()];
        }
        if ($this->permissions->allows($actor, 'bookings.view')) {
            $scope=$scopes->sql($actor,'location_id','assigned_user_id','booking_search');
            $statement=$this->pdo->prepare("SELECT 'booking' AS result_type,id,booking_reference AS label,CONCAT(product_type,' · ',status) AS detail FROM bookings WHERE organisation_id=:org AND {$scope['sql']} AND booking_reference LIKE :query LIMIT {$limit}");
            $statement->execute(['org'=>$actor->organisationId,'query'=>$like,...$scope['parameters']]);$results=[...$results,...$statement->fetchAll()];
        }
        return array_slice(array_values($results), 0, $limit);
    }
}
