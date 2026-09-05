<?php

declare(strict_types=1);

namespace PYH\Tests\Security;

use PHPUnit\Framework\TestCase;
use PYH\Security\Actor;
use PYH\Security\DataScope;
use PYH\Security\ScopeEvaluator;
use RuntimeException;

final class PermissionScopeTest extends TestCase
{
    public function testOwnScopeCannotAccessAnotherAgentsRecord(): void
    {
        $this->expectException(RuntimeException::class);
        (new ScopeEvaluator())->assertAccessible(new Actor(1, 10, 100, ['scope.own']), 10, 100, 2);
    }

    public function testCustomRoleCapabilitiesCanGrantLocationScope(): void
    {
        $actor = new Actor(1, 10, 100, ['customers.view', 'scope.location']);
        self::assertSame(DataScope::Location, (new ScopeEvaluator())->scope($actor));
        (new ScopeEvaluator())->assertAccessible($actor, 10, 100, 2);
        self::addToAssertionCount(1);
    }

    public function testManagerNameWithoutScopeGrantsNothing(): void
    {
        $actor = new Actor(1, 10, 100, ['customers.view']);
        self::assertSame(DataScope::None, (new ScopeEvaluator())->scope($actor));
        $this->expectException(RuntimeException::class);
        (new ScopeEvaluator())->assertAccessible($actor, 10, 100, 1);
    }

    public function testExplicitOrganisationScopeGrantsOrganisationButNeverCrossOrganisation(): void
    {
        $scope = new ScopeEvaluator();
        $actor = new Actor(1, 10, 100, ['scope.organisation']);
        $scope->assertAccessible($actor, 10, 999, 999);
        self::addToAssertionCount(1);
        $this->expectException(RuntimeException::class);
        $scope->assertAccessible($actor, 11, 100, 1);
    }

    public function testSqlScopeUsesCapabilitiesNotNamesOrAuthority(): void
    {
        $scope = new ScopeEvaluator();
        self::assertSame('owner_id=:record_user', $scope->sql(new Actor(1, 10, 100, ['scope.own']), 'location_id', 'owner_id', 'record')['sql']);
        self::assertSame('location_id=:record_location', $scope->sql(new Actor(1, 10, 100, ['scope.location']), 'location_id', 'owner_id', 'record')['sql']);
        self::assertSame('1=1', $scope->sql(new Actor(1, 10, 100, ['scope.organisation']), 'location_id', 'owner_id', 'record')['sql']);
        self::assertSame('1=0', $scope->sql(new Actor(1, 10, 100, []), 'location_id', 'owner_id', 'record')['sql']);
    }
}
