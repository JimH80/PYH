<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Security\Password;
use PYH\Security\SessionAuthenticator;
use RuntimeException;

final class AuthenticationService
{
    public function __construct(private readonly PDO $pdo, private readonly SessionAuthenticator $session, private readonly AuditService $audit)
    {
    }

    public function login(string $email, string $password): int
    {
        $statement = $this->pdo->prepare('SELECT id, organisation_id, password_hash, is_active FROM users WHERE email=:email LIMIT 1');
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!is_array($user) || !(bool) $user['is_active'] || !Password::verify($password, (string) $user['password_hash'])) {
            throw new RuntimeException('Authentication failed.');
        }
        $id = (int) $user['id'];
        $this->session->login($id, true);
        $this->pdo->prepare('UPDATE users SET last_login_at_utc=CURRENT_TIMESTAMP(6) WHERE id=:id')->execute(['id' => $id]);
        $this->audit->record((int) $user['organisation_id'], $id, 'authentication.login', 'user', $id);
        return $id;
    }

    public function logout(?int $userId = null, ?int $organisationId = null): void
    {
        if ($userId !== null) { $this->audit->record($organisationId, $userId, 'authentication.logout', 'user', $userId); }
        $this->session->logout();
    }
}
