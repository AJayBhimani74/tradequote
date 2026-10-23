<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Requested = 'requested';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Quoted]);
    }
}
