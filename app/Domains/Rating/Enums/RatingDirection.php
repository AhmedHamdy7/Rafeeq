<?php

namespace App\Domains\Rating\Enums;

enum RatingDirection: string
{
    case PassengerToDriver = 'passenger_to_driver';
    case DriverToPassenger = 'driver_to_passenger';
}
