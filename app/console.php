<?php

declare(strict_types=1);

use PYH\Database\ConnectionFactory;
use PYH\Database\DatabaseChangeAuditor;
use PYH\Database\DatabaseChangePolicy;
use PYH\Database\Migrator;
use PYH\Installation\Installer;

/** @var array{root: string, config: PYH\Config\Config, logger: PYH\Logging\FileLogger} $app */
$app = require __DIR__ . '/bootstrap.php';
$command = $argv[1] ?? 'help';

if ($command === 'help') {
    fwrite(STDOUT, "Usage: php app/console.php [install|migrate]\n");
    exit(0);
}

$environment = $app['config']->string('app.environment');
$identifier = match ($command) {
    'install' => 'canonical:4.2.0-booking-operations',
    'migrate' => 'migration-batch',
    default => throw new InvalidArgumentException("Unknown command: {$command}"),
};
$auditor = new DatabaseChangeAuditor($app['logger'], $environment);
$auditor->record($identifier, 'started', ['command' => $command]);

try {
    DatabaseChangePolicy::assertAllowed(
        $environment,
        $app['config']->bool('app.allow_production_database_changes'),
        $app['config']->bool('app.confirm_staging_database_changes'),
    );
} catch (\Throwable $exception) {
    $auditor->record($identifier, 'denied', ['error_type' => $exception::class]);
    throw $exception;
}

try {
    $pdo = ConnectionFactory::create($app['config']->array('database'));
    $migrator = new Migrator($pdo, $app['root'] . '/database/migrations', $auditor);

    if ($command === 'install') {
        (new Installer($pdo, $app['root'] . '/database/schema/001_canonical.sql', $auditor))->install();
    } else {
        $migrator->migrate();
    }
    $auditor->record($identifier, 'succeeded', ['command' => $command]);
} catch (\Throwable $exception) {
    $auditor->record($identifier, 'failed', ['error_type' => $exception::class]);
    throw $exception;
}
