<?php

declare(strict_types=1);

namespace PYH\Security;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function assertValid(?string $token): void
    {
        $stored = $_SESSION['csrf_token'] ?? null;
        if (!is_string($stored) || !is_string($token) || !hash_equals($stored, $token)) {
            throw new CsrfViolation('CSRF validation failed.');
        }
    }
}
