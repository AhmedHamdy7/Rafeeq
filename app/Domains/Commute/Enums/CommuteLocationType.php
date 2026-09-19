<?php

namespace App\Domains\Commute\Enums;

enum CommuteLocationType: string
{
    case Origin = 'origin';
    case Pickup = 'pickup';
    case Dropoff = 'dropoff';
    case Destination = 'destination';
}
