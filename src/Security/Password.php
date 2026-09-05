<?php

declare(strict_types=1);

namespace PYH\Security;

final class Password
{
    public static function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_DEFAULT);
    }

    public static function verify(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }
}
