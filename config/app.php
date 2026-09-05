<?php

declare(strict_types=1);

use PYH\Config\Environment;

return [
    'environment' => Environment::get('APP_ENV', 'local'),
    'debug' => Environment::bool('APP_DEBUG', false),
    'timezone' => Environment::get('APP_TIMEZONE', 'UTC'),
    'log_path' => Environment::get('APP_LOG_PATH', 'storage/logs/application.log'),
    'allow_production_database_changes' => Environment::bool('ALLOW_PRODUCTION_DATABASE_CHANGES', false),
    'confirm_staging_database_changes' => Environment::bool('CONFIRM_STAGING_DATABASE_CHANGES', false),
];
