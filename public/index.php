<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = PYH\Database\ConnectionFactory::create($app['config']->array('database'));
try {
    (new PYH\Web\WebApplication($pdo, $app['config']->string('app.environment')))->run();
} catch (PYH\Security\CsrfViolation) {
    $app['logger']->log('warning', 'CSRF validation rejected request.', [
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'request_path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
    ]);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Request forbidden</title></head><body><main><h1>Request forbidden</h1><p>The request could not be verified. Please return to the previous page and try again.</p></main></body></html>';
} catch (RuntimeException $exception) {
    $status = match ($exception->getMessage()) {
        'Permission denied.' => 403,
        'Record not found.' => 404,
        default => 422,
    };
    $app['logger']->log('warning', 'Controlled application request rejection.', [
        'status' => $status,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'request_path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
        'error_type' => $exception::class,
    ]);
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Request rejected</title></head><body><main><h1>Request rejected</h1><p>The requested operation could not be completed.</p></main></body></html>';
}
