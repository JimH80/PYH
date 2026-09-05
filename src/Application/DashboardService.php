<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use PYH\Security\ScopeEvaluator;

final class DashboardService
{
    public function __construct(private readonly PDO $pdo, private readonly PermissionEvaluator $permissions)
    {
    }

    /** @return array{active_bookings:int,checklist_overdue:int} */
    public function bookingAttention(Actor $actor):array
    {
        $this->permissions->assertAllowed($actor,'bookings.view');$scope=(new ScopeEvaluator())->sql($actor,'b.location_id','b.assigned_user_id','attention');
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.organisation_id=:org AND b.status IN ('Booked','Amended') AND {$scope['sql']}");$params=['org'=>$actor->organisationId,...$scope['parameters']];$s->execute($params);$active=(int)$s->fetchColumn();$overdue=0;
        if($this->permissions->allows($actor,'bookings.checklist')){$s=$this->pdo->prepare("SELECT COUNT(*) FROM booking_checklist_items i JOIN bookings b ON b.id=i.booking_id WHERE b.organisation_id=:org AND {$scope['sql']} AND i.status IN ('Open','In Progress') AND i.due_at_utc<UTC_TIMESTAMP(6)");$s->execute($params);$overdue=(int)$s->fetchColumn();}
        return ['active_bookings'=>$active,'checklist_overdue'=>$overdue];
    }

    /** @return array{active_enquiries: int, tasks_due: int, recent_customers: int} */
    public function metrics(Actor $actor): array
    {
        $this->permissions->assertAllowed($actor, 'enquiries.view');
        $scopes = new ScopeEvaluator();
        $enquiry = $scopes->sql($actor, 'assigned_location_id', 'assigned_agent_id', 'dashboard_enquiry');
        $task = $scopes->sql($actor, 'location_id', 'assigned_user_id', 'dashboard_task');
        $customer = $scopes->sql($actor, 'owning_location_id', 'owning_agent_id', 'dashboard_customer');
        $statement = $this->pdo->prepare("SELECT (SELECT COUNT(*) FROM enquiries WHERE organisation_id=:org AND status IN ('New','Contacted','Quoted') AND {$enquiry['sql']}) active_enquiries, (SELECT COUNT(*) FROM tasks WHERE organisation_id=:org2 AND status IN ('Open','In Progress') AND due_at_utc <= UTC_TIMESTAMP() AND {$task['sql']}) tasks_due, (SELECT COUNT(*) FROM customers WHERE organisation_id=:org3 AND created_at_utc >= UTC_TIMESTAMP() - INTERVAL 7 DAY AND {$customer['sql']}) recent_customers");
        $statement->execute(['org' => $actor->organisationId, 'org2' => $actor->organisationId, 'org3' => $actor->organisationId, ...$enquiry['parameters'], ...$task['parameters'], ...$customer['parameters']]);
        $row = $statement->fetch();
        return ['active_enquiries' => (int) ($row['active_enquiries'] ?? 0), 'tasks_due' => (int) ($row['tasks_due'] ?? 0), 'recent_customers' => (int) ($row['recent_customers'] ?? 0)];
    }
}
