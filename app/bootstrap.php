<?php

declare(strict_types=1);

use PYH\Config\Config;
use PYH\Config\Environment;
use PYH\Logging\FileLogger;
use PYH\Support\ErrorHandler;

$root = dirname(__DIR__);
$composerAutoloader = $root . '/vendor/autoload.php';

if (is_file($composerAutoloader)) {
    require $composerAutoloader;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'PYH\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

Environment::load($root . '/.env');

$config = new Config($root . '/config');
date_default_timezone_set($config->string('app.timezone'));
$logger = new FileLogger($root . '/' . $config->string('app.log_path'));
ErrorHandler::register($logger, $config->bool('app.debug'));

return ['root' => $root, 'config' => $config, 'logger' => $logger];
