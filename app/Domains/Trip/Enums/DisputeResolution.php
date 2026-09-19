<?php

namespace App\Domains\Trip\Enums;

enum DisputeResolution: string
{
    case Upheld = 'upheld';
    case Overturned = 'overturned';
    case Refunded = 'refunded';
}
