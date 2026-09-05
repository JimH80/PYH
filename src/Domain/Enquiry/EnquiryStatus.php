<?php

declare(strict_types=1);

namespace PYH\Domain\Enquiry;

enum EnquiryStatus: string
{
    case New = 'New';
    case Contacted = 'Contacted';
    case Quoted = 'Quoted';
    case Booked = 'Booked';
    case Lost = 'Lost';
    case Closed = 'Closed';

    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Quoted], true);
    }
}
