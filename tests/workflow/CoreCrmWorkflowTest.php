<?php

declare(strict_types=1);

namespace PYH\Tests\Workflow;

use PDO;
use PHPUnit\Framework\TestCase;
use PYH\Application\AuditService;
use PYH\Application\CoreCrmService;
use PYH\Application\OrganisationService;
use PYH\Application\RoleAssignmentService;
use PYH\Application\SearchService;
use PYH\Application\TaskCommunicationService;
use PYH\Database\ConnectionFactory;
use PYH\Database\TestDatabasePolicy;
use PYH\Domain\Enquiry\EnquiryStatus;
use PYH\Security\Actor;
use PYH\Security\Password;
use PYH\Security\PermissionEvaluator;
use RuntimeException;

final class CoreCrmWorkflowTest extends TestCase
{
    public function testForeignKeyIntegrityRejectsOrphanLocation(): void
    {
        $this->expectException(\PDOException::class);
        $this->connection()->exec("INSERT INTO locations (organisation_id,name,internal_code) VALUES (999999999,'Orphan','ORPHAN')");
    }

    public function testCompleteCoreCrmWorkflowAndSecurityBoundaries(): void
    {
        $pdo = $this->connection();
        $this->clearFixtures($pdo);
        $pdo->exec("INSERT INTO organisations (legal_name,trading_name) VALUES ('Plan Your Holiday Ltd','Plan Your Holiday')");
        $organisationId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO locations (organisation_id,name,internal_code) VALUES ({$organisationId},'Head Office','HQ')");
        $locationId = (int) $pdo->lastInsertId();
        $hash = $pdo->quote(Password::hash('secure-test-password'));
        $pdo->exec("INSERT INTO users (organisation_id,location_id,first_name,last_name,email,password_hash,agent_code) VALUES ({$organisationId},{$locationId},'Admin','User','admin@example.test',{$hash},'A001')");
        $userId = (int) $pdo->lastInsertId();
        $systemRoleId = (int) $this->queryColumn($pdo, "SELECT id FROM roles WHERE name='System Administrator'");
        $pdo->exec("INSERT INTO user_roles (user_id,role_id,assigned_by_user_id) VALUES ({$userId},{$systemRoleId},NULL)");
        $permissions = $this->queryList($pdo, 'SELECT permission_key FROM permissions');
        $actor = new Actor($userId, $organisationId, $locationId, $permissions, true);
        $audit = new AuditService($pdo, 'test');
        $crm = new CoreCrmService($pdo, new PermissionEvaluator(), $audit);

        $customerId = $crm->createCustomer($actor, ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test', 'phone' => '07700900123']);
        $travellerId = $crm->createTraveller($actor, $customerId, ['first_name' => 'John', 'last_name' => 'Doe', 'relationship_label' => 'Partner']);
        self::assertGreaterThan(0, $customerId); self::assertGreaterThan(0, $travellerId);

        $brief = ['customer_id' => $customerId, 'product_type' => 'Package Holiday', 'destinations' => ['Madeira'], 'adults' => 2, 'children' => 0, 'budget_amount' => '2500.00'];
        $lostEnquiry = $crm->createEnquiry($actor, $brief);
        $crm->transitionEnquiry($actor, $lostEnquiry, EnquiryStatus::Contacted);
        $crm->transitionEnquiry($actor, $lostEnquiry, EnquiryStatus::Quoted);
        $crm->transitionEnquiry($actor, $lostEnquiry, EnquiryStatus::Lost);
        $bookedEnquiry = $crm->createEnquiry($actor, $brief);
        $crm->transitionEnquiry($actor, $bookedEnquiry, EnquiryStatus::Contacted);
        $crm->transitionEnquiry($actor, $bookedEnquiry, EnquiryStatus::Quoted);
        $crm->transitionEnquiry($actor, $bookedEnquiry, EnquiryStatus::Booked);
        $open = $this->queryColumn($pdo, "SELECT COUNT(*) FROM enquiries WHERE status IN ('New','Contacted','Quoted')");
        self::assertSame(0, (int) $open);

        $operations = new TaskCommunicationService($pdo, new PermissionEvaluator(), $audit);
        $taskId = $operations->createTask($actor, ['title' => 'Call customer', 'priority' => 'High', 'related_entity_type' => 'customer', 'related_entity_id' => $customerId]);
        $operations->completeTask($actor, $taskId);
        $communicationId = $operations->logCommunication($actor, ['customer_id' => $customerId, 'enquiry_id' => $lostEnquiry, 'direction' => 'Outbound', 'communication_type' => 'Phone', 'body' => 'Discussed options', 'occurred_at_utc' => gmdate('Y-m-d H:i:s')]);
        self::assertGreaterThan(0, $communicationId);
        self::assertSame('Completed', $this->queryColumn($pdo, "SELECT status FROM tasks WHERE id={$taskId}"));

        $results = (new SearchService($pdo, new PermissionEvaluator()))->search($actor, 'Jane');
        self::assertCount(1, $results); self::assertSame('customer', $results[0]['result_type']);
        $restricted = new Actor(999, $organisationId, 999, ['customers.view', 'scope.own'], true);
        self::assertSame([], (new SearchService($pdo, new PermissionEvaluator()))->search($restricted, 'Jane'));

        $organisation = new OrganisationService($pdo, new PermissionEvaluator(), $audit);
        $secondUserId = $organisation->createUser($actor, ['location_id' => $locationId, 'first_name' => 'Alex', 'last_name' => 'Agent', 'email' => 'alex@example.test', 'password' => 'another-secure-password', 'agent_code' => 'A002']);
        $agentRoleId = (int) $this->queryColumn($pdo, "SELECT id FROM roles WHERE name='Agent'");
        $roles = new RoleAssignmentService($pdo, new PermissionEvaluator(), $audit);
        $roles->assign($actor, $secondUserId, $agentRoleId, 100);
        self::assertSame(1, (int) $this->queryColumn($pdo, "SELECT COUNT(*) FROM user_roles WHERE user_id={$secondUserId} AND role_id={$agentRoleId}"));
        $auditJson = (string) $this->queryColumn($pdo, "SELECT after_summary FROM audit_events WHERE action='user.created' AND entity_id='{$secondUserId}' ORDER BY id DESC LIMIT 1");
        self::assertStringNotContainsString('another-secure-password', $auditJson);
        self::assertStringNotContainsString('password', strtolower($auditJson));

        self::assertSame([], (new SearchService($pdo, new PermissionEvaluator()))->search($actor, "%' OR 1=1 --"));

        $this->expectException(RuntimeException::class);
        $roles->assign($actor, $userId, $systemRoleId, 100);
    }

    private function connection(): PDO
    {
        $database = getenv('TEST_DB_DATABASE');
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $environment = getenv('APP_ENV');
        if (!is_string($database) || !is_string($environment)) {
            self::markTestSkipped('Destructive Phase 2 integration test requires APP_ENV=test and TEST_DB_DATABASE=pyh_v16_phase2_test.');
        }
        TestDatabasePolicy::assertDisposable($environment, $host, $database);
        return ConnectionFactory::create(['host' => $host, 'port' => (int) (getenv('TEST_DB_PORT') ?: 3306), 'database' => $database, 'username' => getenv('TEST_DB_USERNAME') ?: '', 'password' => getenv('TEST_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);
    }

    private function clearFixtures(PDO $pdo): void
    {
        foreach (['quote_booking_handoffs','quote_decisions','proposal_deliveries','proposal_versions','quote_compliance_reviews','quote_adjustments','quote_flight_sectors','quote_accommodation_details','quote_cruise_details','quote_travellers','quote_components','quotes','audit_events','communications','tasks','enquiry_assignment_history','enquiries','travellers','customers','consultant_profiles','user_roles'] as $table) {
            $pdo->exec("DELETE FROM {$table}");
        }
        $pdo->exec('DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.organisation_id IS NOT NULL');
        $pdo->exec('DELETE FROM roles WHERE organisation_id IS NOT NULL');
        foreach (['users','locations','organisations'] as $table) { $pdo->exec("DELETE FROM {$table}"); }
    }

    private function queryColumn(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }

    /** @return list<string> */
    private function queryList(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        return array_values(array_map(static fn (mixed $value): string => (string) $value, $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
