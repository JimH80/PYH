<?php

declare(strict_types=1);

namespace PYH\Logging;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final class FileLogger implements Logger
{
    public function __construct(private readonly string $path)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create log directory: {$directory}");
        }

        try {
            $line = json_encode([
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode log entry.', 0, $exception);
        }

        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write log file: {$this->path}");
        }
    }
}
