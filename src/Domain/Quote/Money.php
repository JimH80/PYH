<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

use InvalidArgumentException;

final class Money
{
    public static function minor(string|int $amount): int
    {
        $value = trim((string) $amount);
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) { throw new InvalidArgumentException('Money must have no more than two decimal places.'); }
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        return $negative ? -$minor : $minor;
    }

    public static function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        return $sign . intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
