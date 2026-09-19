<?php

namespace App\Domains\Booking\Enums;

enum BookingEventType: string
{
    case Created = 'created';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Disputed = 'disputed';
}
