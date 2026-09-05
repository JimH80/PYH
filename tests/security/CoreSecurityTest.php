<?php

declare(strict_types=1);

namespace PYH\Tests\Security;

use PHPUnit\Framework\TestCase;
use PYH\Security\Actor;
use PYH\Security\Csrf;
use PYH\Security\Html;
use PYH\Security\Password;
use PYH\Security\PermissionEvaluator;
use PYH\Security\SessionAuthenticator;
use RuntimeException;

final class CoreSecurityTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }

    public function testCsrfRejectsMissingOrWrongToken(): void
    {
        $_SESSION['csrf_token'] = 'known';
        $this->expectException(RuntimeException::class);
        Csrf::assertValid('wrong');
    }

    public function testPermissionModelDeniesByDefault(): void
    {
        $this->expectException(RuntimeException::class);
        (new PermissionEvaluator())->assertAllowed(new Actor(1, 1, 1, []), 'customers.view');
    }

    public function testCrossOrganisationAndLocationAreHiddenAsNotFound(): void
    {
        $policy = new PermissionEvaluator();
        $actor = new Actor(1, 1, 10, ['customers.view', 'scope.own']);
        try { $policy->assertScoped($actor, 2, 10, 1); self::fail('Cross-organisation access allowed.'); } catch (RuntimeException $exception) { self::assertSame('Record not found.', $exception->getMessage()); }
        try { $policy->assertScoped($actor, 1, 20, 2); self::fail('Cross-location access allowed.'); } catch (RuntimeException $exception) { self::assertSame('Record not found.', $exception->getMessage()); }
        try { $policy->assertScoped($actor, 1, 10, 2); self::fail('Cross-agent access allowed.'); } catch (RuntimeException $exception) { self::assertSame('Record not found.', $exception->getMessage()); }
    }

    public function testOutputEscapingAndPasswordHashing(): void
    {
        self::assertSame('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', Html::escape('<script>"x"</script>'));
        $hash = Password::hash('correct horse battery staple');
        self::assertNotSame('correct horse battery staple', $hash);
        self::assertTrue(Password::verify('correct horse battery staple', $hash));
    }

    public function testLoginRegeneratesSessionAndExpiryDeniesAccess(): void
    {
        $regenerated = false;
        $auth = new SessionAuthenticator(static function (bool $deleteOld) use (&$regenerated): bool { $regenerated = $deleteOld; return true; });
        $auth->login(42, true);
        self::assertTrue($regenerated);
        self::assertSame(42, $auth->requireUser());
        $_SESSION['authenticated_at'] = time() - 10;
        $this->expectException(RuntimeException::class);
        $auth->requireUser(1);
    }

    public function testInactiveUserCannotEnterSession(): void
    {
        $this->expectException(RuntimeException::class);
        (new SessionAuthenticator(static fn (bool $deleteOld): bool => true))->login(42, false);
    }
}
