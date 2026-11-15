<?php

namespace App\Domains\Booking\Enums;

enum SeatOfferSource: string
{
    case SeatFreed = 'seat_freed';
    case DriverResume = 'driver_resume';
    case Replacement = 'replacement';
}
