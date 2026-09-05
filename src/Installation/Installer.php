<?php

declare(strict_types=1);

namespace PYH\Installation;

use PDO;
use PYH\Database\DatabaseChangeAuditor;
use PYH\Database\SqlFileRunner;
use RuntimeException;

final class Installer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $canonicalSchemaPath,
        private readonly DatabaseChangeAuditor $auditor,
    ) {
    }

    public function install(): void
    {
        $query = $this->pdo->query('SHOW TABLES');
        if ($query === false) {
            throw new RuntimeException('Unable to inspect the target database.');
        }
        $tables = $query->fetchAll(PDO::FETCH_COLUMN);
        if ($tables !== []) {
            throw new RuntimeException('Installation requires a completely empty database.');
        }

        $identifier = 'canonical:4.2.0-booking-operations';
        $this->auditor->record($identifier, 'applying');

        try {
            SqlFileRunner::run($this->pdo, $this->canonicalSchemaPath);
            $statement = $this->pdo->prepare(
                'INSERT INTO installation_metadata (schema_version, installed_at_utc) VALUES (:version, UTC_TIMESTAMP(6))'
            );
            $statement->execute(['version' => '4.2.0-booking-operations']);
            $this->auditor->record($identifier, 'applied');
        } catch (\Throwable $exception) {
            $this->auditor->record($identifier, 'failed', ['error_type' => $exception::class]);
            throw $exception;
        }
    }
}
