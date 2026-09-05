<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

final class QuoteReferenceGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** @param null|\Closure(int): string $bytes */
    public function __construct(private readonly ?\Closure $bytes = null) {}

    public function generate(): string
    {
        $source = $this->bytes ?? random_bytes(...);
        $reference = 'PYH-Q-';
        foreach (str_split($source(12)) as $byte) { $reference .= self::ALPHABET[ord($byte) & 31]; }
        return $reference;
    }
}
