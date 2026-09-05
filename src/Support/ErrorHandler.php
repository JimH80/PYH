<?php

declare(strict_types=1);

namespace PYH\Support;

use ErrorException;
use PYH\Logging\Logger;
use Throwable;

final class ErrorHandler
{
    public static function register(Logger $logger, bool $debug): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $exception) use ($logger, $debug): void {
            $logger->log('error', $exception->getMessage(), [
                'type' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            http_response_code(500);
            $message = $debug ? (string) $exception : 'An unexpected error occurred.';
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $message . PHP_EOL);
                exit(1);
            } else {
                echo $message;
            }
        });
    }
}
