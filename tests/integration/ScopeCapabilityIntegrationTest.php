<?php

declare(strict_types=1);

namespace PYH\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PYH\Application\AuditService;
use PYH\Application\CoreCrmService;
use PYH\Application\DashboardService;
use PYH\Application\SearchService;
use PYH\Database\ConnectionFactory;
use PYH\Database\TestDatabasePolicy;
use PYH\Security\Actor;
use PYH\Security\ActorProvider;
use PYH\Security\Password;
use PYH\Security\PermissionEvaluator;
use RuntimeException;

final class ScopeCapabilityIntegrationTest extends TestCase
{
    public function testPermissionsAloneDriveDirectSearchAndDashboardScope(): void
    {
        $pdo = $this->connection();
        $suffix = bin2hex(random_bytes(4));
        $org = $this->organisation($pdo, "Scope Org {$suffix}");
        $otherOrg = $this->organisation($pdo, "Other Org {$suffix}");
        $location = $this->location($pdo, $org, "L1{$suffix}");
        $otherLocation = $this->location($pdo, $org, "L2{$suffix}");
        $foreignLocation = $this->location($pdo, $otherOrg, "LF{$suffix}");
        $ownUser = $this->user($pdo, $org, $location, "own{$suffix}@example.test", "O{$suffix}");
        $otherUser = $this->user($pdo, $org, $location, "other{$suffix}@example.test", "A{$suffix}");
        $locationUser = $this->user($pdo, $org, $location, "location{$suffix}@example.test", "L{$suffix}");
        $managerUser = $this->user($pdo, $org, $location, "manager{$suffix}@example.test", "M{$suffix}");
        $organisationUser = $this->user($pdo, $org, $otherLocation, "org{$suffix}@example.test", "G{$suffix}");

        $base = ['customers.view', 'customers.edit', 'enquiries.view'];
        $this->assignRole($pdo, $org, 'Custom Own ' . $suffix, $ownUser, [...$base, 'scope.own']);
        $this->assignRole($pdo, $org, 'Travel Desk ' . $suffix, $locationUser, [...$base, 'scope.location']);
        $this->assignRole($pdo, $org, 'Manager', $managerUser, $base);
        $this->assignRole($pdo, $org, 'Custom Global ' . $suffix, $organisationUser, [...$base, 'scope.organisation']);

        $ownCustomer = $this->customer($pdo, $org, $location, $ownUser, 'ScopeOwn', $ownUser);
        $sameLocationCustomer = $this->customer($pdo, $org, $location, $otherUser, 'ScopeLocation', $ownUser);
        $otherLocationCustomer = $this->customer($pdo, $org, $otherLocation, $otherUser, 'ScopeOrganisation', $ownUser);
        $foreignCustomer = $this->customer($pdo, $otherOrg, $foreignLocation, null, 'ScopeForeign', null);
        foreach ([[$ownCustomer, $location, $ownUser], [$sameLocationCustomer, $location, $otherUser], [$otherLocationCustomer, $otherLocation, $otherUser]] as [$customer, $customerLocation, $owner]) {
            $this->enquiryAndTask($pdo, $org, $customer, $customerLocation, $owner, $ownUser, $suffix);
        }

        $provider = new ActorProvider($pdo);
        $own = $provider->load($ownUser)['actor'];
        $locationActor = $provider->load($locationUser)['actor'];
        $managerWithoutScope = $provider->load($managerUser)['actor'];
        $organisationActor = $provider->load($organisationUser)['actor'];
        $search = new SearchService($pdo, new PermissionEvaluator());
        self::assertCount(1, $search->search($own, 'Scope'));
        self::assertCount(2, $search->search($locationActor, 'Scope'));
        self::assertCount(0, $search->search($managerWithoutScope, 'Scope'));
        self::assertCount(3, $search->search($organisationActor, 'Scope'));

        $dashboard = new DashboardService($pdo, new PermissionEvaluator());
        self::assertSame(1, $dashboard->metrics($own)['active_enquiries']);
        self::assertSame(2, $dashboard->metrics($locationActor)['active_enquiries']);
        self::assertSame(0, $dashboard->metrics($managerWithoutScope)['active_enquiries']);
        self::assertSame(3, $dashboard->metrics($organisationActor)['active_enquiries']);

        $crm = new CoreCrmService($pdo, new PermissionEvaluator(), new AuditService($pdo, 'test'));
        $this->assertDenied(static fn () => $crm->updateCustomer($own, $sameLocationCustomer, ['first_name' => 'Blocked', 'last_name' => 'Record']));
        $crm->updateCustomer($locationActor, $sameLocationCustomer, ['first_name' => 'Location', 'last_name' => 'Allowed']);
        $crm->updateCustomer($organisationActor, $otherLocationCustomer, ['first_name' => 'Organisation', 'last_name' => 'Allowed']);
        $this->assertDenied(static fn () => $crm->updateCustomer($organisationActor, $foreignCustomer, ['first_name' => 'Foreign', 'last_name' => 'Blocked']));
    }

    private function connection(): PDO
    {
        $environment = (string) getenv('APP_ENV'); $host = getenv('TEST_DB_HOST') ?: '127.0.0.1'; $database = (string) getenv('TEST_DB_DATABASE');
        TestDatabasePolicy::assertDisposable($environment, $host, $database);
        return ConnectionFactory::create(['host' => $host, 'port' => (int) (getenv('TEST_DB_PORT') ?: 3306), 'database' => $database, 'username' => getenv('TEST_DB_USERNAME') ?: '', 'password' => getenv('TEST_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4']);
    }

    private function organisation(PDO $pdo, string $name): int { $s=$pdo->prepare('INSERT INTO organisations(legal_name,trading_name) VALUES(:name,:trade)'); $s->execute(['name'=>$name,'trade'=>$name]); return (int)$pdo->lastInsertId(); }
    private function location(PDO $pdo, int $org, string $code): int { $s=$pdo->prepare('INSERT INTO locations(organisation_id,name,internal_code) VALUES(:org,:name,:code)'); $s->execute(['org'=>$org,'name'=>$code,'code'=>$code]); return (int)$pdo->lastInsertId(); }
    private function user(PDO $pdo, int $org, int $location, string $email, string $code): int { $s=$pdo->prepare('INSERT INTO users(organisation_id,location_id,first_name,last_name,email,password_hash,agent_code) VALUES(:org,:location,:first,:last,:email,:hash,:code)'); $s->execute(['org'=>$org,'location'=>$location,'first'=>'Scope','last'=>'User','email'=>$email,'hash'=>Password::hash('test-password'),'code'=>$code]); return (int)$pdo->lastInsertId(); }
    /** @param list<string> $permissions */
    private function assignRole(PDO $pdo, int $org, string $name, int $user, array $permissions): void { $s=$pdo->prepare('INSERT INTO roles(organisation_id,name,authority_level) VALUES(:org,:name,20)'); $s->execute(['org'=>$org,'name'=>$name]); $role=(int)$pdo->lastInsertId(); foreach($permissions as $permission){$p=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) SELECT :role,id FROM permissions WHERE permission_key=:permission');$p->execute(['role'=>$role,'permission'=>$permission]);} $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(:user,:role)')->execute(['user'=>$user,'role'=>$role]); }
    private function customer(PDO $pdo, int $org, int $location, ?int $owner, string $first, ?int $actor): int { $s=$pdo->prepare('INSERT INTO customers(organisation_id,owning_location_id,owning_agent_id,first_name,last_name,created_by_user_id,updated_by_user_id) VALUES(:org,:location,:owner,:first,\'Test\',:created,:updated)'); $auditActor=$actor ?? $this->user($pdo,$org,$location,strtolower($first).'@example.test','F'.bin2hex(random_bytes(3))); $s->execute(['org'=>$org,'location'=>$location,'owner'=>$owner,'first'=>$first,'created'=>$auditActor,'updated'=>$auditActor]); return (int)$pdo->lastInsertId(); }
    private function enquiryAndTask(PDO $pdo, int $org, int $customer, int $location, int $owner, int $creator, string $suffix): void { $s=$pdo->prepare('INSERT INTO enquiries(reference,organisation_id,customer_id,assigned_location_id,assigned_agent_id,product_type,destinations,created_by_user_id,updated_by_user_id) VALUES(:reference,:org,:customer,:location,:owner,\'Test\',JSON_ARRAY(\'Test\'),:creator,:creator2)');$s->execute(['reference'=>'PYH-E-'.strtoupper(bin2hex(random_bytes(5))),'org'=>$org,'customer'=>$customer,'location'=>$location,'owner'=>$owner,'creator'=>$creator,'creator2'=>$creator]);$t=$pdo->prepare('INSERT INTO tasks(organisation_id,location_id,assigned_user_id,created_by_user_id,title,due_at_utc) VALUES(:org,:location,:owner,:creator,:title,UTC_TIMESTAMP()-INTERVAL 1 HOUR)');$t->execute(['org'=>$org,'location'=>$location,'owner'=>$owner,'creator'=>$creator,'title'=>'Scope '.$suffix]); }
    private function assertDenied(\Closure $operation): void { try { $operation(); self::fail('Scoped access was unexpectedly allowed.'); } catch (RuntimeException $exception) { self::assertSame('Record not found.', $exception->getMessage()); } }
}
