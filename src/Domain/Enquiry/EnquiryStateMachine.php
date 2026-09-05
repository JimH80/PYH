<?php

declare(strict_types=1);

namespace PYH\Domain\Enquiry;

use DomainException;

final class EnquiryStateMachine
{
    /** @var array<string, list<EnquiryStatus>> */
    private const ALLOWED = [
        'New' => [EnquiryStatus::Contacted, EnquiryStatus::Lost, EnquiryStatus::Closed],
        'Contacted' => [EnquiryStatus::New, EnquiryStatus::Quoted, EnquiryStatus::Lost, EnquiryStatus::Closed],
        'Quoted' => [EnquiryStatus::Contacted, EnquiryStatus::Booked, EnquiryStatus::Lost, EnquiryStatus::Closed],
        'Booked' => [],
        'Lost' => [EnquiryStatus::Contacted, EnquiryStatus::Closed],
        'Closed' => [EnquiryStatus::Contacted],
    ];

    public function assertCanTransition(EnquiryStatus $from, EnquiryStatus $to): void
    {
        if ($from === $to) {
            return;
        }
        if (!in_array($to, self::ALLOWED[$from->value], true)) {
            throw new DomainException("Enquiry cannot transition from {$from->value} to {$to->value}.");
        }
    }
}
