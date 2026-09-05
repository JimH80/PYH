<?php

declare(strict_types=1);

namespace PYH\Config;

use InvalidArgumentException;

final class Config
{
    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    public function __construct(string $directory)
    {
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $values = require $file;
            if (!is_array($values)) {
                throw new InvalidArgumentException("Configuration file must return an array: {$file}");
            }
            $this->items[pathinfo($file, PATHINFO_FILENAME)] = $values;
        }
    }

    public function get(string $key): mixed
    {
        [$group, $item] = array_pad(explode('.', $key, 2), 2, '');
        if (!array_key_exists($group, $this->items) || !array_key_exists($item, $this->items[$group])) {
            throw new InvalidArgumentException("Missing configuration key: {$key}");
        }
        return $this->items[$group][$item];
    }

    public function string(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value)) {
            throw new InvalidArgumentException("Configuration key {$key} is not a string.");
        }
        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->get($key);
        if (!is_bool($value)) {
            throw new InvalidArgumentException("Configuration key {$key} is not a boolean.");
        }
        return $value;
    }

    /** @return array<string, mixed> */
    public function array(string $key): array
    {
        $value = $this->items[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException("Configuration group {$key} is not an array.");
        }
        return $value;
    }
}
