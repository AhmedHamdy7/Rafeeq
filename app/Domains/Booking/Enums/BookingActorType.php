<?php

namespace App\Domains\Booking\Enums;

enum BookingActorType: string
{
    case Passenger = 'passenger';
    case Driver = 'driver';
    case System = 'system';
    case Admin = 'admin';
}
