<?php

declare(strict_types=1);

namespace PYH\Database;

use PDO;
use RuntimeException;

final class SqlFileRunner
{
    public static function run(PDO $pdo, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Unable to read SQL file: {$path}");
        }

        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        if ($sql === null) {
            throw new RuntimeException("Unable to parse SQL file: {$path}");
        }

        foreach (preg_split('/;\s*(?:\R|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
