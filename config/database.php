<?php

declare(strict_types=1);

use PYH\Config\Environment;

return [
    'driver' => 'mysql',
    'host' => Environment::get('DB_HOST', '127.0.0.1'),
    'port' => Environment::int('DB_PORT', 3306),
    'database' => Environment::get('DB_DATABASE', ''),
    'username' => Environment::get('DB_USERNAME', ''),
    'password' => Environment::get('DB_PASSWORD', ''),
    'charset' => Environment::get('DB_CHARSET', 'utf8mb4'),
];
