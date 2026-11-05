<?php

namespace App\Domains\Commute\Enums;

enum TripCancelledBy: string
{
    case Driver = 'driver';
    case Platform = 'platform';
}
