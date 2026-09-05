<?php

declare(strict_types=1);

namespace PYH\Domain\Quote;

enum QuoteStatus: string
{
    case Draft = 'Draft';
    case Ready = 'Ready';
    case Sent = 'Sent';
    case Accepted = 'Accepted';
    case Declined = 'Declined';
    case Expired = 'Expired';
    case Superseded = 'Superseded';
    case Converted = 'Converted';
}
