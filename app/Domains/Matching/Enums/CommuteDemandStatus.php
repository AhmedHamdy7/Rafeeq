<?php

namespace App\Domains\Matching\Enums;

enum CommuteDemandStatus: string
{
    case Active = 'active';
    case Matched = 'matched';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
