<?php

declare(strict_types=1);

namespace PYH\Tests\Security;

use PHPUnit\Framework\TestCase;

final class SecretHandlingTest extends TestCase
{
    public function testExampleEnvironmentContainsNoDatabaseCredential(): void
    {
        $example = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
        self::assertIsString($example);
        self::assertMatchesRegularExpression('/^DB_USERNAME=\s*$/m', $example);
        self::assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $example);
    }
}
