<?php

declare(strict_types=1);

namespace PYH\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly DatabaseChangeAuditor $auditor,
    ) {
    }

    public function migrate(): void
    {
        $this->assertMetadataTableExists();
        $query = $this->pdo->query('SELECT migration FROM schema_migrations');
        if ($query === false) {
            throw new RuntimeException('Unable to read migration metadata.');
        }
        $applied = $query->fetchAll(PDO::FETCH_COLUMN);

        foreach (glob($this->directory . '/*.sql') ?: [] as $path) {
            $migration = basename($path);
            if (in_array($migration, $applied, true)) {
                continue;
            }

            $this->auditor->record($migration, 'applying');
            try {
                SqlFileRunner::run($this->pdo, $path);
                $statement = $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
                $statement->execute(['migration' => $migration]);
                $this->auditor->record($migration, 'applied');
            } catch (\Throwable $exception) {
                $this->auditor->record($migration, 'failed', ['error_type' => $exception::class]);
                throw $exception;
            }
        }
    }

    private function assertMetadataTableExists(): void
    {
        $statement = $this->pdo->query("SHOW TABLES LIKE 'schema_migrations'");
        if ($statement === false || $statement->fetchColumn() === false) {
            throw new RuntimeException('Canonical schema is not installed. Run the installer first.');
        }
    }
}
