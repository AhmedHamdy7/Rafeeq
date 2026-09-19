<?php

namespace App\Domains\Booking\Enums;

enum PickupPointRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case SuggestedAlternative = 'suggested_alternative';
    case Rejected = 'rejected';
}
