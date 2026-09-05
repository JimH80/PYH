<?php

declare(strict_types=1);

namespace PYH\Security;

use RuntimeException;

final class SessionAuthenticator
{
    /** @param null|\Closure(bool): bool $regenerate */
    public function __construct(private readonly ?\Closure $regenerate = null)
    {
    }

    public function login(int $userId, bool $active): void
    {
        if (!$active) {
            throw new RuntimeException('Authentication failed.');
        }
        $regenerate = $this->regenerate ?? session_regenerate_id(...);
        if (!$regenerate(true)) {
            throw new RuntimeException('Unable to secure the authenticated session.');
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
    }

    public function requireUser(int $ttlSeconds = 3600): int
    {
        $userId = $_SESSION['user_id'] ?? null;
        $authenticatedAt = $_SESSION['authenticated_at'] ?? null;
        if (!is_int($userId) || !is_int($authenticatedAt) || time() - $authenticatedAt > $ttlSeconds) {
            $this->logout();
            throw new RuntimeException('Authentication required.');
        }
        return $userId;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
