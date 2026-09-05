<?php

declare(strict_types=1);

namespace PYH\Domain\Enquiry;

final class ReferenceGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** @param null|\Closure(int): string $bytes */
    public function __construct(private readonly ?\Closure $bytes = null)
    {
    }

    public function generate(): string
    {
        $source = $this->bytes ?? random_bytes(...);
        $bytes = $source(10);
        $encoded = '';
        foreach (str_split($bytes) as $byte) {
            $encoded .= self::ALPHABET[ord($byte) & 31];
        }
        return 'PYH-E-' . $encoded;
    }
}
