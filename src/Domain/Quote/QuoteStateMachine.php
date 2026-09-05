<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

use DomainException;

final class QuoteStateMachine
{
    /** @var array<string, list<QuoteStatus>> */
    private const ALLOWED = [
        'Draft' => [QuoteStatus::Ready, QuoteStatus::Superseded],
        'Ready' => [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Superseded],
        'Sent' => [QuoteStatus::Accepted, QuoteStatus::Declined, QuoteStatus::Expired, QuoteStatus::Superseded],
        'Accepted' => [QuoteStatus::Converted],
        'Declined' => [], 'Expired' => [], 'Superseded' => [], 'Converted' => [],
    ];

    public function assertCanTransition(QuoteStatus $from, QuoteStatus $to): void
    {
        if ($from === $to || !in_array($to, self::ALLOWED[$from->value], true)) {
            throw new DomainException("Quote cannot transition from {$from->value} to {$to->value}.");
        }
    }
}
